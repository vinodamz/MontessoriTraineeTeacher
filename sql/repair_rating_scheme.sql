-- ============================================================================
-- repair_rating_scheme.sql
--
-- The original migration assumed a 3-level rating scheme (D / P / N), but
-- the actual Supabase data uses 5 levels (M / I / P / E / N). The strict
-- ENUM on evaluation_cards.rating silently dropped 593 / 1036 rows on import.
--
-- This script:
--   1. Loosens evaluation_cards.rating to CHAR(1) so any code from
--      rating_config is accepted.
--   2. Resets rating_config to the real 5-row scheme.
--   3. Truncates the partial assessment data so the re-import builds
--      consistent averages from scratch.
--
-- Apply via phpMyAdmin → Import, then run migrate_from_supabase.php again.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Allow any single-char rating code.
ALTER TABLE evaluation_cards MODIFY rating CHAR(1) NOT NULL;

-- 2. Reset the rating scheme to match Supabase exactly.
DELETE FROM rating_config;
INSERT INTO rating_config (code, label, color, numeric_value, is_active) VALUES
  ('M', 'Mastered',          '#10b981', 5, 1),
  ('I', 'Independent',       '#14b8a6', 4, 1),
  ('P', 'Progressing',       '#0af5e5', 3, 1),
  ('E', 'Emerging',          '#6366f1', 2, 1),
  ('N', 'Needs Improvement', '#f97316', 1, 1);

-- 3. Clear the partial/skewed assessment data so re-import is clean.
--    Teachers, students, indicators, baselines stay untouched.
DELETE FROM assessment_comments;
DELETE FROM assessments;
DELETE FROM evaluation_cards;
