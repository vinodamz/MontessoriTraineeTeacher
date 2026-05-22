-- ============================================================================
-- migrate_015_rebrand_megha_to_ayesha.sql
--
-- The Odoo import (PR #14) ran on the live DB with Megha
-- (meghaprabhakar39@gmail.com) still authoring 63 of the imported
-- inquiry_touchpoints. She's no longer on the team. This migration
-- rewrites those rows in place so the pipeline display reads "— Ayesha"
-- instead of "— meghaprabhakar39@gmail.com".
--
-- 1. Replaces the email string inside inquiry_touchpoints.body.
-- 2. If a local user named "Ayesha" exists, points created_by at her so
--    the touchpoints carry the right authorship metadata.
--
-- Idempotent — both UPDATEs are no-ops once they've been applied.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

UPDATE inquiry_touchpoints
SET body = REPLACE(body, 'meghaprabhakar39@gmail.com', 'Ayesha')
WHERE body LIKE '%meghaprabhakar39@gmail.com%';

UPDATE inquiry_touchpoints t
JOIN users u ON LOWER(u.name) = 'ayesha'
SET t.created_by = u.id
WHERE t.body LIKE '%— Ayesha%'
  AND t.created_by IS NULL;
