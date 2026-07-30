<?php
/**
 * daycare/summary.php — attendance totals per person over a month.
 *
 * One row per daycare child and per staff member: Present, Late, Absent, Leave,
 * Other, Not marked, and hours clocked. Reads the same tables the daily sheet
 * writes (attendance / staff_attendance), so it also reflects statuses set
 * elsewhere in the app — a child marked absent by their class teacher shows up
 * here too.
 *
 *   GET ?month=YYYY-MM  → that month (defaults to the current one)
 *   GET ?csv=1          → same figures as a spreadsheet
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/daycare.php';

$user  = require_module('daycare');

$month = (string)($_GET['month'] ?? date('Y-m'));
[$from, $to] = daycare_month_bounds($month);
$month = substr($from, 0, 7);          // normalised back after clamping

$children = daycare_child_summary($from, $to);
$staff    = daycare_staff_summary($from, $to);
$childDays = daycare_recorded_days('attendance', 'attendance_date', $from, $to);
$staffDays = daycare_recorded_days('staff_attendance', 'att_date', $from, $to);

// ---- CSV -----------------------------------------------------------------
if (!empty($_GET['csv'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daycare-attendance-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Who', 'Type', 'Present', 'Late', 'Absent', 'Leave', 'Other', 'Not marked', 'Hours']);
    foreach ($children as $r) {
        $notMarked = max(0, $childDays - (int)$r['marked']);
        fputcsv($out, [$r['name'], 'Child', (int)$r['present'], (int)$r['late'], (int)$r['absent'],
                       (int)$r['on_leave'], (int)$r['other'], $notMarked, daycare_hours((int)$r['secs'])]);
    }
    foreach ($staff as $r) {
        $notMarked = max(0, $staffDays - (int)$r['marked']);
        fputcsv($out, [$r['name'], 'Staff', (int)$r['present'], (int)$r['late'], (int)$r['absent'],
                       (int)$r['on_leave'], (int)$r['other'], $notMarked, daycare_hours((int)$r['secs'])]);
    }
    fclose($out);
    exit;
}

/** One summary table. $rows need name/present/late/absent/on_leave/other/marked/secs. */
function daycare_summary_table(array $rows, int $recordedDays, string $whoLabel, string $subKey = ''): void
{
    ?>
    <div class="dc-scroll">
        <table class="admin-table dc-sum">
            <thead>
                <tr>
                    <th><?= e($whoLabel) ?></th>
                    <th>Present</th><th>Late</th><th>Absent</th><th>Leave</th>
                    <th>Other</th><th>Not marked</th><th>Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tot = ['present'=>0,'late'=>0,'absent'=>0,'on_leave'=>0,'other'=>0,'nm'=>0,'secs'=>0];
                foreach ($rows as $r):
                    $notMarked = max(0, $recordedDays - (int)$r['marked']);
                    $tot['present']  += (int)$r['present'];
                    $tot['late']     += (int)$r['late'];
                    $tot['absent']   += (int)$r['absent'];
                    $tot['on_leave'] += (int)$r['on_leave'];
                    $tot['other']    += (int)$r['other'];
                    $tot['nm']       += $notMarked;
                    $tot['secs']     += (int)$r['secs'];
                    $sub = $subKey !== '' && !empty($r[$subKey])
                         ? ($subKey === 'role' && function_exists('role_label') ? role_label((string)$r[$subKey]) : (string)$r[$subKey])
                         : '';
                ?>
                    <tr>
                        <td class="dc-name">
                            <?= e((string)$r['name']) ?>
                            <?php if ($sub !== ''): ?><span class="muted small"> · <?= e($sub) ?></span><?php endif; ?>
                        </td>
                        <td><strong><?= (int)$r['present'] ?></strong></td>
                        <td><?= (int)$r['late'] ?: '<span class="muted">—</span>' ?></td>
                        <td><?= (int)$r['absent'] ? '<span class="dc-bad">' . (int)$r['absent'] . '</span>' : '<span class="muted">—</span>' ?></td>
                        <td><?= (int)$r['on_leave'] ?: '<span class="muted">—</span>' ?></td>
                        <td><?= (int)$r['other'] ?: '<span class="muted">—</span>' ?></td>
                        <td><?= $notMarked > 0 ? '<span class="muted">' . $notMarked . '</span>' : '<span class="muted">—</span>' ?></td>
                        <td><?= e(daycare_hours((int)$r['secs'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows): ?>
                    <tr class="dc-total">
                        <td class="dc-name"><strong>All <?= count($rows) ?></strong></td>
                        <td><strong><?= $tot['present'] ?></strong></td>
                        <td><?= $tot['late'] ?></td>
                        <td><?= $tot['absent'] ?></td>
                        <td><?= $tot['on_leave'] ?></td>
                        <td><?= $tot['other'] ?></td>
                        <td><?= $tot['nm'] ?></td>
                        <td><strong><?= e(daycare_hours($tot['secs'])) ?></strong></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$pageTitle  = 'Daycare attendance summary';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance summary</h1>
        <p class="muted">
            <?= e(date('F Y', strtotime($from))) ?>
            · <?= e(date('j M', strtotime($from))) ?>–<?= e(date('j M', strtotime($to))) ?>
            · <?= count($children) ?> child<?= count($children) === 1 ? '' : 'ren' ?>,
            <?= count($staff) ?> staff
        </p>
    </div>
    <div class="actionbar">
        <form method="get" class="dc-datepick">
            <label for="m" class="muted small">Month</label>
            <input id="m" type="month" name="month" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>"
                   onchange="this.form.submit()">
        </form>
        <a class="btn btn-ghost" href="/daycare/summary.php?month=<?= e($month) ?>&amp;csv=1">Download CSV</a>
        <a class="btn" href="/daycare/index.php">Today's sheet</a>
    </div>
</div>

<div class="card">
    <h3>Daycare children</h3>
    <?php if (!$children): ?>
        <p class="muted">No children are in the <strong>Daycare</strong> grade yet.</p>
    <?php else: ?>
        <?php daycare_summary_table($children, $childDays, 'Child'); ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Staff</h3>
    <?php if (!$staff): ?>
        <p class="muted">No active staff found.</p>
    <?php else: ?>
        <?php daycare_summary_table($staff, $staffDays, 'Staff', 'role'); ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>How to read this</h3>
    <ul class="muted small" style="margin:0; padding-left:1.1rem;">
        <li><strong>Not marked</strong> — days in this month where attendance was recorded for
            <em>someone</em> but not for this person
            (<?= (int)$childDays ?> such day<?= $childDays === 1 ? '' : 's' ?> for children,
            <?= (int)$staffDays ?> for staff). Counting every calendar day instead would
            charge people for weekends and days the school was closed.</li>
        <li><strong>Leave</strong> — staff records call this <em>leave</em>; children's records call
            the same thing <em>excused</em>. Both are shown in this column.</li>
        <li><strong>Other</strong> — holidays, plus WFH for staff. Kept separate so the numbers on a
            row add up to the days recorded rather than being folded into one of the four.</li>
        <li><strong>Hours</strong> — from check-in and check-out times, counting only days where both
            were recorded.</li>
        <li>Checking someone in on the daily sheet marks them <strong>present</strong>. Absent, late
            and leave come from the attendance and leave pages elsewhere in the app, so a child
            who simply never arrived shows under <strong>Not marked</strong> rather than Absent.</li>
    </ul>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
