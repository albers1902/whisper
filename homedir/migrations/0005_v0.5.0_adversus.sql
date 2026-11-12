-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F004: Adversus-opkald i samme tabel som Zendesk (source = adversus), lead og succes-markering, "ikke valgt" som
-- transcript-status, succes-leads og agentnavne fra Adversus. Cursorerne ligger i sync_state (adversus, adversus-rec,
-- adversus-leads, adversus-users, adversus-warn). Eksplicit unicode_ci (SERVERKONTRAKT §3).
-- Rollback er flaget F004_adversus = off, ikke down (changes/F004.md).
-- Up sletter intet. Down ER destruktiv for Adversus-data: den sletter alle Adversus-opkald (og deres transcripts),
-- før ENUM'erne kan snævres ind igen (se down-filens header).
ALTER TABLE calls MODIFY `source` ENUM('zendesk','adversus') NOT NULL;
ALTER TABLE calls MODIFY transcript_status ENUM('ventende','transcriberer','faerdig','fejlet','ikke_valgt') NOT NULL DEFAULT 'ventende';
ALTER TABLE calls ADD COLUMN IF NOT EXISTS lead_id BIGINT UNSIGNED NULL AFTER ticket_id;
ALTER TABLE calls ADD COLUMN IF NOT EXISTS campaign_id BIGINT UNSIGNED NULL AFTER lead_id;
ALTER TABLE calls ADD COLUMN IF NOT EXISTS is_success TINYINT(1) NOT NULL DEFAULT 0 AFTER campaign_id;
ALTER TABLE calls ADD COLUMN IF NOT EXISTS agent_ext_id VARCHAR(64) NULL AFTER agent_name;
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_lead (lead_id);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_success (`source`, is_success, started_at);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_agent_ext (agent_ext_id);

-- Optagelser, hvis opkald endnu ikke er fundet i /calls: prøves igen i de næste kørsler, derefter forkastes de synligt
CREATE TABLE IF NOT EXISTS adversus_pending (
    cdr_id     BIGINT UNSIGNED  NOT NULL PRIMARY KEY,
    cdr_at     DATETIME         NULL,
    tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    first_seen DATETIME         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adversus_success_leads (
    lead_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    last_updated_at DATETIME        NULL,
    seen_at         DATETIME        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adversus_users (
    user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name       VARCHAR(120)    NOT NULL,
    updated_at DATETIME        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
