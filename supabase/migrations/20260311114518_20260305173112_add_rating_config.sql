
CREATE TABLE IF NOT EXISTS rating_config (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL,
  label text NOT NULL,
  color text NOT NULL DEFAULT '#10b981',
  numeric_value int NOT NULL DEFAULT 3 CHECK (numeric_value >= 1 AND numeric_value <= 5),
  display_order int NOT NULL DEFAULT 0,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz DEFAULT now()
);

ALTER TABLE rating_config ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anyone can read rating config"
  ON rating_config FOR SELECT
  TO anon, authenticated
  USING (true);

CREATE POLICY "Anyone can insert rating config"
  ON rating_config FOR INSERT
  TO anon, authenticated
  WITH CHECK (true);

CREATE POLICY "Anyone can update rating config"
  ON rating_config FOR UPDATE
  TO anon, authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Anyone can delete rating config"
  ON rating_config FOR DELETE
  TO anon, authenticated
  USING (true);

INSERT INTO rating_config (code, label, color, numeric_value, display_order, is_active) VALUES
  ('D', 'Demonstrated', '#10b981', 5, 1, true),
  ('P', 'Progressing', '#f59e0b', 3, 2, true),
  ('N', 'Not Yet Acquired', '#ef4444', 1, 3, true);
