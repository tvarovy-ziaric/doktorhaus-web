# Runtime úložisko diagnostiky

## Stav a hranica

Krok 3 zavádza izolovanú PHP vrstvu pre pracovné diagnostické JSON dokumenty a nemenné balíky publikovaných reportov. Implementácia je v `api/lib/diagnostics/`, nepoužíva framework, Composer ani databázu a nie je napojená na existujúci klientsky alebo administrátorský endpoint.

Úložisko je persistence a integrity boundary. Nie je autorizačná vrstva. Samo osebe nerobí z existujúceho portálu bezpečný klientsky portál a neimplementuje:

- hashovanie PINu ani jeho vydanie;
- klientsku alebo internú serverovú session;
- role a oprávnenia administrátora;
- rate limiting, lockout ani monitoring pokusov;
- CSRF ochranu;
- HTTP endpoint na autorizované doručovanie JSON alebo médií;
- audit schválenia, publikovania alebo prístupu;
- renderer reportu;
- upload alebo streaming médií;
- SafetyCulture import;
- plnú validáciu JSON Schema Draft 2020-12.

Volajúca vrstva musí pred uložením alebo publikovaním zabezpečiť doménovú validáciu, odborné QA, autentifikáciu, autorizáciu a audit. Úložisko nikdy samo nevytvára `APPROVE`.

## Konfigurácia

Produkčná konfigurácia používa environment premennú:

```text
DIAGNOSTICS_STORAGE_ROOT=/absolutna/cesta/mimo/webrootu
```

Alternatívou pre lokálny hosting je necommitovaný `api/diagnostics.config.php` podľa `api/diagnostics.config.example.php`:

```php
<?php
return [
    'storage_root' => '/srv/doktorhaus-private/diagnostics',
];
```

Environment premenná má prednosť. Chýbajúca, prázdna alebo relatívna cesta zlyhá s `STORAGE_CONFIG`. Neexistuje fallback do `data/`, `uploads/`, `var/` ani iného adresára repozitára.

Ak je známy `$_SERVER['DOCUMENT_ROOT']`, inicializácia odmietne root rovný web rootu alebo umiestnený pod ním. Kontrola pracuje aj s reálnou cestou existujúceho predka, aby odhalila bežné presmerovanie cez symlink. Root súborového systému je vždy odmietnutý. Produkčné nasadenie musí navyše overiť reálnu konfiguráciu hostingu a záloh.

CLI testy môžu použiť explicitný konštruktor s dočasným absolútnym adresárom:

```php
$storage = new DiagnosticsStorage($temporaryRoot, $knownDocumentRoot);
```

## Fyzická štruktúra

```text
<root>/
  drafts/
    <inspection-id>/
      inspection.json
      diagnosis.json
      draft-meta.json
  reports/
    <report-id>/
      <major.minor>/
        manifest.json
        inspection.json
        diagnosis.json
        report-pricing.json  # voliteľný immutable report-level pricing snapshot
        media/
        attachments/
  locks/
  tmp/
```

Adresáre používajú iba kontraktové interné ID a verziu. Email, adresa, meno klienta, title ani iný významový text sa nikdy nepoužijú v ceste.

Root a pracovné adresáre sa vytvárajú s reštriktívnymi oprávneniami `0700`; pracovné súbory používajú `0640`. Nainštalovaný snapshot sa best-effort prepne na adresáre `0550` a súbory `0440`. `chmod` je doplnková ochrana, nie autorizačný mechanizmus a na niektorých platformách nemusí mať POSIX význam.

## Draft operácie

`DiagnosticsStorage` poskytuje:

- `saveDraftInspection(array $document, ?int $expectedRevision = null)`;
- `saveDraftDiagnosis(array $document, ?int $expectedRevision = null)`;
- `loadDraftInspection(string $inspectionId)`;
- `loadDraftDiagnosis(string $inspectionId)`;
- `loadDraftMeta(string $inspectionId)`;
- `draftExists(string $inspectionId)`;
- `deleteDraft(string $inspectionId)`.

Draft sanity check je zámerne užší než JSON Schema:

- top-level JSON musí byť object;
- `document_type` musí byť `inspection` alebo `diagnosis` podľa operácie;
- inspection ID musí zodpovedať `^insp_[0-9a-f]{16,32}$`;
- `diagnosis.id` a `diagnosis.inspection_id` musia byť rovnaké;
- uložený JSON sa po serializácii znovu načíta.

Úspešné uloženie neznamená, že dokument prešiel normatívnou schémou alebo doménovým lintom. Tie sa spúšťajú samostatne pred QA/publish.

### Atomický zápis a konkurencia

Každý JSON sa najprv zapíše do náhodného dočasného súboru v rovnakom adresári a teda na rovnakom filesystéme. Implementácia zapíše celý obsah, vykoná `fflush()`, použije `fsync()`, ak je bezpečne dostupné, znovu načíta JSON a až potom vykoná `rename()` na cieľ. Chyba ponechá pôvodný cieľ bez vedomého skrátenia alebo čiastočného zápisu.

Pred dvojicou rename operácií dokument + `draft-meta.json` vznikne tranzientný `.draft-write-pending.json`. Po úplnom úspechu sa odstráni. Ak proces alebo metadata zápis zlyhá po commite dokumentu, marker zostane a ďalší read/write zlyhá s `STORAGE_INTEGRITY` namiesto práce s nejasnou revíziou. Obnova takého draftu je vedomá prevádzková operácia; knižnica nepredstiera databázovú transakciu.

Read, write aj delete operácie jedného draftu používajú per-inspection `flock(LOCK_EX)`, aby reader nepozoroval medzistav dvojice dokument + metadata. Lock súbor sa po operácii nemaže, aby nevzniklo okno s dvoma rôznymi inode lockmi. Spoľahlivosť `flock` na konkrétnom sieťovom filesystéme treba pred produkciou overiť.

`draft-meta.json` obsahuje minimálne:

```json
{
  "inspection_id": "insp_1111111111111111",
  "updated_at": "2026-08-08T08:00:00Z",
  "storage_revision": 3
}
```

`expectedRevision` je pri prvom zápise voliteľný (`null` alebo `0`), ale pri každej zmene existujúceho draftu povinný optimistic concurrency guard. Ak klient revíziu neuvedie alebo zapisuje zo starej revízie, operácia zlyhá s `STORAGE_REVISION_CONFLICT` a novší draft neprepíše. Dokument bez zodpovedajúceho metadata súboru sa považuje za porušenie integrity, nie za nový draft. Revision chráni volajúcich tejto knižnice; nenahrádza transakčnú databázu ani ochranu pred procesom, ktorý zapisuje súbory mimo knižnice.

## Publikovaný balík

Normatívny manifest zostáva `schemas/report-package.schema.json`. Storage verifier nie je všeobecný schema engine. Pred inštaláciou vykoná runtime integritné minimum:

- povinnú identitu manifestu, reportu a report version;
- kontraktovú verziu `1.0.0` a `document_type: report_package`;
- formát `rpt_…`, `rptv_…`, `insp_…` a `major.minor`;
- zhodu report a report-version ID;
- stav report version presne `published`;
- neprázdne `approved_by`, `approved_at`, `published_at` s timezone;
- approvera prítomného v actors s rolou `inspector` alebo `reviewer`;
- práve jeden súbor s rolou `inspection_data` a práve jeden `diagnosis_data`;
- najviac jeden voliteľný súbor s rolou `report_pricing`, ktorého report/report-version/inspection ownership sa musí zhodovať s manifestom;
- unikátne paths bez case-insensitive kolízie a unikátne SHA-256 podľa doménového lint kontraktu;
- existenciu všetkých deklarovaných súborov a absenciu neočakávaných regular files okrem `manifest.json`;
- voliteľnú deklarovanú veľkosť;
- SHA-256 pomocou streamujúceho `hash_file()`, bez načítania videí do pamäte;
- zhodu inspection ID medzi manifestom, `inspection.json` a `diagnosis.json`.

Doménový lint a plná Draft 2020-12 validácia zostávajú samostatnou podmienkou workflow. Verifier nehodnotí diagnostickú správnosť, scoring, referencie, náklady ani odborné schválenie.

### Relatívne cesty

Manifest môže používať iba relatívne forward-slash paths so znakmi z kontraktu. Kontrola odmieta bez normalizovania:

- `../`, vnorené `..`, samostatné `.` a prázdne segmenty;
- backslash varianty vrátane `..\`;
- Unix absolute paths;
- Windows drive paths;
- UNC paths;
- URL a ľubovoľný protocol prefix;
- NUL a nepovolené znaky;
- Windows device names a segment ukončený bodkou;
- deklarovanie samotného `manifest.json`.

Príklady platných ciest sú `inspection.json`, `diagnosis.json`, `media/foto-01.jpg` a `attachments/meranie.pdf`. Nebezpečná cesta sa nikdy najprv „neopravuje“ alebo nenormalizuje na bezpečne vyzerajúcu cestu.

### Symlinky a filesystem hranica

Verifier odmieta symlink súbor aj symlink adresár v zdrojovom balíku. Kontrolovaný copy prechádza iba deklarované paths, vytvára vlastné destination adresáre a používa exclusive create. Pred otvorením aj cez metadata otvoreného source súboru kontroluje bežnú zámenu inode. Staging sa po skopírovaní celý znovu verifikuje.

Destination root musí byť privátny a vlastnený aplikáciou. Knižnica kontroluje symlinky pri každej internej ceste, no PHP neposkytuje plný `openat(..., O_NOFOLLOW)`/`renameat2` model na všetkých podporovaných hostingoch. Ochrana proti útočníkovi s právom meniť samotný storage root preto stojí aj na OS permissions a výhradnom vlastníctve adresára. Toto je explicitná reziduálna TOCTOU hranica, nie náhrada autorizácie.

## Immutable install

`installPublishedPackage($sourceDirectory)` vykoná:

1. úplnú verifikáciu zdroja;
2. načítanie interného report ID a verzie;
3. per-version exclusive lock;
4. odmietnutie existujúceho `reports/<report-id>/<version>`;
5. vytvorenie náhodného staging adresára pod `tmp/`;
6. streamujúce kontrolované kopírovanie manifestu a iba deklarovaných súborov;
7. úplnú verifikáciu stagingu vrátane hashov;
8. best-effort read-only permissions;
9. kontrolu rovnakého filesystému;
10. atomický `rename()` stagingu na finálny, dovtedy neexistujúci adresár.

Pri chybe sa staging bezpečne odstráni a finálny adresár nevznikne. Druhá inštalácia rovnakej verzie zlyhá s `STORAGE_ALREADY_EXISTS`; knižnica neposkytuje overwrite ani update API pre publikovaný snapshot. Doplnenie obsahu vytvára novú verziu, napríklad 1.1 alebo 2.0.

## Interné čítanie

### Access security layout kroku 4A

Rovnaký privátny `DIAGNOSTICS_STORAGE_ROOT` od kroku 4A obsahuje aj `access/grants/`, dve vrstvy `access/rate-limit/` a denné `audit/*.jsonl`. Granty, rate state aj audit odmietajú symlinky, používajú úzke formáty názvov, reštriktívne permissions, locky a atomické replace zápisy. Grant nesie SHA-256 `manifest.json`, takže session zostáva viazaná na presný overený immutable snapshot.

Tieto súbory nie sú reportový obsah ani verejný cache. Nesmú sa umiestniť pod webroot, commitnúť alebo FTP mirrorovať. Backup a restore musia zachovať konzistenciu grantov, auditnú stopu a oddelené secrets; pri horizontálnom škálovaní musí byť rate-limit a session stav spoločný alebo nahradený zodpovedajúcim centralizovaným mechanizmom.

Access vrstva neposkytuje raw file endpoint. Budúce autorizované doručovanie musí osobitne vyriešiť field-level projection, media path autorizáciu, streaming, `Range`, `Content-Disposition` a cache hlavičky.

Vrstva poskytuje:

- `loadPublishedManifest($reportId, $version)`;
- `loadPublishedInspection($reportId, $version)`;
- `loadPublishedDiagnosis($reportId, $version)`;
- `resolvePublishedFile($reportId, $version, $relativePath)`;
- `listPublishedVersions($reportId)`.

Čítanie znovu overuje balík a jeho hashe. `resolvePublishedFile()` prijme iba path deklarovaný manifestom, znovu skontroluje symlinky a canonical path pod balíkom a vráti internú filesystem cestu spolu s role, checksumom, content type, privacy a prípadnou veľkosťou. Nevracia verejnú URL a nevykonáva HTTP doručenie.

Zoznam verzií zahŕňa iba syntakticky platné, nesymlinkové adresáre s manifestom a používa numerické `major.minor` poradie: `1.0`, `1.1`, `1.10`, `2.0`.

## Stabilné chybové kódy

| Kód | Význam |
|---|---|
| `STORAGE_CONFIG` | chýbajúca alebo neplatná konfigurácia |
| `STORAGE_UNSAFE_ROOT` | root v/na webroote alebo príliš široký root |
| `STORAGE_INVALID_ID` | neplatné interné ID |
| `STORAGE_INVALID_VERSION` | neplatná major.minor verzia |
| `STORAGE_PATH` | nebezpečná, nedeklarovaná alebo unikajúca cesta |
| `STORAGE_SYMLINK` | symlink v chránenej storage/package ceste |
| `STORAGE_IO` | bezpečne dokončená filesystem operácia zlyhala |
| `STORAGE_JSON` | JSON nemožno serializovať alebo načítať ako object |
| `STORAGE_MANIFEST` | neplatná základná štruktúra manifestu |
| `STORAGE_MISSING_FILE` | chýbajúci draft alebo deklarovaný package súbor |
| `STORAGE_UNEXPECTED_FILE` | regular file navyše oproti manifestu |
| `STORAGE_HASH_MISMATCH` | obsah nezodpovedá SHA-256 |
| `STORAGE_SIZE_MISMATCH` | obsah nezodpovedá deklarovanej veľkosti |
| `STORAGE_PACKAGE_STATE` | balík nie je publikovaný/schválený podľa minima |
| `STORAGE_ALREADY_EXISTS` | immutable report version už existuje |
| `STORAGE_ID_MISMATCH` | identity dokumentov alebo manifestu sa nezhodujú |
| `STORAGE_LOCK` | lock nemožno vytvoriť alebo získať |
| `STORAGE_INTEGRITY` | uložená štruktúra alebo filesystem entry je nekonzistentná |
| `STORAGE_REVISION_CONFLICT` | optimistic revision je zastaraná |

Správy sú zámerne všeobecné. Neobsahujú storage root, source cestu, klientsky obsah, PIN, token ani citlivú URL. Detail pre interný monitoring sa má doplniť mimo klientskych odpovedí a bez logovania secrets.

## Deploy hardening

`.gitignore` chráni `api/inspections.config.php` a `api/diagnostics.config.php`. `api/.htaccess` blokuje config aj example config súbory a `api/lib/.htaccess` blokuje priame HTTP načítanie knižničných PHP súborov pri Apache konfigurácii rešpektujúcej `.htaccess`.

FTP workflow explicitne vylučuje oba lokálne configy aj `data/inspections.json`. Diagnostický runtime root sa úmyselne nevytvára v repozitári ani webroote, preto nemá deployovateľnú lokálnu cestu. Produkčný root, jeho backup a retention sa spravujú mimo Git/FTP mirroru.

## CI verification

### Implementation exists

Samostatný runner `tools/test_diagnostics_storage.php` používa iba dočasný adresár operačného systému a anonymizované syntetické balíky založené na validných fixtures. Nečíta ani nemení `data/inspections.json`. Pokrýva draft revision, poškodený JSON, ID mismatch, webroot rejection, traversal, absolútne/URL/UNC paths, hash a size mismatch, chýbajúce a neočakávané súbory, publish state, symlinky, immutable install, čítanie, resolve, cleanup a numerické radenie verzií.

`tools/test_diagnostics_access.php` pridáva syntetické access grant, PIN/pepper, expiration, rate-limit, session-policy, audit, stale update, rollback/fail-closed a symlink scenáre. `tools/test_diagnostics_access_http.sh` používa dočasný storage, lokálny PHP built-in server a curl na overenie unlock/status/logout, generic 401, cookie atribútov, CSRF, 429, revokácie a rotácie. Žiadny runner nečíta legacy runtime ani produkčné secrets.

Krok 4B pridáva projection/media unit runner a samostatný delivery HTTP runner. Session package binding stále vykoná úplnú hash/size verifikáciu. Overený snapshot sa v tom istom requeste odovzdá projekcii a media resolveru, takže resolver nehashuje veľký súbor druhýkrát. Každý nový Range request však stále raz verifikuje celý package; pri multi-GB médiách je to otvorený performance problém, nie dôvod vypnúť integritu. Detail je v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

### Runtime-tested in GitHub CI

Workflow `Diagnostics contracts, access and delivery CI` v `.github/workflows/diagnostics-ci.yml` je autoritatívny execution gate pre PHP runtime. Na `ubuntu-latest` nainštaluje systémový balík `php-cli`, vypíše `php -v`, vykoná lint všetkých diagnostics PHP tried, endpointov a runnerov. Následne spustí storage, access, projection/media unit suite a auth aj delivery HTTP integration suite s viditeľným `E_ALL` pre CLI testy.

CI nastavuje symlink testy ako povinné. Storage aj access log preto musia obsahovať príslušné `symlink … test: PASS`; environment-specific `SKIP` je povolený iba mimo tohto Linux CI gate. Každá revision je runtime overená až vtedy, keď jej zodpovedajúci GitHub Actions run skončí úspešne.

Workflow používa syntetický temporary storage, odstraňuje `DIAGNOSTICS_STORAGE_ROOT` z testovacieho procesu, nemá produkčné secrets ani write permissions a neuploaduje testovacie balíky ako artifacts. Nevykonáva FTP deploy ani nepristupuje k produkčnému hostingu.

Lokálne príkazy pri dostupnom PHP CLI:

```text
python tools/test_diagnostics_contracts.py
php -l api/lib/diagnostics/DiagnosticsStorage.php
php -l api/lib/diagnostics/DiagnosticsPackageVerifier.php
php -l api/lib/diagnostics/DiagnosticsStorageException.php
php -l api/diagnostics-auth.php
php -l api/diagnostics-report.php
php -l api/diagnostics-media.php
php -l api/diagnostics.config.example.php
php -l tools/test_diagnostics_access.php
php -l tools/test_diagnostics_storage.php
php -d display_errors=1 -d error_reporting=-1 tools/test_diagnostics_storage.php
php -d display_errors=1 -d error_reporting=-1 tools/test_diagnostics_access.php
php -d display_errors=1 -d error_reporting=-1 tools/test_diagnostics_client_projection.php
bash tools/test_diagnostics_access_http.sh
bash tools/test_diagnostics_delivery_http.sh
```

Repozitár nepotvrdzuje produkčnú PHP verziu hostingu. Systémové PHP na `ubuntu-latest` je reprodukovateľné CI prostredie pre aktuálnu revision, nie deklarácia production compatibility. Pred ostrým nasadením treba zistiť major/minor PHP verziu hostingu a spustiť storage suite alebo minimálne compatibility smoke test proti rovnakej major/minor vetve.

## Otvorené prevádzkové rozhodnutia

- cieľový produkčný filesystem alebo privátne object storage pre veľké médiá;
- backup, restore test, retention a bezpečné vymazanie;
- overenie semantics `flock`, atomického rename a permissions na konkrétnom hostingu;
- potvrdenie produkčnej major/minor PHP verzie a compatibility smoke test na rovnakom runtime;
- produkčný Draft 2020-12 validator a miesto jeho spustenia v publish workflow;
- dôveryhodný immutable verification index/cache pre veľké balíky bez oslabenia package bindingu;
- produkčné limity, monitoring a object-storage stratégia pre autorizovaný media streaming;
- audit interných actorov a approval/publish udalostí; klientské auth udalosti kroku 4A už majú samostatný security audit;
- riešenie reziduálneho TOCTOU rizika, ak storage root nebude výhradne vlastnený aplikačným používateľom.
