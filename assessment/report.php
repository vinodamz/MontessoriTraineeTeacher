<?php
/**
 * assessment/report.php — the detailed progress report for one child.
 *
 * Everything the assessment module holds about a child, rendered as one
 * print-friendly document: identity, attendance, entry baseline, per-area
 * averages with trend, a trend chart, skill-by-skill ratings month by month,
 * teacher remarks, and the rating key.
 *
 * Sections with no data are omitted entirely (see includes/child_report.php).
 *
 * Admins can open any child; teachers only their own — same rule as
 * progress.php. Also hosts the "share with parents" link panel.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/child_report.php';

$user = require_module('montessori');

$studentId = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
if ($studentId <= 0) { http_response_code(400); echo 'No child selected.'; exit; }

$d = child_report_data($studentId);
if (!$d) { http_response_code(404); echo 'Student not found.'; exit; }

$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isAdmin && (int)$d['student']['teacher_id'] !== (int)$user['id']) {
    http_response_code(403); echo 'You can only view your own students.'; exit;
}

// Generate / revoke the public parent link.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    if ($op === 'report_link_new') {
        child_report_revoke_active($studentId);
        child_report_generate_token($studentId, (int)$user['id']);
        flash_set('ok', 'Parent link generated.');
        redirect('/assessment/report.php?student_id=' . $studentId . '#sharelink');
    } elseif ($op === 'report_link_revoke') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid > 0) child_report_revoke_token($tid);
        flash_set('ok', 'Parent link revoked.');
        redirect('/assessment/report.php?student_id=' . $studentId . '#sharelink');
    }
}
$activeToken = child_report_active_token($studentId);

$fullName  = trim((string)$d['student']['first_name'] . ' ' . (string)$d['student']['last_name']);
$pageTitle = 'Report · ' . $fullName;
require __DIR__ . '/../includes/header.php';
?>
<style><?= child_report_styles() ?></style>

<div class="page-head no-print">
    <div>
        <h1>Detailed report</h1>
        <p class="muted"><a href="/assessment/progress.php?student_id=<?= $studentId ?>">← <?= e($fullName) ?></a></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="/assessment/assess.php?student_id=<?= $studentId ?>&month=<?= e(current_month_year()) ?>">Assess this month</a>
        <?php if ($editUrl = student_edit_url($user, $studentId)): ?>
            <a class="btn btn-ghost" href="<?= e($editUrl) ?>" title="Correct name, grade or other details">Edit details</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/assessment/report_pdf.php?student_id=<?= $studentId ?>">Download PDF</a>
        <button type="button" class="btn btn-ghost" onclick="window.print()">Print</button>
    </div>
</div>

<section class="card no-print" id="sharelink">
    <h3 style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span>Share with parents</span>
        <?php if ($activeToken): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Revoke this link? Parents will no longer be able to open the report.');">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="student_id" value="<?= $studentId ?>">
                <input type="hidden" name="op" value="report_link_revoke">
                <input type="hidden" name="token_id" value="<?= (int)$activeToken['id'] ?>">
                <button class="btn btn-ghost" type="submit">Revoke</button>
            </form>
        <?php else: ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="student_id" value="<?= $studentId ?>">
                <input type="hidden" name="op" value="report_link_new">
                <button class="btn btn-primary" type="submit">Generate parent link</button>
            </form>
        <?php endif; ?>
    </h3>
    <?php if ($activeToken): $url = child_report_url((string)$activeToken['token']); ?>
        <p class="muted small" style="margin:0 0 .4rem;">Read-only. Parents can open this without a school login — it shows this report and nothing else.</p>
        <div style="display:flex; gap:.4rem; align-items:center; flex-wrap:wrap;">
            <input id="cr-url" type="text" readonly value="<?= e($url) ?>"
                   style="flex:1 1 260px; padding:.4rem; border:1px solid var(--line); border-radius:5px; font-family:monospace; font-size:.85rem;">
            <button type="button" class="btn btn-ghost"
                    onclick="navigator.clipboard.writeText(document.getElementById('cr-url').value).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500);});">Copy</button>
            <a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= e($url) ?>">Open</a>
        </div>
        <p class="muted small" style="margin:.4rem 0 0;">
            Created <?= e(date('j M Y', strtotime((string)$activeToken['created_at']))) ?>
            <?php if (!empty($activeToken['last_accessed_at'])): ?>
                · Last opened <?= e(date('j M Y', strtotime((string)$activeToken['last_accessed_at']))) ?>
                · <?= (int)$activeToken['view_count'] ?> view<?= (int)$activeToken['view_count'] === 1 ? '' : 's' ?>
            <?php else: ?>
                · Not opened yet
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p class="muted small">No active link. Generating one creates a unique read-only URL you can send to <?= e((string)$d['student']['first_name']) ?>'s parents.</p>
    <?php endif; ?>
</section>

<?php child_report_render($d); ?>

<?php if ($isAdmin): ?>
<section class="card no-print" id="notes">
    <h3>Edit teacher notes</h3>
    <p class="muted small">
        Corrections to what appears under <em>Teacher's remarks</em> and against each area.
        Teachers write these when they assess a month; as an admin you can fix or add one here
        without re-doing that month's assessment. Clearing a note removes it.
    </p>

    <?php if (!empty($d['comment_rows'])): ?>
        <?php foreach ($d['comment_rows'] as $row): ?>
            <form method="post" action="/assessment/comment_edit.php" class="card" style="margin:.6rem 0; padding:.75rem .9rem;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="update">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="student_id" value="<?= $studentId ?>">
                <div class="muted small" style="margin-bottom:.3rem;">
                    <strong><?= e(month_year_label($row['month'])) ?></strong>
                    · <?= $row['category'] === '' ? 'Overall remark' : e($row['category']) ?>
                </div>
                <div class="field">
                    <label class="sr-only" for="c<?= (int)$row['id'] ?>">Note</label>
                    <textarea id="c<?= (int)$row['id'] ?>" name="comment" rows="2"><?= e($row['text']) ?></textarea>
                </div>
                <div class="actions">
                    <button class="btn" type="submit">Save</button>
                    <?php /* Distinct name — relying on a second "op" field would depend on
                             which duplicate the parser keeps. */ ?>
                    <button class="btn btn-ghost" type="submit" name="do_delete" value="1"
                            onclick="return confirm('Delete this note?');">Delete</button>
                </div>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($d['months'])): ?>
        <form method="post" action="/assessment/comment_edit.php" class="card" style="margin:.6rem 0; padding:.75rem .9rem;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="add">
            <input type="hidden" name="student_id" value="<?= $studentId ?>">
            <div class="row">
                <div class="field">
                    <label for="nm">Month</label>
                    <select id="nm" name="month">
                        <?php foreach (array_reverse($d['months']) as $m): ?>
                            <option value="<?= e($m) ?>"><?= e(month_year_label($m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="nc">Applies to</label>
                    <select id="nc" name="category">
                        <option value="">Overall remark</option>
                        <?php foreach ($d['categories'] as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="nb">New note</label>
                <textarea id="nb" name="comment" rows="2" placeholder="What you want the report to say…"></textarea>
            </div>
            <div class="actions"><button class="btn btn-primary" type="submit">Add note</button></div>
        </form>
    <?php elseif (empty($d['comment_rows'])): ?>
        <p class="muted">Notes can be added once this child has been assessed for a month.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
