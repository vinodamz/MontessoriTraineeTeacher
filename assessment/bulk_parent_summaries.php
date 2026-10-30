<?php
/** Download one parent summary per active student visible to the signed-in user. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/parent_summary.php';
$user = require_module('montessori');
if (!class_exists('ZipArchive')) { http_response_code(503); exit('Bulk export is unavailable because ZIP support is not installed.'); }
$sql = "SELECT id FROM students WHERE is_active = 1 AND enrollment_status IN ('enrolled','promoted')";
$params = [];
if (($user['role'] ?? '') !== 'admin') { $sql .= ' AND teacher_id = ?'; $params[] = (int)$user['id']; }
$sql .= ' ORDER BY first_name, last_name, id';
$q = db()->prepare($sql); $q->execute($params); $ids = $q->fetchAll(PDO::FETCH_COLUMN);
if (!$ids) { http_response_code(404); exit('No active students are available to export.'); }
$path = tempnam(sys_get_temp_dir(), 'mtt-parent-');
if ($path === false) { http_response_code(500); exit('Could not prepare the export.'); }
try {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot open ZIP');
    foreach ($ids as $id) {
        $d = child_report_data((int)$id);
        if (!$d) throw new RuntimeException('A student could not be loaded.');
        $bytes = parent_summary_pdf_bytes($d, function_exists('app_name') ? app_name() : '');
        $filename = (int)$id . '-' . child_report_pdf_filename($d);
        if (!$zip->addFromString($filename, $bytes)) throw new RuntimeException('Cannot add report to ZIP');
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finish ZIP');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="parent-summaries-'.date('Y-m-d').'.zip"');
    header('Content-Length: '.filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $e) {
    error_log('Parent summary export failed: '.$e->getMessage());
    if (!headers_sent()) { http_response_code(500); echo 'The export could not be completed. Please try again.'; }
} finally { @unlink($path); }
