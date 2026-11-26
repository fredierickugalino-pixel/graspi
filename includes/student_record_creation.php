<?php

function validateRegistrarStudent(array $input): array
{
    $student = [];
    foreach (["lrn", "first_name", "middle_name", "last_name", "suffix", "email", "gender", "track_id", "section_id"] as $field) {
        $student[$field] = trim((string) ($input[$field] ?? ""));
    }

    if (!preg_match('/^[0-9]{12,20}$/', $student["lrn"])) {
        throw new InvalidArgumentException("LRN must contain 12 to 20 digits.");
    }
    if ($student["first_name"] === "" || $student["last_name"] === "") {
        throw new InvalidArgumentException("First name and last name are required.");
    }
    if (
        mb_strlen($student["first_name"]) > 50 || mb_strlen($student["middle_name"]) > 50 ||
        mb_strlen($student["last_name"]) > 50 || mb_strlen($student["suffix"]) > 10
    ) {
        throw new InvalidArgumentException("A name field exceeds its allowed length.");
    }
    if (!filter_var($student["email"], FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/i', $student["email"])) {
        throw new InvalidArgumentException("Use a valid Gmail address for the student.");
    }
    if (!in_array($student["gender"], ["Female", "Male", "Prefer not to say"], true)) {
        throw new InvalidArgumentException("Choose a valid gender option.");
    }
    if (!ctype_digit($student["track_id"]) || !ctype_digit($student["section_id"])) {
        throw new InvalidArgumentException("Choose a valid track and section.");
    }

    $student["track_id"] = (int) $student["track_id"];
    $student["section_id"] = (int) $student["section_id"];
    return $student;
}

function validateRegistrarPlacement(mysqli $conn, array $student): void
{
    $trackCheck = $conn->prepare("SELECT track_id FROM tracks WHERE track_id = ?");
    $trackCheck->bind_param("i", $student["track_id"]);
    $trackCheck->execute();
    $validTrack = $trackCheck->get_result()->num_rows === 1;
    $trackCheck->close();

    $sectionCheck = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ? AND track_id = ? AND is_archived = 0");
    $sectionCheck->bind_param("ii", $student["section_id"], $student["track_id"]);
    $sectionCheck->execute();
    $validSection = $sectionCheck->get_result()->num_rows === 1;
    $sectionCheck->close();
    if (!$validTrack || !$validSection) {
        throw new InvalidArgumentException("The selected track and active section do not match.");
    }
}

function registrarStudentAlreadyExists(mysqli $conn, array $student): bool
{
    $check = $conn->prepare("SELECT user_id FROM users WHERE lrn = ? OR LOWER(email) = LOWER(?) LIMIT 1");
    $check->bind_param("ss", $student["lrn"], $student["email"]);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    return $exists;
}

function generateTemporaryStudentPassword(array &$usedPasswords = []): string
{
    do {
        $password = rtrim(strtr(base64_encode(random_bytes(18)), "+/", "-_"), "=");
    } while (
        !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9\s]/', $password) ||
        isset($usedPasswords[hash("sha256", $password)])
    );
    $usedPasswords[hash("sha256", $password)] = true;
    return $password;
}

function createRegistrarStudent(mysqli $conn, array $student, string $temporaryPassword): int
{
    validateRegistrarPlacement($conn, $student);
    $middleName = $student["middle_name"] !== "" ? $student["middle_name"] : null;
    $suffix = $student["suffix"] !== "" ? $student["suffix"] : null;
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $role = "Student";
    $approvalStatus = "approved";
    $mustChangePassword = 1;

    $addUser = $conn->prepare(
        "INSERT INTO users (lrn, first_name, middle_name, last_name, suffix, email, password, role, approval_status, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $addUser->bind_param(
        "sssssssssi",
        $student["lrn"], $student["first_name"], $middleName, $student["last_name"],
        $suffix, $student["email"], $passwordHash, $role, $approvalStatus, $mustChangePassword
    );
    $addUser->execute();
    $userId = (int) $conn->insert_id;
    $addUser->close();

    $addStudent = $conn->prepare("INSERT INTO students (user_id, track_id, gender) VALUES (?, ?, ?)");
    $addStudent->bind_param("iis", $userId, $student["track_id"], $student["gender"]);
    $addStudent->execute();
    $studentId = (int) $conn->insert_id;
    $addStudent->close();

    $manilaNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
    $yearStart = (int) $manilaNow->format("Y");
    if ((int) $manilaNow->format("n") < 6) {
        $yearStart--;
    }
    $schoolYear = $yearStart . "-" . ($yearStart + 1);
    $enrollmentDate = $manilaNow->format("Y-m-d");
    $status = "Enrolled";
    $addEnrollment = $conn->prepare(
        "INSERT INTO enrollments (student_id, section_id, school_year, enrollment_date, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $addEnrollment->bind_param("iisss", $studentId, $student["section_id"], $schoolYear, $enrollmentDate, $status);
    $addEnrollment->execute();
    $addEnrollment->close();

    return $userId;
}

function registrarLoginUrl(): string
{
    $configuredBaseUrl = rtrim(trim((string) getenv("APP_BASE_URL")), "/");
    if ($configuredBaseUrl !== "" && preg_match('#^https?://[A-Za-z0-9.\-]+(?::[0-9]+)?(?:/[A-Za-z0-9._~-]+)*$#i', $configuredBaseUrl)) {
        return $configuredBaseUrl . "/index.html";
    }

    $serverName = preg_replace('/[^A-Za-z0-9.\-]/', "", (string) ($_SERVER["SERVER_NAME"] ?? "localhost"));
    $requestHost = preg_replace('/[^A-Za-z0-9.\-]/', "", (string) ($_SERVER["HTTP_HOST"] ?? ""));
    $host = $serverName !== "" && $requestHost === $serverName ? $requestHost : ($serverName ?: "localhost");
    $port = (int) ($_SERVER["SERVER_PORT"] ?? 80);
    if ($port > 0 && $port !== 80 && $port !== 443) {
        $host .= ":" . $port;
    }
    $https = !empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off";
    return ($https ? "https://" : "http://") . $host . appUrl("index.html");
}

function sendRegistrarStudentCredentials(array $student, string $temporaryPassword): void
{
    $smtpConfig = require __DIR__ . "/../forgotpassword/smtp-config.php";
    $host = trim((string) ($smtpConfig["host"] ?? ""));
    $smtpUser = trim((string) ($smtpConfig["username"] ?? ""));
    $smtpPassword = preg_replace('/\s+/', "", (string) ($smtpConfig["password"] ?? ""));
    $fromEmail = trim((string) ($smtpConfig["from_email"] ?? "")) ?: $smtpUser;
    $fromName = trim((string) ($smtpConfig["from_name"] ?? "St. Peregrine Institute"));
    if ($host === "" || $smtpUser === "" || $smtpPassword === "" || $fromEmail === "") {
        throw new RuntimeException("Gmail SMTP is not configured.");
    }

    require_once __DIR__ . "/../forgotpassword/PHPMailer/src/Exception.php";
    require_once __DIR__ . "/../forgotpassword/PHPMailer/src/PHPMailer.php";
    require_once __DIR__ . "/../forgotpassword/PHPMailer/src/SMTP.php";
    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->SMTPAuth = true;
    $mailer->Username = $smtpUser;
    $mailer->Password = $smtpPassword;
    $mailer->SMTPSecure = strtolower((string) ($smtpConfig["encryption"] ?? "tls")) === "ssl"
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) ($smtpConfig["port"] ?? 587);
    $mailer->CharSet = "UTF-8";
    $mailer->setFrom($fromEmail, $fromName);
    $mailer->addAddress($student["email"], trim($student["first_name"] . " " . $student["last_name"]));
    $mailer->isHTML(true);
    $mailer->Subject = "Your St. Peregrine Institute Student Account";

    $fullName = trim(implode(" ", array_filter([
        $student["first_name"], $student["middle_name"], $student["last_name"], $student["suffix"]
    ], static fn($part) => trim((string) $part) !== "")));
    $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $safeEmail = htmlspecialchars($student["email"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $safeLrn = htmlspecialchars($student["lrn"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $loginUrl = registrarLoginUrl();
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $mailer->Body = "<p>Dear {$safeName},</p>
        <p>Your student account for the St. Peregrine Institute Web-Based Grading System has been successfully created.</p>
        <p><strong>Account Information</strong></p>
        <ul><li>Name: {$safeName}</li><li>Gmail: {$safeEmail}</li><li>Username/LRN: {$safeLrn}</li><li>Temporary Password: {$safePassword}</li></ul>
        <p><strong>Login Here:</strong> <a href=\"{$safeLoginUrl}\">{$safeLoginUrl}</a></p>
        <p>For security, change your temporary password immediately after your first login. Please do not share your login credentials with anyone.</p>
        <p>Thank you!<br>St. Peregrine Institute<br>Web-Based Grading System</p>";
    $mailer->AltBody = "Dear {$fullName},\n\nYour St. Peregrine Institute student account has been created.\n\nGmail: {$student["email"]}\nUsername/LRN: {$student["lrn"]}\nTemporary Password: {$temporaryPassword}\nLogin: {$loginUrl}\n\nChange your temporary password immediately after your first login. Please do not share your login credentials.\n\nSt. Peregrine Institute\nWeb-Based Grading System";
    try {
        $mailer->send();
    } finally {
        $mailer->clearAddresses();
        $mailer->Body = "";
        $mailer->AltBody = "";
        $mailer->Password = "";
        unset($mailer, $temporaryPassword, $safePassword);
    }
}
