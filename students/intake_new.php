<?php
/**
 * students/intake_new.php — admin starts a brand-new admission intake.
 *
 *   GET  → small form (child first name, grade, optional teacher)
 *   POST → create a draft students row with enrollment_status='intake_pending',
 *          mint a parent-form token for it, redirect to view.php where the
 *          link panel will show the shareable URL.
 *
 * Intake-pending rows are hidden from the default students list and only
 * appear under the "Intake review" filter. They satisfy the NOT NULL
 * constraints on first_name / grade / teacher_id by capturing minimal
 * placeholder values up-front; the parent fills the rest via the link,
 * and an admin clicks "Approve" on view.php to flip the row to enrolled.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_form.php';

$user = require_admin();

// Teacher pool for the dropdown — admins + montessori-module teachers.
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
    ORDER BY name
")->fetchAll();

$validGrades = ['Playgroup', 'Nursery', 'LKG', 'UKG'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $first   = trim($_POST['first_name'] ?? '');
    $grade   = $_POST['grade'] ?? '';
    $tid     = (int)($_POST['teacher_id'] ?? 0);
    $year    = trim($_POST['academic_year'] ?? '') ?: current_academic_year();

    $errs = [];
    if ($first === '')                                $errs[] = 'Child name is required.';
    if (!in_array($grade, $validGrades, true))        $errs[] = 'Pick a grade.';
    if ($tid <= 0)                                    $errs[] = 'Pick a teacher.';

    if ($errs) {
        flash_set('error', implode(' ', $errs));
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            // is_active=1 from the start — the enrollment_status filter
            // (default 'enrolled') already hides intake_pending rows from
            // the main list, so we don't also need is_active=0 to do it,
            // and keeping is_active=1 means the status=intake_pending
            // dropdown surfaces these rows without also needing the user
            // to flip the Active filter to "All".
            $ins = $pdo->prepare("
                INSERT INTO students
                    (first_name, last_name, grade, teacher_id,
                     enrollment_status, is_active, academic_year)
                VALUES
                    (:f, '', :g, :tid, 'intake_pending', 1, :ay)
            ");
            $ins->execute([':f' => $first, ':g' => $grade, ':tid' => $tid, ':ay' => $year]);
            $sid = (int)$pdo->lastInsertId();
            generate_form_token($sid, (int)$user['id']);
            $pdo->commit();
            flash_set('ok', 'Intake started — share the parent form link below.');
            redirect('/students/view.php?id=' . $sid);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash_set('error', 'Could not start intake: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Start admission intake';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Start admission intake</h1>
        <p class="muted">For a brand-new family. Captures a placeholder student record, issues a parent-form link, and waits for the family to fill the form. After review, click <em>Approve</em> on the profile to add them to the student list.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<form method="post" class="card card-form" style="max-width: 540px;">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="field">
        <label>Child's first name <span class="muted small">(can be a placeholder; parent corrects it on the form)</span></label>
        <input type="text" name="first_name" required maxlength="80" autofocus>
    </div>

    <div class="row">
        <div class="field">
            <label>Grade</label>
            <select name="grade" required>
                <option value=""></option>
                <?php foreach ($validGrades as $g): ?>
                    <option value="<?= e($g) ?>"><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Academic year</label>
            <input type="text" name="academic_year" value="<?= e(current_academic_year()) ?>" maxlength="9" placeholder="2026-27">
        </div>
    </div>

    <div class="field">
        <label>Assigned teacher <span class="muted small">(can be changed later)</span></label>
        <select name="teacher_id" required>
            <option value=""></option>
            <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$user['id']) ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="actions">
        <button class="btn btn-primary" type="submit">Start intake &amp; get link</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
