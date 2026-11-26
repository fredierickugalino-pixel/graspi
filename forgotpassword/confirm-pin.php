<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . "/database.php";

if (empty($_SESSION["reset_csrf"])) {
    $_SESSION["reset_csrf"] = bin2hex(random_bytes(32));
}
$error = "";
$success = "";
$email = trim($_POST["email"] ?? $_GET["email"] ?? "");
$pin = trim($_POST["pin"] ?? "");
$pinVerified = !empty($_SESSION["reset_user_id"]) && !empty($_SESSION["reset_pin_hash"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["reset_csrf"], $csrf)) {
        $error = "This form expired. Refresh the page and try again.";
    } elseif (($_POST["action"] ?? "") === "verify_pin") {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || !preg_match('/^[0-9]{6}$/', $pin)) {
            $error = "Enter the email address and 6-digit PIN from your reset email.";
        } else {
            try {
                $pinHash = hash("sha256", $pin);
                $lookup = $conn->prepare("SELECT pr.user_id FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE u.email = ? AND pr.token_hash = ? AND pr.expires_at > UTC_TIMESTAMP() LIMIT 1");
                $lookup->bind_param("ss", $email, $pinHash);
                $lookup->execute();
                $record = $lookup->get_result()->fetch_assoc();
                $lookup->close();
                if (!$record) {
                    $error = "That PIN is incorrect or has expired. Request a new PIN and try again.";
                    $pinVerified = false;
                } else {
                    session_regenerate_id(true);
                    $_SESSION["reset_user_id"] = (int) $record["user_id"];
                    $_SESSION["reset_pin_hash"] = $pinHash;
                    $pinVerified = true;
                }
            } catch (Throwable $e) {
                error_log("Password reset PIN verification failed: " . $e->getMessage());
                $error = "We could not verify that PIN right now. Please try again.";
            }
        }
    } elseif (($_POST["action"] ?? "") === "save_password" && $pinVerified) {
        $password = $_POST["password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";
        if (strlen($password) < 8 || strlen($password) > 255 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[^A-Za-z0-9\s]/', $password)) {
            $error = "Use at least 8 characters with uppercase and lowercase letters and a special character.";
        } elseif ($password !== $confirm) {
            $error = "The passwords do not match.";
        } else {
            try {
                $conn->begin_transaction();
                $userId = (int) $_SESSION["reset_user_id"];
                $pinHash = $_SESSION["reset_pin_hash"];
                $lock = $conn->prepare("SELECT user_id FROM password_resets WHERE user_id = ? AND token_hash = ? AND expires_at > UTC_TIMESTAMP() FOR UPDATE");
                $lock->bind_param("is", $userId, $pinHash);
                $lock->execute();
                $record = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$record) {
                    $conn->rollback();
                    $error = "That PIN has expired. Request a new PIN and try again.";
                    $pinVerified = false;
                    unset($_SESSION["reset_user_id"], $_SESSION["reset_pin_hash"]);
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $update->bind_param("si", $passwordHash, $userId);
                    $update->execute();
                    $update->close();
                    $consume = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $consume->bind_param("i", $userId);
                    $consume->execute();
                    $consume->close();
                    $conn->commit();
                    $success = "Your password has been changed. You can now sign in.";
                    $pinVerified = false;
                    unset($_SESSION["reset_user_id"], $_SESSION["reset_pin_hash"], $_SESSION["reset_csrf"]);
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                error_log("Password reset update failed: " . $e->getMessage());
                $error = "We could not update your password right now. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reset your St. Peregrine Institute online grading system account password.">
    <meta name="theme-color" content="#105334">
    <title>Choose a New Password | St. Peregrine Institute</title>
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
<main class="<?= $pinVerified ? "login-wrap" : "pin-page" ?>">
    <section class="<?= $pinVerified ? "login-card" : "pin-card" ?>">
        <header class="brand">
            <?php if ($pinVerified): ?>
                <img class="school-logo" src="../logo.png" alt="St. Peregrine Institute logo">
                <h1>Choose a new password</h1>
                <p class="subtitle">Create a secure password for your account.</p>
            <?php elseif ($success !== ""): ?>
                <h1>Password updated</h1>
            <?php else: ?>
                <div class="pin-emblem" aria-hidden="true"><span>•••</span><i></i></div>
                <h1>Enter your PIN to reset your password</h1>
                <p class="subtitle">We sent a 6-digit PIN to your email. Enter it below to continue.</p>
            <?php endif; ?>
        </header>
        <?php if ($error !== ""): ?><p class="message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
        <?php if ($success !== ""): ?><p class="message success-message" role="status"><?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
        <?php if ($pinVerified): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["reset_csrf"], ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="action" value="save_password">
                <div class="field"><label for="password">New password</label><input type="password" id="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" required></div>
                <div class="field"><label for="confirm-password">Confirm new password</label><input type="password" id="confirm-password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required></div>
                <p class="subtitle password-hint">At least 8 characters, with uppercase and lowercase letters and a special character.</p>
                <button class="submit" type="submit">SAVE NEW PASSWORD</button>
            </form>
        <?php elseif ($success === ""): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["reset_csrf"], ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="action" value="verify_pin">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" id="pin-value" name="pin" value="<?= htmlspecialchars($pin, ENT_QUOTES, "UTF-8") ?>">
                <fieldset class="pin-fieldset">
                    <legend>6-digit PIN</legend>
                    <div class="pin-boxes" role="group" aria-label="Enter your 6-digit reset PIN">
                        <?php for ($digit = 0; $digit < 6; $digit++): ?>
                            <input class="pin-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="<?= $digit === 0 ? "one-time-code" : "off" ?>" aria-label="PIN digit <?= $digit + 1 ?>" value="<?= htmlspecialchars($pin[$digit] ?? "", ENT_QUOTES, "UTF-8") ?>" required>
                        <?php endfor; ?>
                    </div>
                </fieldset>
                <button class="submit" type="submit">CONFIRM PIN</button>
            </form>
            <a class="pin-alternate" href="index.php">Request a new PIN instead</a>
            <a class="pin-skip" href="../index.html">Back to sign in</a>
        <?php else: ?>
            <p class="register-link"><a href="../index.html">Back to sign in</a></p>
        <?php endif; ?>
        <?php if ($pinVerified): ?>
            <p class="register-link"><a href="../index.html">Back to sign in</a></p>
        <?php endif; ?>
    </section>
</main>
<script>
    const pinInputs = Array.from(document.querySelectorAll(".pin-digit"));
    const pinValue = document.getElementById("pin-value");
    const pinForm = pinValue && pinValue.form;
    function syncPin() {
        if (pinValue) pinValue.value = pinInputs.map(function (input) { return input.value; }).join("");
    }
    pinInputs.forEach(function (input, index) {
        input.addEventListener("input", function () {
            const entered = input.value.replace(/[^0-9]/g, "");
            if (entered.length > 1) {
                entered.slice(0, 6 - index).split("").forEach(function (digit, offset) {
                    pinInputs[index + offset].value = digit;
                });
                pinInputs[Math.min(index + entered.length, 5)].focus();
            } else {
                input.value = entered.slice(0, 1);
                if (input.value && pinInputs[index + 1]) pinInputs[index + 1].focus();
            }
            syncPin();
        });
        input.addEventListener("keydown", function (event) {
            if (event.key === "Backspace" && !input.value && pinInputs[index - 1]) pinInputs[index - 1].focus();
            if (event.key === "ArrowLeft" && pinInputs[index - 1]) pinInputs[index - 1].focus();
            if (event.key === "ArrowRight" && pinInputs[index + 1]) pinInputs[index + 1].focus();
        });
        input.addEventListener("paste", function (event) {
            const pasted = (event.clipboardData || window.clipboardData).getData("text").replace(/[^0-9]/g, "").slice(0, 6);
            if (!pasted) return;
            event.preventDefault();
            pasted.split("").forEach(function (digit, digitIndex) {
                if (pinInputs[digitIndex]) pinInputs[digitIndex].value = digit;
            });
            syncPin();
            pinInputs[Math.min(pasted.length, 5)].focus();
        });
    });
    if (pinForm) pinForm.addEventListener("submit", syncPin);
</script>
</body>
</html>
