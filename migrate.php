<?php
/**
 * migrate.php — one-shot schema migrator.
 *
 * Visit once after deploying a release that bumps the schema. Admin-only.
 *
 * Applies every `sql/migrate_*.sql` file in sorted order. Each migration is
 * idempotent (uses information_schema guards), so re-running is safe.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_admin();

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$dir = __DIR__ . '/sql';
$files = glob($dir . '/migrate_*.sql') ?: [];
sort($files, SORT_NATURAL);

if (!$files) {
    echo "No migration files found in sql/.\n";
    exit;
}

echo "Running " . count($files) . " migration(s)…\n\n";
foreach ($files as $f) {
    $base = basename($f);
    echo "── $base ──────────────────────────────────────\n";
    $sql = file_get_contents($f);
    if ($sql === false) { echo "  [skip] cannot read.\n"; continue; }

    // The migrations use DELIMITER blocks; split into top-level statements
    // by DELIMITER directive boundaries.
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
        echo "  (Re-running this migration after fixing the root cause is safe — every step is idempotent.)\n\n";
        break;
    }
}
echo "Done.\n";

/**
 * Splits a SQL file that contains `DELIMITER //` blocks into chunks the PDO
 * `exec()` call can swallow. Inside a `DELIMITER //` … `DELIMITER ;` region,
 * the whole region (a single CREATE PROCEDURE) is returned as one chunk.
 */
function split_by_delimiter(string $sql): array
{
    $out = [];
    $buf = '';
    $delim = ';';
    foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
        $trim = trim($line);
        if (stripos($trim, 'DELIMITER ') === 0) {
            // Flush any pending buffer at the current delimiter boundary.
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
