# Poznámky k budúcej migrácii legacy portálu

## Stav v kroku 2

Krok 2 neprináša produkčnú migráciu. Existujúci runtime `data/inspections.json`, `api/inspections.php`, `inspekcie.html`, `inspekcie-admin.html` a stavy `draft / ready / sent` zostávajú nedotknuté. Nové schemas, fixtures a lint sú vývojový kontrakt a nemenia klientsky portál.

Legacy portál sa nesmie automaticky považovať za diagnosis source of truth. Jeho záznam opisuje odovzdanie klientskych médií, nie auditovateľné diagnostické uvažovanie.

## Stav po kroku 3

Nový modul `api/lib/diagnostics/` pridáva izolované filesystem úložisko pre budúce `inspection.json`, `diagnosis.json` a immutable report packages. Nie je migrátorom a nečíta ani nemení `data/inspections.json`. Existujúci `api/inspections.php`, `inspekcie.html`, `inspekcie-admin.html` a stavy `draft / ready / sent` zostávajú nedotknuté.

Storage install akceptuje iba package, ktorého manifest už nesie stav `published` a approval/publish metadata. Tieto polia nesmú vzniknúť automatickým mapovaním legacy `ready` alebo `sent`; vytvorí ich až budúci autorizovaný QA/APPROVE/publish workflow. Nové úložisko zároveň neposkytuje PIN, session ani klientsky media endpoint, preto zatiaľ nie je dôvod presmerovať legacy klientsky portál.

## Čo obsahuje legacy záznam

Aktuálny PHP model pracuje približne s:

- interným ID záznamu;
- title, location, summary a clientEmail;
- statusom draft, ready alebo sent;
- otvoreným klientskym PINom;
- priamymi URL reportu, Google Docs, Panoraven a videí;
- poľom fotografií s number, title, thumb a full;
- created/updated/ready/sent timestamps.

Tieto polia nie sú automaticky observations, evidence alebo diagnostic issues. Najmä summary je klientsky opis odovzdania, nie diagnostický summary issue; názov fotografie nie je potvrdený finding.

## Budúca mapovacia stratégia

Migrácia musí byť samostatný, auditovaný krok:

1. Vytvoriť property snapshot a inspection identity so stabilnými `prop_`/`insp_` ID.
2. Zachovať legacy record ID a pôvod ako provenance/external reference, nie ako nový významový kód.
3. Preniesť dátum, bezpečnú lokalitu a rozsah iba tam, kde je význam jednoznačný; clientEmail uložiť mimo diagnostického kontraktu.
4. Každé legacy médium vytvoriť ako evidence iba po určení typu, privacy, interného media reference a zdrojovej stopy. Verejnú URL nemožno automaticky prevziať pre client_private obsah.
5. Z legacy PDF alebo dokumentov extrahovať observations do `inspection.json` manuálne alebo asistovane, s odkazom na stranu/položku a následnou kontrolou inšpektora.
6. Až samostatné AI/inšpektorské spracovanie vytvorí issues, hypotheses, impacts, verifications, recommendations a links v `diagnosis.json`.
7. Spustiť schema validation a domain lint, vykonať inspector QA a až potom vytvoriť report package 1.0.
8. Zachovať legacy portál iba ako pôvodný delivery record, kým nebude nový publish a access model produkčne pripravený.

## Stavové mapovanie

`draft / ready / sent` sa v kroku 2 nemigruje ani nepremenúva. Pri budúcej migrácii sa nesmie použiť priame 1:1 mapovanie:

- legacy `draft` znamená rozpracovaný delivery záznam, nie nevyhnutne diagnosis draft;
- legacy `ready` spája manuálne potvrdenie, vytvorenie PINu a klientsku dostupnosť; nenahrádza samostatné QA passed, approved a published;
- legacy `sent` znamená úspešné odoslanie emailu; nie je report version status.

Budúci migrátor musí vytvoriť samostatný diagnostický processing status, report version status a access-delivery status. Legacy timestamp možno zachovať ako historickú udalosť, nie ako nový approval timestamp bez identity approvera a snapshotu.

## Dáta bez priameho diagnostického ekvivalentu

- `clientEmail` patrí do klientského/access modelu mimo schemas;
- otvorený `pin` sa nesmie preniesť do diagnostických JSON ani manifestu;
- `docsUrl`, `panoravenUrl`, YouTube URL a priame upload URL potrebujú samostatné bezpečnostné rozhodnutie;
- email text a `sentAt` patria do delivery/audit vrstvy;
- legacy `summary` a title sú nanajvýš migračný kontext;
- status ready/sent nemá priamy report version ekvivalent.

## Nové entity vznikajúce až spracovaním

Legacy model neobsahuje a migrácia nesmie automaticky predstierať:

- normalizované observations a ich provenance;
- evidence privacy a auditovateľné observation links;
- diagnostic issues a sedem impact dimensions;
- hypotheses so supporting/contradicting evidence a missing information;
- verifications;
- samostatné recommendations a dependency graph;
- issue relations;
- QA metadata, approver identity a acknowledgements;
- immutable report version manifest a checksums.

Tieto objekty vzniknú až po AI-assisted návrhu a kontrole inšpektorom. Automatizácia môže vytvoriť draft, nikdy nie potvrdenú príčinu alebo `APPROVE`.

## Predpoklady pred produkčnou migráciou

- schválený storage a private-media model;
- hashovaný PIN a serverová session;
- rate limiting a audit;
- produkčný Draft 2020-12 validator;
- mapovacie a rollback pravidlá;
- záloha legacy dát a test obnovy;
- pilotná manuálna migrácia anonymizovaného prípadu;
- explicitné rozhodnutie, či sa legacy odkazy archivujú, proxyujú alebo nahradia.
