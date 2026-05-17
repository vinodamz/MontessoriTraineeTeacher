-- ============================================================================
-- Seed data: rating scheme + curriculum indicators (PG / Nur / LKG / UKG).
-- Run after schema.sql on a fresh database.
-- Apply via phpMyAdmin → Import, or `mysql ... < seeds.sql`.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------- rating_config -----------------
INSERT INTO rating_config (code, label, color, numeric_value, is_active) VALUES
  ('D', 'Developed',        '#22c55e', 5, 1),
  ('P', 'Progressing',      '#f59e0b', 3, 1),
  ('N', 'Needs Attention',  '#ef4444', 1, 1);

-- ---------------- skill_indicators: Playgroup ----
INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES
  ('Playgroup', 'PRE-WRITING', 'Art and craft', 1, 1),
  ('Playgroup', 'PRE-WRITING', 'Tracing and drawing', 2, 1),
  ('Playgroup', 'PRE-WRITING', 'Colouring', 3, 1),

  ('Playgroup', 'COMMUNICATION', 'Singing rhymes', 1, 1),
  ('Playgroup', 'COMMUNICATION', 'Talking about a picture', 2, 1),
  ('Playgroup', 'COMMUNICATION', 'Identifying number 0-10', 3, 1),
  ('Playgroup', 'COMMUNICATION', 'Listening to stories', 4, 1),
  ('Playgroup', 'COMMUNICATION', 'Recognizing sounds of letters', 5, 1),

  ('Playgroup', 'VOCABULARY', 'Naming objects around the house', 1, 1),
  ('Playgroup', 'VOCABULARY', 'Naming food we eat', 2, 1),
  ('Playgroup', 'VOCABULARY', 'Naming animals and birds', 3, 1),
  ('Playgroup', 'VOCABULARY', 'Naming vehicles', 4, 1),
  ('Playgroup', 'VOCABULARY', 'Naming plants and trees', 5, 1),
  ('Playgroup', 'VOCABULARY', 'Naming tools', 6, 1),
  ('Playgroup', 'VOCABULARY', 'Naming sports and games', 7, 1),
  ('Playgroup', 'VOCABULARY', 'Naming fruits and vegetables', 8, 1),
  ('Playgroup', 'VOCABULARY', 'Naming professions', 9, 1),
  ('Playgroup', 'VOCABULARY', 'Naming festivals, celebrations and place of worship', 10, 1),
  ('Playgroup', 'VOCABULARY', 'Naming things and places around us', 11, 1),
  ('Playgroup', 'VOCABULARY', 'Naming musical instruments', 12, 1),
  ('Playgroup', 'VOCABULARY', 'Naming dance forms', 13, 1),
  ('Playgroup', 'VOCABULARY', 'Naming seasons and things in nature', 14, 1);

-- ---------------- skill_indicators: Nursery ----
INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES
  ('Nursery', 'SENSORIAL', 'Identifying objects', 1, 1),
  ('Nursery', 'SENSORIAL', 'Identifying colours', 2, 1),
  ('Nursery', 'SENSORIAL', 'Identifying shapes', 3, 1),
  ('Nursery', 'SENSORIAL', 'Identifying sizes', 4, 1),
  ('Nursery', 'SENSORIAL', 'Identifying simple patterns', 5, 1),

  ('Nursery', 'MATHEMATICS', 'Identifying more and less', 1, 1),
  ('Nursery', 'MATHEMATICS', 'Tracing and writing number symbols 0-9', 2, 1),
  ('Nursery', 'MATHEMATICS', 'Counting 1-10', 3, 1),
  ('Nursery', 'MATHEMATICS', 'Sequencing after, in between and before numbers 1-10', 4, 1),
  ('Nursery', 'MATHEMATICS', 'Ordering and comparing 1-10', 5, 1),
  ('Nursery', 'MATHEMATICS', 'Tracing and writing number symbols 11-20', 6, 1),
  ('Nursery', 'MATHEMATICS', 'Counting 11-20', 7, 1),

  ('Nursery', 'ENGLISH', 'Naming of common things and talking about pictures', 1, 1),
  ('Nursery', 'ENGLISH', 'Singing rhymes and listening to stories', 2, 1),
  ('Nursery', 'ENGLISH', 'Drawing and art and craft', 3, 1),
  ('Nursery', 'ENGLISH', 'Tracing curves and lines', 4, 1),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 1 sounds - c, a, p, t, i, n', 5, 1),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 2 sounds - b, o, g, m, u, s', 6, 1),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 3 sounds - d, l, h, e, f', 7, 1),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 4 sounds - v, k, z, w', 8, 1),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 5 sounds - y, j, x, q', 9, 1),
  ('Nursery', 'ENGLISH', 'Identifying first/ending/containing sounds in words', 10, 1),
  ('Nursery', 'ENGLISH', 'Recognizing and naming A-Z', 11, 1),

  ('Nursery', 'EVS', 'Naming parts of the body and activities done with them', 1, 1),
  ('Nursery', 'EVS', 'Naming of clothes and accessories and identifying where they are worn', 2, 1),
  ('Nursery', 'EVS', 'Naming things used everyday and areas of the house', 3, 1),
  ('Nursery', 'EVS', 'Naming fruits and vegetables', 4, 1),
  ('Nursery', 'EVS', 'Naming vehicles and identifying where they move', 5, 1),
  ('Nursery', 'EVS', 'Identifying traffic signals and signs and stations and ports', 6, 1),
  ('Nursery', 'EVS', 'Naming domestic and wild animals', 7, 1),
  ('Nursery', 'EVS', 'Naming birds and water animals', 8, 1);

-- ---------------- skill_indicators: LKG ----
INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES
  ('LKG', 'SENSORIAL', 'Identifying objects', 1, 1),
  ('LKG', 'SENSORIAL', 'Naming colour and colour shades', 2, 1),
  ('LKG', 'SENSORIAL', 'Naming 2D & 3D shapes', 3, 1),
  ('LKG', 'SENSORIAL', 'Naming and comparing dimensions', 4, 1),
  ('LKG', 'SENSORIAL', 'Identifying and completing patterns', 5, 1),
  ('LKG', 'SENSORIAL', 'Naming directions', 6, 1),

  ('LKG', 'MATHEMATICS', 'Identifying more than/less than, most/least and equal to', 1, 1),
  ('LKG', 'MATHEMATICS', 'Counting and writing numbers 1-100', 2, 1),
  ('LKG', 'MATHEMATICS', 'Sequencing after, before and in between numbers', 3, 1),
  ('LKG', 'MATHEMATICS', 'Counting backwards 20-1', 4, 1),
  ('LKG', 'MATHEMATICS', 'Ordering and comparing numbers', 5, 1),
  ('LKG', 'MATHEMATICS', 'Identifying ordinal position', 6, 1),
  ('LKG', 'MATHEMATICS', 'Writing number names 1-10', 7, 1),
  ('LKG', 'MATHEMATICS', 'Identifying place value as unit, ten, hundred, thousand', 8, 1),
  ('LKG', 'MATHEMATICS', 'Identifying the place value of a number', 9, 1),
  ('LKG', 'MATHEMATICS', 'Splitting and constructing numbers as per place value', 10, 1),
  ('LKG', 'MATHEMATICS', 'Adding single digit and double digit numbers without carryover', 11, 1),
  ('LKG', 'MATHEMATICS', 'Skip counting numbers', 12, 1),
  ('LKG', 'MATHEMATICS', 'Reading three digit numbers', 13, 1),

  ('LKG', 'ENGLISH', 'Naming common things and describing pictures', 1, 1),
  ('LKG', 'ENGLISH', 'Singing rhymes and narrating stories', 2, 1),
  ('LKG', 'ENGLISH', 'Identifying and writing all letters in smaller and uppercase', 3, 1),
  ('LKG', 'ENGLISH', 'Identifying all letter sounds and names a-z', 4, 1),
  ('LKG', 'ENGLISH', 'Identifying sounds in words', 5, 1),
  ('LKG', 'ENGLISH', 'Reading and writing 3 letter words', 6, 1),
  ('LKG', 'ENGLISH', 'Reading sentences with 3 letter words', 7, 1),
  ('LKG', 'ENGLISH', 'Reading and writing blend words', 8, 1),
  ('LKG', 'ENGLISH', 'Reading and writing sight words', 9, 1),
  ('LKG', 'ENGLISH', 'Reading and writing words with consonant phonograms', 10, 1),
  ('LKG', 'ENGLISH', 'Reading and writing words with homophones', 11, 1),
  ('LKG', 'ENGLISH', 'Reading sentences with blend and consonant phonogram words', 12, 1),
  ('LKG', 'ENGLISH', 'Identifying long and short vowel sounds', 13, 1),
  ('LKG', 'ENGLISH', 'Reading words with long sounds', 14, 1);

-- ---------------- skill_indicators: UKG ----
INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES
  ('UKG', 'SENSORIAL', 'Identifying objects', 1, 1),
  ('UKG', 'SENSORIAL', 'Naming, reading and writing names of colours and shapes', 2, 1),
  ('UKG', 'SENSORIAL', 'Classifying and discussing properties of 2D and 3D shapes', 3, 1),
  ('UKG', 'SENSORIAL', 'Identifying odd one out and what comes next', 4, 1),
  ('UKG', 'SENSORIAL', 'Identifying mirror image and what is missing', 5, 1),
  ('UKG', 'SENSORIAL', 'Naming, comparing and reading and writing dimensions', 6, 1),

  ('UKG', 'MATHEMATICS', 'Identifying, comparing and writing quantities', 1, 1),
  ('UKG', 'MATHEMATICS', 'Counting and writing numbers 1-1000', 2, 1),
  ('UKG', 'MATHEMATICS', 'Sequencing after, before and in between numbers 1-1000', 3, 1),
  ('UKG', 'MATHEMATICS', 'Counting backwards 100-1', 4, 1),
  ('UKG', 'MATHEMATICS', 'Ordering and comparing numbers and writing its ordinal position', 5, 1),
  ('UKG', 'MATHEMATICS', 'Identifying and writing place value unit, ten, hundred, thousand of a number', 6, 1),
  ('UKG', 'MATHEMATICS', 'Splitting and/or constructing numbers as per place value', 7, 1),
  ('UKG', 'MATHEMATICS', 'Reciting, reading and writing number names 1-100', 8, 1),
  ('UKG', 'MATHEMATICS', 'Adding numbers without and with carryover for single and double digit', 9, 1),
  ('UKG', 'MATHEMATICS', 'Adding three digit numbers without carryover', 10, 1),
  ('UKG', 'MATHEMATICS', 'Skip counting numbers in 2s, 3s, 5s, 10s', 11, 1),
  ('UKG', 'MATHEMATICS', 'Identifying greater than, lesser than', 12, 1),
  ('UKG', 'MATHEMATICS', 'Subtracting single and double digit numbers without borrowing', 13, 1),
  ('UKG', 'MATHEMATICS', 'Subtracting with borrowing for double digit numbers with quantities', 14, 1),
  ('UKG', 'MATHEMATICS', 'Identifying and telling simple time by o''clock', 15, 1),
  ('UKG', 'MATHEMATICS', 'Solving simple math word problems', 16, 1),

  ('UKG', 'ENGLISH', 'Naming and describing pictures and scenes', 1, 1),
  ('UKG', 'ENGLISH', 'Reciting rhymes and stories', 2, 1),
  ('UKG', 'ENGLISH', 'Naming and writing all letter sounds and names a-z in upper case/smaller case', 3, 1),
  ('UKG', 'ENGLISH', 'Reading and writing CVC words and sentences', 4, 1),
  ('UKG', 'ENGLISH', 'Reading and writing blend words and sentences', 5, 1),
  ('UKG', 'ENGLISH', 'Reading and writing sight words and rhyming words', 6, 1),
  ('UKG', 'ENGLISH', 'Identifying short and long vowel sounds in words', 7, 1),
  ('UKG', 'ENGLISH', 'Reading and writing words with long vowel sounds', 8, 1),
  ('UKG', 'ENGLISH', 'Reading and writing words with spelling complexities - r, l and oo sounds', 9, 1),
  ('UKG', 'ENGLISH', 'Writing answers to simple questions', 10, 1),
  ('UKG', 'ENGLISH', 'Introduction to nouns and verbs', 11, 1);
