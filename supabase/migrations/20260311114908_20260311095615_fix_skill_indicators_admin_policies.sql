
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
