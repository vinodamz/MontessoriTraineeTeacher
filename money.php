<?php
/**
 * money.php — the "Money in one place" overview (Phase 4 of the UX roadmap).
 *
 * One screen answering the owner's three money questions:
 *   - What came IN this month?    (fee_payments)
 *   - Who still OWES us?          (open / partial fee_invoices)
 *   - What's waiting to go OUT?   (submitted expenses)
 *
 * Read-only rollup with deep links into the existing pages; the source
 * of truth stays where it was (per-child Fees tab, Expenses module).
 *
 * Access: admins, or anyone holding the fees OR expenses module.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$canFees     = $user['role'] === 'admin' || user_has_module($user, 'fees') || user_has_module($user, 'students');
$canExpenses = $user['role'] === 'admin' || user_has_module($user, 'expenses');
if (!$canFees && !$canExpenses) {
    http_response_code(403);
    echo 'Forbidden — the Money overview needs the fees, students, or expenses module.';
    exit;
}

$inr = fn(float $v) => '₹' . number_format($v, 0);
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
$monthLabel = (new DateTime('today'))->format('F Y');

// ---- In: collections this month ------------------------------------------
$collected = 0.0; $nPayments = 0;
if ($canFees) {
    try {
        $r = db()->prepare("SELECT COALESCE(SUM(amount),0) AS s, COUNT(*) AS n FROM fee_payments WHERE paid_on >= :ms");
        $r->execute([':ms' => $monthStart]);
        $row = $r->fetch();
        $collected = (float)$row['s'];
        $nPayments = (int)$row['n'];
    } catch (Throwable $e) {}
}

// ---- Owed: open dues per child --------------------------------------------
$dues = []; $duesTotal = 0.0;
if ($canFees) {
    try {
        $dues = db()->query("
            SELECT s.id AS student_id, s.first_name, s.last_name, s.grade,
                   SUM(fi.amount) AS billed,
                   COALESCE(SUM(fp.paid), 0) AS paid
            FROM fee_invoices fi
            JOIN students s ON s.id = fi.student_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid FROM fee_payments GROUP BY invoice_id
            ) fp ON fp.invoice_id = fi.id
            WHERE fi.status IN ('open', 'partial')
            GROUP BY s.id
            HAVING billed - paid > 0.009
            ORDER BY billed - paid DESC
        ")->fetchAll();
        foreach ($dues as $d) $duesTotal += (float)$d['billed'] - (float)$d['paid'];
    } catch (Throwable $e) {}
}

// ---- Out: expenses ----------------------------------------------------------
$expMonth = 0.0; $expPending = 0; $expPendingSum = 0.0;
if ($canExpenses) {
    try {
        $r = db()->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN expense_date >= :ms THEN amount ELSE 0 END), 0)            AS month_total,
              SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END)                              AS n_pending,
              COALESCE(SUM(CASE WHEN status = 'submitted' THEN amount ELSE 0 END), 0)            AS pending_total
            FROM expenses
        ");
        $r->execute([':ms' => $monthStart]);
        $row = $r->fetch();
        $expMonth      = (float)$row['month_total'];
        $expPending    = (int)$row['n_pending'];
        $expPendingSum = (float)$row['pending_total'];
    } catch (Throwable $e) {}
}

$pageTitle = 'Money';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Money</h1>
        <p class="muted"><?= e($monthLabel) ?> at a glance — fees in, dues outstanding, expenses out.</p>
    </div>
    <div class="actionbar">
        <?php if ($canFees): ?>
            <a class="btn" href="/students/fees_report.php">Fees report</a>
            <a class="btn" href="/fees/index.php">Fee tools</a>
        <?php endif; ?>
        <?php if ($canExpenses): ?>
            <a class="btn" href="/expenses/index.php">Expenses</a>
        <?php endif; ?>
    </div>
</div>

<div class="row" style="align-items: stretch;">
    <?php if ($canFees): ?>
    <div class="card" style="flex: 1 1 220px;">
        <h3 style="margin-top:0;">Collected — <?= e($monthLabel) ?></h3>
        <p style="font-size:1.8rem; font-weight:800; margin:.2rem 0; color:#2c7a2c;"><?= e($inr($collected)) ?></p>
        <p class="muted small"><?= $nPayments ?> payment<?= $nPayments === 1 ? '' : 's' ?> recorded</p>
    </div>
    <div class="card" style="flex: 1 1 220px;">
        <h3 style="margin-top:0;">Outstanding dues</h3>
        <p style="font-size:1.8rem; font-weight:800; margin:.2rem 0; color:<?= $duesTotal > 0 ? '#b03030' : '#2c7a2c' ?>;"><?= e($inr($duesTotal)) ?></p>
        <p class="muted small"><?= count($dues) ?> famil<?= count($dues) === 1 ? 'y' : 'ies' ?> with open invoices</p>
    </div>
    <?php endif; ?>
    <?php if ($canExpenses): ?>
    <div class="card" style="flex: 1 1 220px;">
        <h3 style="margin-top:0;">Spent — <?= e($monthLabel) ?></h3>
        <p style="font-size:1.8rem; font-weight:800; margin:.2rem 0;"><?= e($inr($expMonth)) ?></p>
        <p class="muted small">
            <?php if ($expPending > 0): ?>
                <a href="/expenses/admin.php"><?= $expPending ?> expense<?= $expPending === 1 ? '' : 's' ?> awaiting review</a>
                (<?= e($inr($expPendingSum)) ?>)
            <?php else: ?>
                Nothing awaiting review
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>
</div>

<?php if ($canFees): ?>
<div class="card">
    <h3 style="margin-top:0;">Who owes what</h3>
    <?php if (!$dues): ?>
        <p class="muted">No outstanding dues — every open invoice is settled. 🎉</p>
    <?php else: ?>
        <table class="att-summary">
            <thead><tr><th>Child</th><th>Grade</th><th>Billed</th><th>Paid</th><th>Due</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($dues as $d):
                    $full = trim($d['first_name'] . ' ' . $d['last_name']);
                    $due  = (float)$d['billed'] - (float)$d['paid'];
                ?>
                    <tr>
                        <td><a href="/students/view.php?id=<?= (int)$d['student_id'] ?>"><?= e($full) ?></a></td>
                        <td><span class="<?= e(grade_badge_class($d['grade'])) ?>"><?= e($d['grade']) ?></span></td>
                        <td><?= e($inr((float)$d['billed'])) ?></td>
                        <td><?= e($inr((float)$d['paid'])) ?></td>
                        <td><strong style="color:#b03030;"><?= e($inr($due)) ?></strong></td>
                        <td><a href="/students/fees.php?student_id=<?= (int)$d['student_id'] ?>">Open fees →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
