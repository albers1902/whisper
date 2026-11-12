-- Down for 0010: fjerner F007's indekser. Ingen data røres; listen bliver langsommere ved mange opkald.
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_started;
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_status;
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_agent;
