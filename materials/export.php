<?php
/**
 * materials/export.php — one-click ZIP of a month's condition audit,
 * INCLUDING every photo and video.
 *
 * Contents:
 *   replacement-list.csv       what to hand Kreedo (flagged rows)
 *   full-audit.csv             every checked material with condition/who/when
 *   media/<Shelf>/<Material>/  the photos and videos, named with kind + time
 *
 *   GET ?period=YYYY-MM
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP support is not available on this server — use the CSV export instead.');
}

/** Safe path segment for inside the zip. */
function mm_zip_seg(string $s): string
{
    $s = preg_replace('/[^\w\s\-\.]+/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    return $s !== '' ? mb_substr($s, 0, 60) : 'unnamed';
}

$tmp = tempnam(sys_get_temp_dir(), 'mmzip');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create the export file.');
}

// ---- full-audit.csv ---------------------------------------------------------
$rows = db()->prepare("
    SELECT m.location, m.name, c.condition_code, c.needs_replacement, c.replace_qty,
           c.notes, c.checked_at, u.name AS checked_by,
           (SELECT COUNT(*) FROM mm_condition_media md WHERE md.check_id = c.id) AS media_count
    FROM mm_condition_checks c
    JOIN mm_materials m ON m.id = c.material_id
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE c.period = :p
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute([':p' => $period]);
$audit = $rows->fetchAll();

$csv = fopen('php://temp', 'r+');
fputcsv($csv, ['Shelf', 'Material', 'Condition', 'Needs replacement', 'Qty', 'Notes', 'Checked by', 'Checked at', 'Photos/videos']);
foreach ($audit as $r) {
    fputcsv($csv, [
        $r['location'], $r['name'], mm_condition_label($r['condition_code']),
        $r['needs_replacement'] ? 'YES' : 'no',
        $r['needs_replacement'] ? max(1, (int)$r['replace_qty']) : '',
        $r['notes'], $r['checked_by'] ?? 'Unknown', $r['checked_at'], (int)$r['media_count'],
    ]);
}
rewind($csv);
$zip->addFromString('full-audit.csv', (string)stream_get_contents($csv));
fclose($csv);

// ---- replacement-list.csv ---------------------------------------------------
$csv = fopen('php://temp', 'r+');
fputcsv($csv, ['Shelf', 'Material', 'Condition', 'Qty', 'Notes', 'Flagged by', 'Flagged on']);
foreach ($audit as $r) {
    if (!$r['needs_replacement']) continue;
    fputcsv($csv, [
        $r['location'], $r['name'], mm_condition_label($r['condition_code']),
        max(1, (int)$r['replace_qty']), $r['notes'],
        $r['checked_by'] ?? 'Unknown', date('Y-m-d', strtotime($r['checked_at'])),
    ]);
}
rewind($csv);
$zip->addFromString('replacement-list.csv', (string)stream_get_contents($csv));
fclose($csv);

// ---- media files, foldered by shelf/material --------------------------------
$media = db()->prepare("
    SELECT md.stored_filename, md.kind, md.uploaded_at, md.mime_type,
           m.name AS material, m.location
    FROM mm_condition_media md
    JOIN mm_condition_checks c ON c.id = md.check_id
    JOIN mm_materials m ON m.id = c.material_id
    WHERE c.period = :p
    ORDER BY m.location, m.name, md.uploaded_at
");
$media->execute([':p' => $period]);
$dir  = mm_media_dir();
$seen = [];
$missing = 0;
foreach ($media as $md) {
    $src = $dir . '/' . basename((string)$md['stored_filename']);
    if (!is_file($src)) { $missing++; continue; }
    $ext  = pathinfo($src, PATHINFO_EXTENSION);
    $base = 'media/' . mm_zip_seg($md['location']) . '/' . mm_zip_seg($md['material']) . '/'
          . $md['kind'] . '-' . date('Ymd-His', strtotime($md['uploaded_at']));
    $name = $base . '.' . $ext;
    $i = 2;
    while (isset($seen[$name])) { $name = $base . '-' . $i++ . '.' . $ext; }
    $seen[$name] = true;
    $zip->addFile($src, $name);
}
if ($missing > 0) {
    $zip->addFromString('MISSING-FILES.txt', "$missing media file(s) recorded in the database were not found on disk and are not in this export.\n");
}

$zip->close();

$fname = 'materials-audit-' . $period . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('X-Content-Type-Options: nosniff');
readfile($tmp);
@unlink($tmp);
