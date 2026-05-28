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
require_once __DIR__ . '/../includes/cofee_api.php';

$user = require_module('fees');
$cofeeReady = cofee_is_configured();

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

    // Pro-rate calculation for the joining month.
    $prorate = fee_prorate($monthlyTotal, $joinDate);
    $proratedFee = $prorate['prorated'];
    $proratedCare = 0;
    $proratedUkg  = 0;
    $carePlanMonthly = $fs['carePlans'][$care]['monthly'] ?? 0;
    if ($carePlanMonthly > 0) {
        $careTotal = $carePlanMonthly + $fs['monthlyBilling'];
        $proratedCare = $isPartial
            ? (int)round($careTotal * $daysRemaining / $daysInMonth)
            : $careTotal;
    }
    if ($gradeInfo['ukg']) {
        $proratedUkg = $isPartial
            ? (int)round($fs['ukgReadiness'] * $daysRemaining / $daysInMonth)
            : $fs['ukgReadiness'];
    }
    $firstMonthTotal = $proratedFee + $proratedCare + $proratedUkg;

    // Step 2: Add to Joining Fees group (admission + pro-rated first month)
    $joiningTotal = $admTotal + $firstMonthTotal;
    $admissionBreakdown = [];
    foreach ($fs['admission'] as $f) {
        $admissionBreakdown[] = $f['name'] . ': ' . fee_inr($f['amount']);
    }
    $joiningNote = 'This triggers the one-time charge of ' . fee_inr($joiningTotal) . ":\n";
    $joiningNote .= '  Admission: ' . fee_inr($admTotal) . ' (' . implode(' + ', $admissionBreakdown) . ")\n";
    if ($isPartial) {
        $joiningNote .= '  First month (pro-rated ' . $daysRemaining . '/' . $daysInMonth . ' days): '
                      . fee_inr($monthlyTotal) . ' × ' . $daysRemaining . '/' . $daysInMonth
                      . ' = ' . fee_inr($proratedFee);
    } else {
        $joiningNote .= '  First month school fee: ' . fee_inr($monthlyTotal);
    }
    if ($proratedCare > 0) {
        $joiningNote .= "\n  Care plan (" . ($isPartial ? 'pro-rated' : $carePlan['label']) . '): ' . fee_inr($proratedCare);
    }
    if ($proratedUkg > 0) {
        $joiningNote .= "\n  UKG Readiness (" . ($isPartial ? 'pro-rated' : 'full month') . '): ' . fee_inr($proratedUkg);
    }
    $joiningNote .= "\n\nA payment link for " . fee_inr($joiningTotal) . ' is sent to the parent immediately.'
                  . "\nThe recurring group (step 3) starts from " . $nextMonth->format('j M Y') . ' — no double billing.';

    $steps[] = [
        'title'  => 'Add to "Joining Fees 2026-27" group',
        'icon'   => '2',
        'where'  => 'Groups → Joining Fees 2026-27 → Add Member',
        'fields' => [
            'Member'           => $memberName,
            'fee_amount'       => fee_inr($joiningTotal),
            'start_date'       => $joinDt->format('Y-m-d'),
            'interval'         => 'once',
            'execution_type'   => 'immediate',
        ],
        'note' => $joiningNote,
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

    // 3-group model: care add-on + UKG Readiness are folded into the
    // member's fee_amount on the recurring group (Monthly or Term).
    // No separate care group, no separate UKG group.
    $careExtra = 0;
    $careLine  = '';
    if ($carePlan['monthly'] > 0) {
        $careExtra = $carePlan['monthly'] + $fs['monthlyBilling'];
        $careLine  = $carePlan['label'] . ' (+' . fee_inr($carePlan['monthly']) . ' + ' . fee_inr($fs['monthlyBilling']) . ' billing)';
    }
    $ukgExtra = $gradeInfo['ukg'] ? $fs['ukgReadiness'] : 0;

    // For weekly/quarterly: care and UKG are monthly costs, so we multiply
    // them into the cadence to keep one charge per cycle.
    $cadenceMultiplier = ($frequency === 'quarterly') ? 3 : (($frequency === 'weekly') ? 0.25 : 1);
    if ($frequency === 'weekly') {
        // Weekly: care/UKG don't fold neatly into weekly billing — keep them
        // as the monthly amount divided by ~4. We add them but note the simplification.
        $careInCadence = (int)round($careExtra * 0.25);
        $ukgInCadence  = (int)round($ukgExtra * 0.25);
    } else {
        $careInCadence = (int)round($careExtra * $cadenceMultiplier);
        $ukgInCadence  = (int)round($ukgExtra * $cadenceMultiplier);
    }
    $recurringAmt += $careInCadence + $ukgInCadence;

    $step3Fields = [
        'Member'           => $memberName,
        'fee_amount'       => fee_inr($recurringAmt),
        'start_date'       => $recurringStartDate,
        'interval'         => $recurringInterval,
        'config'           => $recurringConfig,
        'execution_type'   => 'due_date',
    ];

    $breakdown = ['Base school fee: ' . fee_inr($recurringAmt - $careInCadence - $ukgInCadence)];
    if ($careInCadence > 0) $breakdown[] = $carePlan['label'] . ': +' . fee_inr($careInCadence);
    if ($ukgInCadence > 0)  $breakdown[] = 'UKG Readiness: +' . fee_inr($ukgInCadence);
    $breakdown[] = 'Total fee_amount: ' . fee_inr($recurringAmt);
    $step3Note = $recurringStartNote . "\n\n" . implode("\n", $breakdown);
    if ($frequency === 'weekly' && ($careExtra + $ukgExtra) > 0) {
        $step3Note .= "\n\nNote: care/UKG are monthly costs divided across 4 weekly charges. For a 5-Monday month the parent pays a bit more.";
    }

    $steps[] = [
        'title'  => 'Add to "' . $recurringGroupName . '" group',
        'icon'   => '3',
        'where'  => 'Groups → ' . $recurringGroupName . ' → Add Member',
        'fields' => $step3Fields,
        'note'   => $step3Note,
    ];

    // Summary step
    $totalGroups = [];
    $totalGroups[] = 'Joining Fees: ' . fee_inr($joiningTotal) . ' (once — admission '
                   . fee_inr($admTotal) . ' + first month ' . fee_inr($firstMonthTotal) . ')';
    $totalGroups[] = $recurringGroupName . ': ' . fee_inr($recurringAmt) . ' ' . $frequency
                   . ($careInCadence + $ukgInCadence > 0 ? ' (includes care + UKG overrides)' : '');

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

<?php
// Resolve CoFee group IDs for the "Open in CoFee" links.
$cofeeGroups = [
    'Joining Fees'       => (string)app_setting('cofee_group_joining', ''),
    'School Fee — Monthly'   => (string)app_setting('cofee_group_monthly', ''),
    'School Fee — Weekly'    => (string)app_setting('cofee_group_weekly', ''),
    'School Fee — Quarterly' => (string)app_setting('cofee_group_quarterly', ''),
    'UKG Readiness'      => (string)app_setting('cofee_group_ukg', ''),
];
?>

<?php if ($showSteps): ?>

<?php if (!$cofeeReady): ?>
    <div class="card" style="background:#fff4e1; border-color:#f3dba0; margin-top:1rem;">
        <strong>CoFee API not configured.</strong> Go to <a href="/fees/config.php#cofee">Fee Config → CoFee API</a> to enter your token and group IDs. Once configured, you'll see "Execute in CoFee" buttons below.
    </div>
<?php endif; ?>

<div style="margin-top:1rem;">
    <?php foreach ($steps as $step):
        // Find the matching CoFee group for this step.
        $groupId = '';
        foreach ($cofeeGroups as $gName => $gId) {
            if ($gId && stripos($step['title'], $gName) !== false) {
                $groupId = $gId;
                break;
            }
        }
    ?>
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

            <?php if ($step['icon'] === '1' && $cofeeReady): ?>
                <div class="step-actions" style="margin-top:.6rem;">
                    <button class="btn btn-primary" id="btn-create-member" onclick="cofeeCreateMember(this)">
                        Create member in CoFee
                    </button>
                    <span id="member-result" style="margin-left:.5rem;"></span>
                </div>
            <?php endif; ?>

            <?php if ($groupId): ?>
                <div class="step-actions" style="margin-top:.6rem;">
                    <a class="btn" href="<?= e(cofee_group_url($groupId)) ?>" target="_blank">
                        Open "<?= e($step['title']) ?>" in CoFee →
                    </a>
                    <span class="muted small" style="margin-left:.3rem;">Add the member manually with the values above</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($showSteps && $cofeeReady): ?>
<script>
function cofeeCreateMember(btn) {
    btn.disabled = true;
    btn.textContent = 'Creating...';
    var result = document.getElementById('member-result');
    var csrf = document.querySelector('meta[name="csrf-token"]');

    var fd = new FormData();
    fd.append('op', 'create_member');
    fd.append('member_name', <?= json_encode($memberName ?? '') ?>);
    fd.append('phone', <?= json_encode($parentPhone) ?>);
    fd.append('email', <?= json_encode($parentEmail) ?>);
    fd.append('guardian_name', <?= json_encode($parentName) ?>);
    fd.append('admission_date', <?= json_encode($joinDate) ?>);
    if (csrf) fd.append('_csrf', csrf.content);

    fetch('/fees/cofee_exec.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = true;
            if (data.ok) {
                if (data.already_exists) {
                    btn.textContent = 'Already exists';
                    btn.style.background = '#ca8a04';
                } else {
                    btn.textContent = 'Created!';
                    btn.style.background = '#16a34a';
                }
                result.innerHTML = '<strong>' + (data.message || 'Done') + '</strong>';
            } else {
                btn.textContent = 'Failed';
                btn.style.background = '#dc2626';
                result.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Unknown error') + '</span>';
                btn.disabled = false;
                setTimeout(function() { btn.textContent = 'Retry'; btn.style.background = ''; }, 3000);
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Retry';
            result.innerHTML = '<span style="color:#dc2626;">Network error</span>';
        });
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
