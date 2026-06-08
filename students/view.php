<?php
/**
 * students/view.php — read-only student profile.
 * Shows demographic + emergency + parents/guardians blocks and quick links to
 * the assessment module's per-student pages.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/student_form.php';

$user = require_login();
if (!user_has_module($user, 'students') && !user_has_module($user, 'montessori')) {
    http_response_code(403);
    echo 'Forbidden — you do not have access to the students module.';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { redirect('/students/index.php'); }

// Parent-form-link + intake actions (admins only). Handled before any HTML output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if (in_array($op, ['generate_form_token', 'revoke_form_token', 'approve_intake'], true)) {
        require_admin();
        csrf_check();
        try {
            if ($op === 'generate_form_token') {
                revoke_active_form_tokens($id);
                generate_form_token($id, (int)$user['id']);
                flash_set('ok', 'Parent form link generated.');
            } elseif ($op === 'revoke_form_token') {
                $tokId = (int)($_POST['token_id'] ?? 0);
                if ($tokId > 0) revoke_form_token($tokId);
                flash_set('ok', 'Parent form link revoked.');
            } else {
                // Approve an intake_pending row: flip it to enrolled and
                // stamp the added date. The placeholder is_active=0 from
                // intake_new.php is also flipped on so the child shows up
                // in the default students list.
                db()->prepare("
                    UPDATE students
                    SET    enrollment_status  = 'enrolled',
                           is_active          = 1,
                           intake_approved_at = NOW()
                    WHERE  id = :id AND enrollment_status = 'intake_pending'
                ")->execute([':id' => $id]);
                // Once approved the parent link should no longer work —
                // they can keep editing via the regular form, but only an
                // admin can re-issue a fresh link from this same panel.
                revoke_active_form_tokens($id);
                flash_set('ok', 'Added to the student list.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not update: ' . $e->getMessage());
        }
        redirect('/students/view.php?id=' . $id);
    }
}

$stmt = db()->prepare("
    SELECT s.*, u.name AS teacher_name
    FROM students s
    LEFT JOIN users u ON u.id = s.teacher_id
    WHERE s.id = :id
");
$stmt->execute([':id' => $id]);
$s = $stmt->fetch();
if (!$s) {
    http_response_code(404);
    flash_set('error', 'Student not found.');
    redirect('/students/index.php');
}

// Teachers (assessment users) can only view their own students unless they
// also have the students module or are admin.
$canSeeAll = $user['role'] === 'admin' || user_has_module($user, 'students');
if (!$canSeeAll && (int)$s['teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'Forbidden — this student is not assigned to you.';
    exit;
}

$canEdit  = $user['role'] === 'admin' || user_has_module($user, 'students');
$isAdmin  = $user['role'] === 'admin';

// Active parent-form token (admins only — non-admins shouldn't see the link).
$activeFormToken = $isAdmin ? active_form_token_for_student($id) : null;

// Parents/guardians
$pstmt = db()->prepare("SELECT * FROM student_parents WHERE student_id = :id ORDER BY is_primary DESC, relation, id");
$pstmt->execute([':id' => $id]);
$parents = $pstmt->fetchAll();

// Recent documents (just the latest 5 — full list lives on documents.php).
$docCount = 0;
$recentDocs = [];
try {
    $dstmt = db()->prepare("SELECT COUNT(*) FROM student_documents WHERE student_id = :id");
    $dstmt->execute([':id' => $id]);
    $docCount = (int)$dstmt->fetchColumn();

    if ($docCount > 0) {
        $dstmt = db()->prepare("
            SELECT id, title, category, original_filename, size_bytes, mime_type, uploaded_at
            FROM student_documents
            WHERE student_id = :id
            ORDER BY uploaded_at DESC, id DESC
            LIMIT 5
        ");
        $dstmt->execute([':id' => $id]);
        $recentDocs = $dstmt->fetchAll();
    }
} catch (Throwable $e) { /* table may not yet exist — Phase 2 migration not run */ }

$full = trim($s['first_name'] . ' ' . $s['last_name']);

/** Render a definition-list row only when the value is non-empty. */
function dl_row(string $label, ?string $value, bool $isMultiline = false): void
{
    if ($value === null || trim((string)$value) === '') return;
    echo '<div class="dl-row">';
    echo '<dt>' . e($label) . '</dt>';
    if ($isMultiline) {
        echo '<dd><pre class="pre-wrap">' . e($value) . '</pre></dd>';
    } else {
        echo '<dd>' . e($value) . '</dd>';
    }
    echo '</div>';
}

function fmt_date(?string $iso): string
{
    if (!$iso || $iso === '0000-00-00') return '';
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('j M Y') : (string)$iso;
}

function age_from_dob(?string $iso): string
{
    if (!$iso || $iso === '0000-00-00') return '';
    try {
        $d   = new DateTime($iso);
        $now = new DateTime('today');
        $a   = $d->diff($now);
        if ($a->y > 0) return $a->y . 'y ' . $a->m . 'm';
        return $a->m . 'm';
    } catch (Throwable $e) { return ''; }
}

$pageTitle = $full;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <?php $enrStatus = $s['enrollment_status'] ?? 'enrolled'; ?>
        <h1 style="display:flex; align-items:center; gap:.6rem;">
            <span class="student-avatar" style="--card: <?= e(user_color((int)$s['id'])) ?>;"><?= e(user_initials($full)) ?></span>
            <?= e($full) ?>
            <?php if ($enrStatus !== 'enrolled'): ?>
                <span class="pill enr-<?= e($enrStatus) ?>"><?= e(enrollment_status_label($enrStatus)) ?></span>
            <?php endif; ?>
        </h1>
        <p class="muted">
            <span class="<?= e(grade_badge_class($s['grade'])) ?>"><?= e($s['grade']) ?></span>
            <?php if (!empty($s['academic_year'])): ?> · <strong><?= e($s['academic_year']) ?></strong><?php endif; ?>
            <?php if (!empty($s['teacher_name'])): ?> · Teacher: <strong><?= e($s['teacher_name']) ?></strong><?php endif; ?>
            <?php if (!empty($s['admission_number'])): ?> · Admission #<?= e($s['admission_number']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Back</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary" href="/students/edit.php?id=<?= (int)$s['id'] ?>">Edit</a>
            <a class="btn" href="/students/documents.php?student_id=<?= (int)$s['id'] ?>">Documents<?= $docCount ? ' · ' . $docCount : '' ?></a>
        <?php endif; ?>
        <a class="btn" href="/students/attendance_history.php?student_id=<?= (int)$s['id'] ?>">Attendance</a>
        <?php if ($canEdit): ?>
            <a class="btn" href="/students/fees.php?student_id=<?= (int)$s['id'] ?>">Fees</a>
        <?php endif; ?>
        <?php if (user_has_module($user, 'montessori')): ?>
            <a class="btn" href="/assessment/progress.php?student_id=<?= (int)$s['id'] ?>">Progress</a>
            <a class="btn btn-ghost" href="/assessment/baseline.php?student_id=<?= (int)$s['id'] ?>">Baseline</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($enrStatus === 'intake_pending' && $isAdmin): ?>
<section class="card" style="border-left: 4px solid #f5b342; background: #fff8e7;">
    <h2 style="margin-top:0; display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span>Intake pending — awaiting parent submission</span>
        <form method="post" style="margin:0;"
              onsubmit="return confirm('Add this child to the student list? The parent link will be revoked.');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op"    value="approve_intake">
            <button class="btn btn-primary" type="submit">Approve &amp; add to student list</button>
        </form>
    </h2>
    <p class="muted" style="margin:.3rem 0 0;">
        This is a draft record created from <code>/students/intake_new.php</code>.
        Share the parent form link below with the family. Review their submission, then click <strong>Approve</strong> to add them to the student list — the added date will be stamped automatically.
        <?php if ($activeFormToken && !empty($activeFormToken['last_saved_at'])): ?>
            <br>Parent last saved: <strong><?= e(fmt_date(substr((string)$activeFormToken['last_saved_at'], 0, 10))) ?></strong>.
        <?php elseif ($activeFormToken && !empty($activeFormToken['last_accessed_at'])): ?>
            <br>Parent has opened the link but hasn't saved yet.
        <?php else: ?>
            <br>Parent hasn't opened the link yet.
        <?php endif; ?>
    </p>
</section>
<?php endif; ?>

<?php if ($isAdmin): ?>
<section class="card">
    <h2 style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span>Parent form link</span>
        <?php if ($activeFormToken): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Revoke this link? The parent will see \'Link not active\'.');">
                <input type="hidden" name="_csrf"    value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op"       value="revoke_form_token">
                <input type="hidden" name="token_id" value="<?= (int)$activeFormToken['id'] ?>">
                <button class="btn btn-ghost" type="submit">Revoke</button>
            </form>
        <?php else: ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op"    value="generate_form_token">
                <button class="btn btn-primary" type="submit">Generate link</button>
            </form>
        <?php endif; ?>
    </h2>
    <?php if ($activeFormToken):
        $url = parent_form_url((string)$activeFormToken['token']);
    ?>
        <p class="muted small" style="margin:0 0 .4rem;">Share this with the parent — no school login needed. They can edit child / parent details, address, emergency contact, and upload documents.</p>
        <div style="display:flex; gap:.4rem; align-items:center; margin:.3rem 0;">
            <input id="pf-url" type="text" readonly value="<?= e($url) ?>"
                   style="flex:1; padding:.4rem; border:1px solid #d4c2a8; border-radius:5px; background:#f4ece0; font-family:monospace; font-size:.85rem;">
            <button type="button" class="btn btn-ghost"
                    onclick="navigator.clipboard.writeText(document.getElementById('pf-url').value).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500);});">Copy</button>
            <a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= e($url) ?>">Open</a>
        </div>
        <p class="muted small" style="margin:.3rem 0 0;">
            Created <?= e(fmt_date(substr((string)$activeFormToken['created_at'], 0, 10))) ?>
            <?php if (!empty($activeFormToken['last_saved_at'])): ?> · Last saved <?= e(fmt_date(substr((string)$activeFormToken['last_saved_at'], 0, 10))) ?><?php endif; ?>
            <?php if (!empty($activeFormToken['last_accessed_at'])): ?> · Last opened <?= e(fmt_date(substr((string)$activeFormToken['last_accessed_at'], 0, 10))) ?><?php endif; ?>
        </p>
    <?php else: ?>
        <p class="muted small">No active link. Generating one creates a unique URL the parent can use to edit this child's record without logging in. Revoke it anytime.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (in_array($enrStatus, ['withdrawn','graduated','on_break'], true)): ?>
<section class="card enr-callout enr-callout-<?= e($enrStatus) ?>">
    <h2>Enrollment: <?= e(enrollment_status_label($enrStatus)) ?></h2>
    <dl class="dl-grid">
        <?php
        dl_row('End date', fmt_date($s['withdrawal_date'] ?? null));
        if (!empty($s['withdrawal_reason'])) dl_row('Reason', withdrawal_reason_label($s['withdrawal_reason']));
        dl_row('Notes', $s['withdrawal_notes'] ?? null, true);
        ?>
        <?php if (empty($s['withdrawal_reason']) && empty($s['withdrawal_date']) && empty($s['withdrawal_notes'])): ?>
            <p class="muted">No reason recorded.<?php if ($canEdit): ?> <a href="/students/edit.php?id=<?= (int)$s['id'] ?>">Add one</a>.<?php endif; ?></p>
        <?php endif; ?>
    </dl>
</section>
<?php endif; ?>

<section class="card">
    <h2>Profile</h2>
    <dl class="dl-grid">
        <?php
        dl_row('Date of birth', fmt_date($s['dob']) . (age_from_dob($s['dob']) ? ' (' . age_from_dob($s['dob']) . ')' : ''));
        dl_row('Gender', $s['gender'] ?? null);
        dl_row('Joining date', fmt_date($s['joining_date']));
        if (!empty($s['intake_approved_at'])) {
            dl_row('Added on', fmt_date(substr((string)$s['intake_approved_at'], 0, 10)));
        }
        dl_row('Blood group', $s['blood_group'] ?? null);
        dl_row('Allergies', $s['allergies'] ?? null, true);
        dl_row('Medical notes', $s['medical_notes'] ?? null, true);
        dl_row('Notes', $s['notes'] ?? null, true);
        ?>
    </dl>
</section>

<section class="card">
    <h2>Address &amp; emergency</h2>
    <dl class="dl-grid">
        <?php
        dl_row('Home address', $s['home_address'] ?? null, true);
        dl_row('Pickup person', $s['pickup_person'] ?? null);
        dl_row('Pickup phone', $s['pickup_phone'] ?? null);
        dl_row('Emergency contact', $s['emergency_contact_name'] ?? null);
        dl_row('Emergency phone', $s['emergency_contact_phone'] ?? null);
        ?>
        <?php if (empty($s['home_address']) && empty($s['pickup_person']) && empty($s['emergency_contact_name'])): ?>
            <p class="muted">No address or emergency contact recorded.</p>
        <?php endif; ?>
    </dl>
</section>

<section class="card">
    <h2>Parents / guardians</h2>
    <?php if (!$parents): ?>
        <p class="muted">No parents or guardians recorded yet.<?php if ($canEdit): ?> Add them on the <a href="/students/edit.php?id=<?= (int)$s['id'] ?>">edit page</a>.<?php endif; ?></p>
    <?php else: ?>
        <ul class="parent-list">
            <?php foreach ($parents as $p): ?>
                <li class="parent-row">
                    <div>
                        <div class="parent-name">
                            <?= e($p['name']) ?>
                            <span class="pill"><?= e(ucfirst($p['relation'])) ?></span>
                            <?php if ($p['is_primary']): ?><span class="pill pill-warn">primary</span><?php endif; ?>
                        </div>
                        <div class="parent-meta muted small">
                            <?php if (!empty($p['phone'])): ?>📞 <?= e($p['phone']) ?>&nbsp;&nbsp;<?php endif; ?>
                            <?php if (!empty($p['email'])): ?>✉ <?= e($p['email']) ?>&nbsp;&nbsp;<?php endif; ?>
                            <?php if (!empty($p['occupation'])): ?>· <?= e($p['occupation']) ?><?php endif; ?>
                        </div>
                        <?php if (!empty($p['address'])): ?>
                            <div class="muted small"><?= e($p['address']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <div class="page-head" style="margin:0 0 .5rem;">
        <h2 style="margin:0;">Documents <?php if ($docCount): ?><span class="pill"><?= (int)$docCount ?></span><?php endif; ?></h2>
        <?php if ($canEdit): ?>
            <a class="btn btn-ghost" href="/students/documents.php?student_id=<?= (int)$s['id'] ?>">Manage</a>
        <?php endif; ?>
    </div>
    <?php if (!$recentDocs): ?>
        <p class="muted">No documents uploaded yet.<?php if ($canEdit): ?> <a href="/students/documents.php?student_id=<?= (int)$s['id'] ?>">Add one</a>.<?php endif; ?></p>
    <?php else: ?>
        <ul class="doc-list">
            <?php foreach ($recentDocs as $d): ?>
                <li class="doc-row">
                    <div>
                        <div class="doc-title">
                            <a href="/students/document_download.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a>
                            <span class="pill"><?= e(student_doc_category_label($d['category'])) ?></span>
                        </div>
                        <div class="doc-meta muted small">
                            <?= e($d['original_filename']) ?>
                            · <?= e(format_bytes((int)$d['size_bytes'])) ?>
                            · <?= e(substr((string)$d['uploaded_at'], 0, 16)) ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($docCount > count($recentDocs)): ?>
            <p class="muted small"><a href="/students/documents.php?student_id=<?= (int)$s['id'] ?>">See all <?= (int)$docCount ?> documents →</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
