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
                mm_conditions()[$code]['suggests_replace'], 0, '', (int)$user['id']);
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
            mm_save_check($mid, $period, $code, $needs, $qty,
                          trim((string)($_POST['notes'] ?? '')), (int)$user['id']);
            echo json_encode([
                'ok'    => true,
                'label' => mm_condition_label($code),
                'tone'  => mm_condition_tone($code),
                'needs' => $needs, 'qty' => $qty,
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
            mm_save_check((int)$mid, $period, 'good', false, 0, '', (int)$user['id']);
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
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th style="width:30%">Material</th><th>Condition</th><th>Replace?</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null;
                $mid = (int)$m['id'];
                $suggests = $marked ? (mm_conditions()[$m['condition_code']]['suggests_replace'] ?? false) : false;
            ?>
                <tr id="m<?= $mid ?>" class="mm-row" data-id="<?= $mid ?>" data-saved="<?= e((string)$m['condition_code']) ?>">
                    <td>
                        <strong><?= e($m['name']) ?></strong>
                        <span class="pill small mm-media-pill" title="Photos/videos attached" <?= (int)$m['media_count'] > 0 ? '' : 'hidden' ?>>📎 <span class="mm-media-n"><?= (int)$m['media_count'] ?></span></span>
                    </td>
                    <td>
                        <select name="cond[<?= $mid ?>]" class="mm-cond">
                            <option value="">— pick —</option>
                            <?php foreach (mm_conditions() as $code => $meta): ?>
                                <option value="<?= e($code) ?>" <?= $m['condition_code'] === $code ? 'selected' : '' ?>
                                        data-suggests="<?= $meta['suggests_replace'] ? '1' : '0' ?>"><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="white-space:nowrap;">
                        <label class="checkbox small" style="display:inline-flex; align-items:center; gap:.25rem;">
                            <input type="checkbox" class="mm-needs" <?= !empty($m['needs_replacement']) ? 'checked' : '' ?>>
                            <span>replace</span>
                        </label>
                        <input type="number" class="mm-qty" min="1" max="99"
                               value="<?= max(1, (int)$m['replace_qty']) ?>"
                               style="width:3.6rem; margin-left:.3rem; <?= !empty($m['needs_replacement']) ? '' : 'display:none;' ?>"
                               title="How many to replace">
                    </td>
                    <td class="mm-status muted small" style="min-width:9rem;">
                        <?php if ($marked): ?>
                            <span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($m['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($m['condition_code'])) ?></span><br>
                            ✓ <?= e($m['checked_by'] ?? 'Unknown') ?> · <?= e(date('j M, g:ia', strtotime($m['checked_at']))) ?>
                        <?php else: ?>
                            <span style="color:#b3261e">not checked</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-ghost small mm-expand" aria-expanded="false" title="Notes + photo">▸ note/photo</button>
                    </td>
                </tr>
                <tr class="mm-detail" hidden>
                    <td colspan="5" style="background:#fafaf7;">
                        <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:start; padding:.3rem 0;">
                            <label style="flex:2 1 260px;" class="small">
                                Notes <span class="muted">(where's the mould, which part peeled…)</span>
                                <textarea class="mm-notes" rows="2" style="width:100%;"><?= e((string)($m['notes'] ?? '')) ?></textarea>
                            </label>
                            <label style="flex:1 1 220px;" class="small">
                                Photo / video
                                <input type="file" class="mm-file" accept="image/*,video/*" capture="environment" multiple>
                                <span class="mm-upmsg muted small"></span>
                            </label>
                            <a class="btn btn-ghost small" style="align-self:center;" href="check.php?id=<?= $mid ?>&period=<?= e($period) ?>">full history →</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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

    function rowEls(tr) {
        return {
            cond:  tr.querySelector('.mm-cond'),
            needs: tr.querySelector('.mm-needs'),
            qty:   tr.querySelector('.mm-qty'),
            status:tr.querySelector('.mm-status'),
            detail:tr.nextElementSibling && tr.nextElementSibling.classList.contains('mm-detail') ? tr.nextElementSibling : null
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
                el.status.innerHTML =
                    '<span class="pill small" style="background:' + toneBg + '">' + escapeHtml(d.label) + '</span><br>' +
                    '✓ ' + escapeHtml(d.by) + ' · ' + escapeHtml(d.at);
                el.status.style.color = '#2d6526';
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
    form.querySelectorAll('tr.mm-row').forEach(function (tr) {
        var sel = tr.querySelector('.mm-cond');
        if (sel && sel.value !== tr.dataset.saved) sel.value = tr.dataset.saved || '';
    });

    form.addEventListener('change', function (ev) {
        var t  = ev.target;
        var tr = t.closest('tr.mm-row') || (t.closest('tr.mm-detail') && t.closest('tr.mm-detail').previousElementSibling);
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
        if (t.classList.contains('mm-file')) { uploadFiles(tr, t); return; }
        saveRow(tr);
    });

    // Retry buttons (delegated — they're created dynamically).
    form.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('mm-retry')) {
            var tr = ev.target.closest('tr.mm-row') || ev.target.closest('td').closest('tr');
            if (tr && !tr.classList.contains('mm-row')) tr = tr.previousElementSibling;
            if (tr) saveRow(tr);
        }
        if (ev.target.classList.contains('mm-expand')) {
            var tr = ev.target.closest('tr.mm-row');
            var det = tr.nextElementSibling;
            var open = det.hidden;
            det.hidden = !open;
            ev.target.textContent = (open ? '▾' : '▸') + ' note/photo';
            ev.target.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    });

    function uploadFiles(tr, input) {
        var el  = rowEls(tr);
        var msg = el.detail.querySelector('.mm-upmsg');
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
