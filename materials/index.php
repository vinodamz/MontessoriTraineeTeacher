<?php
/**
 * materials/index.php — monthly condition audit board, built for BULK entry.
 *
 * One form wraps the whole list: pick conditions on any number of rows, hit
 * one "Save all" (sticky bar shows how many changes are pending). Per shelf
 * there's a "rest are Good" button that fills every still-unmarked material
 * — so a typical month is: mark the damaged few, bulk-good the rest, done.
 *
 * The SAVED state renders as its own pill (condition + who/when), separate
 * from the editing dropdown, so a saved mark is always visible even while
 * the select is being changed. No auto-submit JS — plain POSTs only (the
 * old per-row onchange submit silently did nothing on browsers without
 * requestSubmit, which read as "I marked it but it didn't save").
 *
 *   GET ?period=YYYY-MM   audit month (defaults to this month)
 *   GET ?q= / ?loc=       filter
 *   GET ?only=pending     materials not yet marked this month
 *   GET ?only=replace     materials flagged for replacement
 *   POST op=bulk_mark     save every changed row in one go
 *   POST op=bulk_good     mark every still-unmarked material Good
 *                         (scope = one shelf via loc=…, or everything)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

/** Current checks for a period: [material_id => row] for change detection. */
function mm_existing_marks(string $period): array
{
    $st = db()->prepare("
        SELECT material_id, condition_code, needs_replacement, replace_qty, notes
        FROM mm_condition_checks WHERE period = :p
    ");
    $st->execute([':p' => $period]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['material_id']] = $r;
    return $out;
}

// ---- POST: bulk save --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body over post_max_size reaches PHP with $_POST and $_FILES EMPTY —
    // csrf_check() would then reject it as 'Bad CSRF token', which reads as
    // a random failure. Name the real problem instead.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'file too large for the server (limit ' . ini_get('post_max_size') . ') — record a shorter video']);
        exit;
    }
    csrf_check();
    $op = $_POST['op'] ?? '';

    // Where to land after a save (keep the user's filters).
    $backQs = http_build_query(array_filter([
        'period' => $period,
        'q'      => trim((string)($_POST['back_q'] ?? '')),
        'loc'    => trim((string)($_POST['back_loc'] ?? '')),
        'only'   => trim((string)($_POST['back_only'] ?? '')),
    ]));
    $back = '/materials/index.php' . ($backQs ? '?' . $backQs : '');

    if ($op === 'bulk_mark') {
        // The fallback "Save all" carries the FULL row state (condition,
        // replace flag, qty, notes) — it used to post only conditions and
        // silently dropped typed-but-unsaved notes, which read as lost data.
        $conds    = is_array($_POST['cond']  ?? null) ? $_POST['cond']  : [];
        $needsArr = is_array($_POST['needs'] ?? null) ? $_POST['needs'] : [];
        $qtyArr   = is_array($_POST['qty']   ?? null) ? $_POST['qty']   : [];
        $notesArr = is_array($_POST['notes'] ?? null) ? $_POST['notes'] : [];
        $existing = mm_existing_marks($period);
        $saved = 0;
        foreach ($conds as $mid => $code) {
            $mid  = (int)$mid;
            $code = (string)$code;
            if ($mid <= 0 || $code === '' || !isset(mm_conditions()[$code])) continue;
            $needs = !empty($needsArr[$mid]);
            $qty   = max(0, (int)($qtyArr[$mid] ?? 0));
            if ($needs && $qty === 0) $qty = 1;
            $notesPosted = array_key_exists($mid, $notesArr);
            $notes = $notesPosted ? trim((string)$notesArr[$mid]) : null;

            // Only touch rows that actually changed — a bulk save must not
            // re-stamp every untouched row with today's user + time.
            $ex = $existing[$mid] ?? null;
            $same = $ex !== null
                && (string)$ex['condition_code'] === $code
                && (int)$ex['needs_replacement'] === ($needs ? 1 : 0)
                && (int)$ex['replace_qty'] === ($needs ? $qty : (int)$ex['replace_qty'])
                && (!$notesPosted || trim((string)($ex['notes'] ?? '')) === $notes);
            if ($same) continue;
            mm_save_check($mid, $period, $code, $needs, $qty, $notes, (int)$user['id']);
            $saved++;
        }
        flash_set('ok', $saved > 0
            ? "Saved $saved mark" . ($saved === 1 ? '' : 's') . ' (condition, replacement and notes).'
            : 'Nothing changed.');
        redirect($back);
    }

    // ---- AJAX: autosave one row (condition + replace + qty + notes) --------
    if ($op === 'ajax_mark') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $mid  = (int)($_POST['material_id'] ?? 0);
            $code = (string)($_POST['condition_code'] ?? '');
            if ($mid <= 0 || !isset(mm_conditions()[$code])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'pick a condition first']);
                exit;
            }
            $needs = !empty($_POST['needs_replacement']);
            $qty   = max(0, (int)($_POST['replace_qty'] ?? 0));
            if ($needs && $qty === 0) $qty = 1;
            // Posted notes SET (empty clears); an absent field keeps what's
            // there — the dashboard's verdict edits don't carry notes.
            $notes = array_key_exists('notes', $_POST) ? trim((string)$_POST['notes']) : null;
            mm_save_check($mid, $period, $code, $needs, $qty, $notes, (int)$user['id']);
            $mediaN = db()->prepare("
                SELECT COUNT(*) FROM mm_condition_media md
                JOIN mm_condition_checks c ON c.id = md.check_id
                WHERE c.material_id = :m AND c.period = :p
            ");
            $mediaN->execute([':m' => $mid, ':p' => $period]);
            echo json_encode([
                'ok'    => true,
                'label' => mm_condition_label($code),
                'tone'  => mm_condition_tone($code),
                'needs' => $needs, 'qty' => $qty,
                'media' => (int)$mediaN->fetchColumn(),
                'by'    => (string)$user['name'],
                'at'    => date('j M, g:ia'),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ---- AJAX: photo/video upload straight from the row --------------------
    if ($op === 'ajax_media') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $mid = (int)($_POST['material_id'] ?? 0);
            $chk = db()->prepare("SELECT id FROM mm_condition_checks WHERE material_id = :m AND period = :p");
            $chk->execute([':m' => $mid, ':p' => $period]);
            $checkId = (int)$chk->fetchColumn();
            if ($checkId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'mark a condition first, then attach the photo']);
                exit;
            }
            $id = mm_media_store($_FILES['media'] ?? [], $checkId, (int)$user['id']);
            if ($id === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'no file received']);
                exit;
            }
            $kind = db()->query("SELECT kind FROM mm_condition_media WHERE id = $id")->fetchColumn();
            echo json_encode(['ok' => true, 'media_id' => $id, 'kind' => $kind, 'url' => '/materials/media.php?id=' . $id]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($op === 'bulk_good') {
        $scope = trim((string)($_POST['scope'] ?? ''));   // '' = everything
        $params = [':p' => $period];
        $where  = "m.is_active = 1 AND NOT EXISTS (
                       SELECT 1 FROM mm_condition_checks c
                       WHERE c.material_id = m.id AND c.period = :p
                   )";
        if ($scope !== '') { $where .= " AND m.location = :loc"; $params[':loc'] = $scope; }
        $st = db()->prepare("SELECT m.id FROM mm_materials m WHERE $where");
        $st->execute($params);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $mid) {
            mm_save_check((int)$mid, $period, 'good', false, 0, null, (int)$user['id']);
        }
        $label = $scope !== '' ? $scope : 'all shelves';
        flash_set('ok', count($ids) . ' unmarked material' . (count($ids) === 1 ? '' : 's') . " in $label marked Good. Already-marked rows untouched.");
        redirect($back);
    }
}

// ---- Filters ----------------------------------------------------------------
$q    = trim((string)($_GET['q'] ?? ''));
$loc  = trim((string)($_GET['loc'] ?? ''));
$only = (string)($_GET['only'] ?? '');

$where  = ['m.is_active = 1'];
$params = [':p' => $period];
if ($q !== '')   { $where[] = 'm.name LIKE :q';    $params[':q']   = '%' . $q . '%'; }
if ($loc !== '') { $where[] = 'm.location = :loc'; $params[':loc'] = $loc; }
if ($only === 'pending') $where[] = 'c.id IS NULL';
if ($only === 'replace') $where[] = 'c.needs_replacement = 1';
$whereSql = implode(' AND ', $where);

$rows = db()->prepare("
    SELECT m.id, m.name, m.location, m.sort_order,
           c.condition_code, c.needs_replacement, c.replace_qty, c.notes,
           c.checked_at, u.name AS checked_by,
           (SELECT COUNT(*) FROM mm_condition_media md WHERE md.check_id = c.id) AS media_count
    FROM mm_materials m
    LEFT JOIN mm_condition_checks c ON c.material_id = m.id AND c.period = :p
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE $whereSql
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute($params);
$materials = $rows->fetchAll();

// Latest photo/video EVER per material — the board always shows how the
// material last looked, even before this month's photo is taken.
$latestMedia = mm_latest_media(array_column($materials, 'id'));

$byLoc = [];
foreach ($materials as $m) $byLoc[$m['location']][] = $m;

// Progress + counters for the whole (unfiltered) month.
$stat = db()->prepare("
    SELECT
        (SELECT COUNT(*) FROM mm_materials WHERE is_active = 1) AS total,
        (SELECT COUNT(*) FROM mm_condition_checks WHERE period = :p1) AS marked,
        (SELECT COUNT(*) FROM mm_condition_checks WHERE period = :p2 AND needs_replacement = 1) AS to_replace
");
$stat->execute([':p1' => $period, ':p2' => $period]);
$counts = $stat->fetch();

$locations = db()->query("SELECT location FROM mm_materials WHERE is_active = 1 GROUP BY location ORDER BY MIN(sort_order)")->fetchAll(PDO::FETCH_COLUMN);
$periods   = mm_period_options();

$TONE_BG = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];

$pageTitle = 'Material condition — ' . mm_period_label($period);
require __DIR__ . '/../includes/header.php';
?>

<style>
/* Materials board — mobile-first card rows (tables don't survive phones). */
.mm-list  { display: flex; flex-direction: column; }
.mm-item  { padding: .6rem .2rem; border-bottom: 1px solid #eee; }
.mm-item:last-child { border-bottom: 0; }
.mm-top   { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; margin-bottom: .35rem; }
.mm-thumb { flex: 0 0 auto; width: 44px; height: 44px; border-radius: 8px; overflow: hidden;
            border: 1px solid #ddd; display: flex; align-items: center; justify-content: center;
            background: #f4f4f0; text-decoration: none; }
.mm-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mm-thumb-vid { font-size: 1.2rem; }
.mm-name  { flex: 1 1 12rem; }
.mm-status { display: inline-flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.mm-controls { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
.mm-controls .mm-cond { flex: 1 1 11rem; max-width: 16rem; }
.mm-rep   { display: inline-flex; align-items: center; gap: .3rem; font-size: .85rem; white-space: nowrap; }
.mm-qty   { width: 4.2rem; }
.mm-snap  { font-size: 1.15rem; line-height: 1; }
.mm-detail { display: flex; gap: .8rem; flex-wrap: wrap; align-items: end;
             background: #fafaf7; border-radius: 8px; padding: .5rem .6rem; margin-top: .45rem; }
.mm-noteswrap { flex: 2 1 240px; }
.mm-noteswrap textarea { width: 100%; }
.mm-upmsg:empty { display: none; }
.mm-upmsg { margin-top: .25rem; }
.mm-upbar { display: block; max-width: 20rem; height: 8px; border-radius: 5px; background: #eee; overflow: hidden; margin-top: .25rem; }
.mm-upbar i { display: block; height: 100%; background: #2d6ba0; transition: width .2s; }
@media (pointer: coarse), (max-width: 640px) {
    /* Fat-finger sizes: WCAG-ish 44px targets for the walk-the-shelf flow. */
    .mm-controls .mm-cond, .mm-qty { min-height: 44px; font-size: 1rem; }
    .mm-snap, .mm-expand { min-height: 44px; min-width: 48px; }
    .mm-rep input[type="checkbox"] { width: 1.35rem; height: 1.35rem; }
    .mm-item { padding: .75rem .1rem; }
}
</style>

<div class="page-head">
    <div>
        <h1>Material condition audit</h1>
        <p class="muted">
            <strong><?= e(mm_period_label($period)) ?></strong> ·
            <?= (int)$counts['marked'] ?>/<?= (int)$counts['total'] ?> checked ·
            <strong style="color:#b3261e"><?= (int)$counts['to_replace'] ?></strong> flagged for replacement
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="dashboard.php?period=<?= e($period) ?>">Dashboard</a>
        <a class="btn btn-ghost" href="manage.php">Manage materials</a>
        <a class="btn btn-primary" href="replacement.php?period=<?= e($period) ?>">Replacement list →</a>
    </div>
</div>

<form class="filters no-print" method="get" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <label>Month
        <select name="period" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= e(mm_period_label($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <input type="search" name="q" placeholder="Search material…" value="<?= e($q) ?>">
    <select name="loc">
        <option value="">All shelves</option>
        <?php foreach ($locations as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $loc ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="only">
        <option value="">Every material</option>
        <option value="pending" <?= $only === 'pending' ? 'selected' : '' ?>>Not yet checked</option>
        <option value="replace" <?= $only === 'replace' ? 'selected' : '' ?>>Flagged for replacement</option>
    </select>
    <button class="btn">Filter</button>
    <a class="btn btn-ghost" href="index.php?period=<?= e($period) ?>">Reset</a>
</form>

<?php if (!$materials): ?>
    <div class="empty"><p>No materials match. <a href="index.php?period=<?= e($period) ?>">Clear filters</a>.</p></div>
<?php else: ?>

<form method="post" id="bulkForm" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_mark">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <input type="hidden" name="back_q" value="<?= e($q) ?>">
    <input type="hidden" name="back_loc" value="<?= e($loc) ?>">
    <input type="hidden" name="back_only" value="<?= e($only) ?>">

    <?php foreach ($byLoc as $location => $items):
        $shelfMarked = 0;
        foreach ($items as $it) if ($it['condition_code'] !== null) $shelfMarked++;
    ?>
    <section class="card" style="margin-bottom:1rem;">
        <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; gap:.6rem; flex-wrap:wrap;">
            <h2 style="margin:0;"><?= e($location) ?>
                <span class="muted small">· <?= $shelfMarked ?>/<?= count($items) ?> checked</span></h2>
            <?php if ($shelfMarked < count($items)): ?>
                <button class="btn btn-ghost small" type="submit" form="goodForm"
                        name="scope" value="<?= e($location) ?>"
                        title="Every material on this shelf that has no mark yet becomes Good. Rows already marked are untouched."
                        onclick="return confirm('Mark all still-unchecked materials in <?= e(addslashes($location)) ?> as Good?')">
                    rest are Good ✓
                </button>
            <?php endif; ?>
        </div>
        <div class="mm-list">
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null;
                $mid = (int)$m['id'];
            ?>
            <div class="mm-item" id="m<?= $mid ?>" data-id="<?= $mid ?>" data-saved="<?= e((string)$m['condition_code']) ?>">
                <div class="mm-top">
                    <?php $lm = $latestMedia[$mid] ?? null; ?>
                    <?php if ($lm): $lmUrl = '/materials/media.php?id=' . $lm['id']; ?>
                        <a class="mm-thumb" href="check.php?id=<?= $mid ?>&period=<?= e($period) ?>#history"
                           title="Latest <?= $lm['kind'] ?> · <?= e(date('j M Y', strtotime($lm['uploaded_at']))) ?> — tap for history">
                            <?php if ($lm['kind'] === 'video'): ?>
                                <span class="mm-thumb-vid">🎥</span>
                            <?php elseif ($lm['kind'] === 'audio'): ?>
                                <span class="mm-thumb-vid">🎙</span>
                            <?php else: ?>
                                <img src="<?= e($lmUrl) ?>&thumb=1" alt="" loading="lazy">
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <strong class="mm-name"><?= e($m['name']) ?></strong>
                    <span class="pill small mm-media-pill" title="Photos/videos attached" <?= (int)$m['media_count'] > 0 ? '' : 'hidden' ?>>📎 <span class="mm-media-n"><?= (int)$m['media_count'] ?></span></span>
                    <span class="pill small mm-nophoto" style="background:#fbdcd8;color:#8b1c14;"
                          <?= ($marked && $m['needs_replacement'] && (int)$m['media_count'] === 0) ? '' : 'hidden' ?>>no photo 📷</span>
                    <span class="mm-status muted small">
                        <?php if ($marked): ?>
                            <span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($m['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($m['condition_code'])) ?></span>
                            ✓ <?= e($m['checked_by'] ?? 'Unknown') ?> · <?= e(date('j M, g:ia', strtotime($m['checked_at']))) ?>
                        <?php else: ?>
                            <span style="color:#b3261e">not checked</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mm-controls">
                    <select name="cond[<?= $mid ?>]" class="mm-cond">
                        <option value="">— condition —</option>
                        <?php foreach (mm_conditions() as $code => $meta): ?>
                            <option value="<?= e($code) ?>" <?= $m['condition_code'] === $code ? 'selected' : '' ?>
                                    data-suggests="<?= $meta['suggests_replace'] ? '1' : '0' ?>"><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="mm-rep">
                        <input type="checkbox" class="mm-needs" name="needs[<?= $mid ?>]" value="1" <?= !empty($m['needs_replacement']) ? 'checked' : '' ?>>
                        <span>replace</span>
                    </label>
                    <input type="number" class="mm-qty" name="qty[<?= $mid ?>]" min="1" max="99" inputmode="numeric"
                           value="<?= max(1, (int)$m['replace_qty']) ?>"
                           style="<?= !empty($m['needs_replacement']) ? '' : 'display:none;' ?>"
                           title="How many to replace">
                    <!-- Direct capture: single-purpose inputs (capture is ignored
                         when combined with multiple/mixed accept, which is why the
                         old field opened the file picker instead of the camera). -->
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="photo" title="Take a photo now">📷</button>
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="video" title="Record a video now">🎥</button>
                    <button type="button" class="btn btn-ghost mm-rec" title="Record a voice memo" hidden>⏺</button>
                    <button type="button" class="btn btn-ghost mm-expand" aria-expanded="false" title="Notes + gallery">▸ more</button>
                    <input type="file" class="mm-cam mm-cam-photo" accept="image/*" capture="environment" hidden>
                    <input type="file" class="mm-cam mm-cam-video" accept="video/*" capture="environment" hidden>
                </div>
                <div class="mm-upmsg muted small"></div>
                <div class="mm-detail" hidden>
                    <label class="small mm-noteswrap">
                        Notes <span class="muted">(where's the mould, which part peeled…)</span>
                        <button type="button" class="btn btn-ghost small mm-dictate" title="Dictate — speak, it types" hidden>🎤 dictate</button>
                        <textarea class="mm-notes" name="notes[<?= $mid ?>]" rows="2"><?= e((string)($m['notes'] ?? '')) ?></textarea>
                    </label>
                    <label class="small">
                        Upload from gallery
                        <input type="file" class="mm-file" accept="image/*,video/*" multiple>
                    </label>
                    <a class="btn btn-ghost small" href="check.php?id=<?= $mid ?>&period=<?= e($period) ?>">full history →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- Sticky bar: with JS every change autosaves; the button is the no-JS
         fallback and the belt-and-braces "save anything that failed" path. -->
    <div class="no-print" style="position:sticky; bottom:0; background:var(--card-bg, #fff); border-top:2px solid #ddd; padding:.6rem .8rem; display:flex; gap:.7rem; align-items:center; z-index:50;">
        <button class="btn btn-primary" type="submit">Save all changes</button>
        <span id="pendingCount" class="muted small">All changes saved automatically.</span>
        <span id="uploadCount" class="small" style="color:#2d6ba0;"></span>
    </div>
</form>

<!-- Separate tiny form for the "rest are Good" buttons (buttons carry scope). -->
<form method="post" id="goodForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_good">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <input type="hidden" name="back_q" value="<?= e($q) ?>">
    <input type="hidden" name="back_loc" value="<?= e($loc) ?>">
    <input type="hidden" name="back_only" value="<?= e($only) ?>">
</form>

<script>
// Autosave: any change on a row (condition / replace / qty / notes-blur)
// POSTs that row via fetch. The status cell narrates every state — Saving…,
// ✓ saved by-who-at-when, or a red "retry" — so a save can never silently
// fail (the bug the first version had). The sticky "Save all" bulk-form
// remains as the no-JS / it-still-failed fallback.
(function () {
    var form = document.getElementById('bulkForm');
    if (!form || !window.fetch) return;   // no-JS/no-fetch → bulk form still works
    var CSRF   = form.querySelector('input[name="_csrf"]').value;
    var PERIOD = form.querySelector('input[name="period"]').value;
    var UPLOAD_LIMIT = <?= mm_effective_upload_limit() ?>;   // bytes — app cap ∩ php.ini

    function rowEls(item) {
        return {
            cond:  item.querySelector('.mm-cond'),
            needs: item.querySelector('.mm-needs'),
            qty:   item.querySelector('.mm-qty'),
            status:item.querySelector('.mm-status'),
            detail:item.querySelector('.mm-detail'),
            upmsg: item.querySelector('.mm-upmsg')
        };
    }

    // ----- Draft safety net -------------------------------------------------
    // Every edit is journaled to localStorage the moment it happens; the
    // draft is cleared only on a CONFIRMED save. Close the app mid-audit,
    // lose signal, forget entirely — next open restores and re-saves.
    function draftKey(tr) { return 'mmdraft:' + PERIOD + ':' + tr.dataset.id; }
    function serializeRow(tr) {
        var el = rowEls(tr);
        return JSON.stringify({
            c: el.cond ? el.cond.value : '',
            n: el.needs && el.needs.checked ? 1 : 0,
            q: el.qty ? (el.qty.value || '1') : '1',
            t: el.detail ? el.detail.querySelector('.mm-notes').value : ''
        });
    }
    function writeDraft(tr) {
        try { localStorage.setItem(draftKey(tr), serializeRow(tr)); } catch (e) {}
        pending[tr.dataset.id] = true;
        refreshPending();
    }
    function clearDraft(tr) {
        try { localStorage.removeItem(draftKey(tr)); } catch (e) {}
        delete pending[tr.dataset.id];
        refreshPending();
    }
    var pending = {};
    function refreshPending() {
        var n = Object.keys(pending).length;
        var out = document.getElementById('pendingCount');
        if (out) out.textContent = n === 0
            ? 'All changes saved automatically.'
            : n + ' change' + (n === 1 ? '' : 's') + ' not confirmed saved yet — kept safe on this phone; they retry automatically.';
    }

    function buildRowFormData(tr) {
        var el = rowEls(tr);
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('op', 'ajax_mark');
        fd.append('period', PERIOD);
        fd.append('material_id', tr.dataset.id);
        fd.append('condition_code', el.cond.value);
        if (el.needs.checked) fd.append('needs_replacement', '1');
        fd.append('replace_qty', el.needs.checked ? (el.qty.value || '1') : '0');
        fd.append('notes', el.detail ? el.detail.querySelector('.mm-notes').value : '');
        return fd;
    }

    function saveRow(tr) {
        var el = rowEls(tr);
        if (!el.cond || el.cond.value === '') {
            el.status.innerHTML = '<span style="color:#b3261e">pick a condition — the note is kept safe until you do</span>';
            return Promise.resolve(false);
        }
        var state = serializeRow(tr);
        if (tr.dataset.lastSaved === state) {   // nothing new — don't restamp
            clearDraft(tr);
            return Promise.resolve(true);
        }
        var fd = buildRowFormData(tr);

        el.status.textContent = 'Saving…';
        return fetch('/materials/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'save failed');
                tr.dataset.saved = el.cond.value;
                tr.dataset.lastSaved = state;
                clearDraft(tr);
                el.cond.value = tr.dataset.saved;   // pin against browser form-restore
                var toneBg = {ok:'#dff1d3;color:#2d6526', warn:'#fcebc6;color:#6c4612', bad:'#fbdcd8;color:#8b1c14'}[d.tone] || '#eee';
                var needsPhoto = d.needs && d.media === 0;
                el.status.innerHTML =
                    '<span class="pill small" style="background:' + toneBg + '">' + escapeHtml(d.label) + '</span><br>' +
                    '✓ ' + escapeHtml(d.by) + ' · ' + escapeHtml(d.at) +
                    (needsPhoto ? ' <strong style="color:#b3261e">— add a photo 📷</strong>' : '');
                el.status.style.color = '#2d6526';
                var chip = tr.querySelector('.mm-nophoto');
                if (chip) chip.hidden = !needsPhoto;
                return true;
            })
            .catch(function (err) {
                el.status.innerHTML = '<button type="button" class="link-btn danger mm-retry">⚠ not saved — retry</button>';
                console.error('autosave failed:', err);
                return false;
            });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    // Undo browser form-restore: the server's saved value wins on load…
    form.querySelectorAll('.mm-item').forEach(function (item) {
        var sel = item.querySelector('.mm-cond');
        if (sel && sel.value !== item.dataset.saved) sel.value = item.dataset.saved || '';
        item.dataset.lastSaved = serializeRow(item);
    });
    // …then any unsaved DRAFT wins over both: restore it into the fields and
    // push it to the server. This is what rescues "forgot to save yesterday".
    form.querySelectorAll('.mm-item').forEach(function (item) {
        var raw = null;
        try { raw = localStorage.getItem(draftKey(item)); } catch (e) {}
        if (!raw || raw === item.dataset.lastSaved) {
            if (raw) { try { localStorage.removeItem(draftKey(item)); } catch (e) {} }
            return;
        }
        var d; try { d = JSON.parse(raw); } catch (e) { return; }
        var el = rowEls(item);
        if (d.c) el.cond.value = d.c;
        el.needs.checked = !!d.n;
        // Belt-and-braces: a draft written by an OLDER build of this page
        // could have journaled the condition BEFORE the "damage conditions
        // default to replace" rule applied to it (a timing bug, since
        // fixed below). Re-apply the rule now so a stale draft can never
        // silently restore a damage condition with replace unchecked.
        var optNow = el.cond.options[el.cond.selectedIndex];
        if (optNow && optNow.dataset.suggests === '1') el.needs.checked = true;
        el.qty.value = d.q || '1';
        el.qty.style.display = el.needs.checked ? '' : 'none';
        if (el.detail && typeof d.t === 'string') {
            el.detail.querySelector('.mm-notes').value = d.t;
            if (d.t !== '') { el.detail.hidden = false; }
        }
        pending[item.dataset.id] = true;
        el.status.innerHTML = '<span style="color:#6c4612">restoring unsaved change…</span>';
        saveRow(item);
    });
    refreshPending();

    // Journal every keystroke/toggle; notes also save 1.2s after typing stops
    // (blur alone lost notes when the app was closed mid-typing).
    //
    // mm-cond is deliberately EXCLUDED here: for a <select>, the browser
    // fires 'input' BEFORE 'change', and the "damage conditions default to
    // replace" rule below runs in the 'change' handler. Journaling on
    // 'input' would capture the condition together with the OLD, pre-rule
    // replace flag — and if the save that follows ever failed, that WRONG
    // snapshot was what got restored and re-saved later, silently clearing
    // a replacement flag the teacher never touched. mm-cond's own 'change'
    // handler journals the corrected state instead (see below).
    var noteTimers = {};
    form.addEventListener('input', function (ev) {
        if (ev.target.classList.contains('mm-cond')) return;
        var tr = ev.target.closest('.mm-item');
        if (!tr) return;
        writeDraft(tr);
        if (ev.target.classList.contains('mm-notes')) {
            clearTimeout(noteTimers[tr.dataset.id]);
            noteTimers[tr.dataset.id] = setTimeout(function () { saveRow(tr); }, 1200);
        }
    });

    // Last-chance flush when the app is backgrounded or the page is left:
    // sendBeacon survives page teardown. Drafts stay until confirmed, so
    // even a failed beacon is recovered on the next visit.
    function flushPending() {
        Object.keys(pending).forEach(function (id) {
            var tr = document.getElementById('m' + id);
            if (!tr) return;
            var el = rowEls(tr);
            if (!el.cond || el.cond.value === '') return;
            if (tr.dataset.lastSaved === serializeRow(tr)) return;
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/materials/index.php', buildRowFormData(tr));
            }
        });
    }
    window.addEventListener('pagehide', flushPending);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flushPending();
    });

    form.addEventListener('change', function (ev) {
        var t  = ev.target;
        var tr = t.closest('.mm-item');
        if (!tr) return;
        var el = rowEls(tr);

        if (t.classList.contains('mm-cond')) {
            // Damage conditions pre-tick "replace" (teacher can untick — that
            // fires another autosave with the corrected flag).
            var opt = t.options[t.selectedIndex];
            if (opt && opt.dataset.suggests === '1') { el.needs.checked = true; }
            if (opt && opt.dataset.suggests === '0') { el.needs.checked = false; }
        }
        if (t.classList.contains('mm-needs') || t.classList.contains('mm-cond')) {
            el.qty.style.display = el.needs.checked ? '' : 'none';
        }
        if (t.classList.contains('mm-file') || t.classList.contains('mm-cam')) { uploadFiles(tr, t); return; }
        // Journal the CORRECTED state (post auto-tick) so that if the save
        // below fails, whatever survives in the draft is the right one.
        writeDraft(tr);
        saveRow(tr);
    });

    // Retry buttons (delegated — they're created dynamically).
    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn) return;
        var tr = btn.closest('.mm-item');
        if (btn.classList.contains('mm-retry') && tr) {
            saveRow(tr);
        }
        if (btn.classList.contains('mm-expand') && tr) {
            var det = tr.querySelector('.mm-detail');
            var open = det.hidden;
            det.hidden = !open;
            btn.textContent = (open ? '▾' : '▸') + ' more';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (btn.classList.contains('mm-snap') && tr) {
            // Fire the single-purpose capture input — opens the camera app
            // directly on phones instead of a file chooser.
            var input = tr.querySelector(btn.dataset.kind === 'video' ? '.mm-cam-video' : '.mm-cam-photo');
            if (input) input.click();
        }
    });

    // ----- Upload queue: progress %, auto-retry, never-silent ---------------
    // The old fetch() uploader had NO progress events and no leave-guard: a
    // teacher shooting 5 photos saw nothing moving, walked to the next shelf,
    // the phone killed the page, and the photos were gone. XHR gives real
    // upload progress; the queue survives per-file failures; leaving the page
    // mid-upload now warns first.
    var upQueue = [], upActive = null;

    function fmtMB(b) { return (b / 1048576).toFixed(1) + ' MB'; }
    function kindLabel(f) {
        var t = (f.type || '');
        return t.indexOf('video') === 0 ? 'video' : (t.indexOf('audio') === 0 ? 'voice memo' : 'photo');
    }

    function refreshUploadBar() {
        var n = (upActive ? 1 : 0) + upQueue.length;
        var out = document.getElementById('uploadCount');
        if (out) out.textContent = n > 0
            ? '⬆ uploading ' + n + ' file' + (n === 1 ? '' : 's') + ' — keep this page open'
            : '';
    }

    function queueUploads(tr, files) {
        var el = rowEls(tr);
        var accepted = 0;
        files.forEach(function (f) {
            if (f.size > UPLOAD_LIMIT) {
                el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ ' + escapeHtml(f.name || 'file') + ' is ' + fmtMB(f.size)
                    + ' — over the ' + fmtMB(UPLOAD_LIMIT) + ' limit. Record a shorter clip.</span>';
                return;
            }
            upQueue.push({ tr: tr, file: f, tries: 0 });
            accepted++;
        });
        if (accepted > 0) {
            el.upmsg.textContent = '⬆ queued ' + accepted + ' file' + (accepted === 1 ? '' : 's') + '…';
            pumpUploads();
        }
        refreshUploadBar();
    }

    function pumpUploads() {
        if (upActive || !upQueue.length) return;
        var item = upActive = upQueue.shift();
        refreshUploadBar();
        // The check row must exist before media can attach.
        saveRow(item.tr).then(function (ok) {
            var el = rowEls(item.tr);
            if (!ok) {
                upActive = null;
                el.upmsg.innerHTML = '<span style="color:#b3261e">pick a condition first — then retap the photo</span>';
                pumpUploads(); refreshUploadBar();
                return;
            }
            sendUpload(item);
        });
    }

    function sendUpload(item) {
        var tr = item.tr, el = rowEls(tr);
        var label = kindLabel(item.file);
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('op', 'ajax_media');
        fd.append('period', PERIOD);
        fd.append('material_id', tr.dataset.id);
        fd.append('media', item.file, item.file.name || (label.replace(' ', '-') + '.bin'));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/materials/index.php');
        xhr.timeout = 300000;   // 5 min — slow school wifi + 40 MB video
        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round(e.loaded * 100 / e.total);
            el.upmsg.innerHTML = '⬆ Uploading ' + label + ' (' + fmtMB(e.total) + ') — <strong>' + pct + '%</strong>'
                + (upQueue.length ? ' · ' + upQueue.length + ' more waiting' : '')
                + '<span class="mm-upbar"><i style="width:' + pct + '%"></i></span>';
        };
        xhr.onload = function () {
            upActive = null;
            var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status === 200 && d && d.ok) {
                el.upmsg.textContent = '✓ ' + label + ' attached';
                var pill = tr.querySelector('.mm-media-pill');
                var n = tr.querySelector('.mm-media-n');
                n.textContent = String((parseInt(n.textContent, 10) || 0) + 1);
                pill.hidden = false;
                var chip = tr.querySelector('.mm-nophoto');
                if (chip) chip.hidden = true;
                var hint = tr.querySelector('.mm-status strong');
                if (hint) hint.remove();
                pumpUploads(); refreshUploadBar();
            } else {
                uploadFailed(item, (d && d.error) ? d.error : ('server error ' + xhr.status));
            }
        };
        xhr.onerror   = function () { uploadRetryOrFail(item, 'connection lost'); };
        xhr.ontimeout = function () { uploadRetryOrFail(item, 'timed out'); };
        xhr.send(fd);
    }

    function uploadRetryOrFail(item, why) {
        upActive = null;
        if (item.tries < 2) {   // two automatic retries before bothering anyone
            item.tries++;
            rowEls(item.tr).upmsg.textContent = '⚠ ' + why + ' — retrying (' + item.tries + '/2)…';
            upQueue.unshift(item);
            setTimeout(pumpUploads, 2000 * item.tries);
            refreshUploadBar();
            return;
        }
        uploadFailed(item, why);
    }

    function uploadFailed(item, err) {
        upActive = null;
        var el = rowEls(item.tr);
        item.tries = 0;
        el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ ' + escapeHtml(kindLabel(item.file)) + ' NOT uploaded (' + escapeHtml(err) + ')</span> ';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small';
        btn.textContent = 'retry upload';
        btn.addEventListener('click', function () {
            upQueue.unshift(item);
            el.upmsg.textContent = '⬆ retrying…';
            pumpUploads(); refreshUploadBar();
        });
        el.upmsg.appendChild(btn);
        pumpUploads(); refreshUploadBar();
    }

    // Leaving mid-upload loses the file — make the browser ask first.
    window.addEventListener('beforeunload', function (e) {
        if (upActive || upQueue.length) {
            e.preventDefault();
            e.returnValue = 'Photos are still uploading — leaving now will lose them.';
            return e.returnValue;
        }
    });

    function uploadFiles(tr, input) {
        var files = Array.prototype.slice.call(input.files || []);
        input.value = '';   // queue holds the File objects; free the input
        if (files.length) queueUploads(tr, files);
    }

    // Notes autosave on blur (change event covers it via delegation above —
    // textarea 'change' fires on blur when edited).

    // ----- Voice memo (MediaRecorder) + dictation (SpeechRecognition) -----
    // Both feature-detected: unsupported browsers simply never see the
    // buttons, and everything else keeps working.
    var canRecord  = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    var SR         = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (canRecord) form.querySelectorAll('.mm-rec').forEach(function (b) { b.hidden = false; });
    if (SR)        form.querySelectorAll('.mm-dictate').forEach(function (b) { b.hidden = false; });

    var activeRec = null;   // { recorder, stream, tr, btn, timer }

    function stopRecording(save) {
        if (!activeRec) return;
        var a = activeRec;
        activeRec = null;
        clearTimeout(a.timer);
        a.btn.textContent = '⏺';
        a.btn.style.background = '';
        a.saveOnStop = save;
        if (a.recorder.state !== 'inactive') a.recorder.stop();
        a.stream.getTracks().forEach(function (t) { t.stop(); });
    }

    function startRecording(tr, btn) {
        var el = rowEls(tr);
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var mime = '';
            ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].some(function (t) {
                if (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) { mime = t; return true; }
                return false;
            });
            var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            var chunks = [];
            rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            rec.onstop = function () {
                if (!chunks.length) return;
                var type = (rec.mimeType || 'audio/webm').split(';')[0];
                var ext  = type === 'audio/mp4' ? 'm4a' : (type === 'audio/ogg' ? 'ogg' : 'weba');
                var blob = new Blob(chunks, { type: type });
                var f;
                try { f = new File([blob], 'voice-note.' + ext, { type: type }); }
                catch (e) { f = blob; f.name = 'voice-note.' + ext; }
                // Same queue as photos/videos: progress, retries, leave-guard.
                queueUploads(tr, [f]);
            };
            rec.start();
            btn.textContent = '■ stop';
            btn.style.background = '#fbdcd8';
            el.upmsg.textContent = 'Recording… tap ■ to finish (max 60s)';
            activeRec = { recorder: rec, stream: stream, tr: tr, btn: btn,
                          timer: setTimeout(function () { stopRecording(true); }, 60000) };
        }).catch(function () {
            el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ microphone permission needed</span>';
        });
    }

    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn) return;
        var tr = btn.closest('.mm-item');
        if (btn.classList.contains('mm-rec') && tr) {
            if (activeRec && activeRec.btn === btn) { stopRecording(true); }
            else if (!activeRec) { startRecording(tr, btn); }
            return;
        }
        if (btn.classList.contains('mm-dictate') && tr) {
            var el = rowEls(tr);
            var rec;
            try { rec = new SR(); } catch (e) { btn.hidden = true; return; }
            rec.lang = navigator.language || 'en-IN';
            rec.interimResults = false;
            rec.maxAlternatives = 1;
            btn.textContent = '🎤 listening…';
            rec.onresult = function (e) {
                var text = e.results[0][0].transcript;
                var ta = el.detail.querySelector('.mm-notes');
                ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + text;
                saveRow(tr);
            };
            rec.onend = function () { btn.textContent = '🎤 dictate'; };
            rec.onerror = function () {
                btn.textContent = '🎤 dictate';
                el.upmsg.innerHTML = '<span style="color:#b3261e">⚠ could not hear — try again closer to the phone</span>';
            };
            rec.start();
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
