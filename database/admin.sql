-- Admin membership is granted explicitly. New users are regular users by default.
CREATE TABLE IF NOT EXISTS app_admins (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
