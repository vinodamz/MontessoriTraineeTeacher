<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();

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
                UPDATE tasks SET column_id = :c, board_position = :p WHERE id = :id
            ");
            $set->execute([':c' => $colId, ':p' => $pos, ':id' => $id]);

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
        $due         = $_POST['due_date'] ?: null;
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
            flash_set('ok', 'Task created.');
        } else {
            $stmt = db()->prepare("
                UPDATE tasks SET title=:t, description=:d, column_id=:col, priority=:p,
                                 due_date=:due, assigned_to_user_id=:a
                WHERE id=:id
            ");
            $stmt->execute([
                ':t' => $title, ':d' => $description, ':col' => $colId ?: null,
                ':p' => $priority, ':due' => $due, ':a' => $assignee, ':id' => $id,
            ]);
            flash_set('ok', 'Task updated.');
        }
        redirect('tasks.php?' . http_build_query(array_diff_key($_GET, ['edit' => 1])));
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM tasks WHERE id = :id");
        $stmt->execute([':id' => $id]);
        flash_set('ok', 'Task deleted.');
        redirect('tasks.php');
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
            $stmt = db()->prepare("UPDATE tasks SET column_id = :c, board_position = :p WHERE id = :id");
            $stmt->execute([':c' => $colId, ':p' => $maxPos, ':id' => $id]);
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
    $stmt = db()->prepare("SELECT * FROM tasks WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// Filters
$filterAssn = $_GET['assignee'] ?? '';
$filterCol  = $_GET['col']      ?? '';
$search     = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filterAssn === 'me')                       { $where[] = 't.assigned_to_user_id = :me'; $params[':me'] = $user['id']; }
elseif ($filterAssn !== '' && ctype_digit($filterAssn)) { $where[] = 't.assigned_to_user_id = :a'; $params[':a'] = (int)$filterAssn; }
if ($filterCol !== '' && ctype_digit($filterCol)) { $where[] = 't.column_id = :col'; $params[':col'] = (int)$filterCol; }
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
</details>
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
                        <form method="post" class="inline" onsubmit="return confirm('Delete this task?')">
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
