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

// ---- school-visit appointment actions (Done / No-show / Cancel) ------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id'])) {
    csrf_check();
    $aid    = (int)$_POST['appointment_id'];
    $status = (string)($_POST['set_status'] ?? '');
    if ($aid > 0 && in_array($status, ['done', 'cancelled', 'no_show'], true)) {
        $row = $pdo->prepare("SELECT family_id FROM crm_appointments WHERE id = :id");
        $row->execute([':id' => $aid]);
        $famId = (int)$row->fetchColumn();
        if ($famId) {
            $pdo->prepare("UPDATE crm_appointments SET status = :s WHERE id = :id")
                ->execute([':s' => $status, ':id' => $aid]);
            crm_audit_log('appointment_' . $status, $famId, null, 'appointment', $aid);
            if ($status === 'done') {
                // Visit happened — move forward + start the 3-day follow-up clock.
                $cur = $pdo->prepare("SELECT status FROM inquiry_families WHERE id = :id");
                $cur->execute([':id' => $famId]);
                $st = (string)$cur->fetchColumn();
                if (!in_array($st, ['enrolled', 'lost'], true)
                    && crm_stage_rank('school_visited') > crm_stage_rank($st)) {
                    $pdo->prepare("UPDATE inquiry_families
                                   SET status='school_visited', visited_at=NOW(),
                                       post_visit_reminded_at=NULL, probability=:p
                                   WHERE id=:id")
                        ->execute([':p' => crm_default_probability('school_visited'), ':id' => $famId]);
                    crm_audit_log('visit_marked', $famId, ['via' => 'today_view']);
                } else {
                    $pdo->prepare("UPDATE inquiry_families SET visited_at=NOW(), post_visit_reminded_at=NULL WHERE id=:id")
                        ->execute([':id' => $famId]);
                }
            }
            flash_set('ok', 'Appointment updated.');
        }
    }
    redirect('/crm/today.php');
}
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

// ---- school-visit appointments (booked via /crm/book_visit.php) ------------
// try/catch so the page still loads if migrate_031 hasn't run yet.
$apptToday = $apptUpcoming = [];
try {
    $apptSelect = "
        SELECT a.*, f.primary_name, f.primary_phone, f.status AS lead_status
        FROM crm_appointments a
        JOIN inquiry_families f ON f.id = a.family_id";
    $apptToday = $pdo->query("$apptSelect WHERE DATE(a.scheduled_at) = CURDATE()
        ORDER BY a.scheduled_at ASC")->fetchAll();
    $apptUpcoming = $pdo->query("$apptSelect WHERE DATE(a.scheduled_at) > CURDATE()
        AND DATE(a.scheduled_at) <= CURDATE() + INTERVAL 7 DAY AND a.status = 'booked'
        ORDER BY a.scheduled_at ASC")->fetchAll();
} catch (Throwable $e) { /* table not migrated yet */ }

$pageTitle = "Today — Admissions";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Today</h1>
        <p class="muted">
            <?= count($apptToday) ?> visit<?= count($apptToday) === 1 ? '' : 's' ?> today ·
            <?= count($overdue) ?> overdue · <?= count($today) ?> due today ·
            <?= count($week) ?> this week · <?= count($stagnant) ?> stagnant
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/book_visit.php" target="_blank" title="The public booking page — open it to book on a parent's behalf">+ Book a visit</a>
        <a class="btn" href="/crm/index.php">Pipeline →</a>
    </div>
</div>

<section class="card">
    <h3>🗓️ School visits today <span class="muted small">(<?= count($apptToday) ?>)</span></h3>
    <?php if (!$apptToday): ?>
        <p class="muted">No visits booked for today.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Time</th><th>Family</th><th>Child / programme</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($apptToday as $a):
                $aSt = (string)$a['status'];
                $cMap = ['booked' => '#2c6ecb', 'done' => '#1da851', 'cancelled' => '#999', 'no_show' => '#c0392b'];
                $cLbl = ['booked' => 'Booked', 'done' => 'Done ✓', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];
                $c = $cMap[$aSt] ?? '#666';
            ?>
                <tr>
                    <td style="white-space:nowrap; font-weight:600;"><?= e(date('g:i a', strtotime((string)$a['scheduled_at']))) ?></td>
                    <td><a href="/crm/view.php?id=<?= (int)$a['family_id'] ?>"><?= e((string)$a['primary_name']) ?></a><br>
                        <small class="muted"><?= e((string)$a['primary_phone']) ?> · <?= e(crm_status_label((string)$a['lead_status'])) ?></small></td>
                    <td><?= e((string)($a['child_name'] ?: '—')) ?><?= $a['programme'] ? '<br><small class="muted">' . e((string)$a['programme']) . '</small>' : '' ?></td>
                    <td><span style="background:<?= $c ?>1a; color:<?= $c ?>; border:1px solid <?= $c ?>55; border-radius:99px; padding:.1rem .6rem; font-size:.8em;"><?= e($cLbl[$aSt] ?? $aSt) ?></span></td>
                    <td style="white-space:nowrap;">
                        <?php if ($aSt === 'booked'): foreach ([['done', 'Done ✓', 'btn-primary'], ['no_show', 'No-show', ''], ['cancelled', 'Cancel', 'btn-ghost']] as [$s, $lbl, $cls]): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                                <input type="hidden" name="set_status" value="<?= e($s) ?>">
                                <button class="btn btn-small <?= e($cls) ?>"><?= e($lbl) ?></button>
                            </form>
                        <?php endforeach; endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="font-size:.85em;">Marking <strong>Done ✓</strong> moves the lead to School visited and starts the 3-day follow-up reminder.</p>
    <?php endif; ?>
</section>

<?php if ($apptUpcoming): ?>
<section class="card">
    <h3>📅 Visits in the next 7 days <span class="muted small">(<?= count($apptUpcoming) ?>)</span></h3>
    <table class="table">
        <tbody>
        <?php foreach ($apptUpcoming as $a): ?>
            <tr>
                <td style="white-space:nowrap;"><?= e(date('D, M j · g:i a', strtotime((string)$a['scheduled_at']))) ?></td>
                <td><a href="/crm/view.php?id=<?= (int)$a['family_id'] ?>"><?= e((string)$a['primary_name']) ?></a>
                    <small class="muted"> · <?= e((string)$a['primary_phone']) ?></small></td>
                <td><?= e((string)($a['child_name'] ?: '—')) ?><?= $a['programme'] ? ' <small class="muted">(' . e((string)$a['programme']) . ')</small>' : '' ?></td>
                <td style="white-space:nowrap;">
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Cancel this visit?');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="set_status" value="cancelled">
                        <button class="btn btn-small btn-ghost">Cancel</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

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
