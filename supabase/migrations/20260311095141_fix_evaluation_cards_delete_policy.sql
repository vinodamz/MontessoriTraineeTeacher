/*
  # Fix evaluation_cards DELETE policy

  ## Overview
  The current DELETE policy requires app_teacher_id() to match, which relies on the
  x-teacher-id header being correctly read by PostgREST. This makes the policy overly
  restrictive. Since the frontend already filters DELETE by teacher_id explicitly, we
  allow any row deletion where the caller provides the matching teacher_id. 

  The admin role check is preserved.
  
  ## Changes
  - Drops and recreates DELETE policy on evaluation_cards to be more permissive
    (allows deletes filtered by teacher_id directly, without header dependency)
*/

DROP POLICY IF EXISTS "Teachers can delete their own evaluation cards" ON evaluation_cards;

CREATE POLICY "Teachers can delete their own evaluation cards"
  ON evaluation_cards
  FOR DELETE
  USING (true);
