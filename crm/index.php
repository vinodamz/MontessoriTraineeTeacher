<?php
/**
 * crm/index.php — admissions pipeline board.
 *
 * Kanban view of every inquiry family grouped by pipeline status, plus a
 * revenue-projection card at the top (weighted by per-inquiry probability).
 * Cards link through to the detail page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

// ---- AJAX: drag-and-drop status change -----------------------------------
// Mirrors the tasks/tasks.php op=move pattern: POST + X-Requested-With,
// JSON back. Status-only — probability stays whatever the user last set.
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'move' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $st = $_POST['status'] ?? '';
        if ($id <= 0 || !array_key_exists($st, crm_statuses())) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad input']);
            exit;
        }
        // Read previous status for the audit entry.
        $prev = db()->prepare("SELECT status FROM inquiry_families WHERE id = :id");
        $prev->execute([':id' => $id]);
        $prevStatus = (string)$prev->fetchColumn();

        db()->prepare("UPDATE inquiry_families SET status = :s WHERE id = :id")
            ->execute([':s' => $st, ':id' => $id]);

        if ($prevStatus !== '' && $prevStatus !== $st) {
            crm_audit_log('status_changed', $id, [
                'from' => $prevStatus, 'to' => $st, 'via' => 'kanban_drag',
            ]);
        }
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$rows = db()->query("
    SELECT f.*,
           c.name AS campaign_name,
           (SELECT COUNT(*) FROM inquiry_children k WHERE k.family_id = f.id) AS kid_count,
           (SELECT MIN(t.follow_up_at) FROM inquiry_touchpoints t
             WHERE t.family_id = f.id AND t.follow_up_at >= NOW())            AS next_followup
    FROM inquiry_families f
    LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
    WHERE f.status <> 'lead'
    ORDER BY FIELD(f.priority,'urgent','high','normal','low'), f.updated_at DESC
")->fetchAll();

// Batch-load substitution vars for the WhatsApp template picker so the
// kanban doesn't go N+1 (one parent + child lookup per card).
$waVarsByFam = crm_wa_vars_for_families(array_column($rows, 'id'));
$waTemplates = crm_wa_templates_active();
$tagsByFam   = crm_tags_for_families(array_column($rows, 'id'));

// Group by status for the kanban columns (pipeline only — leads excluded).
$byStatus = [];
foreach (array_keys(crm_pipeline_statuses()) as $code) $byStatus[$code] = [];
foreach ($rows as $r) {
    $byStatus[$r['status']][] = $r;
}

// Count of leads sitting in /crm/leads.php — shown as a quick-link chip.
$leadCount = (int)db()->query("SELECT COUNT(*) FROM inquiry_families WHERE status = 'lead'")->fetchColumn();

$projection = crm_revenue_projection();
$money      = fn(float $v) => '₹' . number_format($v, 0);

// Follow-ups due in the next 7 days — handy reminder above the board.
$dueFollowups = db()->query("
    SELECT t.id, t.family_id, t.follow_up_at, t.kind, f.primary_name
    FROM inquiry_touchpoints t
    JOIN inquiry_families f ON f.id = t.family_id
    WHERE t.follow_up_at IS NOT NULL
      AND t.follow_up_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
      AND f.status IN ('" . implode("','", crm_open_statuses()) . "')
    ORDER BY t.follow_up_at
    LIMIT 10
")->fetchAll();

$pageTitle  = 'Admissions';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Admissions</h1>
        <p class="muted"><?= count($rows) ?> inquir<?= count($rows) === 1 ? 'y' : 'ies' ?>
            · <?= $projection['count'] ?> open in funnel</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/leads.php">
            Leads
            <?php if ($leadCount > 0): ?>
                <span class="pill pill-status-lead" style="margin-left:.35rem;"><?= $leadCount ?></span>
            <?php endif; ?>
        </a>
        <a class="btn" href="/crm/today.php"    title="Today's calls + stagnant leads">Today</a>
        <a class="btn" href="/crm/calendar.php" title="Monthly calendar view">Calendar</a>
        <a class="btn" href="/crm/funnel.php"   title="Conversion funnel report">Funnel</a>
        <a class="btn" href="/crm/campaigns.php">Campaigns</a>
        <?php if ($user['role'] === 'admin'): ?>
            <a class="btn" href="/crm/stages.php"       title="Manage pipeline stages">Stages</a>
            <a class="btn" href="/crm/tags.php"         title="Manage inquiry tags">Tags</a>
            <a class="btn" href="/crm/probability_rules.php" title="Auto-set probability based on tags">Rules</a>
            <a class="btn" href="/crm/wa_templates.php" title="Manage WhatsApp message templates">WA templates</a>
            <a class="btn" href="/crm/audit.php"        title="Admin: full activity log">Audit</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin' && is_readable(__DIR__ . '/../sql/odoo_dump/leads.csv')): ?>
            <a class="btn" href="/crm/import_odoo.php" title="One-shot importer for the Odoo 2026 Admission dump">Import Odoo</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/crm/edit.php">+ New inquiry</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Projected revenue</span>
            <span class="tile-value"><?= e($money($projection['weighted'])) ?>/mo</span>
            <span class="tile-sub">Probability-weighted, open funnel</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Pipeline ceiling</span>
            <span class="tile-value"><?= e($money($projection['pipeline'])) ?>/mo</span>
            <span class="tile-sub">If everything converted</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-nav">
            <span class="tile-label">Open inquiries</span>
            <span class="tile-value"><?= (int)$projection['count'] ?></span>
            <span class="tile-sub">Across all open stages</span>
        </div>
    </li>
    <li>
        <div class="admin-tile <?= $dueFollowups ? 'tile-warn' : 'tile-ok' ?>">
            <span class="tile-label">Follow-ups (7d)</span>
            <span class="tile-value"><?= count($dueFollowups) ?></span>
            <span class="tile-sub">Scheduled in next week</span>
        </div>
    </li>
</ul>

<?php if ($dueFollowups): ?>
    <div class="card">
        <h3 style="margin-bottom:.6rem;">Upcoming follow-ups</h3>
        <ul class="followup-list" role="list">
            <?php foreach ($dueFollowups as $f): ?>
                <li>
                    <a href="/crm/view.php?id=<?= (int)$f['family_id'] ?>"><?= e($f['primary_name']) ?></a>
                    <span class="muted small">
                        · <?= e(crm_touchpoint_kinds()[$f['kind']] ?? $f['kind']) ?>
                        · <?= e(date('D, j M · H:i', strtotime($f['follow_up_at']))) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty">
        <p>No inquiries yet. <a href="/crm/edit.php">Add your first inquiry</a> to kick off the funnel.</p>
    </div>
<?php else: ?>
    <div class="crm-board" data-csrf="<?= e(csrf_token()) ?>">
        <?php foreach (crm_pipeline_statuses() as $code => $meta):
            $cards = $byStatus[$code];
            $colCount = count($cards);
        ?>
            <section class="crm-col crm-col-<?= e($code) ?>" data-status="<?= e($code) ?>">
                <header class="crm-col-head">
                    <h3><?= e($meta['label']) ?></h3>
                    <span class="pill"><?= $colCount ?></span>
                </header>
                <ul class="crm-col-list" data-status="<?= e($code) ?>" role="list">
                    <?php foreach ($cards as $r):
                        $fee  = $r['expected_fee'] !== null ? $money((float)$r['expected_fee']) : '—';
                        $prob = (int)$r['probability'];
                    ?>
                        <li class="crm-card-li" data-inquiry-id="<?= (int)$r['id'] ?>">
                            <article class="crm-card">
                                <div class="crm-card-name">
                                    <a href="/crm/view.php?id=<?= (int)$r['id'] ?>"><?= e($r['primary_name']) ?></a>
                                    <?= crm_tag_pills($tagsByFam[(int)$r['id']] ?? []) ?>
                                </div>
                                <?php if (!empty($r['primary_phone'])): ?>
                                    <div class="crm-card-phone"><?= crm_phone_actions($r['primary_phone'], (int)$r['id'], $waVarsByFam[(int)$r['id']] ?? []) ?></div>
                                <?php endif; ?>
                                <div class="crm-card-meta">
                                    <?php if (($r['priority'] ?? 'normal') !== 'normal'): ?>
                                        <span class="pill pill-prio-<?= e($r['priority']) ?>"><?= e(crm_priority_label($r['priority'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$r['kid_count'] > 0): ?>
                                        <span class="pill"><?= (int)$r['kid_count'] ?> kid<?= (int)$r['kid_count'] === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['campaign_name']): ?>
                                        <span class="muted small">· <?= e($r['campaign_name']) ?></span>
                                    <?php elseif ($r['source']): ?>
                                        <span class="muted small">· <?= e($r['source']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="crm-card-meta">
                                    <span><?= e($fee) ?>/mo</span>
                                    <span class="muted small">· <?= $prob ?>%</span>
                                </div>
                                <?php if ($r['next_followup']): ?>
                                    <div class="crm-card-meta muted small">
                                        ↻ <?= e(date('j M', strtotime($r['next_followup']))) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="crm-card-move">
                                    <select class="crm-card-status-select"
                                            aria-label="Move to another stage"
                                            data-current="<?= e($code) ?>">
                                        <option value="">Move to…</option>
                                        <?php foreach (crm_pipeline_statuses() as $sc => $sm): ?>
                                            <option value="<?= e($sc) ?>"
                                                    <?= $sc === $code ? 'hidden' : '' ?>>
                                                <?= e($sm['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!$cards): ?>
                    <p class="crm-col-empty muted small">Drop cards here.</p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script src="/assets/js/crm-board.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-board.js')) ?>"></script>
<?php endif; ?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
