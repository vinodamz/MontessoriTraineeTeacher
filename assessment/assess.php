<?php
/**
 * assess.php — monthly assessment entry for one student.
 *
 * GET  ?student_id=N&month=Jun-25 → renders the rating form (pre-filled if a
 *      prior assessment exists for the same student+month).
 * POST → transactional save: deletes prior eval_cards/assessments/comments
 *      for (student_id, month_year) then inserts fresh rows. The month is the
 *      unit of record — teacher_id on the rows just records who saved last,
 *      so an admin (or a newly assigned teacher) edits the same data the
 *      previous assessor entered instead of creating an invisible duplicate.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_module('montessori');

// ---------- Resolve student + month ----------------------------------------
$studentId    = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
$monthParam   = trim($_REQUEST['month'] ?? '');
$monthDt      = $monthParam !== '' ? DateTime::createFromFormat('M-y', $monthParam) : false;
$monthChosen  = $monthDt !== false;
// Normalise case ("jun-25" → "Jun-25") so one calendar month can't exist
// under two different string keys.
if ($monthChosen) $monthParam = $monthDt->format('M-y');

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

// Months with existing data for this student (any teacher), sorted chronologically.
$stmt = db()->prepare("SELECT DISTINCT month_year FROM evaluation_cards WHERE student_id = :s");
$stmt->execute([':s' => $studentId]);
$existingMonths = $stmt->fetchAll(PDO::FETCH_COLUMN);
usort($existingMonths, 'compare_month_year');

// All academic months minus the ones already used.
$allAcademic  = academic_months();
$unusedMonths = array_values(array_diff($allAcademic, $existingMonths));
usort($unusedMonths, 'compare_month_year');

// Final $month resolution:
//   url param if valid → use it (could be either an existing or a new month)
//   else if any existing → most recent existing
//   else → current month_year (lets the user start a fresh assessment)
if ($monthChosen) {
    $month = $monthParam;
} elseif ($existingMonths) {
    $month = end($existingMonths);
} else {
    $month = current_month_year();
}
$isNewMonth = !in_array($month, $existingMonths, true);

// ---------- POST: save -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // The form carries its month in a hidden field. Refuse to guess: saving
    // under a re-resolved "latest" month could silently overwrite a different
    // month than the one the teacher was looking at.
    if (!$monthChosen) {
        flash_set('error', 'The form did not say which month it was for — nothing was saved. Please reopen the month and try again.');
        redirect("assess.php?student_id=$studentId");
    }

    // ALL configured codes (active + retired): an old month may carry a
    // legacy code; the save must keep it, not silently drop it.
    $rmap        = rating_config_map_all();
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
        // Restricted to the student's grade so a crafted POST can't attach
        // other-grade categories. Deliberately NOT restricted to is_active —
        // a rating carried forward for a since-retired indicator must survive
        // the save instead of being silently dropped.
        $place = implode(',', array_fill(0, count($stdIds), '?'));
        $rows  = db()->prepare("SELECT id, category FROM skill_indicators WHERE id IN ($place) AND grade = ?");
        $params = $stdIds;
        $params[] = $student['grade'];
        $rows->execute($params);
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
        // Month-scoped (not teacher-scoped): the previous assessor's rows are
        // replaced, not duplicated under a second teacher_id.
        $del = $pdo->prepare("DELETE FROM evaluation_cards   WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessments        WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);
        $del = $pdo->prepare("DELETE FROM assessment_comments WHERE student_id=:s AND month_year=:m");
        $del->execute([':s' => $studentId, ':m' => $month]);

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
        $msg = ($e instanceof PDOException && $e->getCode() == 23000)
            ? 'Someone else saved this month at the same moment — reload the page and check their entries before saving again.'
            : 'Save failed: ' . $e->getMessage();
        flash_set('error', $msg);
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

// Pre-fill: existing ratings for this (student, month) — whoever entered them.
$existing = [];
$stmt = db()->prepare("
    SELECT indicator_id, rating, is_custom_indicator
    FROM evaluation_cards
    WHERE student_id = :s AND month_year = :m
");
$stmt->execute([':s' => $studentId, ':m' => $month]);
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
    WHERE student_id = :s AND month_year = :m
");
$stmt->execute([':s' => $studentId, ':m' => $month]);
foreach ($stmt as $r) {
    if ($r['category'] === null) $existingOverall = $r['comment'];
    else                         $existingCatComment[$r['category']] = $r['comment'];
}

// Indicators rated this month but since retired (deactivated) must still
// render — otherwise the delete-then-insert save would silently drop their
// ratings and change the category average.
$activeKeys = [];
foreach ($indicators as $i)       $activeKeys['std:'  . $i['id']] = true;
foreach ($customIndicators as $i) $activeKeys['cust:' . $i['id']] = true;
$retiredStd = $retiredCust = [];
foreach (array_keys($existing) as $key) {
    if (isset($activeKeys[$key])) continue;
    [$kind, $id] = explode(':', $key, 2);
    if ($kind === 'std') $retiredStd[] = (int)$id; else $retiredCust[] = (int)$id;
}
if ($retiredStd) {
    $place = implode(',', array_fill(0, count($retiredStd), '?'));
    $rows  = db()->prepare("SELECT id, category, indicator_text, display_order FROM skill_indicators WHERE id IN ($place)");
    $rows->execute($retiredStd);
    foreach ($rows as $r) { $r['_retired'] = true; $indicators[] = $r; }
}
if ($retiredCust) {
    $place = implode(',', array_fill(0, count($retiredCust), '?'));
    $rows  = db()->prepare("SELECT id, category, indicator_text, display_order FROM student_custom_indicators WHERE id IN ($place) AND student_id = ?");
    $params = $retiredCust;
    $params[] = $studentId;
    $rows->execute($params);
    foreach ($rows as $r) { $r['_retired'] = true; $customIndicators[] = $r; }
}

// Group indicators by category (preserve insertion order — already sorted).
$byCategory = [];
foreach ($indicators as $i) {
    $byCategory[$i['category']]['std'][] = $i;
}
foreach ($customIndicators as $i) {
    $byCategory[$i['category']]['cust'][] = $i;
}

$rmap        = rating_config_map();
$ratingCodes = rating_codes();
$fullName    = trim($student['first_name'] . ' ' . $student['last_name']);

$pageTitle = "Assess " . $fullName;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Assess <?= e($fullName) ?></h1>
        <p class="muted">
            <?= e($student['grade']) ?> ·
            <strong><?= e(month_year_label($month)) ?></strong>
            <?php if ($isNewMonth): ?>
                <span class="pill" style="background:#e8f4ff;color:#1c5b9c">New assessment</span>
            <?php else: ?>
                <span class="pill">Editing existing</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="custom_indicators.php?student_id=<?= $studentId ?>">Custom indicators</a>
        <a class="btn btn-ghost" href="index.php">Back</a>
    </div>
</div>

<section class="card month-card no-print">
    <div class="card-head">
        <h2>Pick a month</h2>
        <p class="muted small">
            <span class="legend-dot is-used"></span> has data ·
            <span class="legend-dot is-empty"></span> empty ·
            <span class="legend-dot is-active"></span> viewing now
        </p>
    </div>
    <div class="month-grid">
        <?php
        $usedSet     = array_flip($existingMonths);
        $todayMy     = current_month_year();
        // A CONTINUOUS month range, not just the current academic year:
        // from the student's earliest assessment (or the academic-year start,
        // whichever is older) through the latest of today / academic-year end
        // / the month being viewed. Last year's months are all present — with
        // data or as fillable gaps — so history is never out of reach.
        $bounds = array_merge($allAcademic, $existingMonths, [$todayMy, $month]);
        usort($bounds, 'compare_month_year');
        $rangeStart = DateTime::createFromFormat('!M-y', $bounds[0]);
        $rangeEnd   = DateTime::createFromFormat('!M-y', end($bounds));
        $monthsOrdered = [];
        for ($d = clone $rangeStart; $d <= $rangeEnd; $d->modify('+1 month')) {
            $monthsOrdered[] = $d->format('M-y');
        }
        // Most recent month first, so any cell with data lands in the top-left.
        $monthsOrdered = array_reverse($monthsOrdered);
        foreach ($monthsOrdered as $my):
            $isUsed   = isset($usedSet[$my]);
            $isActive = $my === $month;
            $isToday  = $my === $todayMy;
            $classes  = ['month-tile'];
            $classes[] = $isUsed ? 'is-used' : 'is-empty';
            if ($isActive) $classes[] = 'is-active';
            $url = "assess.php?student_id=$studentId&month=" . urlencode($my);
        ?>
            <a class="<?= e(implode(' ', $classes)) ?>" href="<?= e($url) ?>" aria-current="<?= $isActive ? 'page' : 'false' ?>">
                <?php if ($isToday): ?><span class="month-today">today</span><?php endif; ?>
                <span class="month-name"><?= e(month_year_label($my)) ?></span>
                <span class="month-status">
                    <?= $isUsed ? '<span class="month-icon used" aria-label="Has assessment">✓ has data</span>'
                                : '<span class="month-icon empty" aria-label="No assessment yet">+ new</span>' ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($isNewMonth): ?>
<div class="flash flash-ok no-print" style="background:#e8f4ff;border-color:#bcd8f5;color:#1c5b9c">
    <strong>Starting a new assessment for <?= e(month_year_label($month)) ?>.</strong>
    Pick a rating for each indicator below, optionally add a per-category or overall comment, then <strong>Save</strong>.
</div>
<?php endif; ?>

<?php if (!$byCategory): ?>
    <div class="empty">
        <p>No indicators have been seeded for <strong><?= e($student['grade']) ?></strong> yet.
        Apply <code>sql/seeds.sql</code> or add indicators in Admin → Indicators.</p>
    </div>
<?php else: ?>
<form method="post" class="assess-form" id="assessForm"
      action="assess.php?student_id=<?= $studentId ?>&amp;month=<?= e(urlencode($month)) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="student_id" value="<?= $studentId ?>">
    <input type="hidden" name="month" value="<?= e($month) ?>">

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
                            <?php if (!empty($ind['_retired'])): ?><span class="pill small" title="This indicator was deactivated after this month was assessed; the rating is kept.">retired</span><?php endif; ?>
                        </td>
                        <td class="ind-rating">
                            <?php if ($sel !== '' && !isset($rmap[$sel])): ?>
                                <?php $legacy = rating_config_map_all()[$sel] ?? null; ?>
                                <span class="pill" title="Recorded with the retired rating code '<?= e($sel) ?>' — kept as-is."
                                      style="<?= $legacy ? 'border:1px solid ' . e($legacy['color']) . ';' : '' ?>">
                                    <?= e($sel) ?><?= $legacy ? ' · ' . e($legacy['label']) : '' ?> (legacy)
                                </span>
                                <input type="hidden" name="rating[<?= e($key) ?>]" value="<?= e($sel) ?>">
                            <?php else: ?>
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
                            <?php endif; ?>
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
<script src="/assets/js/assess.js?v=<?= e(asset_version()) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
