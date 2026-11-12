-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F006: OAuth 2.1 til Claude's custom connector. Klienter fra dynamisk registrering (RFC 7591), bevillinger (én pr.
-- godkendelse = én kæde, der tilbagekaldes samlet), engangskoder, access/refresh tokens (kun SHA-256) og rate limit pr.
-- bevilling på /mcp. client_id er ascii_bin, så opslaget skelner store og små bogstaver. En slettet bruger eller klient
-- tager sine bevillinger, koder og tokens med (cascade).
CREATE TABLE IF NOT EXISTS oauth_clients (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_name   VARCHAR(100) NOT NULL,
    redirect_uris TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    created_ip    VARCHAR(45)  NOT NULL,
    last_used_at  DATETIME     NULL,
    UNIQUE KEY oauth_clients_client_id (client_id),
    INDEX oauth_clients_created (created_at),
    INDEX oauth_clients_ip (created_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_grants (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_pk    INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    resource     VARCHAR(255) NOT NULL,
    created_at   DATETIME     NOT NULL,
    last_used_at DATETIME     NULL,
    revoked_at   DATETIME     NULL,
    INDEX oauth_grants_user (user_id, revoked_at),
    INDEX oauth_grants_created (created_at),
    CONSTRAINT oauth_grants_client_fk FOREIGN KEY (client_pk) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT oauth_grants_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_codes (
    code_hash      CHAR(64)     NOT NULL PRIMARY KEY,
    grant_id       INT UNSIGNED NOT NULL,
    redirect_uri   VARCHAR(500) NOT NULL,
    code_challenge CHAR(43)     NOT NULL,
    expires_at     DATETIME     NOT NULL,
    used_at        DATETIME     NULL,
    INDEX oauth_codes_expires (expires_at),
    CONSTRAINT oauth_codes_grant_fk FOREIGN KEY (grant_id) REFERENCES oauth_grants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grant_id   INT UNSIGNED NOT NULL,
    kind       ENUM('access', 'refresh') NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    UNIQUE KEY oauth_tokens_hash (token_hash),
    INDEX oauth_tokens_grant (grant_id, kind),
    INDEX oauth_tokens_expires (expires_at),
    CONSTRAINT oauth_tokens_grant_fk FOREIGN KEY (grant_id) REFERENCES oauth_grants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_rate (
    grant_id     INT UNSIGNED NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    `count`      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (grant_id, window_start),
    INDEX oauth_rate_window (window_start),
    CONSTRAINT oauth_rate_grant_fk FOREIGN KEY (grant_id) REFERENCES oauth_grants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
