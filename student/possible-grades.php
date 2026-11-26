<?php
require_once __DIR__ . "/../includes/url_helpers.php";

$renderPossibleGradesPage = true;

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/grading_schema.php";
require_once __DIR__ . "/../includes/curriculum_schema.php";
ensureGradingSchema($conn);
ensureCurriculumSchema($conn);

$userId = (int) $_SESSION["user_id"];
$scores = [];
$coursesByOffering = [];
$releasedGradesByOffering = [];
$error = "";
$studentMissing = false;
$studentLrn = "";
$studentName = $_SESSION["name"] ?? "Student";
$studentFileName = "Student";
$studentTrackId = 0;
$studentGradeLevel = 0;
$studentSectionName = "—";
$studentSchoolYear = "—";
$curriculumSubjects = [];
$selectedCurriculumTerm = (int) ($_GET["term"] ?? 1);
if (!in_array($selectedCurriculumTerm, [1, 2, 3], true)) $selectedCurriculumTerm = 1;

try {
    $scoresQuery = $conn->prepare(
        "SELECT DISTINCT class_subjects.class_subject_id, class_subjects.school_year,
                sections.section_name, sections.grade_level,
                subjects.subject_id, subjects.track_id, subjects.subject_name, grading_components.component_id,
                grading_components.component_name, grading_components.weight_percentage,
                assessments.assessment_id, assessments.assessment_name,
                assessments.assessment_type, assessments.assessment_date,
                assessments.grading_period, assessments.max_score, student_scores.score
         FROM students
         JOIN enrollments ON enrollments.student_id = students.student_id
         JOIN class_subjects ON class_subjects.section_id = enrollments.section_id
            AND class_subjects.school_year = enrollments.school_year
         JOIN sections ON sections.section_id = enrollments.section_id
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         JOIN grading_components ON grading_components.class_subject_id = class_subjects.class_subject_id
         LEFT JOIN assessments ON assessments.class_subject_id = class_subjects.class_subject_id
            AND assessments.component_id = grading_components.component_id
         LEFT JOIN student_scores ON student_scores.assessment_id = assessments.assessment_id
            AND student_scores.student_id = students.student_id
         WHERE students.user_id = ?
         ORDER BY subjects.subject_name, class_subjects.school_year DESC,
                  grading_components.component_id, assessments.grading_period,
                  assessments.assessment_date DESC, assessments.assessment_id DESC"
    );
    $scoresQuery->bind_param("i", $userId);
    $scoresQuery->execute();
    $scoreRows = $scoresQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $scoresQuery->close();

    foreach ($scoreRows as $row) {
        $offeringId = (int) $row["class_subject_id"];
        if (!isset($coursesByOffering[$offeringId])) {
            $coursesByOffering[$offeringId] = [
                "subject_id" => (int) $row["subject_id"],
                "track_id" => $row["track_id"] === null ? null : (int) $row["track_id"],
                "subject_name" => $row["subject_name"],
                "school_year" => $row["school_year"],
                "section_name" => $row["section_name"],
                "grade_level" => $row["grade_level"],
                "components" => []
            ];
        }
        $componentId = (int) $row["component_id"];
        if (!isset($coursesByOffering[$offeringId]["components"][$componentId])) {
            $coursesByOffering[$offeringId]["components"][$componentId] = [
                "name" => $row["component_name"],
                "weight" => (float) $row["weight_percentage"],
                "activities" => []
            ];
        }
        if ($row["assessment_id"] !== null) {
            $activity = [
                "assessment_name" => $row["assessment_name"],
                "assessment_type" => $row["assessment_type"],
                "assessment_date" => $row["assessment_date"],
                "grading_period" => (int) $row["grading_period"],
                "max_score" => (float) $row["max_score"],
                "score" => $row["score"] === null ? null : (float) $row["score"]
            ];
            $coursesByOffering[$offeringId]["components"][$componentId]["activities"][] = $activity;
            if ($row["score"] !== null) {
                $scores[] = [
                    "subject_name" => $row["subject_name"],
                    "assessment_name" => $row["assessment_name"],
                    "assessment_type" => $row["assessment_type"],
                    "assessment_date" => $row["assessment_date"],
                    "grading_period" => (int) $row["grading_period"],
                    "score" => $row["score"],
                    "max_score" => $row["max_score"]
                ];
            }
        }
    }

    $studentQuery = $conn->prepare("SELECT students.student_id, students.track_id, users.lrn, users.first_name, users.middle_name, users.last_name FROM students JOIN users ON users.user_id = students.user_id WHERE students.user_id = ? LIMIT 1");
    $studentQuery->bind_param("i", $userId);
    $studentQuery->execute();
    $studentRecord = $studentQuery->get_result()->fetch_assoc();
    $studentMissing = !$studentRecord;
    $studentLrn = (string) ($studentRecord["lrn"] ?? "");
    if ($studentRecord) {
        $studentNameParts = array_filter([$studentRecord["first_name"], $studentRecord["middle_name"], $studentRecord["last_name"]], static function ($part) {
            return trim((string) $part) !== "";
        });
        $studentName = trim(implode(" ", $studentNameParts)) ?: ($studentName ?? "Student");
        $fileNameName = (string) $studentRecord["last_name"] . (string) $studentRecord["first_name"];
        if (function_exists("iconv")) $fileNameName = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $fileNameName) ?: "";
        $studentFileName = preg_replace('/[^A-Za-z0-9_-]/', "", $fileNameName) ?: "Student";
    }
    $studentQuery->close();

    if ($studentRecord) {
        $studentTrackId = (int) ($studentRecord["track_id"] ?? 0);
        $studentId = (int) $studentRecord["student_id"];
        $currentEnrollmentQuery = $conn->prepare(
            "SELECT sections.grade_level, sections.section_name, enrollments.school_year
             FROM enrollments
             JOIN sections ON sections.section_id = enrollments.section_id
             WHERE enrollments.student_id = ? AND enrollments.status = 'Enrolled'
             ORDER BY enrollments.enrollment_date DESC LIMIT 1"
        );
        $currentEnrollmentQuery->bind_param("i", $studentId);
        $currentEnrollmentQuery->execute();
        $currentEnrollment = $currentEnrollmentQuery->get_result()->fetch_assoc();
        $currentEnrollmentQuery->close();
        $studentGradeLevel = (int) ($currentEnrollment["grade_level"] ?? 0);
        $studentSectionName = (string) ($currentEnrollment["section_name"] ?? "—");
        $studentSchoolYear = (string) ($currentEnrollment["school_year"] ?? "—");
        if ($studentGradeLevel > 0) {
            $curriculumQuery = $conn->prepare(
                "SELECT subjects.subject_id, subjects.subject_name, subjects.track_id
                 FROM track_subject_assignments
                 JOIN subjects ON subjects.subject_id = track_subject_assignments.subject_id
                 WHERE track_subject_assignments.track_id = ?
                   AND track_subject_assignments.grade_level = ?
                   AND track_subject_assignments.semester = ?
                   AND subjects.is_archived = 0
                 ORDER BY subjects.track_id IS NULL, subjects.subject_name"
            );
            $curriculumQuery->bind_param("iii", $studentTrackId, $studentGradeLevel, $selectedCurriculumTerm);
            $curriculumQuery->execute();
            $curriculumSubjects = $curriculumQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $curriculumQuery->close();
        }
        $releasedGradesQuery = $conn->prepare(
            "SELECT latest.class_subject_id, latest.grading_period, latest.submission_id,
                    grade_records.initial_grade, component_records.component_id,
                    component_records.component_name_snapshot, component_records.weight_percentage,
                    component_records.percentage_score, component_records.weighted_contribution
             FROM (
                SELECT class_subject_id, grading_period, MAX(submission_id) AS submission_id
                FROM grading_term_submissions
                GROUP BY class_subject_id, grading_period
             ) AS latest
             JOIN grading_term_submissions AS release_status
                ON release_status.submission_id = latest.submission_id
                AND release_status.status = 'Finalized'
             JOIN student_term_grade_records AS grade_records
                ON grade_records.submission_id = latest.submission_id
             LEFT JOIN student_term_component_records AS component_records
                ON component_records.submission_id = latest.submission_id
                AND component_records.student_id = grade_records.student_id
             WHERE grade_records.student_id = ?"
        );
        $studentId = (int) $studentRecord["student_id"];
        $releasedGradesQuery->bind_param("i", $studentId);
        $releasedGradesQuery->execute();
        $releasedGradeRows = $releasedGradesQuery->get_result();
        while ($releasedGradeRow = $releasedGradeRows->fetch_assoc()) {
            $offeringId = (int) $releasedGradeRow["class_subject_id"];
            $period = (int) $releasedGradeRow["grading_period"];
            if (!isset($releasedGradesByOffering[$offeringId][$period])) {
                $releasedGradesByOffering[$offeringId][$period] = [
                    "submission_id" => (int) $releasedGradeRow["submission_id"],
                    "initial_grade" => (float) $releasedGradeRow["initial_grade"],
                    "components" => []
                ];
            }
            if ($releasedGradeRow["component_id"] !== null) {
                $componentId = (int) $releasedGradeRow["component_id"];
                $releasedGradesByOffering[$offeringId][$period]["components"][$componentId] = [
                    "component_name" => $releasedGradeRow["component_name_snapshot"],
                    "weight" => (float) $releasedGradeRow["weight_percentage"],
                    "percentage" => (float) $releasedGradeRow["percentage_score"],
                    "weighted_contribution" => (float) $releasedGradeRow["weighted_contribution"]
                ];
            }
        }
        $releasedGradesQuery->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log("Student scores page error: " . $e->getMessage());
    $error = "We could not load your scores right now.";
}

$isPossibleGradesPage = !empty($renderPossibleGradesPage);
$renderPossibleGradesPdf = !empty($downloadPossibleGradesPdf);
$courseResults = [];
foreach ($coursesByOffering as $offeringId => $course) {
    $weightTotal = 0.0;
    foreach ($course["components"] as $component) {
        if ($component["weight"] > 0) {
            $weightTotal += $component["weight"];
        }
    }
    $terms = [];
    for ($period = 1; $period <= 3; $period++) {
        $termComplete = abs($weightTotal - 100.0) < 0.01;
        $weightedTotal = 0.0;
        $termHasRecords = false;
        $componentResults = [];
        foreach ($course["components"] as $componentId => $component) {
            $activities = array_values(array_filter(
                $component["activities"],
                static fn($activity) => $activity["grading_period"] === $period
            ));
            $possible = 0.0;
            $earned = 0.0;
            $allScored = count($activities) > 0;
            foreach ($activities as $activity) {
                $possible += $activity["max_score"];
                if ($activity["score"] === null) {
                    $allScored = false;
                } else {
                    $earned += $activity["score"];
                }
            }
            $percentage = $allScored && $possible > 0 ? ($earned / $possible) * 100 : null;
            if (count($activities) > 0) {
                $termHasRecords = true;
            }
            if ($component["weight"] > 0) {
                if (!$allScored || $percentage === null) {
                    $termComplete = false;
                } else {
                    $weightedTotal += $percentage * ($component["weight"] / 100);
                }
            }
            $componentResults[$componentId] = [
                "percentage" => $percentage,
                "weight" => $component["weight"],
                "weighted_score" => $percentage === null ? null : $percentage * ($component["weight"] / 100),
                "has_activities" => count($activities) > 0,
                "all_scored" => $allScored
            ];
        }
        $releasedTerm = $releasedGradesByOffering[$offeringId][$period] ?? null;
        $terms[$period] = [
            "has_records" => $termHasRecords || $releasedTerm !== null,
            "released" => $releasedTerm !== null,
            "complete" => $releasedTerm !== null,
            "initial_grade" => $releasedTerm["initial_grade"] ?? null,
            "components" => $componentResults,
            "released_components" => $releasedTerm["components"] ?? []
        ];
    }
    $termsForFinalGrade = array_filter($terms, static fn($term) => $term["has_records"]);
    $allTermsComplete = count($termsForFinalGrade) > 0;
    $finalTotal = 0.0;
    foreach ($termsForFinalGrade as $term) {
        if (!$term["released"] || $term["initial_grade"] === null) {
            $allTermsComplete = false;
            break;
        }
        $finalTotal += $term["initial_grade"];
    }
    $courseResults[$offeringId] = [
        "subject_id" => (int) ($course["subject_id"] ?? 0),
        "track_id" => $course["track_id"] ?? null,
        "subject_name" => $course["subject_name"],
        "school_year" => $course["school_year"],
        "section_name" => $course["section_name"] ?? "",
        "grade_level" => $course["grade_level"] ?? "",
        "weight_total" => $weightTotal,
        "components" => $course["components"],
        "terms" => $terms,
        "final_grade" => $allTermsComplete ? $finalTotal / count($termsForFinalGrade) : null
    ];
}
$assignedSubjectIds = array_fill_keys(array_map(
    static fn($subject) => (int) $subject["subject_id"],
    $curriculumSubjects
), true);
$allCourseResults = array_filter(
    $courseResults,
    static fn($courseResult) => isset($assignedSubjectIds[(int) ($courseResult["subject_id"] ?? 0)])
);
$selectedTermSubjects = [];
foreach ($curriculumSubjects as $curriculumSubject) {
    $subjectId = (int) $curriculumSubject["subject_id"];
    $selectedTermSubjects[$subjectId] = [
        "subject_id" => $subjectId,
        "subject_name" => $curriculumSubject["subject_name"],
        "section_name" => $studentSectionName,
        "school_year" => $studentSchoolYear,
        "grade" => "Not released",
        "completion" => "Planned",
        "course_offering_id" => null
    ];
}
foreach ($allCourseResults as $offeringId => $courseResult) {
    $subjectId = (int) ($courseResult["subject_id"] ?? 0);
    if ($subjectId <= 0) continue;
    if (!isset($selectedTermSubjects[$subjectId])) continue;
    if ($selectedTermSubjects[$subjectId]["course_offering_id"] === null) {
        if ($selectedTermSubjects[$subjectId]["school_year"] === "—") {
            $selectedTermSubjects[$subjectId]["section_name"] = $courseResult["section_name"] ?: "—";
            $selectedTermSubjects[$subjectId]["school_year"] = $courseResult["school_year"];
        }
        $selectedTermSubjects[$subjectId]["course_offering_id"] = (int) $offeringId;
    }
    $selectedTermResult = $courseResult["terms"][$selectedCurriculumTerm];
    if ($selectedTermResult["has_records"] && $selectedTermSubjects[$subjectId]["completion"] === "Planned") {
        $selectedTermSubjects[$subjectId]["grade"] = !$selectedTermResult["released"]
            ? "Not released"
            : number_format((float) $selectedTermResult["initial_grade"], $selectedTermResult["released"] ? 0 : 2);
        $selectedTermSubjects[$subjectId]["completion"] = $selectedTermResult["released"] ? "Complete" : "Pending release";
    }
}
$courseResults = array_filter($courseResults, static function ($courseResult) {
    foreach ($courseResult["terms"] as $term) {
        if ($term["has_records"]) return true;
    }
    return false;
});
$termsWithRecords = [1 => false, 2 => false, 3 => false];
foreach ($courseResults as $courseResult) {
    foreach ($courseResult["terms"] as $period => $term) {
        if ($term["has_records"]) $termsWithRecords[$period] = true;
    }
}
if ($renderPossibleGradesPdf) {
    require_once __DIR__ . "/../includes/student_grade_pdf.php";
    $sectionLabel = $studentGradeLevel > 0 && $studentSectionName !== "—" ? "Grade " . $studentGradeLevel . " " . $studentSectionName : $studentSectionName;
    streamStudentGradePdf($allCourseResults, $termsWithRecords, $studentName, $studentLrn, $studentFileName, __DIR__ . "/../logo.png", $selectedCurriculumTerm, $sectionLabel);
}
$subjects = [];
$assessmentTypes = array_fill_keys(["Quiz", "Activity", "Assignment", "Project", "Exam", "Other"], true);
foreach ($scores as $score) {
    $subjects[$score["subject_name"]] = true;
    $type = trim((string) ($score["assessment_type"] ?? "")) ?: "Other";
    $assessmentTypes[$type] = true;
}
$subjects = array_keys($subjects);
$assessmentTypes = array_keys($assessmentTypes);
sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);
sort($assessmentTypes, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('student/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View your recorded assessment scores in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title><?= $isPossibleGradesPage ? "Grades" : "My Scores" ?> | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="css/scores.css?v=<?= (int) filemtime(__DIR__ . "/css/scores.css") ?>">
    <?php 
if ($isPossibleGradesPage): ?><link rel="stylesheet" href="css/possible-grades.css?v=<?= (int) filemtime(__DIR__ . "/css/possible-grades.css") ?>"><?php 
endif; ?>
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="student-scores-page<?= $isPossibleGradesPage ? " student-possible-grades-page" : "" ?>">
    <header class="topbar">
        <a class="brand" href="../student-dashboard">
            <img src="../logo.png" alt="">
            <span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span>
        </a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu">
                <span></span><span></span><span></span>
            </button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user"><strong><?= h($studentName) ?></strong><span>Student</span></div>
                <a class="menu-link" href="../student-dashboard">Dashboard</a>
                <a class="menu-link <?= !$isPossibleGradesPage ? "active" : "" ?>" href="../student-scores" <?= !$isPossibleGradesPage ? "aria-current=\"page\"" : "" ?>>Scores</a>
                <a class="menu-link <?= $isPossibleGradesPage ? "active" : "" ?>" href="../student-possible-grades" <?= $isPossibleGradesPage ? "aria-current=\"page\"" : "" ?>>Grades</a>
                <a class="menu-link" href="../student-messages">Contact</a>
                <a class="menu-link" href="../student-profile">My Profile</a>
                <a class="menu-link signout-link" href="../logout.php">Sign out</a>
            </nav>
        </div>
    </header>

    <main class="dashboard scores-dashboard">
        <section class="scores-hero">
            <div><p class="eyebrow"><?= $isPossibleGradesPage ? "ACADEMIC OVERVIEW" : "STUDENT PORTAL" ?></p><h1><?= $isPossibleGradesPage ? "Grades" : "My Scores" ?></h1><p><?= $isPossibleGradesPage ? "Review your term results and see how each grade is calculated." : "Track your assessment results across every subject." ?></p><?php 
if ($isPossibleGradesPage && $courseResults): ?><div class="scores-page-actions"><a class="scores-download-pdf" href="../student-download-possible-grades?term=<?= $selectedCurriculumTerm ?>">Download PDF</a></div><?php 
endif; ?></div>
            <div class="scores-hero-mark" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="M8 38V10m0 28h34M15 31l8-9 7 5 11-14"></path><circle cx="15" cy="31" r="2"></circle><circle cx="23" cy="22" r="2"></circle><circle cx="30" cy="27" r="2"></circle><circle cx="41" cy="13" r="2"></circle></svg></div>
        </section>

        <?php 
if ($error !== ""): ?>
            <p class="alert error"><?= h($error) ?></p>
        <?php 
elseif ($studentMissing): ?>
            <p class="alert">No student profile is linked to this account yet. Please contact the administrator.</p>
        <?php 
elseif (!$isPossibleGradesPage && count($scores) === 0): ?>
            <section class="scores-panel scores-empty"><div class="scores-empty-icon" aria-hidden="true">✓</div><h2><?= $isPossibleGradesPage ? "No grades yet" : "No scores yet" ?></h2><p><?= $isPossibleGradesPage ? "Your grade overview will appear here once assessment results are recorded." : "Your assessment results will appear here once they are recorded." ?></p></section>
        <?php 
else: ?>
            <?php 
if ($isPossibleGradesPage): ?>
                <nav class="student-grades-term-nav" aria-label="Choose curriculum term">
                    <?php 
for ($period = 1; $period <= 3; $period++): ?>
                        <a href="../student-possible-grades?term=<?= $period ?>" class="<?= $selectedCurriculumTerm === $period ? "active" : "" ?>" <?= $selectedCurriculumTerm === $period ? "aria-current=\"page\"" : "" ?>>Term <?= $period ?></a>
                    <?php 
endfor; ?>
                </nav>
                <section class="panel scores-panel scores-possible-grades-page-panel">
                    <div class="scores-possible-grades-content">
                        <div class="table-wrap">
                            <table class="scores-possible-grades-table student-term-subject-grades-table">
                                <thead><tr><th>Subject Title</th><th>Section</th><th>School Year</th><th>Grade</th></tr></thead>
                                <tbody>
                                    <?php 
foreach ($selectedTermSubjects as $subjectRow): ?>
                                        <?php 
$calculationTargetId = $subjectRow["course_offering_id"] !== null ? "grade-calculation-" . (int) $subjectRow["course_offering_id"] : "grade-calculation-subject-" . (int) $subjectRow["subject_id"]; ?>
                                            <tr class="scores-course-subject-row" <?= $subjectRow["course_offering_id"] !== null ? "data-course-id=\"" . (int) $subjectRow["course_offering_id"] . "\"" : "" ?> tabindex="0" role="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?= h($calculationTargetId) ?>" aria-label="View <?= h($subjectRow["subject_name"]) ?> grade details">
                                            <td><strong><?= h($subjectRow["subject_name"]) ?></strong></td>
                                            <td><?= h($subjectRow["section_name"]) ?></td>
                                            <td><?= h($subjectRow["school_year"]) ?></td>
                                            <td><strong><?= h($subjectRow["grade"]) ?></strong></td>
                                        </tr>
                                    <?php 
endforeach; ?>
                                    <?php 
if (!$selectedTermSubjects): ?><tr><td colspan="4" class="student-term-no-grades">No subjects are assigned to this term yet.</td></tr><?php 
endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="grades-calculation-modal" id="grades-calculation-modal" hidden>
                            <section class="grades-calculation-dialog" role="dialog" aria-modal="true" aria-labelledby="grades-calculation-modal-title" tabindex="-1">
                                <header class="grades-calculation-modal-header"><div><p class="eyebrow">TRANSPARENT GRADING</p><h2 id="grades-calculation-modal-title">Grade calculations</h2></div><button class="grades-calculation-close" type="button" aria-label="Close calculations">&times;</button></header>
                                <div class="grades-calculation-modal-body">
                        <div class="scores-grade-solutions" aria-live="polite">
                            <?php 
foreach ($allCourseResults as $offeringId => $courseResult): ?>
                                <div class="scores-grade-course-solution" id="grade-calculation-<?= (int) $offeringId ?>" hidden>
                                    <div class="scores-grade-course-content">
                                        <h3 class="scores-grade-course-title"><?= h($courseResult["subject_name"]) ?> <span><?= h($courseResult["school_year"]) ?></span></h3>
                                        <p class="scores-grade-formula">For each component: <strong>earned points ÷ possible points × 100</strong>, then multiply by the component weight. The term grade is the sum of weighted contributions.</p>
                                        <?php 
foreach ($courseResult["terms"] as $period => $termResult): ?>
                                            <?php 
if (!$termResult["has_records"]) continue; ?>
                                            <section class="scores-grade-term-solution">
                                                <h3>Term <?= (int) $period ?><span><?= !$termResult["released"] ? "Not released" : "Released grade: " . h(number_format($termResult["initial_grade"], 0)) ?></span></h3>
                                                <?php 
if (!$termResult["released"]): ?>
                                                    <p class="scores-grade-component-calculation">Your teacher has not released this term grade yet.</p>
                                                    <div class="scores-grade-weight-list" aria-label="Weighted components">
                                                        <h4>Weighted components</h4>
                                                        <ul>
                                                            <?php 
foreach ($courseResult["components"] as $component): ?>
                                                                <?php 
if ($component["weight"] <= 0) continue; ?>
                                                                <li><span><?= h($component["name"]) ?></span><strong><?= h(number_format($component["weight"], 2)) ?>%</strong></li>
                                                            <?php 
endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php 
else: ?>
                                                    <?php 
foreach ($termResult["released_components"] as $componentId => $releasedComponent): ?>
                                                        <?php
                                                        
$componentActivities = array_values(array_filter(
                                                            $courseResult["components"][$componentId]["activities"] ?? [],
                                                            static fn($activity) => (int) $activity["grading_period"] === (int) $period
                                                        ));
                                                        $earnedPoints = 0.0;
                                                        $possiblePoints = 0.0;
                                                        foreach ($componentActivities as $componentActivity) {
                                                            $earnedPoints += (float) ($componentActivity["score"] ?? 0);
                                                            $possiblePoints += (float) $componentActivity["max_score"];
                                                        }
                                                        ?>
                                                        <details class="scores-grade-component-solution">
                                                            <summary><?= h($releasedComponent["component_name"]) ?><span><?= h(number_format($releasedComponent["weight"], 2)) ?>% weight</span></summary>
                                                            <?php 
if ($componentActivities): ?>
                                                                <div class="scores-grade-assessment-list" aria-label="Assessment scores">
                                                                    <?php 
foreach ($componentActivities as $componentActivity): ?>
                                                                        <div class="scores-grade-assessment-row"><span><?= h($componentActivity["assessment_name"]) ?></span><strong><?= h(rtrim(rtrim(number_format((float) ($componentActivity["score"] ?? 0), 2, ".", ""), "0"), ".")) ?> / <?= h(rtrim(rtrim(number_format((float) $componentActivity["max_score"], 2, ".", ""), "0"), ".")) ?></strong></div>
                                                                    <?php 
endforeach; ?>
                                                                </div>
                                                                <p class="scores-grade-component-calculation">Component calculation: <strong><?= h(number_format($earnedPoints, 2)) ?> ÷ <?= h(number_format($possiblePoints, 2)) ?> × 100 = <?= h(number_format($releasedComponent["percentage"], 2)) ?>%</strong>. Weighted contribution: <strong><?= h(number_format($releasedComponent["percentage"], 2)) ?>% × <?= h(number_format($releasedComponent["weight"], 2)) ?>% = <?= h(number_format($releasedComponent["weighted_contribution"], 2)) ?></strong>.</p>
                                                            <?php 
else: ?>
                                                                <p class="scores-grade-component-calculation">Released component score: <strong><?= h(number_format($releasedComponent["percentage"], 2)) ?>%</strong>. Weighted contribution: <strong><?= h(number_format($releasedComponent["weighted_contribution"], 2)) ?></strong>.</p>
                                                            <?php 
endif; ?>
                                                        </details>
                                                    <?php 
endforeach; ?>
                                                    <p class="scores-grade-term-calculation">Released term grade: <strong><?= h(number_format($termResult["initial_grade"], 0)) ?></strong></p>
                                                <?php 
endif; ?>
                                            </section>
                                        <?php 
endforeach; ?>
                                        <p class="scores-grade-final-calculation">Final grade = average of terms with assessments = <strong><?= $courseResult["final_grade"] === null ? "Not released" : h(number_format($courseResult["final_grade"], 2)) ?></strong><?php 
if ($courseResult["final_grade"] === null): ?><span>Your teacher must release every term with assessments before your final grade appears.</span><?php 
endif; ?></p>
                                    </div>
                                </div>
                            <?php 
endforeach; ?>
                            <?php 
foreach ($selectedTermSubjects as $subjectRow): ?>
                                <?php 
if ($subjectRow["course_offering_id"] !== null) continue; ?>
                                <div class="scores-grade-course-solution" id="grade-calculation-subject-<?= (int) $subjectRow["subject_id"] ?>" hidden>
                                    <div class="scores-grade-course-content">
                                        <h3 class="scores-grade-course-title"><?= h($subjectRow["subject_name"]) ?></h3>
                                        <p class="scores-grade-component-calculation">Grade details are not available for this subject yet.</p>
                                    </div>
                                </div>
                            <?php 
endforeach; ?>
                        </div>
                                </div>
                            </section>
                        </div>
                        <p class="scores-possible-grades-note">Term grades appear after your teacher releases them. Your final grade appears after every term with assessments is released.</p>
                    </div>
                </section>
            <?php 
else: ?>
            <section class="panel scores-panel">
                <div class="scores-panel-heading">
                    <div><p class="eyebrow">RESULTS OVERVIEW</p><h2>Assessment records</h2><p>Use the filters to focus on a subject or assessment type.</p></div>
                    <?php 
if ($courseResults): ?><a class="scores-possible-grades-trigger" href="../student-possible-grades">Possible Grades</a><?php 
endif; ?>
                </div>
                <form action="../student-possible-grades" class="scores-filters" id="scores-filters" aria-label="Filter scores">
                    <label>Subject
                        <select id="scores-subject-filter" name="subject">
                            <option value="">All subjects</option>
                            <?php 
foreach ($subjects as $subject): ?><option value="<?= h($subject) ?>"><?= h($subject) ?></option><?php 
endforeach; ?>
                        </select>
                    </label>
                    <label>Assessment type
                        <select id="scores-type-filter" name="assessment_type">
                            <option value="">All types</option>
                            <?php 
foreach ($assessmentTypes as $type): ?><option value="<?= h($type) ?>"><?= h($type) ?></option><?php 
endforeach; ?>
                        </select>
                    </label>
                    <label>Term
                        <select id="scores-period-filter" name="grading_period">
                            <option value="">All terms</option>
                            <?php 
for ($period = 1; $period <= 3; $period++): ?><option value="<?= $period ?>">Term <?= $period ?></option><?php 
endfor; ?>
                        </select>
                    </label>
                    <button type="reset" class="scores-clear-filter">Clear filters</button>
                </form>
                <div class="table-wrap scores-table-wrap">
                    <table class="scores-table">
                        <thead><tr><th>Subject</th><th>Assessment</th><th>Type</th><th>Term</th><th>Status</th><th>Score</th></tr></thead>
                        <tbody>
                            <?php 
foreach ($scores as $score): ?>
                                <tr data-subject="<?= h($score["subject_name"]) ?>" data-type="<?= h(trim((string) ($score["assessment_type"] ?? "")) ?: "Other") ?>" data-period="<?= (int) $score["grading_period"] ?>">
                                    <td><span class="scores-subject-name"><?= h($score["subject_name"]) ?></span></td>
                                    <td><?= h($score["assessment_name"]) ?></td>
                                    <td><span class="scores-type-tag"><?= h($score["assessment_type"] ?: "Other") ?></span></td>
                                    <td>Term <?= (int) $score["grading_period"] ?></td>
                                    <?php 
$scorePassed = (float) $score["max_score"] > 0 && ((float) $score["score"] / (float) $score["max_score"]) >= 0.70; ?>
                                    <td><span class="scores-pass-status <?= $scorePassed ? "is-pass" : "is-fail" ?>"><?= $scorePassed ? "Pass" : "Failed" ?></span></td>
                                    <td><strong class="scores-result"><?= h($score["score"]) ?> <span>/ <?= h($score["max_score"]) ?></span></strong></td>
                                </tr>
                            <?php 
endforeach; ?>
                        </tbody>
                    </table>
                    <p class="scores-no-match" id="scores-no-match" hidden>No scores match those filters.</p>
                </div>
            </section>
            <?php 
endif; ?>
        <?php 
endif; ?>
    </main>
    <?php 
if ($scores && !$isPossibleGradesPage): ?>
    <script>
        (function () {
            const subjectFilter = document.getElementById("scores-subject-filter");
            const typeFilter = document.getElementById("scores-type-filter");
            const periodFilter = document.getElementById("scores-period-filter");
            const form = document.getElementById("scores-filters");
            const rows = Array.from(document.querySelectorAll(".scores-table tbody tr"));
            const noMatch = document.getElementById("scores-no-match");
            function applyFilters() {
                const subject = subjectFilter.value;
                const type = typeFilter.value;
                const period = periodFilter.value;
                let visible = 0;
                rows.forEach(function (row) {
                    const matches = (!subject || row.dataset.subject === subject) && (!type || row.dataset.type === type) && (!period || row.dataset.period === period);
                    row.hidden = !matches;
                    if (matches) visible++;
                });
                noMatch.hidden = visible !== 0;
            }
            subjectFilter.addEventListener("change", applyFilters);
            typeFilter.addEventListener("change", applyFilters);
            periodFilter.addEventListener("change", applyFilters);
            form.addEventListener("reset", function () { window.setTimeout(applyFilters, 0); });
        }());
    </script>
    <?php 
endif; ?>
    <?php 
if ($isPossibleGradesPage && $selectedTermSubjects): ?>
    <script>
        (function () {
            const subjectRows = Array.from(document.querySelectorAll(".scores-course-subject-row"));
            const calculationPanels = Array.from(document.querySelectorAll(".scores-grade-course-solution"));
            const modal = document.getElementById("grades-calculation-modal");
            const dialog = modal.querySelector(".grades-calculation-dialog");
            const closeButton = modal.querySelector(".grades-calculation-close");
            let activeRow = null;
            function openCalculation(row) {
                const target = document.getElementById(row.getAttribute("aria-controls"));
                if (!target) return;
                calculationPanels.forEach(function (panel) { panel.hidden = true; });
                subjectRows.forEach(function (item) { item.setAttribute("aria-expanded", "false"); });
                target.hidden = false;
                activeRow = row;
                row.setAttribute("aria-expanded", "true");
                modal.hidden = false;
                document.body.classList.add("grades-modal-open");
                closeButton.focus();
            }
            function closeCalculation() {
                modal.hidden = true;
                calculationPanels.forEach(function (panel) { panel.hidden = true; });
                subjectRows.forEach(function (item) { item.setAttribute("aria-expanded", "false"); });
                document.body.classList.remove("grades-modal-open");
                if (activeRow) activeRow.focus();
                activeRow = null;
            }
            subjectRows.forEach(function (row) {
                row.addEventListener("click", function () { openCalculation(row); });
                row.addEventListener("keydown", function (event) {
                    if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        openCalculation(row);
                    }
                });
            });
            closeButton.addEventListener("click", closeCalculation);
            modal.addEventListener("click", function (event) {
                if (event.target === modal) closeCalculation();
            });
            document.addEventListener("keydown", function (event) {
                if (!modal.hidden && event.key === "Escape") closeCalculation();
            });
        }());
    </script>
    <?php 
endif; ?>
</body>
</html>

