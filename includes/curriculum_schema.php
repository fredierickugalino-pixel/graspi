<?php

function ensureCurriculumSchema($conn): void
{
    $archiveColumn = $conn->query("SHOW COLUMNS FROM subjects LIKE 'is_archived'");
    if ($archiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE subjects ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }

    $existingTable = $conn->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'track_subject_assignments' LIMIT 1"
    );
    $shouldSeedCurrentOfferings = $existingTable->num_rows === 0;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS track_subject_assignments (
            track_id INT NOT NULL,
            grade_level TINYINT UNSIGNED NOT NULL,
            semester TINYINT UNSIGNED NOT NULL,
            subject_id INT NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (track_id, grade_level, semester, subject_id),
            INDEX idx_track_subject_assignment_subject (subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($shouldSeedCurrentOfferings) {
        $conn->query(
            "INSERT IGNORE INTO track_subject_assignments (track_id, grade_level, semester, subject_id)
             SELECT DISTINCT sections.track_id, sections.grade_level, subjects.semester, subjects.subject_id
             FROM class_subjects
             JOIN sections ON sections.section_id = class_subjects.section_id
             JOIN subjects ON subjects.subject_id = class_subjects.subject_id
             WHERE sections.track_id IS NOT NULL
               AND subjects.is_archived = 0
               AND (subjects.track_id IS NULL OR subjects.track_id = sections.track_id)"
        );
    }
}
