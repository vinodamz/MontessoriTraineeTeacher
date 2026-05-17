/*
  # Add unique constraint to evaluation_cards

  ## Overview
  Adds a unique constraint on (student_id, teacher_id, month_year, indicator_id)
  to prevent duplicate rows and enable upsert operations.

  ## Changes
  - Removes any existing duplicate rows (keeping latest by submitted_at)
  - Adds unique constraint on evaluation_cards(student_id, teacher_id, month_year, indicator_id)
*/

DELETE FROM evaluation_cards ec
WHERE id NOT IN (
  SELECT DISTINCT ON (student_id, teacher_id, month_year, indicator_id) id
  FROM evaluation_cards
  ORDER BY student_id, teacher_id, month_year, indicator_id, submitted_at DESC NULLS LAST
);

ALTER TABLE evaluation_cards
  DROP CONSTRAINT IF EXISTS evaluation_cards_unique_rating;

ALTER TABLE evaluation_cards
  ADD CONSTRAINT evaluation_cards_unique_rating
  UNIQUE (student_id, teacher_id, month_year, indicator_id);
