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
