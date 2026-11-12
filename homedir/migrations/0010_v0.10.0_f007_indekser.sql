-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F007: indekser til fanerne, statusfiltrene og agentlisten, så listen er hurtig ved flere hundrede tusinde opkald.
-- calls_source_started: fanen i listens rækkefølge (started_at DESC, id DESC); is_success og transcript_status efter id,
--   så Adversus' "Succes og valgte" filtreres i indekset uden opslag i rækken.
-- calls_source_status: "Færdige" og "I kø" pr. fane i listens rækkefølge. calls_source_agent: agentlisten pr. fane.
-- Ingen overlap med H013's calls_audio_queue (source, transcript_status, audio_status).
-- InnoDB tilføjer indekser online (skrivning blokeres ikke), så transcription kører videre.
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_started (`source`, started_at, id, is_success, transcript_status);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_status (`source`, transcript_status, started_at, id);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_agent (`source`, agent_name);
