<?php
/**
 * feedback/view.php — one parent-feedback entry + its discussion history.
 *
 * Shows the feedback, discussion points and resolution, a running history log,
 * and quick actions to add a discussion note or change the status (both append
 * to the history). Admin-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/feedback.php';

$user = require_admin();

$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$row = $id > 0 ? feedback_get($id) : null;
if (!$row) { http_response_code(404); echo 'Feedback entry not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'add_note') {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') {
            flash_set('error', 'Write something before adding it to the history.');
        } else {
            feedback_log_add($id, $note, (int)$user['id'], 'note');
            flash_set('ok', 'Discussion note added.');
        }
        redirect('/feedback/view.php?id=' . $id . '#history');
    }

    if ($op === 'set_status') {
        $status = $_POST['status'] ?? '';
        if (array_key_exists($status, feedback_statuses()) && $status !== $row['status']) {
            db()->prepare("UPDATE parent_feedback SET status=:s WHERE id=:id")
                ->execute([':s' => $status, ':id' => $id]);
            feedback_log_add($id, 'Status changed from "' . (feedback_statuses()[$row['status']] ?? $row['status'])
                . '" to "' . (feedback_statuses()[$status] ?? $status) . '".', (int)$user['id'], 'status');
            flash_set('ok', 'Status updated.');
        }
        redirect('/feedback/view.php?id=' . $id . '#history');
    }
}

$log       = feedback_log($id);
$pageTitle = 'Feedback · ' . feedback_about($row);
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Parent feedback</h1>
        <p class="muted">
            <a href="/feedback/index.php">← All feedback</a>
            · <?= e(date('j M Y', strtotime((string)$row['entry_date']))) ?>
            · <span class="pill"><?= e(feedback_statuses()[$row['status']] ?? $row['status']) ?></span>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/feedback/edit.php?id=<?= $id ?>">Edit</a>
    </div>
</div>

<div class="card">
    <h3>About</h3>
    <dl class="dl-grid">
        <dt>Parent / family</dt><dd><?= e(feedback_about($row)) ?>
            <?php if (!empty($row['student_id'])): ?>
                · <a href="/students/view.php?id=<?= (int)$row['student_id'] ?>">student profile</a>
            <?php endif; ?>
        </dd>
        <dt>Date of entry</dt><dd><?= e(date('j M Y', strtotime((string)$row['entry_date']))) ?></dd>
        <?php if (!empty($row['created_by_name'])): ?>
            <dt>Logged by</dt><dd><?= e($row['created_by_name']) ?></dd>
        <?php endif; ?>
    </dl>
</div>

<div class="card">
    <h3>Comments given by parents</h3>
    <p style="white-space:pre-wrap;"><?= e((string)$row['parent_comments']) ?></p>

    <h3 class="section-h-spaced">Discussion points with teachers</h3>
    <?php if (trim((string)$row['discussion_points']) !== ''): ?>
        <p style="white-space:pre-wrap;"><?= e((string)$row['discussion_points']) ?></p>
    <?php else: ?>
        <p class="muted">None recorded.</p>
    <?php endif; ?>

    <h3 class="section-h-spaced">Resolution taken</h3>
    <?php if (trim((string)$row['resolution']) !== ''): ?>
        <p style="white-space:pre-wrap;"><?= e((string)$row['resolution']) ?></p>
    <?php else: ?>
        <p class="muted">Not resolved yet.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Update status</h3>
    <form method="post" action="/feedback/view.php" class="row" style="align-items:flex-end;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="op" value="set_status">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <?php foreach (feedback_statuses() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $row['status'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions"><button class="btn" type="submit">Update</button></div>
    </form>
</div>

<div class="card" id="history">
    <h3>Discussion history</h3>

    <form method="post" action="/feedback/view.php" style="margin-bottom:1rem;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="op" value="add_note">
        <div class="field">
            <label for="note">Add a discussion note</label>
            <textarea id="note" name="note" rows="2" placeholder="What was discussed, with whom, and any next step…"></textarea>
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">Add to history</button></div>
    </form>

    <?php if (!$log): ?>
        <p class="muted">No history yet.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($log as $l): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M Y', strtotime((string)$l['created_at']))) ?></strong>
                        <div class="muted small"><?= e(date('g:i a', strtotime((string)$l['created_at']))) ?></div>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(feedback_log_types()[$l['entry_type']] ?? $l['entry_type']) ?></span>
                        <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e((string)$l['note']) ?></div>
                        <?php if (!empty($l['by_name'])): ?>
                            <div class="muted small">by <?= e($l['by_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
