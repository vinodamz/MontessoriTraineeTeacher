<?php
/**
 * surveys/photo.php — serve one survey photo to a logged-in admin.
 *
 * Same gate as surveys/responses.php. The file name is taken from the
 * response JSON, not from the query string, so a guessed filename is not
 * enough and a pending upload (not yet saved on a response) cannot be
 * fetched here. Direct URLs under /uploads/ are refused by .htaccess.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

require_admin();

$id  = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['k'] ?? '');
if ($id <= 0 || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $key)) {
    http_response_code(400);
    exit('Bad request.');
}

$row = survey_response($id);
$ref = $row ? survey_photo_ref($row['_a'][$key] ?? null) : null;
if ($ref === null) {
    http_response_code(404);
    exit('Not found.');
}

$dir  = realpath(survey_photos_dir());
$path = $dir === false ? false : realpath($dir . '/' . $ref['file']);
if ($dir === false || $path === false
    || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR)
    || !is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

// Sniff again at read time. The stored MIME is a hint; the bytes win, and a
// file that is no longer an image we accept is not sent.
$mime = survey_photo_detect_mime($path);
if ($mime === null || !isset(SURVEY_PHOTO_MIME_EXT[$mime])) {
    http_response_code(404);
    exit('Not found.');
}
$ext = SURVEY_PHOTO_MIME_EXT[$mime];

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="photo.' . $ext . '"');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);
