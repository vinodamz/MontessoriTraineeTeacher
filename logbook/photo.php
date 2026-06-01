<?php
/**
 * logbook/photo.php — serve a log entry's attachment (auth-gated).
 * Files live outside the web-accessible path under /uploads/logbook.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_module('logbook');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT photo_path FROM logbook_entries WHERE id = :id");
$stmt->execute([':id' => $id]);
$path = (string)$stmt->fetchColumn();
if ($path === '') { http_response_code(404); echo 'Not found.'; exit; }

// Prevent path traversal.
$path = basename($path);
$file = realpath(__DIR__ . '/..') . '/uploads/logbook/' . $path;
if (!is_file($file)) { http_response_code(404); echo 'File missing.'; exit; }

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    default => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=3600');
readfile($file);
