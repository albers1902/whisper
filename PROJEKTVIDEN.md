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

## Sessioner (F001)
- PHP-sessionerne ligger i `~/var/sessions` (0700, oprettes af appen). `var/` er projektets efter SERVERKONTRAKT §2,
  står i `open_basedir` (S-20261111-3), og deploy rører den ikke.
- Appen rydder selv op: `gc_maxlifetime` er 8 t, og GC-sandsynligheden er 1/100.

## Klient-IP (F001-throttling)
- Appen bruger kun `REMOTE_ADDR` og stoler aldrig på `X-Forwarded-For`.
- Contabos firewall er kun et portfilter. Port 80 og 443 er åbne direkte på prøvebanen (84.46.252.242) og på drift
  (169.58.51.76), målt udefra 2026-10-21 (MASKINER.md). Der er altså ingen dokumenteret proxy eller CDN.
- Ikke målt endnu på DEV: `grep -Ri remoteip /etc/apache2/` og `apachectl -M | grep -i remoteip` skal være tomme, og
  access-loggen skal vise klientens egen IP. Det skal måles igen på STABLE, før `stable = on`.

## Ruter
- `/health` rammes af den første regel i `public_html/.htaccess`. Den er aldrig bag login eller flag.
- Alt andet går til `public_html/index.php` (front controller).
