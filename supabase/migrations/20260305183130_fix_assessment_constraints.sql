/*
  # Fix Assessment Constraints

  ## Overview
  Removes overly restrictive database constraints that prevent saving assessments
  with partial data or custom rating codes.

  ## Changes

  ### evaluation_cards table
  - Remove the `CHECK (rating IN ('D', 'P', 'N'))` constraint so custom rating
    codes from rating_config are accepted

  ### assessments table
  - Remove the `CHECK (score >= 1 AND score <= 5)` constraint so partial/zero
    assessments can be saved without triggering a constraint violation
*/

ALTER TABLE evaluation_cards DROP CONSTRAINT IF EXISTS evaluation_cards_rating_check;

ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_score_check;
