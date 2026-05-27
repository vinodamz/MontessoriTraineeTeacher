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

<?php require __DIR__ . '/../includes/footer.php'; ?>
