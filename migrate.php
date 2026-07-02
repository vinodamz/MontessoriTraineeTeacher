<?php
/**
 * migrate.php — one-shot schema migrator.
 *
 * Applies every `sql/migrate_*.sql` file in sorted order. Each migration is
 * idempotent (uses information_schema guards), so re-running is safe.
 *
 * Auth model: admin-only in the steady state. During the bootstrap window
 * (no `users` table yet, or `users` exists but isn't the unified shape) the
 * page is reachable without auth — there's no admin to log in as because
 * login.php itself can't query the DB.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$state     = users_table_state();
$bootstrap = $state !== 'unified';

// CLI mode: the deploy pipeline runs `php migrate.php` after every rsync
// (see .cpanel.yml) so schema changes land without the manual browser
// step. Shell access on the cPanel account is the auth — skip the admin
// login gate that protects the web entry point.
$isCli = PHP_SAPI === 'cli';

if (!$bootstrap && !$isCli) {
    require_admin();
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// ---------- Diagnostic header --------------------------------------------
echo "Database diagnostic\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "users table state: $state\n";
echo "auth gate:         " . ($bootstrap ? 'bootstrap (no login required)' : 'admin login required') . "\n";
echo "\n";

try {
    $pdo = db();

    $tables = $pdo->query("
        SELECT TABLE_NAME, TABLE_ROWS
        FROM information_schema.tables
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ")->fetchAll();

    echo "Tables in this database (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        printf("  • %-32s ~%d rows\n", $t['TABLE_NAME'], (int)$t['TABLE_ROWS']);
    }
    echo "\n";

    foreach (['users', 'teachers', 'students'] as $tname) {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME, COLUMN_TYPE
            FROM information_schema.columns
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([':t' => $tname]);
        $cols = $stmt->fetchAll();
        if (!$cols) {
            echo "[$tname] does not exist\n\n";
            continue;
        }
        echo "[$tname] columns:\n";
        foreach ($cols as $c) {
            echo "  • " . $c['COLUMN_NAME'] . "  " . $c['COLUMN_TYPE'] . "\n";
        }
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `$tname`")->fetchColumn();
            echo "  rows: $n\n";
        } catch (Throwable $e) { /* ignore */ }
        echo "\n";
    }
} catch (Throwable $e) {
    echo "DIAGNOSTIC ERROR: " . $e->getMessage() . "\n";
    echo "(usually means the DB config is wrong — check includes/config.php)\n";
    exit;
}

// ---------- Decide what to do --------------------------------------------
if ($state === 'legacy') {
    $confirm = ($_GET['confirm'] ?? '') === 'drop-legacy-lg'
            || ($isCli && in_array('--confirm=drop-legacy-lg', $argv ?? [], true));
    if (!$confirm) {
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "Legacy LGTaskManager tables detected.\n\n";
        echo "Found these stray tables left behind by a previous LG deployment:\n";
        echo "  users             ~2 rows  (LG-shaped, no modules column)\n";
        echo "  tasks             see counts above\n";
        echo "  task_columns      see counts above\n";
        echo "  task_recurrences  see counts above\n\n";
        echo "Your real Montessori data (teachers, students, evaluation_cards,\n";
        echo "assessments, comments, baselines, indicators, rating_config) is\n";
        echo "untouched and will not be affected.\n\n";
        echo "To proceed, click the link below. It will:\n";
        echo "  1. DROP the four LG tables above (in correct FK-safe order).\n";
        echo "  2. Re-run this diagnostic.\n";
        echo "  3. Apply migrate_001_unify_users.sql, which builds the unified\n";
        echo "     `users` table from your existing `teachers` rows.\n\n";
        echo "──────────────────────────────────────────────────────────────────\n";
        echo "  👉 Confirm: " . get_self_url() . "?confirm=drop-legacy-lg\n";
        echo "──────────────────────────────────────────────────────────────────\n\n";
        echo "If you want to inspect what's in those tables first, in phpMyAdmin run:\n";
        echo "  SELECT id, name, role FROM users;\n";
        echo "  SELECT id, title, created_at FROM tasks;\n";
        exit;
    }

    // ---------- Confirmed: drop legacy LG tables ------------------------
    echo "═══════════════════════════════════════════════════════════════════\n";
    echo "Dropping legacy LG tables (confirmed)…\n\n";

    // Order matters: tasks references task_columns + users; task_recurrences
    // references task_columns + users; users is referenced by both.
    $dropOrder = ['tasks', 'task_recurrences', 'task_columns', 'users'];
    foreach ($dropOrder as $t) {
        try {
            db()->exec("DROP TABLE IF EXISTS `$t`");
            echo "  ✓ dropped $t\n";
        } catch (Throwable $e) {
            echo "  ✗ failed to drop $t: " . $e->getMessage() . "\n";
            echo "    Aborting. Fix the issue and retry.\n";
            exit;
        }
    }
    echo "\n";

    // Re-evaluate state and fall through to apply migrations.
    $state     = users_table_state();
    $bootstrap = $state !== 'unified';
    echo "users table state is now: $state\n\n";
}

function get_self_url(): string
{
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '/migrate.php', '?');
    return $scheme . '://' . $host . $path;
}

// ---------- Apply migrations ---------------------------------------------
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Applying migrations…\n\n";

$pdo   = db();
$dir   = __DIR__ . '/sql';
$files = glob($dir . '/migrate_*.sql') ?: [];
sort($files, SORT_NATURAL);

if (!$files) {
    echo "No migration files found in sql/.\n";
    exit;
}

$failed = false;
foreach ($files as $f) {
    $base = basename($f);
    echo "── $base ──────────────────────────────────────\n";
    $sql = file_get_contents($f);
    if ($sql === false) { echo "  [skip] cannot read.\n"; continue; }

    $chunks = split_by_delimiter($sql);

    try {
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $pdo->exec($chunk);
        }
        echo "  ✓ applied.\n\n";
    } catch (Throwable $e) {
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
        echo "  (Each migration is idempotent — re-running after fixing the root cause is safe.)\n\n";
        $failed = true;
        break;
    }
}
echo $failed ? "Done WITH FAILURES — see above.\n" : "Done. You can now visit /login.php.\n";
// Non-zero exit so .cpanel.yml's `|| echo "MIGRATE FAILED"` marker fires and
// the deploy gate can spot a stalled migration run instead of reporting green.
if ($failed && PHP_SAPI === 'cli') {
    exit(1);
}

/**
 * Splits a SQL file that contains `DELIMITER //` blocks into chunks the PDO
 * `exec()` call can swallow.
 */
function split_by_delimiter(string $sql): array
{
    $out = [];
    $buf = '';
    $delim = ';';
    foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
        $trim = trim($line);
        if (stripos($trim, 'DELIMITER ') === 0) {
            if (trim($buf) !== '') { $out[] = $buf; $buf = ''; }
            $delim = trim(substr($trim, 10));
            continue;
        }
        $buf .= $line . "\n";
        if ($delim !== ';' && str_ends_with(rtrim($line), $delim)) {
            $body = rtrim($buf);
            $body = substr($body, 0, strlen($body) - strlen($delim));
            $out[] = $body;
            $buf = '';
        } elseif ($delim === ';' && str_ends_with(rtrim($line), ';')) {
            $out[] = $buf;
            $buf = '';
        }
    }
    if (trim($buf) !== '') $out[] = $buf;
    return $out;
}
