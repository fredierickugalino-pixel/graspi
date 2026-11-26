<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/section_schema.php";
require_once __DIR__ . "/../includes/enrollment_schema.php";

try {
    ensureSectionSchema($conn);
    ensureEnrollmentStatusSchema($conn);
} catch (mysqli_sql_exception $e) {
    error_log("Section and enrollment schema check failed: " . $e->getMessage());
}

if (!isset($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}

$sections = [];
$teachers = [];
$students = [];
$selectedSectionId = 0;
$selectedSection = null;
$error = "";
$assignmentError = "";
$assignmentMessage = "";
$sectionManageError = "";
$sectionManageMessage = "";
$action = "";
$openSectionManager = false;
$allSections = [];
$availableTracks = [];
$openAddSectionModal = false;
$manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$schoolYearStart = (int) $manilaNow->format("Y");
if ((int) $manilaNow->format("n") < 6) {
    $schoolYearStart--;
}
$schoolYear = $schoolYearStart . "-" . ($schoolYearStart + 1);
$sectionInput = $_POST["section_id"] ?? $_GET["section_id"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && str_starts_with((string) ($_POST["action"] ?? ""), "section_")) {
    $action = (string) ($_POST["action"] ?? "");
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    try {
        if (!hash_equals($_SESSION["admin_csrf"], $token)) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }
        if ($action === "section_add") {
            $name = trim((string) ($_POST["section_name"] ?? ""));
            $grade = (int) ($_POST["grade_level"] ?? 0);
            $trackId = (int) ($_POST["track_id"] ?? 0);
            if ($name === "" || mb_strlen($name) > 100 || !in_array($grade, [11, 12], true) || $trackId < 1) {
                throw new Exception("Enter a section name, choose a grade, and choose a track or strand.");
            }
            $trackCheck = $conn->prepare("SELECT track_id FROM tracks WHERE track_id = ?");
            $trackCheck->bind_param("i", $trackId);
            $trackCheck->execute();
            $trackExists = $trackCheck->get_result()->fetch_assoc();
            $trackCheck->close();
            if (!$trackExists) throw new Exception("Choose a valid track or strand.");
            $duplicate = $conn->prepare("SELECT section_id FROM sections WHERE section_name = ? AND grade_level = ? LIMIT 1");
            $duplicate->bind_param("si", $name, $grade);
            $duplicate->execute();
            $exists = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($exists) throw new Exception("That section name already exists for this grade.");
            $addSection = $conn->prepare("INSERT INTO sections (section_name, grade_level, track_id) VALUES (?, ?, ?)");
            $addSection->bind_param("sii", $name, $grade, $trackId);
            $addSection->execute();
            $sectionInput = (string) $addSection->insert_id;
            $addSection->close();
            $sectionManageMessage = "Section added.";
        } elseif ($action === "section_rename") {
            $id = (int) ($_POST["section_id"] ?? 0);
            $name = trim((string) ($_POST["section_name"] ?? ""));
            if ($id < 1 || $name === "" || mb_strlen($name) > 100) throw new Exception("Enter a section name up to 100 characters.");
            $rename = $conn->prepare("UPDATE sections SET section_name = ? WHERE section_id = ? AND is_archived = 0");
            $rename->bind_param("si", $name, $id);
            $rename->execute();
            if ($rename->affected_rows === 0) {
                $existsCheck = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ? AND is_archived = 0 AND section_name = ?");
                $existsCheck->bind_param("is", $id, $name);
                $existsCheck->execute();
                $unchanged = $existsCheck->get_result()->fetch_assoc();
                $existsCheck->close();
                if (!$unchanged) throw new Exception("That active section could not be found, or the name is already in use for its grade.");
            }
            $rename->close();
            $sectionInput = (string) $id;
            $sectionManageMessage = "Section renamed.";
        } elseif ($action === "section_archive" || $action === "section_restore") {
            $id = (int) ($_POST["section_id"] ?? 0);
            if ($id < 1) throw new Exception("Choose a valid section.");
            $archived = $action === "section_archive" ? 1 : 0;
            $changeState = $conn->prepare("UPDATE sections SET is_archived = ? WHERE section_id = ? AND is_archived <> ?");
            $changeState->bind_param("iii", $archived, $id, $archived);
            $changeState->execute();
            if ($changeState->affected_rows === 0) throw new Exception("That section could not be found or is already in that state.");
            $changeState->close();
            if ($archived) $sectionInput = "";
            else $sectionInput = (string) $id;
            $sectionManageMessage = $archived ? "Section archived. Existing student and class records are preserved." : "Section restored.";
        } else {
            throw new Exception("Choose a valid section action.");
        }
        $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
        $openSectionManager = true;
    } catch (mysqli_sql_exception $e) {
        error_log("Section management error: " . $e->getMessage());
        $sectionManageError = $e->getCode() === 1062
            ? "That section name already exists for this grade."
            : "Could not save the section. Please try again.";
        $openSectionManager = true;
    } catch (Exception $e) {
        $sectionManageError = $e->getMessage();
        $openSectionManager = true;
        $openAddSectionModal = $action === "section_add";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "assign_teacher") {
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $postedSectionId = $_POST["section_id"] ?? "";
    $postedTeacherId = $_POST["teacher_id"] ?? "";
    if (!hash_equals($_SESSION["admin_csrf"], $token)) {
        $assignmentError = "The form expired. Refresh the page and try again.";
    } elseif (!is_string($postedSectionId) || !ctype_digit($postedSectionId)) {
        $assignmentError = "Choose a valid section.";
    } else {
        $postedSectionId = (int) $postedSectionId;
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            $sectionCheck = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ? AND is_archived = 0 FOR UPDATE");
            $sectionCheck->bind_param("i", $postedSectionId);
            $sectionCheck->execute();
            $sectionExists = $sectionCheck->get_result()->fetch_assoc();
            $sectionCheck->close();
            if (!$sectionExists) {
                throw new Exception("Choose a valid section.");
            }

            if ($postedTeacherId === "") {
                $updateSection = $conn->prepare("UPDATE sections SET teacher_id = NULL WHERE section_id = ?");
                $updateSection->bind_param("i", $postedSectionId);
            } elseif (is_string($postedTeacherId) && ctype_digit($postedTeacherId)) {
                $postedTeacherId = (int) $postedTeacherId;
                $teacherCheck = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ? FOR UPDATE");
                $teacherCheck->bind_param("i", $postedTeacherId);
                $teacherCheck->execute();
                $teacherExists = $teacherCheck->get_result()->fetch_assoc();
                $teacherCheck->close();
                if (!$teacherExists) {
                    throw new Exception("Choose a valid teacher.");
                }

                $existingAssignmentQuery = $conn->prepare(
                    "SELECT section_id FROM sections WHERE teacher_id = ? AND section_id <> ? AND is_archived = 0 LIMIT 1"
                );
                $existingAssignmentQuery->bind_param("ii", $postedTeacherId, $postedSectionId);
                $existingAssignmentQuery->execute();
                $existingAssignment = $existingAssignmentQuery->get_result()->fetch_assoc();
                $existingAssignmentQuery->close();
                if ($existingAssignment) {
                    throw new Exception("This teacher is already assigned to another section.");
                }

                $updateSection = $conn->prepare("UPDATE sections SET teacher_id = ? WHERE section_id = ?");
                $updateSection->bind_param("ii", $postedTeacherId, $postedSectionId);
            } else {
                throw new Exception("Choose a valid teacher.");
            }

            $updateSection->execute();
            $updateSection->close();
            $conn->commit();
            $transactionStarted = false;
            $assignmentMessage = $postedTeacherId === ""
                ? "Teacher assignment removed."
                : "Successfully assigned a teacher.";
            $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log("Section teacher assignment error: " . $e->getMessage());
            $assignmentError = $e instanceof mysqli_sql_exception
                ? "Could not save the section teacher assignment. Please try again."
                : $e->getMessage();
        }
    }
}

try {
    $availableTracks = $conn->query("SELECT track_id, track_name, strand_name FROM tracks ORDER BY track_name, strand_name")->fetch_all(MYSQLI_ASSOC);
    $sectionQuery = $conn->query(
        "SELECT section_id, section_name, grade_level, teacher_id
         FROM sections WHERE is_archived = 0
         ORDER BY grade_level, section_name"
    );
    $sections = $sectionQuery->fetch_all(MYSQLI_ASSOC);
    $allSectionsQuery = $conn->query("SELECT sections.section_id, sections.section_name, sections.grade_level,
                                             sections.is_archived, tracks.track_name, tracks.strand_name
                                      FROM sections
                                      LEFT JOIN tracks ON tracks.track_id = sections.track_id
                                      ORDER BY sections.is_archived, sections.grade_level, sections.section_name");
    $allSections = $allSectionsQuery->fetch_all(MYSQLI_ASSOC);
    if ($sectionInput === "" && count($sections) > 0) {
        $sectionInput = (string) $sections[0]["section_id"];
    }

    $teacherQuery = $conn->query(
        "SELECT teachers.teacher_id, users.first_name, users.last_name,
                GROUP_CONCAT(sections.section_id) AS assigned_section_ids
         FROM teachers
         JOIN users ON users.user_id = teachers.user_id
         LEFT JOIN sections ON sections.teacher_id = teachers.teacher_id AND sections.is_archived = 0
         GROUP BY teachers.teacher_id, users.first_name, users.last_name
         ORDER BY users.last_name, users.first_name"
    );
    $teachers = $teacherQuery->fetch_all(MYSQLI_ASSOC);

    if (is_string($sectionInput) && ctype_digit($sectionInput)) {
        $selectedSectionId = (int) $sectionInput;
        foreach ($sections as $section) {
            if ((int) $section["section_id"] === $selectedSectionId) {
                $selectedSection = $section;
                break;
            }
        }
        if (!$selectedSection) {
            $selectedSectionId = 0;
            $error = "Choose a valid section.";
        } else {
            $studentQuery = $conn->prepare(
                "SELECT DISTINCT students.student_id, users.lrn, users.first_name, users.middle_name,
                        users.last_name, users.suffix
                 FROM enrollments
                 JOIN students ON students.student_id = enrollments.student_id
                 JOIN users ON users.user_id = students.user_id
                 WHERE enrollments.section_id = ?
                   AND enrollments.school_year = ?
                   AND enrollments.status = 'Enrolled'
                   AND users.approval_status = 'approved'
                 ORDER BY users.last_name, users.first_name, users.middle_name"
            );
            $studentQuery->bind_param("is", $selectedSectionId, $schoolYear);
            $studentQuery->execute();
            $students = $studentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $studentQuery->close();

        }
    } elseif ($sectionInput !== "") {
        $error = "Choose a valid section.";
    }
} catch (mysqli_sql_exception $e) {
    error_log("Section student list error: " . $e->getMessage());
    $error = "We could not load the section student list right now.";
}

if (isset($_GET["download_students"])) {
    if (!$selectedSection || $error !== "") {
        http_response_code(400);
        exit("Choose a section before downloading its student list.");
    }

    $fileBase = strtolower((string) $selectedSection["section_name"]);
    $fileBase = trim((string) preg_replace('/[^a-z0-9]+/i', "-", $fileBase), "-");
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"student-list-" . $fileBase . ".csv\"");
    header("X-Content-Type-Options: nosniff");
    $output = fopen("php://output", "w");
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ["LRN", "First Name", "Middle Name", "Last Name", "Suffix", "Grade Level", "Section", "School Year"]);
    foreach ($students as $student) {
        $csvValues = [
            $student["lrn"] ?? "",
            $student["first_name"],
            $student["middle_name"] ?? "",
            $student["last_name"],
            $student["suffix"] ?? "",
            $selectedSection["grade_level"],
            $selectedSection["section_name"],
            $schoolYear
        ];
        foreach ($csvValues as &$csvValue) {
            $csvValue = (string) $csvValue;
            if (preg_match('/^\s*[=+\-@]/', $csvValue)) {
                $csvValue = "'" . $csvValue;
            }
        }
        unset($csvValue);
        fputcsv($output, $csvValues);
    }
    fclose($output);
    exit;
}

$adminName = $_SESSION["name"] ?? "Administrator";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View the enrolled students in a selected section.">
    <meta name="theme-color" content="#105334">
    <title>Sections | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="section_assignments.css?v=<?= (int) filemtime(__DIR__ . "/section_assignments.css") ?>">
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
                <span></span><span></span><span></span>
            </button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user">
                    <strong><?= h($adminName) ?></strong>
                    <span>Admin</span>
                </div>
                <a class="menu-link" href="../admin-dashboard">Dashboard</a>
                <a class="menu-link" href="../admin-section_assignments">Sections</a>
                <a class="menu-link" href="../admin-subjects">Subjects</a>
                <a class="menu-link" href="../admin-calendar">Calendar</a>
                <a class="menu-link" href="../admin-register">Teacher</a>
            </nav>
        </div>
    </header>

    <main class="dashboard">
        <div class="page-heading">
            <p class="eyebrow">ADMIN PORTAL</p>
            <h1>Sections</h1>
            <button class="section-manage-trigger" id="section-manage-trigger" type="button" aria-haspopup="dialog" aria-controls="section-manage-modal">Manage sections</button>
        </div>

        <?php 
if ($error !== ""): ?>
            <p class="alert error" role="alert"><?= h($error) ?></p>
        <?php 
endif; ?>
        <?php 
if ($assignmentError !== ""): ?>
            <p class="alert error" role="alert"><?= h($assignmentError) ?></p>
        <?php 
endif; ?>
        <?php 
if ($sectionManageError !== ""): ?>
            <p class="alert error" role="alert"><?= h($sectionManageError) ?></p>
        <?php 
endif; ?>
        <?php 
if ($sectionManageMessage !== "" && $action !== "section_add"): ?>
            <p class="alert success" role="status"><?= h($sectionManageMessage) ?></p>
        <?php 
endif; ?>
        <?php 
if (count($sections) === 0): ?>
            <section class="panel"><p class="empty-state">There are no sections in the catalog yet.</p></section>
        <?php 
else: ?>
            <div class="section-roster-layout">
                <aside class="section-roster-sidebar" aria-label="Sections">
                    <div class="section-list-scrollbar" aria-hidden="true"><div class="section-list-scrollbar-content"></div></div>
                    <nav class="section-list" id="section-list" aria-label="Choose a section">
                        <?php 
foreach ($sections as $section): ?>
                            <?php 
$isCurrentSection = $selectedSectionId === (int) $section["section_id"]; ?>
                            <a class="section-list-link<?= $isCurrentSection ? " is-active" : "" ?>" href="../admin-section_assignments?section_id=<?= (int) $section["section_id"] ?>"<?= $isCurrentSection ? ' aria-current="page"' : "" ?>>
                                <span class="section-list-grade">Grade <?= h($section["grade_level"]) ?></span>
                                <span><?= h($section["section_name"]) ?></span>
                            </a>
                        <?php 
endforeach; ?>
                    </nav>
                </aside>
                <div class="section-roster-content">
                <?php 
if ($selectedSection): ?>
                    <div class="section-roster-toolbar">
                    <form action="../admin-section_assignments" class="section-teacher-assignment" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_csrf"]) ?>">
                        <input type="hidden" name="action" value="assign_teacher">
                        <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                        <label for="section-teacher-select">Assigned teacher</label>
                        <div class="section-teacher-controls">
                            <select name="teacher_id" id="section-teacher-select">
                                <option value="">Unassigned</option>
                                <?php 
foreach ($teachers as $teacher): ?>
                                    <?php
                                    
$assignedSectionIds = array_filter(explode(",", (string) ($teacher["assigned_section_ids"] ?? "")), "strlen");
                                    $assignedElsewhere = false;
                                    foreach ($assignedSectionIds as $assignedSectionId) {
                                        if ((int) $assignedSectionId !== $selectedSectionId) {
                                            $assignedElsewhere = true;
                                            break;
                                        }
                                    }
                                    if ($assignedElsewhere) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?= (int) $teacher["teacher_id"] ?>" <?= (int) ($selectedSection["teacher_id"] ?? 0) === (int) $teacher["teacher_id"] ? "selected" : "" ?>>
                                        <?= h($teacher["first_name"] . " " . $teacher["last_name"]) ?>
                                    </option>
                                <?php 
endforeach; ?>
                            </select>
                            <button class="small-button" type="submit">Save teacher</button>
                        </div>
                    </form>
                    <a class="section-roster-download" href="../admin-section_assignments?section_id=<?= $selectedSectionId ?>&amp;download_students=1" aria-label="Download student list as an Excel-compatible CSV" title="Download student list">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v3h14v-3"></path></svg>
                    </a>
                <?php 
endif; ?>
            </div>

            <?php 
if ($selectedSection): ?>
                <section class="panel section-roster-panel">
                    <div class="panel-heading"><div><h2><?= h("Grade " . $selectedSection["grade_level"] . " · " . $selectedSection["section_name"]) ?></h2></div></div>
                    <?php 
if (count($students) === 0): ?>
                        <p class="empty-state">No students are currently enrolled in this section for <?= h($schoolYear) ?>.</p>
                    <?php 
else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>#</th><th>LRN</th><th>Student</th></tr></thead>
                                <tbody>
                                    <?php 
foreach ($students as $index => $student): ?>
                                        <?php 
$studentFullName = trim($student["last_name"] . ", " . $student["first_name"] . " " . ($student["middle_name"] ?? "") . " " . ($student["suffix"] ?? "")); ?>
                                        <tr class="section-student-row" data-student-url="student_info.php?student_id=<?= (int) $student["student_id"] ?>" tabindex="0" role="link" aria-label="View student profile: <?= h($studentFullName) ?>">
                                            <td><?= $index + 1 ?></td>
                                            <td><?= h($student["lrn"] ?? "—") ?></td>
                                            <td><a class="section-student-name-link" href="../admin-student_info?student_id=<?= (int) $student["student_id"] ?>"><?= h($studentFullName) ?></a></td>
                                        </tr>
                                    <?php 
endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php 
endif; ?>
                </section>
            <?php 
elseif ($error === ""): ?>
                <p class="empty-state">Select a section to see its student list.</p>
            <?php 
endif; ?>
                </div>
            </div>
        <?php 
endif; ?>
        <div class="dashboard-modal-overlay" id="section-manage-modal" data-trigger-id="section-manage-trigger" role="dialog" aria-modal="true" aria-labelledby="section-manage-title" <?= $openSectionManager ? "" : "hidden" ?>>
            <div class="section-manage-pair">
                <section class="dashboard-form-modal section-add-modal" id="section-add-modal" role="dialog" aria-modal="true" aria-labelledby="section-add-title" <?= $openAddSectionModal ? "" : "hidden" ?>>
                    <header class="dashboard-form-modal-heading">
                        <div><h2 id="section-add-title">Add section</h2><p>Set the section name, grade, and track or strand.</p></div>
                        <button class="dashboard-modal-close" type="button" aria-label="Close add section">×</button>
                    </header>
                    <?php 
if ($sectionManageError !== ""): ?><p class="section-manage-feedback section-manage-error" role="alert"><?= h($sectionManageError) ?></p><?php 
endif; ?>
                    <form action="../admin-section_assignments" class="section-manage-add" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_csrf"]) ?>">
                        <input type="hidden" name="action" value="section_add">
                        <label>Section name <input name="section_name" maxlength="100" required placeholder="e.g. Sampaguita"></label>
                        <label>Grade
                            <select name="grade_level" required><option value="">Choose grade</option><option value="11">Grade 11</option><option value="12">Grade 12</option></select>
                        </label>
                        <label>Track / strand
                            <select name="track_id" required><option value="">Choose track or strand</option><?php 
foreach ($availableTracks as $track): ?><option value="<?= (int) $track["track_id"] ?>"><?= h($track["track_name"] . " / " . $track["strand_name"]) ?></option><?php 
endforeach; ?></select>
                        </label>
                        <button class="section-manage-trigger" type="submit">Save section</button>
                    </form>
                </section>
                <section class="dashboard-form-modal section-manage-modal" aria-labelledby="section-manage-title">
                    <header class="dashboard-form-modal-heading">
                        <div><h2 id="section-manage-title">Manage sections</h2><p>Update section names or archive sections while preserving their records.</p></div>
                        <button class="dashboard-modal-close" type="button" aria-label="Close section manager">×</button>
                    </header>
                    <?php 
if ($sectionManageMessage !== "" && $action !== "section_add"): ?><p class="section-manage-feedback" role="status"><?= h($sectionManageMessage) ?></p><?php 
endif; ?>
                    <?php 
if ($sectionManageError !== "" && !$openAddSectionModal): ?><p class="section-manage-feedback section-manage-error" role="alert"><?= h($sectionManageError) ?></p><?php 
endif; ?>
                    <div class="section-manage-list">
                    <?php 
if (!$allSections): ?><p class="empty-state">No sections have been added yet.</p><?php 
endif; ?>
                    <?php 
foreach ($allSections as $managedSection): ?>
                        <div class="section-manage-row<?= (int) $managedSection["is_archived"] === 1 ? " is-archived" : "" ?>">
                            <span class="section-manage-grade">Grade <?= h($managedSection["grade_level"]) ?> · <?= h($managedSection["strand_name"] ?: "No track") ?><?= (int) $managedSection["is_archived"] === 1 ? " · Archived" : "" ?></span>
                            <?php 
if ((int) $managedSection["is_archived"] === 0): ?>
                                <form action="../admin-section_assignments" class="section-manage-rename" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_csrf"]) ?>"><input type="hidden" name="action" value="section_rename"><input type="hidden" name="section_id" value="<?= (int) $managedSection["section_id"] ?>">
                                    <input name="section_name" aria-label="Rename <?= h($managedSection["section_name"]) ?>" maxlength="100" required value="<?= h($managedSection["section_name"]) ?>">
                                    <button type="submit">Rename</button>
                                </form>
                                <form action="../admin-section_assignments" method="post" onsubmit="return confirm('Archive this section? Existing records will be kept.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_csrf"]) ?>"><input type="hidden" name="action" value="section_archive"><input type="hidden" name="section_id" value="<?= (int) $managedSection["section_id"] ?>">
                                    <button class="section-manage-archive" type="submit">Archive</button>
                                </form>
                            <?php 
else: ?>
                                <span class="section-manage-name"><?= h($managedSection["section_name"]) ?></span>
                                <form action="../admin-section_assignments" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_csrf"]) ?>"><input type="hidden" name="action" value="section_restore"><input type="hidden" name="section_id" value="<?= (int) $managedSection["section_id"] ?>">
                                    <button type="submit">Restore</button>
                                </form>
                            <?php 
endif; ?>
                        </div>
                    <?php 
endforeach; ?>
                    </div>
                    <footer class="section-manage-footer"><button class="section-manage-trigger" id="section-add-trigger" type="button" aria-haspopup="dialog" aria-controls="section-add-modal">Add section</button></footer>
                </section>
            </div>
        </div>
    </main>
    <script>
        document.querySelectorAll(".section-student-row[data-student-url]").forEach(function (row) {
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
    const sectionManageTrigger = document.getElementById("section-manage-trigger");
    const sectionManageModal = document.getElementById("section-manage-modal");
    const sectionAddModal = document.getElementById("section-add-modal");
    const sectionAddTrigger = document.getElementById("section-add-trigger");
    if (sectionManageTrigger && sectionManageModal) {
        sectionManageTrigger.addEventListener("click", function () {
            sectionManageModal.hidden = false;
            if (sectionAddModal && <?= $openAddSectionModal ? "true" : "false" ?>) sectionAddModal.hidden = false;
            document.body.classList.add("dashboard-modal-open");
            if (sectionAddModal && !sectionAddModal.hidden) sectionAddModal.querySelector("input[name='section_name']")?.focus();
            else sectionAddTrigger?.focus();
        });
        sectionManageModal.querySelectorAll(".dashboard-modal-close").forEach(function (button) {
            button.addEventListener("click", function () {
                sectionManageModal.hidden = true;
                if (sectionAddModal) sectionAddModal.hidden = true;
                document.body.classList.remove("dashboard-modal-open");
                sectionManageTrigger.focus();
            });
        });
    }
    if (sectionAddModal && sectionAddTrigger) {
        sectionAddTrigger.addEventListener("click", function () {
            sectionAddModal.hidden = false;
            sectionAddModal.querySelector("input[name='section_name']")?.focus();
        });
        sectionAddModal.querySelector(".dashboard-modal-close")?.addEventListener("click", function () {
            sectionAddModal.hidden = true;
            sectionAddTrigger.focus();
        });
    }
    document.addEventListener("DOMContentLoaded", function () {
        const sectionAddSuccessModal = document.getElementById("section-add-success-modal");
        if (sectionAddSuccessModal) {
            document.body.classList.add("dashboard-modal-open");
            sectionAddSuccessModal.querySelector(".dashboard-modal-close")?.addEventListener("click", function () {
                sectionAddSuccessModal.hidden = true;
                document.body.classList.remove("dashboard-modal-open");
            });
        }
    });
    </script>
    <?php 
if ($assignmentMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="teacher-assignment-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="teacher-assignment-success-title" aria-describedby="teacher-assignment-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="teacher-assignment-success-title">Assignment successful</h2>
                <p id="teacher-assignment-success-message"><?= h($assignmentMessage) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
    <?php 
if ($sectionManageMessage !== "" && $action === "section_add"): ?>
        <div class="dashboard-modal-overlay" id="section-add-success-modal" role="dialog" aria-modal="true" aria-labelledby="section-add-success-title" aria-describedby="section-add-success-message">
            <section class="dashboard-success-modal" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="section-add-success-title">Section added</h2>
                <p id="section-add-success-message">The section and its track have been saved.</p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
    <script>
        (function () {
            const sectionList = document.getElementById("section-list");
            const scrollbar = document.querySelector(".section-list-scrollbar");
            const scrollbarContent = document.querySelector(".section-list-scrollbar-content");
            if (!sectionList || !scrollbar || !scrollbarContent) return;
            let syncing = false;
            function updateSectionScrollbar() {
                scrollbarContent.style.width = sectionList.scrollWidth + "px";
                scrollbar.hidden = sectionList.scrollWidth <= sectionList.clientWidth + 1;
                if (scrollbar.hidden) return;
                syncing = true;
                scrollbar.scrollLeft = sectionList.scrollLeft;
                syncing = false;
            }
            scrollbar.addEventListener("scroll", function () {
                if (syncing) return;
                syncing = true;
                sectionList.scrollLeft = scrollbar.scrollLeft;
                syncing = false;
            });
            sectionList.addEventListener("scroll", function () {
                if (syncing) return;
                syncing = true;
                scrollbar.scrollLeft = sectionList.scrollLeft;
                syncing = false;
            });
            window.addEventListener("resize", updateSectionScrollbar);
            if (window.ResizeObserver) new ResizeObserver(updateSectionScrollbar).observe(sectionList);
            updateSectionScrollbar();
        }());
    </script>
</body>
</html>
