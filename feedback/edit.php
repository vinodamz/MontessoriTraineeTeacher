<?php
/**
 * feedback/edit.php — create or edit a parent-feedback entry (admin-only).
 *
 *   GET  ?id=N   → edit form (omit id → new-entry form)
 *   POST         → insert/update + write a history-log entry → view.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/feedback.php';

$user = require_admin();
$pdo  = db();

$id      = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$editing = $id > 0;
$row     = $editing ? feedback_get($id) : null;
if ($editing && !$row) { http_response_code(404); echo 'Feedback entry not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $entryDate  = $_POST['entry_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) $entryDate = date('Y-m-d');
    $studentId  = (int)($_POST['student_id'] ?? 0) ?: null;
    $parentName = trim((string)($_POST['parent_name'] ?? ''));
    $comments   = trim((string)($_POST['parent_comments'] ?? ''));
    $discussion = trim((string)($_POST['discussion_points'] ?? ''));
    $resolution = trim((string)($_POST['resolution'] ?? ''));
    $status     = $_POST['status'] ?? 'open';
    if (!array_key_exists($status, feedback_statuses())) $status = 'open';

    if ($comments === '') {
        flash_set('error', 'Parent comments are required.');
        redirect('/feedback/edit.php' . ($editing ? '?id=' . $id : ''));
    }

    $params = [
        ':dt'  => $entryDate,
        ':sid' => $studentId,
        ':pn'  => $parentName !== '' ? $parentName : null,
        ':pc'  => $comments,
        ':dp'  => $discussion !== '' ? $discussion : null,
        ':res' => $resolution !== '' ? $resolution : null,
        ':st'  => $status,
    ];

    if ($editing) {
        $prevStatus = (string)$row['status'];
        $params[':id'] = $id;
        $pdo->prepare("
            UPDATE parent_feedback
            SET entry_date=:dt, student_id=:sid, parent_name=:pn, parent_comments=:pc,
                discussion_points=:dp, resolution=:res, status=:st
            WHERE id=:id
        ")->execute($params);

        if ($prevStatus !== $status) {
            feedback_log_add($id, 'Status changed from "' . (feedback_statuses()[$prevStatus] ?? $prevStatus)
                . '" to "' . (feedback_statuses()[$status] ?? $status) . '".', (int)$user['id'], 'status');
        } else {
            feedback_log_add($id, 'Entry details updated.', (int)$user['id'], 'edit');
        }
        flash_set('ok', 'Feedback updated.');
        redirect('/feedback/view.php?id=' . $id);
    } else {
        $params[':by'] = (int)$user['id'];
        $pdo->prepare("
            INSERT INTO parent_feedback
                (entry_date, student_id, parent_name, parent_comments, discussion_points, resolution, status, created_by)
            VALUES (:dt, :sid, :pn, :pc, :dp, :res, :st, :by)
        ")->execute($params);
        $newId = (int)$pdo->lastInsertId();
        feedback_log_add($newId, 'Feedback logged.', (int)$user['id'], 'created');
        flash_set('ok', 'Feedback logged.');
        redirect('/feedback/view.php?id=' . $newId);
    }
}

// ---- Render ----
$val = static fn(string $k, $default = '') => e((string)($row[$k] ?? $default));
$students  = feedback_student_options();
$pageTitle = $editing ? 'Edit feedback' : 'New feedback';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $editing ? 'Edit feedback' : 'New parent feedback' ?></h1>
        <p class="muted"><a href="/feedback/index.php">← All feedback</a></p>
    </div>
</div>

<form method="post" action="/feedback/edit.php">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <div class="card">
        <div class="row">
            <div class="field">
                <label for="entry_date">Date of entry</label>
                <input type="date" id="entry_date" name="entry_date"
                       value="<?= $val('entry_date', date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (feedback_statuses() as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= ($row['status'] ?? 'open') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label for="student_id">About which child <span class="muted small">(optional)</span></label>
                <select id="student_id" name="student_id">
                    <option value="">—</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)($row['student_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="parent_name">Parent name <span class="muted small">(optional)</span></label>
                <input type="text" id="parent_name" name="parent_name" maxlength="120" value="<?= $val('parent_name') ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="field">
            <label for="parent_comments">Comments given by parents <span class="muted small">(required)</span></label>
            <textarea id="parent_comments" name="parent_comments" rows="4" required><?= $val('parent_comments') ?></textarea>
        </div>
        <div class="field">
            <label for="discussion_points">Discussion points with teachers on the issue</label>
            <textarea id="discussion_points" name="discussion_points" rows="4"><?= $val('discussion_points') ?></textarea>
        </div>
        <div class="field">
            <label for="resolution">Resolution taken</label>
            <textarea id="resolution" name="resolution" rows="3"><?= $val('resolution') ?></textarea>
        </div>
    </div>

    <div class="actions">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Log feedback' ?></button>
        <a class="btn btn-ghost" href="<?= $editing ? '/feedback/view.php?id=' . $id : '/feedback/index.php' ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
