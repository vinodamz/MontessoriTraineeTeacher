<?php
/**
 * materials/daily_history.php — history / report view for the daily audit.
 *
 * Pick a date to see that day's complete audit + auto-computed summary, or
 * drop the date and filter across every day by material, condition,
 * category (shelf) or staff member. Nothing here ever deletes anything —
 * it only reads mm_daily_checks.
 *
 *   GET ?date=YYYY-MM-DD&material=&condition=&category=&staff=
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user = require_module('materials');

$dates = mm_daily_dates_with_checks();
$date     = trim((string)($_GET['date'] ?? ''));
$material = trim((string)($_GET['material'] ?? ''));
$condition = trim((string)($_GET['condition'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$staff    = trim((string)($_GET['staff'] ?? ''));

// Default: most recent audited day (falls back to today if nothing yet).
$anyFilter = $material !== '' || $condition !== '' || $category !== '' || $staff !== '';
if ($date === '' && !$anyFilter) {
    $date = $dates[0] ?? date('Y-m-d');
}
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

$filters = array_filter([
    'date' => $date, 'material' => $material, 'condition' => $condition,
    'category' => $category, 'staff' => $staff,
], fn($v) => $v !== '');

$rows    = mm_daily_history($filters);
$summary = $date !== '' ? mm_daily_summary($date) : null;

$locations = db()->query("SELECT location FROM mm_materials GROUP BY location ORDER BY MIN(sort_order)")->fetchAll(PDO::FETCH_COLUMN);
$staffList = db()->query("
    SELECT DISTINCT u.id, u.name FROM mm_daily_checks c
    JOIN users u ON u.id = c.checked_by_user_id
    ORDER BY u.name
")->fetchAll();

$TONE_BG = ['ok' => '#dff1d3;color:#2d6526', 'warn' => '#fcebc6;color:#6c4612', 'bad' => '#fbdcd8;color:#8b1c14'];

$pageTitle = 'Daily audit history';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Audit history</h1>
        <p class="muted">Every past day's daily check, permanently kept. Nothing here is ever cleared.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="daily.php">Today's check</a>
        <a class="btn btn-ghost" href="daily_trends.php">Trends</a>
    </div>
</div>

<form class="filters no-print" method="get" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <label>Date
        <select name="date">
            <option value="">Any date</option>
            <?php foreach ($dates as $d): ?>
                <option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e(mm_daily_date_label($d)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <input type="search" name="material" placeholder="Material…" value="<?= e($material) ?>">
    <select name="condition">
        <option value="">Any condition</option>
        <?php foreach (mm_conditions() as $code => $meta): ?>
            <option value="<?= e($code) ?>" <?= $code === $condition ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="category">
        <option value="">Any shelf/category</option>
        <?php foreach ($locations as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $category ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="staff">
        <option value="">Any staff member</option>
        <?php foreach ($staffList as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $staff ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
    <a class="btn btn-ghost" href="daily_history.php">Reset</a>
</form>

<?php if ($summary): ?>
<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Summary — <?= e(mm_daily_date_label($date)) ?></h2>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:.6rem;">
        <div><span style="font-size:1.3rem; font-weight:700;"><?= (int)$summary['checked'] ?></span><br><span class="muted small">Materials checked</span></div>
        <div><span style="font-size:1.3rem; font-weight:700;"><?= (int)$summary['pending'] ?></span><br><span class="muted small">Not checked</span></div>
        <?php foreach (mm_conditions() as $code => $meta): $n = $summary['by_condition'][$code] ?? 0; if ($n === 0) continue; ?>
            <div><span style="font-size:1.3rem; font-weight:700;"><?= (int)$n ?></span><br><span class="muted small"><?= e($meta['label']) ?></span></div>
        <?php endforeach; ?>
        <div><span style="font-size:1.3rem; font-weight:700;"><?= (int)$summary['notes_count'] ?></span><br><span class="muted small">Notes logged</span></div>
    </div>
    <?php if ($summary['staff']): ?>
        <p class="muted small" style="margin-bottom:0; margin-top:.6rem;">
            Completed by:
            <?php foreach ($summary['staff'] as $i => $s): ?>
                <?= e($s['name'] ?? 'Unknown') ?> (<?= (int)$s['n'] ?>)<?= $i < count($summary['staff']) - 1 ? ', ' : '' ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2 style="margin-top:0;">Audit entries <span class="muted small">· <?= count($rows) ?></span></h2>
    <?php if (!$rows): ?>
        <div class="empty"><p>No audit entries match.</p></div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Date</th><th>Shelf/Category</th><th>Material</th><th>Condition</th><th>Notes</th><th>Checked by</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="daily_history.php?date=<?= e($r['check_date']) ?>"><?= e($r['check_date']) ?></a></td>
                <td><?= e($r['category']) ?></td>
                <td><?= e($r['material']) ?></td>
                <td><span class="pill small" style="background:<?= $TONE_BG[mm_condition_tone($r['condition_code'])] ?? '#eee' ?>"><?= e(mm_condition_label($r['condition_code'])) ?></span></td>
                <td><?= e((string)($r['notes'] ?? '')) ?></td>
                <td><?= e($r['staff_name'] ?? 'Unknown') ?></td>
                <td><?= e(date('g:ia', strtotime((string)$r['checked_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
