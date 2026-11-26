<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/messaging_schema.php";

$userId = (int) $_SESSION["user_id"];
$teacherUnreadMessageCount = 0;
try {
    ensureStudentTeacherMessagesSchema($conn);
    $teacherUnreadMessageCount = getUnreadTeacherMessageCount($conn, $userId);
} catch (mysqli_sql_exception $messageSchemaError) {
    error_log("Teacher message badge unavailable: " . $messageSchemaError->getMessage());
}
$teacherName = $_SESSION["name"] ?? "Teacher";
$sections = [];
$sectionOptions = [];
$studentsWithFailingScores = [];
$filteredFailingStudents = [];
$selectedSectionId = max(0, (int) ($_GET["section_id"] ?? 0));
$assessmentTypes = ["Quiz", "Activity", "Assignment", "Project", "Exam", "Other"];
$assessmentTypeInput = $_GET["assessment_type"] ?? "all";
$selectedAssessmentType = is_string($assessmentTypeInput) &&
    in_array($assessmentTypeInput, array_merge(["all"], $assessmentTypes), true)
    ? $assessmentTypeInput
    : "all";
$failingPageSize = 10;
$failingPage = max(1, (int) ($_GET["failing_page"] ?? 1));
$failingPageCount = 1;
$failingStudentsOnPage = [];
$error = "";
$showAssessmentOverview = isset($_GET["show_assessment_overview"]);
$manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$schoolYearStart = (int) $manilaNow->format("Y");
if ((int) $manilaNow->format("n") < 6) {
    $schoolYearStart--;
}
$schoolYear = $schoolYearStart . "-" . ($schoolYearStart + 1);

try {
    $sectionQuery = $conn->prepare(
        "SELECT sections.section_id, sections.section_name, sections.grade_level,
                class_subjects.class_subject_id, subjects.subject_name,
                COUNT(DISTINCT users.user_id) AS student_count
         FROM teachers
         JOIN sections ON sections.teacher_id = teachers.teacher_id
            OR sections.section_id IN (
                SELECT assigned_classes.section_id
                FROM class_subjects AS assigned_classes
                WHERE assigned_classes.teacher_id = teachers.teacher_id
                  AND assigned_classes.school_year = ?
            )
         LEFT JOIN class_subjects
            ON class_subjects.section_id = sections.section_id
            AND class_subjects.teacher_id = teachers.teacher_id
            AND class_subjects.school_year = ?
         LEFT JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         LEFT JOIN enrollments
            ON enrollments.section_id = sections.section_id
            AND enrollments.status = 'Enrolled'
            AND enrollments.school_year = ?
         LEFT JOIN students ON students.student_id = enrollments.student_id
         LEFT JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE teachers.user_id = ?
         GROUP BY sections.section_id, sections.section_name, sections.grade_level,
                  class_subjects.class_subject_id, subjects.subject_name
         ORDER BY sections.grade_level, sections.section_name, subjects.subject_name"
    );
    $sectionQuery->bind_param("sssi", $schoolYear, $schoolYear, $schoolYear, $userId);
    $sectionQuery->execute();
    $sections = $sectionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionQuery->close();
    foreach ($sections as $section) {
        $sectionId = (int) $section["section_id"];
        $sectionOptions[$sectionId] = [
            "id" => $sectionId,
            "grade_level" => $section["grade_level"],
            "section_name" => $section["section_name"]
        ];
    }
    if ($selectedSectionId !== 0 && !isset($sectionOptions[$selectedSectionId])) {
        $selectedSectionId = 0;
    }

    $failedStudentsQuery = $conn->prepare(
        "SELECT students.student_id, users.first_name, users.middle_name, users.last_name,
                sections.section_id, sections.grade_level, sections.section_name, subjects.subject_name,
                assessments.assessment_id, assessments.assessment_name,
                student_scores.score, assessments.max_score
         FROM teachers
         JOIN class_subjects ON class_subjects.teacher_id = teachers.teacher_id
            AND class_subjects.school_year = ?
         JOIN sections ON sections.section_id = class_subjects.section_id
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         JOIN enrollments ON enrollments.section_id = sections.section_id
            AND enrollments.school_year = ? AND enrollments.status = 'Enrolled'
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         JOIN assessments ON assessments.class_subject_id = class_subjects.class_subject_id
         JOIN student_scores ON student_scores.assessment_id = assessments.assessment_id
            AND student_scores.student_id = students.student_id
         WHERE teachers.user_id = ?
           AND assessments.max_score > 0
           AND student_scores.score / assessments.max_score < ?
           AND (? = 'all' OR assessments.assessment_type = ?)
         ORDER BY users.last_name, users.first_name, sections.grade_level,
                  sections.section_name, subjects.subject_name, assessments.assessment_type,
                  assessments.assessment_name"
    );
    $passingRatio = 0.70;
    $failedStudentsQuery->bind_param("ssidss", $schoolYear, $schoolYear, $userId, $passingRatio, $selectedAssessmentType, $selectedAssessmentType);
    $failedStudentsQuery->execute();
    $failedRows = $failedStudentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $failedStudentsQuery->close();

    foreach ($failedRows as $failedRow) {
        $studentId = (int) $failedRow["student_id"];
        if (!isset($studentsWithFailingScores[$studentId])) {
            $studentsWithFailingScores[$studentId] = [
                "name" => trim($failedRow["last_name"] . ", " . $failedRow["first_name"] . " " . ($failedRow["middle_name"] ?? "")),
                "sections" => [],
                "failures" => [],
                "failures_by_section" => []
            ];
        }
        $sectionId = (int) $failedRow["section_id"];
        $sectionLabel = "Grade " . $failedRow["grade_level"] . " · " . $failedRow["section_name"];
        $studentsWithFailingScores[$studentId]["sections"][$sectionId] = $sectionLabel;
        $assessmentId = (int) $failedRow["assessment_id"];
        $failureLabel = $failedRow["assessment_name"] . " (" . (int) $failedRow["score"] . "/" . (int) $failedRow["max_score"] . ")";
        $studentsWithFailingScores[$studentId]["failures"][$assessmentId] = $failureLabel;
        $studentsWithFailingScores[$studentId]["failures_by_section"][$sectionId][$assessmentId] = $failureLabel;
    }
    foreach ($studentsWithFailingScores as $studentId => $studentFailure) {
        if ($selectedSectionId === 0 || isset($studentFailure["sections"][$selectedSectionId])) {
            $filteredFailingStudents[$studentId] = $studentFailure;
        }
    }
    $failingPageCount = max(1, (int) ceil(count($filteredFailingStudents) / $failingPageSize));
    $failingPage = min($failingPage, $failingPageCount);
    $failingStudentsOnPage = array_slice($filteredFailingStudents, ($failingPage - 1) * $failingPageSize, $failingPageSize, true);

} catch (mysqli_sql_exception $e) {
    error_log("Teacher sections page error: " . $e->getMessage());
    $error = "We could not load your sections right now.";
}
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View the sections assigned to your teacher account.">
    <meta name="theme-color" content="#105334">
    <title>Sections | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/css/dashboard.css") ?>">
    <link rel="stylesheet" href="css/sections.css?v=<?= (int) filemtime(__DIR__ . "/css/sections.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="teacher-dashboard-page">
    <header class="topbar">
        <a class="brand" href="../teacher-dashboard">
            <img src="../logo.png" alt="">
            <span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span>
        </a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu"><span></span><span></span><span></span></button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user"><strong><?= h($teacherName) ?></strong><span>Teacher</span></div>
                <a class="menu-link" href="../teacher-dashboard">Dashboard</a>
                <a class="menu-link active" href="../teacher-sections" aria-current="page">Sections</a>
                <a class="menu-link" href="../teacher-messages">Messages <span class="nav-unread-count" data-teacher-unread-count<?= $teacherUnreadMessageCount > 0 ? "" : " hidden" ?>><?= $teacherUnreadMessageCount > 99 ? "99+" : $teacherUnreadMessageCount ?></span></a>
                <a class="menu-link" href="../teacher-profile">My Profile</a>
                <a class="menu-link signout-link" href="../logout.php">Sign out</a>
            </nav>
        </div>
    </header>

    <main class="dashboard teacher-dashboard">
        <div class="page-heading">
            <p class="eyebrow">TEACHER PORTAL</p>
            <h1>Sections</h1>
        </div>

        <?php 
if ($error !== ""): ?>
            <p class="alert error" role="alert"><?= h($error) ?></p>
        <?php 
elseif (count($sections) === 0): ?>
            <section class="panel"><p class="empty-state">No sections have been assigned to your account yet.</p></section>
        <?php 
else: ?>
            <div class="assessment-overview-action-row">
                <button class="assessment-overview-open" type="button" id="open-assessment-overview" aria-haspopup="dialog" aria-expanded="false" aria-controls="assessment-overview-modal">Assessment Overview</button>
            </div>
            <section class="panel">
                <div class="panel-heading"><div><h2>Your sections</h2></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Grade level</th><th>Section</th><th>Subject</th><th>Enrolled students</th><th>Download list</th><th>Upload</th></tr></thead>
                        <tbody>
                            <?php 
foreach ($sections as $section): ?>
                                <tr>
                                    <td><?= h($section["grade_level"]) ?></td>
                                    <td><?= h($section["section_name"]) ?></td>
                                    <td><?= h($section["subject_name"] ?? "Not assigned") ?></td>
                                    <td><?= (int) $section["student_count"] ?></td>
                                    <td>
                                        <?php 
if (!empty($section["class_subject_id"])): ?>
                                            <a class="teacher-section-action" href="../teacher-grades?class_subject_id=<?= (int) $section["class_subject_id"] ?>&amp;download_roster=1" aria-label="Download the student list for <?= h($section["section_name"]) ?> as a spreadsheet-compatible CSV">Download list</a>
                                        <?php 
endif; ?>
                                    </td>
                                    <td>
                                        <?php 
if (!empty($section["class_subject_id"])): ?>
                                            <a class="teacher-section-action" href="../teacher-grades?class_subject_id=<?= (int) $section["class_subject_id"] ?>">Enter scores</a>
                                        <?php 
endif; ?>
                                    </td>
                                </tr>
                            <?php 
endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="dashboard-modal-overlay" id="assessment-overview-modal" data-trigger-id="open-assessment-overview" <?= $showAssessmentOverview ? "" : "hidden" ?>>
                <section class="dashboard-form-modal assessment-overview-modal" role="dialog" aria-modal="true" aria-labelledby="assessment-overview-title" tabindex="-1">
                    <div class="dashboard-form-modal-heading">
                        <div><h2 id="assessment-overview-title">Assessment overview</h2><p>Students with scores below the 70% passing rate</p></div>
                        <button class="dashboard-modal-close" type="button" aria-label="Close assessment overview">×</button>
                    </div>
                    <div class="assessment-overview-content">
                <div class="panel-heading assessment-overview-filters">
                    <div class="section-failing-tools">
                        <form action="../teacher-sections" method="get" class="section-failing-filter-form">
                            <input type="hidden" name="show_assessment_overview" value="1">
                            <label for="failing-section-filter">Section</label>
                            <select id="failing-section-filter" name="section_id" onchange="this.form.submit()">
                                <option value="0" <?= $selectedSectionId === 0 ? "selected" : "" ?>>All sections</option>
                                <?php 
foreach ($sectionOptions as $option): ?>
                                    <option value="<?= (int) $option["id"] ?>" <?= $selectedSectionId === (int) $option["id"] ? "selected" : "" ?>>Grade <?= h($option["grade_level"]) ?> · <?= h($option["section_name"]) ?></option>
                                <?php 
endforeach; ?>
                            </select>

                            <label for="assessment-type-filter">Assessment type</label>
                            <select id="assessment-type-filter" name="assessment_type" onchange="this.form.submit()">
                                <option value="all" <?= $selectedAssessmentType === "all" ? "selected" : "" ?>>All types</option>
                                <?php 
foreach ($assessmentTypes as $assessmentType): ?>
                                    <option value="<?= h($assessmentType) ?>" <?= $selectedAssessmentType === $assessmentType ? "selected" : "" ?>><?= h($assessmentType) ?></option>
                                <?php 
endforeach; ?>
                            </select>
                        </form>
                        <span class="section-failing-count"><?= count($filteredFailingStudents) ?> students</span>
                    </div>
                </div>
                <?php 
if (!$filteredFailingStudents): ?>
                    <p class="empty-state">No failing assessment scores found in your sections.</p>
                <?php 
else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Student</th><th>Section</th><th>Failed assessments</th></tr></thead>
                            <tbody>
                                <?php 
foreach ($failingStudentsOnPage as $studentFailure): ?>
                                    <tr>
                                        <td><?= h($studentFailure["name"]) ?></td>
                                        <td><?= h($selectedSectionId !== 0 ? ($studentFailure["sections"][$selectedSectionId] ?? "") : implode(", ", $studentFailure["sections"])) ?></td>
                                        <td><ul class="student-failure-list">
                                            <?php 
$studentFailuresToShow = $selectedSectionId !== 0 ? array_values($studentFailure["failures_by_section"][$selectedSectionId] ?? []) : array_values($studentFailure["failures"]); ?>
                                            <?php 
foreach ($studentFailuresToShow as $failure): ?>
                                                <li><?= h($failure) ?></li>
                                            <?php 
endforeach; ?>
                                        </ul></td>
                                    </tr>
                                <?php 
endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php 
if ($failingPageCount > 1): ?>
                        <nav class="failing-student-pagination" aria-label="Failing students pages">
                            <?php 
if ($failingPage > 1): ?>
                                <a href="../teacher-sections?section_id=<?= $selectedSectionId ?>&amp;assessment_type=<?= rawurlencode($selectedAssessmentType) ?>&amp;show_assessment_overview=1&amp;failing_page=<?= $failingPage - 1 ?>" aria-label="Previous page">Previous</a>
                            <?php 
else: ?>
                                <span class="is-disabled" aria-disabled="true">Previous</span>
                            <?php 
endif; ?>
                            <span>Page <?= $failingPage ?> of <?= $failingPageCount ?></span>
                            <?php 
if ($failingPage < $failingPageCount): ?>
                                <a href="../teacher-sections?section_id=<?= $selectedSectionId ?>&amp;assessment_type=<?= rawurlencode($selectedAssessmentType) ?>&amp;show_assessment_overview=1&amp;failing_page=<?= $failingPage + 1 ?>" aria-label="Next page">Next</a>
                            <?php 
else: ?>
                                <span class="is-disabled" aria-disabled="true">Next</span>
                            <?php 
endif; ?>
                        </nav>
                    <?php 
endif; ?>
                <?php 
endif; ?>
                    </div>
                </section>
            </div>

        <?php 
endif; ?>
    </main>
    <script>
        const assessmentOverviewTrigger = document.getElementById("open-assessment-overview");
        const assessmentOverviewModal = document.getElementById("assessment-overview-modal");
        if (assessmentOverviewTrigger && assessmentOverviewModal) {
            assessmentOverviewTrigger.addEventListener("click", function () {
                assessmentOverviewModal.dataset.triggerId = assessmentOverviewTrigger.id;
                assessmentOverviewModal.hidden = false;
                assessmentOverviewTrigger.setAttribute("aria-expanded", "true");
                document.body.classList.add("dashboard-modal-open");
                const closeButton = assessmentOverviewModal.querySelector(".dashboard-modal-close");
                if (closeButton) closeButton.focus();
            });
            <?php 
if ($showAssessmentOverview): ?>
                assessmentOverviewTrigger.setAttribute("aria-expanded", "true");
                document.body.classList.add("dashboard-modal-open");
            <?php 
endif; ?>
        }
    </script>
</body>
</html>
