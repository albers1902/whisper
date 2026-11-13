# Impact H014: .htaccess uden direktiver, som DEV ikke tillader (F007 kunne ikke udrulles)

Tier: 2

Grund: kun webserver-konfiguration (`homedir/public_html/.htaccess`) og tests. Ingen migration, ingen data, ingen
input, ingen auth, ingen ekstern modtager. Ingen kontrolfil (`coworker.ini` og `homedir/cron/` er urørt).
Critic: `qa-critic` (tier 2 uden data/adgang/input).

## Berørte stier
- `homedir/public_html/.htaccess` (F007-blokken med `AddType`/`Header` fjernes)
- `homedir/tests/test_F007-AK9_csp_kontrast.php` (tjekket for `AddType font/woff2` fjernes)
- `homedir/tests/test_H014-AK1_htaccess_whitelist.php` (ny)
- `changes/H014.md`

## Risiko
- Fonte sendes med Apaches standard-MIME (normalt `font/woff2` fra `mime.types`) og standard-cache. Browsere afviser
  ikke woff2 på type, så visningen er uændret. Lang cache kræver serverkonfiguration (sysadmin), hvis det ønskes.

## Rollback
Revert-H. Det genindfører blokken, der giver 500 på DEV, så i praksis er der intet at rulle tilbage til.
