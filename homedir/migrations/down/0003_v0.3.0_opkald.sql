-- Down for 0003: fjerner opkald, transcripts og sync-cursoren (F002). Lydfilerne i var/recordings bliver liggende.
DROP TABLE IF EXISTS transcripts;
DROP TABLE IF EXISTS calls;
DROP TABLE IF EXISTS sync_state;
