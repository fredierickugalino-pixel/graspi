<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/student_schema.php";

header("Cache-Control: private, max-age=300");
$studentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
$photo = null;

if ($studentId) {
    try {
        ensureStudentProfilePhotoColumn($conn);
        removeLegacyStudentProfilePhotosTable($conn, __DIR__ . "/../student/uploads");
        $photoQuery = $conn->prepare(
            "SELECT profile_photo FROM students WHERE student_id = ? LIMIT 1"
        );
        $photoQuery->bind_param("i", $studentId);
        $photoQuery->execute();
        $photo = $photoQuery->get_result()->fetch_assoc();
        $photoQuery->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Admin student photo load error: " . $e->getMessage());
    }
}

$photoName = basename((string) ($photo["profile_photo"] ?? ""));
$photoPath = __DIR__ . "/../student/uploads/" . $photoName;
$imageInfo = $photoName !== "" && is_file($photoPath) ? @getimagesize($photoPath) : false;
if ($imageInfo && in_array($imageInfo["mime"] ?? "", ["image/jpeg", "image/png", "image/gif", "image/webp"], true)) {
    header("Content-Type: " . $imageInfo["mime"]);
    readfile($photoPath);
    exit;
}

header("Content-Type: image/svg+xml; charset=utf-8");
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160"><rect width="160" height="160" rx="80" fill="#e7f1e9"/><circle cx="80" cy="59" r="29" fill="#8eaa97"/><path d="M25 151c5-34 25-51 55-51s50 17 55 51" fill="#8eaa97"/></svg>';
