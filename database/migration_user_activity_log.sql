-- User Activity Log table
CREATE TABLE IF NOT EXISTS user_activity_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    activity_type   VARCHAR(50)  NOT NULL,          -- e.g. 'login', 'logout', 'create', 'update', 'delete', 'view', 'export'
    description     VARCHAR(255) NOT NULL,          -- human-readable description
    ip_address      VARCHAR(45)  NULL,              -- IPv4 or IPv6
    user_agent      VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, created_at, id),
    INDEX idx_type_time (activity_type, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;