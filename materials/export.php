<?php
/**
 * materials/export.php — one-click ZIP of a month's condition audit,
 * INCLUDING every photo, video and voice memo.
 *
 * The zip itself is built by mm_build_audit_zip() (includes/materials.php),
 * shared with the Google Drive push.
 *
 *   GET ?period=YYYY-MM
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

try {
    $tmp = mm_build_audit_zip($period);
} catch (Throwable $e) {
    http_response_code(500);
    exit($e->getMessage() . ' — use the CSV export instead.');
}

$fname = 'materials-audit-' . $period . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('X-Content-Type-Options: nosniff');
readfile($tmp);
@unlink($tmp);
