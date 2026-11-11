<!-- FILE_VERSION: 5.42 -->
# Feature ID
F000

# Version
v1

# Status
DRAFT

<!--
Statuses for engineer-fil (/fullstack-ai/F00X_vN.md) — se LIFECYCLE.md "Statuses":
DRAFT / READY_FOR_REVIEW / CHANGES_REQUIRED. Filen ændres aldrig efter merge — hvor ID'et kører,
og om det er Released, beregner `bash shared/report.sh id <ID>` (aldrig en status i filen).

Statuses for spec-fil (/product-ai/F00X_vN.md):
DRAFT / READY_FOR_IMPLEMENTATION / CHANGES_REQUIRED

Naming: F001_v1.md. Never overwrite — new version = new file (F001_v2.md).
Sections marked "(if applicable)" may be omitted when truly not relevant.
Keep entries terse. Implementation-ready, not essays.

Tier bestemmer hvilke sektioner der kræves (LIFECYCLE.md §4):
TIER 1: alle sektioner, critics før merge, design briefs kun ved UI.
TIER 2: kort spec, ingen design/critics.
TIER 3: one-liner — kun Goal, Files to deliver og Feature flag (hvis synlig adfærd ændres).
Engineer leverer FULL package i sin afslutningsbesked fra v1.
-->

# Goal
<!-- One sentence. Observable outcome. -->

# Scope
<!-- Bullet list of what IS being built. Concrete. -->

# Scope-kategorisering
<!-- Kategorisér HVERT element i Scope som:
  (1) Kerne — direkte del af feature-kravet
  (2) Infrastruktur — deploy, config, tooling, CI/CD
  (3) Fremtidssikring — nice-to-have, forberedelse til fremtidige features
Kategori 2 og 3 kræver eksplicit go-ahead fra menneske hvis emnet
er nyt for projektet. Udelad denne sektion for simple features. -->

# Requirements

# Acceptkriterier
<!-- Tier 1–2: nummererede, testbare. CI afviser en F-PR, hvis et AK ikke har en test,
     der nævner "<ID>-AKn" i testnavnet. Eksempel:
- AK1: En bruger uden rolle "admin" får 403 på /op/tenants.
- AK2: Eksport af 10.000 rækker tager under 5 sekunder. -->

# User flow
<!-- Step-by-step. Include error paths. -->

# Edge cases
<!-- Enumerated. Each one with how it's handled. -->

# Constraints
<!-- Technical, regulatory, time, or scope constraints. -->

# Out of scope
<!-- Explicit list of what is NOT being built. Prevents scope creep. -->

# Data model
<!-- (if applicable) New/changed tables, columns, types, constraints. -->

# API surface
<!-- (if applicable) Endpoints with method, auth, request/response shape, rate limit. -->

# Multi-tenant implications
<!-- (if applicable) Cross-account isolation rules. If single-tenant: write "None". -->

# Feature flag
<!-- Navn i homedir/config/features.ini (fx F005_widget_themes) og startværdier:
     dev = on, test = off, stable = off. "Ingen" for rene fejlrettelser (H00X). -->

# i18n keys
<!-- (if applicable) New keys per supported locale. -->

# Files to deliver
<!-- Every new/modified file with FULL path. Group by repo. Mark NEW or MODIFIED.
     Fullstack Engineer should not need to guess filenames or locations. -->

# Decisions
<!-- Resolved architectural decisions with one-line rationale.
     Format: [Decision number]. Decision. — Rationale. (resolver, date) -->

# Hand-off notes
<!-- One short paragraph per downstream agent:
     - Fullstack Engineer
     - Security Critic
     - QA Critic
-->

<!-- ============================================================
ENGINEER-LEVERANCE SEKTIONER NEDENFOR — udfyldes i /fullstack-ai/F00X_vN.md
(IKKE i /product-ai/-spec'en — det er engineer's del af pakken)
============================================================ -->

# System understanding
<!-- PÅKRÆVET FØR IMPLEMENTATION — afleveres ALENE som fullstack-ai/F00X_v0.md, branchens første commit,
     og læses af Team Lead, før der kodes (CI: findes, overskrift, mindst to filstier, før koden).
     Beskriv det berørte delsystem baseret
     på hvad du HAR LÆST — ikke hvad du antager.

     1. Hvordan virker det berørte delsystem ende-til-ende?
     2. Hvilke andre dele af systemet rører samme data/config/flow?
        (tværgående afhængigheder — delt tilstand der bruges flere steder)
     3. Hvad er prøvet før? (tjek launched/F00X/ for tidligere
        implementationer og LEARNINGS.md)
     4. Hvad ved du IKKE endnu? (ubekendte du har identificeret)

     Kilde for hver påstand: filnavn + hvad du læste.
     Ingen "jeg antager" uden at have slået det op først.

     Hvis denne sektion er forkert, er implementationen forkert.
     Formålet er at menneske eller Team Lead kan fange en forkert
     model BILLIGT — før der er skrevet kode. -->

# Implementation summary
<!-- Hvad er bygget. Match scope fra spec. Kort. -->

# Files delivered
<!-- Hver fil med fuld lokal sti, NEW/MODIFIED. -->

# Migrations applied
<!-- Faktiske nummre claimet ved impl-tid. Beskriv hvad hver gør. -->

# Tests added
<!-- Liste over test-filer + coverage-procent for feature-area. -->

# Decisions taken during implementation
<!-- Beslutninger inden for spec's autonomi. -->

# Deviations from spec
<!-- Hvis du har afveget: forklar + forsvar. Hvis afvigelse er spec-niveau:
     flag CHANGES_REQUIRED i stedet. -->

# Deploy package
<!-- Pligtig fra v1. Deploy sker automatisk: serveren henter grønne
     commits selv (LIFECYCLE.md §8). Ingen FTP,
     ingen manuel upload. -->

## Branch og commit
<!-- feature/F00X-slug @ <commit-SHA>. CI: grøn / rød (link). -->

## Feature flag
<!-- Navn + værdier i homedir/config/features.ini. Hvad sker der med flaget off/on. -->

## Migrationer
<!-- Nummer(e), hvad de gør, og hvorfor de er bagudkompatible
     (gammel kode skal kunne køre på nyt skema). -->

## Smoke tests
<!-- Test-trin med FORVENTET output, per miljø (DEV med flag on,
     STABLE med flag off). Operator skal kunne verificere. -->

## Ændringsfragment
<!-- changes/F00X.md (eller -2.md …) i samme PR som koden. Første linje "# F00X: Titel";
     Hvad, Hvorfor, Flag, Migrationer, Rollback, og for F ALTID:
     Kunde: én sætning kunden forstår · Support: hvad support skal vide. CI afviser uden. -->

## Rollback
<!-- 1) Før flag-oprydning: stable = off i en ny release.
     2) Efter flag-oprydning: nyt H-nummer "H0NN: revert F00X" — commits fra
        `bash shared/report.sh id F00X`, revertes nyeste først.
     3) Hele STABLE: rollback-udkast mod forrige releases commit (Team Lead),
        mennesket udgiver. Migrationer rulles IKKE tilbage — derfor kun
        expand i STABLE (LIFECYCLE.md §6). -->
