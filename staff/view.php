<?php
/**
 * staff/view.php — Per-staff dashboard.
 *
 * Profile + month attendance + leave balance + recent issues + documents +
 * recent management messages. Admins see + edit everything. Staff see their
 * own page in read-mostly mode (with check-in / apply-leave / send-message
 * shortcuts that link out to the relevant sub-pages).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');

$id = (int)($_GET['id'] ?? $user['id']);
if (!staff_can_view($user, $id)) {
    http_response_code(403); echo 'Forbidden.'; exit;
}
$staff = staff_member($id);
if (!$staff) { http_response_code(404); echo 'Staff member not found.'; exit; }

$isAdmin = staff_is_admin($user);
$isSelf  = (int)$user['id'] === $id;
$year    = (int)date('Y');
$month   = (int)date('n');

$attendance = staff_attendance_summary($id, $year, $month);
$balance    = staff_leave_balance($id, $year);
$hours      = staff_hours_summary($id, $year, $month);
$currentPay = staff_current_pay($id, date('Y-m-d'));

$recentIssues = (function() use ($id, $isAdmin) {
    $sql = "
        SELECT i.*, u.name AS by_name
        FROM staff_issues i
        LEFT JOIN users u ON u.id = i.logged_by
        WHERE i.user_id = :u";
    if (!$isAdmin) $sql .= " AND i.visible_to_staff = 1";
    $sql .= " ORDER BY i.occurred_at DESC LIMIT 5";
    $s = db()->prepare($sql);
    $s->execute([':u' => $id]);
    return $s->fetchAll();
})();

$docs = (function() use ($id) {
    $s = db()->prepare("
        SELECT d.*, u.name AS by_name
        FROM staff_documents d
        LEFT JOIN users u ON u.id = d.uploaded_by
        WHERE d.user_id = :u ORDER BY uploaded_at DESC
    ");
    $s->execute([':u' => $id]);
    return $s->fetchAll();
})();

$recentMsgs = (function() use ($id) {
    $s = db()->prepare("
        SELECT m.*, r.name AS responder_name
        FROM staff_messages m
        LEFT JOIN users r ON r.id = m.responded_by
        WHERE m.from_user_id = :u
        ORDER BY m.created_at DESC LIMIT 5
    ");
    $s->execute([':u' => $id]);
    return $s->fetchAll();
})();

$today = date('Y-m-d');
$todayRow = (function() use ($id, $today) {
    $s = db()->prepare("SELECT * FROM staff_attendance WHERE user_id = :u AND att_date = :d");
    $s->execute([':u' => $id, ':d' => $today]);
    return $s->fetch();
})();

$pageTitle = $staff['name'] . ' — Staff';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($staff['name']) ?></h1>
        <p class="muted">
            <?php if ($isAdmin): ?><a href="/staff/index.php">← Roster</a> · <?php endif; ?>
            <?= e(ucfirst((string)$staff['role'])) ?>
            · <?= (int)$staff['active'] === 1 ? 'Active' : 'Inactive' ?>
        </p>
    </div>
    <div class="actionbar">
        <?php if ($isSelf): ?>
            <a class="btn btn-primary" href="/staff/attendance.php#self">Check-in / out</a>
            <a class="btn" href="/staff/leave.php?user_id=<?= $id ?>#apply">Apply leave</a>
            <a class="btn" href="/staff/messages.php#new">Message management</a>
        <?php endif; ?>
        <a class="btn" href="/staff/payslip.php?id=<?= $id ?>">Payslips</a>
        <?php if ($isAdmin): ?>
            <a class="btn" href="/staff/attendance.php?user_id=<?= $id ?>">Attendance</a>
            <a class="btn" href="/staff/leave.php?user_id=<?= $id ?>">Leave</a>
            <a class="btn" href="/staff/pay.php?id=<?= $id ?>">Pay structure</a>
            <a class="btn" href="/staff/issues.php?user_id=<?= $id ?>">Log issue</a>
        <?php endif; ?>
    </div>
</div>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 280px;">
        <h3>Attendance — <?= e(date('F Y')) ?></h3>
        <dl class="dl-grid">
            <?php foreach (staff_attendance_statuses() as $code => $label): ?>
                <dt><?= e($label) ?></dt>
                <dd><?= (int)($attendance[$code] ?? 0) ?> d</dd>
            <?php endforeach; ?>
        </dl>
        <p class="muted small section-h-spaced">
            Hours worked this month: <strong><?= e(number_format($hours['hours'], 1)) ?> h</strong>
            <?php if ($hours['days'] > 0): ?> over <?= (int)$hours['days'] ?> clocked day<?= $hours['days'] === 1 ? '' : 's' ?><?php endif; ?>
        </p>
        <?php if ($todayRow): ?>
            <p class="muted small">
                Today: <strong><?= e(staff_attendance_statuses()[$todayRow['status']] ?? $todayRow['status']) ?></strong>
                <?php if ($todayRow['check_in']): ?> · in <?= e(substr($todayRow['check_in'], 0, 5)) ?><?php endif; ?>
                <?php if ($todayRow['check_out']): ?> · out <?= e(substr($todayRow['check_out'], 0, 5)) ?><?php endif; ?>
            </p>
        <?php else: ?>
            <p class="muted small">No attendance marked yet today.</p>
        <?php endif; ?>
    </div>

    <div class="card" style="flex: 1 1 280px;">
        <h3>Leave balance — <?= (int)$year ?></h3>
        <table class="admin-table">
            <thead><tr><th>Type</th><th>Total</th><th>Used</th><th>Left</th></tr></thead>
            <tbody>
                <?php foreach ($balance as $code => $b): ?>
                    <tr>
                        <td><?= e($b['label']) ?></td>
                        <td><?= e((string)$b['total']) ?></td>
                        <td><?= e((string)$b['used']) ?></td>
                        <td><strong><?= e((string)$b['remaining']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($isAdmin): ?>
            <div class="actions section-h-spaced">
                <a class="btn btn-ghost" href="/staff/leave.php?user_id=<?= $id ?>#allowances">Edit allowances</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin || $isSelf): ?>
    <div class="card" style="flex: 1 1 280px;">
        <h3>Pay</h3>
        <?php if ($currentPay): ?>
            <dl class="dl-grid">
                <dt>Gross</dt><dd><strong><?= e(staff_money(staff_pay_gross($currentPay))) ?></strong>/mo</dd>
                <dt>Deductions</dt><dd><?= e(staff_money(staff_pay_total_deductions($currentPay))) ?></dd>
                <dt>Net (pre-LOP)</dt><dd><strong><?= e(staff_money(staff_pay_gross($currentPay) - staff_pay_total_deductions($currentPay))) ?></strong></dd>
                <dt>Effective</dt><dd><?= e(date('j M Y', strtotime($currentPay['effective_from']))) ?></dd>
            </dl>
        <?php else: ?>
            <p class="muted small">No pay structure on file yet.</p>
        <?php endif; ?>
        <div class="actions section-h-spaced">
            <a class="btn btn-ghost" href="/staff/payslip.php?id=<?= $id ?>">Payslips</a>
            <?php if ($isAdmin): ?><a class="btn btn-ghost" href="/staff/pay.php?id=<?= $id ?>"><?= $currentPay ? 'Revise pay' : 'Set pay' ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="issues">
    <h3>Issues discussed</h3>
    <?php if (!$recentIssues): ?>
        <p class="muted">No issues logged.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($recentIssues as $iv): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M Y', strtotime($iv['occurred_at']))) ?></strong>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(staff_issue_kinds()[$iv['kind']] ?? $iv['kind']) ?></span>
                        <strong style="margin-left:.4rem;"><?= e($iv['subject']) ?></strong>
                        <?php if ($iv['body']): ?>
                            <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e($iv['body']) ?></div>
                        <?php endif; ?>
                        <?php if ($iv['by_name']): ?>
                            <div class="muted small">logged by <?= e($iv['by_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
        <div class="actions section-h-spaced">
            <a class="btn" href="/staff/issues.php?user_id=<?= $id ?>">+ Log issue</a>
            <a class="btn btn-ghost" href="/staff/issues.php?user_id=<?= $id ?>&view=all">View all</a>
        </div>
    <?php endif; ?>
</div>

<div class="card" id="documents">
    <h3>Documents</h3>
    <?php if ($isAdmin): ?>
        <form id="staff-upload-form" class="row" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="user_id" value="<?= $id ?>">
            <div class="field">
                <label>Kind</label>
                <select name="kind">
                    <?php foreach (staff_doc_kinds() as $code => $label): ?>
                        <option value="<?= e($code) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 2 1 320px;">
                <label>File</label>
                <input type="file" name="file" required
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/*">
            </div>
            <div class="actions"><button class="btn btn-primary" type="submit">Upload</button></div>
        </form>
    <?php endif; ?>

    <?php if (!$docs): ?>
        <p class="muted">No documents on file.</p>
    <?php else: ?>
        <ul class="team-list">
            <?php foreach ($docs as $d): ?>
                <li class="team-row">
                    <div class="team-dot">📄</div>
                    <div>
                        <div class="team-name">
                            <a href="/staff/download.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
                                <?= e($d['original_name']) ?>
                            </a>
                        </div>
                        <div class="team-meta">
                            <?= e(staff_doc_kinds()[$d['kind']] ?? $d['kind']) ?>
                            · <?= e(format_bytes((int)$d['size_bytes'])) ?>
                            · <?= e(date('j M Y', strtotime($d['uploaded_at']))) ?>
                            <?php if ($d['by_name']): ?> · by <?= e($d['by_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isAdmin): ?>
                        <form method="post" action="/staff/upload.php" class="timeline-del"
                              onsubmit="return confirm('Delete this document?')">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <button class="link-btn" title="Delete">×</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($isSelf || $isAdmin): ?>
<div class="card" id="messages">
    <h3>Recent messages to management</h3>
    <?php if (!$recentMsgs): ?>
        <p class="muted">No messages yet.
            <?php if ($isSelf): ?><a href="/staff/messages.php#new">Send one →</a><?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($recentMsgs as $m): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M', strtotime($m['created_at']))) ?></strong>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(staff_message_statuses()[$m['status']] ?? $m['status']) ?></span>
                        <strong style="margin-left:.4rem;"><?= e($m['subject']) ?></strong>
                        <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e($m['body']) ?></div>
                        <?php if ($m['response']): ?>
                            <div class="muted small section-h-spaced">
                                <em>Response from <?= e((string)$m['responder_name']) ?>:</em>
                                <div style="white-space:pre-wrap; color:#333;"><?= e($m['response']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="actions section-h-spaced">
            <a class="btn btn-ghost" href="/staff/messages.php<?= $isAdmin && !$isSelf ? '?from_user_id=' . $id : '' ?>">All messages</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<script>
document.getElementById('staff-upload-form')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(ev.currentTarget);
    try {
        const res = await fetch('/staff/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Upload failed');
        window.location.reload();
    } catch (e) {
        alert('Upload failed: ' + e.message);
    }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
