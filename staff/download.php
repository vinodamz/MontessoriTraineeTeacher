<?php
/**
 * staff/download.php — auth-gated streamer for staff_documents.
 *
 * Admins can fetch any document. Staff can fetch only their own.
 *
 *   GET ?id=N → streams the stored file with the original filename.
 *
 * Files live at /uploads/staff_docs/<user_id>/<random>.<ext>; the parent
 * uploads/.htaccess denies direct web access.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request.'); }

$stmt = db()->prepare("SELECT * FROM staff_documents WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

if (!staff_can_view($user, (int)$doc['user_id'])) {
    http_response_code(403); exit('Forbidden.');
}

$path = realpath(__DIR__ . '/..') . '/uploads/staff_docs/'
      . (int)$doc['user_id'] . '/' . $doc['stored_name'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(410); exit('File missing on disk.');
}

$downloadName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', $doc['original_name']);
if ($downloadName === '' || $downloadName === null) $downloadName = 'document-' . $id;

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: '   . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (int)$doc['size_bytes']);
$inlineSafe = ['application/pdf', 'image/jpeg', 'image/png'];
$dispo      = in_array($doc['mime_type'], $inlineSafe, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $dispo . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($path);
exit;
