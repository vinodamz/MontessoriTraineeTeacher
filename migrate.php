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

if (!$bootstrap) {
    require_admin();
}

header('Content-Type: text/plain; charset=utf-8');

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
    echo "═══════════════════════════════════════════════════════════════════\n";
    echo "STOP — the database is in an unexpected state.\n\n";
    echo "There's already a `users` table here, but it doesn't have the\n";
    echo "`modules` column the unified app expects. That usually means an\n";
    echo "older LGTaskManager-shaped `users` table was applied to the MTT\n";
    echo "database at some past point.\n\n";
    echo "What to do:\n";
    echo "  1. Look at the [users] columns + row count above. If you don't\n";
    echo "     recognise its rows as belonging to this Montessori app, you\n";
    echo "     can drop it safely — your real teachers are in the `teachers`\n";
    echo "     table.\n";
    echo "  2. In cPanel → phpMyAdmin → run:\n";
    echo "       DROP TABLE users;\n";
    echo "  3. Reload /migrate.php. The diagnostic will now read state=missing\n";
    echo "     and it will auto-rebuild `users` from `teachers`.\n";
    exit;
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
        break;
    }
}
echo "Done. You can now visit /login.php.\n";

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
