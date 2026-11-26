<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/student_schema.php";

if (!isset($_SESSION["profile_csrf"])) {
    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["user_id"];
$profile = null;
$error = "";
$passwordError = "";
$passwordMessage = "";
$showPasswordForm = false;
$emailError = "";
$emailMessage = "";
$showEmailForm = false;
$photoError = "";
$photoMessage = "";
$addressError = "";
$addressMessage = "";
$addressFields = [
    "present_province" => "",
    "present_city" => "",
    "present_street" => "",
    "present_barangay" => "",
    "present_postal" => "",
    "permanent_country" => "",
    "permanent_province" => "",
    "permanent_city" => "",
    "permanent_street" => "",
    "permanent_barangay" => "",
    "permanent_postal" => ""
];
$familyFields = ["first_name" => "", "middle_name" => "", "last_name" => "", "relationship" => "", "address" => "", "mobile" => ""];
$familyError = "";
$familyMessage = "";
$showFamilyPanel = $_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_family";

try {
    ensureStudentProfilePhotoColumn($conn);
    removeLegacyStudentProfilePhotosTable($conn, __DIR__ . "/uploads");
} catch (mysqli_sql_exception $e) {
    error_log("Student profile photo schema error: " . $e->getMessage());
    $photoError = "Profile photos are not available right now.";
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_profile_family (
            user_id INT NOT NULL PRIMARY KEY,
            contact_name VARCHAR(150) NOT NULL DEFAULT '',
            relationship VARCHAR(80) NOT NULL DEFAULT '',
            contact_address VARCHAR(500) NOT NULL DEFAULT '',
            mobile_number VARCHAR(30) NOT NULL DEFAULT '',
            emergency_contact TINYINT(1) NOT NULL DEFAULT 0,
            authorized_access TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_student_profile_family_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )"
    );
} catch (mysqli_sql_exception $e) {
    error_log("Student profile family table error: " . $e->getMessage());
    $familyError = "Emergency contact information is not available right now.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_family") {
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $familyError = "The form expired. Refresh the page and try again.";
    } else {
        foreach (["first_name" => 50, "middle_name" => 50, "last_name" => 50, "relationship" => 80, "address" => 500, "mobile" => 30] as $field => $limit) {
            $familyFields[$field] = trim(is_string($_POST["family_" . $field] ?? null) ? $_POST["family_" . $field] : "");
            if (mb_strlen($familyFields[$field]) > $limit) $familyError = "One of the emergency contact fields is too long.";
        }
        $familyFields["name"] = trim(implode(" ", array_filter([
            $familyFields["first_name"], $familyFields["middle_name"], $familyFields["last_name"]
        ], static function ($part) { return $part !== ""; })));
        if (mb_strlen($familyFields["name"]) > 150) $familyError = "The emergency contact name is too long.";
        if ($familyError === "") {
            try {
                $saveFamily = $conn->prepare(
                    "INSERT INTO student_profile_family
                        (user_id, contact_name, relationship, contact_address, mobile_number)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE contact_name = VALUES(contact_name), relationship = VALUES(relationship),
                        contact_address = VALUES(contact_address), mobile_number = VALUES(mobile_number)"
                );
                $saveFamily->bind_param("issss", $userId, $familyFields["name"], $familyFields["relationship"], $familyFields["address"], $familyFields["mobile"]);
                $saveFamily->execute();
                $saveFamily->close();
                $familyMessage = "Emergency contact information saved.";
            } catch (mysqli_sql_exception $e) {
                error_log("Student family update error: " . $e->getMessage());
                $familyError = "We could not save your emergency contact right now.";
            }
        }
    }
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_profile_addresses (
            user_id INT NOT NULL PRIMARY KEY,
            present_province VARCHAR(100) NOT NULL DEFAULT '',
            present_city VARCHAR(100) NOT NULL DEFAULT '',
            present_street VARCHAR(255) NOT NULL DEFAULT '',
            present_barangay VARCHAR(100) NOT NULL DEFAULT '',
            present_postal VARCHAR(20) NOT NULL DEFAULT '',
            permanent_country VARCHAR(100) NOT NULL DEFAULT '',
            permanent_province VARCHAR(100) NOT NULL DEFAULT '',
            permanent_city VARCHAR(100) NOT NULL DEFAULT '',
            permanent_street VARCHAR(255) NOT NULL DEFAULT '',
            permanent_barangay VARCHAR(100) NOT NULL DEFAULT '',
            permanent_postal VARCHAR(20) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_student_profile_addresses_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )"
    );
} catch (mysqli_sql_exception $e) {
    error_log("Student profile address table error: " . $e->getMessage());
    $addressError = "Address information is not available right now.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_address") {
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $addressError = "The form expired. Refresh the page and try again.";
    } else {
        foreach ($addressFields as $field => $unused) {
            $addressFields[$field] = trim(is_string($_POST[$field] ?? null) ? $_POST[$field] : "");
        }
        $maxLengths = ["present_province" => 100, "present_city" => 100, "present_street" => 255,
            "present_barangay" => 100, "present_postal" => 20, "permanent_country" => 100,
            "permanent_province" => 100, "permanent_city" => 100, "permanent_street" => 255,
            "permanent_barangay" => 100, "permanent_postal" => 20];
        foreach ($maxLengths as $field => $limit) {
            if (strlen($addressFields[$field]) > $limit) {
                $addressError = "One of the address fields is too long.";
                break;
            }
        }
        if ($addressError === "") {
            try {
                $saveAddress = $conn->prepare(
                    "INSERT INTO student_profile_addresses
                        (user_id, present_province, present_city, present_street, present_barangay, present_postal,
                         permanent_country, permanent_province, permanent_city, permanent_street, permanent_barangay, permanent_postal)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        present_province = VALUES(present_province), present_city = VALUES(present_city),
                        present_street = VALUES(present_street), present_barangay = VALUES(present_barangay),
                        present_postal = VALUES(present_postal), permanent_country = VALUES(permanent_country),
                        permanent_province = VALUES(permanent_province), permanent_city = VALUES(permanent_city),
                        permanent_street = VALUES(permanent_street), permanent_barangay = VALUES(permanent_barangay),
                        permanent_postal = VALUES(permanent_postal)"
                );
                $saveAddress->bind_param(
                    "isssssssssss",
                    $userId,
                    $addressFields["present_province"], $addressFields["present_city"], $addressFields["present_street"],
                    $addressFields["present_barangay"], $addressFields["present_postal"], $addressFields["permanent_country"],
                    $addressFields["permanent_province"], $addressFields["permanent_city"], $addressFields["permanent_street"],
                    $addressFields["permanent_barangay"], $addressFields["permanent_postal"]
                );
                $saveAddress->execute();
                $saveAddress->close();
                $addressMessage = "Address information saved.";
            } catch (mysqli_sql_exception $e) {
                error_log("Student address update error: " . $e->getMessage());
                $addressError = "We could not save your address right now.";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_photo") {
    $token = $_POST["csrf_token"] ?? "";
    $upload = $_FILES["profile_photo"] ?? null;

    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $photoError = "The form expired. Refresh the page and try again.";
    } elseif (!$upload || $upload["error"] !== UPLOAD_ERR_OK) {
        $photoError = "Choose an image to upload.";
    } elseif ($upload["size"] > 5 * 1024 * 1024) {
        $photoError = "The image must be 5 MB or smaller.";
    } else {
        $imageInfo = @getimagesize($upload["tmp_name"]);
        $allowedTypes = ["image/jpeg" => "jpg", "image/png" => "png", "image/gif" => "gif", "image/webp" => "webp"];
        $mimeType = $imageInfo["mime"] ?? "";
        if (!$imageInfo || !isset($allowedTypes[$mimeType])) {
            $photoError = "Choose a valid JPG, PNG, GIF, or WebP image.";
        } else {
            try {
                $uploadDirectory = __DIR__ . "/uploads";
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException("Could not create student uploads directory.");
                }
                $photoName = "student-" . $userId . "-" . bin2hex(random_bytes(12)) . "." . $allowedTypes[$mimeType];
                $photoPath = $uploadDirectory . DIRECTORY_SEPARATOR . $photoName;
                if (!move_uploaded_file($upload["tmp_name"], $photoPath)) {
                    throw new RuntimeException("Could not save student profile photo.");
                }
                $savePhoto = $conn->prepare("UPDATE students SET profile_photo = ? WHERE user_id = ?");
                $savePhoto->bind_param("si", $photoName, $userId);
                $savePhoto->execute();
                $savePhoto->close();
                $photoMessage = "Your profile picture has been updated.";
            } catch (Throwable $e) {
                if (isset($photoPath) && is_file($photoPath)) @unlink($photoPath);
                error_log("Profile photo update error: " . $e->getMessage());
                $photoError = "We could not save your profile picture right now.";
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

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_email") {
    $showEmailForm = true;
    $token = $_POST["csrf_token"] ?? "";
    $newEmail = trim($_POST["new_email"] ?? "");
    $currentPassword = $_POST["current_password"] ?? "";

    if (!hash_equals($_SESSION["profile_csrf"], $token)) {
        $emailError = "The form expired. Refresh the page and try again.";
    } elseif (strlen($newEmail) > 254 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $emailError = "Enter a valid email address.";
    } else {
        try {
            $accountQuery = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $accountQuery->bind_param("i", $userId);
            $accountQuery->execute();
            $account = $accountQuery->get_result()->fetch_assoc();
            $accountQuery->close();

            if (!$account || !password_verify($currentPassword, $account["password"])) {
                $emailError = "Your current password is incorrect.";
            } else {
                $duplicateQuery = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
                $duplicateQuery->bind_param("si", $newEmail, $userId);
                $duplicateQuery->execute();
                $duplicate = $duplicateQuery->get_result()->fetch_assoc();
                $duplicateQuery->close();

                if ($duplicate) {
                    $emailError = "That email address is already in use.";
                } else {
                    $emailUpdate = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                    $emailUpdate->bind_param("si", $newEmail, $userId);
                    $emailUpdate->execute();
                    $emailUpdate->close();
                    $emailMessage = "Your email address has been updated.";
                    $showEmailForm = false;
                    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
                }
            }
        } catch (mysqli_sql_exception $e) {
            error_log("Email update error: " . $e->getMessage());
            $emailError = "We could not update your email right now.";
        }
    }
}

try {
    $profileQuery = $conn->prepare(
        "SELECT lrn, first_name, middle_name, last_name, suffix, email
         FROM users
         WHERE user_id = ?"
    );
    $profileQuery->bind_param("i", $userId);
    $profileQuery->execute();
    $profile = $profileQuery->get_result()->fetch_assoc();
    $profileQuery->close();

    if ($profile) {
        try {
            $genderQuery = $conn->prepare("SELECT gender FROM students WHERE user_id = ? LIMIT 1");
            $genderQuery->bind_param("i", $userId);
            $genderQuery->execute();
            $genderRow = $genderQuery->get_result()->fetch_assoc();
            $genderQuery->close();
            $profile["gender"] = $genderRow["gender"] ?? null;
        } catch (mysqli_sql_exception $e) {
            error_log("Student profile gender lookup error: " . $e->getMessage());
        }

        try {
            $trackQuery = $conn->prepare(
                "SELECT tracks.track_name, tracks.strand_name
                 FROM students
                 LEFT JOIN tracks ON tracks.track_id = students.track_id
                 WHERE students.user_id = ? LIMIT 1"
            );
            $trackQuery->bind_param("i", $userId);
            $trackQuery->execute();
            $profile = array_merge($profile, $trackQuery->get_result()->fetch_assoc() ?: []);
            $trackQuery->close();
        } catch (mysqli_sql_exception $e) {
            error_log("Student profile track lookup error: " . $e->getMessage());
        }

        try {
            $enrollmentQuery = $conn->prepare(
                "SELECT sections.section_name, sections.grade_level
                 FROM students
                 LEFT JOIN enrollments ON enrollments.student_id = students.student_id
                 LEFT JOIN sections ON sections.section_id = enrollments.section_id
                 WHERE students.user_id = ?
                 ORDER BY enrollments.enrollment_date DESC
                 LIMIT 1"
            );
            $enrollmentQuery->bind_param("i", $userId);
            $enrollmentQuery->execute();
            $profile = array_merge($profile, $enrollmentQuery->get_result()->fetch_assoc() ?: []);
            $enrollmentQuery->close();
        } catch (mysqli_sql_exception $e) {
            error_log("Student profile enrollment lookup error: " . $e->getMessage());
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log("Profile page error: " . $e->getMessage());
    $error = "We could not load your profile right now.";
}

$nameParts = array_filter(
    [$profile["first_name"] ?? "", $profile["last_name"] ?? ""],
    static function ($part) { return trim((string) $part) !== ""; }
);
$userName = trim(implode(" ", $nameParts)) ?: "User";
$userRole = ucfirst($_SESSION["role"] ?? "user");
$familyName = $familyFields["name"] ?? "";
$familyRelationship = $familyFields["relationship"];
$familyAddress = $familyFields["address"];
$familyMobile = $familyFields["mobile"];
if ($_SERVER["REQUEST_METHOD"] !== "POST" || ($_POST["action"] ?? "") !== "update_family") {
    try {
        $familyQuery = $conn->prepare("SELECT contact_name, relationship, contact_address, mobile_number FROM student_profile_family WHERE user_id = ?");
        $familyQuery->bind_param("i", $userId);
        $familyQuery->execute();
        $savedFamily = $familyQuery->get_result()->fetch_assoc();
        $familyQuery->close();
        if ($savedFamily) {
            $savedNameParts = preg_split('/\s+/', trim((string) $savedFamily["contact_name"]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $savedFirstName = array_shift($savedNameParts) ?? "";
            $savedLastName = count($savedNameParts) > 0 ? array_pop($savedNameParts) : "";
            $familyFields = ["first_name" => $savedFirstName, "middle_name" => implode(" ", $savedNameParts), "last_name" => $savedLastName,
                "name" => trim((string) $savedFamily["contact_name"]), "relationship" => $savedFamily["relationship"], "address" => $savedFamily["contact_address"], "mobile" => $savedFamily["mobile_number"]];
            $familyName = $familyFields["name"];
            $familyRelationship = $familyFields["relationship"];
            $familyAddress = $familyFields["address"];
            $familyMobile = $familyFields["mobile"];
        }
    } catch (mysqli_sql_exception $e) {
        error_log("Student profile family lookup error: " . $e->getMessage());
        if ($familyError === "") $familyError = "Emergency contact information is not available right now.";
    }
}
if ($_SERVER["REQUEST_METHOD"] !== "POST" || ($_POST["action"] ?? "") !== "update_address") {
    try {
        $addressQuery = $conn->prepare("SELECT present_province, present_city, present_street, present_barangay, present_postal, permanent_country, permanent_province, permanent_city, permanent_street, permanent_barangay, permanent_postal FROM student_profile_addresses WHERE user_id = ?");
        $addressQuery->bind_param("i", $userId);
        $addressQuery->execute();
        $savedAddress = $addressQuery->get_result()->fetch_assoc();
        $addressQuery->close();
        if ($savedAddress) $addressFields = array_merge($addressFields, $savedAddress);
    } catch (mysqli_sql_exception $e) {
        error_log("Student profile address lookup error: " . $e->getMessage());
        if ($addressError === "") $addressError = "Address information is not available right now.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('student/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage your student profile in the St. Peregrine Institute online grading system.">
    <meta name="theme-color" content="#105334">
    <title>My Profile | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="css/profile.css?v=<?= (int) filemtime(__DIR__ . "/css/profile.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body class="student-profile-copy-page">
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
                <a class="menu-link" href="../student-dashboard">Dashboard</a>
                <a class="menu-link" href="../student-scores">Scores</a>
                <a class="menu-link" href="../student-possible-grades">Grades</a>
                <a class="menu-link" href="../student-messages">Contact</a>
                <a class="menu-link" href="../student-profile" aria-current="page">My Profile</a>
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
            <section class="profile-copy-hero">
                <?php 
if ($photoError !== ""): ?>
                    <p class="alert error profile-photo-alert"><?= h($photoError) ?></p>
                <?php 
elseif ($photoMessage !== ""): ?>
                    <p class="alert success profile-photo-alert"><?= h($photoMessage) ?></p>
                <?php 
endif; ?>
                <aside class="profile-copy-sidebar">
                    <div class="profile-copy-avatar-column">
                        <img class="profile-copy-photo" src="../student-profile-picture?v=<?= urlencode((string) time()) ?>" width="150" height="150" alt="Profile picture of <?= h($userName) ?>">
                        <div class="profile-copy-avatar-name"><h2><?= h($userName) ?></h2><p><?= h(trim(($profile["track_name"] ?? "") . (($profile["strand_name"] ?? "") !== "" ? " – " . $profile["strand_name"] : "")) ?: "Student") ?></p></div>
                        <div class="profile-copy-actions">
                            <form action="../student-profile" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_photo">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                                <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                                <label class="profile-copy-action-button">Update Profile<input id="profile-photo-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" required></label>
                            </form>
                            <button class="profile-copy-action-button" type="button" id="show-password-form" aria-expanded="<?= $showPasswordForm ? "true" : "false" ?>" aria-controls="change-password-panel">Change Password</button>
                            <button class="profile-copy-action-button" type="button" id="show-email-form" aria-expanded="<?= $showEmailForm ? "true" : "false" ?>" aria-controls="change-email-panel">Update Email</button>
                        </div>
                    </div>
                </aside>
                <div class="profile-copy-intro">
                    <h2>Student Details</h2>
                    <p>Your profile information</p>
                    <div class="profile-copy-navigation">
                        <nav class="profile-copy-tabs" aria-label="Profile sections">
                            <button type="button" class="<?= $showFamilyPanel ? "" : "is-active" ?>" aria-selected="<?= $showFamilyPanel ? "false" : "true" ?>" aria-controls="profile-about-panel"><svg class="profile-tab-icon" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"></circle><path d="M8 7v4M8 4.5h.01"></path></svg> About Me</button>
                            <button type="button" class="<?= $showFamilyPanel ? "is-active" : "" ?>" aria-selected="<?= $showFamilyPanel ? "true" : "false" ?>" aria-controls="profile-family-panel">Emergency</button>
                        </nav>
                    </div>
                    <section class="profile-copy-form-panel profile-copy-basic-panel" id="profile-about-panel" <?= $showFamilyPanel ? "hidden" : "" ?>>
                        <h2><svg class="profile-section-icon" viewBox="0 0 18 18" aria-hidden="true"><circle cx="9" cy="9" r="7.5"></circle><path d="M9 8v5M9 5.2h.01"></path></svg> Basic Information</h2>
                        <div class="profile-copy-about-grid">
                            <?php 
foreach ([
                                "First Name" => $profile["first_name"] ?? "",
                                "Middle Name" => $profile["middle_name"] ?? "",
                                "Last Name" => $profile["last_name"] ?? "",
                                "Suffix" => $profile["suffix"] ?? "",
                                "LRN" => $profile["lrn"] ?? "",
                                "Section" => $profile["section_name"] ?? "",
                                "Year Level" => $profile["grade_level"] ?? "",
                                "Gender" => $profile["gender"] ?? "",
                                "Email Address" => $profile["email"] ?? ""
                            ] as $aboutLabel => $aboutValue): ?>
                                <?php 
if (trim((string) $aboutValue) !== ""): ?><div><span><?= h($aboutLabel) ?></span><strong><?= h($aboutValue) ?></strong></div><?php 
endif; ?>
                            <?php 
endforeach; ?>
                        </div>
                    </section>
                    <section class="profile-copy-form-panel profile-copy-basic-panel profile-family-panel" id="profile-family-panel" <?= $showFamilyPanel ? "" : "hidden" ?>>
                        <h2>Emergency Contact</h2>
                        <?php 
if ($familyError !== ""): ?><p class="alert error" role="alert"><?= h($familyError) ?></p><?php 
elseif ($familyMessage !== ""): ?><p class="alert success" role="status"><?= h($familyMessage) ?></p><?php 
endif; ?>
                        <?php 
$emergencyDetails = ["Contact name" => $familyName, "Relationship" => $familyRelationship, "Contact number" => $familyMobile, "Address" => $familyAddress]; ?>
                        <?php 
$hasEmergencyDetails = count(array_filter($emergencyDetails, static function ($value) { return trim((string) $value) !== ""; })) > 0; ?>
                        <?php 
if ($hasEmergencyDetails): ?>
                            <div class="profile-copy-about-grid profile-emergency-details">
                                <?php 
foreach ($emergencyDetails as $detailLabel => $detailValue): ?>
                                    <?php 
if (trim((string) $detailValue) !== ""): ?><div><span><?= h($detailLabel) ?></span><strong><?= h($detailValue) ?></strong></div><?php 
endif; ?>
                                <?php 
endforeach; ?>
                            </div>
                        <?php 
else: ?>
                            <p class="profile-emergency-empty">No emergency contact has been provided yet.</p>
                        <?php 
endif; ?>
                        <button class="profile-copy-action-button profile-family-save" type="button" id="show-family-form" aria-expanded="<?= $showFamilyPanel && $familyError !== "" ? "true" : "false" ?>" aria-controls="update-family-contact-modal"><?= $hasEmergencyDetails ? "Update Emergency Contact" : "Complete Emergency Contact" ?></button>
                    </section>
                </div>
            </section>
        <?php 
endif; ?>
    </main>

    <?php 
if ($profile): ?>
        <div class="dashboard-modal-overlay" id="change-password-panel" <?= $showPasswordForm ? "" : "hidden" ?>>
            <section class="dashboard-form-modal" role="dialog" aria-modal="true" aria-labelledby="change-password-title" tabindex="-1">
                <div class="dashboard-form-modal-heading">
                    <div><h2 id="change-password-title">Change password</h2><p>Enter your current password, then choose a new one.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
                <form action="../student-profile" class="password-form" method="post">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <?php 
if ($passwordError !== ""): ?><p class="alert error modal-form-alert"><?= h($passwordError) ?></p><?php 
endif; ?>
                    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label>New password
                        <input id="change-new-password" type="password" name="new_password" minlength="8" maxlength="255" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9\s]).{8,255}" title="Use 8–255 characters with uppercase and lowercase letters and a special character." autocomplete="new-password" required>
                        <span class="password-constraints">atleast 8 characters, including uppercase and lowercase letters and a special character.</span>
                        <div class="meter change-password-meter" aria-live="polite">
                            <div class="meter-track"><span class="meter-fill" id="change-password-meter-fill"></span></div>
                            <span class="meter-label" id="change-password-meter-label">Password strength</span>
                        </div>
                    </label>
                    <label>Confirm new password<input type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required></label>
                    <div class="dashboard-form-modal-actions">
                        <button class="dashboard-modal-close" type="button">Cancel</button>
                        <button class="change-password-button" type="submit">Save new password</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="dashboard-modal-overlay" id="change-email-panel" <?= $showEmailForm ? "" : "hidden" ?>>
            <section class="dashboard-form-modal" role="dialog" aria-modal="true" aria-labelledby="change-email-title" tabindex="-1">
                <div class="dashboard-form-modal-heading">
                    <div><h2 id="change-email-title">Update email</h2><p>Enter the new email address for your account.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
                <form action="../student-profile" class="password-form" method="post">
                    <input type="hidden" name="action" value="change_email">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <?php 
if ($emailError !== ""): ?><p class="alert error modal-form-alert"><?= h($emailError) ?></p><?php 
endif; ?>
                    <label>New email address<input type="email" name="new_email" maxlength="254" value="<?= h($_POST["new_email"] ?? "") ?>" autocomplete="email" required></label>
                    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <div class="dashboard-form-modal-actions">
                        <button class="dashboard-modal-close" type="button">Cancel</button>
                        <button class="change-password-button" type="submit">Save email</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="dashboard-modal-overlay" id="update-family-contact-modal" data-trigger-id="show-family-form" <?= $showFamilyPanel && $familyError !== "" ? "" : "hidden" ?>>
            <section class="dashboard-form-modal profile-emergency-modal" role="dialog" aria-modal="true" aria-labelledby="emergency-contact-title" tabindex="-1">
                <div class="dashboard-form-modal-heading">
                    <div><h2 id="emergency-contact-title"><?= $hasEmergencyDetails ? "Update Emergency Contact" : "Complete Emergency Contact" ?></h2><p>Add the person the school can contact in an emergency.</p></div>
                    <button class="dashboard-modal-close" type="button" aria-label="Close">×</button>
                </div>
                <form action="../student-profile" class="password-form profile-emergency-form" method="post">
                    <input type="hidden" name="action" value="update_family">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION["profile_csrf"]) ?>">
                    <?php 
if ($familyError !== ""): ?><p class="alert error modal-form-alert" role="alert"><?= h($familyError) ?></p><?php 
endif; ?>
                    <label>First name<input type="text" name="family_first_name" maxlength="50" value="<?= h($familyFields["first_name"]) ?>" required></label>
                    <label>Middle name<input type="text" name="family_middle_name" maxlength="50" value="<?= h($familyFields["middle_name"]) ?>"></label>
                    <label>Last name<input type="text" name="family_last_name" maxlength="50" value="<?= h($familyFields["last_name"]) ?>" required></label>
                    <label>Relationship<input type="text" name="family_relationship" maxlength="80" value="<?= h($familyFields["relationship"]) ?>" placeholder="e.g. Mother, Father, Guardian" required></label>
                    <label>Contact number<input type="tel" name="family_mobile" maxlength="30" value="<?= h($familyFields["mobile"]) ?>" autocomplete="tel" required></label>
                    <label>Address<textarea name="family_address" maxlength="500" rows="2"><?= h($familyFields["address"]) ?></textarea></label>
                    <div class="dashboard-form-modal-actions">
                        <button class="dashboard-modal-close" type="button">Cancel</button>
                        <button class="change-password-button" type="submit">Save Emergency Contact</button>
                    </div>
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
if ($emailMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="email-success-modal">
            <section class="dashboard-success-modal" role="dialog" aria-modal="true" aria-labelledby="email-success-title" aria-describedby="email-success-message" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="email-success-title">Email updated</h2>
                <p id="email-success-message"><?= h($emailMessage) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>

    <script>
        const showPasswordForm = document.getElementById("show-password-form");
        const passwordPanel = document.getElementById("change-password-panel");
        const profilePhotoInput = document.getElementById("profile-photo-input");
        const showEmailForm = document.getElementById("show-email-form");
        const emailPanel = document.getElementById("change-email-panel");
        const showFamilyForm = document.getElementById("show-family-form");
        const familyContactModal = document.getElementById("update-family-contact-modal");
        const newPasswordInput = document.getElementById("change-new-password");

        if (newPasswordInput) {
            newPasswordInput.addEventListener("input", function () {
                const value = newPasswordInput.value;
                let score = 0;
                if (value.length >= 8) score++;
                if (/[a-z]/.test(value)) score++;
                if (/[A-Z]/.test(value)) score++;
                if (/[^A-Za-z0-9\s]/.test(value)) score++;

                document.getElementById("change-password-meter-fill").style.width = (score * 25) + "%";
                document.getElementById("change-password-meter-label").textContent = score === 0
                    ? "Password strength"
                    : ["Weak", "Fair", "Good", "Strong"][score - 1];
            });
        }

        if (showPasswordForm && passwordPanel) {
            showPasswordForm.addEventListener("click", function () {
                passwordPanel.hidden = false;
                passwordPanel.dataset.triggerId = "show-password-form";
                document.body.classList.add("dashboard-modal-open");
                showPasswordForm.setAttribute("aria-expanded", "true");
                passwordPanel.querySelector("input[name='current_password']").focus();
            });
        }

        if (profilePhotoInput) {
            profilePhotoInput.addEventListener("change", function () {
                if (profilePhotoInput.files.length > 0) profilePhotoInput.form.requestSubmit();
            });
        }

        if (showEmailForm && emailPanel) {
            showEmailForm.addEventListener("click", function () {
                emailPanel.hidden = false;
                emailPanel.dataset.triggerId = "show-email-form";
                document.body.classList.add("dashboard-modal-open");
                showEmailForm.setAttribute("aria-expanded", "true");
                emailPanel.querySelector("input[name='new_email']").focus();
            });
        }

        if (showFamilyForm && familyContactModal) {
            showFamilyForm.addEventListener("click", function () {
                familyContactModal.hidden = false;
                familyContactModal.dataset.triggerId = "show-family-form";
                document.body.classList.add("dashboard-modal-open");
                showFamilyForm.setAttribute("aria-expanded", "true");
                familyContactModal.querySelector("input[name='family_first_name']").focus();
            });
        }
    </script>
    <script>
        document.querySelectorAll(".profile-copy-tabs button").forEach(function (tab) {
            tab.addEventListener("click", function () {
                document.querySelectorAll(".profile-copy-tabs button").forEach(function (item) {
                    item.classList.toggle("is-active", item === tab);
                    item.setAttribute("aria-selected", item === tab ? "true" : "false");
                });
                document.querySelectorAll(".profile-copy-form-panel").forEach(function (panel) {
                    panel.hidden = panel.id !== tab.getAttribute("aria-controls");
                });
            });
        });
    </script>
</body>
</html>
