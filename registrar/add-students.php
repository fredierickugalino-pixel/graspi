<?php
require_once __DIR__ . "/../includes/url_helpers.php";
session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole(["registrar", "admin"]);
require_once __DIR__ . "/../includes/student_record_creation.php";
require_once __DIR__ . "/../includes/teacher_schema.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
ensureTeacherMustChangePasswordColumn($conn);
require_once __DIR__ . "/../includes/section_schema.php";
require_once __DIR__ . "/../includes/enrollment_schema.php";
ensureSectionSchema($conn);
ensureEnrollmentStatusSchema($conn);

if (isset($_GET["download"]) && $_GET["download"] === "template") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=registrar-students-template.csv");
    $output = fopen("php://output", "w");
    fputcsv($output, ["lrn", "first_name", "middle_name", "last_name", "suffix", "email", "gender", "track_id", "section_id"]);
    fclose($output);
    exit;
}

if (empty($_SESSION["registrar_students_csrf"])) {
    $_SESSION["registrar_students_csrf"] = bin2hex(random_bytes(32));
}
$tracks = $conn->query("SELECT track_id, track_name, strand_name FROM tracks ORDER BY track_name, strand_name")->fetch_all(MYSQLI_ASSOC);
$sections = $conn->query("SELECT section_id, section_name, grade_level, track_id FROM sections WHERE is_archived = 0 ORDER BY grade_level, section_name")->fetch_all(MYSQLI_ASSOC);
$pageErrors = $_SESSION["registrar_students_flash_errors"] ?? [];
$manual = $_SESSION["registrar_students_manual_values"] ?? [];
unset($_SESSION["registrar_students_flash_errors"], $_SESSION["registrar_students_manual_values"]);
$manual = array_merge([
    "lrn" => "", "first_name" => "", "middle_name" => "", "last_name" => "",
    "suffix" => "", "email" => "", "gender" => "", "track_id" => "", "section_id" => ""
], is_array($manual) ? $manual : []);
$summary = null;
$currentBatch = (string) ($_SESSION["registrar_current_batch"] ?? "");
if ($currentBatch !== "") {
    $summary = $_SESSION["registrar_import_summaries"][$currentBatch] ?? null;
}
$preview = $_SESSION["registrar_csv_preview"] ?? null;
$pendingRetries = $_SESSION["registrar_mail_retries"] ?? [];

function registrarRedirect(): void
{
    header("Location: " . rolePageUrl("registrar", "add-students.php"));
    exit;
}

function registrarBuildSummary(int $total): array
{
    return [
        "total_records" => $total,
        "registered" => 0,
        "emails_sent" => 0,
        "failed_emails" => 0,
        "duplicates" => 0,
        "invalid" => 0,
        "failed_registrations" => 0,
        "row_errors" => []
    ];
}

function registrarSaveSummary(array $summary): void
{
    $batch = bin2hex(random_bytes(12));
    $_SESSION["registrar_current_batch"] = $batch;
    $_SESSION["registrar_import_summaries"][$batch] = $summary;
    $allBatches = array_keys($_SESSION["registrar_import_summaries"]);
    foreach (array_slice($allBatches, 0, max(0, count($allBatches) - 10)) as $oldBatch) {
        unset($_SESSION["registrar_import_summaries"][$oldBatch]);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $action = (string) ($_POST["action"] ?? "");
    $pageErrors = [];
    if (!hash_equals($_SESSION["registrar_students_csrf"], $token)) {
        $pageErrors[] = "This form expired. Refresh the page and try again.";
    } elseif ($action === "preview_csv") {
        unset($_SESSION["registrar_csv_preview"]);
        $preview = null;
        $upload = $_FILES["students_csv"] ?? null;
        if (!is_array($upload) || ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload["tmp_name"] ?? "")) {
            $pageErrors[] = "Choose a CSV file to preview.";
        } elseif (($upload["size"] ?? 0) > 2 * 1024 * 1024) {
            $pageErrors[] = "CSV files must be 2 MB or smaller.";
        } else {
            $handle = fopen($upload["tmp_name"], "rb");
            $header = $handle ? fgetcsv($handle, 0, ",", "\"", "") : false;
            if (!is_array($header)) {
                $pageErrors[] = "The CSV file is empty or unreadable.";
            } else {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', "", (string) $header[0]);
                $header = array_map(static fn($value) => strtolower(trim((string) $value)), $header);
                $required = ["lrn", "first_name", "last_name", "email", "gender", "track_id", "section_id"];
                if (count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
                    $pageErrors[] = "CSV headers must include lrn, first_name, last_name, email, gender, track_id, and section_id. middle_name and suffix are optional.";
                } else {
                    $previewRows = [];
                    $seenLrns = [];
                    $seenEmails = [];
                    $total = 0;
                    $validCount = 0;
                    $duplicateCount = 0;
                    $invalidCount = 0;
                    $line = 1;
                    while (($cells = fgetcsv($handle, 0, ",", "\"", "")) !== false) {
                        $line++;
                        if (count($cells) === 1 && trim((string) ($cells[0] ?? "")) === "") {
                            continue;
                        }
                        $total++;
                        if ($total > 500) {
                            $pageErrors[] = "A CSV can contain at most 500 student rows. No students were added.";
                            break;
                        }
                        if (count($cells) !== count($header)) {
                            $invalidCount++;
                            $previewRows[] = ["line" => $line, "status" => "invalid", "reason" => "Column count does not match the header.", "student" => []];
                            continue;
                        }
                        $row = array_combine($header, $cells);
                        try {
                            $student = validateRegistrarStudent($row);
                            validateRegistrarPlacement($conn, $student);
                            $lrnKey = $student["lrn"];
                            $emailKey = strtolower($student["email"]);
                            if (isset($seenLrns[$lrnKey]) || isset($seenEmails[$emailKey]) || registrarStudentAlreadyExists($conn, $student)) {
                                $duplicateCount++;
                                $previewRows[] = ["line" => $line, "status" => "duplicate", "reason" => "LRN or email is already in use.", "student" => $student];
                                continue;
                            }
                            $seenLrns[$lrnKey] = true;
                            $seenEmails[$emailKey] = true;
                            $validCount++;
                            $previewRows[] = ["line" => $line, "status" => "valid", "reason" => "Ready to add.", "student" => $student];
                        } catch (InvalidArgumentException $e) {
                            $invalidCount++;
                            $previewRows[] = ["line" => $line, "status" => "invalid", "reason" => $e->getMessage(), "student" => $row];
                        } catch (mysqli_sql_exception $e) {
                            error_log("Registrar CSV preview validation error: " . $e->getMessage());
                            $invalidCount++;
                            $previewRows[] = ["line" => $line, "status" => "invalid", "reason" => "Could not validate this row against the current school records.", "student" => $row];
                        }
                    }
                    if ($total === 0 && !$pageErrors) {
                        $pageErrors[] = "The CSV contains no student rows.";
                    } elseif (!$pageErrors) {
                        $_SESSION["registrar_csv_preview"] = [
                            "total" => $total,
                            "valid" => $validCount,
                            "duplicates" => $duplicateCount,
                            "invalid" => $invalidCount,
                            "rows" => $previewRows
                        ];
                        $preview = $_SESSION["registrar_csv_preview"];
                    }
                }
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    } elseif ($action === "cancel_preview") {
        unset($_SESSION["registrar_csv_preview"]);
        $preview = null;
    } elseif ($action === "confirm_csv") {
        @set_time_limit(0);
        $csvPreview = $_SESSION["registrar_csv_preview"] ?? null;
        if (!is_array($csvPreview)) {
            $pageErrors[] = "Upload and preview a CSV before confirming the import.";
        } else {
            $summary = registrarBuildSummary((int) $csvPreview["total"]);
            $summary["duplicates"] = (int) $csvPreview["duplicates"];
            $summary["invalid"] = (int) $csvPreview["invalid"];
            $usedPasswords = [];
            foreach ($csvPreview["rows"] as $entry) {
                if (($entry["status"] ?? "") !== "valid") {
                    continue;
                }
                $student = $entry["student"];
                $line = (int) $entry["line"];
                $temporaryPassword = generateTemporaryStudentPassword($usedPasswords);
                try {
                    $conn->begin_transaction();
                    $userId = createRegistrarStudent($conn, $student, $temporaryPassword);
                    $conn->commit();
                    $summary["registered"]++;
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
                        $summary["duplicates"]++;
                        $summary["row_errors"][] = "Row {$line}: LRN or email became a duplicate during import.";
                    } else {
                        $summary["failed_registrations"]++;
                        $summary["row_errors"][] = "Row {$line}: student account could not be created.";
                        error_log("Registrar CSV student creation error on row {$line}: " . $e->getMessage());
                    }
                    unset($temporaryPassword);
                    continue;
                }

                try {
                    sendRegistrarStudentCredentials($student, $temporaryPassword);
                    $summary["emails_sent"]++;
                } catch (Throwable $e) {
                    $summary["failed_emails"]++;
                    $_SESSION["registrar_mail_retries"][] = [
                        "batch" => "pending",
                        "user_id" => $userId,
                        "email" => $student["email"],
                        "name" => trim(implode(" ", array_filter([$student["first_name"], $student["middle_name"], $student["last_name"], $student["suffix"]]))),
                        "lrn" => $student["lrn"]
                    ];
                    error_log("Registrar CSV credential email failed for user {$userId}: " . $e->getMessage());
                }
                unset($temporaryPassword);
            }
            registrarSaveSummary($summary);
            foreach ($_SESSION["registrar_mail_retries"] ?? [] as $index => $retry) {
                if (($retry["batch"] ?? "") === "pending") {
                    $_SESSION["registrar_mail_retries"][$index]["batch"] = $_SESSION["registrar_current_batch"];
                }
            }
            unset($_SESSION["registrar_csv_preview"]);
        }
    } elseif ($action === "manual_add") {
        unset($_SESSION["registrar_csv_preview"]);
        $preview = null;
        foreach (["lrn", "first_name", "middle_name", "last_name", "suffix", "email", "gender", "track_id", "section_id"] as $field) {
            $manual[$field] = trim((string) ($_POST[$field] ?? ""));
        }
        try {
            $student = validateRegistrarStudent($manual);
            validateRegistrarPlacement($conn, $student);
            if (registrarStudentAlreadyExists($conn, $student)) {
                throw new DomainException("That LRN or email address is already registered.");
            }
            $temporaryPassword = generateTemporaryStudentPassword();
            $conn->begin_transaction();
            $userId = createRegistrarStudent($conn, $student, $temporaryPassword);
            $conn->commit();
            $summary = registrarBuildSummary(1);
            $summary["registered"] = 1;
            try {
                sendRegistrarStudentCredentials($student, $temporaryPassword);
                $summary["emails_sent"] = 1;
            } catch (Throwable $e) {
                $summary["failed_emails"] = 1;
                $_SESSION["registrar_mail_retries"][] = [
                    "batch" => "pending", "user_id" => $userId, "email" => $student["email"],
                    "name" => trim(implode(" ", array_filter([$student["first_name"], $student["middle_name"], $student["last_name"], $student["suffix"]]))),
                    "lrn" => $student["lrn"]
                ];
                error_log("Registrar manual credential email failed for user {$userId}: " . $e->getMessage());
            }
            unset($temporaryPassword);
            registrarSaveSummary($summary);
            foreach ($_SESSION["registrar_mail_retries"] ?? [] as $index => $retry) {
                if (($retry["batch"] ?? "") === "pending") {
                    $_SESSION["registrar_mail_retries"][$index]["batch"] = $_SESSION["registrar_current_batch"];
                }
            }
            $manual = array_fill_keys(array_keys($manual), "");
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $pageErrors[] = $e instanceof InvalidArgumentException || $e instanceof DomainException
                ? $e->getMessage()
                : "Could not create the student account. Check the details and try again.";
            if (!$e instanceof InvalidArgumentException && !$e instanceof DomainException) {
                error_log("Registrar manual student creation error: " . $e->getMessage());
            }
        }
        if ($pageErrors) {
            $_SESSION["registrar_students_manual_values"] = $manual;
        }
    } elseif ($action === "retry_mail") {
        @set_time_limit(0);
        $requestedIds = $_POST["retry_user_ids"] ?? [];
        $requestedIds = is_array($requestedIds) ? array_values(array_unique(array_filter(array_map("intval", $requestedIds), static fn($id) => $id > 0))) : [];
        if (!$requestedIds) {
            $pageErrors[] = "Choose at least one failed email to retry.";
        } else {
            $retryRows = $_SESSION["registrar_mail_retries"] ?? [];
            $left = [];
            foreach ($retryRows as $retry) {
                $userId = (int) ($retry["user_id"] ?? 0);
                if (!in_array($userId, $requestedIds, true)) {
                    $left[] = $retry;
                    continue;
                }
                try {
                    $studentQuery = $conn->prepare(
                        "SELECT user_id, email, lrn, first_name, middle_name, last_name, suffix
                         FROM users WHERE user_id = ? AND role = 'Student' AND must_change_password = 1 LIMIT 1"
                    );
                    $studentQuery->bind_param("i", $userId);
                    $studentQuery->execute();
                    $student = $studentQuery->get_result()->fetch_assoc();
                    $studentQuery->close();
                    if (!$student) {
                        continue;
                    }
                    $temporaryPassword = generateTemporaryStudentPassword();
                    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    $reset = $conn->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ? AND role = 'Student'");
                    $reset->bind_param("si", $passwordHash, $userId);
                    $reset->execute();
                    $reset->close();
                    sendRegistrarStudentCredentials($student, $temporaryPassword);
                    unset($temporaryPassword);
                    $batch = (string) ($retry["batch"] ?? "");
                    if (isset($_SESSION["registrar_import_summaries"][$batch])) {
                        $_SESSION["registrar_import_summaries"][$batch]["emails_sent"]++;
                        $_SESSION["registrar_import_summaries"][$batch]["failed_emails"] = max(0, $_SESSION["registrar_import_summaries"][$batch]["failed_emails"] - 1);
                    }
                } catch (Throwable $e) {
                    unset($temporaryPassword);
                    $left[] = $retry;
                    $pageErrors[] = "Email retry failed for row {$userId}; the student can be retried again.";
                    error_log("Registrar credential email retry failed for user {$userId}: " . $e->getMessage());
                }
            }
            $_SESSION["registrar_mail_retries"] = $left;
        }
    } else {
        $pageErrors[] = "Choose a valid student creation option.";
    }

    $_SESSION["registrar_students_flash_errors"] = $pageErrors;
    $_SESSION["registrar_students_csrf"] = bin2hex(random_bytes(32));
    registrarRedirect();
}

$summary = null;
$currentBatch = (string) ($_SESSION["registrar_current_batch"] ?? "");
if ($currentBatch !== "") {
    $summary = $_SESSION["registrar_import_summaries"][$currentBatch] ?? null;
}
$preview = $_SESSION["registrar_csv_preview"] ?? null;
$pendingRetries = $_SESSION["registrar_mail_retries"] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('registrar/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#105334">
    <title>Add Students | Registrar | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body class="registrar-dashboard-page">
    <header class="registrar-header">
        <a class="registrar-brand" href="../registrar-dashboard"><img src="../logo.png" alt=""><span><strong>ST. PEREGRINE INSTITUTE</strong><small>Online Grading System</small></span></a>
        <nav class="registrar-header-actions" aria-label="Account navigation"><a href="../registrar-dashboard">Dashboard</a><a href="../logout.php">Sign out</a></nav>
    </header>
    <main class="registrar-main registrar-tools-main">
        <section class="registrar-welcome"><p class="registrar-eyebrow">REGISTRAR PORTAL</p><h1>Add students</h1><p>Temporary passwords are generated automatically, stored as hashes, and sent individually to each student's Gmail. Students must change the password before accessing the grading system.</p></section>
        <?php foreach ($pageErrors as $pageError): ?><p class="registrar-error" role="alert"><?= h($pageError) ?></p><?php endforeach; ?>

        <?php if (is_array($summary)): ?>
        <section class="registrar-summary-block" aria-labelledby="import-summary-title">
            <h2 id="import-summary-title">Import summary</h2>
            <div class="registrar-summary registrar-summary-grid">
                <article class="registrar-card"><span>Total records detected</span><strong><?= (int) $summary["total_records"] ?></strong></article>
                <article class="registrar-card"><span>Successfully registered</span><strong><?= (int) $summary["registered"] ?></strong></article>
                <article class="registrar-card"><span>Emails sent</span><strong><?= (int) $summary["emails_sent"] ?></strong></article>
                <article class="registrar-card"><span>Failed email deliveries</span><strong><?= (int) $summary["failed_emails"] ?></strong></article>
                <article class="registrar-card"><span>Duplicate records</span><strong><?= (int) $summary["duplicates"] ?></strong></article>
                <article class="registrar-card"><span>Invalid records</span><strong><?= (int) $summary["invalid"] ?></strong></article>
                <article class="registrar-card"><span>Failed registrations</span><strong><?= (int) $summary["failed_registrations"] ?></strong></article>
            </div>
            <?php if (!empty($summary["row_errors"])): ?><ul class="registrar-import-errors"><?php foreach ($summary["row_errors"] as $rowError): ?><li><?= h($rowError) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($preview): ?>
        <section class="registrar-next-step registrar-form-card registrar-preview-card">
            <div class="registrar-section-heading"><div><h2>Review CSV before adding</h2><p><?= (int) $preview["total"] ?> records · <?= (int) $preview["valid"] ?> ready · <?= (int) $preview["duplicates"] ?> duplicates · <?= (int) $preview["invalid"] ?> invalid</p></div></div>
            <div class="registrar-preview-table-wrap"><table class="registrar-preview-table"><thead><tr><th>Row</th><th>Student</th><th>LRN</th><th>Email</th><th>Result</th></tr></thead><tbody>
                <?php foreach ($preview["rows"] as $row): $student = $row["student"]; ?><tr><td><?= (int) $row["line"] ?></td><td><?= h(trim(implode(" ", array_filter([$student["first_name"] ?? "", $student["middle_name"] ?? "", $student["last_name"] ?? "", $student["suffix"] ?? ""])))) ?></td><td><?= h($student["lrn"] ?? "") ?></td><td><?= h($student["email"] ?? "") ?></td><td><span class="registrar-preview-status <?= h($row["status"]) ?>"><?= h(ucfirst($row["status"])) ?>: <?= h($row["reason"]) ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="registrar-preview-actions"><form action="../registrar-add-students" method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["registrar_students_csrf"]) ?>"><input type="hidden" name="action" value="confirm_csv"><button type="submit" <?= (int) $preview["valid"] === 0 ? "disabled" : "" ?>>Confirm and add <?= (int) $preview["valid"] ?> students</button></form><form action="../registrar-add-students" method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["registrar_students_csrf"]) ?>"><input type="hidden" name="action" value="cancel_preview"><button class="registrar-secondary-button" type="submit">Cancel preview</button></form></div>
        </section>
        <?php endif; ?>

        <?php if ($pendingRetries): ?>
        <section class="registrar-next-step registrar-retry-card">
            <div><h2>Retry failed notifications</h2><p><?= count($pendingRetries) ?> account<?= count($pendingRetries) === 1 ? "" : "s" ?> were created, but their credentials could not be emailed. Retrying generates a fresh temporary password and replaces the previous hash.</p></div>
            <form action="../registrar-add-students" method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["registrar_students_csrf"]) ?>"><input type="hidden" name="action" value="retry_mail"><?php foreach ($pendingRetries as $retry): ?><input type="hidden" name="retry_user_ids[]" value="<?= (int) $retry["user_id"] ?>"><?php endforeach; ?><button type="submit">Retry failed emails</button></form>
            <ul><?php foreach ($pendingRetries as $retry): ?><li><?= h($retry["name"]) ?> · <?= h($retry["email"]) ?></li><?php endforeach; ?></ul>
        </section>
        <?php endif; ?>

        <section class="registrar-next-step registrar-form-card">
            <div class="registrar-section-heading"><div><h2>Add one student</h2><p>Enter the student's details. A unique temporary password will be generated and sent to their Gmail.</p></div></div>
            <form action="../registrar-add-students" method="post" class="registrar-student-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["registrar_students_csrf"]) ?>"><input type="hidden" name="action" value="manual_add">
                <label>LRN<input name="lrn" inputmode="numeric" pattern="[0-9]{12,20}" maxlength="20" value="<?= h($manual["lrn"]) ?>" required></label>
                <label>First name<input name="first_name" maxlength="50" value="<?= h($manual["first_name"]) ?>" required></label>
                <label>Middle name<input name="middle_name" maxlength="50" value="<?= h($manual["middle_name"]) ?>"></label>
                <label>Last name<input name="last_name" maxlength="50" value="<?= h($manual["last_name"]) ?>" required></label>
                <label>Suffix<input name="suffix" maxlength="10" value="<?= h($manual["suffix"]) ?>"></label>
                <label>Gmail<input name="email" type="email" value="<?= h($manual["email"]) ?>" required></label>
                <label>Gender<select name="gender" required><option value="">Choose gender</option><?php foreach (["Female", "Male", "Prefer not to say"] as $gender): ?><option value="<?= h($gender) ?>" <?= $manual["gender"] === $gender ? "selected" : "" ?>><?= h($gender) ?></option><?php endforeach; ?></select></label>
                <label>Track / strand<select name="track_id" class="registrar-track-select" required><option value="">Choose a track</option><?php foreach ($tracks as $track): ?><option value="<?= (int) $track["track_id"] ?>" <?= $manual["track_id"] === (string) $track["track_id"] ? "selected" : "" ?>><?= h($track["track_name"] . " — " . $track["strand_name"]) ?></option><?php endforeach; ?></select></label>
                <label>Section<select name="section_id" class="registrar-section-select" required><option value="">Choose a section</option><?php foreach ($sections as $section): ?><option value="<?= (int) $section["section_id"] ?>" data-track="<?= (int) $section["track_id"] ?>" <?= $manual["section_id"] === (string) $section["section_id"] ? "selected" : "" ?>>Grade <?= (int) $section["grade_level"] ?> · <?= h($section["section_name"]) ?></option><?php endforeach; ?></select></label>
                <div class="registrar-form-submit"><button type="submit">Create student and email credentials</button></div>
            </form>
        </section>

        <section class="registrar-next-step registrar-form-card registrar-csv-card">
            <div class="registrar-section-heading"><div><h2>Bulk add from CSV</h2><p>Preview the records before creating accounts. Each row is handled separately; invalid or duplicate rows do not block other rows.</p></div><a href="../registrar-add-students?download=template">Download CSV template</a></div>
            <form action="../registrar-add-students" method="post" enctype="multipart/form-data" class="registrar-csv-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["registrar_students_csrf"]) ?>"><input type="hidden" name="action" value="preview_csv">
                <label>CSV file<input type="file" name="students_csv" accept=".csv,text/csv" required><small>Maximum 2 MB and 500 students. Required headers: lrn, first_name, last_name, email, gender, track_id, section_id. middle_name and suffix are optional. The system generates passwords automatically.</small></label>
                <button type="submit">Preview CSV</button>
            </form>
            <details class="registrar-id-guide"><summary>Track and section IDs for the CSV</summary><div class="registrar-id-columns"><div><h3>Tracks</h3><ul><?php foreach ($tracks as $track): ?><li><strong><?= (int) $track["track_id"] ?></strong> · <?= h($track["track_name"] . " — " . $track["strand_name"]) ?></li><?php endforeach; ?></ul></div><div><h3>Active sections</h3><ul><?php foreach ($sections as $section): ?><li><strong><?= (int) $section["section_id"] ?></strong> · Grade <?= (int) $section["grade_level"] ?> · <?= h($section["section_name"]) ?></li><?php endforeach; ?></ul></div></div></details>
        </section>
    </main>
    <script>
        const trackSelect = document.querySelector(".registrar-track-select");
        const sectionSelect = document.querySelector(".registrar-section-select");
        if (trackSelect && sectionSelect) {
            const updateSections = () => {
                for (const option of sectionSelect.options) {
                    if (!option.value) continue;
                    option.hidden = option.dataset.track !== trackSelect.value;
                    if (option.selected && option.hidden) sectionSelect.value = "";
                }
            };
            trackSelect.addEventListener("change", updateSections);
            updateSections();
        }
    </script>
</body>
</html>
