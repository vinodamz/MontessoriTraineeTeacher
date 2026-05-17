/*
  # Seed LKG Curriculum Indicators (Level 2)

  ## Overview
  Inserts skill indicators for the LKG grade based on the curriculum chart Level 2.

  ## New Data

  ### Grade: LKG

  #### Categories and Indicators:

  1. **SENSORIAL** (6 indicators)
     - Identifying objects
     - Naming colour and colour shades
     - Naming 2D & 3D shapes
     - Naming and comparing dimensions
     - Identifying and completing patterns
     - Naming directions

  2. **MATHEMATICS** (13 indicators)
     - Identifying more than/less than, most/least and equal to
     - Counting and writing numbers 1-100
     - Sequencing after, before and in between numbers
     - Counting backwards 20-1
     - Ordering and comparing numbers
     - Identifying ordinal position
     - Writing number names 1-10
     - Identifying place value as unit, ten, hundred, thousand
     - Identifying the place value of a number
     - Splitting and constructing numbers as per place value
     - Adding single digit and double digit numbers without carryover
     - Skip counting numbers
     - Reading three digit numbers

  3. **ENGLISH** (14 indicators)
     - Naming common things and describing pictures
     - Singing rhymes and narrating stories
     - Identifying and writing all letters in smaller and uppercase
     - Identifying all letter sounds and names a-z
     - Identifying sounds in words
     - Reading and writing 3 letter words
     - Reading sentences with 3 letter words
     - Reading and writing blend words
     - Reading and writing sight words
     - Reading and writing words with consonant phonograms
     - Reading and writing words with homophones
     - Reading sentences with blend and consonant phonogram words
     - Identifying long and short vowel sounds
     - Reading words with long sounds

  ## Security
  - No RLS changes needed; existing policies cover this table
*/

INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES

  -- SENSORIAL
  ('LKG', 'SENSORIAL', 'Identifying objects', 1, true),
  ('LKG', 'SENSORIAL', 'Naming colour and colour shades', 2, true),
  ('LKG', 'SENSORIAL', 'Naming 2D & 3D shapes', 3, true),
  ('LKG', 'SENSORIAL', 'Naming and comparing dimensions', 4, true),
  ('LKG', 'SENSORIAL', 'Identifying and completing patterns', 5, true),
  ('LKG', 'SENSORIAL', 'Naming directions', 6, true),

  -- MATHEMATICS
  ('LKG', 'MATHEMATICS', 'Identifying more than/less than, most/least and equal to', 1, true),
  ('LKG', 'MATHEMATICS', 'Counting and writing numbers 1-100', 2, true),
  ('LKG', 'MATHEMATICS', 'Sequencing after, before and in between numbers', 3, true),
  ('LKG', 'MATHEMATICS', 'Counting backwards 20-1', 4, true),
  ('LKG', 'MATHEMATICS', 'Ordering and comparing numbers', 5, true),
  ('LKG', 'MATHEMATICS', 'Identifying ordinal position', 6, true),
  ('LKG', 'MATHEMATICS', 'Writing number names 1-10', 7, true),
  ('LKG', 'MATHEMATICS', 'Identifying place value as unit, ten, hundred, thousand', 8, true),
  ('LKG', 'MATHEMATICS', 'Identifying the place value of a number', 9, true),
  ('LKG', 'MATHEMATICS', 'Splitting and constructing numbers as per place value', 10, true),
  ('LKG', 'MATHEMATICS', 'Adding single digit and double digit numbers without carryover', 11, true),
  ('LKG', 'MATHEMATICS', 'Skip counting numbers', 12, true),
  ('LKG', 'MATHEMATICS', 'Reading three digit numbers', 13, true),

  -- ENGLISH
  ('LKG', 'ENGLISH', 'Naming common things and describing pictures', 1, true),
  ('LKG', 'ENGLISH', 'Singing rhymes and narrating stories', 2, true),
  ('LKG', 'ENGLISH', 'Identifying and writing all letters in smaller and uppercase', 3, true),
  ('LKG', 'ENGLISH', 'Identifying all letter sounds and names a-z', 4, true),
  ('LKG', 'ENGLISH', 'Identifying sounds in words', 5, true),
  ('LKG', 'ENGLISH', 'Reading and writing 3 letter words', 6, true),
  ('LKG', 'ENGLISH', 'Reading sentences with 3 letter words', 7, true),
  ('LKG', 'ENGLISH', 'Reading and writing blend words', 8, true),
  ('LKG', 'ENGLISH', 'Reading and writing sight words', 9, true),
  ('LKG', 'ENGLISH', 'Reading and writing words with consonant phonograms', 10, true),
  ('LKG', 'ENGLISH', 'Reading and writing words with homophones', 11, true),
  ('LKG', 'ENGLISH', 'Reading sentences with blend and consonant phonogram words', 12, true),
  ('LKG', 'ENGLISH', 'Identifying long and short vowel sounds', 13, true),
  ('LKG', 'ENGLISH', 'Reading words with long sounds', 14, true);
