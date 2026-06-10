<?php
/**
 * students/fees.php — per-student fees: invoices, payments, dues.
 *
 *   GET ?student_id=N  → list of invoices with payment rollups + add-invoice
 *                        form + add-payment form (per invoice).
 *
 * Auth: admins or anyone with the `students` module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_tabs.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — fees require the students module.';
    exit;
}

$studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
if ($studentId <= 0) { redirect('/students/index.php'); }

$stmt = db()->prepare("SELECT id, first_name, last_name, grade FROM students WHERE id = :id");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();
if (!$student) {
    flash_set('error', 'Student not found.');
    redirect('/students/index.php');
}
$fullName = trim($student['first_name'] . ' ' . $student['last_name']);

$VALID_STATUS = ['open', 'paid', 'partial', 'waived', 'cancelled'];
$VALID_METHOD = ['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'cofee', 'other'];

// ---------- POST handlers -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'invoice_create') {
            $title  = trim($_POST['title'] ?? '');
            $period = trim($_POST['period'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $issue  = $_POST['issue_date'] ?? date('Y-m-d');
            $due    = $_POST['due_date'] ?? '';
            $notes  = trim($_POST['notes'] ?? '');
            if ($title === '')        throw new RuntimeException('Invoice title is required.');
            if ($amount <= 0)         throw new RuntimeException('Amount must be greater than 0.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) $issue = date('Y-m-d');
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;
            $stmt = db()->prepare("
                INSERT INTO fee_invoices (student_id, title, period, amount, issue_date, due_date, notes, created_by_user_id)
                VALUES (:sid, :t, :p, :a, :i, :d, :n, :u)
            ");
            $stmt->execute([
                ':sid' => $studentId, ':t' => $title, ':p' => $period ?: null,
                ':a' => $amount, ':i' => $issue, ':d' => $due ?: null,
                ':n' => $notes ?: null, ':u' => $user['id'],
            ]);
            flash_set('ok', 'Invoice added.');

            // Notify other admins (skip the creator).
            require_once __DIR__ . '/../includes/notify.php';
            $admIds = db()->query("SELECT id FROM users WHERE active = 1 AND role = 'admin' AND id <> " . (int)$user['id'])
                          ->fetchAll(PDO::FETCH_COLUMN);
            if ($admIds) {
                notify(
                    $admIds, 'fees', 'invoice_created',
                    'Invoice ₹' . number_format($amount, 2) . ' for ' . $fullName,
                    ($period !== '' ? "Period: $period\n" : '') . "Title: $title" .
                    ($due !== '' && $due !== null ? "\nDue: $due" : '') .
                    "\nCreated by " . $user['name'],
                    '/students/fees.php?student_id=' . $studentId
                );
            }
        } elseif ($op === 'invoice_delete') {
            $iid = (int)($_POST['invoice_id'] ?? 0);
            db()->prepare("DELETE FROM fee_invoices WHERE id = :id AND student_id = :sid")
                ->execute([':id' => $iid, ':sid' => $studentId]);
            flash_set('ok', 'Invoice deleted.');
        } elseif ($op === 'payment_create') {
            $iid    = (int)($_POST['invoice_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $paid   = $_POST['paid_on'] ?? date('Y-m-d');
            $method = $_POST['method'] ?? 'cash';
            $ref    = trim($_POST['reference_no'] ?? '');
            $notes  = trim($_POST['notes'] ?? '');
            if (!in_array($method, $VALID_METHOD, true)) $method = 'other';
            if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than 0.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid)) $paid = date('Y-m-d');

            // Confirm invoice belongs to this student.
            $check = db()->prepare("SELECT amount FROM fee_invoices WHERE id = :id AND student_id = :sid");
            $check->execute([':id' => $iid, ':sid' => $studentId]);
            $inv = $check->fetch();
            if (!$inv) throw new RuntimeException('Invoice not found for this student.');

            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO fee_payments (invoice_id, amount, paid_on, method, reference_no, notes, recorded_by_user_id)
                VALUES (:iid, :a, :p, :m, :r, :n, :u)
            ")->execute([
                ':iid' => $iid, ':a' => $amount, ':p' => $paid, ':m' => $method,
                ':r' => $ref ?: null, ':n' => $notes ?: null, ':u' => $user['id'],
            ]);
            // Recompute invoice status from total paid.
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE invoice_id = :iid");
            $sumStmt->execute([':iid' => $iid]);
            $paidSum = (float)$sumStmt->fetchColumn();
            $newStatus = 'open';
            if ($paidSum >= (float)$inv['amount']) $newStatus = 'paid';
            elseif ($paidSum > 0)                 $newStatus = 'partial';
            $pdo->prepare("UPDATE fee_invoices SET status = :s WHERE id = :id")
                ->execute([':s' => $newStatus, ':id' => $iid]);
            $pdo->commit();
            flash_set('ok', 'Payment recorded.');
        } elseif ($op === 'payment_delete') {
            $pid = (int)($_POST['payment_id'] ?? 0);
            // Confirm payment belongs to one of this student's invoices.
            $check = db()->prepare("
                SELECT fp.invoice_id, fi.amount AS invoice_amount
                FROM fee_payments fp JOIN fee_invoices fi ON fi.id = fp.invoice_id
                WHERE fp.id = :pid AND fi.student_id = :sid
            ");
            $check->execute([':pid' => $pid, ':sid' => $studentId]);
            $pay = $check->fetch();
            if (!$pay) throw new RuntimeException('Payment not found.');

            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM fee_payments WHERE id = :id")->execute([':id' => $pid]);
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE invoice_id = :iid");
            $sumStmt->execute([':iid' => $pay['invoice_id']]);
            $paidSum = (float)$sumStmt->fetchColumn();
            $newStatus = 'open';
            if ($paidSum >= (float)$pay['invoice_amount']) $newStatus = 'paid';
            elseif ($paidSum > 0)                          $newStatus = 'partial';
            $pdo->prepare("UPDATE fee_invoices SET status = :s WHERE id = :id")
                ->execute([':s' => $newStatus, ':id' => $pay['invoice_id']]);
            $pdo->commit();
            flash_set('ok', 'Payment deleted.');
        } elseif ($op === 'invoice_status') {
            $iid = (int)($_POST['invoice_id'] ?? 0);
            $st  = $_POST['status'] ?? '';
            if (!in_array($st, $VALID_STATUS, true)) throw new RuntimeException('Bad status.');
            db()->prepare("UPDATE fee_invoices SET status = :s WHERE id = :id AND student_id = :sid")
                ->execute([':s' => $st, ':id' => $iid, ':sid' => $studentId]);
            flash_set('ok', 'Invoice status updated.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('/students/fees.php?student_id=' . $studentId);
}

// ---------- GET: load invoices + their payments --------------------------
$inv = db()->prepare("
    SELECT fi.*, COALESCE(SUM(fp.amount), 0) AS paid_total, COUNT(fp.id) AS n_payments
    FROM fee_invoices fi
    LEFT JOIN fee_payments fp ON fp.invoice_id = fi.id
    WHERE fi.student_id = :sid
    GROUP BY fi.id
    ORDER BY fi.issue_date DESC, fi.id DESC
");
$inv->execute([':sid' => $studentId]);
$invoices = $inv->fetchAll();

// Pull payments for all of those invoices in one shot, grouped by invoice_id.
$paymentsByInv = [];
if ($invoices) {
    $ids = array_column($invoices, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $pstmt = db()->prepare("
        SELECT fp.*, u.name AS recorded_by_name
        FROM fee_payments fp
        LEFT JOIN users u ON u.id = fp.recorded_by_user_id
        WHERE fp.invoice_id IN ($place)
        ORDER BY fp.paid_on DESC, fp.id DESC
    ");
    $pstmt->execute($ids);
    foreach ($pstmt as $p) $paymentsByInv[(int)$p['invoice_id']][] = $p;
}

$totalBilled = 0.0; $totalPaid = 0.0; $totalOpen = 0.0;
foreach ($invoices as $i) {
    if ($i['status'] === 'cancelled' || $i['status'] === 'waived') continue;
    $totalBilled += (float)$i['amount'];
    $totalPaid   += (float)$i['paid_total'];
}
$totalOpen = max(0, $totalBilled - $totalPaid);

function money(float $v): string { return '₹' . number_format($v, 2); }

$pageTitle = 'Fees — ' . $fullName;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Fees</h1>
        <p class="muted">
            <a href="/students/view.php?id=<?= $studentId ?>"><?= e($fullName) ?></a>
            · <span class="<?= e(grade_badge_class($student['grade'])) ?>"><?= e($student['grade']) ?></span>
            · Billed <strong><?= e(money($totalBilled)) ?></strong>
            · Paid <strong><?= e(money($totalPaid)) ?></strong>
            <?php if ($totalOpen > 0): ?>· <span class="pill pill-warn">Due <?= e(money($totalOpen)) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<?php student_tab_strip($studentId, 'fees', $user); ?>

<details class="card card-form" <?= $invoices ? '' : 'open' ?>>
    <summary>New invoice</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="invoice_create">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <div class="row">
            <div class="field">
                <label>Title *</label>
                <input name="title" required maxlength="120" placeholder="e.g. Tuition fees">
            </div>
            <div class="field">
                <label>Period</label>
                <input name="period" maxlength="30" placeholder="e.g. May 2026 or Term 1">
            </div>
            <div class="field">
                <label>Amount *</label>
                <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00">
            </div>
        </div>
        <div class="row">
            <div class="field">
                <label>Issue date</label>
                <input type="date" name="issue_date" value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label>Due date</label>
                <input type="date" name="due_date">
            </div>
            <div class="field" style="flex: 2 1 100%;">
                <label>Notes</label>
                <input name="notes" maxlength="200">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Add invoice</button>
        </div>
    </form>
</details>

<?php if (!$invoices): ?>
    <div class="empty"><p>No fees on record for this student yet.</p></div>
<?php else: ?>
    <h2 class="section-h-spaced">Invoices</h2>
    <?php foreach ($invoices as $i):
        $iid    = (int)$i['id'];
        $billed = (float)$i['amount'];
        $paid   = (float)$i['paid_total'];
        $bal    = max(0, $billed - $paid);
    ?>
        <details class="card invoice-card" <?= $bal > 0 && $i['status'] !== 'cancelled' && $i['status'] !== 'waived' ? 'open' : '' ?>>
            <summary>
                <span class="invoice-title">
                    <strong><?= e($i['title']) ?></strong>
                    <?php if (!empty($i['period'])): ?><span class="muted small"> · <?= e($i['period']) ?></span><?php endif; ?>
                </span>
                <span class="invoice-figures">
                    <?= e(money($billed)) ?>
                    · paid <?= e(money($paid)) ?>
                    · <span class="pill inv-<?= e($i['status']) ?>"><?= e(ucfirst($i['status'])) ?></span>
                    <?php if ($bal > 0 && $i['status'] !== 'cancelled' && $i['status'] !== 'waived'): ?>
                        <span class="pill pill-warn">Bal <?= e(money($bal)) ?></span>
                    <?php endif; ?>
                </span>
            </summary>

            <div class="invoice-body">
                <p class="muted small">
                    Issued <?= e($i['issue_date']) ?>
                    <?php if (!empty($i['due_date'])): ?> · Due <?= e($i['due_date']) ?><?php endif; ?>
                </p>
                <?php if (!empty($i['notes'])): ?>
                    <p class="muted small"><?= e($i['notes']) ?></p>
                <?php endif; ?>

                <?php $payments = $paymentsByInv[$iid] ?? []; ?>
                <?php if ($payments): ?>
                    <table class="att-summary">
                        <thead><tr><th>Paid on</th><th>Amount</th><th>Method</th><th>Ref.</th><th>Recorded by</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= e($p['paid_on']) ?></td>
                                    <td><?= e(money((float)$p['amount'])) ?></td>
                                    <td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
                                    <td><?= e($p['reference_no'] ?? '') ?></td>
                                    <td><?= e($p['recorded_by_name'] ?? '') ?></td>
                                    <td>
                                        <form method="post" class="inline" onsubmit="return confirm('Delete this payment?')">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="op" value="payment_delete">
                                            <input type="hidden" name="student_id" value="<?= $studentId ?>">
                                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                            <button class="link-btn" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="muted small">No payments recorded against this invoice yet.</p>
                <?php endif; ?>

                <details class="card card-form" style="margin-top: .75rem;">
                    <summary>Record a payment</summary>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="payment_create">
                        <input type="hidden" name="student_id" value="<?= $studentId ?>">
                        <input type="hidden" name="invoice_id" value="<?= $iid ?>">
                        <div class="row">
                            <div class="field">
                                <label>Amount *</label>
                                <input type="number" step="0.01" min="0.01" name="amount" required value="<?= $bal > 0 ? e(number_format($bal, 2, '.', '')) : '' ?>">
                            </div>
                            <div class="field">
                                <label>Paid on</label>
                                <input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="field">
                                <label>Method</label>
                                <select name="method">
                                    <?php foreach ($VALID_METHOD as $m): ?>
                                        <option value="<?= e($m) ?>"><?= e(ucfirst(str_replace('_', ' ', $m))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Reference no.</label>
                                <input name="reference_no" maxlength="80" placeholder="e.g. UPI txn ID">
                            </div>
                        </div>
                        <div class="row">
                            <div class="field" style="flex: 1 1 100%;">
                                <label>Notes</label>
                                <input name="notes" maxlength="200">
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn btn-primary" type="submit">Record payment</button>
                        </div>
                    </form>
                </details>

                <form method="post" class="inline" style="margin-top: .75rem;" onsubmit="return confirm('Delete this invoice and all its payments?')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="invoice_delete">
                    <input type="hidden" name="student_id" value="<?= $studentId ?>">
                    <input type="hidden" name="invoice_id" value="<?= $iid ?>">
                    <button class="link-btn" type="submit">Delete invoice</button>
                </form>
                <form method="post" class="inline" style="margin-left:.5rem;">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="invoice_status">
                    <input type="hidden" name="student_id" value="<?= $studentId ?>">
                    <input type="hidden" name="invoice_id" value="<?= $iid ?>">
                    <select name="status" onchange="this.form.submit()" aria-label="Invoice status">
                        <?php foreach ($VALID_STATUS as $st): ?>
                            <option value="<?= e($st) ?>" <?= $i['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
