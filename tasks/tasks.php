<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

$user = require_module('tasks');

// Materialise today's recurring instances on every visit.
materialize_recurrences();

// =========================================================================
// AJAX endpoints — POSTs that return JSON. Browser navigates use HTML below.
// =========================================================================
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    // ---------- Kanban: move card to (column, position) ----------
    if ($op === 'move' && $isAjax) {
        $id      = (int)($_POST['id'] ?? 0);
        $colId   = (int)($_POST['column_id'] ?? 0);
        $pos     = max(0, (int)($_POST['position'] ?? 0));
        if ($id <= 0 || $colId <= 0) json_out(['ok' => false, 'error' => 'bad input'], 400);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // shift cards in destination column at >= $pos one slot down
            $shift = $pdo->prepare("
                UPDATE tasks SET board_position = board_position + 1
                WHERE column_id = :c AND board_position >= :p AND id <> :id
            ");
            $shift->execute([':c' => $colId, ':p' => $pos, ':id' => $id]);

            // place the moved card
            $set = $pdo->prepare("
                UPDATE tasks SET column_id = :c, board_position = :p
                WHERE id = :id AND deleted_at IS NULL
            ");
            $set->execute([':c' => $colId, ':p' => $pos, ':id' => $id]);

            // keep the legacy status column in step with the Done column —
            // the dashboard's completed/missed rollups key off status='done'.
            $sync = $pdo->prepare("
                UPDATE tasks t JOIN task_columns c ON c.id = t.column_id
                SET t.status = IF(c.is_done = 1, 'done', 'todo')
                WHERE t.id = :id
            ");
            $sync->execute([':id' => $id]);

            // normalise positions (0..n-1) in the destination column
            $rows = $pdo->prepare("SELECT id FROM tasks WHERE column_id = :c ORDER BY board_position, id");
            $rows->execute([':c' => $colId]);
            $upd = $pdo->prepare("UPDATE tasks SET board_position = :p WHERE id = :id");
            $i = 0;
            foreach ($rows as $r) {
                $upd->execute([':p' => $i++, ':id' => (int)$r['id']]);
            }

            $pdo->commit();
            json_out(['ok' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'db: ' . $e->getMessage()], 500);
        }
    }

    // ---------- Form: create / update / delete ----------
    if ($op === 'create' || $op === 'update') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $colId       = isset($_POST['column_id']) ? (int)$_POST['column_id'] : 0;
        $priority    = $_POST['priority'] ?? 'normal';
        $due         = ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null;
        $assignee    = isset($_POST['assigned_to_user_id']) && $_POST['assigned_to_user_id'] !== ''
                       ? (int)$_POST['assigned_to_user_id'] : null;

        if ($title === '') {
            flash_set('error', 'Title is required.');
            redirect('tasks.php');
        }
        if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';

        // Default column if none picked (first column by position)
        if ($colId <= 0 && kanban_available()) {
            $first = task_columns()[0] ?? null;
            $colId = $first ? (int)$first['id'] : 0;
        }

        if ($op === 'create') {
            // ----- Recurring? Then we insert a recurrence template instead. -----
            $repeat = !empty($_POST['repeat']);
            if ($repeat && recurrence_available()) {
                $freq = $_POST['rec_frequency'] ?? 'daily';
                if (!in_array($freq, ['daily', 'weekly', 'monthly'], true)) $freq = 'daily';

                $startIso = $_POST['rec_start']   ?: date('Y-m-d');
                $endIso   = $_POST['rec_end']     ?: null;
                $dom      = $freq === 'monthly' ? max(1, min(28, (int)($_POST['rec_dom'] ?? (int)date('j')))) : null;
                $offset   = max(0, min(365, (int)($_POST['rec_offset'] ?? 0)));

                // build mask from checkboxes; fall back to preset
                $mask = 0;
                if ($freq === 'weekly') {
                    foreach (($_POST['rec_days'] ?? []) as $d) {
                        $d = (int)$d;
                        if ($d >= 0 && $d <= 6) $mask |= (1 << $d);
                    }
                    if ($mask === 0) $mask = DAYS_WEEKDAYS;
                } else {
                    $mask = DAYS_ALL;
                }

                $stmt = db()->prepare("
                    INSERT INTO task_recurrences
                        (title, description, priority, column_id, assigned_to_user_id,
                         frequency, days_mask, day_of_month, due_offset_days,
                         start_date, end_date, created_by_user_id)
                    VALUES (:t, :d, :p, :col, :a, :f, :m, :dom, :off, :s, :e, :c)
                ");
                $stmt->execute([
                    ':t' => $title, ':d' => $description, ':p' => $priority,
                    ':col' => $colId ?: null, ':a' => $assignee,
                    ':f' => $freq, ':m' => $mask, ':dom' => $dom, ':off' => $offset,
                    ':s' => $startIso, ':e' => $endIso, ':c' => $user['id'],
                ]);
                materialize_recurrences();  // create today's instance if it qualifies
                flash_set('ok', 'Recurring task created. Today\'s instance will show on the board if it matches.');
                redirect('tasks.php?' . http_build_query(array_diff_key($_GET, ['edit' => 1])));
            }

            // ----- Plain one-off task -----
            $maxPos = 0;
            if ($colId > 0) {
                $q = db()->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE column_id = :c");
                $q->execute([':c' => $colId]);
                $maxPos = (int) $q->fetchColumn();
            }
            $stmt = db()->prepare("
                INSERT INTO tasks (title, description, status, column_id, board_position, priority,
                                   due_date, assigned_to_user_id, created_by_user_id)
                VALUES (:t, :d, :s, :col, :pos, :p, :due, :a, :c)
            ");
            $stmt->execute([
                ':t' => $title, ':d' => $description,
                ':s' => 'todo', // legacy column, kept for compat
                ':col' => $colId ?: null, ':pos' => $maxPos,
                ':p' => $priority, ':due' => $due, ':a' => $assignee, ':c' => $user['id'],
            ]);
            $newTaskId = (int)db()->lastInsertId();
            flash_set('ok', 'Task created.');

            // Notify the assignee (but not if they assigned the task to themselves).
            if ($assignee && $assignee !== (int)$user['id']) {
                require_once __DIR__ . '/../includes/notify.php';
                $bodyLines = [];
                if ($description !== '') $bodyLines[] = $description;
                if ($due)                $bodyLines[] = 'Due ' . $due;
                $bodyLines[] = 'Assigned by ' . $user['name'];
                notify(
                    $assignee, 'tasks', 'task_assigned',
                    'New task: ' . $title,
                    implode("\n", $bodyLines),
                    '/tasks/tasks.php?edit=' . $newTaskId
                );
            }
        } else {
            // Detect a re-assignment so we can notify the new assignee.
            $prevAssignee = null;
            try {
                $q = db()->prepare("SELECT assigned_to_user_id FROM tasks WHERE id = :id");
                $q->execute([':id' => $id]);
                $prevAssignee = $q->fetchColumn();
                $prevAssignee = $prevAssignee === false || $prevAssignee === null ? null : (int)$prevAssignee;
            } catch (Throwable $e) { /* swallow */ }

            $stmt = db()->prepare("
                UPDATE tasks SET title=:t, description=:d, column_id=:col, priority=:p,
                                 due_date=:due, assigned_to_user_id=:a
                WHERE id=:id AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':t' => $title, ':d' => $description, ':col' => $colId ?: null,
                ':p' => $priority, ':due' => $due, ':a' => $assignee, ':id' => $id,
            ]);
            // Column may have changed — keep status in step (see op=move).
            $sync = db()->prepare("
                UPDATE tasks t JOIN task_columns c ON c.id = t.column_id
                SET t.status = IF(c.is_done = 1, 'done', 'todo')
                WHERE t.id = :id
            ");
            $sync->execute([':id' => $id]);
            flash_set('ok', 'Task updated.');

            if ($assignee && $assignee !== $prevAssignee && $assignee !== (int)$user['id']) {
                require_once __DIR__ . '/../includes/notify.php';
                notify(
                    $assignee, 'tasks', 'task_assigned',
                    'Task assigned to you: ' . $title,
                    ($due ? "Due $due. " : '') . 'Reassigned by ' . $user['name'],
                    '/tasks/tasks.php?edit=' . $id
                );
            }
        }
        redirect('tasks.php?' . http_build_query(array_diff_key($_GET, ['edit' => 1])));
    }

    if ($op === 'delete') {
        // Two-step delete (goal: "explicit confirm step, not a one-click delete"):
        // the form posts confirm=yes and an op=soft-delete intent. Without the
        // confirm, we redirect to a confirmation page instead of acting.
        $id      = (int)($_POST['id'] ?? 0);
        $confirm = (string)($_POST['confirm'] ?? '');
        if ($confirm !== 'yes') {
            redirect('tasks.php?confirm_delete=' . $id);
        }
        try {
            task_soft_delete($id, (int)$user['id']);
            flash_set('ok', 'Task archived — recover it from the Trash if needed.');
        } catch (Throwable $e) {
            flash_set('error', 'Delete failed: ' . $e->getMessage());
        }
        redirect('tasks.php');
    }

    if ($op === 'restore') {
        $deletionId = (int)($_POST['deletion_id'] ?? 0);
        try {
            $tid = task_restore($deletionId, (int)$user['id']);
            flash_set('ok', 'Task #' . $tid . ' restored.');
        } catch (Throwable $e) {
            flash_set('error', 'Restore failed: ' . $e->getMessage());
        }
        redirect('trash.php');
    }

    // Post-op destination for subtask/attachment ops: the task's edit
    // form (?edit=<task id> — the old URL shape put the task id in a
    // separate param the form loader never read, so it loaded task #1
    // and invited edits landing on the wrong task), or back to My
    // Subtasks when the toggle came from there.
    $opReturn = function (int $tid): string {
        return ($_POST['return_to'] ?? '') === 'my' ? 'my.php' : 'tasks.php?edit=' . $tid;
    };

    if ($op === 'subtask_create') {
        $tid   = (int)($_POST['task_id'] ?? 0);
        $title = (string)($_POST['title'] ?? '');
        $aid   = (int)($_POST['assignee_user_id'] ?? 0) ?: null;
        try {
            task_subtask_create($tid, $title, $aid);
            if ($isAjax) { json_out(['ok' => true]); }
            flash_set('ok', 'Subtask added.');
        } catch (Throwable $e) {
            if ($isAjax) { json_out(['ok' => false, 'error' => $e->getMessage()], 400); }
            flash_set('error', $e->getMessage());
        }
        redirect($opReturn($tid));
    }

    if ($op === 'subtask_update') {
        $sid   = (int)($_POST['id'] ?? 0);
        $tid   = (int)($_POST['task_id'] ?? 0);
        $title = (string)($_POST['title'] ?? '');
        $aid   = (int)($_POST['assignee_user_id'] ?? 0) ?: null;
        try { task_subtask_update($sid, $tid, $title, $aid); } catch (Throwable $e) { flash_set('error', $e->getMessage()); }
        if ($isAjax) json_out(['ok' => true]);
        redirect($opReturn($tid));
    }

    if ($op === 'subtask_toggle') {
        $sid  = (int)($_POST['id'] ?? 0);
        $tid  = (int)($_POST['task_id'] ?? 0);
        $done = !empty($_POST['done']);
        task_subtask_toggle($sid, $tid, $done);
        if ($isAjax) {
            $p = task_subtask_progress($tid);
            json_out(['ok' => true, 'done' => $p['done'], 'total' => $p['total']]);
        }
        redirect($opReturn($tid));
    }

    if ($op === 'subtask_delete') {
        $sid = (int)($_POST['id'] ?? 0);
        $tid = (int)($_POST['task_id'] ?? 0);
        task_subtask_delete($sid, $tid);
        if ($isAjax) json_out(['ok' => true]);
        redirect($opReturn($tid));
    }

    if ($op === 'subtask_reorder' && $isAjax) {
        $tid = (int)($_POST['task_id'] ?? 0);
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        task_subtask_reorder($tid, $ids);
        json_out(['ok' => true]);
    }

    if ($op === 'attachment_upload') {
        $tid = (int)($_POST['task_id'] ?? 0);
        try {
            task_attachment_store($_FILES['file'] ?? [], $tid, (int)$user['id']);
            flash_set('ok', 'Attachment uploaded.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect($opReturn($tid));
    }

    if ($op === 'attachment_delete') {
        $aid = (int)($_POST['id'] ?? 0);
        $tid = (int)($_POST['task_id'] ?? 0);
        task_attachment_delete($aid, $tid);
        flash_set('ok', 'Attachment removed.');
        redirect($opReturn($tid));
    }

    if ($op === 'quick_status') {
        // Used by the list view's per-row column picker
        $id    = (int)($_POST['id'] ?? 0);
        $colId = (int)($_POST['column_id'] ?? 0);
        if ($id > 0 && $colId > 0) {
            $maxPos = 0;
            $q = db()->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE column_id = :c");
            $q->execute([':c' => $colId]);
            $maxPos = (int) $q->fetchColumn();
            $stmt = db()->prepare("UPDATE tasks SET column_id = :c, board_position = :p WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':c' => $colId, ':p' => $maxPos, ':id' => $id]);
            $sync = db()->prepare("
                UPDATE tasks t JOIN task_columns c ON c.id = t.column_id
                SET t.status = IF(c.is_done = 1, 'done', 'todo')
                WHERE t.id = :id
            ");
            $sync->execute([':id' => $id]);
        }
        redirect('tasks.php' . (!empty($_POST['return']) ? '?' . $_POST['return'] : ''));
    }
}

// =========================================================================
// View setup
// =========================================================================
$users = db()->query("
    SELECT id, name FROM users
    WHERE active = 1
      AND (role = 'admin' OR FIND_IN_SET('tasks', modules) > 0)
    ORDER BY name
")->fetchAll();
$cols  = task_columns();
$hasKanban = $cols !== [];

$view = $_GET['view'] ?? ($hasKanban ? 'board' : 'list');
if (!in_array($view, ['board', 'list'], true)) $view = 'list';
if ($view === 'board' && !$hasKanban) $view = 'list';

$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare("SELECT * FROM tasks WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// Filters
$filterAssn = $_GET['assignee'] ?? '';
$filterCol  = $_GET['col']      ?? '';
$search     = trim($_GET['q'] ?? '');

// Always hide soft-deleted tasks (migration 032). Trash page is separate.
$where = ['t.deleted_at IS NULL'];
$params = [];
if ($filterAssn === 'me')                       { $where[] = 't.assigned_to_user_id = :me'; $params[':me'] = $user['id']; }
elseif ($filterAssn !== '' && ctype_digit($filterAssn)) { $where[] = 't.assigned_to_user_id = :a'; $params[':a'] = (int)$filterAssn; }
if ($filterCol !== '' && ctype_digit($filterCol)) { $where[] = 't.column_id = :col'; $params[':col'] = (int)$filterCol; }

// Dashboard bucket filter — clicking a tile on /tasks/dashboard.php lands
// here with ?bucket=… so the user immediately sees the rows behind the
// number they tapped.
$bucket = (string)($_GET['bucket'] ?? '');
if ($bucket === 'assigned')   { $where[] = "t.status <> 'done' AND t.assigned_to_user_id IS NOT NULL"; }
elseif ($bucket === 'completed') { $where[] = "t.status = 'done'"; }
elseif ($bucket === 'pending')   { $where[] = "t.status <> 'done' AND (t.due_date IS NULL OR t.due_date >= CURDATE())"; }
elseif ($bucket === 'missed')    { $where[] = "t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()"; }

// The dashboard's per-person links use assigned_to_user_id=N (more explicit
// than the existing assignee=N alias). Honour both.
$assnExplicit = (string)($_GET['assigned_to_user_id'] ?? '');
if ($assnExplicit !== '' && ctype_digit($assnExplicit)) {
    $where[] = 't.assigned_to_user_id = :auid';
    $params[':auid'] = (int)$assnExplicit;
}
if ($search !== '') { $where[] = '(t.title LIKE :q OR t.description LIKE :q)'; $params[':q'] = '%'.$search.'%'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT t.*, u.name AS assignee_name, c.name AS creator_name,
           col.name AS column_name, col.color AS column_color, col.is_done AS column_done,
           COALESCE(t.due_date, t.instance_date) AS card_date
    FROM tasks t
    LEFT JOIN users u          ON u.id   = t.assigned_to_user_id
    LEFT JOIN users c          ON c.id   = t.created_by_user_id
    LEFT JOIN task_columns col ON col.id = t.column_id
    $whereSql
    ORDER BY col.position ASC,
             -- overdue first, then today, then chronological, then no-date last
             CASE
                WHEN COALESCE(t.due_date, t.instance_date) IS NULL THEN 3
                WHEN COALESCE(t.due_date, t.instance_date) <  CURDATE() THEN 0
                WHEN COALESCE(t.due_date, t.instance_date) =  CURDATE() THEN 1
                ELSE 2
             END,
             COALESCE(t.due_date, t.instance_date) ASC,
             t.board_position ASC,
             t.id DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Group tasks per column for the board view.
$byColumn = [];
foreach ($cols as $col) $byColumn[$col['id']] = [];
foreach ($tasks as $t) {
    if (!empty($t['column_id']) && isset($byColumn[$t['column_id']])) {
        $byColumn[$t['column_id']][] = $t;
    }
}

$pageTitle = 'Tasks — LG Task Manager';
$wideLayout = ($view === 'board');

// Subtask progress per visible task — one query, used by the row pills below.
$subtaskProgress = task_subtask_progress_for(array_map(fn($t) => (int)$t['id'], $tasks));

include __DIR__ . '/../includes/header.php';
?>

<div class="actionbar">
    <h1>Tasks</h1>
    <?php if ($hasKanban): ?>
        <div class="view-toggle">
            <?php $qs = $_GET; unset($qs['view']); $qs['view'] = 'board'; ?>
            <a class="toggle <?= $view === 'board' ? 'active' : '' ?>" href="?<?= e(http_build_query($qs)) ?>">Board</a>
            <?php $qs['view'] = 'list'; ?>
            <a class="toggle <?= $view === 'list' ? 'active' : '' ?>" href="?<?= e(http_build_query($qs)) ?>">List</a>
        </div>
    <?php endif; ?>
</div>

<form class="filters" method="get">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <?php if ($bucket !== ''): ?>
        <input type="hidden" name="bucket" value="<?= e($bucket) ?>">
        <?php if (!empty($_GET['assigned_to_user_id'])): ?>
            <input type="hidden" name="assigned_to_user_id" value="<?= (int)$_GET['assigned_to_user_id'] ?>">
        <?php endif; ?>
        <a class="pill" href="tasks.php?view=<?= e($view) ?>" title="Remove the dashboard filter">bucket: <?= e($bucket) ?> ×</a>
    <?php endif; ?>
    <input type="search" name="q" placeholder="Search tasks…" value="<?= e($search) ?>">
    <?php if ($hasKanban): ?>
        <select name="col">
            <option value="">Any column</option>
            <?php foreach ($cols as $col): ?>
                <option value="<?= (int)$col['id'] ?>" <?= (string)$col['id'] === (string)$filterCol ? 'selected' : '' ?>><?= e($col['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <select name="assignee">
        <option value="">Anyone</option>
        <option value="me" <?= $filterAssn === 'me' ? 'selected' : '' ?>>Me</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === (string)$filterAssn ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
    <a class="btn btn-ghost" href="tasks.php?view=<?= e($view) ?>">Reset</a>
</form>

<details class="card card-form" <?= $editing ? 'open' : '' ?>>
    <summary><?= $editing ? 'Edit task' : 'New task' ?></summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

        <div class="field">
            <label>Title</label>
            <input name="title" required maxlength="200" value="<?= e($editing['title'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Description</label>
            <textarea name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
        </div>
        <div class="row">
            <?php if ($hasKanban): ?>
            <div class="field">
                <label>Column</label>
                <select name="column_id">
                    <?php foreach ($cols as $col): ?>
                        <option value="<?= (int)$col['id'] ?>"
                            <?= isset($editing['column_id']) && (int)$editing['column_id'] === (int)$col['id'] ? 'selected' : '' ?>>
                            <?= e($col['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field">
                <label>Priority</label>
                <select name="priority">
                    <?php foreach (['low','normal','high'] as $p): ?>
                        <option value="<?= $p ?>" <?= ($editing['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Due date</label>
                <input type="date" name="due_date" value="<?= e($editing['due_date'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Assigned to</label>
                <select name="assigned_to_user_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"
                            <?= isset($editing['assigned_to_user_id']) && (int)$editing['assigned_to_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if (!$editing && recurrence_available()): ?>
            <fieldset class="repeat-block">
                <label class="checkbox repeat-toggle">
                    <input type="checkbox" name="repeat" value="1" id="rep-toggle">
                    <span>Repeat this task</span>
                </label>

                <div class="repeat-fields" id="rep-fields" hidden>
                    <div class="row">
                        <div class="field">
                            <label>Frequency</label>
                            <select name="rec_frequency" id="rep-freq">
                                <option value="daily">Daily</option>
                                <option value="weekly" selected>Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="field" id="rep-weekly">
                            <label>Days of the week</label>
                            <div class="dow-presets">
                                <button type="button" data-preset="62">Mon–Fri</button>
                                <button type="button" data-preset="65">Weekends</button>
                                <button type="button" data-preset="127">Every day</button>
                            </div>
                            <div class="dow-chips">
                                <?php $names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                      $default = DAYS_WEEKDAYS;
                                      foreach ($names as $i => $n): ?>
                                    <label class="dow-chip">
                                        <input type="checkbox" name="rec_days[]" value="<?= $i ?>"
                                               <?= ($default & (1 << $i)) ? 'checked' : '' ?>>
                                        <span><?= $n ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="field" id="rep-monthly" hidden>
                            <label>Day of the month</label>
                            <input type="number" name="rec_dom" min="1" max="28" value="<?= e((string)(int)date('j')) ?>">
                        </div>
                        <div class="field">
                            <label>Start</label>
                            <input type="date" name="rec_start" value="<?= e(date('Y-m-d')) ?>">
                        </div>
                        <div class="field">
                            <label>End <em>(optional)</em></label>
                            <input type="date" name="rec_end" value="">
                        </div>
                        <div class="field">
                            <label>Due offset <em>(days after each creation)</em></label>
                            <input type="number" name="rec_offset" min="0" max="365" value="0"
                                   title="0 = same day. 3 = due 3 days after the recurring instance is created.">
                        </div>
                    </div>
                </div>
            </fieldset>
        <?php endif; ?>

        <div class="actions">
            <button class="btn btn-primary"><?= $editing ? 'Save' : 'Create task' ?></button>
            <?php if ($editing): ?><a class="btn btn-ghost" href="tasks.php?view=<?= e($view) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php if ($editing): ?>
    <form method="post" class="inline" style="margin-top:.5rem">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <button class="link-btn danger">Archive this task…</button>
    </form>
    <?php endif; ?>

    <?php if ($editing):
        $subRows  = task_subtasks_for((int)$editing['id']);
        $attRows  = task_attachments_for((int)$editing['id']);
        $progress = task_subtask_progress((int)$editing['id']); ?>

        <h3 style="margin: 1.4rem 0 .6rem;">
            Checklist
            <?php if ($progress['total']): ?>
                <span class="pill"><?= $progress['done'] ?>/<?= $progress['total'] ?> done</span>
            <?php endif; ?>
        </h3>
        <ul class="subtask-list" id="subtask-list" data-task-id="<?= (int)$editing['id'] ?>"
            style="list-style:none; padding:0; margin:0 0 .5rem;">
            <?php foreach ($subRows as $sub): ?>
                <li data-id="<?= (int)$sub['id'] ?>" style="display:flex; gap:.5rem; align-items:center; padding:.35rem 0; border-bottom:1px solid var(--line-soft);">
                    <span class="drag-handle" title="Drag to reorder" style="cursor:grab; color:var(--muted);">⋮⋮</span>
                    <form method="post" class="subtask-toggle-form inline" style="margin:0;">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="subtask_toggle">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$editing['id'] ?>">
                        <input type="hidden" name="done" value="<?= (int)$sub['done'] === 1 ? '0' : '1' ?>">
                        <button type="submit" style="background:transparent; border:0; cursor:pointer; font-size:1.1rem; padding:0;">
                            <?= (int)$sub['done'] === 1 ? '☑' : '☐' ?>
                        </button>
                    </form>
                    <div style="flex:1; <?= (int)$sub['done'] === 1 ? 'text-decoration:line-through; color:var(--muted);' : '' ?>">
                        <?= e($sub['title']) ?>
                        <?php if (!empty($sub['assignee_name'])): ?>
                            <span class="pill"><?= e($sub['assignee_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="inline" style="margin:0;" onsubmit="return confirm('Remove this subtask?')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="subtask_delete">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$editing['id'] ?>">
                        <button class="link-btn" type="submit">×</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post" class="row" style="margin:.4rem 0 .8rem; gap:.4rem; align-items:flex-end;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="subtask_create">
            <input type="hidden" name="task_id" value="<?= (int)$editing['id'] ?>">
            <div class="field" style="flex:2 1 220px;">
                <label>Add subtask</label>
                <input name="title" required maxlength="255" placeholder="What needs doing?">
            </div>
            <div class="field" style="flex:1 1 160px;">
                <label>Assignee (optional)</label>
                <select name="assignee_user_id">
                    <option value="">—</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit">Add</button>
        </form>

        <h3 style="margin: 1.2rem 0 .6rem;">Attachments</h3>
        <?php if ($attRows): ?>
            <ul style="list-style:none; padding:0; margin:0 0 .5rem;">
                <?php foreach ($attRows as $att): ?>
                    <li style="display:flex; gap:.6rem; align-items:center; padding:.35rem 0; border-bottom:1px solid var(--line-soft);">
                        <div style="flex:1;">
                            <a href="/tasks/attachment.php?id=<?= (int)$att['id'] ?>" target="_blank" rel="noopener"><?= e($att['original_filename']) ?></a>
                            <div class="muted small">
                                <?= e(format_bytes((int)$att['size_bytes'])) ?>
                                · <?= e(date('j M Y', strtotime((string)$att['uploaded_at']))) ?>
                                <?php if (!empty($att['uploader_name'])): ?> · <?= e($att['uploader_name']) ?><?php endif; ?>
                            </div>
                        </div>
                        <a class="btn btn-ghost" href="/tasks/attachment.php?id=<?= (int)$att['id'] ?>&download=1">Download</a>
                        <form method="post" class="inline" style="margin:0;" onsubmit="return confirm('Remove this attachment?')">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="attachment_delete">
                            <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                            <input type="hidden" name="task_id" value="<?= (int)$editing['id'] ?>">
                            <button class="link-btn" type="submit">×</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row" style="margin:.4rem 0; gap:.4rem; align-items:flex-end;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="attachment_upload">
            <input type="hidden" name="task_id" value="<?= (int)$editing['id'] ?>">
            <div class="field" style="flex:2 1 320px;">
                <label>Upload a file <span class="muted small">(up to 10 MB · PDF / image / Office / TXT / ZIP)</span></label>
                <input type="file" name="file" required>
            </div>
            <button class="btn" type="submit">Upload</button>
        </form>

        <script>
        // Drag-reorder the checklist using Sortable.js (already loaded for the
        // kanban board on tasks/index.php; load it here so the Edit page works
        // standalone). Fire a subtask_reorder POST when the order changes.
        (function() {
            const list = document.getElementById('subtask-list');
            if (!list) return;
            function init() {
                if (typeof Sortable === 'undefined') return;
                const taskId = list.dataset.taskId;
                Sortable.create(list, {
                    handle: '.drag-handle',
                    animation: 120,
                    onEnd: () => {
                        const ids = [...list.querySelectorAll('li')].map(li => li.dataset.id);
                        const fd = new FormData();
                        fd.append('_csrf', '<?= e(csrf_token()) ?>');
                        fd.append('op', 'subtask_reorder');
                        fd.append('task_id', taskId);
                        ids.forEach(id => fd.append('ids[]', id));
                        fetch('/tasks/tasks.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: fd,
                        });
                    },
                });
            }
            if (typeof Sortable !== 'undefined') {
                init();
            } else {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
                s.onload = init;
                document.head.appendChild(s);
            }
        })();
        </script>
    <?php endif; ?>
</details>

<?php
// ---------- Confirm-delete banner (goal: explicit confirm step) ---------------
// /tasks/tasks.php?confirm_delete=N renders this banner — the actual delete
// action only runs when the form posts op=delete + confirm=yes.
$confirmDeleteId = isset($_GET['confirm_delete']) ? (int)$_GET['confirm_delete'] : 0;
if ($confirmDeleteId > 0):
    $dStmt = db()->prepare("SELECT id, title FROM tasks WHERE id = :id AND deleted_at IS NULL");
    $dStmt->execute([':id' => $confirmDeleteId]);
    $dRow = $dStmt->fetch();
    if ($dRow): ?>
<div class="card" style="border-left: 4px solid #f5b342; background:#fff8e7;">
    <h2 style="margin-top:0;">Archive “<?= e($dRow['title']) ?>”?</h2>
    <p class="muted">The task moves to the Trash. You can restore it from there at any time — no data is lost. The delete is recorded in the audit log with your name + today's date.</p>
    <form method="post" style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$dRow['id'] ?>">
        <input type="hidden" name="confirm" value="yes">
        <button class="btn btn-primary" type="submit">Yes — archive it</button>
        <a class="btn btn-ghost" href="/tasks/tasks.php">Cancel</a>
    </form>
</div>
    <?php endif;
endif;
?>
<?php if (!$editing && recurrence_available()): ?>
<script>
(function(){
  const t = document.getElementById('rep-toggle');
  const f = document.getElementById('rep-fields');
  const freq = document.getElementById('rep-freq');
  const weekly = document.getElementById('rep-weekly');
  const monthly = document.getElementById('rep-monthly');
  function refreshFreq(){
    const v = freq.value;
    weekly.hidden  = v !== 'weekly';
    monthly.hidden = v !== 'monthly';
  }
  if (t && f) {
    t.addEventListener('change', () => { f.hidden = !t.checked; });
    f.hidden = !t.checked;
  }
  if (freq) { freq.addEventListener('change', refreshFreq); refreshFreq(); }
  document.querySelectorAll('.dow-presets button').forEach(b => {
    b.addEventListener('click', () => {
      const mask = parseInt(b.dataset.preset, 10) || 0;
      document.querySelectorAll('input[name="rec_days[]"]').forEach(cb => {
        cb.checked = (mask & (1 << parseInt(cb.value, 10))) > 0;
      });
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($view === 'board' && $hasKanban): ?>
    <!-- ============================ BOARD VIEW ============================ -->
    <div class="board" data-csrf="<?= e(csrf_token()) ?>">
        <?php foreach ($cols as $col):
            $list = $byColumn[$col['id']] ?? [];
        ?>
            <section class="board-col" data-col-id="<?= (int)$col['id'] ?>" style="--col: <?= e($col['color']) ?>;">
                <header class="board-col-head">
                    <span class="board-col-dot"></span>
                    <span class="board-col-name"><?= e($col['name']) ?></span>
                    <span class="board-col-count"><?= count($list) ?></span>
                </header>
                <ul class="board-col-list" data-col-id="<?= (int)$col['id'] ?>">
                    <?php foreach ($list as $t):
                        $cardDate = $t['card_date'] ?? null;
                        $bucket   = date_bucket($cardDate);
                        $label    = date_label($cardDate);
                    ?>
                        <li class="board-card date-<?= e($bucket) ?>" data-task-id="<?= (int)$t['id'] ?>">
                            <div class="board-card-pills">
                                <?php if ($label !== ''): ?>
                                    <span class="board-card-date date-<?= e($bucket) ?>"><?= e($label) ?></span>
                                <?php endif; ?>
                                <span class="task-priority <?= e(priority_class($t['priority'])) ?>"><?= e($t['priority']) ?></span>
                                <?php if (!empty($t['recurrence_id'])): ?>
                                    <span class="recurring-pill" title="Recurring task">↻</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="board-card-title">
                                <a href="tasks.php?view=<?= e($view) ?>&edit=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                            </h3>
                            <?php if (!empty($t['description'])): ?>
                                <p class="board-card-desc"><?= e(mb_strimwidth($t['description'], 0, 110, '…')) ?></p>
                            <?php endif; ?>
                            <p class="board-card-foot">
                                <span><?= e($t['assignee_name'] ?: 'Unassigned') ?></span>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>window.LGTM_CSRF = <?= json_encode(csrf_token()) ?>;</script>
    <script src="/assets/js/kanban.js?v=<?= e(asset_version()) ?>"></script>

<?php else: ?>
    <!-- ============================ LIST VIEW ============================= -->
    <?php if (!$tasks): ?>
        <div class="empty">No tasks match your filters.</div>
    <?php else: ?>
        <ul class="task-list">
            <?php foreach ($tasks as $t): $colColor = $t['column_color'] ?? '#EC407A'; ?>
                <li class="task" style="border-left-color: <?= e($colColor) ?>;">
                    <div class="task-head">
                        <span class="task-status-pill" style="background: <?= e($colColor) ?>22; color: <?= e($colColor) ?>;">
                            <?= e($t['column_name'] ?? $t['status']) ?>
                        </span>
                        <span class="task-priority <?= e(priority_class($t['priority'])) ?>"><?= e($t['priority']) ?></span>
                        <?php if (!empty($t['due_date'])): ?><span class="task-due">Due <?= e($t['due_date']) ?></span><?php endif; ?>
                    </div>
                    <h3 class="task-title">
                        <a href="tasks.php?view=<?= e($view) ?>&edit=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                        <?php $progressRow = $subtaskProgress[(int)$t['id']] ?? null;
                              if ($progressRow && $progressRow['total'] > 0): ?>
                            <span class="pill" style="font-size:.72rem; margin-left:.4rem;">☐ <?= (int)$progressRow['done'] ?>/<?= (int)$progressRow['total'] ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if (!empty($t['description'])): ?>
                        <p class="task-desc"><?= nl2br(e($t['description'])) ?></p>
                    <?php endif; ?>
                    <p class="task-by">
                        <?= e($t['assignee_name'] ? 'Assigned to ' . $t['assignee_name'] : 'Unassigned') ?>
                        · created by <?= e($t['creator_name'] ?? '—') ?>
                    </p>
                    <div class="task-actions">
                        <?php if ($hasKanban): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="quick_status">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="return" value="<?= e(http_build_query($_GET)) ?>">
                            <select name="column_id" onchange="this.form.submit()">
                                <?php foreach ($cols as $col): ?>
                                    <option value="<?= (int)$col['id'] ?>" <?= (int)($t['column_id'] ?? 0) === (int)$col['id'] ? 'selected' : '' ?>>
                                        <?= e($col['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                        <a class="btn btn-ghost" href="tasks.php?view=<?= e($view) ?>&edit=<?= (int)$t['id'] ?>">Edit</a>
                        <form method="post" class="inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="op" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="link-btn">Delete</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
