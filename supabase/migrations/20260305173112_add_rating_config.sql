/*
  # Add Rating Configuration Table

  ## Overview
  Adds a configurable rating system to replace the hardcoded D/P/N options.
  Admin can define any number of rating options with custom labels, colors, and
  numeric equivalents used for progress charts.

  ## New Table

  ### rating_config
  - `id` — primary key
  - `code` — short identifier shown on buttons (e.g., "D", "P", "N")
  - `label` — full descriptive label (e.g., "Demonstrated")
  - `color` — hex color for button styling
  - `numeric_value` — numeric equivalent used for progress chart averages (1–5 scale)
  - `display_order` — controls order of buttons
  - `is_active` — toggle to enable/disable a rating option

  ## Default Seed Data
  D (Demonstrated, green, 5), P (Progressing, amber, 3), N (Not Yet Acquired, red, 1)

  ## Security
  - RLS enabled
  - Anyone can read (for assessment forms)
  - Only insert/update/delete via anon/authenticated (admin controls)
*/

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
