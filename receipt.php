<?php
/**
 * receipt.php — PUBLIC, NO LOGIN. Branded fee-payment receipt.
 *
 * /receipt.php?t=<32-hex token>  → printable receipt for one payment.
 *
 * The token lives on fee_payments.receipt_token (migration 030). The
 * office copies the link from the child's Fees tab and WhatsApps it to
 * the family — same link-only pattern as the parent admission form.
 * Unknown / malformed tokens get a generic dead-end page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';      // db() + app_config(); no login required here
require_once __DIR__ . '/includes/functions.php';

$token = trim((string)($_GET['t'] ?? ''));

$payment = null;
if (preg_match('/^[a-f0-9]{32}$/i', $token)) {
    $stmt = db()->prepare("
        SELECT fp.*, fi.title AS invoice_title, fi.period, fi.amount AS invoice_amount,
               fi.status AS invoice_status, fi.id AS invoice_id,
               s.first_name, s.last_name, s.grade, s.admission_number,
               u.name AS recorded_by_name
        FROM   fee_payments fp
        JOIN   fee_invoices fi ON fi.id = fp.invoice_id
        JOIN   students s      ON s.id  = fi.student_id
        LEFT JOIN users u      ON u.id  = fp.recorded_by_user_id
        WHERE  fp.receipt_token = :t
        LIMIT 1
    ");
    $stmt->execute([':t' => $token]);
    $payment = $stmt->fetch() ?: null;
}

$appName = function_exists('app_name') ? app_name() : 'The Little Graduates';

$logoUri = '';
$logoPath = realpath(__DIR__ . '/assets/img/logo.png');
if ($logoPath && is_file($logoPath)) {
    $logoUri = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath));
}

if (!$payment) {
    http_response_code(404);
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt not found · <?= e($appName) ?></title></head>
<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#fff5fa;display:flex;min-height:100vh;align-items:center;justify-content:center;">
<div style="text-align:center;padding:2rem;max-width:420px;">
<h1 style="color:#ad1457;font-size:1.2rem;">This receipt link isn't active.</h1>
<p style="color:#666;">Please contact the school for a fresh link.</p>
</div></body></html>
    <?php
    exit;
}

// Paid-so-far across the whole invoice, for the balance line.
$sumStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE invoice_id = :iid");
$sumStmt->execute([':iid' => (int)$payment['invoice_id']]);
$paidTotal = (float)$sumStmt->fetchColumn();
$balance   = max(0, (float)$payment['invoice_amount'] - $paidTotal);

$child = trim($payment['first_name'] . ' ' . $payment['last_name']);
$inr   = fn(float $v) => '₹' . number_format($v, 2);
$methodLabel = ucfirst(str_replace('_', ' ', (string)$payment['method']));
$receiptNo   = 'R-' . str_pad((string)(int)$payment['id'], 5, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($receiptNo) ?> · <?= e($appName) ?></title>
<style>
  :root { --pink: #e91e63; --pink-dark: #ad1457; --green: #66bb6a; color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #f4eef1; color: #1a1a1a; }
  .toolbar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #e9c2d3;
             padding: .6rem 1rem; text-align: right; }
  .btn { display: inline-block; padding: .45rem .9rem; background: var(--pink); color: #fff;
         border: 0; border-radius: 5px; font: 500 .9rem/1 inherit; cursor: pointer; text-decoration: none; }
  .sheet { background: #fff; max-width: 480px; margin: 1.5rem auto; padding: 1.6rem;
           border-radius: 10px; border-top: 6px solid var(--pink);
           box-shadow: 0 2px 12px rgba(0,0,0,.10); }
  .brand { display: flex; gap: .8rem; align-items: center; border-bottom: 2px solid var(--pink);
           padding-bottom: .8rem; margin-bottom: 1rem; }
  .brand img { width: 56px; height: auto; }
  .brand h1 { margin: 0; font-size: 1.05rem; color: var(--pink); text-transform: uppercase; letter-spacing: .5px; }
  .brand p  { margin: .1rem 0 0; font-size: .78rem; color: var(--green); font-weight: 600; }
  h2.rcpt { text-align: center; font-size: 1rem; letter-spacing: 2px; color: var(--pink-dark);
            text-transform: uppercase; margin: 0 0 1rem; }
  .amount { text-align: center; font-size: 2rem; font-weight: 800; color: var(--pink-dark); margin: .2rem 0 1rem; }
  dl { margin: 0; }
  .row { display: flex; justify-content: space-between; gap: 1rem;
         padding: .45rem 0; border-bottom: 1px dotted #e0bccd; }
  .row dt { color: #7a5a6a; font-size: .88rem; }
  .row dd { margin: 0; font-weight: 600; text-align: right; }
  .balance-ok   { color: #2c7a2c; }
  .balance-due  { color: #b03030; }
  .foot { text-align: center; color: #999; font-size: .78rem; margin-top: 1.2rem; }
  @page { size: A5; margin: 10mm; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; margin: 0; max-width: none; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn" onclick="window.print();" type="button">Print / Save as PDF</button>
</div>
<div class="sheet">
    <div class="brand">
        <?php if ($logoUri): ?><img src="<?= e($logoUri) ?>" alt=""><?php endif; ?>
        <div>
            <h1><?= e($appName) ?></h1>
            <p>Early Learning Centre</p>
        </div>
    </div>

    <h2 class="rcpt">Payment receipt</h2>
    <div class="amount"><?= e($inr((float)$payment['amount'])) ?></div>

    <dl>
        <div class="row"><dt>Receipt no.</dt><dd><?= e($receiptNo) ?></dd></div>
        <div class="row"><dt>Date</dt><dd><?= e(date('j M Y', strtotime($payment['paid_on']))) ?></dd></div>
        <div class="row"><dt>Child</dt><dd><?= e($child) ?> (<?= e($payment['grade']) ?>)</dd></div>
        <?php if (!empty($payment['admission_number'])): ?>
            <div class="row"><dt>Admission no.</dt><dd><?= e($payment['admission_number']) ?></dd></div>
        <?php endif; ?>
        <div class="row"><dt>Towards</dt><dd><?= e($payment['invoice_title']) ?><?= $payment['period'] ? ' · ' . e($payment['period']) : '' ?></dd></div>
        <div class="row"><dt>Method</dt><dd><?= e($methodLabel) ?></dd></div>
        <?php if (!empty($payment['reference_no'])): ?>
            <div class="row"><dt>Reference</dt><dd><?= e($payment['reference_no']) ?></dd></div>
        <?php endif; ?>
        <div class="row"><dt>Invoice total</dt><dd><?= e($inr((float)$payment['invoice_amount'])) ?></dd></div>
        <div class="row"><dt>Paid so far</dt><dd><?= e($inr($paidTotal)) ?></dd></div>
        <div class="row">
            <dt>Balance</dt>
            <dd class="<?= $balance > 0 ? 'balance-due' : 'balance-ok' ?>">
                <?= $balance > 0 ? e($inr($balance)) . ' due' : 'Fully paid ✓' ?>
            </dd>
        </div>
        <?php if (!empty($payment['recorded_by_name'])): ?>
            <div class="row"><dt>Received by</dt><dd><?= e($payment['recorded_by_name']) ?></dd></div>
        <?php endif; ?>
    </dl>

    <p class="foot">Computer-generated receipt — no signature required.<br>
       Questions? Contact the school office.</p>
</div>
</body>
</html>
