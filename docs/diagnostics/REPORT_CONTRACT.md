# Kontrakt klientského diagnostického reportu

## Účel reportu

Klientsky report je rozhodovací nástroj. Nemá prepisovať SafetyCulture checklist položku po položke. Zoskupuje súvisiace prejavy do menšieho počtu diagnostických issues, vysvetľuje dôkazy a neistotu a ukazuje, čo riešiť hneď, čo overiť, čo naplánovať a čo sledovať.

Tón je vecný, pokojný, zrozumiteľný a technicky presný. Report nestraší, neskrýva neistotu a neprezentuje hypotézu ako potvrdený fakt.

## Povinná identita a verzia

Každý report zobrazuje:

- bezpečné označenie nehnuteľnosti a dátum inspection;
- rozsah obhliadky a dôležité limitations;
- dátum vydania;
- verziu reportu a stav, napríklad `v1.0 – initial`;
- stručný popis zmien oproti predchádzajúcej verzii;
- identitu/rolu schvaľujúceho inšpektora v primeranom rozsahu;
- informáciu, či ide o initial, doplnenie dôkazu/merania, follow-up alebo kontrolu po sanácii.

Verzie:

- `v1.0 initial`: prvý schválený klientsky report;
- `v1.1`: doplnené meranie, dôkaz alebo spresnenie bez zásadnej zmeny charakteru kontroly;
- `v2.0`: významná následná kontrola, kontrola po sanácii alebo podstatná zmena záverov.

Publikovaná verzia je nemenná. Oprava alebo doplnenie vytvára novú verziu s change summary. Staršia verzia sa môže označiť ako superseded alebo withdrawn, ale auditná stopa zostáva.

## Povinné sekcie

### 1. Executive summary

Krátky súhrn stavu pre rozhodovanie:

- účel a rozsah inspection;
- počet a charakter hlavných issues, nie počet flagged checklist položiek;
- celkový obraz bez neodôvodneného „skóre domu“;
- najdôležitejšie neistoty a obmedzenia;
- pokojné vysvetlenie, čo stav prakticky znamená.

### 2. Čo riešiť ako prvé

Zoradený zoznam prvých krokov podľa priority a dependencies. Každý krok uvádza typ recommendation, dôvod, časový rámec a či ide o ochranu, verification, repair alebo monitoring.

P1/P2 položky musia byť viditeľné bez čítania celého reportu. Vysoká priorita verification sa nesmie vizuálne zameniť za potvrdenú potrebu konkrétnej sanácie.

### 3. Finančný rámec

Zobrazuje:

- min–expected–max alebo zrozumiteľný interval;
- confidence, scope, hlavné assumptions a exclusions;
- oddelene náklady okamžitých krokov, verification, prioritných opráv a plánovaných prác;
- podmienené varianty bez automatického sčítania;
- cost escalation risk a kroky, ktoré môžu drahšiemu poškodeniu predísť;
- dátum/cenovú bázu a upozornenie, že nejde o ponuku ani rozpočet.

Ak nie je poctivý odhad možný, report vysvetlí prečo a čo treba zistiť.

### 4. Diagnostic issues

Každý issue má konzistentnú kartu alebo kapitolu:

- id, title, category, affected area a summary;
- oddelené observations a odbornú interpretáciu;
- hypotheses so statusom a confidence;
- severity, likelihood, urgency, priority a deterioration rate s krátkym rationale;
- short-term a long-term risk level + description;
- sedem impact dimensions;
- cost range, confidence a cost escalation risk;
- status issue;
- významné missing information a limitations;
- súvisiace recommendations a dependencies.

Interné technické polia sa nemusia zobraziť doslova, ale ich význam sa nesmie stratiť. Klient musí rozlíšiť pozorovaný stav od možnej príčiny.

### 5. Evidence

Report uvádza reprezentatívne fotografie, merania, dokumenty a ďalšie evidence s popisom, dátumom/zdrojom a väzbou na observation alebo issue. Evidencia má podporovať rozhodnutie, nie zahltiť report galériou bez kontextu.

Pri hypotézach sa podľa významu uvedie, čo ich podporuje a čo im odporuje. Súkromné médiá sa zobrazia iba autorizovanému klientovi.

### 6. Krátkodobé a dlhodobé riziká

Súhrn naprieč issues rozlíši:

- čo môže nastať do najbližšieho odporúčaného kroku alebo približne do 12 mesiacov;
- čo môže nastať v dlhšom horizonte pri odklade alebo nevhodnom zásahu;
- podmienky, pri ktorých sa má klient ozvať skôr;
- opatrenia, ktoré dočasne znižujú expozíciu.

Level bez scenára a časového kontextu nestačí.

### 7. Recommendations

Odporúčania sú samostatné objekty typov IMMEDIATE, VERIFY, REPAIR, MONITOR, MAINTENANCE a DOCUMENT. Report pri každom uvedie:

- konkrétnu akciu a dôvod;
- issue/issues, ktoré rieši;
- cieľový čas;
- vhodnú profesiu alebo špecialistu;
- čo musí predchádzať a čo môže nasledovať;
- ako overiť výsledok;
- či je zásah podmienený výsledkom verification.

### 8. Poradie sanácie

Report zobrazí vykonateľné poradie podľa dependencies, napríklad:

`odvedenie dažďovej vody → monitoring vlhkosti → diagnostika aktivity pohybu → prípadná stabilizácia → finálne omietky`

Paralelné kroky sa môžu zobraziť paralelne. Konflikt alebo cyklus dependencies musí validation zablokovať. Finálne povrchové práce sa nemajú odporučiť pred riešením aktívnej príčiny bez výslovného upozornenia.

### 9. Čo nebolo overené

Jedna viditeľná sekcia zoskupí:

- neprístupné časti;
- nevykonané merania, sondy alebo skúšky;
- chýbajúcu dokumentáciu;
- otvorené hypotézy a contradicting evidence;
- obmedzenia podmienok obhliadky;
- otázky, ktoré môžu zmeniť náklady alebo odporúčania.

Sekcia nepoužíva neoverenie ako dôkaz bezchybnosti ani poruchy.

### 10. Externí špecialisti

Zoznam odporúčaných odborných preverení uvádza profesiu, presnú otázku, naliehavosť, potrebné podklady a naviazaný issue. Report jasne odlíši odporúčanie od už vykonaného posúdenia.

### 11. Rozsah a disclaimer

Report uvedie, že technická inšpekcia DoktorHaus podľa svojho objednaného rozsahu nie je automaticky znalecký posudok, statický posudok, revízna správa, projektová dokumentácia ani laboratórne vyšetrenie. Formulácia má byť konkrétna k vykonaným metódam a nemá pôsobiť ako všeobecné zbavenie sa zodpovednosti.

## Publikačné minimum

Report version sa nesmie označiť `approved` alebo `published`, ak:

- chýba executive summary, limitations alebo sekcia neoverených oblastí;
- významný issue nemá observation/evidence pôvod;
- hypotéza je prezentovaná ako potvrdená bez splnenia diagnostických pravidiel;
- S4/S5, critical risk alebo high/critical safety impact nemá primeraný ďalší krok;
- recommendation dependencies sú neplatné alebo cyklické;
- náklad nemá interval/scope/confidence alebo používa falošnú presnosť;
- report neobsahuje verziu, approver a čas schválenia;
- obsahuje client-private URL, ktoré obchádza autorizáciu.

## Čo report nemá obsahovať

- surový checklist bez syntézy;
- automatický verdikt „dom je dobrý/zlý“ bez kontextu;
- neoverenú príčinu napísanú ako fakt;
- severity zamieňanú za termín opravy;
- jednu presnú cenu bez rozpočtových podkladov;
- skryté contradicting evidence;
- marketingový tlak alebo strašenie;
- verejné odkazy na súkromné fotografie a dokumenty.
