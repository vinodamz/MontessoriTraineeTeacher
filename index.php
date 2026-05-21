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

// Single-module users go straight in.
$moduleCount = (int)$hasTasks + (int)$hasMontess + (int)$hasStudents + (int)$hasCrm;
if ($moduleCount === 1) {
    if ($hasTasks)    redirect('/tasks/index.php');
    if ($hasMontess)  redirect('/assessment/index.php');
    if ($hasStudents) redirect('/students/index.php');
    if ($hasCrm)      redirect('/crm/index.php');
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
    <?php if (!$hasTasks && !$hasMontess && !$hasStudents && !$hasCrm): ?>
        <li>
            <div class="empty">
                <p>No modules assigned yet. Ask an admin to grant you access from <a href="/admin.php">Admin → Users</a>.</p>
            </div>
        </li>
    <?php endif; ?>
</ul>

<?php require __DIR__ . '/includes/footer.php'; ?>
