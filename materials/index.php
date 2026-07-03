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

/** Current check per material for a period: [material_id => condition_code]. */
function mm_existing_marks(string $period): array
{
    $st = db()->prepare("SELECT material_id, condition_code FROM mm_condition_checks WHERE period = :p");
    $st->execute([':p' => $period]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['material_id']] = (string)$r['condition_code'];
    return $out;
}

// ---- POST: bulk save --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $conds    = $_POST['cond'] ?? [];
        if (!is_array($conds)) $conds = [];
        $existing = mm_existing_marks($period);
        $saved = 0;
        foreach ($conds as $mid => $code) {
            $mid  = (int)$mid;
            $code = (string)$code;
            if ($mid <= 0 || $code === '' || !isset(mm_conditions()[$code])) continue;
            // Only touch rows that actually changed — a bulk save must not
            // re-stamp every untouched row with today's user + time.
            if (($existing[$mid] ?? '') === $code) continue;
            mm_save_check($mid, $period, $code,
                mm_conditions()[$code]['suggests_replace'], 0, null, (int)$user['id']);
            $saved++;
        }
        flash_set('ok', $saved > 0
            ? "Saved $saved mark" . ($saved === 1 ? '' : 's') . '. Damaged ones are pre-flagged for replacement — open a row to add qty, notes or a photo.'
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
                            <?php else: ?>
                                <img src="<?= e($lmUrl) ?>" alt="" loading="lazy">
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
                        <input type="checkbox" class="mm-needs" <?= !empty($m['needs_replacement']) ? 'checked' : '' ?>>
                        <span>replace</span>
                    </label>
                    <input type="number" class="mm-qty" min="1" max="99" inputmode="numeric"
                           value="<?= max(1, (int)$m['replace_qty']) ?>"
                           style="<?= !empty($m['needs_replacement']) ? '' : 'display:none;' ?>"
                           title="How many to replace">
                    <!-- Direct capture: single-purpose inputs (capture is ignored
                         when combined with multiple/mixed accept, which is why the
                         old field opened the file picker instead of the camera). -->
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="photo" title="Take a photo now">📷</button>
                    <button type="button" class="btn btn-ghost mm-snap" data-kind="video" title="Record a video now">🎥</button>
                    <button type="button" class="btn btn-ghost mm-expand" aria-expanded="false" title="Notes + gallery">▸ more</button>
                    <input type="file" class="mm-cam mm-cam-photo" accept="image/*" capture="environment" hidden>
                    <input type="file" class="mm-cam mm-cam-video" accept="video/*" capture="environment" hidden>
                </div>
                <div class="mm-upmsg muted small"></div>
                <div class="mm-detail" hidden>
                    <label class="small mm-noteswrap">
                        Notes <span class="muted">(where's the mould, which part peeled…)</span>
                        <textarea class="mm-notes" rows="2"><?= e((string)($m['notes'] ?? '')) ?></textarea>
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
        <span id="pendingCount" class="muted small">Changes save automatically — this button is the backup.</span>
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

    function saveRow(tr) {
        var el = rowEls(tr);
        if (!el.cond || el.cond.value === '') {
            el.status.innerHTML = '<span style="color:#b3261e">pick a condition</span>';
            return Promise.resolve(false);
        }
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('op', 'ajax_mark');
        fd.append('period', PERIOD);
        fd.append('material_id', tr.dataset.id);
        fd.append('condition_code', el.cond.value);
        if (el.needs.checked) fd.append('needs_replacement', '1');
        fd.append('replace_qty', el.needs.checked ? (el.qty.value || '1') : '0');
        fd.append('notes', el.detail ? el.detail.querySelector('.mm-notes').value : '');

        el.status.textContent = 'Saving…';
        return fetch('/materials/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'save failed');
                tr.dataset.saved = el.cond.value;
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

    // Undo browser form-restore: the server's saved value wins on load.
    form.querySelectorAll('.mm-item').forEach(function (item) {
        var sel = item.querySelector('.mm-cond');
        if (sel && sel.value !== item.dataset.saved) sel.value = item.dataset.saved || '';
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

    function uploadFiles(tr, input) {
        var el  = rowEls(tr);
        var msg = el.upmsg;
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) return;
        // The check row must exist first — save, then upload sequentially.
        saveRow(tr).then(function (ok) {
            if (!ok) { msg.textContent = 'pick a condition first'; return; }
            var done = 0;
            function next() {
                if (!files.length) {
                    msg.textContent = '✓ ' + done + ' file' + (done === 1 ? '' : 's') + ' attached';
                    var pill = tr.querySelector('.mm-media-pill');
                    var n = tr.querySelector('.mm-media-n');
                    n.textContent = String((parseInt(n.textContent, 10) || 0) + done);
                    pill.hidden = false;
                    var chip = tr.querySelector('.mm-nophoto');
                    if (chip) chip.hidden = true;
                    var hint = tr.querySelector('.mm-status strong');
                    if (hint) hint.remove();
                    input.value = '';
                    return;
                }
                var f  = files.shift();
                var fd = new FormData();
                fd.append('_csrf', CSRF);
                fd.append('op', 'ajax_media');
                fd.append('period', PERIOD);
                fd.append('material_id', tr.dataset.id);
                fd.append('media', f);
                msg.textContent = 'Uploading ' + f.name + '…';
                fetch('/materials/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) throw new Error(d.error || 'upload failed');
                        done++;
                        next();
                    })
                    .catch(function (err) {
                        msg.innerHTML = '<span style="color:#b3261e">⚠ ' + escapeHtml(err.message) + '</span>';
                    });
            }
            next();
        });
    }

    // Notes autosave on blur (change event covers it via delegation above —
    // textarea 'change' fires on blur when edited).
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
