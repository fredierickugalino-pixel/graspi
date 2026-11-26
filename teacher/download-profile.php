<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/teacher_schema.php";

try {
    ensureTeacherGenderColumn($conn);
    ensureTeacherProfilePhotoColumn($conn);
    ensureTeacherPersonalInfoColumns($conn);
    $userId = (int) $_SESSION["user_id"];
    $profileQuery = $conn->prepare(
        "SELECT users.first_name, users.middle_name, users.last_name, users.suffix,
                users.email, teachers.contact_phone, teachers.gender,
                teachers.civil_status, teachers.nationality, teachers.address, teachers.profile_photo
         FROM users
         LEFT JOIN teachers ON teachers.user_id = users.user_id
         WHERE users.user_id = ? AND users.role = 'Teacher'
         LIMIT 1"
    );
    $profileQuery->bind_param("i", $userId);
    $profileQuery->execute();
    $profile = $profileQuery->get_result()->fetch_assoc();
    $profileQuery->close();
} catch (mysqli_sql_exception $e) {
    error_log("Teacher profile PDF data error: " . $e->getMessage());
    http_response_code(500);
    exit("Could not create the profile PDF.");
}

if (!$profile) {
    http_response_code(404);
    exit("Teacher profile not found.");
}

function pdfText($text)
{
    $text = function_exists("iconv")
        ? iconv("UTF-8", "Windows-1252//TRANSLIT//IGNORE", (string) $text)
        : preg_replace('/[^\x20-\x7E]/', "?", (string) $text);
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text ?: "");
}

function resumeText($font, $size, $x, $y, $text, $color = "0.12 0.22 0.17")
{
    return "{$color} rg BT /{$font} {$size} Tf {$x} {$y} Td (" . pdfText($text) . ") Tj ET\n";
}

$fullName = trim(implode(" ", array_filter([
    $profile["first_name"],
    $profile["middle_name"],
    $profile["last_name"],
    $profile["suffix"]
], static function ($part) {
    return trim((string) $part) !== "";
})));

$photoBytes = null;
$photoWidth = 0;
$photoHeight = 0;
$photoName = basename((string) ($profile["profile_photo"] ?? ""));
if ($photoName !== "") {
    $photoPath = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . $photoName;
    $photoInfo = is_file($photoPath) ? @getimagesize($photoPath) : false;
    if ($photoInfo && ($photoInfo["mime"] ?? "") === "image/jpeg") {
        $photoBytes = file_get_contents($photoPath);
        $photoWidth = (int) $photoInfo[0];
        $photoHeight = (int) $photoInfo[1];
    }
}

$content = "0.90 0.91 0.93 rg 0 0 184 640 re f\n";
$content .= "0.18 0.22 0.29 rg 0 640 612 152 re f\n";
$content .= "0.78 0.81 0.85 RG 0.8 w 0 640 m 612 640 l S\n";
$content .= resumeText("F2", 25, 205, 704, $fullName ?: "Teacher", "1 1 1");
$content .= resumeText("F1", 10, 207, 679, "ST. PEREGRINE INSTITUTE  |  TEACHER", "0.83 0.86 0.90");

$photoObjectId = null;
if ($photoBytes !== null) {
    $photoObjectId = 6;
    $boxSize = 126;
    $scale = max($boxSize / $photoWidth, $boxSize / $photoHeight);
    $imageWidth = round($photoWidth * $scale, 2);
    $imageHeight = round($photoHeight * $scale, 2);
    $imageX = 22 + ($boxSize - $imageWidth) / 2;
    $imageY = 510 + ($boxSize - $imageHeight) / 2;
    $content .= "0.98 0.98 0.98 rg 18 506 142 142 re f\n";
    $content .= "q 89 644 m 126 644 156 614 156 577 c 156 540 126 510 89 510 c 52 510 22 540 22 577 c 22 614 52 644 89 644 c W n {$imageWidth} 0 0 {$imageHeight} {$imageX} {$imageY} cm /Photo Do Q\n";
}

$content .= resumeText("F2", 11, 24, 484, "CONTACT", "0.18 0.22 0.29");
$content .= "0.39 0.43 0.49 RG 24 475 m 160 475 l S\n";
$content .= resumeText("F2", 8, 24, 451, "PHONE", "0.35 0.38 0.43");
$content .= resumeText("F1", 9, 24, 436, $profile["contact_phone"] ?: "Not provided");
$content .= resumeText("F2", 8, 24, 405, "EMAIL", "0.35 0.38 0.43");
$content .= resumeText("F1", 8, 24, 390, $profile["email"] ?: "Not provided");
$content .= resumeText("F2", 8, 24, 359, "ADDRESS", "0.35 0.38 0.43");
$content .= resumeText("F1", 8, 24, 344, $profile["address"] ?: "Not provided");
$content .= resumeText("F2", 11, 24, 291, "PERSONAL", "0.18 0.22 0.29");
$content .= "0.39 0.43 0.49 RG 24 282 m 160 282 l S\n";
$content .= resumeText("F1", 9, 24, 257, "Gender: " . ($profile["gender"] ?: "Not provided"));
$content .= resumeText("F1", 9, 24, 237, "Civil status: " . ($profile["civil_status"] ?: "Not provided"));
$content .= resumeText("F1", 9, 24, 217, "Nationality: " . ($profile["nationality"] ?: "Not provided"));
$content .= resumeText("F2", 13, 213, 584, "PERSONAL DETAILS", "0.18 0.22 0.29");
$content .= "0.39 0.43 0.49 RG 213 572 m 584 572 l S\n";
$content .= resumeText("F2", 10, 213, 544, "Full name", "0.35 0.38 0.43");
$content .= resumeText("F1", 12, 213, 524, $fullName ?: "Teacher");
$content .= resumeText("F2", 10, 213, 489, "Position", "0.35 0.38 0.43");
$content .= resumeText("F1", 12, 213, 469, "Teacher · St. Peregrine Institute");
$content .= resumeText("F2", 13, 213, 414, "PROFILE", "0.18 0.22 0.29");
$content .= "0.39 0.43 0.49 RG 213 402 m 584 402 l S\n";
$content .= resumeText("F1", 11, 213, 375, "Teacher account profile", "0.18 0.22 0.29");
$content .= resumeText("F1", 10, 213, 354, "Personal and contact details saved in the school grading system.");
$content .= resumeText("F2", 13, 213, 294, "ACCOUNT INFORMATION", "0.18 0.22 0.29");
$content .= "0.39 0.43 0.49 RG 213 282 m 584 282 l S\n";
$content .= resumeText("F1", 10, 213, 253, "This document was generated from the teacher profile on file.");
$content .= resumeText("F1", 9, 213, 42, "Generated " . date("F j, Y"), "0.42 0.45 0.49");

$objects = [];
$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$xObjectResource = $photoObjectId ? " /XObject << /Photo {$photoObjectId} 0 R >>" : "";
$objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$xObjectResource} >> /Contents " . ($photoObjectId ? 7 : 6) . " 0 R >>";
$objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
$objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

if ($photoObjectId) {
    $objects[6] = "<< /Type /XObject /Subtype /Image /Width {$photoWidth} /Height {$photoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($photoBytes) . " >>\nstream\n" . $photoBytes . "\nendstream";
    $contentObjectId = 7;
} else {
    $contentObjectId = 6;
}
$objects[$contentObjectId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
ksort($objects);

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $objectNumber => $object) {
    $offsets[$objectNumber] = strlen($pdf);
    $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
}
$xrefOffset = strlen($pdf);
$maxObjectNumber = max(array_keys($objects));
$pdf .= "xref\n0 " . ($maxObjectNumber + 1) . "\n0000000000 65535 f \n";
for ($index = 1; $index <= $maxObjectNumber; $index++) {
    $pdf .= isset($offsets[$index])
        ? sprintf("%010d 00000 n \n", $offsets[$index])
        : "0000000000 00000 f \n";
}
$pdf .= "trailer\n<< /Size " . ($maxObjectNumber + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

header("Content-Type: application/pdf");
header('Content-Disposition: attachment; filename="teacher-resume.pdf"');
header("Content-Length: " . strlen($pdf));
echo $pdf;
