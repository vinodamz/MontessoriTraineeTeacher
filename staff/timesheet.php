<?php
/**
 * staff/timesheet.php — monthly (or custom-range) check-in / check-out register.
 *
 * One section per staff member: each day they were marked, with in/out/hours
 * and a leave call-out. Approved leave days appear even when attendance was
 * never stamped. Admins (or anyone with the Staff module) can filter by
 * person; everyone else sees only themselves.
 *
 *   GET ?from=YYYY-MM-DD&to=YYYY-MM-DD&user_id=N
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_login();
$inStaffRoster = staff_is_on_roster($user);
$canSeeAll     = staff_is_admin($user) || user_has_module($user, 'staff');

if (!$inStaffRoster && !$canSeeAll) {
    http_response_code(403);
    echo 'Forbidden — only staff can view the timesheet.';
    exit;
}

$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$lastStart  = date('Y-m-01', strtotime('first day of last month'));
$lastEnd    = date('Y-m-t', strtotime('last day of last month'));

$preset = (string)($_GET['preset'] ?? '');
if ($preset === 'this_month') {
    $_GET['from'] = $monthStart;
    $_GET['to']   = $monthEnd;
} elseif ($preset === 'last_month') {
    $_GET['from'] = $lastStart;
    $_GET['to']   = $lastEnd;
} elseif ($preset === 'this_week') {
    $_GET['from'] = date('Y-m-d', strtotime('monday this week'));
    $_GET['to']   = date('Y-m-d', strtotime('sunday this week'));
}

[$from, $to] = staff_timesheet_range($_GET['from'] ?? null, $_GET['to'] ?? null);

$filterUser = (int)($_GET['user_id'] ?? 0);
if (!$canSeeAll) {
    $filterUser = (int)$user['id'];
}

$roster = $canSeeAll ? staff_roster() : [];
$sheet  = staff_timesheet($from, $to, $filterUser > 0 ? $filterUser : null);

$filterQs = function (array $extra = []) use ($from, $to, $filterUser, $canSeeAll): string {
    $q = array_merge(['from' => $from, 'to' => $to], $extra);
    if ($canSeeAll && $filterUser > 0 && !array_key_exists('user_id', $extra)) {
        $q['user_id'] = $filterUser;
    }
    return '/staff/timesheet.php?' . http_build_query($q);
};

$leaveTypes = staff_leave_types();
$statuses   = staff_attendance_statuses();
$halfLabel  = ['first' => '1st half', 'second' => '2nd half'];

$pageTitle  = 'Staff timesheet';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Check-in / check-out</h1>
        <p class="muted">
            <a href="/staff/index.php">← Staff</a>
            · <a href="/staff/attendance.php">Today</a>
            · <?= e(date('j M Y', strtotime($from))) ?> – <?= e(date('j M Y', strtotime($to))) ?>
        </p>
    </div>
</div>

<div class="card timesheet-filter-card">
    <form method="get" class="timesheet-filters">
        <div class="field">
            <label for="ts-from">From</label>
            <input id="ts-from" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="ts-to">To</label>
            <input id="ts-to" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <?php if ($canSeeAll): ?>
        <div class="field">
            <label for="ts-user">Staff</label>
            <select id="ts-user" name="user_id">
                <option value="0">Everyone</option>
                <?php foreach ($roster as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $filterUser === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="field timesheet-filters__go">
            <label>&nbsp;</label>
            <button class="btn btn-primary" type="submit">Show</button>
        </div>
    </form>
    <p class="muted small timesheet-presets">
        <a href="<?= e($filterQs(['from' => $monthStart, 'to' => $monthEnd, 'user_id' => $filterUser])) ?>">This month</a>
        · <a href="<?= e($filterQs(['from' => $lastStart, 'to' => $lastEnd, 'user_id' => $filterUser])) ?>">Last month</a>
        · <a href="<?= e($filterQs(['from' => date('Y-m-d', strtotime('monday this week')), 'to' => date('Y-m-d', strtotime('sunday this week')), 'user_id' => $filterUser])) ?>">This week</a>
    </p>
</div>

<?php if (!$sheet): ?>
    <div class="empty">No check-ins, check-outs or leave in this range.</div>
<?php endif; ?>

<?php foreach ($sheet as $person):
    $tot = $person['totals'];
    $presentish = (int)$tot['present'] + (int)$tot['late'] + (int)$tot['wfh'];
?>
<div class="card timesheet-person" id="u-<?= (int)$person['user_id'] ?>">
    <h3>
        <a href="/staff/view.php?id=<?= (int)$person['user_id'] ?>"><?= e($person['name']) ?></a>
        <span class="muted" style="font-weight:400; font-size:.9rem;"> · <?= e(role_label($person['role'])) ?></span>
    </h3>
    <p class="muted small" style="margin-top:0;">
        <?= (int)$tot['rows'] ?> day<?= (int)$tot['rows'] === 1 ? '' : 's' ?> recorded
        · <?= $presentish ?> present
        <?php if ((int)$tot['leave_days'] > 0): ?>
            · <strong><?= (int)$tot['leave_days'] ?> on leave</strong>
        <?php endif; ?>
        <?php if ((int)$tot['clocked_days'] > 0): ?>
            · <?= e(number_format((float)$tot['hours'], 1)) ?> h clocked
        <?php endif; ?>
    </p>
    <div class="timesheet-scroll">
        <table class="admin-table timesheet-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Hours</th>
                    <th>Leave</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($person['days'] as $day):
                    $st = (string)($day['status'] ?? '');
                    $onLeave = !empty($day['on_leave']);
                    $pending = !empty($day['leave_pending']);
                    $leaveTxt = '';
                    if ($onLeave) {
                        $leaveTxt = $leaveTypes[$day['leave_type'] ?? ''] ?? 'On leave';
                        $hf = $halfLabel[$day['leave_half'] ?? ''] ?? '';
                        if ($hf !== '') $leaveTxt .= ' · ' . $hf;
                    } elseif ($pending) {
                        $leaveTxt = 'Pending';
                        $lt = $leaveTypes[$day['leave_type'] ?? ''] ?? '';
                        if ($lt !== '') $leaveTxt .= ' · ' . $lt;
                    }
                ?>
                <tr class="<?= $onLeave ? 'timesheet-leave' : ($pending ? 'timesheet-leave-pending' : '') ?>">
                    <td>
                        <a href="/staff/attendance.php?date=<?= e($day['date']) ?>">
                            <?= e(date('D j M', strtotime($day['date']))) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($st !== ''): ?>
                            <span class="pill pill-status-<?= e($st) ?>"><?= e($statuses[$st] ?? $st) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="timesheet-time"><?= $day['check_in'] ? e(staff_time_label((string)$day['check_in'])) : '—' ?></td>
                    <td class="timesheet-time"><?= $day['check_out'] ? e(staff_time_label((string)$day['check_out'])) : '—' ?></td>
                    <td class="timesheet-time"><?= $day['hours'] !== null ? e(number_format((float)$day['hours'], 1)) : '—' ?></td>
                    <td>
                        <?php if ($onLeave): ?>
                            <span class="pill pill-leave-callout"><?= e($leaveTxt) ?></span>
                        <?php elseif ($pending): ?>
                            <span class="pill"><?= e($leaveTxt) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted small"><?= $day['notes'] ? e((string)$day['notes']) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
