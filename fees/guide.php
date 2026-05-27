<?php
/**
 * fees/guide.php — personalized parent fee guide (downloadable as PDF).
 *
 * The admin fills in the student + parent details and the page renders
 * a branded fee guide with:
 *   - Student and parent names, grade, joining date
 *   - Admission fee breakdown
 *   - Pro-rated first-month fee (if joining mid-month)
 *   - Recurring fee schedule
 *   - Care plan add-ons
 *   - Annual estimate
 *   - Terms (grace period, late fee)
 *
 * "Download PDF" triggers the browser's print-to-PDF. Print CSS hides
 * the form and formats the document for A4.
 *
 * Can be opened from /crm/view.php (linked with the inquiry id to auto-
 * populate) or standalone.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';

$user = require_login();

$fs = fee_structure();

// Auto-populate from an inquiry if ?inquiry_id is passed.
$prefill = ['child_name' => '', 'parent_name' => '', 'grade' => '', 'join_date' => '', 'frequency' => 'monthly', 'care' => 'none'];
$inquiryId = (int)($_GET['inquiry_id'] ?? 0);
if ($inquiryId > 0) {
    try {
        require_once __DIR__ . '/../includes/crm.php';
        $fam = db()->prepare("SELECT primary_name FROM inquiry_families WHERE id = :id");
        $fam->execute([':id' => $inquiryId]);
        $famRow = $fam->fetch();
        if ($famRow) {
            $prefill['parent_name'] = $famRow['primary_name'];
            $kid = db()->prepare("SELECT first_name, last_name, target_grade FROM inquiry_children WHERE family_id = :id ORDER BY id LIMIT 1");
            $kid->execute([':id' => $inquiryId]);
            $kidRow = $kid->fetch();
            if ($kidRow) {
                $prefill['child_name'] = trim($kidRow['first_name'] . ' ' . ($kidRow['last_name'] ?? ''));
                $g = strtolower((string)($kidRow['target_grade'] ?? ''));
                if (array_key_exists($g, $fs['grades'])) $prefill['grade'] = $g;
            }
        }
    } catch (Throwable $e) {}
}

// Form values (GET so the URL is shareable / bookmarkable).
$childName  = trim((string)($_GET['child_name']  ?? $prefill['child_name']));
$parentName = trim((string)($_GET['parent_name'] ?? $prefill['parent_name']));
$grade      = $_GET['grade']     ?? $prefill['grade'];
$joinDate   = $_GET['join_date'] ?? $prefill['join_date'];
$frequency  = $_GET['frequency'] ?? $prefill['frequency'];
$care       = $_GET['care']      ?? $prefill['care'];

if (!array_key_exists($grade, $fs['grades'])) $grade = '';
if (!in_array($frequency, ['monthly','weekly','quarterly'], true)) $frequency = 'monthly';
if (!array_key_exists($care, $fs['carePlans'])) $care = 'none';

$showGuide = ($childName !== '' && $grade !== '' && $joinDate !== '');

// Calculate
$result = null;
if ($showGuide) {
    $gradeInfo   = $fs['grades'][$grade];
    $carePlan    = $fs['carePlans'][$care];
    $admTotal    = array_sum(array_column($fs['admission'], 'amount'));
    $monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];

    $prorate = fee_prorate($monthlyTotal, $joinDate);

    switch ($frequency) {
        case 'weekly':
            $recurringAmt    = $fs['weeklyRate'];
            $recurringLabel  = 'School Fee (weekly)';
            $recurringPeriod = '/week';
            $annualRecurring = $fs['weeklyRate'] * 52;
            break;
        case 'quarterly':
            $recurringAmt    = $fs['quarterlyRate'];
            $recurringLabel  = 'School Fee (quarterly)';
            $recurringPeriod = '/quarter';
            $annualRecurring = $fs['quarterlyRate'] * 4;
            break;
        default:
            $recurringAmt    = $monthlyTotal;
            $recurringLabel  = 'School Fee + Monthly Billing';
            $recurringPeriod = '/month';
            $annualRecurring = $monthlyTotal * 12;
            break;
    }

    $careMonthly = $carePlan['monthly'] > 0 ? $carePlan['monthly'] + $fs['monthlyBilling'] : 0;
    $ukgMonthly  = $gradeInfo['ukg'] ? $fs['ukgReadiness'] : 0;
    $annualTotal = $admTotal + $annualRecurring + ($careMonthly * 12) + ($ukgMonthly * 12);

    // First payment at admission (Easy Start model)
    $firstPayment = $admTotal;
    if ($prorate['is_partial']) {
        $firstPayment += $prorate['prorated'];
    } else {
        $firstPayment += $monthlyTotal;
    }

    $result = compact(
        'gradeInfo', 'carePlan', 'admTotal', 'monthlyTotal',
        'prorate', 'recurringAmt', 'recurringLabel', 'recurringPeriod',
        'annualRecurring', 'careMonthly', 'ukgMonthly', 'annualTotal',
        'firstPayment'
    );
}

$inr = 'fee_inr';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Fee Guide <?= $childName ? '— ' . e($childName) : '' ?> — Little Graduates</title>
    <style>
        :root {
            --bg: #FFF8F0; --bg-card: #fff; --ink: #2b2f33; --ink-soft: #5a6068;
            --muted: #8b919a; --line: #e6dccb; --accent: #EC407A;
            --radius: 14px; --radius-sm: 8px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--ink); background: var(--bg);
        }
        .wrap { max-width: 700px; margin: 0 auto; padding: 1.2rem 1rem 4rem; }
        .no-print { }
        .card { background: var(--bg-card); border: 1px solid var(--line); border-radius: var(--radius); padding: 1rem 1.2rem; margin-bottom: 1rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .3rem; }
        h2 { font-size: 1rem; margin: .8rem 0 .4rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .04em; }
        label { display: block; font-weight: 600; margin-bottom: .15rem; font-size: .9rem; }
        select, input { width: 100%; padding: .5rem .7rem; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .95rem; margin-bottom: .7rem; }
        .row { display: flex; gap: .8rem; flex-wrap: wrap; }
        .row > * { flex: 1 1 200px; }
        .btn { display: inline-block; padding: .55rem 1.1rem; border-radius: 999px; background: var(--accent); color: #fff; font-weight: 600; border: none; cursor: pointer; font-size: .9rem; text-decoration: none; }
        .btn:hover { background: #d5306a; }
        .btn-outline { background: transparent; color: var(--ink); border: 1px solid var(--line); }
        .btn-outline:hover { border-color: var(--ink-soft); }
        table { width: 100%; border-collapse: collapse; margin: .4rem 0; }
        td, th { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid var(--line); font-size: .9rem; }
        td:last-child, th:last-child { text-align: right; white-space: nowrap; }
        .total td { font-weight: 700; border-top: 2px solid var(--ink); border-bottom: none; font-size: 1rem; }
        .highlight { background: #ecf7e8; }
        .muted { color: var(--muted); }
        .note { font-size: .82rem; color: var(--ink-soft); line-height: 1.5; margin-top: .5rem; }
        .letterhead { text-align: center; margin-bottom: 1rem; padding: .8rem 0; border-bottom: 2px solid var(--accent); }
        .letterhead img { height: 48px; }
        .letterhead .school { font-size: 1.3rem; font-weight: 700; display: block; }
        .letterhead .tagline { font-size: .85rem; color: var(--muted); }
        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem .8rem; font-size: .9rem; margin: .6rem 0; }
        .student-info dt { color: var(--muted); font-weight: 600; }
        .student-info dd { margin: 0; }
        .prorate-box { background: #fff4e1; border: 1px solid #f3dba0; border-radius: var(--radius-sm); padding: .7rem .9rem; margin: .6rem 0; font-size: .9rem; }
        .annual-box { text-align: center; padding: .8rem; margin-top: .6rem; }
        .annual-box .big { font-size: 1.4rem; font-weight: 700; color: var(--accent); }
        .terms { font-size: .8rem; color: var(--ink-soft); line-height: 1.6; }
        .terms li { margin-bottom: .2rem; }
        .actions-bar { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .8rem; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; font-size: 12pt; }
            .wrap { max-width: 100%; padding: 0; }
            .card { border: none; box-shadow: none; padding: .5rem 0; margin-bottom: .5rem; break-inside: avoid; }
            .letterhead { border-bottom-color: #000; }
            .highlight { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .prorate-box { background: #fff8e8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="wrap">

<div class="card no-print">
    <h1>Generate Parent Fee Guide</h1>
    <p class="muted">Fill in the student details. The fee guide will appear below — use "Download PDF" to save or share it.</p>
    <form method="get">
        <?php if ($inquiryId): ?>
            <input type="hidden" name="inquiry_id" value="<?= $inquiryId ?>">
        <?php endif; ?>
        <div class="row">
            <div>
                <label for="child_name">Child's Name</label>
                <input id="child_name" name="child_name" value="<?= e($childName) ?>" required placeholder="e.g. Aanya Krishnan">
            </div>
            <div>
                <label for="parent_name">Parent / Guardian Name</label>
                <input id="parent_name" name="parent_name" value="<?= e($parentName) ?>" required placeholder="e.g. Vinod Krishnan">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="grade">Grade</label>
                <select id="grade" name="grade" required>
                    <option value="">Select…</option>
                    <?php foreach ($fs['grades'] as $code => $g): ?>
                        <option value="<?= e($code) ?>" <?= $grade === $code ? 'selected' : '' ?>><?= e($g['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="join_date">Joining Date</label>
                <input id="join_date" name="join_date" type="date" value="<?= e($joinDate) ?>" required>
            </div>
        </div>
        <div class="row">
            <div>
                <label for="frequency">Payment Frequency</label>
                <select id="frequency" name="frequency">
                    <option value="monthly"   <?= $frequency === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
                    <option value="weekly"    <?= $frequency === 'weekly'    ? 'selected' : '' ?>>Weekly</option>
                    <option value="quarterly" <?= $frequency === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                </select>
            </div>
            <div>
                <label for="care">Care Plan</label>
                <select id="care" name="care">
                    <?php foreach ($fs['carePlans'] as $code => $cp): ?>
                        <option value="<?= e($code) ?>" <?= $care === $code ? 'selected' : '' ?>>
                            <?= e($cp['label']) ?><?= $cp['monthly'] ? ' (+' . e($inr($cp['monthly'])) . '/mo)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn" type="submit">Generate Fee Guide</button>
    </form>
</div>

<?php if ($showGuide && $result): ?>

<!-- ===== PRINTABLE FEE GUIDE ===== -->
<div id="fee-guide">
    <div class="card">
        <div class="letterhead">
            <img src="/assets/img/logo.png" alt="" onerror="this.style.display='none'">
            <span class="school">Little Graduates</span>
            <span class="tagline">Montessori Early Learning Centre · Kochi, Kerala</span>
        </div>

        <h1 style="text-align:center; margin-bottom:.2rem;">Parent Fee Guide 2026-27</h1>
        <p class="muted" style="text-align:center; margin:0 0 .8rem;">Personalised fee estimate prepared on <?= e(date('j F Y')) ?></p>

        <dl class="student-info">
            <dt>Student</dt>     <dd><strong><?= e($childName) ?></strong></dd>
            <dt>Parent / Guardian</dt> <dd><?= e($parentName) ?></dd>
            <dt>Programme</dt>   <dd><?= e($result['gradeInfo']['label']) ?></dd>
            <dt>Joining Date</dt><dd><?= e(date('j F Y', strtotime($joinDate))) ?></dd>
            <dt>Payment Plan</dt><dd><?= e(ucfirst($frequency)) ?></dd>
            <?php if ($care !== 'none'): ?>
                <dt>Care Plan</dt><dd><?= e($result['carePlan']['label']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="card">
        <h2>1. One-time Admission Fees</h2>
        <table>
            <?php foreach ($fs['admission'] as $f): ?>
                <tr><td><?= e($f['name']) ?></td><td><?= e($inr($f['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total admission</td><td><?= e($inr($result['admTotal'])) ?></td></tr>
        </table>
    </div>

    <?php if ($result['prorate']['is_partial']): ?>
    <div class="card">
        <h2>2. First Month (Pro-rated)</h2>
        <div class="prorate-box">
            <strong>Joining on <?= e(date('j F', strtotime($joinDate))) ?></strong> — <?= (int)$result['prorate']['days_remaining'] ?> days
            remaining in the month (of <?= (int)$result['prorate']['days_in_month'] ?> days).
            <br>
            Pro-rated school fee: <?= e($inr($result['monthlyTotal'])) ?> × <?= (int)$result['prorate']['days_remaining'] ?>/<?= (int)$result['prorate']['days_in_month'] ?>
            = <strong><?= e($inr($result['prorate']['prorated'])) ?></strong>
        </div>
        <table>
            <tr><td>Admission fees</td><td><?= e($inr($result['admTotal'])) ?></td></tr>
            <tr><td>First month (pro-rated)</td><td><?= e($inr($result['prorate']['prorated'])) ?></td></tr>
            <tr class="total"><td>Amount due at joining</td><td><?= e($inr($result['firstPayment'])) ?></td></tr>
        </table>
        <p class="note">Regular billing starts from the 1st of the following month.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <h2>2. Amount Due at Joining</h2>
        <table>
            <tr><td>Admission fees</td><td><?= e($inr($result['admTotal'])) ?></td></tr>
            <tr><td>First month school fee</td><td><?= e($inr($result['monthlyTotal'])) ?></td></tr>
            <tr class="total"><td>Total due at joining</td><td><?= e($inr($result['firstPayment'])) ?></td></tr>
        </table>
    </div>
    <?php endif; ?>

    <div class="card highlight">
        <h2>3. Recurring Fees</h2>
        <table>
            <tr>
                <td><?= e($result['recurringLabel']) ?></td>
                <td><?= e($inr($result['recurringAmt'])) ?> <?= e($result['recurringPeriod']) ?></td>
            </tr>
            <?php if ($result['careMonthly'] > 0): ?>
                <tr>
                    <td><?= e($result['carePlan']['label']) ?> (+billing)</td>
                    <td><?= e($inr($result['careMonthly'])) ?> /month</td>
                </tr>
            <?php endif; ?>
            <?php if ($result['ukgMonthly'] > 0): ?>
                <tr>
                    <td>UKG Readiness Programme</td>
                    <td><?= e($inr($result['ukgMonthly'])) ?> /month</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="card">
        <h2>4. Annual Estimate</h2>
        <div class="annual-box">
            <div class="big"><?= e($inr($result['annualTotal'])) ?></div>
            <div class="muted">Estimated total for the academic year</div>
        </div>
        <table style="margin-top:.5rem;">
            <tr><td>Admission (one-time)</td><td><?= e($inr($result['admTotal'])) ?></td></tr>
            <tr><td>School fee (12 months)</td><td>~<?= e($inr($result['annualRecurring'])) ?></td></tr>
            <?php if ($result['careMonthly'] > 0): ?>
                <tr><td>Care plan (12 months)</td><td>~<?= e($inr($result['careMonthly'] * 12)) ?></td></tr>
            <?php endif; ?>
            <?php if ($result['ukgMonthly'] > 0): ?>
                <tr><td>UKG Readiness (12 months)</td><td>~<?= e($inr($result['ukgMonthly'] * 12)) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="card">
        <h2>5. Terms & Payment Info</h2>
        <ul class="terms">
            <li>Fees are collected via the <strong>CoFee app</strong>. Payment links are sent to your registered phone number.</li>
            <li>Grace period: <strong><?= (int)$fs['graceDays'] ?> days</strong> from the due date.</li>
            <li>Late payment fee: <strong><?= e($inr($fs['lateFee'])) ?></strong> after the grace period.</li>
            <li>You may switch between monthly and weekly plans from the next billing cycle. Inform the office in advance.</li>
            <li>Fees are non-refundable once the billing cycle has begun.</li>
            <li>Amounts are for the 2026-27 academic year and subject to revision for subsequent years.</li>
        </ul>
    </div>

    <div class="card" style="text-align:center;">
        <p class="muted" style="margin:0;">Little Graduates · Kochi, Kerala · <?= e(date('Y')) ?></p>
    </div>
</div>

<div class="actions-bar no-print">
    <button class="btn" onclick="window.print()">Download PDF</button>
    <a class="btn btn-outline" href="/fees_calculator.php?grade=<?= e($grade) ?>&frequency=<?= e($frequency) ?>&care=<?= e($care) ?>" target="_blank">Open calculator</a>
</div>

<?php endif; ?>

</div>
</body>
</html>
