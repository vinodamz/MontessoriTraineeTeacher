<?php
/**
 * students/grid.php — Excel-like bulk editor for students.
 *
 * A spreadsheet-style table where each row is a student and the common
 * fields (name, admission #, grade, teacher, gender, status, year, active)
 * are inline-editable. One "Save all changes" button writes every changed
 * row in a single transaction.
 *
 * Only the columns shown here are written — medical notes, address,
 * parents and withdrawal details are left untouched (edit those on the
 * full /students/edit.php form).
 *
 * Auth: admins or anyone with the `students` module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — you do not have the students module.';
    exit;
}

$VALID_GRADES  = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$VALID_GENDERS = ['Male', 'Female', 'Other'];

// Teacher dropdown options (montessori-module users + admins).
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
    ORDER BY name
")->fetchAll();
$teacherIds = array_map(fn($t) => (int)$t['id'], $teachers);

$availableYears = academic_years_in_use();

// ---------- POST: bulk save ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rows = $_POST['rows'] ?? [];
    $saved = 0; $skipped = [];

    if (is_array($rows) && $rows) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE students SET
                    admission_number = :adm, first_name = :f, last_name = :l,
                    grade = :g, teacher_id = :tid, gender = :gender,
                    enrollment_status = :es, academic_year = :ay, is_active = :active
                WHERE id = :id
            ");
            foreach ($rows as $sid => $r) {
                $sid = (int)$sid;
                if ($sid <= 0 || !is_array($r)) continue;

                $first = trim($r['first_name'] ?? '');
                $grade = $r['grade'] ?? '';
                $tid   = (int)($r['teacher_id'] ?? 0);
                $name  = $first !== '' ? $first : ('#' . $sid);

                if ($first === '')                              { $skipped[] = "$name — first name blank"; continue; }
                if (!in_array($grade, $VALID_GRADES, true))     { $skipped[] = "$name — invalid grade"; continue; }
                if (!in_array($tid, $teacherIds, true))         { $skipped[] = "$name — teacher not set"; continue; }

                $gender = $r['gender'] ?? '';
                if ($gender !== '' && !in_array($gender, $VALID_GENDERS, true)) $gender = '';

                $es = $r['enrollment_status'] ?? 'enrolled';
                if (!array_key_exists($es, ENROLLMENT_STATUSES)) $es = 'enrolled';

                $ay = trim($r['academic_year'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = current_academic_year();

                $adm    = trim($r['admission_number'] ?? '');
                $active = !empty($r['is_active']) ? 1 : 0;

                $upd->execute([
                    ':adm' => $adm !== '' ? $adm : null,
                    ':f' => $first, ':l' => trim($r['last_name'] ?? ''),
                    ':g' => $grade, ':tid' => $tid, ':gender' => $gender ?: null,
                    ':es' => $es, ':ay' => $ay, ':active' => $active, ':id' => $sid,
                ]);
                $saved++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Save failed (no changes applied): ' . $e->getMessage());
            redirect('/students/grid.php?' . http_build_query($_GET));
        }
    }

    $msg = "$saved student" . ($saved === 1 ? '' : 's') . ' saved.';
    if ($skipped) $msg .= ' Skipped ' . count($skipped) . ': ' . implode('; ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '');
    flash_set($skipped ? 'error' : 'ok', $msg);
    redirect('/students/grid.php?' . http_build_query($_GET));
}

// ---------- GET: filters + load ------------------------------------------
$fYear    = $_GET['year']    ?? ($availableYears[0] ?? current_academic_year());
$fGrade   = $_GET['grade']   ?? '';
$fTeacher = (int)($_GET['teacher'] ?? 0);
$fActive  = $_GET['active']  ?? 'active';   // active | all | inactive

$where = []; $params = [];
if ($fYear !== '' && $fYear !== 'all') { $where[] = 's.academic_year = :ay'; $params[':ay'] = $fYear; }
if (in_array($fGrade, $VALID_GRADES, true)) { $where[] = 's.grade = :g'; $params[':g'] = $fGrade; }
if ($fTeacher > 0) { $where[] = 's.teacher_id = :tid'; $params[':tid'] = $fTeacher; }
if ($fActive === 'active')   $where[] = 'COALESCE(s.is_active,1) = 1';
elseif ($fActive === 'inactive') $where[] = 'COALESCE(s.is_active,1) = 0';

// Non-admin teachers only see their own students (mirrors index.php).
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    $where[] = 's.teacher_id = :me'; $params[':me'] = (int)$user['id'];
}

$sql = "
    SELECT s.id, s.admission_number, s.first_name, s.last_name, s.grade,
           s.teacher_id, s.gender, s.enrollment_status, s.academic_year, s.is_active
    FROM students s
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Year option list must include the current filter even if no rows use it.
if ($fYear !== '' && $fYear !== 'all' && !in_array($fYear, $availableYears, true)) {
    array_unshift($availableYears, $fYear);
}

$pageTitle  = 'Students — grid editor';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Grid editor</h1>
        <p class="muted"><a href="/students/index.php">← Students</a> · <?= count($students) ?> row<?= count($students) === 1 ? '' : 's' ?> · edit inline, then Save all</p>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="year">Academic year</label>
        <select id="year" name="year">
            <option value="all" <?= $fYear === 'all' ? 'selected' : '' ?>>All years</option>
            <?php foreach ($availableYears as $y): ?>
                <option value="<?= e($y) ?>" <?= $fYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
            <option value="">All grades</option>
            <?php foreach ($VALID_GRADES as $g): ?>
                <option value="<?= e($g) ?>" <?= $fGrade === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="teacher">Teacher</label>
        <select id="teacher" name="teacher">
            <option value="0">All teachers</option>
            <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $fTeacher === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="active">Active</label>
        <select id="active" name="active">
            <option value="active"   <?= $fActive === 'active'   ? 'selected' : '' ?>>Active only</option>
            <option value="all"      <?= $fActive === 'all'      ? 'selected' : '' ?>>All</option>
            <option value="inactive" <?= $fActive === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/students/grid.php">Reset</a>
    </div>
</form>

<?php if (!$students): ?>
    <div class="empty"><p>No students match these filters.</p></div>
<?php else: ?>
<form method="post" id="gridForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php foreach ($_GET as $k => $v): if (!is_scalar($v)) continue; ?>
        <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>">
    <?php endforeach; ?>

    <div class="grid-actions-bar">
        <button class="btn btn-primary" type="submit">Save all changes</button>
        <span class="muted small">Editing <?= count($students) ?> rows · only Name / Admission / Grade / Teacher / Gender / Status / Year / Active</span>
    </div>

    <div class="grid-scroll">
        <table class="grid-table">
            <thead>
                <tr>
                    <th class="grid-sticky-col">First name</th>
                    <th>Last name</th>
                    <th>Admission #</th>
                    <th>Grade</th>
                    <th>Teacher</th>
                    <th>Gender</th>
                    <th>Status</th>
                    <th>Year</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): $sid = (int)$s['id']; $p = "rows[$sid]"; ?>
                    <tr>
                        <td class="grid-sticky-col"><input name="<?= $p ?>[first_name]" value="<?= e($s['first_name']) ?>" required></td>
                        <td><input name="<?= $p ?>[last_name]" value="<?= e($s['last_name'] ?? '') ?>"></td>
                        <td><input name="<?= $p ?>[admission_number]" value="<?= e($s['admission_number'] ?? '') ?>" style="width:9ch;"></td>
                        <td>
                            <select name="<?= $p ?>[grade]">
                                <?php foreach ($VALID_GRADES as $g): ?>
                                    <option value="<?= e($g) ?>" <?= $s['grade'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?= $p ?>[teacher_id]">
                                <option value="0">—</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= (int)($s['teacher_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?= $p ?>[gender]">
                                <option value="">—</option>
                                <?php foreach ($VALID_GENDERS as $g): ?>
                                    <option value="<?= e($g) ?>" <?= ($s['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?= $p ?>[enrollment_status]">
                                <?php foreach (ENROLLMENT_STATUSES as $code => $label): ?>
                                    <option value="<?= e($code) ?>" <?= ($s['enrollment_status'] ?? 'enrolled') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?= $p ?>[academic_year]">
                                <?php foreach ($availableYears as $y): ?>
                                    <option value="<?= e($y) ?>" <?= ($s['academic_year'] ?? '') === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="text-align:center;"><input type="checkbox" name="<?= $p ?>[is_active]" value="1" <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>></td>
                        <td><a class="btn btn-ghost btn-small" href="/students/view.php?id=<?= $sid ?>" title="Full profile">⤢</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="grid-actions-bar">
        <button class="btn btn-primary" type="submit">Save all changes</button>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
