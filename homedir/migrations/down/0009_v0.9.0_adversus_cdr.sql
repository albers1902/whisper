-- Down for 0009: fjerner H013's lead-tabel, disposition og de to indeks. adversus_leads er afledt (hentes igen fra
-- Adversus), og adversus_success_leads er urørt, så den gamle kode virker videre. Gemte opkald røres ikke.
ALTER TABLE calls DROP INDEX IF EXISTS calls_audio_queue;
ALTER TABLE calls DROP INDEX IF EXISTS calls_source_started;
ALTER TABLE calls DROP COLUMN IF EXISTS disposition;
DROP TABLE IF EXISTS adversus_leads;
