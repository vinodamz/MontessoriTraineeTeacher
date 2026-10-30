-- Observation recording is optional for each student; existing records are retained.
CREATE TABLE IF NOT EXISTS student_observation_preferences (
 student_id INT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
