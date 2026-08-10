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

/*
 * Withdrawing a response.
 *
 * The field trip form takes one consent per child, which without this would be
 * a trap: a mistyped name, or a parent who changes their mind, and the form
 * says "we already have this" with nobody able to clear it. Deleting rather
 * than editing is deliberate — the answers are the parent's own words, and an
 * admin quietly rewriting a consent record is not the same thing as a parent
 * filling the form in again.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string)($_POST['op'] ?? '') === 'delete') {
        $gone   = survey_response($id);
        $backTo = $gone ? '/surveys/responses.php?id=' . (int)$gone['survey_id'] : '/surveys/index.php';
        if ($gone && survey_response_delete($id)) {
            flash_set('ok', 'Response withdrawn' . ((string)$gone['child_name'] !== ''
                ? ' for ' . (string)$gone['child_name'] : '')
                . '. The form can be filled in again — tell the family they may resubmit.');
        } else {
            flash_set('error', 'That response no longer exists.');
        }
        redirect($backTo);
    }
    redirect('/surveys/view.php?id=' . $id);
}

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

<?php if (!empty($spec['one_per_child'])): ?>
<div class="card">
    <h3 style="margin-top:0;">Withdraw this response</h3>
    <p class="muted small" style="margin-top:0;">
        This form takes one response per child, so
        <strong><?= e((string)$row['child_name'] ?: 'this child') ?></strong> cannot be submitted
        again while this one exists. Withdraw it if the name was mistyped, if it was filled in for
        the wrong child, or if the family want to change their answers — then tell them they can
        fill the form in again using the same link.
    </p>
    <p class="muted small">
        The answers are deleted, not archived. If you only need to correct a detail, note it down
        first.
    </p>
    <form method="post" onsubmit="return confirm('Withdraw this response? The answers are deleted and the family will need to fill the form in again.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <button class="btn btn-ghost" type="submit">Withdraw response</button>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
