<?php
/**
 * duties/index.php — my daily / weekly / monthly duty ticks.
 *
 * Open to anyone signed in (same idea as leave): the list is personal. Admin
 * configuration lives at /duties/admin.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/duties.php';

$user = require_login();
$uid  = (int)$user['id'];

if (!duty_tables_ready()) {
    $pageTitle = 'My duties';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Duty lists are not on this server yet. Ask an admin to run migrations.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

duty_materialize_for_user($uid);

$focus = (string)($_GET['tab'] ?? 'daily');
if (!in_array($focus, DUTY_FREQUENCIES, true)) $focus = 'daily';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    $tab = (string)($_POST['tab'] ?? $focus);
    if (!in_array($tab, DUTY_FREQUENCIES, true)) $tab = 'daily';
    $here = '/duties/index.php?tab=' . urlencode($tab);

    try {
        if ($op === 'mark') {
            duty_set_status(
                (int)($_POST['id'] ?? 0),
                $uid,
                (string)($_POST['status'] ?? ''),
                (string)($_POST['reason'] ?? '')
            );
            flash_set('success', 'Saved.');
        } elseif ($op === 'notes') {
            duty_save_period_note(
                $uid,
                $tab,
                duty_period_key($tab),
                (string)($_POST['comment'] ?? ''),
                (string)($_POST['extra_work'] ?? '')
            );
            flash_set('success', 'Notes saved.');
        } elseif ($op === 'add_self') {
            duty_add_self($uid, $tab, (string)($_POST['title'] ?? ''), null, (string)$user['name']);
            flash_set('success', 'Added — admin has been told.');
        } else {
            flash_set('error', 'Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('duties: ' . $e->getMessage());
        flash_set('error', 'Could not save just now.');
    }
    redirect($here);
}

$sections = [];
foreach (DUTY_FREQUENCIES as $freq) {
    $key = duty_period_key($freq);
    $items = duty_items_for_user($uid, $freq, $key);
    $pending = 0;
    foreach ($items as $it) {
        if ($it['status'] === 'pending') $pending++;
    }
    $sections[$freq] = [
        'key'     => $key,
        'label'   => duty_period_label($freq, $key),
        'items'   => $items,
        'pending' => $pending,
        'note'    => duty_period_note($uid, $freq, $key),
    ];
}

$pageTitle = 'My duties';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/duties.css?v=<?= e(asset_version()) ?>">

<div class="page-head">
    <div>
        <h1>My duties</h1>
        <p class="muted">Tick done or not done. If it was not done, say why.</p>
    </div>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
        <div class="actionbar">
            <a class="btn" href="/duties/admin.php">Configure lists</a>
        </div>
    <?php endif; ?>
</div>

<nav class="duty-tabs" aria-label="Period">
    <?php foreach (DUTY_FREQUENCIES as $freq):
        $n = $sections[$freq]['pending']; ?>
        <a class="duty-tab<?= $focus === $freq ? ' on' : '' ?>"
           href="/duties/index.php?tab=<?= e($freq) ?>">
            <?= e(duty_freq_label($freq)) ?>
            <?php if ($n > 0): ?><span class="pill pill-warn"><?= (int)$n ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
$sec = $sections[$focus];
$items = $sec['items'];
$note = $sec['note'];
?>
<p class="muted duty-when"><?= e($sec['label']) ?></p>

<?php if (!$items): ?>
    <div class="card">
        <p>Nothing on this list yet. If a task should be here, ask admin to assign it — or add your own below.</p>
    </div>
<?php endif; ?>

<?php foreach ($items as $it):
    $st = (string)$it['status'];
    $self = $it['source'] === 'self';
    ?>
    <div class="card duty-item duty-<?= e($st) ?>">
        <div class="duty-item-head">
            <strong><?= e((string)$it['title']) ?></strong>
            <?php if ($self): ?><span class="pill">My extra</span><?php endif; ?>
            <span class="pill <?= $st === 'done' ? 'pill-ok' : ($st === 'not_done' ? 'pill-warn' : '') ?>">
                <?= e(duty_status_label($st)) ?>
            </span>
        </div>
        <?php if (!empty($it['notes'])): ?>
            <p class="muted small"><?= e((string)$it['notes']) ?></p>
        <?php endif; ?>

        <div class="duty-actions">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="mark">
                <input type="hidden" name="tab" value="<?= e($focus) ?>">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="status" value="done">
                <button class="btn <?= $st === 'done' ? 'btn-primary' : '' ?>" type="submit">Done</button>
            </form>
            <?php if ($st === 'done' || $st === 'not_done'): ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="mark">
                    <input type="hidden" name="tab" value="<?= e($focus) ?>">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="status" value="pending">
                    <button class="btn btn-ghost" type="submit">Clear</button>
                </form>
            <?php endif; ?>
        </div>

        <form method="post" class="duty-notdone">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="mark">
            <input type="hidden" name="tab" value="<?= e($focus) ?>">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="status" value="not_done">
            <label>Not done — reason
                <textarea name="reason" rows="2" required placeholder="Why not?"><?= e((string)($it['reason'] ?? '')) ?></textarea>
            </label>
            <button class="btn <?= $st === 'not_done' ? 'btn-primary' : '' ?>" type="submit">Mark not done</button>
        </form>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2>Notes for this <?= e(strtolower(duty_freq_label($focus))) ?></h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="notes">
        <input type="hidden" name="tab" value="<?= e($focus) ?>">
        <label>General comments
            <textarea name="comment" rows="3" placeholder="Anything to flag…"><?= e($note['comment']) ?></textarea>
        </label>
        <label>Additional work taken
            <textarea name="extra_work" rows="3" placeholder="Extra things you did that were not on the list…"><?= e($note['extra_work']) ?></textarea>
        </label>
        <button class="btn btn-primary" type="submit">Save notes</button>
    </form>
</div>

<div class="card">
    <h2>Add my own task</h2>
    <p class="muted small">Admin is notified when you add one.</p>
    <form method="post" class="duty-add">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="add_self">
        <input type="hidden" name="tab" value="<?= e($focus) ?>">
        <input type="text" name="title" maxlength="200" required placeholder="Task name">
        <button class="btn" type="submit">Add</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
