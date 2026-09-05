<?php
/**
 * duties/index.php — one checklist of everything on my plate now.
 *
 * Daily, weekly, monthly and dated tasks share a page (no tabs). Admin
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

$focus = (string)($_POST['tab'] ?? $_GET['tab'] ?? 'daily');
if (!in_array($focus, DUTY_FREQUENCIES, true)) $focus = 'daily';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    $tab = (string)($_POST['tab'] ?? $focus);
    if (!in_array($tab, DUTY_FREQUENCIES, true)) $tab = 'daily';
    $anchor = isset($_POST['id']) ? '#duty-' . (int)$_POST['id'] : '';
    $here = '/duties/index.php' . $anchor;

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
            $here = '/duties/index.php#duty-notes';
        } elseif ($op === 'add_self') {
            $id = duty_add_self($uid, $tab, (string)($_POST['title'] ?? ''), null, (string)$user['name']);
            flash_set('success', 'Added — admin has been told.');
            $here = '/duties/index.php#duty-' . $id;
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
$total = 0;
$pendingTotal = 0;
foreach (DUTY_FREQUENCIES as $freq) {
    $key = duty_period_key($freq);
    $items = duty_items_for_user($uid, $freq, $key);
    $pending = 0;
    foreach ($items as $it) {
        if ($it['status'] === 'pending') $pending++;
    }
    $total += count($items);
    $pendingTotal += $pending;
    $sections[$freq] = [
        'key'     => $key,
        'label'   => $freq === 'adhoc' ? duty_now_label($freq) : duty_period_label($freq, $key),
        'when'    => duty_now_label($freq),
        'items'   => $items,
        'pending' => $pending,
        'note'    => duty_period_note($uid, $freq, $key),
    ];
}

$dailyNote = $sections['daily']['note'];

$pageTitle = 'My duties';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/duties.css?v=<?= e((string)@filemtime(__DIR__ . '/../assets/css/duties.css')) ?>">

<div class="page-head">
    <div>
        <h1>My duties</h1>
        <p class="muted">
            <?php if ($total === 0): ?>
                Nothing assigned right now.
            <?php elseif ($pendingTotal === 0): ?>
                All caught up — <?= (int)$total ?> task<?= $total === 1 ? '' : 's' ?>.
            <?php else: ?>
                <?= (int)$pendingTotal ?> of <?= (int)$total ?> still to tick.
            <?php endif; ?>
        </p>
    </div>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
        <div class="actionbar">
            <a class="btn" href="/duties/admin.php">Set up tasks</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <p>Nothing on your list yet. If a task should be here, ask admin to assign it — or add your own below.</p>
    </div>
<?php endif; ?>

<?php foreach (DUTY_FREQUENCIES as $freq):
    $sec = $sections[$freq];
    if (!$sec['items']) continue;
    ?>
    <h2 class="duty-group">
        <?= e($sec['when']) ?>
        <span class="muted small"><?= e($sec['label']) ?></span>
        <?php if ($sec['pending'] > 0): ?>
            <span class="pill pill-warn"><?= (int)$sec['pending'] ?></span>
        <?php endif; ?>
    </h2>
    <?php foreach ($sec['items'] as $it):
        $st = (string)$it['status'];
        $self = $it['source'] === 'self';
        $iid = (int)$it['id'];
        ?>
        <div class="card duty-item duty-<?= e($st) ?>" id="duty-<?= $iid ?>">
            <div class="duty-item-head">
                <strong><?= e((string)$it['title']) ?></strong>
                <?php if ($self): ?><span class="pill">Mine</span><?php endif; ?>
            </div>
            <?php if (!empty($it['notes'])): ?>
                <p class="muted small"><?= e((string)$it['notes']) ?></p>
            <?php endif; ?>
            <?php
                $action = (string)($it['action_key'] ?? '');
                $actionHref = duty_action_href($action);
            ?>
            <?php if ($actionHref !== ''): ?>
                <p>
                    <a class="btn btn-primary" href="<?= e($actionHref) ?>">
                        <?= $action === 'materials_check' ? "Open today's sheet" : 'Open' ?>
                    </a>
                </p>
            <?php endif; ?>
            <?php
                $ws = (string)($it['window_start'] ?? '');
                $we = (string)($it['window_end'] ?? '');
                if ($ws !== '' || $we !== ''):
            ?>
                <p class="muted small"><?= e(trim($ws . ($we && $we !== $ws ? ' → ' . $we : ''))) ?></p>
            <?php endif; ?>

            <div class="duty-actions">
                <?php if ($st !== 'done'): ?>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="mark">
                        <input type="hidden" name="id" value="<?= $iid ?>">
                        <input type="hidden" name="status" value="done">
                        <button class="btn btn-primary" type="submit">Done</button>
                    </form>
                <?php else: ?>
                    <span class="pill pill-ok">Done</span>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="mark">
                        <input type="hidden" name="id" value="<?= $iid ?>">
                        <input type="hidden" name="status" value="pending">
                        <button class="btn btn-ghost" type="submit">Undo</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($st !== 'done'): ?>
                <details class="duty-couldnt" <?= $st === 'not_done' ? 'open' : '' ?>>
                    <summary><?= $st === 'not_done' ? 'Couldn’t — change reason' : 'Couldn’t do it' ?></summary>
                    <form method="post" class="duty-notdone">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="mark">
                        <input type="hidden" name="id" value="<?= $iid ?>">
                        <input type="hidden" name="status" value="not_done">
                        <label>Why not?
                            <textarea name="reason" rows="2" required placeholder="Short reason"><?= e((string)($it['reason'] ?? '')) ?></textarea>
                        </label>
                        <button class="btn" type="submit">Save</button>
                    </form>
                </details>
            <?php elseif (!empty($it['reason'])): ?>
                <p class="muted small">Was: <?= e((string)$it['reason']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<div class="card" id="duty-notes">
    <h2>Notes</h2>
    <p class="muted small">Optional — anything to flag for today, or extra work you took on.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="notes">
        <input type="hidden" name="tab" value="daily">
        <label>Comments
            <textarea name="comment" rows="2" placeholder="Anything to flag…"><?= e($dailyNote['comment']) ?></textarea>
        </label>
        <label>Extra work
            <textarea name="extra_work" rows="2" placeholder="Things you did that were not on the list…"><?= e($dailyNote['extra_work']) ?></textarea>
        </label>
        <button class="btn" type="submit">Save notes</button>
    </form>
</div>

<details class="card duty-extras">
    <summary>Add my own task</summary>
    <p class="muted small">Shows on today’s list. Admin is notified.</p>
    <form method="post" class="duty-add">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="add_self">
        <input type="hidden" name="tab" value="daily">
        <input type="text" name="title" maxlength="200" required placeholder="Task name">
        <button class="btn" type="submit">Add</button>
    </form>
</details>

<?php require __DIR__ . '/../includes/footer.php'; ?>
