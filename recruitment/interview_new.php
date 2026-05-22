<?php
/**
 * recruitment/interview_new.php — log an interview / demo / note.
 *
 * GET  ?candidate_id=N → blank form
 * POST                 → insert + redirect to view.php#interviews
 *
 * Non-JS form mirror of api.php op=interview.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$cid = (int)($_GET['candidate_id'] ?? $_POST['candidate_id'] ?? 0);
if ($cid <= 0) { http_response_code(400); echo 'candidate_id required.'; exit; }

$stmt = db()->prepare("SELECT id, first_name, last_name FROM recruit_candidates WHERE id = :id");
$stmt->execute([':id' => $cid]);
$cand = $stmt->fetch();
if (!$cand) { http_response_code(404); echo 'Candidate not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $stage = $_POST['stage'] ?? 'note';
    if (!array_key_exists($stage, recruit_interview_stages())) $stage = 'note';
    $when = trim($_POST['occurred_at'] ?? '');
    if ($when === '') {
        flash_set('error', 'When did it happen?');
        redirect('/recruitment/interview_new.php?candidate_id=' . $cid);
    }
    $outcome = $_POST['outcome'] ?? '';
    $outcome = in_array($outcome, ['pending','passed','failed','no_show'], true) ? $outcome : null;

    db()->prepare("
        INSERT INTO recruit_interviews
            (candidate_id, interviewer_id, stage, occurred_at,
             duration_min, location, outcome, body, created_by)
        VALUES
            (:c, :i, :s, :w, :d, :l, :o, :b, :u)
    ")->execute([
        ':c' => $cid,
        ':i' => (int)($_POST['interviewer_id'] ?? $user['id']) ?: null,
        ':s' => $stage,
        ':w' => $when,
        ':d' => ($_POST['duration_min'] ?? '') !== '' ? (int)$_POST['duration_min'] : null,
        ':l' => trim((string)($_POST['location'] ?? '')) ?: null,
        ':o' => $outcome,
        ':b' => trim((string)($_POST['body'] ?? '')) ?: null,
        ':u' => (int)$user['id'],
    ]);
    flash_set('ok', 'Interview logged.');
    redirect('/recruitment/view.php?id=' . $cid . '#interviews');
}

$interviewers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('recruitment', modules) > 0)
    ORDER BY name
")->fetchAll();

$full = trim(($cand['first_name'] ?? '') . ' ' . ($cand['last_name'] ?? ''));
$pageTitle = 'Log interview — ' . $full;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Log interview / note</h1>
        <p class="muted"><a href="/recruitment/view.php?id=<?= $cid ?>">← <?= e($full) ?></a></p>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="candidate_id" value="<?= (int)$cid ?>">

    <div class="row">
        <div class="field">
            <label>Stage</label>
            <select name="stage">
                <?php foreach (recruit_interview_stages() as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>When</label>
            <input name="occurred_at" type="datetime-local" required
                   value="<?= e(date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="field">
            <label>Duration (min)</label>
            <input name="duration_min" type="number" min="0" max="600" step="5" placeholder="e.g. 45">
        </div>
        <div class="field">
            <label>Location</label>
            <input name="location" maxlength="160" placeholder="On-site / Zoom / phone">
        </div>
        <div class="field">
            <label>Outcome</label>
            <select name="outcome">
                <option value="">—</option>
                <option value="pending">Pending</option>
                <option value="passed">Passed</option>
                <option value="failed">Failed</option>
                <option value="no_show">No show</option>
            </select>
        </div>
        <div class="field">
            <label>Interviewer</label>
            <select name="interviewer_id">
                <?php foreach ($interviewers as $iv): ?>
                    <option value="<?= (int)$iv['id'] ?>" <?= (int)$user['id'] === (int)$iv['id'] ? 'selected' : '' ?>>
                        <?= e($iv['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="section-h-spaced">Notes</h3>
    <div class="field">
        <textarea name="body" rows="5"
                  placeholder="Demo day observations, behavioural anecdotes, reference-check quotes…"></textarea>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit">Log interview</button>
        <a class="btn btn-ghost" href="/recruitment/view.php?id=<?= $cid ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
