<?php
/**
 * plans/view.php — read-only plan + admin review actions.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';

$user = plan_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$plan = plan_get($id);
if (!$plan || !plan_can_view($user, $plan)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'review') {
            plan_review($id, $user, (string)($_POST['action'] ?? ''), (string)($_POST['body'] ?? ''));
            $act = (string)($_POST['action'] ?? '');
            flash_set('ok', $act === 'approved' ? 'Plan approved.'
                : ($act === 'changes_requested' ? 'Changes requested.' : 'Comment added.'));
            redirect('/plans/view.php?id=' . $id);
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
        redirect('/plans/view.php?id=' . $id);
    }
}

$daysMeta = plan_week_days((string)$plan['week_key']);
$dayRows  = [];
foreach (plan_days($id) as $r) {
    $dayRows[(string)$r['day_date']] = $r;
}
$media   = plan_media_list($id);
$reviews = plan_reviews($id);
$isAdmin = ($user['role'] ?? '') === 'admin';
$canEdit = plan_can_edit($user, $plan);

$pageTitle = 'Weekly plan';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e((string)$plan['teacher_name']) ?></h1>
        <p class="muted">
            <?= e(plan_week_label((string)$plan['week_key'])) ?>
            <?php if ((string)$plan['class_grade'] !== ''): ?>
                · <?= e((string)$plan['class_grade']) ?>
            <?php endif; ?>
            · <span class="pill"><?= e(plan_status_label((string)$plan['status'])) ?></span>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/plans/index.php<?= $isAdmin ? '?week=' . e(urlencode((string)$plan['week_key'])) : '' ?>">All plans</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-primary" href="/plans/edit.php?week=<?= e(urlencode((string)$plan['week_key'])) ?><?= $isAdmin && (int)$plan['teacher_id'] !== (int)$user['id'] ? '&teacher=' . (int)$plan['teacher_id'] : '' ?>">Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($daysMeta as $meta):
    $date = $meta['date'];
    $row  = $dayRows[$date] ?? null;
    $act  = trim((string)($row['activities'] ?? ''));
    $mat  = trim((string)($row['materials'] ?? ''));
?>
    <div class="card">
        <h2 style="margin-top:0"><?= e($meta['label']) ?></h2>
        <?php if ($act === '' && $mat === ''): ?>
            <p class="muted">No plan entered.</p>
        <?php else: ?>
            <?php if ($act !== ''): ?>
                <h3 class="muted small" style="margin-bottom:.25rem">Activities</h3>
                <div><?= nl2br(e($act)) ?></div>
            <?php endif; ?>
            <?php if ($mat !== ''): ?>
                <h3 class="muted small" style="margin:1rem 0 .25rem">Materials</h3>
                <div><?= nl2br(e($mat)) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 style="margin-top:0">Weekly materials summary</h2>
    <?php if (trim((string)($plan['weekly_materials'] ?? '')) === ''): ?>
        <p class="muted">None listed.</p>
    <?php else: ?>
        <div><?= nl2br(e((string)$plan['weekly_materials'])) ?></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0">Note to Principal</h2>
    <?php if (!empty($plan['addressed_to_name'])): ?>
        <p class="muted small">Addressed to <?= e((string)$plan['addressed_to_name']) ?></p>
    <?php endif; ?>
    <?php if (trim((string)($plan['note_to_principal'] ?? '')) === ''): ?>
        <p class="muted">No note.</p>
    <?php else: ?>
        <div><?= nl2br(e((string)$plan['note_to_principal'])) ?></div>
    <?php endif; ?>

    <?php if ($media): ?>
        <div style="margin-top:1rem;display:grid;gap:1rem">
            <?php foreach ($media as $m): ?>
                <?php if ($m['kind'] === 'photo'): ?>
                    <a href="/plans/media.php?id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                        <img src="/plans/media.php?id=<?= (int)$m['id'] ?>" alt="<?= e((string)$m['original_filename']) ?>"
                             style="max-width:100%;max-height:360px;border-radius:8px">
                    </a>
                <?php else: ?>
                    <div>
                        <p class="muted small"><?= e((string)$m['original_filename']) ?></p>
                        <audio controls src="/plans/media.php?id=<?= (int)$m['id'] ?>" style="width:100%;max-width:420px"></audio>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($reviews): ?>
    <div class="card">
        <h2 style="margin-top:0">Review thread</h2>
        <ul style="list-style:none;padding:0;margin:0">
            <?php foreach ($reviews as $r): ?>
                <li style="padding:.5rem 0;border-bottom:1px solid var(--line,#eee)">
                    <strong><?= e(ucfirst(str_replace('_', ' ', (string)$r['action']))) ?></strong>
                    <span class="muted small">
                        · <?= e((string)($r['reviewer_name'] ?? 'Someone')) ?>
                        · <?= e(substr((string)$r['created_at'], 0, 16)) ?>
                    </span>
                    <?php if (trim((string)($r['body'] ?? '')) !== ''): ?>
                        <div><?= nl2br(e((string)$r['body'])) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($isAdmin && (string)$plan['status'] === 'submitted'): ?>
    <div class="card">
        <h2 style="margin-top:0">Review</h2>
        <form method="post" style="display:grid;gap:.75rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="review">
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>Comment
                <textarea name="body" rows="3" placeholder="Optional for approve; required when requesting changes"></textarea>
            </label>
            <div class="actionbar" style="gap:.5rem;flex-wrap:wrap">
                <button class="btn btn-primary" type="submit" name="action" value="approved">Approve</button>
                <button class="btn" type="submit" name="action" value="changes_requested"
                        onclick="var t=this.form.body; if(!t.value.trim()){alert('Please explain what needs changing.'); return false;}">
                    Request changes
                </button>
                <button class="btn btn-ghost" type="submit" name="action" value="comment"
                        onclick="var t=this.form.body; if(!t.value.trim()){alert('Comment cannot be empty.'); return false;}">
                    Comment only
                </button>
            </div>
        </form>
    </div>
<?php elseif ($isAdmin && in_array((string)$plan['status'], ['approved', 'changes_requested'], true)): ?>
    <div class="card">
        <h2 style="margin-top:0">Add a comment</h2>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="review">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="comment">
            <label>
                <textarea name="body" rows="3" required></textarea>
            </label>
            <div class="actionbar" style="margin-top:.75rem">
                <button class="btn" type="submit">Post comment</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if (!empty($plan['submitted_at']) || !empty($plan['reviewed_at'])): ?>
    <p class="muted small">
        <?php if (!empty($plan['submitted_at'])): ?>
            Submitted <?= e(substr((string)$plan['submitted_at'], 0, 16)) ?>.
        <?php endif; ?>
        <?php if (!empty($plan['reviewed_at'])): ?>
            Reviewed <?= e(substr((string)$plan['reviewed_at'], 0, 16)) ?>
            <?php if (!empty($plan['reviewed_by_name'])): ?>
                by <?= e((string)$plan['reviewed_by_name']) ?>
            <?php endif; ?>.
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
