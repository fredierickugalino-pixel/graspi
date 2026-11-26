<?php

function ensureStudentTeacherMessagesSchema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_teacher_conversations (
            conversation_id INT NOT NULL AUTO_INCREMENT,
            student_id INT NOT NULL,
            teacher_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_id),
            UNIQUE KEY uq_student_teacher_conversation (student_id, teacher_id),
            KEY idx_teacher_conversations (teacher_id, updated_at),
            CONSTRAINT fk_st_conversation_student FOREIGN KEY (student_id)
                REFERENCES students(student_id) ON DELETE CASCADE,
            CONSTRAINT fk_st_conversation_teacher FOREIGN KEY (teacher_id)
                REFERENCES teachers(teacher_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_teacher_messages (
            message_id BIGINT NOT NULL AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            sender_user_id INT NOT NULL,
            message_body TEXT NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (message_id),
            KEY idx_conversation_messages (conversation_id, message_id),
            KEY idx_unread_teacher_messages (conversation_id, read_at, sender_user_id),
            CONSTRAINT fk_st_message_conversation FOREIGN KEY (conversation_id)
                REFERENCES student_teacher_conversations(conversation_id) ON DELETE CASCADE,
            CONSTRAINT fk_st_message_sender FOREIGN KEY (sender_user_id)
                REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $readAtColumn = $conn->query("SHOW COLUMNS FROM student_teacher_messages LIKE 'read_at'");
    if ($readAtColumn->num_rows === 0) {
        $conn->query("ALTER TABLE student_teacher_messages ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER sent_at");
    }
    $unreadIndex = $conn->query("SHOW INDEX FROM student_teacher_messages WHERE Key_name = 'idx_unread_teacher_messages'");
    if ($unreadIndex->num_rows === 0) {
        $conn->query("CREATE INDEX idx_unread_teacher_messages ON student_teacher_messages (conversation_id, read_at, sender_user_id)");
    }
}

function getUnreadTeacherMessageCount(mysqli $conn, int $teacherUserId): int
{
    $countQuery = $conn->prepare(
        "SELECT COUNT(*) AS unread_count
         FROM student_teacher_messages AS messages
         JOIN student_teacher_conversations AS conversations ON conversations.conversation_id = messages.conversation_id
         JOIN teachers ON teachers.teacher_id = conversations.teacher_id
         WHERE teachers.user_id = ? AND messages.sender_user_id <> ? AND messages.read_at IS NULL"
    );
    $countQuery->bind_param("ii", $teacherUserId, $teacherUserId);
    $countQuery->execute();
    $count = (int) ($countQuery->get_result()->fetch_assoc()["unread_count"] ?? 0);
    $countQuery->close();
    return $count;
}

function markTeacherConversationMessagesRead(mysqli $conn, int $conversationId, int $teacherUserId): void
{
    $readQuery = $conn->prepare(
        "UPDATE student_teacher_messages AS messages
         JOIN student_teacher_conversations AS conversations ON conversations.conversation_id = messages.conversation_id
         JOIN teachers ON teachers.teacher_id = conversations.teacher_id
         SET messages.read_at = CURRENT_TIMESTAMP
         WHERE conversations.conversation_id = ? AND teachers.user_id = ?
           AND messages.sender_user_id <> ? AND messages.read_at IS NULL"
    );
    $readQuery->bind_param("iii", $conversationId, $teacherUserId, $teacherUserId);
    $readQuery->execute();
    $readQuery->close();
}
