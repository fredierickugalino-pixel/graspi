<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");

// When opened directly, show the profile page instead of the raw image document.
$fetchDestination = $_SERVER["HTTP_SEC_FETCH_DEST"] ?? "";
$acceptHeader = $_SERVER["HTTP_ACCEPT"] ?? "";
if ($fetchDestination === "document" || strpos($acceptHeader, "text/html") !== false) {
    header("Location: " . rolePageUrl("student", "profile.php"));
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/student_schema.php";

try {
    $userId = (int) $_SESSION["user_id"];
    ensureStudentProfilePhotoColumn($conn);
    removeLegacyStudentProfilePhotosTable($conn, __DIR__ . "/uploads");
    $query = $conn->prepare("SELECT profile_photo FROM students WHERE user_id = ?");
    $query->bind_param("i", $userId);
    $query->execute();
    $photo = $query->get_result()->fetch_assoc();
    $query->close();

    $photoName = basename((string) ($photo["profile_photo"] ?? ""));
    $photoPath = __DIR__ . "/uploads/" . $photoName;
    $imageInfo = $photoName !== "" && is_file($photoPath) ? @getimagesize($photoPath) : false;
    if ($imageInfo && in_array($imageInfo["mime"] ?? "", ["image/jpeg", "image/png", "image/gif", "image/webp"], true)) {
        header("Content-Type: " . $imageInfo["mime"]);
        header("Cache-Control: private, max-age=300");
        readfile($photoPath);
        exit;
    }
} catch (mysqli_sql_exception $e) {
    error_log("Profile photo load error: " . $e->getMessage());
}

header("Content-Type: image/svg+xml; charset=utf-8");
header("Cache-Control: no-store");
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" rx="60" fill="#e8f1eb"/><circle cx="60" cy="44" r="22" fill="#91ae9b"/><path d="M20 112c3-25 19-38 40-38s37 13 40 38" fill="#91ae9b"/></svg>';
