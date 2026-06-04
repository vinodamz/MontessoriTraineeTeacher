<?php
/**
 * staff/attendance.php — daily attendance.
 *
 *   GET                       Admin: full roster grid for the given date.
 *                             Non-admin: own row only, self check-in/out form.
 *   POST op=self_in           Stamp own check_in NOW() for today (status defaults
 *                             to 'present' or 'late' >9:15 grace).
 *   POST op=self_out          Stamp own check_out NOW() for today.
 *   POST op=mark              Admin: upsert a row for (user_id, att_date) with
 *                             explicit status / times / notes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user    = require_login();
// Self check-in/out is open to everyone in the staff roster (admins,
// teachers, and anyone with the staff module). Other ops (the admin grid
// view, op=mark) still need the staff module — checked below.
$inStaffRoster = ($user['role'] === 'admin')
    || ($user['role'] === 'teacher')
    || user_has_module($user, 'staff');
$isAdmin = staff_is_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'self_in' || $op === 'self_out') {
        if (!$inStaffRoster) {
            http_response_code(403);
            echo 'Forbidden — only staff can check in.';
            exit;
        }
        $today = date('Y-m-d');
        $uid   = (int)$user['id'];
        $stmt  = db()->prepare("SELECT id, check_in, status FROM staff_attendance WHERE user_id = :u AND att_date = :d");
        $stmt->execute([':u' => $uid, ':d' => $today]);
        $existing = $stmt->fetch();

        if ($op === 'self_in') {
            $now    = date('H:i:s');
            $late   = ($now > '09:15:00') ? 'late' : 'present';
            if ($existing) {
                db()->prepare("
                    UPDATE staff_attendance
                       SET check_in = COALESCE(check_in, :t), status = IF(status='absent', :st, status)
                     WHERE id = :id
                ")->execute([':t' => $now, ':st' => $late, ':id' => $existing['id']]);
            } else {
                // Note: :u (user_id) and :by (marked_by) need distinct names —
                // PDO native prepares (EMULATE_PREPARES=false) reject a reused
                // placeholder and the page goes blank under display_errors=off.
                db()->prepare("
                    INSERT INTO staff_attendance (user_id, att_date, status, check_in, marked_by)
                    VALUES (:u, :d, :st, :t, :by)
                ")->execute([':u' => $uid, ':d' => $today, ':st' => $late, ':t' => $now, ':by' => $uid]);
            }
            flash_set('ok', 'Checked in at ' . substr($now, 0, 5) . '.');
        } else {
            $now = date('H:i:s');
            if ($existing) {
                db()->prepare("UPDATE staff_attendance SET check_out = :t WHERE id = :id")
                    ->execute([':t' => $now, ':id' => $existing['id']]);
                flash_set('ok', 'Checked out at ' . substr($now, 0, 5) . '.');
            } else {
                flash_set('error', 'Check in first before checking out.');
            }
        }
        // Bounce back to where the user clicked from (dashboard, checkin
        // page, or the attendance page itself). Only allow same-origin
        // paths to prevent open-redirect.
        $back = (string)($_POST['return_to'] ?? '/staff/attendance.php');
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//')) {
            $back = '/staff/attendance.php';
        }
        redirect($back);
    }

    if ($op === 'mark' && $isAdmin) {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $date   = $_POST['att_date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'present';
        if (!array_key_exists($status, staff_attendance_statuses())) $status = 'present';
        $cIn    = trim($_POST['check_in'] ?? '')  ?: null;
        $cOut   = trim($_POST['check_out'] ?? '') ?: null;
        $notes  = trim($_POST['notes'] ?? '')     ?: null;
        if ($uid > 0) {
            db()->prepare("
                INSERT INTO staff_attendance
                    (user_id, att_date, status, check_in, check_out, notes, marked_by)
                VALUES (:u, :d, :st, :ci, :co, :n, :by)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    check_in = VALUES(check_in),
                    check_out = VALUES(check_out),
                    notes = VALUES(notes),
                    marked_by = VALUES(marked_by)
            ")->execute([
                ':u' => $uid, ':d' => $date, ':st' => $status,
                ':ci' => $cIn, ':co' => $cOut, ':n' => $notes, ':by' => (int)$user['id'],
            ]);
            flash_set('ok', 'Attendance saved.');
        }
        redirect('/staff/attendance.php?date=' . urlencode($date));
    }
}

// ---- GET ----------------------------------------------------------------
// Anyone in the staff roster can view their own attendance row; the admin
// roster grid + op=mark still need the staff module.
if (!$inStaffRoster) {
    http_response_code(403);
    echo 'Forbidden — you do not have access to the staff module.';
    exit;
}
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

if ($isAdmin) {
    $roster = staff_roster(true);
    $stmt = db()->prepare("SELECT * FROM staff_attendance WHERE att_date = :d");
    $stmt->execute([':d' => $date]);
    $byUser = [];
    foreach ($stmt->fetchAll() as $r) $byUser[(int)$r['user_id']] = $r;
} else {
    $roster = [staff_member((int)$user['id'])];
    $byUser = [];
    $stmt = db()->prepare("SELECT * FROM staff_attendance WHERE user_id = :u AND att_date = :d");
    $stmt->execute([':u' => (int)$user['id'], ':d' => $date]);
    if ($r = $stmt->fetch()) $byUser[(int)$user['id']] = $r;
}

$today      = date('Y-m-d');
$myTodayRow = null;
if ($date === $today) {
    $myTodayRow = $byUser[(int)$user['id']] ?? null;
}

$pageTitle  = 'Staff attendance';
$wideLayout = $isAdmin;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a> ·
            <form method="get" style="display:inline;">
                <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
            </form>
        </p>
    </div>
</div>

<?php if ($date === $today): ?>
<div class="card" id="self">
    <h3>My check-in</h3>
    <?php if (!$myTodayRow): ?>
        <p class="muted">Not checked in yet today.</p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="self_in">
            <button class="btn btn-primary">Check in now</button>
        </form>
    <?php else: ?>
        <p>
            <span class="pill pill-status-<?= e($myTodayRow['status']) ?>"><?= e(staff_attendance_statuses()[$myTodayRow['status']] ?? $myTodayRow['status']) ?></span>
            <?php if ($myTodayRow['check_in']): ?> · in <?= e(substr($myTodayRow['check_in'], 0, 5)) ?><?php endif; ?>
            <?php if ($myTodayRow['check_out']): ?> · out <?= e(substr($myTodayRow['check_out'], 0, 5)) ?><?php endif; ?>
        </p>
        <?php if (!$myTodayRow['check_out']): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="self_out">
                <button class="btn">Check out now</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card">
    <h3><?= e(date('l, j M Y', strtotime($date))) ?></h3>

    <?php // Forms live outside the table so they don't cross <td> boundaries;
          // inputs reference them via the HTML5 form="..." attribute. ?>
    <?php foreach ($roster as $s): $uid = (int)$s['id']; ?>
        <form id="att-<?= $uid ?>" method="post" hidden>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="mark">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="hidden" name="att_date" value="<?= e($date) ?>">
        </form>
    <?php endforeach; ?>

    <table class="admin-table">
        <thead>
            <tr><th>Name</th><th>Status</th><th>In</th><th>Out</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($roster as $s):
                $uid = (int)$s['id'];
                $row = $byUser[$uid] ?? null;
                $fid = 'att-' . $uid;
            ?>
                <tr>
                    <td><a href="/staff/view.php?id=<?= $uid ?>"><?= e($s['name']) ?></a></td>
                    <td>
                        <select form="<?= $fid ?>" name="status">
                            <?php foreach (staff_attendance_statuses() as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= ($row['status'] ?? 'present') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input form="<?= $fid ?>" type="time" name="check_in"  value="<?= e($row['check_in']  ? substr($row['check_in'],  0, 5) : '') ?>"></td>
                    <td><input form="<?= $fid ?>" type="time" name="check_out" value="<?= e($row['check_out'] ? substr($row['check_out'], 0, 5) : '') ?>"></td>
                    <td><input form="<?= $fid ?>" type="text" name="notes" maxlength="255" value="<?= e((string)($row['notes'] ?? '')) ?>"></td>
                    <td><button form="<?= $fid ?>" class="btn btn-ghost" type="submit">Save</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
