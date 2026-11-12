-- reversibel: ja
-- destruktiv: ja
-- idempotent: ja
-- Down for 0005 (F004). DESTRUKTIV: sletter alle Adversus-opkald (transcripts følger med ON DELETE CASCADE),
-- succes-leads, agentnavne og Adversus-cursorerne, før ENUM'erne snævres ind. Lydfilerne i var/recordings bliver liggende
-- uden række (kunders stemmer): køres down, skal de slettes bagefter (changes/F004.md, "Rollback"). Rollback er ellers flaget.
DELETE FROM calls WHERE `source` = 'adversus';
DELETE FROM sync_state WHERE `source` IN ('adversus', 'adversus-rec', 'adversus-leads', 'adversus-users', 'adversus-warn');
UPDATE calls SET transcript_status = 'ventende' WHERE transcript_status = 'ikke_valgt';
ALTER TABLE calls MODIFY transcript_status ENUM('ventende','transcriberer','faerdig','fejlet') NOT NULL DEFAULT 'ventende';
ALTER TABLE calls MODIFY `source` ENUM('zendesk') NOT NULL;
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_success;
ALTER TABLE calls DROP INDEX IF EXISTS calls_lead;
ALTER TABLE calls DROP INDEX IF EXISTS calls_agent_ext;
ALTER TABLE calls DROP COLUMN IF EXISTS is_success;
ALTER TABLE calls DROP COLUMN IF EXISTS agent_ext_id;
ALTER TABLE calls DROP COLUMN IF EXISTS campaign_id;
ALTER TABLE calls DROP COLUMN IF EXISTS lead_id;
DROP TABLE IF EXISTS adversus_pending;
DROP TABLE IF EXISTS adversus_users;
DROP TABLE IF EXISTS adversus_success_leads;
