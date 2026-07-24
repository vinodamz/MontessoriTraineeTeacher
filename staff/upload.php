<?php
/**
 * staff/upload.php — staff document upload + delete.
 *
 *   POST (multipart, AJAX): { _csrf, user_id, kind, file } → JSON.
 *   POST op=delete { _csrf, id } from a regular form → redirects to view.php.
 *
 * Access: admins act on any staff member. A staff member may upload to their
 * own record and delete documents they themselves uploaded — admin-loaded
 * documents (contracts etc.) stay admin-only to remove.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user    = require_module('staff');
$isAdmin = staff_is_admin($user);
$me      = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('POST only.');
}

csrf_check();

// Branch: form-style delete first (has no "file" key).
if (($_POST['op'] ?? '') === 'delete') {
    $did  = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare("SELECT user_id, stored_name, uploaded_by FROM staff_documents WHERE id = :id");
    $stmt->execute([':id' => $did]);
    $d = $stmt->fetch();
    if ($d) {
        // Admin → any. Staff → only a document on their own record that they
        // uploaded themselves.
        $canDelete = $isAdmin
            || ((int)$d['user_id'] === $me && (int)$d['uploaded_by'] === $me);
        if (!$canDelete) {
            http_response_code(403);
            flash_set('error', 'You can only delete documents you uploaded.');
            redirect('/staff/view.php?id=' . (int)$d['user_id'] . '#documents');
        }
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
    // Admins upload for anyone; a staff member only onto their own record.
    if (!$isAdmin && $uid !== $me) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'you can only upload to your own record']);
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
