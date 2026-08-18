<?php
/**
 * materials/daily.php — today's material condition audit (daily, not monthly).
 *
 * Separate from index.php's monthly Kreedo replacement board: this page is
 * always TODAY, always starts blank, and has no replacement flag or media.
 * A new day has zero rows in mm_daily_checks until someone marks it — that
 * IS the reset. Every previous day's rows stay put; see daily_history.php.
 *
 *   GET                current view — today's date, every active material,
 *                       blank unless already marked today.
 *   POST op=bulk_mark   save every changed row in one go.
 *   POST op=bulk_good   mark every still-unmarked material Good.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user  = require_module('materials');
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'bulk_mark') {
        $existing = mm_daily_existing($today);
        $conds  = $_POST['cond']  ?? [];
        $notes  = $_POST['notes'] ?? [];
        $saved  = 0;
        foreach ($conds as $mid => $code) {
            $mid  = (int)$mid;
            $code = trim((string)$code);
            if ($mid <= 0 || $code === '' || !isset(mm_conditions()[$code])) continue;
            $note = trim((string)($notes[$mid] ?? ''));
            $prev = $existing[$mid] ?? null;
            if ($prev !== null && $prev['condition_code'] === $code && (string)$prev['notes'] === $note) {
                continue; // unchanged — don't re-stamp who/when
            }
            mm_daily_save_check($mid, $today, $code, $note, (int)$user['id']);
            $saved++;
        }
        flash_set('ok', $saved > 0 ? "Saved $saved material" . ($saved === 1 ? '' : 's') . " for today." : 'Nothing changed.');
        redirect('/materials/daily.php');
    }

    if ($op === 'bulk_good') {
        $scope  = trim((string)($_POST['scope'] ?? ''));
        $where  = "m.is_active = 1 AND NOT EXISTS (SELECT 1 FROM mm_daily_checks c WHERE c.material_id = m.id AND c.check_date = :d)";
        $params = [':d' => $today];
        if ($scope !== '') { $where .= ' AND m.location = :loc'; $params[':loc'] = $scope; }
        $st = db()->prepare("SELECT m.id FROM mm_materials m WHERE $where");
        $st->execute($params);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $mid) {
            mm_daily_save_check((int)$mid, $today, 'good', null, (int)$user['id']);
        }
        $label = $scope !== '' ? $scope : 'all shelves';
        flash_set('ok', count($ids) . ' unmarked material' . (count($ids) === 1 ? '' : 's') . " in $label marked Good.");
        redirect('/materials/daily.php');
    }
}

// ---- Filters ------------------------------------------------------------
$q   = trim((string)($_GET['q'] ?? ''));
$loc = trim((string)($_GET['loc'] ?? ''));

$where  = ['m.is_active = 1'];
$params = [':d' => $today];
if ($q !== '')   { $where[] = 'm.name LIKE :q';    $params[':q']   = '%' . $q . '%'; }
if ($loc !== '') { $where[] = 'm.location = :loc'; $params[':loc'] = $loc; }
$whereSql = implode(' AND ', $where);

$rows = db()->prepare("
    SELECT m.id, m.name, m.location, m.sort_order,
           c.condition_code, c.notes, c.checked_at, u.name AS checked_by
    FROM mm_materials m
    LEFT JOIN mm_daily_checks c ON c.material_id = m.id AND c.check_date = :d
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE $whereSql
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute($params);
$materials = $rows->fetchAll();

$byLoc = [];
foreach ($materials as $m) $byLoc[$m['location']][] = $m;

$locations = db()->query("SELECT location FROM mm_materials WHERE is_active = 1 GROUP BY location ORDER BY MIN(sort_order)")->fetchAll(PDO::FETCH_COLUMN);
$summary   = mm_daily_summary($today);
$TONE_BG   = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];

$pageTitle = 'Daily material check — ' . mm_daily_date_label($today);
require __DIR__ . '/../includes/header.php';
?>

<style>
.mmd2-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: .6rem; margin-bottom: 1rem; }
.mmd2-tile  { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: .6rem .8rem; }
.mmd2-tile .v { font-size: 1.4rem; font-weight: 700; display: block; }
.mmd2-tile .l { color: #777; font-size: .78rem; }
.mm-list  { display: flex; flex-direction: column; }
.mm-item  { padding: .6rem .2rem; border-bottom: 1px solid #eee; }
.mm-item:last-child { border-bottom: 0; }
.mm-top   { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; margin-bottom: .35rem; }
.mm-name  { flex: 1 1 12rem; }
.mm-status { display: inline-flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.mm-controls { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
.mm-controls .mm-cond { flex: 1 1 11rem; max-width: 16rem; }
.mm-controls .mm-note { flex: 2 1 14rem; }
@media (pointer: coarse), (max-width: 640px) {
    .mm-controls .mm-cond, .mm-controls .mm-note { min-height: 44px; font-size: 1rem; }
    .mm-item { padding: .75rem .1rem; }
}
</style>

<div class="page-head">
    <div>
        <h1>Daily material check</h1>
        <p class="muted">
            <strong><?= e(mm_daily_date_label($today)) ?></strong> ·
            <?= (int)$summary['checked'] ?>/<?= (int)$summary['total_active'] ?> checked today
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="daily_history.php">Audit history</a>
        <a class="btn btn-ghost" href="daily_trends.php">Trends</a>
        <a class="btn btn-ghost" href="index.php">Monthly board</a>
    </div>
</div>

<div class="mmd2-tiles">
    <div class="mmd2-tile"><span class="v"><?= (int)$summary['checked'] ?></span><span class="l">Checked today</span></div>
    <div class="mmd2-tile"><span class="v"><?= (int)$summary['pending'] ?></span><span class="l">Not yet checked</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#2d6526"><?= (int)($summary['by_tone']['ok'] ?? 0) ?></span><span class="l">Good condition</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#6c4612"><?= (int)($summary['by_tone']['warn'] ?? 0) ?></span><span class="l">Needs attention</span></div>
    <div class="mmd2-tile"><span class="v" style="color:#8b1c14"><?= (int)($summary['by_tone']['bad'] ?? 0) ?></span><span class="l">Damaged / missing</span></div>
    <div class="mmd2-tile"><span class="v"><?= (int)$summary['notes_count'] ?></span><span class="l">Notes logged</span></div>
</div>

<form class="filters no-print" method="get" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <input type="search" name="q" placeholder="Search material…" value="<?= e($q) ?>">
    <select name="loc">
        <option value="">All shelves</option>
        <?php foreach ($locations as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $loc ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
    <a class="btn btn-ghost" href="daily.php">Reset</a>
</form>

<?php if (!$materials): ?>
    <div class="empty"><p>No materials match. <a href="daily.php">Clear filters</a>.</p></div>
<?php else: ?>

<form method="post" id="bulkForm" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_mark">

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
                        title="Every material on this shelf with no mark today becomes Good."
                        onclick="return confirm('Mark all still-unchecked materials in <?= e(addslashes($location)) ?> as Good?')">
                    rest are Good ✓
                </button>
            <?php endif; ?>
        </div>
        <div class="mm-list">
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null;
                $mid = (int)$m['id'];
            ?>
            <div class="mm-item" id="m<?= $mid ?>">
                <div class="mm-top">
                    <strong class="mm-name"><?= e($m['name']) ?></strong>
                    <span class="mm-status muted small">
                        <?php if ($marked): ?>
                            <span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($m['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($m['condition_code'])) ?></span>
                            ✓ <?= e($m['checked_by'] ?? 'Unknown') ?> · <?= e(date('g:ia', strtotime((string)$m['checked_at']))) ?>
                        <?php else: ?>
                            <span style="color:#b3261e">not checked today</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mm-controls">
                    <select name="cond[<?= $mid ?>]" class="mm-cond">
                        <option value="">— condition —</option>
                        <?php foreach (mm_conditions() as $code => $meta): ?>
                            <option value="<?= e($code) ?>" <?= $m['condition_code'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="mm-note" name="notes[<?= $mid ?>]" maxlength="500"
                           placeholder="Note (optional)" value="<?= e((string)($m['notes'] ?? '')) ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="form-actions no-print"><button class="btn btn-primary" type="submit">Save today's check</button></div>
</form>

<form method="post" id="goodForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="bulk_good">
</form>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
