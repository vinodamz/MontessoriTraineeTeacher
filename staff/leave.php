<?php
/**
 * staff/leave.php — leave requests & allowances.
 *
 *   GET  /staff/leave.php                 Admin: every pending request + filters.
 *                                         Non-admin: own requests + apply form.
 *   GET  /staff/leave.php?user_id=N       Admin: focus one staff member.
 *   POST op=apply                         Anyone: apply for themselves
 *                                         (admins can also apply on behalf via user_id).
 *   POST op=decide   { id, decision, note } Admin: approve / reject.
 *   POST op=cancel   { id }               Owner: cancel own pending request.
 *   POST op=allowance { user_id, year, leave_type, days_total } Admin only.
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

    if ($op === 'apply') {
        $forUser = $isAdmin ? (int)($_POST['user_id'] ?? $user['id']) : (int)$user['id'];
        $type    = $_POST['leave_type'] ?? 'casual';
        if (!array_key_exists($type, staff_leave_types())) $type = 'casual';
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date']   ?? $start;
        $reason = trim($_POST['reason'] ?? '') ?: null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            flash_set('error', 'Pick a valid date range.');
            redirect('/staff/leave.php');
        }
        $days = staff_leave_days($start, $end);
        db()->prepare("
            INSERT INTO staff_leave_requests
                (user_id, leave_type, start_date, end_date, days_count, reason, status)
            VALUES (:u, :t, :s, :e, :d, :r, 'pending')
        ")->execute([':u' => $forUser, ':t' => $type, ':s' => $start, ':e' => $end, ':d' => $days, ':r' => $reason]);
        flash_set('ok', 'Leave request submitted (' . $days . ' day' . ($days == 1 ? '' : 's') . ').');
        redirect('/staff/leave.php' . ($isAdmin && $forUser !== (int)$user['id'] ? "?user_id=$forUser" : ''));
    }

    if ($op === 'decide' && $isAdmin) {
        $rid    = (int)($_POST['id'] ?? 0);
        $decide = $_POST['decision'] ?? '';
        $note   = trim($_POST['note'] ?? '') ?: null;
        if (in_array($decide, ['approved', 'rejected'], true) && $rid > 0) {
            db()->prepare("
                UPDATE staff_leave_requests
                SET status = :s, decided_by = :by, decided_at = NOW(), decision_note = :n
                WHERE id = :id AND status = 'pending'
            ")->execute([':s' => $decide, ':by' => (int)$user['id'], ':n' => $note, ':id' => $rid]);
            flash_set('ok', 'Request ' . $decide . '.');
        }
        redirect('/staff/leave.php' . (isset($_POST['user_id']) && (int)$_POST['user_id'] > 0 ? '?user_id=' . (int)$_POST['user_id'] : ''));
    }

    if ($op === 'cancel') {
        $rid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT user_id, status FROM staff_leave_requests WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        $r = $stmt->fetch();
        if ($r && (int)$r['user_id'] === (int)$user['id'] && $r['status'] === 'pending') {
            db()->prepare("UPDATE staff_leave_requests SET status='cancelled' WHERE id = :id")->execute([':id' => $rid]);
            flash_set('ok', 'Request cancelled.');
        }
        redirect('/staff/leave.php');
    }

    if ($op === 'allowance' && $isAdmin) {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $yr   = (int)($_POST['year'] ?? date('Y'));
        $type = $_POST['leave_type'] ?? '';
        $days = (float)($_POST['days_total'] ?? 0);
        if ($uid > 0 && $yr > 1970 && array_key_exists($type, staff_leave_types())) {
            db()->prepare("
                INSERT INTO staff_leave_allowances (user_id, year, leave_type, days_total)
                VALUES (:u, :y, :t, :d)
                ON DUPLICATE KEY UPDATE days_total = VALUES(days_total)
            ")->execute([':u' => $uid, ':y' => $yr, ':t' => $type, ':d' => $days]);
            flash_set('ok', 'Allowance saved.');
        }
        redirect('/staff/leave.php?user_id=' . $uid . '#allowances');
    }
}

// ---- GET ----------------------------------------------------------------
$focusUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($isAdmin ? 0 : (int)$user['id']);
if (!$isAdmin) $focusUser = (int)$user['id'];

$where  = '';
$params = [];
if ($focusUser > 0) {
    $where = ' WHERE r.user_id = :u';
    $params[':u'] = $focusUser;
}

$sql = "
    SELECT r.*, u.name AS user_name, d.name AS decider_name
    FROM staff_leave_requests r
    JOIN users u  ON u.id = r.user_id
    LEFT JOIN users d ON d.id = r.decided_by
    $where
    ORDER BY FIELD(r.status,'pending','approved','rejected','cancelled'), r.start_date DESC
    LIMIT 200
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$focusStaff = $focusUser > 0 ? staff_member($focusUser) : null;
$year       = (int)date('Y');
$balance    = $focusStaff ? staff_leave_balance((int)$focusStaff['id'], $year) : [];

$pageTitle  = 'Staff leave';
$wideLayout = $isAdmin;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Leave</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a>
            <?php if ($focusStaff): ?> · for <strong><?= e($focusStaff['name']) ?></strong>
                <a class="muted" href="/staff/leave.php">(clear)</a><?php endif; ?>
        </p>
    </div>
</div>

<div class="card" id="apply">
    <h3>Apply for leave</h3>
    <form method="post" class="row">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="apply">
        <?php if ($isAdmin): ?>
            <div class="field">
                <label>Staff member</label>
                <select name="user_id">
                    <?php foreach (staff_roster(true) as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $focusUser === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="field">
            <label>Type</label>
            <select name="leave_type">
                <?php foreach (staff_leave_types() as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>From</label><input type="date" name="start_date" required></div>
        <div class="field"><label>To</label><input type="date" name="end_date" required></div>
        <div class="field" style="flex: 2 1 280px;"><label>Reason</label><input name="reason" maxlength="500"></div>
        <div class="actions"><button class="btn btn-primary">Submit request</button></div>
    </form>
</div>

<?php if ($focusStaff): ?>
<div class="card" id="allowances">
    <h3>Allowances — <?= e($focusStaff['name']) ?>, <?= (int)$year ?></h3>
    <table class="admin-table">
        <thead><tr><th>Type</th><th>Total (days)</th><th>Used</th><th>Remaining</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
            <?php foreach ($balance as $code => $b): ?>
                <tr>
                    <td><?= e($b['label']) ?></td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <form method="post" class="row" style="gap:.3rem; align-items:center;">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="op" value="allowance">
                                <input type="hidden" name="user_id" value="<?= (int)$focusStaff['id'] ?>">
                                <input type="hidden" name="year" value="<?= (int)$year ?>">
                                <input type="hidden" name="leave_type" value="<?= e($code) ?>">
                                <input type="number" step="0.5" min="0" max="365" name="days_total"
                                       value="<?= e((string)$b['total']) ?>" style="width:80px;">
                                <button class="btn btn-ghost" type="submit">Save</button>
                            </form>
                        </td>
                    <?php else: ?>
                        <td><?= e((string)$b['total']) ?></td>
                    <?php endif; ?>
                    <td><?= e((string)$b['used']) ?></td>
                    <td><strong><?= e((string)$b['remaining']) ?></strong></td>
                    <?php if ($isAdmin): ?><td></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h3>Requests</h3>
    <?php if (!$requests): ?>
        <p class="muted">No requests yet.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Staff</th><th>Type</th><th>From</th><th>To</th><th>Days</th>
                    <th>Status</th><th>Reason / decision</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><a href="/staff/view.php?id=<?= (int)$r['user_id'] ?>"><?= e($r['user_name']) ?></a></td>
                        <td><?= e(staff_leave_types()[$r['leave_type']] ?? $r['leave_type']) ?></td>
                        <td><?= e((string)$r['start_date']) ?></td>
                        <td><?= e((string)$r['end_date']) ?></td>
                        <td><?= e((string)$r['days_count']) ?></td>
                        <td><span class="pill pill-status-<?= e($r['status']) ?>"><?= e(staff_leave_statuses()[$r['status']] ?? $r['status']) ?></span></td>
                        <td class="muted small">
                            <?php if ($r['reason']): ?><?= e($r['reason']) ?><?php endif; ?>
                            <?php if ($r['decision_note']): ?><br><em>→ <?= e($r['decision_note']) ?></em><?php endif; ?>
                            <?php if ($r['decider_name']): ?><br><span class="muted">by <?= e($r['decider_name']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'pending' && $isAdmin): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="decide">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$focusUser ?>">
                                    <input type="hidden" name="decision" value="approved">
                                    <button class="btn btn-ghost" type="submit">Approve</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="decide">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$focusUser ?>">
                                    <input type="hidden" name="decision" value="rejected">
                                    <button class="btn btn-ghost" type="submit">Reject</button>
                                </form>
                            <?php elseif ($r['status'] === 'pending' && (int)$r['user_id'] === (int)$user['id']): ?>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('Cancel this request?')">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="op" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-ghost" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
