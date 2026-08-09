# Repository instructions for AI/Codex

For any task that touches the DoktorHaus diagnostics/reporting system, read these files before proposing or implementing changes:

1. `docs/diagnostics/PROJECT_MEMORY.md`
2. `docs/diagnostics/DIAGNOSTIC_RULES.md`
3. `docs/diagnostics/DATA_MODEL.md`
4. `docs/diagnostics/WORKFLOW.md`
5. `docs/diagnostics/INSPECTOR_QA_WORKFLOW.md`
6. `docs/diagnostics/OBSERVATION_GRANULARITY.md`
7. `docs/diagnostics/SPATIAL_GROUPING_RULES.md`
8. `docs/diagnostics/INSPECTOR_ASSERTION_RULES.md`
9. the relevant security/storage/client-delivery documents for the task

Treat these documents as durable product context. Do not silently replace their decisions with assumptions from chat history or general knowledge.

Key invariants:

- source observations/evidence remain distinct from diagnostic interpretation;
- AI may create drafts, but human inspector `APPROVE` is mandatory;
- renderer is deterministic and must not invent or rewrite diagnosis;
- diagnostic issues may aggregate observations, but aggregation must never destroy source/evidence detail;
- spatial locality is part of the meaning: observations from different walls/details must not be merged merely because they are in the same room or checklist section;
- when no floor plan exists, preserve relative spatial anchors rather than inventing geometry or orientation;
- detail-heavy domains such as roof trusses require element/detail-level observations and evidence even when client-facing issues are grouped;
- human QA is planned as a structured, audit-friendly form; `not verified` is a valid answer;
- absence of monitoring/evidence is not a negative finding: `unconfirmed/unknown` must not be silently converted to `no/stable`;
- future AI discussion inside QA must resolve to explicit structured inspector input before diagnosis is revised;
- real client inspection data, photos, addresses, PINs, grants and private report packages must never be committed to this public repository.

If a new product decision changes these invariants or the inspector workflow, update the durable documentation in the same change before implementing the behavior.
