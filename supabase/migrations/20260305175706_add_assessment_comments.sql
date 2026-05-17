/*
  # Add Assessment Comments Table

  ## Overview
  Adds a table for storing teacher comments per assessment — both per-category
  and an overall comment for each student-month assessment.

  ## New Table

  ### assessment_comments
  - `id` — primary key
  - `student_id` — linked student
  - `teacher_id` — teacher who wrote the comment
  - `month_year` — assessment period (e.g., "Jun-25")
  - `category` — skill category name, NULL means overall/general comment
  - `comment` — the text comment
  - `created_at` — timestamp

  ## Security
  - RLS enabled
  - Anyone can read/write (consistent with other tables in this app)
*/

CREATE TABLE IF NOT EXISTS assessment_comments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id uuid REFERENCES students(id),
  teacher_id uuid REFERENCES teachers(id),
  month_year text NOT NULL,
  category text,
  comment text NOT NULL DEFAULT '',
  created_at timestamptz DEFAULT now()
);

ALTER TABLE assessment_comments ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read assessment comments"
  ON assessment_comments FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert assessment comments"
  ON assessment_comments FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Anyone can update assessment comments"
  ON assessment_comments FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Anyone can delete assessment comments"
  ON assessment_comments FOR DELETE
  TO anon, authenticated
  USING (true);
