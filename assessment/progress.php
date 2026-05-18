<?php
/**
 * progress.php — historical view of one student.
 * Baseline + per-month per-category scores + inline SVG trend chart + comments.
 * The "Print" button uses the browser's print dialog; the @media print rules
 * in style.css hide the topbar and form chrome so the page prints cleanly.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();

$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;

$stmt = db()->prepare("
    SELECT s.id, s.first_name, s.last_name, s.grade, s.teacher_id, t.name AS teacher_name
    FROM students s
    LEFT JOIN users t ON t.id = s.teacher_id
    WHERE s.id = :id
");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}
if ($user['role'] !== 'admin' && (int)$student['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'You can only view your own students.';
    exit;
}

// Baseline (one per student).
$stmt = db()->prepare("SELECT * FROM student_baselines WHERE student_id = :s");
$stmt->execute([':s' => $studentId]);
$baseline = $stmt->fetch();

// All assessments (per-category monthly scores).
$stmt = db()->prepare("
    SELECT month_year, category, category_avg, score
    FROM assessments
    WHERE student_id = :s
    ORDER BY category, month_year
");
$stmt->execute([':s' => $studentId]);
$rows = $stmt->fetchAll();

// Pivot → [month][category] = ['score' => ..., 'avg' => ...]
$pivot     = [];
$months    = [];
$categories = [];
foreach ($rows as $r) {
    $pivot[$r['month_year']][$r['category']] = [
        'score' => (int)$r['score'],
        'avg'   => (float)$r['category_avg'],
    ];
    $months[$r['month_year']]   = true;
    $categories[$r['category']] = true;
}
$months    = array_keys($months);
usort($months, 'compare_month_year');
$categories = array_keys($categories);
sort($categories);

// Comments grouped by month → [cat] (cat = "" for overall).
$stmt = db()->prepare("
    SELECT month_year, category, comment, created_at
    FROM assessment_comments
    WHERE student_id = :s
    ORDER BY month_year DESC, category IS NULL DESC, category
");
$stmt->execute([':s' => $studentId]);
$comments = [];
foreach ($stmt as $r) {
    $cat = $r['category'] ?? '';
    $comments[$r['month_year']][$cat][] = $r['comment'];
}

// ---------- Chart maths ---------------------------------------------------
$rmap = rating_config_map();
$catColors = [];
$palette = ['#2D6BA0', '#5BA547', '#EC407A', '#F5B342', '#7E57C2', '#5DA8A2', '#E07A5F', '#A05C7B'];
foreach ($categories as $i => $cat) $catColors[$cat] = $palette[$i % count($palette)];

$fullName  = trim($student['first_name'] . ' ' . $student['last_name']);
$pageTitle = "Progress · $fullName";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <h1><?= e($fullName) ?></h1>
        <p class="muted">
            <?= e($student['grade']) ?>
            <?php if (!empty($student['teacher_name']) && $user['role'] === 'admin'): ?>
                · Teacher: <?= e($student['teacher_name']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn" href="assess.php?student_id=<?= $studentId ?>&month=<?= e(current_month_year()) ?>">Assess this month</a>
        <a class="btn btn-ghost" href="baseline.php?student_id=<?= $studentId ?>"><?= $baseline ? 'Edit baseline' : 'Add baseline' ?></a>
        <a class="btn btn-ghost" href="custom_indicators.php?student_id=<?= $studentId ?>">Custom indicators</a>
        <button type="button" class="btn btn-ghost" onclick="window.print()">Print</button>
    </div>
</div>

<section class="report-head">
    <h2>Progress report</h2>
    <p class="muted">Generated <?= e(date('j M Y')) ?> · <?= count($months) ?> month<?= count($months)===1?'':'s' ?> of data</p>
</section>

<?php if ($baseline): ?>
<section class="card">
    <h3>Entry baseline <span class="muted small">· recorded <?= e($baseline['recorded_at']) ?> by <?= e($baseline['recorded_by']) ?></span></h3>
    <dl class="bl-list">
        <?php
        $blLabels = [
            'gross_motor'   => 'Gross motor',
            'fine_motor'    => 'Fine motor',
            'literacy'      => 'Literacy',
            'numeracy'      => 'Numeracy',
            'social_skills' => 'Social skills',
            'communication' => 'Communication',
            'overall_notes' => 'Overall notes',
        ];
        foreach ($blLabels as $f => $label):
            if (!empty($baseline[$f])):
        ?>
            <dt><?= e($label) ?></dt>
            <dd><?= nl2br(e($baseline[$f])) ?></dd>
        <?php endif; endforeach; ?>
    </dl>
</section>
<?php endif; ?>

<?php if (!$months): ?>
    <div class="empty">
        <p>No assessments recorded yet. <a href="assess.php?student_id=<?= $studentId ?>&month=<?= e(current_month_year()) ?>">Start the first one →</a></p>
    </div>
<?php else: ?>

<section class="card">
    <h3>Monthly summary</h3>
    <div class="table-scroll">
    <table class="summary-table">
        <thead>
            <tr>
                <th>Month</th>
                <?php foreach ($categories as $cat): ?>
                    <th><span class="cat-dot" style="--c: <?= e($catColors[$cat]) ?>"></span><?= e($cat) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($months as $m): ?>
                <tr>
                    <th class="row-h"><?= e(month_year_label($m)) ?></th>
                    <?php foreach ($categories as $cat): ?>
                        <td><?php
                            if (!isset($pivot[$m][$cat])) { echo '—'; }
                            else {
                                $avg = $pivot[$m][$cat]['avg'];
                                printf('<span class="score">%.1f</span>', $avg);
                            }
                        ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php
// ---------- SVG line chart ----------
$W = 720; $H = 280; $padL = 38; $padR = 16; $padT = 12; $padB = 30;
$plotW = $W - $padL - $padR;
$plotH = $H - $padT - $padB;
$nM = count($months);
$xStep = $nM > 1 ? $plotW / ($nM - 1) : 0;
$yFor  = fn($v) => $padT + $plotH - (($v - 1) / 4) * $plotH;       // map 1..5 → plotH..0
$xFor  = fn($i) => $padL + ($nM > 1 ? $i * $xStep : $plotW / 2);
?>
<section class="card no-print-padding">
    <h3>Trend</h3>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" class="trend-chart" role="img" aria-label="Category averages across months">
        <!-- y gridlines for 1,2,3,4,5 -->
        <?php for ($v = 1; $v <= 5; $v++): $y = $yFor($v); ?>
            <line x1="<?= $padL ?>" y1="<?= $y ?>" x2="<?= $W - $padR ?>" y2="<?= $y ?>" stroke="#eee"/>
            <text x="<?= $padL - 6 ?>" y="<?= $y + 4 ?>" text-anchor="end" font-size="11" fill="#888"><?= $v ?></text>
        <?php endfor; ?>
        <!-- x labels -->
        <?php foreach ($months as $i => $m): $x = $xFor($i); ?>
            <text x="<?= $x ?>" y="<?= $H - 10 ?>" text-anchor="middle" font-size="11" fill="#666"><?= e(month_year_label($m)) ?></text>
        <?php endforeach; ?>
        <!-- one polyline per category -->
        <?php foreach ($categories as $cat):
            $points = [];
            foreach ($months as $i => $m) {
                if (!isset($pivot[$m][$cat])) continue;
                $points[] = $xFor($i) . ',' . $yFor($pivot[$m][$cat]['avg']);
            }
            if (!$points) continue;
        ?>
            <polyline fill="none" stroke="<?= e($catColors[$cat]) ?>" stroke-width="2"
                      points="<?= e(implode(' ', $points)) ?>"/>
            <?php foreach ($points as $p): [$x,$y] = explode(',', $p); ?>
                <circle cx="<?= e($x) ?>" cy="<?= e($y) ?>" r="3" fill="<?= e($catColors[$cat]) ?>"/>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </svg>
    <ul class="legend">
        <?php foreach ($categories as $cat): ?>
            <li><span class="cat-dot" style="--c: <?= e($catColors[$cat]) ?>"></span><?= e($cat) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($comments): ?>
<section class="card">
    <h3>Teacher notes</h3>
    <?php foreach ($comments as $m => $byCat): ?>
        <details open class="month-block">
            <summary><strong><?= e(month_year_label($m)) ?></strong></summary>
            <?php if (!empty($byCat[''])): ?>
                <div class="cmt cmt-overall">
                    <span class="cmt-cat">Overall</span>
                    <?php foreach ($byCat[''] as $c): ?>
                        <p><?= nl2br(e($c)) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($byCat as $cat => $list): if ($cat === '') continue; ?>
                <div class="cmt">
                    <span class="cmt-cat"><?= e($cat) ?></span>
                    <?php foreach ($list as $c): ?>
                        <p><?= nl2br(e($c)) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
