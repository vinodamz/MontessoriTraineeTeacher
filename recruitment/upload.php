<?php
/**
 * recruitment/upload.php — resume / supporting document upload.
 *
 * Multipart POST, CSRF-gated. Stores under uploads/recruit_docs/<cid>/ with
 * a random filename. Returns JSON. The parent uploads/.htaccess denies all
 * direct web access, so download.php is the only retrieval path.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

header('Content-Type: application/json; charset=utf-8');
$user = require_module('recruitment');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

try {
    csrf_check();

    $cid  = (int)($_POST['candidate_id'] ?? 0);
    $kind = $_POST['kind'] ?? 'resume';
    if (!array_key_exists($kind, recruit_attachment_kinds())) $kind = 'resume';

    if ($cid <= 0 || !isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'candidate_id and file required']);
        exit;
    }
    // Confirm the candidate exists before we save anything to disk.
    $exists = db()->prepare("SELECT 1 FROM recruit_candidates WHERE id = :id");
    $exists->execute([':id' => $cid]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'candidate not found']);
        exit;
    }

    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'upload error ' . $f['error']]);
        exit;
    }
    if ($f['size'] > RECRUIT_DOC_MAX_BYTES) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'file too large (8 MB max)']);
        exit;
    }

    $mime = sniff_mime_type($f['tmp_name']);
    if ($mime === null || !isset(RECRUIT_DOC_MIME_ALLOW[$mime])) {
        http_response_code(415);
        echo json_encode(['ok' => false, 'error' => 'file type not allowed']);
        exit;
    }
    $ext = RECRUIT_DOC_MIME_ALLOW[$mime];

    $dir    = recruit_docs_dir($cid);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) {
        throw new RuntimeException('failed to move uploaded file');
    }

    $stmt = db()->prepare("
        INSERT INTO recruit_attachments
            (candidate_id, kind, original_name, stored_name, mime_type, size_bytes, uploaded_by)
        VALUES
            (:c, :k, :o, :s, :m, :z, :u)
    ");
    $stmt->execute([
        ':c' => $cid,
        ':k' => $kind,
        ':o' => substr((string)$f['name'], 0, 255),
        ':s' => $stored,
        ':m' => $mime,
        ':z' => (int)$f['size'],
        ':u' => (int)$user['id'],
    ]);
    http_response_code(201);
    echo json_encode([
        'ok'   => true,
        'id'   => (int)db()->lastInsertId(),
        'name' => $f['name'],
        'kind' => $kind,
        'size' => format_bytes((int)$f['size']),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
