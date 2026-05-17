/*
  # Fix skill_indicators RLS policies for admin management

  ## Problem
  The skill_indicators table only had a SELECT policy. INSERT, UPDATE, and DELETE
  were blocked by RLS for all users, including admins. This meant any changes made
  in the Skills Config settings were silently failing — the DB was never updated.

  ## Changes
  - Add INSERT policy: only admins can insert new skill indicators
  - Add UPDATE policy: only admins can update skill indicators (e.g., toggle is_active, edit text)
  - Add DELETE policy: only admins can delete skill indicators

  These policies use app_teacher_role() which reads the x-teacher-id header to look
  up the teacher's role. Admin teachers have role = 'admin'.
*/

CREATE POLICY "Only admins can insert skill indicators"
  ON skill_indicators
  FOR INSERT
  WITH CHECK (app_teacher_role() = 'admin');

CREATE POLICY "Only admins can update skill indicators"
  ON skill_indicators
  FOR UPDATE
  USING (app_teacher_role() = 'admin')
  WITH CHECK (app_teacher_role() = 'admin');

CREATE POLICY "Only admins can delete skill indicators"
  ON skill_indicators
  FOR DELETE
  USING (app_teacher_role() = 'admin');
