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
$teacher = null;
$teachingAssignments = [];
$sectionSubjectGrid = [];
$error = "";

try {
    if (!$teacherId) {
        throw new RuntimeException("Choose a valid teacher.");
    }

    $teacherQuery = $conn->prepare(
        "SELECT teachers.teacher_id, teachers.contact_phone, teachers.gender, teachers.profile_photo,
                users.first_name, users.middle_name, users.last_name, users.suffix, users.email
         FROM teachers
         JOIN users ON users.user_id = teachers.user_id
         WHERE teachers.teacher_id = ? LIMIT 1"
    );
    $teacherQuery->bind_param("i", $teacherId);
    $teacherQuery->execute();
    $teacher = $teacherQuery->get_result()->fetch_assoc();
    $teacherQuery->close();
    if (!$teacher) {
        http_response_code(404);
        throw new RuntimeException("Teacher record not found.");
    }

    $assignmentsQuery = $conn->prepare(
        "SELECT DISTINCT sections.section_id, sections.section_name,
                subjects.subject_name
         FROM sections
         LEFT JOIN class_subjects
            ON class_subjects.section_id = sections.section_id
            AND class_subjects.teacher_id = ?
         LEFT JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         WHERE sections.teacher_id = ? OR class_subjects.teacher_id = ?
         ORDER BY sections.grade_level, sections.section_name,
                  subjects.subject_name, class_subjects.school_year DESC"
    );
    $assignmentsQuery->bind_param("iii", $teacherId, $teacherId, $teacherId);
    $assignmentsQuery->execute();
    $teachingAssignments = $assignmentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignmentsQuery->close();
    foreach ($teachingAssignments as $assignment) {
        $sectionId = (int) $assignment["section_id"];
        if (!isset($sectionSubjectGrid[$sectionId])) {
            $sectionSubjectGrid[$sectionId] = [
                "section_name" => $assignment["section_name"],
                "subjects" => []
            ];
        }
        if (!empty($assignment["subject_name"])) {
            $sectionSubjectGrid[$sectionId]["subjects"][] = $assignment["subject_name"];
        }
    }
} catch (RuntimeException $e) {
    $error = $e->getMessage();
} catch (mysqli_sql_exception $e) {
    error_log("Admin teacher info page error: " . $e->getMessage());
    $error = "We could not load this teacher’s information right now.";
}

$adminName = $_SESSION["name"] ?? "Administrator";
$teacherName = $teacher
    ? trim(implode(" ", array_filter([$teacher["first_name"], $teacher["middle_name"], $teacher["last_name"], $teacher["suffix"]], static fn($part) => trim((string) $part) !== "")) )
    : "Teacher details";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View teacher contact information and assigned sections.">
    <meta name="theme-color" content="#105334">
    <title><?= h($teacherName) ?> | Teacher Details</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <link rel="stylesheet" href="teacher_info.css?v=<?= (int) filemtime(__DIR__ . "/teacher_info.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="admin-teacher-info-page">
    <header class="topbar">
        <a class="brand" href="../admin-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode"><svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg><svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg></button>
            <a class="admin-profile-icon" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
        </div>
    </header>
    <main class="dashboard teacher-info-dashboard">
        <a class="teacher-info-back" href="../admin-register"><span aria-hidden="true">←</span> Back to teachers</a>
        <?php 
if ($error !== ""): ?>
            <p class="alert error" role="alert"><?= h($error) ?></p>
        <?php 
elseif ($teacher): ?>
            <section class="panel teacher-info-card">
                <div class="teacher-info-heading">
                    <div class="teacher-info-avatar">
                        <?php 
if (!empty($teacher["profile_photo"])): ?>
                            <img src="../admin-teacher_photo?teacher_id=<?= (int) $teacher["teacher_id"] ?>" alt="Profile picture of <?= h($teacherName) ?>">
                        <?php 
else: ?>
                            <span aria-hidden="true"><?= h(strtoupper(substr($teacherName, 0, 1))) ?></span>
                        <?php 
endif; ?>
                    </div>
                    <div><p class="eyebrow">STAFF DETAILS</p><h1><?= h($teacherName) ?></h1></div>
                </div>
                <dl class="teacher-info-facts">
                    <div><dt>Email</dt><dd><?= h($teacher["email"] ?: "—") ?></dd></div>
                    <div><dt>Contact number</dt><dd><?= h($teacher["contact_phone"] ?: "—") ?></dd></div>
                    <div><dt>Gender</dt><dd><?= h($teacher["gender"] ?: "—") ?></dd></div>
                </dl>
            </section>
            <section class="panel teacher-info-sections">
                <div class="teacher-info-section-heading"><h2>Sections and subjects handled</h2></div>
                <?php 
if (!$sectionSubjectGrid): ?><p class="empty-state">This teacher has no assigned sections or subjects yet.</p>
                <?php 
else: ?><div class="teacher-info-section-grid"><?php 
foreach ($sectionSubjectGrid as $sectionCard): ?><article class="teacher-info-section-stat"><h3><?= h($sectionCard["section_name"]) ?></h3><?php 
if ($sectionCard["subjects"]): ?><ul><?php 
foreach (array_unique($sectionCard["subjects"]) as $subjectName): ?><li><?= h($subjectName) ?></li><?php 
endforeach; ?></ul><?php 
else: ?><p>No subjects assigned yet.</p><?php 
endif; ?></article><?php 
endforeach; ?></div><?php 
endif; ?>
            </section>
        <?php 
endif; ?>
    </main>
</body>
</html>
