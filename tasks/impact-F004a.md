# Impact F004a — Adversus-probe (read-only)

Tier: 2

Grund: Et nyt CLI-værktøj med en ekstern integration og en hemmelighed (Adversus API-bruger). Det har ingen
migration, gemmer intet og har intet UI. Critic er `security-critic` (hemmeligheder, SSRF og persondata i output).

## Berørte stier
- `homedir/src/Adversus/` (ny)
- `homedir/cron/adversus-probe.php` (ny)
- `homedir/tests/`

## Persondata og eksterne modtagere
- Kilde: Adversus. Proben læser data, men viser kun feltnavne og tal og gemmer intet. Én optagelse hentes og slettes
  igen med det samme.
- Eksterne modtagere: ingen. Der sendes intet personhenførbart til Adversus.

## Risiko
At hemmeligheder eller persondata slipper ud i proben eller i loggen. Det modvirkes ved kun at vise feltnavne og tal,
rense fejltekster og dække det med tests (AK2 og AK5).

## Rollback
Revert-H. Værktøjet køres kun manuelt.
