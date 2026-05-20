<?php
/**
 * expenses/edit.php — add / edit / review an expense.
 *
 * GET  ?id=N  → edit existing
 * GET         → new
 * POST op=save    → upsert
 * POST op=review  → admin: change status, add review notes
 * POST op=delete  → owner (if still submitted) or admin
 *
 * Receipt upload: the user picks an image / PDF. If it's an image, the
 * browser also runs Tesseract.js OCR on it (assets/js/expenses-ocr.js)
 * and pre-fills the amount / date / merchant fields. They can override
 * anything before submitting. The raw OCR text is shipped along as
 * a hidden form field for the audit log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'expenses')) {
    http_response_code(403);
    echo 'Forbidden — you do not have the expenses module.';
    exit;
}
$isAdmin = $user['role'] === 'admin';

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

function load_expense(int $id): ?array
{
    $stmt = db()->prepare("
        SELECT e.*, u.name AS user_name, r.name AS reviewer_name
        FROM expenses e
        LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN users r ON r.id = e.reviewed_by_user_id
        WHERE e.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$expense = $isEdit ? load_expense($id) : null;
if ($isEdit && !$expense) {
    flash_set('error', 'Expense not found.');
    redirect('/expenses/index.php');
}
// Owners can edit their own row; admins can edit anyone's.
if ($isEdit && !$isAdmin && (int)$expense['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Forbidden — not your expense.');
}

// ---------- POST ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? 'save';

    if ($op === 'delete') {
        $postId = (int)($_POST['id'] ?? 0);
        $row = load_expense($postId);
        if (!$row) { flash_set('error', 'Expense not found.'); redirect('/expenses/index.php'); }
        $owns = (int)$row['user_id'] === (int)$user['id'];
        $editableByOwner = $owns && in_array($row['status'], ['submitted', 'rejected'], true);
        if (!$isAdmin && !$editableByOwner) {
            flash_set('error', "You can't delete this — ask an admin.");
            redirect('/expenses/edit.php?id=' . $postId);
        }
        if (!empty($row['receipt_filename'])) {
            $p = receipts_dir() . '/' . $row['receipt_filename'];
            if (is_file($p)) @unlink($p);
        }
        $del = db()->prepare("DELETE FROM expenses WHERE id = :id");
        $del->execute([':id' => $postId]);
        flash_set('ok', 'Expense deleted.');
        redirect('/expenses/index.php');
    }

    if ($op === 'review') {
        if (!$isAdmin) { http_response_code(403); exit('Forbidden — admins only.'); }
        $postId    = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $notes     = trim($_POST['review_notes'] ?? '');
        if (!array_key_exists($newStatus, EXPENSE_STATUSES)) {
            flash_set('error', 'Bad status.');
            redirect('/expenses/edit.php?id=' . $postId);
        }
        $up = db()->prepare("
            UPDATE expenses
            SET status = :s, review_notes = :n,
                reviewed_by_user_id = :rid, reviewed_at = NOW()
            WHERE id = :id
        ");
        $up->execute([
            ':s'   => $newStatus,
            ':n'   => $notes !== '' ? $notes : null,
            ':rid' => $user['id'],
            ':id'  => $postId,
        ]);
        flash_set('ok', 'Review saved.');
        redirect('/expenses/edit.php?id=' . $postId);
    }

    // ---- op = save (default) ----
    $postId = (int)($_POST['id'] ?? 0);
    $isEditPost = $postId > 0;

    $existing = $isEditPost ? load_expense($postId) : null;
    if ($isEditPost) {
        if (!$existing) { flash_set('error', 'Expense not found.'); redirect('/expenses/index.php'); }
        if (!$isAdmin && (int)$existing['user_id'] !== (int)$user['id']) {
            http_response_code(403); exit('Forbidden.');
        }
        // Once approved or reimbursed, only admins may further edit the row.
        if (!$isAdmin && in_array($existing['status'], ['approved', 'reimbursed'], true)) {
            flash_set('error', 'This expense has been approved already — ask an admin to amend.');
            redirect('/expenses/edit.php?id=' . $postId);
        }
    }

    $amountIn = trim((string)($_POST['amount'] ?? ''));
    // Strip currency symbols + thousands separators; keep one decimal point.
    $amountIn = preg_replace('/[^\d.,-]/', '', $amountIn);
    $amountIn = str_replace(',', '', $amountIn);
    $amount   = is_numeric($amountIn) ? round((float)$amountIn, 2) : -1;

    $date     = $_POST['expense_date'] ?? '';
    $merchant = trim($_POST['merchant'] ?? '');
    $catId    = (int)($_POST['category_id'] ?? 0);
    $pay      = $_POST['payment_method'] ?? 'cash';
    $desc     = trim($_POST['description'] ?? '');
    $ocrText  = trim($_POST['ocr_text'] ?? '');

    if (!array_key_exists($pay, EXPENSE_PAYMENT_METHODS)) $pay = 'cash';
    if (!DateTime::createFromFormat('Y-m-d', $date)) {
        flash_set('error', 'Pick a valid expense date.');
        redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
    }
    if ($amount <= 0) {
        flash_set('error', 'Amount must be greater than zero.');
        redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
    }

    // Receipt upload (optional). If a new file is provided it replaces any
    // previously stored one.
    $newStored = null; $newOriginal = null; $newMime = null; $newSize = null;
    if (!empty($_FILES['receipt']) && is_array($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['receipt'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Receipt upload failed (code ' . (int)$f['error'] . ').');
            redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
        }
        if ((int)$f['size'] > RECEIPT_MAX_BYTES) {
            flash_set('error', 'Receipt too large — max ' . format_bytes(RECEIPT_MAX_BYTES) . '.');
            redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
        }
        $sniffed = sniff_mime_type($f['tmp_name']) ?? '';
        if (!array_key_exists($sniffed, RECEIPT_MIME_ALLOW)) {
            flash_set('error', 'Unsupported file type (' . e($sniffed ?: 'unknown') . '). PDFs and images only.');
            redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
        }
        $ext = RECEIPT_MIME_ALLOW[$sniffed];
        $newStored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = receipts_dir() . '/' . $newStored;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            flash_set('error', 'Could not save the receipt to disk.');
            redirect('/expenses/edit.php' . ($isEditPost ? '?id=' . $postId : ''));
        }
        @chmod($dest, 0644);
        $newOriginal = mb_substr((string)$f['name'], 0, 255);
        $newMime     = $sniffed;
        $newSize     = (int)$f['size'];
    }

    $pdo = db();
    if ($isEditPost) {
        // Replace receipt? Wipe the old file from disk if a new one came in.
        if ($newStored !== null && !empty($existing['receipt_filename'])) {
            $oldPath = receipts_dir() . '/' . $existing['receipt_filename'];
            if (is_file($oldPath)) @unlink($oldPath);
        }
        if ($newStored !== null) {
            $stmt = $pdo->prepare("
                UPDATE expenses
                SET category_id = :c, merchant = :m, expense_date = :d, amount = :a,
                    payment_method = :p, description = :desc,
                    receipt_filename = :rf, receipt_original = :ro,
                    receipt_mime = :rm, receipt_size = :rs,
                    ocr_text = COALESCE(NULLIF(:ocr,''), ocr_text)
                WHERE id = :id
            ");
            $stmt->execute([
                ':c' => $catId ?: null, ':m' => $merchant ?: null, ':d' => $date, ':a' => $amount,
                ':p' => $pay, ':desc' => $desc ?: null,
                ':rf' => $newStored, ':ro' => $newOriginal, ':rm' => $newMime, ':rs' => $newSize,
                ':ocr' => $ocrText, ':id' => $postId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE expenses
                SET category_id = :c, merchant = :m, expense_date = :d, amount = :a,
                    payment_method = :p, description = :desc,
                    ocr_text = COALESCE(NULLIF(:ocr,''), ocr_text)
                WHERE id = :id
            ");
            $stmt->execute([
                ':c' => $catId ?: null, ':m' => $merchant ?: null, ':d' => $date, ':a' => $amount,
                ':p' => $pay, ':desc' => $desc ?: null,
                ':ocr' => $ocrText, ':id' => $postId,
            ]);
        }
        flash_set('ok', 'Expense updated.');
        redirect('/expenses/edit.php?id=' . $postId);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO expenses
                (user_id, category_id, merchant, expense_date, amount,
                 payment_method, description, status,
                 receipt_filename, receipt_original, receipt_mime, receipt_size,
                 ocr_text)
            VALUES
                (:u, :c, :m, :d, :a, :p, :desc, 'submitted',
                 :rf, :ro, :rm, :rs, :ocr)
        ");
        $stmt->execute([
            ':u' => $user['id'], ':c' => $catId ?: null, ':m' => $merchant ?: null,
            ':d' => $date, ':a' => $amount, ':p' => $pay, ':desc' => $desc ?: null,
            ':rf' => $newStored, ':ro' => $newOriginal, ':rm' => $newMime, ':rs' => $newSize,
            ':ocr' => $ocrText !== '' ? $ocrText : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        flash_set('ok', 'Expense submitted.');
        redirect('/expenses/edit.php?id=' . $newId);
    }
}

// ---------- Render -------------------------------------------------------
$cats = expense_categories_active();

$expense = $isEdit ? load_expense($id) : [
    'expense_date'     => date('Y-m-d'),
    'amount'           => '',
    'merchant'         => '',
    'category_id'      => null,
    'payment_method'   => 'cash',
    'description'      => '',
    'status'           => 'submitted',
    'receipt_filename' => null,
    'receipt_mime'     => null,
    'ocr_text'         => '',
    'review_notes'     => '',
];

$ownsRow      = !$isEdit || (int)$expense['user_id'] === (int)$user['id'];
$lockedForUser = $isEdit && !$isAdmin && in_array($expense['status'], ['approved', 'reimbursed'], true);

$pageTitle = $isEdit ? 'Edit expense' : 'New expense';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit expense' : 'New expense' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted">
                Submitted by <strong><?= e($expense['user_name'] ?? '—') ?></strong>
                on <?= e(substr((string)$expense['created_at'], 0, 10)) ?> ·
                Status: <span class="<?= e(expense_status_class($expense['status'])) ?>"><?= e(expense_status_label($expense['status'])) ?></span>
            </p>
        <?php else: ?>
            <p class="muted">Snap a receipt and we'll try to pre-fill the amount and date.</p>
        <?php endif; ?>
    </div>
    <div class="page-head-actions">
        <a class="btn" href="/expenses/index.php">← All expenses</a>
    </div>
</div>

<?php if ($lockedForUser): ?>
    <div class="flash flash-info">
        This expense has been <?= e(expense_status_label($expense['status'])) ?> and can no longer be edited.
        Ask an admin if changes are needed.
    </div>
<?php endif; ?>

<form class="card card-form" method="post" enctype="multipart/form-data" id="expense-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="save">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$expense['id'] ?>"><?php endif; ?>
    <input type="hidden" name="ocr_text" id="ocr_text" value="<?= e((string)($expense['ocr_text'] ?? '')) ?>">

    <div class="row">
        <div class="field" style="flex: 2 1 320px;">
            <label>Receipt</label>
            <?php if (!empty($expense['receipt_filename'])): ?>
                <p class="muted small">
                    Current receipt:
                    <a href="/expenses/receipt.php?id=<?= (int)$expense['id'] ?>" target="_blank">View existing</a>
                    — upload a new file to replace it.
                </p>
            <?php endif; ?>
            <input type="file" name="receipt" id="receipt-file"
                   accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                   <?= $lockedForUser ? 'disabled' : '' ?>>
            <p class="muted small">Max <?= e(format_bytes(RECEIPT_MAX_BYTES)) ?>. Images and PDFs only.</p>

            <div id="ocr-status" class="ocr-status" hidden></div>
            <details id="ocr-details" hidden>
                <summary>Show extracted text</summary>
                <pre id="ocr-preview" class="ocr-preview"></pre>
            </details>
        </div>
        <div class="field">
            <label>Expense date *</label>
            <input type="date" name="expense_date" id="expense_date"
                   value="<?= e((string)$expense['expense_date']) ?>"
                   required <?= $lockedForUser ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>Amount * (₹)</label>
            <input type="number" name="amount" id="expense_amount" step="0.01" min="0.01"
                   value="<?= e((string)$expense['amount']) ?>"
                   required <?= $lockedForUser ? 'disabled' : '' ?>
                   placeholder="0.00">
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex: 2 1 240px;">
            <label>Merchant / vendor</label>
            <input type="text" name="merchant" id="expense_merchant" maxlength="160"
                   value="<?= e((string)$expense['merchant']) ?>"
                   <?= $lockedForUser ? 'disabled' : '' ?>
                   placeholder="e.g. Sharma Stationers">
        </div>
        <div class="field">
            <label>Category</label>
            <select name="category_id" <?= $lockedForUser ? 'disabled' : '' ?>>
                <option value="0">— pick one —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= (int)$expense['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Payment method</label>
            <select name="payment_method" <?= $lockedForUser ? 'disabled' : '' ?>>
                <?php foreach (EXPENSE_PAYMENT_METHODS as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $expense['payment_method'] === $k ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex: 1 1 100%;">
            <label>Description / notes</label>
            <textarea name="description" rows="3"
                      <?= $lockedForUser ? 'disabled' : '' ?>
                      placeholder="What was this for?"><?= e((string)$expense['description']) ?></textarea>
        </div>
    </div>

    <?php if (!$lockedForUser): ?>
        <div class="actions">
            <button class="btn btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Submit expense' ?>
            </button>
        </div>
    <?php endif; ?>
</form>

<?php if ($isEdit && ($isAdmin || ($ownsRow && in_array($expense['status'], ['submitted','rejected'], true)))): ?>
    <form class="card card-form danger-zone" method="post"
          onsubmit="return confirm('Delete this expense and its receipt file? This cannot be undone.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$expense['id'] ?>">
        <div class="actions">
            <button class="link-btn" type="submit">Delete this expense</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($isEdit && $isAdmin): ?>
    <form class="card card-form" method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="review">
        <input type="hidden" name="id" value="<?= (int)$expense['id'] ?>">
        <h3>Admin review</h3>
        <?php if (!empty($expense['reviewer_name'])): ?>
            <p class="muted small">
                Last reviewed by <?= e($expense['reviewer_name']) ?>
                on <?= e(substr((string)$expense['reviewed_at'], 0, 16)) ?>.
            </p>
        <?php endif; ?>
        <div class="row">
            <div class="field">
                <label>Set status</label>
                <select name="status">
                    <?php foreach (EXPENSE_STATUSES as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $expense['status'] === $k ? 'selected' : '' ?>>
                            <?= e($v) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 2 1 320px;">
                <label>Review note (visible to the submitter)</label>
                <input type="text" name="review_notes" maxlength="500"
                       value="<?= e((string)$expense['review_notes']) ?>"
                       placeholder="e.g. Approved — paid by cash on 21 May.">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Save review</button>
        </div>
    </form>
<?php elseif ($isEdit && !empty($expense['review_notes'])): ?>
    <div class="card">
        <h3>Admin note</h3>
        <p><?= nl2br(e((string)$expense['review_notes'])) ?></p>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="/assets/js/expenses-ocr.js?v=<?= e(asset_version()) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
