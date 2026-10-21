<?php
/** GET ?id=N&thumb=1 — a daily-check photo or video for the signed-in user. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_materials.php';

api_boot();
api_require_method('GET');
api_materials_user();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) api_error('File not found.', 404, 'not_found');

$st = db()->prepare('SELECT stored_filename, mime_type, original_filename, kind FROM mm_daily_media WHERE id = :id');
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) api_error('File not found.', 404, 'not_found');

$path = mm_media_dir() . '/' . basename((string)$row['stored_filename']);
$mime = (string)$row['mime_type'];
if (!empty($_GET['thumb']) && $row['kind'] === 'photo') {
    $thumb = mm_thumb_for((string)$row['stored_filename']);
    if ($thumb !== null) {
        $path = $thumb;
        $mime = 'image/jpeg';
    }
}
if (!is_file($path)) api_error('File not found.', 404, 'not_found');

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$row['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
