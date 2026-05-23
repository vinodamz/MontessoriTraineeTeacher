<?php
/**
 * crm/today.php — daily action dashboard for the admissions team.
 *
 * Mobile-first landing page. Surfaces the four things the team needs
 * to see first thing every morning:
 *
 *   1. Overdue follow-ups   — touchpoints with follow_up_at in the past
 *                              for inquiries still open in the funnel.
 *   2. Due today            — same, with follow_up_at today.
 *   3. Due this week        — same, with follow_up_at in the next 7 days.
 *   4. Stagnant inquiries   — open inquiries with no touchpoint in 7+ days.
 *
 * Each row has the family name + phone with Call / WhatsApp / Save pills
 * inline so the admin can act without opening the detail page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

$pdo      = db();
$openCsv  = "'" . implode("','", crm_open_statuses()) . "'";

// Common SELECT — touchpoint rows joined to the family.
$tpSelect = "
    SELECT t.id AS tp_id, t.follow_up_at, t.kind, t.body,
           f.id AS family_id, f.primary_name, f.primary_phone, f.status
    FROM inquiry_touchpoints t
    JOIN inquiry_families f ON f.id = t.family_id
    WHERE t.follow_up_at IS NOT NULL
      AND f.status IN ($openCsv)
";

// Overdue — follow_up_at strictly before today's start.
$overdue = $pdo->query("$tpSelect AND t.follow_up_at < CURDATE() ORDER BY t.follow_up_at ASC LIMIT 100")->fetchAll();

// Today — between today's start and tomorrow's start.
$today = $pdo->query("$tpSelect AND t.follow_up_at >= CURDATE() AND t.follow_up_at < CURDATE() + INTERVAL 1 DAY ORDER BY t.follow_up_at ASC LIMIT 100")->fetchAll();

// This week — tomorrow through 7 days out.
$week = $pdo->query("$tpSelect AND t.follow_up_at >= CURDATE() + INTERVAL 1 DAY AND t.follow_up_at < CURDATE() + INTERVAL 8 DAY ORDER BY t.follow_up_at ASC LIMIT 100")->fetchAll();

// Stagnant — open inquiries with no touchpoint in 7+ days (or none at all,
// older than 3 days). Excludes 'lead' (which lives in /crm/leads.php and
// has its own triage flow) so this view focuses on the pipeline.
$stagnant = $pdo->query("
    SELECT f.id AS family_id, f.primary_name, f.primary_phone, f.status, f.created_at,
           (SELECT MAX(occurred_at) FROM inquiry_touchpoints WHERE family_id = f.id) AS last_touch
    FROM inquiry_families f
    WHERE f.status IN ($openCsv)
      AND f.status <> 'lead'
      AND NOT EXISTS (
          SELECT 1 FROM inquiry_touchpoints t
          WHERE t.family_id = f.id
            AND t.occurred_at >= CURDATE() - INTERVAL 7 DAY
      )
      AND f.created_at < CURDATE() - INTERVAL 3 DAY
    ORDER BY (SELECT MAX(occurred_at) FROM inquiry_touchpoints WHERE family_id = f.id) IS NULL DESC,
             (SELECT MAX(occurred_at) FROM inquiry_touchpoints WHERE family_id = f.id) ASC,
             f.created_at ASC
    LIMIT 100
")->fetchAll();

// Batch-load substitution vars for the WhatsApp template picker. Wrapped
// in function_exists because the helpers ship with the WA-templates PR;
// when this page is deployed first, the picker simply doesn't render —
// Call / WhatsApp / Save pills still work.
$familyIds = array_unique(array_merge(
    array_column($overdue,  'family_id'),
    array_column($today,    'family_id'),
    array_column($week,     'family_id'),
    array_column($stagnant, 'family_id'),
));
$waVarsByFam = function_exists('crm_wa_vars_for_families')
    ? crm_wa_vars_for_families($familyIds)
    : [];
$waTemplates = function_exists('crm_wa_templates_active')
    ? crm_wa_templates_active()
    : [];

function fu_when(?string $ts): string
{
    if (!$ts) return '';
    $t = strtotime($ts);
    $today = strtotime('today');
    $tomorrow = strtotime('tomorrow');
    if ($t >= $today && $t < $tomorrow) return 'Today ' . date('H:i', $t);
    if ($t >= $tomorrow && $t < $tomorrow + 86400) return 'Tomorrow ' . date('H:i', $t);
    return date('j M', $t) . ' ' . date('H:i', $t);
}

function stagnant_age(?string $ts): string
{
    if (!$ts) return 'never contacted';
    $d = max(0, (int)((strtotime('today') - strtotime($ts)) / 86400));
    if ($d === 0) return 'today';
    if ($d === 1) return '1 day ago';
    return $d . ' days ago';
}

$pageTitle = "Today — Admissions";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Today</h1>
        <p class="muted">
            <?= count($overdue) ?> overdue · <?= count($today) ?> due today ·
            <?= count($week) ?> this week · <?= count($stagnant) ?> stagnant
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/index.php">Pipeline →</a>
    </div>
</div>

<?php
function render_followup_card(string $heading, array $rows, string $emptyText, array $waVarsByFam, ?array $statusLabels = null): void
{
    if (!$rows && $emptyText === '') return;
    ?>
    <section class="card">
        <h3><?= e($heading) ?> <span class="muted small">(<?= count($rows) ?>)</span></h3>
        <?php if (!$rows): ?>
            <p class="muted"><?= e($emptyText) ?></p>
        <?php else: ?>
            <ul class="today-list" role="list">
                <?php foreach ($rows as $r): ?>
                    <li class="today-row">
                        <div class="today-row-head">
                            <a href="/crm/view.php?id=<?= (int)$r['family_id'] ?>" class="today-name"><?= e($r['primary_name']) ?></a>
                            <?php if (!empty($r['status'])): ?>
                                <span class="pill pill-status-<?= e($r['status']) ?>"><?= e(crm_status_label($r['status'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['follow_up_at'])): ?>
                                <span class="muted small">↻ <?= e(fu_when($r['follow_up_at'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['last_touch']) || array_key_exists('last_touch', $r)): ?>
                                <span class="muted small">Last touch · <?= e(stagnant_age($r['last_touch'] ?? null)) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($r['body'])): ?>
                            <div class="muted small today-body"><?= e(mb_strimwidth((string)$r['body'], 0, 180, '…')) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['primary_phone'])): ?>
                            <div class="today-phone"><?= crm_phone_actions($r['primary_phone'], (int)$r['family_id'], $waVarsByFam[(int)$r['family_id']] ?? []) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
}

render_followup_card('Overdue',     $overdue, 'No overdue follow-ups. Nice.',                        $waVarsByFam);
render_followup_card('Due today',   $today,   'Nothing scheduled for today.',                       $waVarsByFam);
render_followup_card('Due this week', $week,  'Nothing scheduled for the next 7 days.',             $waVarsByFam);
render_followup_card('Stagnant',    $stagnant,'No stagnant inquiries — everyone is being chased.',  $waVarsByFam);
?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
