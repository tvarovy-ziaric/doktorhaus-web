# Inspector assertion semantics

Tento dokument zachytáva pravidlá, ako sa majú ľudské QA odpovede inšpektora premeniť na odborný diagnostický vstup bez zjednodušenia epistemického stavu.

## 1. `Nie` nie je to isté ako `nepotvrdené`

Ak sa určitý jav cielene netestoval alebo nemonitoroval, odpoveď sa nesmie preložiť na negatívny fakt.

Príklad pri trhline alebo deformácii muriva:

- `aktívny pohyb potvrdený` znamená, že existuje časové alebo meracie evidence pre zmenu;
- `aktívny pohyb nepotvrdený / aktivita neznáma` znamená, že existuje geometrická deformácia, ale neexistuje dostatočné časové porovnanie alebo monitoring;
- `aktívny pohyb vylúčený` možno použiť iba vtedy, ak ho podporuje primeraná metóda a dostatočné obdobie sledovania.

Absencia sadrových pásikov, prasklinomeru, opakovaného merania, porovnateľných starších fotografií alebo iného časového evidence znamená typicky `unknown / unconfirmed`, nie `no movement` a nie `stable`.

## 2. Jednorazová obhliadka neurčuje tempo zmeny

Jednorazová vizuálna kontrola môže potvrdiť existenciu deformácie, trhliny, vlhkostného prejavu alebo poškodenia. Sama osebe spravidla nedokazuje:

- že sa stav zhoršuje;
- že je stabilný;
- rýchlosť zhoršovania;
- čas vzniku.

Ak chýba časová séria, `deterioration_rate` má zostať `unknown`, pokiaľ neexistuje iný explicitný dôkaz.

## 3. Rozlišuj osobné pozorovanie, odborné potvrdenie a svedeckú informáciu

Ľudský QA vstup môže mať rôznu evidenčnú silu:

- **inspector_observed** — inšpektor jav osobne videl alebo zmeral;
- **inspector_confirmed_from_accessible_detail** — inšpektor vie z prístupného detailu potvrdiť technický stav, napríklad absenciu komínovej vložky;
- **owner_or_local_report** — informácia od majiteľa, obyvateľa, suseda alebo inej osoby;
- **historical_context_unverified** — lokálna/historická informácia bez nezávislého overenia;
- **not_verified** — otázka zostáva otvorená.

Tieto kategórie sa nesmú zlievať. Svedecké tvrdenie môže podporiť hypotézu alebo verification plán, ale nesmie sa automaticky zmeniť na potvrdený technický fakt.

Ak presný zdroj osoby nie je pre klienta odborne významný alebo by zbytočne personalizoval tvrdenie, klientský text má preferovať anonymizovanú formuláciu, napríklad `podľa miestnych svedectiev` alebo `podľa dostupných svedeckých informácií`. Interná provenance vrstva si môže zachovať presnejší typ zdroja.

## 4. Svedecký odhad nie je meranie

Ak tretia osoba opisuje rozsah javu číslom, ktoré nebolo merané alebo inšpektorom overené, číslo sa nesmie automaticky prevziať ako technická veličina.

Príklad: výrok typu `voda býva približne 30 cm` bez merania sa nemá v klientskom reporte prezentovať ako `výška zaplavenia 30 cm`.

Ak je pre rozhodovanie dôležitejšia samotná existencia a opakovanosť javu než neoverený rozmer, preferovať kvalitatívny opis, napríklad:

- `dochádza k lokálnym sezónnym nízkym zaplavovaniam podľa dostupných svedeckých informácií`;
- `opakovaný výskyt vody pri intenzívnejších zrážkach bol hlásený, rozsah nebol meraný`.

Interná QA/provenance vrstva môže zachovať pôvodný odhad ako neoverený source claim, ak je to užitočné.

## 5. Materiálový fakt nie je automaticky mechanizmus

Ak inšpektor identifikuje materiál, napríklad `stavebné lepidlo`, možno ho zapísať ako potvrdený materiálový detail, ak bol rozpoznaný dostatočne spoľahlivo.

Z toho však automaticky nevyplýva tvrdenie typu `difúzne uzavretá vrstva spôsobila vlhkosť`. Paropriepustnosť, skladba vrstiev a kauzálny význam zostávajú diagnostickou interpretáciou/hypotézou, pokiaľ neboli priamo overené.

## 6. Odhadovaný rozmer musí zostať odhadom

Ak rozmer nevznikol meraním, ale vizuálnym alebo terénnym odhadom, musí sa tak označiť. Odhad `0–3 cm` sa nesmie v reportovej vrstve prezentovať ako presné meranie.

Rovnaké pravidlo platí pre odhadované vzdialenosti, hrúbky, výšky zaplavenia, plochy a deformácie.

## 7. Tvar trhliny je pozorovanie; pohyb je interpretácia

Geometria a orientácia trhliny môžu byť source observation, napríklad:

- vertikálna trhlina;
- viacero nadväzujúcich trhlín;
- trhlina sa smerom nahor rozširuje.

Z toho možno vytvoriť odbornú hypotézu alebo interpretation o možnom pohybe muriva, ale samotný tvar trhliny bez časového porovnania nepotvrdzuje aktuálnu aktivitu pohybu ani jeho rýchlosť.

## 8. Degradácia povrchu bez dostatočných dôkazov nemá dostať jednu vymyslenú príčinu

Celoplošné opadávanie alebo degradácia omietky môže mať viac možných mechanizmov, napríklad historické zatekanie, nevhodné zloženie omietky, nedostatok spojiva, chybnú aplikáciu alebo kombináciu faktorov.

Ak zdrojové dáta neumožňujú medzi nimi rozlíšiť, report nemá vybrať jednu príčinu iba preto, aby bol príbeh jednoduchší. Stav sa opíše ako finding a príčina zostane `unknown`, prípadne sa uvedú len odborne obhájiteľné alternatívne hypotheses.

## 9. Human QA nesmie prepisovať source observation

Nová odpoveď inšpektora môže:

- doplniť evidenčný kontext;
- zmeniť hypothesis/confidence;
- vytvoriť alebo zrušiť verification;
- upraviť scoring rationale;
- upraviť recommendation alebo cost uncertainty.

Nemá spätne prepisovať pôvodnú source observation tak, aby vyzerala istejšie, než bola pri obhliadke.

## 10. Formulár musí ponúkať epistemicky správne možnosti

Budúci QA formulár nemá všade používať iba binárne `áno/nie`.

Podľa otázky musí vedieť ponúknuť napríklad:

- `áno / nie / neviem`;
- `potvrdené / nepotvrdené / nebolo overované`;
- `osobne pozorované / informácia od tretej osoby / neoverené`;
- `merané / odhadované / neznáme`.

Výber odpovede musí zachovať význam a nesmie nútiť inšpektora k falošnej istote.
