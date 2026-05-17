/*
  # Wipe All Skill Indicators and Assessment Data

  ## Purpose
  Clears all existing questionnaire/indicator data and all student assessment records.
  This leaves a clean slate so new indicators can be added per grade from the curriculum documents.

  ## Deleted Data
  1. `evaluation_cards` - All per-indicator ratings for all students
  2. `assessments` - All monthly category scores for all students
  3. `assessment_comments` - All teacher notes/comments
  4. `student_custom_indicators` - All custom indicators added per student
  5. `skill_indicators` - All questionnaire questions/indicators for all grades

  ## Notes
  - Students and teachers are NOT affected
  - Deletion order respects foreign key relationships
*/

DELETE FROM evaluation_cards;

DELETE FROM assessments;

DELETE FROM assessment_comments;

DELETE FROM student_custom_indicators;

DELETE FROM skill_indicators;
