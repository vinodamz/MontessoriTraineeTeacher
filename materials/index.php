<?php
/**
 * materials/index.php — monthly condition audit board.
 *
 * Lists every material grouped by shelf/location with its condition mark for
 * the selected month. Marking is inline (a condition dropdown + "needs
 * replacement" toggle) so a teacher can walk the shelves and tick down the
 * list. Full detail + media upload lives on check.php.
 *
 *   GET ?period=YYYY-MM   audit month (defaults to this month)
 *   GET ?q= / ?loc=       filter
 *   GET ?only=pending     materials not yet marked this month
 *   GET ?only=replace     materials flagged for replacement
 *   POST op=quick_mark    inline condition save
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

// ---- Inline quick-mark ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'quick_mark') {
    csrf_check();
    $mid  = (int)($_POST['material_id'] ?? 0);
    $cond = (string)($_POST['condition_code'] ?? '');
    if ($mid > 0 && isset(mm_conditions()[$cond])) {
        // Default the replacement flag from the condition unless the row's
        // checkbox overrides it.
        $suggests = mm_conditions()[$cond]['suggests_replace'];
        $needs    = array_key_exists('needs_replacement', $_POST)
            ? !empty($_POST['needs_replacement'])
            : $suggests;
        mm_save_check($mid, $period, $cond, $needs, (int)($_POST['replace_qty'] ?? 0),
                      trim((string)($_POST['notes'] ?? '')), (int)$user['id']);
        flash_set('ok', 'Marked.');
    }
    $qs = http_build_query(array_filter([
        'period' => $period, 'q' => $_GET['q'] ?? '', 'loc' => $_GET['loc'] ?? '', 'only' => $_GET['only'] ?? '',
    ]));
    redirect('/materials/index.php' . ($qs ? '?' . $qs : '') . '#m' . $mid);
}

// ---- Filters --------------------------------------------------------------
$q    = trim((string)($_GET['q'] ?? ''));
$loc  = trim((string)($_GET['loc'] ?? ''));
$only = (string)($_GET['only'] ?? '');

$where  = ['m.is_active = 1'];
$params = [':p' => $period];
if ($q !== '')   { $where[] = 'm.name LIKE :q';       $params[':q']   = '%' . $q . '%'; }
if ($loc !== '') { $where[] = 'm.location = :loc';    $params[':loc'] = $loc; }
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

// Group by location for rendering.
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

$locations = db()->query("SELECT DISTINCT location FROM mm_materials WHERE is_active = 1 ORDER BY MIN(sort_order)")->fetchAll(PDO::FETCH_COLUMN);
$periods   = mm_period_options();

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
<?php endif; ?>

<?php foreach ($byLoc as $location => $items): ?>
    <section class="card" style="margin-bottom:1rem;">
        <div class="card-head"><h2 style="margin:0;"><?= e($location) ?> <span class="muted small">· <?= count($items) ?></span></h2></div>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th style="width:32%">Material</th><th>Condition</th><th>Replace?</th><th>Marked by</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null; ?>
                <tr id="m<?= (int)$m['id'] ?>">
                    <td>
                        <strong><?= e($m['name']) ?></strong>
                        <?php if ((int)$m['media_count'] > 0): ?><span class="pill small" title="Has photos/videos">📎 <?= (int)$m['media_count'] ?></span><?php endif; ?>
                    </td>
                    <td>
                        <form method="post" class="inline" style="display:flex; gap:.35rem; align-items:center;">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="quick_mark">
                            <input type="hidden" name="period" value="<?= e($period) ?>">
                            <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                            <select name="condition_code" onchange="this.form.requestSubmit()">
                                <option value="">— pick —</option>
                                <?php foreach (mm_conditions() as $code => $meta): ?>
                                    <option value="<?= e($code) ?>" <?= $m['condition_code'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php if ($marked && $m['needs_replacement']): ?>
                            <span class="pill" style="background:#fbdcd8;color:#8b1c14;">replace<?= (int)$m['replace_qty'] > 0 ? ' ×' . (int)$m['replace_qty'] : '' ?></span>
                        <?php elseif ($marked): ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted small">
                        <?php if ($marked): ?>
                            <?= e($m['checked_by'] ?? 'Unknown') ?><br><?= e(date('j M, g:ia', strtotime($m['checked_at']))) ?>
                        <?php else: ?>
                            <span style="color:#b3261e">not checked</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-ghost small" href="check.php?id=<?= (int)$m['id'] ?>&period=<?= e($period) ?>">Detail / photo</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
