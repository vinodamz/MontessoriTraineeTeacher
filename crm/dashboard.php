<?php
/**
 * crm/dashboard.php — interactive admissions dashboard.
 *
 * Charts (Chart.js via jsdelivr, same CDN pattern as Sortable/Tesseract):
 *   - Funnel: open inquiries per pipeline stage (click a bar → board)
 *   - Inquiries vs enrolments per month (selectable 3/6/12-month window)
 *   - Where families come from (source/campaign doughnut)
 *   - Why we lose (lost reasons doughnut)
 * KPI tiles: new this month, conversion rate, median days to enrol,
 * weighted pipeline value.
 *
 * Read-only; every number deep-links into the page that owns it.
 * Access: anyone with the crm module (admins implicitly).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

// Month window for the trend chart: 3 / 6 / 12 (default 6).
$months = (int)($_GET['months'] ?? 6);
if (!in_array($months, [3, 6, 12], true)) $months = 6;
$windowStart = (new DateTime('first day of this month'))
    ->modify('-' . ($months - 1) . ' months')->format('Y-m-d');

// ---- KPI tiles ---------------------------------------------------------------
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d');

$newThisMonth = 0;
try {
    $st = db()->prepare("SELECT COUNT(*) FROM inquiry_families WHERE created_at >= :ms");
    $st->execute([':ms' => $monthStart]);
    $newThisMonth = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// Conversion: of inquiries that CLOSED (enrolled or lost), what share enrolled?
$convRate = null; $nEnrolled = 0; $nLost = 0;
try {
    $r = db()->query("
        SELECT
          SUM(CASE WHEN status = 'enrolled' THEN 1 ELSE 0 END) AS n_enr,
          SUM(CASE WHEN status = 'lost'     THEN 1 ELSE 0 END) AS n_lost
        FROM inquiry_families
    ")->fetch();
    $nEnrolled = (int)$r['n_enr'];
    $nLost     = (int)$r['n_lost'];
    $closed    = $nEnrolled + $nLost;
    if ($closed > 0) $convRate = round(100 * $nEnrolled / $closed);
} catch (Throwable $e) {}

// Median days from inquiry to enrolment (MySQL has no MEDIAN — fetch + sort).
$medianDays = null;
try {
    $days = db()->query("
        SELECT DATEDIFF(enrolled_at, created_at) AS d
        FROM inquiry_families
        WHERE status = 'enrolled' AND enrolled_at IS NOT NULL
          AND DATEDIFF(enrolled_at, created_at) >= 0
        ORDER BY d
    ")->fetchAll(PDO::FETCH_COLUMN);
    if ($days) $medianDays = (int)$days[intdiv(count($days) - 1, 2)];
} catch (Throwable $e) {}

$projection = ['weighted' => 0.0, 'count' => 0];
try { $projection = crm_revenue_projection(); } catch (Throwable $e) {}

// ---- Funnel: open inquiries per stage ------------------------------------------
$funnelLabels = []; $funnelCounts = []; $funnelCodes = [];
try {
    $byStatus = [];
    foreach (db()->query("SELECT status, COUNT(*) AS n FROM inquiry_families GROUP BY status") as $r) {
        $byStatus[$r['status']] = (int)$r['n'];
    }
    foreach (crm_pipeline_statuses() as $code => $label) {
        $funnelCodes[]  = $code;
        $funnelLabels[] = $label;
        $funnelCounts[] = $byStatus[$code] ?? 0;
    }
} catch (Throwable $e) {}

// ---- Trend: created vs enrolled per month ---------------------------------------
$trendLabels = []; $trendCreated = []; $trendEnrolled = [];
try {
    $created = []; $enrolled = [];
    $st = db()->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
        FROM inquiry_families WHERE created_at >= :ws GROUP BY ym
    ");
    $st->execute([':ws' => $windowStart]);
    foreach ($st as $r) $created[$r['ym']] = (int)$r['n'];

    $st = db()->prepare("
        SELECT DATE_FORMAT(enrolled_at, '%Y-%m') AS ym, COUNT(*) AS n
        FROM inquiry_families
        WHERE enrolled_at IS NOT NULL AND enrolled_at >= :ws
        GROUP BY ym
    ");
    $st->execute([':ws' => $windowStart]);
    foreach ($st as $r) $enrolled[$r['ym']] = (int)$r['n'];

    $cursor = new DateTime($windowStart);
    for ($i = 0; $i < $months; $i++) {
        $ym = $cursor->format('Y-m');
        $trendLabels[]   = $cursor->format('M y');
        $trendCreated[]  = $created[$ym]  ?? 0;
        $trendEnrolled[] = $enrolled[$ym] ?? 0;
        $cursor->modify('+1 month');
    }
} catch (Throwable $e) {}

// ---- Sources ---------------------------------------------------------------------
$srcLabels = []; $srcCounts = [];
try {
    $rows = db()->query("
        SELECT COALESCE(NULLIF(TRIM(COALESCE(c.name, f.source)), ''), 'Unknown') AS src, COUNT(*) AS n
        FROM inquiry_families f
        LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
        GROUP BY src ORDER BY n DESC LIMIT 8
    ")->fetchAll();
    foreach ($rows as $r) { $srcLabels[] = $r['src']; $srcCounts[] = (int)$r['n']; }
} catch (Throwable $e) {}

// ---- Lost reasons -------------------------------------------------------------
$lostLabels = []; $lostCounts = [];
try {
    $rows = db()->query("
        SELECT COALESCE(NULLIF(lost_reason, ''), 'unspecified') AS reason, COUNT(*) AS n
        FROM inquiry_families WHERE status = 'lost'
        GROUP BY reason ORDER BY n DESC LIMIT 8
    ")->fetchAll();
    foreach ($rows as $r) {
        $lostLabels[] = crm_lost_reason_label($r['reason'] === 'unspecified' ? null : $r['reason']) ?: 'Unspecified';
        $lostCounts[] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$pageTitle = 'Admissions dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Admissions dashboard</h1>
        <p class="muted">The funnel at a glance — every number links to where you act on it.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">← Pipeline board</a>
        <a class="btn" href="/crm/funnel.php">Detailed funnel report</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
    <li><div class="admin-tile tile-nav">
        <span class="tile-label">New this month</span>
        <span class="tile-value"><?= $newThisMonth ?></span>
        <span class="tile-sub">inquiries created</span>
    </div></li>
    <li><div class="admin-tile <?= ($convRate ?? 0) >= 50 ? 'tile-ok' : '' ?>">
        <span class="tile-label">Conversion</span>
        <span class="tile-value"><?= $convRate === null ? '—' : $convRate . '%' ?></span>
        <span class="tile-sub"><?= $nEnrolled ?> enrolled · <?= $nLost ?> lost</span>
    </div></li>
    <li><div class="admin-tile">
        <span class="tile-label">Median time to enrol</span>
        <span class="tile-value"><?= $medianDays === null ? '—' : $medianDays . 'd' ?></span>
        <span class="tile-sub">inquiry → enrolled</span>
    </div></li>
    <li><div class="admin-tile tile-ok">
        <span class="tile-label">Weighted pipeline</span>
        <span class="tile-value">₹<?= number_format((float)$projection['weighted'], 0) ?>/mo</span>
        <span class="tile-sub"><?= (int)$projection['count'] ?> open inquiries</span>
    </div></li>
</ul>

<div class="card">
    <h3 style="margin-top:0;">Pipeline right now <span class="muted small">· click a bar to open that stage on the board</span></h3>
    <canvas id="chart-funnel" height="110"></canvas>
</div>

<div class="card">
    <h3 style="margin-top:0; display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;">
        Inquiries vs enrolments
        <span style="margin-left:auto; display:flex; gap:.3rem;">
            <?php foreach ([3, 6, 12] as $m): ?>
                <a class="btn <?= $months === $m ? 'btn-primary' : 'btn-ghost' ?>" style="padding:.25rem .7rem; font-size:.85rem;"
                   href="/crm/dashboard.php?months=<?= $m ?>"><?= $m ?>m</a>
            <?php endforeach; ?>
        </span>
    </h3>
    <canvas id="chart-trend" height="110"></canvas>
</div>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 320px;">
        <h3 style="margin-top:0;">Where families come from</h3>
        <?php if ($srcCounts): ?>
            <canvas id="chart-sources" height="220"></canvas>
        <?php else: ?>
            <p class="muted">No source data yet.</p>
        <?php endif; ?>
    </div>
    <div class="card" style="flex: 1 1 320px;">
        <h3 style="margin-top:0;">Why we lose inquiries</h3>
        <?php if ($lostCounts): ?>
            <canvas id="chart-lost" height="220"></canvas>
        <?php else: ?>
            <p class="muted">No lost inquiries — nothing to chart. 🎉</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
    const PINK = '#e91e63', PINK_SOFT = 'rgba(233,30,99,.18)', GREEN = '#66bb6a',
          PALETTE = ['#e91e63','#66bb6a','#42a5f5','#f5b342','#ab47bc','#26a69a','#ef5350','#8d6e63'];
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    // Funnel — horizontal bars, click-through to the board.
    const funnelCodes = <?= json_encode($funnelCodes) ?>;
    const elFunnel = document.getElementById('chart-funnel');
    if (elFunnel) {
        const ch = new Chart(elFunnel, {
            type: 'bar',
            data: {
                labels: <?= json_encode($funnelLabels) ?>,
                datasets: [{ data: <?= json_encode($funnelCounts) ?>, backgroundColor: PALETTE, borderRadius: 5 }]
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { precision: 0 } } },
                onClick: (evt, els) => {
                    if (els.length) location.href = '/crm/index.php#col-' + funnelCodes[els[0].index];
                },
                onHover: (evt, els) => { evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; }
            }
        });
    }

    // Trend — inquiries (bars) vs enrolments (line).
    const elTrend = document.getElementById('chart-trend');
    if (elTrend) {
        new Chart(elTrend, {
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [
                    { type: 'bar',  label: 'New inquiries', data: <?= json_encode($trendCreated) ?>,
                      backgroundColor: PINK_SOFT, borderColor: PINK, borderWidth: 1.5, borderRadius: 5 },
                    { type: 'line', label: 'Enrolled', data: <?= json_encode($trendEnrolled) ?>,
                      borderColor: GREEN, backgroundColor: GREEN, tension: .3, pointRadius: 4 }
                ]
            },
            options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    // Doughnuts.
    const mkDoughnut = (id, labels, data) => {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: PALETTE }] },
            options: { plugins: { legend: { position: 'right' } }, maintainAspectRatio: false }
        });
    };
    mkDoughnut('chart-sources', <?= json_encode($srcLabels) ?>, <?= json_encode($srcCounts) ?>);
    mkDoughnut('chart-lost',    <?= json_encode($lostLabels) ?>, <?= json_encode($lostCounts) ?>);
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
