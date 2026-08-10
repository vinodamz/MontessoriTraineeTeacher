<?php
/**
 * surveys/index.php — parent surveys, admin only.
 *
 * Shows every questionnaire the app defines, its shareable link, and how many
 * responses have come in. The link for a survey is minted automatically the
 * first time this page is opened, so there's nothing to set up before an
 * orientation — open the page, copy the link, share it.
 *
 *   POST op=close / op=open  → stop / resume accepting responses
 *
 * There is no op that changes a survey's link. It is shared outside the app
 * — CuePilot, WhatsApp, a printed notice — and every copy of it has to keep
 * working for as long as the survey is open. See the comment above
 * survey_set_active() in includes/surveys.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['op'] ?? '');
    $sid = (int)($_POST['survey_id'] ?? 0);
    try {
        if ($sid > 0 && $op === 'close') {
            survey_set_active($sid, false);
            flash_set('ok', 'Survey closed — the link no longer accepts responses.');
        } elseif ($sid > 0 && $op === 'open') {
            survey_set_active($sid, true);
            flash_set('ok', 'Survey reopened.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Could not update the survey: ' . $e->getMessage());
    }
    redirect('/surveys/index.php');
}

// Each known spec (PHP + MCP/DB) gets its live row, created on first sight.
$rows = [];
foreach (survey_all_specs() as $key => $entry) {
    $spec = $entry['spec'];
    $survey = survey_ensure($key, (int)$user['id']);
    $rows[] = [
        'spec'   => $spec,
        'source' => $entry['source'],
        'survey' => $survey,
        'count'  => $survey ? survey_response_count((int)$survey['id']) : 0,
    ];
}

$pageTitle  = 'Parent surveys';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Parent surveys</h1>
        <p class="muted">
            Share a link, collect responses, read them here. Responses are visible to
            admins only. New surveys can also be created via the MCP API (JSON).
        </p>
    </div>
</div>

<?php foreach ($rows as $r):
    $spec   = $r['spec'];
    $survey = $r['survey'];
    ?>
    <div class="card">
        <h3 style="margin-bottom:.15rem;"><?= e((string)$spec['title']) ?></h3>
        <p class="muted" style="margin-top:0;">
            <?= e((string)($spec['subtitle'] ?? '')) ?>
            · <?= count(survey_questions($spec)) ?> questions
            · <?= count(survey_columns($spec)) ?> spreadsheet columns
            · <strong><?= (int)$r['count'] ?></strong> response<?= (int)$r['count'] === 1 ? '' : 's' ?>
            · <span class="pill"><?= $r['source'] === 'mcp' ? 'MCP / JSON' : 'Built-in' ?></span>
        </p>

        <?php if (!$survey): ?>
            <p class="muted">
                The survey link can't be created yet — the database tables aren't in place.
                They arrive with migration 054 on the next deploy.
            </p>
        <?php else: ?>
            <?php $url = survey_url((string)$survey['token']); ?>

            <?php if ((int)$survey['active'] === 1): ?>
                <label class="muted small" for="url-<?= (int)$survey['id'] ?>">Share this link with parents</label>
                <div class="sv-linkrow">
                    <input id="url-<?= (int)$survey['id'] ?>" class="sv-linkbox" type="text" readonly
                           value="<?= e($url) ?>" onclick="this.select();">
                    <button class="btn" type="button"
                            onclick="navigator.clipboard.writeText(<?= e(json_encode($url)) ?>).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1600);});">Copy</button>
                    <a class="btn btn-ghost" href="<?= e($url) ?>" target="_blank" rel="noopener">Preview</a>
                </div>
                <p class="muted small">
                    Anyone with this link can submit — that's the point, the same as a Google Form.
                    It doesn't identify the parent; they type their own name and their child's.
                </p>
            <?php else: ?>
                <p><span class="pill">Closed</span>
                   <span class="muted">The link is no longer accepting responses. Responses already
                   collected are kept.</span></p>
            <?php endif; ?>

            <div class="actionbar" style="margin-top:.9rem;">
                <a class="btn btn-primary" href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>">
                    View responses<?= (int)$r['count'] ? ' (' . (int)$r['count'] . ')' : '' ?>
                </a>
                <a class="btn" href="/surveys/summary.php?id=<?= (int)$survey['id'] ?>">Summary</a>
                <a class="btn btn-ghost" href="/surveys/responses.php?id=<?= (int)$survey['id'] ?>&amp;csv=1">Download CSV</a>

                <?php if ((int)$survey['active'] === 1): ?>
                    <form method="post" class="inline-form"
                          onsubmit="return confirm('Close this survey? The link stops accepting responses. You can reopen it later.');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="close">
                        <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
                        <button class="btn btn-ghost" type="submit">Close survey</button>
                    </form>
                <?php else: ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="open">
                        <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
                        <button class="btn" type="submit">Reopen</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h3>How this works</h3>
    <ul class="muted small" style="margin:0; padding-left:1.1rem;">
        <li>The link is public by design — no login, so a parent can fill it in on their phone
            in the hall. The random token in the URL is what makes it unguessable.</li>
        <li>A survey's link never changes once it exists. It gets shared outside the app, and a
            new token would break every copy already sent. <strong>Close</strong> when the window
            is over: the link stops accepting responses but everything collected stays, and
            <strong>Reopen</strong> undoes that without needing a new link.</li>
        <li>The <strong>Class</strong> options come from <a href="/grades.php">Grades</a>, so a
            grade added there is offered to parents straight away.</li>
        <li>Only the parent's name, the child's name and the class are required — the rest is
            optional, because a long form with compulsory questions gets abandoned and a
            half-finished form saves nothing.</li>
    </ul>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
