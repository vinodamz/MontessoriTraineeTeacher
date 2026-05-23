<?php
/**
 * crm/funnel.php — admissions conversion funnel report.
 *
 * Shows where inquiries are getting stuck and which sources convert
 * best. Date range filter (last 30 / 60 / 90 days + custom). Counts
 * are based on the current status of each inquiry — an inquiry counts
 * as "reached" a stage if its current stage's display_order is at or
 * past that stage's display_order. This is a simplification (a child
 * who was moved back is shown at the current stage only); for a
 * stage-by-stage transition log see /crm/audit.php filtered by
 * status_changed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

// ---- Range filter --------------------------------------------------------
$range = $_GET['range'] ?? '30';
$today = new DateTime('today');
$to    = (clone $today)->modify('+1 day')->format('Y-m-d'); // exclusive upper bound
switch ($range) {
    case '60':    $from = (clone $today)->modify('-60 days')->format('Y-m-d');   $rangeLabel = 'Last 60 days'; break;
    case '90':    $from = (clone $today)->modify('-90 days')->format('Y-m-d');   $rangeLabel = 'Last 90 days'; break;
    case '180':   $from = (clone $today)->modify('-180 days')->format('Y-m-d');  $rangeLabel = 'Last 6 months'; break;
    case 'all':   $from = '1970-01-01';                                          $rangeLabel = 'All time'; break;
    case 'custom':
        $from = $_GET['from'] ?? $today->format('Y-m-d');
        $toCustom = $_GET['to'] ?? $today->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))     $from = (clone $today)->modify('-30 days')->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toCustom)) $toCustom = $today->format('Y-m-d');
        $to = (new DateTime($toCustom))->modify('+1 day')->format('Y-m-d');
        $rangeLabel = "$from to $toCustom";
        break;
    case '30':
    default:
        $from = (clone $today)->modify('-30 days')->format('Y-m-d');
        $rangeLabel = 'Last 30 days';
        $range = '30';
        break;
}

$pdo = db();

// ---- Pull active stages once for ordering -------------------------------
$stages = $pdo->query("
    SELECT code, label, display_order, is_open
    FROM crm_stages
    WHERE is_active = 1
    ORDER BY display_order
")->fetchAll();

$stageOrder = [];
$stageLabel = [];
foreach ($stages as $s) {
    $stageOrder[$s['code']] = (int)$s['display_order'];
    $stageLabel[$s['code']] = $s['label'];
}

// ---- Pull inquiries in range --------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, status, source, campaign_id, created_at
    FROM inquiry_families
    WHERE created_at >= :from AND created_at < :to
");
$stmt->execute([':from' => $from, ':to' => $to]);
$inquiries = $stmt->fetchAll();

$total    = count($inquiries);
$enrolled = 0;
$lost     = 0;
$active   = 0;

// Per-stage "reached" counts.
$reached = [];
foreach ($stages as $s) $reached[$s['code']] = 0;

foreach ($inquiries as $r) {
    $statusCode  = (string)$r['status'];
    $famOrder    = $stageOrder[$statusCode] ?? 0;
    if ($statusCode === 'enrolled') $enrolled++;
    elseif ($statusCode === 'lost') $lost++;
    else $active++;

    // For the conversion funnel we want the linear progression Leads → Enrolled.
    // 'lost' sits at the end with display_order 100 but conceptually it's a
    // sideways exit — don't credit lost rows with passing through later stages
    // they may never have touched.
    if ($statusCode === 'lost') {
        // count only the 'lead' / 'new' rows below it as "reached".
        foreach ($stages as $s) {
            if ((int)$s['display_order'] <= 20) {
                $reached[$s['code']]++;
            }
        }
        continue;
    }
    foreach ($stages as $s) {
        if ((int)$s['display_order'] <= $famOrder) {
            $reached[$s['code']]++;
        }
    }
}

// ---- Sources breakdown ---------------------------------------------------
$srcStmt = $pdo->prepare("
    SELECT
        COALESCE(c.name, NULLIF(f.source, ''), 'Unknown') AS src,
        COUNT(*)                                          AS total,
        SUM(f.status = 'enrolled')                        AS enrolled,
        SUM(f.status = 'lost')                            AS lost
    FROM inquiry_families f
    LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
    WHERE f.created_at >= :from AND f.created_at < :to
    GROUP BY src
    ORDER BY total DESC
");
$srcStmt->execute([':from' => $from, ':to' => $to]);
$sources = $srcStmt->fetchAll();

function pct($num, $denom): string
{
    if (!$denom) return '—';
    return number_format(($num / $denom) * 100, 1) . '%';
}

$pageTitle = 'Admissions funnel';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Conversion funnel</h1>
        <p class="muted"><?= e($rangeLabel) ?> · <?= $total ?> inquir<?= $total === 1 ? 'y' : 'ies' ?> created</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">← Pipeline</a>
    </div>
</div>

<section class="card">
    <form method="get" class="row" style="align-items:end;">
        <div class="field">
            <label>Range</label>
            <select name="range" onchange="document.getElementById('custom-range').hidden = (this.value !== 'custom'); ">
                <option value="30"     <?= $range === '30'     ? 'selected' : '' ?>>Last 30 days</option>
                <option value="60"     <?= $range === '60'     ? 'selected' : '' ?>>Last 60 days</option>
                <option value="90"     <?= $range === '90'     ? 'selected' : '' ?>>Last 90 days</option>
                <option value="180"    <?= $range === '180'    ? 'selected' : '' ?>>Last 6 months</option>
                <option value="all"    <?= $range === 'all'    ? 'selected' : '' ?>>All time</option>
                <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom…</option>
            </select>
        </div>
        <div class="field" id="custom-range" <?= $range !== 'custom' ? 'hidden' : '' ?>>
            <label>From</label>
            <input type="date" name="from" value="<?= e($_GET['from'] ?? $from) ?>">
        </div>
        <div class="field" <?= $range !== 'custom' ? 'hidden' : '' ?>>
            <label>To</label>
            <input type="date" name="to"   value="<?= e($_GET['to']   ?? $today->format('Y-m-d')) ?>">
        </div>
        <div class="actions"><button class="btn btn-primary">Filter</button></div>
    </form>
</section>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
    <li><div class="admin-tile">
        <span class="tile-label">Inquiries created</span>
        <span class="tile-value"><?= $total ?></span>
    </div></li>
    <li><div class="admin-tile tile-ok">
        <span class="tile-label">Enrolled</span>
        <span class="tile-value"><?= $enrolled ?></span>
        <span class="tile-sub"><?= e(pct($enrolled, $total)) ?> overall</span>
    </div></li>
    <li><div class="admin-tile">
        <span class="tile-label">Still open</span>
        <span class="tile-value"><?= $active ?></span>
    </div></li>
    <li><div class="admin-tile <?= $lost ? 'tile-warn' : '' ?>">
        <span class="tile-label">Lost</span>
        <span class="tile-value"><?= $lost ?></span>
        <span class="tile-sub"><?= e(pct($lost, $total)) ?></span>
    </div></li>
</ul>

<section class="card">
    <h3>Funnel by stage</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Stage</th>
                <th>Reached</th>
                <th>% of total</th>
                <th>Stage-to-stage</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $prev = null;
            foreach ($stages as $i => $s):
                $code  = $s['code'];
                $count = (int)$reached[$code];
                // Skip 'lost' in the linear funnel — it's an exit, not a stage.
                if ($code === 'lost') continue;
                $stagePct = pct($count, $total);
                $stageVsPrev = $prev === null ? '—' : pct($count, $prev);
                $prev = $count;
            ?>
                <tr>
                    <td><span class="pill pill-status-<?= e($code) ?>"><?= e($stageLabel[$code]) ?></span></td>
                    <td><strong><?= $count ?></strong></td>
                    <td><?= e($stagePct) ?></td>
                    <td class="muted"><?= e($stageVsPrev) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small">
        Counts reflect each inquiry's current stage (a card at "School visited" is counted under that stage and every earlier one).
        For a precise transition log see <a href="/crm/audit.php?action=status_changed">audit · status changes</a>.
    </p>
</section>

<section class="card">
    <h3>By source / campaign</h3>
    <?php if (!$sources): ?>
        <p class="muted">No inquiries in this range.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Total</th>
                    <th>Enrolled</th>
                    <th>Lost</th>
                    <th>Conversion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sources as $s): ?>
                    <tr>
                        <td><?= e($s['src']) ?></td>
                        <td><?= (int)$s['total'] ?></td>
                        <td><?= (int)$s['enrolled'] ?></td>
                        <td><?= (int)$s['lost'] ?></td>
                        <td><?= e(pct((int)$s['enrolled'], (int)$s['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
