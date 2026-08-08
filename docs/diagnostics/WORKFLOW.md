# Diagnostický workflow

## Zásady workflow

- Zdrojové dáta sa uchovávajú bez prepisovania.
- Automatizácia môže vytvoriť iba draft; diagnózu a publikovanie schvaľuje inšpektor.
- Každý prechod má vstup, výstup, validáciu a zodpovednú rolu.
- `APPROVE` je manuálna, auditovaná brána viazaná na konkrétnu report version.
- Klient nikdy nevidí draft, nevalidné dáta ani neschválené médiá.
- Opakovaný import nesmie duplikovať objekty alebo potichu prepisovať odborné úpravy.

## MVP workflow

`SafetyCulture inspection → PDF export → diagnostické spracovanie → inspection.json → diagnosis.json → validation → inspector QA → APPROVE → publish → client PIN`

Tento tok je zámerne manuálny a nevyžaduje SafetyCulture API.

### 1. SafetyCulture inspection

Terénny pracovník dokončí inspection v SafetyCulture. Checklist, flagged items, poznámky, merania a fotografie sú raw observations/evidence, nie hotová diagnóza.

Výstupná podmienka: inspection má jednoznačný zdrojový identifikátor, dátum, objekt a zodpovedného inšpektora.

### 2. PDF export

Vytvorí sa pôvodný PDF export a zhromaždia sa dostupné médiá. Originál sa uloží ako nemenný source artifact s názvom, časom importu a ideálne checksumom. Ak PDF neobsahuje originálne médiá alebo úplné metadáta, missing information to prizná.

Výstupná podmienka: zdroj je dohľadateľný a oddelený od pracovných diagnostických súborov.

### 3. Diagnostické spracovanie

Inšpektor alebo asistovaný nástroj:

- prepíše/normalizuje fakty ako observations;
- priradí evidence a source references;
- zoskupí súvisiace prejavy do diagnostic issues;
- vytvorí alternatívne hypotheses;
- uvedie supporting/contradicting evidence a missing information;
- navrhne verifications, scores, impacts, cost ranges a recommendations;
- vytvorí dependencies a poradie krokov.

Asistovaný návrh nesmie meniť observation na potvrdenú príčinu a nesmie udeliť `APPROVE`.

### 4. `inspection.json`

Kanonický pracovný súbor zdrojovej/normalizovanej vrstvy podľa `schemas/inspection.schema.json`. Obsahuje výhradne:

- schema/version metadata, property snapshot a inspection metadata;
- scope a limitations;
- lightweight actors relevantných pre zber;
- observations;
- evidence metadata a privátne media references;
- explicitné observation ↔ evidence link objects;
- provenance a import metadata.

Neobsahuje diagnostické príčiny, hypotheses, diagnostické scores, recommendations, report copy, klientsky PIN, session ani secrets.

### 5. `diagnosis.json`

Kanonický pracovný súbor diagnostickej vrstvy podľa `schemas/diagnosis.schema.json`. Obsahuje:

- diagnostic issues;
- hypotheses a ich evidence roles;
- verifications;
- presne sedem impact objects na každý issue ako source of truth;
- scoring rationale;
- cost model;
- recommendations a dependencies;
- issue relations a všetky explicitné many-to-many link objects;
- QA metadata a diagnostic status.

Referencie na inspection, observations a evidence musia byť validné. Klientsky render nie je zdroj pravdy.

### 6. Validation

Strojová validácia kontroluje syntaktické a doménové podmienky, najmä:

- povinné polia a enum hodnoty;
- unikátne ID a existujúce referencie;
- vlastnícke hranice inspection/property;
- min ≤ expected ≤ max a prítomný scope/confidence;
- povinné score osi a rationale;
- supporting/contradicting role evidence;
- platné recommendation dependencies bez cyklov;
- report completeness podľa `REPORT_CONTRACT.md`;
- zákaz client-private public URL;
- konzistenčné upozornenia zo `SCORING_RULES.md`.

Validácia má tri triedy:

- schema error: blocking pre chybný JSON, required field, enum, type, ID pattern alebo štruktúru;
- domain error: blocking pre approve/publish, napríklad dangling reference, duplicate ID/idempotency key, neplatný cost range, impact dimensions, dependency cycle alebo approval;
- domain warning: neblokuje exit code, ale vyžaduje QA vyhodnotenie, napríklad S5 bez U1/P1 alebo critical impact bez primeranej akcie.

`tools/diagnostics_lint.py` vypisuje polia `errors` a `warnings` so stabilnými kódmi. Exit code 0 znamená bez errors, 1 schema/domain errors a 2 tool/input failure. Warnings môžu byť štruktúrovane acknowledged; blocking errors nie.

### 7. Inspector QA

Inšpektor porovná diagnostický draft s originálnym PDF/médiami a skontroluje:

- že všetky zásadné observations majú zdroj;
- že zoskupenie flagged items nestratilo dôležitý prejav;
- že hypotézy, confidence a contradicting evidence sú poctivé;
- že scores, risk a cost escalation majú rationale;
- že recommendations sú realizovateľné a správne zoradené;
- že náklady nemajú falošnú presnosť;
- že text rešpektuje rozsah inšpekcie;
- že report neobsahuje cudzie alebo verejne prístupné privátne médiá.

QA môže vrátiť draft na úpravu. Samotné spustenie validátora nie je QA.

### 8. `APPROVE`

Oprávnený inšpektor schváli presný snapshot `inspection.json`, `diagnosis.json` a zostaveného reportu. Záznam obsahuje approver, timestamp, verziu a identifikátor/hash schváleného obsahu.

Po approve sa obsah tejto verzie neupravuje. Zmena vyžaduje novú draft verziu a nový QA/APPROVE cyklus.

### 9. Publish

Server sprístupní iba approved report version a jej povolené médiá. Publish zaznamená čas, publikovanú verziu a access policy. Vrátenie pracovnej inspection do draftu nesmie potichu meniť už publikovaný snapshot; withdraw je samostatná auditovaná akcia.

Od kroku 3 môže storage vrstva nainštalovať iba manifest so stavom `published`, s `approved_by`, `approved_at` a `published_at`. Predpokladá, že oprávnený workflow už vytvoril tieto auditné fakty; sama ich nevytvára. Balík kopíruje do stagingu, znovu overí paths, symlinky, veľkosti a SHA-256 a atomicky ho premenuje na novú `reports/<report-id>/<version>` cestu. Existujúca verzia sa nikdy neprepisuje.

Doménový lint, plná schema validácia, inspector QA a auditovaný `APPROVE` musia prebehnúť pred volaním storage install. Úspešný install ešte neznamená klientsky prístup: auth/session a autorizované HTTP doručenie zostávajú samostatný budúci krok.

### 10. Client PIN

Po publish sa vydá alebo aktivuje klientsky prístup. Server uloží hash PINu, aplikuje rate limiting a po úspešnom overení vytvorí scope-limited session pre konkrétny report. PIN sa neposiela spolu s reportovým obsahom v jednom verejnom kanáli bez vedomého rozhodnutia.

Klient vidí verziu, change summary, report a autorizované médiá. Prístup možno expirovať, odvolať alebo regenerovať bez úpravy reportu.

## Pracovné stavy a mapovanie dnešného prototypu

Kontrakt 1.0.0 používa malé explicitné lifecycle enumy:

- inspection processing: imported, normalized, diagnostic_draft, qa_pending, qa_blocked, approved;
- diagnosis: draft, qa_pending, qa_blocked, approved, superseded;
- observation: active, corrected, superseded;
- evidence: active, superseded, withdrawn;
- hypothesis: proposed, under_verification, supported, contradicted, inconclusive;
- verification: proposed, scheduled, completed, not_feasible, declined;
- recommendation: proposed, approved, completed, deferred, cancelled;
- links/relations: active, superseded, rejected;
- report: draft, active, closed;
- report version: draft, in_review, approved, published, superseded, withdrawn.

Aktuálny kód pozná iba `draft`, `ready`, `sent`. `ready` dnes zároveň generuje PIN a umožňuje client unlock, teda spája approve, publish a credential issuance. `sent` vyjadruje úspešné odoslanie emailu, nie verziu reportu. Pri budúcej implementácii treba tieto významy migrovať explicitne, nie iba premenovať enum.

## Oprava, doplnenie a follow-up

- Doplnené meranie alebo evidence vytvorí pracovnú revíziu a po QA typicky report v1.1.
- Významná následná inspection alebo kontrola po sanácii má vlastnú inspection a typicky report v2.0.
- Nové evidence môže zvýšiť aj znížiť confidence, podporiť inú hypotézu alebo zmeniť priority.
- Pôvodné observations a publikované verzie zostávajú dohľadateľné; chyba zdroja sa označí korekciou/supersede, nie tichým prepisom.
- Completed recommendation sa overí novým evidence alebo follow-up inspection; samotné označenie klientom nepotvrdzuje technický výsledok.

## Budúci automatizovaný workflow

`SafetyCulture completed → API/webhook → raw inspection data + media → diagnostic draft → inspector QA → publish`

### Ingest

- Webhook sa autentifikuje a overí integritu podľa možností SafetyCulture.
- Udalosť sa spracuje idempotentne podľa source inspection/event ID.
- Raw payload a media metadata sa uložia bez prepisu s received timestamp a verziou integrácie.
- Neúspešné stiahnutie médií alebo neúplný payload vytvorí retry/dead-letter stav a viditeľné missing information.

### Normalizácia a draft

- Adapter mapuje SafetyCulture polia na kanonické property/inspection/observation/evidence objekty.
- Zmena šablóny alebo neznáme pole sa zaloguje a nesmie sa potichu zahodiť.
- Automatizácia môže navrhnúť grouping, hypotheses a recommendations iba ako diagnostic draft so zdrojovou stopou.
- Re-import porovná source version a vytvorí kontrolovanú revíziu; nesmie prepisovať schválené odborné dáta.

### QA a publish

Rovnaké validation, inspector QA, APPROVE, versioning, publish a security pravidlá platia aj pri API importe. Webhook nikdy priamo nepublikuje klientsky report ani nevydáva PIN.

## Zlyhania a návratové body

- Chýbajúci/poškodený export: zastaviť normalizáciu a vyžiadať zdroj.
- Neplatná referencia alebo dependency cycle: validation fail.
- Významný diagnostický konflikt: vrátiť do draftu alebo publikovať iba s explicitným unverified stavom, ak to QA dovolí.
- Chýbajúci externý posudok pri kritickom scenári: report môže odporučiť okamžité opatrenie a VERIFY, ale nesmie tvrdiť výsledok posudku.
- Chyba publish: approved verzia zostáva approved, nie automaticky published.
- Chyba odoslania PINu: report môže zostať published, ale access delivery má samostatný stav a retry.
- Zistenie úniku média: revoke access, withdraw podľa rozsahu, audit incidentu; samotná zmena URL nestačí ako úplná náprava.

## Zostávajúce workflow rozhodnutia

- spôsob práce s re-importom a konfliktom už existujúcich ručných diagnostických úprav;
- hranica, kedy možno neúplný report publikovať s limitations po uzavretí warnings;
- fyzické oddelenie publish state a access-delivery state v budúcom backende;
- konkrétna retencia raw exportov, médií a superseded versions;
- atomický publish/rollback immutable report package;
- výber plného Draft 2020-12 validátora pre budúce CI.
