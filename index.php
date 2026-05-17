<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();

// Admins see every student. Teachers see only their own.
if ($user['role'] === 'admin') {
    $students = db()->query("
        SELECT s.id, s.first_name, s.last_name, s.grade, s.teacher_id, t.name AS teacher_name
        FROM students s
        JOIN teachers t ON t.id = s.teacher_id
        ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name
    ")->fetchAll();
} else {
    $stmt = db()->prepare("
        SELECT s.id, s.first_name, s.last_name, s.grade, s.teacher_id
        FROM students s
        WHERE s.teacher_id = :tid
        ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name
    ");
    $stmt->execute([':tid' => $user['id']]);
    $students = $stmt->fetchAll();
}

// Latest month_year of any assessment per student.
$lastAssessment = [];
if ($students) {
    $ids   = array_column($students, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $rows  = db()->prepare("
        SELECT student_id, month_year
        FROM evaluation_cards
        WHERE student_id IN ($place)
    ");
    $rows->execute($ids);
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($lastAssessment[$sid]) || compare_month_year($r['month_year'], $lastAssessment[$sid]) > 0) {
            $lastAssessment[$sid] = $r['month_year'];
        }
    }
}

// Which students already have a baseline?
$hasBaseline = [];
if ($students) {
    $rows = db()->query("SELECT student_id FROM student_baselines");
    foreach ($rows as $r) $hasBaseline[(int)$r['student_id']] = true;
}

$currentMonth = current_month_year();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Welcome, <?= e(first_name($user['name'])) ?> 👋</h1>
        <p class="muted">Current month: <strong><?= e(month_year_label($currentMonth)) ?></strong>
            · <?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></p>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
        <a class="btn btn-primary" href="admin.php?tab=students">Manage students</a>
    <?php endif; ?>
</div>

<?php if (!$students): ?>
    <div class="empty">
        <p>No students assigned to you yet.<?= $user['role'] === 'admin' ? ' Add one from <a href="admin.php?tab=students">Admin → Students</a>.' : ' Ask an admin to assign you students.' ?></p>
    </div>
<?php else: ?>
<ul class="student-grid" role="list">
    <?php foreach ($students as $s): ?>
        <?php
            $sid       = (int)$s['id'];
            $last      = $lastAssessment[$sid] ?? null;
            $isCurrent = $last === $currentMonth;
            $statusCls = $last ? ($isCurrent ? 'ok' : 'old') : 'none';
            $statusTxt = $last
                ? ($isCurrent ? 'Assessed this month' : 'Last: ' . month_year_label($last))
                : 'Not yet assessed';
            $hasBl     = !empty($hasBaseline[$sid]);
            $fullName  = trim($s['first_name'] . ' ' . $s['last_name']);
        ?>
        <li class="student-card" style="--card: <?= e(user_color($sid)) ?>;">
            <div class="student-head">
                <span class="student-avatar"><?= e(user_initials($fullName)) ?></span>
                <div>
                    <div class="student-name"><?= e($fullName) ?></div>
                    <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
                    <?php if ($user['role'] === 'admin' && !empty($s['teacher_name'])): ?>
                        <span class="muted small"> · <?= e($s['teacher_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="student-status">
                <span class="status-dot status-<?= e($statusCls) ?>"></span>
                <span><?= e($statusTxt) ?></span>
                <?php if ($hasBl): ?><span class="pill">Baseline ✓</span><?php endif; ?>
            </div>
            <div class="student-actions">
                <a class="btn btn-primary" href="assess.php?student_id=<?= $sid ?>&month=<?= e($currentMonth) ?>">Assess</a>
                <a class="btn" href="progress.php?student_id=<?= $sid ?>">Progress</a>
                <a class="btn btn-ghost" href="baseline.php?student_id=<?= $sid ?>"><?= $hasBl ? 'Edit Baseline' : 'Add Baseline' ?></a>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
