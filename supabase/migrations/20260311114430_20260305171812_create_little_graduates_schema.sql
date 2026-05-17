
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
  score int NOT NULL,
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
  rating text NOT NULL,
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
