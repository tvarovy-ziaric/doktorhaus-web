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

Strojový kontrakt používa version string `major.minor` s patternom `^[1-9][0-9]*\.[0-9]+$`. Minor increment pokrýva doplnené evidence, meranie alebo textové spresnenie bez novej samostatnej inspection a zásadnej zmeny záveru. Major increment pokrýva follow-up, post-repair, novú diagnostickú etapu alebo materiálnu zmenu záveru. `change_type` a `change_summary` vždy schvaľuje inšpektor; increment sa neurčuje iba automaticky.

## Versioned report package

Report package je manifest, nie renderer output ani jeden gigantický JSON blob. Cieľová fyzická štruktúra je:

```text
reports/<report-id>/<version>/
    inspection.json
    diagnosis.json
    manifest.json
    media/...
```

`report-package.schema.json` validuje `report`, `report_version`, actors a files. File entry má role, relatívny path, SHA-256, content type, voliteľnú veľkosť a explicitnú privacy. Absolútne cesty, `..` a URL sú zakázané. Publikovaná verzia dostane nový immutable adresár; existujúci manifest ani súbory sa neupravujú inplace.

Report version status je draft, in_review, approved, published, superseded alebo withdrawn. Approved/published/superseded verzia musí mať `approved_by` a `approved_at`; published navyše `published_at`. Domain lint overuje, že approver actor má rolu inspector alebo reviewer a timestamp obsahuje timezone.

## Odvodený machine delivery kontrakt

`client-report.schema.json` je od kroku 4B strict allowlist projekcia publikovaného package, nie štvrtý diagnostický source dokument. Server ju deterministicky vytvorí z immutable inspection, diagnosis a manifest metadata až po validnej session. Raw source objects sa nekopírujú cez „all except“ filter.

Projekcia zachová klientsky odborný význam issue scoring rationale, risks, costs, impacts, observations, hypotheses, verifications a recommendations, ale odstraňuje actors, QA, provenance, source/import metadata, raw IDs verzie/reportu, hashe, presnú adresu a storage referencie. Hypothesis `rationale` sa v 4B nevydáva, kým source contract nerozlíši klientsky odborný dôvod od interného pracovného reasoning textu; klient stále vidí statement, mechanism, status a confidence. Evidence sa vydá iba ak je active, `public|client_private` a aktívne relevantné pre viditeľný issue. Presný delivery kontrakt je v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

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

Finančný rámec má dva nezameniteľné zdroje:

1. `issue.cost_estimate` — iba whole defined issue-scope estimate;
2. report-level pricing components — partial verification, material, unit, conditional alebo no-direct-cost scope.

Čiastkový komponent nesmie pôsobiť ako cena odstránenia celého issue. Ak celý issue scope nie je naceniteľný, issue zostáva `not_estimated` aj pri existencii čiastkových cien. Budúci samostatný report-level renderer musí komponenty označiť podľa významu, napríklad bez priameho nákladu, overenia, jednotkové materiály, podmienené práce a zatiaľ nenacenené práce.

Report pricing sa publikuje ako voliteľný samostatný `report_pricing` file role v immutable package konkrétnej report version. Staršie package bez tohto file role zostávajú validné.

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

Blocking schema/domain errors nemožno acknowledge-nuť a obísť. Domain warnings možno po odbornom posúdení štruktúrovane acknowledge-nuť kódom, rationale, actorom a časom. Diagnosis v stave approved musí mať QA status passed a nesmie mať blocking errors.

## Čo report nemá obsahovať

- surový checklist bez syntézy;
- automatický verdikt „dom je dobrý/zlý“ bez kontextu;
- neoverenú príčinu napísanú ako fakt;
- severity zamieňanú za termín opravy;
- jednu presnú cenu bez rozpočtových podkladov;
- skryté contradicting evidence;
- marketingový tlak alebo strašenie;
- verejné odkazy na súkromné fotografie a dokumenty.
- interný tarif DoktorHaus, interný labour/travel costing, obstarávaciu cenu vybavenia, maržu, markup, interné obchodné poznámky alebo súkromné dodávateľské rokovania.

## Renderer boundary pre report-level pricing

Aktuálny `renderCost(issue)` zostáva rendererom whole-issue `issue.cost_estimate`. Nesmie dostať čiastkovú cenu recommendation alebo pricing componentu. Nasledujúci implementation krok má načítať client-safe `pricing` projekciu a vytvoriť samostatný report-level financial renderer; tento foundation kontrakt nemení existujúce issue karty ani ich spätnú kompatibilitu.
