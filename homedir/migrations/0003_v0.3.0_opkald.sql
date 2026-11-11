-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F002: opkald fra Zendesk Talk, deres transcripts og sync-cursoren. Eksplicit unicode_ci (SERVERKONTRAKT §3).
-- Statusværdierne er ASCII (faerdig), så sammenligninger aldrig afhænger af forbindelsens tegnsæt; UI'et viser dansk.
CREATE TABLE IF NOT EXISTS calls (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `source`            ENUM('zendesk')  NOT NULL,
    external_id         VARCHAR(64)      NOT NULL,
    ticket_id           BIGINT UNSIGNED  NULL,
    direction           ENUM('inbound','outbound') NOT NULL,
    customer_phone      VARCHAR(40)      NOT NULL DEFAULT '',
    agent_name          VARCHAR(120)     NOT NULL DEFAULT '',
    started_at          DATETIME         NOT NULL,
    duration_sec        INT UNSIGNED     NOT NULL DEFAULT 0,
    recording_url       VARCHAR(500)     NOT NULL,
    audio_path          VARCHAR(255)     NULL,
    audio_status        ENUM('venter','hentet','fejlet') NOT NULL DEFAULT 'venter',
    audio_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    audio_error         VARCHAR(255)     NULL,
    transcript_status   ENUM('ventende','transcriberer','faerdig','fejlet') NOT NULL DEFAULT 'ventende',
    transcript_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    transcript_error    VARCHAR(255)     NULL,
    transcript_retry_at DATETIME         NULL,
    claimed_at          DATETIME         NULL,
    created_at          DATETIME         NOT NULL,
    updated_at          DATETIME         NOT NULL,
    UNIQUE KEY calls_source_external (`source`, external_id),
    INDEX calls_started (started_at),
    INDEX calls_transcript_status (transcript_status, started_at),
    INDEX calls_audio_status (audio_status),
    INDEX calls_agent (agent_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcripts (
    call_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    `text`        MEDIUMTEXT   NOT NULL,
    segments_json MEDIUMTEXT   NOT NULL,
    model         VARCHAR(40)  NOT NULL,
    `language`    CHAR(2)      NOT NULL,
    created_at    DATETIME     NOT NULL,
    FULLTEXT KEY transcripts_text (`text`),
    CONSTRAINT transcripts_call FOREIGN KEY (call_id) REFERENCES calls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_state (
    `source`     VARCHAR(20)  NOT NULL PRIMARY KEY,
    cursor_value VARCHAR(255) NULL,
    last_run_at  DATETIME     NULL,
    last_ok_at   DATETIME     NULL,
    last_error   VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
