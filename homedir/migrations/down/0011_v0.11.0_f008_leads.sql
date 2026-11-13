-- Down for 0011: fjerner F008's indeks. Ingen data røres; lead-listen bliver langsommere ved mange opkald.
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_lead;
