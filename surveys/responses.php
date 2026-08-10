<?php
/**
 * surveys/responses.php — the responses grid, admin only.
 *
 * One row per parent, one column per question — the spreadsheet the office
 * actually wants, with the same columns as the CSV so what's on screen and
 * what lands in Excel are the same thing.
 *
 * Matrix questions are split into one column per statement, because a cell
 * has to hold a single value for a spreadsheet to be sortable or filterable.
 *
 *   GET ?id=N          → the grid
 *   GET ?id=N&q=…      → filter by parent, child, class or any answer text
 *   GET ?id=N&csv=1    → the same rows and columns as a .csv download
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

$user = require_admin();

$sid = (int)($_GET['id'] ?? 0);
$q   = trim((string)($_GET['q'] ?? ''));

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
       . 'That survey doesn\'t exist, or its questions are no longer defined in the app. '
       . '<a href="/surveys/index.php">Back to surveys</a>.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$cols = survey_columns($spec);
$rows = survey_responses((int)$survey['id'], $q);

// ---- CSV -----------------------------------------------------------------
// Written with a UTF-8 BOM so Excel on Windows opens names and the ★ ratings
// correctly instead of turning them into mojibake.
if (!empty($_GET['csv'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $file = 'survey-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$survey['spec_key'])
          . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $head = ['#', 'Submitted'];
    foreach ($cols as $c) $head[] = $c['label'];
    fputcsv($out, $head);

    $n = count($rows);
    foreach ($rows as $r) {
        $line = [$n--, date('Y-m-d H:i', strtotime((string)$r['submitted_at']))];
        foreach ($cols as $c) $line[] = survey_cell($c, $r['_a']);
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/** Long free text is truncated in the grid; the full text is on the row's page. */
function survey_clip(string $s, int $max = 90): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
    return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
}

$pageTitle  = 'Survey responses';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Responses</h1>
        <p class="muted">
            <a href="/surveys/index.php">← Surveys</a>
            · <?= e((string)$spec['title']) ?>
            · <strong><?= count($rows) ?></strong> response<?= count($rows) === 1 ? '' : 's' ?><?php
                if ($q !== '') echo ' matching “' . e($q) . '”'; ?>
        </p>
    </div>
    <div class="actionbar">
        <form method="get" class="inline-form">
            <input type="hidden" name="id" value="<?= (int)$survey['id'] ?>">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Parent, child, class or text">
            <button class="btn" type="submit">Search</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-ghost" href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>">Clear</a>
            <?php endif; ?>
        </form>
        <a class="btn" href="/surveys/summary.php?id=<?= (int)$survey['id'] ?>">Summary</a>
        <a class="btn btn-primary"
           href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>&amp;csv=1<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">
            Download CSV
        </a>
    </div>
</div>

<?php if (!empty($spec['roster'])): ?>
<?php
/*
 * Who has not answered.
 *
 * The grid below lists the forms we have, which cannot answer the question the
 * office actually asks the day before a trip. This starts from the class
 * roster instead, so a missing consent is a name on a page rather than
 * something to work out by hand.
 */
$roster = survey_roster_status((int)$survey['id'], $spec);
$total  = count($roster['consented']) + count($roster['declined']) + count($roster['waiting']);
?>
<div class="card" id="roster">
    <h3 style="margin-top:0;">Consent by child
        <span class="muted small" style="font-weight:400;">
            — <?= implode(' · ', array_map('e', $roster['classes'])) ?></span>
    </h3>

    <?php if ($total === 0): ?>
        <p class="muted">No enrolled children in those classes yet, so there is nothing to
           compare against. Check the classes on <a href="/grades.php">/grades.php</a> and that
           children are marked enrolled.</p>
    <?php else: ?>
    <div class="actionbar" style="margin:0 0 1rem;">
        <span class="pill pill-ok"><?= count($roster['consented']) ?> consented</span>
        <?php if ($roster['declined']): ?>
            <span class="pill"><?= count($roster['declined']) ?> not attending</span>
        <?php endif; ?>
        <span class="pill <?= $roster['waiting'] ? 'pill-warn' : '' ?>">
            <?= count($roster['waiting']) ?> still to reply</span>
        <?php if ($roster['unmatched']): ?>
            <span class="pill pill-warn"><?= count($roster['unmatched']) ?> needs checking</span>
        <?php endif; ?>
    </div>

    <div style="overflow-x:auto;">
    <table class="admin-table">
        <thead><tr>
            <th>Child</th><th>Class</th><th>Consent</th><th>Photographs</th>
            <th>Volunteer</th><th></th>
        </tr></thead>
        <tbody>
        <?php
        // Waiting first: this table exists to be acted on, and the names to
        // chase are the ones worth putting at the top.
        $ordered = array_merge(
            array_map(fn($c) => [$c, 'waiting'],   $roster['waiting']),
            array_map(fn($c) => [$c, 'consented'], $roster['consented']),
            array_map(fn($c) => [$c, 'declined'],  $roster['declined'])
        );
        $mediaLabels = [];
        foreach (survey_questions($spec) as $q0) {
            if (($q0['key'] ?? '') === 'media_consent') $mediaLabels = survey_options($q0);
        }
        ?>
        <?php foreach ($ordered as [$child, $state]): ?>
            <tr>
                <td><a href="/students/view.php?id=<?= (int)$child['id'] ?>"><?= e((string)$child['full_name']) ?></a></td>
                <td class="small"><?= e((string)$child['grade']) ?></td>
                <td>
                    <?php if ($state === 'consented'): ?>
                        <span class="pill pill-ok">Yes</span>
                    <?php elseif ($state === 'declined'): ?>
                        <span class="pill">Not attending</span>
                    <?php else: ?>
                        <span class="pill pill-warn">No reply</span>
                    <?php endif; ?>
                </td>
                <td class="small">
                    <?php $mc = (string)($child['answers']['media_consent'] ?? ''); ?>
                    <?= $mc !== '' ? e(mb_substr((string)($mediaLabels[$mc] ?? $mc), 0, 40)) : '<span class="muted">—</span>' ?>
                </td>
                <td class="small">
                    <?= in_array('yes', (array)($child['answers']['volunteer'] ?? []), true)
                        ? 'Offered' : '<span class="muted">—</span>' ?>
                </td>
                <td class="small">
                    <?php if (!empty($child['response'])): ?>
                        <a href="/surveys/view.php?id=<?= (int)$child['response']['id'] ?>">Open</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($roster['unmatched']): ?>
        <div class="card" style="background:#fff8e6;border:1px solid #f0dca8;margin-top:1rem;">
            <p class="small" style="margin:0 0 .4rem;"><strong>These forms did not match a child on the roster.</strong>
               Usually a spelling difference, or a name we hold differently. Until they match, the
               child above still shows as “No reply” — so check these before chasing anybody.</p>
            <ul class="small" style="margin:.3rem 0 0;padding-left:1.1rem;">
            <?php foreach ($roster['unmatched'] as $u): ?>
                <li><strong><?= e((string)$u['child_name']) ?></strong>
                    <?= (string)$u['class'] !== '' ? '(' . e((string)$u['class']) . ')' : '' ?>
                    — <?= e((string)$u['parent_name']) ?>
                    · <a href="/surveys/view.php?id=<?= (int)$u['id'] ?>">open</a></li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="card">
        <h3>No responses yet</h3>
        <p class="muted">
            <?php if ($q !== ''): ?>
                Nothing matches “<?= e($q) ?>”. <a href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>">Show all</a>.
            <?php else: ?>
                Responses appear here as parents submit the form. The link to share is on the
                <a href="/surveys/index.php">surveys page</a>.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <?php // The first three columns are pinned so a parent's name stays visible
              // while you scroll right through fifty answers. ?>
        <div class="sv-grid-wrap">
            <table class="admin-table sv-grid">
                <thead>
                    <tr>
                        <th class="sv-stick sv-stick-1">#</th>
                        <th class="sv-stick sv-stick-2">Parent</th>
                        <th class="sv-stick sv-stick-3">Child</th>
                        <th>Class</th>
                        <th>Submitted</th>
                        <?php foreach ($cols as $c): ?>
                            <?php // parent/child/class already have pinned columns above. ?>
                            <?php if (in_array($c['key'], ['parent_name', 'child_name', 'class'], true)) continue; ?>
                            <th title="<?= e($c['label']) ?>"><?= e(survey_clip($c['label'], 46)) ?></th>
                        <?php endforeach; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = count($rows); foreach ($rows as $r): ?>
                        <tr>
                            <td class="sv-stick sv-stick-1 muted"><?= $n-- ?></td>
                            <td class="sv-stick sv-stick-2">
                                <a href="/surveys/view.php?id=<?= (int)$r['id'] ?>"><?= e((string)$r['parent_name']) ?: '<span class="muted">—</span>' ?></a>
                            </td>
                            <td class="sv-stick sv-stick-3"><?= e((string)$r['child_name']) ?></td>
                            <td><?= e((string)$r['class']) ?></td>
                            <td class="muted small" style="white-space:nowrap;">
                                <?= e(date('j M, g:i a', strtotime((string)$r['submitted_at']))) ?>
                            </td>
                            <?php foreach ($cols as $c): ?>
                                <?php if (in_array($c['key'], ['parent_name', 'child_name', 'class'], true)) continue; ?>
                                <?php $cell = survey_cell($c, $r['_a']); ?>
                                <td <?= $cell !== '' ? 'title="' . e($cell) . '"' : '' ?>>
                                    <?= $cell !== '' ? e(survey_clip($cell)) : '<span class="muted">—</span>' ?>
                                </td>
                            <?php endforeach; ?>
                            <td><a class="btn btn-ghost" href="/surveys/view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">
            Scroll sideways for the rest of the questions — the first three columns stay put.
            Long answers are shortened here; open a row to read one in full, or download the
            CSV for the complete text.
        </p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
