<?php
/**
 * index.php — unified home / module picker.
 *
 * If the user has only one module, redirect straight into it.
 * If they have both (or are admin), show a picker with quick stats.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

$user = require_login();

// ---- Lazy attendance reminder ----
// After 11:00 local time, if the teacher hasn't marked attendance for any of
// their students today, fire one notification per day. Cheap query + dedup
// guard so we don't spam.
try {
    $hour = (int)(new DateTime('now'))->format('H');
    if ($hour >= 11 && ($user['role'] === 'teacher' || user_has_module($user, 'students') || user_has_module($user, 'montessori'))) {
        $today = (new DateTime('today'))->format('Y-m-d');

        // Has this user already received an attendance reminder today?
        $alreadySent = (bool)db()->prepare("
            SELECT 1 FROM notifications
            WHERE user_id = :uid AND event_type = 'attendance_reminder'
              AND DATE(created_at) = :today
            LIMIT 1
        ");
        $alreadySent->execute([':uid' => $user['id'], ':today' => $today]);
        if (!$alreadySent->fetchColumn()) {
            // How many of this user's active students still have no attendance row today?
            $missing = db()->prepare("
                SELECT COUNT(*) FROM students s
                LEFT JOIN attendance a
                  ON a.student_id = s.id AND a.attendance_date = :today
                WHERE COALESCE(s.is_active, 1) = 1
                  AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
                  AND s.teacher_id = :uid
                  AND a.id IS NULL
            ");
            $missing->execute([':today' => $today, ':uid' => $user['id']]);
            $missingCount = (int)$missing->fetchColumn();
            if ($missingCount > 0) {
                notify(
                    (int)$user['id'], 'attendance', 'attendance_reminder',
                    "$missingCount student" . ($missingCount === 1 ? '' : 's') . " still need attendance for today",
                    "It's after 11am and " . $missingCount . " of your students are unmarked for $today. Tap below to mark them now.",
                    '/students/attendance.php',
                    false  // don't email this — it's a soft nudge; bell only.
                );
            }
        }
    }
} catch (Throwable $e) { /* attendance reminder is best-effort */ }

$hasTasks    = user_has_module($user, 'tasks');
$hasMontess  = user_has_module($user, 'montessori');
$hasStudents = user_has_module($user, 'students');
$hasCrm      = user_has_module($user, 'crm');
$hasRecruit  = user_has_module($user, 'recruitment');
$hasStaff    = user_has_module($user, 'staff');
$hasExpenses = user_has_module($user, 'expenses');
$hasFees     = user_has_module($user, 'fees');

// Single-module users go straight in.
$moduleCount = (int)$hasTasks + (int)$hasMontess + (int)$hasStudents + (int)$hasCrm + (int)$hasRecruit + (int)$hasStaff + (int)$hasExpenses + (int)$hasFees;
if ($moduleCount === 1) {
    if ($hasTasks)    redirect('/tasks/index.php');
    if ($hasMontess)  redirect('/assessment/index.php');
    if ($hasStudents) redirect('/students/index.php');
    if ($hasCrm)      redirect('/crm/index.php');
    if ($hasRecruit)  redirect('/recruitment/index.php');
    if ($hasStaff)    redirect('/staff/index.php');
    if ($hasExpenses) redirect('/expenses/index.php');
    if ($hasFees)     redirect('/fees/index.php');
}
// 0 or 2+ modules → render the picker below.

// ---------- Stats for the picker tiles ------------------------------------
$tasksStats = ['todo' => 0, 'today' => 0, 'overdue' => 0];
if ($hasTasks) {
    try {
        $today = (new DateTime('today'))->format('Y-m-d');
        $row = db()->prepare("
            SELECT
              SUM(CASE WHEN c.is_done = 0 THEN 1 ELSE 0 END)                                AS open_tasks,
              SUM(CASE WHEN c.is_done = 0 AND t.due_date = :t1                THEN 1 ELSE 0 END) AS due_today,
              SUM(CASE WHEN c.is_done = 0 AND t.due_date IS NOT NULL AND t.due_date < :t2 THEN 1 ELSE 0 END) AS overdue
            FROM tasks t
            LEFT JOIN task_columns c ON c.id = t.column_id
        ");
        $row->execute([':t1' => $today, ':t2' => $today]);
        $r = $row->fetch();
        $tasksStats = [
            'todo'    => (int)($r['open_tasks'] ?? 0),
            'today'   => (int)($r['due_today']  ?? 0),
            'overdue' => (int)($r['overdue']    ?? 0),
        ];
    } catch (Throwable $e) { /* table may not exist yet — leave zeros */ }
}

$studentsStats = ['total' => 0, 'active' => 0];
if ($hasStudents) {
    try {
        $studentsStats['total']  = (int)db()->query("SELECT COUNT(*) FROM students")->fetchColumn();
        // is_active was added by migrate_002 — if it's missing the COALESCE keeps the count correct.
        $studentsStats['active'] = (int)db()->query("SELECT COUNT(*) FROM students WHERE COALESCE(is_active, 1) = 1")->fetchColumn();
    } catch (Throwable $e) { /* table may be in mid-migration */ }
}

$crmStats = ['open' => 0, 'weighted' => 0.0];
if ($hasCrm) {
    try {
        require_once __DIR__ . '/includes/crm.php';
        $open = "'" . implode("','", crm_open_statuses()) . "'";
        $crmStats['open'] = (int)db()->query(
            "SELECT COUNT(*) FROM inquiry_families WHERE status IN ($open)"
        )->fetchColumn();
        $proj = crm_revenue_projection();
        $crmStats['weighted'] = $proj['weighted'];
    } catch (Throwable $e) { /* tables may not exist yet */ }
}

$recruitStats = ['open' => 0, 'interviews_7d' => 0];
if ($hasRecruit) {
    try {
        require_once __DIR__ . '/includes/recruitment.php';
        $open = "'" . implode("','", recruit_open_statuses()) . "'";
        $recruitStats['open'] = (int)db()->query(
            "SELECT COUNT(*) FROM recruit_candidates WHERE status IN ($open)"
        )->fetchColumn();
        $recruitStats['interviews_7d'] = (int)db()->query("
            SELECT COUNT(*) FROM recruit_interviews
            WHERE occurred_at >= NOW() AND occurred_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        ")->fetchColumn();
    } catch (Throwable $e) { /* tables may not exist yet */ }
}

$admTotal = 19000; $monthlyTotal = 8200;
if ($hasFees) {
    try {
        require_once __DIR__ . '/includes/fees.php';
        $fs = fee_structure();
        $admTotal     = array_sum(array_column($fs['admission'], 'amount'));
        $monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];
    } catch (Throwable $e) {}
}

$expensesStats = ['this_month' => 0, 'pending' => 0, 'total' => 0.0];
if ($hasExpenses) {
    try {
        $monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
        if ($user['role'] === 'admin') {
            $stmt = db()->prepare("
                SELECT
                  COUNT(*)                                                              AS n_month,
                  SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END)                 AS n_pending,
                  COALESCE(SUM(CASE WHEN expense_date >= :ms THEN amount ELSE 0 END), 0) AS total_month
                FROM expenses
            ");
            $stmt->execute([':ms' => $monthStart]);
        } else {
            $stmt = db()->prepare("
                SELECT
                  COUNT(*)                                                              AS n_month,
                  SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END)                 AS n_pending,
                  COALESCE(SUM(CASE WHEN expense_date >= :ms THEN amount ELSE 0 END), 0) AS total_month
                FROM expenses
                WHERE user_id = :u
            ");
            $stmt->execute([':ms' => $monthStart, ':u' => $user['id']]);
        }
        $r = $stmt->fetch();
        $expensesStats = [
            'this_month' => (int)($r['n_month']     ?? 0),
            'pending'    => (int)($r['n_pending']   ?? 0),
            'total'      => (float)($r['total_month'] ?? 0),
        ];
    } catch (Throwable $e) { /* table may not exist yet — leave zeros */ }
}

$mttStats = ['students' => 0, 'pending_this_month' => 0];
if ($hasMontess) {
    try {
        $month = current_month_year();
        if ($user['role'] === 'admin') {
            $mttStats['students'] = (int) db()->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $stmt = db()->prepare("
                SELECT COUNT(*) FROM students s
                WHERE NOT EXISTS (
                    SELECT 1 FROM evaluation_cards e
                    WHERE e.student_id = s.id AND e.month_year = :m
                )
            ");
            $stmt->execute([':m' => $month]);
        } else {
            $stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE teacher_id = :u");
            $stmt->execute([':u' => $user['id']]);
            $mttStats['students'] = (int)$stmt->fetchColumn();

            $stmt = db()->prepare("
                SELECT COUNT(*) FROM students s
                WHERE s.teacher_id = :u
                AND NOT EXISTS (
                    SELECT 1 FROM evaluation_cards e
                    WHERE e.student_id = s.id AND e.month_year = :m AND e.teacher_id = :u2
                )
            ");
            $stmt->execute([':u' => $user['id'], ':m' => $month, ':u2' => $user['id']]);
        }
        $mttStats['pending_this_month'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* tables may not exist yet */ }
}

$pageTitle = 'Home — Little Graduates';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Welcome, <?= e(first_name($user['name'])) ?> 👋</h1>
        <p class="muted"><?= e(date('l, j M')) ?>
            <?php if ($hasMontess): ?> · Assessment month: <strong><?= e(month_year_label(current_month_year())) ?></strong><?php endif; ?>
        </p>
    </div>
</div>

<ul class="module-grid" role="list">
    <?php if ($hasStudents): ?>
        <li>
            <a class="module-tile" href="/students/index.php">
                <h2>Students</h2>
                <p class="muted">Profiles, parents, contacts, attendance and fees.</p>
                <div class="module-stats">
                    <span class="pill"><?= (int)$studentsStats['active'] ?> active</span>
                    <?php if ($studentsStats['total'] !== $studentsStats['active']): ?>
                        <span class="pill"><?= (int)$studentsStats['total'] ?> total</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasMontess): ?>
        <li>
            <a class="module-tile" href="/assessment/index.php">
                <h2>Assessment</h2>
                <p class="muted">Trainee teacher assessment — student progress, baselines, monthly cards.</p>
                <div class="module-stats">
                    <span class="pill"><?= (int)$mttStats['students'] ?> student<?= $mttStats['students'] === 1 ? '' : 's' ?></span>
                    <?php if ($mttStats['pending_this_month'] > 0): ?>
                        <span class="pill pill-warn"><?= (int)$mttStats['pending_this_month'] ?> pending this month</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasTasks): ?>
        <li>
            <a class="module-tile" href="/tasks/index.php">
                <h2>Tasks</h2>
                <p class="muted">Team task board — kanban, recurring routines, calendar view.</p>
                <div class="module-stats">
                    <span class="pill"><?= (int)$tasksStats['todo'] ?> open</span>
                    <?php if ($tasksStats['today'] > 0): ?>
                        <span class="pill"><?= (int)$tasksStats['today'] ?> due today</span>
                    <?php endif; ?>
                    <?php if ($tasksStats['overdue'] > 0): ?>
                        <span class="pill pill-warn"><?= (int)$tasksStats['overdue'] ?> overdue</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasCrm): ?>
        <li>
            <a class="module-tile" href="/crm/index.php">
                <h2>Admissions</h2>
                <p class="muted">Prospect pipeline, tours, follow-ups and projected revenue.</p>
                <div class="module-stats">
                    <span class="pill"><?= (int)$crmStats['open'] ?> open inquir<?= (int)$crmStats['open'] === 1 ? 'y' : 'ies' ?></span>
                    <?php if ($crmStats['weighted'] > 0): ?>
                        <span class="pill">₹<?= number_format($crmStats['weighted'], 0) ?>/mo projected</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasRecruit): ?>
        <li>
            <a class="module-tile" href="/recruitment/index.php">
                <h2>Recruitment</h2>
                <p class="muted">Candidate pipeline, demo days, scorecards and hires.</p>
                <div class="module-stats">
                    <span class="pill"><?= (int)$recruitStats['open'] ?> open candidate<?= (int)$recruitStats['open'] === 1 ? '' : 's' ?></span>
                    <?php if ($recruitStats['interviews_7d'] > 0): ?>
                        <span class="pill pill-warn"><?= (int)$recruitStats['interviews_7d'] ?> interview<?= (int)$recruitStats['interviews_7d'] === 1 ? '' : 's' ?> this week</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasStaff): ?>
        <?php
        $staffStats = ['pending_leave' => 0, 'open_msgs' => 0];
        try {
            $staffStats['pending_leave'] = (int)db()->query("SELECT COUNT(*) FROM staff_leave_requests WHERE status='pending'")->fetchColumn();
            $staffStats['open_msgs']     = (int)db()->query("SELECT COUNT(*) FROM staff_messages WHERE status IN ('open','acknowledged')")->fetchColumn();
        } catch (Throwable $e) { /* tables may not exist yet */ }
        ?>
        <li>
            <a class="module-tile" href="/staff/index.php">
                <h2>Staff</h2>
                <p class="muted">Attendance, leave, 1:1 notes, HR documents and messages to management.</p>
                <div class="module-stats">
                    <?php if ($staffStats['pending_leave'] > 0): ?>
                        <span class="pill pill-warn"><?= (int)$staffStats['pending_leave'] ?> pending leave</span>
                    <?php endif; ?>
                    <?php if ($staffStats['open_msgs'] > 0): ?>
                        <span class="pill"><?= (int)$staffStats['open_msgs'] ?> open message<?= $staffStats['open_msgs'] === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasExpenses): ?>
        <li>
            <a class="module-tile" href="/expenses/index.php">
                <h2>Expenses</h2>
                <p class="muted">Reimbursable spend — snap a receipt, let OCR pre-fill the amount.</p>
                <div class="module-stats">
                    <span class="pill">₹<?= number_format((float)$expensesStats['total'], 2) ?> this month</span>
                    <?php if ($expensesStats['pending'] > 0): ?>
                        <span class="pill pill-warn"><?= (int)$expensesStats['pending'] ?> awaiting review</span>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if ($hasFees): ?>
        <li>
            <a class="module-tile" href="/fees/index.php">
                <h2>Fees</h2>
                <p class="muted">Fee calculator, personalised parent fee guides, and fee configuration.</p>
                <div class="module-stats">
                    <span class="pill">Admission <?= e(fee_inr($admTotal ?? 19000)) ?></span>
                    <span class="pill">Monthly <?= e(fee_inr($monthlyTotal ?? 8200)) ?></span>
                </div>
            </a>
        </li>
    <?php endif; ?>
    <?php if (!$hasTasks && !$hasMontess && !$hasStudents && !$hasCrm && !$hasRecruit && !$hasStaff && !$hasExpenses && !$hasFees): ?>
        <li>
            <div class="empty">
                <p>No modules assigned yet. Ask an admin to grant you access from <a href="/admin.php">Admin → Users</a>.</p>
            </div>
        </li>
    <?php endif; ?>
</ul>

<?php require __DIR__ . '/includes/footer.php'; ?>
