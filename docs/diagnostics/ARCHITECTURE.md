# Architektúra diagnostickej vrstvy

## Účel

Cieľom je rozšíriť existujúci web DoktorHaus o diagnostickú vrstvu bez predčasnej zmeny technologického smeru. Vrstva má premeniť terénne pozorovania a dôkazy na kontrolovateľné diagnostické problémy, hypotézy, overenia, odporúčania a verziovaný klientsky report.

## Súčasná relevantná architektúra podľa repozitára

### Verejný a klientsky frontend

- Web tvoria samostatné HTML stránky, globálny `styles/style.css` a inline vanilla JavaScript.
- `JSS/header.html` a `JSS/footer.html` sa do stránok vkladajú klientskym `fetch()`; navigácia už obsahuje verejnú položku `Inšpekcie` a samostatnú obsahovú vetvu `Diagnostika`.
- `inspekcie.html` obsahuje verejný odkaz na statickú vzorovú stránku a formulár pre šesťmiestny klientsky PIN. Po úspešnom POSTe vykreslí v tej istej stránke názov, opis, odkazy na médiá a fotogalériu.
- `inspekcia-vzor.html` je samostatná, napevno napísaná verejná ukážka. Obsahuje PDF v `uploads/inspekcie/vzor/`, externý Google Docs odkaz, Panoraven, YouTube a ukážkové SVG fotografie. Nie je generovaná z diagnostického dátového modelu.
- Všetky uvedené stránky používajú existujúci vizuálny jazyk a triedy z globálneho CSS.

### Backoffice

- `backoffice.html` je rozcestník interných modulov. PIN overuje cez `api/backoffice.php`, ale úspešný stav rozcestníka drží iba ako príznak v `sessionStorage`.
- `inspekcie-admin.html` je samostatný vanilla JS formulár. Pri každej operácii posiela Admin PIN do `api/inspections.php`.
- Formulár spravuje základné metadáta, email, priame URL dokumentov a médií a ručne vložené JSON pole fotografií.
- Aktuálne stavy sú `draft`, `ready` a `sent`. Akcia `mark-ready` je dnešná manuálna publikačná brána: nastaví `ready` a prvýkrát vygeneruje PIN. `send-email` používa PHP `mail()` a nastaví `sent`; `set-draft` vráti záznam do konceptu.

### PHP a runtime dáta

- `api/inspections.php` je jeden POST endpoint prepínaný poľom `action`: `unlock`, `admin-list`, `save`, `mark-ready`, `send-email`, `set-draft`.
- Konfigurácia sa číta z environment premenných alebo z lokálneho `api/inspections.config.php`; Admin PIN môže spätne použiť aj `PUBLIC_HELP_PIN`.
- Dáta sa ukladajú ako jedno JSON pole v runtime súbore `data/inspections.json`. Zápis používa `LOCK_EX`; produkčná databáza neexistuje.
- Klientsky `unlock` prehľadá záznamy podľa PINu a vráti záznam iba v stave `ready` alebo `sent`.
- `api/.htaccess` blokuje priame načítanie konfiguračných PHP súborov. `data/.htaccess` blokuje celý adresár. `uploads/.htaccess` blokuje vykonanie PHP, ale nebráni priamemu načítaniu obrázkov a dokumentov.
- Izolovaný modul `api/lib/diagnostics/` od kroku 3 poskytuje filesystem storage pre diagnostické drafty a immutable report packages. Nie je napojený na legacy endpoint ani na HTTP route a produkčný root vyžaduje mimo webrootu.

### Nasadenie

- `.github/workflows/deploy-ftp.yml` je ručne spúšťaný GitHub Actions workflow. Checkoutnutý strom zrkadlí cez `lftp` na FTP/FTPS hosting.
- Workflow explicitne chráni runtime dáta a uploady modulu Pomoc verejnosti a od kroku 3 aj `api/inspections.config.php`, `api/diagnostics.config.php` a `data/inspections.json`.
- `.gitignore` ignoruje oba lokálne inspections/diagnostics configy aj legacy runtime JSON. Diagnostický storage root nemá byť súčasťou checkoutu ani FTP mirroru.

## Cieľová logická architektúra

Technologicky zostáva vhodný jednoduchý HTML/CSS/vanilla JS frontend a PHP backend. Nasledujúce sú logické hranice, nie požiadavka na framework alebo samostatné služby.

### 1. Vrstva zdrojových dát

Vlastní pôvodný SafetyCulture export alebo budúcu API/webhook odpoveď, prílohy a importné metadáta. Uchováva zdrojový identifikátor, čas importu a pôvod hodnoty. Zdrojové dáta sa pri diagnostickej úprave neprepisujú.

### 2. Normalizačná vrstva

Prevedie zdroj do kanonických objektov `property`, `inspection`, `observation` a `evidence`. Zachová väzbu späť na zdroj. Nevykonáva diagnózu a nemení checklistové položky na potvrdené príčiny.

### 3. Diagnostická doménová vrstva

Spravuje `diagnostic_issue`, `hypothesis`, `verification`, `impact`, `recommendation` a väzby medzi nimi. Tu platia pravidlá oddelenia pozorovania, interpretácie, hypotézy a potvrdeného zistenia. Skóre a náklady sa ukladajú s odôvodnením, nie iba ako čísla.

### 4. Validácia a QA

Kontroluje povinné polia, dovolené hodnoty, referenčnú integritu, interval nákladov, neúplné hypotézy, chýbajúce dôkazy, konflikt skóre a publikačné podmienky. Strojová validácia nenahrádza odborné schválenie inšpektorom.

Od kroku 2 je štruktúrny kontrakt vyjadrený cez JSON Schema Draft 2020-12 v `docs/diagnostics/schemas/`. Python stdlib nástroj `tools/diagnostics_lint.py` dopĺňa cross-file a doménové invarianty. Nie je vlastným všeobecným schema enginom a nie je produkčnou dependency.

### 5. Reportová vrstva

Z vybranej, schválenej verzie zostaví klientsky rozhodovací pohľad. Report je projekcia diagnostických dát, nie druhé miesto, kde sa ručne vytvára diagnóza. Publikovaná verzia je nemenný snapshot; doplnenie merania vytvorí novú verziu.

Nemennosť pripravuje versioned manifest: `reports/<report-id>/<version>/manifest.json` referencuje samostatný `inspection.json`, `diagnosis.json` a médiá relatívnymi cestami a SHA-256. Manifest nie je gigantický blob ani renderer output.

Krok 3 túto fyzickú hranicu implementuje cez staging pod rovnakým storage rootom, kontrolované streamujúce kopírovanie, opakovanú hash verifikáciu a atomický rename na dovtedy neexistujúcu verziu. Publikovaný adresár nemá overwrite API. Podrobnosti a prevádzkové predpoklady sú v [RUNTIME_STORAGE.md](RUNTIME_STORAGE.md).

### 6. Autorizačná a publikačná vrstva

Server overuje interného používateľa aj klienta, vytvára autorizovanú session a až po `APPROVE` sprístupní konkrétnu verziu reportu. Súkromné dáta a médiá sa vydávajú cez autorizovaný serverový tok, nie cez verejné predvídateľné URL.

### 7. Prezentačná vrstva

Existujúci klientsky vstup `inspekcie.html`, vzor, backoffice a globálny vizuálny jazyk zostávajú zachované. Krok 5A pridáva oddelenú súkromnú route `inspekcia.html?access=acc_…`. Jej lifecycle smie komunikovať iba s auth/report endpointmi a jej renderer smie čítať iba publikovaný `client_report`; diagnózu ani nové súhrny v prehliadači neodvodzuje. Médiá prijme iba z presného same-origin delivery endpointu.

## Hranice a smer toku

- SafetyCulture je evidencia terénneho zberu, nie autorita pre výslednú diagnózu.
- Normalizácia môže čistiť formát, ale nesmie dopĺňať nevyslovené závery.
- Diagnostická vrstva môže zoskupiť viac pozorovaní do jedného problému; pôvodné pozorovania zostávajú dohľadateľné.
- Validátor môže zablokovať publikovanie, ale nemôže sám udeliť odborné `APPROVE`.
- Report nesmie spätne meniť doménové dáta. Zmena záveru vzniká v diagnostike a vedie k novej verzii reportu.
- Autorizácia sa uplatňuje na dáta aj binárne médiá. Skrytie odkazu v HTML nie je ochrana.

## Čo sa zachová

- jednoduchý HTML/CSS/vanilla JS frontend;
- PHP pre serverové operácie;
- spoločný header, footer, globálne CSS a vizuálny jazyk DoktorHaus;
- `inspekcie.html` ako klientsky vstup a existujúci backoffice ako miesto interného workflow;
- manuálne odborné schválenie pred publikovaním;
- GitHub ako zdroj kódu a FTP workflow, pokiaľ hosting zostane rovnaký.

## Čo sa neskôr zmení

- plochý runtime záznam inšpekcie sa oddelí na zdrojové, diagnostické a reportové objekty;
- otvorený klientsky PIN sa nahradí hashom a serverovou session;
- `ready` sa spresní na auditovateľný `APPROVE` viazaný na konkrétnu verziu;
- priame súkromné URL sa nahradia autorizovaným doručením;
- pribudne validácia referencií, stavov a publikačného kontraktu;
- report získa explicitné verzie a históriu;
- deploy výnimky a práca s runtime dátami sa zosúladia s bezpečnostným modelom;
- budúci import môže nahradiť manuálny PDF tok SafetyCulture API/webhookom bez zmeny diagnostického modelu.

## Čo krok 2 explicitne nezavádza

- React, Vue, Next, Laravel ani Node backend;
- produkčnú databázu alebo migrácie;
- nový renderer klientského reportu;
- autentifikáciu, session, rate limiting alebo privátny media endpoint;
- upload súborov;
- SafetyCulture API, webhook alebo automatickú diagnostiku;
- zmenu existujúceho HTML, PHP, CSS, JS alebo deploy workflow.

## Čo zavádza krok 3

- explicitne konfigurovaný diagnostický storage root bez fallbacku pod webroot;
- atomické draft JSON zápisy s lockom a optimistic `storage_revision`;
- runtime sanity kontrolu ID a document type bez predstierania plnej schema validácie;
- strict manifest paths, zákaz symlinkov a streaming SHA-256/size verifikáciu;
- staging a immutable inštaláciu iba `published` report version;
- interné read/resolve metódy bez HTTP sprístupnenia;
- ignore, Apache a FTP hardening pre lokálne configy a legacy runtime dáta.

Krok 3 sám nezaviedol auth, PIN, session, role, rate limiting, CSRF, audit, renderer ani autorizované media delivery. Existujúci `api/inspections.php`, `inspekcie.html` a `inspekcie-admin.html` zostali nezmenené.

## Čo zavádza krok 4A

- filesystem access grant s opaque `acc_…` handle, ktorý je viazaný na presný report ID, verziu, version ID a SHA-256 manifestu;
- šesťmiestny PIN uložený iba ako peppered password hash;
- dve perzistentné vrstvy rate limitingu založené iba na `REMOTE_ADDR`;
- serverovú PHP session s idle/absolute timeoutom, CSRF tokenom a generation invalidáciou;
- rotáciu PINu, idempotentnú revokáciu a bezpečnostný JSONL audit;
- úzky HTTPS endpoint `api/diagnostics-auth.php` iba pre unlock, status a logout.

Krok 4A je autorizačné jadro, nie hotový klientsky portál. Nevracia reportové dáta ani súbory a nepridáva klientskú projekciu, field-level auth, renderer, media/PDF streaming, `Range`, `Content-Disposition`, cache politiku, backoffice vydávanie grantov, SafetyCulture integráciu alebo legacy migráciu. Podrobný kontrakt je v [CLIENT_ACCESS_SECURITY.md](CLIENT_ACCESS_SECURITY.md).

## Čo zavádza krok 4B

- derived `client_report` schema a realistický anonymizovaný fixture;
- `DiagnosticsClientProjection`, ktorý explicitne skladá client-safe polia a fail-closed kontroluje identity, sedem impacts, relation/dependency konzistenciu a cycle;
- privacy + active-link evidence filter a serverový `clientVisibleEvidenceIds` set;
- session-bound `GET api/diagnostics-report.php` bez report/version selectorov;
- session-bound `GET|HEAD api/diagnostics-media.php` iba s opaque evidence ID;
- manifest role kontrolu, safe inline MIME allowlist, generated filename, single byte ranges a chunk streaming;
- audit events `report_viewed` a `media_accessed` s fail-closed delivery politikou;
- uvoľnenie PHP session locku pred media streamom a reuse package snapshotu úplne overeného v session bindingu.

Raw `inspection.json`, `diagnosis.json` a `manifest.json` nie sú client API. 4B nemení ich diagnostický význam ani existujúce schemas. `client_report` je jednosmerná projekcia a nesmie spätne meniť source objects alebo vytvárať nové odborné závery. Podrobný kontrakt je v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

Samotný krok 4B ešte nebol klientsky portál: renderer, finálny UX, `inspekcie.html`, backoffice issuance, SafetyCulture adapter, produkčný report a legacy tok zostali nezmenené.

## Čo zavádza krok 5A

- izolovaný klientsky shell `inspekcia.html` bez zmeny legacy vstupu a verejného vzoru;
- oddelený vanilla JS lifecycle pre session, PIN, report, expiry, retry a CSRF logout;
- DOM-only renderer client-safe reportu s centrálnymi slovenskými mapovaniami;
- strict same-origin media URL validáciu, lazy dôkazy a prístupnú fotogalériu;
- mobilný a print layout bez externých assetov, trackingu či klientského úložiska;
- Node bezpečnostné kontrakty a HTTP smoke integrovaný do diagnostics CI.

Krok 5A nemení source schemas, projekciu, auth/media endpointy, grant issuance, backoffice, SafetyCulture, legacy migráciu, databázu ani PDF generátor. Kompletná hranica je v [CLIENT_RENDERER.md](CLIENT_RENDERER.md).

## Rozhodnutia uzavreté kontraktom 1.0.0

- stabilné prefixované ID s 16–32 lowercase hex znakmi a oddeleným `display_code`;
- `inspection.json` ako normalizovaný faktický dokument a `diagnosis.json` ako diagnostický dokument;
- explicitné auditovateľné link objects pre many-to-many väzby;
- impact objects ako jediný source of truth pre sedem povinných dimensions;
- jednotné confidence a risk enumy, kontrolované issue kategórie, areas, specialty a lifecycle statusy;
- versioned report-package manifest s relatívnymi cestami a SHA-256;
- schema/domain error verzus domain warning a stabilné lint kódy;
- provenance a SafetyCulture idempotency invariant bez databázovej unikátnosti.

## Zostávajúce architektonické rozhodnutia

1. Ako dlho MVP runtime zostane na oddelených JSON súboroch a kedy bude odôvodnená databáza.
2. Produkčný filesystem/object storage, zálohy, restore test, retencia a overenie atomic rename/flock semantics hostingu.
3. Konkrétna interná identita, role a auditné úložisko pre `APPROVE`.
4. Cieľové produkčné úložisko a škálovanie integrity verifikácie veľkých médií; základné autorizované PHP delivery je od 4B implementované.
5. Produkčná prevádzková politika retencie session/auditu, rotácie secrets a distribuovaného rate limitingu pri horizontálnom škálovaní.
6. Konkrétny SafetyCulture adapter, webhook idempotency a riešenie konfliktu re-importu s ručnými úpravami.
7. Voľba plného Draft 2020-12 validátora a jeho verziové uzamknutie v budúcom CI.
