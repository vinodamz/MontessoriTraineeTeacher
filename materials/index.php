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

<form method="post" id="bulkForm">
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
            <thead><tr><th style="width:34%">Material</th><th>Saved</th><th>Change to</th><th>Marked by</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $m): $marked = $m['condition_code'] !== null; ?>
                <tr id="m<?= (int)$m['id'] ?>">
                    <td>
                        <strong><?= e($m['name']) ?></strong>
                        <?php if ((int)$m['media_count'] > 0): ?><span class="pill small" title="Has photos/videos">📎 <?= (int)$m['media_count'] ?></span><?php endif; ?>
                        <?php if ($marked && $m['needs_replacement']): ?>
                            <span class="pill small" style="background:#fbdcd8;color:#8b1c14;">replace<?= (int)$m['replace_qty'] > 0 ? ' ×' . (int)$m['replace_qty'] : '' ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($marked): ?>
                            <span class="pill" style="background:<?= $TONE_BG[mm_condition_tone($m['condition_code'])] ?? '' ?>">
                                <?= e(mm_condition_label($m['condition_code'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="pill" style="background:#eee;color:#666;">not checked</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="cond[<?= (int)$m['id'] ?>]" class="mm-cond" data-saved="<?= e((string)$m['condition_code']) ?>">
                            <option value=""><?= $marked ? '(keep as is)' : '— pick —' ?></option>
                            <?php foreach (mm_conditions() as $code => $meta): ?>
                                <option value="<?= e($code) ?>"><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="muted small">
                        <?php if ($marked): ?>
                            <?= e($m['checked_by'] ?? 'Unknown') ?><br><?= e(date('j M, g:ia', strtotime($m['checked_at']))) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-ghost small" href="check.php?id=<?= (int)$m['id'] ?>&period=<?= e($period) ?>">qty / note / photo</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- Sticky save bar: always in reach on a long list / phone. -->
    <div class="no-print" style="position:sticky; bottom:0; background:var(--card-bg, #fff); border-top:2px solid #ddd; padding:.6rem .8rem; display:flex; gap:.7rem; align-items:center; z-index:50;">
        <button class="btn btn-primary" type="submit">Save all changes</button>
        <span id="pendingCount" class="muted small">No changes yet — pick conditions above, then save once.</span>
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
// Live count of pending (changed, non-empty) selects so the sticky bar tells
// the teacher exactly what one press of Save will write.
(function () {
    var form = document.getElementById('bulkForm');
    var out  = document.getElementById('pendingCount');
    if (!form || !out) return;
    function refresh() {
        var n = 0;
        form.querySelectorAll('select.mm-cond').forEach(function (s) {
            if (s.value !== '' && s.value !== s.dataset.saved) n++;
            s.style.background = (s.value !== '' && s.value !== s.dataset.saved) ? '#fff8dc' : '';
        });
        out.textContent = n === 0
            ? 'No changes yet — pick conditions above, then save once.'
            : n + ' change' + (n === 1 ? '' : 's') + ' ready to save.';
    }
    form.addEventListener('change', refresh);
    refresh();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
