<?php
/**
 * survey.php — PUBLIC, NO LOGIN.
 *
 * The parent-facing survey form. One link, shared with every family:
 *
 *     https://<school>/survey.php?t=<64-char token>
 *
 * The token is the sole credential and identifies *which survey*, not which
 * parent — parents type their own name and their child's on the form, exactly
 * as they would on a Google Form. An unknown, retired or closed token lands on
 * a generic "not accepting responses" page that says nothing about whether a
 * token ever existed.
 *
 *   GET  ?t=…   → render the form
 *   POST        → validate, store, show the thank-you page
 *
 * Answers are whitelisted against the spec in includes/surveys.php, so only
 * options the form actually offered can be stored.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';        // db + app_config; no require_login
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/surveys.php';

// auth.php defines the session bootstrap but doesn't run it — on a logged-in
// page require_login() does that. Nothing calls it here, so start it now:
// without it $_SESSION is empty when the POST branch checks the CSRF token
// (every parent would be told to submit twice) and submitted_at would be
// stamped in the server's timezone rather than the school's.
start_session_once();

$token   = (string)($_REQUEST['t'] ?? $_REQUEST['token'] ?? '');
$survey  = survey_by_token($token);
$spec    = $survey ? survey_spec((string)$survey['spec_key']) : null;
$appName = function_exists('app_name') ? app_name() : 'Little Graduates';

$done    = false;   // submitted successfully → thank-you page
$errors  = [];
$posted  = [];      // what the parent typed, for re-rendering after an error
$topErr  = '';

if ($spec && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;

    // A hidden field no human ever sees. Bots fill every input they find, so
    // anything in here is not a parent — accepted silently so the bot doesn't
    // learn to try again, but nothing is stored.
    $trap = trim((string)($_POST['website'] ?? ''));

    // Soft CSRF: a form left open long enough for the session to lapse would
    // fail a hard check and throw away five minutes of a parent's typing.
    // Re-render with everything they entered instead, and ask for one more tap.
    $sessionToken = $_SESSION['_csrf'] ?? '';
    if ($sessionToken === '' || !hash_equals((string)$sessionToken, (string)($_POST['_csrf'] ?? ''))) {
        $topErr = 'This page had been open for a while, so we could not submit it straight away. '
                . 'Your answers are still here — please press Submit once more.';
    } else {
        [$answers, $errors] = survey_collect($spec, $_POST);
        if (!$errors) {
            if ($trap === '') {
                try {
                    survey_save_response((int)$survey['id'], $answers);
                } catch (Throwable $e) {
                    $topErr = 'Something went wrong saving your response. Please try again.';
                }
            }
            if ($topErr === '') $done = true;
        } else {
            $topErr = 'Please check the highlighted questions below.';
        }
    }
}

/** What the form should show for a question after a failed submit. */
function survey_posted($posted, string $key, $default = '')
{
    return $posted[$key] ?? $default;
}

if (!$survey || !$spec) {
    http_response_code(404);
    $pageTitle = 'Survey not available';
} elseif ($done) {
    $pageTitle = 'Thank you';
} else {
    $pageTitle = (string)$spec['title'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
<style>
  :root { color-scheme: light; --pink: #e91e63; --deep: #ad1457; --ink: #2b2b2b; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #fff5fa; color: var(--ink); line-height: 1.5; }
  header.sv-top { background: #fff; border-bottom: 3px solid var(--pink); padding: 1rem 1.2rem;
                  display: grid; grid-template-columns: 48px 1fr; gap: .8rem; align-items: center; }
  header.sv-top img { width: 48px; height: auto; }
  header.sv-top h1 { margin: 0; font-size: 1.05rem; color: var(--deep); font-weight: 800;
                     text-transform: uppercase; letter-spacing: .5px; }
  header.sv-top p { margin: .15rem 0 0; font-size: .8rem; color: #66bb6a; font-weight: 600; }
  main { max-width: 780px; margin: 0 auto; padding: 1.2rem 1.2rem 4rem; }

  .sv-card { background: #fff; border: 1px solid #f0dde6; border-radius: 12px;
             padding: 1.1rem 1.2rem; margin-bottom: 1rem; }
  .sv-intro { white-space: pre-line; }
  .sv-card > h2 { margin: 0 0 .2rem; font-size: 1rem; color: var(--deep);
                  text-transform: uppercase; letter-spacing: .4px; }
  .sv-card > h2 + p.sv-sec-intro { margin: .1rem 0 1rem; color: #6b6b6b; font-size: .9rem; }

  .sv-q { padding: .9rem 0; border-top: 1px solid #f6ebf1; }
  .sv-q:first-of-type { border-top: 0; padding-top: .2rem; }
  .sv-q > label.sv-label, .sv-q > .sv-label { display: block; font-weight: 600; margin-bottom: .1rem; }
  .sv-help { color: #7a7a7a; font-size: .85rem; margin: 0 0 .5rem; }
  .sv-req { color: var(--pink); }

  input[type=text], textarea, .sv-other input {
      width: 100%; padding: .6rem .7rem; border: 1px solid #dfd3da; border-radius: 8px;
      font: inherit; color: inherit; background: #fff; }
  textarea { min-height: 4.5rem; resize: vertical; }
  input[type=text]:focus, textarea:focus { outline: 2px solid #f8bbd0; border-color: var(--pink); }

  /* Choices are full-width tap targets — most parents fill this in on a phone. */
  .sv-choice { display: flex; align-items: flex-start; gap: .6rem; padding: .5rem .6rem;
               border: 1px solid #eadfe5; border-radius: 8px; margin-bottom: .4rem;
               cursor: pointer; background: #fffdfe; }
  .sv-choice:hover { background: #fff5fa; }
  .sv-choice input { margin: .25rem 0 0; flex: 0 0 auto; width: 1.05rem; height: 1.05rem; accent-color: var(--pink); }
  .sv-choice span { flex: 1 1 auto; }
  .sv-other { margin: .3rem 0 0 1.9rem; }

  /* Matrix: a real table on a wide screen, stacked cards on a phone. */
  .sv-matrix { width: 100%; border-collapse: collapse; margin-top: .5rem; }
  .sv-matrix th { font-size: .74rem; font-weight: 600; color: #6b6b6b; text-align: center;
                  padding: .3rem .2rem; vertical-align: bottom; line-height: 1.2; }
  .sv-matrix th:first-child { text-align: left; width: 40%; }
  .sv-matrix td { text-align: center; padding: .45rem .2rem; border-top: 1px solid #f6ebf1; }
  .sv-matrix td:first-child { text-align: left; font-size: .92rem; }
  .sv-matrix input { width: 1.05rem; height: 1.05rem; accent-color: var(--pink); }
  .sv-mrow-label { display: none; }
  @media (max-width: 620px) {
      .sv-matrix, .sv-matrix tbody, .sv-matrix tr, .sv-matrix td { display: block; width: 100%; }
      .sv-matrix thead { display: none; }
      .sv-matrix tr { border: 1px solid #eadfe5; border-radius: 8px; margin-bottom: .5rem; padding: .5rem .6rem; }
      .sv-matrix td { border: 0; text-align: left; padding: .15rem 0; }
      .sv-matrix td:first-child { font-weight: 600; margin-bottom: .3rem; }
      .sv-mrow-label { display: inline; margin-left: .5rem; font-size: .9rem; }
      .sv-matrix td label { display: flex; align-items: center; padding: .3rem 0; }
  }

  .sv-err { border-left: 3px solid var(--pink); padding-left: .7rem; }
  .sv-err-msg { color: #c2185b; font-size: .85rem; margin: .25rem 0 0; font-weight: 600; }
  .sv-flash { background: #fdecef; border: 1px solid #f7c9d6; color: #a01040;
              padding: .8rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-weight: 600; }

  .sv-submit { display: block; width: 100%; padding: .95rem 1rem; border: 0; border-radius: 10px;
               background: var(--pink); color: #fff; font-size: 1.05rem; font-weight: 700;
               cursor: pointer; }
  .sv-submit:hover { background: var(--deep); }
  .sv-foot { text-align: center; color: #8a8a8a; font-size: .8rem; margin-top: 1rem; }
  .sv-thanks { text-align: center; padding: 2rem 1.2rem; }
  .sv-thanks .sv-tick { font-size: 3rem; line-height: 1; }
  .sv-thanks h2 { color: var(--deep); margin: .6rem 0; }
  .sv-thanks p { white-space: pre-line; color: #4a4a4a; max-width: 34rem; margin: 0 auto; }
  .sv-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

  /* Autosave: a quiet confirmation, and the offer to discard a restored draft. */
  .sv-saved { position: fixed; right: .8rem; bottom: .8rem; z-index: 20;
              background: #2f7d4f; color: #fff; font-size: .8rem; font-weight: 600;
              padding: .4rem .75rem; border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,.18);
              opacity: 0; transform: translateY(.4rem); transition: opacity .2s, transform .2s;
              pointer-events: none; }
  .sv-saved.on { opacity: 1; transform: none; }
  .sv-restored { background: #eef7f0; border: 1px solid #cfe6d6; color: #22603c;
                 padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: .9rem; }
  .sv-restored button { background: none; border: 0; color: #22603c; font: inherit;
                        font-weight: 700; text-decoration: underline; cursor: pointer; padding: 0; }
</style>
</head>
<body>

<header class="sv-top">
    <img src="/assets/img/logo.png" alt="">
    <div>
        <h1><?= e($appName) ?></h1>
        <p><?= e($spec['subtitle'] ?? 'Parent survey') ?></p>
    </div>
</header>

<main>
<?php if (!$survey || !$spec): ?>

    <div class="sv-card">
        <h2 style="text-transform:none; font-size:1.15rem;">This survey isn't accepting responses.</h2>
        <p>The link you opened has closed or is no longer in use. If you believe this is a
           mistake, please contact the school and we'll be glad to send you a fresh link.</p>
    </div>

<?php elseif ($done): ?>

    <div class="sv-card sv-thanks">
        <div class="sv-tick">🌸</div>
        <h2>Thank you!</h2>
        <p><?= e((string)($spec['thanks'] ?? 'Your response has been recorded.')) ?></p>
    </div>

<?php else: ?>

    <div class="sv-card">
        <h2 style="text-transform:none; font-size:1.2rem; letter-spacing:0;"><?= e((string)$spec['title']) ?></h2>
        <p class="sv-sec-intro" style="margin-top:.2rem;"><strong><?= e((string)($spec['subtitle'] ?? '')) ?></strong></p>
        <p class="sv-intro"><?= e((string)($spec['intro'] ?? '')) ?></p>
    </div>

    <?php if ($topErr !== ''): ?>
        <div class="sv-flash"><?= e($topErr) ?></div>
    <?php endif; ?>

    <div id="sv-restored" class="sv-restored" hidden>
        We've brought back the answers you'd already filled in on this device.
        <button type="button" id="sv-discard">Start fresh instead</button>
    </div>

    <?php // data-restore is set only on a clean GET. After a failed submit the
          // server has already re-rendered what the parent typed, and letting the
          // saved draft overwrite that would undo their most recent edits. ?>
    <form method="post" action="/survey.php?t=<?= e($token) ?>" novalidate
          id="sv-form" data-key="<?= e(substr($token, 0, 16)) ?>"
          data-restore="<?= $posted ? '0' : '1' ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <div class="sv-hp" aria-hidden="true">
            <label>Leave this field empty
                <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <?php foreach ($spec['sections'] as $sec): ?>
            <div class="sv-card">
                <h2><?= e((string)$sec['title']) ?></h2>
                <?php if (!empty($sec['intro'])): ?>
                    <p class="sv-sec-intro"><?= e((string)$sec['intro']) ?></p>
                <?php endif; ?>

                <?php foreach ($sec['questions'] as $q):
                    $key   = (string)$q['key'];
                    $type  = (string)($q['type'] ?? 'text');
                    $err   = $errors[$key] ?? '';
                    $req   = !empty($q['required']);
                    $val   = survey_posted($posted, $key, $type === 'checkbox' || $type === 'matrix' ? [] : '');
                ?>
                    <div class="sv-q <?= $err !== '' ? 'sv-err' : '' ?>">
                        <?php if ((string)($q['label'] ?? '') !== ''): ?>
                            <div class="sv-label" id="lbl-<?= e($key) ?>">
                                <?= e((string)$q['label']) ?><?php if ($req): ?> <span class="sv-req" title="Required">*</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($q['help'])): ?>
                            <p class="sv-help"><?= e((string)$q['help']) ?></p>
                        <?php endif; ?>

                        <?php if ($type === 'text'): ?>
                            <input type="text" name="<?= e($key) ?>" maxlength="<?= SURVEY_NAME_MAX ?>"
                                   value="<?= e((string)$val) ?>" aria-labelledby="lbl-<?= e($key) ?>">

                        <?php elseif ($type === 'textarea'): ?>
                            <textarea name="<?= e($key) ?>" maxlength="<?= SURVEY_TEXT_MAX ?>" rows="3"
                                      aria-labelledby="lbl-<?= e($key) ?>"><?= e((string)$val) ?></textarea>

                        <?php elseif ($type === 'radio'): ?>
                            <?php foreach (survey_options($q) as $code => $label): ?>
                                <label class="sv-choice">
                                    <input type="radio" name="<?= e($key) ?>" value="<?= e((string)$code) ?>"
                                           <?= (string)$val === (string)$code ? 'checked' : '' ?>>
                                    <span><?= e((string)$label) ?></span>
                                </label>
                            <?php endforeach; ?>

                        <?php elseif ($type === 'checkbox'): ?>
                            <?php $picked = array_map('strval', (array)$val); ?>
                            <?php foreach (survey_options($q) as $code => $label): ?>
                                <label class="sv-choice">
                                    <input type="checkbox" name="<?= e($key) ?>[]" value="<?= e((string)$code) ?>"
                                           <?= in_array((string)$code, $picked, true) ? 'checked' : '' ?>>
                                    <span><?= e((string)$label) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!empty($q['other'])): ?>
                                <div class="sv-other">
                                    <input type="text" name="<?= e($key) ?>_other" maxlength="<?= SURVEY_OTHER_MAX ?>"
                                           placeholder="Other — please tell us"
                                           value="<?= e((string)survey_posted($posted, $key . '_other')) ?>"
                                           aria-label="Other, for: <?= e((string)$q['label']) ?>">
                                </div>
                            <?php endif; ?>

                        <?php elseif ($type === 'matrix'):
                            $scale = (array)($q['scale'] ?? []);
                            $rowsV = (array)$val; ?>
                            <table class="sv-matrix">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <?php foreach ($scale as $label): ?>
                                            <th scope="col"><?= e((string)$label) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ((array)($q['rows'] ?? []) as $rk => $rlabel): ?>
                                        <tr>
                                            <td><?= e((string)$rlabel) ?></td>
                                            <?php foreach ($scale as $code => $label): ?>
                                                <td>
                                                    <label>
                                                        <input type="radio" name="<?= e($key) ?>[<?= e((string)$rk) ?>]"
                                                               value="<?= e((string)$code) ?>"
                                                               <?= (string)($rowsV[$rk] ?? '') === (string)$code ? 'checked' : '' ?>
                                                               aria-label="<?= e((string)$rlabel . ' — ' . (string)$label) ?>">
                                                        <span class="sv-mrow-label"><?= e((string)$label) ?></span>
                                                    </label>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php if ($err !== ''): ?>
                            <p class="sv-err-msg"><?= e($err) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <button class="sv-submit" type="submit">Submit my response</button>
        <p class="sv-foot">
            Only questions marked <span class="sv-req">*</span> are required —
            answer as much or as little as you like.<br>
            <span id="sv-foot-save">Your answers are kept on this device as you type.</span>
        </p>
    </form>
    <div class="sv-saved" id="sv-saved" role="status" aria-live="polite">Saved</div>

<?php endif; ?>
</main>

<script>
/*
 * Autosave. Every keystroke and every tap is written to this device's
 * localStorage, so a dropped connection, a phone that sleeps, or a
 * closed tab doesn't cost a parent the five minutes they just spent.
 *
 * Deliberately device-local: nothing is sent to the school until the parent
 * presses Submit. A parent who types a worry, thinks better of it and closes
 * the tab has not consented to send it, and a draft on a server is sent.
 * The trade-off is that a draft doesn't follow them to another phone.
 *
 * Everything here is an enhancement. With JS off the form behaves exactly as
 * it did before: the server still re-renders what was typed after a failed
 * submit, and nothing about saving changes.
 */
(function () {
    var form = document.getElementById('sv-form');
    if (!form || !window.localStorage) return;

    var KEY  = 'lg_survey_' + form.getAttribute('data-key');
    var MAXAGE = 30 * 24 * 60 * 60 * 1000;          // forget drafts after a month
    var SKIP = { '_csrf': 1, 't': 1, 'website': 1 };

    // localStorage throws in some private-browsing modes; a survey must never
    // break because of a storage quirk, so every access is guarded.
    function read()      { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
    function write(o)    { try { localStorage.setItem(KEY, JSON.stringify(o)); return true; } catch (e) { return false; } }
    function drop()      { try { localStorage.removeItem(KEY); } catch (e) {} }

    function collect() {
        var data = {}, fd = new FormData(form), it = fd.entries(), row;
        while (!(row = it.next()).done) {
            var k = row.value[0], v = row.value[1];
            if (SKIP[k]) continue;
            if (k.slice(-2) === '[]') { (data[k] = data[k] || []).push(v); }
            else if (v !== '') { data[k] = v; }
        }
        return data;
    }

    // form.elements[name] handles 'understand[vision]' and 'valuable[]' without
    // any selector escaping, and hands back a RadioNodeList for grouped inputs.
    function apply(data) {
        var restored = 0;
        for (var k in data) {
            if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
            var field = form.elements[k];
            if (!field) continue;                          // question since removed
            var vals  = data[k] instanceof Array ? data[k] : [data[k]];
            var nodes = (field.length !== undefined && !field.tagName) ? field : [field];
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.type === 'radio' || el.type === 'checkbox') {
                    for (var j = 0; j < vals.length; j++) {
                        if (el.value === vals[j]) { el.checked = true; restored++; }
                    }
                } else if (vals[0] != null) {
                    el.value = vals[0]; restored++;
                }
            }
        }
        return restored;
    }

    var badge = document.getElementById('sv-saved');
    var hideTimer, saveTimer;
    function flash(text) {
        if (!badge) return;
        badge.textContent = text;
        badge.classList.add('on');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { badge.classList.remove('on'); }, 1400);
    }

    function save() {
        var ok = write({ at: new Date().getTime(), v: collect() });
        flash(ok ? 'Saved' : 'Could not save on this device');
        if (!ok) {                                        // say so once, then stop nagging
            var foot = document.getElementById('sv-foot-save');
            if (foot) foot.textContent = 'This browser is blocking local storage, so answers are not being kept — please finish in one go.';
        }
    }

    // Restore before wiring the listeners, so replaying a draft doesn't
    // immediately rewrite it, and only on a clean load (see data-restore).
    if (form.getAttribute('data-restore') === '1') {
        var saved = read();
        if (saved && saved.v && (new Date().getTime() - (saved.at || 0)) < MAXAGE) {
            if (apply(saved.v) > 0) {
                var note = document.getElementById('sv-restored');
                if (note) note.hidden = false;
            }
        } else if (saved) {
            drop();                                        // stale
        }
    }

    var discard = document.getElementById('sv-discard');
    if (discard) {
        discard.addEventListener('click', function () {
            drop();
            form.reset();
            var note = document.getElementById('sv-restored');
            if (note) note.hidden = true;
        });
    }

    // Typing is debounced so we're not serialising the whole form on every
    // letter; taps on radios and checkboxes save straight away.
    form.addEventListener('input', function () {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 400);
    });
    form.addEventListener('change', function () {
        clearTimeout(saveTimer);
        save();
    });
    // A phone locking or the tab being swapped away is the most likely way a
    // half-filled form is lost, so flush on the way out too.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { clearTimeout(saveTimer); save(); }
    });

    // Once it's really submitted the draft has done its job.
    form.addEventListener('submit', function () { drop(); });
})();

<?php if ($done): ?>
/* Submitted successfully — clear the draft even though the form is gone. */
(function () {
    try { localStorage.removeItem('lg_survey_' + <?= json_encode(substr($token, 0, 16)) ?>); } catch (e) {}
})();
<?php endif; ?>
</script>

</body>
</html>
