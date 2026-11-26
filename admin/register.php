<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/teacher_schema.php";

try {
    ensureTeacherMustChangePasswordColumn($conn);
    ensureTeacherGenderColumn($conn);
} catch (mysqli_sql_exception $schemaError) {
    error_log("Teacher registration schema setup error: " . $schemaError->getMessage());
}

if (!isset($_SESSION["teacher_registration_csrf"])) {
    $_SESSION["teacher_registration_csrf"] = bin2hex(random_bytes(32));
}

function generateTeacherTemporaryPassword(): string
{
    $upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $lower = "abcdefghijklmnopqrstuvwxyz";
    $digits = "0123456789";
    $special = "!@#$%&*?";
    $all = $upper . $lower . $digits . $special;
    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $special[random_int(0, strlen($special) - 1)]
    ];
    for ($i = 0; $i < 5; $i++) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode("", $chars);
}
$error = "";
$success = "";
$values = [
    "first_name" => "",
    "middle_name" => "",
    "last_name" => "",
    "suffix" => "",
    "gender" => "",
    "email" => "",
    "contact_phone" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($values as $field => $unused) {
        $values[$field] = trim($_POST[$field] ?? "");
    }

    $password = generateTeacherTemporaryPassword();
    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["teacher_registration_csrf"], $token)) {
        $error = "The form expired. Refresh the page and try again.";
    } elseif ($values["first_name"] === "" || $values["last_name"] === "") {
        $error = "Enter your first and last name.";
    } elseif (
        strlen($values["first_name"]) > 50 ||
        strlen($values["middle_name"]) > 50 ||
        strlen($values["last_name"]) > 50 ||
        strlen($values["suffix"]) > 10
    ) {
        $error = "One of the name fields is too long.";
    } elseif (!in_array($values["gender"], ["Female", "Male", "Prefer not to say"], true)) {
        $error = "Choose a gender option.";
    } elseif (
        !filter_var($values["email"], FILTER_VALIDATE_EMAIL) ||
        !preg_match("/@gmail\\.com$/i", $values["email"]) ||
        strlen($values["email"]) > 100
    ) {
        $error = "Enter a valid Gmail address (up to 100 characters).";
    } elseif (
        $values["contact_phone"] !== "" &&
        (!preg_match('/^[0-9+() .-]+$/', $values["contact_phone"]) || strlen($values["contact_phone"]) > 20)
    ) {
        $error = "Enter a valid phone number (up to 20 characters).";

    } else {
        try {
            $conn->begin_transaction();

            $middleName = $values["middle_name"] !== ""
                ? $values["middle_name"]
                : null;
            $suffix = $values["suffix"] !== ""
                ? $values["suffix"]
                : null;
            $phone = $values["contact_phone"] !== ""
                ? $values["contact_phone"]
                : null;
            $lrn = null;
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $role = "Teacher";

            $addUser = $conn->prepare(
                "INSERT INTO users
                    (lrn, first_name, middle_name, last_name, suffix, email, password, role, approval_status, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', 1)"
            );
            $addUser->bind_param(
                "ssssssss",
                $lrn,
                $values["first_name"],
                $middleName,
                $values["last_name"],
                $suffix,
                $values["email"],
                $passwordHash,
                $role
            );
            $addUser->execute();
            $userId = $conn->insert_id;
            $addUser->close();

            $addTeacher = $conn->prepare(
                "INSERT INTO teachers (user_id, contact_phone, gender)
                 VALUES (?, ?, ?)"
            );
            $addTeacher->bind_param("iss", $userId, $phone, $values["gender"]);
            $addTeacher->execute();
            $addTeacher->close();

            $smtpConfig = require __DIR__ . "/../forgotpassword/smtp-config.php";
            $smtpHost = trim($smtpConfig["host"] ?? "");
            $smtpUser = trim($smtpConfig["username"] ?? "");
            $smtpPass = preg_replace('/\s+/', "", (string) ($smtpConfig["password"] ?? ""));
            $fromEmail = trim($smtpConfig["from_email"] ?? "") ?: $smtpUser;
            if ($smtpHost === "" || $smtpUser === "" || $smtpPass === "" || $fromEmail === "") {
                throw new RuntimeException("SMTP settings are missing");
            }
            require_once __DIR__ . "/../forgotpassword/PHPMailer/src/Exception.php";
            require_once __DIR__ . "/../forgotpassword/PHPMailer/src/PHPMailer.php";
            require_once __DIR__ . "/../forgotpassword/PHPMailer/src/SMTP.php";
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $smtpHost;
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpUser;
            $mailer->Password = $smtpPass;
            $mailer->SMTPSecure = ($smtpConfig["encryption"] ?? "tls") === "ssl"
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port = (int) ($smtpConfig["port"] ?? 587);
            $mailer->CharSet = "UTF-8";
            $mailer->setFrom($fromEmail, $smtpConfig["from_name"] ?? "St. Peregrine Institute");
            $mailer->addAddress($values["email"], trim($values["first_name"] . " " . $values["last_name"]));
            $mailer->isHTML(true);
            $mailer->Subject = "Your St. Peregrine Institute teacher account";
            $safeFirstName = htmlspecialchars($values["first_name"], ENT_QUOTES, "UTF-8");
            $safeTemporaryPassword = htmlspecialchars($password, ENT_QUOTES, "UTF-8");
            $mailer->Body = "<p>Hello {$safeFirstName},</p><p>Your teacher account has been created. Use this temporary password to sign in:</p><p style=\"font-size:22px;font-weight:bold;letter-spacing:2px\">{$safeTemporaryPassword}</p><p>Please change this password after signing in and do not share it with anyone.</p>";
            $mailer->AltBody = "Your teacher account has been created. Temporary password: {$password}. Please change this password after signing in and do not share it with anyone.";
            $mailer->send();
            $conn->commit();
            $success = "The teacher account was created. A temporary password was sent to the teacher email address.";
            $_SESSION["teacher_registration_csrf"] = bin2hex(random_bytes(32));

            foreach ($values as $field => $unused) {
                $values[$field] = "";
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log("Teacher registration or temporary password email error: " . $e->getMessage());

            $error = $e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062
                ? "That Gmail address is already registered."
                : "Could not create the teacher account or send its temporary password. Check the email setup and try again.";
        }
    }
}
$teachers = [];
$teacherListError = "";
$teacherSearch = trim((string) ($_GET["search"] ?? ""));
$teacherPage = max(1, (int) ($_GET["page"] ?? 1));
$teacherPageSize = 10;
$teacherTotal = 0;
$teacherPageCount = 1;
try {
    $teacherWhere = $teacherSearch !== ""
        ? " WHERE CONCAT_WS(' ', users.first_name, users.middle_name, users.last_name, users.suffix, users.email, teachers.contact_phone) LIKE ?"
        : "";
    $teacherCountQuery = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM teachers
         JOIN users ON users.user_id = teachers.user_id" . $teacherWhere
    );
    if ($teacherSearch !== "") {
        $teacherSearchPattern = "%" . $teacherSearch . "%";
        $teacherCountQuery->bind_param("s", $teacherSearchPattern);
    }
    $teacherCountQuery->execute();
    $teacherTotal = (int) $teacherCountQuery->get_result()->fetch_assoc()["total"];
    $teacherCountQuery->close();

    $teacherPageCount = max(1, (int) ceil($teacherTotal / $teacherPageSize));
    $teacherPage = min($teacherPage, $teacherPageCount);
    $teacherOffset = ($teacherPage - 1) * $teacherPageSize;
    $teacherListQuery = $conn->prepare(
        "SELECT teachers.teacher_id, users.first_name, users.middle_name, users.last_name, users.suffix,
                users.email, teachers.contact_phone, teachers.gender
         FROM teachers
         JOIN users ON users.user_id = teachers.user_id" . $teacherWhere . "
         ORDER BY users.last_name, users.first_name
         LIMIT ?, ?"
    );
    if ($teacherSearch !== "") {
        $teacherSearchPattern = "%" . $teacherSearch . "%";
        $teacherListQuery->bind_param("sii", $teacherSearchPattern, $teacherOffset, $teacherPageSize);
    } else {
        $teacherListQuery->bind_param("ii", $teacherOffset, $teacherPageSize);
    }
    $teacherListQuery->execute();
    $teachers = $teacherListQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $teacherListQuery->close();
} catch (mysqli_sql_exception $e) {
    error_log("Teacher list error: " . $e->getMessage());
    $teacherListError = "We could not load the teacher list right now.";
}
$teacherPageLinkPrefix = "../admin-register?" . http_build_query(
    ($teacherSearch !== "" ? ["search" => $teacherSearch] : []) + ["page" => ""]
);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Create a teacher account for the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>Teacher Registration | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/register.css?v=<?= (int) filemtime(__DIR__ . "/../css/register.css") ?>">
    <link rel="stylesheet" href="../css/teacher-list.css?v=<?= (int) filemtime(__DIR__ . "/../css/teacher-list.css") ?>">
    <link rel="stylesheet" href="../css/admin-register.css?v=<?= (int) filemtime(__DIR__ . "/../css/admin-register.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="teacher-registration-page">
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
                <div class="menu-user"><strong><?= h($_SESSION["name"] ?? "Administrator") ?></strong><span>Admin</span></div>
                <a class="menu-link" href="../admin-dashboard">Dashboard</a>
                <a class="menu-link" href="../admin-section_assignments">Sections</a>
                <a class="menu-link" href="../admin-subjects">Subjects</a>
                <a class="menu-link" href="../admin-calendar">Calendar</a>
                <a class="menu-link active" href="../admin-register" aria-current="page">Teacher</a>
            </nav>
        </div>
    </header>

    <main class="page teacher-list-page">
        <div class="page-heading admin-welcome-banner teacher-registration-banner">
            <div class="admin-welcome-copy">
                <p class="admin-welcome-greeting">STAFF MANAGEMENT</p>
                <h1>Teachers</h1>
                <p>Manage teacher accounts and view their contact details.</p>
            </div>
        </div>
        <div class="teacher-page-header">
            <div class="teacher-page-actions">
                <form class="teacher-list-search" method="get" action="../admin-register">
                    <div class="teacher-list-search-controls">
                        <input id="teacher-search" type="search" name="search" value="<?= h($teacherSearch) ?>" placeholder="Search teachers…" autocomplete="off">
                        <button type="submit">Search</button>
                        <?php 
if ($teacherSearch !== ""): ?><a href="../admin-register">Clear</a><?php 
endif; ?>
                    </div>
                </form>
                <?php 
if ($teacherTotal > 0): ?>
                    <nav class="teacher-list-pagination" aria-label="Teacher list pages">
                        <div class="teacher-pagination-controls">
                            <?php 
if ($teacherPage > 1): ?><a class="teacher-pagination-arrow" href="<?= h($teacherPageLinkPrefix . ($teacherPage - 1)) ?>" aria-label="Previous page">Previous</a><?php 
else: ?><span class="teacher-pagination-arrow disabled" aria-disabled="true">Previous</span><?php 
endif; ?>
                            <?php 
if ($teacherPage < $teacherPageCount): ?><a class="teacher-pagination-arrow" href="<?= h($teacherPageLinkPrefix . ($teacherPage + 1)) ?>" aria-label="Next page">Next</a><?php 
else: ?><span class="teacher-pagination-arrow disabled" aria-disabled="true">Next</span><?php 
endif; ?>
                        </div>
                    </nav>
                <?php 
endif; ?>
                <button class="submit teacher-add-button" type="button" id="open-teacher-modal"><span aria-hidden="true">＋</span> Add teacher</button>
            </div>
        </div>
        <section class="card teacher-list-card">
            <div class="teacher-list-heading">
                <div><h2>Teacher directory</h2><p>Browse and search registered teachers.</p></div>
                <span class="teacher-count-badge"><?= $teacherTotal ?> <?= $teacherTotal === 1 ? "teacher" : "teachers" ?></span>
            </div>

            <?php 
if ($teacherListError !== ""): ?><p class="message error" role="alert"><?= h($teacherListError) ?></p><?php 
endif; ?>
            <?php 
if (count($teachers) > 0): ?>
                <div class="teacher-table-wrap"><table class="teacher-table"><thead><tr><th>Teacher</th><th>Email</th><th>Contact number</th><th>Gender</th></tr></thead><tbody>
                    <?php 
foreach ($teachers as $teacher): ?>
                        <?php 
$fullName = trim(implode(" ", array_filter([$teacher["first_name"], $teacher["middle_name"], $teacher["last_name"], $teacher["suffix"]]))); $teacherInitials = strtoupper(substr((string) $teacher["first_name"], 0, 1) . substr((string) $teacher["last_name"], 0, 1)); ?>
                        <tr class="admin-teacher-row" data-teacher-url="teacher_info.php?teacher_id=<?= (int) $teacher["teacher_id"] ?>" tabindex="0" role="link" aria-label="View teacher details: <?= h($fullName) ?>"><td><div class="teacher-name-cell"><span class="teacher-avatar" aria-hidden="true"><?= h($teacherInitials) ?></span><strong><?= h($fullName) ?></strong></div></td><td><span class="teacher-email"><?= h($teacher["email"]) ?></span></td><td><?= h($teacher["contact_phone"] ?: "—") ?></td><td><span class="teacher-gender-tag"><?= h($teacher["gender"] ?: "Not specified") ?></span></td></tr>
                    <?php 
endforeach; ?>
                </tbody></table></div>
            <?php 
else: ?>
                <div class="teacher-list-empty"><span aria-hidden="true">♙</span><h2><?= $teacherSearch !== "" ? "No matching teachers" : "No teachers registered" ?></h2><p><?= $teacherSearch !== "" ? "Try a different name, email, or contact number." : "Add a teacher account to get started." ?></p></div>
            <?php 
endif; ?>
        </section>
        <div class="modal-overlay teacher-add-overlay" id="teacher-add-modal" <?= $error === "" ? "hidden" : "" ?>>
            <section class="card teacher-add-card" role="dialog" aria-modal="true" aria-labelledby="teacher-add-title" tabindex="-1">
                <button class="teacher-modal-close" type="button" id="close-teacher-modal" aria-label="Close add teacher form">×</button>
                <header class="heading"><p class="eyebrow">TEACHER REGISTRATION</p><h2 id="teacher-add-title">Add a teacher</h2><p class="subtitle">Enter the teacher details and set up their login.</p></header>
                <div class="notice"><span class="notice-icon" aria-hidden="true">✉</span><span>The system will generate a secure temporary password and email it to the teacher.</span></div>
                <?php 
if ($error !== ""): ?><p class="message error" role="alert"><?= h($error) ?></p><?php 
endif; ?>
                <form action="../admin-register" method="post" autocomplete="on">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION["teacher_registration_csrf"]) ?>"
                >

                <section class="section">
                    <div class="section-head">
                        <span class="number">1</span>
                        <div>
                            <h2>Teacher details</h2>
                            <p>Use your name as it appears in school records.</p>
                        </div>
                    </div>

                    <div class="grid">
                        <label class="field">
                            First name
                            <input name="first_name" maxlength="50" autocomplete="given-name" value="<?= h($values["first_name"]) ?>" required>
                        </label>
                        <label class="field">
                            Middle name <span class="hint">Optional</span>
                            <input name="middle_name" maxlength="50" autocomplete="additional-name" value="<?= h($values["middle_name"]) ?>">
                        </label>
                        <label class="field">
                            Last name
                            <input name="last_name" maxlength="50" autocomplete="family-name" value="<?= h($values["last_name"]) ?>" required>
                        </label>
                        <label class="field">
                            Suffix <span class="hint">Optional</span>
                            <input name="suffix" maxlength="10" placeholder="Jr., III" value="<?= h($values["suffix"]) ?>">
                        </label>
                        <label class="field">
                            Gender
                            <select name="gender" required>
                                <option value="">Choose gender</option>
                                <?php 
foreach (["Female", "Male", "Prefer not to say"] as $gender): ?>
                                    <option value="<?= h($gender) ?>" <?= $values["gender"] === $gender ? "selected" : "" ?>>
                                        <?= h($gender) ?>
                                    </option>
                                <?php 
endforeach; ?>
                            </select>
                        </label>
                        <label class="field full">
                            Gmail address
                            <input type="email" name="email" maxlength="100" autocomplete="email" placeholder="you@gmail.com" value="<?= h($values["email"]) ?>" required>
                        </label>
                        <label class="field full">
                            Contact phone <span class="hint">Optional</span>
                            <input type="tel" name="contact_phone" maxlength="20" autocomplete="tel" placeholder="09xx xxx xxxx" value="<?= h($values["contact_phone"]) ?>">
                        </label>
                    </div>
                </section>

                <button class="submit" type="submit">Create teacher and email temporary password</button>
                </form>
            </section>
        </div>
    </main>
    <?php 
if ($success !== ""): ?>
        <div class="modal-overlay" id="success-modal">
            <section
                class="success-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="success-title"
                aria-describedby="success-message"
                tabindex="-1"
            >
                <div class="success-icon" aria-hidden="true">✓</div>
                <h2 id="success-title">Registration complete!</h2>
                <p id="success-message"><?= h($success) ?></p>
                <a class="modal-action" href="../admin-dashboard">Return to admin dashboard</a>
                <button class="modal-close" type="button" id="close-success-modal">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
    <footer class="teacher-registration-footer">
        <span>St. Peregrine Institute</span>
        <span>Online Grading System</span>
    </footer>
    <script>
        const teacherAddModal = document.getElementById("teacher-add-modal");
        const openTeacherModalButton = document.getElementById("open-teacher-modal");
        const closeTeacherModalButton = document.getElementById("close-teacher-modal");
        function openTeacherModal() {
            if (!teacherAddModal) return;
            teacherAddModal.hidden = false;
            document.body.classList.add("modal-open");
            teacherAddModal.querySelector(".teacher-add-card").focus();
        }
        function closeTeacherModal() {
            if (!teacherAddModal) return;
            teacherAddModal.hidden = true;
            document.body.classList.remove("modal-open");
            openTeacherModalButton?.focus();
        }
        openTeacherModalButton?.addEventListener("click", openTeacherModal);
        closeTeacherModalButton?.addEventListener("click", closeTeacherModal);
        teacherAddModal?.addEventListener("click", function (event) {
            if (event.target === teacherAddModal) closeTeacherModal();
        });
        if (teacherAddModal && !teacherAddModal.hidden) document.body.classList.add("modal-open");
document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && teacherAddModal && !teacherAddModal.hidden) closeTeacherModal();
        });
        const successModal = document.getElementById("success-modal");
        const closeModalButton = document.getElementById("close-success-modal");

        if (successModal) {
            successModal.querySelector(".modal-action").focus();

            function closeSuccessModal() {
                successModal.hidden = true;
                document.body.classList.remove("modal-open");
            }

            closeModalButton.addEventListener("click", closeSuccessModal);
            successModal.addEventListener("click", function (event) {
                if (event.target === successModal) {
                    closeSuccessModal();
                }
            });
            document.addEventListener("keydown", function (event) {
                if (event.key === "Escape") {
                    closeSuccessModal();
                }
            });
            document.body.classList.add("modal-open");
        }

        document.querySelectorAll(".password-toggle").forEach(function (button) {
            button.addEventListener("click", function () {
                const input = document.getElementById(button.dataset.target);
                const showPassword = input.type === "password";

                input.type = showPassword ? "text" : "password";
                button.setAttribute("aria-pressed", showPassword);
                button.setAttribute(
                    "aria-label",
                    showPassword ? "Hide password" : "Show password"
                );
                button.classList.toggle("is-visible", showPassword);
            });
        });

        document.querySelectorAll(".admin-teacher-row[data-teacher-url]").forEach(function (row) {
            row.addEventListener("click", function (event) {
                if (event.target.closest("a, button, input, select, textarea")) return;
                window.location.href = row.dataset.teacherUrl;
            });
            row.addEventListener("keydown", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    window.location.href = row.dataset.teacherUrl;
                }
            });
        });
    </script>
</body>
</html>
