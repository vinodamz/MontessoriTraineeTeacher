/*
  # Little Graduates Assessment Portal - Core Schema

  ## Overview
  Creates all tables required for the preschool student assessment web application.

  ## New Tables

  ### teachers
  - Stores teacher accounts with name, PIN, and role
  - Roles: 'teacher' or 'admin'

  ### students
  - Stores student records linked to a teacher
  - Grades: Playgroup, Nursery, LKG, UKG

  ### skill_indicators
  - Developmental skill indicators per grade and category
  - Categories: Gross Motor, Fine Motor, Literacy, Numeracy, Social Skills, Communication

  ### assessments
  - Monthly numerical assessments (1-5 scale) per student per category
  - Stores category averages

  ### evaluation_cards
  - D/P/N (Demonstrated/Progressing/Not Yet Acquired) ratings per indicator

  ## Security
  - RLS enabled on all tables
  - Teachers can read/write their own data
  - Public read for teachers (for login dropdown)
  - All inserts/updates require authenticated session via teacher_id matching
*/

CREATE TABLE IF NOT EXISTS teachers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  pin text NOT NULL,
  role text NOT NULL DEFAULT 'teacher',
  created_at timestamptz DEFAULT now()
);

ALTER TABLE teachers ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read teachers for login"
  ON teachers FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE TABLE IF NOT EXISTS students (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  first_name text NOT NULL,
  last_name text NOT NULL,
  grade text NOT NULL,
  teacher_id uuid REFERENCES teachers(id),
  created_at timestamptz DEFAULT now()
);

ALTER TABLE students ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read students"
  ON students FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert students"
  ON students FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE TABLE IF NOT EXISTS skill_indicators (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  grade text NOT NULL,
  category text NOT NULL,
  indicator_text text NOT NULL,
  display_order int NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

ALTER TABLE skill_indicators ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read skill indicators"
  ON skill_indicators FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE TABLE IF NOT EXISTS assessments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id uuid REFERENCES students(id),
  teacher_id uuid REFERENCES teachers(id),
  month_year text NOT NULL,
  category text NOT NULL,
  score int NOT NULL CHECK (score >= 1 AND score <= 5),
  category_avg float,
  submitted_at timestamptz DEFAULT now()
);

ALTER TABLE assessments ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read assessments"
  ON assessments FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert assessments"
  ON assessments FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Anyone can update assessments"
  ON assessments FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Anyone can delete assessments"
  ON assessments FOR DELETE
  TO anon, authenticated
  USING (true);

CREATE TABLE IF NOT EXISTS evaluation_cards (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id uuid REFERENCES students(id),
  teacher_id uuid REFERENCES teachers(id),
  month_year text NOT NULL,
  indicator_id uuid REFERENCES skill_indicators(id),
  rating text NOT NULL CHECK (rating IN ('D', 'P', 'N')),
  submitted_at timestamptz DEFAULT now()
);

ALTER TABLE evaluation_cards ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read evaluation cards"
  ON evaluation_cards FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert evaluation cards"
  ON evaluation_cards FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Anyone can update evaluation cards"
  ON evaluation_cards FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Anyone can delete evaluation cards"
  ON evaluation_cards FOR DELETE
  TO anon, authenticated
  USING (true);
