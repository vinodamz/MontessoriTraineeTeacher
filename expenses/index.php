<?php
/**
 * expenses/index.php — list view + filters.
 *
 * Teachers see only their own expenses; admins see everyone's. Filters are
 * URL-driven so the page is shareable + bookmarkable.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'expenses')) {
    http_response_code(403);
    echo 'Forbidden — you do not have the expenses module.';
    exit;
}

$isAdmin = $user['role'] === 'admin';

// ---------- Filters ------------------------------------------------------
$fStatus = $_GET['status']      ?? '';
$fCat    = (int)($_GET['cat']    ?? 0);
$fMonth  = $_GET['month']       ?? '';   // YYYY-MM
$fUser   = $isAdmin ? (int)($_GET['user'] ?? 0) : (int)$user['id'];

if (!array_key_exists($fStatus, EXPENSE_STATUSES)) $fStatus = '';
if (!preg_match('/^\d{4}-\d{2}$/', (string)$fMonth)) $fMonth = '';

$where  = [];
$params = [];
if ($fStatus !== '') { $where[] = 'e.status = :st';   $params[':st'] = $fStatus; }
if ($fCat    >  0)   { $where[] = 'e.category_id = :c'; $params[':c'] = $fCat; }
if ($fMonth  !== '') {
    $where[] = 'e.expense_date >= :ms AND e.expense_date < :me';
    $start = $fMonth . '-01';
    $end   = (new DateTime($start))->modify('+1 month')->format('Y-m-d');
    $params[':ms'] = $start; $params[':me'] = $end;
}
if ($fUser   >  0)   { $where[] = 'e.user_id = :u';   $params[':u']  = $fUser; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------- Aggregates ---------------------------------------------------
$sumStmt = db()->prepare("
    SELECT
      COUNT(*)                                       AS n,
      COALESCE(SUM(amount), 0)                       AS total,
      COALESCE(SUM(CASE WHEN status = 'submitted'  THEN amount ELSE 0 END), 0) AS pending,
      COALESCE(SUM(CASE WHEN status = 'approved'   THEN amount ELSE 0 END), 0) AS approved,
      COALESCE(SUM(CASE WHEN status = 'reimbursed' THEN amount ELSE 0 END), 0) AS reimbursed
    FROM expenses e
    $whereSql
");
$sumStmt->execute($params);
$sum = $sumStmt->fetch();

// ---------- Rows ---------------------------------------------------------
$rowsStmt = db()->prepare("
    SELECT e.*, u.name AS user_name, c.name AS category_name
    FROM expenses e
    LEFT JOIN users u              ON u.id = e.user_id
    LEFT JOIN expense_categories c ON c.id = e.category_id
    $whereSql
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 200
");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$cats = expense_categories_active();

$users = [];
if ($isAdmin) {
    $users = db()->query("
        SELECT DISTINCT u.id, u.name
        FROM users u
        JOIN expenses e ON e.user_id = u.id
        ORDER BY u.name
    ")->fetchAll();
}

$money     = fn(float $v) => '₹' . number_format($v, 2);
$pageTitle = 'Expenses';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Expenses</h1>
        <p class="muted">
            <?= $isAdmin ? 'All staff submissions.' : 'Your receipts and reimbursements.' ?>
            Snap a receipt and the OCR pre-fills the form.
        </p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="/expenses/edit.php"><span class="plus">+</span> New expense</a>
        <?php if ($isAdmin): ?>
            <a class="btn" href="/expenses/admin.php">Categories</a>
        <?php endif; ?>
    </div>
</div>

<ul class="admin-tiles" role="list">
    <li>
        <div class="admin-tile">
            <span class="tile-label">Filtered total</span>
            <span class="tile-value"><?= e($money((float)$sum['total'])) ?></span>
            <span class="tile-sub"><?= (int)$sum['n'] ?> expense<?= (int)$sum['n'] === 1 ? '' : 's' ?></span>
        </div>
    </li>
    <li>
        <div class="admin-tile <?= (float)$sum['pending'] > 0 ? 'tile-warn' : '' ?>">
            <span class="tile-label">Pending review</span>
            <span class="tile-value"><?= e($money((float)$sum['pending'])) ?></span>
            <span class="tile-sub">Status = Submitted</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Approved</span>
            <span class="tile-value"><?= e($money((float)$sum['approved'])) ?></span>
            <span class="tile-sub">Awaiting reimbursement</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Reimbursed</span>
            <span class="tile-value"><?= e($money((float)$sum['reimbursed'])) ?></span>
            <span class="tile-sub">Paid out</span>
        </div>
    </li>
</ul>

<form class="filter-bar" method="get">
    <div class="field">
        <label>Status</label>
        <select name="status">
            <option value="">Any</option>
            <?php foreach (EXPENSE_STATUSES as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Category</label>
        <select name="cat">
            <option value="0">Any</option>
            <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $fCat === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Month</label>
        <input type="month" name="month" value="<?= e($fMonth) ?>">
    </div>
    <?php if ($isAdmin && $users): ?>
        <div class="field">
            <label>Submitted by</label>
            <select name="user">
                <option value="0">Anyone</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="actions">
        <button class="btn">Filter</button>
        <a class="link-btn" href="/expenses/index.php">Reset</a>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty">
        No expenses match. <a href="/expenses/edit.php">Add one →</a>
    </div>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Merchant</th>
                <th>Category</th>
                <?php if ($isAdmin): ?><th>By</th><?php endif; ?>
                <th>Payment</th>
                <th class="num">Amount</th>
                <th>Status</th>
                <th>Receipt</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['expense_date']) ?></td>
                    <td><?= e($r['merchant'] ?: '—') ?></td>
                    <td><?= e($r['category_name'] ?: '—') ?></td>
                    <?php if ($isAdmin): ?><td><?= e($r['user_name']) ?></td><?php endif; ?>
                    <td><?= e(expense_payment_label($r['payment_method'])) ?></td>
                    <td class="num"><?= e($money((float)$r['amount'])) ?></td>
                    <td><span class="<?= e(expense_status_class($r['status'])) ?>"><?= e(expense_status_label($r['status'])) ?></span></td>
                    <td>
                        <?php if (!empty($r['receipt_filename'])): ?>
                            <a href="/expenses/receipt.php?id=<?= (int)$r['id'] ?>" target="_blank">View</a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="/expenses/edit.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
