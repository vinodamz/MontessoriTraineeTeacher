<?php
/**
 * materials/dashboard.php — live overview of a month's condition audit.
 *
 * Summary tiles (auto-refresh every 30s via a tiny JSON poll), a per-shelf
 * progress table, a condition breakdown, and the full photo/video gallery
 * for the month grouped by material. Export buttons: CSV (list only) and
 * ZIP (report + every photo/video, foldered by shelf/material).
 *
 *   GET ?period=YYYY-MM
 *   GET ?period=YYYY-MM&format=stats   JSON tile numbers (the live poll)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

/** Tile numbers for a period — shared by the page render and the live poll. */
function mm_dash_stats(string $period): array
{
    $one = function (string $sql, array $p = []) use ($period): int {
        $st = db()->prepare($sql);
        $st->execute([':p' => $period] + $p);
        return (int)$st->fetchColumn();
    };
    $total   = (int)db()->query("SELECT COUNT(*) FROM mm_materials WHERE is_active = 1")->fetchColumn();
    $checked = $one("SELECT COUNT(*) FROM mm_condition_checks c JOIN mm_materials m ON m.id = c.material_id AND m.is_active = 1 WHERE c.period = :p");
    $replace = $one("SELECT COUNT(*) FROM mm_condition_checks c JOIN mm_materials m ON m.id = c.material_id AND m.is_active = 1 WHERE c.period = :p AND c.needs_replacement = 1");
    $media   = $one("SELECT COUNT(*) FROM mm_condition_media md JOIN mm_condition_checks c ON c.id = md.check_id WHERE c.period = :p");

    $byCond = [];
    $st = db()->prepare("
        SELECT c.condition_code, COUNT(*) AS n
        FROM mm_condition_checks c
        JOIN mm_materials m ON m.id = c.material_id AND m.is_active = 1
        WHERE c.period = :p
        GROUP BY c.condition_code
    ");
    $st->execute([':p' => $period]);
    foreach ($st as $r) $byCond[$r['condition_code']] = (int)$r['n'];

    return [
        'total' => $total, 'checked' => $checked,
        'pending' => max(0, $total - $checked),
        'pct' => $total > 0 ? (int)round($checked * 100 / $total) : 0,
        'replace' => $replace, 'media' => $media, 'by_condition' => $byCond,
    ];
}

if (($_GET['format'] ?? '') === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(mm_dash_stats($period), JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = mm_dash_stats($period);

// Per-shelf progress, most urgent first (unchecked count, then photo gaps).
$shelves = mm_shelf_priorities($period);
// Flagged-for-replacement materials with no photo — Kreedo wants proof.
$gaps = mm_evidence_gaps($period);

// Media gallery for the month, grouped by material.
$mediaRows = db()->prepare("
    SELECT md.id, md.kind, md.mime_type, md.uploaded_at,
           m.id AS material_id, m.name AS material, m.location,
           c.condition_code, c.needs_replacement, c.replace_qty, c.notes,
           u.name AS by_name
    FROM mm_condition_media md
    JOIN mm_condition_checks c ON c.id = md.check_id
    JOIN mm_materials m ON m.id = c.material_id
    LEFT JOIN users u ON u.id = md.uploaded_by_user_id
    WHERE c.period = :p
    ORDER BY m.location, m.sort_order, m.name, md.uploaded_at
");
$mediaRows->execute([':p' => $period]);
// Group: shelf/location → material → its media items.
$gallery = [];
foreach ($mediaRows as $r) $gallery[$r['location']][$r['material_id']][] = $r;

$periods = mm_period_options();
$TONE_BG = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];

$pageTitle = 'Materials dashboard — ' . mm_period_label($period);
require __DIR__ . '/../includes/header.php';
?>

<style>
.mmd-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .7rem; margin-bottom: 1rem; }
.mmd-tile  { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: .7rem .9rem; }
.mmd-tile .v { font-size: 1.6rem; font-weight: 700; display: block; }
.mmd-tile .l { color: #777; font-size: .8rem; }
.mmd-bar   { height: 10px; border-radius: 6px; background: #eee; overflow: hidden; margin-top: .3rem; }
.mmd-bar i { display: block; height: 100%; background: #5ba547; }
.mmd-gal   { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .7rem; }
.mmd-card  { border: 1px solid #eee; border-radius: 10px; overflow: hidden; background: #fff; }
.mmd-card img, .mmd-card video { width: 100%; height: 120px; object-fit: cover; display: block; background: #000; }
.mmd-card .cap { padding: .35rem .5rem; font-size: .75rem; color: #555; }
@media (max-width: 640px) { .mmd-gal { grid-template-columns: repeat(auto-fill, minmax(46%, 1fr)); } }
</style>

<div class="page-head">
    <div>
        <h1>Materials dashboard</h1>
        <p class="muted"><strong><?= e(mm_period_label($period)) ?></strong> · tiles refresh live every 30s</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="index.php?period=<?= e($period) ?>">Audit board</a>
        <a class="btn" href="replacement.php?period=<?= e($period) ?>&format=csv">CSV</a>
        <a class="btn btn-primary" href="export.php?period=<?= e($period) ?>" title="Report + every photo and video, foldered by shelf">Export ZIP (with photos/videos)</a>
    </div>
</div>

<form class="filters no-print" method="get" style="margin-bottom:1rem;">
    <label>Month
        <select name="period" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= e(mm_period_label($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <noscript><button class="btn">Go</button></noscript>
</form>

<div class="mmd-tiles" id="mmdTiles">
    <div class="mmd-tile"><span class="v" data-k="checked"><?= $stats['checked'] ?></span><span class="l">checked of <span data-k="total"><?= $stats['total'] ?></span></span>
        <div class="mmd-bar"><i data-k="pctbar" style="width:<?= $stats['pct'] ?>%"></i></div></div>
    <div class="mmd-tile"><span class="v" data-k="pending"><?= $stats['pending'] ?></span><span class="l">still to check</span></div>
    <div class="mmd-tile"><span class="v" style="color:#b3261e" data-k="replace"><?= $stats['replace'] ?></span><span class="l">to replace (Kreedo)</span></div>
    <div class="mmd-tile"><span class="v" data-k="media"><?= $stats['media'] ?></span><span class="l">photos / videos</span></div>
</div>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Condition breakdown</h2>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;" id="mmdConds">
        <?php foreach (mm_conditions() as $code => $meta): $n = $stats['by_condition'][$code] ?? 0; ?>
            <span class="pill" style="background:<?= $TONE_BG[$meta['tone']] ?? '#eee' ?>" data-cond="<?= e($code) ?>">
                <?= e($meta['label']) ?> · <strong data-n><?= $n ?></strong>
            </span>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($gaps): ?>
<section class="card" style="margin-bottom:1rem; border-left:4px solid #b3261e;">
    <h2 style="margin-top:0;">⚠ Flagged for replacement, but no photo (<?= count($gaps) ?>)</h2>
    <p class="muted small">Kreedo will want proof — take a photo of these before sending the list.</p>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Shelf</th><th>Material</th><th>Condition</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($gaps as $g): ?>
            <tr>
                <td><?= e($g['location']) ?></td>
                <td><strong><?= e($g['name']) ?></strong></td>
                <td><span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($g['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($g['condition_code'])) ?></span></td>
                <td><a class="btn btn-ghost small" href="index.php?period=<?= e($period) ?>&q=<?= e(urlencode($g['name'])) ?>">open → 📷</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">What's next <span class="muted small">· shelves ranked by what still needs doing</span></h2>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th></th><th>Shelf / group</th><th>Checked</th><th>Still to do</th><th></th></tr></thead>
        <tbody>
        <?php $rank = 0; foreach ($shelves as $s):
            $pct = (int)$s['total'] > 0 ? (int)round((int)$s['checked'] * 100 / (int)$s['total']) : 0;
            $todo = [];
            if ((int)$s['unchecked'] > 0) $todo[] = '<strong>' . (int)$s['unchecked'] . ' unchecked</strong>';
            if ((int)$s['gaps'] > 0)      $todo[] = '<strong style="color:#b3261e">' . (int)$s['gaps'] . ' flagged need a photo 📷</strong>';
            if ((int)$s['flagged'] > 0)   $todo[] = (int)$s['flagged'] . ' to replace';
            $isDone = (int)$s['unchecked'] === 0 && (int)$s['gaps'] === 0;
            $rank++;
        ?>
            <tr style="<?= $isDone ? 'opacity:.6;' : '' ?>">
                <td><?= (!$isDone && $rank === 1) ? '<span class="pill small" style="background:#fcebc6;color:#6c4612;">next ➜</span>' : '' ?></td>
                <td><?= e($s['location']) ?></td>
                <td><?= (int)$s['checked'] ?>/<?= (int)$s['total'] ?>
                    <div class="mmd-bar" style="max-width:120px;"><i style="width:<?= $pct ?>%"></i></div></td>
                <td class="small"><?= $todo ? implode(' · ', $todo) : '✓ all done' ?></td>
                <td><a class="btn btn-ghost small" href="index.php?period=<?= e($period) ?>&loc=<?= e(urlencode($s['location'])) ?><?= (int)$s['unchecked'] > 0 ? '&only=pending' : '' ?>">open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2 style="margin-top:0;">Photos &amp; videos this month <span class="muted small">(<?= $stats['media'] ?>)</span></h2>
    <p class="muted small">Review the evidence and correct the verdict right here — condition and replacement changes save instantly.</p>
    <?php if (!$gallery): ?>
        <p class="muted">No photos or videos yet — take them from the audit board (📷 / 🎥 on each material).</p>
    <?php else: ?>
        <?php foreach ($gallery as $location => $mats): ?>
            <h3 style="margin:1rem 0 .5rem; padding-bottom:.25rem; border-bottom:2px solid #eee;"><?= e($location) ?></h3>
            <?php foreach ($mats as $matId => $items): $first = $items[0]; ?>
            <div class="mmd-mat" data-id="<?= (int)$matId ?>" style="margin:0 0 1rem .2rem;">
                <div class="mmd-mathead" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:.4rem;">
                    <strong><?= e($first['material']) ?></strong>
                    <select class="mmd-cond">
                        <?php foreach (mm_conditions() as $code => $meta): ?>
                            <option value="<?= e($code) ?>" <?= $first['condition_code'] === $code ? 'selected' : '' ?>
                                    data-suggests="<?= $meta['suggests_replace'] ? '1' : '0' ?>"><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="display:inline-flex; align-items:center; gap:.25rem; font-size:.85rem; white-space:nowrap;">
                        <input type="checkbox" class="mmd-needs" <?= !empty($first['needs_replacement']) ? 'checked' : '' ?>>
                        <span>replace</span>
                    </label>
                    <input type="number" class="mmd-qty" min="1" max="99" inputmode="numeric"
                           value="<?= max(1, (int)$first['replace_qty']) ?>"
                           style="width:4rem; <?= !empty($first['needs_replacement']) ? '' : 'display:none;' ?>">
                    <span class="mmd-rowstatus muted small"></span>
                </div>
                <?php if (trim((string)$first['notes']) !== ''): ?>
                    <p class="muted small" style="margin:.1rem 0 .4rem;">“<?= e($first['notes']) ?>”</p>
                <?php endif; ?>
                <div class="mmd-gal">
                    <?php foreach ($items as $md): $url = '/materials/media.php?id=' . (int)$md['id']; ?>
                        <div class="mmd-card">
                            <?php if ($md['kind'] === 'video'): ?>
                                <video src="<?= e($url) ?>" controls preload="metadata"></video>
                            <?php else: ?>
                                <a href="<?= e($url) ?>" target="_blank"><img src="<?= e($url) ?>" alt="<?= e($first['material']) ?>" loading="lazy"></a>
                            <?php endif; ?>
                            <div class="cap"><?= $md['kind'] === 'video' ? '🎥' : '📷' ?> <?= e($md['by_name'] ?? 'Unknown') ?> · <?= e(date('j M, g:ia', strtotime($md['uploaded_at']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php if ($gallery): ?>
<input type="hidden" id="mmdCsrf" value="<?= e(csrf_token()) ?>">
<?php endif; ?>

<script>
// Live tiles: poll the JSON stats every 30s. The gallery refreshes on reload
// (streaming new thumbnails in-place isn't worth the complexity here).
(function () {
    if (!window.fetch) return;
    function tick() {
        fetch('/materials/dashboard.php?format=stats&period=<?= e(urlencode($period)) ?>', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                ['checked','total','pending','replace','media'].forEach(function (k) {
                    var el = document.querySelector('[data-k="' + k + '"]');
                    if (el) el.textContent = d[k];
                });
                var bar = document.querySelector('[data-k="pctbar"]');
                if (bar) bar.style.width = d.pct + '%';
                document.querySelectorAll('#mmdConds [data-cond]').forEach(function (p) {
                    var n = p.querySelector('[data-n]');
                    if (n) n.textContent = (d.by_condition && d.by_condition[p.dataset.cond]) || 0;
                });
            })
            .catch(function () { /* transient — next tick retries */ });
    }
    setInterval(tick, 30000);
})();

// Gallery verdict editing: change condition / replace / qty next to the
// photos → saves through the same ajax_mark endpoint the audit board uses.
(function () {
    var csrf = document.getElementById('mmdCsrf');
    if (!csrf || !window.fetch) return;
    document.querySelectorAll('.mmd-mat').forEach(function (mat) {
        mat.addEventListener('change', function (ev) {
            var cond   = mat.querySelector('.mmd-cond');
            var needs  = mat.querySelector('.mmd-needs');
            var qty    = mat.querySelector('.mmd-qty');
            var status = mat.querySelector('.mmd-rowstatus');
            if (ev.target === cond) {
                var opt = cond.options[cond.selectedIndex];
                needs.checked = opt && opt.dataset.suggests === '1';
            }
            qty.style.display = needs.checked ? '' : 'none';

            var fd = new FormData();
            fd.append('_csrf', csrf.value);
            fd.append('op', 'ajax_mark');
            fd.append('period', '<?= e($period) ?>');
            fd.append('material_id', mat.dataset.id);
            fd.append('condition_code', cond.value);
            if (needs.checked) fd.append('needs_replacement', '1');
            fd.append('replace_qty', needs.checked ? (qty.value || '1') : '0');
            // notes deliberately not sent from here — ajax_mark would blank
            // them; the board/detail page owns notes. (Server keeps notes
            // only when the field is posted, see index.php ajax_mark.)
            status.textContent = 'Saving…';
            fetch('/materials/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) throw new Error(d.error || 'failed');
                    status.textContent = '✓ saved ' + d.at;
                    status.style.color = '#2d6526';
                })
                .catch(function (e) {
                    status.textContent = '⚠ not saved — try again';
                    status.style.color = '#b3261e';
                });
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
