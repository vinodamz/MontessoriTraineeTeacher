<?php
/**
 * fees/index.php — Fees module landing page.
 *
 * Quick-access hub for all fee tools:
 *   - Fee Calculator (public link to share with parents)
 *   - Parent Fee Guide (personalised, downloadable PDF)
 *   - Fee Configuration (admin: edit amounts)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';

$user = require_module('fees');

$fs = fee_structure();
$admTotal   = array_sum(array_column($fs['admission'], 'amount'));
$monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];

$scheme  = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? '';
$calcUrl = "$scheme://$host/fees_calculator.php";

$pageTitle = 'Fees';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Fees</h1>
        <p class="muted">Fee tools for the admissions team and parents.</p>
    </div>
    <div class="actionbar">
        <?php if ($user['role'] === 'admin'): ?>
            <a class="btn" href="/fees/config.php">Configure fees</a>
        <?php endif; ?>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Admission Fee</span>
            <span class="tile-value"><?= e(fee_inr($admTotal)) ?></span>
            <span class="tile-sub">One-time (Registration + Kit + Renewal)</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Monthly School Fee</span>
            <span class="tile-value"><?= e(fee_inr($monthlyTotal)) ?></span>
            <span class="tile-sub">₹<?= number_format($fs['schoolFeeMonthly']) ?> base + ₹<?= number_format($fs['monthlyBilling']) ?> billing</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Weekly Rate</span>
            <span class="tile-value"><?= e(fee_inr($fs['weeklyRate'])) ?></span>
            <span class="tile-sub">Per week (4-week flat basis)</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Quarterly Rate</span>
            <span class="tile-value"><?= e(fee_inr($fs['quarterlyRate'])) ?></span>
            <span class="tile-sub">Per quarter (3 months)</span>
        </div>
    </li>
</ul>

<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">

    <div class="card">
        <h3>Fee Calculator</h3>
        <p class="muted small">Public link — share with parents via WhatsApp. No login needed.</p>
        <p>Parents pick grade + frequency + care plan and see the full breakdown.</p>
        <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.6rem;">
            <a class="btn btn-primary" href="/fees_calculator.php" target="_blank">Open calculator</a>
            <button class="btn" onclick="navigator.clipboard.writeText('<?= e($calcUrl) ?>').then(()=>alert('Link copied!'))">Copy link</button>
        </div>
    </div>

    <div class="card">
        <h3>Parent Fee Guide</h3>
        <p class="muted small">Personalised document with full payment schedule, due dates, pro-rated first month.</p>
        <p>Fill in student details → download as PDF → share with the family.</p>
        <a class="btn btn-primary" href="/fees/guide.php" style="margin-top:.6rem;">Generate guide</a>
    </div>

    <div class="card">
        <h3>CoFee Enrollment Wizard</h3>
        <p class="muted small">Input child details → get exact CoFee instructions: which groups, what amounts, what dates.</p>
        <p>Step-by-step guide to enrol a child in CoFee with the right groups and fee settings.</p>
        <a class="btn btn-primary" href="/fees/cofee_enroll.php" style="margin-top:.6rem;">Open wizard</a>
    </div>

    <div class="card">
        <h3>CoFee Admin Guide</h3>
        <p class="muted small">Step-by-step guide for setting up groups, members, and fees on web.cofee.life.</p>
        <p>Which groups to create, what amounts to set, how to enrol children, care add-ons, switching plans.</p>
        <a class="btn" href="/fees/cofee_admin_guide.php" style="margin-top:.6rem;">Open guide</a>
    </div>

    <?php if ($user['role'] === 'admin'): ?>
    <div class="card">
        <h3>Fee Configuration</h3>
        <p class="muted small">Admin only — edit amounts used by the calculator and guide.</p>
        <p>Change admission fees, school fee, care plan rates, payment due day, grace period, late fee.</p>
        <a class="btn" href="/fees/config.php" style="margin-top:.6rem;">Edit fee amounts</a>
    </div>
    <?php endif; ?>

</div>

<div class="card" style="margin-top:1rem;">
    <h3>Care Plan Add-ons</h3>
    <table class="data-table">
        <thead><tr><th>Plan</th><th>Monthly Add-on</th></tr></thead>
        <tbody>
            <?php foreach ($fs['carePlans'] as $code => $cp): if ($cp['monthly'] === 0) continue; ?>
                <tr><td><?= e($cp['label']) ?></td><td><?= e(fee_inr($cp['monthly'])) ?>/month</td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small" style="margin-top:.4rem;">
        Payment due before the <?= (int)$fs['paymentDueDay'] ?><?= match((int)$fs['paymentDueDay'] % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?> of each month.
        Grace: <?= (int)$fs['graceDays'] ?> days. Late fee: <?= e(fee_inr($fs['lateFee'])) ?>.
    </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
