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
require_once __DIR__ . '/../includes/assessment.php';

$user = require_module('montessori');

// ---------- Resolve student + month ----------------------------------------
$studentId    = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
$monthParam   = normalize_month_year(trim((string)($_REQUEST['month'] ?? ''))) ?? '';
$monthChosen  = $monthParam !== '';

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
    $ratings     = $_POST['rating'] ?? [];          // key = "std:NN" or "cust:NN"
    $catComments = $_POST['cat_comment'] ?? [];     // key = category name
    $overall     = trim($_POST['overall_comment'] ?? '');

    if (!is_array($ratings))     $ratings = [];
    if (!is_array($catComments)) $catComments = [];

    // A blank is not a rating code. The indicator still belongs on this
    // assessment, with no developmental level, so the report can list it.
    // A code the scheme does not know aborts the save — it must not be
    // written as '' and it must not be dropped while the rest of the month
    // is replaced.
    $split = assessment_ratings_for_save($ratings, $rmap);
    if ($split['invalid'] !== []) {
        flash_set('error', 'A rating on this form is not one of the configured codes, so nothing was saved.');
        redirect("assess.php?student_id=$studentId&month=" . urlencode($month));
    }
    $ratings = $split['ok'];
    $unrated = $split['blank'];

    // Group every indicator on the form by category, rated or not.
    $stdIds  = [];
    $custIds = [];
    foreach (array_merge(array_keys($ratings), $unrated) as $key) {
        [$kind, $idStr] = explode(':', $key, 2);
        if ($kind === 'std')  $stdIds[]  = (int)$idStr;
        else                  $custIds[] = (int)$idStr;
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
    // The live server is non-strict, which is how an unknown ENUM value used
    // to be stored as ''. Strict mode makes that insert fail; the read-back
    // inside assessment_store_rating covers a server that still won't.
    $previousMode = null;
    try {
        $previousMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES,STRICT_ALL_TABLES')");
    } catch (Throwable $e) {
        $previousMode = null;
    }
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
        $catTotals = []; // [cat => [sum, count]]
        foreach ($ratings as $key => $val) {
            if (!isset($indicatorCat[$key]))  continue;
            if (!isset($rmap[$val]))          continue;
            [$kind, $idStr] = explode(':', $key, 2);
            $isCustom = $kind === 'cust' ? 1 : 0;
            assessment_store_rating(
                $pdo,
                $studentId,
                $assessingTeacherId,
                $month,
                (int)$idStr,
                $val,
                $isCustom
            );
            $cat = $indicatorCat[$key];
            $catTotals[$cat] ??= [0, 0];
            $catTotals[$cat][0] += (int)$rmap[$val]['numeric_value'];
            $catTotals[$cat][1] += 1;
        }

        // Unselected indicators are kept, with no level. They do not move
        // the category average.
        foreach ($unrated as $key) {
            if (!isset($indicatorCat[$key])) continue;
            [$kind, $idStr] = explode(':', $key, 2);
            assessment_store_unrated(
                $pdo,
                $studentId,
                $assessingTeacherId,
                $month,
                (int)$idStr,
                $kind === 'cust' ? 1 : 0
            );
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
        assessment_restore_sql_mode($pdo, $previousMode);
        flash_set('ok', 'Assessment saved.');
        redirect('progress.php?student_id=' . $studentId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        assessment_restore_sql_mode($pdo, $previousMode);
        flash_set('error', assessment_save_failure_message($e));
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

// Comments already saved for this month. A new month's empty boxes are
// filled from the previous month further down, after we know which
// categories the form actually shows.
$currentComments = assessment_load_month_comments(db(), $studentId, $month);

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

// Carry forward last month's ratings and comments.
// Most of a child's ratings don't move month to month, and the same is true
// of the notes. When this month has nothing recorded yet, start from the
// most recent earlier month. Carried values are marked until the teacher
// touches them, and nothing reaches the database until Save.
// ?blank=1 opts out and starts from an empty grid.
$prefill = [
    'ratings' => [],
    'from' => null,
    'overall' => $currentComments['overall'],
    'categories' => $currentComments['categories'],
    'carried_overall' => false,
    'carried_categories' => [],
];
try {
    $prefill = assessment_prefill_from_previous(
        db(),
        $studentId,
        $month,
        !empty($_GET['blank']),
        $existing !== [],
        $currentComments['overall'],
        $currentComments['categories'],
        array_keys($byCategory),
        rating_config_map(),
        $activeKeys
    );
} catch (Throwable $e) {
    // A carry-forward problem must not block the form. The month can still
    // be entered from scratch.
}

$rmap        = rating_config_map();
$ratingCodes = rating_codes();
$fullName    = trim($student['first_name'] . ' ' . $student['last_name']);

$pageTitle = "Assess " . $fullName;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Monthly assessment</h1>
        <p class="muted">
            <?= e($fullName) ?> · <?= e($student['grade']) ?> ·
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

<?php
// Offer the June-start academic year plus saved historical months.
// Do not generate empty April/May gaps just because March has saved data.
$usedSet = array_flip($existingMonths);
$todayMy = current_month_year();
$monthsOrdered = array_values(array_unique(array_merge($allAcademic, $existingMonths, [$todayMy, $month])));
usort($monthsOrdered, fn($a, $b) => compare_month_year($b, $a));
?>
<section class="card assessment-period no-print" aria-labelledby="assessment-period-title">
    <div class="assessment-period-heading">
        <div><h2 id="assessment-period-title">Assessment month</h2><p>Choose a month to start or review its assessment.</p></div>
        <span class="assessment-period-status"><?= $isNewMonth ? 'Not saved yet' : 'Saved assessment' ?></span>
    </div>
    <form method="get" action="assess.php" class="assessment-period-form">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <div class="assessment-period-field">
            <label for="assessment-month">Month and year</label>
            <select id="assessment-month" name="month">
                <?php foreach ($monthsOrdered as $my): ?>
                <option value="<?= e($my) ?>" <?= $my === $month ? 'selected' : '' ?>><?= e(month_year_label($my)) ?><?= $my === $todayMy ? ' · This month' : '' ?><?= isset($usedSet[$my]) ? ' · Saved' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">View month</button>
        <?php if ($month !== $todayMy): ?>
        <a class="btn btn-ghost" href="assess.php?student_id=<?= $studentId ?>&amp;month=<?= e(urlencode($todayMy)) ?>">This month →</a>
        <?php endif; ?>
    </form>
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

    <?php if ($prefill['from'] !== null): ?>
        <?php
            $carriedParts = [];
            if ($prefill['ratings'] !== []) $carriedParts[] = 'ratings';
            if ($prefill['carried_overall'] || $prefill['carried_categories'] !== []) $carriedParts[] = 'comments';
            $carriedWhat = implode(' and ', $carriedParts);
        ?>
        <div class="flash flash-info carry-note">
            Started from <strong><?= e(month_year_label($prefill['from'])) ?></strong>'s <?= e($carriedWhat) ?> —
            change what has moved and leave the rest. Anything still marked
            <span class="carry-tag">carried</span> hasn't been reviewed yet.
            Nothing is saved until you press <strong>Save assessment</strong>.
            <a href="assess.php?student_id=<?= $studentId ?>&amp;month=<?= e(urlencode($month)) ?>&amp;blank=1">Start from blank instead</a>
        </div>
    <?php endif; ?>

    <?php foreach ($byCategory as $cat => $groups): ?>
        <section class="cat-block">
            <h2><?= e($cat) ?></h2>
            <div class="bulk-bar" role="group" aria-label="Set every skill in <?= e($cat) ?>">
                <span class="bulk-label">Set all</span>
                <?php foreach ($ratingCodes as $code): ?>
                    <button type="button" class="bulk-set" data-code="<?= e($code) ?>"
                            style="--ring: <?= e($rmap[$code]['color']) ?>"
                            title="Set every skill in <?= e($cat) ?> to <?= e($rmap[$code]['label']) ?>"><?= e($code) ?></button>
                <?php endforeach; ?>
                <button type="button" class="bulk-clear" title="Clear every rating in <?= e($cat) ?>">Clear</button>
            </div>
            <table class="rating-table">
                <tbody>
                <?php
                    $allIndicators = array_merge($groups['std'] ?? [], array_map(function($r) {
                        $r['_kind'] = 'cust'; return $r;
                    }, $groups['cust'] ?? []));
                    foreach ($allIndicators as $ind):
                        $kind = $ind['_kind'] ?? 'std';
                        $key  = "$kind:" . $ind['id'];
                        // Real data for this month wins; otherwise fall back to
                        // whatever was carried forward (flagged, not confirmed).
                        $sel        = $existing[$key] ?? '';
                        $isCarried  = false;
                        if ($sel === '' && isset($prefill['ratings'][$key])) {
                            $sel       = $prefill['ratings'][$key];
                            $isCarried = true;
                        }
                ?>
                    <tr<?= $isCarried ? ' class="ind-carried"' : '' ?>>
                        <td class="ind-text">
                            <?= e($ind['indicator_text']) ?>
                            <?php if ($isCarried): ?><span class="carry-tag">carried</span><?php endif; ?>
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
                            <?php // An unchecked radio is not submitted. The empty value keeps the indicator on the assessment. ?>
                            <input type="hidden" name="rating[<?= e($key) ?>]" value="">
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
            <?php $catCarried = !empty($prefill['carried_categories'][$cat]); ?>
            <label class="cat-comment<?= $catCarried ? ' is-carried' : '' ?>">
                <span>Comment on <?= e($cat) ?> <span class="muted small">(optional)</span><?php if ($catCarried): ?><span class="carry-tag">carried</span><?php endif; ?></span>
                <textarea name="cat_comment[<?= e($cat) ?>]" rows="2"><?= e($prefill['categories'][$cat] ?? '') ?></textarea>
            </label>
        </section>
    <?php endforeach; ?>

    <section class="cat-block overall-comment<?= !empty($prefill['carried_overall']) ? ' is-carried' : '' ?>">
        <h2>Overall comment<?php if (!empty($prefill['carried_overall'])): ?> <span class="carry-tag">carried</span><?php endif; ?></h2>
        <textarea name="overall_comment" rows="3" placeholder="A short note for the parents and the file."><?= e($prefill['overall']) ?></textarea>
    </section>

    <div class="form-actions">
        <a class="btn btn-ghost" href="index.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Save assessment</button>
    </div>
</form>
<script src="/assets/js/assess.js?v=<?= e(asset_version()) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
