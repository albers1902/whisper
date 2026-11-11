<!-- FILE_VERSION: 1.1 — 2026-10-30. Ejes af Sysadmin-Lead. Leveres til coworker-development
     som shared/FEJLKATALOG.md.
     1.1: rettet efter sysadmin/aendringer/GENNEMGANG-2026-10-26.md afsnit 3 — §1 rækkefølgen
     i ERR-fælden, §4 begrundelsen for ikke-ASCII, §5 reload kontra restart. -->
# Fejlkataloget — de fejl der har kostet tid, og hvordan de findes

Alle fejl herunder er fundet i dette projekts egen kode, reproduceret, og rettet. De står
her af én grund: **`bash -n` og `shellcheck -S warning` sagde ok til dem alle.** Et
syntakstjek er derfor ikke en gennemgang, og det er den konklusion hele `GENNEMGANG.md`
hviler på.

Læs afsnit 1 før du skriver bash der skal køre på en maskine. Det er den samme fejl hver
gang, i otte forklædninger.

---

## 1. Familien: tildelingen der dør tavst

```bash
set -euo pipefail
x="$(kommando)"        # fejler kommandoen, fejler tildelingen, og scriptet
                       # afsluttes UDEN ÉT ORD — også med 2>/dev/null på
valider "$x"           # nås aldrig
```

Valideringen på næste linje nås aldrig. Der kommer ingen fejlbesked, intet exit-spor i
output, ingen linje i loggen. Scriptet stopper midt i sit arbejde og ser ud som om det blev
færdigt.

**Det er den værste fejltype vi har mødt**, fordi den rammer *efter* arbejdet er gjort, men
*før* beviset bliver skrevet: backuppen var i hus, off-site-pushet var lykkedes, og alarmen
sagde alligevel "ingen backup".

### De otte forklædninger — alle målt

| # | Mønster | Hvorfor den fejler |
|---|---|---|
| 1 | `n="$(mysql … \| wc -l)"` | MariaDB svarer på alt undtagen `information_schema` → 0 linjer, ingen fejl, tildelingen dør |
| 2 | `s="$(du -sh "$D" \| cut -f1)"` | `du` slutter 1 hvis bare én fil forsvinder undervejs. `cut` lykkes, `pipefail` gør pipen 1. **Race målt: 5 af 5 forsøg** med en baggrundsproces der sletter filer under `du` |
| 3 | `LEDIG="$(df -P -BG --output=avail / )"` | `-P` og `--output` er en **ulovlig flagkombination** i GNU `df`. Den dræbte en bootstrap på en maskine i drift, efter scriptet havde printet sin egen overskrift og intet andet |
| 4 | `fejl(){ …; logger …; }` | funktionen slutter på `logger`. Fejler `logger`, dør scriptet **ved den første fejl det skulle melde** |
| 5 | `galt="$(awk … \| tr …)"` | samme som 2, med `awk` |
| 6 | `stat -c %Y` inde i en procesudskiftning | filen kan være væk |
| 7 | `printf … \| mail …` som bart statement | fejler `mail`, dør scriptet |
| 8 | bar `grep` på et legitimt resultat | `grep` slutter **1** når der ikke er match. Et tomt resultat er ofte det rigtige svar, men `set -e` læser det som en fejl |

### Rettelsen er ikke `|| x=0` alle steder

På et tabeltal ville `0` få scriptet til at konkludere "tom base" og skrive et **bevis** for
en base vi ikke kunne måle. Det er værre end at fejle. Reglen:

- Er værdien **bevismateriale** → tildelingen skal have `|| true`, og den manglende værdi
  skal behandles som "ukendt", ikke som nul. Dagens resultat kasseres.
- Er værdien **kosmetisk** (en størrelse i en rapport) → `|| true` og en pladsholder.
- Er værdien **en forudsætning** → mål den, og stop med en navngiven fejl hvis den mangler.

### Det der fanger dem

```bash
set -Eeuo pipefail
TRIN="(før trin 1)"
say(){ TRIN="$*"; printf '\n==> %s\n' "$*"; }
paa_fejl(){
  local kode=$?                        # FØRST — ellers er exit-koden væk
  [[ $BASHPID == "$$" ]] || return 0   # vagten EFTER, aldrig før
  printf '\nAFBRUDT (exit %s) — trin: %s\n  linje %s: %s\n' "$kode" "$TRIN" "$1" "$2" >&2
}
trap 'paa_fejl "$LINENO" "$BASH_COMMAND"' ERR
```

ERR-fælden gør den tavse død hørbar: trin, linjenummer og den kommando der fejlede.
Målt mod 15 legitime mønstre i en fil på 1300 linjer: nul falske fyringer. Vagten
`[[ $BASHPID == "$$" ]]` forhindrer at fælden fyrer to gange, når en funktion kører i en
subshell.

**Rækkefølgen i `paa_fejl` er ikke valgfri.** Den første udgave af dette katalog havde
vagten før `local kode=$?`, og så måler `$?` vagtens eget resultat: målt `AFBRUDT (exit 0)`
på en kommando der fejlede med 7 (rettet 2026-10-30 efter
`sysadmin/aendringer/GENNEMGANG-2026-10-26.md`). Og fælden kan fyre flere gange på
hovedforløbet, ikke kun i funktioner.

---

## 2. Værktøjet staver navnet anderledes end du gør

```bash
php -m | grep -qix opcache        # finder intet
```

PHP kalder den **`Zend OPcache`**. Tjekket gav tre falske advarsler i træk, og hver af dem
sendte nogen afsted for at rette noget der ikke var i stykker.

**Reglen:** mål hvad værktøjet faktisk udskriver, før du matcher på det. `php -m`,
`systemctl list-units`, `apt-cache policy` og `ss` staver alle noget anderledes end
dokumentationen.

**Et råd der ikke kan køres, er værre end intet råd**, fordi det sender folk afsted. Målt
eksempel fra dette projekt: et script svarede `root: dnf install python3` på en
**Ubuntu**-maskine, hvor det er `apt install python3-venv` — og pakken var i forvejen
installeret.

---

## 3. Gennemgangen der indførte to nye fejl

To uafhængige review-blik fandt to rigtige fejl i et script — og indførte samtidig to nye:
et root-tjek der blev slået fra (`[[ 1 -eq 1 ]]`), og en sti der blev ændret, så en måling
tavst målte ingenting.

De blev fundet ved at **diffe gennemgangens resultat mod originalen**, linje for linje, og
derefter verificere de to ændrede steder selvstændigt.

**Reglen:** en gennemgang er et input, ikke en konklusion. Dens resultat skal diffes mod
det den rettede, og dens egne ændringer skal efterprøves som enhver anden ændring.

---

## 4. Ikke-ASCII i et variabelnavn

Fanget af hverken `bash -n` eller `shellcheck`. Et æ i et variabelnavn giver en kørselsfejl
i **alle** locales — målt, også med `LC_ALL=C` og i en UTF-8-locale. (Den første udgave
sagde "på en maskine med en anden locale"; konklusionen var rigtig, begrundelsen forkert.)
Og uden `set -e` er det værre end en fejl: `$æ` efterlades literalt i strengen, og
scriptet kører videre.

Kun ASCII i navne — æøå hører i de strenge der udskrives. `LC_ALL=C` hører i scripts der
sorterer eller sammenligner, men af en anden grund (`agents/shell.md`).

---

## 5. En indstilling der ligger på disken, men ikke er i kraft

En `.ini`-fil i en scan-mappe gør ingenting, før hovedprocessen har genlæst konfigurationen
efter filen blev skrevet. Og `<binær> -i` læser **disken**, ikke den kørende proces — den
svarer altså "rettet" på en proces der kører videre med den gamle værdi.

Det her er den fejl der nemmest bliver stående, fordi verifikationen ser rigtig ud.
Fremgangsmåden står i `GENNEMGANG.md` afsnit 2, trin 3: spørg puljen, ikke binæren.

**Rettet 2026-10-30:** her stod at `reload` kun genstarter arbejderne, og at en ny `.ini`
kræver `restart`. Det er **målt forkert** (2026-10-26, to gange uafhængigt): `reload` sender
USR2, hovedprocessen re-eksekverer sig selv og genlæser konfigurationen uden at tabe
forbindelser. Reglen kostede unødig nedetid på tværs af sites. Og `ActiveEnterTimestamp`
er ikke et bevis efter en reload — se `GENNEMGANG.md` afsnit 2, trin 3.

Om en reload også tager en **ny binær** efter en pakkeopgradering, er ikke målt. Der
bruges `restart`.

---

## 6. Målt forkert i et dokument, troet i en uge

Tre påstande der stod i vores egen dokumentation og **ikke holdt ved måling**:

| Påstod | Virkeligheden |
|---|---|
| "MariaDB 12.3 defaulter til `uca1400`" | `collation_server` er `utf8mb4_general_ci` på **begge** maskiner |
| "Afværgelsen ligger på drift-maskinen" | Filen var aldrig skrevet; alle tre PHP-versioner stod `On` |
| "CWP og Rocky er væk" | CWP-maskinen kører 57 sites på upatchet alt-php |

Alle tre blev opdaget ved at nogen spurgte "er det nu rigtigt?" og kørte kommandoen.

**Reglen:** en påstand i et dokument har samme status som en påstand i en chat. Står der et
tal, skal der stå hvornår det blev målt — og en linje uden dato skal ikke bruges som
grundlag.

---

## 7. Åbne defekter i `sitectl` 1.1 — målt, ikke rettet

Det her rammer projekter direkte. De er målt på en maskine, og de er **ikke** rettet endnu;
rettelsen ligger i `sitectl` 1.2.

| Sted | Hvad der sker |
|---|---|
| `sitectl db` | opretter med `COLLATE utf8mb4_general_ci`. Appens egne tabeller forventer `unicode_ci` → `Illegal mix of collations` i en JOIN |
| `sitectl new` | opretter **ikke** `config/`, selvom layoutet forventer den |
| `open_basedir` fra `sitectl new` | mangler `config/`, `logs/`, `src/` og `vendor/`. Målt til at knække coworker-frameworkets eget layout |
| `sitectl check <domæne>` | `check` tager kun `--quiet` og **sluger tavst** et domæne-argument. Du får maskinens svar, ikke sitets, uden at noget siger det |

Der er også et fund på den anden side: **coworker-frameworkets CI opretter databaser uden
`COLLATE`.** Målt: 11 tabeller i `devdesk_ci`. På MariaDB 12.3 (dev) arver de serverens
default, og det er ikke nødvendigvis det appen forventer. Opret altid eksplicit med
`COLLATE utf8mb4_unicode_ci`, i begge ender.

---

## 8. Baseline arver det der var galt, da den blev taget

En godkendt baseline siger "sådan ser maskinen ud", ikke "sådan **bør** maskinen se ud".
Docker og containerd kørte på drift-maskinen fra dag ét, blev aldrig brugt, og var derfor
"normalt" i baseline — ingen afvigelsesrapport kunne finde dem.

**Reglen:** baseline skal gennemgås én gang i sig selv, ikke kun dens afvigelser bagefter.
Spørgsmålet er ikke "er der ændret noget", men "burde noget af det her køre".
