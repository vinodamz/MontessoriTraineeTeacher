<?php
/**
 * students/edit.php — add or edit a student.
 *
 * GET  ?id=N   → edit existing student (with their parents inline)
 * GET           → new-student form
 * POST          → transactional save (student + parents in one go)
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

$VALID_GRADES   = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$VALID_GENDERS  = ['Male', 'Female', 'Other'];
$VALID_RELATION = ['father', 'mother', 'guardian', 'other'];

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit  = $id > 0;

// ---------- POST: save ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $postId = (int)($_POST['id'] ?? 0);
    $isEdit = $postId > 0;

    // Collect + validate student fields
    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    $grade  = $_POST['grade'] ?? '';
    $tid    = (int)($_POST['teacher_id'] ?? 0);
    $adm    = trim($_POST['admission_number'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob    = $_POST['dob'] ?? '';
    $join   = $_POST['joining_date'] ?? '';
    $blood  = trim($_POST['blood_group'] ?? '');
    $allg   = trim($_POST['allergies'] ?? '');
    $med    = trim($_POST['medical_notes'] ?? '');
    $addr   = trim($_POST['home_address'] ?? '');
    $pickN  = trim($_POST['pickup_person'] ?? '');
    $pickP  = trim($_POST['pickup_phone'] ?? '');
    $emN    = trim($_POST['emergency_contact_name'] ?? '');
    $emP    = trim($_POST['emergency_contact_phone'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');
    $active = !empty($_POST['is_active']) ? 1 : 0;

    if ($first === '') { flash_set('error', 'First name is required.'); redirect('/students/edit.php' . ($isEdit ? '?id=' . $postId : '')); }
    if (!in_array($grade, $VALID_GRADES, true)) { flash_set('error', 'Grade is required.'); redirect('/students/edit.php' . ($isEdit ? '?id=' . $postId : '')); }
    if ($tid <= 0) { flash_set('error', 'Teacher is required.'); redirect('/students/edit.php' . ($isEdit ? '?id=' . $postId : '')); }
    if ($gender !== '' && !in_array($gender, $VALID_GENDERS, true)) $gender = '';
    if ($dob === '0000-00-00') $dob = '';
    if ($join === '0000-00-00') $join = '';
    if ($adm === '') $adm = null;          // unique key on empty string would collide → store NULL

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($isEdit) {
            $stmt = $pdo->prepare("
                UPDATE students SET
                    admission_number = :adm,
                    first_name = :f, last_name = :l, grade = :g, teacher_id = :tid,
                    gender = :gender, dob = :dob, joining_date = :join,
                    blood_group = :blood, allergies = :allg, medical_notes = :med,
                    home_address = :addr,
                    pickup_person = :pickN, pickup_phone = :pickP,
                    emergency_contact_name = :emN, emergency_contact_phone = :emP,
                    notes = :notes, is_active = :active
                WHERE id = :id
            ");
            $stmt->execute([
                ':adm' => $adm, ':f' => $first, ':l' => $last, ':g' => $grade, ':tid' => $tid,
                ':gender' => $gender ?: null, ':dob' => $dob ?: null, ':join' => $join ?: null,
                ':blood' => $blood ?: null, ':allg' => $allg ?: null, ':med' => $med ?: null,
                ':addr' => $addr ?: null,
                ':pickN' => $pickN ?: null, ':pickP' => $pickP ?: null,
                ':emN' => $emN ?: null, ':emP' => $emP ?: null,
                ':notes' => $notes ?: null, ':active' => $active,
                ':id' => $postId,
            ]);
            $studentId = $postId;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO students
                    (admission_number, first_name, last_name, grade, teacher_id,
                     gender, dob, joining_date, blood_group, allergies, medical_notes,
                     home_address, pickup_person, pickup_phone,
                     emergency_contact_name, emergency_contact_phone, notes, is_active)
                VALUES
                    (:adm, :f, :l, :g, :tid,
                     :gender, :dob, :join, :blood, :allg, :med,
                     :addr, :pickN, :pickP, :emN, :emP, :notes, :active)
            ");
            $stmt->execute([
                ':adm' => $adm, ':f' => $first, ':l' => $last, ':g' => $grade, ':tid' => $tid,
                ':gender' => $gender ?: null, ':dob' => $dob ?: null, ':join' => $join ?: null,
                ':blood' => $blood ?: null, ':allg' => $allg ?: null, ':med' => $med ?: null,
                ':addr' => $addr ?: null,
                ':pickN' => $pickN ?: null, ':pickP' => $pickP ?: null,
                ':emN' => $emN ?: null, ':emP' => $emP ?: null,
                ':notes' => $notes ?: null, ':active' => 1,
            ]);
            $studentId = (int)$pdo->lastInsertId();
        }

        // Parents: array-driven. Each entry has id (existing) or '' (new).
        // If `name` is blank we skip it. To delete a row, the UI submits
        // parents[i][_delete]=1.
        $rows = $_POST['parents'] ?? [];
        if (is_array($rows)) {
            $ins  = $pdo->prepare("
                INSERT INTO student_parents (student_id, relation, name, phone, email, occupation, address, is_primary)
                VALUES (:sid, :rel, :nm, :ph, :em, :occ, :ad, :pr)
            ");
            $upd  = $pdo->prepare("
                UPDATE student_parents SET
                    relation = :rel, name = :nm, phone = :ph, email = :em,
                    occupation = :occ, address = :ad, is_primary = :pr
                WHERE id = :id AND student_id = :sid
            ");
            $del  = $pdo->prepare("DELETE FROM student_parents WHERE id = :id AND student_id = :sid");
            $sawPrimary = false;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $rid     = (int)($row['id'] ?? 0);
                $rname   = trim($row['name'] ?? '');
                $rrel    = $row['relation'] ?? 'guardian';
                if (!in_array($rrel, $VALID_RELATION, true)) $rrel = 'guardian';
                $rphone  = trim($row['phone'] ?? '');
                $remail  = trim($row['email'] ?? '');
                $rocc    = trim($row['occupation'] ?? '');
                $raddr   = trim($row['address'] ?? '');
                $rprim   = !empty($row['is_primary']) ? 1 : 0;
                $deleted = !empty($row['_delete']);

                if ($deleted && $rid > 0) {
                    $del->execute([':id' => $rid, ':sid' => $studentId]);
                    continue;
                }
                if ($rname === '') {
                    // Skip blank rows. Delete the existing record if its name was cleared.
                    if ($rid > 0) $del->execute([':id' => $rid, ':sid' => $studentId]);
                    continue;
                }
                if ($rprim) {
                    if ($sawPrimary) $rprim = 0;          // only one primary allowed
                    else             $sawPrimary = true;
                }

                $bind = [
                    ':rel' => $rrel, ':nm' => $rname,
                    ':ph' => $rphone ?: null, ':em' => $remail ?: null,
                    ':occ' => $rocc ?: null, ':ad' => $raddr ?: null,
                    ':pr' => $rprim,
                ];
                if ($rid > 0) {
                    $upd->execute($bind + [':id' => $rid, ':sid' => $studentId]);
                } else {
                    $ins->execute($bind + [':sid' => $studentId]);
                }
            }
        }

        $pdo->commit();
        flash_set('ok', $isEdit ? 'Student updated.' : 'Student added.');
        redirect('/students/view.php?id=' . $studentId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Save failed: ' . $e->getMessage());
        redirect('/students/edit.php' . ($isEdit ? '?id=' . $postId : ''));
    }
}

// ---------- GET: prepare data --------------------------------------------
$s = null;
if ($isEdit) {
    $stmt = db()->prepare("SELECT * FROM students WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $s = $stmt->fetch();
    if (!$s) {
        flash_set('error', 'Student not found.');
        redirect('/students/index.php');
    }
}

$parents = [];
if ($isEdit) {
    $stmt = db()->prepare("SELECT * FROM student_parents WHERE student_id = :id ORDER BY is_primary DESC, relation, id");
    $stmt->execute([':id' => $id]);
    $parents = $stmt->fetchAll();
}

// Teacher list for the dropdown — montessori-module users + admins.
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
    ORDER BY name
")->fetchAll();

$pageTitle = $isEdit ? ('Edit — ' . trim($s['first_name'] . ' ' . $s['last_name'])) : 'New student';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit student' : 'New student' ?></h1>
        <?php if ($isEdit): ?>
            <p class="muted"><?= e($s['first_name'] . ' ' . $s['last_name']) ?>
                <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
            </p>
        <?php endif; ?>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="<?= $isEdit ? '/students/view.php?id=' . (int)$s['id'] : '/students/index.php' ?>">Cancel</a>
    </div>
</div>

<form method="post" class="card card-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><?php endif; ?>

    <h2>Basic details</h2>
    <div class="row">
        <div class="field">
            <label>First name *</label>
            <input name="first_name" required maxlength="80" value="<?= e($s['first_name'] ?? '') ?>" autofocus>
        </div>
        <div class="field">
            <label>Last name</label>
            <input name="last_name" maxlength="80" value="<?= e($s['last_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Admission number</label>
            <input name="admission_number" maxlength="40" value="<?= e($s['admission_number'] ?? '') ?>" placeholder="optional">
        </div>
    </div>

    <div class="row">
        <div class="field">
            <label>Grade *</label>
            <select name="grade" required>
                <option value="">—</option>
                <?php foreach ($VALID_GRADES as $g): ?>
                    <option value="<?= e($g) ?>" <?= ($s['grade'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Class teacher *</label>
            <select name="teacher_id" required>
                <option value="">—</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ($s['teacher_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Gender</label>
            <select name="gender">
                <option value="">—</option>
                <?php foreach ($VALID_GENDERS as $g): ?>
                    <option value="<?= e($g) ?>" <?= ($s['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="field">
            <label>Date of birth</label>
            <input type="date" name="dob" value="<?= e($s['dob'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Joining date</label>
            <input type="date" name="joining_date" value="<?= e($s['joining_date'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Blood group</label>
            <input name="blood_group" maxlength="5" value="<?= e($s['blood_group'] ?? '') ?>" placeholder="e.g. O+">
        </div>
        <?php if ($isEdit): ?>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= ($s['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="field" style="flex: 1 1 100%;">
            <label>Allergies</label>
            <textarea name="allergies" rows="2" maxlength="2000"><?= e($s['allergies'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="row">
        <div class="field" style="flex: 1 1 100%;">
            <label>Medical notes</label>
            <textarea name="medical_notes" rows="2" maxlength="2000"><?= e($s['medical_notes'] ?? '') ?></textarea>
        </div>
    </div>

    <h2 class="section-h-spaced">Address &amp; emergency</h2>
    <div class="row">
        <div class="field" style="flex: 1 1 100%;">
            <label>Home address</label>
            <textarea name="home_address" rows="2" maxlength="2000"><?= e($s['home_address'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="row">
        <div class="field">
            <label>Pickup person</label>
            <input name="pickup_person" maxlength="120" value="<?= e($s['pickup_person'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Pickup phone</label>
            <input name="pickup_phone" maxlength="40" value="<?= e($s['pickup_phone'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Emergency contact name</label>
            <input name="emergency_contact_name" maxlength="120" value="<?= e($s['emergency_contact_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Emergency contact phone</label>
            <input name="emergency_contact_phone" maxlength="40" value="<?= e($s['emergency_contact_phone'] ?? '') ?>">
        </div>
    </div>

    <h2 class="section-h-spaced">Parents &amp; guardians</h2>
    <p class="muted small">Tick one as <strong>primary</strong> — that's the first point of contact. Leave the name blank to remove a row.</p>

    <div id="parents-block">
        <?php
        // Render existing parents + one blank slot to add a new entry.
        $rowsForView = $parents;
        $rowsForView[] = ['id' => 0, 'relation' => 'guardian', 'name' => '', 'phone' => '', 'email' => '', 'occupation' => '', 'address' => '', 'is_primary' => 0];
        foreach ($rowsForView as $i => $p):
        ?>
            <div class="card parent-edit">
                <input type="hidden" name="parents[<?= $i ?>][id]" value="<?= (int)$p['id'] ?>">
                <div class="row">
                    <div class="field">
                        <label>Name</label>
                        <input name="parents[<?= $i ?>][name]" maxlength="120" value="<?= e($p['name']) ?>" placeholder="—">
                    </div>
                    <div class="field">
                        <label>Relation</label>
                        <select name="parents[<?= $i ?>][relation]">
                            <?php foreach ($VALID_RELATION as $rel): ?>
                                <option value="<?= e($rel) ?>" <?= $p['relation'] === $rel ? 'selected' : '' ?>><?= e(ucfirst($rel)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Phone</label>
                        <input name="parents[<?= $i ?>][phone]" maxlength="40" value="<?= e($p['phone']) ?>">
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="parents[<?= $i ?>][email]" maxlength="120" value="<?= e($p['email']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="field">
                        <label>Occupation</label>
                        <input name="parents[<?= $i ?>][occupation]" maxlength="120" value="<?= e($p['occupation']) ?>">
                    </div>
                    <div class="field" style="flex: 2 1 320px;">
                        <label>Address</label>
                        <input name="parents[<?= $i ?>][address]" maxlength="500" value="<?= e($p['address']) ?>">
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="parents[<?= $i ?>][is_primary]" value="1" <?= $p['is_primary'] ? 'checked' : '' ?>>
                            <span>Primary</span>
                        </label>
                    </div>
                    <?php if ((int)$p['id'] > 0): ?>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="parents[<?= $i ?>][_delete]" value="1">
                            <span>Remove</span>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create student' ?></button>
        <a class="btn btn-ghost" href="<?= $isEdit ? '/students/view.php?id=' . (int)$s['id'] : '/students/index.php' ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
