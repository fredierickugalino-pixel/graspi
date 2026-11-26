<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/curriculum_schema.php";
ensureCurriculumSchema($conn);

if (!isset($_SESSION["admin_track_subjects_csrf"])) {
    $_SESSION["admin_track_subjects_csrf"] = bin2hex(random_bytes(32));
}

$error = "";
$message = "";
$tracks = [];
$plannedSubjects = [];
$trackId = max(0, (int) ($_POST["track_id"] ?? $_GET["track_id"] ?? 0));
$gradeLevel = (int) ($_POST["grade_level"] ?? $_GET["grade_level"] ?? 11);
$term = (int) ($_POST["term"] ?? $_GET["term"] ?? 1);
if (!in_array($gradeLevel, [11, 12], true)) $gradeLevel = 11;
if (!in_array($term, [1, 2, 3], true)) $term = 1;

try {
    $tracks = $conn->query("SELECT track_id, track_name, strand_name FROM tracks ORDER BY track_name, strand_name")
        ->fetch_all(MYSQLI_ASSOC);
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (!hash_equals($_SESSION["admin_track_subjects_csrf"], $_POST["csrf_token"] ?? "")) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }
        if (!in_array($gradeLevel, [11, 12], true) || !in_array($term, [1, 2, 3], true) || $trackId <= 0) {
            throw new Exception("Choose a valid track, grade level, and term.");
        }
        $postTrackQuery = $conn->prepare("SELECT track_id FROM tracks WHERE track_id = ?");
        $postTrackQuery->bind_param("i", $trackId);
        $postTrackQuery->execute();
        $postTrackExists = $postTrackQuery->get_result()->fetch_assoc();
        $postTrackQuery->close();
        if (!$postTrackExists) {
            throw new Exception("Choose a valid track or strand.");
        }
        $action = (string) ($_POST["action"] ?? "");
        if ($action === "save_plan") {
            $subjectIds = array_values(array_unique(array_filter(array_map("intval", (array) ($_POST["subject_ids"] ?? [])), static fn($id) => $id > 0)));
            $eligibleIds = [];
            $eligibleSubjectQuery = $conn->prepare(
                "SELECT subject_id FROM subjects
                 WHERE is_archived = 0 AND grade_level = ? AND semester = ?
                   AND (track_id = ? OR track_id IS NULL)"
            );
            $eligibleSubjectQuery->bind_param("iii", $gradeLevel, $term, $trackId);
            $eligibleSubjectQuery->execute();
            foreach ($eligibleSubjectQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $eligibleRow) {
                $eligibleIds[] = (int) $eligibleRow["subject_id"];
            }
            $eligibleSubjectQuery->close();
            if (array_diff($subjectIds, $eligibleIds)) {
                throw new Exception("One or more selected subjects are not available for this track, grade, and term.");
            }
            $conn->begin_transaction();
            try {
                $clearPlanQuery = $conn->prepare("DELETE FROM track_subject_assignments WHERE track_id = ? AND grade_level = ? AND semester = ?");
                $clearPlanQuery->bind_param("iii", $trackId, $gradeLevel, $term);
                $clearPlanQuery->execute();
                $clearPlanQuery->close();
                if ($subjectIds) {
                    $saveSubjectQuery = $conn->prepare("INSERT INTO track_subject_assignments (track_id, grade_level, semester, subject_id) VALUES (?, ?, ?, ?)");
                    foreach ($subjectIds as $subjectId) {
                        $saveSubjectQuery->bind_param("iiii", $trackId, $gradeLevel, $term, $subjectId);
                        $saveSubjectQuery->execute();
                    }
                    $saveSubjectQuery->close();
                }
                $conn->commit();
                $message = "Student subject plan saved for this track, grade, and term.";
            } catch (Throwable $saveError) {
                $conn->rollback();
                throw $saveError;
            }
        } elseif (in_array($action, ["assign_subject", "unassign_subject"], true)) {
            $subjectId = (int) ($_POST["subject_id"] ?? 0);
            if ($subjectId <= 0) {
                throw new Exception("Choose a valid subject.");
            }
            if ($action === "assign_subject") {
            $eligibleSubjectQuery = $conn->prepare(
                "SELECT subject_id FROM subjects
                 WHERE subject_id = ? AND is_archived = 0 AND grade_level = ? AND semester = ?
                   AND (track_id = ? OR track_id IS NULL)"
            );
            $eligibleSubjectQuery->bind_param("iiii", $subjectId, $gradeLevel, $term, $trackId);
            $eligibleSubjectQuery->execute();
            $eligibleSubject = $eligibleSubjectQuery->get_result()->fetch_assoc();
            $eligibleSubjectQuery->close();
            if (!$eligibleSubject) {
                throw new Exception("That subject is not available for the selected track, grade, and term.");
            }
            $assignSubjectQuery = $conn->prepare(
                "INSERT IGNORE INTO track_subject_assignments (track_id, grade_level, semester, subject_id)
                 VALUES (?, ?, ?, ?)"
            );
            $assignSubjectQuery->bind_param("iiii", $trackId, $gradeLevel, $term, $subjectId);
            $assignSubjectQuery->execute();
            $assignSubjectQuery->close();
            $message = "Subject added to this track's term plan.";
            } else {
            $unassignSubjectQuery = $conn->prepare(
                "DELETE FROM track_subject_assignments
                 WHERE track_id = ? AND grade_level = ? AND semester = ? AND subject_id = ?"
            );
            $unassignSubjectQuery->bind_param("iiii", $trackId, $gradeLevel, $term, $subjectId);
            $unassignSubjectQuery->execute();
            $unassignSubjectQuery->close();
            $message = "Subject removed from this track's term plan. Existing class offerings and grades remain unchanged.";
            }
        } else {
            throw new Exception("Choose a valid subject plan action.");
        }
    }
    if ($trackId > 0) {
        $trackExistsQuery = $conn->prepare("SELECT track_id FROM tracks WHERE track_id = ?");
        $trackExistsQuery->bind_param("i", $trackId);
        $trackExistsQuery->execute();
        $trackExists = $trackExistsQuery->get_result()->fetch_assoc();
        $trackExistsQuery->close();
        if (!$trackExists) {
            $trackId = 0;
        } else {
            $subjectQuery = $conn->prepare(
                "SELECT subjects.subject_id, subjects.subject_name, subjects.track_id,
                        subjects.default_written_works_weight,
                        subjects.default_performance_tasks_weight,
                        subjects.default_term_assessment_weight,
                        tracks.track_name, tracks.strand_name,
                        (track_subject_assignments.subject_id IS NOT NULL) AS is_assigned
                 FROM subjects
                 LEFT JOIN tracks ON tracks.track_id = subjects.track_id
                 LEFT JOIN track_subject_assignments
                    ON track_subject_assignments.subject_id = subjects.subject_id
                    AND track_subject_assignments.track_id = ?
                    AND track_subject_assignments.grade_level = ?
                    AND track_subject_assignments.semester = ?
                 WHERE subjects.is_archived = 0
                   AND subjects.grade_level = ?
                   AND subjects.semester = ?
                   AND (subjects.track_id = ? OR subjects.track_id IS NULL)
                 ORDER BY is_assigned DESC, subjects.track_id IS NULL, subjects.subject_name"
            );
            $subjectQuery->bind_param("iiiiii", $trackId, $gradeLevel, $term, $gradeLevel, $term, $trackId);
            $subjectQuery->execute();
            $plannedSubjects = $subjectQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $subjectQuery->close();
        }
    }
} catch (Throwable $e) {
    error_log("Admin track subject filter error: " . $e->getMessage());
    $error = $e instanceof mysqli_sql_exception
        ? "We could not load the subjects for that selection right now."
        : $e->getMessage();
}

$adminName = $_SESSION["name"] ?? "Administrator";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View planned subjects by track, grade level, and term.">
    <meta name="theme-color" content="#105334">
    <title>Set Track Subjects | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <link rel="stylesheet" href="track_subjects.css?v=<?= (int) filemtime(__DIR__ . "/track_subjects.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="track-subjects-page">
    <header class="topbar">
        <a class="brand" href="../admin-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 1.3 1.42 1.42M2 12h2m16 0h2"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <a class="admin-profile-icon" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu"><span></span><span></span><span></span></button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user"><strong><?= h($adminName) ?></strong><span>Admin</span></div>
                <a class="menu-link" href="../admin-dashboard">Dashboard</a><a class="menu-link" href="../admin-subjects">Subjects</a>
                <a class="menu-link" href="../admin-section_assignments">Sections</a><a class="menu-link" href="../admin-subjects">Subjects</a><a class="menu-link" href="../admin-calendar">Calendar</a><a class="menu-link" href="../admin-register">Teacher</a>
            </nav>
        </div>
    </header>
    <main class="dashboard track-subjects-dashboard">
        <a class="track-plan-back" href="../admin-subjects">← Back to Subjects</a>
        <div class="page-heading"><p class="eyebrow">ADMIN PORTAL · CURRICULUM</p><h1>Set subjects by term</h1><p>Choose which track subjects and core subjects students may take for each term.</p></div>
        <?php 
if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php 
endif; ?>
        <?php 
if ($message !== ""): ?><p class="alert success" role="status"><?= h($message) ?></p><?php 
endif; ?>

        <section class="panel track-plan-panel">
            <div class="track-plan-filter-layout">
            <form action="../admin-track_subjects" class="track-plan-filter-bar" method="get" aria-label="Filter planned subjects">
                <label>Track / strand<select name="track_id" id="track-plan-track" required>
                    <option value="0">Select a track / strand</option>
                    <?php 
foreach ($tracks as $track): ?><option value="<?= (int) $track["track_id"] ?>" <?= $trackId === (int) $track["track_id"] ? "selected" : "" ?>><?= h($track["track_name"] . " / " . $track["strand_name"]) ?></option><?php 
endforeach; ?>
                </select></label>
                <label>Grade level<select name="grade_level" id="track-plan-grade"><option value="11" <?= $gradeLevel === 11 ? "selected" : "" ?>>Grade 11</option><option value="12" <?= $gradeLevel === 12 ? "selected" : "" ?>>Grade 12</option></select></label>
                <label>Term<select name="term" id="track-plan-term"><?php 
for ($period = 1; $period <= 3; $period++): ?><option value="<?= $period ?>" <?= $term === $period ? "selected" : "" ?>>Term <?= $period ?></option><?php 
endfor; ?></select></label>
            </form>
            <button class="track-plan-manage-button" type="button" id="open-track-plan-modal" <?= $trackId <= 0 ? "disabled" : "" ?>>Manage subjects</button>
            </div>
        </section>

        <dialog class="track-plan-dialog" id="track-plan-dialog" aria-labelledby="track-plan-dialog-title">
            <form action="../admin-track_subjects" method="post" class="track-plan-dialog-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_track_subjects_csrf"]) ?>">
                <input type="hidden" name="action" value="save_plan">
                <input type="hidden" name="track_id" value="<?= $trackId ?>">
                <input type="hidden" name="grade_level" value="<?= $gradeLevel ?>">
                <input type="hidden" name="term" value="<?= $term ?>">
                <div class="track-plan-dialog-heading"><div><p class="eyebrow">STUDENT CURRICULUM</p><h2 id="track-plan-dialog-title">Manage subjects</h2></div><button type="button" class="track-plan-dialog-close" id="close-track-plan-modal" aria-label="Close">×</button></div>
                <div class="track-plan-checklist">
                    <?php 
if (!$plannedSubjects): ?><p class="empty-state">No subjects are available for this track, grade, and term.</p>
                    <?php 
else: ?><div class="track-plan-selected-list" id="track-plan-selected-list">
                        <?php 
foreach ($plannedSubjects as $subject): if ((int) $subject["is_assigned"] !== 1) continue; $subjectKind = $subject["track_id"] === null ? "Core subject" : "Track / strand subject"; ?><label class="track-plan-check-item" data-subject-row="<?= (int) $subject["subject_id"] ?>" data-subject-name="<?= h($subject["subject_name"]) ?>" data-subject-kind="<?= h($subjectKind) ?>"><input type="checkbox" name="subject_ids[]" value="<?= (int) $subject["subject_id"] ?>" checked><span><strong><?= h($subject["subject_name"]) ?></strong><small><?= $subject["track_id"] === null ? "Core subject" : h($subject["track_name"] . " / " . $subject["strand_name"]) ?></small></span><span class="track-plan-remove-label">Remove</span></label><?php 
endforeach; ?>
                    </div>
                    <div class="track-plan-add-row"><label for="track-plan-add-select">Add an existing subject<select id="track-plan-add-select"><option value="">Choose a subject</option><?php 
foreach ($plannedSubjects as $subject): if ((int) $subject["is_assigned"] === 1) continue; ?><option value="<?= (int) $subject["subject_id"] ?>" data-subject-name="<?= h($subject["subject_name"]) ?>" data-subject-kind="<?= $subject["track_id"] === null ? "Core subject" : "Track / strand subject" ?>"><?= h($subject["subject_name"] . ($subject["track_id"] === null ? " · Core" : " · " . $subject["track_name"] . " / " . $subject["strand_name"])) ?></option><?php 
endforeach; ?></select></label><button type="button" class="track-plan-add-selected-button" id="add-track-plan-subject">Add subject</button></div><?php 
endif; ?>
                </div>
                <div class="track-plan-dialog-actions"><button type="button" class="track-plan-cancel-button" id="cancel-track-plan-modal">Cancel</button><button type="submit" class="track-plan-save-button" <?= !$plannedSubjects ? "disabled" : "" ?>>Save student plan</button></div>
            </form>
        </dialog>

        <?php 
if ($trackId > 0): ?>
            <?php 
$displaySubjects = array_values(array_filter($plannedSubjects, static fn($subject) => (int) $subject["is_assigned"] === 1)); ?>
            <section class="panel track-plan-list-panel">
                <div class="track-plan-heading"><div><h2>Grade <?= $gradeLevel ?> · Term <?= $term ?></h2><p>Only subjects marked “In plan” can be offered to sections for this track.</p></div></div>
                <?php 
if (!$displaySubjects): ?><p class="empty-state">No subjects are assigned to this track, grade, and term yet.</p>
                <?php 
else: ?>
                    <div class="track-plan-table-wrap"><table class="track-plan-table">
                        <thead><tr><th>Subject</th><th>Component weights</th></tr></thead>
                        <tbody><?php 
foreach ($displaySubjects as $subject): ?>
                            <?php
                                
$weights = array_filter([
                                    "Written Works" => $subject["default_written_works_weight"],
                                    "Performance Tasks" => $subject["default_performance_tasks_weight"],
                                    "Term Assessment" => $subject["default_term_assessment_weight"]
                                ], static fn($value) => $value !== null);
                            ?>
                            <tr><td><strong><?= h($subject["subject_name"]) ?></strong></td><td><?= h(implode(" · ", array_map(static fn($name, $weight) => $name . " " . number_format((float) $weight, 2) . "%", array_keys($weights), array_values($weights)))) ?></td></tr>
                        <?php 
endforeach; ?></tbody>
                    </table></div>
                <?php 
endif; ?>
            </section>
        <?php 
else: ?>
            <section class="panel track-plan-list-panel"><p class="empty-state">Choose a track, grade level, and term to see its subjects. Core subjects for that grade and term will be included automatically.</p></section>
        <?php 
endif; ?>
    </main>
    <script>
        document.querySelectorAll("#track-plan-track, #track-plan-grade, #track-plan-term").forEach(function (filter) {
            filter.addEventListener("change", function () { filter.form.requestSubmit(); });
        });
        const trackPlanDialog = document.getElementById("track-plan-dialog");
        document.getElementById("open-track-plan-modal")?.addEventListener("click", function () { trackPlanDialog?.showModal(); });
        ["close-track-plan-modal", "cancel-track-plan-modal"].forEach(function (id) {
            document.getElementById(id)?.addEventListener("click", function () { trackPlanDialog?.close(); });
        });
        const addSubjectSelect = document.getElementById("track-plan-add-select");
        document.getElementById("add-track-plan-subject")?.addEventListener("click", function () {
            const selectedOption = addSubjectSelect?.selectedOptions[0];
            if (!selectedOption || !selectedOption.value) return;
            const subjectId = selectedOption.value;
            const subjectName = selectedOption.dataset.subjectName;
            const subjectKind = selectedOption.dataset.subjectKind;
            const row = document.querySelector('[data-subject-row="' + subjectId + '"]');
            if (row) return;
            const item = document.createElement("label");
            item.className = "track-plan-check-item";
            item.dataset.subjectRow = subjectId;
            item.dataset.subjectName = subjectName;
            item.dataset.subjectKind = subjectKind;
            item.innerHTML = '<input type="checkbox" name="subject_ids[]" checked value="' + subjectId + '"><span><strong></strong><small></small></span><span class="track-plan-remove-label">Remove</span>';
            item.querySelector("strong").textContent = subjectName;
            item.querySelector("small").textContent = subjectKind;
            document.getElementById("track-plan-selected-list")?.appendChild(item);
            selectedOption.remove();
            addSubjectSelect.value = "";
        });
        document.getElementById("track-plan-selected-list")?.addEventListener("change", function (event) {
            if (event.target.matches('input[type="checkbox"]') && !event.target.checked) {
                const row = event.target.closest("[data-subject-row]");
                const option = document.createElement("option");
                option.value = row.dataset.subjectRow;
                option.dataset.subjectName = row.dataset.subjectName;
                option.dataset.subjectKind = row.dataset.subjectKind;
                option.textContent = row.dataset.subjectName + (row.dataset.subjectKind === "Core subject" ? " · Core" : " · Track / strand");
                addSubjectSelect?.appendChild(option);
                row.remove();
            }
        });
    </script>
</body>
</html>
