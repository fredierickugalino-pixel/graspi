<?php
require_once __DIR__ . "/../includes/url_helpers.php";
session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("student");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/messaging_schema.php";
date_default_timezone_set("Asia/Manila");

$userId = (int) $_SESSION["user_id"];
$error = "";
$availableTeachers = [];
$conversations = [];
$messages = [];
$selectedConversation = null;
$selectedTeacher = null;
$studentId = 0;
if (!isset($_SESSION["student_messages_csrf"])) $_SESSION["student_messages_csrf"] = bin2hex(random_bytes(32));

try {
    ensureStudentTeacherMessagesSchema($conn);
    $studentQuery = $conn->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
    $studentQuery->bind_param("i", $userId);
    $studentQuery->execute();
    $studentId = (int) ($studentQuery->get_result()->fetch_assoc()["student_id"] ?? 0);
    $studentQuery->close();
    if (!$studentId) throw new RuntimeException("Your student profile is not available.");

    $enrollmentQuery = $conn->prepare(
        "SELECT section_id, school_year FROM enrollments
         WHERE student_id = ? AND status = 'Enrolled'
         ORDER BY enrollment_date DESC LIMIT 1"
    );
    $enrollmentQuery->bind_param("i", $studentId);
    $enrollmentQuery->execute();
    $currentEnrollment = $enrollmentQuery->get_result()->fetch_assoc();
    $enrollmentQuery->close();
    if ($currentEnrollment) {
        $teachersQuery = $conn->prepare(
            "SELECT DISTINCT teachers.teacher_id, users.first_name, users.middle_name,
                    users.last_name, users.suffix, users.email, subjects.subject_name
             FROM class_subjects
             JOIN teachers ON teachers.teacher_id = class_subjects.teacher_id
             JOIN users ON users.user_id = teachers.user_id
             JOIN subjects ON subjects.subject_id = class_subjects.subject_id
             WHERE class_subjects.section_id = ? AND class_subjects.school_year = ?
             ORDER BY users.last_name, users.first_name, subjects.subject_name"
        );
        $teachersQuery->bind_param("is", $currentEnrollment["section_id"], $currentEnrollment["school_year"]);
        $teachersQuery->execute();
        foreach ($teachersQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $teacherId = (int) $row["teacher_id"];
            if (!isset($availableTeachers[$teacherId])) {
                $availableTeachers[$teacherId] = [
                    "teacher_id" => $teacherId,
                    "name" => trim(implode(" ", array_filter([$row["first_name"], $row["middle_name"], $row["last_name"], $row["suffix"]]))),
                    "email" => (string) $row["email"],
                    "subjects" => []
                ];
            }
            $availableTeachers[$teacherId]["subjects"][] = (string) $row["subject_name"];
        }
        $teachersQuery->close();
        foreach ($availableTeachers as &$teacher) $teacher["subjects"] = array_values(array_unique($teacher["subjects"]));
        unset($teacher);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "send_message") {
        $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
        if (!hash_equals($_SESSION["student_messages_csrf"], $token)) throw new RuntimeException("The form expired. Refresh and try again.");
        $body = trim((string) ($_POST["message_body"] ?? ""));
        $bodyLength = function_exists("mb_strlen") ? mb_strlen($body) : strlen($body);
        if ($body === "" || $bodyLength > 4000) throw new RuntimeException("Enter a message up to 4,000 characters.");
        $conversationId = filter_var($_POST["conversation_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $teacherId = filter_var($_POST["teacher_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;

        $conn->begin_transaction();
        try {
            if ($conversationId > 0) {
                $conversationCheck = $conn->prepare("SELECT conversation_id FROM student_teacher_conversations WHERE conversation_id = ? AND student_id = ? FOR UPDATE");
                $conversationCheck->bind_param("ii", $conversationId, $studentId);
                $conversationCheck->execute();
                $ownedConversation = $conversationCheck->get_result()->fetch_assoc();
                $conversationCheck->close();
                if (!$ownedConversation) throw new RuntimeException("That conversation is not available.");
            } else {
                if (!isset($availableTeachers[$teacherId])) throw new RuntimeException("Choose a teacher assigned to your current section.");
                $createConversation = $conn->prepare(
                    "INSERT INTO student_teacher_conversations (student_id, teacher_id) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE conversation_id = LAST_INSERT_ID(conversation_id)"
                );
                $createConversation->bind_param("ii", $studentId, $teacherId);
                $createConversation->execute();
                $conversationId = (int) $conn->insert_id;
                $createConversation->close();
            }
            $saveMessage = $conn->prepare("INSERT INTO student_teacher_messages (conversation_id, sender_user_id, message_body) VALUES (?, ?, ?)");
            $saveMessage->bind_param("iis", $conversationId, $userId, $body);
            $saveMessage->execute();
            $saveMessage->close();
            $updateConversation = $conn->prepare("UPDATE student_teacher_conversations SET updated_at = CURRENT_TIMESTAMP WHERE conversation_id = ?");
            $updateConversation->bind_param("i", $conversationId);
            $updateConversation->execute();
            $updateConversation->close();
            $conn->commit();
        } catch (Throwable $sendError) {
            $conn->rollback();
            throw $sendError;
        }
        header("Location: " . rolePageUrl("student", "messages.php") . "?conversation_id=" . $conversationId . "&sent=1");
        exit;
    }

    $conversationsQuery = $conn->prepare(
        "SELECT conversations.conversation_id, conversations.teacher_id,
                users.first_name, users.middle_name, users.last_name, users.suffix,
                (SELECT message_body FROM student_teacher_messages
                 WHERE conversation_id = conversations.conversation_id ORDER BY message_id DESC LIMIT 1) AS last_message,
                conversations.updated_at
         FROM student_teacher_conversations AS conversations
         JOIN teachers ON teachers.teacher_id = conversations.teacher_id
         JOIN users ON users.user_id = teachers.user_id
         WHERE conversations.student_id = ? ORDER BY conversations.updated_at DESC"
    );
    $conversationsQuery->bind_param("i", $studentId);
    $conversationsQuery->execute();
    $conversations = $conversationsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $conversationsQuery->close();

    $requestedConversationId = filter_var($_GET["conversation_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $requestedTeacherId = filter_var($_GET["teacher_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if ($requestedConversationId > 0) {
        foreach ($conversations as $conversation) {
            if ((int) $conversation["conversation_id"] === $requestedConversationId) {
                $selectedConversation = $conversation;
                break;
            }
        }
    } elseif ($requestedTeacherId > 0 && isset($availableTeachers[$requestedTeacherId])) {
        $selectedTeacher = $availableTeachers[$requestedTeacherId];
        foreach ($conversations as $conversation) {
            if ((int) $conversation["teacher_id"] === $requestedTeacherId) {
                $selectedConversation = $conversation;
                break;
            }
        }
    }
    if (!$selectedConversation && !$selectedTeacher && $conversations) $selectedConversation = $conversations[0];
    if ($selectedConversation) {
        $selectedConversationId = (int) $selectedConversation["conversation_id"];
        $messageTeacherId = (int) $selectedConversation["teacher_id"];
        $messageTeacherQuery = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, email FROM teachers JOIN users ON users.user_id = teachers.user_id WHERE teachers.teacher_id = ?");
        $messageTeacherQuery->bind_param("i", $messageTeacherId);
        $messageTeacherQuery->execute();
        $teacherRow = $messageTeacherQuery->get_result()->fetch_assoc();
        $messageTeacherQuery->close();
        if ($teacherRow) $selectedTeacher = ["teacher_id" => $messageTeacherId, "name" => trim(implode(" ", array_filter([$teacherRow["first_name"], $teacherRow["middle_name"], $teacherRow["last_name"], $teacherRow["suffix"]]))), "email" => $teacherRow["email"], "subjects" => $availableTeachers[$messageTeacherId]["subjects"] ?? []];

        $messagesQuery = $conn->prepare("SELECT sender_user_id, message_body, sent_at FROM student_teacher_messages WHERE conversation_id = ? ORDER BY message_id ASC");
        $messagesQuery->bind_param("i", $selectedConversationId);
        $messagesQuery->execute();
        $messages = $messagesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $messagesQuery->close();
    }
} catch (Throwable $e) {
    error_log("Student messages page error: " . $e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : "We could not load messages right now.";
}

$studentName = $_SESSION["name"] ?? "Student";
$selectedConversationId = (int) ($selectedConversation["conversation_id"] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('student/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Teachers | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/messenger.css?v=1">
    <script src="../js/dashboard.js" defer></script>
</head>
<body class="messenger-page student-messenger-page">
    <header class="topbar">
        <a class="brand" href="../student-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode"><svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 1.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg><svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg></button>
            <a class="student-signout-button" href="../logout.php">Sign out</a>
        </div>
    </header>
    <nav class="student-topnav" aria-label="Student navigation">
        <a class="student-nav-link" href="../student-dashboard">Dashboard</a><a class="student-nav-link" href="../student-scores">Scores</a><a class="student-nav-link" href="../student-possible-grades">Grades</a><a class="student-nav-link active" href="../student-messages" aria-current="page">Contact</a><a class="student-nav-link" href="../student-profile">My Profile</a>
    </nav>
    <main class="messenger-main">
        <div class="messenger-heading"><div><p class="eyebrow">STUDENT PORTAL</p><h1>Contact your teachers</h1><p>Private messages with teachers assigned to your enrolled section.</p></div></div>
        <?php if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if (isset($_GET["sent"])): ?><p class="alert success" role="status">Message sent.</p><?php endif; ?>
        <section class="messenger-shell" aria-label="Messages with teachers">
            <aside class="messenger-sidebar">
                <div class="messenger-sidebar-heading"><strong>Chats</strong><span><?= count($conversations) ?></span></div>
                <?php if ($conversations): ?>
                    <div class="messenger-chat-list">
                        <?php foreach ($conversations as $conversation): ?>
                            <?php $otherName = trim(implode(" ", array_filter([$conversation["first_name"], $conversation["middle_name"], $conversation["last_name"], $conversation["suffix"]]))); $activeConversation = (int) $conversation["conversation_id"] === $selectedConversationId; ?>
                            <a class="messenger-chat-item<?= $activeConversation ? " is-active" : "" ?>" href="../student-messages?conversation_id=<?= (int) $conversation["conversation_id"] ?>">
                                <span class="messenger-avatar"><?= h(strtoupper(substr($otherName ?: "T", 0, 1))) ?></span>
                                <span class="messenger-chat-copy"><strong><?= h($otherName ?: "Teacher") ?></strong><small><?= h($conversation["last_message"] ?? "Conversation started") ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><p class="messenger-sidebar-empty">Your conversations will appear here.</p><?php endif; ?>
                <div class="messenger-sidebar-heading messenger-teachers-heading"><strong>Your teachers</strong></div>
                <?php if ($availableTeachers): ?>
                    <div class="messenger-chat-list">
                        <?php foreach ($availableTeachers as $teacher): ?>
                            <?php $isCurrentTeacher = $selectedTeacher && (int) $selectedTeacher["teacher_id"] === (int) $teacher["teacher_id"]; ?>
                            <a class="messenger-chat-item<?= $isCurrentTeacher ? " is-active" : "" ?>" href="../student-messages?teacher_id=<?= (int) $teacher["teacher_id"] ?>">
                                <span class="messenger-avatar teacher-avatar"><?= h(strtoupper(substr($teacher["name"] ?: "T", 0, 1))) ?></span>
                                <span class="messenger-chat-copy"><strong><?= h($teacher["name"] ?: "Teacher") ?></strong><small><?= h(implode(", ", $teacher["subjects"])) ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><p class="messenger-sidebar-empty">No teachers are assigned to your current section yet.</p><?php endif; ?>
            </aside>
            <section class="messenger-conversation" aria-label="Selected conversation">
                <?php if ($selectedTeacher): ?>
                    <header class="messenger-conversation-header"><span class="messenger-avatar teacher-avatar"><?= h(strtoupper(substr($selectedTeacher["name"] ?: "T", 0, 1))) ?></span><div><strong><?= h($selectedTeacher["name"] ?: "Teacher") ?></strong><small><?= h(implode(", ", $selectedTeacher["subjects"])) ?></small></div></header>
                    <div class="messenger-history" id="messenger-history" aria-live="polite">
                        <?php if (!$messages): ?><div class="messenger-start-note">Start a conversation with your teacher.</div><?php endif; ?>
                        <?php foreach ($messages as $message): $isOwnMessage = (int) $message["sender_user_id"] === $userId; ?>
                            <div class="messenger-message-row<?= $isOwnMessage ? " is-own" : "" ?>"><div class="messenger-bubble"><?= nl2br(h($message["message_body"])) ?><time><?= h(date("M j · g:i A", strtotime($message["sent_at"]))) ?></time></div></div>
                        <?php endforeach; ?>
                    </div>
                    <form class="messenger-compose" action="../student-messages" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["student_messages_csrf"]) ?>"><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= $selectedConversationId ?>"><input type="hidden" name="teacher_id" value="<?= (int) $selectedTeacher["teacher_id"] ?>">
                        <textarea id="message-body" name="message_body" rows="1" maxlength="4000" aria-label="Write a message" placeholder="Write a message…" required></textarea><button type="submit" aria-label="Send message">➤</button>
                    </form>
                <?php else: ?>
                    <div class="messenger-empty-state"><span aria-hidden="true">✉</span><h2>Select a teacher</h2><p>Choose a conversation or teacher from the list to send a message.</p></div>
                <?php endif; ?>
            </section>
        </section>
    </main>
    <script>const historyPane = document.getElementById("messenger-history"); if (historyPane) historyPane.scrollTop = historyPane.scrollHeight;</script>
</body>
</html>
