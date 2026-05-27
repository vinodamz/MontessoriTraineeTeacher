<?php
/**
 * fees_calculator.php — public fee calculator for parents.
 *
 * No authentication — this is a shareable link the admissions team
 * sends via WhatsApp so parents can explore the fee structure before
 * visiting. Mobile-first responsive layout.
 *
 * Fee amounts are from the Little Graduates Parent Fee Guide 2026-27
 * and the CoFee platform configuration.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// ============================================================================
// Fee structure — 2026-27. Edit this section when fees change.
// ============================================================================

$admissionFees = [
    ['name' => 'Registration / Admission Fee', 'amount' => 7500],
    ['name' => 'Yearly Starter Kit',           'amount' => 6500],
    ['name' => 'Annual Resource Renewal',      'amount' => 5000],
];
$admissionTotal = array_sum(array_column($admissionFees, 'amount'));

$schoolFeeMonthly  = 7900;
$monthlyBilling    = 300;
$monthlyTotal      = $schoolFeeMonthly + $monthlyBilling;
$weeklyRate        = 1975;
$quarterlyRate     = 22800;

$carePlans = [
    'none'       => ['label' => 'No extra care (half day)',  'monthly' => 0],
    'rest'       => ['label' => 'Rest Care (stay for nap)',  'monthly' => 1500],
    'enrichment' => ['label' => 'Enrichment (till 3:30 PM)', 'monthly' => 3800],
    'fullday'    => ['label' => 'Full Day (till 5:00 PM)',   'monthly' => 5500],
];

$ukgReadiness = 1500; // Level-3 / UKG only

$grades = [
    'playgroup' => ['label' => 'Playgroup (1.5–2.5 yrs)',   'level' => 0, 'ukg' => false],
    'nursery'   => ['label' => 'Nursery (2.5–3.5 yrs)',     'level' => 1, 'ukg' => false],
    'lkg'       => ['label' => 'LKG (3.5–4.5 yrs)',         'level' => 2, 'ukg' => false],
    'ukg'       => ['label' => 'UKG (4.5–5.5 yrs)',         'level' => 3, 'ukg' => true],
];

// ============================================================================
// Calculate
// ============================================================================
$selectedGrade     = $_GET['grade']     ?? '';
$selectedFrequency = $_GET['frequency'] ?? 'monthly';
$selectedCare      = $_GET['care']      ?? 'none';

if (!array_key_exists($selectedGrade, $grades))     $selectedGrade = '';
if (!in_array($selectedFrequency, ['monthly','weekly','quarterly'], true)) $selectedFrequency = 'monthly';
if (!array_key_exists($selectedCare, $carePlans))   $selectedCare = 'none';

$result = null;
if ($selectedGrade !== '') {
    $gradeInfo = $grades[$selectedGrade];
    $carePlan  = $carePlans[$selectedCare];

    // Recurring fee
    $recurringBase   = 0;
    $recurringLabel  = '';
    $recurringPeriod = '';
    switch ($selectedFrequency) {
        case 'weekly':
            $recurringBase   = $weeklyRate;
            $recurringLabel  = 'School Fee (weekly)';
            $recurringPeriod = '/week';
            break;
        case 'quarterly':
            $recurringBase   = $quarterlyRate;
            $recurringLabel  = 'School Fee (quarterly)';
            $recurringPeriod = '/quarter';
            break;
        case 'monthly':
        default:
            $recurringBase   = $monthlyTotal;
            $recurringLabel  = 'School Fee + Monthly Billing';
            $recurringPeriod = '/month';
            break;
    }

    // Care add-on (monthly rate; for weekly/quarterly we show the monthly equiv)
    $careMonthly = $carePlan['monthly'] + $monthlyBilling;
    if ($carePlan['monthly'] === 0) $careMonthly = 0;

    // UKG Readiness (monthly)
    $ukgMonthly = $gradeInfo['ukg'] ? $ukgReadiness : 0;

    // For monthly: total = recurringBase + careMonthly + ukgMonthly
    // For weekly/quarterly: care is still monthly, so we show both lines
    $recurringTotal = $recurringBase;
    $addOns = [];
    if ($careMonthly > 0) {
        $addOns[] = ['name' => $carePlan['label'] . ' (+billing)', 'amount' => $careMonthly, 'period' => '/month'];
    }
    if ($ukgMonthly > 0) {
        $addOns[] = ['name' => 'UKG Readiness Programme', 'amount' => $ukgMonthly, 'period' => '/month'];
    }

    // Annual estimate
    $annualRecurring = 0;
    switch ($selectedFrequency) {
        case 'weekly':    $annualRecurring = $weeklyRate * 52; break;
        case 'quarterly': $annualRecurring = $quarterlyRate * 4; break;
        default:          $annualRecurring = $monthlyTotal * 12; break;
    }
    $annualCare = $careMonthly * 12;
    $annualUkg  = $ukgMonthly * 12;
    $annualTotal = $admissionTotal + $annualRecurring + $annualCare + $annualUkg;

    $result = compact(
        'gradeInfo', 'carePlan',
        'recurringBase', 'recurringLabel', 'recurringPeriod', 'recurringTotal',
        'addOns', 'careMonthly', 'ukgMonthly',
        'annualRecurring', 'annualCare', 'annualUkg', 'annualTotal'
    );
}

$inr = fn(int $v): string => '₹' . number_format($v);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFF8F0">
    <title>Fee Calculator — Little Graduates</title>
    <style>
        :root {
            --bg: #FFF8F0; --bg-card: #fff; --ink: #2b2f33; --ink-soft: #5a6068;
            --muted: #8b919a; --line: #e6dccb; --accent: #EC407A; --accent2: #5BA547;
            --radius: 14px; --radius-sm: 8px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--ink); background: var(--bg); min-height: 100vh;
        }
        .wrap { max-width: 640px; margin: 0 auto; padding: 1.2rem 1rem 4rem; }
        .logo-bar { text-align: center; padding: 1rem 0; }
        .logo-bar img { height: 48px; }
        .logo-bar .brand { font-size: 1.2rem; font-weight: 700; display: block; margin-top: .3rem; }
        h1 { font-size: 1.5rem; text-align: center; margin: 0 0 .4rem; }
        .sub { text-align: center; color: var(--muted); margin-bottom: 1.5rem; }
        .card { background: var(--bg-card); border: 1px solid var(--line); border-radius: var(--radius); padding: 1rem 1.2rem; margin-bottom: 1rem; }
        h2 { font-size: 1.05rem; margin: 0 0 .5rem; }
        label { display: block; font-weight: 600; margin-bottom: .15rem; font-size: .9rem; }
        select, input { width: 100%; padding: .55rem .75rem; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .95rem; margin-bottom: .8rem; }
        .btn { display: inline-block; padding: .6rem 1.2rem; border-radius: 999px; background: var(--accent); color: #fff; font-weight: 600; border: none; cursor: pointer; font-size: .95rem; width: 100%; text-align: center; }
        .btn:hover { background: #d5306a; }
        table { width: 100%; border-collapse: collapse; margin: .5rem 0; }
        td, th { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid var(--line); font-size: .9rem; }
        td:last-child, th:last-child { text-align: right; }
        .total td { font-weight: 700; border-top: 2px solid var(--ink); border-bottom: none; font-size: 1rem; }
        .highlight { background: #ecf7e8; }
        .muted { color: var(--muted); }
        .pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; background: #eef1f4; font-size: .75rem; font-weight: 600; }
        .pill-green { background: #d8f3c8; color: #14532d; }
        .note { font-size: .82rem; color: var(--ink-soft); margin-top: .5rem; line-height: 1.5; }
        .annual-box { background: #fff4e1; border: 1px solid #f3dba0; border-radius: var(--radius-sm); padding: .8rem 1rem; margin-top: .8rem; text-align: center; }
        .annual-box .big { font-size: 1.5rem; font-weight: 700; color: var(--accent); }
        .annual-box .label { font-size: .85rem; color: var(--ink-soft); }
        footer { text-align: center; color: var(--muted); font-size: .8rem; padding: 1.5rem 0; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #1a1a1a; --bg-card: #2a2a2a; --ink: #e8e4de; --ink-soft: #b0ada6; --line: #3a3a3a; --muted: #888; }
            select, input { background: #333; color: var(--ink); border-color: #444; }
            .highlight { background: #1e3620; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo-bar">
        <img src="/assets/img/logo.png" alt="Little Graduates" onerror="this.style.display='none'">
        <span class="brand">Little Graduates</span>
    </div>

    <h1>Fee Calculator 2026-27</h1>
    <p class="sub">Explore our fee structure. Pick your child's grade and preferences below.</p>

    <form method="get" class="card">
        <label for="grade">Grade / Programme</label>
        <select id="grade" name="grade" required>
            <option value="">Select grade…</option>
            <?php foreach ($grades as $code => $g): ?>
                <option value="<?= e($code) ?>" <?= $selectedGrade === $code ? 'selected' : '' ?>><?= e($g['label']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="frequency">Payment Frequency</label>
        <select id="frequency" name="frequency">
            <option value="monthly"   <?= $selectedFrequency === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
            <option value="weekly"    <?= $selectedFrequency === 'weekly'    ? 'selected' : '' ?>>Weekly</option>
            <option value="quarterly" <?= $selectedFrequency === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
        </select>

        <label for="care">Care Plan (optional)</label>
        <select id="care" name="care">
            <?php foreach ($carePlans as $code => $cp): ?>
                <option value="<?= e($code) ?>" <?= $selectedCare === $code ? 'selected' : '' ?>>
                    <?= e($cp['label']) ?><?= $cp['monthly'] ? ' (+' . e($inr($cp['monthly'])) . '/mo)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn" type="submit">Calculate</button>
    </form>

    <?php if ($result): ?>
    <div class="card">
        <h2>One-time admission fees</h2>
        <table>
            <?php foreach ($admissionFees as $f): ?>
                <tr><td><?= e($f['name']) ?></td><td><?= e($inr($f['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total at admission</td><td><?= e($inr($admissionTotal)) ?></td></tr>
        </table>
    </div>

    <div class="card highlight">
        <h2>Recurring fees</h2>
        <table>
            <tr>
                <td><?= e($result['recurringLabel']) ?></td>
                <td><?= e($inr($result['recurringBase'])) ?> <span class="pill"><?= e($result['recurringPeriod']) ?></span></td>
            </tr>
            <?php foreach ($result['addOns'] as $a): ?>
                <tr>
                    <td><?= e($a['name']) ?></td>
                    <td><?= e($inr($a['amount'])) ?> <span class="pill"><?= e($a['period']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($selectedFrequency !== 'monthly' && ($result['careMonthly'] || $result['ukgMonthly'])): ?>
            <p class="note">Care plan and UKG Readiness are billed monthly regardless of your school-fee frequency.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="annual-box">
            <div class="label">Estimated annual total (12 months)</div>
            <div class="big"><?= e($inr($result['annualTotal'])) ?></div>
            <div class="label">
                Admission <?= e($inr($admissionTotal)) ?>
                + Recurring ~<?= e($inr($result['annualTotal'] - $admissionTotal)) ?>
            </div>
        </div>
        <p class="note">
            This is an estimate. Actual amounts depend on your joining date and the number of billing cycles.
            Weekly plans have ~52 billing cycles/year vs 12 for monthly.
            Grace period: 7 days from the due date; late fee of ₹500 applies after.
            Fees can be paid via the CoFee app. For details, visit us or call.
        </p>
    </div>

    <div class="card" style="text-align:center;">
        <p style="margin: 0 0 .4rem;"><strong>Ready to visit?</strong></p>
        <p class="muted" style="margin: 0 0 .6rem;">Schedule a school tour — we'd love to show you around.</p>
        <a href="https://wa.me/919567036027?text=Hi%2C%20I%20used%20the%20fee%20calculator%20and%20I'm%20interested%20in%20visiting%20Little%20Graduates%20for%20my%20child."
           class="btn" style="background: #25d366; display:inline-block; width:auto; padding:.55rem 1.5rem; text-decoration:none;">
            WhatsApp us
        </a>
    </div>
    <?php endif; ?>
</div>

<footer>Little Graduates · Kochi, Kerala · 2026-27 Academic Year</footer>
</body>
</html>
