-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- Down for 0007 (H011): fjerner kun diarization-trinnets bogføring. Talerne i segments_json bliver stående (koden før
-- H011 ignorerer nøglerne), og bogføringen genskabes ved at køre trinnet igen.
ALTER TABLE transcripts
    DROP COLUMN IF EXISTS diarized_at,
    DROP COLUMN IF EXISTS diar_attempts,
    DROP COLUMN IF EXISTS diar_status;
