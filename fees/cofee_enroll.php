<?php
/**
 * fees/cofee_enroll.php — CoFee enrollment wizard.
 *
 * Input a child's details → get exact step-by-step instructions for
 * what to do in CoFee: which groups to add them to, what fee_amount
 * to set, what start_date to use, in what order.
 *
 * Replaces the static admin guide with a practical, per-child tool.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';

$user = require_module('fees');

$fs = fee_structure();
$monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];
$admTotal     = array_sum(array_column($fs['admission'], 'amount'));

// Auto-populate from inquiry if linked.
$inquiryId = (int)($_GET['inquiry_id'] ?? 0);
$prefill = ['child_name' => '', 'parent_name' => '', 'parent_phone' => '', 'parent_email' => '', 'grade' => '', 'join_date' => '', 'frequency' => 'monthly', 'care' => 'none'];
if ($inquiryId > 0) {
    try {
        require_once __DIR__ . '/../includes/crm.php';
        $fam = db()->prepare("SELECT primary_name, primary_phone, primary_email FROM inquiry_families WHERE id = :id");
        $fam->execute([':id' => $inquiryId]);
        $famRow = $fam->fetch();
        if ($famRow) {
            $prefill['parent_name']  = $famRow['primary_name'];
            $prefill['parent_phone'] = $famRow['primary_phone'] ?? '';
            $prefill['parent_email'] = $famRow['primary_email'] ?? '';
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

$childName   = trim((string)($_GET['child_name']   ?? $prefill['child_name']));
$parentName  = trim((string)($_GET['parent_name']  ?? $prefill['parent_name']));
$parentPhone = trim((string)($_GET['parent_phone'] ?? $prefill['parent_phone']));
$parentEmail = trim((string)($_GET['parent_email'] ?? $prefill['parent_email']));
$grade       = $_GET['grade']     ?? $prefill['grade'];
$joinDate    = $_GET['join_date'] ?? $prefill['join_date'];
$frequency   = $_GET['frequency'] ?? $prefill['frequency'];
$care        = $_GET['care']      ?? $prefill['care'];

if (!array_key_exists($grade, $fs['grades'])) $grade = '';
if (!in_array($frequency, ['monthly','weekly','quarterly'], true)) $frequency = 'monthly';
if (!array_key_exists($care, $fs['carePlans'])) $care = 'none';

$showSteps = ($childName !== '' && $grade !== '' && $joinDate !== '');

// Build the enrollment steps.
$steps = [];
if ($showSteps) {
    $gradeInfo  = $fs['grades'][$grade];
    $carePlan   = $fs['carePlans'][$care];
    $joinDt     = new DateTime($joinDate);
    $joinDay    = (int)$joinDt->format('j');
    $daysInMonth = (int)$joinDt->format('t');
    $daysRemaining = $daysInMonth - $joinDay + 1;
    $isPartial  = ($joinDay > 1);
    $nextMonth  = (clone $joinDt)->modify('first day of next month');
    $memberName = $childName . ' - ' . ($parentName ?: 'Parent');
    $dueDay     = (int)$fs['paymentDueDay'];

    // Step 1: Create the member
    $steps[] = [
        'title' => 'Create the member in CoFee',
        'icon'  => '1',
        'where' => 'Members → Add Member',
        'fields' => [
            'Name'             => $memberName,
            'Phone'            => $parentPhone ?: '(parent phone with country code 91)',
            'Email'            => $parentEmail ?: '(parent email)',
            'Admission Date'   => $joinDt->format('j F Y'),
        ],
        'note' => 'If the member already exists in the roster, skip this step.',
    ];

    // Step 2: Add to Joining Fees group
    $admissionBreakdown = [];
    foreach ($fs['admission'] as $f) {
        $admissionBreakdown[] = $f['name'] . ': ' . fee_inr($f['amount']);
    }
    $steps[] = [
        'title'  => 'Add to "Joining Fees 2026-27" group',
        'icon'   => '2',
        'where'  => 'Groups → Joining Fees 2026-27 → Add Member',
        'fields' => [
            'Member'           => $memberName,
            'fee_amount'       => fee_inr($admTotal),
            'start_date'       => $joinDt->format('Y-m-d'),
            'interval'         => 'once',
            'execution_type'   => 'immediate',
        ],
        'note' => 'This triggers the one-time admission charge of ' . fee_inr($admTotal)
                . ' (' . implode(' + ', $admissionBreakdown) . ').'
                . ' A payment link is sent to the parent immediately.',
    ];

    // Step 3: Add to the recurring school fee group
    $recurringGroupName = '';
    $recurringAmt       = 0;
    $recurringInterval  = '';
    $recurringConfig    = '';
    $recurringStartDate = '';
    $recurringStartNote = '';

    switch ($frequency) {
        case 'weekly':
            $recurringGroupName = 'School Fee — Weekly';
            $recurringAmt       = $fs['weeklyRate'];
            $recurringInterval  = 'weekly';
            $recurringConfig    = 'day_option: monday';
            $nextMonday = (clone $joinDt);
            if ((int)$nextMonday->format('N') !== 1) {
                $nextMonday->modify('next monday');
            }
            $recurringStartDate = $nextMonday->format('Y-m-d');
            $recurringStartNote = 'First weekly charge on ' . $nextMonday->format('l, j M Y');
            break;

        case 'quarterly':
            $recurringGroupName = 'School Fee — Quarterly';
            $recurringAmt       = $fs['quarterlyRate'];
            $recurringInterval  = 'monthly (interval_frequency: 3)';
            $recurringConfig    = 'day_option: first_day';
            $recurringStartDate = $nextMonth->format('Y-m-d');
            $recurringStartNote = 'First quarterly charge on ' . $nextMonth->format('j M Y')
                                . '. Subsequent charges every 3 months.';
            break;

        default: // monthly
            $recurringGroupName = 'School Fee — Monthly';
            $recurringAmt       = $monthlyTotal;
            $recurringInterval  = 'monthly';
            $recurringConfig    = 'day_option: first_day (or custom_day: ' . $dueDay . ')';
            $recurringStartDate = $nextMonth->format('Y-m-d');
            $recurringStartNote = $isPartial
                ? 'Starts from ' . $nextMonth->format('j M Y') . ' (joining month is covered by the admission payment — no double billing).'
                : 'Starts from ' . $joinDt->format('j M Y') . ' (joined on the 1st, so first charge is this month).';
            break;
    }

    // Care add-on: adjust the member's fee_amount in the recurring group
    $careExtra = 0;
    $careLine  = '';
    if ($carePlan['monthly'] > 0) {
        $careExtra = $carePlan['monthly'] + $fs['monthlyBilling'];
        $careLine = $carePlan['label'] . ' (+' . fee_inr($carePlan['monthly']) . ' + ' . fee_inr($fs['monthlyBilling']) . ' billing)';
        if ($frequency === 'monthly') {
            $recurringAmt += $careExtra;
        }
    }

    $step3Fields = [
        'Member'           => $memberName,
        'fee_amount'       => fee_inr($recurringAmt),
        'start_date'       => $recurringStartDate,
        'interval'         => $recurringInterval,
        'config'           => $recurringConfig,
        'execution_type'   => 'due_date',
    ];

    $step3Note = $recurringStartNote;
    if ($careExtra > 0 && $frequency === 'monthly') {
        $step3Note .= "\n\nfee_amount includes care add-on: " . fee_inr($monthlyTotal) . ' (school) + ' . fee_inr($careExtra) . ' (' . $careLine . ') = ' . fee_inr($recurringAmt) . '.';
    }

    $steps[] = [
        'title'  => 'Add to "' . $recurringGroupName . '" group',
        'icon'   => '3',
        'where'  => 'Groups → ' . $recurringGroupName . ' → Add Member',
        'fields' => $step3Fields,
        'note'   => $step3Note,
    ];

    // Step 3b: If care is a separate group (alternative approach)
    if ($careExtra > 0 && $frequency !== 'monthly') {
        $steps[] = [
            'title'  => 'Add care plan (separate group OR per-member override)',
            'icon'   => '3b',
            'where'  => 'Groups → Care — ' . $carePlan['label'] . ' → Add Member',
            'fields' => [
                'Member'         => $memberName,
                'fee_amount'     => fee_inr($careExtra),
                'start_date'     => $recurringStartDate,
                'interval'       => 'monthly',
                'execution_type' => 'due_date',
            ],
            'note' => 'Care plans are always monthly. Since the school fee is ' . $frequency . ', the care is a separate monthly charge.'
                     . "\nAlternatively: add the care cost to the member's fee_amount in the school fee group as a per-member override.",
        ];
    }

    // Step 4: UKG Readiness (if applicable)
    if ($gradeInfo['ukg']) {
        $steps[] = [
            'title'  => 'Add to "UKG Readiness" group',
            'icon'   => '4',
            'where'  => 'Groups → UKG Readiness → Add Member',
            'fields' => [
                'Member'         => $memberName,
                'fee_amount'     => fee_inr($fs['ukgReadiness']),
                'start_date'     => $recurringStartDate,
                'interval'       => 'monthly',
                'execution_type' => 'due_date',
            ],
            'note' => 'UKG Readiness Programme — ' . fee_inr($fs['ukgReadiness']) . '/month, Level-3 children only.',
        ];
    }

    // Summary step
    $totalGroups = [];
    $totalGroups[] = 'Joining Fees: ' . fee_inr($admTotal) . ' (once)';
    $totalGroups[] = $recurringGroupName . ': ' . fee_inr($recurringAmt) . ' ' . $frequency;
    if ($careExtra > 0 && $frequency !== 'monthly') {
        $totalGroups[] = 'Care — ' . $carePlan['label'] . ': ' . fee_inr($careExtra) . '/month';
    }
    if ($gradeInfo['ukg']) {
        $totalGroups[] = 'UKG Readiness: ' . fee_inr($fs['ukgReadiness']) . '/month';
    }

    $steps[] = [
        'title'  => 'Verify — child should now be in these groups',
        'icon'   => '✓',
        'where'  => 'Members → ' . $memberName . ' → Groups tab',
        'fields' => [],
        'note'   => implode("\n", $totalGroups)
                   . "\n\nCheck the member's profile: they should appear in all the groups above."
                   . ' The first payment link (admission ' . fee_inr($admTotal) . ') should already be sent.',
        'groups' => $totalGroups,
    ];
}

$inr = 'fee_inr';
$pageTitle = 'CoFee Enrollment Wizard';
require __DIR__ . '/../includes/header.php';
?>

<style>
    .wizard-step { background: var(--bg-card); border: 1px solid var(--line); border-radius: var(--radius); padding: 1rem 1.2rem; margin-bottom: .8rem; position: relative; }
    .wizard-step.step-verify { border-color: var(--accent-2); border-width: 2px; }
    .step-badge { position: absolute; top: -.6rem; left: 1rem; background: var(--accent); color: #fff; font-weight: 700; font-size: .85rem; width: 2rem; height: 2rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .step-verify .step-badge { background: var(--accent-2); }
    .step-title { font-size: 1rem; font-weight: 700; margin: .3rem 0 .5rem; padding-left: 2.2rem; }
    .step-where { font-size: .82rem; color: var(--muted); margin-bottom: .5rem; padding-left: 2.2rem; }
    .step-fields { display: grid; grid-template-columns: 140px 1fr; gap: .2rem .6rem; font-size: .9rem; margin-bottom: .5rem; background: #f8f5ef; padding: .6rem .8rem; border-radius: 8px; }
    .step-fields dt { color: var(--muted); font-weight: 600; }
    .step-fields dd { margin: 0; font-family: ui-monospace, "SF Mono", Menlo, monospace; word-break: break-all; }
    .step-note { font-size: .85rem; color: var(--ink-soft); white-space: pre-line; line-height: 1.5; }
    .copy-val { cursor: pointer; border-bottom: 1px dashed var(--muted); }
    .copy-val:hover { color: var(--accent); }
</style>

<div class="page-head">
    <div>
        <h1>CoFee Enrollment Wizard</h1>
        <p class="muted">Input child details → get exact CoFee instructions</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/fees/index.php">← Fees</a>
        <?php if ($showSteps): ?>
            <button class="btn" onclick="window.print()">Print steps</button>
        <?php endif; ?>
    </div>
</div>

<div class="card no-print">
    <form method="get">
        <?php if ($inquiryId): ?><input type="hidden" name="inquiry_id" value="<?= $inquiryId ?>"><?php endif; ?>
        <div class="row">
            <div><label>Child's Name</label><input name="child_name" value="<?= e($childName) ?>" required placeholder="e.g. Aanya"></div>
            <div><label>Parent Name</label><input name="parent_name" value="<?= e($parentName) ?>" required placeholder="e.g. Vinod Krishnan"></div>
        </div>
        <div class="row">
            <div><label>Parent Phone</label><input name="parent_phone" value="<?= e($parentPhone) ?>" placeholder="+91 90000 00000"></div>
            <div><label>Parent Email</label><input name="parent_email" value="<?= e($parentEmail) ?>" placeholder="parent@email.com"></div>
        </div>
        <div class="row">
            <div><label>Grade</label>
                <select name="grade" required>
                    <option value="">Select…</option>
                    <?php foreach ($fs['grades'] as $code => $g): ?>
                        <option value="<?= e($code) ?>" <?= $grade === $code ? 'selected' : '' ?>><?= e($g['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Joining Date</label><input name="join_date" type="date" value="<?= e($joinDate) ?>" required></div>
        </div>
        <div class="row">
            <div><label>Payment Plan</label>
                <select name="frequency">
                    <option value="monthly"   <?= $frequency === 'monthly'   ? 'selected' : '' ?>>Monthly (<?= e($inr($monthlyTotal)) ?>/mo)</option>
                    <option value="weekly"    <?= $frequency === 'weekly'    ? 'selected' : '' ?>>Weekly (<?= e($inr($fs['weeklyRate'])) ?>/wk)</option>
                    <option value="quarterly" <?= $frequency === 'quarterly' ? 'selected' : '' ?>>Quarterly (<?= e($inr($fs['quarterlyRate'])) ?>/qtr)</option>
                </select>
            </div>
            <div><label>Care Plan</label>
                <select name="care">
                    <?php foreach ($fs['carePlans'] as $code => $cp): ?>
                        <option value="<?= e($code) ?>" <?= $care === $code ? 'selected' : '' ?>>
                            <?= e($cp['label']) ?><?= $cp['monthly'] ? ' (+' . e($inr($cp['monthly'])) . '/mo)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:.6rem;">Show enrollment steps</button>
    </form>
</div>

<?php if ($showSteps): ?>
<div style="margin-top:1rem;">
    <?php foreach ($steps as $step): ?>
        <div class="wizard-step <?= ($step['icon'] === '✓') ? 'step-verify' : '' ?>">
            <span class="step-badge"><?= e($step['icon']) ?></span>
            <div class="step-title"><?= e($step['title']) ?></div>
            <div class="step-where">📍 <?= e($step['where']) ?></div>
            <?php if ($step['fields']): ?>
                <dl class="step-fields">
                    <?php foreach ($step['fields'] as $k => $v): ?>
                        <dt><?= e($k) ?></dt>
                        <dd><span class="copy-val" title="Click to copy" onclick="navigator.clipboard.writeText(this.textContent)"><?= e($v) ?></span></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <div class="step-note"><?= e($step['note']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
