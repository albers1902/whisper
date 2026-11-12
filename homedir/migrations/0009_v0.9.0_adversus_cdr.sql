-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- H013: Adversus hentes som CDR'er (alle opkald fra /calls) med leads som lokal sandhed om status. Lyd hentes kun for
-- succes og manuelt valgte. adversus_leads gemmer alle leads (id, status, tid — ingen fritekst). Kendte succes-leads
-- kopieres over, så intet succes-opkald mister sin markering mellem deploy og første lead-backfill.
-- adversus_pending og adversus_success_leads bruges ikke mere; de DROPpes først i en contract med eget ID (LIFECYCLE §6).
-- Indekset bærer lydkøen (source, transcript_status, audio_status), som ellers scanner alle Adversus-rækker, når der
-- er flere hundrede tusinde. Indekset til listen "Alle opkald" leverer F007 (0010).
CREATE TABLE IF NOT EXISTS adversus_leads (
    lead_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `status`        VARCHAR(32)     NOT NULL,
    last_updated_at DATETIME        NULL,
    synced_at       DATETIME        NOT NULL,
    INDEX adversus_leads_status (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO adversus_leads (lead_id, `status`, last_updated_at, synced_at)
    SELECT lead_id, 'success', last_updated_at, seen_at FROM adversus_success_leads;

ALTER TABLE calls ADD COLUMN IF NOT EXISTS disposition VARCHAR(16) NULL AFTER direction;
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_audio_queue (`source`, transcript_status, audio_status);
