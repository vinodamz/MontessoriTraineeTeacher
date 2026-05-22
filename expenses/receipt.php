<?php
/**
 * expenses/receipt.php — auth-gated receipt streamer.
 *
 * Mirrors students/document_download.php — files live under /uploads/receipts/
 * which is denied by .htaccess at the web tier. This endpoint authenticates
 * the request and streams bytes through PHP.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'expenses')) {
    http_response_code(403);
    exit('Forbidden — no access to expenses.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request.'); }

$stmt = db()->prepare("SELECT * FROM expenses WHERE id = :id");
$stmt->execute([':id' => $id]);
$exp = $stmt->fetch();
if (!$exp) { http_response_code(404); exit('Expense not found.'); }
if (empty($exp['receipt_filename'])) { http_response_code(404); exit('No receipt attached.'); }

// Owners can fetch their own receipt; admins can fetch any.
if ($user['role'] !== 'admin' && (int)$exp['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Forbidden — not your receipt.');
}

$path = receipts_dir() . '/' . $exp['receipt_filename'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(410);
    exit('File missing on disk.');
}

$downloadName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', (string)$exp['receipt_original']);
if ($downloadName === '' || $downloadName === null) {
    $downloadName = 'receipt-' . $id;
}

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: '   . $exp['receipt_mime']);
header('Content-Length: ' . (int)$exp['receipt_size']);
$inlineSafe = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
$dispo = in_array($exp['receipt_mime'], $inlineSafe, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $dispo . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($path);
exit;
