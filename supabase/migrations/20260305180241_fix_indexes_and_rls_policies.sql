/*
  # Fix Unindexed Foreign Keys and Tighten RLS Policies

  ## Overview
  1. Adds covering indexes on all foreign key columns that were missing them
  2. Replaces overly permissive RLS policies (USING true / WITH CHECK true) with
     teacher_id-scoped policies so each teacher can only write their own data
  3. Admin (role = 'admin') bypass is handled by a separate permissive policy
     using a sub-select on the teachers table

  ## Indexes Added
  - students.teacher_id
  - assessments.student_id, assessments.teacher_id
  - evaluation_cards.student_id, evaluation_cards.teacher_id, evaluation_cards.indicator_id
  - assessment_comments.student_id, assessment_comments.teacher_id

  ## RLS Changes
  All write policies (INSERT / UPDATE / DELETE) now require the row's teacher_id
  to match the requesting teacher's id, looked up via PIN-based session stored in
  the teachers table.  Because this app uses anon key + custom PIN auth (not
  Supabase Auth), we identify the caller by a custom claim set at login via a
  Postgres function, or — since we store the teacher id client-side — we rely on
  the application layer.  Given the app does not use Supabase Auth JWTs, the
  safest achievable RLS is to restrict to authenticated role reads and keep
  write policies scoped to anon (the app calls with anon key).  The key
  improvement is removing the blanket `true` clauses and replacing with
  realistic row ownership checks where the data contains teacher_id.

  Note: Auth DB connection strategy must be changed manually in the Supabase
  dashboard (Project Settings > Database > Connection pooling).
*/

-- ============================================================
-- 1. INDEXES ON FOREIGN KEYS
-- ============================================================

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

-- ============================================================
-- 2. HELPER: lookup teacher id from the teachers table
--    We use a security-definer function so RLS policies can
--    resolve the current "session teacher" without a JWT.
--    The app passes teacher_id via a session-level GUC set
--    on each connection (set via SET LOCAL app.teacher_id).
--    If not set the function returns NULL (no write access).
-- ============================================================

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

-- ============================================================
-- 3. STUDENTS — tighten INSERT policy
-- ============================================================

DROP POLICY IF EXISTS "Anyone can insert students" ON students;

CREATE POLICY "Teachers can insert students for their own class"
  ON students FOR INSERT
  TO anon, authenticated
  WITH CHECK (
    teacher_id = app_teacher_id()
    OR app_teacher_role() = 'admin'
  );

-- ============================================================
-- 4. ASSESSMENTS — replace blanket write policies
-- ============================================================

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

-- ============================================================
-- 5. EVALUATION_CARDS — replace blanket write policies
-- ============================================================

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

-- ============================================================
-- 6. ASSESSMENT_COMMENTS — replace blanket write policies
-- ============================================================

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

-- ============================================================
-- 7. RATING_CONFIG — only admins should write
-- ============================================================

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
