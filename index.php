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
$hasLogbook  = user_has_module($user, 'logbook');

// Single-module users go straight in.
$moduleCount = (int)$hasTasks + (int)$hasMontess + (int)$hasStudents + (int)$hasCrm + (int)$hasRecruit + (int)$hasStaff + (int)$hasExpenses + (int)$hasFees + (int)$hasLogbook;
if ($moduleCount === 1) {
    if ($hasTasks)    redirect('/tasks/index.php');
    if ($hasMontess)  redirect('/assessment/index.php');
    if ($hasStudents) redirect('/students/index.php');
    if ($hasCrm)      redirect('/crm/index.php');
    if ($hasRecruit)  redirect('/recruitment/index.php');
    if ($hasStaff)    redirect('/staff/index.php');
    if ($hasExpenses) redirect('/expenses/index.php');
    if ($hasFees)     redirect('/fees/index.php');
    if ($hasLogbook)  redirect('/logbook/index.php');
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

<?php
$staffStats = ['pending_leave' => 0, 'open_msgs' => 0];
if ($hasStaff) {
    try {
        $staffStats['pending_leave'] = (int)db()->query("SELECT COUNT(*) FROM staff_leave_requests WHERE status='pending'")->fetchColumn();
        $staffStats['open_msgs']     = (int)db()->query("SELECT COUNT(*) FROM staff_messages WHERE status IN ('open','acknowledged')")->fetchColumn();
    } catch (Throwable $e) {}
}

// App catalog — each entry: icon SVG, gradient class, subtitle, stats builder.
$apps = [];
if ($hasStudents) {
    $stats = [['label' => $studentsStats['active'] . ' active', 'tone' => '']];
    if ($studentsStats['total'] !== $studentsStats['active']) $stats[] = ['label' => $studentsStats['total'] . ' total', 'tone' => ''];
    $apps[] = ['key' => 'students', 'name' => 'Students', 'subtitle' => 'Roster · Parents · Attendance', 'href' => '/students/index.php', 'stats' => $stats];
}
if ($hasMontess) {
    $stats = [['label' => $mttStats['students'] . ' student' . ($mttStats['students'] === 1 ? '' : 's'), 'tone' => '']];
    if ($mttStats['pending_this_month'] > 0) $stats[] = ['label' => $mttStats['pending_this_month'] . ' pending', 'tone' => 'warn'];
    $apps[] = ['key' => 'assessment', 'name' => 'Assessment', 'subtitle' => 'Trainee teacher progress', 'href' => '/assessment/index.php', 'stats' => $stats];
}
if ($hasTasks) {
    $stats = [['label' => $tasksStats['todo'] . ' open', 'tone' => '']];
    if ($tasksStats['today']   > 0) $stats[] = ['label' => $tasksStats['today'] . ' today',   'tone' => ''];
    if ($tasksStats['overdue'] > 0) $stats[] = ['label' => $tasksStats['overdue'] . ' overdue', 'tone' => 'warn'];
    $apps[] = ['key' => 'tasks', 'name' => 'Tasks', 'subtitle' => 'Team board · Routines', 'href' => '/tasks/index.php', 'stats' => $stats];
}
if ($hasCrm) {
    $stats = [['label' => $crmStats['open'] . ' inquiries', 'tone' => '']];
    if ($crmStats['weighted'] > 0) $stats[] = ['label' => '₹' . number_format($crmStats['weighted'], 0) . '/mo', 'tone' => ''];
    $apps[] = ['key' => 'admissions', 'name' => 'Admissions', 'subtitle' => 'Pipeline · Tours · Follow-ups', 'href' => '/crm/index.php', 'stats' => $stats];
}
if ($hasRecruit) {
    $stats = [['label' => $recruitStats['open'] . ' candidates', 'tone' => '']];
    if ($recruitStats['interviews_7d'] > 0) $stats[] = ['label' => $recruitStats['interviews_7d'] . ' interviews 7d', 'tone' => 'warn'];
    $apps[] = ['key' => 'recruitment', 'name' => 'Recruitment', 'subtitle' => 'Candidates · Demo days', 'href' => '/recruitment/index.php', 'stats' => $stats];
}
if ($hasStaff) {
    $stats = [];
    if ($staffStats['pending_leave'] > 0) $stats[] = ['label' => $staffStats['pending_leave'] . ' leave', 'tone' => 'warn'];
    if ($staffStats['open_msgs']     > 0) $stats[] = ['label' => $staffStats['open_msgs'] . ' messages', 'tone' => ''];
    $apps[] = ['key' => 'staff', 'name' => 'Staff', 'subtitle' => 'Attendance · Leave · HR', 'href' => '/staff/index.php', 'stats' => $stats];
}
if ($hasExpenses) {
    $stats = [['label' => '₹' . number_format((float)$expensesStats['total'], 0), 'tone' => '']];
    if ($expensesStats['pending'] > 0) $stats[] = ['label' => $expensesStats['pending'] . ' to review', 'tone' => 'warn'];
    $apps[] = ['key' => 'expenses', 'name' => 'Expenses', 'subtitle' => 'Receipts · OCR · Reimburse', 'href' => '/expenses/index.php', 'stats' => $stats];
}
if ($hasFees) {
    $apps[] = ['key' => 'fees', 'name' => 'Fees', 'subtitle' => 'Calculator · CoFee · Guide', 'href' => '/fees/index.php',
               'stats' => [
                   ['label' => 'Admission ' . fee_inr($admTotal ?? 19000), 'tone' => ''],
                   ['label' => 'Monthly ' . fee_inr($monthlyTotal ?? 8200), 'tone' => ''],
               ]];
}
if ($hasLogbook) {
    $logToday = 0;
    try { $logToday = (int)db()->query("SELECT COUNT(*) FROM logbook_entries WHERE occurred_at >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
    $stats = [];
    if ($logToday > 0) $stats[] = ['label' => $logToday . ' today', 'tone' => ''];
    $apps[] = ['key' => 'logbook', 'name' => 'Logbook', 'subtitle' => 'Visitors · Incidents · Observations', 'href' => '/logbook/index.php', 'stats' => $stats];
}

// SVG glyphs — simple line icons (24x24, 1.8 stroke). Each module key maps here.
$icons = [
    'students'    => '<path d="M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'assessment'  => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'tasks'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 10l2 2 4-4M8 16l2 2 4-4"/>',
    'admissions'  => '<path d="M4 4h16l-6 8v6l-4 2v-8L4 4Z"/>',
    'recruitment' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0M15 20a4 4 0 0 1 6 0"/>',
    'staff'       => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><rect x="6" y="14" width="12" height="7" rx="2"/><path d="M10 17h4"/>',
    'expenses'    => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h5M8 17h4"/><path d="M16 17l2 2 3-3"/>',
    'fees'        => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4"/><circle cx="16" cy="16" r="2"/>',
    'logbook'     => '<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2Z"/><path d="M9 3v18M13 8h4M13 12h4"/>',
];
?>

<ul class="app-grid" role="list">
    <?php foreach ($apps as $app): ?>
        <li>
            <a class="app-card" href="<?= e($app['href']) ?>">
                <div class="app-icon app-icon-<?= e($app['key']) ?>">
                    <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <?= $icons[$app['key']] ?? '' ?>
                    </svg>
                </div>
                <div class="app-text">
                    <div class="app-name"><?= e($app['name']) ?></div>
                    <div class="app-subtitle"><?= e($app['subtitle']) ?></div>
                    <?php if (!empty($app['stats'])): ?>
                        <div class="app-stats">
                            <?php foreach ($app['stats'] as $s): ?>
                                <span class="pill <?= $s['tone'] === 'warn' ? 'pill-warn' : '' ?>"><?= e($s['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        </li>
    <?php endforeach; ?>
    <?php if (!$apps): ?>
        <li class="app-empty">
            <div class="empty">
                <p>No apps assigned yet. Ask an admin to grant you access from <a href="/admin.php">Admin → Users</a>.</p>
            </div>
        </li>
    <?php endif; ?>
</ul>

<?php require __DIR__ . '/includes/footer.php'; ?>
