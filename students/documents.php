<?php
/**
 * students/documents.php — per-student document attachments.
 *
 *   GET ?student_id=N   → list of documents + upload form + delete buttons
 *   POST op=upload      → handle file upload (CSRF + MIME + size validated)
 *   POST op=delete      → remove a document (DB row + file on disk)
 *
 * Auth: admins, or anyone with the `students` module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_tabs.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — you do not have the students module.';
    exit;
}

$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
if ($studentId <= 0) { redirect('/students/index.php'); }

// Load the student so we can render their name + verify they exist.
$stmt = db()->prepare("SELECT id, first_name, last_name, grade FROM students WHERE id = :id");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();
if (!$student) {
    flash_set('error', 'Student not found.');
    redirect('/students/index.php');
}
$fullName = trim($student['first_name'] . ' ' . $student['last_name']);

// ---------- POST handlers --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'upload') {
        try {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new RuntimeException('No file received.');
            }
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $msg = match ((int)$f['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server limit.',
                    UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted. Try again.',
                    UPLOAD_ERR_NO_FILE                        => 'No file selected.',
                    default                                   => 'Upload failed (error code ' . (int)$f['error'] . ').',
                };
                throw new RuntimeException($msg);
            }

            $size = (int)($f['size'] ?? 0);
            if ($size <= 0)                       throw new RuntimeException('Empty file.');
            if ($size > STUDENT_DOC_MAX_BYTES)    throw new RuntimeException('File is larger than ' . format_bytes(STUDENT_DOC_MAX_BYTES) . '.');

            // Trust nothing the browser said about type — sniff the bytes.
            $mime = sniff_mime_type($f['tmp_name']) ?? 'application/octet-stream';
            if (!array_key_exists($mime, STUDENT_DOC_MIME_ALLOW)) {
                throw new RuntimeException('File type "' . $mime . '" not allowed. Allowed: PDF / image / Word / Excel / TXT.');
            }
            $ext = STUDENT_DOC_MIME_ALLOW[$mime];

            $title    = trim($_POST['title'] ?? '');
            $category = $_POST['category'] ?? 'other';
            if (!array_key_exists($category, STUDENT_DOC_CATEGORIES)) $category = 'other';
            $originalName = (string)($f['name'] ?? 'upload.' . $ext);
            if ($title === '') $title = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Document';
            if (strlen($title) > 160) $title = substr($title, 0, 160);

            $dir   = student_docs_dir();
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest  = $dir . '/' . $stored;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save file to disk. Check uploads/ folder is writable.');
            }
            @chmod($dest, 0644);

            $ins = db()->prepare("
                INSERT INTO student_documents
                    (student_id, category, title, original_filename, stored_filename,
                     mime_type, size_bytes, uploaded_by_user_id)
                VALUES (:sid, :cat, :t, :orig, :stored, :mime, :sz, :u)
            ");
            $ins->execute([
                ':sid' => $studentId, ':cat' => $category, ':t' => $title,
                ':orig' => $originalName, ':stored' => $stored,
                ':mime' => $mime, ':sz' => $size, ':u' => $user['id'],
            ]);

            flash_set('ok', 'Uploaded ' . htmlspecialchars($originalName) . '.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('/students/documents.php?student_id=' . $studentId);
    }

    if ($op === 'delete') {
        $docId = (int)($_POST['id'] ?? 0);
        try {
            $row = db()->prepare("SELECT stored_filename FROM student_documents WHERE id = :id AND student_id = :sid");
            $row->execute([':id' => $docId, ':sid' => $studentId]);
            $doc = $row->fetch();
            if (!$doc) throw new RuntimeException('Document not found.');

            db()->prepare("DELETE FROM student_documents WHERE id = :id")->execute([':id' => $docId]);

            // Best-effort filesystem cleanup. If unlink fails the DB row is
            // already gone — log and move on.
            $path = student_docs_dir() . '/' . $doc['stored_filename'];
            if (is_file($path)) @unlink($path);

            flash_set('ok', 'Document deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Delete failed: ' . $e->getMessage());
        }
        redirect('/students/documents.php?student_id=' . $studentId);
    }
}

// ---------- GET: render list ----------------------------------------------
$docs = db()->prepare("
    SELECT d.*, u.name AS uploader_name
    FROM student_documents d
    LEFT JOIN users u ON u.id = d.uploaded_by_user_id
    WHERE d.student_id = :sid
    ORDER BY d.uploaded_at DESC, d.id DESC
");
$docs->execute([':sid' => $studentId]);
$documents = $docs->fetchAll();

$pageTitle = 'Documents — ' . $fullName;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Documents</h1>
        <p class="muted">
            <a href="/students/view.php?id=<?= (int)$student['id'] ?>"><?= e($fullName) ?></a>
            · <span class="<?= e(grade_badge_class($student['grade'])) ?>"><?= e($student['grade']) ?></span>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<?php student_tab_strip((int)$student['id'], 'documents', $user); ?>

<details class="card card-form" open>
    <summary>Upload a document</summary>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="upload">
        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
        <div class="row">
            <div class="field" style="flex: 1 1 100%;">
                <label>File <span class="muted small">(up to <?= format_bytes(STUDENT_DOC_MAX_BYTES) ?> — PDF / image / Word / Excel / TXT)</span></label>
                <input type="file" name="file" required
                       accept="application/pdf,image/jpeg,image/png,image/gif,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>Title</label>
                <input name="title" maxlength="160" placeholder="(falls back to the filename if blank)">
            </div>
            <div class="field">
                <label>Category</label>
                <select name="category">
                    <?php foreach (STUDENT_DOC_CATEGORIES as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $code === 'other' ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Upload</button>
        </div>
    </form>
</details>

<h2 class="section-h-spaced">Files on record</h2>
<?php if (!$documents): ?>
    <div class="empty"><p>No documents uploaded yet.</p></div>
<?php else: ?>
    <ul class="doc-list">
        <?php foreach ($documents as $d): ?>
            <li class="doc-row">
                <div>
                    <div class="doc-title">
                        <a href="/students/document_download.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a>
                        <span class="pill"><?= e(student_doc_category_label($d['category'])) ?></span>
                    </div>
                    <div class="doc-meta muted small">
                        <?= e($d['original_filename']) ?>
                        · <?= e(format_bytes((int)$d['size_bytes'])) ?>
                        · <?= e($d['mime_type']) ?>
                        · Uploaded <?= e(substr((string)$d['uploaded_at'], 0, 16)) ?>
                        <?php if (!empty($d['uploader_name'])): ?> by <?= e($d['uploader_name']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="doc-actions">
                    <a class="btn" href="/students/document_download.php?id=<?= (int)$d['id'] ?>">Download</a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this document? This cannot be undone.')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <button class="link-btn" type="submit">Delete</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
