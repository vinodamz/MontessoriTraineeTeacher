/*
  # Add is_custom_indicator flag to evaluation_cards

  ## Changes
  - `evaluation_cards` table: add `is_custom_indicator` boolean column (default false)
    - Used to distinguish ratings for student-specific custom indicators vs standard grade indicators
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'evaluation_cards' AND column_name = 'is_custom_indicator'
  ) THEN
    ALTER TABLE evaluation_cards ADD COLUMN is_custom_indicator boolean NOT NULL DEFAULT false;
  END IF;
END $$;
