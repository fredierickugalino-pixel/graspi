<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/includes/teacher_schema.php";
require_once __DIR__ . "/includes/url_helpers.php";

try {
    ensureTeacherMustChangePasswordColumn($conn);
} catch (mysqli_sql_exception $e) {
    error_log("Temporary teacher password schema check failed: " . $e->getMessage());
    redirectToLogin("unavailable");
}

function redirectToLogin($error)
{
    header("Location: index.html?error=" . urlencode($error));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit;
}

$identifier = trim($_POST["identifier"] ?? "");
$password = $_POST["password"] ?? "";

if ($identifier === "" || $password === "") {
    redirectToLogin("missing");
}

try {
    $loginQuery = $conn->prepare(
        "SELECT user_id, lrn, first_name, middle_name, last_name,
                suffix, email, password, role, must_change_password
         FROM users
         WHERE lrn = ? OR email = ?
         LIMIT 1"
    );
    $loginQuery->bind_param("ss", $identifier, $identifier);
    $loginQuery->execute();

    $result = $loginQuery->get_result();
    $user = $result->fetch_assoc();
    $loginQuery->close();
} catch (mysqli_sql_exception $e) {
    error_log("Login query failed: " . $e->getMessage());
    redirectToLogin("unavailable");
}

if (!$user || !password_verify($password, $user["password"])) {
    redirectToLogin("invalid");
}

$role = strtolower($user["role"]);
$dashboards = [
    "student" => rolePageUrl("student", "dashboard.php"),
    "teacher" => rolePageUrl("teacher", "dashboard.php"),
    "admin" => rolePageUrl("admin", "dashboard.php"),
    "registrar" => rolePageUrl("registrar", "dashboard.php")
];

if (!isset($dashboards[$role])) {
    redirectToLogin("invalid");
}

$nameParts = [
    $user["first_name"],
    $user["middle_name"],
    $user["last_name"],
    $user["suffix"]
];
$fullName = implode(" ", array_filter($nameParts));

session_regenerate_id(true);
$_SESSION["user_id"] = (int) $user["user_id"];
$_SESSION["role"] = $role;
$_SESSION["email"] = $user["email"] ?? "";
$_SESSION["lrn"] = $user["lrn"];
$_SESSION["name"] = trim($fullName);
$_SESSION["must_change_password"] = in_array($role, ["teacher", "student"], true) && (int) ($user["must_change_password"] ?? 0) === 1;

$destination = $role === "student" && $_SESSION["must_change_password"]
    ? rolePageUrl("student", "change-password.php")
    : $dashboards[$role];
header("Location: " . $destination);
exit;
