<?php
/**
 * crm/leads.php — lead-focused list view.
 *
 * Same inquiry_families table as the pipeline board, but defaulted to
 * status='lead' and rendered as a filterable list with priority/campaign/
 * owner chips. From here you "+ New lead", bulk-import a CSV, or open a
 * lead's detail page to start the touchpoint timeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

// ---- Filters -------------------------------------------------------------
$statusIn   = $_GET['status']   ?? 'lead';   // 'lead'|'all'|specific status code
$campaignIn = isset($_GET['campaign']) ? (int)$_GET['campaign'] : 0;
$priorityIn = $_GET['priority'] ?? '';
$ownerIn    = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;
$q          = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($statusIn === 'lead') {
    $where[] = "f.status = 'lead'";
} elseif (array_key_exists($statusIn, crm_statuses())) {
    $where[] = "f.status = :st";
    $params[':st'] = $statusIn;
}
if ($campaignIn > 0) {
    $where[] = "f.campaign_id = :cid";
    $params[':cid'] = $campaignIn;
}
if ($priorityIn !== '' && array_key_exists($priorityIn, crm_priorities())) {
    $where[] = "f.priority = :pr";
    $params[':pr'] = $priorityIn;
}
if ($ownerIn > 0) {
    $where[] = "f.owner_id = :oid";
    $params[':oid'] = $ownerIn;
}
if ($q !== '') {
    $where[] = "(f.primary_name LIKE :q OR f.primary_phone LIKE :q OR f.primary_email LIKE :q)";
    $params[':q'] = "%$q%";
}

$sql = "
    SELECT f.id, f.primary_name, f.primary_phone, f.primary_email,
           f.status, f.priority, f.created_at,
           c.name AS campaign_name, c.channel AS campaign_channel,
           u.name AS owner_name
    FROM inquiry_families f
    LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
    LEFT JOIN users u         ON u.id = f.owner_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY FIELD(f.priority,'urgent','high','normal','low'),
             f.created_at DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Batch-load WhatsApp template substitution vars for these leads.
$waVarsByFam = crm_wa_vars_for_families(array_column($leads, 'id'));
$waTemplates = crm_wa_templates_active();

$campaigns = db()->query("SELECT id, name, channel FROM crm_campaigns ORDER BY active DESC, name")->fetchAll();
$owners    = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('crm', modules) > 0)
    ORDER BY name
")->fetchAll();

$pageTitle = 'Leads';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Leads</h1>
        <p class="muted">
            <a href="/crm/index.php">← Pipeline</a>
            · <?= count($leads) ?> match<?= count($leads) === 1 ? '' : 'es' ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/lead_import.php">Bulk import</a>
        <a class="btn" href="/crm/campaigns.php">Campaigns</a>
        <a class="btn btn-primary" href="/crm/lead_new.php">+ New lead</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="q">Search</label>
        <input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone or email" autocomplete="off">
    </div>
    <div class="field">
        <label for="campaign">Campaign</label>
        <select id="campaign" name="campaign">
            <option value="0">All campaigns</option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $campaignIn === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="priority">Priority</label>
        <select id="priority" name="priority">
            <option value="">All</option>
            <?php foreach (crm_priorities() as $code => $meta): ?>
                <option value="<?= e($code) ?>" <?= $priorityIn === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="owner">Owner</label>
        <select id="owner" name="owner">
            <option value="0">All</option>
            <?php foreach ($owners as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $ownerIn === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Stage</label>
        <select id="status" name="status">
            <option value="lead" <?= $statusIn === 'lead' ? 'selected' : '' ?>>Leads only</option>
            <option value="all"  <?= $statusIn === 'all'  ? 'selected' : '' ?>>All stages</option>
            <?php foreach (crm_statuses() as $code => $meta):
                if ($code === 'lead') continue; ?>
                <option value="<?= e($code) ?>" <?= $statusIn === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/crm/leads.php">Reset</a>
    </div>
</form>

<?php if (!$leads): ?>
    <div class="empty">
        <p>No leads match these filters.
            <a href="/crm/lead_new.php">Add one</a>
            or <a href="/crm/lead_import.php">import a batch</a>.</p>
    </div>
<?php else: ?>
    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Campaign</th>
                    <th>Priority</th>
                    <th>Owner</th>
                    <th>Stage</th>
                    <th>Age</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l):
                $ageHours = (time() - strtotime($l['created_at'])) / 3600;
                $ageLabel = $ageHours < 24
                    ? round($ageHours) . 'h'
                    : round($ageHours / 24) . 'd';
            ?>
                <tr>
                    <td><a href="/crm/view.php?id=<?= (int)$l['id'] ?>"><?= e($l['primary_name']) ?></a></td>
                    <td>
                        <?php if ($l['primary_phone']): ?>
                            <div class="phone-cell"><?= crm_phone_actions($l['primary_phone'], (int)$l['id'], $waVarsByFam[(int)$l['id']] ?? []) ?></div>
                        <?php endif; ?>
                        <?php if ($l['primary_email']): ?>
                            <span class="muted small"><?= e($l['primary_email']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['campaign_name']): ?>
                            <span class="pill"><?= e($l['campaign_name']) ?></span>
                            <span class="muted small"><?= e(crm_channels()[$l['campaign_channel']] ?? '') ?></span>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="pill pill-prio-<?= e($l['priority']) ?>"><?= e(crm_priority_label($l['priority'])) ?></span></td>
                    <td><?= e((string)$l['owner_name']) ?: '<span class="muted small">unassigned</span>' ?></td>
                    <td><span class="pill pill-status-<?= e($l['status']) ?>"><?= e(crm_status_label($l['status'])) ?></span></td>
                    <td class="muted small"><?= e($ageLabel) ?></td>
                    <td class="row-actions">
                        <a class="btn small" href="/crm/view.php?id=<?= (int)$l['id'] ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
