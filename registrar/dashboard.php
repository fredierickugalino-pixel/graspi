<?php
require_once __DIR__ . "/../includes/url_helpers.php";
session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("registrar");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

$summary = [
    "students" => 0,
    "pending" => 0,
    "enrolled" => 0,
    "sections" => 0
];
$loadError = "";

try {
    $result = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM students) AS students,
            (SELECT COUNT(*) FROM users WHERE LOWER(role) = 'student' AND LOWER(approval_status) = 'pending') AS pending,
            (SELECT COUNT(*) FROM enrollments WHERE status = 'Enrolled') AS enrolled,
            (SELECT COUNT(*) FROM sections WHERE is_archived = 0) AS sections"
    );
    $summary = $result->fetch_assoc() ?: $summary;
} catch (mysqli_sql_exception $e) {
    error_log("Registrar dashboard summary error: " . $e->getMessage());
    $loadError = "We could not load the school overview right now.";
}

$registrarName = $_SESSION["name"] ?? "Registrar";
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('registrar/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#105334">
    <title>Registrar Dashboard | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body class="registrar-dashboard-page">
    <header class="registrar-header">
        <a class="registrar-brand" href="../registrar-dashboard">
            <img src="../logo.png" alt="">
            <span><strong>ST. PEREGRINE INSTITUTE</strong><small>Online Grading System</small></span>
        </a>
        <nav class="registrar-header-actions" aria-label="Account navigation">
            <span><?= h($registrarName) ?></span>
            <a href="../logout.php">Sign out</a>
        </nav>
    </header>

    <main class="registrar-main">
        <section class="registrar-welcome">
            <p class="registrar-eyebrow">REGISTRAR PORTAL</p>
            <h1>Enrollment overview</h1>
            <p>Welcome, <?= h($registrarName) ?>. Here is a quick view of current student records.</p>
        </section>

        <?php if ($loadError !== ""): ?>
            <p class="registrar-error" role="alert"><?= h($loadError) ?></p>
        <?php endif; ?>

        <section class="registrar-summary" aria-label="School enrollment summary">
            <article class="registrar-card"><span>Student records</span><strong><?= (int) $summary["students"] ?></strong></article>
            <article class="registrar-card"><span>Applications awaiting review</span><strong><?= (int) $summary["pending"] ?></strong></article>
            <article class="registrar-card"><span>Currently enrolled</span><strong><?= (int) $summary["enrolled"] ?></strong></article>
            <article class="registrar-card"><span>Active sections</span><strong><?= (int) $summary["sections"] ?></strong></article>
        </section>

        <section class="registrar-next-step">
            <div>
                <h2>Add students</h2>
                <p>Add one student manually or import a CSV roster from the Registrar workspace.</p>
            </div>
            <a href="../registrar-add-students">Add students</a>
        </section>
    </main>
</body>
</html>
