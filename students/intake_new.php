<?php
/**
 * students/intake_new.php — admin starts a brand-new admission intake.
 *
 *   GET  → small form. Optionally select an unpromoted child from the
 *          CRM admission pipeline at the top to pre-fill everything.
 *   POST → create a draft students row with enrollment_status='intake_pending',
 *          mint a parent-form token for it, redirect to view.php where the
 *          link panel will show the shareable URL.
 *
 * Two intake paths:
 *   - Manual: admin types the child's first name + grade + teacher.
 *   - From CRM: admin picks an inquiry_children row from the dropdown.
 *     The intake row is created from the inquiry's child + family data,
 *     student_parents rows are seeded from inquiry_parents, and the
 *     inquiry_children.promoted_student_id is back-linked so the CRM
 *     family view shows "enrolled →".
 *
 * Intake-pending rows are hidden from the default students list and only
 * appear under the "Intake pending" status filter. They satisfy the
 * NOT NULL constraints on first_name / grade / teacher_id from the inquiry
 * data (when present) or from the manual form fields.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_form.php';

$user = require_admin();

$validGrades = ['Playgroup', 'Nursery', 'LKG', 'UKG'];

// Teacher pool for the dropdown — admins + montessori-module teachers.
$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0)
    ORDER BY name
")->fetchAll();

// Unpromoted inquiries from the CRM pipeline. Skip leads marked 'lost' —
// every other stage is fair game for an admission intake.
$pipelineKids = [];
try {
    $pipelineKids = db()->query("
        SELECT ic.id, ic.first_name, ic.last_name, ic.target_grade, ic.dob,
               f.id AS family_id, f.primary_name, f.status
        FROM   inquiry_children ic
        JOIN   inquiry_families f ON f.id = ic.family_id
        WHERE  ic.promoted_student_id IS NULL
          AND  COALESCE(f.status, '') NOT IN ('lost')
        ORDER  BY f.updated_at DESC, ic.first_name
        LIMIT  200
    ")->fetchAll();
} catch (Throwable $e) { /* CRM tables may not exist on bare installs — ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $inquiryChildId = (int)($_POST['inquiry_child_id'] ?? 0);
    $tid            = (int)($_POST['teacher_id'] ?? 0);
    $year           = trim($_POST['academic_year'] ?? '') ?: current_academic_year();

    $first  = trim($_POST['first_name'] ?? '');
    $grade  = $_POST['grade'] ?? '';
    $last   = '';
    $dob    = null;
    $gender = null;
    $sourceInquiry = null;
    $sourceFamilyParents = [];

    // CRM-sourced path: rehydrate child + parents from the inquiry.
    if ($inquiryChildId > 0) {
        $stmt = db()->prepare("
            SELECT ic.*, f.primary_name AS family_primary_name
            FROM   inquiry_children ic
            JOIN   inquiry_families f ON f.id = ic.family_id
            WHERE  ic.id = :id AND ic.promoted_student_id IS NULL
            LIMIT  1
        ");
        $stmt->execute([':id' => $inquiryChildId]);
        $sourceInquiry = $stmt->fetch() ?: null;
        if (!$sourceInquiry) {
            flash_set('error', 'That pipeline entry is no longer available — it may have been enrolled or removed.');
            redirect('/students/intake_new.php');
        }

        // Inquiry fields win over manual inputs, but the manual inputs can
        // override when they're non-empty (admin already typed something).
        $first  = $first !== '' ? $first : (string)$sourceInquiry['first_name'];
        $last   = (string)($sourceInquiry['last_name'] ?? '');
        $dob    = $sourceInquiry['dob'] ?: null;
        $gender = $sourceInquiry['gender'] ?: null;
        if ($grade === '') $grade = (string)($sourceInquiry['target_grade'] ?? '');

        $pstmt = db()->prepare("SELECT * FROM inquiry_parents WHERE family_id = :fid ORDER BY is_primary DESC, id");
        $pstmt->execute([':fid' => (int)$sourceInquiry['family_id']]);
        $sourceFamilyParents = $pstmt->fetchAll();
    }

    $errs = [];
    if ($first === '')                         $errs[] = 'Child name is required.';
    if (!in_array($grade, $validGrades, true)) $errs[] = 'Pick a grade.';
    if ($tid <= 0)                             $errs[] = 'Pick a teacher.';

    if ($errs) {
        flash_set('error', implode(' ', $errs));
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // is_active=1 from the start — the enrollment_status filter
            // ('enrolled' by default) already hides intake_pending rows
            // from the main list. See PR #68 for the rationale.
            $ins = $pdo->prepare("
                INSERT INTO students
                    (first_name, last_name, grade, teacher_id,
                     enrollment_status, is_active, academic_year,
                     dob, gender)
                VALUES
                    (:f, :l, :g, :tid, 'intake_pending', 1, :ay, :dob, :gender)
            ");
            $ins->execute([
                ':f'      => $first,
                ':l'      => $last,
                ':g'      => $grade,
                ':tid'    => $tid,
                ':ay'     => $year,
                ':dob'    => $dob,
                ':gender' => $gender,
            ]);
            $sid = (int)$pdo->lastInsertId();

            // Seed student_parents from the inquiry's parent rows when the
            // CRM path was used. Keeps the inquiry's relation / contact info
            // so the parent doesn't have to re-type it on the public form.
            foreach ($sourceFamilyParents as $p) {
                $pdo->prepare("
                    INSERT INTO student_parents
                        (student_id, relation, name, phone, email, occupation, is_primary)
                    VALUES (:sid, :rel, :n, :ph, :em, :oc, :pr)
                ")->execute([
                    ':sid' => $sid,
                    ':rel' => $p['relation'] ?? 'guardian',
                    ':n'   => $p['name'],
                    ':ph'  => $p['phone']      !== '' ? $p['phone']      : null,
                    ':em'  => $p['email']      !== '' ? $p['email']      : null,
                    ':oc'  => $p['occupation'] !== '' ? $p['occupation'] : null,
                    ':pr'  => (int)($p['is_primary'] ?? 0),
                ]);
            }

            // Back-link the inquiry so the CRM family view shows "enrolled →".
            if ($sourceInquiry) {
                $pdo->prepare("UPDATE inquiry_children SET promoted_student_id = :sid WHERE id = :id")
                    ->execute([':sid' => $sid, ':id' => (int)$sourceInquiry['id']]);
            }

            generate_form_token($sid, (int)$user['id']);
            $pdo->commit();
            flash_set('ok', $sourceInquiry
                ? 'Intake started from CRM pipeline — share the parent form link below.'
                : 'Intake started — share the parent form link below.');
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

<form method="post" class="card card-form" style="max-width: 600px;">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <?php
    // Deep links from the CRM family view land here with the child
    // pre-selected: /students/intake_new.php?inquiry_child_id=N
    $preselect = isset($_GET['inquiry_child_id']) ? (int)$_GET['inquiry_child_id'] : 0;
    ?>
    <?php if ($pipelineKids): ?>
        <div class="field">
            <label>Pre-fill from CRM pipeline <span class="muted small">(optional)</span></label>
            <select name="inquiry_child_id">
                <option value="">— Type details manually below —</option>
                <?php foreach ($pipelineKids as $k):
                    $childName  = trim((string)$k['first_name'] . ' ' . (string)($k['last_name'] ?? ''));
                    $gradeBit   = $k['target_grade'] ? ' · ' . $k['target_grade'] : '';
                    $statusBit  = $k['status'] ? ' (' . $k['status'] . ')' : '';
                    $familyBit  = $k['primary_name'] ? ' — ' . $k['primary_name'] : '';
                ?>
                    <option value="<?= (int)$k['id'] ?>" <?= $preselect === (int)$k['id'] ? 'selected' : '' ?>><?= e($childName . $gradeBit . $familyBit . $statusBit) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted small" style="margin:.3rem 0 0;">
                Picking an inquiry copies the child's name, DOB, gender, target grade, and parent contacts into the intake. The CRM card is back-linked to the new student.
            </p>
        </div>
    <?php else: ?>
        <p class="muted small" style="margin:0 0 .8rem;">
            No unpromoted inquiries in the CRM pipeline. Fill the form manually below.
        </p>
    <?php endif; ?>

    <div class="field">
        <label>Child's first name <span class="muted small">(skip if you picked from CRM above — we'll use the inquiry's name)</span></label>
        <input type="text" name="first_name" maxlength="80">
    </div>

    <div class="row">
        <div class="field">
            <label>Grade <span class="muted small">(blank = use CRM target grade)</span></label>
            <select name="grade">
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
