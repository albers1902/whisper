-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- H012: hvornår en bruger valgte et opkald med "Transcribér dette opkald". Et valgt opkald tages, selv om det er kortere
-- end [whisper] min_seconds eller ældre end max_age_days. "For kort" og "Ikke automatisk" gemmes ikke; de regnes ud af
-- config, når køen bruges (src/Transcription/QueueRules.php).
ALTER TABLE calls ADD COLUMN IF NOT EXISTS manual_at DATETIME NULL AFTER claimed_at;
