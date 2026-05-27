<?php
/**
 * fees/config.php — admin: manage fee amounts for the calculator + parent guide.
 *
 * Saves each fee component as a row in app_settings with key prefix 'fee_'.
 * The public calculator and parent guide read these via includes/fees.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';

require_admin();

$fields = [
    ['key' => 'admission_fee',     'label' => 'Admission / Registration Fee',    'default' => 7500],
    ['key' => 'starter_kit',       'label' => 'Yearly Starter Kit',              'default' => 6500],
    ['key' => 'resource_renewal',  'label' => 'Annual Resource Renewal',         'default' => 5000],
    ['key' => 'school_fee_monthly','label' => 'School Fee (monthly base)',       'default' => 7900],
    ['key' => 'monthly_billing',   'label' => 'Monthly Billing Add-on',          'default' => 300],
    ['key' => 'weekly_rate',       'label' => 'School Fee (weekly rate)',         'default' => 1975],
    ['key' => 'quarterly_rate',    'label' => 'School Fee (quarterly rate)',      'default' => 22800],
    ['key' => 'care_rest',         'label' => 'Rest Care (monthly add-on)',      'default' => 1500],
    ['key' => 'care_enrichment',   'label' => 'Enrichment Care (monthly)',       'default' => 3800],
    ['key' => 'care_fullday',      'label' => 'Full Day Care (monthly)',         'default' => 5500],
    ['key' => 'ukg_readiness',     'label' => 'UKG Readiness Programme (monthly)','default' => 1500],
    ['key' => 'late_fee',          'label' => 'Late Payment Fee',                'default' => 500],
    ['key' => 'grace_days',        'label' => 'Grace Period (days)',             'default' => 7],
    ['key' => 'payment_due_day',   'label' => 'Monthly Payment Due Day (e.g. 5 = before 5th)', 'default' => 5],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    foreach ($fields as $f) {
        $val = (string)(int)($_POST[$f['key']] ?? $f['default']);
        $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = :v2
        ")->execute([':k' => 'fee_' . $f['key'], ':v' => $val, ':v2' => $val]);
    }
    app_setting_clear_cache();
    flash_set('ok', 'Fee configuration saved. The calculator and parent guide now use the new amounts.');
    redirect('/fees/config.php');
}

// Handle CoFee API settings separately (string values, not integers).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_section'] ?? '') === 'cofee') {
    csrf_check();
    $pdo = db();
    $cofeeFields = ['cofee_org_id', 'cofee_branch_id', 'cofee_token'];
    foreach ($cofeeFields as $k) {
        $val = trim((string)($_POST[$k] ?? ''));
        $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = :v2
        ")->execute([':k' => $k, ':v' => $val, ':v2' => $val]);
    }
    // Also save group IDs so the wizard can link directly.
    $groupFields = ['cofee_group_joining', 'cofee_group_monthly', 'cofee_group_weekly', 'cofee_group_quarterly', 'cofee_group_ukg'];
    foreach ($groupFields as $k) {
        $val = trim((string)($_POST[$k] ?? ''));
        $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = :v2
        ")->execute([':k' => $k, ':v' => $val, ':v2' => $val]);
    }
    app_setting_clear_cache();
    flash_set('ok', 'CoFee API settings saved.');
    redirect('/fees/config.php#cofee');
}

$pageTitle = 'Fee Configuration';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Fee Configuration</h1>
        <p class="muted">Edit the amounts used by the <a href="/fees_calculator.php" target="_blank">public fee calculator</a> and the parent fee guide.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/fees_calculator.php" target="_blank">Preview calculator</a>
    </div>
</div>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <table class="data-table">
        <thead><tr><th>Fee component</th><th style="width:10rem;">Amount (INR)</th></tr></thead>
        <tbody>
            <?php foreach ($fields as $f): ?>
                <tr>
                    <td><label for="<?= e($f['key']) ?>"><?= e($f['label']) ?></label></td>
                    <td>
                        <input id="<?= e($f['key']) ?>" name="<?= e($f['key']) ?>"
                               type="number" min="0" step="1"
                               value="<?= (int)fee_int($f['key'], $f['default']) ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="actions" style="margin-top: 1rem;">
        <button class="btn btn-primary" type="submit">Save all</button>
    </div>
</form>

<section class="card" id="cofee">
    <h2>CoFee API Integration</h2>
    <p class="muted small">Connect to <a href="https://web.cofee.life" target="_blank">web.cofee.life</a> so the enrollment wizard can create members directly. Get the token from DevTools → Application → Local Storage → token.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="_section" value="cofee">
        <table class="data-table">
            <tbody>
                <tr>
                    <td><label for="cofee_org_id">Organisation ID</label></td>
                    <td><input id="cofee_org_id" name="cofee_org_id" value="<?= e((string)app_setting('cofee_org_id', 'org_DmdMcjbAFx2232')) ?>" placeholder="org_..."></td>
                </tr>
                <tr>
                    <td><label for="cofee_branch_id">Branch ID</label></td>
                    <td><input id="cofee_branch_id" name="cofee_branch_id" value="<?= e((string)app_setting('cofee_branch_id', 'brch_ZG3hZaurVN2682')) ?>" placeholder="brch_..."></td>
                </tr>
                <tr>
                    <td><label for="cofee_token">JWT Token</label></td>
                    <td><input id="cofee_token" name="cofee_token" type="password" value="<?= e((string)app_setting('cofee_token', '')) ?>" placeholder="eyJ... (from localStorage)"></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top:1rem;">CoFee Group IDs</h3>
        <p class="muted small">Paste the group IDs from CoFee so the wizard can link and enrol directly. Find them in Groups → click a group → the URL contains the ID (grp_...).</p>
        <table class="data-table">
            <tbody>
                <tr><td><label>Joining Fees group</label></td><td><input name="cofee_group_joining" value="<?= e((string)app_setting('cofee_group_joining', '')) ?>" placeholder="grp_..."></td></tr>
                <tr><td><label>School Fee — Monthly group</label></td><td><input name="cofee_group_monthly" value="<?= e((string)app_setting('cofee_group_monthly', '')) ?>" placeholder="grp_..."></td></tr>
                <tr><td><label>School Fee — Weekly group</label></td><td><input name="cofee_group_weekly" value="<?= e((string)app_setting('cofee_group_weekly', '')) ?>" placeholder="grp_..."></td></tr>
                <tr><td><label>School Fee — Quarterly group</label></td><td><input name="cofee_group_quarterly" value="<?= e((string)app_setting('cofee_group_quarterly', '')) ?>" placeholder="grp_..."></td></tr>
                <tr><td><label>UKG Readiness group</label></td><td><input name="cofee_group_ukg" value="<?= e((string)app_setting('cofee_group_ukg', '')) ?>" placeholder="grp_..."></td></tr>
            </tbody>
        </table>
        <div class="actions" style="margin-top:.8rem;">
            <button class="btn btn-primary" type="submit">Save CoFee settings</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
