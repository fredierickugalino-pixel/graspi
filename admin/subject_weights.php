<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

try {
    $schemaColumns = [
        "is_archived" => "TINYINT(1) NOT NULL DEFAULT 0",
        "default_written_works_weight" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "default_performance_tasks_weight" => "DECIMAL(5,2) NULL DEFAULT NULL",
        "default_term_assessment_weight" => "DECIMAL(5,2) NULL DEFAULT NULL"
    ];
    foreach ($schemaColumns as $columnName => $definition) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM subjects LIKE '" . $columnName . "'");
        if ($columnCheck->num_rows === 0) {
            $conn->query("ALTER TABLE subjects ADD COLUMN " . $columnName . " " . $definition);
        }
    }
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
    error_log("Admin subject weight schema error: " . $e->getMessage());
}

if (!isset($_SESSION["admin_subject_weights_csrf"])) {
    $_SESSION["admin_subject_weights_csrf"] = bin2hex(random_bytes(32));
}

$componentNames = ["Written Works", "Performance Tasks", "Term Assessment"];

function adjustHighestActiveComponent(mysqli $conn, int $classSubjectId, int $excludedComponentId, float $weightDelta): void
{
    if (abs($weightDelta) < 0.001) return;
    $highestQuery = $conn->prepare(
        "SELECT grading_components.component_id, grading_components.weight_percentage
         FROM grading_components
         JOIN class_subjects ON class_subjects.class_subject_id = grading_components.class_subject_id
         LEFT JOIN subject_grading_components
            ON subject_grading_components.subject_id = class_subjects.subject_id
            AND LOWER(TRIM(subject_grading_components.component_name)) = LOWER(TRIM(grading_components.component_name))
         WHERE grading_components.class_subject_id = ?
           AND grading_components.component_id <> ?
           AND (subject_grading_components.subject_component_id IS NULL OR subject_grading_components.is_archived = 0)
         ORDER BY grading_components.weight_percentage DESC, grading_components.component_id
         LIMIT 1 FOR UPDATE"
    );
    $highestQuery->bind_param("ii", $classSubjectId, $excludedComponentId);
    $highestQuery->execute();
    $highest = $highestQuery->get_result()->fetch_assoc();
    $highestQuery->close();
    if (!$highest) throw new Exception("There is no active component available to rebalance this weight.");
    $newWeight = (float) $highest["weight_percentage"] + $weightDelta;
    if ($newWeight < -0.001) throw new Exception("The highest active component does not have enough weight to rebalance this component.");
    $componentId = (int) $highest["component_id"];
    $updateHighest = $conn->prepare("UPDATE grading_components SET weight_percentage = ? WHERE component_id = ?");
    $newWeight = max(0, $newWeight);
    $updateHighest->bind_param("di", $newWeight, $componentId);
    $updateHighest->execute();
    $updateHighest->close();
}

$error = "";
$message = "";
$weightValidationDetails = null;
$submittedSubjectId = 0;
$submittedStandardWeights = [];
$submittedCustomWeights = [];
$allSubjects = [];
$weightsBySubject = [];
$customComponentsBySubject = [];
$globalCustomComponentNames = [];
$globalCustomComponentArchived = [];
$componentManagementList = [];
$tracks = [];
$trackFilter = (string) ($_GET["track_id"] ?? $_POST["track_id"] ?? "all");
if ($trackFilter !== "all" && $trackFilter !== "core" && !ctype_digit($trackFilter)) {
    $trackFilter = "all";
}
$gradeFilter = (string) ($_GET["grade_level"] ?? $_POST["grade_level"] ?? "all");
if (!in_array($gradeFilter, ["all", "11", "12"], true)) {
    $gradeFilter = "all";
}
$subjectSearch = trim((string) ($_GET["search"] ?? $_POST["search"] ?? ""));
$subjectSearch = function_exists("mb_substr") ? mb_substr($subjectSearch, 0, 100) : substr($subjectSearch, 0, 100);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
        if (!hash_equals($_SESSION["admin_subject_weights_csrf"], $token)) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }

        $postAction = (string) ($_POST["action"] ?? "");
        if (in_array($postAction, ["archive_component", "recover_component", "delete_component"], true)) {
            $componentName = trim((string) ($_POST["component_name"] ?? ""));
            if ($componentName === "") throw new Exception("Choose a component to manage.");
            if ($postAction === "archive_component") {
                $conn->begin_transaction();
                try {
                    $archiveComponent = $conn->prepare("UPDATE subject_grading_components SET is_archived = 1 WHERE component_name = ? AND is_archived = 0");
                    $archiveComponent->bind_param("s", $componentName);
                    $archiveComponent->execute();
                    $affectedComponents = $archiveComponent->affected_rows;
                    $archiveComponent->close();
                    if ($affectedComponents === 0) throw new Exception("That component is already archived or could not be found.");
                    $classComponentsQuery = $conn->prepare("SELECT component_id, class_subject_id, weight_percentage FROM grading_components WHERE component_name = ? FOR UPDATE");
                    $classComponentsQuery->bind_param("s", $componentName);
                    $classComponentsQuery->execute();
                    $classComponents = $classComponentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                    $classComponentsQuery->close();
                    foreach ($classComponents as $classComponent) {
                        $weight = (float) $classComponent["weight_percentage"];
                        if ($weight <= 0) continue;
                        $classSubjectId = (int) $classComponent["class_subject_id"];
                        $componentId = (int) $classComponent["component_id"];
                        adjustHighestActiveComponent($conn, $classSubjectId, $componentId, $weight);
                        $zeroComponent = $conn->prepare("UPDATE grading_components SET weight_percentage = 0 WHERE component_id = ?");
                        $zeroComponent->bind_param("i", $componentId);
                        $zeroComponent->execute();
                        $zeroComponent->close();
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                $message = "The component was archived across all subjects. Its weight was moved to the highest active component; existing assessment history remains available.";
            } elseif ($postAction === "recover_component") {
                $conn->begin_transaction();
                try {
                    $classComponentsQuery = $conn->prepare(
                        "SELECT grading_components.component_id, grading_components.class_subject_id,
                                grading_components.weight_percentage, subject_grading_components.weight_percentage AS restore_weight
                         FROM grading_components
                         JOIN class_subjects ON class_subjects.class_subject_id = grading_components.class_subject_id
                         JOIN subject_grading_components ON subject_grading_components.subject_id = class_subjects.subject_id
                            AND subject_grading_components.component_name = grading_components.component_name
                         WHERE grading_components.component_name = ? AND subject_grading_components.is_archived = 1
                         FOR UPDATE"
                    );
                    $classComponentsQuery->bind_param("s", $componentName);
                    $classComponentsQuery->execute();
                    $classComponents = $classComponentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                    $classComponentsQuery->close();
                    foreach ($classComponents as $classComponent) {
                        $classWeight = (float) $classComponent["weight_percentage"];
                        $restoreWeight = (float) $classComponent["restore_weight"];
                        $classSubjectId = (int) $classComponent["class_subject_id"];
                        $componentId = (int) $classComponent["component_id"];
                        // Normalize archives created before archived weights were moved
                        // onto an active component, then restore from the active total.
                        if ($classWeight > 0) {
                            adjustHighestActiveComponent($conn, $classSubjectId, $componentId, $classWeight);
                            $zeroComponent = $conn->prepare("UPDATE grading_components SET weight_percentage = 0 WHERE component_id = ?");
                            $zeroComponent->bind_param("i", $componentId);
                            $zeroComponent->execute();
                            $zeroComponent->close();
                        }
                        if ($restoreWeight <= 0) continue;
                        adjustHighestActiveComponent($conn, $classSubjectId, $componentId, -$restoreWeight);
                        $restoreComponent = $conn->prepare("UPDATE grading_components SET weight_percentage = ? WHERE component_id = ?");
                        $restoreComponent->bind_param("di", $restoreWeight, $componentId);
                        $restoreComponent->execute();
                        $restoreComponent->close();
                    }
                    $recoverComponent = $conn->prepare("UPDATE subject_grading_components SET is_archived = 0 WHERE component_name = ? AND is_archived = 1");
                    $recoverComponent->bind_param("s", $componentName);
                    $recoverComponent->execute();
                    $affectedComponents = $recoverComponent->affected_rows;
                    $recoverComponent->close();
                    if ($affectedComponents === 0) throw new Exception("That archived component could not be found.");
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                $message = "The component was recovered across all subjects. Its weight was taken from the highest active component.";
            } else {
                $activeCheck = $conn->prepare("SELECT COUNT(*) AS total FROM subject_grading_components WHERE component_name = ? AND is_archived = 0");
                $activeCheck->bind_param("s", $componentName);
                $activeCheck->execute();
                $activeCount = (int) ($activeCheck->get_result()->fetch_assoc()["total"] ?? 0);
                $activeCheck->close();
                if ($activeCount > 0) throw new Exception("Archive this component before deleting it.");
                $archivedCheck = $conn->prepare("SELECT COUNT(*) AS total FROM subject_grading_components WHERE component_name = ? AND is_archived = 1");
                $archivedCheck->bind_param("s", $componentName);
                $archivedCheck->execute();
                $archivedCount = (int) ($archivedCheck->get_result()->fetch_assoc()["total"] ?? 0);
                $archivedCheck->close();
                if ($archivedCount === 0) throw new Exception("That archived component could not be found.");
                $assessmentCheck = $conn->prepare(
                    "SELECT COUNT(*) AS total FROM assessments
                     JOIN grading_components ON grading_components.component_id = assessments.component_id
                     WHERE grading_components.component_name = ?"
                );
                $assessmentCheck->bind_param("s", $componentName);
                $assessmentCheck->execute();
                $assessmentCount = (int) ($assessmentCheck->get_result()->fetch_assoc()["total"] ?? 0);
                $assessmentCheck->close();
                if ($assessmentCount > 0) throw new Exception("This component has assessment history, so it must remain archived instead of being permanently deleted.");
                $conn->begin_transaction();
                try {
                    $classComponentsQuery = $conn->prepare("SELECT component_id, class_subject_id, weight_percentage FROM grading_components WHERE component_name = ? FOR UPDATE");
                    $classComponentsQuery->bind_param("s", $componentName);
                    $classComponentsQuery->execute();
                    $classComponents = $classComponentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                    $classComponentsQuery->close();
                    foreach ($classComponents as $classComponent) {
                        $classSubjectId = (int) $classComponent["class_subject_id"];
                        $classComponentId = (int) $classComponent["component_id"];
                        adjustHighestActiveComponent($conn, $classSubjectId, $classComponentId, (float) $classComponent["weight_percentage"]);
                        $deleteClassComponent = $conn->prepare("DELETE FROM grading_components WHERE component_id = ?");
                        $deleteClassComponent->bind_param("i", $classComponentId);
                        $deleteClassComponent->execute();
                        $deleteClassComponent->close();
                    }
                    $deleteTemplate = $conn->prepare("DELETE FROM subject_grading_components WHERE component_name = ? AND is_archived = 1");
                    $deleteTemplate->bind_param("s", $componentName);
                    $deleteTemplate->execute();
                    $deletedTemplates = $deleteTemplate->affected_rows;
                    $deleteTemplate->close();
                    if ($deletedTemplates === 0) throw new Exception("That archived component could not be found.");
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                $message = "The archived component was permanently deleted.";
            }
            $_SESSION["admin_subject_weights_csrf"] = bin2hex(random_bytes(32));
        } elseif ($postAction === "add_component") {
            $componentName = trim((string) ($_POST["component_name"] ?? ""));
            $componentWeight = filter_var($_POST["component_weight"] ?? null, FILTER_VALIDATE_FLOAT);
            $componentNameLength = function_exists("mb_strlen") ? mb_strlen($componentName) : strlen($componentName);
            if ($componentName === "" || $componentNameLength > 100) throw new Exception("Enter a component name up to 100 characters.");
            if (in_array(strtolower($componentName), array_map("strtolower", $componentNames), true)) throw new Exception("That name is already used by a standard grading component.");
            if ($componentWeight === false || $componentWeight <= 0 || $componentWeight > 100 || abs((float) $componentWeight - round((float) $componentWeight)) > 0.001) throw new Exception("Enter a whole-number weight greater than 0 and no more than 100%.");
            $componentWeight = (int) round((float) $componentWeight);
            $archivedNameCheck = $conn->prepare("SELECT COUNT(*) AS total FROM subject_grading_components WHERE component_name = ? AND is_archived = 1");
            $archivedNameCheck->bind_param("s", $componentName);
            $archivedNameCheck->execute();
            $archivedNameCount = (int) ($archivedNameCheck->get_result()->fetch_assoc()["total"] ?? 0);
            $archivedNameCheck->close();
            if ($archivedNameCount > 0) throw new Exception("That component name is archived. Delete it first if it has no assessment history, or choose a different name.");
            $subjectsForComponent = $conn->query("SELECT subject_id FROM subjects WHERE is_archived = 0 ORDER BY subject_id")->fetch_all(MYSQLI_ASSOC);
            if (!$subjectsForComponent) throw new Exception("There are no active subjects to update.");
            $conn->begin_transaction();
            try {
                foreach ($subjectsForComponent as $subjectRow) {
                    $subjectId = (int) $subjectRow["subject_id"];
                    $templateFind = $conn->prepare("SELECT subject_component_id, weight_percentage FROM subject_grading_components WHERE subject_id = ? AND LOWER(TRIM(component_name)) = LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
                    $templateFind->bind_param("is", $subjectId, $componentName);
                    $templateFind->execute();
                    $template = $templateFind->get_result()->fetch_assoc();
                    $templateFind->close();
                    $oldTemplateWeight = $template ? (float) $template["weight_percentage"] : 0.0;

                    $customSumFind = $conn->prepare("SELECT COALESCE(SUM(weight_percentage), 0) AS total FROM subject_grading_components WHERE subject_id = ? AND is_archived = 0");
                    $customSumFind->bind_param("i", $subjectId);
                    $customSumFind->execute();
                    $customSum = (float) ($customSumFind->get_result()->fetch_assoc()["total"] ?? 0);
                    $customSumFind->close();
                    if ($customSum - $oldTemplateWeight + $componentWeight > 100) throw new Exception("Added component weights cannot total more than 100% for every subject.");

                    if ($template) {
                        $updateTemplate = $conn->prepare("UPDATE subject_grading_components SET component_name = ?, weight_percentage = ? WHERE subject_component_id = ?");
                        $templateId = (int) $template["subject_component_id"];
                        $updateTemplate->bind_param("sdi", $componentName, $componentWeight, $templateId);
                        $updateTemplate->execute();
                        $updateTemplate->close();
                    } else {
                        $createTemplate = $conn->prepare("INSERT INTO subject_grading_components (subject_id, component_name, weight_percentage) VALUES (?, ?, ?)");
                        $createTemplate->bind_param("isd", $subjectId, $componentName, $componentWeight);
                        $createTemplate->execute();
                        $createTemplate->close();
                    }

                    $offeringQuery = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE subject_id = ?");
                    $offeringQuery->bind_param("i", $subjectId);
                    $offeringQuery->execute();
                    $offeringRows = $offeringQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                    $offeringQuery->close();
                    foreach ($offeringRows as $offeringRow) {
                        $classSubjectId = (int) $offeringRow["class_subject_id"];
                        $classComponentFind = $conn->prepare("SELECT component_id, weight_percentage FROM grading_components WHERE class_subject_id = ? AND LOWER(TRIM(component_name)) = LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
                        $classComponentFind->bind_param("is", $classSubjectId, $componentName);
                        $classComponentFind->execute();
                        $classComponent = $classComponentFind->get_result()->fetch_assoc();
                        $classComponentFind->close();
                        $oldClassWeight = $classComponent ? (float) $classComponent["weight_percentage"] : 0.0;
                        $weightDelta = $componentWeight - $oldClassWeight;
                        if (abs($weightDelta) > 0.001) {
                            $classComponentId = $classComponent ? (int) $classComponent["component_id"] : 0;
                            adjustHighestActiveComponent($conn, $classSubjectId, $classComponentId, -$weightDelta);
                        }
                        if ($classComponent) {
                            $classComponentId = (int) $classComponent["component_id"];
                            $updateClassComponent = $conn->prepare("UPDATE grading_components SET component_name = ?, weight_percentage = ? WHERE component_id = ?");
                            $updateClassComponent->bind_param("sdi", $componentName, $componentWeight, $classComponentId);
                            $updateClassComponent->execute();
                            $updateClassComponent->close();
                        } else {
                            $addComponent = $conn->prepare("INSERT INTO grading_components (class_subject_id, component_name, weight_percentage) VALUES (?, ?, ?)");
                            $addComponent->bind_param("isd", $classSubjectId, $componentName, $componentWeight);
                            $addComponent->execute();
                            $addComponent->close();
                        }
                    }
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            $_SESSION["admin_subject_weights_csrf"] = bin2hex(random_bytes(32));
            $message = "The component was added to all active subjects and current class offerings. Its weight was taken from each subject's highest-weighted component.";
        } else {

        $subjectId = filter_var($_POST["subject_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$subjectId || $subjectId < 1) {
            throw new Exception("Choose a valid subject.");
        }

        $weights = $_POST["weights"] ?? [];
        $checkedWeights = [];
        $totalWeight = 0.0;
        foreach ($componentNames as $componentName) {
            $value = $weights[$componentName] ?? "";
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100 || abs((float) $value - round((float) $value)) > 0.001) {
                throw new Exception("Enter each component weight as a whole number between 0 and 100.");
            }
            $checkedWeights[$componentName] = (int) round((float) $value);
            $totalWeight += $checkedWeights[$componentName];
        }
        $subjectCheck = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE subject_id = ? AND is_archived = 0");
        $subjectCheck->bind_param("i", $subjectId);
        $subjectCheck->execute();
        $subjectExists = $subjectCheck->get_result()->fetch_assoc();
        $subjectCheck->close();
        if (!$subjectExists) {
            throw new Exception("That active subject could not be found.");
        }

            $globalCustomNamesRows = $conn->query("SELECT DISTINCT component_name FROM subject_grading_components WHERE is_archived = 0 ORDER BY component_name")->fetch_all(MYSQLI_ASSOC);
        $customWeightsInput = $_POST["custom_weights"] ?? [];
        if (!is_array($customWeightsInput)) throw new Exception("Enter valid weights for the added components.");
        $checkedCustomWeights = [];
        $customWeightTotal = 0.0;
        foreach ($globalCustomNamesRows as $customNameRow) {
            $customName = (string) $customNameRow["component_name"];
            $customInputKey = hash("sha256", strtolower($customName));
            $value = $customWeightsInput[$customInputKey] ?? "0";
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100 || abs((float) $value - round((float) $value)) > 0.001) throw new Exception("Enter each added component weight as a whole number between 0 and 100.");
            $checkedCustomWeights[$customName] = (int) round((float) $value);
            $customWeightTotal += $checkedCustomWeights[$customName];
        }
        $submittedSubjectId = (int) $subjectId;
        $submittedStandardWeights = $checkedWeights;
        $submittedCustomWeights = $checkedCustomWeights;
        if (abs($totalWeight + $customWeightTotal - 100) > 0.001) {
            $combinedWeights = $checkedWeights + $checkedCustomWeights;
            $combinedTotal = round($totalWeight + $customWeightTotal);
            $weightDifference = (int) round(abs($combinedTotal - 100));
            $deductions = [];
            if ($combinedTotal > 100) {
                arsort($combinedWeights, SORT_NUMERIC);
                $remaining = $weightDifference;
                foreach ($combinedWeights as $name => $weight) {
                    if ($remaining <= 0.001) break;
                    $deduct = min((float) $weight, $remaining);
                    if ($deduct > 0) {
                        $deductions[] = ["name" => (string) $name, "deduct" => (int) round($deduct), "result" => (int) round((float) $weight - $deduct)];
                        $remaining = round($remaining - $deduct);
                    }
                }
                $weightValidationDetails = [
                    "subject" => (string) $subjectExists["subject_name"],
                    "total" => $combinedTotal,
                    "difference" => $weightDifference,
                    "is_over" => true,
                    "suggestions" => $deductions
                ];
            } else {
                arsort($combinedWeights, SORT_NUMERIC);
                $suggestedComponent = (string) (array_key_first($combinedWeights) ?? "Written Works");
                $weightValidationDetails = [
                    "subject" => (string) $subjectExists["subject_name"],
                    "total" => $combinedTotal,
                    "difference" => $weightDifference,
                    "is_over" => false,
                    "suggested_component" => $suggestedComponent
                ];
            }
            throw new Exception("Standard and added component weights must add up to 100%.");
        }

        $conn->begin_transaction();
        try {
            $updateSubject = $conn->prepare(
                "UPDATE subjects
                 SET default_written_works_weight = ?,
                     default_performance_tasks_weight = ?,
                     default_term_assessment_weight = ?
                 WHERE subject_id = ?"
            );
            $defaultWeights = $checkedWeights;
            if ($customWeightTotal > 0) {
                $highestName = array_keys($defaultWeights, max($defaultWeights), true)[0];
                $defaultWeights[$highestName] += $customWeightTotal;
            }
            $wwDefault = $defaultWeights["Written Works"];
            $ptDefault = $defaultWeights["Performance Tasks"];
            $taDefault = $defaultWeights["Term Assessment"];
            $updateSubject->bind_param("dddi", $wwDefault, $ptDefault, $taDefault, $subjectId);
            $updateSubject->execute();
            $updateSubject->close();

            $offeringQuery = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE subject_id = ?");
            $offeringQuery->bind_param("i", $subjectId);
            $offeringQuery->execute();
            $offeringRows = $offeringQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $offeringQuery->close();

            foreach ($offeringRows as $offeringRow) {
                $classSubjectId = (int) $offeringRow["class_subject_id"];
            foreach ($componentNames as $componentName) {
                $weight = $checkedWeights[$componentName];
                $componentFind = $conn->prepare(
                    "SELECT component_id FROM grading_components
                     WHERE class_subject_id = ? AND TRIM(component_name) = ?
                     ORDER BY component_id LIMIT 1"
                );
                $componentFind->bind_param("is", $classSubjectId, $componentName);
                $componentFind->execute();
                $component = $componentFind->get_result()->fetch_assoc();
                $componentFind->close();

                if ($component) {
                    $componentId = (int) $component["component_id"];
                    $saveWeight = $conn->prepare(
                        "UPDATE grading_components SET weight_percentage = ? WHERE component_id = ?"
                    );
                    $saveWeight->bind_param("di", $weight, $componentId);
                } else {
                    $blankFind = $conn->prepare(
                        "SELECT component_id FROM grading_components
                         WHERE class_subject_id = ? AND TRIM(component_name) = ''
                         ORDER BY component_id LIMIT 1"
                    );
                    $blankFind->bind_param("i", $classSubjectId);
                    $blankFind->execute();
                    $blankComponent = $blankFind->get_result()->fetch_assoc();
                    $blankFind->close();
                    if ($blankComponent) {
                        $componentId = (int) $blankComponent["component_id"];
                        $saveWeight = $conn->prepare(
                            "UPDATE grading_components
                             SET component_name = ?, weight_percentage = ?
                             WHERE component_id = ?"
                        );
                        $saveWeight->bind_param("sdi", $componentName, $weight, $componentId);
                    } else {
                        $saveWeight = $conn->prepare(
                            "INSERT INTO grading_components (class_subject_id, component_name, weight_percentage)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                                component_name = VALUES(component_name),
                                weight_percentage = VALUES(weight_percentage)"
                        );
                        $saveWeight->bind_param("isd", $classSubjectId, $componentName, $weight);
                    }
                }
                $saveWeight->execute();
                $saveWeight->close();
            }
            }

            $templateFind = $conn->prepare("SELECT subject_component_id FROM subject_grading_components WHERE subject_id = ? AND component_name = ? LIMIT 1");
            $templateUpdate = $conn->prepare("UPDATE subject_grading_components SET weight_percentage = ? WHERE subject_component_id = ?");
            $templateInsert = $conn->prepare("INSERT INTO subject_grading_components (subject_id, component_name, weight_percentage) VALUES (?, ?, ?)");
            foreach ($checkedCustomWeights as $customName => $customWeight) {
                $templateFind->bind_param("is", $subjectId, $customName);
                $templateFind->execute();
                $customTemplate = $templateFind->get_result()->fetch_assoc();
                if ($customTemplate) {
                    $templateId = (int) $customTemplate["subject_component_id"];
                    $templateUpdate->bind_param("di", $customWeight, $templateId);
                    $templateUpdate->execute();
                } else {
                    $templateInsert->bind_param("isd", $subjectId, $customName, $customWeight);
                    $templateInsert->execute();
                }
                foreach ($offeringRows as $offeringRow) {
                    $classSubjectId = (int) $offeringRow["class_subject_id"];
                    $customFind = $conn->prepare("SELECT component_id FROM grading_components WHERE class_subject_id = ? AND component_name = ? LIMIT 1");
                    $customFind->bind_param("is", $classSubjectId, $customName);
                    $customFind->execute();
                    $classComponent = $customFind->get_result()->fetch_assoc();
                    $customFind->close();
                    if ($classComponent) {
                        $classComponentId = (int) $classComponent["component_id"];
                        $customUpdate = $conn->prepare("UPDATE grading_components SET weight_percentage = ? WHERE component_id = ?");
                        $customUpdate->bind_param("di", $customWeight, $classComponentId);
                        $customUpdate->execute();
                        $customUpdate->close();
                    } else {
                        $customInsert = $conn->prepare("INSERT INTO grading_components (class_subject_id, component_name, weight_percentage) VALUES (?, ?, ?)");
                        $customInsert->bind_param("isd", $classSubjectId, $customName, $customWeight);
                        $customInsert->execute();
                        $customInsert->close();
                    }
                }
            }
            $templateFind->close();
            $templateUpdate->close();
            $templateInsert->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $message = "Component weights saved for the subject and all its current class offerings.";
        }
    } catch (Throwable $e) {
        error_log("Admin subject weights page error: " . $e->getMessage());
        $error = $e instanceof mysqli_sql_exception
            ? "Could not save these component weights. Refresh the page and try again."
            : $e->getMessage();
    }
}

try {
    $tracks = $conn->query("SELECT track_id, track_name, strand_name FROM tracks ORDER BY track_name, strand_name")
        ->fetch_all(MYSQLI_ASSOC);
    $trackCondition = $trackFilter === "core"
        ? " AND subjects.track_id IS NULL"
        : ($trackFilter !== "all" ? " AND subjects.track_id = " . (int) $trackFilter : "");
    $gradeCondition = $gradeFilter !== "all" ? " AND subjects.grade_level = " . (int) $gradeFilter : "";
    $searchCondition = "";
    if ($subjectSearch !== "") {
        $escapedSearch = $conn->real_escape_string($subjectSearch);
        $searchCondition = " AND (subjects.subject_name LIKE '%" . $escapedSearch . "%'"
            . " OR tracks.track_name LIKE '%" . $escapedSearch . "%'"
            . " OR tracks.strand_name LIKE '%" . $escapedSearch . "%')";
    }
    $allSubjects = $conn->query(
        "SELECT subjects.subject_id, subjects.subject_name, subjects.grade_level,
                subjects.track_id, tracks.track_name, tracks.strand_name,
                subjects.default_written_works_weight, subjects.default_performance_tasks_weight,
                subjects.default_term_assessment_weight
         FROM subjects
         LEFT JOIN tracks ON tracks.track_id = subjects.track_id
         WHERE subjects.is_archived = 0" . $trackCondition . $gradeCondition . $searchCondition . "
         ORDER BY subjects.grade_level, tracks.track_name, tracks.strand_name, subjects.subject_name"
    )->fetch_all(MYSQLI_ASSOC);

    $customComponentQuery = $conn->query(
        "SELECT subject_id, component_name, weight_percentage, is_archived
         FROM subject_grading_components ORDER BY component_name"
    );
    foreach ($customComponentQuery->fetch_all(MYSQLI_ASSOC) as $customComponent) {
        $customComponentsBySubject[(int) $customComponent["subject_id"]][] = $customComponent;
        if ((int) $customComponent["is_archived"] === 0) {
            $globalCustomComponentNames[$customComponent["component_name"]] = true;
            $globalCustomComponentArchived[$customComponent["component_name"]] = false;
        }
    }
    $globalCustomComponentNames = array_keys($globalCustomComponentNames);
    natcasesort($globalCustomComponentNames);
    $globalCustomComponentNames = array_values($globalCustomComponentNames);
    $componentManagementList = $conn->query(
        "SELECT component_name, MAX(is_archived) AS is_archived
         FROM subject_grading_components
         GROUP BY component_name ORDER BY component_name"
    )->fetch_all(MYSQLI_ASSOC);
    $customWeightBySubject = [];
    foreach ($customComponentsBySubject as $subjectId => $customComponents) {
        $activeCustomComponents = array_filter($customComponents, static fn($component) => (int) $component["is_archived"] === 0);
        $customWeightBySubject[$subjectId] = array_sum(array_map(static fn($component) => (float) $component["weight_percentage"], $activeCustomComponents));
    }

    $offeringWeights = $conn->query(
        "SELECT class_subjects.subject_id, class_subjects.class_subject_id,
                grading_components.component_name,
                grading_components.weight_percentage
         FROM class_subjects
         JOIN grading_components ON grading_components.class_subject_id = class_subjects.class_subject_id
         ORDER BY class_subjects.class_subject_id DESC, grading_components.component_id"
    )->fetch_all(MYSQLI_ASSOC);
    $offeringWeightSets = [];
    foreach ($offeringWeights as $weightRow) {
        $componentName = strtolower(trim((string) $weightRow["component_name"]));
        $subjectId = (int) $weightRow["subject_id"];
        $classSubjectId = (int) $weightRow["class_subject_id"];
        foreach ($componentNames as $canonicalComponentName) {
            if ($componentName === strtolower($canonicalComponentName) && !isset($offeringWeightSets[$subjectId][$classSubjectId][$canonicalComponentName])) {
                $offeringWeightSets[$subjectId][$classSubjectId][$canonicalComponentName] = $weightRow["weight_percentage"];
                break;
            }
        }
    }
    foreach ($offeringWeightSets as $subjectId => $weightSets) {
        foreach ($weightSets as $weightSet) {
            if (count($weightSet) === count($componentNames)) {
                $weightsBySubject[(int) $subjectId] = $weightSet;
                break;
            }
        }
    }
    foreach ($allSubjects as $subject) {
        $subjectId = (int) $subject["subject_id"];
        $defaultValues = [
            "Written Works" => $subject["default_written_works_weight"],
            "Performance Tasks" => $subject["default_performance_tasks_weight"],
            "Term Assessment" => $subject["default_term_assessment_weight"]
        ];
        if (!isset($weightsBySubject[$subjectId]) && array_filter($defaultValues, static fn($weight) => $weight !== null)) {
            $defaultWeights = array_map(static fn($weight) => (float) ($weight ?? 0), $defaultValues);
            $activeCustomWeight = (float) ($customWeightBySubject[$subjectId] ?? 0);
            if ($activeCustomWeight > 0) {
                $highestName = array_keys($defaultWeights, max($defaultWeights), true)[0];
                $defaultWeights[$highestName] = max(0, $defaultWeights[$highestName] - $activeCustomWeight);
            }
            foreach ($componentNames as $componentName) {
                if ($defaultValues[$componentName] !== null) $weightsBySubject[$subjectId][$componentName] = $defaultWeights[$componentName];
            }
        }
    }

} catch (mysqli_sql_exception $e) {
    error_log("Admin subject weights load error: " . $e->getMessage());
    $error = $error !== "" ? $error : "We could not load class component weights right now.";
}

$adminName = $_SESSION["name"] ?? "Administrator";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Component Weights | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="subject_weights.css?v=<?= (int) filemtime(__DIR__ . "/subject_weights.css") ?>">
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
    <main class="dashboard subject-weights-page">
        <div class="page-heading"><p class="eyebrow">ADMIN PORTAL</p><h1>Component Weights</h1></div>
        <a class="weights-back-link" href="../admin-subjects" aria-label="Back to subjects" title="Back to subjects">
            <span class="weights-back-icon" aria-hidden="true">➤</span>
            <span>Back to subjects</span>
        </a>
        <?php 
if ($error !== "" && $weightValidationDetails === null): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php 
endif; ?>
        <?php 
if ($message !== ""): ?><p class="alert success" role="status"><?= h($message) ?></p><?php 
endif; ?>
        <form action="../admin-subject_weights" class="subject-weights-filter" method="get" aria-label="Filter subject weights">
            <label for="subject-track-filter">Track / strand
                <select id="subject-track-filter" name="track_id">
                    <option value="all" <?= $trackFilter === "all" ? "selected" : "" ?>>All tracks</option>
                    <option value="core" <?= $trackFilter === "core" ? "selected" : "" ?>>Core subjects</option>
                    <?php 
foreach ($tracks as $track): ?>
                        <option value="<?= (int) $track["track_id"] ?>" <?= $trackFilter === (string) $track["track_id"] ? "selected" : "" ?>><?= h($track["track_name"] . " / " . $track["strand_name"]) ?></option>
                    <?php 
endforeach; ?>
                </select>
            </label>
            <label for="subject-grade-filter">Grade level
                <select id="subject-grade-filter" name="grade_level">
                    <option value="all" <?= $gradeFilter === "all" ? "selected" : "" ?>>All grades</option>
                    <option value="11" <?= $gradeFilter === "11" ? "selected" : "" ?>>Grade 11</option>
                    <option value="12" <?= $gradeFilter === "12" ? "selected" : "" ?>>Grade 12</option>
                </select>
            </label>
            <label class="subject-weights-search" for="subject-search">Search subjects
                <input type="search" id="subject-search" name="search" value="<?= h($subjectSearch) ?>" placeholder="Search by subject or track">
            </label>
            <button class="small-button subject-weights-search-button" type="submit">Search</button>
            <?php 
if ($trackFilter !== "all" || $gradeFilter !== "all" || $subjectSearch !== ""): ?><a class="subject-weights-clear" href="../admin-subject_weights">Clear filters</a><?php 
endif; ?>
        </form>
        <section class="panel subject-weights-table-panel">
            <div class="panel-heading"><div><h2>Subject weights</h2><p>All active subjects are listed, including subjects not yet offered to a section.</p></div><div class="subject-weights-actions"><button class="small-button" type="button" id="save-all-weights">Save weights</button><button class="small-button" type="button" id="manage-components-trigger">Manage components</button></div></div>
            <p class="alert error" id="bulk-save-status" role="alert" hidden></p>
            <?php 
if (!$allSubjects): ?>
                <p class="empty-state">No subjects match these filters.</p>
            <?php 
else: ?>
                <div class="table-wrap">
                    <table class="subject-weights-table">
                        <thead><tr><th>Subject</th><th>Track / strand</th><th>Grade</th><th>Written Works (%)</th><th>Performance Tasks (%)</th><th>Term Assessment (%)</th><?php foreach ($globalCustomComponentNames as $customComponentName): ?><th><?= h($customComponentName) ?> (%)</th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php 
foreach ($allSubjects as $subject): ?>
                                <?php
                                
$subjectId = (int) $subject["subject_id"];
                                $formId = "weights-form-" . $subjectId;
                                ?>
                                <tr>
                                    <td><?= h($subject["subject_name"]) ?>
                                        <form action="../admin-subject_weights" id="<?= h($formId) ?>" method="post" class="subject-weight-row-form" hidden>
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subject_weights_csrf"]) ?>">
                                            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                            <input type="hidden" name="track_id" value="<?= h($trackFilter) ?>">
                                        </form>
                                    </td>
                                    <td><?= $subject["track_id"] === null ? "Core / All tracks" : h($subject["track_name"] . " / " . $subject["strand_name"]) ?></td>
                                    <td><?= h($subject["grade_level"]) ?></td>
                                    <?php 
foreach ($componentNames as $componentName): ?>
                                        <?php $displayWeight = $submittedSubjectId === $subjectId && isset($submittedStandardWeights[$componentName]) ? $submittedStandardWeights[$componentName] : ($weightsBySubject[$subjectId][$componentName] ?? ""); ?>
                                        <td><input class="weight-cell-input" type="number" name="weights[<?= h($componentName) ?>]" min="0" max="100" step="1" value="<?= h(number_format((float) $displayWeight, 0, ".", "")) ?>" placeholder="Not set" form="<?= h($formId) ?>" aria-label="<?= h($componentName . " weight for " . $subject["subject_name"]) ?>" required></td>
                                    <?php 
endforeach; ?>
                                    <?php foreach ($globalCustomComponentNames as $customComponentName): ?>
                                        <?php $customWeight = null; foreach ($customComponentsBySubject[$subjectId] ?? [] as $customComponent) { if (strcasecmp((string) $customComponent["component_name"], $customComponentName) === 0) { $customWeight = $customComponent["weight_percentage"]; break; } } if ($submittedSubjectId === $subjectId && array_key_exists($customComponentName, $submittedCustomWeights)) $customWeight = $submittedCustomWeights[$customComponentName]; ?>
                                        <?php $customInputKey = hash("sha256", strtolower($customComponentName)); ?>
                                        <td><input class="weight-cell-input" type="number" name="custom_weights[<?= h($customInputKey) ?>]" min="0" max="100" step="1" value="<?= h(number_format((float) ($customWeight ?? 0), 0, ".", "")) ?>" form="<?= h($formId) ?>" aria-label="<?= h($customComponentName . " weight for " . $subject["subject_name"]) ?>" required></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php 
endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php 
endif; ?>
        </section>
    </main>
    <?php if ($weightValidationDetails !== null): ?>
        <?php
        $defaultAdjustmentChoice = $weightValidationDetails["is_over"]
            ? (string) ($weightValidationDetails["suggestions"][0]["name"] ?? "Written Works")
            : (string) ($weightValidationDetails["suggested_component"] ?? "Written Works");
        ?>
        <div class="dashboard-modal-overlay" id="weight-validation-modal" data-subject-id="<?= (int) $submittedSubjectId ?>" data-difference="<?= h(number_format((float) $weightValidationDetails["difference"], 0, ".", "")) ?>" data-mode="<?= $weightValidationDetails["is_over"] ? "deduct" : "add" ?>" role="dialog" aria-modal="true" aria-labelledby="weight-validation-title">
            <section class="dashboard-form-modal weight-validation-dialog">
                <header class="dashboard-form-modal-heading"><div><h2 id="weight-validation-title">Weights must total 100%</h2><p><?= h($weightValidationDetails["subject"]) ?> currently totals <?= h(number_format((float) $weightValidationDetails["total"], 0)) ?>%.</p></div><button class="dashboard-modal-close" type="button" aria-label="Close">×</button></header>
                <div class="weight-validation-content">
                    <?php if ($weightValidationDetails["is_over"]): ?>
                        <p class="weight-validation-message">Choose which component should give up the extra <?= h(number_format((float) $weightValidationDetails["difference"], 0)) ?> points. If that component does not have enough weight, the remainder will come from the next-highest components.</p>
                    <?php else: ?>
                        <p class="weight-validation-message">Choose which component should receive the missing <?= h(number_format((float) $weightValidationDetails["difference"], 0)) ?> points.</p>
                    <?php endif; ?>
                    <label class="weight-adjustment-select-label" for="weight-adjustment-select"><?= $weightValidationDetails["is_over"] ? "Deduct from" : "Add to" ?>
                        <select id="weight-adjustment-select">
                            <?php foreach ($submittedStandardWeights as $componentName => $weight): ?>
                                <?php if ((float) $weight > 0 || !$weightValidationDetails["is_over"]): ?>
                                    <?php $fieldName = "weights[" . $componentName . "]"; ?>
                                    <option value="<?= h($fieldName) ?>" <?= $defaultAdjustmentChoice === $componentName ? "selected" : "" ?>><?= h($componentName) ?> (<?= h(number_format((float) $weight, 0)) ?>%)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($submittedCustomWeights as $componentName => $weight): ?>
                                <?php if ((float) $weight > 0 || !$weightValidationDetails["is_over"]): ?>
                                    <?php $fieldName = "custom_weights[" . hash("sha256", strtolower($componentName)) . "]"; ?>
                                    <option value="<?= h($fieldName) ?>" <?= $defaultAdjustmentChoice === $componentName ? "selected" : "" ?>><?= h($componentName) ?> (<?= h(number_format((float) $weight, 0)) ?>%)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="manage-components-note" id="weight-adjustment-status">Your entered values are still in the table. You can choose a different component before applying the adjustment.</p>
                    <footer><button class="small-button" type="button" id="weight-adjustment-apply"><?= $weightValidationDetails["is_over"] ? "Apply deduction" : "Apply points" ?></button><button class="small-button secondary" type="button" id="weight-validation-close">Back to weights</button></footer>
                </div>
            </section>
        </div>
    <?php endif; ?>
    <div class="dashboard-modal-overlay" id="manage-components-modal" role="dialog" aria-modal="true" aria-labelledby="manage-components-title" hidden>
        <section class="dashboard-form-modal manage-components-dialog">
            <header class="dashboard-form-modal-heading"><div><h2 id="manage-components-title">Manage graded components</h2><p>Add components across active subjects, or archive and delete components that are no longer needed.</p></div><button class="dashboard-modal-close" type="button" aria-label="Close">×</button></header>
            <div class="component-tabs" role="tablist" aria-label="Manage components">
                <button type="button" role="tab" id="component-tab-add" aria-controls="component-panel-add" aria-selected="true" data-component-tab="add">Add</button>
                <button type="button" role="tab" id="component-tab-existing" aria-controls="component-panel-existing" aria-selected="false" data-component-tab="existing">Existing</button>
                <button type="button" role="tab" id="component-tab-archive" aria-controls="component-panel-archive" aria-selected="false" data-component-tab="archive">Archives</button>
            </div>
            <section class="component-tab-panel" id="component-panel-add" role="tabpanel" aria-labelledby="component-tab-add" data-component-panel="add">
                <form action="../admin-subject_weights" class="manage-components-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subject_weights_csrf"]) ?>">
                    <input type="hidden" name="action" value="add_component">
                    <input type="hidden" name="track_id" value="<?= h($trackFilter) ?>">
                    <input type="hidden" name="grade_level" value="<?= h($gradeFilter) ?>">
                    <input type="hidden" name="search" value="<?= h($subjectSearch) ?>">
                    <label>Component name<input type="text" name="component_name" maxlength="100" required placeholder="e.g. Attendance"></label>
                    <label>Weight (%)<input type="number" name="component_weight" min="1" max="100" step="1" required placeholder="e.g. 10"></label>
                    <p class="manage-components-note">The weight is taken from the highest-weighted component for each subject and added to current and future class offerings.</p>
                    <footer><button class="small-button" type="submit">Add component</button></footer>
                </form>
            </section>
            <section class="component-tab-panel" id="component-panel-existing" role="tabpanel" aria-labelledby="component-tab-existing" data-component-panel="existing" hidden>
                <div class="managed-component-list">
                    <?php $activeManagedComponents = array_values(array_filter($componentManagementList, static fn($item) => (int) $item["is_archived"] === 0)); ?>
                    <?php if (!$activeManagedComponents): ?><p class="manage-components-note">No active added components.</p><?php else: foreach ($activeManagedComponents as $managedComponent): ?>
                        <form action="../admin-subject_weights" method="post" class="managed-component-row">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subject_weights_csrf"]) ?>">
                            <input type="hidden" name="action" value="archive_component">
                            <input type="hidden" name="component_name" value="<?= h($managedComponent["component_name"]) ?>">
                            <span><strong><?= h($managedComponent["component_name"]) ?></strong><small>Active · weights are set per subject</small></span>
                            <button class="component-archive-button" type="submit">Archive</button>
                        </form>
                    <?php endforeach; endif; ?>
                </div>
            </section>
            <section class="component-tab-panel" id="component-panel-archive" role="tabpanel" aria-labelledby="component-tab-archive" data-component-panel="archive" hidden>
                <div class="managed-component-list">
                    <?php $archivedManagedComponents = array_values(array_filter($componentManagementList, static fn($item) => (int) $item["is_archived"] === 1)); ?>
                    <?php if (!$archivedManagedComponents): ?><p class="manage-components-note">No archived components.</p><?php else: foreach ($archivedManagedComponents as $managedComponent): ?>
                        <form action="../admin-subject_weights" method="post" class="managed-component-row">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_subject_weights_csrf"]) ?>">
                            <input type="hidden" name="component_name" value="<?= h($managedComponent["component_name"]) ?>">
                            <span><strong><?= h($managedComponent["component_name"]) ?></strong><small>Archived · historical grades retained</small></span>
                            <div class="component-archive-actions">
                                <button class="component-recover-button" type="submit" name="action" value="recover_component">Recover</button>
                                <button class="component-delete-button" type="submit" name="action" value="delete_component" data-confirm-delete="Permanently delete this component? It cannot be deleted if assessments use it.">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; endif; ?>
                </div>
            </section>
        </section>
    </div>
</body>
<script>
    document.querySelectorAll("#subject-track-filter, #subject-grade-filter").forEach(function (filter) {
        filter.addEventListener("change", function () {
            filter.form.requestSubmit();
        });
    });
    const saveAllWeightsButton = document.getElementById("save-all-weights");
    const bulkSaveStatus = document.getElementById("bulk-save-status");
    if (saveAllWeightsButton) {
        saveAllWeightsButton.addEventListener("click", async function () {
            const rowForms = Array.from(document.querySelectorAll(".subject-weight-row-form"));
            if (!rowForms.length) return;
            const getFields = function (form) {
                return Array.from(document.querySelectorAll(".weight-cell-input")).filter(function (input) { return input.form === form; });
            };
            for (const form of rowForms) {
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                const total = getFields(form).reduce(function (sum, input) { return sum + Number(input.value); }, 0);
                if (Math.abs(total - 100) > 0.001) {
                    form.requestSubmit();
                    return;
                }
            }
            saveAllWeightsButton.disabled = true;
            if (bulkSaveStatus) {
                bulkSaveStatus.hidden = false;
                bulkSaveStatus.classList.remove("error");
                bulkSaveStatus.classList.add("success");
            }
            try {
                for (let index = 0; index < rowForms.length; index++) {
                    if (bulkSaveStatus) bulkSaveStatus.textContent = "Saving subject weights (" + (index + 1) + " of " + rowForms.length + ")…";
                    const form = rowForms[index];
                    const response = await fetch(form.action, {
                        method: "POST",
                        body: new FormData(form),
                        credentials: "same-origin"
                    });
                    if (!response.ok) throw new Error("Could not save all subject weights. Please try again.");
                    const responseDocument = new DOMParser().parseFromString(await response.text(), "text/html");
                    const responseError = responseDocument.querySelector(".alert.error");
                    if (responseError) throw new Error(responseError.textContent.trim());
                }
                window.location.reload();
            } catch (error) {
                if (bulkSaveStatus) {
                    bulkSaveStatus.classList.remove("success");
                    bulkSaveStatus.classList.add("error");
                    bulkSaveStatus.textContent = error.message || "Could not save all subject weights. Please try again.";
                }
                saveAllWeightsButton.disabled = false;
            }
        });
    }
    const manageTrigger = document.getElementById("manage-components-trigger");
    const manageModal = document.getElementById("manage-components-modal");
    const weightValidationModal = document.getElementById("weight-validation-modal");
    if (weightValidationModal) {
        document.body.classList.add("dashboard-modal-open");
        const subjectFormId = "weights-form-" + weightValidationModal.dataset.subjectId;
        const componentSelector = document.getElementById("weight-adjustment-select");
        const applyAdjustmentButton = document.getElementById("weight-adjustment-apply");
        const adjustmentStatus = document.getElementById("weight-adjustment-status");
        const closeValidationModal = function () {
            weightValidationModal.hidden = true;
            document.body.classList.remove("dashboard-modal-open");
            Array.from(document.querySelectorAll(".weight-cell-input")).find(function (input) { return input.form && input.form.id === subjectFormId; })?.focus();
        };
        applyAdjustmentButton?.addEventListener("click", function () {
            const fields = Array.from(document.querySelectorAll(".weight-cell-input")).filter(function (input) { return input.form && input.form.id === subjectFormId; });
            const selectedField = componentSelector?.value;
            const selectedInput = fields.find(function (input) { return input.name === selectedField; });
            if (!selectedInput || fields.length === 0) return;
            let remaining = Math.round(Number(weightValidationModal.dataset.difference) * 100);
            if (weightValidationModal.dataset.mode === "add") {
                selectedInput.value = (Math.round((Number(selectedInput.value) || 0) * 100 + remaining) / 100).toFixed(2);
                remaining = 0;
            } else {
                const selectedCents = Math.round((Number(selectedInput.value) || 0) * 100);
                const selectedDeduction = Math.min(selectedCents, remaining);
                selectedInput.value = ((selectedCents - selectedDeduction) / 100).toFixed(2);
                remaining -= selectedDeduction;
                fields.filter(function (input) { return input !== selectedInput; }).sort(function (a, b) {
                    return Number(b.value) - Number(a.value);
                }).forEach(function (input) {
                    if (remaining <= 0) return;
                    const currentCents = Math.round((Number(input.value) || 0) * 100);
                    const deduction = Math.min(currentCents, remaining);
                    input.value = ((currentCents - deduction) / 100).toFixed(2);
                    remaining -= deduction;
                });
            }
            if (remaining > 0) {
                if (adjustmentStatus) adjustmentStatus.textContent = "The selected weights could not cover the full adjustment. Review the entered values and choose another component.";
                return;
            }
            fields.forEach(function (input) { input.dispatchEvent(new Event("input", { bubbles: true })); });
            const mode = weightValidationModal.dataset.mode;
            if (adjustmentStatus) adjustmentStatus.textContent = mode === "deduct"
                ? "The excess was deducted, starting with your selected component. The table now totals 100%; review the values and click Save."
                : "The missing points were added to your selected component. The table now totals 100%; review the values and click Save.";
            if (componentSelector) componentSelector.disabled = true;
            if (applyAdjustmentButton) applyAdjustmentButton.hidden = true;
            const headingMessage = weightValidationModal.querySelector(".dashboard-form-modal-heading p");
            if (headingMessage) headingMessage.textContent = "The suggested adjustment has been applied to the table.";
        });
        weightValidationModal.querySelectorAll(".dashboard-modal-close, #weight-validation-close").forEach(function (button) { button.addEventListener("click", closeValidationModal); });
        weightValidationModal.addEventListener("click", function (event) { if (event.target === weightValidationModal) closeValidationModal(); });
        document.addEventListener("keydown", function (event) { if (event.key === "Escape") closeValidationModal(); });
    }
    if (manageTrigger && manageModal) {
        const closeModal = function () {
            manageModal.hidden = true;
            document.body.classList.remove("dashboard-modal-open");
            manageTrigger.focus();
        };
        manageTrigger.addEventListener("click", function () {
            manageModal.hidden = false;
            document.body.classList.add("dashboard-modal-open");
            manageModal.querySelector("[data-component-panel='add'] input")?.focus();
        });
        manageModal.querySelectorAll(".dashboard-modal-close").forEach(function (button) { button.addEventListener("click", closeModal); });
        manageModal.addEventListener("click", function (event) { if (event.target === manageModal) closeModal(); });
        manageModal.querySelectorAll("button[data-confirm-delete]").forEach(function (button) {
            button.addEventListener("click", function (event) {
                if (!window.confirm(button.dataset.confirmDelete)) event.preventDefault();
            });
        });
        manageModal.querySelectorAll("[data-component-tab]").forEach(function (tab) {
            tab.addEventListener("click", function () {
                const selected = tab.dataset.componentTab;
                manageModal.querySelectorAll("[data-component-tab]").forEach(function (item) {
                    const active = item === tab;
                    item.setAttribute("aria-selected", active ? "true" : "false");
                    item.tabIndex = active ? 0 : -1;
                });
                manageModal.querySelectorAll("[data-component-panel]").forEach(function (panel) {
                    panel.hidden = panel.dataset.componentPanel !== selected;
                });
            });
        });
    }
</script>
</html>
