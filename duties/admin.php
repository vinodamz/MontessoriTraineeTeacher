<?php
/**
 * duties/admin.php — configure duty templates and review ticks.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/duties.php';

$user = require_admin();

if (!duty_tables_ready()) {
    $pageTitle = 'Duty lists';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Run migrations — table staff_duty_templates is missing.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$view = (string)($_GET['view'] ?? 'list');
if (!in_array($view, ['list', 'edit', 'review'], true)) $view = 'list';

$editId = (int)($_GET['id'] ?? 0);
$people = duty_people();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'save') {
            $ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
            $saved = duty_template_upsert([
                'id'         => (int)($_POST['id'] ?? 0),
                'title'      => (string)($_POST['title'] ?? ''),
                'notes'      => (string)($_POST['notes'] ?? ''),
                'frequency'  => (string)($_POST['frequency'] ?? 'daily'),
                'audience'   => (string)($_POST['audience'] ?? 'all_teachers'),
                'user_ids'   => $ids,
                'is_active'  => !empty($_POST['is_active']),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
            ], (int)$user['id']);
            flash_set('success', 'Saved “' . $saved['title'] . '”.');
            redirect('/duties/admin.php');
        }
        if ($op === 'delete') {
            duty_template_delete((int)($_POST['id'] ?? 0));
            flash_set('success', 'Removed.');
            redirect('/duties/admin.php');
        }
        flash_set('error', 'Unknown action.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('duties admin: ' . $e->getMessage());
        flash_set('error', 'Could not save.');
    }
    redirect('/duties/admin.php' . ($editId ? ('?view=edit&id=' . $editId) : ''));
}

$freq = (string)($_GET['freq'] ?? 'daily');
if (!in_array($freq, DUTY_FREQUENCIES, true)) $freq = 'daily';
$period = (string)($_GET['period'] ?? duty_period_key($freq));
if ($period === '') $period = duty_period_key($freq);

$pageTitle = 'Duty lists';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/duties.css?v=<?= e(asset_version()) ?>">

<div class="page-head">
    <div>
        <h1>Duty lists</h1>
        <p class="muted">Daily, weekly and monthly ticks for teachers and staff. MCP can add or change these too.</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/duties/index.php">My ticks</a>
        <a class="btn<?= $view === 'list' ? ' btn-primary' : '' ?>" href="/duties/admin.php">Templates</a>
        <a class="btn<?= $view === 'review' ? ' btn-primary' : '' ?>" href="/duties/admin.php?view=review">Review</a>
        <a class="btn" href="/duties/admin.php?view=edit">New task</a>
    </div>
</div>

<?php if ($view === 'list'):
    $tpls = duty_templates();
    ?>
    <?php if (!$tpls): ?>
        <div class="card"><p>No tasks yet. <a href="/duties/admin.php?view=edit">Add one</a>, or use MCP <code>staff_duty_template_upsert</code>.</p></div>
    <?php else: ?>
        <div class="card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>When</th>
                        <th>Who</th>
                        <th>People</th>
                        <th>On</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tpls as $t): ?>
                    <tr>
                        <td>
                            <a href="/duties/admin.php?view=edit&amp;id=<?= (int)$t['id'] ?>"><?= e((string)$t['title']) ?></a>
                            <?php if (!empty($t['notes'])): ?><div class="muted small"><?= e((string)$t['notes']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e(duty_freq_label((string)$t['frequency'])) ?></td>
                        <td><?= e(duty_audience_label((string)$t['audience'])) ?></td>
                        <td><?= (int)$t['assignee_count'] ?></td>
                        <td><?= (int)$t['is_active'] ? 'Yes' : 'No' ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Remove this task from future lists? Past ticks stay.');">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-ghost" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($view === 'edit'):
    $t = $editId ? duty_template($editId) : null;
    if ($editId && !$t) {
        echo '<div class="card">No such task.</div>';
    } else {
        $t = $t ?: [
            'id' => 0, 'title' => '', 'notes' => '', 'frequency' => 'daily',
            'audience' => 'all_teachers', 'is_active' => 1, 'sort_order' => 0, 'user_ids' => [],
        ];
        $picked = array_map('intval', (array)$t['user_ids']);
        ?>
        <div class="card">
            <h2><?= $editId ? 'Edit task' : 'New task' ?></h2>
            <form method="post" class="duty-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="save">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <label>Title
                    <input type="text" name="title" required maxlength="200" value="<?= e((string)$t['title']) ?>">
                </label>
                <label>Help text (optional)
                    <input type="text" name="notes" maxlength="500" value="<?= e((string)($t['notes'] ?? '')) ?>">
                </label>
                <label>Frequency
                    <select name="frequency">
                        <?php foreach (DUTY_FREQUENCIES as $f): ?>
                            <option value="<?= e($f) ?>" <?= $t['frequency'] === $f ? 'selected' : '' ?>><?= e(duty_freq_label($f)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <fieldset class="duty-audience">
                    <legend>Assign to</legend>
                    <?php foreach (DUTY_AUDIENCES as $a): ?>
                        <label class="sv-choice">
                            <input type="radio" name="audience" value="<?= e($a) ?>"
                                   <?= $t['audience'] === $a ? 'checked' : '' ?>>
                            <span><?= e(duty_audience_label($a)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="duty-people">
                    <p class="muted small">Named people (used when “Named people” is selected)</p>
                    <?php foreach ($people as $p): ?>
                        <label class="duty-person">
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$p['id'] ?>"
                                <?= in_array((int)$p['id'], $picked, true) ? 'checked' : '' ?>>
                            <?= e((string)$p['name']) ?>
                            <span class="muted small"><?= e(role_label((string)$p['role'])) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <label>Sort order
                    <input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>">
                </label>
                <label class="duty-person">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($t['is_active']) ? 'checked' : '' ?>>
                    Active
                </label>
                <button class="btn btn-primary" type="submit">Save</button>
            </form>
        </div>
    <?php } ?>

<?php else:
    $rows = duty_review($freq, $period);
    $notes = [];
    try {
        $nst = db()->prepare("
            SELECT n.*, u.name FROM staff_duty_period_notes n
            JOIN users u ON u.id = n.user_id
            WHERE n.frequency = :f AND n.period_key = :k
        ");
        $nst->execute([':f' => $freq, ':k' => $period]);
        $notes = $nst->fetchAll();
    } catch (Throwable $e) {}
    ?>
    <form class="duty-filter" method="get">
        <input type="hidden" name="view" value="review">
        <label>List
            <select name="freq" onchange="this.form.submit()">
                <?php foreach (DUTY_FREQUENCIES as $f): ?>
                    <option value="<?= e($f) ?>" <?= $freq === $f ? 'selected' : '' ?>><?= e(duty_freq_label($f)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Period
            <input type="text" name="period" value="<?= e($period) ?>" placeholder="2026-08-13 or 2026-W33 or 2026-08">
        </label>
        <button class="btn" type="submit">Show</button>
    </form>
    <p class="muted"><?= e(duty_period_label($freq, $period)) ?> · <?= count($rows) ?> ticks</p>

    <div class="card">
        <?php if (!$rows): ?>
            <p>No ticks for this period yet.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Reason / notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e((string)$r['user_name']) ?><div class="muted small"><?= e(role_label((string)$r['user_role'])) ?></div></td>
                        <td>
                            <?= e((string)$r['title']) ?>
                            <?php if ($r['source'] === 'self'): ?><span class="pill">Self-added</span><?php endif; ?>
                        </td>
                        <td><?= e(duty_status_label((string)$r['status'])) ?></td>
                        <td class="small">
                            <?php if (!empty($r['reason'])): ?><div><strong>Why not:</strong> <?= e((string)$r['reason']) ?></div><?php endif; ?>
                            <?php if (!empty($r['comment'])): ?><div><?= e((string)$r['comment']) ?></div><?php endif; ?>
                            <?php if (!empty($r['extra_work'])): ?><div><strong>Extra:</strong> <?= e((string)$r['extra_work']) ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($notes): ?>
        <div class="card">
            <h2>Period comments</h2>
            <?php foreach ($notes as $n): ?>
                <p>
                    <strong><?= e((string)$n['name']) ?></strong>
                    <?php if (!empty($n['comment'])): ?><br><?= nl2br(e((string)$n['comment'])) ?><?php endif; ?>
                    <?php if (!empty($n['extra_work'])): ?><br><em>Extra work:</em> <?= nl2br(e((string)$n['extra_work'])) ?><?php endif; ?>
                </p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
