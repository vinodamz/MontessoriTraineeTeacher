/*
  # Seed Skill Indicators

  ## Overview
  Inserts all developmental skill indicators for all 4 grades across 6 categories.

  ## Grades Covered
  - Playgroup: 18 indicators
  - Nursery: 18 indicators
  - LKG: 12 indicators
  - UKG: 12 indicators

  ## Categories
  Gross Motor, Fine Motor, Literacy, Numeracy, Social Skills, Communication
*/

INSERT INTO skill_indicators (grade, category, indicator_text, display_order) VALUES
  ('Playgroup', 'Gross Motor', 'Walks independently', 1),
  ('Playgroup', 'Gross Motor', 'Kicks ball forward', 2),
  ('Playgroup', 'Gross Motor', 'Climbs low steps', 3),
  ('Playgroup', 'Gross Motor', 'Attempts jumping with both feet', 4),

  ('Playgroup', 'Fine Motor', 'Scribbles with crayon', 1),
  ('Playgroup', 'Fine Motor', 'Stacks 3–5 blocks', 2),
  ('Playgroup', 'Fine Motor', 'Turns pages of board book', 3),

  ('Playgroup', 'Literacy', 'Responds to name', 1),
  ('Playgroup', 'Literacy', 'Identifies familiar objects', 2),
  ('Playgroup', 'Literacy', 'Points to body parts', 3),

  ('Playgroup', 'Numeracy', 'Identifies basic colours', 1),
  ('Playgroup', 'Numeracy', 'Understands big vs small', 2),
  ('Playgroup', 'Numeracy', 'Matches similar objects', 3),

  ('Playgroup', 'Social Skills', 'Engages in parallel play', 1),
  ('Playgroup', 'Social Skills', 'Attempts sharing', 2),
  ('Playgroup', 'Social Skills', 'Participates in group activities', 3),

  ('Playgroup', 'Communication', 'Uses 1–3 word phrases', 1),
  ('Playgroup', 'Communication', 'Follows simple instructions', 2),
  ('Playgroup', 'Communication', 'Imitates words', 3),

  ('Nursery', 'Gross Motor', 'Walks in a line', 1),
  ('Nursery', 'Gross Motor', 'Jumps with both feet', 2),
  ('Nursery', 'Gross Motor', 'Balances briefly on one foot', 3),

  ('Nursery', 'Fine Motor', 'Scribbles with intent', 1),
  ('Nursery', 'Fine Motor', 'Holds crayon properly', 2),
  ('Nursery', 'Fine Motor', 'Stacks blocks (5+)', 3),

  ('Nursery', 'Literacy', 'Recognises own name in print', 1),
  ('Nursery', 'Literacy', 'Identifies few letters (5–10)', 2),
  ('Nursery', 'Literacy', 'Listens to stories attentively', 3),

  ('Nursery', 'Numeracy', 'Counts till 5', 1),
  ('Nursery', 'Numeracy', 'Recognises numbers 1–5', 2),
  ('Nursery', 'Numeracy', 'Sorts by attribute', 3),

  ('Nursery', 'Social Skills', 'Shares toys with peers', 1),
  ('Nursery', 'Social Skills', 'Responds to name', 2),
  ('Nursery', 'Social Skills', 'Follows classroom routines', 3),

  ('Nursery', 'Communication', 'Speaks 2–3 word sentences', 1),
  ('Nursery', 'Communication', 'Follows simple instructions', 2),
  ('Nursery', 'Communication', 'Expresses needs verbally', 3),

  ('LKG', 'Gross Motor', 'Hops on one foot', 1),
  ('LKG', 'Gross Motor', 'Climbs stairs alternately', 2),

  ('LKG', 'Fine Motor', 'Cuts with scissors', 1),
  ('LKG', 'Fine Motor', 'Traces shapes', 2),

  ('LKG', 'Literacy', 'Recognises A–Z', 1),
  ('LKG', 'Literacy', 'Identifies beginning sounds', 2),
  ('LKG', 'Literacy', 'Writes few letters', 3),

  ('LKG', 'Numeracy', 'Counts till 20', 1),
  ('LKG', 'Numeracy', 'Matches quantities', 2),
  ('LKG', 'Numeracy', 'Identifies shapes', 3),

  ('LKG', 'Social Skills', 'Takes turns', 1),
  ('LKG', 'Social Skills', 'Works in a group', 2),

  ('LKG', 'Communication', 'Speaks in full sentences', 1),
  ('LKG', 'Communication', 'Answers simple questions', 2),

  ('UKG', 'Gross Motor', 'Skips', 1),
  ('UKG', 'Gross Motor', 'Throws and catches a ball', 2),

  ('UKG', 'Fine Motor', 'Writes name clearly', 1),
  ('UKG', 'Fine Motor', 'Colours within lines', 2),

  ('UKG', 'Literacy', 'Reads simple words', 1),
  ('UKG', 'Literacy', 'Forms simple sentences', 2),
  ('UKG', 'Literacy', 'Identifies sight words', 3),

  ('UKG', 'Numeracy', 'Counts till 50', 1),
  ('UKG', 'Numeracy', 'Understands single-digit addition', 2),
  ('UKG', 'Numeracy', 'Identifies patterns', 3),

  ('UKG', 'Social Skills', 'Follows classroom rules', 1),
  ('UKG', 'Social Skills', 'Shows leadership qualities', 2),

  ('UKG', 'Communication', 'Narrates a short story', 1),
  ('UKG', 'Communication', 'Expresses needs clearly', 2);
