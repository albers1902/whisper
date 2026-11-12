-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- H011: hvem taler. Diarization er et separat trin i ledig tid (cron/diarize.php); talerne står pr. segment i
-- transcripts.segments_json ("speaker"/"role"). Kolonnerne her er kun trinnets bogføring: NULL i diar_status = mangler,
-- 'faerdig' = talerne er beregnet (eller der var ingen tale), 'fejlet' = opgivet efter 3 forsøg. En ny transcription
-- nulstiller dem (Transcriber::finish), så et opkald, der transcriberes igen, også får nye talere.
ALTER TABLE transcripts
    ADD COLUMN IF NOT EXISTS diar_status   ENUM('faerdig','fejlet') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS diar_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS diarized_at   DATETIME NULL DEFAULT NULL;
