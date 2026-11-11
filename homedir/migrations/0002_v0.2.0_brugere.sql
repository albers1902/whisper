-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F001: brugere og login-forsøg (throttling). Eksplicit unicode_ci: sitectl og CI opretter ellers med general_ci (SERVERKONTRAKT §3).
CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email                VARCHAR(190) NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    role                 ENUM('admin','user') NOT NULL DEFAULT 'user',
    password_hash        VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    active               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL,
    last_login_at        DATETIME     NULL,
    UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190)    NOT NULL,
    ip         VARCHAR(45)     NOT NULL,
    success    TINYINT(1)      NOT NULL,
    created_at DATETIME        NOT NULL,
    INDEX login_attempts_email (email, created_at),
    INDEX login_attempts_ip (ip, created_at),
    INDEX login_attempts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
