<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/grading_schema.php";
require_once __DIR__ . "/../includes/messaging_schema.php";
ensureGradingSchema($conn);

if (!isset($_SESSION["teacher_grades_csrf"])) {
    $_SESSION["teacher_grades_csrf"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["user_id"];
$teacherUnreadMessageCount = 0;
try {
    ensureStudentTeacherMessagesSchema($conn);
    $teacherUnreadMessageCount = getUnreadTeacherMessageCount($conn, $userId);
} catch (mysqli_sql_exception $messageSchemaError) {
    error_log("Teacher message badge unavailable: " . $messageSchemaError->getMessage());
}
$classes = [];
$components = [];
$creatableComponents = [];
$assessments = [];
$typeAssessments = [];
$students = [];
$scoresByAssessment = [];
$sampleScoresByAssessment = [];
$termScoresByAssessment = [];
$possibleGradesByStudent = [];
$componentPercentagesByStudent = [];
$componentContributionsByStudent = [];
$selectedClassId = (int) ($_GET["class_subject_id"] ?? $_POST["class_subject_id"] ?? 0);
$selectedGradingPeriod = 1;
$assessmentTypeInput = $_GET["assessment_type"] ?? $_POST["assessment_type"] ?? "all";
$selectedAssessmentType = is_string($assessmentTypeInput) && trim($assessmentTypeInput) !== ""
    ? trim($assessmentTypeInput)
    : "all";
$componentInput = $_GET["component_id"] ?? $_POST["component_id"] ?? "0";
$selectedComponentId = is_string($componentInput) && ctype_digit($componentInput)
    ? (int) $componentInput
    : 0;
$teacherId = 0;
$error = "";
$message = "";
$showCreateAssessmentModal = false;
$showManageAssessmentsModal = false;
$latestGradeRelease = null;
$gradesAlreadyReleased = false;
$releaseReady = false;
$releaseReadinessMessage = "";
$studentsBelowPassing = [];
$assessmentTypes = ["Quiz", "Activity", "Assignment", "Project", "Exam", "Other"];
function gradeReportColumnName(int $columnNumber): string
{
    $name = "";
    while ($columnNumber > 0) {
        $remainder = ($columnNumber - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $columnNumber = intdiv($columnNumber - 1, 26);
    }
    return $name;
}

function gradeComponentAbbreviation(string $componentName): string
{
    $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($componentName)));
    return match ($normalized) {
        "writtenwork", "writtenworks", "ww" => "WW",
        "performancetask", "performancetasks", "pt" => "PT",
        "termassessment", "termassessments", "quarterlyassessment", "quarterlyassessments", "ta" => "TA",
        default => strtoupper(substr($normalized, 0, 3))
    };
}

function buildGradeReportXlsx(array $rows): string
{
    $lastColumn = gradeReportColumnName(max(1, count($rows[0] ?? [])));
    $lastRow = count($rows);
    $sheetRows = [];
    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $cellValue) {
            if ($cellValue === null || $cellValue === "") {
                continue;
            }
            $cellReference = gradeReportColumnName($columnIndex + 1) . ($rowIndex + 1);
            $style = $rowIndex === 0 ? ' s="1"' : "";
            if (is_array($cellValue) && array_key_exists("number", $cellValue)) {
                $number = is_numeric($cellValue["number"]) ? (string) (0 + $cellValue["number"]) : "0";
                $cells[] = '<c r="' . $cellReference . '"' . $style . '><v>' . $number . '</v></c>';
                continue;
            }
            $text = (string) $cellValue;
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', "", $text) ?? "";
            $cells[] = '<c r="' . $cellReference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, "UTF-8") . '</t></is></c>';
        }
        $rowStyle = $rowIndex === 0 ? ' ht="30" customHeight="1"' : "";
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '"' . $rowStyle . '>' . implode("", $cells) . '</row>';
    }

    $filter = $lastRow > 1 ? '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>' : "";
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/><cols><col min="1" max="' . max(1, count($rows[0] ?? [])) . '" width="24" customWidth="1"/></cols>'
        . '<sheetData>' . implode("", $sheetRows) . '</sheetData>' . $filter . '</worksheet>';

    $files = [
        "[Content_Types].xml" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        "_rels/.rels" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        "xl/workbook.xml" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Student Records" sheetId="1" r:id="rId1"/></sheets></workbook>',
        "xl/_rels/workbook.xml.rels" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        "xl/styles.xml" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17683E"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
    ];
    $files["xl/worksheets/sheet1.xml"] = $worksheet;

    $localData = "";
    $centralDirectory = "";
    $offset = 0;
    foreach ($files as $fileName => $contents) {
        $compressed = gzdeflate($contents, 6);
        if ($compressed === false) {
            throw new RuntimeException("Could not prepare the Excel report.");
        }
        $crc = crc32($contents);
        $nameLength = strlen($fileName);
        $localHeader = pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), $nameLength, 0);
        $localEntry = $localHeader . $fileName . $compressed;
        $centralDirectory .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), $nameLength, 0, 0, 0, 0, 0, $offset) . $fileName;
        $localData .= $localEntry;
        $offset += strlen($localEntry);
    }
    $entryCount = count($files);
    $endRecord = pack("VvvvvVVv", 0x06054b50, 0, 0, $entryCount, $entryCount, strlen($centralDirectory), strlen($localData), 0);
    return $localData . $centralDirectory . $endRecord;
}

try {
    $teacherQuery = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $teacherQuery->bind_param("i", $userId);
    $teacherQuery->execute();
    $teacherRow = $teacherQuery->get_result()->fetch_assoc();
    $teacherQuery->close();

    if (!$teacherRow) {
        throw new Exception("Your teacher profile is not linked yet. Please contact the administrator.");
    }
    $teacherId = (int) $teacherRow["teacher_id"];

    $classQuery = $conn->prepare(
        "SELECT class_subjects.class_subject_id, class_subjects.section_id,
                class_subjects.school_year, sections.section_name,
                sections.grade_level, subjects.subject_name, subjects.semester AS subject_term
         FROM class_subjects
         JOIN sections ON sections.section_id = class_subjects.section_id
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         WHERE class_subjects.teacher_id = ?
         ORDER BY class_subjects.school_year DESC,
                  sections.grade_level, sections.section_name, subjects.subject_name"
    );
    $classQuery->bind_param("i", $teacherId);
    $classQuery->execute();
    $classes = $classQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $classQuery->close();


    $classIds = array_map("intval", array_column($classes, "class_subject_id"));
    if ($selectedClassId && !in_array($selectedClassId, $classIds, true)) {
        $selectedClassId = 0;
        $selectedAssessmentType = "all";
        $error = "Choose one of your assigned classes.";
    }
    $selectedClass = null;
    foreach ($classes as $class) {
        if ((int) $class["class_subject_id"] === $selectedClassId) {
            $selectedClass = $class;
            $selectedGradingPeriod = (int) $class["subject_term"];
            break;
        }
    }

    if (isset($_GET["download_roster"])) {
        if (!$selectedClassId || !in_array($selectedClassId, $classIds, true) || !$selectedClass) {
            http_response_code(403);
            exit("Choose one of your assigned classes before downloading its student list.");
        }

        $rosterQuery = $conn->prepare(
            "SELECT users.lrn, users.first_name, users.middle_name, users.last_name,
                    sections.grade_level, sections.section_name,
                    tracks.track_name, tracks.strand_name
             FROM class_subjects
             JOIN sections ON sections.section_id = class_subjects.section_id
             JOIN enrollments ON enrollments.section_id = class_subjects.section_id
                AND enrollments.school_year = class_subjects.school_year
                AND enrollments.status = 'Enrolled'
             JOIN students ON students.student_id = enrollments.student_id
             JOIN users ON users.user_id = students.user_id
                AND users.approval_status = 'approved'
             LEFT JOIN tracks ON tracks.track_id = students.track_id
             WHERE class_subjects.class_subject_id = ?
               AND class_subjects.teacher_id = ?
             ORDER BY users.last_name, users.first_name, users.middle_name"
        );
        $rosterQuery->bind_param("ii", $selectedClassId, $teacherId);
        $rosterQuery->execute();
        $rosterStudents = $rosterQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $rosterQuery->close();

        $rosterRows = [["LRN", "First name", "Middle name", "Last name", "Grade", "Section", "Track / Strand"]];
        foreach ($rosterStudents as $rosterStudent) {
            $trackParts = array_filter([
                trim((string) ($rosterStudent["track_name"] ?? "")),
                trim((string) ($rosterStudent["strand_name"] ?? ""))
            ], static fn($part) => $part !== "");
            $rosterRows[] = [
                (string) ($rosterStudent["lrn"] ?? ""),
                (string) ($rosterStudent["first_name"] ?? ""),
                (string) ($rosterStudent["middle_name"] ?? ""),
                (string) ($rosterStudent["last_name"] ?? ""),
                (string) $rosterStudent["grade_level"],
                (string) $rosterStudent["section_name"],
                implode(" / ", $trackParts)
            ];
        }

        $fileBase = strtolower((string) $selectedClass["section_name"]);
        $fileBase = trim((string) preg_replace('/[^a-z0-9]+/i', "-", $fileBase), "-");
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"student-list-" . ($fileBase ?: "section") . ".csv\"");
        header("X-Content-Type-Options: nosniff");
        $csvOutput = fopen("php://output", "wb");
        fwrite($csvOutput, "\xEF\xBB\xBF");
        fputcsv($csvOutput, $rosterRows[0], ",", '"', "");
        foreach (array_slice($rosterRows, 1) as $rosterRow) {
            foreach ($rosterRow as &$value) {
                $value = (string) $value;
                if (preg_match('/^\s*[=+\-@]/', $value)) {
                    $value = "'" . $value;
                }
            }
            unset($value);
            fputcsv($csvOutput, $rosterRow, ",", '"', "");
        }
        fclose($csvOutput);
        exit;
    }

    if (isset($_GET["download_report"])) {
        if (!$selectedClassId || !in_array($selectedClassId, $classIds, true)) {
            http_response_code(403);
            exit("Choose one of your assigned classes before downloading its grade report.");
        }

        $exportQuery = $conn->prepare(
            "SELECT students.student_id, users.lrn, users.first_name, users.middle_name,
                    users.last_name, users.suffix
             FROM class_subjects
             JOIN enrollments
                ON enrollments.section_id = class_subjects.section_id
                AND enrollments.school_year = class_subjects.school_year
                AND enrollments.status = 'Enrolled'
             JOIN students ON students.student_id = enrollments.student_id
             JOIN users ON users.user_id = students.user_id
                AND users.approval_status = 'approved'
             WHERE class_subjects.class_subject_id = ?
               AND class_subjects.teacher_id = ?
             ORDER BY users.last_name, users.first_name, users.middle_name"
        );
        $exportQuery->bind_param("ii", $selectedClassId, $teacherId);
        $exportQuery->execute();
        $exportStudents = $exportQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $exportQuery->close();

        $exportAssessmentQuery = $conn->prepare(
            "SELECT assessments.assessment_id, assessments.component_id, assessments.assessment_name,
                    assessments.assessment_type, assessments.max_score,
                    assessments.assessment_date, grading_components.component_name,
                    grading_components.weight_percentage
             FROM assessments
             JOIN grading_components ON grading_components.component_id = assessments.component_id
             WHERE assessments.class_subject_id = ? AND assessments.grading_period = ?
               AND assessments.activity_status = 'Active'
             ORDER BY grading_components.component_id, assessments.assessment_date,
                      assessments.assessment_id"
        );
        $exportAssessmentQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
        $exportAssessmentQuery->execute();
        $exportAssessments = $exportAssessmentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $exportAssessmentQuery->close();

        $exportScoreQuery = $conn->prepare(
            "SELECT student_scores.student_id, student_scores.assessment_id, student_scores.score
             FROM student_scores
             JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
             WHERE assessments.class_subject_id = ? AND assessments.grading_period = ?"
        );
        $exportScoreQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
        $exportScoreQuery->execute();
        $exportScoreRows = $exportScoreQuery->get_result();
        $exportScores = [];
        while ($exportScore = $exportScoreRows->fetch_assoc()) {
            $exportScores[(int) $exportScore["student_id"]][(int) $exportScore["assessment_id"]] = $exportScore["score"];
        }
        $exportScoreQuery->close();

        $reportRows = [[
            "LRN", "Student name", "Grade level", "Section", "School year",
            "Subject", "Term " . $selectedGradingPeriod
        ]];
        foreach ($exportAssessments as $assessment) {
            $reportRows[0][] = $assessment["assessment_name"] . " — " . $assessment["assessment_type"]
                . " — " . $assessment["component_name"] . " (Max " . $assessment["max_score"] . ")"
                . " — " . $assessment["assessment_date"];
        }
        $reportRows[0][] = "Grade (%)";
        $exportComponentWeights = [];
        foreach ($exportAssessments as $assessment) {
            $exportComponentWeights[(int) $assessment["component_id"]] = (float) $assessment["weight_percentage"];
        }
        foreach ($exportStudents as $exportStudent) {
            $nameParts = [
                trim((string) ($exportStudent["first_name"] ?? "")),
                trim((string) ($exportStudent["middle_name"] ?? "")),
                trim((string) ($exportStudent["last_name"] ?? "")),
                trim((string) ($exportStudent["suffix"] ?? ""))
            ];
            $studentName = implode(" ", array_filter($nameParts, static fn($part) => $part !== ""));
            $reportRow = [
                (string) ($exportStudent["lrn"] ?? ""),
                $studentName,
                (string) $selectedClass["grade_level"],
                (string) $selectedClass["section_name"],
                (string) $selectedClass["school_year"],
                (string) $selectedClass["subject_name"],
                "Term " . $selectedGradingPeriod
            ];
            $earnedByComponent = [];
            $possibleByComponent = [];
            foreach ($exportAssessments as $assessment) {
                $score = $exportScores[(int) $exportStudent["student_id"]][(int) $assessment["assessment_id"]] ?? null;
                $reportRow[] = $score === null ? "" : ["number" => $score];
                if ($score !== null) {
                    $componentId = (int) $assessment["component_id"];
                    $earnedByComponent[$componentId] = ($earnedByComponent[$componentId] ?? 0) + (float) $score;
                    $possibleByComponent[$componentId] = ($possibleByComponent[$componentId] ?? 0) + (float) $assessment["max_score"];
                }
            }
            $weightedTotal = 0.0;
            $scoredWeightTotal = 0.0;
            foreach ($exportComponentWeights as $componentId => $weight) {
                $possiblePoints = $possibleByComponent[$componentId] ?? 0.0;
                if ($weight <= 0 || $possiblePoints <= 0) {
                    continue;
                }
                $weightedTotal += (($earnedByComponent[$componentId] ?? 0.0) / $possiblePoints) * $weight;
                $scoredWeightTotal += $weight;
            }
            $reportRow[] = $scoredWeightTotal > 0 ? ["number" => $weightedTotal / $scoredWeightTotal] : "";
            $reportRows[] = $reportRow;
        }

        $fileBase = strtolower(($selectedClass["section_name"] ?? "section") . "-" . ($selectedClass["subject_name"] ?? "class") . "-term-" . $selectedGradingPeriod);
        $fileBase = trim((string) preg_replace('/[^a-z0-9]+/i', "-", $fileBase), "-");
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"grade-report-" . $fileBase . ".csv\"");
        header("X-Content-Type-Options: nosniff");
        $csvOutput = fopen("php://output", "wb");
        fwrite($csvOutput, "\xEF\xBB\xBF");
        foreach ($reportRows as $reportRow) {
            foreach ($reportRow as &$value) {
                if (is_array($value) && array_key_exists("number", $value)) {
                    $value = (string) (0 + $value["number"]);
                    continue;
                }
                $value = (string) $value;
                if (preg_match('/^\s*[=+\-@]/', $value)) {
                    $value = "'" . $value;
                }
            }
            unset($value);
            fputcsv($csvOutput, $reportRow, ",", '"', "");
        }
        fclose($csvOutput);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
        $token = $_POST["csrf_token"] ?? "";
        if (!hash_equals($_SESSION["teacher_grades_csrf"], $token)) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }

        $action = $_POST["action"] ?? "";
        if (in_array($action, ["create_assessment", "save_scores", "update_assessment", "set_assessment_status", "delete_assessment"], true)) {
            $lockedStatusQuery = $conn->prepare(
                "SELECT status FROM grading_term_submissions
                 WHERE class_subject_id = ? AND grading_period = ?
                 ORDER BY submission_id DESC LIMIT 1"
            );
            $lockedStatusQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
            $lockedStatusQuery->execute();
            $lockedStatus = $lockedStatusQuery->get_result()->fetch_assoc();
            $lockedStatusQuery->close();
            if (($lockedStatus["status"] ?? "") === "Finalized") {
                throw new Exception("These grades have been released. Scores and assessments are now locked.");
            }
        }
        if (in_array($action, ["update_assessment", "set_assessment_status", "delete_assessment"], true)) {
            $showManageAssessmentsModal = true;
        }
        if ($action === "create_assessment") {
            $showCreateAssessmentModal = true;
            $assessmentType = $_POST["assessment_type"] ?? "";
            $gradingPeriod = $selectedGradingPeriod;
            $maxScore = $_POST["max_score"] ?? "";
            $assessmentDate = $_POST["assessment_date"] ?? "";

            if (!in_array($assessmentType, $assessmentTypes, true)) {
                throw new Exception("Choose a valid assessment type.");
            }
            $maxScoreValue = filter_var($maxScore, FILTER_VALIDATE_INT);
            if ($maxScoreValue === false || $maxScoreValue <= 0 || $maxScoreValue > 999999) {
                throw new Exception("Enter a whole-number maximum score greater than zero.");
            }
            $date = DateTime::createFromFormat("Y-m-d", $assessmentDate);
            if (!$date || $date->format("Y-m-d") !== $assessmentDate) {
                throw new Exception("Choose a valid assessment date.");
            }

            $componentId = filter_var($_POST["component_id"] ?? null, FILTER_VALIDATE_INT);
            if (!$componentId || $componentId < 1) throw new Exception("Choose a grading component.");
            $componentLookup = $conn->prepare(
                "SELECT grading_components.component_id
                 FROM grading_components
                 JOIN class_subjects ON class_subjects.class_subject_id = grading_components.class_subject_id
                 LEFT JOIN subject_grading_components
                    ON subject_grading_components.subject_id = class_subjects.subject_id
                    AND LOWER(TRIM(subject_grading_components.component_name)) = LOWER(TRIM(grading_components.component_name))
                 WHERE grading_components.component_id = ?
                   AND grading_components.class_subject_id = ?
                   AND (subject_grading_components.subject_component_id IS NULL OR subject_grading_components.is_archived = 0)"
            );
            $componentLookup->bind_param("ii", $componentId, $selectedClassId);
            $componentLookup->execute();
            $componentExists = $componentLookup->get_result()->fetch_assoc();
            $componentLookup->close();
            if (!$componentExists) throw new Exception("Choose a component configured for this class.");

            $existingAssessments = $conn->prepare(
                "SELECT assessment_name FROM assessments
                 WHERE class_subject_id = ? AND assessment_type = ?"
            );
            $existingAssessments->bind_param("is", $selectedClassId, $assessmentType);
            $existingAssessments->execute();
            $existingNames = $existingAssessments->get_result();
            $highestNumber = 0;
            while ($existing = $existingNames->fetch_assoc()) {
                if (preg_match('/^' . preg_quote($assessmentType, '/') . '\\s+(\\d+)\\b/i', $existing["assessment_name"], $matches)) {
                    $highestNumber = max($highestNumber, (int) $matches[1]);
                }
            }
            $existingAssessments->close();
            $assessmentName = $assessmentType . " " . ($highestNumber + 1);

            $addAssessment = $conn->prepare(
                "INSERT INTO assessments
                    (class_subject_id, component_id, assessment_name, assessment_type,
                     grading_period, max_score, assessment_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $addAssessment->bind_param(
                "iissiis",
                $selectedClassId,
                $componentId,
                $assessmentName,
                $assessmentType,
                $gradingPeriod,
                $maxScoreValue,
                $assessmentDate
            );
            $addAssessment->execute();
            $addAssessment->close();
            $showCreateAssessmentModal = false;
            $selectedAssessmentType = $assessmentType;
            $message = "Assessment created. You can now enter student scores.";
        } elseif (in_array($action, ["update_assessment", "set_assessment_status", "delete_assessment"], true)) {
            $assessmentIdInput = $_POST["assessment_id"] ?? "";
            if (!is_string($assessmentIdInput) || !ctype_digit($assessmentIdInput) || (int) $assessmentIdInput < 1) {
                throw new Exception("Choose a valid assessment.");
            }
            $assessmentIdToManage = (int) $assessmentIdInput;
            $ownedAssessmentQuery = $conn->prepare(
                "SELECT assessments.assessment_id
                 FROM assessments
                 JOIN class_subjects ON class_subjects.class_subject_id = assessments.class_subject_id
                 WHERE assessments.assessment_id = ? AND assessments.class_subject_id = ?
                   AND assessments.grading_period = ? AND class_subjects.teacher_id = ?
                 LIMIT 1"
            );
            $ownedAssessmentQuery->bind_param("iiii", $assessmentIdToManage, $selectedClassId, $selectedGradingPeriod, $teacherId);
            $ownedAssessmentQuery->execute();
            $ownedAssessment = $ownedAssessmentQuery->get_result()->fetch_assoc();
            $ownedAssessmentQuery->close();
            if (!$ownedAssessment) {
                throw new Exception("That assessment is not available in this class and term.");
            }

            if ($action === "update_assessment") {
                $assessmentName = trim((string) ($_POST["assessment_name"] ?? ""));
                $maxScoreValue = filter_var($_POST["max_score"] ?? "", FILTER_VALIDATE_INT);
                $assessmentDate = (string) ($_POST["assessment_date"] ?? "");
                $date = DateTime::createFromFormat("Y-m-d", $assessmentDate);
                if ($assessmentName === "" || strlen($assessmentName) > 100) {
                    throw new Exception("Enter an assessment name up to 100 characters.");
                }
                if ($maxScoreValue === false || $maxScoreValue <= 0 || $maxScoreValue > 999999) {
                    throw new Exception("Enter a whole-number maximum score greater than zero.");
                }
                if (!$date || $date->format("Y-m-d") !== $assessmentDate) {
                    throw new Exception("Choose a valid assessment date.");
                }
                $highestScoreQuery = $conn->prepare(
                    "SELECT MAX(score) AS highest_score FROM student_scores WHERE assessment_id = ?"
                );
                $highestScoreQuery->bind_param("i", $assessmentIdToManage);
                $highestScoreQuery->execute();
                $highestScore = $highestScoreQuery->get_result()->fetch_assoc()["highest_score"];
                $highestScoreQuery->close();
                if ($highestScore !== null && (float) $highestScore > $maxScoreValue) {
                    throw new Exception("The maximum score cannot be lower than the highest score already entered (" . $highestScore . ").");
                }
                $updateAssessment = $conn->prepare(
                    "UPDATE assessments SET assessment_name = ?, max_score = ?, assessment_date = ?
                     WHERE assessment_id = ? AND class_subject_id = ? AND grading_period = ?"
                );
                $updateAssessment->bind_param("sisiii", $assessmentName, $maxScoreValue, $assessmentDate, $assessmentIdToManage, $selectedClassId, $selectedGradingPeriod);
                $updateAssessment->execute();
                $updateAssessment->close();
                $message = "Assessment details updated.";
            } elseif ($action === "set_assessment_status") {
                $newActivityStatus = (string) ($_POST["activity_status"] ?? "");
                if (!in_array($newActivityStatus, ["Active", "Archived"], true)) {
                    throw new Exception("Choose a valid assessment status.");
                }
                $updateAssessmentStatus = $conn->prepare(
                    "UPDATE assessments SET activity_status = ?
                     WHERE assessment_id = ? AND class_subject_id = ? AND grading_period = ?"
                );
                $updateAssessmentStatus->bind_param("siii", $newActivityStatus, $assessmentIdToManage, $selectedClassId, $selectedGradingPeriod);
                $updateAssessmentStatus->execute();
                $updateAssessmentStatus->close();
                $message = $newActivityStatus === "Archived" ? "Assessment archived." : "Assessment restored.";
            } else {
                $conn->begin_transaction();
                try {
                    $scoreCountQuery = $conn->prepare("SELECT COUNT(*) AS total FROM student_scores WHERE assessment_id = ?");
                    $scoreCountQuery->bind_param("i", $assessmentIdToManage);
                    $scoreCountQuery->execute();
                    $scoreCount = (int) ($scoreCountQuery->get_result()->fetch_assoc()["total"] ?? 0);
                    $scoreCountQuery->close();
                    $auditCountQuery = $conn->prepare("SELECT COUNT(*) AS total FROM student_score_audit WHERE assessment_id = ?");
                    $auditCountQuery->bind_param("i", $assessmentIdToManage);
                    $auditCountQuery->execute();
                    $auditCount = (int) ($auditCountQuery->get_result()->fetch_assoc()["total"] ?? 0);
                    $auditCountQuery->close();
                    if ($scoreCount > 0 || $auditCount > 0) {
                        throw new Exception("This assessment has saved scores or score history, so it cannot be permanently deleted.");
                    }
                    $deleteAssessment = $conn->prepare(
                        "DELETE FROM assessments
                         WHERE assessment_id = ? AND class_subject_id = ? AND grading_period = ?
                           AND NOT EXISTS (SELECT 1 FROM student_scores WHERE assessment_id = ?)
                           AND NOT EXISTS (SELECT 1 FROM student_score_audit WHERE assessment_id = ?)"
                    );
                    $deleteAssessment->bind_param("iiiii", $assessmentIdToManage, $selectedClassId, $selectedGradingPeriod, $assessmentIdToManage, $assessmentIdToManage);
                    $deleteAssessment->execute();
                    $deleted = $deleteAssessment->affected_rows;
                    $deleteAssessment->close();
                    if ($deleted !== 1) throw new Exception("This assessment could not be deleted. Refresh the page and try again.");
                    $conn->commit();
                } catch (Throwable $deleteError) {
                    $conn->rollback();
                    throw $deleteError;
                }
                $message = "Assessment permanently deleted.";
            }
        } elseif ($action === "undo_release") {
            if (!$selectedClass || (int) $selectedClass["class_subject_id"] !== $selectedClassId) {
                throw new Exception("Choose one of your assigned classes before unlocking grades.");
            }
            $undoRelease = $conn->prepare(
                "UPDATE grading_term_submissions
                 SET status = 'Returned', finalized_at = NULL, finalized_by_user_id = NULL
                 WHERE submission_id = (
                    SELECT submission_id FROM (
                        SELECT submission_id FROM grading_term_submissions
                        WHERE class_subject_id = ? AND grading_period = ? AND teacher_id = ? AND status = 'Finalized'
                        ORDER BY submission_id DESC LIMIT 1
                    ) AS latest_release
                 )"
            );
            $undoRelease->bind_param("iii", $selectedClassId, $selectedGradingPeriod, $teacherId);
            $undoRelease->execute();
            $unlocked = $undoRelease->affected_rows;
            $undoRelease->close();
            if ($unlocked < 1) {
                throw new Exception("There is no released grade lock to undo for this term.");
            }
            $message = "Term " . $selectedGradingPeriod . " grades are unlocked. You can edit scores and release them again.";
        } elseif ($action === "release_grades") {
            if (!$selectedClass || (int) $selectedClass["class_subject_id"] !== $selectedClassId) {
                throw new Exception("Choose one of your assigned classes before releasing grades.");
            }
            $lockedStatusQuery = $conn->prepare(
                "SELECT status FROM grading_term_submissions
                 WHERE class_subject_id = ? AND grading_period = ?
                 ORDER BY submission_id DESC LIMIT 1"
            );
            $lockedStatusQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
            $lockedStatusQuery->execute();
            $lockedStatus = $lockedStatusQuery->get_result()->fetch_assoc();
            $lockedStatusQuery->close();
            if (($lockedStatus["status"] ?? "") === "Finalized") {
                throw new Exception("These grades have already been released and are locked.");
            }
            $termData = getGradingTermData($conn, $selectedClassId, $selectedGradingPeriod);
            if (!$termData["students"]) {
                throw new Exception("There are no enrolled students to release grades for.");
            }
            if (!$termData["assessments"]) {
                throw new Exception("Add assessments before releasing grades for this term.");
            }
            $incompleteCount = 0;
            foreach ($termData["students"] as $student) {
                $studentResult = $termData["results_by_student"][(int) $student["student_id"]] ?? null;
                if (!$studentResult || !$studentResult["complete"] || $studentResult["initial_grade"] === null) {
                    $incompleteCount++;
                }
            }
            if ($incompleteCount > 0) {
                throw new Exception("Complete all required component scores for every student before releasing grades. " . $incompleteCount . " student(s) still have incomplete grades.");
            }

            $conn->begin_transaction();
            try {
                $submissionInsert = $conn->prepare(
                    "INSERT INTO grading_term_submissions
                        (class_subject_id, grading_period, teacher_id, status, finalized_at, finalized_by_user_id)
                     VALUES (?, ?, ?, 'Finalized', CURRENT_TIMESTAMP, ?)"
                );
                $submissionInsert->bind_param("iiii", $selectedClassId, $selectedGradingPeriod, $teacherId, $userId);
                $submissionInsert->execute();
                $submissionId = (int) $conn->insert_id;
                $submissionInsert->close();

                $gradeInsert = $conn->prepare(
                    "INSERT INTO student_term_grade_records (submission_id, student_id, initial_grade)
                     VALUES (?, ?, ?)"
                );
                $componentInsert = $conn->prepare(
                    "INSERT INTO student_term_component_records
                        (submission_id, student_id, component_id, component_name_snapshot,
                         weight_percentage, percentage_score, weighted_contribution)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($termData["students"] as $student) {
                    $studentId = (int) $student["student_id"];
                    $studentResult = $termData["results_by_student"][$studentId];
                    $initialGrade = round((float) $studentResult["initial_grade"], 0, PHP_ROUND_HALF_UP);
                    $gradeInsert->bind_param("iid", $submissionId, $studentId, $initialGrade);
                    $gradeInsert->execute();

                    foreach ($studentResult["components"] as $componentId => $componentResult) {
                        if ($componentResult["percentage_score"] === null || $componentResult["weighted_contribution"] === null) {
                            continue;
                        }
                        $componentId = (int) $componentId;
                        $componentName = (string) $componentResult["component_name"];
                        $componentWeight = (float) $componentResult["weight_percentage"];
                        $componentPercentage = (float) $componentResult["percentage_score"];
                        $weightedContribution = (float) $componentResult["weighted_contribution"];
                        $componentInsert->bind_param("iiisddd", $submissionId, $studentId, $componentId, $componentName, $componentWeight, $componentPercentage, $weightedContribution);
                        $componentInsert->execute();
                    }
                }
                $gradeInsert->close();
                $componentInsert->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            $message = "Term " . $selectedGradingPeriod . " grades released to students.";
        } elseif ($action === "save_scores") {
            $selectedAssessmentType = $_POST["assessment_type"] ?? "";
            if (!is_string($selectedAssessmentType) || !in_array($selectedAssessmentType, array_merge(["all"], $assessmentTypes), true)) {
                throw new Exception("Choose a valid assessment type.");
            }
            $componentInput = $_POST["component_id"] ?? "0";
            if (!is_string($componentInput) || !ctype_digit($componentInput)) {
                throw new Exception("Choose a valid grading component.");
            }
            $selectedComponentId = (int) $componentInput;
            $assessmentCheck = $conn->prepare(
                "SELECT assessments.assessment_id, assessments.max_score FROM assessments
                 JOIN class_subjects ON class_subjects.class_subject_id = assessments.class_subject_id
                 WHERE assessments.class_subject_id = ?
                   AND (? = 'all' OR assessments.assessment_type = ?)
                   AND class_subjects.teacher_id = ?
                   AND assessments.grading_period = ?
                   AND assessments.activity_status = 'Active'
                   AND (? = 0 OR assessments.component_id = ?)"
            );
            $assessmentCheck->bind_param("issiiii", $selectedClassId, $selectedAssessmentType, $selectedAssessmentType, $teacherId, $selectedGradingPeriod, $selectedComponentId, $selectedComponentId);
            $assessmentCheck->execute();
            $validAssessments = [];
            $assessmentRows = $assessmentCheck->get_result();
            while ($assessmentRow = $assessmentRows->fetch_assoc()) {
                $validAssessments[(int) $assessmentRow["assessment_id"]] = (int) $assessmentRow["max_score"];
            }
            $assessmentCheck->close();
            if (!$validAssessments) {
                throw new Exception("There are no assessments matching these filters in this class.");
            }
            $rosterQuery = $conn->prepare(
                "SELECT students.student_id
                 FROM class_subjects
                 JOIN enrollments ON enrollments.section_id = class_subjects.section_id
                    AND enrollments.school_year = class_subjects.school_year
                    AND enrollments.status = 'Enrolled'
                 JOIN students ON students.student_id = enrollments.student_id
                 JOIN users ON users.user_id = students.user_id
                    AND users.approval_status = 'approved'
                 WHERE class_subjects.class_subject_id = ?
                   AND class_subjects.teacher_id = ?"
            );
            $rosterQuery->bind_param("ii", $selectedClassId, $teacherId);
            $rosterQuery->execute();
            $allowedStudentIds = array_map(
                "intval",
                array_column($rosterQuery->get_result()->fetch_all(MYSQLI_ASSOC), "student_id")
            );
            $rosterQuery->close();

            $submittedScores = $_POST["scores"] ?? [];
            if (!is_array($submittedScores)) {
                throw new Exception("The submitted scores are invalid.");
            }
            $conn->begin_transaction();
            try {
                foreach ($submittedScores as $assessmentIdText => $assessmentScores) {
                    $assessmentId = (int) $assessmentIdText;
                    if (!ctype_digit((string) $assessmentIdText) || !isset($validAssessments[$assessmentId]) || !is_array($assessmentScores)) {
                        throw new Exception("A submitted assessment is not valid for this class.");
                    }
                    $maxScoreValue = $validAssessments[$assessmentId];
                    foreach ($assessmentScores as $studentIdText => $scoreText) {
                        $studentId = (int) $studentIdText;
                        if (!ctype_digit((string) $studentIdText) || !in_array($studentId, $allowedStudentIds, true)) {
                            throw new Exception("A submitted student is not enrolled in this class.");
                        }
                        if ($scoreText === "") {
                            continue;
                        }
                        if (!is_scalar($scoreText)) {
                            throw new Exception("Each score must be a whole number between 0 and its assessment maximum.");
                        }
                        $scoreText = (string) $scoreText;
                        if (!preg_match('/^\d+(?:\.0+)?$/', $scoreText)) {
                            throw new Exception("Each score must be a whole number between 0 and its assessment maximum.");
                        }
                        $scoreValue = (int) $scoreText;
                        if ($scoreValue > $maxScoreValue) {
                            throw new Exception("Each score must be a whole number between 0 and its assessment maximum.");
                        }
                        $existingScoreQuery = $conn->prepare(
                            "SELECT score_id FROM student_scores
                             WHERE assessment_id = ? AND student_id = ? LIMIT 1"
                        );
                        $existingScoreQuery->bind_param("ii", $assessmentId, $studentId);
                        $existingScoreQuery->execute();
                        $existingScore = $existingScoreQuery->get_result()->fetch_assoc();
                        $existingScoreQuery->close();

                        if ($existingScore) {
                            $saveScore = $conn->prepare(
                                "UPDATE student_scores SET score = ? WHERE score_id = ?"
                            );
                            $scoreId = (int) $existingScore["score_id"];
                            $saveScore->bind_param("ii", $scoreValue, $scoreId);
                        } else {
                            $saveScore = $conn->prepare(
                                "INSERT INTO student_scores (assessment_id, student_id, score, remarks)
                                 VALUES (?, ?, ?, '')"
                            );
                            $saveScore->bind_param("iii", $assessmentId, $studentId, $scoreValue);
                        }
                        $saveScore->execute();
                        $saveScore->close();
                    }
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

            $message = "Student scores saved.";
        } else {
            throw new Exception("Choose a valid Grades action.");
        }

        $_SESSION["teacher_grades_csrf"] = bin2hex(random_bytes(32));
    }

    if ($selectedClassId) {
        $componentQuery = $conn->prepare(
            "SELECT component_id, component_name, weight_percentage
             FROM grading_components
             WHERE class_subject_id = ?
             ORDER BY component_id"
        );
        $componentQuery->bind_param("i", $selectedClassId);
        $componentQuery->execute();
        $components = $componentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($components as &$component) {
            $component["weight_percentage"] = (float) $component["weight_percentage"];
        }
        unset($component);
        $componentQuery->close();
        $configuredComponentIds = array_map(static fn($component) => (int) $component["component_id"], $components);
        if ($selectedComponentId > 0 && !in_array($selectedComponentId, $configuredComponentIds, true)) {
            $selectedComponentId = 0;
        }
        $selectedComponent = null;
        if ($selectedComponentId > 0) {
            foreach ($components as $component) {
                if ((int) $component["component_id"] === $selectedComponentId) {
                    $selectedComponent = $component;
                    break;
                }
            }
        }

        $assessmentQuery = $conn->prepare(
            "SELECT assessments.assessment_id, assessments.component_id, assessments.assessment_name,
                    assessments.assessment_type, assessments.max_score,
                    assessments.grading_period, assessments.assessment_date, assessments.activity_status,
                    grading_components.component_name,
                    (SELECT COUNT(*) FROM student_scores WHERE assessment_id = assessments.assessment_id) AS score_count,
                    (SELECT COUNT(*) FROM student_score_audit WHERE assessment_id = assessments.assessment_id) AS audit_count
             FROM assessments
             JOIN grading_components ON grading_components.component_id = assessments.component_id
             WHERE assessments.class_subject_id = ?
             ORDER BY assessments.assessment_date DESC, assessments.assessment_id DESC"
        );
        $assessmentQuery->bind_param("i", $selectedClassId);
        $assessmentQuery->execute();
        $assessments = $assessmentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $assessmentQuery->close();

        if (!in_array($selectedAssessmentType, array_merge(["all"], $assessmentTypes), true)) {
            throw new Exception("Choose a valid assessment type.");
        }
        $termAssessments = array_values(array_filter(
            $assessments,
            static fn($assessment) => (int) $assessment["grading_period"] === $selectedGradingPeriod
                && ($assessment["activity_status"] ?? "Active") === "Active"
        ));
        $possiblePointsByComponent = [];
        foreach ($termAssessments as $assessment) {
            $componentId = (int) $assessment["component_id"];
            $possiblePointsByComponent[$componentId] = ($possiblePointsByComponent[$componentId] ?? 0.0)
                + (float) $assessment["max_score"];
        }
        $typeAssessments = $selectedAssessmentType === "all"
            ? $termAssessments
            : array_values(array_filter(
                $termAssessments,
                static fn($assessment) => $assessment["assessment_type"] === $selectedAssessmentType
            ));
        if ($selectedComponentId > 0) {
            $typeAssessments = array_values(array_filter(
                $typeAssessments,
                static fn($assessment) => (int) $assessment["component_id"] === $selectedComponentId
            ));
        }

        $studentQuery = $conn->prepare(
            "SELECT students.student_id, users.first_name, users.middle_name,
                    users.last_name
             FROM class_subjects
             JOIN enrollments ON enrollments.section_id = class_subjects.section_id
                AND enrollments.school_year = class_subjects.school_year
                AND enrollments.status = 'Enrolled'
             JOIN students ON students.student_id = enrollments.student_id
             JOIN users ON users.user_id = students.user_id
                AND users.approval_status = 'approved'
             WHERE class_subjects.class_subject_id = ?
               AND class_subjects.teacher_id = ?
             ORDER BY users.last_name, users.first_name"
        );
        $studentQuery->bind_param("ii", $selectedClassId, $teacherId);
        $studentQuery->execute();
        $students = $studentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $studentQuery->close();

        if (count($termAssessments) > 0) {
            $typeAssessmentsById = array_fill_keys(
                array_map(static fn($assessment) => (int) $assessment["assessment_id"], $typeAssessments),
                true
            );
            $scoreQuery = $conn->prepare(
                "SELECT student_scores.assessment_id, student_scores.student_id, student_scores.score, student_scores.remarks
                 FROM student_scores
                 JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
                 WHERE assessments.class_subject_id = ? AND assessments.grading_period = ?"
            );
            $scoreQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
            $scoreQuery->execute();
            $scoreRows = $scoreQuery->get_result();
            while ($scoreRow = $scoreRows->fetch_assoc()) {
                $assessmentId = (int) $scoreRow["assessment_id"];
                $studentId = (int) $scoreRow["student_id"];
                $termScoresByAssessment[$assessmentId][$studentId] = (float) $scoreRow["score"];
                if (isset($typeAssessmentsById[$assessmentId])) {
                    $scoresByAssessment[$assessmentId][$studentId] = $scoreRow["score"];
                    $sampleScoresByAssessment[$assessmentId][$studentId] = stripos((string) ($scoreRow["remarks"] ?? ""), "Sample score generated for demonstration") !== false;
                }
            }
            $scoreQuery->close();

        }

        foreach ($students as $student) {
            $studentId = (int) $student["student_id"];
            $earnedByComponent = [];
            $possibleByComponent = [];
            foreach ($termAssessments as $assessment) {
                $assessmentId = (int) $assessment["assessment_id"];
                if (!array_key_exists($studentId, $termScoresByAssessment[$assessmentId] ?? [])) {
                    continue;
                }
                $componentId = (int) $assessment["component_id"];
                $earnedByComponent[$componentId] = ($earnedByComponent[$componentId] ?? 0.0)
                    + $termScoresByAssessment[$assessmentId][$studentId];
                $possibleByComponent[$componentId] = ($possibleByComponent[$componentId] ?? 0.0)
                    + (float) $assessment["max_score"];
            }

            $weightedTotal = 0.0;
            $scoredWeightTotal = 0.0;
            foreach ($components as $component) {
                $componentId = (int) $component["component_id"];
                $weight = (float) $component["weight_percentage"];
                $possiblePoints = $possibleByComponent[$componentId] ?? 0.0;
                $componentPossiblePoints = $possiblePointsByComponent[$componentId] ?? 0.0;
                if ($componentPossiblePoints > 0) {
                    $componentPercentage = (($earnedByComponent[$componentId] ?? 0.0) / $componentPossiblePoints) * 100;
                    $componentPercentagesByStudent[$studentId][$componentId] = $componentPercentage;
                    $componentContributionsByStudent[$studentId][$componentId] = $componentPercentage * ($weight / 100);
                }
                if ($weight <= 0 || $possiblePoints <= 0) {
                    continue;
                }
                $componentPercentage = (($earnedByComponent[$componentId] ?? 0.0) / $possiblePoints) * 100;
                $weightedTotal += $componentPercentage * ($weight / 100);
                $scoredWeightTotal += $weight;
            }
            $possibleGradesByStudent[$studentId] = $scoredWeightTotal > 0
                ? $weightedTotal / $scoredWeightTotal * 100
                : null;
        }
    }
} catch (Exception $e) {
    error_log("Teacher grades page error: " . $e->getMessage());
    if ($error === "") {
        $error = $e instanceof mysqli_sql_exception
            ? "We could not process that Grades request. Please try again."
            : $e->getMessage();
    }
}

if ($selectedClassId && $teacherId && count($components) === 0) {
    try {
        $componentQuery = $conn->prepare(
            "SELECT component_id, component_name FROM grading_components WHERE class_subject_id = ? ORDER BY component_id"
        );
        $componentQuery->bind_param("i", $selectedClassId);
        $componentQuery->execute();
        $components = $componentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $componentQuery->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Teacher grading components reload error: " . $e->getMessage());
    }
}

$teacherName = $_SESSION["name"] ?? "Teacher";
$selectedClass = null;
foreach ($classes as $class) {
    if ((int) $class["class_subject_id"] === $selectedClassId) {
        $selectedClass = $class;
        break;
    }
}
if ($selectedClass && $teacherId) {
    try {
        $creatableComponentQuery = $conn->prepare(
            "SELECT grading_components.component_id, grading_components.component_name, grading_components.weight_percentage
             FROM grading_components
             JOIN class_subjects ON class_subjects.class_subject_id = grading_components.class_subject_id
             LEFT JOIN subject_grading_components
                ON subject_grading_components.subject_id = class_subjects.subject_id
                AND LOWER(TRIM(subject_grading_components.component_name)) = LOWER(TRIM(grading_components.component_name))
             WHERE grading_components.class_subject_id = ?
               AND (subject_grading_components.subject_component_id IS NULL OR subject_grading_components.is_archived = 0)
             ORDER BY grading_components.component_id"
        );
        $creatableComponentQuery->bind_param("i", $selectedClassId);
        $creatableComponentQuery->execute();
        $creatableComponents = $creatableComponentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $creatableComponentQuery->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Teacher creatable grading components load error: " . $e->getMessage());
    }
}
if ($selectedClass && $teacherId) {
    try {
        $releaseStatusQuery = $conn->prepare(
            "SELECT status, finalized_at
             FROM grading_term_submissions
             WHERE class_subject_id = ? AND grading_period = ?
             ORDER BY submission_id DESC LIMIT 1"
        );
        $releaseStatusQuery->bind_param("ii", $selectedClassId, $selectedGradingPeriod);
        $releaseStatusQuery->execute();
        $latestGradeRelease = $releaseStatusQuery->get_result()->fetch_assoc() ?: null;
        $releaseStatusQuery->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Teacher grade release status error: " . $e->getMessage());
    }
}
$gradesAlreadyReleased = ($latestGradeRelease["status"] ?? "") === "Finalized";
if ($selectedClass && !$gradesAlreadyReleased) {
    try {
        $releasePreviewData = getGradingTermData($conn, $selectedClassId, $selectedGradingPeriod);
        if (!$releasePreviewData["students"]) {
            $releaseReadinessMessage = "There are no enrolled students to release grades for.";
        } elseif (!$releasePreviewData["assessments"]) {
            $releaseReadinessMessage = "Add assessments before releasing grades for this term.";
        } else {
            $incompleteStudents = 0;
            foreach ($releasePreviewData["students"] as $releaseStudent) {
                $releaseStudentId = (int) $releaseStudent["student_id"];
                $releaseStudentResult = $releasePreviewData["results_by_student"][$releaseStudentId] ?? null;
                if (!$releaseStudentResult || !$releaseStudentResult["complete"] || $releaseStudentResult["initial_grade"] === null) {
                    $incompleteStudents++;
                    continue;
                }
                $roundedReleaseGrade = round((float) $releaseStudentResult["initial_grade"], 0, PHP_ROUND_HALF_UP);
                if ($roundedReleaseGrade < 75) {
                    $studentsBelowPassing[] = [
                        "name" => trim(($releaseStudent["last_name"] ?? "") . ", " . ($releaseStudent["first_name"] ?? "") . " " . ($releaseStudent["middle_name"] ?? "")),
                        "grade" => $roundedReleaseGrade
                    ];
                }
            }
            if ($incompleteStudents > 0) {
                $releaseReadinessMessage = "Complete all required scores and grading weights first. " . $incompleteStudents . " student(s) still have incomplete grades.";
            } else {
                $releaseReady = true;
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log("Teacher grade release preview error: " . $e->getMessage());
        $releaseReadinessMessage = "We could not check whether these grades are ready to release.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Create assessments and manage student grades in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Grades | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <link rel="stylesheet" href="css/grades.css?v=<?= (int) filemtime(__DIR__ . "/css/grades.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="teacher-grades-page">
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
            <a class="teacher-messages-nav-link" href="../teacher-messages">Messages <span class="nav-unread-count" data-teacher-unread-count<?= $teacherUnreadMessageCount > 0 ? "" : " hidden" ?>><?= $teacherUnreadMessageCount > 99 ? "99+" : $teacherUnreadMessageCount ?></span></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
        </div>
    </header>

    <main class="dashboard grades-dashboard">
        <a class="grades-back-link" href="../teacher-sections"><span aria-hidden="true">←</span> Back to Sections</a>
        <div class="page-heading grades-page-heading">
            <div>
                <p class="eyebrow">TEACHER PORTAL <span aria-hidden="true">/</span> GRADEBOOK</p>
                <h1>Grades</h1>
                <?php 
if (!$selectedClass): ?>
                    <p class="grades-heading-subtitle">Enter, review, and save student assessment scores.</p>
                <?php 
endif; ?>
            </div>
        </div>

        <?php 
if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php 
endif; ?>

        <?php 
if (count($classes) === 0): ?>
            <section class="panel"><p class="empty-state">No classes have been assigned to your account yet.</p></section>
        <?php 
elseif (!$selectedClassId): ?>
            <section class="panel"><p class="empty-state">Open a section from the Sections page to view and enter its grades.</p><p><a class="small-button" href="../teacher-sections">Go to Sections</a></p></section>
        <?php 
else: ?>
            <?php 
if ($selectedClassId): ?>
                
                <div class="dashboard-modal-overlay" id="create-assessment-modal" data-trigger-id="open-create-assessment-toolbar" <?= $showCreateAssessmentModal ? "" : "hidden" ?>>
                    <section class="dashboard-form-modal assessment-form-modal" role="dialog" aria-modal="true" aria-labelledby="create-assessment-title" tabindex="-1">
                        <div class="dashboard-form-modal-heading">
                            <div><h2 id="create-assessment-title">Create an assessment</h2><p><?= h("Grade " . $selectedClass["grade_level"] . " · " . $selectedClass["section_name"] . " — " . $selectedClass["subject_name"] . " · Term " . $selectedGradingPeriod) ?></p></div>
                            <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                        </div>
                    <?php 
if ($showCreateAssessmentModal && $error !== ""): ?><p class="alert error assessment-modal-alert" role="alert"><?= h($error) ?></p><?php 
endif; ?>
                    <?php 
if (count($creatableComponents) === 0): ?>
                        <p class="empty-state">No grading components are configured for this class yet. Please contact the administrator.</p>
                    <?php 
else: ?>
                        <form action="../teacher-grades" class="grade-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                            <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                            <input type="hidden" name="action" value="create_assessment">
                            <label>Type
                                <select name="assessment_type" required>
                                    <?php 
foreach ($assessmentTypes as $type): ?><option value="<?= h($type) ?>"><?= h($type) ?></option><?php 
endforeach; ?>
                                </select>
                            </label>
                            <label>Grading component
                                <select name="component_id" required>
                                    <option value="">Choose a component</option>
                                    <?php foreach ($creatableComponents as $component): ?><option value="<?= (int) $component["component_id"] ?>"><?= h($component["component_name"]) ?> · <?= h($component["weight_percentage"] ?? "0") ?>%</option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>Maximum score<input type="number" name="max_score" min="1" step="1" required></label>
                            <label>Assessment date<input type="date" name="assessment_date" value="<?= date("Y-m-d") ?>" required></label>
                            <button class="small-button" type="submit">Create assessment</button>
                        </form>
                    <?php 
endif; ?>
                    </section>
                </div>

                <div class="dashboard-modal-overlay" id="manage-assessments-modal" data-trigger-id="manage-assessments-trigger" <?= $showManageAssessmentsModal ? "" : "hidden" ?>>
                    <section class="dashboard-form-modal assessment-management-modal" role="dialog" aria-modal="true" aria-labelledby="manage-assessments-title" tabindex="-1">
                        <div class="dashboard-form-modal-heading">
                            <div><h2 id="manage-assessments-title">Manage assessments</h2><p><?= h($selectedClass["section_name"] . " — " . $selectedClass["subject_name"] . " · Term " . $selectedGradingPeriod) ?></p></div>
                            <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                        </div>
                        <?php 
if ($showManageAssessmentsModal && $error !== ""): ?><p class="alert error assessment-modal-alert" role="alert"><?= h($error) ?></p><?php 
endif; ?>
                        <?php
                            
$managedAssessments = array_values(array_filter(
                                $assessments,
                                static fn($assessment) => (int) $assessment["grading_period"] === $selectedGradingPeriod
                            ));
                        ?>
                        <?php 
if (!$managedAssessments): ?>
                            <p class="assessment-management-empty">There are no assessments to manage for this term.</p>
                        <?php 
else: ?>
                            <div class="assessment-management-list">
                                <?php 
foreach ($managedAssessments as $managedAssessment): ?>
                                    <?php 
$isAssessmentArchived = ($managedAssessment["activity_status"] ?? "Active") === "Archived"; ?>
                                    <?php $assessmentHasHistory = (int) ($managedAssessment["score_count"] ?? 0) > 0 || (int) ($managedAssessment["audit_count"] ?? 0) > 0; ?>
                                    <article class="assessment-management-item <?= $isAssessmentArchived ? "is-archived" : "" ?>">
                                        <div class="assessment-management-title">
                                            <div><strong><?= h($managedAssessment["assessment_name"]) ?></strong><span><?= h($managedAssessment["assessment_type"] . " · " . $managedAssessment["component_name"]) ?></span></div>
                                            <span class="assessment-management-status"><?= $isAssessmentArchived ? "Archived" : "Active" ?></span>
                                        </div>
                                        <form action="../teacher-grades" class="assessment-management-edit" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                                            <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                                            <input type="hidden" name="action" value="update_assessment">
                                            <input type="hidden" name="assessment_id" value="<?= (int) $managedAssessment["assessment_id"] ?>">
                                            <label>Assessment name<input type="text" name="assessment_name" maxlength="100" value="<?= h($managedAssessment["assessment_name"]) ?>" required></label>
                                            <label>Maximum score<input type="number" name="max_score" min="1" max="999999" step="1" value="<?= h($managedAssessment["max_score"]) ?>" required></label>
                                            <label>Date<input type="date" name="assessment_date" value="<?= h($managedAssessment["assessment_date"]) ?>" required></label>
                                            <button class="small-button" type="submit">Save changes</button>
                                        </form>
                                        <form action="../teacher-grades" class="assessment-management-delete-form" method="post" onsubmit="return confirm('Permanently delete this assessment? This action cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                                            <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                                            <input type="hidden" name="action" value="delete_assessment">
                                            <input type="hidden" name="assessment_id" value="<?= (int) $managedAssessment["assessment_id"] ?>">
                                            <button class="assessment-management-delete-button" type="submit" <?= $assessmentHasHistory ? "disabled title=\"This assessment has saved scores or score history.\"" : "" ?>>Delete assessment</button>
                                            <?php if ($assessmentHasHistory): ?><small>Cannot delete: this assessment has saved scores or score history.</small><?php endif; ?>
                                        </form>
                                    </article>
                                <?php 
endforeach; ?>
                            </div>
                        <?php 
endif; ?>
                    </section>
                </div>

                <?php 
if (!$gradesAlreadyReleased): ?>
                    <div class="dashboard-modal-overlay" id="release-grades-modal" data-trigger-id="open-release-grades" hidden>
                        <section class="dashboard-form-modal release-grades-modal" role="dialog" aria-modal="true" aria-labelledby="release-grades-title" tabindex="-1">
                            <div class="dashboard-form-modal-heading">
                                <div>
                                    <h2 id="release-grades-title">Release grades?</h2>
                                    <p>Students will be able to see their grades. Score and assessment changes will be locked.</p>
                                </div>
                                <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                            </div>
                            <div class="release-grades-content">
                                <h3>Students below 75%</h3>
                                <?php 
if ($studentsBelowPassing): ?>
                                    <ul class="release-failed-students">
                                        <?php 
foreach ($studentsBelowPassing as $failedStudent): ?>
                                            <li><span><?= h($failedStudent["name"]) ?></span><strong><?= h(number_format($failedStudent["grade"], 2)) ?>%</strong></li>
                                        <?php 
endforeach; ?>
                                    </ul>
                                <?php 
elseif ($releaseReady): ?>
                                    <p class="release-no-failures">No students are below 75%.</p>
                                <?php 
endif; ?>
                                <?php 
if (!$releaseReady): ?><p class="release-readiness-warning" role="alert"><?= h($releaseReadinessMessage ?: "Complete all required scores before releasing grades.") ?></p><?php 
endif; ?>
                            </div>
                            <form action="../teacher-grades" class="release-grades-actions" method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                                <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                                <input type="hidden" name="action" value="release_grades">
                                <button class="small-button" type="submit" <?= !$releaseReady ? "disabled" : "" ?>>Confirm release</button>
                                <button class="dashboard-modal-close release-cancel-button" type="button">Cancel</button>
                            </form>
                        </section>
                    </div>
                <?php 
endif; ?>

                <section class="panel grade-panel">
                    <div class="grade-spreadsheet-toolbar">
                        <form action="../teacher-grades" class="grade-class-picker" method="get">
                            <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                            <strong><?= h($selectedClass["section_name"]) ?></strong>
                            <label class="grade-assessment-type-filter" for="grade-assessment-filter">Assessment type
                                <select name="assessment_type" id="grade-assessment-filter">
                                    <option value="all" <?= $selectedAssessmentType === "all" ? "selected" : "" ?>>All assessments</option>
                                    <?php 
foreach ($assessmentTypes as $type): ?>
                                        <option value="<?= h($type) ?>" <?= $selectedAssessmentType === $type ? "selected" : "" ?>><?= h($type) ?></option>
                                    <?php 
endforeach; ?>
                                </select>
                            </label>
                            <label for="grade-component-filter">Grading component
                                <select name="component_id" id="grade-component-filter">
                                    <option value="0" <?= $selectedComponentId === 0 ? "selected" : "" ?>>All components</option>
                                    <?php 
foreach ($components as $component): ?>
                                        <option value="<?= (int) $component["component_id"] ?>" <?= $selectedComponentId === (int) $component["component_id"] ? "selected" : "" ?>><?= h(gradeComponentAbbreviation((string) $component["component_name"]) . " · " . $component["component_name"] . " (" . rtrim(rtrim(number_format((float) $component["weight_percentage"], 2), "0"), ".") . "%)") ?></option>
                                    <?php 
endforeach; ?>
                                </select>
                            </label>
                        </form>
                        <?php 
if (!$gradesAlreadyReleased): ?>
                            <button class="small-button manage-assessments-trigger" id="manage-assessments-trigger" type="button" data-modal-target="manage-assessments-modal" aria-haspopup="dialog" aria-expanded="false">Manage assessments</button>
                            <button class="small-button create-assessment-toolbar-trigger" id="open-create-assessment-toolbar" type="button" data-modal-target="create-assessment-modal" aria-haspopup="dialog" aria-expanded="false">Add assessment</button>
                            <div class="grade-release-form">
                                <button class="grade-release-button" type="button" id="open-release-grades" data-modal-target="release-grades-modal">Release grades</button>
                            </div>
                        <?php 
else: ?>
                            <span class="grade-lock-note" role="status">Scores locked</span>
                            <form action="../teacher-grades" class="grade-release-form" method="post" onsubmit="return confirm('Undo the grade release and unlock scores for this term? Students will no longer see the released grades.');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                                <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                                <input type="hidden" name="action" value="undo_release">
                                <button class="small-button" type="submit">Undo lock</button>
                            </form>
                        <?php 
endif; ?>
                        <?php 
if (count($typeAssessments) > 0 && !$gradesAlreadyReleased): ?>
                            <form action="../teacher-grades" class="grade-score-form" method="post" id="grade-score-form">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_grades_csrf"]) ?>">
                                <input type="hidden" name="class_subject_id" value="<?= $selectedClassId ?>">
                                <input type="hidden" name="assessment_type" value="<?= h($selectedAssessmentType) ?>">
                                <input type="hidden" name="component_id" value="<?= $selectedComponentId ?>">
                                <input type="hidden" name="action" value="save_scores">
                                <button class="small-button" type="submit">Save scores</button>
                            </form>
                        <?php 
endif; ?>
                        <a class="grade-download-button" href="../teacher-grades?class_subject_id=<?= $selectedClassId ?>&amp;download_report=1" aria-label="Download student records and assessment scores as an Excel-compatible CSV" title="Download grades report">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v3h14v-3"></path></svg>
                        </a>
                    </div>
                    <?php 
if (count($students) === 0): ?>
                        <p class="empty-state">There are no currently enrolled students in this class.</p>
                    <?php 
else: ?>
                        <div class="table-wrap assessment-spreadsheet-wrap">
                                <table class="grade-table grade-spreadsheet" style="min-width: <?= max(1160, 760 + (count($typeAssessments) * 165)) ?>px">
                                    <thead>
                                        <tr><th class="spreadsheet-row-number">#</th><th class="spreadsheet-student-name">Student</th>
                                            <?php 
foreach ($typeAssessments as $assessment): ?>
                                                <th title="<?= h($assessment["component_name"] . " · Term " . $assessment["grading_period"]) ?>"><?= h($assessment["assessment_name"]) ?><small class="spreadsheet-max-score"> / <?= h($assessment["max_score"]) ?></small></th>
                                            <?php 
endforeach; ?>
                                            <th class="spreadsheet-possible-grade"><?= $selectedComponent !== null ? h(gradeComponentAbbreviation((string) $selectedComponent["component_name"])) : "Grades" ?><?php 
if ($selectedComponent !== null): ?><small class="spreadsheet-max-score"><?= h(rtrim(rtrim(number_format((float) $selectedComponent["weight_percentage"], 2), "0"), ".")) ?> weight</small><?php 
endif; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
foreach ($students as $studentIndex => $student): ?>
                                            <tr>
                                                <td class="spreadsheet-row-number"><?= $studentIndex + 1 ?></td>
                                                <td class="spreadsheet-student-name"><?= h(trim($student["last_name"] . ", " . $student["first_name"] . " " . ($student["middle_name"] ?? ""))) ?></td>
                                                <?php 
foreach ($typeAssessments as $assessment): ?>
                                                    <?php 
$storedScore = $scoresByAssessment[(int) $assessment["assessment_id"]][(int) $student["student_id"]] ?? ""; ?>
                                                    <td><input class="grade-score" form="grade-score-form" type="number" inputmode="numeric" name="scores[<?= (int) $assessment["assessment_id"] ?>][<?= (int) $student["student_id"] ?>]" min="0" max="<?= h($assessment["max_score"]) ?>" step="1" value="<?= $storedScore === "" ? "0" : h((string) (int) $storedScore) ?>" title="<?= !empty($sampleScoresByAssessment[(int) $assessment["assessment_id"]][(int) $student["student_id"]]) ? "Generated sample score" : "" ?>" aria-label="<?= h($assessment["assessment_name"] . " score for " . $student["first_name"] . " " . $student["last_name"]) ?>" <?= $gradesAlreadyReleased ? "disabled" : "" ?>></td>
                                                <?php 
endforeach; ?>
                                                <?php
                                                    
$studentId = (int) $student["student_id"];
                                                    $possibleGrade = $possibleGradesByStudent[$studentId] ?? null;
                                                    $componentPercentage = $selectedComponent !== null
                                                        ? ($componentPercentagesByStudent[$studentId][(int) $selectedComponent["component_id"]] ?? null)
                                                        : null;
                                                    $componentContribution = $selectedComponent !== null
                                                        ? ($componentContributionsByStudent[$studentId][(int) $selectedComponent["component_id"]] ?? null)
                                                        : null;
                                                ?>
                                                <td class="spreadsheet-possible-grade">
                                                    <?php 
if ($selectedComponent !== null): ?>
                                                        <?php 
if ($componentPercentage === null): ?><span class="grade-not-available">No assessments</span>
                                                        <?php 
else: ?><strong><?= h(number_format($componentPercentage, 2)) ?></strong><small><?= h(number_format((float) $componentContribution, 2)) ?> grade contribution</small><?php 
endif; ?>
                                                    <?php 
elseif ($possibleGrade === null): ?><span class="grade-not-available">No recorded scores</span>
                                                    <?php 
else: ?><strong><?= h(number_format($possibleGrade, 2)) ?></strong><?php 
endif; ?>
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
            <?php 
endif; ?>
        <?php 
endif; ?>
    </main>
    <?php 
if ($message !== ""): ?>
        <div class="dashboard-modal-overlay" id="grades-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="grades-success-title" aria-describedby="grades-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="grades-success-title">Gradebook updated</h2>
                <p id="grades-success-message"><?= h($message) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
    <script>
        document.querySelectorAll(".grade-score").forEach(function (scoreInput) {
            scoreInput.addEventListener("input", function () {
                const digitsOnly = scoreInput.value.replace(/\D/g, "");
                if (scoreInput.value !== digitsOnly) scoreInput.value = digitsOnly;
            });
        });
        const gradeAssessmentFilter = document.getElementById("grade-assessment-filter");
        if (gradeAssessmentFilter) {
            gradeAssessmentFilter.addEventListener("change", function () {
                gradeAssessmentFilter.form.requestSubmit();
            });
        }
        const gradeComponentFilter = document.getElementById("grade-component-filter");
        if (gradeComponentFilter) {
            gradeComponentFilter.addEventListener("change", function () {
                gradeComponentFilter.form.requestSubmit();
            });
        }

    </script>
</body>
</html>
