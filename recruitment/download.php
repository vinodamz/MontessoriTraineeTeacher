<?php
/**
 * recruitment/download.php — auth-gated file streamer for recruit_attachments.
 *
 *   GET ?id=N → streams the stored file with the original filename as the
 *               download name. 403 / 404 on auth or lookup failure.
 *
 * The files live at /uploads/recruit_docs/<candidate_id>/<random>.<ext>;
 * web access to that tree is denied by the parent uploads/.htaccess. This
 * endpoint is the only legitimate retrieval path.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request.'); }

$stmt = db()->prepare("SELECT * FROM recruit_attachments WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$path = realpath(__DIR__ . '/..') . '/uploads/recruit_docs/'
      . (int)$doc['candidate_id'] . '/' . $doc['stored_name'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(410);
    exit('File missing on disk.');
}

$downloadName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', $doc['original_name']);
if ($downloadName === '' || $downloadName === null) {
    $downloadName = 'document-' . $id;
}

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
