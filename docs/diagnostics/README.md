# Diagnostická vrstva DoktorHaus

Tento adresár je záväzný doménový a architektonický základ pre budúci systém klientskych diagnostických reportov. Oddeľuje terénne záznamy od odborného uvažovania a klientského výstupu. Od kroku 3 opisuje aj izolovanú runtime storage foundation; tá stále nemení správanie klientského portálu.

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
10. [schemas/README.md](schemas/README.md) – normatívne machine-readable kontrakty, lint a fixtures.
11. [SCHEMA_MIGRATION_NOTES.md](SCHEMA_MIGRATION_NOTES.md) – budúce mapovanie dnešného portálu bez produkčnej migrácie.

## Pravidlo zmeny

Zmena dátového modelu, diagnostických pravidiel, skórovania, finančného modelu, reportového kontraktu alebo bezpečnostnej hranice sa musí najprv premietnuť do týchto dokumentov. Až po odsúhlasení dokumentácie sa má meniť JSON Schema alebo produkčná implementácia.

Ak sa dokumentácia rozchádza s reálnym kódom, pri opise súčasného stavu je zdrojom pravdy repozitár. Rozdiel sa nemá potichu prekryť: treba ho pomenovať ako dlh, rozhodnutie alebo plánovanú migráciu.

## Machine-readable kontrakty

Koncepčný model je od kroku 2 vyjadrený aj cez JSON Schema Draft 2020-12 kontrakty verzie `1.0.0`:

- [common.schema.json](schemas/common.schema.json);
- [inspection.schema.json](schemas/inspection.schema.json);
- [diagnosis.schema.json](schemas/diagnosis.schema.json);
- [report-package.schema.json](schemas/report-package.schema.json).

Realistické vstupy sú v `fixtures/valid/`; úmyselne neplatné doménové prípady a ich očakávané kódy sú v `fixtures/invalid/`.

Lokálne kontroly bez produkčných dependencies:

```text
python tools/diagnostics_lint.py --inspection docs/diagnostics/fixtures/valid/inspection-example.json --diagnosis docs/diagnostics/fixtures/valid/diagnosis-example.json --report-package docs/diagnostics/fixtures/valid/report-package-example.json
python tools/test_diagnostics_contracts.py
```

Stdlib lint nie je všeobecný JSON Schema engine. Schémy sú normatívny štruktúrny kontrakt; lint dopĺňa referenčnú integritu, grafové, finančné, QA a bezpečnostné invarianty.

## Runtime storage foundation po kroku 3

`api/lib/diagnostics/` implementuje PHP úložisko draft `inspection.json`/`diagnosis.json` dokumentov a nemenných publikovaných report packages. Používa explicitný root mimo webrootu, atomické zápisy, per-object locks, optimistic revision, strict paths, symlink kontroly, SHA-256 a staging + atomic rename publish. Samostatný runner je `tools/test_diagnostics_storage.php`.

Táto verzia stále neobsahuje databázové tabuľky, renderer reportu, autentifikáciu, PIN hashing, session, rate limiting, CSRF, autorizované HTTP doručovanie médií, audit, upload, SafetyCulture API ani webhook. Storage úspech nie je schema/domain validácia ani odborné `APPROVE`. Nezavádza produkčný framework alebo dependency a nemení existujúce správanie webu.
