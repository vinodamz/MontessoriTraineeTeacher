
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
