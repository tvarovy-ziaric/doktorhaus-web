# Bezpečnostný model

## Rozsah dokumentu

Tento dokument opisuje súčasný prototyp a cieľový model. Neimplementuje autentifikáciu, session, rate limiting ani ochranu médií.

Bezpečnostná zásada: žiadne client-private diagnostické dáta, fotografie ani dokumenty nesmú byť dostupné bez platnej serverom overenej autorizácie. Nezverejnený odkaz, `hidden` element alebo neuhádnuteľný názov súboru nie sú autorizácia.

## Súčasný stav podľa kódu

### Klientsky prístup

- `inspekcie.html` posiela šesťmiestny PIN v JSON tele na `api/inspections.php` s akciou `unlock`.
- `api/inspections.php` porovnáva PIN priamo s hodnotou uloženou v `data/inspections.json`. PIN je uložený v otvorenom tvare.
- Úspešné overenie nevytvára serverovú session. Endpoint v jednej odpovedi vráti metadáta a priame URL médií záznamu v stave `ready` alebo `sent`.
- PINy sa generujú cez `random_int()` a kontroluje sa jedinečnosť v aktuálnom JSON súbore.
- Nie je implementovaný limit pokusov, oneskorenie, blokovanie, expirácia, revokácia ani audit klientskych prístupov.

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
- `.gitignore` ignoruje `data/inspections.json`, ale aktuálne neuvádza `api/inspections.config.php`, hoci ide o lokálny secret config.
- FTP workflow má výnimky pre runtime dáta modulu Pomoc verejnosti, nie však ekvivalenty pre inšpekcie. Lokálne runtime súbory alebo privátne uploady by mohol mirror podľa stavu workspace preniesť alebo prepísať.
- Endpointy nastavujú JSON content type a `X-Content-Type-Options: nosniff`; komplexnejšia politika security headers nie je v tomto module definovaná.

## Identifikované riziká

1. Šesťmiestny PIN má nízku entropiu a bez rate limitingu sa dá skúšať automatizovane.
2. Únik `data/inspections.json`, admin odpovede alebo zálohy odhalí otvorené klientské PINy.
3. Priama URL súkromného média zostáva použiteľná bez PINu a môže sa dostať do histórie, logov, referrerov alebo preposlania.
4. `sessionStorage` chráni iba zobrazenie rozcestníka, nie serverový zdroj.
5. Zdieľaný globálny Admin PIN neposkytuje identitu, role, odvolanie konkrétneho prístupu ani audit approvera.
6. Neexistuje expirácia/revokácia klientského prístupu ani invalidácia pri vrátení reportu do draftu.
7. `ready` sprístupňuje dáta, ale nie je viazané na nemennú, auditovanú report version.
8. Deploy a ignore pravidlá nie sú úplne zosúladené s inspections runtime a secrets.
9. Ochrana `data/` a configs závisí od webservera; pri migrácii mimo Apache môže prestať platiť.
10. Externé médiá môžu mať verejné alebo širšie oprávnenia než klientsky report.
11. Chýba audit prístupov, zmien, schválenia, publikovania, stiahnutia a zrušenia prístupu.
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

Šesťmiestny PIN môže zostať používateľsky jednoduchý iba spolu s rate limitingom, monitoringom, expiráciou a serverovou session. Presné limity a životnosť sú otvorené rozhodnutia pred implementáciou.

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
- umiestnenie a spôsob doručovania privátnych médií;
- politika starších report versions po publish novej verzie;
- retencia, zálohy a vymazanie klientskych dát;
- pravidlá externých Google/Panoraven/YouTube výstupov pre súkromné prípady;
- požadované security headers a serverová konfigurácia cieľového hostingu.
