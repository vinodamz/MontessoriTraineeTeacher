-- ============================================================================
-- migrate_032_task_enhancements.sql
--
-- Goal: docs/goal — Task Tracker Enhancements.
--
-- Adds four pieces the existing tasks module doesn't have yet:
--   1. tasks.deleted_at DATETIME — soft-delete tombstone. Every existing
--      list query starts filtering deleted_at IS NULL after this lands;
--      restoring is just NULL'ing the tombstone.
--   2. task_subtasks — per-task checklist with title, done flag, optional
--      assignee (FK to users), and order_idx for drag-reordering.
--   3. task_attachments — uploaded files per task, with the same store-by-
--      random-name + serve-via-auth pattern student_documents already uses.
--   4. task_deletions — append-only audit log: who deleted what task when,
--      a full JSON snapshot of the task at delete time, and restore-back
--      bookkeeping.
--
-- Existing tasks rows are preserved — the ALTER TABLE only adds a NULLable
-- column, and all migrations are idempotent.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_task_enhancements;
DELIMITER //
CREATE PROCEDURE pr_lg_task_enhancements()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'
          AND COLUMN_NAME = 'deleted_at'
    ) THEN
        ALTER TABLE tasks
            ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
            ADD KEY idx_tasks_deleted (deleted_at);
    END IF;
END //
DELIMITER ;
CALL pr_lg_task_enhancements();
DROP PROCEDURE pr_lg_task_enhancements;

CREATE TABLE IF NOT EXISTS task_subtasks (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(255) NOT NULL,
    done             TINYINT(1)   NOT NULL DEFAULT 0,
    assignee_user_id INT UNSIGNED NULL,
    order_idx        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ts_task     (task_id, order_idx),
    KEY idx_ts_assignee (assignee_user_id),
    CONSTRAINT fk_ts_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ts_user FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_attachments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id             INT UNSIGNED NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    stored_filename     VARCHAR(80)  NOT NULL,
    mime_type           VARCHAR(120) NOT NULL,
    size_bytes          INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ta_stored (stored_filename),
    KEY idx_ta_task (task_id, uploaded_at),
    CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log — no FK on task_id so the row survives if a task is ever
-- hard-purged; snapshot_json holds enough to reconstruct the task.
CREATE TABLE IF NOT EXISTS task_deletions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id             INT UNSIGNED NOT NULL,
    snapshot_json       MEDIUMTEXT   NOT NULL,
    deleted_by_user_id  INT UNSIGNED NOT NULL,
    deleted_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored            TINYINT(1)   NOT NULL DEFAULT 0,
    restored_at         DATETIME     NULL,
    restored_by_user_id INT UNSIGNED NULL,
    KEY idx_td_task     (task_id),
    KEY idx_td_deleted  (deleted_at),
    KEY idx_td_restored (restored, deleted_at),
    CONSTRAINT fk_td_user     FOREIGN KEY (deleted_by_user_id)  REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_td_restorer FOREIGN KEY (restored_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
