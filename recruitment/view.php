<?php
/**
 * recruitment/view.php — candidate detail page.
 *
 * One-stop view: profile, attachments, evaluation scorecards (per evaluator
 * + averages), interview log, hire / reject actions. Server-side ops are
 * grouped here so the JSON api.php handles only the AJAX surface; reject /
 * delete / attachment-delete post back to this page like the CRM view does.
 *
 *   op=reject   { reason }     → marks status=rejected
 *   op=delete                  → hard delete (cascades)
 *   op=attach_del { aid }      → delete an attachment (file + row)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Bad id.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'reject') {
        $reason = trim($_POST['reason'] ?? '') ?: null;
        db()->prepare("
            UPDATE recruit_candidates SET status='rejected', rejected_reason=:r WHERE id=:id
        ")->execute([':r' => $reason, ':id' => $id]);
        flash_set('ok', 'Candidate marked as rejected.');
        redirect('/recruitment/view.php?id=' . $id);
    }

    if ($op === 'attach_del') {
        $aid = (int)($_POST['aid'] ?? 0);
        $stmt = db()->prepare("SELECT candidate_id, stored_name FROM recruit_attachments WHERE id = :a");
        $stmt->execute([':a' => $aid]);
        $a = $stmt->fetch();
        if ($a && (int)$a['candidate_id'] === $id) {
            $path = realpath(__DIR__ . '/..') . '/uploads/recruit_docs/' . $id . '/' . $a['stored_name'];
            if (is_file($path)) @unlink($path);
            db()->prepare("DELETE FROM recruit_attachments WHERE id = :a")->execute([':a' => $aid]);
            flash_set('ok', 'Attachment removed.');
        }
        redirect('/recruitment/view.php?id=' . $id . '#attachments');
    }

    if ($op === 'delete') {
        // Refuse to delete if already promoted — that would orphan a real
        // users row.
        $stmt = db()->prepare("SELECT promoted_user_id FROM recruit_candidates WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash_set('error', 'Already hired — cannot delete. Deactivate the user in /admin.php instead.');
            redirect('/recruitment/view.php?id=' . $id);
        }
        // Best-effort: wipe attachments from disk before the row goes.
        $dir = realpath(__DIR__ . '/..') . '/uploads/recruit_docs/' . $id;
        if (is_dir($dir)) {
            foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
        db()->prepare("DELETE FROM recruit_candidates WHERE id = :id")->execute([':id' => $id]);
        flash_set('ok', 'Candidate deleted.');
        redirect('/recruitment/index.php');
    }
}

// ---- Load -----------------------------------------------------------------
$stmt = db()->prepare("
    SELECT c.*, u.name AS owner_name, pu.name AS promoted_user_name
    FROM recruit_candidates c
    LEFT JOIN users u  ON u.id  = c.owner_id
    LEFT JOIN users pu ON pu.id = c.promoted_user_id
    WHERE c.id = :id
");
$stmt->execute([':id' => $id]);
$cand = $stmt->fetch();
if (!$cand) { http_response_code(404); echo 'Candidate not found.'; exit; }

$attachments = (function() use ($id) {
    $s = db()->prepare("
        SELECT a.*, u.name AS by_name
        FROM recruit_attachments a
        LEFT JOIN users u ON u.id = a.uploaded_by
        WHERE a.candidate_id = :id ORDER BY uploaded_at DESC
    ");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();
$evals = (function() use ($id) {
    $s = db()->prepare("
        SELECT e.*, u.name AS evaluator_name
        FROM recruit_evaluations e
        LEFT JOIN users u ON u.id = e.evaluator_id
        WHERE e.candidate_id = :id
        ORDER BY e.updated_at DESC
    ");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();
$interviews = (function() use ($id) {
    $s = db()->prepare("
        SELECT i.*, u.name AS interviewer_name, b.name AS by_name
        FROM recruit_interviews i
        LEFT JOIN users u ON u.id = i.interviewer_id
        LEFT JOIN users b ON b.id = i.created_by
        WHERE i.candidate_id = :id
        ORDER BY i.occurred_at DESC
    ");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();

$avg     = recruit_avg_scores($id);
$full    = trim(($cand['first_name'] ?? '') . ' ' . ($cand['last_name'] ?? ''));
$canHire = !in_array($cand['status'], ['hired', 'rejected', 'withdrawn'], true);
$money   = fn(float $v) => '₹' . number_format($v, 0);

$pageTitle = $full . ' — Recruitment';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($full) ?></h1>
        <p class="muted">
            <a href="/recruitment/index.php">← Pipeline</a>
            · <span class="pill pill-status-<?= e($cand['status']) ?>"><?= e(recruit_status_label($cand['status'])) ?></span>
            <?php if (($cand['priority'] ?? 'normal') !== 'normal'): ?>
                · <span class="pill pill-prio-<?= e($cand['priority']) ?>"><?= e(recruit_priorities()[$cand['priority']]) ?></span>
            <?php endif; ?>
            · <?= e(recruit_position_label($cand['position_applied'])) ?>
            <?php if ($cand['owner_name']): ?> · recruiter: <?= e($cand['owner_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <?php if ($canHire): ?>
            <button class="btn btn-primary" id="hire-btn" data-id="<?= (int)$id ?>" data-csrf="<?= e(csrf_token()) ?>">
                Hire →
            </button>
            <button class="btn" onclick="document.getElementById('reject-form').hidden = false">Reject</button>
        <?php endif; ?>
        <a class="btn" href="/recruitment/edit.php?id=<?= $id ?>">Edit</a>
        <?php if (!$cand['promoted_user_id']): ?>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete this candidate permanently? All evaluations, interviews and attachments will be lost.')">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="delete">
                <button class="btn btn-ghost">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($cand['promoted_user_id']): ?>
    <div class="flash flash-ok">
        Hired — a user row was created for <strong><?= e((string)$cand['promoted_user_name']) ?></strong>.
        <a href="/admin.php">Open Admin → Users</a> to set a PIN and assign modules.
    </div>
<?php endif; ?>

<?php if ($cand['status'] === 'rejected' && $cand['rejected_reason']): ?>
    <div class="flash flash-error" style="background:#fdf0d3; border-color:#f0c98a; color:#78420a;">
        <strong>Rejected:</strong> <?= e($cand['rejected_reason']) ?>
    </div>
<?php endif; ?>

<form id="reject-form" method="post" class="card" hidden>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="reject">
    <h3>Reject candidate</h3>
    <div class="field">
        <label>Reason (optional, but helpful)</label>
        <input name="reason" maxlength="255" placeholder="e.g. Stronger candidate progressed">
    </div>
    <div class="actions">
        <button class="btn btn-primary">Confirm reject</button>
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('reject-form').hidden = true">Cancel</button>
    </div>
</form>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 320px;">
        <h3>Contact</h3>
        <dl class="dl-grid">
            <dt>Phone</dt><dd><?= e((string)$cand['phone']) ?: '—' ?></dd>
            <dt>Email</dt><dd><?= e((string)$cand['email']) ?: '—' ?></dd>
            <dt>Source</dt><dd><?= e((string)$cand['source']) ?: '—' ?></dd>
            <dt>Experience</dt><dd><?= $cand['years_experience'] !== null ? (int)$cand['years_experience'] . ' yr' : '—' ?></dd>
            <dt>Expected salary</dt><dd>
                <?= $cand['expected_salary'] !== null ? e($money((float)$cand['expected_salary'])) : '—' ?>
            </dd>
            <dt>Available from</dt><dd><?= e((string)$cand['available_from']) ?: '—' ?></dd>
            <dt>Certifications</dt><dd><?= e((string)$cand['certifications']) ?: '—' ?></dd>
        </dl>
        <?php if ($cand['notes']): ?>
            <p class="muted small" style="margin-top:.6rem; white-space:pre-wrap;"><?= e($cand['notes']) ?></p>
        <?php endif; ?>
    </div>

    <div class="card" style="flex: 1 1 320px;">
        <h3>Average scorecard</h3>
        <?php if ((int)($avg['evaluators'] ?? 0) === 0): ?>
            <p class="muted">No evaluations yet. <a href="/recruitment/evaluate.php?candidate_id=<?= $id ?>">Add the first one</a>.</p>
        <?php else: ?>
            <dl class="dl-grid">
                <?php foreach (recruit_eval_dimensions() as $code => $label):
                    $v = $avg[$code] ?? null;
                ?>
                    <dt><?= e($label) ?></dt>
                    <dd><?= $v === null ? '—' : number_format((float)$v, 1) . ' / 5' ?></dd>
                <?php endforeach; ?>
                <dt>Evaluators</dt><dd><?= (int)$avg['evaluators'] ?></dd>
            </dl>
        <?php endif; ?>
        <div class="actions section-h-spaced">
            <a class="btn" href="/recruitment/evaluate.php?candidate_id=<?= $id ?>">My scorecard</a>
        </div>
    </div>
</div>

<div class="card" id="attachments">
    <h3>Attachments</h3>
    <p class="muted small">PDF / DOC / DOCX / JPG / PNG, up to 8 MB each.</p>
    <form id="upload-form" class="row" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="candidate_id" value="<?= (int)$id ?>">
        <div class="field">
            <label>Kind</label>
            <select name="kind">
                <?php foreach (recruit_attachment_kinds() as $code => $label): ?>
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

    <?php if (!$attachments): ?>
        <p class="muted">No attachments yet.</p>
    <?php else: ?>
        <ul class="team-list" id="attach-list">
            <?php foreach ($attachments as $a): ?>
                <li class="team-row">
                    <div class="team-dot">📄</div>
                    <div>
                        <div class="team-name">
                            <a href="/recruitment/download.php?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">
                                <?= e($a['original_name']) ?>
                            </a>
                        </div>
                        <div class="team-meta">
                            <?= e(recruit_attachment_kinds()[$a['kind']] ?? $a['kind']) ?>
                            · <?= e(format_bytes((int)$a['size_bytes'])) ?>
                            · <?= e(date('j M Y', strtotime($a['uploaded_at']))) ?>
                            <?php if ($a['by_name']): ?> · by <?= e($a['by_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <form method="post" class="timeline-del"
                          onsubmit="return confirm('Delete this attachment? The file will be removed from disk.')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="attach_del">
                        <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
                        <button class="link-btn" title="Delete">×</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card" id="evaluations">
    <h3>Evaluation scorecards</h3>
    <?php if (!$evals): ?>
        <p class="muted">No scorecards yet. <a href="/recruitment/evaluate.php?candidate_id=<?= $id ?>">Add yours →</a></p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Evaluator</th>
                    <?php foreach (recruit_eval_dimensions() as $label): ?>
                        <th><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th>Recommend</th>
                    <th>Comments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($evals as $ev): ?>
                    <tr>
                        <td><?= e((string)$ev['evaluator_name']) ?></td>
                        <?php foreach (array_keys(recruit_eval_dimensions()) as $d): ?>
                            <td><?= $ev[$d] !== null ? (int)$ev[$d] : '—' ?></td>
                        <?php endforeach; ?>
                        <td><?= $ev['overall_recommend'] ? e(recruit_recommendations()[$ev['overall_recommend']] ?? $ev['overall_recommend']) : '—' ?></td>
                        <td class="muted small"><?= e((string)$ev['comments']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card" id="interviews">
    <h3>Interviews &amp; notes</h3>
    <p class="muted small">
        <a class="btn" href="/recruitment/interview_new.php?candidate_id=<?= $id ?>">+ Log interview / note</a>
    </p>
    <?php if (!$interviews): ?>
        <p class="muted">No interviews logged yet.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($interviews as $iv): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M Y', strtotime($iv['occurred_at']))) ?></strong>
                        <span class="muted small"><?= e(date('H:i', strtotime($iv['occurred_at']))) ?></span>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(recruit_interview_stages()[$iv['stage']] ?? $iv['stage']) ?></span>
                        <?php if ($iv['outcome']): ?>
                            <span class="pill pill-status-<?= e($iv['outcome']) ?>"><?= e(ucfirst($iv['outcome'])) ?></span>
                        <?php endif; ?>
                        <?php if ($iv['body']): ?>
                            <div style="margin-top:.3rem; white-space:pre-wrap;"><?= e($iv['body']) ?></div>
                        <?php endif; ?>
                        <div class="muted small">
                            <?php if ($iv['interviewer_name']): ?>by <?= e($iv['interviewer_name']) ?><?php endif; ?>
                            <?php if ($iv['location']): ?> · <?= e($iv['location']) ?><?php endif; ?>
                            <?php if ($iv['duration_min']): ?> · <?= (int)$iv['duration_min'] ?> min<?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<script>
// AJAX hire — posts to /recruitment/api.php so the user-row promotion runs in
// a single transaction. On success, reload so the "Hired" banner + admin
// onboarding link appear.
document.getElementById('hire-btn')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    if (!confirm('Hire this candidate? A user row will be created (inactive — set PIN in Admin to finish onboarding).')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('_csrf', btn.dataset.csrf);
    fd.append('op', 'hire');
    fd.append('id', btn.dataset.id);
    try {
        const res = await fetch('/recruitment/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Hire failed');
        window.location.reload();
    } catch (e) {
        alert('Hire failed: ' + e.message);
        btn.disabled = false;
    }
});

// AJAX upload — keeps users on the page after attaching.
document.getElementById('upload-form')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(ev.currentTarget);
    try {
        const res = await fetch('/recruitment/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Upload failed');
        window.location.reload();
    } catch (e) {
        alert('Upload failed: ' + e.message);
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
