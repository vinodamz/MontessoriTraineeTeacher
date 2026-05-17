
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'skill_indicators' AND column_name = 'is_active'
  ) THEN
    ALTER TABLE skill_indicators ADD COLUMN is_active boolean NOT NULL DEFAULT true;
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS student_custom_indicators (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id uuid REFERENCES students(id),
  teacher_id uuid REFERENCES teachers(id),
  category text NOT NULL,
  indicator_text text NOT NULL,
  display_order int NOT NULL DEFAULT 0,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz DEFAULT now()
);

ALTER TABLE student_custom_indicators ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read student custom indicators"
  ON student_custom_indicators FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert student custom indicators"
  ON student_custom_indicators FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Anyone can update student custom indicators"
  ON student_custom_indicators FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Anyone can delete student custom indicators"
  ON student_custom_indicators FOR DELETE
  TO anon, authenticated
  USING (true);
