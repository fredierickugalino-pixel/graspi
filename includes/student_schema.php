<?php
function ensureStudentProfilePhotoColumn($conn)
{
    $columns = $conn->query("SHOW COLUMNS FROM students LIKE 'profile_photo'");
    if ($columns->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE students ADD COLUMN profile_photo VARCHAR(255) NULL");
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
}

function removeLegacyStudentProfilePhotosTable($conn, $uploadDirectory)
{
    $table = $conn->query("SHOW TABLES LIKE 'student_profile_photos'");
    if ($table->num_rows === 0) {
        return;
    }

    $legacyPhotos = $conn->query(
        "SELECT students.user_id, student_profile_photos.mime_type, student_profile_photos.image_data
         FROM student_profile_photos
         JOIN students ON students.user_id = student_profile_photos.user_id"
    );
    if ($legacyPhotos->num_rows > 0 && !is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException("Could not create student uploads directory.");
    }

    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/gif" => "gif", "image/webp" => "webp"];
    while ($legacyPhoto = $legacyPhotos->fetch_assoc()) {
        $mimeType = (string) $legacyPhoto["mime_type"];
        if (!isset($extensions[$mimeType])) {
            continue;
        }
        $imageData = (string) $legacyPhoto["image_data"];
        $imageInfo = @getimagesizefromstring($imageData);
        if (!$imageInfo || ($imageInfo["mime"] ?? "") !== $mimeType) {
            continue;
        }
        $userId = (int) $legacyPhoto["user_id"];
        $photoName = "student-" . $userId . "-" . bin2hex(random_bytes(12)) . "." . $extensions[$mimeType];
        $photoPath = rtrim($uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $photoName;
        if (file_put_contents($photoPath, $imageData, LOCK_EX) === false) {
            throw new RuntimeException("Could not migrate student profile photo.");
        }
        try {
            $update = $conn->prepare("UPDATE students SET profile_photo = ? WHERE user_id = ?");
            $update->bind_param("si", $photoName, $userId);
            $update->execute();
            $update->close();
        } catch (Throwable $e) {
            @unlink($photoPath);
            throw $e;
        }
    }
    $legacyPhotos->free();
    $conn->query("DROP TABLE student_profile_photos");
}
