<?php
/**
 * staff/upload.php — staff document upload + delete.
 *
 *   POST (multipart, AJAX): { _csrf, user_id, kind, file } → JSON.
 *                           Admin-only.
 *   POST op=delete { _csrf, id } from a regular form → redirects to view.php.
 *                           Admin-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');
if (!staff_is_admin($user)) {
    http_response_code(403);
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
        header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'admin only']);
    } else { echo 'Forbidden.'; }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('POST only.');
}

csrf_check();

// Branch: form-style delete first (has no "file" key).
if (($_POST['op'] ?? '') === 'delete') {
    $did  = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare("SELECT user_id, stored_name FROM staff_documents WHERE id = :id");
    $stmt->execute([':id' => $did]);
    $d = $stmt->fetch();
    if ($d) {
        $path = realpath(__DIR__ . '/..') . '/uploads/staff_docs/' . (int)$d['user_id'] . '/' . $d['stored_name'];
        if (is_file($path)) @unlink($path);
        db()->prepare("DELETE FROM staff_documents WHERE id = :id")->execute([':id' => $did]);
        flash_set('ok', 'Document removed.');
        redirect('/staff/view.php?id=' . (int)$d['user_id'] . '#documents');
    }
    flash_set('error', 'Document not found.');
    redirect('/staff/index.php');
}

// Default: AJAX multipart upload.
header('Content-Type: application/json; charset=utf-8');
try {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $kind = $_POST['kind'] ?? 'other';
    if ($uid <= 0 || !isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'user_id and file required']);
        exit;
    }
    if (!staff_member($uid)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'staff member not found']);
        exit;
    }
    $f   = $_FILES['file'];
    $aid = staff_save_uploaded_document($uid, $f, (int)$user['id'], $kind);
    http_response_code(201);
    echo json_encode([
        'ok'   => true,
        'id'   => $aid,
        'name' => $f['name'],
        'kind' => $kind,
        'size' => format_bytes((int)$f['size']),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
