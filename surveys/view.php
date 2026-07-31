<?php
/**
 * surveys/view.php — one response, read in full. Admin only.
 *
 * The grid shortens long answers so fifty questions fit on a screen; this is
 * where you actually read what a parent wrote, laid out in the same sections
 * and order as the form they filled in.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

$user = require_admin();

$id  = (int)($_GET['id'] ?? 0);
$row = survey_response($id);

$survey = null;
if ($row) {
    try {
        $s = db()->prepare("SELECT * FROM surveys WHERE id = :id");
        $s->execute([':id' => (int)$row['survey_id']]);
        $survey = $s->fetch() ?: null;
    } catch (Throwable $e) {
        $survey = null;
    }
}
$spec = $survey ? survey_spec((string)$survey['spec_key']) : null;

if (!$row || !$spec) {
    $pageTitle = 'Response not found';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><h3>Response not found</h3><p class="muted">'
       . 'That response no longer exists. <a href="/surveys/index.php">Back to surveys</a>.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$a = $row['_a'];

$pageTitle = 'Response — ' . (string)$row['parent_name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e((string)$row['parent_name']) ?: 'Response' ?></h1>
        <p class="muted">
            <a href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>">← All responses</a>
            <?php if ((string)$row['child_name'] !== ''): ?>
                · Child: <strong><?= e((string)$row['child_name']) ?></strong>
            <?php endif; ?>
            <?php if ((string)$row['class'] !== ''): ?> · <?= e((string)$row['class']) ?><?php endif; ?>
            · <?= e(date('j M Y, g:i a', strtotime((string)$row['submitted_at']))) ?>
        </p>
    </div>
</div>

<?php foreach ($spec['sections'] as $sec): ?>
    <?php
    // Skip a section entirely when this parent answered nothing in it — an
    // all-blank card is noise when you're reading through submissions.
    $any = false;
    foreach ($sec['questions'] as $q) {
        $v = $a[$q['key']] ?? null;
        if ($v !== null && $v !== '' && $v !== []) { $any = true; break; }
    }
    if (!$any) continue;
    ?>
    <div class="card">
        <h3><?= e((string)$sec['title']) ?></h3>
        <dl class="dl-grid">
            <?php foreach ($sec['questions'] as $q):
                $key  = (string)$q['key'];
                $type = (string)($q['type'] ?? 'text');

                if ($type === 'matrix'):
                    $answered = (array)($a[$key] ?? []);
                    if (!$answered) continue;
                    $scale = (array)($q['scale'] ?? []);
                    foreach ((array)($q['rows'] ?? []) as $rk => $rlabel):
                        $v = (string)($answered[$rk] ?? '');
                        if ($v === '') continue; ?>
                        <dt><?= e((string)$rlabel) ?></dt>
                        <dd><?= e((string)($scale[$v] ?? $v)) ?></dd>
                    <?php endforeach;
                    continue;
                endif;

                $col  = ['key' => $key, 'type' => $type, 'q' => $q];
                $text = survey_cell($col, $a);
                $other = (string)($a[$key . '_other'] ?? '');
                if ($text === '' && $other === '') continue; ?>

                <dt><?= e((string)$q['label']) ?></dt>
                <dd style="white-space:pre-wrap;">
                    <?= $text !== '' ? e($text) : '' ?>
                    <?php if ($other !== ''): ?>
                        <?= $text !== '' ? '<br>' : '' ?><em>Other:</em> <?= e($other) ?>
                    <?php endif; ?>
                </dd>
            <?php endforeach; ?>
        </dl>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
