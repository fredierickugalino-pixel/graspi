<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/enrollment_schema.php";
ensureEnrollmentStatusSchema($conn);
require_once __DIR__ . "/../includes/section_schema.php";
ensureSectionSchema($conn);

$counts = [
    "students" => 0,
    "teachers" => 0,
    "sections" => 0,
    "tracks" => 0,
    "enrollments" => 0
];
$recentEnrollments = [];
$assessmentScoresBySection = [];
$assessmentTopStudents = [];
$assessmentTypes = ["Quiz", "Activity", "Assignment", "Project", "Exam", "Other"];
$assessmentTypeInput = $_GET["assessment_type"] ?? "Quiz";
$selectedAssessmentType = is_string($assessmentTypeInput) && in_array($assessmentTypeInput, $assessmentTypes, true)
    ? $assessmentTypeInput
    : "Quiz";
$enrollmentTotal = 0;
$pageSize = 10;
$page = max(1, (int) ($_GET["page"] ?? 1));
$gradeFilterInput = $_GET["grade_level"] ?? "all";
$gradeFilter = is_string($gradeFilterInput) && in_array($gradeFilterInput, ["all", "11", "12"], true)
    ? $gradeFilterInput
    : "all";
$gradeLevelFilter = $gradeFilter === "all" ? 0 : (int) $gradeFilter;
$studentSearchInput = $_GET["search"] ?? "";
$studentSearch = is_string($studentSearchInput) ? trim(mb_substr($studentSearchInput, 0, 100)) : "";
$studentSearchPattern = "%" . $studentSearch . "%";
$pageCount = 1;
$error = "";
$manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$schoolYearStart = (int) $manilaNow->format("Y");
if ((int) $manilaNow->format("n") < 6) {
    $schoolYearStart--;
}
$schoolYear = $schoolYearStart . "-" . ($schoolYearStart + 1);

try {
    $countQuery = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM students) AS students,
            (SELECT COUNT(*) FROM teachers) AS teachers,
            (SELECT COUNT(*) FROM sections) AS sections,
            (SELECT COUNT(*) FROM enrollments WHERE status = 'Enrolled') AS enrollments"
    );
    $counts = $countQuery->fetch_assoc();

    $enrollmentCountQuery = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM enrollments
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
         LEFT JOIN sections ON sections.section_id = enrollments.section_id
         WHERE enrollments.status = 'Enrolled'
           AND users.approval_status = 'approved'
           AND (? = 'all' OR sections.grade_level = ?)
           AND (? = '' OR CONCAT(users.first_name, ' ', users.last_name) LIKE ? OR users.lrn LIKE ?)"
    );
    $enrollmentCountQuery->bind_param("sisss", $gradeFilter, $gradeLevelFilter, $studentSearch, $studentSearchPattern, $studentSearchPattern);
    $enrollmentCountQuery->execute();
    $enrollmentTotal = (int) ($enrollmentCountQuery->get_result()->fetch_assoc()["total"] ?? 0);
    $enrollmentCountQuery->close();

    $pageCount = max(1, (int) ceil($enrollmentTotal / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;
    $recentQuery = $conn->prepare(
        "SELECT students.student_id, users.first_name, users.last_name, users.lrn,
                sections.section_name, sections.grade_level
         FROM enrollments
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
         LEFT JOIN sections ON sections.section_id = enrollments.section_id
         WHERE enrollments.status = 'Enrolled'
           AND users.approval_status = 'approved'
           AND (? = 'all' OR sections.grade_level = ?)
           AND (? = '' OR CONCAT(users.first_name, ' ', users.last_name) LIKE ? OR users.lrn LIKE ?)
         ORDER BY enrollments.enrollment_date DESC, enrollments.enrollment_id DESC
         LIMIT ? OFFSET ?"
    );
    $recentQuery->bind_param("sisssii", $gradeFilter, $gradeLevelFilter, $studentSearch, $studentSearchPattern, $studentSearchPattern, $pageSize, $offset);
    $recentQuery->execute();
    $recentEnrollments = $recentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentQuery->close();

    $assessmentScoreQuery = $conn->prepare(
        "SELECT sections.section_id, sections.section_name, sections.grade_level,
                COALESCE(ROUND(
                    100 * SUM(student_scores.score) / NULLIF(SUM(assessments.max_score), 0),
                    1
                ), 0) AS assessment_percentage
         FROM sections
         LEFT JOIN class_subjects ON class_subjects.section_id = sections.section_id
         LEFT JOIN assessments
            ON assessments.class_subject_id = class_subjects.class_subject_id
            AND assessments.assessment_type = ?
         LEFT JOIN student_scores ON student_scores.assessment_id = assessments.assessment_id
         GROUP BY sections.section_id, sections.section_name, sections.grade_level
         ORDER BY assessment_percentage DESC, sections.grade_level, sections.section_name
         LIMIT 3"
    );
    $assessmentScoreQuery->bind_param("s", $selectedAssessmentType);
    $assessmentScoreQuery->execute();
    $assessmentScoresBySection = $assessmentScoreQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $assessmentScoreQuery->close();

    $topStudentQuery = $conn->prepare(
        "SELECT students.student_id, users.first_name, users.last_name,
                sections.section_name, sections.grade_level,
                COALESCE(ROUND(
                    100 * SUM(student_scores.score) / NULLIF(SUM(assessments.max_score), 0),
                    1
                ), 0) AS assessment_percentage
         FROM student_scores
         JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
         JOIN class_subjects ON class_subjects.class_subject_id = assessments.class_subject_id
         JOIN sections ON sections.section_id = class_subjects.section_id
         JOIN students ON students.student_id = student_scores.student_id
         JOIN users ON users.user_id = students.user_id
         WHERE assessments.assessment_type = ?
         GROUP BY students.student_id, users.first_name, users.last_name,
                  sections.section_id, sections.section_name, sections.grade_level
         ORDER BY assessment_percentage DESC, users.last_name, users.first_name
         LIMIT 10"
    );
    $topStudentQuery->bind_param("s", $selectedAssessmentType);
    $topStudentQuery->execute();
    $assessmentTopStudents = $topStudentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $topStudentQuery->close();

} catch (mysqli_sql_exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $error = "We could not load the school information right now.";
}

$adminName = $_SESSION["name"] ?? "Administrator";
$pageStart = $enrollmentTotal > 0 ? (($page - 1) * $pageSize) + 1 : 0;
$pageEnd = min($page * $pageSize, $enrollmentTotal);
$pageLink = static function (int $targetPage) use ($gradeFilter, $studentSearch): string {
    return "../admin-dashboard?" . http_build_query([
        "grade_level" => $gradeFilter,
        "search" => $studentSearch,
        "page" => $targetPage
    ]);
};
$firstPageLink = $pageLink(1);
$previousPageLink = $pageLink(max(1, $page - 1));
$nextPageLink = $pageLink(min($pageCount, $page + 1));
$lastPageLink = $pageLink($pageCount);
$visiblePageStart = max(1, min($page - 1, $pageCount - 2));
$visiblePageEnd = min($pageCount, $visiblePageStart + 2);
$visiblePages = range($visiblePageStart, $visiblePageEnd);
$greeting = match (true) {
    (int) $manilaNow->format("G") < 12 => "GOOD MORNING",
    (int) $manilaNow->format("G") < 18 => "GOOD AFTERNOON",
    default => "GOOD EVENING"
};
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage school users and records in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Admin Dashboard | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="dashboard.css?v=<?= (int) filemtime(__DIR__ . "/dashboard.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="../admin-dashboard">
            <img src="../logo.png" alt="">
            <span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span>
        </a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <a class="admin-profile-icon" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user">
                    <strong><?= h($_SESSION["name"] ?? "User") ?></strong>
                    <span>Admin</span>
                </div>
                <a class="menu-link" href="../admin-dashboard">Dashboard</a>
                <a class="menu-link" href="../admin-section_assignments">Sections</a>
                <a class="menu-link" href="../admin-subjects">Subjects</a>
                <a class="menu-link" href="../admin-calendar">Calendar</a>
                <a class="menu-link" href="../admin-register">Teacher</a>
                <a class="menu-link" href="../registrar-add-students">Add students</a>
            </nav>
        </div>
    </header>

    <main class="dashboard">
        <div class="page-heading admin-welcome-banner">
            <div class="admin-welcome-copy">
                <p class="admin-welcome-greeting"><?= h($greeting) ?></p>
                <h1>Welcome, System Admin</h1>
                <p>School accounts, enrollment, and catalog overview.</p>
            </div>
            <div class="admin-welcome-date" aria-label="Current date in Manila">
                <p id="admin-manila-date"><?= h($manilaNow->format("l, F j, Y")) ?></p>
            </div>
        </div>

        <?php 
if ($error !== ""): ?><p class="alert error"><?= h($error) ?></p><?php 
endif; ?>
        <?php 
if ($error === ""): ?>
            <section class="summary-grid">
                <article class="summary-card">
                    <div class="admin-summary-card-heading"><span class="admin-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18M8 14h3M8 17h7"></path></svg></span><span class="summary-label">School year</span></div>
                    <strong><?= h($schoolYear) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="admin-summary-card-heading"><span class="admin-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20v-1a5.5 5.5 0 0 1 11 0v1M16 5.5a3 3 0 0 1 0 5.8M17 14a4.5 4.5 0 0 1 3.5 4.2V20"></path></svg></span><span class="summary-label">Teachers</span></div>
                    <strong><?= h($counts["teachers"]) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="admin-summary-card-heading"><span class="admin-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V8l7-4 7 4v12M9 20v-5h6v5M8 10h.01M12 10h.01M16 10h.01"></path></svg></span><span class="summary-label">Sections</span></div>
                    <strong><?= h($counts["sections"]) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="admin-summary-card-heading"><span class="admin-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20v-1a5.5 5.5 0 0 1 11 0v1M17 8h5M19.5 5.5v5M16 15h6v5h-6z"></path></svg></span><span class="summary-label">Active enrollments</span></div>
                    <strong><?= h($counts["enrollments"]) ?></strong>
                </article>
            </section>

            <div class="admin-dashboard-lower-grid">
            <div class="admin-student-column">
            <div class="admin-student-filter-bar">
                <form action="../admin-dashboard" class="admin-student-filter" method="get">
                    <select name="grade_level" id="student-grade-filter" aria-label="Filter students by grade level">
                        <option value="all" <?= $gradeFilter === "all" ? "selected" : "" ?>>All grades</option>
                        <option value="11" <?= $gradeFilter === "11" ? "selected" : "" ?>>Grade 11</option>
                        <option value="12" <?= $gradeFilter === "12" ? "selected" : "" ?>>Grade 12</option>
                    </select>
                    <input class="admin-student-search" type="search" name="search" value="<?= h($studentSearch) ?>" placeholder="Search name or LRN" aria-label="Search students by name or LRN" maxlength="100">
                    <button class="admin-student-search-button" type="submit" aria-label="Search students" title="Search students">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"></circle><path d="m15.5 15.5 5 5"></path></svg>
                    </button>
                </form>
                <?php 
if ($enrollmentTotal > 0): ?>
                    <nav class="student-pagination" aria-label="Student list pagination">
                        <div class="pagination-controls">
                            <?php 
if ($page > 1): ?>
                                <a href="<?= h($firstPageLink) ?>" aria-label="First page">«</a>
                                <a href="<?= h($previousPageLink) ?>" aria-label="Previous page">‹</a>
                            <?php 
else: ?>
                                <span class="disabled" aria-disabled="true">«</span><span class="disabled" aria-disabled="true">‹</span>
                            <?php 
endif; ?>
                            <?php 
foreach ($visiblePages as $visiblePage): ?>
                                <?php 
$visiblePageLink = $pageLink($visiblePage); ?>
                                <?php 
if ($visiblePage === $page): ?>
                                    <span class="current-page" aria-current="page"><?= $visiblePage ?></span>
                                <?php 
else: ?>
                                    <a href="<?= h($visiblePageLink) ?>"><?= $visiblePage ?></a>
                                <?php 
endif; ?>
                            <?php 
endforeach; ?>
                            <?php 
if ($page < $pageCount): ?>
                                <a href="<?= h($nextPageLink) ?>" aria-label="Next page">›</a>
                                <a href="<?= h($lastPageLink) ?>" aria-label="Last page">»</a>
                            <?php 
else: ?>
                                <span class="disabled" aria-disabled="true">›</span><span class="disabled" aria-disabled="true">»</span>
                            <?php 
endif; ?>
                        </div>
                    </nav>
                <?php 
endif; ?>
            </div>

            <section class="panel admin-student-list-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Student List</h2>
                    </div>
                </div>

                <?php 
if ($enrollmentTotal === 0): ?>
                    <p class="empty-state"><?php 
if ($studentSearch !== ""): ?>No students match “<?= h($studentSearch) ?>”<?= $gradeFilter === "all" ? "" : " in Grade " . h($gradeFilter) ?>.<?php 
elseif ($gradeFilter !== "all"): ?>There are no enrollments for Grade <?= h($gradeFilter) ?>.<?php 
else: ?>There are no enrollments to show yet.<?php 
endif; ?></p>
                <?php 
else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>LRN</th>
                                    <th>Level & Section</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
foreach ($recentEnrollments as $enrollment): ?>
                                    <tr class="admin-student-row" data-student-url="student_info.php?student_id=<?= (int) $enrollment["student_id"] ?>" tabindex="0" role="link" aria-label="View student profile: <?= h($enrollment["first_name"] . " " . $enrollment["last_name"]) ?>">
                                        <td><a class="admin-student-name-link" href="../admin-student_info?student_id=<?= (int) $enrollment["student_id"] ?>"><?= h($enrollment["first_name"] . " " . $enrollment["last_name"]) ?></a></td>
                                        <td><?= h($enrollment["lrn"]) ?></td>
                                        <td>
                                            Grade <?= h($enrollment["grade_level"]) ?> ·
                                            <?= h($enrollment["section_name"]) ?>
                                        </td>
                                    </tr>
                                <?php 
endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php 
endif; ?>
            </section>
            </div>
            <div class="admin-dashboard-charts">
                <form action="../admin-dashboard" class="admin-assessment-filter" method="get">
                    <input type="hidden" name="grade_level" value="<?= h($gradeFilter) ?>">
                    <input type="hidden" name="search" value="<?= h($studentSearch) ?>">
                        <label for="admin-assessment-type">Assessment type</label>
                    <select id="admin-assessment-type" name="assessment_type" aria-label="Choose assessment type">
                        <?php 
foreach ($assessmentTypes as $assessmentType): ?>
                            <option value="<?= h($assessmentType) ?>" <?= $selectedAssessmentType === $assessmentType ? "selected" : "" ?>><?= h($assessmentType) ?></option>
                        <?php 
endforeach; ?>
                    </select>
                </form>
                <section class="admin-quiz-chart" aria-labelledby="quiz-section-chart-title">
                    <div class="admin-quiz-chart-heading">
                        <div><p class="eyebrow">ASSESSMENT OVERVIEW</p><h2 id="quiz-section-chart-title"><?= h($selectedAssessmentType) ?> scores by section</h2></div>
                    </div>
                    <?php 
if (!$assessmentScoresBySection): ?>
                        <p class="admin-quiz-empty">No sections are available yet.</p>
                    <?php 
else: ?>
                        <div class="admin-quiz-bars" role="img" aria-label="Recorded <?= h(strtolower($selectedAssessmentType)) ?> score percentages for the top three sections">
                            <?php 
foreach ($assessmentScoresBySection as $sectionAssessmentScore): ?>
                                <?php 
$barPercent = min(100, max(0, (float) $sectionAssessmentScore["assessment_percentage"])); ?>
                                <div class="admin-quiz-bar-row">
                                    <span class="admin-quiz-bar-label"><?= h("G" . $sectionAssessmentScore["grade_level"] . " · " . $sectionAssessmentScore["section_name"]) ?></span>
                                    <span class="admin-quiz-bar-track"><span class="admin-quiz-bar-fill" style="width: <?= number_format($barPercent, 2, ".", "") ?>%"></span></span>
                                    <strong class="admin-quiz-bar-value"><?= h(number_format($barPercent, 1)) ?>%</strong>
                                </div>
                            <?php 
endforeach; ?>
                        </div>
                    <?php 
endif; ?>
                    <div class="admin-top-students-heading"><h3>Top 10 students · <?= h($selectedAssessmentType) ?></h3><span>Average percentage</span></div>
                    <?php 
if (!$assessmentTopStudents): ?>
                        <p class="admin-quiz-empty">No student scores are recorded for this assessment type.</p>
                    <?php 
else: ?>
                        <ol class="admin-top-students">
                            <?php 
foreach ($assessmentTopStudents as $studentIndex => $topStudent): ?>
                                <?php 
$studentPercent = min(100, max(0, (float) $topStudent["assessment_percentage"])); ?>
                                <li class="admin-top-student-row">
                                    <span class="admin-top-student-rank"><?= $studentIndex + 1 ?></span>
                                    <a class="admin-top-student-name admin-top-student-link" href="../admin-student_info?student_id=<?= (int) $topStudent["student_id"] ?>"><?= h($topStudent["last_name"] . ", " . $topStudent["first_name"]) ?><small><?= h("Grade " . $topStudent["grade_level"] . " · " . $topStudent["section_name"]) ?></small></a>
                                    <span class="admin-top-student-track"><span style="width: <?= number_format($studentPercent, 2, ".", "") ?>%"></span></span>
                                    <strong class="admin-top-student-percent"><?= h(number_format($studentPercent, 1)) ?>%</strong>
                                </li>
                            <?php 
endforeach; ?>
                        </ol>
                    <?php 
endif; ?>
                </section>
            </div>
            </div>
        <?php 
endif; ?>
    </main>
    <script>
        document.querySelectorAll(".admin-student-row[data-student-url]").forEach(function (row) {
            row.addEventListener("click", function (event) {
                if (event.target.closest("a, button, input, select, textarea")) return;
                window.location.href = row.dataset.studentUrl;
            });
            row.addEventListener("keydown", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    window.location.href = row.dataset.studentUrl;
                }
            });
        });
        const adminAssessmentType = document.getElementById("admin-assessment-type");
        adminAssessmentType?.addEventListener("change", function () {
            adminAssessmentType.form.requestSubmit();
        });
        const studentGradeFilter = document.getElementById("student-grade-filter");
        if (studentGradeFilter) {
            studentGradeFilter.addEventListener("change", function () {
                studentGradeFilter.form.requestSubmit();
            });
        }
    </script>
</body>
</html>
