# Machine-readable diagnostické kontrakty

Adresár obsahuje normatívne JSON Schema Draft 2020-12 kontrakty verzie `1.0.0`:

- `common.schema.json` – zdieľané ID, enumy a value objects;
- `inspection.schema.json` – normalizované fakty, observations, evidence a provenance;
- `diagnosis.schema.json` – diagnostické issues, hypotheses, impacts, verifications, recommendations, link objects a QA;
- `report-package.schema.json` – manifest nemennej verzie reportového balíka.

Lokálne `$ref` sú relatívne, aby schémy fungovali spolu aj bez siete. `$id` poskytuje stabilnú kanonickú identitu. Kritické doménové objekty používajú `additionalProperties: false`.

## Dve vrstvy validácie

JSON Schema je normatívny štruktúrny kontrakt. Python standard library neobsahuje Draft 2020-12 validator a repository zámerne nepridáva externú dependency. `tools/diagnostics_lint.py` preto nie je všeobecný JSON Schema engine. Kontroluje iba cross-file a doménové invarianty, ktoré samotná schema nevie spoľahlivo vyjadriť.

Pre budúce CI je vhodné pridať samostatný, verziovo uzamknutý Draft 2020-12 validator, napríklad `check-jsonschema` alebo oficiálne podporovaný validator zvoleného CI prostredia. Toto rozhodnutie patrí do CI/tooling kroku, nie do produkčného runtime.

## Lokálne použitie

```text
python tools/diagnostics_lint.py --inspection path/to/inspection.json --diagnosis path/to/diagnosis.json --report-package path/to/manifest.json
python tools/test_diagnostics_contracts.py
```

`--diagnosis` a `--report-package` sú voliteľné. Diagnosis vyžaduje inspection, aby bolo možné overiť odkazy na observations a evidence.

Exit codes:

- `0` – bez schema/domain errors; warnings sú dovolené;
- `1` – schema-shape alebo domain errors;
- `2` – tool/input failure, napríklad nečitateľný alebo neplatný JSON.

Lint vypisuje stabilné `E_*` a `W_*` kódy v sekciách `errors` a `warnings`.

## Doménové invarianty mimo JSON Schema

Najmä unikátnosť ID a SafetyCulture idempotency keys, referenčná integrita, presne sedem impact dimensions, poradie nákladov, dependency cycles, actor role pri approval a nemennosť publikovaného adresára sú kontrolované domain lintom alebo budúcou persistence/publish vrstvou.

Schema `default` hodnoty nie sú runtime mutácie. Privacy je preto vo fixtures aj dokumentoch vždy uvedená explicitne; žiadny klientsky obsah sa implicitne nepovažuje za public.
