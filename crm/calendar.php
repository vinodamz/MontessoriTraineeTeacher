<?php
/**
 * crm/calendar.php — monthly calendar view for the admissions pipeline.
 *
 * Shows a month grid where each day cell carries badge counts for:
 *   - Follow-ups due that day
 *   - Touchpoints logged that day
 *   - Inquiries created that day
 *
 * Click a day → the detail panel below shows exactly what happened /
 * is scheduled, with inline Call / WhatsApp / Save pills.
 *
 * ?month=2026-05       selects the month (defaults to today's)
 * ?date=2026-05-23     selects a specific day for the detail panel
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');
$pdo  = db();

// ---- Month navigation ----------------------------------------------------
$todayDate  = new DateTime('today');
$todayStr   = $todayDate->format('Y-m-d');
$monthParam = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = $todayDate->format('Y-m');
}
$monthStart = new DateTime($monthParam . '-01');
$monthEnd   = (clone $monthStart)->modify('last day of this month');
$prevMonth  = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $monthStart)->modify('+1 month')->format('Y-m');

$dateParam  = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = $todayStr;
}

$fromSql = $monthStart->format('Y-m-d');
$toSql   = $monthEnd->format('Y-m-d') . ' 23:59:59';

// ---- Aggregate counts per day for the calendar grid ----------------------

// Follow-ups due this month.
$fuStmt = $pdo->prepare("
    SELECT DATE(t.follow_up_at) AS d, COUNT(*) AS n
    FROM inquiry_touchpoints t
    JOIN inquiry_families f ON f.id = t.family_id
    WHERE t.follow_up_at BETWEEN :from AND :to
      AND t.follow_up_at IS NOT NULL
    GROUP BY d
");
$fuStmt->execute([':from' => $fromSql, ':to' => $toSql]);
$followupsByDay = [];
foreach ($fuStmt as $r) $followupsByDay[$r['d']] = (int)$r['n'];

// Touchpoints logged this month.
$tpStmt = $pdo->prepare("
    SELECT DATE(t.occurred_at) AS d, COUNT(*) AS n
    FROM inquiry_touchpoints t
    WHERE t.occurred_at BETWEEN :from AND :to
    GROUP BY d
");
$tpStmt->execute([':from' => $fromSql, ':to' => $toSql]);
$touchpointsByDay = [];
foreach ($tpStmt as $r) $touchpointsByDay[$r['d']] = (int)$r['n'];

// Inquiries created this month.
$newStmt = $pdo->prepare("
    SELECT DATE(created_at) AS d, COUNT(*) AS n
    FROM inquiry_families
    WHERE created_at BETWEEN :from AND :to
    GROUP BY d
");
$newStmt->execute([':from' => $fromSql, ':to' => $toSql]);
$createdByDay = [];
foreach ($newStmt as $r) $createdByDay[$r['d']] = (int)$r['n'];

// ---- Selected-day detail -------------------------------------------------
$dayFollowups   = [];
$dayTouchpoints = [];
$dayCreated     = [];
$dayAudit       = [];

if ($dateParam) {
    $dayFrom = $dateParam . ' 00:00:00';
    $dayTo   = $dateParam . ' 23:59:59';

    $s = $pdo->prepare("
        SELECT t.*, f.primary_name, f.primary_phone, f.status, f.id AS family_id
        FROM inquiry_touchpoints t
        JOIN inquiry_families f ON f.id = t.family_id
        WHERE t.follow_up_at BETWEEN :from AND :to
        ORDER BY t.follow_up_at
    ");
    $s->execute([':from' => $dayFrom, ':to' => $dayTo]);
    $dayFollowups = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT t.*, f.primary_name, f.primary_phone, f.status, f.id AS family_id,
               u.name AS by_name
        FROM inquiry_touchpoints t
        JOIN inquiry_families f ON f.id = t.family_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.occurred_at BETWEEN :from AND :to
        ORDER BY t.occurred_at DESC
    ");
    $s->execute([':from' => $dayFrom, ':to' => $dayTo]);
    $dayTouchpoints = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT f.id AS family_id, f.primary_name, f.primary_phone, f.status, f.source
        FROM inquiry_families f
        WHERE f.created_at BETWEEN :from AND :to
        ORDER BY f.created_at
    ");
    $s->execute([':from' => $dayFrom, ':to' => $dayTo]);
    $dayCreated = $s->fetchAll();

    try {
        $s = $pdo->prepare("
            SELECT a.*, u.name AS by_name, f.primary_name AS family_name
            FROM inquiry_audit a
            LEFT JOIN users u            ON u.id = a.user_id
            LEFT JOIN inquiry_families f ON f.id = a.family_id
            WHERE a.created_at BETWEEN :from AND :to
            ORDER BY a.created_at DESC
            LIMIT 200
        ");
        $s->execute([':from' => $dayFrom, ':to' => $dayTo]);
        $dayAudit = $s->fetchAll();
    } catch (Throwable $e) {
        $dayAudit = [];
    }
}

// WA template picker vars.
$allFamIds = array_unique(array_merge(
    array_column($dayFollowups,   'family_id'),
    array_column($dayTouchpoints, 'family_id'),
    array_column($dayCreated,     'family_id'),
));
$waVarsByFam = function_exists('crm_wa_vars_for_families')
    ? crm_wa_vars_for_families($allFamIds) : [];
$waTemplates = function_exists('crm_wa_templates_active')
    ? crm_wa_templates_active() : [];

$pageTitle = 'Calendar — Admissions';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Calendar</h1>
        <p class="muted"><?= e($monthStart->format('F Y')) ?></p>
    </div>
    <div class="actionbar">
        <a class="btn" href="?month=<?= e($prevMonth) ?>">← <?= e((new DateTime("$prevMonth-01"))->format('M')) ?></a>
        <a class="btn" href="?month=<?= e($todayDate->format('Y-m')) ?>&date=<?= e($todayStr) ?>">Today</a>
        <a class="btn" href="?month=<?= e($nextMonth) ?>"><?= e((new DateTime("$nextMonth-01"))->format('M')) ?> →</a>
        <a class="btn" href="/crm/index.php">Pipeline →</a>
    </div>
</div>

<?php
// Build the calendar grid.
$firstDow  = (int)$monthStart->format('w'); // 0=Sun
$daysInMonth = (int)$monthStart->format('t');
$dayNames  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>

<div class="cal-grid card">
    <div class="cal-head">
        <?php foreach ($dayNames as $dn): ?>
            <div class="cal-head-cell"><?= $dn ?></div>
        <?php endforeach; ?>
    </div>
    <div class="cal-body">
        <?php
        // Blank cells before the 1st.
        for ($b = 0; $b < $firstDow; $b++) echo '<div class="cal-cell cal-blank"></div>';

        for ($d = 1; $d <= $daysInMonth; $d++):
            $ds = $monthParam . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $isToday    = ($ds === $todayStr);
            $isSelected = ($ds === $dateParam);
            $fuN  = $followupsByDay[$ds]   ?? 0;
            $tpN  = $touchpointsByDay[$ds] ?? 0;
            $crN  = $createdByDay[$ds]     ?? 0;
            $hasEvents = ($fuN + $tpN + $crN) > 0;
            $classes = 'cal-cell';
            if ($isToday)    $classes .= ' cal-today';
            if ($isSelected) $classes .= ' cal-selected';
            if (!$hasEvents) $classes .= ' cal-empty';
        ?>
            <a href="?month=<?= e($monthParam) ?>&date=<?= e($ds) ?>" class="<?= $classes ?>">
                <span class="cal-day"><?= $d ?></span>
                <?php if ($hasEvents): ?>
                    <span class="cal-badges">
                        <?php if ($fuN): ?><span class="cal-badge cal-badge-fu" title="<?= $fuN ?> follow-up<?= $fuN > 1 ? 's' : '' ?> due"><?= $fuN ?></span><?php endif; ?>
                        <?php if ($tpN): ?><span class="cal-badge cal-badge-tp" title="<?= $tpN ?> touchpoint<?= $tpN > 1 ? 's' : '' ?> logged"><?= $tpN ?></span><?php endif; ?>
                        <?php if ($crN): ?><span class="cal-badge cal-badge-new" title="<?= $crN ?> new inquir<?= $crN > 1 ? 'ies' : 'y' ?>"><?= $crN ?></span><?php endif; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php
        endfor;

        // Trailing blanks.
        $trailing = (7 - (($firstDow + $daysInMonth) % 7)) % 7;
        for ($b = 0; $b < $trailing; $b++) echo '<div class="cal-cell cal-blank"></div>';
        ?>
    </div>
</div>

<?php if ($dateParam): ?>
<div class="cal-detail">
    <h2><?= e(date('l, j F Y', strtotime($dateParam))) ?></h2>

    <?php if ($dayFollowups): ?>
    <section class="card">
        <h3>Follow-ups due (<?= count($dayFollowups) ?>)</h3>
        <ul class="today-list" role="list">
            <?php foreach ($dayFollowups as $r): ?>
                <li class="today-row">
                    <div class="today-row-head">
                        <a href="/crm/view.php?id=<?= (int)$r['family_id'] ?>" class="today-name"><?= e($r['primary_name']) ?></a>
                        <span class="pill pill-status-<?= e($r['status']) ?>"><?= e(crm_status_label($r['status'])) ?></span>
                        <span class="muted small"><?= e(date('H:i', strtotime($r['follow_up_at']))) ?></span>
                    </div>
                    <?php if (!empty($r['body'])): ?>
                        <div class="muted small today-body"><?= e(mb_strimwidth((string)$r['body'], 0, 200, '…')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['primary_phone'])): ?>
                        <div class="today-phone"><?= crm_phone_actions($r['primary_phone'], (int)$r['family_id'], $waVarsByFam[(int)$r['family_id']] ?? []) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($dayCreated): ?>
    <section class="card">
        <h3>New inquiries (<?= count($dayCreated) ?>)</h3>
        <ul class="today-list" role="list">
            <?php foreach ($dayCreated as $r): ?>
                <li class="today-row">
                    <div class="today-row-head">
                        <a href="/crm/view.php?id=<?= (int)$r['family_id'] ?>" class="today-name"><?= e($r['primary_name']) ?></a>
                        <span class="pill pill-status-<?= e($r['status']) ?>"><?= e(crm_status_label($r['status'])) ?></span>
                        <?php if ($r['source']): ?><span class="muted small">· <?= e($r['source']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($r['primary_phone'])): ?>
                        <div class="today-phone"><?= crm_phone_actions($r['primary_phone'], (int)$r['family_id'], $waVarsByFam[(int)$r['family_id']] ?? []) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($dayTouchpoints): ?>
    <section class="card">
        <h3>Activity logged (<?= count($dayTouchpoints) ?>)</h3>
        <ul class="today-list" role="list">
            <?php foreach ($dayTouchpoints as $r): ?>
                <li class="today-row">
                    <div class="today-row-head">
                        <a href="/crm/view.php?id=<?= (int)$r['family_id'] ?>" class="today-name"><?= e($r['primary_name']) ?></a>
                        <span class="pill"><?= e(crm_touchpoint_kinds()[$r['kind']] ?? $r['kind']) ?></span>
                        <span class="muted small"><?= e(date('H:i', strtotime($r['occurred_at']))) ?></span>
                        <?php if ($r['by_name']): ?><span class="muted small">— <?= e($r['by_name']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($r['body'])): ?>
                        <div class="muted small today-body"><?= e(mb_strimwidth((string)$r['body'], 0, 200, '…')) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($dayAudit && ($user['role'] ?? '') === 'admin'): ?>
    <section class="card">
        <h3>Audit trail <span class="muted small">(admin only · <?= count($dayAudit) ?>)</span></h3>
        <table class="data-table audit-table">
            <thead><tr><th>Time</th><th>Action</th><th>By</th><th>Family</th></tr></thead>
            <tbody>
            <?php foreach ($dayAudit as $a): ?>
                <tr>
                    <td><?= e(date('H:i', strtotime($a['created_at']))) ?></td>
                    <td><span class="pill"><?= e(crm_audit_action_label($a['action'])) ?></span></td>
                    <td><?= e($a['by_name'] ?: '—') ?></td>
                    <td>
                        <?php if ($a['family_id']): ?>
                            <a href="/crm/view.php?id=<?= (int)$a['family_id'] ?>"><?= e($a['family_name'] ?: '#'.(int)$a['family_id']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if (!$dayFollowups && !$dayTouchpoints && !$dayCreated && !$dayAudit): ?>
        <p class="muted">No events on this day.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
