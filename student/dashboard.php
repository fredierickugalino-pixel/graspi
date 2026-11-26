<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

$userId = (int) $_SESSION["user_id"];
$student = null;
$enrollment = null;
$approvalPending = false;
$performanceRows = [];
$calendarEvents = [];
$error = "";

try {
    $studentQuery = $conn->prepare(
        "SELECT students.student_id, students.track_id, users.lrn, users.first_name, users.middle_name,
                users.last_name, users.approval_status, tracks.track_name, tracks.strand_name
         FROM students
         JOIN users ON users.user_id = students.user_id
         LEFT JOIN tracks ON tracks.track_id = students.track_id
         WHERE students.user_id = ?"
    );
    $studentQuery->bind_param("i", $userId);
    $studentQuery->execute();
    $student = $studentQuery->get_result()->fetch_assoc();
    $studentQuery->close();

    if ($student) {
        $approvalPending = strtolower((string) ($student["approval_status"] ?? "approved")) !== "approved";
        $studentId = (int) $student["student_id"];

        $enrollmentQuery = $conn->prepare(
            "SELECT enrollments.school_year, enrollments.enrollment_date, enrollments.section_id,
                    enrollments.status, sections.section_name, sections.grade_level
             FROM enrollments
             LEFT JOIN sections ON sections.section_id = enrollments.section_id
             WHERE enrollments.student_id = ?
             ORDER BY enrollments.enrollment_date DESC
             LIMIT 1"
        );
        $enrollmentQuery->bind_param("i", $studentId);
        $enrollmentQuery->execute();
        $enrollment = $enrollmentQuery->get_result()->fetch_assoc();
        $enrollmentQuery->close();


        // The student calendar shows only events entered by an admin.
        // Keep the dashboard usable if the admin calendar table has not been created yet.
        try {
            $schoolCalendarResult = $conn->query(
                "SELECT event_date AS assessment_date, event_type AS subject_name,
                        title AS assessment_name, description AS event_description
                 FROM school_calendar_events
                 WHERE status = 'Active'
                 ORDER BY event_date, title"
            );
            $calendarEvents = array_merge($calendarEvents, $schoolCalendarResult->fetch_all(MYSQLI_ASSOC));
        } catch (mysqli_sql_exception $calendarError) {
            error_log("School calendar events unavailable: " . $calendarError->getMessage());
        }

        $historyQuery = $conn->prepare(
            "SELECT subjects.subject_id, subjects.subject_name,
                    assessments.assessment_type,
                    assessments.assessment_name, assessments.assessment_date,
                    assessments.assessment_id, student_scores.score,
                    assessments.max_score
             FROM student_scores
             JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
             JOIN class_subjects ON class_subjects.class_subject_id = assessments.class_subject_id
             JOIN subjects ON subjects.subject_id = class_subjects.subject_id
             WHERE student_scores.student_id = ?
             ORDER BY subjects.subject_name, assessments.assessment_date DESC,
                      assessments.assessment_id DESC"
        );
        $historyQuery->bind_param("i", $studentId);
        $historyQuery->execute();
        $historyResult = $historyQuery->get_result();
        $recentBySubject = [];
        while ($record = $historyResult->fetch_assoc()) {
            $subjectId = (int) $record["subject_id"];
            $assessmentType = trim((string) ($record["assessment_type"] ?? "")) ?: "Other";
            if (!isset($recentBySubject[$subjectId][$assessmentType])) {
                $recentBySubject[$subjectId][$assessmentType] = [];
            }
            $record["percentage"] = (float) $record["max_score"] > 0
                ? ((float) $record["score"] / (float) $record["max_score"]) * 100
                : 0.0;
            $record["assessment_type_label"] = $assessmentType;
            $recentBySubject[$subjectId][$assessmentType][] = $record;
        }
        $historyQuery->close();

        foreach ($recentBySubject as $subjectId => $historiesByType) {
            foreach ($historiesByType as $assessmentType => $subjectHistory) {
                $latest = $subjectHistory[0];
                $previous = $subjectHistory[1] ?? null;
                $change = $previous
                    ? round($latest["percentage"] - $previous["percentage"], 1)
                    : null;
                $trend = $change === null
                    ? "pending"
                    : ($change > 0 ? "rising" : ($change < 0 ? "declining" : "steady"));
                $performanceRows[] = [
                    "subject_id" => (int) $subjectId,
                    "subject_name" => $latest["subject_name"],
                    "assessment_type" => $assessmentType,
                    "scores" => array_reverse($subjectHistory),
                    "latest" => $latest,
                    "previous" => $previous,
                    "change" => $change,
                    "trend" => $trend
                ];
            }
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log("Student dashboard error: " . $e->getMessage());
    $error = "We could not load your school information right now.";
}

$studentName = $_SESSION["name"] ?? "Student";
$performanceSubjects = [];
$performanceAssessmentTypes = ["Quiz" => true];
foreach ($performanceRows as $performanceRow) {
    $performanceSubjects[$performanceRow["subject_id"]] = $performanceRow["subject_name"];
    $performanceAssessmentTypes[$performanceRow["assessment_type"]] = true;
}
natcasesort($performanceSubjects);
$performanceAssessmentTypes = array_keys($performanceAssessmentTypes);
sort($performanceAssessmentTypes, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('student/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View your school information in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Student Dashboard | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/css/dashboard.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="student-dashboard">
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
            <a class="student-signout-button" href="../logout.php">Sign out</a>
        </div>
    </header>
    <nav class="student-topnav" aria-label="Student navigation">
        <a class="student-nav-link active" href="../student-dashboard" aria-current="page">Dashboard</a>
        <a class="student-nav-link" href="../student-scores">Scores</a>
        <a class="student-nav-link" href="../student-possible-grades">Grades</a>
        <a class="student-nav-link" href="../student-messages">Contact</a>
        <a class="student-nav-link" href="../student-profile">My Profile</a>
    </nav>

    <main class="dashboard">
        <div class="dashboard-welcome-banner">
            <div class="dashboard-welcome-copy">
                <p class="dashboard-welcome-greeting">STUDENT DASHBOARD</p>
                <h1>Welcome, <?= h($studentName) ?></h1>
                <p>Check your enrollment details and school information.</p>
            </div>
        </div>

        <?php 
if ($error !== ""): ?>
            <p class="alert error"><?= h($error) ?></p>
        <?php 
elseif (!$student): ?>
            <p class="alert">No student profile is linked to this account yet. Please contact the administrator.</p>
        <?php 
else: ?>
            <section class="summary-grid">
                <article class="summary-card">
                    <div class="student-summary-card-heading"><span class="student-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="8" cy="11" r="2"></circle><path d="M5.5 16c.6-1.5 1.5-2.2 2.5-2.2s1.9.7 2.5 2.2M13 10h5M13 14h5"></path></svg></span><span class="summary-label">LRN</span></div>
                    <strong><?= h($student["lrn"]) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="student-summary-card-heading"><span class="student-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5 12 3l8 2.5v13L12 21l-8-2.5zM12 3v18M4 5.5l8 2.7 8-2.7"></path></svg></span><span class="summary-label">Track / strand</span></div>
                    <strong><?= h(trim(($student["track_name"] ?? "") . " / " . ($student["strand_name"] ?? ""), " /")) ?: "Not assigned" ?></strong>
                </article>
                <article class="summary-card">
                    <div class="student-summary-card-heading"><span class="student-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V8l7-4 7 4v12M9 20v-5h6v5M8 10h.01M12 10h.01M16 10h.01"></path></svg></span><span class="summary-label">Grades &amp; Section</span></div>
                    <strong>
                        <?php 
if ($enrollment): ?>
                            Grade <?= h($enrollment["grade_level"]) ?> · <?= h($enrollment["section_name"]) ?>
                        <?php 
else: ?>
                            Not enrolled
                        <?php 
endif; ?>
                    </strong>
                </article>
                <article class="summary-card">
                    <div class="student-summary-card-heading"><span class="student-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18M8 14h3M8 17h7"></path></svg></span><span class="summary-label">School year</span></div>
                    <strong><?= h($enrollment["school_year"] ?? "—") ?></strong>
                </article>
            </section>

            <div class="dashboard-insights">
            <section class="panel performance-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Student performance</h2>
                        <p>Review your recorded scores by subject and assessment type. Scroll across the graph to see every assessment.</p>
                    </div>
                </div>
                <?php 
if (count($performanceRows) === 0): ?>
                    <p class="empty-state">No scores have been recorded yet.</p>
                <?php 
else: ?>
                    <div class="performance-filters">
                        <label class="performance-subject-picker" for="performance-subject-select">Subject
                            <select id="performance-subject-select">
                                <?php 
foreach ($performanceSubjects as $subjectId => $subjectName): ?>
                                    <option value="<?= (int) $subjectId ?>"><?= h($subjectName) ?></option>
                                <?php 
endforeach; ?>
                            </select>
                        </label>
                        <label class="performance-subject-picker" for="performance-assessment-select">Assessment type
                            <select id="performance-assessment-select">
                                <?php 
foreach ($performanceAssessmentTypes as $assessmentType): ?>
                                    <option value="<?= h($assessmentType) ?>"<?= $assessmentType === "Quiz" ? " selected" : "" ?>><?= h($assessmentType) ?></option>
                                <?php 
endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="performance-chart" aria-label="Previous and latest score percentages">
                        <?php 
foreach ($performanceRows as $subjectIndex => $row): ?>
                            <?php
                                
$scoreSummary = array_map(static function ($score) {
                                    return $score["assessment_name"] . " " . number_format($score["percentage"], 1) . "%";
                                }, $row["scores"]);
                            $barCenters = array_map(static fn($index) => 70 + ($index * 65), array_keys($row["scores"]));
                            $chartWidth = max(360, (int) end($barCenters) + 30);
                            ?>
                            <article class="performance-chart-row" data-performance-subject="<?= (int) $row["subject_id"] ?>" data-performance-type="<?= h($row["assessment_type"]) ?>" hidden>
                                <div class="performance-line-chart-wrap">
                                    <svg class="performance-line-chart" width="<?= $chartWidth * 1.5 ?>" height="217.5" viewBox="0 0 <?= $chartWidth ?> 145" role="img" aria-label="<?= h($row["subject_name"] . " " . $row["assessment_type"] . " scores: " . implode(", ", $scoreSummary)) ?>">
                                        <g class="performance-chart-grid">
                                            <line x1="42" y1="18" x2="<?= $chartWidth - 12 ?>" y2="18"></line><text x="4" y="22">100%</text>
                                            <line x1="42" y1="41.5" x2="<?= $chartWidth - 12 ?>" y2="41.5"></line><text x="9" y="45.5">75%</text>
                                            <line x1="42" y1="65" x2="<?= $chartWidth - 12 ?>" y2="65"></line><text x="9" y="69">50%</text>
                                            <line x1="42" y1="88.5" x2="<?= $chartWidth - 12 ?>" y2="88.5"></line><text x="9" y="92.5">25%</text>
                                            <line class="performance-chart-axis" x1="42" y1="112" x2="<?= $chartWidth - 12 ?>" y2="112"></line><text x="15" y="116">0%</text>
                                            <line class="performance-chart-axis" x1="42" y1="18" x2="42" y2="112"></line>
                                        </g>
                                        <?php 
foreach ($row["scores"] as $scoreIndex => $score): ?>
                                            <?php
                                                
$scorePercent = (float) $score["percentage"];
                                                $scoreClamped = max(0, min(100, $scorePercent));
                                                $barY = 112 - ($scoreClamped * 0.94);
                                                $barX = $barCenters[$scoreIndex] - 17;
                                                $barClass = $scoreIndex === count($row["scores"]) - 1 ? "latest-column" : "previous-column";
                                            ?>
                                            <rect class="performance-column <?= $barClass ?>" x="<?= $barX ?>" y="<?= number_format($barY, 1, ".", "") ?>" width="34" height="<?= number_format(max(1, 112 - $barY), 1, ".", "") ?>" rx="4"><title><?= h($score["assessment_name"] . ": " . number_format($scorePercent, 1) . "%") ?></title></rect>
                                            <text class="performance-point-value" x="<?= $barCenters[$scoreIndex] ?>" y="<?= number_format(max(12, $barY - 6), 1, ".", "") ?>" text-anchor="middle"><?= number_format($scorePercent, 1) ?>%</text>
                                            <text class="performance-chart-x-label" x="<?= $barCenters[$scoreIndex] ?>" y="141" text-anchor="middle"><?= $scoreIndex + 1 ?></text>
                                        <?php 
endforeach; ?>
                                    </svg>
                                </div>
                            </article>
                        <?php 
endforeach; ?>
                    </div>
                    <script>
                        (function () {
                            const picker = document.getElementById("performance-subject-select");
                            const assessmentPicker = document.getElementById("performance-assessment-select");
                            if (!picker || !assessmentPicker) return;
                            const rows = document.querySelectorAll("[data-performance-subject]");
                            function showSelectedSubject() {
                                rows.forEach(function (row) {
                                    const matchesSubject = picker.value === "" || row.dataset.performanceSubject === picker.value;
                                    const matchesAssessment = assessmentPicker.value === "" || row.dataset.performanceType === assessmentPicker.value;
                                    row.hidden = !matchesSubject || !matchesAssessment;
                                });
                            }
                            picker.addEventListener("change", showSelectedSubject);
                            assessmentPicker.addEventListener("change", showSelectedSubject);
                            showSelectedSubject();
                        })();
                    </script>
                <?php 
endif; ?>
            </section>

            <section class="panel school-calendar-panel" aria-labelledby="school-calendar-title">
                <div class="panel-heading"><div><h2 id="school-calendar-title">School calendar</h2><p>School events schedule</p></div></div>
                <div class="school-calendar-content">
                    <section class="calendar-feature" aria-live="polite" aria-label="Upcoming school event">
                        <div class="calendar-feature-month" id="calendar-month-label"></div>
                        <div class="calendar-feature-body">
                            <button class="calendar-event-nav" id="calendar-previous-month" type="button" aria-label="Previous event">‹</button>
                            <div class="calendar-feature-details">
                                <strong class="calendar-feature-day" id="calendar-event-day">—</strong>
                                <span class="calendar-feature-title" id="calendar-event-title">No upcoming events</span>
                                <span class="calendar-feature-meta" id="calendar-event-meta"></span>
                            </div>
                            <button class="calendar-event-nav" id="calendar-next-month" type="button" aria-label="Next event">›</button>
                        </div>
                    </section>
                    <script type="application/json" id="school-calendar-data"><?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                </div>
            </section>
            </div>

            <?php 
if ($approvalPending): ?>
                <aside class="student-approval-notice" role="status" aria-live="polite">
                    <span class="student-approval-notice-icon" aria-hidden="true">!</span>
                    <div><strong>Approval pending</strong><p>Please wait for your advisory class teacher to approve your account. Your teacher will be able to enter your scores after approval.</p></div>
                </aside>
            <?php 
endif; ?>

        <?php 
endif; ?>
    </main>
    <script>
        (function () {
            const dataNode = document.getElementById("school-calendar-data");
            const monthLabel = document.getElementById("calendar-month-label");
            if (!dataNode || !monthLabel) return;

            const events = JSON.parse(dataNode.textContent || "[]");
            const dayLabel = document.getElementById("calendar-event-day");
            const titleLabel = document.getElementById("calendar-event-title");
            const metaLabel = document.getElementById("calendar-event-meta");
            const eventDates = Array.from(new Set(events.map(function (event) {
                return event.assessment_date;
            }).filter(Boolean))).sort();
            const now = new Date();
            const todayKey = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
            let eventDateIndex = eventDates.findIndex(function (dateKey) {
                return dateKey >= todayKey;
            });
            if (eventDateIndex < 0) eventDateIndex = eventDates.length ? eventDates.length - 1 : 0;

            function renderEventCard() {
                const dateKey = eventDates[eventDateIndex];
                const date = dateKey ? new Date(dateKey + "T00:00:00") : new Date();
                const dayEvents = dateKey ? events.filter(function (event) {
                    return event.assessment_date === dateKey;
                }) : [];
                monthLabel.textContent = new Intl.DateTimeFormat(undefined, { month: "long" }).format(date);
                dayLabel.textContent = date.getDate();
                if (!dayEvents.length) {
                    titleLabel.textContent = "No upcoming events";
                    metaLabel.textContent = "School dates will appear here.";
                    return;
                }
                titleLabel.textContent = dayEvents.length === 1
                    ? dayEvents[0].assessment_name
                    : dayEvents.map(function (event) { return event.assessment_name; }).join(" · ");
                metaLabel.textContent = dayEvents.map(function (event) {
                    return event.event_description || event.subject_name || "School event";
                }).join(" · ");
            }

            document.getElementById("calendar-previous-month").addEventListener("click", function () {
                if (eventDates.length) eventDateIndex = (eventDateIndex - 1 + eventDates.length) % eventDates.length;
                renderEventCard();
            });
            document.getElementById("calendar-next-month").addEventListener("click", function () {
                if (eventDates.length) eventDateIndex = (eventDateIndex + 1) % eventDates.length;
                renderEventCard();
            });
            renderEventCard();
        })();
    </script>
</body>
</html>
