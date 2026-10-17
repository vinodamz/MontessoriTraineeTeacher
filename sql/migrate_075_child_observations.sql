-- Additive, append-only observation history; safe to run repeatedly.
CREATE TABLE IF NOT EXISTS child_observations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id INT UNSIGNED NOT NULL,
 author_id INT UNSIGNED NOT NULL,
 author_name VARCHAR(255) NOT NULL,
 grade VARCHAR(100) NOT NULL,
 observed_on DATE NOT NULL,
 submission_token CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_observation_token (submission_token),
 KEY idx_observation_child_date (student_id, observed_on, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS child_observation_skills (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 observation_id INT UNSIGNED NOT NULL,
 skill_key VARCHAR(40) NOT NULL,
 category VARCHAR(255) NOT NULL,
 skill_text TEXT NOT NULL,
 rating_code VARCHAR(40) NOT NULL,
 rating_label VARCHAR(255) NOT NULL,
 numeric_value DECIMAL(10,2) NOT NULL,
 scale_snapshot TEXT NOT NULL,
 evidence TEXT NOT NULL,
 next_steps TEXT NOT NULL,
 UNIQUE KEY uq_observation_skill (observation_id, skill_key),
 CONSTRAINT fk_observation_skills FOREIGN KEY (observation_id) REFERENCES child_observations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
