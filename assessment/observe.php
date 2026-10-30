<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/observations.php';
$user = require_module('montessori');
$studentId = (int)($_GET['student_id'] ?? 0);
$s = db()->prepare('SELECT * FROM students WHERE id=?');
$s->execute([$studentId]);
$student = $s->fetch();
if (!$student) { http_response_code(404); exit('Student not found.'); }
if (!observation_can_access($user,$student)) { http_response_code(403); exit('You can only observe your own students.'); }
$q = db()->prepare('SELECT enabled FROM student_observation_preferences WHERE student_id = ?');
$q->execute([$studentId]);
if (!$q->fetchColumn()) { http_response_code(403); exit('Enable observations for this student on the progress page first.'); }
$error = '';
$input = ['observed_on'=>date('Y-m-d'),'submission_token'=>bin2hex(random_bytes(32))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $input = $_POST;
    try {
        observation_save(db(),$user,$student,$input);
        flash_set('ok','Observation saved.');
        redirect('progress.php?student_id='.$studentId);
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log((string)$e); $error='Observation could not be saved. Please try again.'; }
}
$indicators=observation_indicators(db(),$student);
$ratings=rating_config_map();
$pageTitle='Record observation';
require __DIR__.'/../includes/header.php';
?>
<div class="page-head"><div><h1>Record observation</h1><p><?= e(trim($student['first_name'].' '.$student['last_name'])) ?> · <?= e($student['grade']) ?></p></div><a class="btn" href="progress.php?student_id=<?= $studentId ?>">Progress/history</a></div>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<p>Select a level only for skills you actually observed. Leave the others blank. Saved observations remain in the child's history.</p>
<?php if (!$indicators || !$ratings): ?>
<div class="empty">No active skills or ratings are configured for this child. Ask an administrator to configure them.</div>
<?php else: ?>
<label>Find a skill <input type="search" id="skillSearch" placeholder="Search skill or category" autocomplete="off"></label>
<p id="skillSearchStatus" class="muted" aria-live="polite"></p>
<form method="post" id="observationForm">
<div class="card" style="position:sticky;top:0;z-index:5;background:white"><span id="selectedCount" aria-live="polite">0 skills selected</span> <button class="btn btn-primary" type="submit">Save observation</button></div>
<input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="submission_token" value="<?= e(is_string($input['submission_token'] ?? null) ? $input['submission_token'] : '') ?>">
<label>Observation date <input type="date" name="observed_on" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(is_string($input['observed_on'] ?? null) ? $input['observed_on'] : '') ?>"></label>
<?php $cat=null; foreach ($indicators as $key=>$ind): ?>
<?php if ($cat!==$ind['category']): $cat=$ind['category']; ?><h2 class="observation-category" data-category="<?= e($cat) ?>"><?= e($cat) ?></h2><?php endif; ?>
<fieldset class="card observation-skill" data-category="<?= e($ind['category']) ?>" data-search="<?= e(strtolower($ind['category'] . ' ' . $ind['indicator_text'])) ?>"><legend><?= e($ind['indicator_text']) ?></legend>
<label>Observed level <select name="rating[<?= e($key) ?>]">
<option value="">Not observed</option>
<?php foreach ($ratings as $code=>$r): ?><option value="<?= e($code) ?>" <?= (($input['rating'][$key] ?? '')===$code) ? 'selected' : '' ?>><?= e($code.' · '.$r['label']) ?></option><?php endforeach; ?>
</select></label>
<details <?= !empty($input['rating'][$key]) ? 'open' : '' ?>><summary>Evidence and next steps (optional)</summary>
<label>Evidence <textarea name="evidence[<?= e($key) ?>]" rows="2" maxlength="5000"><?= e(is_string($input['evidence'][$key] ?? null) ? $input['evidence'][$key] : '') ?></textarea></label>
<label>Next steps <textarea name="next_steps[<?= e($key) ?>]" rows="2" maxlength="5000"><?= e(is_string($input['next_steps'][$key] ?? null) ? $input['next_steps'][$key] : '') ?></textarea></label>
</details>
</fieldset>
<?php endforeach; ?>
<div class="form-actions"><a class="btn" href="index.php">Cancel</a><button class="btn btn-primary" type="submit">Save observation</button></div>
</form>
<script>
const search = document.getElementById('skillSearch');
if (search) search.addEventListener('input', () => {
    const query = search.value.toLocaleLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('.observation-skill').forEach(row => {
        row.hidden = !row.dataset.search.includes(query);
        if (!row.hidden) shown++;
    });
    document.querySelectorAll('.observation-category').forEach(heading => {
        heading.hidden = !Array.from(document.querySelectorAll('.observation-skill')).some(row => row.dataset.category === heading.dataset.category && !row.hidden);
    });
    document.getElementById('skillSearchStatus').textContent = shown + ' skills shown. Selected skills are saved even when hidden by search.';
});
const observationForm = document.getElementById('observationForm');
function countSelected() {
    const count = Array.from(observationForm.querySelectorAll('select[name^="rating["]')).filter(field => field.value !== '').length;
    document.getElementById('selectedCount').textContent = count + ' skills selected';
}
if (observationForm) { observationForm.addEventListener('change', countSelected); countSelected(); }
</script>
<?php endif; require __DIR__.'/../includes/footer.php'; ?>
