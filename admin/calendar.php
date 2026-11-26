<?php
require_once __DIR__ . "/../includes/url_helpers.php";

session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("admin");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";

if (!isset($_SESSION["admin_calendar_csrf"])) {
    $_SESSION["admin_calendar_csrf"] = bin2hex(random_bytes(32));
}

$eventTypes = ["Examination", "Holiday", "School event", "Deadline", "Other"];
$error = "";
$message = "";
$popupMessage = "";
$resetCalendarForm = false;
$editEvent = null;
$events = [];

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS school_calendar_events (
            event_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            event_date DATE NOT NULL,
            description VARCHAR(500) NULL,
            created_by INT NOT NULL,
            status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_school_calendar_event_date (event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $statusColumn = $conn->query("SHOW COLUMNS FROM school_calendar_events LIKE 'status'");
    if ($statusColumn->num_rows === 0) {
        $conn->query("ALTER TABLE school_calendar_events ADD COLUMN status ENUM('Active','Archived') NOT NULL DEFAULT 'Active'");
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $token = $_POST["csrf_token"] ?? "";
        if (!hash_equals($_SESSION["admin_calendar_csrf"], is_string($token) ? $token : "")) {
            throw new Exception("The form expired. Refresh the page and try again.");
        }

        $action = $_POST["action"] ?? "";
        $eventId = (int) ($_POST["event_id"] ?? 0);
        if ($action === "archive") {
            if ($eventId < 1) throw new Exception("Choose a valid calendar event.");
            $archiveEvent = $conn->prepare("UPDATE school_calendar_events SET status = 'Archived' WHERE event_id = ? AND status = 'Active'");
            $archiveEvent->bind_param("i", $eventId);
            $archiveEvent->execute();
            $archived = $archiveEvent->affected_rows;
            $archiveEvent->close();
            if (!$archived) throw new Exception("That active calendar event could not be found.");
            $message = "Calendar event archived.";
            $_SESSION["admin_calendar_csrf"] = bin2hex(random_bytes(32));
        } elseif ($action === "save") {
            $title = trim(is_string($_POST["title"] ?? null) ? $_POST["title"] : "");
            $eventType = $_POST["event_type"] ?? "";
            $eventDate = $_POST["event_date"] ?? "";
            $description = trim(is_string($_POST["description"] ?? null) ? $_POST["description"] : "");

            if ($title === "" || strlen($title) > 150) throw new Exception("Enter an event name up to 150 characters long.");
            if (!is_string($eventType) || !in_array($eventType, $eventTypes, true)) throw new Exception("Choose a valid event type.");
            if (!is_string($eventDate) || !($parsedDate = DateTime::createFromFormat("Y-m-d", $eventDate)) || $parsedDate->format("Y-m-d") !== $eventDate) {
                throw new Exception("Choose a valid event date.");
            }
            if (strlen($description) > 500) throw new Exception("Keep the event description under 500 characters.");

            if ($eventId > 0) {
                $saveEvent = $conn->prepare(
                    "UPDATE school_calendar_events
                     SET title = ?, event_type = ?, event_date = ?, description = ?
                     WHERE event_id = ?"
                );
                $saveEvent->bind_param("ssssi", $title, $eventType, $eventDate, $description, $eventId);
                $saveEvent->execute();
                $saveEvent->close();
                $message = "Calendar event updated.";
            } else {
                $adminId = (int) $_SESSION["user_id"];
                $saveEvent = $conn->prepare(
                    "INSERT INTO school_calendar_events (title, event_type, event_date, description, created_by)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $saveEvent->bind_param("ssssi", $title, $eventType, $eventDate, $description, $adminId);
                $saveEvent->execute();
                $saveEvent->close();
                $message = "Calendar event added.";
                $popupMessage = "School calendar event added successfully.";
                $resetCalendarForm = true;
            }
            $_SESSION["admin_calendar_csrf"] = bin2hex(random_bytes(32));
        } else {
            throw new Exception("Choose a valid calendar action.");
        }
    }

    $editId = (int) ($_GET["edit_id"] ?? 0);
    if ($editId > 0) {
        $editQuery = $conn->prepare("SELECT event_id, title, event_type, event_date, description FROM school_calendar_events WHERE event_id = ? AND status = 'Active'");
        $editQuery->bind_param("i", $editId);
        $editQuery->execute();
        $editEvent = $editQuery->get_result()->fetch_assoc();
        $editQuery->close();
    }

    $eventQuery = $conn->query(
        "SELECT event_id, title, event_type, event_date, description
         FROM school_calendar_events
         WHERE status = 'Active'
         ORDER BY (event_date < CURRENT_DATE()), event_date ASC, event_id DESC"
    );
    $events = $eventQuery->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Admin calendar error: " . $e->getMessage());
    if ($error === "") {
        $error = $e instanceof mysqli_sql_exception
            ? "We could not update the school calendar right now."
            : $e->getMessage();
    }
}

$adminName = $_SESSION["name"] ?? "Administrator";
$formEvent = $editEvent ?: ($resetCalendarForm ? [
    "event_id" => 0,
    "title" => "",
    "event_type" => "Examination",
    "event_date" => "",
    "description" => ""
] : [
    "event_id" => 0,
    "title" => $_POST["title"] ?? "",
    "event_type" => $_POST["event_type"] ?? "Examination",
    "event_date" => $_POST["event_date"] ?? "",
    "description" => $_POST["description"] ?? ""
]);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('admin/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Manage school calendar events and examination dates.">
    <meta name="theme-color" content="#105334">
    <title>School Calendar | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?= (int) filemtime(__DIR__ . "/../css/dashboard.css") ?>">
    <script src="../js/dashboard.js?v=<?= (int) filemtime(__DIR__ . "/../js/dashboard.js") ?>" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="../admin-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
            </button>
            <a class="admin-profile-icon" href="../admin-profile" aria-label="My profile" title="My profile"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></a>
            <a class="student-signout-button" href="../logout.php" aria-label="Sign out" title="Sign out">Sign out</a>
            <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="account-menu"><span></span><span></span><span></span></button>
            <nav class="menu-panel" id="account-menu" aria-label="Account menu" hidden>
                <div class="menu-user"><strong><?= h($adminName) ?></strong><span>Admin</span></div>
                <a class="menu-link" href="../admin-dashboard">Dashboard</a>
                <a class="menu-link" href="../admin-section_assignments">Sections</a>
                <a class="menu-link" href="../admin-subjects">Subjects</a>
                <a class="menu-link active" href="../admin-calendar" aria-current="page">Calendar</a>
                <a class="menu-link" href="../admin-register">Teacher</a>
            </nav>
        </div>
    </header>

    <main class="dashboard">
        <div class="page-heading"><p class="eyebrow">ADMIN PORTAL</p><h1>School Calendar</h1></div>
        <?php 
if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php 
endif; ?>
        <?php 
if ($message !== ""): ?><p class="alert success" role="status"><?= h($message) ?></p><?php 
endif; ?>

        <section class="panel calendar-admin-form-panel">
            <div class="panel-heading"><div><h2><?= $editEvent ? "Edit calendar event" : "Add a calendar event" ?></h2><p>Students can see these dates in their dashboard calendar.</p></div></div>
            <form action="../admin-calendar" class="grade-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_calendar_csrf"]) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="event_id" value="<?= (int) $formEvent["event_id"] ?>">
                <label>Event name<input type="text" name="title" maxlength="150" value="<?= h($formEvent["title"]) ?>" placeholder="e.g. Midterm examination" required></label>
                <label>Event type<select name="event_type" required><?php 
foreach ($eventTypes as $type): ?><option value="<?= h($type) ?>" <?= ($formEvent["event_type"] ?? "") === $type ? "selected" : "" ?>><?= h($type) ?></option><?php 
endforeach; ?></select></label>
                <label class="calendar-date-field">Date<input type="date" name="event_date" value="<?= h($formEvent["event_date"]) ?>" required></label>
                <label class="calendar-description-field">Description <span>(optional)</span><input type="text" name="description" maxlength="500" value="<?= h($formEvent["description"] ?? "") ?>" placeholder="Add details students should know"></label>
                <div class="calendar-form-actions">
                    <button class="small-button" type="submit"><?= $editEvent ? "Save changes" : "Add to calendar" ?></button>
                    <?php 
if ($editEvent): ?><a class="calendar-cancel-link" href="../admin-calendar">Cancel</a><?php 
endif; ?>
                </div>
            </form>
        </section>

        <section class="panel grade-panel">
            <div class="panel-heading"><div><h2>Scheduled dates</h2><p><?= count($events) ?> calendar event<?= count($events) === 1 ? "" : "s" ?></p></div></div>
            <?php 
if (count($events) === 0): ?>
                <p class="empty-state">No school calendar events have been added yet.</p>
            <?php 
else: ?>
                <div class="table-wrap">
                    <table class="calendar-admin-table">
                        <thead><tr><th>Date</th><th>Type</th><th>Event</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php 
foreach ($events as $event): ?>
                                <tr>
                                    <td><?= h(date("M j, Y", strtotime($event["event_date"]))) ?></td>
                                    <td><?= h($event["event_type"]) ?></td>
                                    <td><?= h($event["title"]) ?></td>
                                    <td><?= h($event["description"] ?: "—") ?></td>
                                    <td><div class="calendar-row-actions"><a href="../admin-calendar?edit_id=<?= (int) $event["event_id"] ?>">Edit</a><form action="../admin-calendar" method="post" onsubmit="return confirm('Archive this calendar event? It will no longer appear on student calendars.');"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["admin_calendar_csrf"]) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="event_id" value="<?= (int) $event["event_id"] ?>"><button type="submit" class="calendar-archive-button">Archive</button></form></div></td>
                                </tr>
                            <?php 
endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php 
endif; ?>
        </section>
    </main>
    <?php 
if ($popupMessage !== ""): ?>
        <div class="dashboard-modal-overlay" id="calendar-success-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-success-title" aria-describedby="calendar-success-message">
            <section class="dashboard-success-modal" tabindex="-1">
                <div class="dashboard-success-icon" aria-hidden="true">✓</div>
                <h2 id="calendar-success-title">Event added</h2>
                <p id="calendar-success-message"><?= h($popupMessage) ?></p>
                <button class="dashboard-modal-close" type="button">Close</button>
            </section>
        </div>
    <?php 
endif; ?>
</body>
</html>
