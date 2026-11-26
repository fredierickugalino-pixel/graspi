<?php
function ensureEnrollmentStatusSchema(mysqli $conn): void
{
    $statusColumn = $conn->query("SHOW COLUMNS FROM enrollments LIKE 'status'")->fetch_assoc();
    if (!$statusColumn) {
        throw new RuntimeException("The enrollment status column is missing.");
    }

    $statusType = (string) ($statusColumn["Type"] ?? "");
    if (stripos($statusType, "'Pending'") === false) {
        $conn->query(
            "ALTER TABLE enrollments
             MODIFY status ENUM('Pending','Enrolled','Completed','Transferred','Dropped')
             NOT NULL DEFAULT 'Enrolled'"
        );
    }

    $conn->query(
        "UPDATE enrollments
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
         SET enrollments.status = 'Pending'
         WHERE enrollments.status = ''
           AND users.approval_status = 'pending'"
    );
}
