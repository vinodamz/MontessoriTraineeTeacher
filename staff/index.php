<?php
/**
 * staff/index.php — Staff roster (admins) / self-redirect (non-admins).
 *
 * Admins see a table of every staff member with at-a-glance counts:
 * pending leave requests, this-month attendance, open messages. Non-admins
 * land on their own view.php — the rest of the module is self-service from
 * there.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');

if (!staff_is_admin($user)) {
    redirect('/staff/view.php?id=' . (int)$user['id']);
}

$roster = staff_roster();
$year   = (int)date('Y');
$month  = (int)date('n');

// Aggregate widgets in one shot, keyed by user_id.
$pendingByUser = [];
foreach (db()->query("
    SELECT user_id, COUNT(*) AS n FROM staff_leave_requests
    WHERE status = 'pending' GROUP BY user_id
")->fetchAll() as $r) $pendingByUser[(int)$r['user_id']] = (int)$r['n'];

$openMsgs = (int)db()->query("
    SELECT COUNT(*) FROM staff_messages WHERE status IN ('open','acknowledged')
")->fetchColumn();
$pendingAllLeaves = array_sum($pendingByUser);

$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-t', strtotime($start));
$presentStmt = db()->prepare("
    SELECT user_id, COUNT(*) AS n FROM staff_attendance
    WHERE status IN ('present','late','wfh') AND att_date BETWEEN :s AND :e
    GROUP BY user_id
");
$presentStmt->execute([':s' => $start, ':e' => $end]);
$presentByUser = [];
foreach ($presentStmt->fetchAll() as $r) $presentByUser[(int)$r['user_id']] = (int)$r['n'];

$pageTitle  = 'Staff';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Staff</h1>
        <p class="muted">
            <?= count($roster) ?> on roster
            · <?= $pendingAllLeaves ?> pending leave request<?= $pendingAllLeaves === 1 ? '' : 's' ?>
            · <?= $openMsgs ?> open message<?= $openMsgs === 1 ? '' : 's' ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/staff/attendance.php">Today's attendance</a>
        <a class="btn" href="/staff/leave.php">Leave requests</a>
        <a class="btn" href="/staff/messages.php">Messages</a>
    </div>
</div>

<div class="card">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Present <?= e(date('M')) ?></th>
                <th>Pending leave</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$roster): ?>
                <tr><td colspan="6" class="muted">No staff yet — hire a recruit from <a href="/recruitment/index.php">Recruitment</a>.</td></tr>
            <?php endif; ?>
            <?php foreach ($roster as $s): ?>
                <?php $uid = (int)$s['id']; ?>
                <tr>
                    <td><a href="/staff/view.php?id=<?= $uid ?>"><?= e($s['name']) ?></a></td>
                    <td><?= e(ucfirst((string)$s['role'])) ?></td>
                    <td>
                        <?php if ((int)$s['active'] === 1): ?>
                            <span class="pill pill-status-present">Active</span>
                        <?php else: ?>
                            <span class="pill">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)($presentByUser[$uid] ?? 0) ?> d</td>
                    <td>
                        <?php if (!empty($pendingByUser[$uid])): ?>
                            <a href="/staff/leave.php?user_id=<?= $uid ?>"><?= (int)$pendingByUser[$uid] ?> pending</a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-ghost" href="/staff/view.php?id=<?= $uid ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
