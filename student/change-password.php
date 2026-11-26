<?php
require_once __DIR__ . "/../includes/url_helpers.php";
session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");
if (empty($_SESSION["must_change_password"])) {
    header("Location: " . rolePageUrl("student", "dashboard.php"));
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/teacher_schema.php";
ensureTeacherMustChangePasswordColumn($conn);

$error = "";
if (empty($_SESSION["student_password_csrf"])) {
    $_SESSION["student_password_csrf"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $newPassword = (string) ($_POST["new_password"] ?? "");
    $confirmPassword = (string) ($_POST["confirm_password"] ?? "");
    if (!hash_equals($_SESSION["student_password_csrf"], $token)) {
        $error = "This form expired. Refresh the page and try again.";
    } elseif (
        strlen($newPassword) < 8 || strlen($newPassword) > 255 ||
        !preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) ||
        !preg_match('/[^A-Za-z0-9\s]/', $newPassword)
    ) {
        $error = "Use at least 8 characters with uppercase and lowercase letters and a special character.";
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $error = "The passwords do not match.";
    } else {
        try {
            $currentQuery = $conn->prepare("SELECT password FROM users WHERE user_id = ? AND role = 'Student' LIMIT 1");
            $userId = (int) $_SESSION["user_id"];
            $currentQuery->bind_param("i", $userId);
            $currentQuery->execute();
            $currentUser = $currentQuery->get_result()->fetch_assoc();
            $currentQuery->close();
            if (!$currentUser) {
                throw new RuntimeException("The student account could not be found.");
            }
            if (password_verify($newPassword, $currentUser["password"])) {
                throw new InvalidArgumentException("Choose a password different from your temporary password.");
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ? AND role = 'Student'");
            $update->bind_param("si", $passwordHash, $userId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException("The password could not be updated. Please try again.");
            }
            $update->close();
            $_SESSION["must_change_password"] = false;
            session_regenerate_id(true);
            header("Location: " . rolePageUrl("student", "dashboard.php") . "?password_changed=1");
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log("Required student password change failed: " . $e->getMessage());
            $error = "The password could not be changed right now. Please try again.";
        }
    }
    $_SESSION["student_password_csrf"] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('student/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#105334">
    <title>Change Password | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="change-password.css">
</head>
<body class="student-password-page">
    <main class="student-password-card">
        <img src="../logo.png" alt="St. Peregrine Institute">
        <p class="student-password-eyebrow">ACCOUNT SECURITY</p>
        <h1>Change your temporary password</h1>
        <p>Set a new password before continuing to your student dashboard.</p>
        <?php if ($error !== ""): ?><p class="student-password-error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form action="../student-change-password" method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["student_password_csrf"]) ?>">
            <label>New password<input type="password" name="new_password" minlength="8" maxlength="255" autocomplete="new-password" required></label>
            <label>Confirm new password<input type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required></label>
            <small>At least 8 characters, including uppercase and lowercase letters and a special character.</small>
            <button type="submit">Save new password</button>
        </form>
        <a class="student-password-signout" href="../logout.php">Sign out</a>
    </main>
</body>
</html>
