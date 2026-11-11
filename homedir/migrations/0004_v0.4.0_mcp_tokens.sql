-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F003: personlige MCP-nøgler (kun SHA-256 af nøglen + 8 tegns prefix til visning) og rate limit pr. nøgle og minut.
-- Eksplicit unicode_ci (SERVERKONTRAKT §3). En slettet bruger tager sine nøgler med (cascade).
CREATE TABLE IF NOT EXISTS mcp_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    name         VARCHAR(80)  NOT NULL,
    prefix       CHAR(8)      NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    created_at   DATETIME     NOT NULL,
    last_used_at DATETIME     NULL,
    revoked_at   DATETIME     NULL,
    UNIQUE KEY mcp_tokens_hash (token_hash),
    INDEX mcp_tokens_user (user_id),
    CONSTRAINT mcp_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_rate (
    token_id     INT UNSIGNED NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    `count`      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (token_id, window_start),
    INDEX mcp_rate_window (window_start),
    CONSTRAINT mcp_rate_token_fk FOREIGN KEY (token_id) REFERENCES mcp_tokens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
