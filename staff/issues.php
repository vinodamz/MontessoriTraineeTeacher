<?php
/**
 * staff/issues.php — log + list issues discussed (1:1, performance, kudos…).
 *
 * Admin-only writes. Staff can read their own visible_to_staff=1 entries
 * via the recent-issues block on view.php; this page is the full log + the
 * authoring surface.
 *
 *   GET   ?user_id=N            New-issue form + log for that staff member.
 *   POST  op=create             Insert new issue.
 *   POST  op=delete  { id }     Hard delete (admin only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');
if (!staff_is_admin($user)) {
    http_response_code(403); echo 'Forbidden — admin only.'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $kind   = $_POST['kind'] ?? 'one_on_one';
        if (!array_key_exists($kind, staff_issue_kinds())) $kind = 'one_on_one';
        $when   = $_POST['occurred_at'] ?? date('Y-m-d H:i:s');
        $subj   = trim($_POST['subject'] ?? '');
        $body   = trim($_POST['body'] ?? '') ?: null;
        $visible = isset($_POST['visible_to_staff']) ? 1 : 0;

        if ($uid <= 0 || $subj === '') {
            flash_set('error', 'Pick a staff member and add a subject.');
            redirect('/staff/issues.php' . ($uid ? "?user_id=$uid" : ''));
        }
        db()->prepare("
            INSERT INTO staff_issues
                (user_id, kind, occurred_at, subject, body, visible_to_staff, logged_by)
            VALUES (:u, :k, :w, :s, :b, :v, :by)
        ")->execute([
            ':u' => $uid, ':k' => $kind, ':w' => $when, ':s' => $subj,
            ':b' => $body, ':v' => $visible, ':by' => (int)$user['id'],
        ]);
        flash_set('ok', 'Issue logged.');
        redirect('/staff/issues.php?user_id=' . $uid);
    }

    if ($op === 'delete') {
        $iid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT user_id FROM staff_issues WHERE id = :id");
        $stmt->execute([':id' => $iid]);
        $r = $stmt->fetch();
        if ($r) {
            db()->prepare("DELETE FROM staff_issues WHERE id = :id")->execute([':id' => $iid]);
            flash_set('ok', 'Issue deleted.');
            redirect('/staff/issues.php?user_id=' . (int)$r['user_id']);
        }
        redirect('/staff/issues.php');
    }
}

$focusUser  = (int)($_GET['user_id'] ?? 0);
$focusStaff = $focusUser > 0 ? staff_member($focusUser) : null;

$issues = (function() use ($focusUser) {
    $sql = "
        SELECT i.*, u.name AS user_name, b.name AS by_name
        FROM staff_issues i
        JOIN users u  ON u.id = i.user_id
        LEFT JOIN users b ON b.id = i.logged_by";
    $params = [];
    if ($focusUser > 0) { $sql .= " WHERE i.user_id = :u"; $params[':u'] = $focusUser; }
    $sql .= " ORDER BY i.occurred_at DESC LIMIT 200";
    $s = db()->prepare($sql); $s->execute($params);
    return $s->fetchAll();
})();

$pageTitle = 'Staff issues';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Issues discussed</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a>
            <?php if ($focusStaff): ?> · <a href="/staff/view.php?id=<?= (int)$focusStaff['id'] ?>"><?= e($focusStaff['name']) ?></a><?php endif; ?>
        </p>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="create">
    <h3>Log new</h3>
    <div class="row">
        <div class="field">
            <label>Staff member *</label>
            <select name="user_id" required>
                <option value="">— Select —</option>
                <?php foreach (staff_roster() as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$focusUser === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Kind</label>
            <select name="kind">
                <?php foreach (staff_issue_kinds() as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>When</label>
            <input type="datetime-local" name="occurred_at" value="<?= e(date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="field" style="flex: 2 1 320px;">
            <label>Subject *</label>
            <input name="subject" required maxlength="200">
        </div>
    </div>
    <div class="field">
        <label>Body / notes</label>
        <textarea name="body" rows="4" placeholder="What was discussed, agreed, follow-ups…"></textarea>
    </div>
    <div class="field">
        <label><input type="checkbox" name="visible_to_staff" value="1" checked> Visible to the staff member on their own page</label>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Log entry</button></div>
</form>

<div class="card">
    <h3>Log</h3>
    <?php if (!$issues): ?>
        <p class="muted">No issues logged yet.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($issues as $iv): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M Y', strtotime($iv['occurred_at']))) ?></strong>
                        <span class="muted small"><?= e(date('H:i', strtotime($iv['occurred_at']))) ?></span>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(staff_issue_kinds()[$iv['kind']] ?? $iv['kind']) ?></span>
                        <?php if (!$focusStaff): ?>
                            · <a href="/staff/view.php?id=<?= (int)$iv['user_id'] ?>"><?= e($iv['user_name']) ?></a>
                        <?php endif; ?>
                        <strong style="margin-left:.4rem;"><?= e($iv['subject']) ?></strong>
                        <?php if (!(int)$iv['visible_to_staff']): ?>
                            <span class="pill" title="Not shown to the staff member">private</span>
                        <?php endif; ?>
                        <?php if ($iv['body']): ?>
                            <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e($iv['body']) ?></div>
                        <?php endif; ?>
                        <div class="muted small">
                            by <?= e((string)$iv['by_name']) ?>
                            <form method="post" style="display:inline; float:right;"
                                  onsubmit="return confirm('Delete this entry?')">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$iv['id'] ?>">
                                <button class="link-btn" type="submit" title="Delete">×</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
