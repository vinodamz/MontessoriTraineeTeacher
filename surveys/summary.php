<?php
/**
 * surveys/summary.php — how everyone answered, question by question. Admin only.
 *
 * The grid is for reading individual families; this is for seeing the shape of
 * the whole room — which sessions landed, where confidence is thin, how many
 * are undecided about staying on.
 *
 * Only the choice questions are counted. Free-text answers are listed instead
 * of tallied, because counting them would mean inventing categories nobody
 * agreed to; the words are the finding.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

$user = require_admin();

$sid   = (int)($_GET['id'] ?? 0);
$class = trim((string)($_GET['class'] ?? ''));

$survey = null;
try {
    $s = db()->prepare("SELECT * FROM surveys WHERE id = :id");
    $s->execute([':id' => $sid]);
    $survey = $s->fetch() ?: null;
} catch (Throwable $e) {
    $survey = null;
}
$spec = $survey ? survey_spec((string)$survey['spec_key']) : null;

if (!$spec) {
    $pageTitle = 'Survey not found';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><h3>Survey not found</h3><p class="muted">'
       . '<a href="/surveys/index.php">Back to surveys</a>.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$all = survey_responses((int)$survey['id']);

// Class filter, applied in PHP so the tallies and the row count can't disagree.
$classes = [];
foreach ($all as $r) {
    $c = (string)$r['class'];
    if ($c !== '') $classes[$c] = ($classes[$c] ?? 0) + 1;
}
ksort($classes);
$rows = $class === '' ? $all : array_values(array_filter($all, fn($r) => (string)$r['class'] === $class));

$tally = survey_tally($spec, $rows);

/** One tallied question: a labelled bar per option. */
function survey_bars(array $t): void
{
    $max = 0;
    foreach ($t['options'] as $n) $max = max($max, (int)$n);
    ?>
    <div class="sv-tally">
        <p class="sv-tally-q"><?= e((string)$t['label']) ?>
            <span class="muted small">· <?= (int)$t['answered'] ?> answered<?php
                if (!empty($t['multi'])) echo ', multiple choices allowed'; ?></span></p>
        <?php foreach ($t['options'] as $label => $n):
            $n   = (int)$n;
            $pct = $t['answered'] > 0 ? round($n * 100 / $t['answered']) : 0;
            $w   = $max > 0 ? round($n * 100 / $max) : 0; ?>
            <div class="sv-bar-row">
                <span class="sv-bar-label"><?= e((string)$label) ?></span>
                <span class="sv-bar-track"><span class="sv-bar-fill" style="width:<?= $w ?>%;"></span></span>
                <span class="sv-bar-n"><?= $n ?><?php if ($n > 0): ?> <span class="muted">· <?= $pct ?>%</span><?php endif; ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

$pageTitle  = 'Survey summary';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Summary</h1>
        <p class="muted">
            <a href="/surveys/index.php">← Surveys</a>
            · <?= e((string)$spec['title']) ?>
            · <strong><?= count($rows) ?></strong> of <?= count($all) ?> response<?= count($all) === 1 ? '' : 's' ?>
            <?php if ($class !== ''): ?> · <?= e($class) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>">All responses</a>
    </div>
</div>

<?php if ($classes): ?>
    <div class="card">
        <h3>By class</h3>
        <p style="margin:0;">
            <a class="pill <?= $class === '' ? 'pill-status-enrolled' : '' ?>"
               href="/surveys/summary.php?id=<?= (int)$survey['id'] ?>">All · <?= count($all) ?></a>
            <?php foreach ($classes as $c => $n): ?>
                <a class="pill <?= $class === (string)$c ? 'pill-status-enrolled' : '' ?>"
                   href="/surveys/summary.php?id=<?= (int)$survey['id'] ?>&amp;class=<?= urlencode((string)$c) ?>">
                    <?= e((string)$c) ?> · <?= (int)$n ?>
                </a>
            <?php endforeach; ?>
        </p>
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="card">
        <h3>Nothing to summarise yet</h3>
        <p class="muted">Responses appear here as parents submit the form.</p>
    </div>
<?php else: ?>

    <?php foreach ($spec['sections'] as $sec):
        // Which tallies belong to this section, in form order.
        $mine = [];
        foreach ($sec['questions'] as $q) {
            foreach ($tally as $k => $t) {
                if ($k === $q['key'] || strpos($k, $q['key'] . '.') === 0) $mine[$k] = $t;
            }
        }
        // Free-text questions in this section, with the answers people gave.
        $texts = [];
        foreach ($sec['questions'] as $q) {
            if (($q['type'] ?? '') !== 'textarea') continue;
            $vals = [];
            foreach ($rows as $r) {
                $v = trim((string)($r['_a'][$q['key']] ?? ''));
                if ($v !== '') $vals[] = ['who' => (string)$r['parent_name'], 'id' => (int)$r['id'], 'text' => $v];
            }
            if ($vals) $texts[] = ['q' => $q, 'vals' => $vals];
        }
        if (!$mine && !$texts) continue;
        ?>
        <div class="card">
            <h3><?= e((string)$sec['title']) ?></h3>

            <?php foreach ($mine as $t): ?>
                <?php // "Other" free text on a checkbox question isn't a tallied option;
                      // it's shown with the written answers below. ?>
                <?php survey_bars($t); ?>
            <?php endforeach; ?>

            <?php foreach ($texts as $tx): ?>
                <div class="sv-tally">
                    <p class="sv-tally-q"><?= e((string)$tx['q']['label']) ?>
                        <span class="muted small">· <?= count($tx['vals']) ?> answered</span></p>
                    <ul class="sv-quotes">
                        <?php foreach ($tx['vals'] as $v): ?>
                            <li>
                                <?= e($v['text']) ?>
                                <a class="muted small" href="/surveys/view.php?id=<?= (int)$v['id'] ?>">
                                    — <?= e($v['who'] !== '' ? $v['who'] : 'response ' . $v['id']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
