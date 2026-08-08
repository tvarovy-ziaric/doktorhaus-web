# Invalid domain fixtures

Tieto súbory sú určené pre `tools/diagnostics_lint.py`. Každý cieli na invariant, ktorý všeobecný JSON Schema nevie vyjadriť alebo ktorý domain lint kontroluje aj bez externého schema enginu.

| Fixture | Vstup | Očakávaný stabilný kód |
|---|---|---|
| `dangling-reference.json` | diagnosis + `valid/inspection-minimal.json` | `E_DANGLING_REFERENCE` |
| `duplicate-id.json` | diagnosis + `valid/inspection-minimal.json` | `E_DUPLICATE_ID` |
| `invalid-cost-range.json` | diagnosis + `valid/inspection-minimal.json` | `E_COST_RANGE` |
| `invalid-impact-count.json` | diagnosis + `valid/inspection-minimal.json` | `E_IMPACT_DIMENSIONS` |
| `dependency-cycle.json` | diagnosis + `valid/inspection-minimal.json` | `E_DEPENDENCY_CYCLE` |
| `invalid-report-approval.json` | report package + `valid/inspection-minimal.json` | `E_REPORT_APPROVAL` |

Test vyžaduje exit code `1` a prítomnosť uvedeného kódu. Voľný text správy nie je testovací kontrakt; môže sa spresňovať bez zmeny kódu.
