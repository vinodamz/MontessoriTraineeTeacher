<?php
/**
 * students/document_download.php — auth-gated file streamer.
 *
 *   GET ?id=N → streams the stored file with the original filename as the
 *               download name. Returns 403 / 404 on auth or lookup failure.
 *
 * The file lives at /uploads/student_docs/<random>.<ext>; web access to that
 * directory is blocked by an .htaccess Deny rule, so this endpoint is the
 * only legitimate way to retrieve a document. Auth check happens here, file
 * bytes only leave the server after the check passes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    exit('Forbidden — no access to student documents.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request.'); }

$stmt = db()->prepare("
    SELECT d.*, s.teacher_id
    FROM student_documents d
    JOIN students s ON s.id = d.student_id
    WHERE d.id = :id
");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

// Assessment-only teachers (no students module) can still download but only
// for students they're assigned to. Admins and students-module users can see
// any student's documents.
$canSeeAll = $user['role'] === 'admin' || user_has_module($user, 'students');
if (!$canSeeAll && (int)$doc['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Forbidden — not your student.');
}

$path = student_docs_dir() . '/' . $doc['stored_filename'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(410);
    exit('File missing on disk (the upload may have been wiped).');
}

// Sanitise the filename we hand back to the browser.
$downloadName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', $doc['original_filename']);
if ($downloadName === '' || $downloadName === null) {
    $downloadName = 'document-' . $id;
}

// Stream the file. Use readfile() for efficiency on large files; output
// buffers are flushed first so we don't double-buffer megabytes in memory.
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: '        . $doc['mime_type']);
header('Content-Length: '      . (int)$doc['size_bytes']);
// `inline` lets the browser render PDFs and images in a new tab; the user can
// still hit "Save as". Force `attachment` only for the formats browsers
// can't safely render.
$inlineSafe = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain'];
$dispo      = in_array($doc['mime_type'], $inlineSafe, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $dispo . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($path);
exit;
