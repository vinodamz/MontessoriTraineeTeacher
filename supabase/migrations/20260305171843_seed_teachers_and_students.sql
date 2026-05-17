/*
  # Seed Teachers and Students

  ## Overview
  Inserts the 5 teacher accounts and all 40 students with their grade and teacher assignments.

  ## Teachers Seeded
  - Ann Mary (PIN: 1111, role: teacher)
  - Deepa (PIN: 2222, role: teacher)
  - Mansu (PIN: 3333, role: teacher)
  - Aysha (PIN: 4444, role: teacher)
  - Admin (PIN: 0000, role: admin)

  ## Students Seeded
  - Ann Mary's Nursery class: 11 students
  - Deepa's Playgroup class: 10 students
  - Mansu's Playgroup class: 10 students
  - Aysha's mixed class: 9 students (LKG, UKG, Playgroup)
*/

INSERT INTO teachers (id, name, pin, role) VALUES
  ('11111111-1111-1111-1111-111111111111', 'Ann Mary', '1111', 'teacher'),
  ('22222222-2222-2222-2222-222222222222', 'Deepa', '2222', 'teacher'),
  ('33333333-3333-3333-3333-333333333333', 'Mansu', '3333', 'teacher'),
  ('44444444-4444-4444-4444-444444444444', 'Aysha', '4444', 'teacher'),
  ('00000000-0000-0000-0000-000000000000', 'Admin', '0000', 'admin')
ON CONFLICT (id) DO NOTHING;

INSERT INTO students (first_name, last_name, grade, teacher_id) VALUES
  ('Adhvik', 'Krishnan', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Gautham P', 'Prabhu', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Yara K', 'Sanish', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Nizwa', 'Mariyam', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Yaakov Stan', 'John', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Naif', 'Nazeer', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Sidhviik Dev', 'S', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Jaiden', 'Kynady', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Nalda Naba', 'Niyas Muhammed', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Thanvi', 'Puneeth', 'Nursery', '11111111-1111-1111-1111-111111111111'),
  ('Druvika', 'Binil', 'Nursery', '11111111-1111-1111-1111-111111111111'),

  ('Adil Nasir', 'Rakhangi', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Adhraihn', 'Thraiv', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Auden', 'Dan', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Joshua', 'Lincoln', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Mikhara', 'Unni', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Devansh', 'Midhun', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Druva Shaurya', 'S J', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('M Nathaniel', 'John', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Zidan', 'Anees', 'Playgroup', '22222222-2222-2222-2222-222222222222'),
  ('Daneen Daiba', 'Niyas Muhammed', 'Playgroup', '22222222-2222-2222-2222-222222222222'),

  ('Nayanika', 'Ashwin', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Zara Miriam', 'Vakayil', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Natasha', 'Tony', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Rayan Chacko', 'Ashish', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Zaren Tintu', 'Abraham', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Joseph', 'Thomas', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Mishel', 'Carduz', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Advik', 'Suresh', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Joshua', 'Joy', 'Playgroup', '33333333-3333-3333-3333-333333333333'),
  ('Kavin', 'Prabhu', 'Playgroup', '33333333-3333-3333-3333-333333333333'),

  ('Alankrita', 'Abhinav', 'LKG', '44444444-4444-4444-4444-444444444444'),
  ('Parveen', 'Sanish', 'UKG', '44444444-4444-4444-4444-444444444444'),
  ('Rudradev', 'Ribin', 'UKG', '44444444-4444-4444-4444-444444444444'),
  ('Rayan', 'Roy', 'LKG', '44444444-4444-4444-4444-444444444444'),
  ('Ezlin', 'Aji', 'LKG', '44444444-4444-4444-4444-444444444444'),
  ('Airin', 'Millath', 'Playgroup', '44444444-4444-4444-4444-444444444444'),
  ('Ezza', 'Haneen', 'Playgroup', '44444444-4444-4444-4444-444444444444'),
  ('Druv K', 'Nikhil', 'Playgroup', '44444444-4444-4444-4444-444444444444'),
  ('Vidhyud', 'Rakesh', 'Playgroup', '44444444-4444-4444-4444-444444444444');
