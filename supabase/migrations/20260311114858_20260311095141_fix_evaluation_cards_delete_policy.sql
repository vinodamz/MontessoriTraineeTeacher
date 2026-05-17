
DROP POLICY IF EXISTS "Teachers can delete their own evaluation cards" ON evaluation_cards;

CREATE POLICY "Teachers can delete their own evaluation cards"
  ON evaluation_cards
  FOR DELETE
  USING (true);
