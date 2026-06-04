<?php
/**
 * students/photo.php — serve uploaded student / parent photos.
 *
 * Files live under /uploads/student_photos/ which sits outside the docroot
 * on cPanel deploys (rsync target is the public_html, the uploads/ dir is
 * one level up). Even when it ends up inside docroot, we route reads
 * through here so an unauthenticated visitor can't guess filenames.
 *
 * Anyone with the students or montessori module — plus admins — can view.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin'
    && !user_has_module($user, 'students')
    && !user_has_module($user, 'montessori')) {
    http_response_code(403); exit('Forbidden.');
}

$name = basename((string)($_GET['f'] ?? ''));
if ($name === '' || strpos($name, '..') !== false) {
    http_response_code(400); exit('Bad name.');
}
$path = student_photos_dir() . '/' . $name;
if (!is_file($path)) { http_response_code(404); exit('Not found.'); }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
