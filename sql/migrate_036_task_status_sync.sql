-- ============================================================================
-- migrate_036_task_status_sync.sql
--
-- Backfill tasks.status from the kanban Done column. Moving a card to Done
-- only ever updated column_id — status stayed 'todo' forever, so the
-- dashboard's Completed tile showed 0 and Missed kept growing for tasks that
-- were finished weeks ago. tasks.php now keeps the two in step on every
-- move/update; this repairs the rows written before that fix.
--
-- Idempotent: the UPDATE converges (second run changes 0 rows).
-- ============================================================================

SET NAMES utf8mb4;

UPDATE tasks t
JOIN   task_columns c ON c.id = t.column_id
SET    t.status = IF(c.is_done = 1, 'done', 'todo')
WHERE  t.status <> IF(c.is_done = 1, 'done', 'todo');
