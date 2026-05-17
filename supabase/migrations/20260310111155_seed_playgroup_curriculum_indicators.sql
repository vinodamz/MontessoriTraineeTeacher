/*
  # Seed Playgroup Curriculum Indicators (Level 0)

  ## Overview
  Inserts skill indicators for the Playgroup grade based on the curriculum chart Level 0.

  ## New Data

  ### Grade: Playgroup

  #### Categories and Indicators:

  1. **PRE-WRITING** (3 indicators)
     - Art and craft
     - Tracing and drawing
     - Colouring

  2. **COMMUNICATION** (5 indicators)
     - Singing rhymes
     - Talking about a picture
     - Identifying number 0-10
     - Listening to stories
     - Recognizing sounds of letters

  3. **VOCABULARY** (15 indicators)
     - Naming objects around the house
     - Naming food we eat
     - Naming animals and birds
     - Naming vehicles
     - Naming plants and trees
     - Naming tools
     - Naming sports and games
     - Naming fruits and vegetables
     - Naming professions
     - Naming festivals, celebrations and place of worship
     - Naming things and places around us
     - Naming musical instruments
     - Naming dance forms
     - Naming seasons and things in nature

  ## Security
  - No RLS changes needed; existing policies cover this table
*/

INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES

  -- PRE-WRITING
  ('Playgroup', 'PRE-WRITING', 'Art and craft', 1, true),
  ('Playgroup', 'PRE-WRITING', 'Tracing and drawing', 2, true),
  ('Playgroup', 'PRE-WRITING', 'Colouring', 3, true),

  -- COMMUNICATION
  ('Playgroup', 'COMMUNICATION', 'Singing rhymes', 1, true),
  ('Playgroup', 'COMMUNICATION', 'Talking about a picture', 2, true),
  ('Playgroup', 'COMMUNICATION', 'Identifying number 0-10', 3, true),
  ('Playgroup', 'COMMUNICATION', 'Listening to stories', 4, true),
  ('Playgroup', 'COMMUNICATION', 'Recognizing sounds of letters', 5, true),

  -- VOCABULARY
  ('Playgroup', 'VOCABULARY', 'Naming objects around the house', 1, true),
  ('Playgroup', 'VOCABULARY', 'Naming food we eat', 2, true),
  ('Playgroup', 'VOCABULARY', 'Naming animals and birds', 3, true),
  ('Playgroup', 'VOCABULARY', 'Naming vehicles', 4, true),
  ('Playgroup', 'VOCABULARY', 'Naming plants and trees', 5, true),
  ('Playgroup', 'VOCABULARY', 'Naming tools', 6, true),
  ('Playgroup', 'VOCABULARY', 'Naming sports and games', 7, true),
  ('Playgroup', 'VOCABULARY', 'Naming fruits and vegetables', 8, true),
  ('Playgroup', 'VOCABULARY', 'Naming professions', 9, true),
  ('Playgroup', 'VOCABULARY', 'Naming festivals, celebrations and place of worship', 10, true),
  ('Playgroup', 'VOCABULARY', 'Naming things and places around us', 11, true),
  ('Playgroup', 'VOCABULARY', 'Naming musical instruments', 12, true),
  ('Playgroup', 'VOCABULARY', 'Naming dance forms', 13, true),
  ('Playgroup', 'VOCABULARY', 'Naming seasons and things in nature', 14, true);
