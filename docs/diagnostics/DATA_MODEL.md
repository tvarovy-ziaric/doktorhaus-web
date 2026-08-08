# Koncepčný dátový model

## Zásady

- Model je koncepčný. Neurčuje databázové tabuľky ani JSON Schema.
- Každý objekt má stabilné, neprázdne `id`, ktoré sa nemení medzi úpravami. Formát ID je otvorené rozhodnutie.
- Zdrojové fakty a odborné závery sú oddelené. Text reportu nie je primárnym úložiskom diagnózy.
- Referencie musia smerovať na objekt v rovnakom vlastníckom kontexte, pokiaľ dokument výslovne nepovoľuje vzťah naprieč inšpekciami.
- Odstránenie objektu s referenciami nesmie potichu vytvoriť neplatné väzby. Produkčné pravidlá archivácie sa určia neskôr.

## Vlastnícky strom

`property` je koreň dlhodobej evidencie nehnuteľnosti. `inspection` patrí jednej property a predstavuje konkrétnu obhliadku alebo následnú kontrolu. Observation, evidence a diagnostické objekty patria inspection, aby bolo zrejmé, pri akej udalosti vznikli. Report patrí inspection a obsahuje verzie publikovaného výstupu.

Property môže mať viac inspections. Následná inspection môže odkazovať na predchádzajúcu, ale jej pozorovania a dôkazy zostávajú samostatné.

## Entity

### `property`

Predstavuje nehnuteľnosť nezávisle od jednej obhliadky.

Minimálny význam polí:

- `id`: stabilný interný identifikátor;
- `display_name`: bezpečné interné pomenovanie;
- `property_type`: typ objektu z dohodnutého číselníka;
- `location`: štruktúrovaná alebo primerane obmedzená lokalita;
- `external_references`: identifikátory v zdrojových systémoch;
- `created_at`, `updated_at`: technické časové údaje.

Presná adresa, vlastník a kontaktné údaje sú súkromné údaje. Do reportu sa premietajú iba v potrebnom rozsahu.

### `inspection`

Jedna vykonaná obhliadka alebo následná kontrola.

- `id`, `property_id`;
- `inspection_type`: initial, follow_up, post_repair alebo budúci číselník;
- `performed_at`, `inspectors`;
- `scope`: čo bolo predmetom kontroly;
- `limitations`: neprístupné časti, podmienky a obmedzenia metódy;
- `source_system`, `source_inspection_id`, `source_imported_at`;
- `status`: pracovný stav spracovania, nie klientsky risk;
- `previous_inspection_id`: voliteľná väzba na predchádzajúcu kontrolu;
- `created_at`, `updated_at`.

### `observation`

Priamo pozorovaný, zmeraný alebo zo zdroja prevzatý fakt bez tvrdenia o príčine.

- `id`, `inspection_id`;
- `statement`: vecný opis toho, čo bolo vidieť alebo zmerať;
- `observation_type`: visual, measured, reported, document alebo dohodnutý číselník;
- `area`: miesto alebo stavebná časť;
- `observed_at`, `observed_by`;
- `measurement`: voliteľná hodnota, jednotka, metóda a podmienky;
- `source_reference`: odkaz na SafetyCulture položku, PDF stranu alebo iný pôvod;
- `limitations`: čo pozorovanie nevie potvrdiť;
- `status`: active, corrected, superseded alebo budúci číselník.

Jedno pozorovanie môže podporovať viac issues. Jeden issue môže zoskupovať viac observations.

### `evidence`

Dohľadateľný nosič alebo záznam, ktorý možno pripojiť k pozorovaniu, hypotéze, issue alebo overeniu.

- `id`, `inspection_id`;
- `evidence_type`: photo, video, document, measurement, sensor_series, note alebo iný číselník;
- `title`, `description`;
- `captured_at`, `captured_by`;
- `source_reference` a voliteľný checksum/verzia zdroja;
- `media_reference`: interný privátny identifikátor, nie nutne verejná URL;
- `metadata`: typ súboru, rozmery, jednotky alebo technické údaje;
- `privacy`: klasifikácia prístupu;
- `status`.

Evidence nie je automaticky dôkazom príčiny. Význam supporting alebo contradicting vzniká až vo väzbe ku konkrétnemu tvrdeniu.

### `diagnostic_issue`

Odborne zoskupený problém, ktorý môže združovať viac prejavov jednej súvislosti. Pätnásť flagged položiek môže vytvoriť päť issues.

Povinné doménové polia:

- `id`, `inspection_id`;
- `title`, `category`, `affected_area`, `summary`;
- `severity`: S1–S5;
- `likelihood`: L1–L5;
- `urgency`: U1–U5;
- `priority`: P1–P5;
- `confidence`: miera istoty diagnostického tvrdenia;
- `deterioration_rate`: stable, slow, progressive, rapid, unknown;
- `short_term_risk_level`, `short_term_risk_description`;
- `long_term_risk_level`, `long_term_risk_description`;
- `safety_impact`, `structural_impact`, `moisture_impact`, `health_impact`, `durability_impact`, `usability_impact`, `financial_impact`;
- `cost_escalation_risk`;
- `estimated_cost_min`, `estimated_cost_expected`, `estimated_cost_max`, `cost_estimate_confidence`;
- `status`.

K nákladom patria aj `cost_assumptions`, `cost_scope`, `cost_exclusions` a mena. Skóre musí mať odôvodnenie alebo odkaz na hodnotené fakty. Issue neobsahuje príčinu ako potvrdený fakt, ak ju nepotvrdzuje stav hypotézy a evidence.

### `hypothesis`

Jedno možné vysvetlenie mechanizmu. Jeden issue môže mať viac konkurenčných alebo súbežných hypotéz.

- `id`, `diagnostic_issue_id`;
- `statement`: opatrne formulované možné vysvetlenie;
- `mechanism`: technický mechanizmus;
- `confidence`;
- `status`: proposed, under_verification, supported, contradicted, inconclusive alebo budúci číselník;
- `rationale`: prečo sa hypotéza zvažuje;
- `missing_information`: čo chýba na lepší záver;
- väzby na supporting evidence a contradicting evidence.

Stav `supported` nie je automaticky `confirmed`. Podmienky potvrdenia sa riadia [DIAGNOSTIC_RULES.md](DIAGNOSTIC_RULES.md).

### `verification`

Vykonané alebo navrhované overenie diagnostického tvrdenia.

- `id`, `inspection_id`;
- `verification_type`, `method`, `purpose`;
- `status`: proposed, scheduled, completed, not_feasible, declined alebo budúci číselník;
- `performed_at`, `performed_by`;
- `result_summary`;
- `limitations`;
- `specialist_required` a požadovaný odbor;
- väzby na overované hypotheses/issues a vzniknuté evidence.

Verification nie je recommendation: opisuje, čo a ako sa má overiť alebo bolo overené; recommendation nesie rozhodovaciu akciu pre klienta.

### `recommendation`

Samostatná akcia odporúčaná klientovi.

- `id`, `inspection_id`;
- `type`: IMMEDIATE, VERIFY, REPAIR, MONITOR, MAINTENANCE, DOCUMENT;
- `title`, `description`, `rationale`;
- `status`: proposed, approved, completed, deferred, cancelled alebo budúci číselník;
- `target_timeframe` a voliteľné poradie;
- `responsible_specialty`: typ vhodného realizátora alebo špecialistu;
- `acceptance_or_follow_up`: ako overiť vykonanie;
- väzby na issues a voliteľne hypotheses/verifications;
- dependencies na iné recommendations.

Dependency je smerová: napríklad odvedenie dažďovej vody musí predchádzať monitoringu vlhkosti; monitoring môže predchádzať rozhodnutiu o stabilizácii; finálne omietky majú nasledovať až po vyriešení príčiny a overení stavu.

### `issue_relation`

Explicitná smerová alebo symetrická väzba medzi dvoma issues v jednej property.

- `id`, `from_issue_id`, `to_issue_id`;
- `relation_type`: contributes_to, caused_by, aggravates, depends_on, same_mechanism, supersedes alebo budúci číselník;
- `description`, `confidence`;
- supporting evidence;
- `status`.

Samotná relation nesmie predstierať potvrdenú kauzalitu. Typ a confidence musia zodpovedať dostupným dôkazom. Väzba naprieč inspections je dovolená iba pri explicitnej identite property a dohľadateľnom pôvode.

### `impact`

Štruktúrované posúdenie jedného rozmeru dopadu issue. Povinné rozmery sú safety, structural, moisture, health, durability, usability a financial.

- `id`, `diagnostic_issue_id`;
- `dimension`;
- `level`: hodnota z budúceho spoločného číselníka;
- `description`: konkrétny dopad a koho/čo sa týka;
- `time_horizon`: short_term, long_term alebo both;
- `confidence`;
- odkaz na supporting observations/evidence.

Ploché polia na issue sú povinný kontrakt pre jednoduchú spotrebu. Kolekcia impact objektov poskytuje odôvodnenie a časový kontext. Pri implementácii schémy treba určiť, či sa ploché hodnoty generujú z impact objektov alebo naopak; nesmú sa ručne rozchádzať.

### `report` a `report_version`

`report` je identita klientského výstupu pre inspection. `report_version` je nemenný publikovateľný snapshot.

`report`:

- `id`, `inspection_id`;
- `title`, `audience`, `status`;
- `current_published_version_id`;
- `created_at`, `updated_at`.

`report_version`:

- `id`, `report_id`;
- `version`: napríklad 1.0, 1.1, 2.0;
- `change_type`: initial, evidence_update, follow_up, post_repair alebo budúci číselník;
- `change_summary`;
- `based_on_inspection_ids` a snapshot/referencie použitých issues, evidence a recommendations;
- `status`: draft, in_review, approved, published, superseded, withdrawn;
- `approved_by`, `approved_at`, `published_at`;
- `generated_at` a identifikátor verzie kontraktu/renderera, keď bude existovať;
- `limitations_snapshot` a `unverified_items_snapshot`.

v1.0 je prvý schválený výstup. v1.1 dopĺňa meranie alebo dôkaz bez zásadnej zmeny charakteru. v2.0 označuje významnú následnú kontrolu, kontrolu po sanácii alebo podstatnú zmenu záverov. Publikovaná verzia sa neupravuje na mieste.

## Many-to-many väzby

Nasledujúce väzby sú doménovo potrebné, aj keď ich budúci fyzický formát ostáva otvorený:

- observation ↔ evidence: jeden záber alebo meracia séria môže zachytiť viac observations; observation môže mať viac evidence;
- diagnostic_issue ↔ observation: issue zoskupuje viac observations; observation môže byť relevantné pre viac issues;
- diagnostic_issue ↔ evidence: priame podklady k rozsahu alebo následku issue;
- hypothesis ↔ evidence: väzba nesie rolu `supporting` alebo `contradicting` a voliteľné vysvetlenie významu;
- verification ↔ hypothesis a verification ↔ diagnostic_issue: jedno overenie môže rozlišovať viac hypotéz alebo issues;
- verification ↔ evidence: overenie môže použiť vstupné evidence a vytvoriť nové evidence;
- recommendation ↔ diagnostic_issue: jedna spoločná akcia môže riešiť viac issues a issue môže mať viac recommendations;
- report_version ↔ diagnostic_issue/recommendation/evidence: verzia vyberá presný schválený súbor položiek alebo uchová ich snapshot.

Každá väzba má vlastný pôvod, autora/čas vytvorenia, voliteľné odôvodnenie a stav, ak jej význam môže byť predmetom QA.

## Integrita a ownership

- Observation a evidence sú vlastnené inspection; issue ich iba referencuje.
- Hypothesis je vlastnená issue. Jej odstránenie nesmie odstrániť evidence.
- Verification a recommendation sú vlastnené inspection a môžu sa viazať na viac issues.
- Impact je vlastnený issue.
- Report version nesmie odkazovať na pracovný objekt bez zachovania jeho schváleného stavu alebo snapshotu.
- Klientsky prístup patrí reportu/report version, nie property ako celku. PIN nesmie automaticky odomknúť iné inspections tej istej property.
- Kontaktné údaje klienta nepatria do diagnostických tvrdení a majú byť oddelené od publikovaného obsahu.

## Otvorené rozhodnutia pred JSON Schema

- konečný formát ID a externých referencií;
- povolené číselníky kategórií, oblastí a statusov;
- spoločná stupnica `confidence` a impact/risk levels;
- presný model meraní a jednotiek;
- reprezentácia väzieb ako samostatných objektov alebo polí referencií;
- zdroj pravdy medzi plochými impact poľami a kolekciou `impact`;
- spôsob snapshotovania report version;
- pravidlá archivácie, supersede a opravy chybných zdrojových údajov;
- hranica osobných údajov medzi property, inspection a samostatným klientskym profilom.
