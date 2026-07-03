<?php
/**
 * materials/media.php — auth-gated serve for a condition photo/video.
 *
 * Files live under uploads/material_media (outside any direct link). Only a
 * logged-in user with the materials module can stream them.
 *
 *   GET ?id=N   stream the media file inline
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

require_module('materials');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT stored_filename, mime_type, original_filename FROM mm_condition_media WHERE id = :id");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Not found.'); }

$path = mm_media_dir() . '/' . basename((string)$row['stored_filename']);
if (!is_file($path)) { http_response_code(404); exit('File missing.'); }

header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$row['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
