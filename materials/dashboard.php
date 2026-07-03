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

// Per-shelf progress.
$shelves = db()->prepare("
    SELECT m.location,
           COUNT(*) AS total,
           SUM(c.id IS NOT NULL) AS checked,
           SUM(COALESCE(c.needs_replacement, 0)) AS to_replace
    FROM mm_materials m
    LEFT JOIN mm_condition_checks c ON c.material_id = m.id AND c.period = :p
    WHERE m.is_active = 1
    GROUP BY m.location
    ORDER BY MIN(m.sort_order)
");
$shelves->execute([':p' => $period]);
$shelves = $shelves->fetchAll();

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
$gallery = [];
foreach ($mediaRows as $r) $gallery[$r['material_id']][] = $r;

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

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Per-shelf progress</h2>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Shelf / group</th><th>Checked</th><th>To replace</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shelves as $s): $pct = $s['total'] > 0 ? (int)round((int)$s['checked'] * 100 / (int)$s['total']) : 0; ?>
            <tr>
                <td><?= e($s['location']) ?></td>
                <td><?= (int)$s['checked'] ?>/<?= (int)$s['total'] ?>
                    <div class="mmd-bar" style="max-width:120px;"><i style="width:<?= $pct ?>%"></i></div></td>
                <td><?= (int)$s['to_replace'] > 0 ? '<strong style="color:#b3261e">' . (int)$s['to_replace'] . '</strong>' : '—' ?></td>
                <td><a class="btn btn-ghost small" href="index.php?period=<?= e($period) ?>&loc=<?= e(urlencode($s['location'])) ?>">open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2 style="margin-top:0;">Photos &amp; videos this month <span class="muted small">(<?= $stats['media'] ?>)</span></h2>
    <?php if (!$gallery): ?>
        <p class="muted">No photos or videos yet — take them from the audit board (📷 / 🎥 on each material).</p>
    <?php else: ?>
        <?php foreach ($gallery as $items): $first = $items[0]; ?>
            <div style="margin-bottom:1rem;">
                <h3 style="margin:.2rem 0 .4rem;">
                    <?= e($first['material']) ?>
                    <span class="muted small">· <?= e($first['location']) ?></span>
                    <span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($first['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($first['condition_code'])) ?></span>
                    <?php if ($first['needs_replacement']): ?>
                        <span class="pill small" style="background:#fbdcd8;color:#8b1c14;">replace ×<?= max(1, (int)$first['replace_qty']) ?></span>
                    <?php endif; ?>
                </h3>
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
    <?php endif; ?>
</section>

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
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
