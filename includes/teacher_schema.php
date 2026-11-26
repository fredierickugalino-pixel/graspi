<?php
function ensureTeacherMustChangePasswordColumn($conn): void
{
    $column = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
    if ($column->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query(
            "UPDATE users
             JOIN teachers ON teachers.user_id = users.user_id
             SET users.must_change_password = 1
             WHERE users.role = 'Teacher'"
        );
    }
}

function ensureTeacherGenderColumn($conn)
{
    $columns = $conn->query("SHOW COLUMNS FROM teachers LIKE 'gender'");
    if ($columns->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE teachers ADD COLUMN gender VARCHAR(30) NULL");
        } catch (mysqli_sql_exception $e) {
            // Another request may have added the column between the check and ALTER.
            if ($e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
}

function ensureTeacherProfilePhotoColumn($conn)
{
    $columns = $conn->query("SHOW COLUMNS FROM teachers LIKE 'profile_photo'");
    if ($columns->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE teachers ADD COLUMN profile_photo VARCHAR(255) NULL");
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
}

function ensureTeacherPersonalInfoColumns($conn)
{
    $columns = [
        "civil_status" => "VARCHAR(30) NULL",
        "nationality" => "VARCHAR(80) NULL",
        "address" => "VARCHAR(255) NULL"
    ];
    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM teachers LIKE '{$column}'");
        if ($result->num_rows === 0) {
            try {
                $conn->query("ALTER TABLE teachers ADD COLUMN {$column} {$definition}");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1060) {
                    throw $e;
                }
            }
        }
    }
}
