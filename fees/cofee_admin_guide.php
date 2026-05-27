<?php
/**
 * fees/cofee_admin_guide.php — CoFee platform admin guide.
 *
 * Step-by-step instructions for the Little Graduates admin to set up
 * and manage fee collection on web.cofee.life. Covers groups, members,
 * fee categories, payment schedules, and common scenarios.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fees.php';

$user = require_module('fees');

$fs = fee_structure();
$admTotal     = array_sum(array_column($fs['admission'], 'amount'));
$monthlyTotal = $fs['schoolFeeMonthly'] + $fs['monthlyBilling'];

$pageTitle = 'CoFee Admin Guide';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>CoFee Admin Guide</h1>
        <p class="muted">Step-by-step setup for fee collection on <a href="https://web.cofee.life" target="_blank">web.cofee.life</a></p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/fees/index.php">← Fees</a>
        <button class="btn" onclick="window.print()">Print guide</button>
    </div>
</div>

<!-- ===== HOW COFEE WORKS ===== -->
<div class="card">
    <h2>How CoFee is structured</h2>
    <p>Everything in CoFee hangs off this hierarchy:</p>
    <pre style="background:#f8f5ef; padding:.8rem; border-radius:8px; font-size:.85rem; overflow-x:auto;">
Organisation (Little Graduates)
└── Branch (The Little Graduates - Kochi)
    ├── Groups         ← each group = one fee + one schedule
    │   └── Members    ← children enrolled in the group
    ├── Members        ← the branch-wide roster
    ├── Fee Categories ← admission, starter kit, etc.
    └── Payment Orders ← every charge/invoice generated
    </pre>
    <p><strong>Key rule:</strong> A group carries <em>exactly one fee + one schedule</em>. So you model different fee types by creating <strong>different groups</strong>. A child can belong to multiple groups — their total bill is the sum of all groups they're in.</p>
</div>

<!-- ===== GROUPS TO CREATE ===== -->
<div class="card">
    <h2>Groups to create (2026-27)</h2>
    <p>You need these groups to cover the full fee structure:</p>

    <table class="data-table">
        <thead>
            <tr><th>Group name</th><th>Amount</th><th>Frequency</th><th>When</th><th>Who goes in</th></tr>
        </thead>
        <tbody>
            <tr class="highlight">
                <td><strong>Joining Fees 2026-27</strong></td>
                <td><?= e(fee_inr($admTotal)) ?></td>
                <td>Once</td>
                <td>Immediate (at joining)</td>
                <td>Every new child</td>
            </tr>
            <tr>
                <td><strong>School Fee — Monthly</strong></td>
                <td><?= e(fee_inr($monthlyTotal)) ?></td>
                <td>Monthly</td>
                <td>Due on <?= (int)$fs['paymentDueDay'] ?><?= match((int)$fs['paymentDueDay'] % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?> of each month</td>
                <td>Children on monthly plan</td>
            </tr>
            <tr>
                <td><strong>School Fee — Weekly</strong></td>
                <td><?= e(fee_inr($fs['weeklyRate'])) ?></td>
                <td>Weekly</td>
                <td>Every Monday</td>
                <td>Children on weekly plan</td>
            </tr>
            <tr>
                <td><strong>School Fee — Quarterly</strong></td>
                <td><?= e(fee_inr($fs['quarterlyRate'])) ?></td>
                <td>Monthly (interval: 3)</td>
                <td>Every 3 months</td>
                <td>Children on quarterly plan</td>
            </tr>
            <tr>
                <td><strong>UKG Readiness</strong></td>
                <td><?= e(fee_inr($fs['ukgReadiness'])) ?></td>
                <td>Monthly</td>
                <td>Due on <?= (int)$fs['paymentDueDay'] ?><?= match((int)$fs['paymentDueDay'] % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } ?></td>
                <td>UKG (Level-3) children only</td>
            </tr>
        </tbody>
    </table>

    <div style="background:#fff4e1; border:1px solid #f3dba0; border-radius:8px; padding:.7rem .9rem; margin-top:.8rem;">
        <strong>Important:</strong> A parent who picks monthly goes into <strong>Joining Fees + School Fee Monthly</strong>. A parent who picks weekly goes into <strong>Joining Fees + School Fee Weekly</strong>. They should NOT be in both monthly and weekly.
    </div>
</div>

<!-- ===== JOINING FEES SETUP ===== -->
<div class="card">
    <h2>Step 1: Set up the Joining Fees group</h2>
    <p>This is a <strong>one-time</strong> charge split across fee categories so the receipt itemises each component.</p>

    <h3>1a. First, create fee categories (if not already done)</h3>
    <p>Go to <strong>Branch Settings → Fee Categories</strong> and ensure these exist:</p>
    <table class="data-table">
        <thead><tr><th>Category name</th><th>Amount</th></tr></thead>
        <tbody>
            <?php foreach ($fs['admission'] as $f): ?>
                <tr><td><?= e($f['name']) ?></td><td><?= e(fee_inr($f['amount'])) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total</td><td><?= e(fee_inr($admTotal)) ?></td></tr>
        </tbody>
    </table>

    <h3>1b. Create the group</h3>
    <ol>
        <li>Go to <strong>Groups → Create Group</strong></li>
        <li>Name: <code>Joining Fees 2026-27</code></li>
        <li>Amount: <code><?= number_format($admTotal) ?></code></li>
        <li>Enable <strong>Split Payment</strong></li>
        <li>Add each fee category with its amount (must total <?= e(fee_inr($admTotal)) ?>)</li>
        <li>Schedule: <strong>Once</strong>, Execution: <strong>Immediate</strong></li>
        <li>Activation date: <code>2026-06-01</code> (or when admissions open)</li>
        <li>Save</li>
    </ol>
</div>

<!-- ===== MONTHLY SCHOOL FEE ===== -->
<div class="card">
    <h2>Step 2: Set up the Monthly School Fee group</h2>
    <ol>
        <li>Go to <strong>Groups → Create Group</strong></li>
        <li>Name: <code>School Fee — Monthly</code></li>
        <li>Amount: <code><?= number_format($monthlyTotal) ?></code> (<?= e(fee_inr($fs['schoolFeeMonthly'])) ?> school fee + <?= e(fee_inr($fs['monthlyBilling'])) ?> billing)</li>
        <li>Split payment: <strong>No</strong> (single line)</li>
        <li>Schedule: <strong>Monthly</strong>, Day: <strong>1st of month</strong> (or custom day <?= (int)$fs['paymentDueDay'] ?>)</li>
        <li>Execution: <strong>Due date</strong> (generates on schedule, not immediately)</li>
        <li>Activation date: <code>2026-06-01</code></li>
        <li>Save</li>
    </ol>
</div>

<!-- ===== WEEKLY ===== -->
<div class="card">
    <h2>Step 3: Set up the Weekly School Fee group</h2>
    <ol>
        <li>Name: <code>School Fee — Weekly</code></li>
        <li>Amount: <code><?= number_format($fs['weeklyRate']) ?></code></li>
        <li>Schedule: <strong>Weekly</strong>, Day: <strong>Monday</strong></li>
        <li>Execution: <strong>Due date</strong></li>
        <li>Activation date: <code>2026-06-01</code></li>
    </ol>
    <p class="muted" style="font-size:.85rem;">
        <strong>Note on weekly pricing:</strong> <?= e(fee_inr($fs['weeklyRate'])) ?>/week is the 4-week flat rate (<?= e(fee_inr($fs['schoolFeeMonthly'])) ?> ÷ 4).
        In months with 5 Mondays, the parent pays <?= e(fee_inr($fs['weeklyRate'] * 5)) ?> — about <?= e(fee_inr($fs['weeklyRate'] * 5 - $fs['schoolFeeMonthly'])) ?> more than the monthly plan.
        Weekly billing produces ~52 payment links/year vs 12 for monthly.
    </p>
</div>

<!-- ===== QUARTERLY ===== -->
<div class="card">
    <h2>Step 4: Set up the Quarterly group</h2>
    <ol>
        <li>Name: <code>School Fee — Quarterly</code></li>
        <li>Amount: <code><?= number_format($fs['quarterlyRate']) ?></code></li>
        <li>Schedule: <strong>Monthly</strong>, Interval: <strong>3</strong> (every 3rd month)</li>
        <li>Day: <strong>1st of month</strong></li>
        <li>Execution: <strong>Due date</strong></li>
    </ol>
</div>

<!-- ===== UKG READINESS ===== -->
<div class="card">
    <h2>Step 5: UKG Readiness Programme (Level-3 only)</h2>
    <ol>
        <li>Name: <code>UKG Readiness</code></li>
        <li>Amount: <code><?= number_format($fs['ukgReadiness']) ?></code></li>
        <li>Schedule: <strong>Monthly</strong></li>
        <li>Only enrol UKG children in this group</li>
    </ol>
</div>

<!-- ===== ENROLLING A NEW CHILD ===== -->
<div class="card">
    <h2>Enrolling a new child</h2>
    <p>When a family confirms admission:</p>
    <ol>
        <li><strong>Create the member</strong> (if not already in the roster):
            <ul>
                <li>Name: <code>Child Name - Parent Name</code></li>
                <li>Phone: parent's mobile (with country code 91)</li>
                <li>Email: parent's email</li>
                <li>Address, DOB, admission date</li>
            </ul>
        </li>
        <li><strong>Add to the Joining Fees group</strong> — this triggers the one-time admission charge</li>
        <li><strong>Add to the chosen school fee group</strong> (Monthly OR Weekly OR Quarterly — not both):
            <ul>
                <li>If joining mid-month: set the member's <code>start_date</code> to the <strong>1st of next month</strong> so the first recurring charge starts cleanly (the joining month is already covered by the admission payment)</li>
                <li>If joining on the 1st: <code>start_date</code> = joining date</li>
            </ul>
        </li>
        <li><strong>If UKG:</strong> also add to the UKG Readiness group</li>
    </ol>

    <div style="background:#ecf7e8; border:1px solid #b9deaf; border-radius:8px; padding:.7rem .9rem; margin-top:.8rem;">
        <strong>Example — child joins 18 June 2026, monthly plan:</strong>
        <ol style="margin:.4rem 0 0;">
            <li>Add to <strong>Joining Fees</strong> → charge of <?= e(fee_inr($admTotal)) ?> fires immediately</li>
            <li>Add to <strong>School Fee — Monthly</strong> with <code>start_date = 2026-07-01</code> → first monthly charge on 1 July</li>
            <li>June is already covered by the joining payment</li>
        </ol>
    </div>
</div>

<!-- ===== CARE ADD-ONS ===== -->
<div class="card">
    <h2>Care add-ons (Rest / Enrichment / Full Day)</h2>
    <p>Two ways to handle care plans:</p>

    <h3>Option A: Per-member fee override (simpler)</h3>
    <p>When enrolling a child in the monthly school fee group, set <strong>their</strong> <code>fee_amount</code> to include the care surcharge:</p>
    <table class="data-table">
        <thead><tr><th>Care plan</th><th>Add-on</th><th>Member fee_amount</th></tr></thead>
        <tbody>
            <tr><td>No care (half day)</td><td><?= e(fee_inr(0)) ?></td><td><?= e(fee_inr($monthlyTotal)) ?></td></tr>
            <?php foreach ($fs['carePlans'] as $code => $cp): if ($cp['monthly'] === 0) continue; ?>
                <tr>
                    <td><?= e($cp['label']) ?></td>
                    <td>+<?= e(fee_inr($cp['monthly'])) ?></td>
                    <td><strong><?= e(fee_inr($monthlyTotal + $cp['monthly'] + $fs['monthlyBilling'])) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Option B: Separate care groups (better reporting)</h3>
    <p>Create a separate monthly group for each care plan (e.g. "Care — Enrichment", amount <?= e(fee_inr(3800 + $fs['monthlyBilling'])) ?>). The child gets two monthly charges: school fee + care. Cleaner for tracking care revenue separately.</p>
</div>

<!-- ===== SWITCHING PLANS ===== -->
<div class="card">
    <h2>Switching monthly ↔ weekly</h2>
    <p>There's no "change cadence" toggle. To switch:</p>
    <ol>
        <li><strong>Deactivate / remove</strong> the child from the old group at the end of the current cycle</li>
        <li><strong>Add</strong> them to the new group with <code>start_date</code> = next cycle</li>
    </ol>
    <p class="muted" style="font-size:.85rem;">The Fee Guide tells parents: "Switching is from the next billing cycle." This matches perfectly.</p>
</div>

<!-- ===== LATE FEE ===== -->
<div class="card">
    <h2>Late fees & grace period</h2>
    <p>Configure in <strong>Branch Settings → Fine Configuration</strong>:</p>
    <table class="data-table">
        <tr><td>Grace period</td><td><strong><?= (int)$fs['graceDays'] ?> days</strong> from the due date</td></tr>
        <tr><td>Late fee</td><td><strong><?= e(fee_inr($fs['lateFee'])) ?></strong> after grace period</td></tr>
    </table>
    <p class="muted" style="font-size:.85rem;">CoFee applies this automatically via <code>fine_starts_in_days</code> and <code>fine_amount</code> in the branch settings.</p>
</div>

<!-- ===== QUICK REFERENCE ===== -->
<div class="card">
    <h2>Quick reference: Fee Guide → CoFee mapping</h2>
    <table class="data-table">
        <thead><tr><th>Fee Guide item</th><th>CoFee representation</th></tr></thead>
        <tbody>
            <tr><td>Admission Fee <?= e(fee_inr(7500)) ?></td><td>Fee category in the <code>once</code> Joining group</td></tr>
            <tr><td>Starter Kit <?= e(fee_inr(6500)) ?></td><td>Fee category in the <code>once</code> Joining group</td></tr>
            <tr><td>Annual Resource Renewal <?= e(fee_inr(5000)) ?></td><td>Fee category in the Joining group (or separate <code>once</code> group activated after day 30)</td></tr>
            <tr><td>School Fee <?= e(fee_inr($fs['schoolFeeMonthly'])) ?>/mo</td><td><code>monthly</code> group (<?= e(fee_inr($monthlyTotal)) ?> with billing add-on)</td></tr>
            <tr><td>Quarterly <?= e(fee_inr($fs['quarterlyRate'])) ?></td><td><code>monthly</code> group, interval_frequency: 3</td></tr>
            <tr><td>Rest/Enrichment/Full Day care</td><td>Per-member <code>fee_amount</code> override, or a separate add-on group</td></tr>
            <tr><td>UKG Readiness <?= e(fee_inr($fs['ukgReadiness'])) ?>/mo</td><td>Separate <code>monthly</code> group; enrol only Level-3 children</td></tr>
            <tr><td>Grace 7 days / late fee <?= e(fee_inr($fs['lateFee'])) ?></td><td>Branch <code>fine_config</code> in Branch Settings</td></tr>
        </tbody>
    </table>
</div>

<!-- ===== CHECKLIST ===== -->
<div class="card">
    <h2>Setup checklist</h2>
    <ul style="list-style:none; padding:0;">
        <li><label><input type="checkbox"> Fee categories created (Admission Charge, Starter Kit, Resource Renewal)</label></li>
        <li><label><input type="checkbox"> Joining Fees group created (once, split payment, <?= e(fee_inr($admTotal)) ?>)</label></li>
        <li><label><input type="checkbox"> School Fee — Monthly group created (<?= e(fee_inr($monthlyTotal)) ?>/month)</label></li>
        <li><label><input type="checkbox"> School Fee — Weekly group created (<?= e(fee_inr($fs['weeklyRate'])) ?>/week)</label></li>
        <li><label><input type="checkbox"> School Fee — Quarterly group created (<?= e(fee_inr($fs['quarterlyRate'])) ?>/quarter)</label></li>
        <li><label><input type="checkbox"> UKG Readiness group created (<?= e(fee_inr($fs['ukgReadiness'])) ?>/month, Level-3 only)</label></li>
        <li><label><input type="checkbox"> Fine config set: <?= (int)$fs['graceDays'] ?> days grace, <?= e(fee_inr($fs['lateFee'])) ?> late fee</label></li>
        <li><label><input type="checkbox"> Bank account verified in Organisation → Account Detail</label></li>
        <li><label><input type="checkbox"> Test: enrolled one child in Joining + Monthly, verified payment link received</label></li>
    </ul>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
