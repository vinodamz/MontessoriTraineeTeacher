<?php
/**
 * recruitment/evaluate.php — scorecard form (per evaluator).
 *
 * GET  ?candidate_id=N → loads the current user's existing scorecard if any,
 *                        or a blank one.
 * POST                 → upserts (one row per candidate × evaluator) via the
 *                        same UNIQUE(candidate_id, evaluator_id) key the JSON
 *                        api uses. Direct form post here so non-JS clients
 *                        still work.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$cid = (int)($_GET['candidate_id'] ?? $_POST['candidate_id'] ?? 0);
if ($cid <= 0) { http_response_code(400); echo 'candidate_id required.'; exit; }

// Confirm candidate exists.
$stmt = db()->prepare("SELECT id, first_name, last_name FROM recruit_candidates WHERE id = :id");
$stmt->execute([':id' => $cid]);
$cand = $stmt->fetch();
if (!$cand) { http_response_code(404); echo 'Candidate not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dims = array_keys(recruit_eval_dimensions());
    $vals = [':c' => $cid, ':e' => (int)$user['id']];
    foreach ($dims as $d) {
        $v = $_POST[$d] ?? '';
        if ($v === '' || $v === null) { $vals[":$d"] = null; }
        else {
            $n = (int)$v;
            $vals[":$d"] = ($n >= 1 && $n <= 5) ? $n : null;
        }
    }
    $rec = $_POST['overall_recommend'] ?? '';
    $vals[':r'] = ($rec !== '' && array_key_exists($rec, recruit_recommendations())) ? $rec : null;
    $vals[':m'] = trim((string)($_POST['comments'] ?? '')) ?: null;

    $cols   = implode(', ', $dims);
    $params = implode(', ', array_map(fn($d) => ":$d", $dims));
    $setParts = array_map(fn($d) => "$d = VALUES($d)", $dims);
    $setParts[] = 'overall_recommend = VALUES(overall_recommend)';
    $setParts[] = 'comments = VALUES(comments)';

    db()->prepare("
        INSERT INTO recruit_evaluations
            (candidate_id, evaluator_id, $cols, overall_recommend, comments)
        VALUES
            (:c, :e, $params, :r, :m)
        ON DUPLICATE KEY UPDATE " . implode(', ', $setParts)
    )->execute($vals);

    flash_set('ok', 'Scorecard saved.');
    redirect('/recruitment/view.php?id=' . $cid . '#evaluations');
}

// ---- GET — pre-fill with this user's existing scorecard if any -----------
$stmt = db()->prepare("
    SELECT * FROM recruit_evaluations
    WHERE candidate_id = :c AND evaluator_id = :e
");
$stmt->execute([':c' => $cid, ':e' => (int)$user['id']]);
$ev = $stmt->fetch() ?: [];

$full = trim(($cand['first_name'] ?? '') . ' ' . ($cand['last_name'] ?? ''));
$pageTitle = 'Scorecard — ' . $full;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Scorecard</h1>
        <p class="muted">
            <a href="/recruitment/view.php?id=<?= $cid ?>">← <?= e($full) ?></a>
            · evaluator: <?= e($user['name']) ?>
        </p>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="candidate_id" value="<?= (int)$cid ?>">

    <p class="muted small">Rate from 1 (poor) to 5 (excellent). Leave blank if you didn't observe it.</p>

    <div class="row">
        <?php foreach (recruit_eval_dimensions() as $code => $label):
            $cur = $ev[$code] ?? null;
        ?>
            <div class="field">
                <label><?= e($label) ?></label>
                <select name="<?= e($code) ?>">
                    <option value="">—</option>
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                        <option value="<?= $n ?>" <?= (int)$cur === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php endforeach; ?>
    </div>

    <h3 class="section-h-spaced">Overall recommendation</h3>
    <div class="row">
        <div class="field">
            <select name="overall_recommend">
                <option value="">—</option>
                <?php foreach (recruit_recommendations() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($ev['overall_recommend'] ?? '') === $code ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="section-h-spaced">Comments</h3>
    <div class="field">
        <textarea name="comments" rows="4"
                  placeholder="What stood out? Demo day observations, soft-skill anecdotes…"><?= e((string)($ev['comments'] ?? '')) ?></textarea>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit">Save scorecard</button>
        <a class="btn btn-ghost" href="/recruitment/view.php?id=<?= $cid ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
