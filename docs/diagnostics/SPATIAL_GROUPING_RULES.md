# Spatial grouping and locality rules

Tento dokument dopĺňa pravidlá granularizácie observations a diagnostickej agregácie o priestorový kontext.

## 1. Lokalita je prvotriedna diagnostická informácia

Dve observations sa nesmú automaticky zlúčiť len preto, že sú v rovnakej miestnosti, objekte alebo sekcii checklistu.

Pri groupingu sa musí zohľadniť minimálne:

- konkrétna stena / plocha / prvok;
- orientácia voči vstupu alebo inému referenčnému bodu;
- vnútorná vs. vonkajšia strana tej istej konštrukcie;
- vzdialená alebo protiľahlá konštrukcia;
- samostatný konštrukčný detail;
- rozdielny mechanizmus, dôsledok alebo remediation workflow.

## 2. Rovnaká miestnosť neznamená rovnaký problém

V jednej miestnosti môžu existovať viaceré nezávislé problémy. Napríklad:

- lokálna porucha povrchovej vrstvy pri jednom otvore;
- samostatná deformácia a trhliny protiľahlej steny;
- degradácia omietky na tretej stene.

Tieto findings sa nemajú zlúčiť do jedného issue len kvôli spoločnej miestnosti. Zlúčenie je prípustné až vtedy, keď majú spoločný rozhodovací význam a nestratí sa priestorová ani technická odlišnosť.

## 3. Dve strany tej istej konštrukcie

Observation z exteriérovej a interiérovej strany tej istej steny môžu patriť k jednému diagnostic issue, ak ide o ten istý konštrukčný prvok a spoločnú diagnostickú otázku.

To však neznamená automaticky potvrdenú kauzalitu medzi oboma prejavmi. Väzba typu `jedna vrstva spôsobila poruchu na druhej strane` zostáva hypothesis, pokiaľ nie je potvrdená.

## 4. Keď chýba pôdorys

Pôdorys je žiaduci, ale nesmie byť podmienkou pre zachovanie priestorového významu.

Ak pôdorys chýba, observations majú používať stabilné relatívne priestorové kotvy, napríklad:

- `pri vstupnom otvore`;
- `ľavá strana pri pohľade zvnútra`;
- `pravá strana za regálom`;
- `protiľahlá stena oproti vstupu`;
- `roh pri dverách`;
- `vonkajšia strana toho istého muriva`.

Ak orientácia nie je spoľahlivá, nepoužívať vymyslené svetové strany.

## 5. Budúca podpora pôdorysu

Budúci workflow môže pridať jednoduchý pôdorys alebo priestorovú mapu, na ktorú sa budú viazať observations a evidence. Dátový model má preto zachovať lokality dostatočne detailne, aby ich bolo možné neskôr spätne priradiť.

Pôdorys môže pomôcť AI pri groupingu, ale nenahrádza source observations ani fotografie.

## 6. AI grouping check

Pred zlúčením observations do jedného issue sa AI musí opýtať:

1. Ide o tú istú fyzickú konštrukciu alebo bezprostredne súvisiaci detail?
2. Je priestorová väzba medzi observations spoľahlivo známa?
3. Majú rovnaký alebo spoločný rozhodovací/remediation význam?
4. Nestratí sa samostatný structural, moisture, safety alebo material finding?
5. Dokáže klient po rozbalení issue stále identifikovať každý konkrétny prejav a jeho fotografie?

Ak niektorá odpoveď vychádza negatívne alebo neisto, observations sa nemajú zlučovať len kvôli zjednodušeniu prezentácie.

## 7. Priestorová neistota

Ak nie je jasné, či dve fotografie alebo poznámky dokumentujú tú istú stenu/detail, systém má radšej zachovať samostatné observations a označiť chýbajúcu priestorovú väzbu ako `missing information` alebo QA otázku.

Nesmie vytvoriť spoločný issue na základe domnienky o lokalite.
