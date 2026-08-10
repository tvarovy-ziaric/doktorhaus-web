# Project memory — diagnostická vrstva DoktorHaus

Tento dokument je trvalý rozhodovací záznam projektu. Má zabrániť tomu, aby sa dôležité produktové a odborné rozhodnutia stratili medzi diskusiami, novými chatmi alebo zmenou implementátora.

Pred návrhom významnej zmeny diagnostického workflow, dátového modelu, AI správania, backoffice QA alebo klientského reportu treba prečítať tento dokument spolu s `DIAGNOSTIC_RULES.md`, `DATA_MODEL.md`, `WORKFLOW.md` a relevantnými security kontraktmi.

Ak sa nová diskusia skončí rozhodnutím, ktoré mení fungovanie produktu, rozhodnutie sa má doplniť sem alebo do špecializovaného dokumentu, na ktorý tento dokument odkazuje.

## 1. Úloha AI, človeka a renderera

Záväzný princíp:

`source data → AI diagnostic draft → inspector QA → AI revision → human APPROVE → immutable report → deterministic renderer`

- AI môže normalizovať, zoskupovať, navrhovať hypotézy, scoring, riziká, verifications, recommendations a pracovný text iba ako draft.
- Inšpektor je odborná autorita, ktorá dopĺňa chýbajúci kontext, koriguje AI a udeľuje `APPROVE`.
- Renderer nie je AI. Po schválení iba deterministicky zobrazuje schválený `client_report` kontrakt.
- AI nesmie po `APPROVE` potichu meniť význam reportu pri jeho zobrazovaní.
- Klientský chat nad reportom môže vzniknúť neskôr ako samostatná vrstva, ale nesmie meniť schválený report bez nového QA/revision workflow.

## 2. Human-in-the-loop QA bude primárne formulárový

Cieľový denný workflow inšpektora je formulár, nie copy-paste a nie povinný dlhý chat.

Po vytvorení diagnostického draftu systém vygeneruje iba otázky, ktoré sú relevantné pre konkrétnu inšpekciu. Otázky môžu mať typ:

- áno / nie / neoverené;
- výber z možností;
- číslo + jednotku;
- krátku poznámku;
- odbornú voľnú poznámku;
- dátum;
- neskôr prílohu alebo doplňujúce evidence.

`Neviem / nebolo overené` je legitímna odborná odpoveď a systém nesmie inšpektora nútiť vyrábať istotu, ktorá neexistuje.

Otázky sa delia najmenej na:

1. **Blocks approval** — bez odpovede sa report nemá schváliť.
2. **Improves confidence** — odpoveď zvyšuje kvalitu, ale report môže zostať s explicitnou neistotou.
3. **Provenance / informational** — rieši pôvod dát alebo nekritickú nezrovnalosť.

Podrobný cieľový tok je v `INSPECTOR_QA_WORKFLOW.md`.

## 3. Budúci AI dialóg vo formulári

Pri jednotlivých QA otázkach má neskôr pribudnúť možnosť typu `Prediskutovať s AI`.

Cieľ:

- inšpektor zostáva na tej istej QA obrazovke;
- AI pozná konkrétny issue, observations, evidence, hypotheses a otázku;
- inšpektor môže doplniť odborný kontext prirodzeným dialógom;
- výsledok dialógu sa musí skonvertovať na štruktúrovanú QA odpoveď;
- štruktúrovaná odpoveď, nie samotný chat, je vstupom do ďalšej diagnostickej revízie;
- žiadne manuálne copy-paste medzi chatom a formulárom.

Táto funkcionalita je **odložená**, kým základný formulárový QA workflow nezačne produkovať reálne reporty. Prioritou je dostať produkt čo najskôr do použiteľnej prevádzky.

## 4. AI revision po odpovediach inšpektora

Po odoslaní QA odpovedí LLM dostane minimálne:

`inspection.json + current diagnosis draft + inspector answers + diagnostic rules`

LLM má prehodnotiť iba tie diagnostické objekty, ktorých sa nové odpovede týkajú. Nemá prepisovať source observations.

Po revízii má inšpektor dostať zrozumiteľný diff, napríklad:

- confidence `medium → low`;
- recommendation z definitívnej opravy na `VERIFY`;
- cost `estimated → not_estimated`;
- priority bez zmeny;
- nový approval blocker odstránený alebo pridaný.

Až následne môže človek schváliť konkrétny snapshot.

## 5. Granularita: detail sa nestráca agregáciou

Záväzné pravidlo:

> Zlučovať možno diagnostické problémy. Zlučovaním sa nesmie stratiť detail observations ani evidence.

Jeden `diagnostic_issue` môže obsahovať mnoho observations a evidence. AI smie povedať, že viac prejavov patrí k jednému rozhodovaciemu problému, ale nesmie z viacerých zdrojových zistení vyrobiť jednu neurčitú vetu a ostatné zahodiť.

Praktický princíp:

`high-fidelity observations/evidence → decision-level diagnostic issues → simplified client presentation`

Agregácia môže byť zjednodušujúca iba na úrovni diagnostického zoskupenia a prezentácie, nie na úrovni zdrojovej dokumentácie.

Podrobné pravidlá sú v `OBSERVATION_GRANULARITY.md`.

## 6. Krov a detailné konštrukcie

Pri krove a podobných detailovo citlivých konštrukciách je požadovaná vysoká granularita dokumentácie.

Typický model:

- konkrétny prvok alebo spoj = samostatné observation, ak má vlastný odborný význam;
- ku každému relevantnému detailu fotografie/evidence;
- presná lokalita;
- popis stavu;
- následne možno viac observations zaradiť pod spoločný diagnostic issue, ak majú spoločný rozhodovací význam.

Nesmie vzniknúť výstup typu `krov má niekoľko lokálnych nedostatkov` bez zachovania jednotlivých detailov pod ním.

## 7. Stena, pivnica a agregovateľné prejavy

Pri jednej konštrukcii môžu byť trhlina, deformácia, opadaná omietka, výkvety, vlhkostné prejavy zvnútra a súvisiace fotografie samostatné observations, ale diagnosticky môžu patriť do jedného issue, ak majú spoločný rozhodovací význam.

Príklad:

`OBS trhlina + OBS deformácia + OBS výkvety + OBS poškodená omietka + OBS vlhkosť → DI: deformácia a vlhkostná degradácia muriva`

Klient vidí jeden zrozumiteľný problém, po rozbalení však musí byť možné dohľadať všetky relevantné prejavy a evidence.

## 8. Kritérium agregácie

AI môže observations spojiť do jedného diagnostic issue, ak majú spoločný rozhodovací význam, napríklad:

- spoločná konštrukcia alebo funkčný celok;
- súvisiaci mechanizmus, ktorý je stále hypotézou alebo potvrdeným mechanizmom podľa dát;
- podobný remediation workflow;
- rovnaká diagnostická otázka alebo verification;
- spoločná priorita rozhodnutia.

AI ich nemá spájať, ak by sa stratili rozdiely v:

- lokalite;
- type konštrukčného detailu;
- bezpečnostnom význame;
- možnej príčine;
- spôsobe opravy;
- potrebnom špecialistovi;
- priorite alebo urgencii;
- potrebe samostatného evidence trailu.

## 9. Rýchlosť MVP vs. budúce funkcie

Aktuálna priorita je dostať reálne inšpekcie cez celý workflow až ku klientskemu reportu.

Preto sa odkladajú funkcie, ktoré nie sú potrebné pre prvé produkčné prípady, najmä:

- live AI chat v backoffice;
- client-facing AI chat;
- plná SafetyCulture API automatizácia;
- komplexné workflow automation nad externými službami;
- automatický PDF generator;
- sofistikované reportové cache alebo analytické vrstvy.

Odloženie neznamená, že sa na ne zabúda. Architektúra ich má umožniť bez narušenia source/diagnosis/approval hraníc.

## 10. QA musí zobrazovať ľudskú legendu skóre

Inšpektor nemá byť nútený pamätať si interné kódy ako `S4`, `L2`, `U3` alebo `P3`.

V každom QA review, backoffice formulári a diagnostickom diff-e sa má pri kóde zobraziť aj jeho ľudský význam. Minimálne:

- `S1–S5` → závažnosť následku;
- `L1–L5` → pravdepodobnosť alebo pozorovanosť presne pomenovaného javu;
- `U1–U5` → odporúčaný čas začatia kroku;
- `P1–P5` → rozhodovacia priorita;
- `confidence` → istota diagnostického tvrdenia;
- `deterioration_rate` → tempo zmeny alebo `unknown`.

Preferovaný QA zápis je napríklad:

`S4 — vysoká závažnosť`

`L2 — možné`

`U3 — približne do 3 mesiacov`

`P3 — plánovať`

Nie iba holé kódy.

Ak sa skóre predkladá inšpektorovi na potvrdenie, systém má zároveň jednou krátkou vetou uviesť, **čo sa danou osou hodnotí**. Najmä pri likelihood musí byť viditeľný `likelihood_subject`, aby inšpektor nehodnotil číslo bez kontextu.

Toto je UX povinnosť systému, nie pamäťová povinnosť inšpektora.

## 11. Zásada aktualizácie tejto pamäte

Tento dokument je trvalá projektová pamäť, nie historický changelog.

- Aktuálne platné rozhodnutia majú byť formulované jednoznačne.
- Nahradené rozhodnutie sa má označiť ako superseded alebo presunúť do krátkeho decision logu, nie ponechať dve protichodné pravidlá.
- Implementácia významnej funkcie musí odkazovať na príslušný dokument.
- Pri novom AI/Codex tasku, ktorý sa týka diagnostického workflow, QA alebo agregácie, sa majú tieto pravidlá zahrnúť do source-of-truth čítania.
