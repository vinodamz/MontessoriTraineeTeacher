
CREATE INDEX IF NOT EXISTS idx_students_teacher_id
  ON students (teacher_id);

CREATE INDEX IF NOT EXISTS idx_assessments_student_id
  ON assessments (student_id);

CREATE INDEX IF NOT EXISTS idx_assessments_teacher_id
  ON assessments (teacher_id);

CREATE INDEX IF NOT EXISTS idx_evaluation_cards_student_id
  ON evaluation_cards (student_id);

CREATE INDEX IF NOT EXISTS idx_evaluation_cards_teacher_id
  ON evaluation_cards (teacher_id);

CREATE INDEX IF NOT EXISTS idx_evaluation_cards_indicator_id
  ON evaluation_cards (indicator_id);

CREATE INDEX IF NOT EXISTS idx_assessment_comments_student_id
  ON assessment_comments (student_id);

CREATE INDEX IF NOT EXISTS idx_assessment_comments_teacher_id
  ON assessment_comments (teacher_id);

CREATE OR REPLACE FUNCTION app_teacher_id()
RETURNS uuid
LANGUAGE sql
STABLE
SECURITY DEFINER
AS $$
  SELECT NULLIF(current_setting('app.teacher_id', true), '')::uuid;
$$;

CREATE OR REPLACE FUNCTION app_teacher_role()
RETURNS text
LANGUAGE sql
STABLE
SECURITY DEFINER
AS $$
  SELECT role FROM teachers
  WHERE id = NULLIF(current_setting('app.teacher_id', true), '')::uuid
  LIMIT 1;
$$;

DROP POLICY IF EXISTS "Anyone can insert students" ON students;

CREATE POLICY "Teachers can insert students for their own class"
  ON students FOR INSERT
  TO anon, authenticated
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

DROP POLICY IF EXISTS "Anyone can insert assessments" ON assessments;
DROP POLICY IF EXISTS "Anyone can update assessments" ON assessments;
DROP POLICY IF EXISTS "Anyone can delete assessments" ON assessments;

CREATE POLICY "Teachers can insert their own assessments"
  ON assessments FOR INSERT
  TO anon, authenticated
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can update their own assessments"
  ON assessments FOR UPDATE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  )
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can delete their own assessments"
  ON assessments FOR DELETE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

DROP POLICY IF EXISTS "Anyone can insert evaluation cards" ON evaluation_cards;
DROP POLICY IF EXISTS "Anyone can update evaluation cards" ON evaluation_cards;
DROP POLICY IF EXISTS "Anyone can delete evaluation cards" ON evaluation_cards;

CREATE POLICY "Teachers can insert their own evaluation cards"
  ON evaluation_cards FOR INSERT
  TO anon, authenticated
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can update their own evaluation cards"
  ON evaluation_cards FOR UPDATE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  )
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can delete their own evaluation cards"
  ON evaluation_cards FOR DELETE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

DROP POLICY IF EXISTS "Anyone can insert assessment comments" ON assessment_comments;
DROP POLICY IF EXISTS "Anyone can update assessment comments" ON assessment_comments;
DROP POLICY IF EXISTS "Anyone can delete assessment comments" ON assessment_comments;

CREATE POLICY "Teachers can insert their own assessment comments"
  ON assessment_comments FOR INSERT
  TO anon, authenticated
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can update their own assessment comments"
  ON assessment_comments FOR UPDATE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  )
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

CREATE POLICY "Teachers can delete their own assessment comments"
  ON assessment_comments FOR DELETE
  TO anon, authenticated
  USING (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

DROP POLICY IF EXISTS "Anyone can insert rating config" ON rating_config;
DROP POLICY IF EXISTS "Anyone can update rating config" ON rating_config;
DROP POLICY IF EXISTS "Anyone can delete rating config" ON rating_config;

CREATE POLICY "Only admins can insert rating config"
  ON rating_config FOR INSERT
  TO anon, authenticated
  WITH CHECK (app_teacher_role() = 'admin');

CREATE POLICY "Only admins can update rating config"
  ON rating_config FOR UPDATE
  TO anon, authenticated
  USING (app_teacher_role() = 'admin')
  WITH CHECK (app_teacher_role() = 'admin');

CREATE POLICY "Only admins can delete rating config"
  ON rating_config FOR DELETE
  TO anon, authenticated
  USING (app_teacher_role() = 'admin');
