<?php
/**
 * tasks/attachment.php — serve an uploaded task file.
 *
 *   GET ?id=N&download=1 → forces an attachment download (Content-Disposition)
 *   GET ?id=N            → inline preview when the browser can render it
 *
 * Auth: same as the tasks module; anyone holding the module (admins implicit).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

$user = require_module('tasks');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id.'); }

$st = db()->prepare("SELECT * FROM task_attachments WHERE id = :id");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Attachment not found.'); }

$path = task_attachments_dir() . '/' . basename((string)$row['stored_filename']);
if (!is_file($path)) { http_response_code(404); exit('File missing on disk.'); }

$mime = (string)$row['mime_type'] ?: 'application/octet-stream';
$name = (string)$row['original_filename'] ?: 'attachment';
$dispo = !empty($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $dispo . '; filename="' . rawurlencode($name) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
