<?php
/**
 * tasks.php — Task Tracker domain helpers (Phase: migration 032 enhancements).
 *
 *   - Subtask CRUD + reorder
 *   - Attachment store / fetch / delete (uploads/task_attachments/)
 *   - Soft delete + restore with append-only audit log (task_deletions)
 *   - Dashboard rollups: assigned / completed / pending / missed
 */
declare(strict_types=1);

// ---------- Subtasks ---------------------------------------------------------

function task_subtasks_for(int $taskId): array
{
    $st = db()->prepare("
        SELECT ts.*, u.name AS assignee_name
        FROM   task_subtasks ts
        LEFT JOIN users u ON u.id = ts.assignee_user_id
        WHERE  ts.task_id = :id
        ORDER BY ts.order_idx, ts.id
    ");
    $st->execute([':id' => $taskId]);
    return $st->fetchAll();
}

function task_subtask_progress(int $taskId): array
{
    $r = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(done), 0) AS d FROM task_subtasks WHERE task_id = :id");
    $r->execute([':id' => $taskId]);
    $row = $r->fetch();
    return [
        'total' => (int)($row['n'] ?? 0),
        'done'  => (int)($row['d'] ?? 0),
    ];
}

/** Progress for many tasks at once — used by the board / list pages. */
function task_subtask_progress_for(array $taskIds): array
{
    $out = [];
    if (!$taskIds) return $out;
    $place = implode(',', array_fill(0, count($taskIds), '?'));
    $st = db()->prepare("
        SELECT task_id, COUNT(*) AS n, COALESCE(SUM(done), 0) AS d
        FROM task_subtasks
        WHERE task_id IN ($place)
        GROUP BY task_id
    ");
    $st->execute(array_values($taskIds));
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['task_id']] = ['total' => (int)$r['n'], 'done' => (int)$r['d']];
    }
    return $out;
}

function task_subtask_create(int $taskId, string $title, ?int $assigneeUserId): int
{
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('subtask title is required');
    if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);
    $st = db()->prepare("SELECT COALESCE(MAX(order_idx), 0) + 1 FROM task_subtasks WHERE task_id = :id");
    $st->execute([':id' => $taskId]);
    $idx = (int)$st->fetchColumn();
    $st = db()->prepare("
        INSERT INTO task_subtasks (task_id, title, assignee_user_id, order_idx)
        VALUES (:tid, :t, :uid, :ord)
    ");
    $st->execute([':tid' => $taskId, ':t' => $title, ':uid' => $assigneeUserId, ':ord' => $idx]);
    return (int)db()->lastInsertId();
}

function task_subtask_update(int $subtaskId, int $taskId, string $title, ?int $assigneeUserId): void
{
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('subtask title is required');
    if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);
    db()->prepare("
        UPDATE task_subtasks
        SET    title = :t, assignee_user_id = :uid
        WHERE  id = :id AND task_id = :tid
    ")->execute([':t' => $title, ':uid' => $assigneeUserId, ':id' => $subtaskId, ':tid' => $taskId]);
}

function task_subtask_toggle(int $subtaskId, int $taskId, bool $done): void
{
    db()->prepare("UPDATE task_subtasks SET done = :d WHERE id = :id AND task_id = :tid")
        ->execute([':d' => $done ? 1 : 0, ':id' => $subtaskId, ':tid' => $taskId]);
}

function task_subtask_delete(int $subtaskId, int $taskId): void
{
    db()->prepare("DELETE FROM task_subtasks WHERE id = :id AND task_id = :tid")
        ->execute([':id' => $subtaskId, ':tid' => $taskId]);
}

/** Reorder by an ordered list of subtask ids. Items not in the list keep their order. */
function task_subtask_reorder(int $taskId, array $orderedIds): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE task_subtasks SET order_idx = :ord WHERE id = :id AND task_id = :tid");
        $i = 1;
        foreach ($orderedIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;
            $st->execute([':ord' => $i, ':id' => $sid, ':tid' => $taskId]);
            $i++;
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

// ---------- Attachments ------------------------------------------------------

function task_attachments_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/task_attachments';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

const TASK_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const TASK_ATTACHMENT_MIME_ALLOW = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'text/plain'      => 'txt',
    'text/csv'        => 'csv',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/zip' => 'zip',
];

function task_attachment_store(array $file, int $taskId, int $byUserId): int
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file selected.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('Empty file.');
    if ($size > TASK_ATTACHMENT_MAX_BYTES) {
        throw new RuntimeException('File over ' . format_bytes(TASK_ATTACHMENT_MAX_BYTES) . '.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!array_key_exists($mime, TASK_ATTACHMENT_MIME_ALLOW)) {
        throw new RuntimeException('File type "' . $mime . '" not allowed. PDF / image / Office / TXT / ZIP only.');
    }
    $ext  = TASK_ATTACHMENT_MIME_ALLOW[$mime];
    $orig = (string)($file['name'] ?? "upload.$ext");
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = task_attachments_dir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the file.');
    }
    @chmod($dest, 0644);

    db()->prepare("
        INSERT INTO task_attachments (task_id, original_filename, stored_filename, mime_type, size_bytes, uploaded_by_user_id)
        VALUES (:tid, :orig, :stored, :mime, :sz, :u)
    ")->execute([':tid' => $taskId, ':orig' => $orig, ':stored' => $stored, ':mime' => $mime, ':sz' => $size, ':u' => $byUserId]);
    return (int)db()->lastInsertId();
}

function task_attachments_for(int $taskId): array
{
    $st = db()->prepare("
        SELECT ta.*, u.name AS uploader_name
        FROM   task_attachments ta
        LEFT JOIN users u ON u.id = ta.uploaded_by_user_id
        WHERE  ta.task_id = :id
        ORDER  BY ta.uploaded_at DESC
    ");
    $st->execute([':id' => $taskId]);
    return $st->fetchAll();
}

function task_attachment_delete(int $attachmentId): void
{
    $st = db()->prepare("SELECT stored_filename FROM task_attachments WHERE id = :id");
    $st->execute([':id' => $attachmentId]);
    $stored = (string)($st->fetchColumn() ?: '');
    db()->prepare("DELETE FROM task_attachments WHERE id = :id")->execute([':id' => $attachmentId]);
    if ($stored !== '') {
        $p = task_attachments_dir() . '/' . basename($stored);
        if (is_file($p)) @unlink($p);
    }
}

// ---------- Soft delete + restore + audit log -------------------------------

function task_soft_delete(int $taskId, int $byUserId): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND deleted_at IS NULL");
        $st->execute([':id' => $taskId]);
        $task = $st->fetch();
        if (!$task) throw new RuntimeException('Task not found or already deleted.');

        $snapshot = [
            'task'        => $task,
            'subtasks'    => task_subtasks_for($taskId),
            'attachments' => task_attachments_for($taskId),
        ];

        $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = :id")->execute([':id' => $taskId]);
        $pdo->prepare("
            INSERT INTO task_deletions (task_id, snapshot_json, deleted_by_user_id)
            VALUES (:tid, :snap, :u)
        ")->execute([':tid' => $taskId, ':snap' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), ':u' => $byUserId]);

        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

function task_restore(int $deletionId, int $byUserId): int
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM task_deletions WHERE id = :id AND restored = 0");
        $st->execute([':id' => $deletionId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Audit entry not found or already restored.');
        $tid = (int)$row['task_id'];

        $check = $pdo->prepare("SELECT id, deleted_at FROM tasks WHERE id = :id");
        $check->execute([':id' => $tid]);
        $t = $check->fetch();
        if ($t) {
            $pdo->prepare("UPDATE tasks SET deleted_at = NULL WHERE id = :id")->execute([':id' => $tid]);
        } else {
            // Row was hard-purged at some point — recreate from snapshot.
            $snap = json_decode((string)$row['snapshot_json'], true);
            $tsk  = $snap['task'] ?? null;
            if (!$tsk) throw new RuntimeException('Snapshot missing task payload.');
            $pdo->prepare("
                INSERT INTO tasks
                    (id, title, description, status, column_id, board_position, priority,
                     due_date, assigned_to_user_id, created_by_user_id, recurrence_id, instance_date,
                     created_at, updated_at, deleted_at)
                VALUES
                    (:id, :title, :desc, :status, :col, :pos, :prio,
                     :due, :auid, :cuid, :rid, :idate, :ca, NOW(), NULL)
            ")->execute([
                ':id' => $tid,
                ':title' => $tsk['title'],
                ':desc'  => $tsk['description'] ?? null,
                ':status' => $tsk['status'] ?? 'todo',
                ':col'    => $tsk['column_id'] ?? null,
                ':pos'    => (int)($tsk['board_position'] ?? 0),
                ':prio'   => $tsk['priority'] ?? 'normal',
                ':due'    => $tsk['due_date'] ?? null,
                ':auid'   => $tsk['assigned_to_user_id'] ?? null,
                ':cuid'   => $tsk['created_by_user_id'] ?? $byUserId,
                ':rid'    => $tsk['recurrence_id'] ?? null,
                ':idate'  => $tsk['instance_date'] ?? null,
                ':ca'     => $tsk['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }

        $pdo->prepare("
            UPDATE task_deletions SET restored = 1, restored_at = NOW(), restored_by_user_id = :u
            WHERE id = :id
        ")->execute([':u' => $byUserId, ':id' => $deletionId]);

        if ($own) $pdo->commit();
        return $tid;
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

// ---------- Dashboard rollups -----------------------------------------------

/**
 * Count tasks per status bucket for the dashboard tiles.
 * Excludes deleted (deleted_at IS NULL).
 *   assigned  = open + has assignee
 *   completed = status = 'done'
 *   pending   = open + (no due date OR due >= today)
 *   missed    = open + has due date < today (past due)
 *
 * Optional $assigneeId narrows to one person.
 */
function task_dashboard_counts(?int $assigneeId = null): array
{
    $where = "deleted_at IS NULL";
    $params = [];
    if ($assigneeId !== null) {
        $where .= " AND assigned_to_user_id = :uid";
        $params[':uid'] = $assigneeId;
    }
    $sql = "
        SELECT
          SUM(CASE WHEN status <> 'done' AND assigned_to_user_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned,
          SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END)                                       AS completed,
          SUM(CASE WHEN status <> 'done' AND (due_date IS NULL OR due_date >= CURDATE()) THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN status <> 'done' AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS missed
        FROM tasks WHERE $where
    ";
    $st = db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return [
        'assigned'  => (int)($r['assigned']  ?? 0),
        'completed' => (int)($r['completed'] ?? 0),
        'pending'   => (int)($r['pending']   ?? 0),
        'missed'    => (int)($r['missed']    ?? 0),
    ];
}

function task_dashboard_per_user(): array
{
    // Wrap in a subquery so HAVING/ORDER BY can reference the aliases —
    // MariaDB's strict mode rejects column aliases inside HAVING when the
    // alias points at an aggregate ("Reference 'assigned' not supported
    // (reference to group function)").
    $sql = "
        SELECT * FROM (
            SELECT u.id, u.name,
              SUM(CASE WHEN t.status <> 'done' AND t.assigned_to_user_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned,
              SUM(CASE WHEN t.status = 'done'  THEN 1 ELSE 0 END) AS completed,
              SUM(CASE WHEN t.status <> 'done' AND (t.due_date IS NULL OR t.due_date >= CURDATE()) THEN 1 ELSE 0 END) AS pending,
              SUM(CASE WHEN t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS missed
            FROM users u
            LEFT JOIN tasks t ON t.assigned_to_user_id = u.id AND t.deleted_at IS NULL
            WHERE u.active = 1
            GROUP BY u.id
        ) AS x
        WHERE (assigned + completed + pending + missed) > 0
        ORDER BY (assigned + missed) DESC, name
    ";
    return db()->query($sql)->fetchAll();
}
