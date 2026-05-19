<?php
/**
 * students/fees_report.php — fee dues summary across all students.
 *
 *   GET                       → list of every active student with billed /
 *                               paid / balance totals + invoice + payment counts.
 *   GET ?grade=XYZ            → filter by grade.
 *   GET ?status=due           → only show students with a non-zero balance.
 *
 * Admins + students-module users only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — fees report requires the students module.';
    exit;
}

$VALID_GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'];
$gradeFilter  = $_GET['grade']  ?? '';
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($gradeFilter, $VALID_GRADES, true)) $gradeFilter = '';

$where  = ['COALESCE(s.is_active, 1) = 1'];
$params = [];
if ($gradeFilter !== '') {
    $where[] = 's.grade = :g';
    $params[':g'] = $gradeFilter;
}

$sql = "
    SELECT s.id, s.first_name, s.last_name, s.grade, s.admission_number,
           COALESCE(SUM(CASE WHEN fi.status NOT IN ('cancelled','waived') THEN fi.amount ELSE 0 END), 0) AS billed,
           COALESCE((
               SELECT SUM(fp.amount)
               FROM fee_payments fp
               JOIN fee_invoices fi2 ON fi2.id = fp.invoice_id
               WHERE fi2.student_id = s.id
                 AND fi2.status NOT IN ('cancelled','waived')
           ), 0) AS paid,
           COUNT(DISTINCT fi.id) AS n_invoices
    FROM students s
    LEFT JOIN fee_invoices fi ON fi.student_id = s.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY s.id
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Compute balance and optionally hide settled accounts.
foreach ($rows as &$r) {
    $r['balance'] = max(0, (float)$r['billed'] - (float)$r['paid']);
}
unset($r);
if ($statusFilter === 'due') {
    $rows = array_values(array_filter($rows, fn($r) => $r['balance'] > 0));
}

$grandBilled = array_sum(array_column($rows, 'billed'));
$grandPaid   = array_sum(array_column($rows, 'paid'));
$grandDue    = array_sum(array_column($rows, 'balance'));

function money(float $v): string { return '₹' . number_format($v, 2); }

$pageTitle = 'Fees report';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Fees report</h1>
        <p class="muted">
            <?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?>
            · Billed <strong><?= e(money($grandBilled)) ?></strong>
            · Paid <strong><?= e(money($grandPaid)) ?></strong>
            <?php if ($grandDue > 0): ?>
                · <span class="pill pill-warn">Due <?= e(money($grandDue)) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="grade">Grade</label>
        <select id="grade" name="grade">
            <option value="">All grades</option>
            <?php foreach ($VALID_GRADES as $g): ?>
                <option value="<?= e($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Show</label>
        <select id="status" name="status">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Everyone</option>
            <option value="due" <?= $statusFilter === 'due' ? 'selected' : '' ?>>Only with dues</option>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/students/fees_report.php">Reset</a>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty"><p>No matching students.</p></div>
<?php else: ?>
    <table class="att-summary">
        <thead>
            <tr>
                <th>Student</th>
                <th>Grade</th>
                <th>Invoices</th>
                <th>Billed</th>
                <th>Paid</th>
                <th>Balance</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $full = trim($r['first_name'] . ' ' . $r['last_name']);
            ?>
                <tr>
                    <td>
                        <a href="/students/view.php?id=<?= (int)$r['id'] ?>"><?= e($full) ?></a>
                        <?php if (!empty($r['admission_number'])): ?>
                            <div class="muted small">Adm #<?= e($r['admission_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?= e(grade_badge_class($r['grade'])) ?>"><?= e($r['grade']) ?></span></td>
                    <td><?= (int)$r['n_invoices'] ?></td>
                    <td><?= e(money((float)$r['billed'])) ?></td>
                    <td><?= e(money((float)$r['paid'])) ?></td>
                    <td>
                        <?php if ($r['balance'] > 0): ?>
                            <span class="pill pill-warn"><?= e(money((float)$r['balance'])) ?></span>
                        <?php else: ?>
                            <span class="pill">Settled</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-ghost" href="/students/fees.php?student_id=<?= (int)$r['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
