<?php
/**
 * students/attendance_history.php — per-student attendance log + monthly summary.
 *
 *   GET ?student_id=N&month=YYYY-MM   → month-by-month rows for that student.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_tabs.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — no access to attendance.';
    exit;
}

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($studentId <= 0) { redirect('/students/index.php'); }

// Load the student.
$stmt = db()->prepare("
    SELECT s.*, u.name AS teacher_name
    FROM students s
    LEFT JOIN users u ON u.id = s.teacher_id
    WHERE s.id = :id
");
$stmt->execute([':id' => $studentId]);
$s = $stmt->fetch();
if (!$s) {
    flash_set('error', 'Student not found.');
    redirect('/students/index.php');
}

// Teachers without students module can only see their own students.
$canSeeAll = $user['role'] === 'admin' || user_has_module($user, 'students');
if (!$canSeeAll && (int)$s['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'Forbidden — not your student.';
    exit;
}

$full = trim($s['first_name'] . ' ' . $s['last_name']);

// Month selector — default to current month.
$thisMonth = (new DateTime('first day of this month'))->format('Y-m');
$month     = $_GET['month'] ?? $thisMonth;
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = $thisMonth;

$startOfMonth = $month . '-01';
$endOfMonth   = (new DateTime($startOfMonth))->modify('first day of next month')->format('Y-m-d');

$histStmt = db()->prepare("
    SELECT a.attendance_date, a.status, a.notes, a.marked_at,
           u.name AS marked_by_name
    FROM attendance a
    LEFT JOIN users u ON u.id = a.marked_by_user_id
    WHERE a.student_id = :sid
      AND a.attendance_date >= :start
      AND a.attendance_date <  :end
    ORDER BY a.attendance_date DESC
");
$histStmt->execute([':sid' => $studentId, ':start' => $startOfMonth, ':end' => $endOfMonth]);
$history = $histStmt->fetchAll();

// Monthly summary across the academic year so far.
$yearStartIso = (new DateTime('today'))->format('n') >= 6
    ? (new DateTime('today'))->format('Y') . '-06-01'
    : ((int)(new DateTime('today'))->format('Y') - 1) . '-06-01';

$sumStmt = db()->prepare("
    SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') AS ym,
           SUM(a.status = 'present') AS n_present,
           SUM(a.status = 'absent')  AS n_absent,
           SUM(a.status = 'late')    AS n_late,
           SUM(a.status = 'excused') AS n_excused,
           SUM(a.status = 'holiday') AS n_holiday,
           COUNT(*)                  AS n_total
    FROM attendance a
    WHERE a.student_id = :sid
      AND a.attendance_date >= :start
    GROUP BY ym
    ORDER BY ym DESC
");
$sumStmt->execute([':sid' => $studentId, ':start' => $yearStartIso]);
$monthlySummary = $sumStmt->fetchAll();

// Prev/next month nav.
$prevMonth = (new DateTime($startOfMonth))->modify('-1 month')->format('Y-m');
$nextMonth = (new DateTime($startOfMonth))->modify('+1 month')->format('Y-m');

$pageTitle = 'Attendance — ' . $full;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted">
            <a href="/students/view.php?id=<?= $studentId ?>"><?= e($full) ?></a>
            · <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
            <?php if (!empty($s['teacher_name'])): ?> · <?= e($s['teacher_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
        <a class="btn" href="/students/attendance.php">Mark today</a>
    </div>
</div>

<?php student_tab_strip($studentId, 'attendance', $user); ?>

<section class="card">
    <h2>Monthly summary (this academic year)</h2>
    <?php if (!$monthlySummary): ?>
        <p class="muted">No attendance recorded yet this year.</p>
    <?php else: ?>
        <table class="att-summary">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Excused</th>
                    <th>Holiday</th>
                    <th>%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlySummary as $m):
                    $pct = ($m['n_total'] - $m['n_holiday']) > 0
                        ? round(($m['n_present'] / ($m['n_total'] - $m['n_holiday'])) * 100)
                        : null;
                    $label = DateTime::createFromFormat('Y-m', $m['ym'])->format('M Y');
                ?>
                    <tr>
                        <td><a href="?student_id=<?= $studentId ?>&month=<?= e($m['ym']) ?>"><?= e($label) ?></a></td>
                        <td><?= (int)$m['n_present'] ?></td>
                        <td><?= (int)$m['n_absent']  ?></td>
                        <td><?= (int)$m['n_late']    ?></td>
                        <td><?= (int)$m['n_excused'] ?></td>
                        <td><?= (int)$m['n_holiday'] ?></td>
                        <td><?= $pct === null ? '—' : ($pct . '%') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <div class="page-head" style="margin:0 0 .75rem;">
        <h2 style="margin:0;"><?= e(DateTime::createFromFormat('Y-m', $month)->format('F Y')) ?></h2>
        <div class="actionbar">
            <a class="btn btn-ghost" href="?student_id=<?= $studentId ?>&month=<?= e($prevMonth) ?>">‹ Prev</a>
            <a class="btn btn-ghost" href="?student_id=<?= $studentId ?>&month=<?= e($nextMonth) ?>">Next ›</a>
        </div>
    </div>

    <?php if (!$history): ?>
        <p class="muted">No attendance recorded for this month.</p>
    <?php else: ?>
        <table class="att-summary">
            <thead><tr><th>Date</th><th>Status</th><th>Notes</th><th>Marked by</th><th>At</th></tr></thead>
            <tbody>
                <?php foreach ($history as $r):
                    $d = DateTime::createFromFormat('Y-m-d', $r['attendance_date']);
                ?>
                    <tr>
                        <td><?= e($d ? $d->format('D j M') : $r['attendance_date']) ?></td>
                        <td><span class="pill att-pill att-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                        <td><?= e($r['notes'] ?? '') ?></td>
                        <td><?= e($r['marked_by_name'] ?? '') ?></td>
                        <td><?= e(substr((string)$r['marked_at'], 0, 16)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
