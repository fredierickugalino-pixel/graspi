<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

$studentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
$student = null;
$currentPlacement = null;
$familyContact = null;
$scores = [];
$error = "";

try {
    if (!$studentId) {
        throw new RuntimeException("Choose a valid student.");
    }

    $studentQuery = $conn->prepare(
        "SELECT students.student_id, users.user_id, users.lrn, users.first_name,
                users.middle_name, users.last_name, users.suffix, users.email,
                students.gender, tracks.track_name, tracks.strand_name
         FROM students
         JOIN users ON users.user_id = students.user_id
         LEFT JOIN tracks ON tracks.track_id = students.track_id
         WHERE students.student_id = ?
         LIMIT 1"
    );
    $studentQuery->bind_param("i", $studentId);
    $studentQuery->execute();
    $student = $studentQuery->get_result()->fetch_assoc();
    $studentQuery->close();

    if (!$student) {
        http_response_code(404);
        throw new RuntimeException("Student record not found.");
    }

    $placementQuery = $conn->prepare(
        "SELECT sections.grade_level, sections.section_name
         FROM enrollments
         LEFT JOIN sections ON sections.section_id = enrollments.section_id
         WHERE enrollments.student_id = ?
         ORDER BY enrollments.enrollment_date DESC, enrollments.enrollment_id DESC
         LIMIT 1"
    );
    $placementQuery->bind_param("i", $studentId);
    $placementQuery->execute();
    $currentPlacement = $placementQuery->get_result()->fetch_assoc();
    $placementQuery->close();

    $scoresQuery = $conn->prepare(
        "SELECT subjects.subject_name, assessments.assessment_name,
                assessments.assessment_type, assessments.assessment_date,
                assessments.max_score, student_scores.score
         FROM student_scores
         JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
         JOIN class_subjects ON class_subjects.class_subject_id = assessments.class_subject_id
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         WHERE student_scores.student_id = ?
         ORDER BY assessments.assessment_date DESC, assessments.assessment_id DESC"
    );
    $scoresQuery->bind_param("i", $studentId);
    $scoresQuery->execute();
    $scores = $scoresQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $scoresQuery->close();

    try {
        $familyQuery = $conn->prepare(
            "SELECT contact_name, relationship, contact_address, mobile_number, emergency_contact
             FROM student_profile_family WHERE user_id = ? LIMIT 1"
        );
        $familyQuery->bind_param("i", $student["user_id"]);
        $familyQuery->execute();
        $familyContact = $familyQuery->get_result()->fetch_assoc();
        $familyQuery->close();
    } catch (mysqli_sql_exception $familyError) {
        if ((int) $familyError->getCode() !== 1146) {
            error_log("Admin student family contact lookup error: " . $familyError->getMessage());
        }
    }

} catch (RuntimeException $e) {
    $error = $e->getMessage();
} catch (mysqli_sql_exception $e) {
    error_log("Admin student info page error: " . $e->getMessage());
    $error = "We could not load this student’s information right now.";
}

$fullName = $student
    ? trim($student["first_name"] . " " . $student["last_name"])
    : "Student information";
$scoreSubjects = [];
$scoreAssessmentTypes = [];
foreach ($scores as $score) {
    $scoreSubjects[(string) $score["subject_name"]] = true;
    $assessmentType = trim((string) ($score["assessment_type"] ?? "")) ?: "Other";
    $scoreAssessmentTypes[$assessmentType] = true;
}
$scoreSubjects = array_keys($scoreSubjects);
$scoreAssessmentTypes = array_keys($scoreAssessmentTypes);
sort($scoreSubjects, SORT_NATURAL | SORT_FLAG_CASE);
sort($scoreAssessmentTypes, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View student information in the St. Peregrine Institute admin portal.">
    <meta name="theme-color" content="#105334">
    <title><?= h($fullName) ?> | Student Information</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="student_info.css?v=<?= (int) filemtime(__DIR__ . "/student_info.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="../admin-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <a class="admin-profile-icon" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
        </div>
    </header>
    <main class="dashboard student-info-page">
        <a class="student-info-back" href="../admin-dashboard" aria-label="Back to dashboard" title="Back to dashboard">
            <span class="student-info-back-icon" aria-hidden="true">➤</span>
            <span>Back to dashboard</span>
        </a>
        <?php 
if ($error !== ""): ?>
            <p class="alert error" role="alert"><?= h($error) ?></p>
        <?php 
elseif ($student): ?>
            <section class="panel student-overview">
                <div class="student-info-identity">
                    <img class="student-info-photo" src="../admin-student_photo?student_id=<?= (int) $student["student_id"] ?>" alt="Photo of <?= h($fullName) ?>">
                    <div>
                        <h1><?= h($fullName) ?></h1>
                        <p><?= h($student["lrn"] ?: "LRN unavailable") ?></p>
                    </div>
                </div>
                <div class="student-overview-details">
                    <nav class="student-info-detail-tabs" role="tablist" aria-label="Student information">
                        <button type="button" class="is-active" role="tab" id="student-personal-tab" aria-selected="true" aria-controls="student-personal-panel">Personal information</button>
                        <button type="button" role="tab" id="student-family-tab" aria-selected="false" aria-controls="student-family-panel">Family contact</button>
                    </nav>
                    <section class="student-info-detail-panel" id="student-personal-panel" role="tabpanel" aria-labelledby="student-personal-tab">
                        <dl class="student-info-facts">
                            <div><dt>LRN</dt><dd><?= h($student["lrn"] ?: "—") ?></dd></div>
                            <div><dt>Email</dt><dd><?= h($student["email"] ?: "—") ?></dd></div>
                            <div><dt>Gender</dt><dd><?= h($student["gender"] ?: "—") ?></dd></div>
                            <div><dt>Track</dt><dd><?= h($student["track_name"] ?: "—") ?></dd></div>
                            <div><dt>Strand</dt><dd><?= h($student["strand_name"] ?: "—") ?></dd></div>
                            <div><dt>Grade</dt><dd><?= $currentPlacement && $currentPlacement["grade_level"] ? h("Grade " . $currentPlacement["grade_level"]) : "—" ?></dd></div>
                            <div><dt>Section</dt><dd><?= h($currentPlacement["section_name"] ?? "—") ?></dd></div>
                        </dl>
                    </section>
                    <section class="student-info-detail-panel" id="student-family-panel" role="tabpanel" aria-labelledby="student-family-tab" hidden>
                        <?php 
if ($familyContact): ?>
                            <dl class="student-family-contact-facts">
                                <div class="family-contact-name"><dt>Contact name</dt><dd><?= h($familyContact["contact_name"] ?: "—") ?></dd></div>
                                <div class="family-contact-relationship"><dt>Relationship</dt><dd><?= h($familyContact["relationship"] ?: "—") ?></dd></div>
                                <div class="family-contact-phone"><dt>Contact number</dt><dd><?= h($familyContact["mobile_number"] ?: "—") ?></dd></div>
                                <div class="family-contact-address"><dt>Address</dt><dd><?= h($familyContact["contact_address"] ?: "—") ?></dd></div>
                            </dl>
                        <?php 
else: ?>
                            <p class="student-family-contact-empty">No family or emergency contact information has been provided.</p>
                        <?php 
endif; ?>
                    </section>
                </div>
            </section>

            <div class="student-info-grid">
                <?php 
if ($scores): ?>
                    <form action="../admin-student_info" class="student-score-filters" id="student-score-filters" aria-label="Filter student scores">
                        <select id="student-score-subject-filter" aria-label="Filter by subject">
                            <option value="">All subjects</option>
                            <?php 
foreach ($scoreSubjects as $scoreSubject): ?><option value="<?= h($scoreSubject) ?>"><?= h($scoreSubject) ?></option><?php 
endforeach; ?>
                        </select>
                        <select id="student-score-type-filter" aria-label="Filter by assessment type">
                            <option value="">All types</option>
                            <?php 
foreach ($scoreAssessmentTypes as $scoreType): ?><option value="<?= h($scoreType) ?>"><?= h($scoreType) ?></option><?php 
endforeach; ?>
                        </select>
                        <button class="student-score-filter-reset" type="reset">Clear</button>
                        <nav class="student-score-pagination" id="student-score-pagination" aria-label="Student score pages">
                            <button type="button" data-score-page="previous" aria-label="Previous page">‹</button>
                            <div id="student-score-page-numbers"></div>
                            <button type="button" data-score-page="next" aria-label="Next page">›</button>
                        </nav>
                    </form>
                <?php 
endif; ?>
                <section class="panel student-info-card student-enrollment-card student-scores-card">
                    <div class="panel-heading"><div><p class="eyebrow">STUDENT RECORD</p><h2>Student scores</h2><p>Recorded assessment results</p></div></div>
                    <?php 
if ($scores): ?>
                        <div class="table-wrap"><table>
                            <thead><tr><th>Subject</th><th>Assessment</th><th>Type</th><th>Score</th><th>Percent</th><th>Result</th></tr></thead>
                            <tbody id="student-score-rows"><?php 
foreach ($scores as $score): ?>
                                <?php 
$scorePercent = (float) $score["max_score"] > 0 ? ((float) $score["score"] / (float) $score["max_score"]) * 100 : null; ?>
                                <?php 
$scoreType = trim((string) ($score["assessment_type"] ?? "")) ?: "Other"; ?>
                                <tr data-score-subject="<?= h($score["subject_name"]) ?>" data-score-type="<?= h($scoreType) ?>">
                                    <td><?= h($score["subject_name"]) ?></td>
                                    <td><?= h($score["assessment_name"]) ?></td>
                                    <td><?= h($scoreType) ?></td>
                                    <td><?= h($score["score"]) ?> / <?= h($score["max_score"]) ?></td>
                                    <td><?= $scorePercent === null ? "—" : h(number_format($scorePercent, 1) . "%") ?></td>
                                    <td><?php 
if ($scorePercent === null): ?>—<?php 
else: ?><span class="student-score-result <?= $scorePercent > 70 ? "is-pass" : "is-fail" ?>"><?= $scorePercent > 70 ? "Pass" : "Fail" ?></span><?php 
endif; ?></td>
                                </tr>
                            <?php 
endforeach; ?></tbody>
                        </table></div>
                        <p class="student-score-no-match" id="student-score-no-match" hidden>No scores match these filters.</p>
                    <?php 
else: ?>
                        <p class="empty-state">No scores have been recorded for this student.</p>
                    <?php 
endif; ?>
                </section>
            </div>
        <?php 
endif; ?>
    </main>
    <?php 
if ($student): ?>
        <script>
            (function () {
                const tabs = Array.from(document.querySelectorAll(".student-info-detail-tabs [role='tab']"));
                tabs.forEach(function (tab) {
                    tab.addEventListener("click", function () {
                        tabs.forEach(function (otherTab) {
                            const active = otherTab === tab;
                            otherTab.classList.toggle("is-active", active);
                            otherTab.setAttribute("aria-selected", String(active));
                            document.getElementById(otherTab.getAttribute("aria-controls")).hidden = !active;
                        });
                    });
                });
            }());
        </script>
    <?php 
endif; ?>
    <?php 
if ($scores): ?>
        <script>
            (function () {
                const subjectFilter = document.getElementById("student-score-subject-filter");
                const typeFilter = document.getElementById("student-score-type-filter");
                const rows = Array.from(document.querySelectorAll("#student-score-rows tr"));
                const noMatch = document.getElementById("student-score-no-match");
                const form = document.getElementById("student-score-filters");
                const pagination = document.getElementById("student-score-pagination");
                const pageNumbers = document.getElementById("student-score-page-numbers");
                const previousButton = pagination.querySelector('[data-score-page="previous"]');
                const nextButton = pagination.querySelector('[data-score-page="next"]');
                const pageSize = 10;
                let currentPage = 1;
                function applyScoreFilters() {
                    const matchingRows = rows.filter(function (row) {
                        return (!subjectFilter.value || row.dataset.scoreSubject === subjectFilter.value)
                            && (!typeFilter.value || row.dataset.scoreType === typeFilter.value);
                    });
                    const pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
                    currentPage = Math.min(currentPage, pageCount);
                    const firstRow = (currentPage - 1) * pageSize;
                    rows.forEach(function (row) { row.hidden = true; });
                    matchingRows.slice(firstRow, firstRow + pageSize).forEach(function (row) { row.hidden = false; });
                    noMatch.hidden = matchingRows.length > 0;
                    pagination.hidden = matchingRows.length === 0;
                    previousButton.disabled = currentPage <= 1;
                    nextButton.disabled = currentPage >= pageCount;
                    pageNumbers.replaceChildren();
                    const firstPage = Math.max(1, Math.min(currentPage - 2, pageCount - 4));
                    const lastPage = Math.min(pageCount, firstPage + 4);
                    for (let page = firstPage; page <= lastPage; page++) {
                        const button = document.createElement("button");
                        button.type = "button";
                        button.textContent = String(page);
                        button.setAttribute("aria-label", "Page " + page);
                        if (page === currentPage) {
                            button.className = "is-current";
                            button.setAttribute("aria-current", "page");
                        }
                        button.addEventListener("click", function () { currentPage = page; applyScoreFilters(); });
                        pageNumbers.appendChild(button);
                    }
                }
                subjectFilter.addEventListener("change", function () { currentPage = 1; applyScoreFilters(); });
                typeFilter.addEventListener("change", function () { currentPage = 1; applyScoreFilters(); });
                previousButton.addEventListener("click", function () { if (currentPage > 1) { currentPage--; applyScoreFilters(); } });
                nextButton.addEventListener("click", function () { currentPage++; applyScoreFilters(); });
                form.addEventListener("reset", function () {
                    window.setTimeout(function () { currentPage = 1; applyScoreFilters(); }, 0);
                });
                applyScoreFilters();
            }());
        </script>
    <?php 
endif; ?>
</body>
</html>
