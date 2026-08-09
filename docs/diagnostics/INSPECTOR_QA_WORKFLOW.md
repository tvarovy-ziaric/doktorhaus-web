# Inspector QA workflow

Tento dokument definuje cieľový human-in-the-loop workflow inšpektora medzi AI diagnostickým draftom a odborným `APPROVE`.

Cieľom je minimalizovať manuálne prepisovanie a copy-paste, ale zachovať jasnú ľudskú zodpovednosť za odborný záver.

## 1. Prevádzkový tok

Cieľový tok jednej inšpekcie:

`SafetyCulture → source normalization → AI diagnostic draft → generated QA questionnaire → inspector answers → AI diagnostic revision → inspector diff review → APPROVE → immutable report → publish → access grant/PIN`

V MVP môže byť QA vykonané aj priamym dialógom s AI. Produkčný cieľ je formulár.

## 2. Generovanie otázok

QA formulár nie je univerzálny checklist s desiatkami rovnakých otázok pre každý dom.

Otázky sa generujú z konkrétneho diagnostického draftu a majú vzniknúť iba tam, kde odpoveď inšpektora môže zmeniť alebo spresniť:

- hypothesis;
- confidence;
- deterioration rate;
- severity / likelihood / urgency / priority rationale;
- verification;
- recommendation;
- cost estimate;
- approval blocker;
- provenance alebo zdrojovú interpretáciu.

Jednoduchá inšpekcia môže mať iba niekoľko otázok. Zložitá staršia stavba môže mať viac.

## 3. Triedy otázok

### Blocks approval

Otázka musí byť vyriešená pred `APPROVE`, pretože bez nej by report mohol tvrdiť odborný záver, ktorý nie je dostatočne podložený.

Formulár ju označí najvýraznejšie.

### Improves confidence

Odpoveď je hodnotná, ale report môže byť schválený aj bez nej, ak diagnosis explicitne prizná neistotu alebo missing information.

### Provenance / informational

Otázka rieši pôvod, čas, označenie média, nejasný zdrojový detail alebo inú informáciu, ktorá nemusí meniť odborný záver.

## 4. Typy vstupov

Formulár má podporovať podľa potreby:

- `yes / no / not_verified`;
- single choice;
- multiple choice, iba ak dáva odborný zmysel;
- číslo + jednotka;
- dátum;
- krátka poznámka;
- odborná voľná poznámka;
- neskôr attachment/evidence.

`not_verified` / `neviem` je plnohodnotná odpoveď. Systém ju nesmie penalizovať alebo maskovať.

## 5. Kontext otázky

Otázka sa nemá zobraziť bez vysvetlenia.

Každý QA blok má podľa potreby obsahovať:

- **Čo vieme** — stručné source facts;
- **Prečo sa pýtame** — ktorý diagnostický záver závisí od odpovede;
- **Otázka** — konkrétny ľudský vstup;
- **Súvisiace evidence** — fotografie alebo relevantné detaily, ak pomáhajú rozhodnutiu;
- **Odpoveď** — štruktúrovaný vstup;
- **Poznámka** — voliteľné spresnenie.

## 6. Formulár je source of truth pre ľudský vstup

Po odoslaní sa odpovede ukladajú ako štruktúrovaný auditovateľný objekt, nie iba ako voľný chat transcript.

Cieľový koncept môže byť napríklad:

`inspector_questionnaire.json + inspector_answers.json`

Presný schema návrh sa má vytvoriť až po prvých reálnych prípadoch, aby nevznikol naslepo.

## 7. AI revízia

Po odpovediach inšpektora LLM dostane aktuálny source a diagnostický stav spolu s odpoveďami.

AI smie meniť iba diagnostickú vrstvu a objekty dotknuté novými odpoveďami.

AI nesmie:

- prepisovať source observations tak, aby zodpovedali novej hypotéze;
- meniť alebo mazať evidence bez zdrojového dôvodu;
- udeliť `APPROVE`;
- maskovať `not_verified` ako potvrdený fakt.

## 8. Diff pred schválením

Po AI revízii má inšpektor dostať zmenu vo forme diffu namiesto potreby čítať celý report od začiatku.

Diff má zvýrazniť napríklad:

- zmenený hypothesis status/confidence;
- zmenené S/L/U/P alebo ich rationale;
- nový alebo odstránený verification;
- zmenu recommendation;
- zmenu cost estimate;
- odstránený alebo nový approval blocker;
- významnú zmenu klientského textu.

Inšpektor môže:

- prijať revíziu;
- upraviť odpoveď;
- vrátiť issue na ďalšie QA;
- manuálne odborné rozhodnutie označiť ako override s rationale.

## 9. `APPROVE`

`APPROVE` je samostatný ľudský akt po revízii.

Inšpektor schvaľuje konkrétny snapshot source/diagnosis/report verzie. AI ani úspešný lint nesmú approval vytvoriť automaticky.

## 10. Budúci kontextový AI chat

Pri otázke môže neskôr pribudnúť akcia `Prediskutovať s AI`.

Chat musí byť scopeovaný na konkrétny prípad a podľa potreby konkrétny issue/question. AI má dostať iba relevantný autorizovaný kontext.

Po diskusii musí vzniknúť explicitná štruktúrovaná odpoveď, ktorú inšpektor ešte potvrdí alebo upraví. Samotný chat transcript nie je odborným source of truth.

Príklad toku:

`QA question → Diskutovať s AI → conversation → navrhnutá structured answer → inspector confirms → AI revision`

Táto funkcia je zámerne odložená za MVP formulár a prvé produkčné reporty.

## 11. Rýchly MVP pred formulárom

Kým backoffice formulár nie je implementovaný, prvé reálne prípady môžu fungovať takto:

1. AI vytvorí `diagnosis.json` draft a `DIAGNOSTIC_QA.md`.
2. Inšpektor odpovie na otázky v priamom dialógu.
3. Odpovede sa interpretujú ako ľudský QA vstup.
4. AI vytvorí novú diagnosis revision.
5. Inšpektor skontroluje diff a schváli alebo vráti draft.

Toto je dočasný prevádzkový spôsob, nie cieľový UX.

## 12. Dizajnové požiadavky na budúci backoffice

QA obrazovka má byť rýchla a mobilne použiteľná.

Preferované vlastnosti:

- prehľad počtu blockers / confidence otázok / provenance otázok;
- otázky zoradené podľa významu, nie podľa source checklistu;
- fotografie priamo pri otázke, ak sú relevantné;
- minimum povinného textového písania;
- uloženie rozpracovaných odpovedí;
- jasný stav `nezodpovedané / odpovedané / znovu otvorené`;
- tlačidlo `Prepracovať diagnostiku`;
- následný diff;
- samostatné `APPROVE`;
- žiadne automatické publish pri odoslaní formulára.
