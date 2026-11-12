-- Down for 0008: fjerner markeringen af manuelt valgte opkald (H012). Opkald, der er valgt, men ikke færdige, falder
-- tilbage under reglerne og vises igen som "For kort" / "Ikke automatisk". Færdige transcripts er uberørt.
ALTER TABLE calls DROP COLUMN IF EXISTS manual_at;
