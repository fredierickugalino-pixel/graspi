<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

try {
    $profileColumns = [
        "profile_photo" => "VARCHAR(255) NULL DEFAULT NULL",
        "nationality" => "VARCHAR(80) NULL DEFAULT NULL",
        "civil_status" => "VARCHAR(30) NULL DEFAULT NULL",
        "contact_phone" => "VARCHAR(20) NULL DEFAULT NULL"
    ];
    foreach ($profileColumns as $columnName => $definition) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE '" . $columnName . "'");
        if ($columnCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN " . $columnName . " " . $definition);
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log("Admin profile schema error: " . $e->getMessage());
}

if (!isset($_SESSION["profile_csrf"])) {
    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["user_id"];
$profile = null;
$error = "";
$passwordError = "";
$passwordMessage = "";
$photoError = "";
$profileFieldError = "";
$showProfileDialogError = false;
$showPasswordForm = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_profile_field") {
    $showProfileDialogError = true;
    $field = is_string($_POST["profile_field"] ?? null) ? $_POST["profile_field"] : "";
    $value = is_string($_POST["profile_value"] ?? null) ? trim($_POST["profile_value"]) : "";
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $profileFieldColumns = [
        "first_name" => "first_name",
        "last_name" => "last_name",
        "email" => "email",
        "nationality" => "nationality",
        "civil_status" => "civil_status",
        "contact_phone" => "contact_phone"
    ];
    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $profileFieldError = "The form expired. Refresh the page and try again.";
    } elseif (!isset($profileFieldColumns[$field])) {
        $profileFieldError = "Choose a valid profile field.";
    } elseif (in_array($field, ["first_name", "last_name"], true) && ($value === "" || strlen($value) > 50)) {
        $profileFieldError = "Enter a name up to 50 characters long.";
    } elseif ($field === "email" && ($value === "" || !filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 254)) {
        $profileFieldError = "Enter a valid email address.";
    } elseif ($field === "nationality" && strlen($value) > 80) {
        $profileFieldError = "Nationality must be 80 characters or fewer.";
    } elseif ($field === "civil_status" && $value !== "" && !in_array($value, ["Single", "Married", "Widowed", "Separated", "Divorced"], true)) {
        $profileFieldError = "Choose a valid civil status.";
    } elseif ($field === "contact_phone" && $value !== "" && (!preg_match('/^[0-9+() .-]+$/', $value) || strlen($value) > 20)) {
        $profileFieldError = "Enter a valid contact number up to 20 characters long.";
    } else {
        try {
            $column = $profileFieldColumns[$field];
            $storedValue = $value === "" ? null : $value;
            $updateProfile = $conn->prepare("UPDATE users SET {$column} = ? WHERE user_id = ?");
            $updateProfile->bind_param("si", $storedValue, $userId);
            $updateProfile->execute();
            $updateProfile->close();
            $verifyProfile = $conn->prepare("SELECT {$column} AS saved_value FROM users WHERE user_id = ?");
            $verifyProfile->bind_param("i", $userId);
            $verifyProfile->execute();
            $verifiedValue = $verifyProfile->get_result()->fetch_assoc()["saved_value"] ?? null;
            $verifyProfile->close();
            if ((string) ($verifiedValue ?? "") !== (string) ($storedValue ?? "")) {
                throw new Exception("The profile information did not save. Please try again.");
            }
            if (in_array($field, ["first_name", "last_name"], true)) {
                $nameQuery = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $nameQuery->bind_param("i", $userId);
                $nameQuery->execute();
                $updatedName = $nameQuery->get_result()->fetch_assoc();
                $nameQuery->close();
                if ($updatedName) {
                    $_SESSION["name"] = trim($updatedName["first_name"] . " " . $updatedName["last_name"]);
                }
            }
            $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
            header("Location: " . rolePageUrl("admin", "profile.php") . "?profile_saved=1");
            exit;
        } catch (Throwable $e) {
            error_log("Admin profile field update error: " . $e->getMessage());
            $profileFieldError = $e instanceof mysqli_sql_exception && $e->getCode() === 1062
                ? "That email address is already in use."
                : ($e instanceof mysqli_sql_exception ? "We could not save this information right now." : $e->getMessage());
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload_profile_photo") {
    $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $upload = $_FILES["profile_photo"] ?? null;
    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $photoError = "The form expired. Refresh the page and try again.";
    } elseif (!is_array($upload) || ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $photoError = "Choose a photo to upload.";
    } elseif (($upload["size"] ?? 0) > 5 * 1024 * 1024) {
        $photoError = "Choose an image smaller than 5 MB.";
    } else {
        $imageInfo = @getimagesize($upload["tmp_name"] ?? "");
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload["tmp_name"] ?? "");
        $photoExtensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
        if (!$imageInfo || ($imageInfo["mime"] ?? "") !== $mimeType || !isset($photoExtensions[$mimeType])) {
            $photoError = "Choose a valid JPG, PNG, or WebP photo.";
        } else {
            $uploadDirectory = __DIR__ . "/uploads";
            if (!is_dir($uploadDirectory)) {
                mkdir($uploadDirectory, 0755, true);
            }
            $photoName = "admin-" . $userId . "-" . bin2hex(random_bytes(12)) . "." . $photoExtensions[$mimeType];
            $photoPath = $uploadDirectory . DIRECTORY_SEPARATOR . $photoName;
            if (!move_uploaded_file($upload["tmp_name"], $photoPath)) {
                $photoError = "We could not save that photo. Please try again.";
            } else {
                try {
                    $photoUpdate = $conn->prepare("UPDATE users SET profile_photo = ? WHERE user_id = ?");
                    $photoUpdate->bind_param("si", $photoName, $userId);
                    $photoUpdate->execute();
                    $photoUpdate->close();
                    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
                    header("Location: " . rolePageUrl("admin", "profile.php") . "?photo_saved=1");
                    exit;
                } catch (mysqli_sql_exception $e) {
                    @unlink($photoPath);
                    error_log("Admin profile photo update error: " . $e->getMessage());
                    $photoError = "We could not save that photo right now.";
                }
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
    $profileQuery = $conn->prepare(
        "SELECT lrn, first_name, middle_name, last_name, suffix,
                email, profile_photo, nationality, civil_status, contact_phone
         FROM users
         WHERE user_id = ?"
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
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage your administrator profile in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>My Profile | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../student/css/profile.css?v=<?= (int) filemtime(__DIR__ . "/../student/css/profile.css") ?>">
    <link rel="stylesheet" href="profile.css?v=<?= (int) filemtime(__DIR__ . "/profile.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="student-profile-copy-page admin-profile-page">
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
            <a class="admin-profile-icon is-active" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
        </div>
    </header>

    <main class="dashboard profile-copy-dashboard">
        <a class="admin-profile-back" href="../admin-dashboard"><span aria-hidden="true">←</span> Back to dashboard</a>
        <?php 
if ($error !== ""): ?><p class="alert error"><?= h($error) ?></p><?php 
elseif (!$profile): ?><p class="alert error">We could not find your account profile.</p><?php 
else: ?>
        <?php 
if ($profileFieldError !== ""): ?><p class="alert error" role="alert"><?= h($profileFieldError) ?></p><?php 
elseif (isset($_GET["profile_saved"])): ?><p class="alert success" role="status">Profile information saved.</p><?php 
endif; ?>
        <?php 
if ($photoError !== ""): ?><p class="alert error" role="alert"><?= h($photoError) ?></p><?php 
elseif (isset($_GET["photo_saved"])): ?><p class="alert success" role="status">Profile photo updated.</p><?php 
endif; ?>
        <section class="profile-copy-hero">
            <aside class="profile-copy-sidebar">
                <div class="profile-copy-avatar-column">
                    <?php 
if (!empty($profile["profile_photo"])): ?>
                        <img class="profile-copy-photo" src="uploads/<?= rawurlencode(basename($profile["profile_photo"])) ?>" width="150" height="150" alt="Profile picture of <?= h($userName) ?>">
                    <?php 
else: ?>
                        <div class="profile-copy-photo admin-profile-initial" aria-hidden="true"><?= h(strtoupper(substr($userName, 0, 1))) ?></div>
                    <?php 
endif; ?>
                    <div class="profile-copy-avatar-name"><h2><?= h($userName) ?></h2><p><?= h($userRole) ?></p></div>
                    <div class="profile-copy-actions">
                        <form action="../admin-profile" class="admin-profile-photo-form" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_profile_photo">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                            <label class="profile-copy-action-button" for="admin-account-photo-input">Upload Photo</label>
                            <input type="file" name="profile_photo" id="admin-account-photo-input" accept="image/jpeg,image/png,image/webp" required>
                        </form>
                        <button class="profile-copy-action-button" type="button" id="show-password-form" aria-expanded="<?= $showPasswordForm ? "true" : "false" ?>" aria-controls="change-password-panel">Change Password</button>
                        <a class="profile-copy-action-button" href="../admin-download-profile">Download Profile</a>
                    </div>
                </div>
            </aside>
            <div class="profile-copy-intro">
                <h2>Administrator Details</h2>
                <p>Your profile information</p>
                <div class="profile-copy-navigation">
                    <nav class="profile-copy-tabs admin-profile-tabs" aria-label="Profile sections">
                        <button type="button" class="is-active" aria-selected="true" aria-controls="profile-about-panel">About Me</button>
                        <button type="button" aria-selected="false" aria-controls="profile-contact-panel">Contact</button>
                    </nav>
                </div>
                <section class="profile-copy-form-panel profile-copy-basic-panel" id="profile-about-panel">
                    <h2>Basic Information</h2>
                    <div class="profile-copy-about-grid admin-profile-fields">
                        <?php 
foreach (["first_name" => "First Name", "middle_name" => "Middle Name", "last_name" => "Last Name", "suffix" => "Suffix", "nationality" => "Nationality", "civil_status" => "Civil Status"] as $field => $label): ?>
                            <?php 
if (in_array($field, ["middle_name", "suffix"], true) && trim((string) ($profile[$field] ?? "")) === "") continue; ?>
                            <?php 
if (in_array($field, ["first_name", "last_name", "nationality", "civil_status"], true)): ?><button class="admin-profile-field" type="button" data-profile-field="<?= h($field) ?>" data-profile-label="<?= h($label) ?>" data-profile-value="<?= h($profile[$field] ?? "") ?>" aria-label="Edit <?= h($label) ?>"><?php 
else: ?><div class="admin-profile-field"><?php 
endif; ?>
                                <span><?= h($label) ?></span><strong><?= h($profile[$field] ?: "—") ?></strong><?php 
if (in_array($field, ["first_name", "last_name", "nationality", "civil_status"], true)): ?><small>Edit</small></button><?php 
else: ?></div><?php 
endif; ?>
                        <?php 
endforeach; ?>
                    </div>
                </section>
                <section class="profile-copy-form-panel profile-copy-basic-panel" id="profile-contact-panel" hidden>
                    <h2>Contact Information</h2>
                    <div class="profile-copy-about-grid admin-profile-fields">
                        <?php 
foreach (["contact_phone" => "Contact Number", "email" => "Email Address"] as $field => $label): ?>
                            <button class="admin-profile-field" type="button" data-profile-field="<?= h($field) ?>" data-profile-label="<?= h($label) ?>" data-profile-value="<?= h($profile[$field] ?? "") ?>" aria-label="Edit <?= h($label) ?>">
                                <span><?= h($label) ?></span><strong><?= h($profile[$field] ?: "—") ?></strong><small>Edit</small>
                            </button>
                        <?php 
endforeach; ?>
                    </div>
                </section>
            </div>
        </section>

        <dialog class="admin-account-edit-dialog" id="admin-account-edit-dialog" aria-labelledby="admin-account-edit-title">
            <form action="../admin-profile" method="post" class="admin-account-edit-form">
                <input type="hidden" name="action" value="update_profile_field">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                <input type="hidden" name="profile_field" id="admin-account-edit-field">
                <h2 id="admin-account-edit-title">Update profile information</h2>
                <label for="admin-account-edit-value" id="admin-account-edit-label">Information</label>
                <input type="text" id="admin-account-edit-value" name="profile_value" maxlength="254" required>
                <select id="admin-account-edit-civil-status" name="profile_value" hidden disabled>
                    <option value="">Choose civil status</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option><option value="Divorced">Divorced</option>
                </select>
                <p class="admin-account-edit-error" id="admin-account-edit-error" <?= $showProfileDialogError ? "" : "hidden" ?>><?= h($profileFieldError) ?></p>
                <div class="admin-account-edit-actions"><button type="button" class="admin-account-cancel" id="admin-account-edit-cancel">Cancel</button><button type="submit" class="admin-account-save">Save information</button></div>
            </form>
        </dialog>

        <?php 
if ($passwordError !== ""): ?><p class="alert error"><?= h($passwordError) ?></p><?php 
endif; ?>
        <section class="panel change-password-panel" id="change-password-panel" <?= $showPasswordForm ? "" : "hidden" ?>>
            <div class="panel-heading"><div><h2>Change password</h2><p>Enter your current password, then choose a new one.</p></div></div>
            <form action="../admin-profile" class="password-form" method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                <label>New password<input type="password" name="new_password" minlength="8" maxlength="255" autocomplete="new-password" required></label>
                <label>Confirm new password<input type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required></label>
                <button class="change-password-button" type="submit">Save new password</button>
            </form>
        </section>
        <?php 
endif; ?>
    </main>

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

    <script>
        document.querySelectorAll(".admin-profile-tabs button").forEach(function (tab) {
            tab.addEventListener("click", function () {
                document.querySelectorAll(".admin-profile-tabs button").forEach(function (otherTab) {
                    otherTab.classList.remove("is-active");
                    otherTab.setAttribute("aria-selected", "false");
                    document.getElementById(otherTab.getAttribute("aria-controls")).hidden = true;
                });
                tab.classList.add("is-active");
                tab.setAttribute("aria-selected", "true");
                document.getElementById(tab.getAttribute("aria-controls")).hidden = false;
            });
        });
        const adminAccountPhotoInput = document.getElementById("admin-account-photo-input");
        adminAccountPhotoInput?.addEventListener("change", function () {
            if (adminAccountPhotoInput.files.length > 0) adminAccountPhotoInput.form.requestSubmit();
        });
        const accountEditDialog = document.getElementById("admin-account-edit-dialog");
        const accountEditField = document.getElementById("admin-account-edit-field");
        const accountEditLabel = document.getElementById("admin-account-edit-label");
        const accountEditValue = document.getElementById("admin-account-edit-value");
        const accountEditCivilStatus = document.getElementById("admin-account-edit-civil-status");
        const openAccountEdit = function (button) {
            const field = button.dataset.profileField;
            const label = button.dataset.profileLabel;
            const isCivilStatus = field === "civil_status";
            accountEditField.value = field;
            accountEditLabel.textContent = label;
            accountEditValue.hidden = isCivilStatus;
            accountEditValue.disabled = isCivilStatus;
            accountEditCivilStatus.hidden = !isCivilStatus;
            accountEditCivilStatus.disabled = !isCivilStatus;
            if (isCivilStatus) {
                accountEditCivilStatus.value = button.dataset.profileValue;
            } else {
                accountEditValue.value = button.dataset.profileValue;
                accountEditValue.type = field === "email" ? "email" : (field === "contact_phone" ? "tel" : "text");
                accountEditValue.required = ["first_name", "last_name", "email"].includes(field);
                accountEditValue.maxLength = field === "contact_phone" ? 20 : (field === "nationality" ? 80 : (field === "email" ? 254 : 50));
                if (field === "contact_phone") {
                    accountEditValue.pattern = "[0-9+() .-]{0,20}";
                } else {
                    accountEditValue.removeAttribute("pattern");
                }
            }
            accountEditDialog.showModal();
            (isCivilStatus ? accountEditCivilStatus : accountEditValue).focus();
        };
        document.querySelectorAll("[data-profile-field]").forEach(function (button) {
            button.addEventListener("click", function () { openAccountEdit(button); });
        });
        document.getElementById("admin-account-edit-cancel")?.addEventListener("click", function () {
            accountEditDialog.close();
        });
        accountEditDialog?.addEventListener("click", function (event) {
            if (event.target === accountEditDialog) accountEditDialog.close();
        });
        <?php 
if ($showProfileDialogError): ?>
            const attemptedProfileField = <?= json_encode($_POST["profile_field"] ?? "", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const attemptedProfileValue = <?= json_encode($_POST["profile_value"] ?? "", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const attemptedProfileButton = Array.from(document.querySelectorAll("[data-profile-field]")).find(function (button) {
                return button.dataset.profileField === attemptedProfileField;
            });
            if (attemptedProfileButton) {
                openAccountEdit(attemptedProfileButton);
                if (attemptedProfileField === "civil_status") accountEditCivilStatus.value = attemptedProfileValue;
                else accountEditValue.value = attemptedProfileValue;
            }
        <?php 
endif; ?>
        const showPasswordForm = document.getElementById("show-password-form");
        const passwordPanel = document.getElementById("change-password-panel");

        if (showPasswordForm && passwordPanel) {
            if (window.location.hash === "#change-password-panel") {
                passwordPanel.hidden = false;
                showPasswordForm.setAttribute("aria-expanded", "true");
                passwordPanel.scrollIntoView({ behavior: "smooth", block: "start" });
                const firstPasswordInput = passwordPanel.querySelector("input[type='password']");
                if (firstPasswordInput) firstPasswordInput.focus({ preventScroll: true });
            }
            showPasswordForm.addEventListener("click", function () {
                const isOpen = passwordPanel.hidden;
                passwordPanel.hidden = !isOpen;
                showPasswordForm.setAttribute("aria-expanded", isOpen);
            });
        }
    </script>
</body>
</html>
