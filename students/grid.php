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

// Helper: upsert one parent row (father / mother) for a student. Inserts
// when name is set and no row exists; updates when both exist; deletes the
// row when name is cleared.
function grid_upsert_parent(PDO $pdo, int $studentId, string $relation, array $r): void
{
    $name  = trim((string)($r['name']  ?? ''));
    $phone = trim((string)($r['phone'] ?? ''));
    $email = trim((string)($r['email'] ?? ''));

    $sel = $pdo->prepare("SELECT id FROM student_parents WHERE student_id = :s AND relation = :r LIMIT 1");
    $sel->execute([':s' => $studentId, ':r' => $relation]);
    $existingId = (int)($sel->fetchColumn() ?: 0);

    if ($name === '' && $phone === '' && $email === '') {
        // Empty triple → remove the relation row if present.
        if ($existingId > 0) {
            $pdo->prepare("DELETE FROM student_parents WHERE id = :id")->execute([':id' => $existingId]);
        }
        return;
    }
    // Must have at least a name for the parent record to be meaningful.
    if ($name === '') $name = ucfirst($relation);

    if ($existingId > 0) {
        $pdo->prepare("
            UPDATE student_parents SET name=:n, phone=:p, email=:e
            WHERE id=:id
        ")->execute([
            ':n'=>$name, ':p'=>$phone ?: null, ':e'=>$email ?: null,
            ':id'=>$existingId,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO student_parents (student_id, relation, name, phone, email, is_primary)
            VALUES (:s, :rel, :n, :p, :e, :pr)
        ")->execute([
            ':s'=>$studentId, ':rel'=>$relation, ':n'=>$name,
            ':p'=>$phone ?: null, ':e'=>$email ?: null,
            ':pr'=>$relation === 'father' ? 1 : 0, // father defaults to primary if no one else
        ]);
    }
}

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
                    enrollment_status = :es, academic_year = :ay, is_active = :active,
                    emergency_contact_name = :emN, emergency_contact_phone = :emP,
                    home_address = :addr, permanent_address = :paddr
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

                $emN  = trim($r['emergency_name']  ?? '');
                $emP  = trim($r['emergency_phone'] ?? '');
                $addr = trim($r['home_address']      ?? '');
                $paddr= trim($r['permanent_address'] ?? '');

                $upd->execute([
                    ':adm' => $adm !== '' ? $adm : null,
                    ':f' => $first, ':l' => trim($r['last_name'] ?? ''),
                    ':g' => $grade, ':tid' => $tid, ':gender' => $gender ?: null,
                    ':es' => $es, ':ay' => $ay, ':active' => $active,
                    ':emN' => $emN ?: null, ':emP' => $emP ?: null,
                    ':addr' => $addr ?: null, ':paddr' => $paddr ?: null,
                    ':id' => $sid,
                ]);

                if (is_array($r['father'] ?? null)) grid_upsert_parent($pdo, $sid, 'father', $r['father']);
                if (is_array($r['mother'] ?? null)) grid_upsert_parent($pdo, $sid, 'mother', $r['mother']);

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
           s.teacher_id, s.gender, s.enrollment_status, s.academic_year, s.is_active,
           s.emergency_contact_name, s.emergency_contact_phone,
           s.home_address, s.permanent_address, s.photo_path
    FROM students s
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Batch-load father + mother rows for every student on screen so the grid
// can render their name / phone / email inline without N+1 queries.
$parentByStudent = []; // [student_id][relation] = ['name','phone','email']
if ($students) {
    $ids = array_map(fn($r) => (int)$r['id'], $students);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $pst = db()->prepare("
        SELECT student_id, relation, name, phone, email
        FROM student_parents
        WHERE student_id IN ($place) AND relation IN ('father','mother')
    ");
    $pst->execute($ids);
    foreach ($pst->fetchAll() as $pr) {
        $parentByStudent[(int)$pr['student_id']][$pr['relation']] = [
            'name'  => $pr['name']  ?? '',
            'phone' => $pr['phone'] ?? '',
            'email' => $pr['email'] ?? '',
        ];
    }
}

// Year option list must include the current filter even if no rows use it.
if ($fYear !== '' && $fYear !== 'all' && !in_array($fYear, $availableYears, true)) {
    array_unshift($availableYears, $fYear);
}

$pageTitle  = 'Students — edit all';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Edit all (grid)</h1>
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
        <span class="muted small">Editing <?= count($students) ?> row<?= count($students) === 1 ? '' : 's' ?> · all text fields editable inline · use the ⤢ column to open the full profile and upload photos.</span>
    </div>

    <div class="grid-scroll">
        <table class="grid-table">
            <thead>
                <tr>
                    <th class="grid-sticky-col">First name</th>
                    <th>Last name</th>
                    <th class="grid-cell-center">Photo</th>
                    <th>Admission #</th>
                    <th>Grade</th>
                    <th>Teacher</th>
                    <th>Gender</th>
                    <th>Status</th>
                    <th>Year</th>
                    <th class="grid-cell-center">Active</th>
                    <th>Emergency name</th>
                    <th>Emergency phone</th>
                    <th>Father name</th>
                    <th>Father phone</th>
                    <th>Father email</th>
                    <th>Mother name</th>
                    <th>Mother phone</th>
                    <th>Mother email</th>
                    <th>Home address</th>
                    <th>Permanent address</th>
                    <th class="grid-cell-center">Open</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s):
                    $sid = (int)$s['id']; $p = "rows[$sid]";
                    $father = $parentByStudent[$sid]['father'] ?? ['name'=>'','phone'=>'','email'=>''];
                    $mother = $parentByStudent[$sid]['mother'] ?? ['name'=>'','phone'=>'','email'=>''];
                ?>
                    <tr>
                        <td class="grid-sticky-col"><input type="text" name="<?= $p ?>[first_name]" value="<?= e($s['first_name']) ?>" required></td>
                        <td><input type="text" name="<?= $p ?>[last_name]" value="<?= e($s['last_name'] ?? '') ?>"></td>
                        <td class="grid-cell-center">
                            <?php if (!empty($s['photo_path'])): ?>
                                <img class="grid-avatar" src="<?= e(student_photo_url($s['photo_path'])) ?>" alt="">
                            <?php else: ?>
                                <a class="grid-photo-add" href="/students/edit.php?id=<?= $sid ?>" title="Upload on the full edit page" aria-label="Upload photo">+</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="text" name="<?= $p ?>[admission_number]" value="<?= e($s['admission_number'] ?? '') ?>"></td>
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
                        <td class="grid-cell-center"><input type="checkbox" name="<?= $p ?>[is_active]" value="1" <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>></td>
                        <td><input type="text"  name="<?= $p ?>[emergency_name]"  value="<?= e($s['emergency_contact_name']  ?? '') ?>"></td>
                        <td><input type="tel"   name="<?= $p ?>[emergency_phone]" value="<?= e($s['emergency_contact_phone'] ?? '') ?>"></td>
                        <td><input type="text"  name="<?= $p ?>[father][name]"  value="<?= e($father['name'])  ?>"></td>
                        <td><input type="tel"   name="<?= $p ?>[father][phone]" value="<?= e($father['phone']) ?>"></td>
                        <td><input type="email" name="<?= $p ?>[father][email]" value="<?= e($father['email']) ?>"></td>
                        <td><input type="text"  name="<?= $p ?>[mother][name]"  value="<?= e($mother['name'])  ?>"></td>
                        <td><input type="tel"   name="<?= $p ?>[mother][phone]" value="<?= e($mother['phone']) ?>"></td>
                        <td><input type="email" name="<?= $p ?>[mother][email]" value="<?= e($mother['email']) ?>"></td>
                        <td><textarea name="<?= $p ?>[home_address]"      rows="1"><?= e($s['home_address']      ?? '') ?></textarea></td>
                        <td><textarea name="<?= $p ?>[permanent_address]" rows="1"><?= e($s['permanent_address'] ?? '') ?></textarea></td>
                        <td class="grid-cell-center"><a class="btn btn-ghost btn-small" href="/students/edit.php?id=<?= $sid ?>" title="Full profile (upload photos here)" aria-label="Open full profile">⤢</a></td>
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
