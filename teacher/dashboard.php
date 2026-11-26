<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/messaging_schema.php";
require_once __DIR__ . "/../includes/teacher_schema.php";
require_once __DIR__ . "/../includes/enrollment_schema.php";
ensureEnrollmentStatusSchema($conn);

$userId = (int) $_SESSION["user_id"];
$teacherUnreadMessageCount = 0;
try {
    ensureStudentTeacherMessagesSchema($conn);
    $teacherUnreadMessageCount = getUnreadTeacherMessageCount($conn, $userId);
} catch (mysqli_sql_exception $messageSchemaError) {
    error_log("Teacher message badge unavailable: " . $messageSchemaError->getMessage());
}
$manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$schoolYearStart = (int) $manilaNow->format("Y");
if ((int) $manilaNow->format("n") < 6) {
    $schoolYearStart--;
}
$schoolYear = $schoolYearStart . "-" . ($schoolYearStart + 1);
$sections = [];
$classes = [];
$schoolEnrolledStudentCount = 0;
$teacherStudentCount = 0;
$teacherProfile = null;
$calendarEvents = [];
$advisoryStudents = [];
$error = "";
$profileError = "";
$profileFieldOptions = [
    "first_name" => ["label" => "First name", "table" => "users", "column" => "first_name", "required" => true],
    "middle_name" => ["label" => "Middle name", "table" => "users", "column" => "middle_name", "required" => false],
    "last_name" => ["label" => "Last name", "table" => "users", "column" => "last_name", "required" => true],
    "suffix" => ["label" => "Suffix", "table" => "users", "column" => "suffix", "required" => false],
    "contact_phone" => ["label" => "Contact number", "table" => "teachers", "column" => "contact_phone", "required" => false],
    "email" => ["label" => "Email", "table" => "users", "column" => "email", "required" => true],
    "gender" => ["label" => "Gender", "table" => "teachers", "column" => "gender", "required" => true],
    "civil_status" => ["label" => "Civil status", "table" => "teachers", "column" => "civil_status", "required" => false],
    "nationality" => ["label" => "Nationality", "table" => "teachers", "column" => "nationality", "required" => false],
    "address" => ["label" => "Address", "table" => "teachers", "column" => "address", "required" => false]
];

try {
    ensureTeacherMustChangePasswordColumn($conn);
    ensureTeacherGenderColumn($conn);
    ensureTeacherProfilePhotoColumn($conn);
    ensureTeacherPersonalInfoColumns($conn);
    $temporaryPasswordQuery = $conn->prepare("SELECT must_change_password FROM users WHERE user_id = ? LIMIT 1");
    $temporaryPasswordQuery->bind_param("i", $userId);
    $temporaryPasswordQuery->execute();
    $temporaryPasswordAccount = $temporaryPasswordQuery->get_result()->fetch_assoc();
    $temporaryPasswordQuery->close();
    if ($temporaryPasswordAccount) {
        $mustChangePassword = (int) $temporaryPasswordAccount["must_change_password"] === 1;
        $_SESSION["must_change_password"] = $mustChangePassword;
    } else {
        $mustChangePassword = !empty($_SESSION["must_change_password"]);
    }
} catch (mysqli_sql_exception $schemaError) {
    error_log("Teacher gender column setup error: " . $schemaError->getMessage());
    $error = "We could not prepare your profile information right now.";
    $mustChangePassword = !empty($_SESSION["must_change_password"]);
}

if ($mustChangePassword) {
    if (!isset($_SESSION["teacher_force_password_csrf"])) {
        $_SESSION["teacher_force_password_csrf"] = bin2hex(random_bytes(32));
    }
    $forcePasswordError = "";
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "force_change_temporary_password") {
        $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
        $newPassword = is_string($_POST["new_password"] ?? null) ? $_POST["new_password"] : "";
        $confirmPassword = is_string($_POST["confirm_password"] ?? null) ? $_POST["confirm_password"] : "";
        if (!hash_equals($_SESSION["teacher_force_password_csrf"], $token)) {
            $forcePasswordError = "The form expired. Refresh the page and try again.";
        } elseif ($newPassword !== $confirmPassword) {
            $forcePasswordError = "The new passwords do not match.";
        } elseif (
            strlen($newPassword) < 8 || strlen($newPassword) > 255 ||
            !preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[^A-Za-z0-9\\s]/', $newPassword)
        ) {
            $forcePasswordError = "Use at least 8 characters with uppercase and lowercase letters and a special character.";
        } else {
            $forceChangeTransaction = false;
            try {
                $conn->begin_transaction();
                $forceChangeTransaction = true;
                $currentPasswordQuery = $conn->prepare("SELECT password, must_change_password FROM users WHERE user_id = ? FOR UPDATE");
                $currentPasswordQuery->bind_param("i", $userId);
                $currentPasswordQuery->execute();
                $currentAccount = $currentPasswordQuery->get_result()->fetch_assoc();
                $currentPasswordQuery->close();
                if (!$currentAccount || (int) $currentAccount["must_change_password"] !== 1) {
                    throw new DomainException("Your password has already been changed. Please sign in again.");
                }
                if (password_verify($newPassword, $currentAccount["password"])) {
                    throw new DomainException("Choose a new password different from your current password.");
                }
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $saveNewPassword = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ? AND must_change_password = 1");
                $saveNewPassword->bind_param("si", $newPasswordHash, $userId);
                $saveNewPassword->execute();
                if ($saveNewPassword->affected_rows !== 1) throw new DomainException("Your password has already been changed. Please sign in again.");
                $saveNewPassword->close();
                $conn->commit();
                $forceChangeTransaction = false;
                session_regenerate_id(true);
                $_SESSION["must_change_password"] = false;
                unset($_SESSION["teacher_force_password_csrf"]);
                header("Location: " . rolePageUrl("teacher", "dashboard.php") . "?password_changed=1");
                exit;
            } catch (DomainException $e) {
                if ($forceChangeTransaction) $conn->rollback();
                $forcePasswordError = $e->getMessage();
            } catch (Throwable $e) {
                if ($forceChangeTransaction) $conn->rollback();
                error_log("Forced teacher password change failed: " . $e->getMessage());
                $forcePasswordError = "We could not save your new password right now. Please try again.";
            }
        }
    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $forcePasswordError = "Set a new password before continuing.";
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Set a new teacher password.">
        <meta name="theme-color" content="#105334">
        <title>Set New Password | St. Peregrine Institute</title>
        <link rel="icon" type="image/png" href="../logo.png">
        <link rel="stylesheet" href="../css/dashboard.css">
        <link rel="stylesheet" href="css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/css/dashboard.css") ?>">
        <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
    </head>
    <body class="teacher-dashboard-page force-password-page">
        <header class="topbar">
            <a class="brand" href="../teacher-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
            <div class="nav-menu">
                <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                    <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 1.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                    <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
                </button>
                <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
            </div>
        </header>
        <main class="dashboard force-password-main">
            <div class="dashboard-modal-overlay force-password-overlay">
                <section class="dashboard-form-modal force-password-modal" role="dialog" aria-modal="true" aria-labelledby="force-password-title" aria-describedby="force-password-description">
                    <header class="dashboard-form-modal-heading"><div><h2 id="force-password-title">Set a new password</h2><p id="force-password-description">For security, choose a new password before continuing to your dashboard.</p></div></header>
                    <?php 
if ($forcePasswordError !== ""): ?><p class="force-password-error" role="alert"><?= h($forcePasswordError) ?></p><?php 
endif; ?>
                    <form action="../teacher-dashboard" class="force-password-form" method="post">
                        <input type="hidden" name="action" value="force_change_temporary_password">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_force_password_csrf"]) ?>">
                        <label>New password
                            <span class="force-password-box"><input id="force-new-password" type="password" name="new_password" minlength="8" maxlength="255" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9\s]).{8,255}" title="Use 8–255 characters with uppercase and lowercase letters and a special character." autocomplete="new-password" required autofocus><button class="force-password-toggle" type="button" data-target="force-new-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="m3 3 18 18"></path></svg></button></span>
                            <small>At least 8 characters, with uppercase and lowercase letters and a special character.</small>
                            <div class="force-password-meter" aria-live="polite"><span class="force-password-meter-track"><span id="force-password-meter-fill"></span></span><span id="force-password-meter-label">Password strength</span></div>
                        </label>
                        <label>Confirm new password
                            <span class="force-password-box"><input id="force-confirm-password" type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required><button class="force-password-toggle" type="button" data-target="force-confirm-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="m3 3 18 18"></path></svg></button></span>
                        </label>
                        <button class="force-password-submit" type="submit">Save new password</button>
                    </form>
                </section>
            </div>
        </main>
        <script>
            document.body.classList.add("dashboard-modal-open");
            document.querySelectorAll(".force-password-toggle").forEach(function (button) {
                button.addEventListener("click", function () {
                    const input = document.getElementById(button.dataset.target);
                    const showPassword = input.type === "password";
                    input.type = showPassword ? "text" : "password";
                    button.setAttribute("aria-pressed", String(showPassword));
                    button.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
                    button.classList.toggle("is-visible", showPassword);
                });
            });
            const newPasswordInput = document.getElementById("force-new-password");
            newPasswordInput.addEventListener("input", function () {
                const value = newPasswordInput.value;
                let score = 0;
                if (value.length >= 8) score++;
                if (/[a-z]/.test(value)) score++;
                if (/[A-Z]/.test(value)) score++;
                if (/[^A-Za-z0-9\s]/.test(value)) score++;
                document.getElementById("force-password-meter-fill").style.width = (score * 25) + "%";
                document.getElementById("force-password-meter-label").textContent = score === 0 ? "Password strength" : ["Weak", "Fair", "Good", "Strong"][score - 1];
            });
        </script>
    </body>
    </html>
    <?php
    
exit;
}

if (!isset($_SESSION["teacher_dashboard_csrf"])) {
    $_SESSION["teacher_dashboard_csrf"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload_profile_photo") {
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $upload = $_FILES["profile_photo"] ?? null;

    if (!hash_equals($_SESSION["teacher_dashboard_csrf"], $token)) {
        $profileError = "The form expired. Refresh the page and try again.";
    } elseif (!is_array($upload) || ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $profileError = "Choose a photo to upload.";
    } elseif (($upload["size"] ?? 0) > 5 * 1024 * 1024) {
        $profileError = "Choose an image smaller than 5 MB.";
    } else {
        $imageInfo = @getimagesize($upload["tmp_name"] ?? "");
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload["tmp_name"] ?? "");
        $photoExtensions = ["image/jpeg" => "jpg"];

        if (!$imageInfo || !isset($photoExtensions[$mimeType])) {
            $profileError = "We could not process this image. Choose a JPG, PNG, or WebP photo.";
        } else {
            $uploadDirectory = __DIR__ . "/uploads";
            if (!is_dir($uploadDirectory)) {
                mkdir($uploadDirectory, 0755, true);
            }
            $photoName = "teacher-" . $userId . "-" . bin2hex(random_bytes(12)) . "." . $photoExtensions[$mimeType];
            $photoPath = $uploadDirectory . DIRECTORY_SEPARATOR . $photoName;

            if (!move_uploaded_file($upload["tmp_name"], $photoPath)) {
                $profileError = "We could not save that photo. Please try again.";
            } else {
                try {
                    $photoUpdate = $conn->prepare("UPDATE teachers SET profile_photo = ? WHERE user_id = ?");
                    $photoUpdate->bind_param("si", $photoName, $userId);
                    $photoUpdate->execute();
                    $photoUpdate->close();
                    $_SESSION["teacher_dashboard_csrf"] = bin2hex(random_bytes(32));
                    header("Location: " . rolePageUrl("teacher", "dashboard.php") . "?photo_saved=1");
                    exit;
                } catch (mysqli_sql_exception $photoError) {
                    @unlink($photoPath);
                    error_log("Teacher profile photo update error: " . $photoError->getMessage());
                    $profileError = "We could not save that photo right now.";
                }
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_profile_field") {
    $field = is_string($_POST["profile_field"] ?? null) ? $_POST["profile_field"] : "";
    $value = is_string($_POST["profile_value"] ?? null) ? trim($_POST["profile_value"]) : "";
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";

    if (!hash_equals($_SESSION["teacher_dashboard_csrf"], $token)) {
        $profileError = "The form expired. Refresh the page and try again.";
    } elseif (!isset($profileFieldOptions[$field])) {
        $profileError = "Choose a valid profile field.";
    } elseif ($profileFieldOptions[$field]["required"] && $value === "") {
        $profileError = "This information is required.";
    } elseif (in_array($field, ["first_name", "middle_name", "last_name"], true) && strlen($value) > 50) {
        $profileError = "Names must be 50 characters or fewer.";
    } elseif ($field === "suffix" && strlen($value) > 10) {
        $profileError = "Suffix must be 10 characters or fewer.";
    } elseif ($field === "email" && (
        !filter_var($value, FILTER_VALIDATE_EMAIL) ||
        !preg_match("/@gmail\\.com$/i", $value) ||
        strlen($value) > 100
    )) {
        $profileError = "Enter a valid Gmail address (up to 100 characters).";
    } elseif ($field === "contact_phone" && $value !== "" &&
        (!preg_match('/^[0-9+() .-]+$/', $value) || strlen($value) > 20)
    ) {
        $profileError = "Enter a valid phone number (up to 20 characters).";
    } elseif ($field === "gender" && !in_array($value, ["Female", "Male", "Prefer not to say"], true)) {
        $profileError = "Choose a valid gender option.";
    } elseif ($field === "civil_status" && $value !== "" && !in_array($value, ["Single", "Married", "Widowed", "Separated", "Divorced"], true)) {
        $profileError = "Choose a valid civil status.";
    } elseif ($field === "nationality" && strlen($value) > 80) {
        $profileError = "Nationality must be 80 characters or fewer.";
    } elseif ($field === "address" && strlen($value) > 255) {
        $profileError = "Address must be 255 characters or fewer.";
    } else {
        try {
            $column = $profileFieldOptions[$field]["column"];
            $table = $profileFieldOptions[$field]["table"];
            $storedValue = $value === "" ? null : $value;
            $updateProfile = $conn->prepare("UPDATE {$table} SET {$column} = ? WHERE user_id = ?");
            $updateProfile->bind_param("si", $storedValue, $userId);
            $updateProfile->execute();
            $updateProfile->close();

            if ($field === "first_name" || $field === "last_name") {
                $nameQuery = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $nameQuery->bind_param("i", $userId);
                $nameQuery->execute();
                $updatedName = $nameQuery->get_result()->fetch_assoc();
                $nameQuery->close();
                if ($updatedName) {
                    $_SESSION["name"] = trim($updatedName["first_name"] . " " . $updatedName["last_name"]);
                }
            }

            $_SESSION["teacher_dashboard_csrf"] = bin2hex(random_bytes(32));
            header("Location: " . rolePageUrl("teacher", "dashboard.php") . "?profile_saved=1");
            exit;
        } catch (mysqli_sql_exception $updateError) {
            error_log("Teacher dashboard profile update error: " . $updateError->getMessage());
            $profileError = $updateError->getCode() === 1062
                ? "That email address is already in use."
                : "We could not save this information right now.";
        }
    }
}

try {
    $sectionQuery = $conn->prepare(
        "SELECT sections.section_id, sections.section_name, sections.grade_level,
                COUNT(DISTINCT users.user_id) AS student_count
         FROM teachers
         JOIN sections ON sections.teacher_id = teachers.teacher_id
         LEFT JOIN enrollments
            ON enrollments.section_id = sections.section_id
            AND enrollments.status = 'Enrolled'
         LEFT JOIN students ON students.student_id = enrollments.student_id
         LEFT JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE teachers.user_id = ?
         GROUP BY sections.section_id, sections.section_name, sections.grade_level
         ORDER BY sections.grade_level, sections.section_name"
    );
    $sectionQuery->bind_param("i", $userId);
    $sectionQuery->execute();
    $sections = $sectionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionQuery->close();

    $advisoryStudentsQuery = $conn->prepare(
        "SELECT sections.section_id, sections.section_name, sections.grade_level,
                users.first_name, users.middle_name, users.last_name
         FROM teachers
         JOIN sections ON sections.teacher_id = teachers.teacher_id
         LEFT JOIN enrollments ON enrollments.section_id = sections.section_id
            AND enrollments.school_year = ? AND enrollments.status = 'Enrolled'
         LEFT JOIN students ON students.student_id = enrollments.student_id
         LEFT JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE teachers.user_id = ?
         ORDER BY users.last_name ASC, users.first_name ASC, users.middle_name ASC,
                  sections.grade_level, sections.section_name"
    );
    $advisoryStudentsQuery->bind_param("si", $schoolYear, $userId);
    $advisoryStudentsQuery->execute();
    $advisoryStudentRows = $advisoryStudentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $advisoryStudentsQuery->close();
    foreach ($advisoryStudentRows as $advisoryStudentRow) {
        if ($advisoryStudentRow["first_name"] === null) {
            continue;
        }
        $advisoryStudents[] = $advisoryStudentRow;
    }

    $classQuery = $conn->prepare(
        "SELECT class_subjects.section_id, sections.section_name, sections.grade_level,
                subjects.subject_name, class_subjects.school_year
         FROM teachers
         JOIN class_subjects ON class_subjects.teacher_id = teachers.teacher_id
         JOIN sections ON sections.section_id = class_subjects.section_id
         JOIN subjects ON subjects.subject_id = class_subjects.subject_id
         WHERE teachers.user_id = ?
         ORDER BY class_subjects.school_year DESC,
                  sections.grade_level, sections.section_name, subjects.subject_name"
    );
    $classQuery->bind_param("i", $userId);
    $classQuery->execute();
    $classes = $classQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $classQuery->close();

    $schoolStudentCountQuery = $conn->prepare(
        "SELECT COUNT(DISTINCT enrollments.student_id) AS student_count
         FROM enrollments
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE enrollments.school_year = ? AND enrollments.status = 'Enrolled'"
    );
    $schoolStudentCountQuery->bind_param("s", $schoolYear);
    $schoolStudentCountQuery->execute();
    $schoolStudentCountRow = $schoolStudentCountQuery->get_result()->fetch_assoc();
    $schoolEnrolledStudentCount = (int) ($schoolStudentCountRow["student_count"] ?? 0);
    $schoolStudentCountQuery->close();

    $teacherStudentCountQuery = $conn->prepare(
        "SELECT COUNT(DISTINCT enrollments.student_id) AS student_count
         FROM teachers
         JOIN sections
            ON sections.teacher_id = teachers.teacher_id
            OR sections.section_id IN (
                SELECT assigned_classes.section_id
                FROM class_subjects AS assigned_classes
                WHERE assigned_classes.teacher_id = teachers.teacher_id
                  AND assigned_classes.school_year = ?
            )
         JOIN enrollments
            ON enrollments.section_id = sections.section_id
            AND enrollments.school_year = ?
            AND enrollments.status = 'Enrolled'
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE teachers.user_id = ?"
    );
    $teacherStudentCountQuery->bind_param("ssi", $schoolYear, $schoolYear, $userId);
    $teacherStudentCountQuery->execute();
    $teacherStudentCountRow = $teacherStudentCountQuery->get_result()->fetch_assoc();
    $teacherStudentCount = (int) ($teacherStudentCountRow["student_count"] ?? 0);
    $teacherStudentCountQuery->close();

    try {
        $profileQuery = $conn->prepare(
            "SELECT first_name, last_name, email
             FROM users
             WHERE user_id = ?"
        );
        $profileQuery->bind_param("i", $userId);
        $profileQuery->execute();
        $teacherProfile = $profileQuery->get_result()->fetch_assoc();
        $profileQuery->close();

        if ($teacherProfile) {
            try {
                $extraProfileQuery = $conn->prepare(
                    "SELECT middle_name, suffix FROM users WHERE user_id = ?"
                );
                $extraProfileQuery->bind_param("i", $userId);
                $extraProfileQuery->execute();
                $extraProfile = $extraProfileQuery->get_result()->fetch_assoc();
                $extraProfileQuery->close();
                $teacherProfile = array_merge($teacherProfile, $extraProfile ?: []);
            } catch (mysqli_sql_exception $extraProfileError) {
                error_log("Teacher dashboard optional name fields error: " . $extraProfileError->getMessage());
            }

            try {
                $teacherDetailsQuery = $conn->prepare(
                    "SELECT contact_phone, gender, civil_status, nationality, address, profile_photo
                     FROM teachers WHERE user_id = ?"
                );
                $teacherDetailsQuery->bind_param("i", $userId);
                $teacherDetailsQuery->execute();
                $teacherDetails = $teacherDetailsQuery->get_result()->fetch_assoc();
                $teacherDetailsQuery->close();
                $teacherProfile = array_merge($teacherProfile, $teacherDetails ?: []);
            } catch (mysqli_sql_exception $teacherDetailsError) {
                error_log("Teacher dashboard optional contact fields error: " . $teacherDetailsError->getMessage());
            }
        }
    } catch (mysqli_sql_exception $profileError) {
        error_log("Teacher dashboard profile summary error: " . $profileError->getMessage());
    }

    try {
        $schoolCalendarResult = $conn->query(
            "SELECT event_date AS assessment_date, event_type AS subject_name,
                    title AS assessment_name, description AS event_description
             FROM school_calendar_events
             WHERE status = 'Active'
             ORDER BY event_date, title"
        );
        $calendarEvents = $schoolCalendarResult->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $calendarError) {
        error_log("Teacher school calendar events unavailable: " . $calendarError->getMessage());
    }
} catch (mysqli_sql_exception $e) {
    error_log("Teacher dashboard error: " . $e->getMessage());
    $error = "We could not load your teaching information right now.";
}

$teacherName = $_SESSION["name"] ?? "Teacher";
$teacherHonorific = match ($teacherProfile["gender"] ?? "") {
    "Male" => "Sir",
    "Female" => "Ma'am",
    default => ""
};
$advisorySection = count($sections) > 0
    ? $sections[0]["section_name"]
    : "Not assigned";
$handledSectionIds = array_unique(array_column($classes, "section_id"));
$handledSectionCount = count($handledSectionIds);
if (($_GET["download"] ?? "") === "advisory_students") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"advisory-students-" . str_replace("-", "_", $schoolYear) . ".csv\"");
    $csvOutput = fopen("php://output", "wb");
    fwrite($csvOutput, "\xEF\xBB\xBF");
    fputcsv($csvOutput, ["No.", "First Name", "Middle Name", "Last Name", "Grade Level", "Section"]);
    foreach ($advisoryStudents as $index => $student) {
        fputcsv($csvOutput, [$index + 1, $student["first_name"], $student["middle_name"] ?? "", $student["last_name"], $student["grade_level"], $student["section_name"]]);
    }
    fclose($csvOutput);
    exit;
}
$profileFields = [
    "Nationality" => $teacherProfile["nationality"] ?? "",
    "Civil status" => $teacherProfile["civil_status"] ?? "",
    "Address" => $teacherProfile["address"] ?? "",
    "Contact number" => $teacherProfile["contact_phone"] ?? "",
    "Email" => $teacherProfile["email"] ?? "",
];
$showProfileDialogError = $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "update_profile_field" && $profileError !== "";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="View your classes and teaching information in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Teacher Dashboard | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/css/dashboard.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="teacher-dashboard-page">
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
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user">
                    <strong><?= h($_SESSION["name"] ?? "User") ?></strong>
                    <span>Teacher</span>
                </div>
                <a class="menu-link" href="../teacher-dashboard">Dashboard</a>
                <a class="menu-link" href="../teacher-sections">Sections</a>
                <a class="menu-link" href="../teacher-messages">Messages <span class="nav-unread-count" data-teacher-unread-count<?= $teacherUnreadMessageCount > 0 ? "" : " hidden" ?>><?= $teacherUnreadMessageCount > 99 ? "99+" : $teacherUnreadMessageCount ?></span></a>
                <a class="menu-link" href="../teacher-profile">My Profile</a>
                <a class="menu-link signout-link" href="../logout.php">Sign out</a>
            </nav>
        </div>
    </header>

    <main class="dashboard teacher-dashboard">
        <div class="dashboard-welcome-banner">
            <div class="dashboard-welcome-copy">
                <p class="dashboard-welcome-greeting">TEACHER DASHBOARD</p>
                <h1>Welcome, <?= $teacherHonorific !== "" ? h($teacherHonorific) . " " : "" ?><?= h($teacherName) ?></h1>
            </div>
        </div>

        <?php 
if ($error !== ""): ?>
            <p class="alert error"><?= h($error) ?></p>
        <?php 
else: ?>
            <?php 
if (isset($_GET["profile_saved"])): ?>
                <p class="alert success">Profile information saved.</p>
            <?php 
endif; ?>
            <?php 
if (isset($_GET["photo_saved"])): ?>
                <p class="alert success">Profile photo updated.</p>
            <?php 
endif; ?>
            <?php 
if ($profileError !== ""): ?>
                <p class="alert error"><?= h($profileError) ?></p>
            <?php 
endif; ?>
            <section class="summary-grid teacher-summary-grid">
                <article class="summary-card">
                    <div class="teacher-summary-card-heading"><span class="teacher-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20v-1.5A4.5 4.5 0 0 1 8.5 14h3a4.5 4.5 0 0 1 4.5 4.5V20M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7-7.5a4 4 0 0 1 0 7.7M19 14a4.5 4.5 0 0 1 3 4.3V20"></path></svg></span><span class="summary-label">Advisory class</span></div>
                    <strong><?= h($advisorySection) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="teacher-summary-card-heading"><span class="teacher-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg></span><span class="summary-label">School year</span></div>
                    <strong><?= h($schoolYear) ?></strong>
                </article>
                <article class="summary-card">
                    <div class="teacher-summary-card-heading"><span class="teacher-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20v-1a5.5 5.5 0 0 1 11 0v1M16 5.5a3 3 0 0 1 0 5.8M17 14a4 4 0 0 1 3.5 4v2"></path></svg></span><span class="summary-label">Students handled</span></div>
                    <strong><?= $teacherStudentCount ?></strong>
                </article>
                <article class="summary-card">
                    <div class="teacher-summary-card-heading"><span class="teacher-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V8l7-4 7 4v12M9 20v-5h6v5M8 10h.01M12 10h.01M16 10h.01"></path></svg></span><span class="summary-label">School enrolled students</span></div>
                    <strong><?= $schoolEnrolledStudentCount ?></strong>
                </article>
            </section>

            <section class="teacher-dashboard-panels">
                <article class="teacher-top-students-board" aria-labelledby="advisory-students-title">
                    <header class="teacher-top-students-heading">
                        <div><p class="eyebrow">ADVISORY CLASS</p><h2 id="advisory-students-title">Student list</h2></div>
                        <div class="teacher-advisory-board-actions">
                            <span class="teacher-top-students-count"><?= count($advisoryStudents) ?> students</span>
                            <?php 
if (count($sections) > 0): ?>
                                <div class="teacher-advisory-actions-row">
                                    <a class="teacher-advisory-download" href="../teacher-dashboard?download=advisory_students">Download list</a>
                                </div>
                            <?php 
endif; ?>
                        </div>
                    </header>
                    <?php 
if ($advisoryStudents): ?>
                        <ol class="teacher-top-students-list teacher-advisory-students-list" tabindex="0" aria-label="Students in your advisory class">
                            <?php 
foreach ($advisoryStudents as $index => $advisoryStudent): ?>
                                <?php 
$advisoryLastName = trim($advisoryStudent["last_name"] ?? ""); $advisoryGivenNames = trim($advisoryStudent["first_name"] . " " . ($advisoryStudent["middle_name"] ?? "")); $advisoryDisplayName = $advisoryLastName . ($advisoryGivenNames !== "" ? ", " . $advisoryGivenNames : ""); ?>
                                <li class="teacher-top-student-row teacher-advisory-student-row">
                                    <span class="teacher-top-student-rank"><?= $index + 1 ?></span>
                                    <div class="teacher-top-student-info"><strong><?= h($advisoryDisplayName) ?></strong><span><?= h($advisoryStudent["section_name"]) ?></span></div>
                                </li>
                            <?php 
endforeach; ?>
                        </ol>
                    <?php 
else: ?>
                        <p class="teacher-top-students-empty"><?= count($sections) > 0 ? "No students are enrolled in your advisory class this school year." : "No advisory class has been assigned to you." ?></p>
                    <?php 
endif; ?>
                </article>

                <section class="panel school-calendar-panel" aria-labelledby="school-calendar-title">
                    <div class="panel-heading"><div><h2 id="school-calendar-title">School calendar</h2><p>School events schedule</p></div></div>
                    <div class="school-calendar-content">
                        <section class="calendar-feature" aria-live="polite" aria-label="Upcoming school event">
                            <div class="calendar-feature-month" id="teacher-calendar-month-label"></div>
                            <div class="calendar-feature-body">
                                <button class="calendar-event-nav" id="teacher-calendar-previous-event" type="button" aria-label="Previous event">‹</button>
                                <div class="calendar-feature-details">
                                    <strong class="calendar-feature-day" id="teacher-calendar-event-day">—</strong>
                                    <span class="calendar-feature-title" id="teacher-calendar-event-title">No upcoming events</span>
                                    <span class="calendar-feature-meta" id="teacher-calendar-event-meta"></span>
                                </div>
                                <button class="calendar-event-nav" id="teacher-calendar-next-event" type="button" aria-label="Next event">›</button>
                            </div>
                        </section>
                        <script type="application/json" id="teacher-school-calendar-data"><?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                    </div>
                </section>

            </section>
        <?php 
endif; ?>
    </main>
    <script>
        (function () {
            const dataNode = document.getElementById("teacher-school-calendar-data");
            const monthLabel = document.getElementById("teacher-calendar-month-label");
            if (!dataNode || !monthLabel) return;
            const events = JSON.parse(dataNode.textContent || "[]");
            const dayLabel = document.getElementById("teacher-calendar-event-day");
            const titleLabel = document.getElementById("teacher-calendar-event-title");
            const metaLabel = document.getElementById("teacher-calendar-event-meta");
            const eventDates = Array.from(new Set(events.map(function (event) {
                return event.assessment_date;
            }).filter(Boolean))).sort();
            const now = new Date();
            const todayKey = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
            let eventDateIndex = eventDates.findIndex(function (dateKey) { return dateKey >= todayKey; });
            if (eventDateIndex < 0) eventDateIndex = eventDates.length ? eventDates.length - 1 : 0;
            function renderEventCard() {
                const dateKey = eventDates[eventDateIndex];
                const date = dateKey ? new Date(dateKey + "T00:00:00") : new Date();
                const dayEvents = dateKey ? events.filter(function (event) { return event.assessment_date === dateKey; }) : [];
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
            document.getElementById("teacher-calendar-previous-event").addEventListener("click", function () {
                if (eventDates.length) eventDateIndex = (eventDateIndex - 1 + eventDates.length) % eventDates.length;
                renderEventCard();
            });
            document.getElementById("teacher-calendar-next-event").addEventListener("click", function () {
                if (eventDates.length) eventDateIndex = (eventDateIndex + 1) % eventDates.length;
                renderEventCard();
            });
            renderEventCard();
        }());
    </script>
</body>
</html>
