<?php
/**
 * baseline.php — entry-baseline observations for one student.
 * Single page, GET shows the form pre-filled if a row exists, POST upserts.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();

$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;

$stmt = db()->prepare("SELECT id, first_name, last_name, grade, teacher_id FROM students WHERE id = :id");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}
if ($user['role'] !== 'admin' && (int)$student['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'You can only edit your own students.';
    exit;
}

$fields = ['gross_motor', 'fine_motor', 'literacy', 'numeracy', 'social_skills', 'communication', 'overall_notes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    $recordedAt = trim($_POST['recorded_at'] ?? '');
    if ($recordedAt === '' || !DateTime::createFromFormat('Y-m-d', $recordedAt)) {
        $recordedAt = (new DateTime('today'))->format('Y-m-d');
    }
    $recordedBy = trim($_POST['recorded_by'] ?? $user['name']);

    $stmt = db()->prepare("
        INSERT INTO student_baselines
            (student_id, teacher_id, recorded_by, gross_motor, fine_motor, literacy,
             numeracy, social_skills, communication, overall_notes, recorded_at)
        VALUES
            (:s, :t, :rb, :gm, :fm, :lit, :num, :soc, :com, :notes, :rd)
        ON DUPLICATE KEY UPDATE
            teacher_id   = VALUES(teacher_id),
            recorded_by  = VALUES(recorded_by),
            gross_motor  = VALUES(gross_motor),
            fine_motor   = VALUES(fine_motor),
            literacy     = VALUES(literacy),
            numeracy     = VALUES(numeracy),
            social_skills= VALUES(social_skills),
            communication= VALUES(communication),
            overall_notes= VALUES(overall_notes),
            recorded_at  = VALUES(recorded_at)
    ");
    $stmt->execute([
        ':s'    => $studentId,
        ':t'    => (int)$user['id'],
        ':rb'   => $recordedBy,
        ':gm'   => $data['gross_motor']    ?: null,
        ':fm'   => $data['fine_motor']     ?: null,
        ':lit'  => $data['literacy']       ?: null,
        ':num'  => $data['numeracy']       ?: null,
        ':soc'  => $data['social_skills']  ?: null,
        ':com'  => $data['communication']  ?: null,
        ':notes'=> $data['overall_notes']  ?: null,
        ':rd'   => $recordedAt,
    ]);

    flash_set('ok', 'Baseline saved.');
    redirect('progress.php?student_id=' . $studentId);
}

// Pre-fill if exists.
$stmt = db()->prepare("SELECT * FROM student_baselines WHERE student_id = :s");
$stmt->execute([':s' => $studentId]);
$bl = $stmt->fetch() ?: [];

$fullName  = trim($student['first_name'] . ' ' . $student['last_name']);
$pageTitle = "Baseline · $fullName";
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Baseline for <?= e($fullName) ?></h1>
        <p class="muted"><?= e($student['grade']) ?> · <?= $bl ? 'Last edit: ' . e($bl['recorded_at']) : 'No baseline recorded yet' ?></p>
    </div>
    <a class="btn btn-ghost" href="index.php">Back</a>
</div>

<form method="post" class="baseline-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="bl-grid">
        <label>Recorded on
            <input type="date" name="recorded_at" value="<?= e($bl['recorded_at'] ?? (new DateTime('today'))->format('Y-m-d')) ?>">
        </label>
        <label>Recorded by
            <input type="text" name="recorded_by" maxlength="120" value="<?= e($bl['recorded_by'] ?? $user['name']) ?>">
        </label>
    </div>

    <?php
    $labels = [
        'gross_motor'   => 'Gross motor',
        'fine_motor'    => 'Fine motor',
        'literacy'      => 'Literacy',
        'numeracy'      => 'Numeracy',
        'social_skills' => 'Social skills',
        'communication' => 'Communication',
    ];
    ?>
    <?php foreach ($labels as $f => $label): ?>
        <label class="cat-comment">
            <span><?= e($label) ?></span>
            <textarea name="<?= e($f) ?>" rows="2"><?= e($bl[$f] ?? '') ?></textarea>
        </label>
    <?php endforeach; ?>

    <label class="cat-comment">
        <span>Overall notes</span>
        <textarea name="overall_notes" rows="3"><?= e($bl['overall_notes'] ?? '') ?></textarea>
    </label>

    <div class="form-actions">
        <a class="btn btn-ghost" href="index.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Save baseline</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
