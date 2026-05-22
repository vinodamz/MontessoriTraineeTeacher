<?php
/**
 * crm/campaigns.php — admin: manage marketing campaigns.
 *
 * Each campaign is a labelled bucket for leads (Instagram, Walk-in, etc.).
 * Soft-delete via the `active` flag — deactivated campaigns stay attached
 * to historical leads but no longer appear in pickers.
 *
 * Shows a public-form embed URL per campaign so marketing can drop a link
 * into landing pages / WhatsApp / Instagram bio.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create' || $op === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $channel = $_POST['channel'] ?? 'other';
        if (!array_key_exists($channel, crm_channels())) $channel = 'other';
        $cost    = $_POST['cost'] !== '' ? (float)$_POST['cost'] : null;
        $active  = !empty($_POST['active']) ? 1 : 0;
        $notes   = trim($_POST['notes'] ?? '') ?: null;

        if ($name === '') {
            flash_set('error', 'Campaign name is required.');
            redirect('/crm/campaigns.php');
        }
        try {
            if ($op === 'create') {
                db()->prepare("
                    INSERT INTO crm_campaigns (name, channel, cost, active, notes)
                    VALUES (:n, :c, :co, :a, :nt)
                ")->execute([':n' => $name, ':c' => $channel, ':co' => $cost, ':a' => $active, ':nt' => $notes]);
                flash_set('ok', 'Campaign added.');
            } else {
                db()->prepare("
                    UPDATE crm_campaigns
                    SET name=:n, channel=:c, cost=:co, active=:a, notes=:nt
                    WHERE id=:id
                ")->execute([':n' => $name, ':c' => $channel, ':co' => $cost, ':a' => $active, ':nt' => $notes, ':id' => $id]);
                flash_set('ok', 'Campaign updated.');
            }
        } catch (PDOException $e) {
            flash_set('error', 'Save failed (name must be unique): ' . $e->getMessage());
        }
        redirect('/crm/campaigns.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM crm_campaigns WHERE id=:id")->execute([':id' => $id]);
            flash_set('ok', 'Campaign deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete: this campaign has leads attached. Deactivate it instead.');
        }
        redirect('/crm/campaigns.php');
    }
}

$rows = db()->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM inquiry_families f WHERE f.campaign_id = c.id) AS lead_count,
           (SELECT COUNT(*) FROM inquiry_families f WHERE f.campaign_id = c.id AND f.status = 'enrolled') AS enrolled_count
    FROM crm_campaigns c
    ORDER BY c.active DESC, c.name
")->fetchAll();

$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = "$scheme://$host";

$pageTitle = 'Campaigns';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Campaigns</h1>
        <p class="muted"><a href="/crm/leads.php">← Leads</a></p>
    </div>
</div>

<details class="card card-form" open>
    <summary>+ Add a campaign</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="row">
            <div class="field" style="flex: 2 1 280px;">
                <label>Name</label>
                <input name="name" required maxlength="120" placeholder="e.g. Instagram – June 2026">
            </div>
            <div class="field">
                <label>Channel</label>
                <select name="channel">
                    <?php foreach (crm_channels() as $code => $label): ?>
                        <option value="<?= e($code) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Cost (₹, optional)</label>
                <input name="cost" type="number" min="0" step="100">
            </div>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="active" value="1" checked>
                    <span>Active</span>
                </label>
            </div>
        </div>
        <div class="field">
            <label>Notes</label>
            <input name="notes" maxlength="500">
        </div>
        <div class="actions"><button class="btn btn-primary">Add campaign</button></div>
    </form>
</details>

<?php if (!$rows): ?>
    <div class="empty"><p>No campaigns yet. Add one above to start tagging leads.</p></div>
<?php else: ?>
    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Channel</th>
                    <th>Cost</th>
                    <th>Leads</th>
                    <th>Enrolled</th>
                    <th>Public form URL</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $c):
                $embedUrl = $baseUrl . '/crm/lead_submit.php?c=' . urlencode($c['name']);
            ?>
                <form id="camp-edit-<?= (int)$c['id'] ?>" method="post" hidden>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="update">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                </form>
                <form id="camp-del-<?= (int)$c['id'] ?>" method="post" hidden
                      onsubmit="return confirm('Delete this campaign? Fails if any leads are attached — deactivate instead.')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                </form>
                <tr class="<?= $c['active'] ? '' : 'is-inactive' ?>">
                    <td>
                        <input form="camp-edit-<?= (int)$c['id'] ?>" name="name" value="<?= e($c['name']) ?>" maxlength="120" required>
                    </td>
                    <td>
                        <select form="camp-edit-<?= (int)$c['id'] ?>" name="channel">
                            <?php foreach (crm_channels() as $code => $label): ?>
                                <option value="<?= e($code) ?>" <?= $c['channel'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input form="camp-edit-<?= (int)$c['id'] ?>" name="cost" type="number" min="0" step="100"
                               value="<?= e((string)($c['cost'] ?? '')) ?>" style="width:7em;">
                    </td>
                    <td><?= (int)$c['lead_count'] ?></td>
                    <td><?= (int)$c['enrolled_count'] ?></td>
                    <td class="muted small">
                        <a href="<?= e($embedUrl) ?>" target="_blank" rel="noopener" title="Public form for this campaign">
                            <?= e($embedUrl) ?>
                        </a>
                    </td>
                    <td>
                        <label class="checkbox">
                            <input form="camp-edit-<?= (int)$c['id'] ?>" type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>>
                            <span>On</span>
                        </label>
                    </td>
                    <td class="row-actions">
                        <button class="btn small" form="camp-edit-<?= (int)$c['id'] ?>">Save</button>
                        <button class="link-btn" form="camp-del-<?= (int)$c['id'] ?>" title="Delete">×</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
