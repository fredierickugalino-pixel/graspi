<?php
function ensureSectionSchema(mysqli $conn): void
{
    $archiveColumn = $conn->query("SHOW COLUMNS FROM sections LIKE 'is_archived'");
    if ($archiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE sections ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
}
