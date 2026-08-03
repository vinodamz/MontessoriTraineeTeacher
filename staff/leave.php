<?php
/**
 * staff/leave.php — apply for leave, and approve it.
 *
 * Open to everyone signed in: applying for your own leave is not a management
 * function. Admins additionally see the whole queue and the balance controls.
 *
 *   GET  /staff/leave.php                 Admin: every request + filters.
 *                                         Everyone else: own requests + apply form.
 *   GET  /staff/leave.php?user_id=N       Admin: focus one staff member.
 *   POST op=apply     Anyone, for themselves (admins may apply on behalf via user_id).
 *   POST op=decide    { id, decision, note }  Admin: approve / reject.
 *   POST op=cancel    { id }                  Owner: cancel own pending request.
 *   POST op=opening   { user_id, days, as_of } Admin: set the balance as at a date.
 *   POST op=adjust    { user_id, days, note }  Admin: correct without resetting.
 *
 * Applying notifies the admins; deciding notifies the applicant. The decision
 * goes out as a 'system' notification so it cannot be muted — it changes
 * whether somebody is expected at work, and whether they are paid.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/notify.php';

// Every employee applies for their own leave, so this page is open to anyone
// signed in — not gated on the 'staff' module, which most teachers do not
// have. Nothing management-facing is exposed by that: the roster picker, the
// approve/reject buttons and the balance controls are all behind $isAdmin,
// and a non-admin's view is forced to their own user id below.
$user    = require_login();
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
        // A half day only makes sense on a single date; the helper enforces it.
        $half = (string)($_POST['half_day'] ?? '');
        if (!in_array($half, ['', 'first', 'second'], true)) $half = '';
        if ($start !== $end) $half = '';

        $days = staff_leave_days($start, $end, $half);
        db()->prepare("
            INSERT INTO staff_leave_requests
                (user_id, leave_type, start_date, end_date, half_day, days_count, reason, status)
            VALUES (:u, :t, :s, :e, :h, :d, :r, 'pending')
        ")->execute([':u' => $forUser, ':t' => $type, ':s' => $start, ':e' => $end,
                     ':h' => $half, ':d' => $days, ':r' => $reason]);
        // Taken immediately: lastInsertId() reflects the most recent INSERT on
        // the connection, and reading it after the queries below would be a
        // trap for whoever adds another write in between.
        $newId = (int)db()->lastInsertId();

        // Tell them now whether this will be paid, rather than at payday.
        $bal  = staff_leave_balance_at($forUser);
        $note = '';
        if ($type === 'unpaid') {
            $note = ' This is unpaid leave — it will be deducted as loss of pay.';
        } elseif ($days > (float)$bal['balance']) {
            $short = round($days - max(0.0, (float)$bal['balance']), 2);
            $note  = ' Heads up: your balance is ' . rtrim(rtrim(number_format((float)$bal['balance'], 1), '0'), '.')
                   . ' day(s), so ' . rtrim(rtrim(number_format($short, 1), '0'), '.')
                   . ' of these would be loss of pay if approved.';
        }
        // Let the admins know somebody is waiting on them.
        $q = db()->prepare("SELECT * FROM staff_leave_requests WHERE id = :i");
        $q->execute([':i' => $newId]);
        if ($row = $q->fetch()) {
            $applicant = $forUser === (int)$user['id']
                       ? (string)$user['name']
                       : (string)((staff_member($forUser)['name'] ?? 'A staff member'));
            staff_leave_notify_applied($row, $applicant);
        }

        flash_set($note === '' ? 'ok' : 'error',
                  'Leave request submitted (' . rtrim(rtrim(number_format($days, 1), '0'), '.')
                  . ' day' . ($days == 1 ? '' : 's') . ').' . $note);
        redirect('/staff/leave.php' . ($isAdmin && $forUser !== (int)$user['id'] ? "?user_id=$forUser" : ''));
    }

    if ($op === 'decide' && $isAdmin) {
        $rid    = (int)($_POST['id'] ?? 0);
        $decide = $_POST['decision'] ?? '';
        $note   = trim($_POST['note'] ?? '') ?: null;
        if (in_array($decide, ['approved', 'rejected'], true) && $rid > 0) {
            $q = db()->prepare("SELECT * FROM staff_leave_requests WHERE id = :id AND status = 'pending'");
            $q->execute([':id' => $rid]);
            $req = $q->fetch();

            if ($req) {
                // Work out the paid/LOP split BEFORE the row is approved, so
                // the balance being drawn against does not already include it.
                $lop = 0.0;
                if (in_array($req['leave_type'], staff_leave_paid_types(), true)) {
                    $bal = staff_leave_balance_at((int)$req['user_id'], (string)$req['start_date']);
                    $lop = max(0.0, round((float)$req['days_count'] - max(0.0, (float)$bal['balance']), 2));
                } else {
                    $lop = (float)$req['days_count'];      // unpaid leave is all LOP
                }

                db()->prepare("
                    UPDATE staff_leave_requests
                    SET status = :s, decided_by = :by, decided_at = NOW(),
                        decision_note = :n, lop_days = :lop
                    WHERE id = :id AND status = 'pending'
                ")->execute([':s' => $decide, ':by' => (int)$user['id'], ':n' => $note,
                             ':lop' => $decide === 'approved' ? $lop : 0.0, ':id' => $rid]);

                // The estimate above is per-request; payroll allocates across the
                // whole month. Reconcile immediately so the number the admin is
                // about to be shown is the number that will be paid.
                if ($decide === 'approved') {
                    staff_leave_resync_month((int)$req['user_id'],
                        (int)date('Y', strtotime((string)$req['start_date'])),
                        (int)date('n', strtotime((string)$req['start_date'])));
                    $lop = (float)db()->query(
                        "SELECT lop_days FROM staff_leave_requests WHERE id = " . (int)$rid
                    )->fetchColumn();
                }

                $msg = 'Request ' . $decide . '.';
                if ($decide === 'approved') {
                    // Mark the days so the person stops reading as absent —
                    // which is what was previously docking their pay for
                    // leave that had been approved.
                    $marked = staff_leave_mark_attendance($req, (int)$user['id']);
                    $msg .= ' ' . $marked . ' day(s) marked as leave in attendance.';
                    if ($lop > 0) {
                        $msg .= ' ' . rtrim(rtrim(number_format($lop, 1), '0'), '.')
                              . ' day(s) will be loss of pay — the balance did not cover it.';
                    }
                }
                // Tell the person who asked. This is the whole point of the
                // exercise — otherwise they have to keep checking the page.
                staff_leave_notify_decided($req, $decide, $note, $decide === 'approved' ? $lop : 0.0);

                flash_set($decide === 'approved' && $lop > 0 ? 'error' : 'ok', $msg);
            }
        }
        redirect('/staff/leave.php' . (isset($_POST['user_id']) && (int)$_POST['user_id'] > 0 ? '?user_id=' . (int)$_POST['user_id'] : ''));
    }

    if ($op === 'cancel') {
        $rid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM staff_leave_requests WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        $r = $stmt->fetch();
        if ($r && (int)$r['user_id'] === (int)$user['id'] && $r['status'] === 'pending') {
            db()->prepare("UPDATE staff_leave_requests SET status='cancelled' WHERE id = :id")->execute([':id' => $rid]);
            // So an admin who saw the request in their bell stops looking for it.
            staff_leave_notify_cancelled($r, (string)$user['name']);
            flash_set('ok', 'Request cancelled.');
        }
        redirect('/staff/leave.php');
    }

    // Set the balance as at a date. Everything before it stops counting, so
    // this is the one action that lets an admin say "as of today they have N"
    // without reconstructing years of history.
    if ($op === 'opening' && $isAdmin) {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $date = (string)($_POST['as_of'] ?? date('Y-m-d'));
        $days = (float)($_POST['days'] ?? 0);
        $note = (string)($_POST['note'] ?? '');
        if ($uid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            staff_leave_ledger_add($uid, $date, 'opening', $days, $note, (int)$user['id']);
            flash_set('ok', 'Balance set to ' . rtrim(rtrim(number_format($days, 1), '0'), '.')
                          . ' day(s) as at ' . $date . '. Accrual runs forward from there.');
        } else {
            flash_set('error', 'Pick a staff member and a valid date.');
        }
        redirect('/staff/leave.php?user_id=' . $uid . '#balance');
    }

    // A correction that does not reset history.
    if ($op === 'adjust' && $isAdmin) {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $days = (float)($_POST['days'] ?? 0);
        $note = (string)($_POST['note'] ?? '');
        if ($uid > 0 && abs($days) > 0.001) {
            staff_leave_ledger_add($uid, date('Y-m-d'), 'adjust', $days, $note, (int)$user['id']);
            flash_set('ok', 'Adjusted by ' . ($days > 0 ? '+' : '')
                          . rtrim(rtrim(number_format($days, 1), '0'), '.') . ' day(s).');
        }
        redirect('/staff/leave.php?user_id=' . $uid . '#balance');
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
$balance    = $focusStaff ? staff_leave_balance_at((int)$focusStaff['id']) : [];
$ledger     = [];
if ($focusStaff) {
    $ls = db()->prepare(
        "SELECT l.*, u.name AS by_name
           FROM staff_leave_ledger l
           LEFT JOIN users u ON u.id = l.created_by
          WHERE l.user_id = :u
          ORDER BY l.entry_date DESC, l.id DESC LIMIT 20"
    );
    $ls->execute([':u' => (int)$focusStaff['id']]);
    $ledger = $ls->fetchAll();
}

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
        <div class="field"><label>From</label><input type="date" name="start_date" id="lv-from" required></div>
        <div class="field"><label>To</label><input type="date" name="end_date" id="lv-to" required></div>
        <div class="field">
            <label>Half day</label>
            <select name="half_day" id="lv-half">
                <option value="">Full day</option>
                <option value="first">First half</option>
                <option value="second">Second half</option>
            </select>
            <span class="muted small" id="lv-half-note">Only for a single date</span>
        </div>
        <div class="field" style="flex: 2 1 280px;"><label>Reason</label><input name="reason" maxlength="500"></div>
        <div class="actions"><button class="btn btn-primary">Submit request</button></div>
    </form>
</div>

<?php if ($focusStaff): ?>
<?php $d = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); ?>
<div class="card" id="balance">
    <h3>Leave balance — <?= e($focusStaff['name']) ?></h3>

    <p style="font-size:1.6rem;margin:.2rem 0;">
        <strong><?= e($d($balance['balance'])) ?></strong>
        <span class="muted" style="font-size:1rem;">day<?= $balance['balance'] == 1 ? '' : 's' ?> available today</span>
    </p>

    <p class="muted small" style="margin-top:0;">
        <?= e($d($balance['opening'])) ?> carried from <?= e((string)$balance['since']) ?>
        <?= $balance['from_open'] ? '(balance set by an admin)' : '(no opening balance set — counted from when the account was created)' ?>
        &nbsp;+&nbsp; <?= e($d($balance['accrued'])) ?> earned
        (<?= (int)$balance['months'] ?> month<?= $balance['months'] == 1 ? '' : 's' ?>
        × <?= e($d($balance['rate'])) ?>/month)
        <?php if (abs((float)$balance['adjust']) > 0.001): ?>
            &nbsp;<?= $balance['adjust'] > 0 ? '+' : '−' ?>&nbsp;<?= e($d(abs((float)$balance['adjust']))) ?> adjusted
        <?php endif; ?>
        &nbsp;−&nbsp; <?= e($d($balance['taken'])) ?> taken
    </p>

    <p class="muted small">
        Leave accrues at <?= e($d($balance['rate'])) ?> day per completed month and carries
        forward. Anything taken beyond the balance — and all Unpaid leave — becomes loss of pay
        on that month's payslip.
    </p>

    <?php if ($isAdmin): ?>
    <div class="row" style="gap:1.5rem; flex-wrap:wrap; margin-top:1rem;">
        <form method="post" class="row" style="gap:.4rem; align-items:flex-end;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="opening">
            <input type="hidden" name="user_id" value="<?= (int)$focusStaff['id'] ?>">
            <div class="field" style="margin:0;">
                <label class="small">Set balance to</label>
                <input type="number" step="0.5" min="0" max="365" name="days" value="0" style="width:90px;" required>
            </div>
            <div class="field" style="margin:0;">
                <label class="small">as at</label>
                <input type="date" name="as_of" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="field" style="margin:0;">
                <label class="small">Note</label>
                <input name="note" maxlength="255" placeholder="e.g. agreed at review">
            </div>
            <button class="btn btn-primary" type="submit">Set</button>
        </form>

        <form method="post" class="row" style="gap:.4rem; align-items:flex-end;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="adjust">
            <input type="hidden" name="user_id" value="<?= (int)$focusStaff['id'] ?>">
            <div class="field" style="margin:0;">
                <label class="small">Adjust by</label>
                <input type="number" step="0.5" min="-365" max="365" name="days" value="0" style="width:90px;" required>
            </div>
            <div class="field" style="margin:0;">
                <label class="small">Note</label>
                <input name="note" maxlength="255" placeholder="e.g. extra day for the fete">
            </div>
            <button class="btn btn-ghost" type="submit">Adjust</button>
        </form>
    </div>
    <p class="muted small" style="margin-bottom:0;">
        <strong>Set balance</strong> starts the count again from that date — use it when you
        know the true figure today. <strong>Adjust</strong> nudges it without discarding history.
    </p>
    <?php endif; ?>

    <?php if ($ledger): ?>
    <h4 style="margin-bottom:.3rem;">Balance history</h4>
    <table class="admin-table">
        <thead><tr><th>Date</th><th>What</th><th>Days</th><th>Note</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $l): ?>
            <tr>
                <td><?= e((string)$l['entry_date']) ?></td>
                <td><?= $l['kind'] === 'opening' ? 'Balance set' : 'Adjustment' ?></td>
                <td><?= $l['kind'] === 'adjust' && $l['days'] > 0 ? '+' : '' ?><?= e($d($l['days'])) ?></td>
                <td class="small"><?= e((string)($l['note'] ?? '')) ?></td>
                <td class="small"><?= e((string)($l['by_name'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
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
                    <th>LOP</th><th>Status</th><th>Reason / decision</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><a href="/staff/view.php?id=<?= (int)$r['user_id'] ?>"><?= e($r['user_name']) ?></a></td>
                        <td><?= e(staff_leave_types()[$r['leave_type']] ?? $r['leave_type']) ?></td>
                        <td><?= e((string)$r['start_date']) ?></td>
                        <td><?= e((string)$r['end_date']) ?></td>
                        <td>
                            <?= e(rtrim(rtrim(number_format((float)$r['days_count'], 1), '0'), '.')) ?>
                            <?php if ($r['half_day'] !== ''): ?>
                                <span class="muted small">(<?= $r['half_day'] === 'first' ? '1st' : '2nd' ?> half)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((float)$r['lop_days'] > 0): ?>
                                <strong style="color:#c62828;"><?= e(rtrim(rtrim(number_format((float)$r['lop_days'], 1), '0'), '.')) ?></strong>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
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

<script>
// A half day only means something on one date. Rather than let someone pick
// "first half" across a fortnight and have the server silently ignore it,
// disable the control the moment the dates differ.
(function () {
    var from = document.getElementById('lv-from'),
        to   = document.getElementById('lv-to'),
        half = document.getElementById('lv-half'),
        note = document.getElementById('lv-half-note');
    if (!from || !to || !half) return;

    function sync() {
        var single = from.value !== '' && from.value === to.value;
        half.disabled = !single;
        if (!single) half.value = '';
        if (note) note.textContent = single ? 'Counts as 0.5 days' : 'Only for a single date';
    }
    // Keep "To" from preceding "From", which would otherwise submit a
    // negative range and store zero days.
    function clampTo() {
        if (from.value && (!to.value || to.value < from.value)) to.value = from.value;
        if (from.value) to.min = from.value;
        sync();
    }
    from.addEventListener('change', clampTo);
    to.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
