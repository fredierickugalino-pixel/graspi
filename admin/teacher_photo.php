<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/teacher_schema.php";
ensureTeacherProfilePhotoColumn($conn);

$teacherId = filter_input(INPUT_GET, "teacher_id", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
if (!$teacherId) {
    http_response_code(404);
    exit;
}

try {
    $photoQuery = $conn->prepare("SELECT profile_photo FROM teachers WHERE teacher_id = ? LIMIT 1");
    $photoQuery->bind_param("i", $teacherId);
    $photoQuery->execute();
    $photoRow = $photoQuery->get_result()->fetch_assoc();
    $photoQuery->close();
    $photoName = basename((string) ($photoRow["profile_photo"] ?? ""));
    $photoPath = __DIR__ . "/../teacher/uploads/" . $photoName;
    $imageInfo = $photoName !== "" && is_file($photoPath) ? @getimagesize($photoPath) : false;
    $allowedMimes = ["image/jpeg", "image/png", "image/webp"];
    if (!$imageInfo || !in_array($imageInfo["mime"] ?? "", $allowedMimes, true)) {
        http_response_code(404);
        exit;
    }
    header("Content-Type: " . $imageInfo["mime"]);
    header("Content-Length: " . filesize($photoPath));
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=300");
    readfile($photoPath);
} catch (mysqli_sql_exception $e) {
    error_log("Admin teacher photo error: " . $e->getMessage());
    http_response_code(404);
}
