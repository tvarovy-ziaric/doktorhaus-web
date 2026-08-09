# Observation granularity and diagnostic aggregation

Tento dokument definuje, koľko detailu sa musí zachovať pri zdrojových observations/evidence a kedy ich možno zoskupiť do jedného diagnostic issue.

Základné pravidlo:

> Agregácia smie zjednodušiť rozhodovanie a prezentáciu, ale nesmie zničiť evidence trail.

## 1. Tri úrovne detailu

### Source observation

Najjemnejšia odborná vrstva. Zachytáva konkrétny jav, detail, lokalitu, meranie alebo negatívny finding.

### Diagnostic issue

Rozhodovacia vrstva. Zoskupuje observations, ktoré spolu tvoria jeden zrozumiteľný technický problém alebo jednu diagnostickú otázku.

### Client presentation

Prezentačná vrstva. Má znižovať informačný chaos, ale musí umožniť dohľadať jednotlivé source prejavy a relevantné evidence.

Tok:

`detailed observations/evidence → diagnostic grouping → client-facing explanation`

## 2. Čo sa nikdy nestráca

Ak bol detail odborne významný pri inšpekcii, zoskupenie ho nesmie odstrániť.

Musí zostať dohľadateľné minimálne:

- čo bolo pozorované;
- kde;
- aký prvok alebo detail bol hodnotený;
- ktoré fotografie/video/meranie ho dokumentujú;
- source status alebo limitation;
- väzba na diagnostic issue.

## 3. Kedy možno observations zlučovať do jedného issue

Je vhodné ich zoskupiť, ak majú spoločný rozhodovací význam, napríklad:

- rovnaká konštrukcia alebo funkčný celok;
- prejavy sú technicky súvisiace;
- rieši ich rovnaký verification alebo remediation workflow;
- klient potrebuje urobiť jedno spoločné rozhodnutie;
- majú podobnú prioritu a časový rámec;
- rozdelenie na viac issue by len opakovalo rovnaký problém.

Zoskupenie neznamená tvrdenie spoločnej príčiny. Spoločný mechanizmus musí zostať hypothesis, kým nie je potvrdený.

## 4. Kedy sa observations nemajú zlučovať

Nezlučovať, ak by sa stratila významná odlišnosť v:

- lokalite;
- type prvku alebo konštrukčného detailu;
- safety consequence;
- možnom mechanizme;
- potrebnom odborníkovi;
- remediation postupe;
- severity, urgency alebo priority;
- stave `active / monitoring / resolved`;
- potrebe samostatného monitoringu;
- evidence traili.

## 5. Krov — vysoká granularita

Krov je príklad konštrukcie, kde detailná dokumentácia jednotlivých prvkov a spojov často tvorí samotnú hodnotu inšpekcie.

Ak je relevantné, samostatné observations môžu byť napríklad:

- uloženie pomúrnice;
- konkrétna krokva;
- konkrétny spoj;
- väzný trám;
- styk dreva s komínom;
- lokálna biologická degradácia;
- lokálna deformácia;
- prestup strešným plášťom;
- detail latovania alebo kotvenia.

Ku každému významnému detailu má zostať jeho fotografia alebo iné evidence.

Viac týchto observations možno neskôr zaradiť pod spoločný issue, napríklad `Lokálne poruchy a nevhodné detaily krovu`, iba ak klient stále vie rozbaliť a dohľadať každý relevantný detail.

Zakázaný výsledok:

`Krov má niekoľko nedostatkov.`

ak source vrstva obsahovala presnejšie údaje, ktoré sa následne stratili.

## 6. Murivo/pivnica — vysoký detail, nižší počet issues

Jedna pivničná stena môže mať samostatné observations:

- trhlina;
- geometrická deformácia;
- opadaná omietka;
- soľné výkvety;
- vlhkostný prejav pri päte steny;
- poškodenie z exteriéru;
- súvisiace fotografie.

Diagnosticky môžu patriť pod jeden issue, napríklad:

`Deformácia a vlhkostná degradácia muriva`

ak je to jeden spoločný rozhodovací problém.

Klient vidí jeden problém, ale po otvorení issue musí vidieť jednotlivé prejavy a evidence.

## 7. Strecha, komín, elektro a technické systémy

### Strecha

Lokálne detaily sa dokumentujú osobitne, najmä prestupy, hrebeň, okrajové detaily, krytina a stopy zatekania. Diagnostické issue sa môžu zoskupiť podľa spoločného remediation významu.

### Komín

Bezpečnostne odlišné findings sa nemajú zlievať iba preto, že patria jednému komínu. Chýbajúce čistiace dvierka, nevhodný drevený prvok a neistý stav vložky môžu vyžadovať samostatné observations a rozdielne verification kroky.

### Elektro

Jednotlivé viditeľné nedostatky sa dokumentujú samostatne. Ak nebol pozorovaný defect a chýba iba odborná revízia, nemusí vzniknúť samostatný vysokoprioritný diagnostic issue.

## 8. Evidence nie je dekorácia

Fotografia má mať väzbu na konkrétnu observation alebo relevantný issue. Galéria bez jasnej väzby znižuje auditovateľnosť.

Pri detailových konštrukciách môže viac fotografií dokumentovať jednu observation. Naopak jedna fotografia môže podporovať viac súvisiacich observations, ak je to explicitne naviazané.

## 9. Negatívne findings

Relevantné negatívne findings sa nemajú zahadzovať iba preto, že klientsky issue opisuje problém.

Príklady:

- v krove neboli viditeľné stopy zatekania;
- nebola viditeľná pleseň;
- určitá časť systému bola vizuálne bez zjavných problémov.

Takýto finding môže obmedzovať hypotézu alebo confidence. Nemá sa premeniť na tvrdenie o absolútnej bezchybnosti.

## 10. AI grouping policy

Pri automatickom zoskupovaní má AI postupovať v tomto poradí:

1. zachovať všetky relevantné observations a evidence;
2. identifikovať rozhodovací význam každého findingu;
3. navrhnúť diagnostic issues;
4. skontrolovať, či zoskupením nezanikol rozdiel v bezpečnosti, príčine, sanácii, odbornosti alebo priorite;
5. zachovať explicitné linky issue ↔ observation ↔ evidence;
6. až potom zjednodušiť klientsku prezentáciu.

## 11. QA kontrola agregácie

Inspector QA má vždy obsahovať kontrolu:

- nestratil sa pri groupingu dôležitý detail?
- sú všetky významné fotografie stále naviazané?
- nevznikol jeden príliš široký issue s viacerými nesúvisiacimi problémami?
- nevzniklo naopak priveľa issues, ktoré klientovi opakujú to isté rozhodnutie?
- nebola agregácia mylne interpretovaná ako potvrdená spoločná príčina?

Ak je odpoveď áno, diagnosis draft sa vracia na úpravu pred `APPROVE`.
