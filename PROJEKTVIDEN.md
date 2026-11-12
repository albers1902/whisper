# PROJEKTVIDEN — whisper.xanouso.com

Målt viden om kodebasen og miljøerne. Kurateret.

## Miljøer
- **DEV:** `dev.whisper.xanouso.com` på prøvebanen `vmi3661273` (dev.xanouso.com). Bruger `whisperdev`, PHP 8.4,
  MariaDB 12.3, baserne `whisperdev_app` og `whisperdev_ci`. Sat op 2026-11-11 (S-20261111-1 til -4).
- **STABLE:** `whisper.xanouso.com` er planlagt på drift, men ikke sat op (DECISIONS #4).

## Miljønavn til feature flags (F001, 2026-11-11)
- Appen kan ikke selv se, hvilket miljø den kører i. deploy.sh giver ikke `MODE` videre.
- Flagene læser derfor `[app] env` i serverens `~/config/config.ini`, som ikke ligger i git. Gyldige værdier er
  `dev`, `test` og `stable`. `development` læses som `dev`, og `production` som `stable`. En manglende eller ukendt
  værdi betyder, at alle flag er slukket.
- Hvert miljø skal have linjen en gang, før flagede funktioner bliver synlige. Ejeren skriver den med
  `vi ~/config/config.ini` som sitets bruger:
  ```
  [app]
  env = dev
  ```
- En eksempelfil kan ikke ligge i repoet, fordi `.gitignore` udelukker `**/config/*.ini` (frameworket).

## Zendesk og whisper i config.ini (F002)
- `~/config/config.ini` ligger ikke i git. Sektionerne skrives af ejeren med `vi ~/config/config.ini`:
  ```
  [zendesk]
  subdomain = trygenergidanmarkhelp
  client_id = <OAuth-klientens Identifier>
  client_secret = <OAuth-klientens Secret>
  since = 2026-10-01
  scope = read
  ; recording_hosts = <vært>   (kun hvis zendesk-probe.php siger "ikke godkendt")

  [whisper]
  model = turbo

  ; [diarization]   (H011, alle valgfri; uden sektionen gælder standarderne herunder)
  ; enabled = on    (off = nødbremse: ingen nye talere; visningen følger flaget H011_diarization)
  ; agent_phrases = du snakker med|du taler med|du har ringet til|velkommen til|fra tryg energi
  ; threads = 3     (standard: som whispers tråde pr. worker)
  ```
- Auth sker med OAuth `client_credentials`, for API-tokens er fjernet af Zendesk (DECISIONS #14). Tokenet caches i
  `~/var/zendesk-token.json` (0600).
- Motoren: `bash ~/cron/whisper-setup.sh` laver venv'et og modellen i `~/var/whisper/` og skriver markøren
  `ready-<model>`. Uden markøren tager transcription intet. Skiftes modellen, skal setup køres igen.
- Cron: `bash ~/cron/whisper-cron.sh` tilføjer `zendesk-sync.php` (hvert 10. minut) og `transcribe.php` (hvert
  minut) til sitets egen crontab. `server.sh` lægger kun frameworkets egne linjer.
- Begge cron-scripts gør intet, når `F001_brugere` eller `F002_zendesk` er slukket i miljøet.
- Fejlede opkald sendes tilbage i køen med `php ~/cron/whisper-requeue.php [--id=N]`.
- Hvem der har hentet lyd, transcripts og zip, står i `~/logs/adgang.log`.

## Transcription-hastighed og kvalitet (H005)
- Før H005 tog et opkald ca. 8 min. (120 opkald på 16 timer, 2026-11-12) med faster-whispers standarder (4 tråde,
  beam 5, ingen VAD).
- Efter H005 er standarden kerner−1 tråde (5 på DEV), `beam_size = 1` og `vad = on`. Loggen viser lyd, tid og RTF pr.
  opkald.
- Kvalitet måles ikke automatisk. Efter en ændring stikprøvekontrolleres 3–5 opkald: lyt og læs. Mangler der tale,
  sættes `vad = off`, og er teksten mærkbart dårligere, sættes `beam_size = 5`, begge under `[whisper]` i
  `~/config/config.ini`. Det kræver ingen deploy.

## Hvem taler (H011)
- `cron/diarize.php` (hvert minut, lagt af `whisper-cron.sh`) lægger talere på færdige transcripts, kun når
  transcription-køen er tom, nyeste først. Den stopper inden for ca. 5 s, når et opkald kommer. Log: `~/logs/diarize.log`,
  motorens fejl: `~/var/whisper/diarize-fejl.log`.
- En motorfejl giver 30 min pause (`~/var/whisper/diarize-pause`) uden brugte forsøg. Et opkald prøves højst 3 gange med
  mindst 30 min imellem.
- Beregn igen (fx efter ændrede `agent_phrases`): `php ~/cron/diarize.php --reset <opkalds-id|fejlet|alle>`.
- Down-migrationen 0007 må kun køres efter en revert af H011-koden (`Transcriber` skriver kolonnerne).

## Sessioner (F001)
- PHP-sessionerne ligger i `~/var/sessions` (0700, oprettes af appen). `var/` er projektets efter SERVERKONTRAKT §2,
  står i `open_basedir` (S-20261111-3), og deploy rører den ikke.
- Appen rydder selv op: `gc_maxlifetime` er 8 t, og GC-sandsynligheden er 1/100.

## Klient-IP (F001-throttling)
- Appen bruger kun `REMOTE_ADDR` og stoler aldrig på `X-Forwarded-For`.
- Contabos firewall er kun et portfilter. Port 80 og 443 er åbne direkte på prøvebanen (84.46.252.242) og på drift
  (169.58.51.76), målt udefra 2026-10-21 (MASKINER.md). Der er altså ingen dokumenteret proxy eller CDN.
- Målt på DEV 2026-11-11 af ejeren. `mod_remoteip` er indlæst (`mods-enabled/remoteip.load`), men `grep -Ri remoteip
  /etc/apache2/` finder ingen `RemoteIPHeader`- eller `RemoteIPTrustedProxy`-direktiver, kun en kommentar i
  `apache2.conf`. Modulet ændrer derfor ikke `REMOTE_ADDR`, og den er klientens egen adresse. F001-S2 er dermed
  bekræftet for DEV.
- Det skal måles igen på STABLE, før `stable = on` (DECISIONS #10). Hvis nogen senere sætter `RemoteIPHeader` uden
  `RemoteIPTrustedProxy`, kan `REMOTE_ADDR` forfalskes via headeren. Det er serverens sag.

## Ruter
- `/health` rammes af den første regel i `public_html/.htaccess`. Den er aldrig bag login eller flag.
- Alt andet går til `public_html/index.php` (front controller).
