# Diagnostická vrstva DoktorHaus

Tento adresár je záväzný doménový a architektonický základ pre systém klientskych diagnostických reportov. Oddeľuje terénne záznamy od odborného uvažovania a klientského výstupu. Krok 3 pridal izolovanú runtime storage foundation, krok 4A serverové bezpečnostné jadro klientského prístupu, krok 4B strict client-safe report projection s autorizovaným media delivery, krok 5A samostatný bezpečný klientsky renderer a report-level pricing runtime doplnil session-bound client-safe finančný rámec. Legacy portál zostáva zachovaný; voliteľný server-side binding môže jeho existujúci PIN formulár použiť ako vstup do autoritatívneho diagnostics auth/session toku.

Základný tok informácií je:

`PROPERTY → INSPECTION → OBSERVATIONS → EVIDENCE → DIAGNOSTIC ISSUES → HYPOTHESES → VERIFICATIONS → RECOMMENDATIONS → CLIENT REPORT`

SafetyCulture zostáva zdrojom terénneho zberu a surových záznamov. Diagnostická vrstva nad nimi má zoskupiť súvisiace prejavy, pomenovať neistotu, oddeliť možné mechanizmy od potvrdených zistení a pripraviť pokojný rozhodovací výstup pre klienta.

## Odporúčané poradie čítania

1. [ARCHITECTURE.md](ARCHITECTURE.md) – súčasný stav repozitára, cieľové vrstvy a hranice.
2. [DIAGNOSTIC_RULES.md](DIAGNOSTIC_RULES.md) – význam diagnostických tvrdení a pravidlá práce s neistotou.
3. [DATA_MODEL.md](DATA_MODEL.md) – entity, vlastníctvo, väzby a význam polí.
4. [SCORING_RULES.md](SCORING_RULES.md) – nezávislé osi závažnosti, pravdepodobnosti, naliehavosti a priority.
5. [COST_MODEL.md](COST_MODEL.md) – intervalové odhady nákladov a ochrana pred falošnou presnosťou.
6. [REPORT_CONTRACT.md](REPORT_CONTRACT.md) – obsah a pravidlá klientského reportu.
7. [SECURITY_MODEL.md](SECURITY_MODEL.md) – dnešný prototyp a cieľová ochrana klientskych dát.
8. [WORKFLOW.md](WORKFLOW.md) – manuálny MVP tok a budúce napojenie na SafetyCulture.
9. [RUNTIME_STORAGE.md](RUNTIME_STORAGE.md) – filesystem storage, atomické drafty, immutable publish a bezpečnostné hranice.
10. [CLIENT_ACCESS_SECURITY.md](CLIENT_ACCESS_SECURITY.md) – granty, PIN hashing, serverová session, rate limiting, revokácia a audit v kroku 4A.
11. [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md) – strict allowlist projekcia, report/media endpointy, BOLA, MIME, Range a audit v kroku 4B.
12. [schemas/README.md](schemas/README.md) – normatívne machine-readable kontrakty, lint a fixtures.
13. [CLIENT_RENDERER.md](CLIENT_RENDERER.md) – stavový klient, bezpečný DOM renderer, media URL hranica, responsive a print správanie v kroku 5A.
14. [SCHEMA_MIGRATION_NOTES.md](SCHEMA_MIGRATION_NOTES.md) – budúce mapovanie dnešného portálu bez produkčnej migrácie.

## Pravidlo zmeny

Zmena dátového modelu, diagnostických pravidiel, skórovania, finančného modelu, reportového kontraktu alebo bezpečnostnej hranice sa musí najprv premietnuť do týchto dokumentov. Až po odsúhlasení dokumentácie sa má meniť JSON Schema alebo produkčná implementácia.

Ak sa dokumentácia rozchádza s reálnym kódom, pri opise súčasného stavu je zdrojom pravdy repozitár. Rozdiel sa nemá potichu prekryť: treba ho pomenovať ako dlh, rozhodnutie alebo plánovanú migráciu.

## Machine-readable kontrakty

Koncepčný model je od kroku 2 vyjadrený aj cez JSON Schema Draft 2020-12 kontrakty verzie `1.0.0`:

- [common.schema.json](schemas/common.schema.json);
- [inspection.schema.json](schemas/inspection.schema.json);
- [diagnosis.schema.json](schemas/diagnosis.schema.json);
- [report-package.schema.json](schemas/report-package.schema.json);
- [report-pricing.schema.json](schemas/report-pricing.schema.json) – voliteľný report-version snapshot čiastkových finančných komponentov;
- [client-report.schema.json](schemas/client-report.schema.json) – odvodený delivery kontrakt, nie nový source of truth.

Realistické vstupy sú v `fixtures/valid/`; úmyselne neplatné doménové prípady a ich očakávané kódy sú v `fixtures/invalid/`.

Lokálne kontroly bez produkčných dependencies:

```text
python tools/diagnostics_lint.py --inspection docs/diagnostics/fixtures/valid/inspection-example.json --diagnosis docs/diagnostics/fixtures/valid/diagnosis-example.json --report-package docs/diagnostics/fixtures/valid/report-package-example.json
python tools/diagnostics_lint.py --inspection docs/diagnostics/fixtures/valid/inspection-minimal.json --report-pricing docs/diagnostics/fixtures/valid/report-pricing-example.json
python tools/test_diagnostics_contracts.py
```

Stdlib lint nie je všeobecný JSON Schema engine. Schémy sú normatívny štruktúrny kontrakt; lint dopĺňa referenčnú integritu, grafové, finančné, QA a bezpečnostné invarianty.

## CI verification

Workflow `Diagnostics contracts, access and delivery CI` v `.github/workflows/diagnostics-ci.yml` vykonáva Python contract tests, PHP syntax lint, storage/access/projection runners a auth aj delivery HTTP integráciu na GitHub-hosted Linux runneri. Testy používajú iba syntetické dáta, dočasný storage a syntetický 6 MiB media súbor; nepotrebujú produkčné secrets, FTP ani prístup k hostingu.

Ak je lokálne dostupné PHP CLI, rovnaké jadro kontroly sa spustí príkazmi:

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
node tools/test_diagnostics_renderer.js
bash tools/test_diagnostics_renderer_http.sh
```

Úspešný workflow potvrdzuje vykonanie tejto suite pre konkrétnu revision na CI PHP runtime. Nepotvrdzuje verziu ani konfiguráciu produkčného hostingu.

## Runtime storage foundation po kroku 3

`api/lib/diagnostics/` implementuje PHP úložisko draft `inspection.json`/`diagnosis.json` dokumentov a nemenných publikovaných report packages. Používa explicitný root mimo webrootu, atomické zápisy, per-object locks, optimistic revision, strict paths, symlink kontroly, SHA-256 a staging + atomic rename publish. Samostatný runner je `tools/test_diagnostics_storage.php`.

Krok 4A nad túto vrstvu pridáva opaque access grant viazaný na presný hash publikovaného manifestu, peppered PIN hash, perzistentný rate limiting, serverovú session, CSRF, rotáciu/revokáciu a bezpečnostný audit. Auth endpoint poskytuje iba unlock, status a logout.

Krok 4B z immutable package vytvára `client_report` výhradne cez field allowlist, filtruje internal/orphan evidence a poskytuje session-bound `GET api/diagnostics-report.php` a `GET|HEAD api/diagnostics-media.php`. Media selector je iba evidence ID; endpoint podporuje single byte ranges, bezpečný MIME/Content-Disposition model, `no-store` a audit. Raw inspection, diagnosis, manifest, storage paths a interné metadáta klientovi neposiela. Podrobnosti sú v [CLIENT_DELIVERY.md](CLIENT_DELIVERY.md).

Krok 5A pridáva `inspekcia.html`, oddelený session/PIN lifecycle a čistý DOM renderer client-safe kontraktu. Reportové texty neprechádzajú HTML parserom, media URL musia zostať na presnom same-origin delivery endpointe a pri expirácii/logout sa obsah odstráni z DOM. Podrobnosti sú v [CLIENT_RENDERER.md](CLIENT_RENDERER.md).

Voliteľný report-level pricing runtime odovzdáva projekcii iba `report_pricing` snapshot z už overeného package, fail-closed filtruje interné komponenty a väzby a renderuje samostatnú sekciu „Finančný rámec“. Starý package bez pricingu nemení svoj client report a issue `cost_estimate` ostáva cenou celého definovaného issue scope.

Final-delivery vrstva dopĺňa voliteľný immutable source-documentation appendix a minimálnu admin aktiváciu published package existujúcim inspection PINom. Stále neobsahuje databázové tabuľky, všeobecný backoffice issuance modul, SafetyCulture API/webhook, backup/restore ani samostatný klientsky PDF generátor. Renderer nevydáva granty a nevytvára odborný obsah. Storage, auth ani delivery úspech nie je schema/domain validácia alebo odborné `APPROVE`.

## Roadmap po kroku 5A

Hotové sú: diagnostic foundation, machine contracts, runtime storage, CI execution, secure access authentication, client-safe projection, authorized linked/appendix media delivery, client renderer, voliteľný report-level pricing runtime a minimálna same-PIN admin aktivácia.

Reálne klientské dáta a published packages zostávajú výhradne v private storage mimo verejného repozitára. Ešte nie sú hotové: všeobecný issuance/backoffice modul, SafetyCulture adapter, legacy migrácia, server backup/restore ani samostatný PDF generátor.
