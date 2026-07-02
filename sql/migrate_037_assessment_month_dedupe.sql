-- ============================================================================
-- migrate_037_assessment_month_dedupe.sql
--
-- assess.php used to save per (student, TEACHER, month): if an admin (or a
-- newly assigned teacher) re-entered a month another teacher had already
-- assessed, a second full set of rows appeared under the new teacher_id and
-- the progress page showed whichever duplicate the DB returned last.
--
-- assess.php is now month-scoped (one set of rows per student+month). This
-- repairs history: wherever a (student, month) has rows from more than one
-- teacher, keep the most recently written set (the teacher owning the highest
-- row id) and drop the older sets.
--
-- Idempotent: after one pass every (student, month) has a single teacher's
-- rows, so re-running deletes nothing.
-- ============================================================================

SET NAMES utf8mb4;

DELETE ec FROM evaluation_cards ec
JOIN (
    SELECT e2.student_id, e2.month_year, MAX(e2.id) AS max_id
    FROM evaluation_cards e2
    GROUP BY e2.student_id, e2.month_year
) latest ON latest.student_id = ec.student_id AND latest.month_year = ec.month_year
JOIN evaluation_cards keeper ON keeper.id = latest.max_id
WHERE ec.teacher_id <> keeper.teacher_id;

DELETE a FROM assessments a
JOIN (
    SELECT a2.student_id, a2.month_year, MAX(a2.id) AS max_id
    FROM assessments a2
    GROUP BY a2.student_id, a2.month_year
) latest ON latest.student_id = a.student_id AND latest.month_year = a.month_year
JOIN assessments keeper ON keeper.id = latest.max_id
WHERE a.teacher_id <> keeper.teacher_id;

DELETE c FROM assessment_comments c
JOIN (
    SELECT c2.student_id, c2.month_year, MAX(c2.id) AS max_id
    FROM assessment_comments c2
    GROUP BY c2.student_id, c2.month_year
) latest ON latest.student_id = c.student_id AND latest.month_year = c.month_year
JOIN assessment_comments keeper ON keeper.id = latest.max_id
WHERE c.teacher_id <> keeper.teacher_id;
