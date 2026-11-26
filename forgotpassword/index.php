<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . "/database.php";

if (empty($_SESSION["forgot_csrf"])) {
    $_SESSION["forgot_csrf"] = bin2hex(random_bytes(32));
}
if (empty($_SESSION["reset_csrf"])) {
    $_SESSION["reset_csrf"] = bin2hex(random_bytes(32));
}
$message = "";
$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf_token"] ?? "";
    $email = trim($_POST["email"] ?? "");
    if (!hash_equals($_SESSION["forgot_csrf"], $csrf)) {
        $error = "This form expired. Refresh the page and try again.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = "Enter a valid email address.";
    } else {
        try {
            $smtpConfig = require __DIR__ . "/smtp-config.php";
            $smtpHost = trim($smtpConfig["host"] ?? "");
            $smtpUser = trim($smtpConfig["username"] ?? "");
            // Google displays App Passwords in four-character groups; ignore pasted spaces.
            $smtpPass = preg_replace('/\s+/', "", (string) ($smtpConfig["password"] ?? ""));
            $fromEmail = trim($smtpConfig["from_email"] ?? "") ?: $smtpUser;
            if ($smtpHost === "" || $smtpUser === "" || $smtpPass === "" || $fromEmail === "") {
                throw new RuntimeException("SMTP settings are missing");
            }
            $query = $conn->prepare("SELECT user_id, first_name FROM users WHERE email = ? LIMIT 1");
            $query->bind_param("s", $email);
            $query->execute();
            $user = $query->get_result()->fetch_assoc();
            $query->close();

            if ($user) {
                $pin = (string) random_int(100000, 999999);
                $tokenHash = hash("sha256", $pin);
                $userId = (int) $user["user_id"];
                $conn->begin_transaction();
                $delete = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $delete->bind_param("i", $userId);
                $delete->execute();
                $delete->close();
                $insert = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))");
                $insert->bind_param("is", $userId, $tokenHash);
                $insert->execute();
                $insert->close();
                $conn->commit();

                $host = $_SERVER["HTTP_HOST"] ?? "localhost";
                if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
                    throw new RuntimeException("Invalid HTTP host");
                }
                $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
                $basePath = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/forgotpassword/index.php")), "/");
                $resetUrl = $scheme . "://" . $host . $basePath . "/confirm-pin.php?email=" . rawurlencode($email);

                require_once __DIR__ . "/PHPMailer/src/Exception.php";
                require_once __DIR__ . "/PHPMailer/src/PHPMailer.php";
                require_once __DIR__ . "/PHPMailer/src/SMTP.php";
                $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
                $fromName = $smtpConfig["from_name"] ?? "St. Peregrine Institute";
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
                $mailer->setFrom($fromEmail, $fromName);
                $mailer->addAddress($email, $user["first_name"]);
                $mailer->isHTML(true);
                $mailer->Subject = "Your password reset PIN";
                $safeName = htmlspecialchars($user["first_name"], ENT_QUOTES, "UTF-8");
                $mailer->Body = "<p>Hello {$safeName},</p><p>Your password reset PIN is:</p><p style=\"font-size:28px;font-weight:bold;letter-spacing:6px\">{$pin}</p><p>Enter this PIN at <a href=\"{$resetUrl}\">the password reset page</a>. It expires in 10 minutes and can be used once.</p><p>If you did not request this, you can ignore this email.</p>";
                $mailer->AltBody = "Your password reset PIN is {$pin}. Enter it at {$resetUrl}. It expires in 10 minutes and can be used once.";
                $mailer->send();
            }
            // Keep the same response for registered and unregistered addresses.
            $message = "Your request has been sent. Please check your email for the reset PIN.";
        } catch (Throwable $e) {
            if (isset($conn) && $conn->errno === 0) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
            }
            error_log("Password reset request failed: " . $e->getMessage());
            $error = "The reset email could not be sent. Gmail SMTP is not configured or the mail server rejected the request. Check forgotpassword/README.txt.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Request help recovering your St. Peregrine Institute online grading system account.">
    <meta name="theme-color" content="#105334">
    <title>Forgot Password | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="forgot-password.css?v=<?= (int) filemtime(__DIR__ . "/forgot-password.css") ?>">
</head>
<body>
<header class="auth-site-header">
    <a class="auth-site-brand" href="../index.html">
        <img src="../logo.png" alt="">
        <span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span>
    </a>
</header>
<main class="login-wrap">
    <section class="login-card">
        <header class="brand">
            <img class="school-logo" src="../logo.png" alt="St. Peregrine Institute logo">
            <h1>Reset your password</h1>
            <p class="subtitle">We’ll email you a 6-digit PIN to reset your password.</p>
        </header>
        <?php if ($error !== ""): ?><p class="message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["forgot_csrf"], ENT_QUOTES, "UTF-8") ?>">
            <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" maxlength="100" autocomplete="email" placeholder="you@example.com" value="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>" required></div>
            <button class="submit" type="submit">SEND RESET PIN</button>
        </form>
        <p class="register-link"><a href="../index.html">Back to sign in</a></p>
    </section>
</main>
<?php if ($message !== ""): ?>
<div class="modal-overlay" id="pin-modal">
    <section class="success-modal" role="dialog" aria-modal="true" aria-labelledby="pin-modal-title" aria-describedby="pin-modal-message" tabindex="-1">
        <div class="success-icon" aria-hidden="true">✓</div>
        <h2 id="pin-modal-title">Check your email</h2>
        <p id="pin-modal-message"><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></p>
        <a class="modal-action" href="confirm-pin.php?email=<?= htmlspecialchars(urlencode($email), ENT_QUOTES, "UTF-8") ?>">Continue</a>
        <button class="modal-close" type="button" id="close-pin-modal">Close</button>
    </section>
</div>
<script>
    const pinModal = document.getElementById("pin-modal");
    document.body.classList.add("modal-open");
    pinModal.querySelector(".modal-action").focus();
    document.getElementById("close-pin-modal").addEventListener("click", function () {
        pinModal.hidden = true;
        document.body.classList.remove("modal-open");
    });
    pinModal.addEventListener("click", function (event) {
        if (event.target === pinModal) {
            pinModal.hidden = true;
            document.body.classList.remove("modal-open");
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            pinModal.hidden = true;
            document.body.classList.remove("modal-open");
        }
    });
</script>
<?php endif; ?>
</body>
</html>
