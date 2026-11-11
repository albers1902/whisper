<!-- FILE_VERSION: 1.1 — 2026-10-30. Ejes af Sysadmin-Lead. Leveres til coworker-development
     som shared/GENNEMGANG.md. Redigér den ikke i et projekt.
     1.1: rettet efter sysadmin/aendringer/GENNEMGANG-2026-10-26.md afsnit 3 — `reload` af
     PHP-FPM tager en ny .ini (målt), `grep -r` følger ikke symlinks i sites-enabled/, og
     ActiveEnterTimestamp er ikke et bevis efter en reload. -->
# Gennemgang — hvordan noget bliver efterprøvet, før det kaldes rigtigt

Den her fil findes, fordi "det er gennemgået" betyder fire forskellige ting, og tre af dem
har kostet en aften i dette projekt. Den fastlægger hvad der tæller, i hvilken rækkefølge
der måles, og hvad der skal stå i et fund, så en anden agent kan efterprøve det uden at
have været med.

Den gælder serversiden, og den gælder alt et projekt leverer der ender på en maskine.

---

## 1. De fire niveauer af bevis

| Niveau | Hvad der er sket | Hvad det er værd |
|---|---|---|
| **Påstået** | nogen siger det virker | ingenting |
| **Syntakstjekket** | `bash -n`, `shellcheck`, `php -l`, en linter | fanger tastefejl. Fanger **ingen** af de fejl der er beskrevet i `FEJLKATALOG.md` |
| **Gennemgået** | et andet blik har læst koden og reproduceret dens fund | fanger logik. Fanger ikke hvad maskinen faktisk svarer |
| **Målt i drift** | kørt på en rigtig maskine, output gemt | det eneste der tæller som bevis |

**Reglen:** intet leveres videre til nogen — ikke til et andet projekt, ikke til
framework-lead, ikke til mennesket som "klar" — før niveau 4. Et script der er
syntakstjekket og gennemgået, men ikke kørt, leveres som **"ikke kørt"**, og det ord skal
stå i leverancen.

Det er ikke et krav om mere omhu. Det er fordi niveau 2 og 3 beviseligt har godkendt kode
der døde tavst på første kørsel. To gange, dokumenteret i `FEJLKATALOG.md`.

### Hvad der IKKE er en gennemgang

- At scriptet er langt og velkommenteret.
- At samme model har læst det igen.
- At `bash -n` siger ok.
- At en agent svarer "jeg har gennemgået det". Spørg hvilken kommando der frembragte
  konklusionen. Kan den ikke navngives, er konklusionen en formodning.

---

## 2. Rækkefølgen når noget skal efterprøves på en maskine

Måling før ændring. Altid. Også når svaret virker åbenlyst.

**Trin 1 — hvad kører der i virkeligheden.**
Ikke hvad der er installeret, men hvad der betjener trafikken. Det er sjældent det samme.
Eksempel målt 2026-10-21: en maskine havde fire PHP-FPM-hovedprocesser kørende, og den
opdatering der var planlagt ville have ramt den ene ingen sites brugte.

```
systemctl list-units --type=service --state=active 'php*-fpm.service'
grep -Rh 'proxy:unix\|SetHandler' /etc/apache2/sites-enabled/ | sort -u
```

`-R`, ikke `-r`: `sites-enabled/*` er symlinks, og `grep -r` følger dem ikke. Med `-r`
er svaret tomt med exit 0 — altså "intet fundet" på en maskine fuld af vhosts.

**Trin 2 — hvilken konfiguration læser den proces.**
En indstilling i en fil, en proces ikke læser, er ikke sat.

```
<fpm-binaeren> -i | grep -m2 -E 'Loaded Configuration File|Scan this dir'
```

**Trin 3 — er ændringen i kraft, eller ligger den bare på disken.**
Det er det tjek der oftest mangler. En `.ini`-fil i en scan-mappe gør **ingenting**, før
hovedprocessen har genlæst konfigurationen efter filen blev skrevet. Og `-i` på binæren
læser disken, ikke den kørende proces — den svarer altså "rettet" på en proces der stadig
kører med den gamle værdi.

Både `reload` og `restart` får hovedprocessen til at genlæse (afsnit 5). Beviset er derfor
ikke hvilken af dem der blev kørt, men hvad puljen **serverer**:

```
# bedst: spørg puljen selv — en request der svarer med ini_get('<indstillingen>')
# næstbedst: kom der en "ready to handle connections" i FPM-loggen efter filen blev skrevet?
grep 'ready to handle connections' /var/log/php<v>-fpm.log | tail -n 1   # tidspunktet skal ligge efter reloaden
# IKKE MainPID: den er den samme før og efter reload (målt DEV og STABLE 2026-11-06)
```

**Brug ikke `ActiveEnterTimestamp` som bevis.** Den opdateres ikke når en unit går
active → reloading → active, så den siger "ikke i kraft" om en ændring der **er** i kraft
efter en `reload`, og sender folk ud i et unødvendigt `restart`. (Læst i systemd's adfærd,
ikke målt på maskinen — afgøres med `systemctl show -p ActiveEnterTimestamp -p MainPID`
før og efter en reload.)

**Trin 4 — kan noget overskrive den.**
En puljekonfiguration slår en global indstilling. Tjek det i stedet for at antage.

```
grep -rn '<indstillingen>' /etc/php/*/fpm/pool.d/
```

**Trin 5 — mål det udefra, hvis påstanden handler om adgang.**
En firewall kan ikke efterprøves fra maskinen selv. Åbne porte måles fra en anden maskine.
Det samme gælder certifikater, DNS og alt andet hvor spørgsmålet er "hvad ser omverdenen".

---

## 3. Hvad der skal stå i et fund

Et fund uden reproduktion er en mening. Fem felter, og de er ikke valgfrie:

```
Sted:          fil og linje, eller kommando og maskine
Hvad der sker: i én sætning, i årsag-virkning-form
Reproduktion:  den kommando eller det testscript der viser det, og hvad det udskriver
Målt:          hvor, hvornår, og hvor mange gange af hvor mange forsøg
Tjekket der burde have fanget det: navnet på kontrollen — findes den ikke, er det at
               bygge den en del af rettelsen
```

Det sidste felt er det der gør holdet uafhængigt af hvem der kigger. Et fund der lukkes
uden at noget kan fange det næste gang, kommer igen.

**Et race skal tælles.** "Det kan ske" er ikke et fund. "5 af 5 forsøg med en
baggrundsproces der sletter filer under `du`" er et fund.

---

## 4. Hvad der ikke må røres under en gennemgang

Disse er absolutte. De er ikke råd.

- **Firewallen må kun læses.** `ufw status`, `iptables -L`, `ss -lntp` — ja. Ingen
  `ufw allow/deny/default/enable/reset`, ingen start eller genstart af `fail2ban`. Et
  script der skriver i firewallen kan lukke mennesket ude af maskinen, og så er der ingen
  vej tilbage uden konsoladgang. Mål, rapportér, og lad mennesket skrive reglen.
- **Firewallen kan ligge uden for maskinen.** Målt 2026-10-21: port 22 er lukket i
  Contabo's egen firewall foran både `llm.xanouso.com` og `dev.xanouso.com`, mens
  maskinens egen `ufw status` fortsat viser `22/tcp ALLOW Anywhere`. Begge udsagn er
  sande. En gennemgang der kun læser `ufw` konkluderer forkert. Mål udefra.
- **Indhold i filer under `/home/<bruger>/` røres aldrig.** Ejerskab og rettigheder er
  serveradministration; indholdet ejer projektet.
- **Intet slettes.** Flyt: `<fil>.bak-<dato>`, vhost til `/etc/sitectl/arkiv/`, hjemmemappe
  til `/home/transferred/`.
- **En hemmelighed der har været i et output eller en chat, er lækket og skal roteres.**
  Derfor: ingen hemmeligheder i en kommandolinje (`/proc/<pid>/cmdline` er verdenslæsbar,
  og alle site-brugere har en shell), ingen i et fund, ingen i en logpost.
- **Findes en hemmelighed i forvejen, flyttes den uændret.** At generere en ny, hvor der
  allerede findes en, er et hårdt stop. Nye kodeord er menneskets valg.

---

## 5. Når en ændring skal laves alligevel

**Rækkefølgen er dev først.** Intet rammer en maskine i drift, som ikke er kørt på dev.
Kan kravet ikke opfyldes, springes det ikke over — det siges højt, så beslutningen om at
tage risikoen er bevidst og menneskets.

**Ingen ændring uden en vej tilbage.** Kan du ikke skrive hvordan den rulles tilbage, er
den ikke klar til at blive lavet.

**Et stop er kun til:** hemmeligheder mennesket skal levere, filer fra en anden maskine,
DNS, valg med konsekvenser, og det irreversible. Alt andet skal scriptet selv kunne —
installere pakken, oprette mappen, finde ud af hvor filerne ligger. En linje mennesket
selv skal klippe sammen er en fejl i scriptet, ikke en service.

**Genstart kontra genindlæsning.** `reload` af PHP-FPM er nok til en ny `.ini` eller en
ændret puljefil. `ExecReload` er `kill -USR2 $MAINPID`: hovedprocessen re-eksekverer sig
selv, genlæser hele konfigurationen og arver soklen. Målt 2026-10-26 gennem en kørende
pulje (`ini_get()` via `cgi-fcgi`, ikke `-i`): værdien skiftede efter USR2, og ingen forbindelser blev tabt. Master-PID skifter **ikke**:
målt på DEV og STABLE 2026-11-06, `reloading: execvp(...)` og derefter `fpm is running, pid` med samme pid.
Påstanden fra 2026-10-26 om at den skiftede, var forkert. Bekræft at maskinen gør det samme med
`systemctl cat php8.X-fpm | grep ExecReload`.

Den tidligere regel her ("reload genstarter kun arbejderne, brug restart") var forkert, og
den kostede unødig nedetid på tværs af sites under PHP2Shell-afværgelsen.

`systemctl restart php8.X-fpm` tager **alle** sites på den version ned samtidigt. Den
bruges kun efter en pakkeopgradering af selve PHP (en ny binær — om `reload` også tager
den, er ikke målt), og det er derfor den ikke er et projekt-svar.

---

## 6. Hvordan et projekt fejlfinder sit eget 500 — uden at det lander hos sysadmin

Det her afsnit findes, fordi hver gang et projekt selv finder ud af hvorfor deres site
giver 500, er det et spørgsmål der ikke behøver en serverbeslutning.

Alle fire logfiler ligger i projektets egen hjemmemappe og kan læses som egen bruger:

```
/home/<bruger>/logs/php-error.log     PHP's egne fejl — start her
/home/<bruger>/logs/error.log         Apaches fejl for dette site
/home/<bruger>/logs/access.log        hvad der blev kaldt, og hvad der blev svaret
/home/<bruger>/logs/fpm-slow.log      hvad der hang, med stakspor
```

Rækkefølgen der løser de fleste:

1. **`tail -50 logs/php-error.log`.** Står der `open_basedir restriction in effect`, er
   det en sti uden for sitets egne mapper. Det er ikke en fejl at rette — det er
   isolationen. Svaret er en bestilling, ikke en omgåelse.
2. **`tail -50 logs/error.log`.** Står der `not allowed here`, bruger `.htaccess` et
   direktiv `AllowOverride` ikke giver på dette site.
3. **`grep ' 500 ' logs/access.log | tail`.** Rammer det én rute eller alle? Alle ruter
   lige efter et nyt site er næsten altid `open_basedir`.
4. **Er det 401 på Bearer- eller Basic-auth, og koden er rigtig:** `CGIPassAuth`. Den er
   sat af `sitectl new`, men mangler i et site der er lavet i hånden.
5. **503 på HTTPS, 200 på HTTP:** FPM-soklen i `-le-ssl.conf` peger forkert.
6. **Virker det slet ikke, og `.php` downloades som tekst:** vhosten mangler handleren.
   Sitet er lavet i hånden.

Det der **ikke** er fejlfinding: at slå `open_basedir`, `AllowOverride`,
`disable_functions` eller rettigheder på hjemmemappen fra, for at få noget til at virke.
De er grunden til at ét hacket site kun er ét site.

---

## 7. Tjeklisten før noget kaldes færdigt

- Er påstanden målt, eller kun gennemgået? Hvis gennemgået: står ordet "ikke kørt" i
  leverancen?
- Kan den kommando der frembragte konklusionen navngives?
- Er ændringen i kraft, eller ligger den bare på disken? (afsnit 2, trin 3)
- Er der en vej tilbage, og er den skrevet ned?
- Handler fundet om adgang udefra? Så er det målt udefra, ikke med `ufw status`.
- Er der et tjek der fanger det næste gang? Hvis nej, er fundet ikke lukket.
- Står der eksplicit hvad der er læst, og hvad der **ikke** er læst? "Jeg har læst det
  hele" kan ikke kontrolleres af modtageren. En liste kan.
