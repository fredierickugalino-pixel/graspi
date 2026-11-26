<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/url_helpers.php";

function requireRole($requiredRole)
{
    $requiredRoles = array_map("strtolower", (array) $requiredRole);
    if (empty($_SESSION["user_id"]) || empty($_SESSION["role"])) {
        header("Location: " . appUrl("index.html"));
        exit;
    }

    $currentRole = strtolower((string) $_SESSION["role"]);
    if (!in_array($currentRole, $requiredRoles, true)) {
        $dashboards = [
            "student" => rolePageUrl("student", "dashboard.php"),
            "teacher" => rolePageUrl("teacher", "dashboard.php"),
            "admin" => rolePageUrl("admin", "dashboard.php"),
            "registrar" => rolePageUrl("registrar", "dashboard.php")
        ];

        if (isset($dashboards[$currentRole])) {
            header("Location: " . $dashboards[$currentRole]);
        } else {
            header("Location: " . appUrl("index.html"));
        }

        exit;
    }

    $requestPath = parse_url((string) ($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : "";
    $isTeacherDashboard = basename((string) ($_SERVER["SCRIPT_NAME"] ?? "")) === "dashboard.php"
        || preg_match("#/teacher-dashboard/?$#i", $requestPath) === 1;
    $isStudentPasswordPage = basename((string) ($_SERVER["SCRIPT_NAME"] ?? "")) === "change-password.php"
        || preg_match("#/student-change-password/?$#i", $requestPath) === 1;

    if (
        $currentRole === "teacher" &&
        !empty($_SESSION["must_change_password"]) &&
        !$isTeacherDashboard
    ) {
        header("Location: " . rolePageUrl("teacher", "dashboard.php") . "?force_password=1");
        exit;
    }

    if (
        $currentRole === "student" &&
        !empty($_SESSION["must_change_password"]) &&
        !$isStudentPasswordPage
    ) {
        header("Location: " . rolePageUrl("student", "change-password.php"));
        exit;
    }
}

function h($value)
{
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}
