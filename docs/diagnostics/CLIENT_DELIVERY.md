# Client-safe report a autorizované médiá

Krok 4B pridáva serverové doručenie jednej nemennej publikovanej verzie, na ktorú je viazaná session z kroku 4A. Nevytvára nový diagnostický source of truth. Autoritatívne dokumenty zostávajú `inspection.json`, `diagnosis.json` a `manifest.json`; `client_report` je deterministická allowlist projekcia vytvorená pri requeste.

## Hranica raw source a client projection

Autentifikovaný klient nikdy nedostane raw inspection, diagnosis ani manifest. `DiagnosticsClientProjection` skladá každé výstupné pole explicitne. Nové source pole sa preto bez vedomého rozšírenia `client-report.schema.json` a projekcie klientovi nezobrazí.

Top-level kontrakt `client_report` verzie `1.0.0` je:

```text
schema_version
document_type
report
property
inspection
overview
issues
recommendations
verifications
issue_relations
unverified_items
generated_at
```

`report` obsahuje iba version, change type/summary, published timestamp, renderer contract version a voliteľný approved timestamp. Neobsahuje report ID, report-version ID, package hash ani approver actor ID. Property používa privacy-minimizing označenie, typ, krajinu, región a voliteľne obec/okres; presná súkromná adresa a property ID sa nevydávajú. Inspection obsahuje iba type, performed timestamp, scope a limitations.

Overview je mechanický count/rank súhrn. Negeneruje text, house score, percento kvality ani farebný verdikt. Náklady sa nesčítavajú naprieč issues.

## Client-visible issue polia

Issue allowlist je:

```text
id, display_code?, title, category, category_label?, affected_areas,
summary, interpretation?, severity, severity_rationale,
likelihood, likelihood_subject, likelihood_subject_kind, likelihood_rationale,
urgency, urgency_rationale, priority, priority_rationale, confidence,
deterioration_rate, deterioration_rationale,
short_term_risk, long_term_risk, cost_estimate, cost_escalation, status,
limitations, missing_information, observations, evidence, hypotheses, impacts,
verification_ids, recommendation_ids
```

`superseded` issue sa filtruje. `active`, `monitoring` a `resolved` zostávajú so svojím stavom. Každý viditeľný issue musí mať presne sedem pôvodných impact dimensions; chýbajúca, duplicitná alebo ôsma dimension zastaví projekciu.

Observation current view povoľuje iba source observation so stavom `active` cez aktívny issue-observation link. `corrected` a `superseded` sa v 4B nezobrazujú, pretože kontrakt nežiada historický timeline. `observed_at`, actor, provenance a source metadata sa nevydávajú. Measurement je samostatný allowlist bez `instrument_id`.

Hypothesis zobrazuje statement, mechanism, confidence a status. Source `rationale` sa v 4B nevydáva: existujúci report contract ho neoddeľuje na klientsky odborný dôvod a interný pracovný reasoning. Projekcia negeneruje ani nesumarizuje náhradný text.

Missing information stráca interné relation ID a hypothesis linky. Top-level `unverified_items` je iba odvodené zopakovanie rovnakých client-safe polí s opaque `issue_id`; nepridáva nový odborný text.

## Centrálne zakázané interné polia

Testy rekurzívne odmietajú minimálne:

```text
qa, actors, actor_ids, approved_by, observed_by, captured_by, performed_by,
provenance, import_metadata,
source_system, source_inspection_id, source_item_id, source_media_id,
source_reference, source_hash,
pin, pin_hash, csrf_token, session_id,
report_id, report_version_id, package_manifest_sha256,
media_reference, sha256, address_private, storage_path, filesystem_path
```

Rovnako sa nevydávajú link audit metadata, approver/creator actor IDs, raw package role/path/hash, cost `source_method` ani private address lines.

## Evidence visibility

Evidence sa dostane do issue iba v tomto prieniku:

1. issue je client-visible;
2. evidence má `status == active`;
3. evidence privacy je `public` alebo `client_private`;
4. existuje aktívny `issue_evidence_link` na tento issue, alebo aktívny `observation_evidence_link` na aktívnu observation, ktorá je s issue spojená aktívnym `issue_observation_link`.

`internal` evidence sa neobjaví ani názvom, ID alebo metadátami. `superseded`/`withdrawn` a orphan client-private evidence sa správajú rovnako ako neexistujúce. Projekcia si drží presný `clientVisibleEvidenceIds` set; media endpoint neautorizuje iba podľa existencie v `inspection.evidence`.

Evidence môže byť viditeľný textový záznam s `has_media: false`. `has_media: true` a `media_url` vzniknú iba vtedy, keď jeho interný `media_reference` ukazuje na deklarovaný manifest file roly `media` alebo `attachment` s privacy `public|client_private`. Roly `source_report`, `inspection_data`, `diagnosis_data` a `other` sa nevydávajú. URL má jediný selector:

```text
api/diagnostics-media.php?evidence=ev_<opaque-id>
```

Neobsahuje report, version ani path.

## Report endpoint

`api/diagnostics-report.php` podporuje iba `GET` bez query parametrov. Tok je security headers → config/storage/access → serverová session → `current()` → package snapshot overený bindingom → projekcia → audit → `session_write_close()` → JSON.

Autorizácia používa iba report ID/verziu z validnej session. Access ID, PIN, report, version ani path nemožno zvoliť query parametrom. Každý query selector je 400. Chýbajúca, expirovaná, revokovaná, generation-invalid alebo package-mismatch session dostane rovnakú 401 odpoveď. Nepodporovaná metóda je 405 s `Allow: GET`; storage/config sú generic 503 a interná projection chyba generic 500.

Úspešný request má JSON UTF-8, `Cache-Control: no-store, private`, `Pragma: no-cache`, `Vary: Cookie`, `nosniff`, `no-referrer`, `DENY` a same-origin resource policy. Wildcard CORS sa neposiela.

## Media endpoint a BOLA ochrana

`api/diagnostics-media.php` podporuje `GET` a `HEAD`; jediný parameter je evidence ID s patternom `^ev_[0-9a-f]{16,32}$`. Report, version, filename a storage path vždy určuje session a overený package snapshot, nikdy klient.

Validný, ale unknown/internal/withdrawn/orphan/unlinked/no-media evidence ID má jednotnú 404 odpoveď `Media not found.`. Neodhaľuje sa, ktorá vnútorná podmienka zlyhala. Neplatná syntax alebo iný query shape je 400. Tým je zabránené BOLA cez report/version override, hádanie pomocného evidence ID aj traversal/path selector.

Pred streamingom sa overí session, package binding, client-visible evidence set, manifest role/privacy, bezpečné resolved umiestnenie, size a Range. Audit sa musí úspešne zapísať; report aj media delivery pri audit failure zlyhajú zatvorene. Po autorizácii a audite sa uloží `last_seen_at` a uvoľní PHP session lock cez `session_write_close()` ešte pred headers/body streamu. Dlhé video preto nedrží session lock pre ďalšie report/media/auth requesty.

## MIME a Content-Disposition

Safe inline allowlist je presne:

```text
image/jpeg, image/png, image/webp,
video/mp4, video/webm,
application/pdf,
audio/mpeg, audio/mp4, audio/ogg
```

`text/html`, JavaScript, SVG, XHTML, XML a každý unknown MIME sa posiela ako `application/octet-stream` s `attachment`. Názov je vždy generovaný z už validovaného ID a fixného MIME mapovania, napríklad `doktorhaus-ev_….jpg` alebo `.pdf`; evidence title ani storage path nevstupujú do HTTP headeru.

Client-private media používa `no-store, private`, `Pragma: no-cache`, `Vary: Cookie`, `no-referrer`, `nosniff` a same-origin resource policy. Public caching ani cross-origin delivery sa v 4B nezavádza.

## Byte Range a streaming

Podporovaný je celý súbor (200) a jeden byte range (206): bounded `bytes=0-999`, open-ended `bytes=1000-` a suffix `bytes=-500`. End nad veľkosťou sa bezpečne oreže. Invalidný, pretečený, obrátený, prázdny alebo neuspokojiteľný range a multiple ranges vracajú 416 s `Content-Range: bytes */<size>`.

Úspešná čiastočná odpoveď má `Accept-Ranges`, `Content-Range` a presný `Content-Length`; plná odpoveď má `Accept-Ranges` a celú veľkosť. `HEAD` vracia rovnaké metadata bez body. Binary sa číta cez `fopen`/`fseek`/`fread` v 64 KiB chunks, nie cez whole-file `file_get_contents`. Po začatí body sa už neposiela JSON chyba; stream sa ukončí a server zaloguje iba generickú technickú správu.

## Audit

4B pridáva:

- `report_viewed`: jeden úspešný GET reportu = jedna udalosť;
- `media_accessed`: úspešná autorizovaná media odpoveď s metadata `evidence_id` a `request_type` (`full`, `range`, `head`).

Audit obsahuje access ID, report ID/verziu a HMAC pseudonymy IP/user agentu. Neobsahuje diagnostický content, byte range, raw IP, session, PIN, filename, path ani `media_reference`. `media_denied` sa v 4B zámerne neloguje, aby enumerácia a opakované 404 nevytvorili log flood.

## Integrita a výkon

`DiagnosticsClientSession::current()` zachováva 4A invariant: grant je viazaný na presný manifest hash a storage vykoná úplnú package hash/size verifikáciu. Overený package snapshot sa následne v rovnakom requeste jednorazovo spotrebuje projekciou a resolverom, takže veľké médium sa v jednom requeste nehashuje druhýkrát.

Reziduálny výkonový limit zostáva: každý nový Range request cez 4A package binding stále raz hash-uje celý package, teda aj všetky veľké médiá v ňom. Je to bezpečné, ale pri multi-GB balíkoch drahé. V 4B sa integrity kontrola nevypína ani necacheuje na disk. Budúci redesign môže zaviesť dôveryhodný immutable verification index/cache viazaný na manifest hash a invalidovaný mimo requestu; musí zachovať TOCTOU a package-binding model. Storage root naďalej predpokladá výhradné vlastníctvo aplikačným používateľom.

## Overenie a zostávajúci rozsah

`tools/test_diagnostics_client_projection.php` pokrýva allowlist, denylist, privacy/link filtering, dependency order/cycle, issue relations, sedem impacts, no-new-inference invariant, Range parser, MIME a statický chunk/session-lock check. `tools/test_diagnostics_delivery_http.sh` používa iba temp storage a syntetický 6 MiB súbor; overuje report/media auth, headers, revoke/rotation, internal/orphan 404, full/HEAD/ranges/416, safe/unsafe MIME a audit privacy.

Po 4B máme secure authentication, client-safe report API a authorized media delivery. Stále nemáme klientsky renderer ani finálny UX, zmenu `inspekcie.html`, backoffice grant issuance UI, SafetyCulture adapter, production deployment config/PHP verification, backup/restore policy, Babiná production report, PDF generátor, legacy PIN migráciu, databázu, AI diagnostiku ani automatické finančné agregácie.
