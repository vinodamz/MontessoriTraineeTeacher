<?php
/**
 * staff/messages.php — staff-to-management note channel.
 *
 *   GET                         Admin: every message, filterable.
 *                               Non-admin: own outgoing messages + composer.
 *   POST op=send                Anyone: post a new note from themselves.
 *   POST op=respond  { id, response, status } Admin only.
 *   POST op=set_status { id, status }         Admin only.
 *   POST op=delete   { id }     Author can delete an open one of their own;
 *                               admin can delete any.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user    = require_module('staff');
$isAdmin = staff_is_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'send') {
        $subj = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $cat  = $_POST['category'] ?? 'other';
        if (!array_key_exists($cat, staff_message_categories())) $cat = 'other';
        if ($subj === '' || $body === '') {
            flash_set('error', 'Subject and body are both required.');
            redirect('/staff/messages.php#new');
        }
        db()->prepare("
            INSERT INTO staff_messages (from_user_id, subject, body, category, status)
            VALUES (:u, :s, :b, :c, 'open')
        ")->execute([':u' => (int)$user['id'], ':s' => $subj, ':b' => $body, ':c' => $cat]);
        flash_set('ok', 'Sent to management.');
        redirect('/staff/messages.php');
    }

    if ($op === 'respond' && $isAdmin) {
        $mid    = (int)($_POST['id'] ?? 0);
        $resp   = trim($_POST['response'] ?? '');
        $status = $_POST['status'] ?? 'acknowledged';
        if (!array_key_exists($status, staff_message_statuses())) $status = 'acknowledged';
        if ($mid > 0 && $resp !== '') {
            db()->prepare("
                UPDATE staff_messages
                SET response = :r, responded_by = :by, responded_at = NOW(), status = :s
                WHERE id = :id
            ")->execute([':r' => $resp, ':by' => (int)$user['id'], ':s' => $status, ':id' => $mid]);
            flash_set('ok', 'Response posted.');
        }
        redirect('/staff/messages.php');
    }

    if ($op === 'set_status' && $isAdmin) {
        $mid    = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($mid > 0 && array_key_exists($status, staff_message_statuses())) {
            db()->prepare("UPDATE staff_messages SET status = :s WHERE id = :id")
                ->execute([':s' => $status, ':id' => $mid]);
            flash_set('ok', 'Status updated.');
        }
        redirect('/staff/messages.php');
    }

    if ($op === 'delete') {
        $mid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT from_user_id, status FROM staff_messages WHERE id = :id");
        $stmt->execute([':id' => $mid]);
        $m = $stmt->fetch();
        $canDelete = $m && ($isAdmin || ((int)$m['from_user_id'] === (int)$user['id'] && $m['status'] === 'open'));
        if ($canDelete) {
            db()->prepare("DELETE FROM staff_messages WHERE id = :id")->execute([':id' => $mid]);
            flash_set('ok', 'Message deleted.');
        }
        redirect('/staff/messages.php');
    }
}

// ---- GET ----------------------------------------------------------------
$fromUser = isset($_GET['from_user_id']) ? (int)$_GET['from_user_id'] : ($isAdmin ? 0 : (int)$user['id']);
if (!$isAdmin) $fromUser = (int)$user['id'];

$sql    = "
    SELECT m.*, f.name AS from_name, r.name AS responder_name
    FROM staff_messages m
    JOIN users f ON f.id = m.from_user_id
    LEFT JOIN users r ON r.id = m.responded_by";
$params = [];
if ($fromUser > 0) { $sql .= " WHERE m.from_user_id = :u"; $params[':u'] = $fromUser; }
$sql .= " ORDER BY FIELD(m.status,'open','acknowledged','resolved','archived'), m.created_at DESC LIMIT 200";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

$pageTitle  = 'Messages to management';
$wideLayout = $isAdmin;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Messages to management</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a>
            <?php if ($fromUser > 0 && $isAdmin): ?>
                · from <strong><?= e((staff_member($fromUser)['name'] ?? '#' . $fromUser)) ?></strong>
                <a class="muted" href="/staff/messages.php">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" class="card" id="new">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="send">
    <h3>New message</h3>
    <div class="row">
        <div class="field">
            <label>Category</label>
            <select name="category">
                <?php foreach (staff_message_categories() as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex: 3 1 320px;">
            <label>Subject *</label>
            <input name="subject" required maxlength="200">
        </div>
    </div>
    <div class="field">
        <label>Message *</label>
        <textarea name="body" rows="4" required placeholder="Share with school management…"></textarea>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Send</button></div>
</form>

<div class="card">
    <h3>Inbox</h3>
    <?php if (!$messages): ?>
        <p class="muted">No messages yet.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($messages as $m): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M', strtotime($m['created_at']))) ?></strong>
                        <span class="muted small"><?= e(date('Y', strtotime($m['created_at']))) ?></span>
                    </div>
                    <div class="timeline-body">
                        <span class="pill pill-status-<?= e($m['status']) ?>"><?= e(staff_message_statuses()[$m['status']] ?? $m['status']) ?></span>
                        <span class="pill"><?= e(staff_message_categories()[$m['category']] ?? $m['category']) ?></span>
                        <?php if ($isAdmin): ?>
                            · from <a href="/staff/view.php?id=<?= (int)$m['from_user_id'] ?>"><?= e($m['from_name']) ?></a>
                        <?php endif; ?>
                        <strong style="margin-left:.4rem;"><?= e($m['subject']) ?></strong>
                        <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e($m['body']) ?></div>

                        <?php if ($m['response']): ?>
                            <div class="muted small section-h-spaced">
                                <em>Response from <?= e((string)$m['responder_name']) ?>
                                    on <?= e(date('j M Y', strtotime((string)$m['responded_at']))) ?>:</em>
                                <div style="white-space:pre-wrap; color:#333;"><?= e($m['response']) ?></div>
                            </div>
                        <?php elseif ($isAdmin): ?>
                            <details class="section-h-spaced">
                                <summary class="btn btn-ghost" style="display:inline-block;">Respond</summary>
                                <form method="post" class="section-h-spaced">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="respond">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <div class="field">
                                        <label>Response</label>
                                        <textarea name="response" rows="3" required></textarea>
                                    </div>
                                    <div class="row">
                                        <div class="field">
                                            <label>Set status</label>
                                            <select name="status">
                                                <option value="acknowledged">Acknowledged</option>
                                                <option value="resolved">Resolved</option>
                                            </select>
                                        </div>
                                        <div class="actions"><button class="btn btn-primary" type="submit">Send response</button></div>
                                    </div>
                                </form>
                            </details>
                        <?php endif; ?>

                        <div class="muted small section-h-spaced">
                            <?php if ($isAdmin && !in_array($m['status'], ['resolved','archived'], true)): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="status" value="resolved">
                                    <button class="link-btn">Mark resolved</button>
                                </form>
                                ·
                            <?php endif; ?>
                            <?php $canDelete = $isAdmin || ((int)$m['from_user_id'] === (int)$user['id'] && $m['status'] === 'open'); ?>
                            <?php if ($canDelete): ?>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('Delete this message?')">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button class="link-btn">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
