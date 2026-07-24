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
require_once __DIR__ . '/../includes/staff_form.php';

$user = require_module('staff');

$id = (int)($_POST['id'] ?? $_GET['id'] ?? $user['id']);
if (!staff_can_view($user, $id)) {
    http_response_code(403); echo 'Forbidden.'; exit;
}
$staff = staff_member($id);
if (!$staff) { http_response_code(404); echo 'Staff member not found.'; exit; }

$isAdmin = staff_is_admin($user);
$isSelf  = (int)$user['id'] === $id;
$canEdit = $isAdmin || $isSelf;   // who may upload documents to this record

// Admin-only: generate / revoke the public application-form link.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_check();
    $op = $_POST['op'] ?? '';
    if ($op === 'staff_generate_form_token') {
        staff_revoke_active_form_tokens($id);
        staff_generate_form_token($id, (int)$user['id']);
        flash_set('ok', 'Application-form link generated. Share it with ' . $staff['name'] . '.');
        redirect('/staff/view.php?id=' . $id . '#applink');
    } elseif ($op === 'staff_revoke_form_token') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid > 0) staff_revoke_form_token($tid);
        flash_set('ok', 'Link revoked.');
        redirect('/staff/view.php?id=' . $id . '#applink');
    }
}
$activeFormToken = $isAdmin ? staff_active_form_token($id) : null;
$photo   = staff_latest_photo($id);
$profile = staff_profile($id);
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
    <div style="display:flex; align-items:center; gap:1rem;">
        <?php if ($photo): ?>
            <img src="/staff/download.php?id=<?= (int)$photo['id'] ?>" alt="Photo of <?= e($staff['name']) ?>"
                 style="width:72px; height:72px; border-radius:50%; object-fit:cover; flex:0 0 auto;">
        <?php else: ?>
            <span class="who-avatar" style="width:72px; height:72px; font-size:1.6rem; flex:0 0 auto;"><?= e(user_initials($staff['name'])) ?></span>
        <?php endif; ?>
        <div>
            <h1><?= e($staff['name']) ?></h1>
            <p class="muted">
                <?php if ($isAdmin): ?><a href="/staff/index.php">← Roster</a> · <?php endif; ?>
                <?= e(role_label((string)$staff['role'])) ?>
                · <?= (int)$staff['active'] === 1 ? 'Active' : 'Inactive' ?>
            </p>
        </div>
    </div>
    <div class="actionbar">
        <?php if ($canEdit): ?>
            <a class="btn" href="/staff/profile.php?id=<?= $id ?>">Edit details</a>
        <?php endif; ?>
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

<?php
// Read-only view of a profile value, or a muted em-dash when blank.
$pv = static function (?string $v): string {
    $v = trim((string)$v);
    return $v === '' ? '<span class="muted">—</span>' : e($v);
};
$dobNice = '';
if (!empty($profile['date_of_birth'])) {
    $ts = strtotime((string)$profile['date_of_birth']);
    if ($ts) {
        $age = (int)((new DateTime('today'))->diff(new DateTime($profile['date_of_birth']))->y);
        $dobNice = date('j M Y', $ts) . ' · ' . $age . ' yrs';
    }
}
$relRelation = $profile['relative_relation'] !== '' ? (staff_relations()[$profile['relative_relation']] ?? '') : '';
?>
<div class="card" id="personal">
    <h3>Personal details</h3>
    <div class="row" style="align-items: flex-start;">
        <div style="flex: 1 1 240px;">
            <h4 class="muted small">Profile</h4>
            <dl class="dl-grid">
                <dt>Date of birth</dt><dd><?= $dobNice !== '' ? e($dobNice) : '<span class="muted">—</span>' ?></dd>
                <dt>Gender</dt><dd><?= $profile['gender'] !== '' ? e(staff_genders()[$profile['gender']] ?? $profile['gender']) : '<span class="muted">—</span>' ?></dd>
                <dt>Blood group</dt><dd><?= $pv($profile['blood_group']) ?></dd>
            </dl>
            <h4 class="muted small section-h-spaced">Education</h4>
            <dl class="dl-grid">
                <dt>Highest qualification</dt><dd><?= $pv($profile['highest_qualification']) ?></dd>
            </dl>
        </div>
        <div style="flex: 1 1 240px;">
            <h4 class="muted small">Address &amp; emergency</h4>
            <dl class="dl-grid">
                <dt>Home address</dt><dd style="white-space:pre-wrap;"><?= $pv($profile['home_address']) ?></dd>
                <dt>Emergency contact</dt><dd><?= $pv($profile['emergency_contact_name']) ?></dd>
                <dt>Emergency phone</dt><dd><?= $pv($profile['emergency_phone']) ?></dd>
            </dl>
        </div>
        <div style="flex: 1 1 240px;">
            <h4 class="muted small"><?= $relRelation !== '' ? e($relRelation) : 'Father / Spouse' ?> details</h4>
            <dl class="dl-grid">
                <dt>Name</dt><dd><?= $pv($profile['relative_name']) ?></dd>
                <dt>Email id</dt><dd><?= $pv($profile['relative_email']) ?></dd>
                <dt>Phone no.</dt><dd><?= $pv($profile['relative_phone']) ?></dd>
            </dl>
            <h4 class="muted small section-h-spaced">Experience</h4>
            <dl class="dl-grid">
                <dt>Previous employer</dt><dd style="white-space:pre-wrap;"><?= $pv($profile['previous_employer']) ?></dd>
            </dl>
        </div>
    </div>
    <?php if ($canEdit): ?>
        <div class="actions section-h-spaced">
            <a class="btn btn-ghost" href="/staff/profile.php?id=<?= $id ?>"><?= $profile['_exists'] ? 'Edit details' : 'Add details' ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<div class="card" id="applink">
    <h3 style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span>Application form link</span>
        <?php if ($activeFormToken): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Revoke this link? The staff member will see \'Link not active\'.');">
                <input type="hidden" name="_csrf"    value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id"       value="<?= $id ?>">
                <input type="hidden" name="op"       value="staff_revoke_form_token">
                <input type="hidden" name="token_id" value="<?= (int)$activeFormToken['id'] ?>">
                <button class="btn btn-ghost" type="submit">Revoke</button>
            </form>
        <?php else: ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id"    value="<?= $id ?>">
                <input type="hidden" name="op"    value="staff_generate_form_token">
                <button class="btn btn-primary" type="submit">Generate link</button>
            </form>
        <?php endif; ?>
    </h3>
    <?php if ($activeFormToken): $url = staff_form_url((string)$activeFormToken['token']); ?>
        <p class="muted small" style="margin:0 0 .4rem;">Share this with <?= e($staff['name']) ?> — no school login needed. They can fill in their details and upload their photo, ID proofs, certificates and experience letter.</p>
        <div style="display:flex; gap:.4rem; align-items:center; margin:.3rem 0; flex-wrap:wrap;">
            <input id="applink-url" type="text" readonly value="<?= e($url) ?>"
                   style="flex:1 1 260px; padding:.4rem; border:1px solid var(--line); border-radius:5px; font-family:monospace; font-size:.85rem;">
            <button type="button" class="btn btn-ghost"
                    onclick="navigator.clipboard.writeText(document.getElementById('applink-url').value).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500);});">Copy</button>
            <a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= e($url) ?>">Open</a>
        </div>
        <p class="muted small" style="margin:.3rem 0 0;">
            Created <?= e(date('j M Y', strtotime((string)$activeFormToken['created_at']))) ?>
            <?php if (!empty($activeFormToken['last_saved_at'])): ?> · Last saved <?= e(date('j M Y', strtotime((string)$activeFormToken['last_saved_at']))) ?><?php endif; ?>
            <?php if (!empty($activeFormToken['last_accessed_at'])): ?> · Last opened <?= e(date('j M Y', strtotime((string)$activeFormToken['last_accessed_at']))) ?>
            <?php else: ?> · Not opened yet<?php endif; ?>
        </p>
    <?php else: ?>
        <p class="muted small">No active link. Generating one creates a unique URL <?= e($staff['name']) ?> can use to fill in their application without logging in. Revoke it anytime.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

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
    <?php if ($canEdit): ?>
        <p class="muted small">
            Upload certificates, ID proofs, previous-experience certificates and a
            profile photo. PDF, DOC/DOCX, JPG or PNG · 8&nbsp;MB max.
            <?php if ($isSelf && !$isAdmin): ?> You can remove anything you upload here yourself.<?php endif; ?>
        </p>
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
                <?php $canDeleteDoc = $isAdmin || ($isSelf && (int)$d['uploaded_by'] === (int)$user['id']); ?>
                <li class="team-row">
                    <?php if ($d['kind'] === 'photo'): ?>
                        <img class="team-dot" src="/staff/download.php?id=<?= (int)$d['id'] ?>" alt=""
                             style="object-fit:cover;">
                    <?php else: ?>
                        <div class="team-dot">📄</div>
                    <?php endif; ?>
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
                    <?php if ($canDeleteDoc): ?>
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

<?php if ($canEdit): ?>
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
