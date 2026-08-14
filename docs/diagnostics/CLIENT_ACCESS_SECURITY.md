# Bezpečnostné jadro klientskeho prístupu

## Rozsah kroku 4A

Krok 4A vytvára izolované serverové jadro pre budúci klientsky prístup k presne určenej publikovanej verzii diagnostického reportu. Tok je:

`published report → access grant → opaque access handle → 6-miestny PIN → overenie → serverová session → rate limit → rotácia alebo revokácia → audit`

Implementácia chráni iba stav autentizácie a väzbu oprávnenia na immutable balík. Nevydáva inspection, diagnosis, reportové JSON, PDF, prílohy, fotografie ani video. Neobsahuje renderer, klientsky frontend, backoffice UI na vydávanie grantov, field-level autorizáciu ani media streaming.

Legacy záznamy sa nemigrujú a ich pôvodný klientsky tok zostáva dostupný. Záznam však môže mať voliteľný server-side binding `diagnosticsAccessId`. V takom prípade je `inspekcie.html` iba vstupný formulár: `api/inspections.php` musí rovnaký zadaný PIN overiť cez `DiagnosticsAccessService::verifyPin()`, vytvoriť štandardnú session cez `DiagnosticsClientSession::establish()` a vrátiť bezpečný redirect na `inspekcia.html?access=acc_…`. Binding nevytvára grant, nemení jeho PIN a nie je alternatívnym autorizačným modelom.

## Komponenty

- `DiagnosticsSecurityConfig` načíta povinné secrets a necitlivé časové/limitné nastavenia bez frameworku alebo Composeru.
- `DiagnosticsAccessStore` ukladá granty ako samostatné JSON objekty s per-grant lockom, atomickým zápisom, kontrolou symlinkov a optimistic generation kontrolou.
- `DiagnosticsAccessService` vytvára, overuje, rotuje a revokuje grant; plaintext PIN vracia iba pri vytvorení alebo rotácii.
- `DiagnosticsRateLimiter` udržiava perzistentný fixed-window limit pre dvojicu access+IP a konzervatívny globálny limit IP.
- `DiagnosticsClientSession` spravuje serverovú PHP session, CSRF token, idle/absolute timeout a opakovanú validáciu väzby grantu.
- `DiagnosticsAuditLog` zapisuje bezpečnostné udalosti do denných JSONL súborov s `flock`.
- `api/diagnostics-auth.php` poskytuje iba unlock, status a logout. Nie je reportovým ani media endpointom.

## Access grant a nemenná väzba

Verejný handle má tvar `acc_<32 lowercase hex>` a vzniká z `random_bytes(16)`. Je opaque identifikátor, nie heslo. Grant sa uloží do `access/grants/<access-id>.json` a obsahuje:

- `report_id`, numerickú `report_version` a `report_version_id`;
- SHA-256 skutočného `manifest.json` nainštalovaného publikovaného balíka;
- stav `active` alebo `revoked`, generation a časové údaje;
- iba `pin_hash`, nikdy plaintext PIN.

Pri autentizácii aj pri validácii session sa znovu overí aktuálny immutable balík, version ID aj hash manifestu. Grant nesleduje automaticky „najnovšiu“ verziu. Nová publikovaná verzia potrebuje vedomé vydanie samostatného grantu.

Rotácia PINu a revokácia zvýšia `generation`. Session si generation zapamätá, takže stará session zlyhá pri nasledujúcej požiadavke. Revokácia je idempotentná: opakované volanie už revokovaný grant znovu nemení.

## PIN a secrets

Šesťmiestny PIN vzniká cez `random_int(100000, 999999)`. Pred `password_hash(..., PASSWORD_DEFAULT)` sa spracuje:

```text
hash_hmac('sha256', 'doktorhaus-diagnostics-pin-v1:' + PIN, DIAGNOSTICS_PIN_PEPPER)
```

`DIAGNOSTICS_PIN_PEPPER` a `DIAGNOSTICS_AUDIT_HMAC_KEY` sú povinné, vzájomne oddelené secrets s minimálnou dĺžkou 32 bytes. Nemajú produkčný default a nesmú byť commitnuté. Environment má prednosť pred ignorovaným `api/diagnostics.config.php`.

Necitlivé defaulty sú:

| Nastavenie | Default |
|---|---:|
| idle session | 3600 s |
| absolute session | 43200 s |
| rate window | 900 s |
| access+IP maximum | 6 zlyhaní |
| globálne IP maximum | 30 zlyhaní |
| lockout | 900 s |

## Rate limiting a ochrana proti zisťovaniu účtov

Rate limiter sa kontroluje pred `password_verify`. Access+IP aj globálna IP vrstva sú perzistentné pod rovnakým privátnym storage rootom. Po úspechu sa resetuje access+IP bucket; globálny IP bucket zostáva konzervatívny.

Server používa iba `REMOTE_ADDR`. `X-Forwarded-For`, `Forwarded` ani podobné klientom ovládateľné hlavičky nie sú zdrojom identity. Uložené bucket názvy aj audit používajú HMAC pseudonymizáciu, nie raw IP.

Neznámy syntakticky platný access ID prejde po rate-limit kontrole dummy `password_verify`. Nesprávny PIN, neznámy grant, revokovaný grant a expirovaný grant majú na klientskom endpoint-e rovnaký status 401 a rovnakú odpoveď. Limitovaný klient dostane 429 s `Retry-After`.

Fixed-window súbory a ich zámky chránia perzistenciu a aktualizácie. Rate check, drahé password verification a success/failure update sa pre rovnaký IP pseudonym serializujú pod jedným filesystem lockom, takže paralelné požiadavky neobídu limit cez check/update race. Kompromisom je serializácia legitímnych súbežných pokusov za spoločným NAT. Pri horizontálnom škálovaní musí byť storage aj lock spoločný alebo sa limiter presunie do centralizovaného atomického úložiska.

## Serverová session a HTTPS

Cookie sa volá `DH_DIAGSESSID` a používa:

- `Secure` v produkčnom HTTPS režime;
- `HttpOnly`;
- `SameSite=Strict`;
- `Path=/`;
- session lifetime 0;
- PHP strict mode, only-cookies a vypnuté trans SID.

Session ID sa po unlock regeneruje. Session nie je natvrdo viazaná na IP, aby legitímna zmena siete klienta nespôsobovala náhodné odhlásenie. Obsahuje iba interný autentizačný kontext: access/report/version väzbu, generation, časy a náhodný 32-byte CSRF token. Neukladá sa do `localStorage`.

Každá status/logout požiadavka kontroluje idle aj absolútny timeout, stav a expiráciu grantu, generation, report ID, verziu, version ID a hash manifestu. Pri chybe sa session zničí a zapíše auditná udalosť.

Endpoint odmietne nešifrovanú požiadavku. Jediná výnimka je testovací PHP built-in server, ak súčasne platí `DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST=1`, `PHP_SAPI=cli-server` a `REMOTE_ADDR` je `127.0.0.1` alebo `::1`. Prepínač sám osebe v inom runtime alebo pre vzdialenú adresu HTTPS neobíde.

## HTTP kontrakt

`api/diagnostics-auth.php` podporuje:

- `POST` JSON `{ "action": "unlock", "accessId": "…", "pin": "……" }`;
- `GET` pre stav existujúcej session;
- `POST` JSON `{ "action": "logout", "csrfToken": "…" }`.

Úspešný unlock/status vracia iba `ok`, `authenticated`, `accessId`, `version` a `csrfToken`. Nevracia report ID, version ID, package hash, filesystem path, PIN/hash, session name/ID/hodnotu ani diagnostický obsah. Logout vyžaduje validnú session a `hash_equals` CSRF kontrolu.

POST akceptuje iba `application/json`; neplatný JSON je 400, chybný CSRF 403 a nepodporovaná metóda 405. Interné chyby sú generic 500 alebo 503. Odpovede používajú `no-store, private`, `Pragma: no-cache`, `nosniff`, `no-referrer`, `DENY` a `Cross-Origin-Resource-Policy: same-origin`. Endpoint neposiela wildcard CORS.

## Audit

Denný súbor `audit/YYYY-MM-DD.jsonl` používa bezpečný append a `flock`. Evidujú sa:

`access_grant_created`, `access_pin_rotated`, `access_revoked`, `auth_success`, `auth_failure`, `auth_rate_limited`, `session_created`, `session_expired`, `session_invalidated`, `logout`.

Audit neobsahuje raw IP, PIN, `pin_hash`, session ID/cookie, celý user agent ani diagnostický obsah. IP a user agent sa pseudonymizujú oddelenými domain-tagged HMAC vstupmi; metadata majú úzky whitelist.

Delivery vrstva kroku 4B používa ten istý audit a pridáva iba `report_viewed` a `media_accessed`. Zápis musí uspieť pred vydaním client-private contentu; event metadata nepridávajú path, filename ani `media_reference`. Podrobnosti sú v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

Vytvorenie, rotácia a revokácia sú auditne konzistentné v bezpečnom smere. Store pod per-grant lockom najprv pripraví a overí dočasný JSON, potom zapíše audit a až následne vykoná atomický commit. Ak audit zlyhá alebo proces skončí pred commitom, pôvodný grant zostane nezmenený a pripravený súbor nie je autoritatívny. Pri zlyhaní filesystem commitu po úspešnom audite môže audit obsahovať udalosť bez zodpovedajúcej mutácie; nikdy však nevznikne platná mutácia bez predchádzajúcej auditnej udalosti. Prevádzkový monitoring má takýto prípad vyhodnotiť ako neúspešný commit.

## Runtime layout a prevádzka

Všetky nové runtime dáta používajú ten istý `DIAGNOSTICS_STORAGE_ROOT` mimo webrootu:

```text
access/grants/<access-id>.json
access/rate-limit/access-ip/<hmac>.json
access/rate-limit/ip/<hmac>.json
audit/YYYY-MM-DD.jsonl
locks/access-*.lock
locks/rate-*.lock
```

Storage root musí vlastniť aplikačný používateľ. Backup, restore, retencia, rotácia secrets a overenie `flock`/atomic rename semantics na produkčnom hostingu zostávajú prevádzkové rozhodnutia. Strata PIN peppera zneplatní overovanie existujúcich PINov; rotácia preto potrebuje riadený proces nového vydania. Zmena audit HMAC kľúča zmení pseudonymy a musí mať definovanú hranicu obdobia.

## Overenie a hranice dôkazu

`tools/test_diagnostics_access.php` pokrýva config, grant, PIN hash/pepper, expiry, package binding, rotáciu, revokáciu, generation invalidáciu, CSRF, cookie policy, perzistentný rate limit, audit, poškodený JSON, rollback pri chybe auditu a symlink fail-closed správanie. `tools/test_diagnostics_access_http.sh` spúšťa dočasný PHP built-in server a overuje HTTP scenáre A–O vrátane ekvivalentnej 401 odpovede, cookie atribútov, statusu, logoutu, 429/`Retry-After` a okamžitej invalidácie session.

Lokálne prostredie projektu nemusí mať PHP. Autoritatívny runtime dôkaz preto poskytuje GitHub Actions na PHP 8.3.x runneri. Verzia produkčného PHP hostingu nie je v repozitári potvrdená a pred nasadením sa musí samostatne overiť.

## Čo zostáva mimo kroku 4A

Krok 4A neimplementuje a netvrdí ochranu týchto budúcich tokov:

- klientská projekcia a field-level autorizácia;
- report renderer a klientská UX;
- endpointy pre inspection/diagnosis/report dáta;
- autorizovaný media/PDF streaming vrátane `Range`, `Content-Disposition` a cache pravidiel;
- backoffice identita, role a UI na vydávanie grantov alebo doručenie PINu;
- SafetyCulture integrácia;
- migrácia legacy záznamov alebo ich plaintext PINov.

## Bridge z existujúceho inspection PIN formulára

Voliteľné pole `diagnosticsAccessId` má tvar `^acc_[0-9a-f]{32}$` a ukladá sa iba v internom inspection recorde. `public_item()` ho nesmie vydať ako všeobecné klientské metadata. Bez bindingu sa zachová pôvodná legacy odpoveď.

Pri linked zázname server po nájdení `ready|sent` inspection odovzdá ten istý šesťmiestny PIN autoritatívnemu diagnostics grantu. Legacy PIN a grant PIN preto musia byť rovnaké; rozdiel, neznámy alebo neplatný binding, neaktívny či expirovaný grant a package mismatch zlyhajú rovnakou všeobecnou auth odpoveďou. Rate-limit odpoveď grantu sa prenesie ako 429 s `Retry-After`.

Úspech vytvorí tú istú `DH_DIAGSESSID` cookie s existujúcou politikou a vráti iba `mode: diagnostics` a allowlisted redirect URL. PIN nie je v URL ani odpovedi. Po presmerovaní `diagnostics-client.js` rozpozná session cez status request a načíta report bez druhého PIN formulára. Opaque access ID v redirect URL nie je samostatný credential.

Kým tieto vrstvy nevzniknú, nový auth endpoint sa nesmie interpretovať ako hotový klientsky portál a žiadne privátne reportové dáta sa cezň nesmú vracať.

Na tento izolovaný 4A kontrakt nadväzuje krok 4B: client-safe report a autorizovaný media/PDF streaming už poskytujú samostatné `diagnostics-report.php` a `diagnostics-media.php`, nie auth endpoint. Renderer, finálny klientsky UX, backoffice issuance UI, SafetyCulture a legacy migrácia zostávajú mimo 4B aj mimo tohto dokumentu.

Krok 5A je prvý browser klient tohto kontraktu. `JSS/diagnostics-client.js` používa status, unlock a CSRF logout presne cez uvedené requesty; access ID a CSRF drží iba v pamäti. Rozdielny access handle oproti aktívnej session neprepne report bez PINu. Pri 401 alebo logout odstráni vykreslený obsah a nikdy nezobrazuje interný text chyby. Renderer stále nevydáva, nerotuje ani nerevokuje granty; tieto interné operácie zostávajú mimo klienta. Pozri [CLIENT_RENDERER.md](CLIENT_RENDERER.md).

## Aktivácia existujúcim inspection PINom

Interná metóda `createGrantWithPin()` prijíma iba validný šesťmiestny PIN, používa rovnaký HMAC prehash, `password_hash`, package binding a audit ako náhodné vydanie a plaintext nevracia ani neukladá. Admin akcia `activate-diagnostics` vyžaduje Admin PIN, stav `ready|sent`, existujúci šesťmiestny inspection PIN a existujúci published package. Existujúci binding sa overí proti tomu istému PINu a package; konflikt vyžaduje vedomú opravu.

Bežné admin UX neprijíma ručne prepísané `acc_`, `rpt_` ani verziu. Admin-only akcia `available-diagnostics` skladá human-readable zoznam iba z verifierom potvrdených publikovaných balíkov. Po vedomom výbere server uloží voliteľné `diagnosticsInspectionId`; toto stabilné ID je jediný signál pre neskoršie automatické nájdenie verzie. Názov, lokalita ani adresa sa na párovanie nepoužívajú. Existujúci grant sa pri idempotentnej aktivácii nemení ani znovu nevytvára.
