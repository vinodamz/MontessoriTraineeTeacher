<?php
/**
 * assess.php — monthly assessment entry for one student.
 *
 * GET  ?student_id=N&month=Jun-25 → renders the rating form (pre-filled if a
 *      prior assessment exists for the same student+teacher+month).
 * POST → transactional save: deletes prior eval_cards/assessments/comments
 *      for (student_id, teacher_id, month_year) then inserts fresh rows.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();

// ---------- Resolve student + month ----------------------------------------
$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
$month     = trim($_REQUEST['month'] ?? '');
if ($month === '' || !DateTime::createFromFormat('M-y', $month)) {
    $month = current_month_year();
}

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
    echo 'You can only assess your own students.';
    exit;
}

// Teacher whose name attaches to this assessment.
$assessingTeacherId = (int)$user['id'];

// ---------- POST: save -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $rmap        = rating_config_map();
    $ratings     = $_POST['rating'] ?? [];          // key = "std:NN" or "cust:NN", value = D|P|N
    $catComments = $_POST['cat_comment'] ?? [];     // key = category name
    $overall     = trim($_POST['overall_comment'] ?? '');

    if (!is_array($ratings))     $ratings = [];
    if (!is_array($catComments)) $catComments = [];

    // Group ratings by category (we need to know each indicator's category).
    $stdIds  = [];
    $custIds = [];
    foreach ($ratings as $key => $val) {
        if (!preg_match('/^(std|cust):(\d+)$/', $key, $m)) continue;
        if (!isset($rmap[$val])) continue;
        if ($m[1] === 'std')  $stdIds[]  = (int)$m[2];
        else                  $custIds[] = (int)$m[2];
    }

    $indicatorCat = [];   // ["std:123" => "MATHEMATICS", ...]
    if ($stdIds) {
        $place = implode(',', array_fill(0, count($stdIds), '?'));
        $rows  = db()->prepare("SELECT id, category FROM skill_indicators WHERE id IN ($place)");
        $rows->execute($stdIds);
        foreach ($rows as $r) $indicatorCat['std:' . $r['id']] = $r['category'];
    }
    if ($custIds) {
        $place = implode(',', array_fill(0, count($custIds), '?'));
        $rows  = db()->prepare("SELECT id, category FROM student_custom_indicators WHERE id IN ($place) AND student_id = ?");
        $params = $custIds;
        $params[] = $studentId;
        $rows->execute($params);
        foreach ($rows as $r) $indicatorCat['cust:' . $r['id']] = $r['category'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM evaluation_cards   WHERE student_id=:s AND teacher_id=:t AND month_year=:m");
        $del->execute([':s' => $studentId, ':t' => $assessingTeacherId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessments        WHERE student_id=:s AND teacher_id=:t AND month_year=:m");
        $del->execute([':s' => $studentId, ':t' => $assessingTeacherId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessment_comments WHERE student_id=:s AND teacher_id=:t AND month_year=:m");
        $del->execute([':s' => $studentId, ':t' => $assessingTeacherId, ':m' => $month]);

        // Insert eval cards + tally per-category averages.
        $ins = $pdo->prepare("
            INSERT INTO evaluation_cards
                (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator)
            VALUES (:s, :t, :m, :i, :r, :c)
        ");
        $catTotals = []; // [cat => [sum, count]]
        foreach ($ratings as $key => $val) {
            if (!isset($indicatorCat[$key]))  continue;
            if (!isset($rmap[$val]))          continue;
            [$kind, $idStr] = explode(':', $key, 2);
            $isCustom = $kind === 'cust' ? 1 : 0;
            $ins->execute([
                ':s' => $studentId,
                ':t' => $assessingTeacherId,
                ':m' => $month,
                ':i' => (int)$idStr,
                ':r' => $val,
                ':c' => $isCustom,
            ]);
            $cat = $indicatorCat[$key];
            $catTotals[$cat] ??= [0, 0];
            $catTotals[$cat][0] += (int)$rmap[$val]['numeric_value'];
            $catTotals[$cat][1] += 1;
        }

        $insA = $pdo->prepare("
            INSERT INTO assessments
                (student_id, teacher_id, month_year, category, score, category_avg)
            VALUES (:s, :t, :m, :c, :sc, :av)
        ");
        foreach ($catTotals as $cat => [$sum, $n]) {
            if ($n === 0) continue;
            $avg = $sum / $n;
            $insA->execute([
                ':s'  => $studentId,
                ':t'  => $assessingTeacherId,
                ':m'  => $month,
                ':c'  => $cat,
                ':sc' => (int)round($avg),
                ':av' => number_format($avg, 2, '.', ''),
            ]);
        }

        $insC = $pdo->prepare("
            INSERT INTO assessment_comments
                (student_id, teacher_id, month_year, category, comment)
            VALUES (:s, :t, :m, :c, :body)
        ");
        foreach ($catComments as $cat => $body) {
            $body = trim($body);
            if ($body === '') continue;
            $insC->execute([
                ':s' => $studentId, ':t' => $assessingTeacherId,
                ':m' => $month,     ':c' => (string)$cat,
                ':body' => $body,
            ]);
        }
        if ($overall !== '') {
            $insC->execute([
                ':s' => $studentId, ':t' => $assessingTeacherId,
                ':m' => $month,     ':c' => null,
                ':body' => $overall,
            ]);
        }

        $pdo->commit();
        flash_set('ok', 'Assessment saved.');
        redirect('progress.php?student_id=' . $studentId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Save failed: ' . $e->getMessage());
        redirect("assess.php?student_id=$studentId&month=" . urlencode($month));
    }
}

// ---------- GET: render form -----------------------------------------------

// Indicators for this student's grade.
$stmt = db()->prepare("
    SELECT id, category, indicator_text, display_order
    FROM skill_indicators
    WHERE grade = :g AND is_active = 1
    ORDER BY category, display_order, id
");
$stmt->execute([':g' => $student['grade']]);
$indicators = $stmt->fetchAll();

// Student-specific custom indicators.
$stmt = db()->prepare("
    SELECT id, category, indicator_text, display_order
    FROM student_custom_indicators
    WHERE student_id = :s AND is_active = 1
    ORDER BY category, display_order, id
");
$stmt->execute([':s' => $studentId]);
$customIndicators = $stmt->fetchAll();

// Pre-fill: existing ratings for this (student, teacher, month).
$existing = [];
$stmt = db()->prepare("
    SELECT indicator_id, rating, is_custom_indicator
    FROM evaluation_cards
    WHERE student_id = :s AND teacher_id = :t AND month_year = :m
");
$stmt->execute([':s' => $studentId, ':t' => $assessingTeacherId, ':m' => $month]);
foreach ($stmt as $r) {
    $key = ($r['is_custom_indicator'] ? 'cust' : 'std') . ':' . $r['indicator_id'];
    $existing[$key] = $r['rating'];
}

// Pre-fill: comments.
$existingCatComment = [];
$existingOverall    = '';
$stmt = db()->prepare("
    SELECT category, comment
    FROM assessment_comments
    WHERE student_id = :s AND teacher_id = :t AND month_year = :m
");
$stmt->execute([':s' => $studentId, ':t' => $assessingTeacherId, ':m' => $month]);
foreach ($stmt as $r) {
    if ($r['category'] === null) $existingOverall = $r['comment'];
    else                         $existingCatComment[$r['category']] = $r['comment'];
}

// Group indicators by category (preserve insertion order — already sorted).
$byCategory = [];
foreach ($indicators as $i) {
    $byCategory[$i['category']]['std'][] = $i;
}
foreach ($customIndicators as $i) {
    $byCategory[$i['category']]['cust'][] = $i;
}

$rmap     = rating_config_map();
$ratingCodes = rating_codes();
$months   = academic_months();
$fullName = trim($student['first_name'] . ' ' . $student['last_name']);

$pageTitle = "Assess " . $fullName;
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Assess <?= e($fullName) ?></h1>
        <p class="muted">
            <?= e($student['grade']) ?>
            <?php if (!empty($existing)): ?>· <span class="pill">Editing existing assessment</span><?php endif; ?>
        </p>
    </div>
    <form method="get" class="month-switch">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <label>Month
            <select name="month" onchange="this.form.submit()">
                <?php foreach ($months as $my): ?>
                    <option value="<?= e($my) ?>" <?= $my === $month ? 'selected' : '' ?>>
                        <?= e(month_year_label($my)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <a class="btn btn-ghost" href="custom_indicators.php?student_id=<?= $studentId ?>">Custom indicators</a>
        <a class="btn btn-ghost" href="index.php">Back</a>
    </form>
</div>

<?php if (!$byCategory): ?>
    <div class="empty">
        <p>No indicators have been seeded for <strong><?= e($student['grade']) ?></strong> yet.
        Apply <code>sql/seeds.sql</code> or add indicators in Admin → Indicators.</p>
    </div>
<?php else: ?>
<form method="post" class="assess-form" id="assessForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="rating-legend">
        <?php foreach ($ratingCodes as $code): ?>
            <span class="rating-key rating-<?= e($code) ?>" style="--ring: <?= e($rmap[$code]['color']) ?>">
                <strong><?= e($code) ?></strong> · <?= e($rmap[$code]['label']) ?>
            </span>
        <?php endforeach; ?>
    </div>

    <?php foreach ($byCategory as $cat => $groups): ?>
        <section class="cat-block">
            <h2><?= e($cat) ?></h2>
            <table class="rating-table">
                <tbody>
                <?php
                    $allIndicators = array_merge($groups['std'] ?? [], array_map(function($r) {
                        $r['_kind'] = 'cust'; return $r;
                    }, $groups['cust'] ?? []));
                    foreach ($allIndicators as $ind):
                        $kind = $ind['_kind'] ?? 'std';
                        $key  = "$kind:" . $ind['id'];
                        $sel  = $existing[$key] ?? '';
                ?>
                    <tr>
                        <td class="ind-text">
                            <?= e($ind['indicator_text']) ?>
                            <?php if ($kind === 'cust'): ?><span class="pill small">custom</span><?php endif; ?>
                        </td>
                        <td class="ind-rating">
                            <?php foreach ($ratingCodes as $code): ?>
                                <label class="rating-pick rating-<?= e($code) ?> <?= $sel === $code ? 'is-on' : '' ?>"
                                       style="--ring: <?= e($rmap[$code]['color']) ?>">
                                    <input type="radio"
                                           name="rating[<?= e($key) ?>]"
                                           value="<?= e($code) ?>"
                                           <?= $sel === $code ? 'checked' : '' ?>>
                                    <?= e($code) ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <label class="cat-comment">
                <span>Comment on <?= e($cat) ?> <span class="muted small">(optional)</span></span>
                <textarea name="cat_comment[<?= e($cat) ?>]" rows="2"><?= e($existingCatComment[$cat] ?? '') ?></textarea>
            </label>
        </section>
    <?php endforeach; ?>

    <section class="cat-block">
        <h2>Overall comment</h2>
        <textarea name="overall_comment" rows="3" placeholder="A short note for the parents and the file."><?= e($existingOverall) ?></textarea>
    </section>

    <div class="form-actions">
        <a class="btn btn-ghost" href="index.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Save assessment</button>
    </div>
</form>
<script src="assets/js/assess.js?v=<?= e(asset_version()) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
