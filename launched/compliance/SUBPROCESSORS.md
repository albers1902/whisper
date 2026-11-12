<!-- FILE_VERSION: 5.42 — fra shared/templates/SUBPROCESSORS.md.template (F003). Opdateres i samme PR som en ny ekstern modtager (tier 1, LIFECYCLE §4). -->
# Underdatabehandlere og eksterne modtagere — Whisper

| Modtager | Formål | Hvilke data | Land / overførselsgrundlag | DPA indgået | Fra dato | Til dato | ID |
|---|---|---|---|---|---|---|---|
| Anthropic (Claude Desktop / Claude API) | Analyse af transcripts i Claude Desktop efter brugerens anmodning (MCP, kun når en bruger med egen nøgle forbinder) | Transcripts, telefonnumre, agentnavne og metadata om opkald (ingen lydfiler, ingen filstier) | udfyldes af ejeren | udfyldes af ejeren | 2026-11-11 | — | F003 |
| Adversus (api.adversus.io; lyd via godkendt lagervært i `recording_hosts`) | KILDE, ikke modtager: Whisper henter opkaldsmetadata, succes-leads, agentnavne og lyd (kun succes-opkald og manuelt valgte). Der sendes ingen kundedata til Adversus, kun API-login og tids- og statusfiltre | Lokalt gemmes opkalds-id, lead-id, kampagne-id, agent-id og -navn, retning, tidspunkt, varighed, telefonnummer (som for Zendesk) og lyd. Transcription sker lokalt (DECISIONS #2). Den signerede lyd-URL gemmes aldrig | udfyldes af ejeren | udfyldes af ejeren | 2026-11-12 | — | F004 |
