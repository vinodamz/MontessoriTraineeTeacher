/*
  # Seed Nursery Curriculum Indicators (Level 1)

  ## Overview
  Inserts skill indicators for the Nursery grade based on the curriculum chart Level 1.

  ## New Data

  ### Grade: Nursery

  #### Categories and Indicators:

  1. **SENSORIAL** (5 indicators)
     - Identifying objects
     - Identifying colours
     - Identifying shapes
     - Identifying sizes
     - Identifying simple patterns

  2. **MATHEMATICS** (7 indicators)
     - Identifying more and less
     - Tracing and writing number symbols 0-9
     - Counting 1-10
     - Sequencing after, in between and before numbers 1-10
     - Ordering and comparing 1-10
     - Tracing and writing number symbols 11-20
     - Counting 11-20

  3. **ENGLISH** (11 indicators)
     - Naming of common things and talking about pictures
     - Singing rhymes and listening to stories
     - Drawing and art and craft
     - Tracing curves and lines
     - Tracing, identifying and writing Set 1 sounds - c, a, p, t, i, n
     - Tracing, identifying and writing Set 2 sounds - b, o, g, m, u, s
     - Tracing, identifying and writing Set 3 sounds - d, l, h, e, f
     - Tracing, identifying and writing Set 4 sounds - v, k, z, w
     - Tracing, identifying and writing Set 5 sounds - y, j, x, q
     - Identifying first/ending/containing sounds in words
     - Recognizing and naming A-Z

  4. **EVS** (8 indicators)
     - Naming parts of the body and activities done with them
     - Naming of clothes and accessories and identifying where they are worn
     - Naming things used everyday and areas of the house
     - Naming fruits and vegetables
     - Naming vehicles and identifying where they move
     - Identifying traffic signals and signs and stations and ports
     - Naming domestic and wild animals
     - Naming birds and water animals

  ## Security
  - No RLS changes needed; existing policies cover this table
*/

INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active) VALUES

  -- SENSORIAL
  ('Nursery', 'SENSORIAL', 'Identifying objects', 1, true),
  ('Nursery', 'SENSORIAL', 'Identifying colours', 2, true),
  ('Nursery', 'SENSORIAL', 'Identifying shapes', 3, true),
  ('Nursery', 'SENSORIAL', 'Identifying sizes', 4, true),
  ('Nursery', 'SENSORIAL', 'Identifying simple patterns', 5, true),

  -- MATHEMATICS
  ('Nursery', 'MATHEMATICS', 'Identifying more and less', 1, true),
  ('Nursery', 'MATHEMATICS', 'Tracing and writing number symbols 0-9', 2, true),
  ('Nursery', 'MATHEMATICS', 'Counting 1-10', 3, true),
  ('Nursery', 'MATHEMATICS', 'Sequencing after, in between and before numbers 1-10', 4, true),
  ('Nursery', 'MATHEMATICS', 'Ordering and comparing 1-10', 5, true),
  ('Nursery', 'MATHEMATICS', 'Tracing and writing number symbols 11-20', 6, true),
  ('Nursery', 'MATHEMATICS', 'Counting 11-20', 7, true),

  -- ENGLISH
  ('Nursery', 'ENGLISH', 'Naming of common things and talking about pictures', 1, true),
  ('Nursery', 'ENGLISH', 'Singing rhymes and listening to stories', 2, true),
  ('Nursery', 'ENGLISH', 'Drawing and art and craft', 3, true),
  ('Nursery', 'ENGLISH', 'Tracing curves and lines', 4, true),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 1 sounds - c, a, p, t, i, n', 5, true),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 2 sounds - b, o, g, m, u, s', 6, true),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 3 sounds - d, l, h, e, f', 7, true),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 4 sounds - v, k, z, w', 8, true),
  ('Nursery', 'ENGLISH', 'Tracing, identifying and writing Set 5 sounds - y, j, x, q', 9, true),
  ('Nursery', 'ENGLISH', 'Identifying first/ending/containing sounds in words', 10, true),
  ('Nursery', 'ENGLISH', 'Recognizing and naming A-Z', 11, true),

  -- EVS
  ('Nursery', 'EVS', 'Naming parts of the body and activities done with them', 1, true),
  ('Nursery', 'EVS', 'Naming of clothes and accessories and identifying where they are worn', 2, true),
  ('Nursery', 'EVS', 'Naming things used everyday and areas of the house', 3, true),
  ('Nursery', 'EVS', 'Naming fruits and vegetables', 4, true),
  ('Nursery', 'EVS', 'Naming vehicles and identifying where they move', 5, true),
  ('Nursery', 'EVS', 'Identifying traffic signals and signs and stations and ports', 6, true),
  ('Nursery', 'EVS', 'Naming domestic and wild animals', 7, true),
  ('Nursery', 'EVS', 'Naming birds and water animals', 8, true);
