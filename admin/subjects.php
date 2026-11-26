<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/section_schema.php";
ensureSectionSchema($conn);
require_once __DIR__ . "/../includes/curriculum_schema.php";
ensureCurriculumSchema($conn);

// Keep existing installs compatible with archive support without requiring
// a separate manual migration before the page can load.
try {
    $archiveColumn = $conn->query("SHOW COLUMNS FROM subjects LIKE 'is_archived'");
    if ($archiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE subjects ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (mysqli_sql_exception $e) {
    error_log("Subject archive schema check failed: " . $e->getMessage());
}

try {
    $subjectWeightColumns = [
        "default_written_works_weight" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "default_performance_tasks_weight" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "default_term_assessment_weight" => "DECIMAL(5,2) NULL DEFAULT NULL"
    ];
    foreach ($subjectWeightColumns as $columnName => $columnDefinition) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM subjects LIKE '" . $columnName . "'");
        if ($columnCheck->num_rows === 0) {
            $conn->query("ALTER TABLE subjects ADD COLUMN " . $columnName . " " . $columnDefinition);
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log("Subject component weight schema check failed: " . $e->getMessage());
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS subject_grading_components (
            subject_component_id INT NOT NULL AUTO_INCREMENT,
            subject_id INT NOT NULL,
            component_name VARCHAR(100) NOT NULL,
            weight_percentage DECIMAL(5,2) NOT NULL,
            PRIMARY KEY (subject_component_id),
            UNIQUE KEY uq_subject_grading_component (subject_id, component_name),
            CONSTRAINT fk_subject_grading_component_subject FOREIGN KEY (subject_id)
                REFERENCES subjects(subject_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $customArchiveColumn = $conn->query("SHOW COLUMNS FROM subject_grading_components LIKE 'is_archived'");
    if ($customArchiveColumn->num_rows === 0) {
        $conn->query("ALTER TABLE subject_grading_components ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (mysqli_sql_exception $e) {
    error_log("Subject custom component schema check failed: " . $e->getMessage());
}

if (!isset($_SESSION["admin_subjects_csrf"])) {
    $_SESSION["admin_subjects_csrf"] = bin2hex(random_bytes(32));
}

$componentNames = ["Written Works", "Performance Tasks", "Term Assessment"];
$error = "";
$message = "";
$subjects = [];
$catalogSubjects = [];
$catalogTotal = 0;
$catalogPageSize = 10;
$catalogPage = max(1, (int) ($_GET["catalog_page"] ?? 1));
$catalogPageCount = 1;
$catalogOffset = 0;
$catalogSearch = trim((string) ($_GET["catalog_search"] ?? ""));
$catalogTrackFilter = (string) ($_GET["catalog_track"] ?? "all");
if ($catalogTrackFilter !== "all" && $catalogTrackFilter !== "core" && !ctype_digit($catalogTrackFilter)) {
    $catalogTrackFilter = "all";
}
$catalogPageLinkPrefix = "../admin-subjects?" . ($catalogSearch !== "" ? "catalog_search=" . rawurlencode($catalogSearch) . "&" : "")
    . ($catalogTrackFilter !== "all" ? "catalog_track=" . rawurlencode($catalogTrackFilter) . "&" : "")
    . "catalog_page=";
$tracks = [];
$sections = [];
$teachers = [];
$classSubjects = [];
$weightsByClass = [];
$manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$schoolYearStart = (int) $manilaNow->format("Y");
if ((int) $manilaNow->format("n") < 6) {
    $schoolYearStart--;
}
$schoolYear = $schoolYearStart . "-" . ($schoolYearStart + 1);
$offeringTerm = (int) ($_POST["term"] ?? $_GET["term"] ?? 1);
if (!in_array($offeringTerm, [1, 2, 3], true)) $offeringTerm = 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!hash_equals($_SESSION["admin_subjects_csrf"], $_POST["csrf_token"] ?? "")) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }

        $action = $_POST["action"] ?? "";
        if ($action === "add_subject") {
            $subjectName = trim($_POST["subject_name"] ?? "");
            $trackChoice = $_POST["track_id"] ?? "";
            $isCoreSubject = $trackChoice === "core";
            $trackId = $isCoreSubject ? null : (int) $trackChoice;
            $gradeLevel = (int) ($_POST["grade_level"] ?? 0);
            $term = (int) ($_POST["term"] ?? 0);
            $weights = $_POST["weights"] ?? [];
            if ($subjectName === "" || strlen($subjectName) > 150) {
                throw new Exception("Enter a subject name up to 150 characters long.");
            }
            if (!in_array($gradeLevel, [11, 12], true) || !in_array($term, [1, 2, 3], true)) {
                throw new Exception("Choose a valid grade level and term.");
            }
            $checkedWeights = [];
            $totalWeight = 0.0;
            foreach ($componentNames as $componentName) {
                $value = $weights[$componentName] ?? "";
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                    throw new Exception("Enter each component weight between 0 and 100.");
                }
                $checkedWeights[$componentName] = (float) $value;
                $totalWeight += (float) $value;
            }
            if (abs($totalWeight - 100) > 0.001) {
                throw new Exception("The three component weights must add up to 100%.");
            }
            if (!$isCoreSubject) {
                $trackCheck = $conn->prepare("SELECT track_id FROM tracks WHERE track_id = ?");
                $trackCheck->bind_param("i", $trackId);
                $trackCheck->execute();
                $trackExists = $trackCheck->get_result()->fetch_assoc();
                $trackCheck->close();
                if (!$trackExists) {
                    throw new Exception("Choose a valid track or strand, or mark this as a core subject.");
                }
            }

            $duplicateCheck = $conn->prepare(
                "SELECT subject_id FROM subjects
                WHERE subject_name = ? AND track_id <=> ? AND grade_level = ? AND semester = ? AND is_archived = 0"
            );
            $duplicateCheck->bind_param("siii", $subjectName, $trackId, $gradeLevel, $term);
            $duplicateCheck->execute();
            $duplicate = $duplicateCheck->get_result()->fetch_assoc();
            $duplicateCheck->close();
            if ($duplicate) {
                throw new Exception("That subject is already listed for this track, grade, and term.");
            }

            $addSubject = $conn->prepare(
                "INSERT INTO subjects (
                    subject_name, track_id, grade_level, semester,
                    default_written_works_weight, default_performance_tasks_weight,
                    default_term_assessment_weight
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $writtenWorksWeight = $checkedWeights["Written Works"];
            $performanceTasksWeight = $checkedWeights["Performance Tasks"];
            $termAssessmentWeight = $checkedWeights["Term Assessment"];
            $addSubject->bind_param("siiiddd", $subjectName, $trackId, $gradeLevel, $term, $writtenWorksWeight, $performanceTasksWeight, $termAssessmentWeight);
            $addSubject->execute();
            $addSubject->close();
            $message = $isCoreSubject
                ? "Core subject added to the catalog. Assign it to each track that should include it."
                : "Subject added to the catalog.";
        } elseif ($action === "archive_subject") {
            $subjectId = (int) ($_POST["subject_id"] ?? 0);
            $archiveSubject = $conn->prepare("UPDATE subjects SET is_archived = 1 WHERE subject_id = ? AND is_archived = 0");
            $archiveSubject->bind_param("i", $subjectId);
            $archiveSubject->execute();
            if ($archiveSubject->affected_rows === 0) {
                throw new Exception("That active subject could not be found.");
            }
            $archiveSubject->close();
            $message = "Subject archived. Existing class records are preserved.";
        } elseif ($action === "assign_teacher") {
            $subjectId = (int) ($_POST["subject_id"] ?? 0);
            $sectionId = (int) ($_POST["section_id"] ?? 0);
            $teacherId = (int) ($_POST["teacher_id"] ?? 0);
            $subjectTerm = (int) ($_POST["term"] ?? 0);
            if (!in_array($subjectTerm, [1, 2, 3], true)) {
                throw new Exception("Choose the current term.");
            }
            $subjectCheck = $conn->prepare(
                "SELECT grade_level, track_id, semester, default_written_works_weight,
                        default_performance_tasks_weight, default_term_assessment_weight
                 FROM subjects WHERE subject_id = ? AND is_archived = 0"
            );
            $subjectCheck->bind_param("i", $subjectId);
            $subjectCheck->execute();
            $subject = $subjectCheck->get_result()->fetch_assoc();
            $subjectCheck->close();
            $sectionCheck = $conn->prepare("SELECT grade_level, track_id FROM sections WHERE section_id = ?");
            $sectionCheck->bind_param("i", $sectionId);
            $sectionCheck->execute();
            $section = $sectionCheck->get_result()->fetch_assoc();
            $sectionCheck->close();
            $teacherCheck = $conn->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ?");
            $teacherCheck->bind_param("i", $teacherId);
            $teacherCheck->execute();
            $teacherExists = $teacherCheck->get_result()->fetch_assoc();
            $teacherCheck->close();

            if (!$subject || !$section || !$teacherExists) {
                throw new Exception("Choose a valid subject, section, and teacher.");
            }
            if ((int) $subject["grade_level"] !== (int) $section["grade_level"]) {
                throw new Exception("The subject grade level must match the section grade level.");
            }
            if ($subject["track_id"] !== null &&
                ($section["track_id"] === null || (int) $subject["track_id"] !== (int) $section["track_id"])) {
                throw new Exception("Choose a subject offered for this section's track or strand.");
            }
            if ($section["track_id"] === null) {
                throw new Exception("Assign a track or strand to this section before offering curriculum subjects.");
            }
            $curriculumAssignmentCheck = $conn->prepare(
                "SELECT subject_id FROM track_subject_assignments
                 WHERE track_id = ? AND grade_level = ? AND semester = ? AND subject_id = ? LIMIT 1"
            );
            $sectionTrackId = (int) $section["track_id"];
            $sectionGradeLevel = (int) $section["grade_level"];
            if ((int) $subject["semester"] !== $subjectTerm) {
                throw new Exception("Choose a subject assigned to the selected term.");
            }
            $curriculumAssignmentCheck->bind_param("iiii", $sectionTrackId, $sectionGradeLevel, $subjectTerm, $subjectId);
            $curriculumAssignmentCheck->execute();
            $curriculumSubjectAllowed = $curriculumAssignmentCheck->get_result()->fetch_assoc();
            $curriculumAssignmentCheck->close();
            if (!$curriculumSubjectAllowed) {
                throw new Exception("This subject is not in the selected track's curriculum plan for Grade " . $sectionGradeLevel . ", Term " . $subjectTerm . ". Add it to the plan first.");
            }
            $duplicateClass = $conn->prepare(
                "SELECT class_subject_id, teacher_id FROM class_subjects
                 WHERE section_id = ? AND subject_id = ? AND school_year = ?"
            );
            $duplicateClass->bind_param("iis", $sectionId, $subjectId, $schoolYear);
            $duplicateClass->execute();
            $classAlreadyExists = $duplicateClass->get_result()->fetch_assoc();
            $duplicateClass->close();
            if ($classAlreadyExists) {
                if ($classAlreadyExists["teacher_id"] !== null) {
                    throw new Exception("A teacher is already assigned to this subject for the selected section and school year.");
                }
                $classSubjectId = (int) $classAlreadyExists["class_subject_id"];
                $assignTeacher = $conn->prepare("UPDATE class_subjects SET teacher_id = ? WHERE class_subject_id = ?");
                $assignTeacher->bind_param("ii", $teacherId, $classSubjectId);
                $assignTeacher->execute();
                $assignTeacher->close();
                $message = "Teacher assigned to the subject for this section.";
            } else {
            $checkedWeights = [
                "Written Works" => $subject["default_written_works_weight"],
                "Performance Tasks" => $subject["default_performance_tasks_weight"],
                "Term Assessment" => $subject["default_term_assessment_weight"]
            ];
            $weightsFromExistingOffering = false;
            if (in_array(null, $checkedWeights, true)) {
                $existingOfferingWeightsQuery = $conn->prepare(
                    "SELECT class_subjects.class_subject_id,
                            grading_components.component_name,
                            grading_components.weight_percentage
                     FROM class_subjects
                     JOIN grading_components ON grading_components.class_subject_id = class_subjects.class_subject_id
                     WHERE class_subjects.subject_id = ?
                     ORDER BY class_subjects.class_subject_id DESC, grading_components.component_id"
                );
                $existingOfferingWeightsQuery->bind_param("i", $subjectId);
                $existingOfferingWeightsQuery->execute();
                $existingOfferingWeightRows = $existingOfferingWeightsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $existingOfferingWeightsQuery->close();

                $weightsByOffering = [];
                foreach ($existingOfferingWeightRows as $weightRow) {
                    $normalizedComponentName = strtolower(trim((string) $weightRow["component_name"]));
                    foreach ($componentNames as $componentName) {
                        if ($normalizedComponentName === strtolower($componentName)) {
                            $weightsByOffering[(int) $weightRow["class_subject_id"]][$componentName] = $weightRow["weight_percentage"];
                            break;
                        }
                    }
                }
                foreach ($weightsByOffering as $offeringWeights) {
                    if (count($offeringWeights) !== count($componentNames)) {
                        continue;
                    }
                    $offeringIsValid = true;
                    foreach ($componentNames as $componentName) {
                        $weight = $offeringWeights[$componentName] ?? null;
                        if (!is_numeric($weight) || (float) $weight < 0 || (float) $weight > 100) {
                            $offeringIsValid = false;
                            break;
                        }
                    }
                    if ($offeringIsValid) {
                        $checkedWeights = $offeringWeights;
                        $weightsFromExistingOffering = true;
                        break;
                    }
                }
                if (!$weightsFromExistingOffering) {
                    throw new Exception("No complete saved component weight set was found for this subject. Check its subject defaults or existing offering components before offering it.");
                }
            }
            $totalWeight = array_sum(array_map("floatval", $checkedWeights));
            if (array_filter($checkedWeights, static fn($value) => !is_numeric($value) || (float) $value < 0 || (float) $value > 100)) {
                throw new Exception("This subject has invalid component weights. Update the subject weights before offering it.");
            }
            if (abs($totalWeight - 100) > 0.001) {
                throw new Exception("The saved component weights currently total " . number_format($totalWeight, 2) . "%. Update them to total 100% before offering this subject.");
            }

            $conn->begin_transaction();
            try {
                if ($weightsFromExistingOffering) {
                    $saveSubjectWeights = $conn->prepare(
                        "UPDATE subjects
                         SET default_written_works_weight = ?,
                             default_performance_tasks_weight = ?,
                             default_term_assessment_weight = ?
                         WHERE subject_id = ?"
                    );
                    $defaultWrittenWorks = (float) $checkedWeights["Written Works"];
                    $defaultPerformanceTasks = (float) $checkedWeights["Performance Tasks"];
                    $defaultTermAssessment = (float) $checkedWeights["Term Assessment"];
                    $saveSubjectWeights->bind_param("dddi", $defaultWrittenWorks, $defaultPerformanceTasks, $defaultTermAssessment, $subjectId);
                    $saveSubjectWeights->execute();
                    $saveSubjectWeights->close();
                }

                $addClass = $conn->prepare(
                    "INSERT INTO class_subjects (section_id, subject_id, teacher_id, school_year)
                     VALUES (?, ?, ?, ?)"
                );
                $addClass->bind_param("iiis", $sectionId, $subjectId, $teacherId, $schoolYear);
                $addClass->execute();
                $classSubjectId = (int) $conn->insert_id;
                $addClass->close();

                $addComponent = $conn->prepare(
                    "INSERT INTO grading_components (class_subject_id, component_name, weight_percentage)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        component_name = VALUES(component_name),
                        weight_percentage = VALUES(weight_percentage)"
                );
                foreach ($componentNames as $componentName) {
                    $weight = $checkedWeights[$componentName];
                    $addComponent->bind_param("isd", $classSubjectId, $componentName, $weight);
                    $addComponent->execute();
                }
                $addComponent->close();
                $customComponentQuery = $conn->prepare("SELECT component_name, weight_percentage FROM subject_grading_components WHERE subject_id = ? AND is_archived = 0 ORDER BY subject_component_id");
                $customComponentQuery->bind_param("i", $subjectId);
                $customComponentQuery->execute();
                $customComponents = $customComponentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $customComponentQuery->close();
                $customWeightTotal = array_sum(array_map(static fn($component) => (float) $component["weight_percentage"], $customComponents));
                if ($customWeightTotal > 0) {
                    $highestName = array_keys($checkedWeights, max($checkedWeights), true)[0];
                    $rebalancedWeight = (float) $checkedWeights[$highestName] - $customWeightTotal;
                    if ($rebalancedWeight < 0) throw new Exception("The custom component weights exceed the highest-weighted component allocation.");
                    $standardUpdate = $conn->prepare("UPDATE grading_components SET weight_percentage = ? WHERE class_subject_id = ? AND component_name = ?");
                    $standardUpdate->bind_param("dis", $rebalancedWeight, $classSubjectId, $highestName);
                    $standardUpdate->execute();
                    $standardUpdate->close();
                    $addCustomComponent = $conn->prepare("INSERT INTO grading_components (class_subject_id, component_name, weight_percentage) VALUES (?, ?, ?)");
                    foreach ($customComponents as $customComponent) {
                        $customName = (string) $customComponent["component_name"];
                        $customWeight = (float) $customComponent["weight_percentage"];
                        $addCustomComponent->bind_param("isd", $classSubjectId, $customName, $customWeight);
                        $addCustomComponent->execute();
                    }
                    $addCustomComponent->close();
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $message = "Teacher assigned to the subject for this section.";
            }
        } elseif ($action === "save_weights") {
            $classSubjectId = (int) ($_POST["class_subject_id"] ?? 0);
            $weights = $_POST["weights"] ?? [];
            $classCheck = $conn->prepare("SELECT class_subject_id, subject_id FROM class_subjects WHERE class_subject_id = ?");
            $classCheck->bind_param("i", $classSubjectId);
            $classCheck->execute();
            $classExists = $classCheck->get_result()->fetch_assoc();
            $classCheck->close();
            if (!$classExists) {
                throw new Exception("Choose a valid class subject.");
            }

            $customWeightQuery = $conn->prepare("SELECT COALESCE(SUM(weight_percentage), 0) AS total FROM subject_grading_components WHERE subject_id = ? AND is_archived = 0");
            $subjectIdForCustomWeights = (int) $classExists["subject_id"];
            $customWeightQuery->bind_param("i", $subjectIdForCustomWeights);
            $customWeightQuery->execute();
            $customWeightTotal = (float) ($customWeightQuery->get_result()->fetch_assoc()["total"] ?? 0);
            $customWeightQuery->close();

            $checkedWeights = [];
            $totalWeight = 0.0;
            foreach ($componentNames as $componentName) {
                $value = $weights[$componentName] ?? "";
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                    throw new Exception("Enter each component weight between 0 and 100.");
                }
                $checkedWeights[$componentName] = (float) $value;
                $totalWeight += (float) $value;
            }
            if (abs($totalWeight + $customWeightTotal - 100) > 0.001) {
                throw new Exception("Standard and added component weights must add up to 100%.");
            }

            $conn->begin_transaction();
            try {
                foreach ($componentNames as $componentName) {
                    $componentCheck = $conn->prepare(
                        "SELECT component_id FROM grading_components
                         WHERE class_subject_id = ? AND component_name = ? LIMIT 1"
                    );
                    $componentCheck->bind_param("is", $classSubjectId, $componentName);
                    $componentCheck->execute();
                    $component = $componentCheck->get_result()->fetch_assoc();
                    $componentCheck->close();

                    $weight = $checkedWeights[$componentName];
                    if ($component) {
                        $componentId = (int) $component["component_id"];
                        $updateWeight = $conn->prepare(
                            "UPDATE grading_components SET weight_percentage = ? WHERE component_id = ?"
                        );
                        $updateWeight->bind_param("di", $weight, $componentId);
                    } else {
                        $updateWeight = $conn->prepare(
                            "INSERT INTO grading_components (class_subject_id, component_name, weight_percentage)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                                component_name = VALUES(component_name),
                                weight_percentage = VALUES(weight_percentage)"
                        );
                        $updateWeight->bind_param("isd", $classSubjectId, $componentName, $weight);
                    }
                    $updateWeight->execute();
                    $updateWeight->close();
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $savedWeightsQuery = $conn->prepare(
                "SELECT component_name, weight_percentage
                 FROM grading_components
                 WHERE class_subject_id = ?"
            );
            $savedWeightsQuery->bind_param("i", $classSubjectId);
            $savedWeightsQuery->execute();
            $savedWeightRows = $savedWeightsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $savedWeightsQuery->close();
            $savedWeights = [];
            foreach ($savedWeightRows as $savedWeightRow) {
                $savedWeights[trim((string) $savedWeightRow["component_name"])] = (float) $savedWeightRow["weight_percentage"];
            }
            foreach ($componentNames as $componentName) {
                if (!isset($savedWeights[$componentName]) || abs($savedWeights[$componentName] - $checkedWeights[$componentName]) > 0.005) {
                    throw new Exception("The saved grading weights did not match the values submitted. Please try again.");
                }
            }
            $message = sprintf(
                "Grading weights saved: Written Works %s%%, Performance Tasks %s%%, Term Assessment %s%%.",
                number_format($savedWeights["Written Works"], 2),
                number_format($savedWeights["Performance Tasks"], 2),
                number_format($savedWeights["Term Assessment"], 2)
            );
        } else {
            throw new Exception("Choose a valid subject management action.");
        }

        $_SESSION["admin_subjects_csrf"] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        error_log("Admin subjects page error: " . $e->getMessage());
        $error = $e instanceof mysqli_sql_exception
            ? ((($_POST["action"] ?? "") === "save_weights")
                ? "Could not save the grading weights. Refresh the page and try again."
                : "Could not save the subject changes. Refresh the page and try again.")
            : $e->getMessage();
    }
}

try {
    $searchPattern = $catalogSearch === "" ? "%" : "%" . $catalogSearch . "%";
    $catalogTrackCondition = $catalogTrackFilter === "core"
        ? " AND subjects.track_id IS NULL"
        : ($catalogTrackFilter !== "all" ? " AND subjects.track_id = " . (int) $catalogTrackFilter : "");
    $countQuery = $conn->prepare("SELECT COUNT(*) AS total FROM subjects WHERE subject_name LIKE ? AND is_archived = 0" . $catalogTrackCondition);
    $countQuery->bind_param("s", $searchPattern);
    $countQuery->execute();
    $catalogTotal = (int) $countQuery->get_result()->fetch_assoc()["total"];
    $countQuery->close();
    $catalogPageCount = max(1, (int) ceil($catalogTotal / $catalogPageSize));
    $catalogPage = min($catalogPage, $catalogPageCount);
    $catalogOffset = ($catalogPage - 1) * $catalogPageSize;

    $catalogQuery = $conn->prepare(
        "SELECT subjects.subject_id, subjects.subject_name, subjects.track_id, subjects.grade_level,
                subjects.semester, tracks.track_name, tracks.strand_name,
                COUNT(class_subjects.class_subject_id) AS class_count
         FROM subjects
         LEFT JOIN tracks ON tracks.track_id = subjects.track_id
         LEFT JOIN class_subjects ON class_subjects.subject_id = subjects.subject_id
         WHERE subjects.subject_name LIKE ? AND subjects.is_archived = 0" . $catalogTrackCondition . "
         GROUP BY subjects.subject_id, subjects.subject_name, subjects.grade_level,
                  subjects.track_id, subjects.semester, tracks.track_name, tracks.strand_name
         ORDER BY subjects.grade_level, tracks.track_name, tracks.strand_name,
                  subjects.semester, subjects.subject_name
         LIMIT ?, ?"
    );
    $catalogQuery->bind_param("sii", $searchPattern, $catalogOffset, $catalogPageSize);
    $catalogQuery->execute();
    $catalogSubjects = $catalogQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $catalogQuery->close();

    // Keep the full subject list for the class-offering dropdown below.
    $subjects = $conn->query(
        "SELECT subjects.subject_id, subjects.subject_name, subjects.track_id, subjects.grade_level,
                subjects.semester, subjects.default_written_works_weight,
                subjects.default_performance_tasks_weight, subjects.default_term_assessment_weight,
                tracks.track_name, tracks.strand_name,
                COUNT(class_subjects.class_subject_id) AS class_count
         FROM subjects
         LEFT JOIN tracks ON tracks.track_id = subjects.track_id
         LEFT JOIN class_subjects ON class_subjects.subject_id = subjects.subject_id
         WHERE subjects.is_archived = 0
         GROUP BY subjects.subject_id, subjects.subject_name, subjects.grade_level,
                  subjects.track_id, subjects.semester, tracks.track_name, tracks.strand_name
         ORDER BY subjects.grade_level, tracks.track_name, tracks.strand_name,
                  subjects.semester, subjects.subject_name"
    )->fetch_all(MYSQLI_ASSOC);
    $assignedTrackTermsBySubject = [];
    $assignmentRows = $conn->query(
        "SELECT subject_id, track_id, grade_level, semester
         FROM track_subject_assignments
         ORDER BY subject_id, track_id, grade_level, semester"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($assignmentRows as $assignmentRow) {
        $assignedTrackTermsBySubject[(int) $assignmentRow["subject_id"]][] =
            (int) $assignmentRow["track_id"] . ":" . (int) $assignmentRow["grade_level"] . ":" . (int) $assignmentRow["semester"];
    }
    $teacherAssignedSectionsBySubject = [];
    $teacherAssignmentsQuery = $conn->prepare(
        "SELECT section_id, subject_id FROM class_subjects
         WHERE school_year = ? AND teacher_id IS NOT NULL"
    );
    $teacherAssignmentsQuery->bind_param("s", $schoolYear);
    $teacherAssignmentsQuery->execute();
    foreach ($teacherAssignmentsQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $teacherAssignmentRow) {
        $teacherAssignedSectionsBySubject[(int) $teacherAssignmentRow["subject_id"]][] =
            (string) $teacherAssignmentRow["section_id"];
    }
    $teacherAssignmentsQuery->close();
    foreach ($subjects as &$subject) {
        $subject["assigned_track_terms"] = implode(",", $assignedTrackTermsBySubject[(int) $subject["subject_id"]] ?? []);
        $subject["teacher_assigned_section_ids"] = implode(",", $teacherAssignedSectionsBySubject[(int) $subject["subject_id"]] ?? []);
    }
    unset($subject);
    $tracks = $conn->query("SELECT track_id, track_name, strand_name FROM tracks ORDER BY track_name, strand_name")
        ->fetch_all(MYSQLI_ASSOC);
    $sections = $conn->query("SELECT section_id, section_name, grade_level, track_id FROM sections WHERE is_archived = 0 ORDER BY grade_level, section_name")
        ->fetch_all(MYSQLI_ASSOC);
    $teachers = $conn->query(
        "SELECT teachers.teacher_id, users.first_name, users.last_name
         FROM teachers JOIN users ON users.user_id = teachers.user_id
         ORDER BY users.last_name, users.first_name"
    )->fetch_all(MYSQLI_ASSOC);
    $classSubjects = $conn->query(
        "SELECT class_subjects.class_subject_id, class_subjects.school_year,
                subjects.subject_name, subjects.grade_level, sections.section_name,
                users.first_name, users.last_name
         FROM class_subjects
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         JOIN sections ON sections.section_id = class_subjects.section_id
         LEFT JOIN teachers ON teachers.teacher_id = class_subjects.teacher_id
         LEFT JOIN users ON users.user_id = teachers.user_id
         ORDER BY class_subjects.school_year DESC, subjects.grade_level,
                  sections.section_name, subjects.subject_name"
    )->fetch_all(MYSQLI_ASSOC);
    $weightRows = $conn->query(
        "SELECT class_subject_id, component_name, weight_percentage FROM grading_components"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($weightRows as $weightRow) {
        $componentName = trim((string) $weightRow["component_name"]);
        if (!in_array($componentName, $componentNames, true)) {
            continue;
        }
        $weightsByClass[(int) $weightRow["class_subject_id"]][$componentName] =
            $weightRow["weight_percentage"];
    }
} catch (mysqli_sql_exception $e) {
    error_log("Admin subjects catalog error: " . $e->getMessage());
    $error = $error !== "" ? $error : "We could not load the subject catalog right now.";
}

$adminName = $_SESSION["name"] ?? "Administrator";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage subjects and class offerings in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Subjects | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="subjects.css?v=<?= (int) filemtime(__DIR__ . "/subjects.css") ?>">
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
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu"><span></span><span></span><span></span></button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user"><strong><?= h($adminName) ?></strong><span>Admin</span></div>
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
            <h1>Subjects</h1>
        </div>

        <?php 
if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php 
endif; ?>

        <nav class="add-subject-toolbar" aria-label="Subject management">
            <button class="small-button" type="button" id="open-add-subject">Add a subject</button>
            <button class="small-button" type="button" id="open-offer-subject">Assign a teacher to a subject</button>
            <a class="small-button subject-weights-link" href="../admin-subject_weights">Update component weights</a>
            <a class="small-button subject-weights-link" href="../admin-track_subjects">Set subjects</a>
        </nav>

        <div class="dashboard-modal-overlay" id="add-subject-modal" hidden>
            <section class="dashboard-form-modal subject-add-dialog" role="dialog" aria-modal="true" aria-labelledby="add-subject-title" tabindex="-1">
                <div class="dashboard-form-modal-heading"><div><h2 id="add-subject-title">Add a subject</h2><p>Add a catalog subject, then choose which tracks can use it on the Set subjects page.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
                <form action="../admin-subjects" class="grade-form subject-add-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subjects_csrf"]) ?>">
                    <input type="hidden" name="action" value="add_subject">
                    <label>Subject name<input name="subject_name" maxlength="150" required></label>
                    <label>Track / strand<select name="track_id" required>
                        <option value="">Select track / strand</option>
                        <option value="core">Core</option>
                        <?php 
foreach ($tracks as $track): ?><option value="<?= (int) $track["track_id"] ?>"><?= h($track["track_name"] . " / " . $track["strand_name"]) ?></option><?php 
endforeach; ?>
                    </select></label>
                    <label>Grade level<select name="grade_level" required><option value="11">Grade 11</option><option value="12">Grade 12</option></select></label>
                    <label>Term<select name="term" required><option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option></select></label>
                    <?php 
foreach ($componentNames as $componentIndex => $componentName): ?>
                        <label><?= h($componentName) ?> weight (%)<input type="number" name="weights[<?= h($componentName) ?>]" min="0" max="100" step="0.01" value="<?= $componentIndex === 2 ? "20" : "40" ?>" required></label>
                    <?php 
endforeach; ?>
                    <p class="weight-note">The three component weights must add up to 100%.</p>
                    <div class="dashboard-form-modal-actions"><button class="dashboard-modal-close" type="button">Cancel</button><button class="change-password-button" type="submit">Add subject</button></div>
                </form>
            </section>
        </div>

        <?php
        
?>
            <nav class="catalog-pagination" aria-label="Subject catalog pages">
                <?php 
if ($catalogTotal > 0): ?>
                    <div class="pagination-controls">
                        <?php 
if ($catalogPage > 1): ?><a class="pagination-arrow" href="<?= h($catalogPageLinkPrefix . ($catalogPage - 1)) ?>#subject-catalog" aria-label="Previous page"><span aria-hidden="true">←</span> Previous</a><?php 
else: ?><span class="pagination-arrow disabled" aria-disabled="true"><span aria-hidden="true">←</span> Previous</span><?php 
endif; ?>
                        <?php 
if ($catalogPage < $catalogPageCount): ?><a class="pagination-arrow" href="<?= h($catalogPageLinkPrefix . ($catalogPage + 1)) ?>#subject-catalog" aria-label="Next page">Next <span aria-hidden="true">→</span></a><?php 
else: ?><span class="pagination-arrow disabled" aria-disabled="true">Next <span aria-hidden="true">→</span></span><?php 
endif; ?>
                    </div>
                <?php 
endif; ?>
                <form class="catalog-track-filter" action="../admin-subjects#subject-catalog" method="get">
                    <?php 
if ($catalogSearch !== ""): ?><input type="hidden" name="catalog_search" value="<?= h($catalogSearch) ?>"><?php 
endif; ?>
                    <label for="catalog-track-filter">Track</label>
                    <select name="catalog_track" id="catalog-track-filter" aria-label="Filter subjects by track">
                        <option value="all" <?= $catalogTrackFilter === "all" ? "selected" : "" ?>>All tracks</option>
                        <option value="core" <?= $catalogTrackFilter === "core" ? "selected" : "" ?>>Core subjects</option>
                        <?php 
foreach ($tracks as $track): ?>
                            <option value="<?= (int) $track["track_id"] ?>" <?= $catalogTrackFilter === (string) $track["track_id"] ? "selected" : "" ?>>
                                <?= h($track["track_name"] . " / " . $track["strand_name"]) ?>
                            </option>
                        <?php 
endforeach; ?>
                    </select>
                </form>
                <form class="catalog-search" action="../admin-subjects#subject-catalog" method="get" role="search">
                    <?php 
if ($catalogTrackFilter !== "all"): ?><input type="hidden" name="catalog_track" value="<?= h($catalogTrackFilter) ?>"><?php 
endif; ?>
                    <label class="visually-hidden" for="catalog-search-input">Search subjects</label>
                    <div class="catalog-search-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                        <input id="catalog-search-input" type="search" name="catalog_search" value="<?= h($catalogSearch) ?>" placeholder="Search subjects" maxlength="100">
                    </div>
                    <button type="submit">Search</button>
                    <?php 
if ($catalogSearch !== ""): ?><a class="catalog-search-clear" href="../admin-subjects<?= $catalogTrackFilter !== "all" ? "?catalog_track=" . rawurlencode($catalogTrackFilter) : "" ?>#subject-catalog">Clear</a><?php 
endif; ?>
                </form>
            </nav>

        <section class="panel" id="subject-catalog">
            <div class="panel-heading"><div><h2>Subject catalog</h2><p>Archived subjects are hidden from the catalog and new class offerings; existing class records stay intact.</p></div></div>
            <?php 
if ($catalogTotal === 0): ?>
                <p class="empty-state"><?= $catalogSearch !== "" ? "No subjects match your search." : "No subjects have been added yet." ?></p>
            <?php 
else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Subject</th><th>Track / strand</th><th>Grade</th><th>Term</th><th></th></tr></thead>
                    <tbody><?php 
foreach ($catalogSubjects as $subject): ?><tr>
                        <td><?= h($subject["subject_name"]) ?></td>
                        <td><?= $subject["track_id"] === null ? "Core / All tracks" : h($subject["track_name"] . " / " . $subject["strand_name"]) ?></td>
                        <td><?= h($subject["grade_level"]) ?></td>
                        <td>Term <?= h($subject["semester"]) ?></td>
                        <td><form action="../admin-subjects" method="post" onsubmit="return confirm('Archive this subject? It will no longer be available for new class offerings.');">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subjects_csrf"]) ?>">
                            <input type="hidden" name="action" value="archive_subject">
                            <input type="hidden" name="subject_id" value="<?= (int) $subject["subject_id"] ?>">
                            <button class="small-button" type="submit">Archive</button>
                        </form></td>
                    </tr><?php 
endforeach; ?></tbody>
                </table></div>
            <?php 
endif; ?>
        </section>

        <div class="dashboard-modal-overlay" id="offer-subject-modal" hidden>
            <section class="dashboard-form-modal subject-offer-dialog" role="dialog" aria-modal="true" aria-labelledby="offer-subject-title" tabindex="-1">
                <div class="dashboard-form-modal-heading"><div><h2 id="offer-subject-title">Assign a teacher to a subject</h2><p>Choose a section and current term, then select a planned subject and its teacher.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
            <?php 
if (!$subjects || !$sections || !$teachers): ?>
                <p class="empty-state subject-offer-empty">Add subjects, sections, and teacher accounts before creating a class offering.</p>
            <?php 
else: ?>
                <form action="../admin-subjects" class="grade-form subject-offer-form" id="offering-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subjects_csrf"]) ?>">
                    <input type="hidden" name="action" value="assign_teacher">
                    <label>Section<select name="section_id" id="offering-section" required><option value="">Select a section</option><?php 
foreach ($sections as $section): ?><option value="<?= (int) $section["section_id"] ?>" data-grade="<?= (int) $section["grade_level"] ?>" data-track="<?= $section["track_id"] === null ? "" : (int) $section["track_id"] ?>">Grade <?= h($section["grade_level"]) ?> · <?= h($section["section_name"]) ?></option><?php 
endforeach; ?></select></label>
                    <label>Current term<select name="term" id="offering-term" required><?php 
for ($period = 1; $period <= 3; $period++): ?><option value="<?= $period ?>" <?= $offeringTerm === $period ? "selected" : "" ?>>Term <?= $period ?></option><?php 
endfor; ?></select></label>
                    <label>Subject<select name="subject_id" id="offering-subject" required disabled>
                        <option value="">Choose a section and term first</option>
                        <?php 
foreach ($subjects as $subject): ?>
                            <option value="<?= (int) $subject["subject_id"] ?>" data-grade="<?= (int) $subject["grade_level"] ?>" data-semester="<?= (int) $subject["semester"] ?>" data-assigned-track-terms="<?= h($subject["assigned_track_terms"]) ?>" data-teacher-assigned-section-ids="<?= h($subject["teacher_assigned_section_ids"]) ?>" data-written-works-weight="<?= h($subject["default_written_works_weight"] ?? "") ?>" data-performance-tasks-weight="<?= h($subject["default_performance_tasks_weight"] ?? "") ?>" data-term-assessment-weight="<?= h($subject["default_term_assessment_weight"] ?? "") ?>">
                                <?= h($subject["subject_name"]) ?> (<?= $subject["track_id"] === null ? "Core" : h($subject["track_name"] . " / " . $subject["strand_name"]) ?>)
                            </option>
                        <?php 
endforeach; ?>
                    </select></label>
                    <label>Teacher<select name="teacher_id" required><?php 
foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher["teacher_id"] ?>"><?= h($teacher["last_name"] . ", " . $teacher["first_name"]) ?></option><?php 
endforeach; ?></select></label>
                    <div class="dashboard-form-modal-actions"><button class="dashboard-modal-close" type="button">Cancel</button><button class="change-password-button" type="submit">Assign teacher</button></div>
                </form>
            <?php 
endif; ?>
            </section>
        </div>

    </main>
    <?php 
if ($message !== ""): ?>
        <div class="dashboard-modal-overlay" id="subject-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="subject-success-title" aria-describedby="subject-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="subject-success-title">Changes saved</h2>
                <p id="subject-success-message"><?= h($message) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
    <script>
        const catalogTrackFilter = document.getElementById("catalog-track-filter");
        catalogTrackFilter?.addEventListener("change", function () {
            catalogTrackFilter.form.requestSubmit();
        });

        const addSubjectModal = document.getElementById("add-subject-modal");
        const openAddSubjectButton = document.getElementById("open-add-subject");
        const closeAddSubjectButtons = addSubjectModal?.querySelectorAll(".dashboard-modal-close") ?? [];
        const openAddSubjectModal = function () {
            if (!addSubjectModal) return;
            addSubjectModal.hidden = false;
            document.body.classList.add("dashboard-modal-open");
            addSubjectModal.querySelector("input[name='subject_name']")?.focus();
        };
        const closeAddSubjectModal = function () {
            if (!addSubjectModal) return;
            addSubjectModal.hidden = true;
            document.body.classList.remove("dashboard-modal-open");
            openAddSubjectButton?.focus();
        };

        openAddSubjectButton?.addEventListener("click", openAddSubjectModal);
        closeAddSubjectButtons.forEach(function (button) {
            button.addEventListener("click", closeAddSubjectModal);
        });
        addSubjectModal?.addEventListener("click", function (event) {
            if (event.target === addSubjectModal) closeAddSubjectModal();
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && addSubjectModal && !addSubjectModal.hidden) {
                closeAddSubjectModal();
            }
        });

        const offerSubjectModal = document.getElementById("offer-subject-modal");
        const openOfferSubjectButton = document.getElementById("open-offer-subject");
        const closeOfferSubjectButtons = offerSubjectModal?.querySelectorAll(".dashboard-modal-close") ?? [];
        const openOfferSubjectModal = function () {
            if (!offerSubjectModal) return;
            offerSubjectModal.hidden = false;
            document.body.classList.add("dashboard-modal-open");
            offerSubjectModal.querySelector("select")?.focus();
        };
        const closeOfferSubjectModal = function () {
            if (!offerSubjectModal) return;
            offerSubjectModal.hidden = true;
            document.body.classList.remove("dashboard-modal-open");
            openOfferSubjectButton?.focus();
        };
        openOfferSubjectButton?.addEventListener("click", openOfferSubjectModal);
        closeOfferSubjectButtons.forEach(function (button) {
            button.addEventListener("click", closeOfferSubjectModal);
        });
        offerSubjectModal?.addEventListener("click", function (event) {
            if (event.target === offerSubjectModal) closeOfferSubjectModal();
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && offerSubjectModal && !offerSubjectModal.hidden) {
                closeOfferSubjectModal();
            }
        });

        const offeringSection = document.getElementById("offering-section");
        const offeringTerm = document.getElementById("offering-term");
        const offeringSubject = document.getElementById("offering-subject");

        if (offeringSection && offeringTerm && offeringSubject) {
            const subjectOptions = Array.from(offeringSubject.options).slice(1);
            const subjectPlaceholder = offeringSubject.options[0];
            const filterOfferingSubjects = function () {
                const section = offeringSection.selectedOptions[0];
                const selectedTerm = offeringTerm.value;
                let firstAvailable = null;

                if (!section || !section.value) {
                    subjectOptions.forEach(function (option) {
                        option.hidden = true;
                        option.disabled = true;
                    });
                    subjectPlaceholder.textContent = "Choose a section and term first";
                    subjectPlaceholder.hidden = false;
                    subjectPlaceholder.disabled = true;
                    offeringSubject.selectedIndex = 0;
                    offeringSubject.disabled = true;
                    return;
                }

                subjectOptions.forEach(function (option) {
                    const gradeMatches = option.dataset.grade === section.dataset.grade;
                    const sectionTrack = section.dataset.track;
                    const planKey = sectionTrack + ":" + section.dataset.grade + ":" + selectedTerm;
                    const assignedTrackTerms = (option.dataset.assignedTrackTerms || "").split(",").filter(Boolean);
                    const teacherAssignedSections = (option.dataset.teacherAssignedSectionIds || "").split(",").filter(Boolean);
                    const termMatches = option.dataset.semester === selectedTerm;
                    const trackMatches = sectionTrack !== "" && assignedTrackTerms.includes(planKey);
                    const teacherNotAssigned = !teacherAssignedSections.includes(section.value);
                    option.hidden = !(gradeMatches && termMatches && trackMatches && teacherNotAssigned);
                    option.disabled = option.hidden;
                    if (!option.hidden && firstAvailable === null) firstAvailable = option;
                });

                subjectPlaceholder.hidden = firstAvailable !== null;
                subjectPlaceholder.disabled = true;
                subjectPlaceholder.textContent = firstAvailable
                    ? "Choose a subject"
                    : "No unassigned subjects available for this section and Term " + selectedTerm;
                if (firstAvailable) {
                    if (offeringSubject.selectedOptions[0]?.hidden) firstAvailable.selected = true;
                    offeringSubject.disabled = false;
                } else {
                    offeringSubject.selectedIndex = 0;
                    offeringSubject.disabled = true;
                }
            };

            offeringSection.addEventListener("change", filterOfferingSubjects);
            offeringTerm.addEventListener("change", filterOfferingSubjects);
            filterOfferingSubjects();
        }
    </script>
</body>
</html>
