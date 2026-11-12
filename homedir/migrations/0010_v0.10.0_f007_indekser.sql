-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F007: indekser til fanerne, statusfiltrene og agentlisten, så listen er hurtig ved flere hundrede tusinde opkald.
-- calls_source_started: fanen sorteret efter tid; is_success og transcript_status er med i indekset, så Adversus'
--   "Succes og valgte" filtreres i indekset uden opslag i rækken.
-- calls_source_status: "Færdige" og "I kø" pr. fane. calls_source_agent: agentlisten pr. fane (DISTINCT i indekset).
-- InnoDB tilføjer indekser online (skrivning blokeres ikke), så transcription kører videre.
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_started (`source`, started_at, is_success, transcript_status);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_status (`source`, transcript_status, started_at);
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_agent (`source`, agent_name);
