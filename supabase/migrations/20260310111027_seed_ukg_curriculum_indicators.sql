/*
  # Seed UKG Curriculum Indicators (Level 3)

  ## Overview
  Inserts skill indicators for the UKG grade based on the curriculum chart Level 3.

  ## New Data

  ### Grade: UKG

  #### Categories and Indicators:

  1. **SENSORIAL** (6 indicators)
     - Identifying objects
     - Naming, reading and writing names of colours and shapes
     - Classifying and discussing properties of 2D and 3D shapes
     - Identifying odd one out and what comes next
     - Identifying mirror image and what is missing
     - Naming, comparing and reading and writing dimensions

  2. **MATHEMATICS** (17 indicators)
     - Identifying, comparing and writing quantities
     - Counting and writing numbers 1-1000
     - Sequencing after, before and in between numbers 1-1000
     - Counting backwards 100-1
     - Ordering and comparing numbers and writing its ordinal position
     - Identifying and writing place value unit, ten, hundred, thousand of a number
     - Splitting and/or constructing numbers as per place value
     - Reciting, reading and writing number names 1-100
     - Adding numbers without and with carryover for single and double digit
     - Adding three digit numbers without carryover
     - Skip counting numbers in 2s, 3s, 5s, 10s
     - Identifying greater than, lesser than
     - Subtracting single and double digit numbers without borrowing
     - Subtracting with borrowing for double digit numbers with quantities
     - Identifying and telling simple time by o'clock
     - Solving simple math word problems

  3. **ENGLISH** (12 indicators)
     - Naming and describing pictures and scenes
     - Reciting rhymes and stories
     - Naming and writing all letter sounds and names a-z in upper case/smaller case
     - Reading and writing CVC words and sentences
     - Reading and writing blend words and sentences
     - Reading and writing sight words and rhyming words
     - Identifying short and long vowel sounds in words
     - Reading and writing words with long vowel sounds
     - Reading and writing words with spelling complexities - r, l and oo sounds
     - Writing answers to simple questions
     - Introduction to nouns and verbs

  ## Security
  - No RLS changes needed; existing policies cover this table
*/

INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES

  -- SENSORIAL
  ('UKG', 'SENSORIAL', 'Identifying objects', 1, true),
  ('UKG', 'SENSORIAL', 'Naming, reading and writing names of colours and shapes', 2, true),
  ('UKG', 'SENSORIAL', 'Classifying and discussing properties of 2D and 3D shapes', 3, true),
  ('UKG', 'SENSORIAL', 'Identifying odd one out and what comes next', 4, true),
  ('UKG', 'SENSORIAL', 'Identifying mirror image and what is missing', 5, true),
  ('UKG', 'SENSORIAL', 'Naming, comparing and reading and writing dimensions', 6, true),

  -- MATHEMATICS
  ('UKG', 'MATHEMATICS', 'Identifying, comparing and writing quantities', 1, true),
  ('UKG', 'MATHEMATICS', 'Counting and writing numbers 1-1000', 2, true),
  ('UKG', 'MATHEMATICS', 'Sequencing after, before and in between numbers 1-1000', 3, true),
  ('UKG', 'MATHEMATICS', 'Counting backwards 100-1', 4, true),
  ('UKG', 'MATHEMATICS', 'Ordering and comparing numbers and writing its ordinal position', 5, true),
  ('UKG', 'MATHEMATICS', 'Identifying and writing place value unit, ten, hundred, thousand of a number', 6, true),
  ('UKG', 'MATHEMATICS', 'Splitting and/or constructing numbers as per place value', 7, true),
  ('UKG', 'MATHEMATICS', 'Reciting, reading and writing number names 1-100', 8, true),
  ('UKG', 'MATHEMATICS', 'Adding numbers without and with carryover for single and double digit', 9, true),
  ('UKG', 'MATHEMATICS', 'Adding three digit numbers without carryover', 10, true),
  ('UKG', 'MATHEMATICS', 'Skip counting numbers in 2s, 3s, 5s, 10s', 11, true),
  ('UKG', 'MATHEMATICS', 'Identifying greater than, lesser than', 12, true),
  ('UKG', 'MATHEMATICS', 'Subtracting single and double digit numbers without borrowing', 13, true),
  ('UKG', 'MATHEMATICS', 'Subtracting with borrowing for double digit numbers with quantities', 14, true),
  ('UKG', 'MATHEMATICS', 'Identifying and telling simple time by o''clock', 15, true),
  ('UKG', 'MATHEMATICS', 'Solving simple math word problems', 16, true),

  -- ENGLISH
  ('UKG', 'ENGLISH', 'Naming and describing pictures and scenes', 1, true),
  ('UKG', 'ENGLISH', 'Reciting rhymes and stories', 2, true),
  ('UKG', 'ENGLISH', 'Naming and writing all letter sounds and names a-z in upper case/smaller case', 3, true),
  ('UKG', 'ENGLISH', 'Reading and writing CVC words and sentences', 4, true),
  ('UKG', 'ENGLISH', 'Reading and writing blend words and sentences', 5, true),
  ('UKG', 'ENGLISH', 'Reading and writing sight words and rhyming words', 6, true),
  ('UKG', 'ENGLISH', 'Identifying short and long vowel sounds in words', 7, true),
  ('UKG', 'ENGLISH', 'Reading and writing words with long vowel sounds', 8, true),
  ('UKG', 'ENGLISH', 'Reading and writing words with spelling complexities - r, l and oo sounds', 9, true),
  ('UKG', 'ENGLISH', 'Writing answers to simple questions', 10, true),
  ('UKG', 'ENGLISH', 'Introduction to nouns and verbs', 11, true);
