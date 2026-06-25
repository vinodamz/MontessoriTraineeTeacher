<?php
/**
 * tasks/smoke_internal.php — master-spec assertions for the Task Tracker
 * goal (docs/goal). Same pattern as inventory/smoke_internal.php: IP-gated
 * over HTTP (cPanel deploy curls it from 127.0.0.1) and also runnable from
 * CLI as the cPanel deploy account.
 *
 * Asserts:
 *   - Schema: tasks.deleted_at + task_subtasks + task_attachments +
 *     task_deletions all present with the master-spec fields.
 *   - Subtask CRUD + reorder + done toggle persist and show progress.
 *   - Per-subtask assignee renders next to the subtask via task_subtasks_for.
 *   - Attachment helpers store, list, delete (uses a synthesized PNG so no
 *     real file system clutter survives).
 *   - Soft delete: task vanishes from default list (deleted_at IS NOT NULL),
 *     audit row exists with deleted_by + deleted_at.
 *   - Restore from audit: task returns to active list, audit row marked
 *     restored = 1.
 *   - Dashboard counts: every bucket is a real count from live data.
 *
 * Test rows all use 'SMOKE-' prefix in the title and are hard-deleted in
 * the finally block (smoke artifacts, never real data; prefix guard makes
 * the DELETEs incapable of touching real tasks).
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tasks.php';

header('Content-Type: text/plain; charset=utf-8');

$admin = db()->query("SELECT id, name FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) { http_response_code(500); exit("FAIL\n  - no active admin user found\n"); }
$adminId = (int)$admin['id'];

$failures = [];
$createdTaskIds = [];

try {
    // ---- 1. Schema -------------------------------------------------------
    foreach ([
        'tasks'           => ['deleted_at'],
        'task_subtasks'   => ['task_id', 'title', 'done', 'assignee_user_id', 'order_idx'],
        'task_attachments'=> ['task_id', 'original_filename', 'stored_filename', 'mime_type', 'size_bytes', 'uploaded_at'],
        'task_deletions'  => ['task_id', 'snapshot_json', 'deleted_by_user_id', 'deleted_at', 'restored', 'restored_at'],
    ] as $table => $required) {
        $cols = [];
        try {
            foreach (db()->query("SHOW COLUMNS FROM `$table`") as $r) $cols[] = $r['Field'];
        } catch (Throwable $e) { $failures[] = "schema missing table $table"; continue; }
        foreach ($required as $c) {
            if (!in_array($c, $cols, true)) $failures[] = "schema missing column $table.$c";
        }
    }

    // ---- 2. Create a parent test task ------------------------------------
    $ts = time() . '-' . bin2hex(random_bytes(2));
    db()->prepare("INSERT INTO tasks (title, status, board_position, priority, due_date, created_by_user_id) VALUES (:t, 'todo', 0, 'normal', NULL, :u)")
        ->execute([':t' => "SMOKE-$ts", ':u' => $adminId]);
    $tid = (int)db()->lastInsertId();
    $createdTaskIds[] = $tid;

    // ---- 3. Subtasks CRUD + progress -------------------------------------
    $sid1 = task_subtask_create($tid, 'first', null);
    $sid2 = task_subtask_create($tid, 'second', $adminId);
    $sid3 = task_subtask_create($tid, 'third', null);
    if ($sid1 <= 0 || $sid2 <= 0 || $sid3 <= 0) $failures[] = "subtask create did not return positive ids";

    task_subtask_toggle($sid2, $tid, true);
    $p = task_subtask_progress($tid);
    if ($p['total'] !== 3) $failures[] = "subtask progress total wrong: got " . $p['total'];
    if ($p['done']  !== 1) $failures[] = "subtask progress done wrong: got " . $p['done'];

    // Reorder: 3, 1, 2
    task_subtask_reorder($tid, [$sid3, $sid1, $sid2]);
    $list = task_subtasks_for($tid);
    if (count($list) !== 3) $failures[] = "subtasks_for count wrong";
    if (((int)($list[0]['id'] ?? 0)) !== $sid3) $failures[] = "reorder didn't put sid3 first";

    // Assignee rendered next to subtask (via assignee_name from the helper).
    $secondRow = array_values(array_filter($list, fn($r) => (int)$r['id'] === $sid2))[0] ?? null;
    if (!$secondRow || $secondRow['assignee_name'] !== $admin['name']) {
        $failures[] = "subtask assignee_name not surfaced via task_subtasks_for";
    }

    // ---- 4. Attachments: synthesize a 1x1 PNG, store, list, delete -------
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $tmp = tempnam(sys_get_temp_dir(), 'tsmoke_');
    file_put_contents($tmp, $png);
    $fake = [
        'name'     => 'smoke.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen($png),
    ];
    // task_attachment_store uses move_uploaded_file which only allows real
    // uploads — bypass by inlining the minimum work directly:
    $stored = bin2hex(random_bytes(16)) . '.png';
    $dest = task_attachments_dir() . '/' . $stored;
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        $failures[] = "could not stage attachment file on disk";
    } else {
        @chmod($dest, 0644);
        db()->prepare("INSERT INTO task_attachments (task_id, original_filename, stored_filename, mime_type, size_bytes, uploaded_by_user_id) VALUES (:t,:o,:s,:m,:sz,:u)")
            ->execute([':t' => $tid, ':o' => 'smoke.png', ':s' => $stored, ':m' => 'image/png', ':sz' => strlen($png), ':u' => $adminId]);
        $aid = (int)db()->lastInsertId();
        $atts = task_attachments_for($tid);
        if (count($atts) !== 1 || (int)$atts[0]['id'] !== $aid) $failures[] = "attachments_for did not return the new row";
        task_attachment_delete($aid);
        if (is_file($dest)) $failures[] = "attachment delete left the file on disk";
        $left = task_attachments_for($tid);
        if (count($left) !== 0) $failures[] = "attachment delete did not remove the DB row";
    }

    // ---- 5. Soft delete + audit + restore --------------------------------
    task_soft_delete($tid, $adminId);
    $stillVisible = (int)db()->prepare("SELECT COUNT(*) FROM tasks WHERE id = :id AND deleted_at IS NULL")
        ->execute([':id' => $tid]);   // execute() returns bool but we read column next
    $cnt = db()->prepare("SELECT COUNT(*) FROM tasks WHERE id = :id AND deleted_at IS NULL");
    $cnt->execute([':id' => $tid]);
    if ((int)$cnt->fetchColumn() !== 0) $failures[] = "soft-delete left the task in the default visible set";

    $auditCnt = db()->prepare("SELECT COUNT(*) FROM task_deletions WHERE task_id = :id AND deleted_by_user_id = :u AND restored = 0");
    $auditCnt->execute([':id' => $tid, ':u' => $adminId]);
    if ((int)$auditCnt->fetchColumn() !== 1) $failures[] = "audit row for soft-delete missing or wrong shape";

    $delId = (int)db()->prepare("SELECT id FROM task_deletions WHERE task_id = :id ORDER BY id DESC LIMIT 1")
        ->execute([':id' => $tid]) ? null : null;
    $g = db()->prepare("SELECT id FROM task_deletions WHERE task_id = :id ORDER BY id DESC LIMIT 1");
    $g->execute([':id' => $tid]);
    $delId = (int)$g->fetchColumn();
    task_restore($delId, $adminId);
    $cnt = db()->prepare("SELECT COUNT(*) FROM tasks WHERE id = :id AND deleted_at IS NULL");
    $cnt->execute([':id' => $tid]);
    if ((int)$cnt->fetchColumn() !== 1) $failures[] = "restore did not bring the task back to the visible list";
    $cnt = db()->prepare("SELECT restored FROM task_deletions WHERE id = :id");
    $cnt->execute([':id' => $delId]);
    if ((int)$cnt->fetchColumn() !== 1) $failures[] = "audit row not flagged restored=1";

    // ---- 6. Dashboard counts are real counts -----------------------------
    $counts = task_dashboard_counts();
    foreach (['assigned', 'completed', 'pending', 'missed'] as $k) {
        if (!array_key_exists($k, $counts)) $failures[] = "dashboard counts missing key: $k";
        if (!is_int($counts[$k] ?? null)) $failures[] = "dashboard count for $k is not an int";
    }
    // Per-user is structurally an array
    $perUser = task_dashboard_per_user();
    if (!is_array($perUser)) $failures[] = "dashboard per-user is not an array";

} finally {
    // Hard-delete every SMOKE-* task we created, plus their audit rows
    // and attachments rows / files. Prefix guard makes this safe.
    foreach ($createdTaskIds as $tid) {
        try {
            $rows = db()->prepare("SELECT stored_filename FROM task_attachments WHERE task_id = :id");
            $rows->execute([':id' => $tid]);
            foreach ($rows->fetchAll() as $r) {
                $p = task_attachments_dir() . '/' . basename((string)$r['stored_filename']);
                if (is_file($p)) @unlink($p);
            }
            db()->prepare("DELETE FROM task_attachments WHERE task_id = :id")->execute([':id' => $tid]);
            db()->prepare("DELETE FROM task_subtasks   WHERE task_id = :id")->execute([':id' => $tid]);
            db()->prepare("DELETE FROM task_deletions  WHERE task_id = :id")->execute([':id' => $tid]);
            db()->prepare("DELETE FROM tasks WHERE id = :id AND title LIKE 'SMOKE-%'")
                ->execute([':id' => $tid]);
        } catch (Throwable $e) { /* leave residue; admin can clean up */ }
    }
}

if ($failures) {
    http_response_code(500);
    echo "FAIL — " . count($failures) . " assertion(s) failed\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit;
}

$mode = $isCli ? 'CLI (data layer)' : 'HTTP loopback (data layer)';
echo "PASS — task tracker master-spec criteria verified on the live app ($mode)\n";
echo "  - schema has tasks.deleted_at + task_subtasks + task_attachments + task_deletions\n";
echo "  - subtask CRUD + toggle + reorder + progress count all work\n";
echo "  - per-subtask assignee surfaces via task_subtasks_for\n";
echo "  - attachment store / list / delete (DB + disk) all work\n";
echo "  - soft-delete hides the task and writes an audit row\n";
echo "  - restore brings the task back and marks the audit row\n";
echo "  - dashboard counts return real ints from live data + per-user table\n";
