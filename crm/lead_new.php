<?php
/**
 * crm/lead_new.php — staff-side single-lead capture.
 *
 * Stripped-down form for inputting one lead at a time. Inserts an
 * inquiry_families row with status='lead'. Once contacted and qualified
 * the user opens it in /crm/view.php and moves the status to 'new' (which
 * sends it into the main pipeline).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['primary_name'] ?? '');
    if ($name === '') {
        flash_set('error', 'Lead name is required.');
        redirect('/crm/lead_new.php');
    }

    $phone   = trim($_POST['primary_phone'] ?? '') ?: null;
    $email   = trim($_POST['primary_email'] ?? '') ?: null;

    // Same dedupe contract as the bot/booking ingest paths: an existing
    // family with this phone means "open it", not "create a twin" that later
    // needs the destructive dedupe tools.
    if ($phone !== null) {
        $existing = crm_find_lead_by_phone($phone);
        if ($existing !== null) {
            flash_set('ok', 'A record with this phone number already exists — opened it instead of creating a duplicate.');
            redirect('/crm/view.php?id=' . $existing);
        }
    }

    $camp    = (int)($_POST['campaign_id'] ?? 0) ?: null;
    $prio    = $_POST['priority'] ?? 'normal';
    if (!array_key_exists($prio, crm_priorities())) $prio = 'normal';
    $owner   = (int)($_POST['owner_id'] ?? 0) ?: $user['id'];
    $notes   = trim($_POST['notes'] ?? '');
    $childAge= trim($_POST['child_age'] ?? '');
    if ($childAge !== '') {
        $notes = "Child age: $childAge" . ($notes !== '' ? "\n\n$notes" : '');
    }

    // "Walk-in" path skips the lead stage and lands directly on the pipeline
    // board as a New inquiry. Anything else is captured as a cold lead.
    $isWalkIn = !empty($_POST['walk_in']);
    $status   = $isWalkIn ? 'new'  : 'lead';
    $defaultProb = crm_default_probability($status);

    db()->prepare("
        INSERT INTO inquiry_families
            (primary_name, primary_phone, primary_email,
             status, priority, probability, campaign_id, owner_id, notes)
        VALUES
            (:n, :p, :e, :st, :pr, :prob, :c, :o, :notes)
    ")->execute([
        ':n'  => $name, ':p' => $phone, ':e' => $email,
        ':st' => $status, ':pr' => $prio, ':prob' => $defaultProb,
        ':c'  => $camp, ':o' => $owner,
        ':notes' => $notes ?: null,
    ]);
    $id = (int)db()->lastInsertId();
    flash_set('ok', $isWalkIn
        ? 'Walk-in added directly to the pipeline as a New inquiry.'
        : 'Lead captured. Log a touchpoint, then hit "Add to pipeline" to promote them.');
    redirect('/crm/view.php?id=' . $id);
}

$campaigns = crm_active_campaigns();
$owners    = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('crm', modules) > 0)
    ORDER BY name
")->fetchAll();

$pageTitle = 'New lead';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>New lead</h1>
        <p class="muted">
            <a href="/crm/leads.php">← Leads</a>
            · Minimal capture. Add children/parents/fees later from the detail page.
        </p>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="row">
        <div class="field" style="flex: 2 1 280px;">
            <label>Primary contact name *</label>
            <input name="primary_name" required maxlength="160" placeholder="e.g. Anita Sharma">
        </div>
        <div class="field">
            <label>Phone</label>
            <input name="primary_phone" maxlength="40" placeholder="+91 …">
        </div>
        <div class="field">
            <label>Email</label>
            <input name="primary_email" type="email" maxlength="160">
        </div>
        <div class="field">
            <label>Child age</label>
            <input name="child_age" maxlength="20" placeholder="e.g. 3 / 2.5y">
        </div>
    </div>

    <div class="row">
        <div class="field">
            <label>Campaign</label>
            <select name="campaign_id">
                <option value="0">— Unknown —</option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Priority</label>
            <select name="priority">
                <?php foreach (crm_priorities() as $code => $meta): ?>
                    <option value="<?= e($code) ?>" <?= $code === 'normal' ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Owner</label>
            <select name="owner_id">
                <option value="0">— Me —</option>
                <?php foreach ($owners as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$user['id'] ? 'selected' : '' ?>>
                        <?= e($o['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Anything else captured at first contact…"></textarea>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit" name="walk_in" value="">Save as lead</button>
        <button class="btn" type="submit" name="walk_in" value="1"
                title="Walk-in: skip the lead stage and add straight to the pipeline">
            Save as walk-in →
        </button>
        <a class="btn btn-ghost" href="/crm/leads.php">Cancel</a>
    </div>
    <p class="muted small">
        Use <strong>walk-in</strong> for families you've already talked to in person —
        they land directly on the pipeline board. <strong>Lead</strong> is for cold
        contacts; log a touchpoint and promote them from the detail page.
    </p>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
