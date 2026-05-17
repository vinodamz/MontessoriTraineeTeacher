
CREATE TABLE IF NOT EXISTS student_baselines (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id uuid NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  teacher_id uuid NOT NULL REFERENCES teachers(id),
  recorded_by text NOT NULL DEFAULT '',
  gross_motor text NOT NULL DEFAULT '',
  fine_motor text NOT NULL DEFAULT '',
  literacy text NOT NULL DEFAULT '',
  numeracy text NOT NULL DEFAULT '',
  social_skills text NOT NULL DEFAULT '',
  communication text NOT NULL DEFAULT '',
  overall_notes text NOT NULL DEFAULT '',
  recorded_at date NOT NULL DEFAULT CURRENT_DATE,
  created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE student_baselines ENABLE ROW LEVEL SECURITY;

CREATE UNIQUE INDEX IF NOT EXISTS student_baselines_student_id_idx
  ON student_baselines (student_id);

CREATE POLICY "Anyone can read student baselines"
  ON student_baselines
  FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Teachers can insert student baselines"
  ON student_baselines
  FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Teachers can update student baselines"
  ON student_baselines
  FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Teachers can delete student baselines"
  ON student_baselines
  FOR DELETE
  TO anon, authenticated
  USING (true);
