-- reversibel: ja
-- destruktiv: nej
-- idempotent: ja
-- F008: Adversus pr. lead. Listen grupperer opkaldene pr. lead_id (antal leads med loft, leads sorteret efter seneste
-- opkald), og lead-siden henter et leads opkald i tidsrækkefølge. Indekset (source, lead_id, started_at, id) bærer begge
-- som indeksopslag i stedet for opslag i rækken. transcript_status og audio_status står ikke i indekset, så
-- transcription-workerens statusskift ikke skal vedligeholde det (transcription må ikke blive langsommere).
-- Ingen overlap med calls_lead (lead_id) fra 0005, som markSuccess bruger, eller med F007's indekser (0010).
-- InnoDB tilføjer indekset online.
ALTER TABLE calls ADD INDEX IF NOT EXISTS calls_source_lead (`source`, lead_id, started_at, id);
