-- Run this once in the `drick` database (phpMyAdmin or MySQL command line).
CREATE TABLE IF NOT EXISTS password_resets (
    user_id INT NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
