# Bezpečnostný model

## Rozsah dokumentu

Tento dokument opisuje legacy prototyp, bezpečnostné jadro kroku 4A, delivery hranicu kroku 4B, prezentačnú hranicu kroku 5A a zostávajúci cieľový model. Krok 4A implementuje izolované granty, PIN hashing, rate limiting, serverovú session, CSRF, revokáciu a audit. Krok 4B chráni client-safe report a médiá; krok 5A ich zobrazuje bez vytvorenia novej autorizačnej cesty.

Bezpečnostná zásada: žiadne client-private diagnostické dáta, fotografie ani dokumenty nesmú byť dostupné bez platnej serverom overenej autorizácie. Nezverejnený odkaz, `hidden` element alebo neuhádnuteľný názov súboru nie sú autorizácia.

## Súčasný stav podľa kódu

### Klientsky prístup

- `inspekcie.html` posiela šesťmiestny PIN v JSON tele na `api/inspections.php` s akciou `unlock`.
- `api/inspections.php` najprv porovnáva PIN priamo s hodnotou uloženou v `data/inspections.json`. PIN je v tomto legacy zázname stále uložený v otvorenom tvare.
- Záznam bez `diagnosticsAccessId` po úspechu naďalej nevytvára serverovú session a v jednej odpovedi vráti legacy metadáta a priame URL médií.
- Záznam s platným server-side `diagnosticsAccessId` nevracia legacy obsah. Rovnaký zadaný PIN musí úspešne prejsť `DiagnosticsAccessService::verifyPin()`, potom `DiagnosticsClientSession::establish()` vytvorí štandardnú diagnostics session a klient dostane iba allowlisted redirect na `inspekcia.html?access=acc_…`.
- PINy sa generujú cez `random_int()` a kontroluje sa jedinečnosť v aktuálnom JSON súbore.
- Legacy záznam bez bindingu nemá limit pokusov, expiráciu, revokáciu ani access audit. Linked tok preberá rate limiting, expiráciu, revokáciu, generation kontrolu a audit existujúceho diagnostics grantu.

Bridge nemigruje legacy PIN, nevytvára grant a z `ready`/`sent` nerobí diagnostics publish stav. Binding je použiteľný iba na už existujúci publikovaný package a grant s rovnakým PINom; grant zostáva autoritatívny.

### Izolované diagnostické jadro kroku 4A

- `api/diagnostics-auth.php` vracia iba minimálny stav autentizácie; žiadne inspection, diagnosis, reporty, paths, PDF ani médiá.
- Access grant je viazaný na konkrétnu immutable publikovanú verziu a hash manifestu. Opaque access ID nie je tajomstvo; tajomstvom je PIN a server-side session cookie.
- PIN sa HMAC-prehashuje povinným pepperom a ukladá cez `password_hash`. Unknown ID používa dummy verify a má rovnakú 401 odpoveď ako wrong/revoked/expired prístup.
- Perzistentný access+IP a globálny IP limiter sa vyhodnotí pred password verify. Zdrojom adresy je iba `REMOTE_ADDR`.
- Session používa Secure/HttpOnly/SameSite Strict cookie, regeneráciu ID, idle/absolute timeout, CSRF a generation kontrolu pri rotácii alebo revokácii.
- Audit používa pseudonymizované request fingerprints a nikdy neukladá PIN, pin hash, raw IP, session ID/cookie, celý user agent ani klientsky diagnostický obsah.

Detailný model, HTTP kontrakt a prevádzkové hranice sú v [CLIENT_ACCESS_SECURITY.md](CLIENT_ACCESS_SECURITY.md).

### Interný prístup

- `backoffice.html` overí spoločný `PUBLIC_HELP_PIN` cez `api/backoffice.php`. Po úspechu zapíše iba príznak `doktorhausBackofficeUnlocked=1` do browser `sessionStorage` a odkryje odkazy na moduly.
- Tento príznak nie je serverová autorizácia a možno ho lokálne nastaviť. Samotný `api/inspections.php` našťastie pri každej admin akcii znovu vyžaduje Admin PIN v request body.
- `api/inspections.php` používa `INSPECTIONS_ADMIN_PIN`, fallback na `PUBLIC_HELP_PIN` alebo lokálny config. Hodnota sa porovnáva priamo, bez hashovania a bez serverovej session.
- Akcia `admin-list` vracia celé záznamy vrátane emailov a otvorených klientskych PINov oprávnenému requestu.
- Neexistujú používateľské identity, role, granular permissions ani audit toho, kto schválil alebo zmenil záznam.

### Dáta a médiá

- `data/inspections.json` je chránený pravidlom `Require all denied` v `data/.htaccess`. Ochrana závisí od Apache konfigurácie, ktorá musí `.htaccess` rešpektovať.
- `uploads/.htaccess` blokuje vykonanie PHP súborov, ale statické fotografie a dokumenty pod `uploads/` sú dostupné priamou URL. PIN chráni získanie odkazu, nie samotný súbor.
- Verejná vzorová inšpekcia a jej médiá sú zámerne verejné; nesmú sa miešať s klientskymi dátami.
- Existujúce URL môžu smerovať aj na Google Docs, Panoraven a YouTube. Ochrana externého obsahu závisí od nastavení danej služby, nie od PINu DoktorHaus.

### Konfigurácia a deploy

- `api/.htaccess` blokuje priame načítanie lokálnych config súborov.
- `.gitignore` od kroku 3 ignoruje `data/inspections.json`, `api/inspections.config.php` aj `api/diagnostics.config.php`.
- FTP workflow od kroku 3 explicitne vylučuje oba lokálne configy a legacy `data/inspections.json`. Nový diagnostický runtime root musí byť mimo checkoutu a webrootu, takže ho mirror nespravuje.
- Endpointy nastavujú JSON content type a `X-Content-Type-Options: nosniff`; komplexnejšia politika security headers nie je v tomto module definovaná.

## Identifikované legacy riziká a zostávajúce medzery

1. Legacy šesťmiestny PIN má nízku entropiu. Neznáme PINy, ktoré nenájdu žiadny legacy záznam, stále neprechádzajú diagnostics limiterom; pri linked zázname však každý pokus, ktorý sa dostane k diagnostics grantu, podlieha jeho access+IP a globálnemu IP limitu.
2. Únik `data/inspections.json`, admin odpovede alebo zálohy odhalí otvorené klientské PINy.
3. Priama URL súkromného média zostáva použiteľná bez PINu a môže sa dostať do histórie, logov, referrerov alebo preposlania.
4. `sessionStorage` chráni iba zobrazenie rozcestníka, nie serverový zdroj.
5. Zdieľaný globálny Admin PIN neposkytuje identitu, role, odvolanie konkrétneho prístupu ani audit approvera.
6. Legacy prístup nemá expiráciu/revokáciu. Linked diagnostics tok rešpektuje expiráciu, revokáciu, generation a package binding grantu, ale jeho vytvorenie ani zrušenie stále nie je automaticky prepojené na legacy `ready`/`sent` alebo budúci withdraw/publish workflow.
7. `ready` sprístupňuje dáta, ale nie je viazané na nemennú, auditovanú report version.
8. Produkčné umiestnenie, backup, restore a retencia nového storage rootu ešte nie sú overené na cieľovom hostingu.
9. Ochrana `data/` a configs závisí od webservera; pri migrácii mimo Apache môže prestať platiť.
10. Externé médiá môžu mať verejné alebo širšie oprávnenia než klientsky report.
11. Krok 4A audituje grant a auth/session udalosti, stále však chýba audit interných zmien, schválenia, publikovania a budúceho stiahnutia obsahu.
12. Chýba politika minimalizácie osobných údajov, retencie, záloh a bezpečného vymazania.

## Cieľová hranica verejné vs. súkromné

### Verejné

- marketingové a odborné HTML stránky;
- zámerne anonymizovaná vzorová inšpekcia;
- globálne CSS, JS fragmenty, ikony a verejné assety;
- všeobecné informácie o rozsahu služby.

### Client-private

- identity a kontaktné údaje klienta;
- presná identifikácia nehnuteľnosti, ak nie je určená na zverejnenie;
- inspection, observations, evidence a diagnostic data;
- všetky draft/in-review/approved report versions, pričom klient smie vidieť iba explicitne published verziu;
- fotografie, videá, dokumenty, merania a exporty;
- PIN hashe, sessions, access logy a auditné metadáta.

Interný backoffice je neverejná aplikačná plocha, hoci jeho HTML môže byť technicky stiahnuteľné. Všetky citlivé operácie a dáta musí chrániť server.

## Cieľový klientsky PIN a session model

1. PIN sa vygeneruje kryptograficky bezpečne až pre schválenú a publikovanú report version.
2. Server uloží iba silný password hash PINu s individuálnou soľou pomocou aktuálne odporúčaného PHP password API; otvorený PIN sa zobrazí/odošle iba pri vydaní alebo vedomej regenerácii.
3. PIN sa overuje serverovo. Chybová odpoveď neprezrádza, či report alebo PIN existuje.
4. Pokusy sa limitujú minimálne podľa kombinácie IP, report/access identifikátora a časového okna. Použije sa progresívne oneskorenie alebo dočasná blokácia a bezpečnostný audit.
5. Po úspechu server vytvorí náhodnú, rotovanú session viazanú iba na povolený report/report version.
6. Session identifikátor je v cookie `Secure`, `HttpOnly` a primerane `SameSite`; neukladá sa do local/sessionStorage.
7. Session má krátku neaktívnu a absolútnu expiráciu, možnosť revokácie a invalidáciu pri zrušení publikácie alebo regenerácii prístupu.
8. Každé načítanie JSON, dokumentu alebo média overí session a autorizáciu pre konkrétny objekt.

Šesťmiestny PIN môže zostať používateľsky jednoduchý iba spolu s rate limitingom, monitoringom, expiráciou a serverovou session. Krok 4A určuje bezpečné necitlivé defaulty; ich produkčné ladenie podľa monitoringu zostáva prevádzkovým rozhodnutím.

## Cieľový interný prístup

- Interné prihlásenie vytvára serverovú session; client-side flag môže slúžiť iba na UX.
- Oprávnenia odlišujú minimálne editáciu draftu, odborné QA, `APPROVE`, publish/withdraw a správu prístupov.
- `APPROVE` zaznamená identitu, čas, report version a hash/snapshot schváleného obsahu.
- Kritické akcie vyžadujú aktuálnu autorizáciu a ochranu pred CSRF podľa zvoleného session modelu.
- Spoločný PIN je prototyp. Cieľom je individuálna identita alebo iný auditovateľný mechanizmus; konkrétny provider sa teraz neurčuje.
- Secrets sa ukladajú v hosting environment/secrets alebo mimo deployovaného web rootu, nie v repozitári.

## Ochrana privátnych médií

Preferovaný model:

- súbory sú mimo verejného web rootu alebo v privátnom object storage;
- aplikácia udržiava interné media ID a access policy, nie verejnú URL ako autoritu;
- PHP endpoint po autorizácii streamuje súbor alebo vydá krátko platný, scope-limited signed URL;
- odpoveď používa primerané cache a download headers; citlivé médiá sa verejne necacheujú;
- thumbnail podlieha rovnakej autorizácii ako originál;
- logy a chybové správy neobsahujú PIN, session token ani citlivú URL;
- externá služba sa použije iba s nastavením prístupu zodpovedajúcim client-private klasifikácii.

Kontrakt 1.0.0 pripravuje túto hranicu bez implementácie auth: každé evidence a manifest file má explicitnú privacy `public`, `client_private` alebo `internal`; `media_reference` je interná referencia, nie požiadavka na verejnú URL; manifest path musí byť relatívny bez `..`, absolútnej cesty alebo URL. Domain lint blokuje http(s) reference pri client_private/internal evidence kódom `E_PRIVATE_PUBLIC_URL`.

## Storage hardening od kroku 3

`api/lib/diagnostics/` ukladá diagnostické dáta do explicitného absolútneho rootu a pri známom `DOCUMENT_ROOT` odmietne root vo webroote. Draft zápisy používajú lock, temporary file na rovnakom filesystéme, flush/fsync podľa platformy, re-read a rename. Publikovaný package vznikne iba cez nový staging adresár, kontrolovaný copy, druhú hash verifikáciu a atomický rename bez overwrite.

Manifest paths sa nenormalizujú z nebezpečného na bezpečný tvar. Vrstva odmieta traversal, absolute/drive/UNC/URL paths, backslash, NUL, prázdne segmenty, symlink súbory a symlink adresáre. Deklarovaný obsah kontroluje streamujúcim SHA-256 a voliteľnou veľkosťou; neočakávaný regular file blokuje inštaláciu.

Tieto vlastnosti samotnej storage vrstvy chránia filesystem integritu, nie prístup klienta. Krok 4A nad ňou vytvára grant/session/audit modul. Krok 4B pridáva HTTP report/media delivery, ale oprávnenie overuje pred každým read/resolve a klientovi nikdy nevracia filesystem cestu. Session binding vykoná úplnú package verifikáciu; rovnaký request potom spotrebuje tento overený snapshot bez druhého hashovania veľkého média. Reziduálna TOCTOU hranica predpokladá privátny storage root vo výhradnom vlastníctve aplikačného používateľa; podrobnosti sú v [RUNTIME_STORAGE.md](RUNTIME_STORAGE.md) a [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

## Client-safe delivery od kroku 4B

- Authenticated session neotvára raw inspection, diagnosis ani manifest. `client_report` je strict allowlist a rekurzívny denylist test blokuje actor, provenance, source, QA, credential, package a path/hash polia.
- Report/version patria výhradne session. Report endpoint odmieta query selectors a media endpoint prijíma iba evidence ID, čím blokuje BOLA cez report, version alebo path override.
- Evidence musí byť active, mať privacy `public|client_private` a aktívnu väzbu na client-visible issue alebo jeho active observation. Internal, withdrawn a orphan evidence sa správajú ako neexistujúce.
- Media resolver povoľuje iba manifest role `media|attachment`. Source report a raw data role nie sú client-deliverable.
- Active MIME (`html`, JavaScript, SVG, XHTML/XML) a unknown typy sa nútene sťahujú ako `application/octet-stream`; inline je uzavretý image/video/PDF/audio allowlist.
- Report aj media sú `no-store, private`, same-origin, bez wildcard CORS. Media podporuje iba jeden validný byte range a uvoľní session lock pred body.
- Security audit musí uspieť pred doručením. Udalosti neobsahujú raw IP, session, PIN, filesystem path, filename ani `media_reference`.

Validný, ale neprístupný evidence ID má vždy generic 404 bez potvrdenia jeho existencie alebo privacy. Podrobný algoritmus, hlavičky a reziduálne performance riziko sú v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

## Schválenie, verzia a audit

- Draft ani in-review dáta nie sú klientovi dostupné.
- `APPROVE` sa viaže na konkrétny nemenný report version; publish je samostatná auditovaná akcia.
- Každá zmena publikovaného záveru vytvára novú verziu. Starý access sa podľa politiky presmeruje na aktuálnu verziu alebo zostane viazaný na pôvodnú; rozhodnutie musí byť explicitné.
- Audit obsahuje minimálne actor, action, object/version, timestamp, outcome a bezpečné technické metadáta. Neobsahuje otvorené PINy ani plný citlivý obsah.
- Auditujú sa neúspešné prihlásenia, lockouty, zmeny, QA, approve, publish, withdraw, vydanie/revokácia prístupu a prístup k citlivým médiám podľa primeranej retencie.

## Prevádzkové požiadavky pred produkciou

- zosúladiť `.gitignore` a FTP exclusions pre všetky secret, runtime a private-media súbory;
- overiť webserverové pravidlá v reálnom hostingu, nie iba existenciu `.htaccess` v repo;
- zaviesť zálohy, obnovu, retenciu a test obnovy pre diagnostické dáta;
- minimalizovať osobné údaje a definovať ich životný cyklus;
- neukladať secrets ani tokeny do logov, frontendu, reportu alebo commitu;
- zaviesť bezpečné chybové odpovede a monitoring zneužitia;
- pred publish vykonať kontrolu, že report neobsahuje priame client-private URL.

## Otvorené bezpečnostné rozhodnutia

- životnosť PINu a session, pravidlá revokácie a regenerácie;
- konkrétne rate limit prahy a recovery workflow;
- cieľový interný identity/role model;
- úložisko session a audit logu bez zavedenia neodôvodneného frameworku;
- produkčné umiestnenie privátnych médií a škálovanie per-request integrity verifikácie pri multi-GB packages;
- politika starších report versions po publish novej verzie;
- retencia, zálohy a vymazanie klientskych dát;
- pravidlá externých Google/Panoraven/YouTube výstupov pre súkromné prípady;
- overenie security headers, PHP verzie a serverovej konfigurácie na cieľovom hostingu.
## Prezentačná bezpečnostná hranica kroku 5A

`inspekcia.html` je súkromný klient serverovej session, nie nositeľ oprávnenia. Opaque access handle v URL identifikuje grant, ale report neodomkne bez PINu alebo už platnej session. PIN, CSRF a report sa držia iba v pamäti dokumentu; stránka nepoužíva persistentné browser storage ani tracking a explicitne nastavuje noindex/no-referrer metadata.

Lifecycle volá iba same-origin auth/report endpointy s `credentials: same-origin` a `cache: no-store`. Serverový text chyby sa neodráža do UI. 401 pri report requeste odstráni súkromný obsah z DOM a vyžiada nové overenie; logout používa session CSRF token a vykoná rovnaké vyčistenie.

Renderer používa výhradne textové DOM API, takže payload z client-safe kontraktu sa neinterpretuje ako HTML. Media URL má ďalšiu klientsku allowlist kontrolu presného originu, endpointu, jediného evidence parametra a opaque ID. Táto defense-in-depth kontrola neznižuje požiadavku na serverovú session, BOLA ochranu, MIME a `nosniff` z kroku 4B. Podrobnosti a testy sú v [CLIENT_RENDERER.md](CLIENT_RENDERER.md).

## Final delivery companion a same-PIN hranica

Admin aktivácia môže vytvoriť diagnostics grant s už vydaným inspection PINom bez vrátenia alebo uloženia plaintextu v diagnostics store. Existujúce naviazanie je idempotentné iba po overení rovnakého PINu a rovnakého immutable package.

Source-documentation appendix je samostatný session-bound allowlist, nie všeobecný download. Interná media mapa ani raw appendix paths/provenance sa klientovi nevydávajú. Fotografia je dostupná iba ak ju verifier nájde v rovnakom package, source evidence je client-safe photo a presná položka client reportu alebo appendixu ju autorizuje.
