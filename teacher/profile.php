<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/messaging_schema.php";
require_once __DIR__ . "/../includes/teacher_schema.php";

if (!isset($_SESSION["profile_csrf"])) {
    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["user_id"];
$teacherUnreadMessageCount = 0;
try {
    ensureStudentTeacherMessagesSchema($conn);
    $teacherUnreadMessageCount = getUnreadTeacherMessageCount($conn, $userId);
} catch (mysqli_sql_exception $messageSchemaError) {
    error_log("Teacher message badge unavailable: " . $messageSchemaError->getMessage());
}
$profile = null;
$error = "";
$passwordError = "";
$passwordMessage = "";
$photoError = "";
$photoMessage = "";
$showPasswordForm = false;
$detailsError = "";
$detailsMessage = "";
$detailsModal = "";

try {
    ensureTeacherProfilePhotoColumn($conn);
    ensureTeacherPersonalInfoColumns($conn);
} catch (mysqli_sql_exception $e) {
    error_log("Teacher profile schema error: " . $e->getMessage());
    $photoError = "Some profile information is not available right now.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_photo") {
    $token = $_POST["csrf_token"] ?? "";
    $upload = $_FILES["profile_photo"] ?? null;

    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $photoError = "The form expired. Refresh the page and try again.";
    } elseif (!is_array($upload) || ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $photoError = "Choose a photo to upload.";
    } elseif (($upload["size"] ?? 0) > 5 * 1024 * 1024) {
        $photoError = "The image must be 5 MB or smaller.";
    } else {
        $imageInfo = @getimagesize($upload["tmp_name"] ?? "");
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload["tmp_name"] ?? "");
        $photoExtensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

        if (!$imageInfo || ($imageInfo["mime"] ?? "") !== $mimeType || !isset($photoExtensions[$mimeType])) {
            $photoError = "Choose a valid JPG, PNG, or WebP image.";
        } else {
            try {
                $uploadDirectory = __DIR__ . "/uploads";
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException("Could not create teacher uploads directory.");
                }
                $photoName = "teacher-" . $userId . "-" . bin2hex(random_bytes(12)) . "." . $photoExtensions[$mimeType];
                $photoPath = $uploadDirectory . DIRECTORY_SEPARATOR . $photoName;
                if (!move_uploaded_file($upload["tmp_name"], $photoPath)) {
                    throw new RuntimeException("Could not save teacher profile photo.");
                }
                $savePhoto = $conn->prepare("UPDATE teachers SET profile_photo = ? WHERE user_id = ?");
                $savePhoto->bind_param("si", $photoName, $userId);
                $savePhoto->execute();
                $savePhoto->close();
                $photoMessage = "Your profile picture has been updated.";
                $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                if (isset($photoPath) && is_file($photoPath)) @unlink($photoPath);
                error_log("Teacher profile photo update error: " . $e->getMessage());
                $photoError = "We could not save your profile picture right now.";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_teacher_details") {
    $detailsModal = ($_POST["details_section"] ?? "") === "contact" ? "contact" : "about";
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $detailsError = "The form expired. Refresh the page and try again.";
    } else {
        $civilStatus = trim(is_string($_POST["civil_status"] ?? null) ? $_POST["civil_status"] : "");
        $nationality = trim(is_string($_POST["nationality"] ?? null) ? $_POST["nationality"] : "");
        $contactPhone = trim(is_string($_POST["contact_phone"] ?? null) ? $_POST["contact_phone"] : "");
        $email = trim(is_string($_POST["email"] ?? null) ? $_POST["email"] : "");
        $address = trim(is_string($_POST["address"] ?? null) ? $_POST["address"] : "");

        if ($detailsModal === "about" && $civilStatus !== "" && !in_array($civilStatus, ["Single", "Married", "Widowed", "Separated", "Divorced"], true)) {
            $detailsError = "Choose a valid civil status.";
        } elseif ($detailsModal === "about" && strlen($nationality) > 80) {
            $detailsError = "Nationality must be 80 characters or fewer.";
        } elseif ($detailsModal === "contact" && $contactPhone !== "" && (!preg_match('/^[0-9+() .-]+$/', $contactPhone) || strlen($contactPhone) > 20)) {
            $detailsError = "Enter a valid phone number (up to 20 characters).";
        } elseif ($detailsModal === "contact" && (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match("/@gmail\\.com$/i", $email) || strlen($email) > 100)) {
            $detailsError = "Enter a valid Gmail address (up to 100 characters).";
        } elseif ($detailsModal === "contact" && strlen($address) > 255) {
            $detailsError = "Address must be 255 characters or fewer.";
        } else {
            try {
                $conn->begin_transaction();
                if ($detailsModal === "about") {
                    $saveDetails = $conn->prepare("UPDATE teachers SET civil_status = ?, nationality = ? WHERE user_id = ?");
                    $civilStatusValue = $civilStatus === "" ? null : $civilStatus;
                    $nationalityValue = $nationality === "" ? null : $nationality;
                    $saveDetails->bind_param("ssi", $civilStatusValue, $nationalityValue, $userId);
                } else {
                    $emailCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
                    $emailCheck->bind_param("si", $email, $userId);
                    $emailCheck->execute();
                    $emailTaken = $emailCheck->get_result()->num_rows > 0;
                    $emailCheck->close();
                    if ($emailTaken) {
                        throw new DomainException("That email address is already in use.");
                    }
                    $saveUser = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                    $saveUser->bind_param("si", $email, $userId);
                    $saveUser->execute();
                    $saveUser->close();
                    $phoneValue = $contactPhone === "" ? null : $contactPhone;
                    $addressValue = $address === "" ? null : $address;
                    $saveDetails = $conn->prepare("UPDATE teachers SET contact_phone = ?, address = ? WHERE user_id = ?");
                    $saveDetails->bind_param("ssi", $phoneValue, $addressValue, $userId);
                }
                $saveDetails->execute();
                $saveDetails->close();
                $conn->commit();
                $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
                $detailsMessage = "Your profile information has been updated.";
                $detailsModal = "";
            } catch (DomainException $e) {
                $conn->rollback();
                $detailsError = $e->getMessage();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log("Teacher profile details update error: " . $e->getMessage());
                $detailsError = $e->getCode() === 1062 ? "That email address is already in use." : "We could not save your information right now.";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_password") {
    $showPasswordForm = true;
    $token = $_POST["csrf_token"] ?? "";
    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $passwordError = "The form expired. Refresh the page and try again.";
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = "The new passwords do not match.";
    } elseif (
        strlen($newPassword) < 8 ||
        strlen($newPassword) > 255 ||
        !preg_match('/[a-z]/', $newPassword) ||
        !preg_match('/[A-Z]/', $newPassword) ||
        !preg_match('/[^A-Za-z0-9\\s]/', $newPassword)
    ) {
        $passwordError = "Use at least 8 characters with uppercase and lowercase letters and a special character.";
    } else {
        try {
            $passwordQuery = $conn->prepare(
                "SELECT password FROM users WHERE user_id = ?"
            );
            $passwordQuery->bind_param("i", $userId);
            $passwordQuery->execute();
            $account = $passwordQuery->get_result()->fetch_assoc();
            $passwordQuery->close();

            if (!$account || !password_verify($currentPassword, $account["password"])) {
                $passwordError = "Your current password is incorrect.";
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updatePassword = $conn->prepare(
                    "UPDATE users SET password = ? WHERE user_id = ?"
                );
                $updatePassword->bind_param("si", $passwordHash, $userId);
                $updatePassword->execute();
                $updatePassword->close();

                $passwordMessage = "Your password has been changed.";
                $showPasswordForm = false;
                $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
            }
        } catch (mysqli_sql_exception $e) {
            error_log("Password update error: " . $e->getMessage());
            $passwordError = "We could not change your password right now.";
        }
    }
}

try {
    ensureTeacherGenderColumn($conn);
    $profileQuery = $conn->prepare(
        "SELECT users.lrn, users.first_name, users.middle_name, users.last_name,
                users.suffix, users.email, teachers.contact_phone, teachers.gender,
                teachers.civil_status, teachers.nationality, teachers.address,
                teachers.profile_photo
         FROM users
         LEFT JOIN teachers ON teachers.user_id = users.user_id
         WHERE users.user_id = ?"
    );
    $profileQuery->bind_param("i", $userId);
    $profileQuery->execute();
    $profile = $profileQuery->get_result()->fetch_assoc();
    $profileQuery->close();
} catch (mysqli_sql_exception $e) {
    error_log("Profile page error: " . $e->getMessage());
    $error = "We could not load your profile right now.";
}

$userName = $_SESSION["name"] ?? "User";
$userRole = ucfirst($_SESSION["role"] ?? "user");
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage your teacher profile in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>My Profile | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
            <link rel="stylesheet" href="../student/css/profile.css?v=<?= (int) filemtime(__DIR__ . "/../student/css/profile.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="student-profile-copy-page teacher-profile-page">
    <header class="topbar">
        <a class="brand" href="../<?= h($_SESSION["role"]) ?>-dashboard">
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
                    <strong><?= h($userName) ?></strong>
                    <span><?= h($userRole) ?></span>
                </div>
                <a class="menu-link" href="../teacher-dashboard">Dashboard</a>
                <a class="menu-link" href="../teacher-sections">Sections</a>
                <a class="menu-link" href="../teacher-messages">Messages <span class="nav-unread-count" data-teacher-unread-count<?= $teacherUnreadMessageCount > 0 ? "" : " hidden" ?>><?= $teacherUnreadMessageCount > 99 ? "99+" : $teacherUnreadMessageCount ?></span></a>
                <a class="menu-link active" href="../teacher-profile" aria-current="page">My Profile</a>
                <a class="menu-link signout-link" href="../logout.php">Sign out</a>
            </nav>
        </div>
    </header>

    <main class="dashboard profile-copy-dashboard">
        <?php 
if ($error !== ""): ?>
            <p class="alert error"><?= h($error) ?></p>
        <?php 
elseif (!$profile): ?>
            <p class="alert error">We could not find your account profile.</p>
        <?php 
else: ?>
            <?php 
if ($photoError !== ""): ?><p class="alert error profile-photo-alert" role="alert"><?= h($photoError) ?></p><?php 
endif; ?>
            <?php 
if ($passwordError !== ""): ?><p class="alert error profile-photo-alert"><?= h($passwordError) ?></p><?php 
endif; ?>
            <section class="profile-copy-hero">
                <aside class="profile-copy-sidebar">
                    <div class="profile-copy-avatar-column">
                        <?php 
if (!empty($profile["profile_photo"])): ?>
                            <img class="profile-copy-photo" src="uploads/<?= rawurlencode($profile["profile_photo"]) ?>" width="150" height="150" alt="Profile picture of <?= h($userName) ?>">
                        <?php 
else: ?>
                            <div class="profile-copy-photo teacher-profile-initial" aria-hidden="true"><?= h(strtoupper(substr($userName, 0, 1))) ?></div>
                        <?php 
endif; ?>
                        <div class="profile-copy-avatar-name"><h2><?= h($userName) ?></h2><p>Teacher</p></div>
                        <div class="profile-copy-actions">
                            <button class="profile-copy-action-button" type="button" id="show-password-form" aria-expanded="<?= $showPasswordForm ? "true" : "false" ?>" aria-controls="change-password-panel">Change Password</button>
                            <form action="../teacher-profile" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_photo">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                                <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                                <label class="profile-copy-action-button"><?= empty($profile["profile_photo"]) ? "Add Profile Picture" : "Change Profile Picture" ?><input id="profile-photo-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required></label>
                            </form>
                            <a class="profile-copy-action-button teacher-profile-download" href="../teacher-download-profile">Download Profile</a>
                        </div>
                    </div>
                </aside>
                <div class="profile-copy-intro">
                    <h2>Teacher Details</h2>
                    <p>Your profile information</p>
                    <div class="profile-copy-navigation">
                        <nav class="profile-copy-tabs teacher-profile-tabs" aria-label="Profile sections">
                            <button type="button" class="is-active" aria-selected="true" aria-controls="profile-about-panel">About Me</button>
                            <button type="button" aria-selected="false" aria-controls="profile-contact-panel">Contact</button>
                        </nav>
                    </div>
                    <section class="profile-copy-form-panel profile-copy-basic-panel" id="profile-about-panel">
                        <h2>Basic Information</h2>
                        <div class="profile-copy-about-grid">
                            <div><span>Last Name</span><strong><?= h($profile["last_name"] ?: "—") ?></strong></div>
                            <div><span>First Name</span><strong><?= h($profile["first_name"] ?: "—") ?></strong></div>
                            <?php 
if (trim((string) ($profile["middle_name"] ?? "")) !== ""): ?><div><span>Middle Name</span><strong><?= h($profile["middle_name"]) ?></strong></div><?php 
endif; ?>
                            <?php 
if (trim((string) ($profile["suffix"] ?? "")) !== ""): ?><div><span>Suffix</span><strong><?= h($profile["suffix"]) ?></strong></div><?php 
endif; ?>
                            <div><span>Gender</span><strong><?= h($profile["gender"] ?: "—") ?></strong></div>
                            <div><span>Civil Status</span><strong><?= h($profile["civil_status"] ?: "—") ?></strong></div>
                            <div><span>Nationality</span><strong><?= h($profile["nationality"] ?: "—") ?></strong></div>
                        </div>
                        <?php 
if (empty($profile["civil_status"]) || empty($profile["nationality"])): ?>
                            <button class="profile-copy-action-button teacher-details-complete" type="button" id="show-about-details" aria-controls="about-details-modal">Complete Information</button>
                        <?php 
endif; ?>
                    </section>
                    <section class="profile-copy-form-panel profile-copy-basic-panel" id="profile-contact-panel" hidden>
                        <h2>Contact Information</h2>
                        <div class="profile-copy-about-grid">
                            <div><span>Contact Number</span><strong><?= h($profile["contact_phone"] ?: "—") ?></strong></div>
                            <div><span>Email Address</span><strong><?= h($profile["email"] ?: "—") ?></strong></div>
                            <div><span>Address</span><strong><?= h($profile["address"] ?: "—") ?></strong></div>
                        </div>
                        <button class="profile-copy-action-button teacher-details-complete" type="button" id="show-contact-details" aria-controls="contact-details-modal">Update Contact</button>
                    </section>
                </div>
            </section>
        <?php 
endif; ?>
    </main>
    <?php 
if ($profile): ?>
        <div class="dashboard-modal-overlay" id="change-password-panel" data-trigger-id="show-password-form" <?= $showPasswordForm ? "" : "hidden" ?>>
            <section class="dashboard-form-modal" role="dialog" aria-modal="true" aria-labelledby="change-password-title" tabindex="-1">
                <div class="dashboard-form-modal-heading">
                    <div><h2 id="change-password-title">Change password</h2><p>Enter your current password, then choose a new one.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
                <form action="../teacher-profile" class="password-form" method="post">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <label>Current password
                        <div class="password-box"><input id="teacher-current-password" type="password" name="current_password" autocomplete="current-password" required>
                            <button class="password-toggle" type="button" data-target="teacher-current-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="m3 3 18 18"></path></svg></button>
                        </div>
                    </label>
                    <label>New password
                        <div class="password-box"><input id="change-new-password" type="password" name="new_password" minlength="8" maxlength="255" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9\s]).{8,255}" title="Use 8–255 characters with uppercase and lowercase letters and a special character." autocomplete="new-password" required>
                            <button class="password-toggle" type="button" data-target="change-new-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="m3 3 18 18"></path></svg></button>
                        </div>
                        <span class="password-constraints">At least 8 characters, including uppercase and lowercase letters and a special character.</span>
                        <div class="meter change-password-meter" aria-live="polite"><div class="meter-track"><span class="meter-fill" id="change-password-meter-fill"></span></div><span class="meter-label" id="change-password-meter-label">Password strength</span></div>
                    </label>
                    <label>Confirm new password
                        <div class="password-box"><input id="teacher-confirm-password" type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" data-target="teacher-confirm-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="eye-slash" d="m3 3 18 18"></path></svg></button>
                        </div>
                    </label>
                    <button class="change-password-button" type="submit">Save new password</button>
                </form>
            </section>
        </div>

        <div class="dashboard-modal-overlay" id="about-details-modal" data-trigger-id="show-about-details" <?= $detailsModal === "about" ? "" : "hidden" ?>>
            <section class="dashboard-form-modal" role="dialog" aria-modal="true" aria-labelledby="about-details-title" tabindex="-1">
                <div class="dashboard-form-modal-heading"><div><h2 id="about-details-title">Complete About Me</h2><p>Add your civil status and nationality.</p></div><button class="dashboard-modal-close" type="button" aria-label="Close">×</button></div>
                <form action="../teacher-profile" class="password-form" method="post">
                    <input type="hidden" name="action" value="update_teacher_details"><input type="hidden" name="details_section" value="about"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <?php 
if ($detailsError !== "" && $detailsModal === "about"): ?><p class="alert error modal-form-alert" role="alert"><?= h($detailsError) ?></p><?php 
endif; ?>
                    <label>Civil Status
                        <?php 
$civilStatusValue = $detailsModal === "about" ? ($_POST["civil_status"] ?? $profile["civil_status"]) : $profile["civil_status"]; ?>
                        <select name="civil_status"><option value="">Select civil status</option><?php 
foreach (["Single", "Married", "Widowed", "Separated", "Divorced"] as $status): ?><option value="<?= h($status) ?>" <?= $civilStatusValue === $status ? "selected" : "" ?>><?= h($status) ?></option><?php 
endforeach; ?></select>
                    </label>
                    <label>Nationality<input type="text" name="nationality" maxlength="80" value="<?= h($detailsModal === "about" ? ($_POST["nationality"] ?? $profile["nationality"]) : $profile["nationality"]) ?>" autocomplete="country-name"></label>
                    <div class="dashboard-form-modal-actions"><button class="dashboard-modal-close" type="button">Cancel</button><button class="change-password-button" type="submit">Save Information</button></div>
                </form>
            </section>
        </div>

        <div class="dashboard-modal-overlay" id="contact-details-modal" data-trigger-id="show-contact-details" <?= $detailsModal === "contact" ? "" : "hidden" ?>>
            <section class="dashboard-form-modal" role="dialog" aria-modal="true" aria-labelledby="contact-details-title" tabindex="-1">
                <div class="dashboard-form-modal-heading"><div><h2 id="contact-details-title">Complete Contact Information</h2><p>Keep your phone number, email, and address up to date.</p></div><button class="dashboard-modal-close" type="button" aria-label="Close">×</button></div>
                <form action="../teacher-profile" class="password-form" method="post">
                    <input type="hidden" name="action" value="update_teacher_details"><input type="hidden" name="details_section" value="contact"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <?php 
if ($detailsError !== "" && $detailsModal === "contact"): ?><p class="alert error modal-form-alert" role="alert"><?= h($detailsError) ?></p><?php 
endif; ?>
                    <label>Contact Number<input type="tel" name="contact_phone" maxlength="20" value="<?= h($detailsModal === "contact" ? ($_POST["contact_phone"] ?? $profile["contact_phone"]) : $profile["contact_phone"]) ?>" autocomplete="tel"></label>
                    <label>Email Address<input type="email" name="email" maxlength="100" value="<?= h($detailsModal === "contact" ? ($_POST["email"] ?? $profile["email"]) : $profile["email"]) ?>" autocomplete="email" required></label>
                    <label>Address<textarea name="address" maxlength="255" rows="3"><?= h($detailsModal === "contact" ? ($_POST["address"] ?? $profile["address"]) : $profile["address"]) ?></textarea></label>
                    <div class="dashboard-form-modal-actions"><button class="dashboard-modal-close" type="button">Cancel</button><button class="change-password-button" type="submit">Save Information</button></div>
                </form>
            </section>
        </div>
    <?php 
endif; ?>

    <?php 
if ($passwordMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="password-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="password-success-title" aria-describedby="password-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="password-success-title">Password changed</h2>
                <p id="password-success-message"><?= h($passwordMessage) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>

    <?php 
if ($photoMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="photo-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="photo-success-title" aria-describedby="photo-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="photo-success-title">Profile picture updated</h2>
                <p id="photo-success-message"><?= h($photoMessage) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>

    <?php 
if ($detailsMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="details-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="details-success-title" aria-describedby="details-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div><h2 id="details-success-title">Information saved</h2><p id="details-success-message"><?= h($detailsMessage) ?></p><button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>

    <script>
        const passwordTrigger = document.getElementById("show-password-form");
        const passwordModal = document.getElementById("change-password-panel");
        const newPasswordInput = document.getElementById("change-new-password");
        const profilePhotoInput = document.getElementById("profile-photo-input");
        [["show-about-details", "about-details-modal"], ["show-contact-details", "contact-details-modal"]].forEach(function (pair) {
            const trigger = document.getElementById(pair[0]);
            const modal = document.getElementById(pair[1]);
            if (!trigger || !modal) return;
            trigger.addEventListener("click", function () {
                modal.hidden = false;
                modal.dataset.triggerId = trigger.id;
                document.body.classList.add("dashboard-modal-open");
                const closeButton = modal.querySelector(".dashboard-modal-close");
                if (closeButton) closeButton.focus();
            });
        });
        document.querySelectorAll(".password-toggle").forEach(function (button) {
            button.addEventListener("click", function () {
                const input = document.getElementById(button.dataset.target);
                const showPassword = input.type === "password";
                input.type = showPassword ? "text" : "password";
                button.setAttribute("aria-pressed", String(showPassword));
                button.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
                button.classList.toggle("is-visible", showPassword);
            });
        });
        if (newPasswordInput) {
            newPasswordInput.addEventListener("input", function () {
                const value = newPasswordInput.value;
                let score = 0;
                if (value.length >= 8) score++;
                if (/[a-z]/.test(value)) score++;
                if (/[A-Z]/.test(value)) score++;
                if (/[^A-Za-z0-9\s]/.test(value)) score++;
                document.getElementById("change-password-meter-fill").style.width = (score * 25) + "%";
                document.getElementById("change-password-meter-label").textContent = score === 0 ? "Password strength" : ["Weak", "Fair", "Good", "Strong"][score - 1];
            });
        }
        if (profilePhotoInput) {
            profilePhotoInput.addEventListener("change", function () {
                if (profilePhotoInput.files.length > 0) profilePhotoInput.form.requestSubmit();
            });
        }
        if (passwordTrigger && passwordModal) {
            passwordTrigger.addEventListener("click", function () {
                passwordModal.dataset.triggerId = passwordTrigger.id;
                passwordModal.hidden = false;
                passwordTrigger.setAttribute("aria-expanded", "true");
                document.body.classList.add("dashboard-modal-open");
                const closeButton = passwordModal.querySelector(".dashboard-modal-close");
                if (closeButton) closeButton.focus();
            });
        }
        document.querySelectorAll(".teacher-profile-tabs button").forEach(function (tab) {
            tab.addEventListener("click", function () {
                document.querySelectorAll(".teacher-profile-tabs button").forEach(function (otherTab) {
                    const isActive = otherTab === tab;
                    otherTab.classList.toggle("is-active", isActive);
                    otherTab.setAttribute("aria-selected", String(isActive));
                    const panel = document.getElementById(otherTab.getAttribute("aria-controls"));
                    if (panel) panel.hidden = !isActive;
                });
            });
        });
    </script>
</body>
</html>
