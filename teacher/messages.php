<?php
require_once __DIR__ . "/../includes/url_helpers.php";
session_start();
require_once __DIR__ . "/../includes/dashboard_helpers.php";
requireRole("teacher");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../includes/messaging_schema.php";
date_default_timezone_set("Asia/Manila");

$userId = (int) $_SESSION["user_id"];
$teacherId = 0;
$error = "";
$conversations = [];
$messages = [];
$selectedConversation = null;
$teacherUnreadCount = 0;
if (!isset($_SESSION["teacher_messages_csrf"])) $_SESSION["teacher_messages_csrf"] = bin2hex(random_bytes(32));

try {
    ensureStudentTeacherMessagesSchema($conn);
    $teacherQuery = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ? LIMIT 1");
    $teacherQuery->bind_param("i", $userId);
    $teacherQuery->execute();
    $teacherId = (int) ($teacherQuery->get_result()->fetch_assoc()["teacher_id"] ?? 0);
    $teacherQuery->close();
    if (!$teacherId) throw new RuntimeException("Your teacher profile is not available.");

    if (isset($_GET["unread_count"])) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["unread" => getUnreadTeacherMessageCount($conn, $userId)]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "send_message") {
        $token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
        if (!hash_equals($_SESSION["teacher_messages_csrf"], $token)) throw new RuntimeException("The form expired. Refresh and try again.");
        $body = trim((string) ($_POST["message_body"] ?? ""));
        $bodyLength = function_exists("mb_strlen") ? mb_strlen($body) : strlen($body);
        if ($body === "" || $bodyLength > 4000) throw new RuntimeException("Enter a message up to 4,000 characters.");
        $conversationId = filter_var($_POST["conversation_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $conversationCheck = $conn->prepare("SELECT conversation_id FROM student_teacher_conversations WHERE conversation_id = ? AND teacher_id = ?");
        $conversationCheck->bind_param("ii", $conversationId, $teacherId);
        $conversationCheck->execute();
        $ownedConversation = $conversationCheck->get_result()->fetch_assoc();
        $conversationCheck->close();
        if (!$ownedConversation) throw new RuntimeException("That conversation is not available.");
        $saveMessage = $conn->prepare("INSERT INTO student_teacher_messages (conversation_id, sender_user_id, message_body) VALUES (?, ?, ?)");
        $saveMessage->bind_param("iis", $conversationId, $userId, $body);
        $saveMessage->execute();
        $saveMessage->close();
        $updateConversation = $conn->prepare("UPDATE student_teacher_conversations SET updated_at = CURRENT_TIMESTAMP WHERE conversation_id = ?");
        $updateConversation->bind_param("i", $conversationId);
        $updateConversation->execute();
        $updateConversation->close();
        header("Location: " . rolePageUrl("teacher", "messages.php") . "?conversation_id=" . $conversationId . "&sent=1");
        exit;
    }

    $conversationsQuery = $conn->prepare(
        "SELECT conversations.conversation_id, students.student_id,
                users.first_name, users.middle_name, users.last_name, users.suffix,
                sections.section_name,
                (SELECT message_body FROM student_teacher_messages
                 WHERE conversation_id = conversations.conversation_id ORDER BY message_id DESC LIMIT 1) AS last_message,
                conversations.updated_at
         FROM student_teacher_conversations AS conversations
         JOIN students ON students.student_id = conversations.student_id
         JOIN users ON users.user_id = students.user_id
         LEFT JOIN enrollments ON enrollments.enrollment_id = (
            SELECT current_enrollment.enrollment_id FROM enrollments AS current_enrollment
            WHERE current_enrollment.student_id = students.student_id AND current_enrollment.status = 'Enrolled'
            ORDER BY current_enrollment.enrollment_date DESC LIMIT 1
         )
         LEFT JOIN sections ON sections.section_id = enrollments.section_id
         WHERE conversations.teacher_id = ?
         ORDER BY conversations.updated_at DESC"
    );
    $conversationsQuery->bind_param("i", $teacherId);
    $conversationsQuery->execute();
    $conversations = $conversationsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $conversationsQuery->close();

    $requestedConversationId = filter_var($_GET["conversation_id"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    foreach ($conversations as $conversation) {
        if ((int) $conversation["conversation_id"] === $requestedConversationId) {
            $selectedConversation = $conversation;
            break;
        }
    }
    if (!$selectedConversation && $conversations) $selectedConversation = $conversations[0];
    if ($selectedConversation) {
        $conversationId = (int) $selectedConversation["conversation_id"];
        markTeacherConversationMessagesRead($conn, $conversationId, $userId);
        $messagesQuery = $conn->prepare("SELECT sender_user_id, message_body, sent_at FROM student_teacher_messages WHERE conversation_id = ? ORDER BY message_id ASC");
        $messagesQuery->bind_param("i", $conversationId);
        $messagesQuery->execute();
        $messages = $messagesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $messagesQuery->close();
    }
    $teacherUnreadCount = getUnreadTeacherMessageCount($conn, $userId);
} catch (Throwable $e) {
    error_log("Teacher messages page error: " . $e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : "We could not load messages right now.";
}

$selectedConversationId = (int) ($selectedConversation["conversation_id"] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars(appUrl('teacher/'), ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages | St. Peregrine Institute</title>
    <link rel="icon" type="image/png" href="../logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/messenger.css?v=1">
    <script src="../js/dashboard.js" defer></script>
</head>
<body class="messenger-page teacher-messenger-page">
    <header class="topbar">
        <a class="brand" href="../teacher-dashboard"><img src="../logo.png" alt=""><span>ST. PEREGRINE INSTITUTE<small>Online Grading System</small></span></a>
        <div class="nav-menu">
            <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode"><svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 1.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg><svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg></button>
            <a class="student-signout-button" href="../logout.php">Sign out</a>
        </div>
    </header>
    <nav class="teacher-messenger-topnav" aria-label="Teacher navigation">
        <a href="../teacher-dashboard">Dashboard</a><a href="../teacher-sections">Sections</a><a href="../teacher-messages" class="is-active" aria-current="page">Messages <span class="nav-unread-count" data-teacher-unread-count<?= $teacherUnreadCount > 0 ? "" : " hidden" ?>><?= $teacherUnreadCount > 99 ? "99+" : $teacherUnreadCount ?></span></a><a href="../teacher-profile">My Profile</a>
    </nav>
    <main class="messenger-main">
        <div class="messenger-heading"><div><p class="eyebrow">TEACHER PORTAL</p><h1>Messages</h1><p>Private conversations with students assigned to your classes.</p></div></div>
        <?php if ($error !== ""): ?><p class="alert error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if (isset($_GET["sent"])): ?><p class="alert success" role="status">Message sent.</p><?php endif; ?>
        <section class="messenger-shell teacher-messenger-shell" aria-label="Student messages">
            <aside class="messenger-sidebar">
                <div class="messenger-sidebar-heading"><strong>Student chats</strong><span><?= count($conversations) ?></span></div>
                <?php if ($conversations): ?><div class="messenger-chat-list">
                    <?php foreach ($conversations as $conversation): ?>
                        <?php $studentDisplayName = trim(implode(" ", array_filter([$conversation["first_name"], $conversation["middle_name"], $conversation["last_name"], $conversation["suffix"]]))); $activeConversation = (int) $conversation["conversation_id"] === $selectedConversationId; ?>
                        <a class="messenger-chat-item<?= $activeConversation ? " is-active" : "" ?>" href="../teacher-messages?conversation_id=<?= (int) $conversation["conversation_id"] ?>">
                            <span class="messenger-avatar student-avatar"><?= h(strtoupper(substr($studentDisplayName ?: "S", 0, 1))) ?></span><span class="messenger-chat-copy"><strong><?= h($studentDisplayName ?: "Student") ?></strong><small><?= h($conversation["last_message"] ?? "Conversation started") ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div><?php else: ?><p class="messenger-sidebar-empty">Student conversations will appear here when a student sends you a message.</p><?php endif; ?>
            </aside>
            <section class="messenger-conversation" aria-label="Selected student conversation">
                <?php if ($selectedConversation): ?>
                    <?php $studentDisplayName = trim(implode(" ", array_filter([$selectedConversation["first_name"], $selectedConversation["middle_name"], $selectedConversation["last_name"], $selectedConversation["suffix"]]))); ?>
                    <header class="messenger-conversation-header"><span class="messenger-avatar student-avatar"><?= h(strtoupper(substr($studentDisplayName ?: "S", 0, 1))) ?></span><div><strong><?= h($studentDisplayName ?: "Student") ?></strong><small><?= h($selectedConversation["section_name"] ?? "Student") ?></small></div></header>
                    <div class="messenger-history" id="messenger-history" aria-live="polite">
                        <?php foreach ($messages as $message): $isOwnMessage = (int) $message["sender_user_id"] === $userId; ?>
                            <div class="messenger-message-row<?= $isOwnMessage ? " is-own" : "" ?>"><div class="messenger-bubble"><?= nl2br(h($message["message_body"])) ?><time><?= h(date("M j · g:i A", strtotime($message["sent_at"]))) ?></time></div></div>
                        <?php endforeach; ?>
                    </div>
                    <form class="messenger-compose" action="../teacher-messages" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["teacher_messages_csrf"]) ?>"><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= $selectedConversationId ?>">
                        <textarea id="message-body" name="message_body" rows="1" maxlength="4000" aria-label="Write a message" placeholder="Write a message…" required></textarea><button type="submit" aria-label="Send message">➤</button>
                    </form>
                <?php else: ?><div class="messenger-empty-state"><span aria-hidden="true">✉</span><h2>No conversations yet</h2><p>Student messages will appear here.</p></div><?php endif; ?>
            </section>
        </section>
    </main>
    <script>const historyPane = document.getElementById("messenger-history"); if (historyPane) historyPane.scrollTop = historyPane.scrollHeight;</script>
</body>
</html>
