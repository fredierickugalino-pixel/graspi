<?php
session_start();
require_once __DIR__ . "/includes/url_helpers.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$dashboards = [
    "student" => rolePageUrl("student", "dashboard.php"),
    "teacher" => rolePageUrl("teacher", "dashboard.php"),
    "admin" => rolePageUrl("admin", "dashboard.php"),
    "registrar" => rolePageUrl("registrar", "dashboard.php")
];

$role = strtolower((string) ($_SESSION["role"] ?? ""));
$authenticated = !empty($_SESSION["user_id"]) && isset($dashboards[$role]);

echo json_encode([
    "authenticated" => $authenticated,
    "dashboard" => $authenticated ? $dashboards[$role] : null
]);
