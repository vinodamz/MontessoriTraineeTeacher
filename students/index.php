<?php
/**
 * students/index.php — students list with search + grade/teacher/active filters.
 *
 * Visible to anyone with the `students` module OR the `montessori` module
 * (assessment teachers see the same list so they can find a student fast).
 * Admins implicitly have access.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if (!user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — you do not have access to the students module.';
    exit;
}

// ---------- Filters --------------------------------------------------------
$q          = trim($_GET['q'] ?? '');
$gradeIn    = $_GET['grade'] ?? '';
$teacherIn  = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$activeIn   = $_GET['active'] ?? 'active';  // 'active' | 'inactive' | 'all'

$validGrades   = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$gradeFilter   = in_array($gradeIn, $validGrades, true) ? $gradeIn : '';

// Academic year + enrollment-status filters. Default to the LATEST year
// in the dropdown — academic_years_in_use() puts the upcoming year first,
// so during admissions season (April–May) the page lands on the year the
// team is currently enrolling for. Outside that window it lands on the
// most-recent year with student data.
$availableYears = academic_years_in_use();
$defaultYear    = $availableYears[0] ?? current_academic_year();
$yearIn = $_GET['year'] ?? $defaultYear;
if ($yearIn !== '' && $yearIn !== 'all' && !in_array($yearIn, $availableYears, true)) {
    $yearIn = $defaultYear;
}
$statusIn = $_GET['status'] ?? 'enrolled';   // 'enrolled' | 'left' | 'all' | specific code
$STATUSES_LEFT = ['withdrawn', 'graduated', 'on_break'];

// Intake-pending rows are created with is_active=0 (see students/intake_new.php)
// so the default active filter would otherwise hide them. When the admin
// explicitly asks for status=intake_pending, relax the active filter to
// include inactive rows. The same applies to the "left" bucket (graduated /
// withdrawn / on_break — those rows are typically inactive too).
if ($statusIn === 'intake_pending' || $statusIn === 'left') {
    $activeIn = 'all';
}

$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(s.first_name LIKE :q OR s.last_name LIKE :q OR s.admission_number LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($gradeFilter !== '') {
    $where[] = "s.grade = :g";
    $params[':g'] = $gradeFilter;
}
if ($teacherIn > 0) {
    $where[] = "s.teacher_id = :tid";
    $params[':tid'] = $teacherIn;
}
if ($activeIn === 'active') {
    $where[] = "COALESCE(s.is_active, 1) = 1";
} elseif ($activeIn === 'inactive') {
    $where[] = "COALESCE(s.is_active, 1) = 0";
}
if ($yearIn !== '' && $yearIn !== 'all') {
    $where[] = "s.academic_year = :ay";
    $params[':ay'] = $yearIn;
}
if ($statusIn === 'enrolled') {
    $where[] = "COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'";
} elseif ($statusIn === 'left') {
    $where[] = "s.enrollment_status IN ('withdrawn','graduated','on_break')";
} elseif (array_key_exists($statusIn, ENROLLMENT_STATUSES)) {
    $where[] = "s.enrollment_status = :es";
    $params[':es'] = $statusIn;
}

// Non-admins without the students module (i.e. assessment teachers viewing the
// list) only see their own students, mirroring assessment/index.php behaviour.
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    $where[] = "s.teacher_id = :me";
    $params[':me'] = $user['id'];
}

$sql = "
    SELECT s.id, s.admission_number, s.first_name, s.last_name, s.grade, s.gender,
           s.dob, s.joining_date, s.photo_path, s.is_active, s.teacher_id,
           s.academic_year, s.enrollment_status, s.withdrawal_reason,
           u.name AS teacher_name
    FROM students s
    LEFT JOIN users u ON u.id = s.teacher_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'),
             s.first_name, s.last_name
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Teachers list for the filter dropdown.
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
    ORDER BY name
")->fetchAll();

// Grade counts for the summary chips.
$gradeCounts = ['Playgroup' => 0, 'Nursery' => 0, 'LKG' => 0, 'UKG' => 0];
foreach ($students as $s) {
    $gradeCounts[$s['grade']] = ($gradeCounts[$s['grade']] ?? 0) + 1;
}

$canEdit   = $user['role'] === 'admin' || user_has_module($user, 'students');
$pageTitle = 'Students';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Students</h1>
        <p class="muted"><?= count($students) ?> result<?= count($students) === 1 ? '' : 's' ?>
            <?php foreach ($gradeCounts as $g => $n): if ($n): ?>
                · <span class="<?= e(grade_badge_class($g)) ?>"><?= e($g) ?></span> <?= $n ?>
            <?php endif; endforeach; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/students/attendance.php">Mark attendance</a>
        <?php if ($canEdit): ?>
            <a class="btn" href="/students/grid.php">Grid editor</a>
            <a class="btn" href="/students/fees_report.php">Fees report</a>
            <a class="btn" href="/students/export.php<?= $_SERVER['QUERY_STRING'] !== '' ? '?' . e($_SERVER['QUERY_STRING']) : '' ?>" title="Download current view as Excel">Export</a>
            <a class="btn" href="/students/import.php">Import</a>
            <a class="btn" href="/students/yearend.php">Year-end</a>
            <a class="btn" href="/students/withdrawals.php">Withdrawals</a>
            <a class="btn" href="/students/intake_new.php" title="Send admission form to a new family">+ New admission (parent form)</a>
            <a class="btn btn-primary" href="/students/edit.php">+ New student</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="q">Search</label>
        <input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name or admission no." autocomplete="off">
    </div>
    <div class="field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
            <option value="">All grades</option>
            <?php foreach ($validGrades as $g): ?>
                <option value="<?= e($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="teacher_id">Teacher</label>
        <select id="teacher_id" name="teacher_id">
            <option value="0">All teachers</option>
            <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $teacherIn === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="year">Academic year</label>
        <select id="year" name="year">
            <?php foreach ($availableYears as $y): ?>
                <option value="<?= e($y) ?>" <?= $yearIn === $y ? 'selected' : '' ?>><?= e($y) ?></option>
            <?php endforeach; ?>
            <option value="all" <?= $yearIn === 'all' ? 'selected' : '' ?>>All years</option>
        </select>
    </div>
    <div class="field">
        <label for="status">Enrollment</label>
        <select id="status" name="status">
            <option value="enrolled" <?= $statusIn === 'enrolled' ? 'selected' : '' ?>>Currently enrolled</option>
            <option value="left"     <?= $statusIn === 'left'     ? 'selected' : '' ?>>Left (withdrawn / graduated)</option>
            <option value="all"      <?= $statusIn === 'all'      ? 'selected' : '' ?>>Any status</option>
            <?php foreach (ENROLLMENT_STATUSES as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $statusIn === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/students/index.php">Reset</a>
    </div>
</form>

<?php if (!$students): ?>
    <div class="empty">
        <p>No students matching the current filters.<?php if ($canEdit): ?> <a href="/students/edit.php">Add one</a>.<?php endif; ?></p>
    </div>
<?php else: ?>
    <ul class="student-grid" role="list">
        <?php foreach ($students as $s):
            $full = trim($s['first_name'] . ' ' . $s['last_name']);
            $sid  = (int)$s['id'];
        ?>
            <?php
                $enrStatus = $s['enrollment_status'] ?? 'enrolled';
                $hasLeft   = in_array($enrStatus, ['withdrawn','graduated','on_break'], true);
            ?>
            <li class="student-card <?= $s['is_active'] && !$hasLeft ? '' : 'is-inactive' ?>"
                style="--card: <?= e(user_color($sid)) ?>;">
                <div class="student-head">
                    <span class="student-avatar"><?= e(user_initials($full)) ?></span>
                    <div>
                        <div class="student-name">
                            <a href="/students/view.php?id=<?= $sid ?>"><?= e($full) ?></a>
                            <?php if ($enrStatus !== 'enrolled'): ?>
                                <span class="pill enr-<?= e($enrStatus) ?>"><?= e(enrollment_status_label($enrStatus)) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
                        <?php if (!empty($s['teacher_name'])): ?>
                            <span class="muted small"> · <?= e($s['teacher_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($s['academic_year'])): ?>
                            <span class="muted small"> · <?= e($s['academic_year']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($s['admission_number'])): ?>
                            <div class="muted small">Adm #<?= e($s['admission_number']) ?></div>
                        <?php endif; ?>
                        <?php if ($enrStatus === 'withdrawn' && !empty($s['withdrawal_reason'])): ?>
                            <div class="muted small">Reason: <?= e(withdrawal_reason_label($s['withdrawal_reason'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="student-actions">
                    <a class="btn" href="/students/view.php?id=<?= $sid ?>">View</a>
                    <?php if ($canEdit): ?>
                        <a class="btn btn-ghost" href="/students/edit.php?id=<?= $sid ?>">Edit</a>
                    <?php endif; ?>
                    <?php if (user_has_module($user, 'montessori')): ?>
                        <a class="btn btn-ghost" href="/assessment/progress.php?student_id=<?= $sid ?>">Progress</a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
