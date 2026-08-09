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

## 4. Materiálový fakt nie je automaticky mechanizmus

Ak inšpektor identifikuje materiál, napríklad `stavebné lepidlo`, možno ho zapísať ako potvrdený materiálový detail, ak bol rozpoznaný dostatočne spoľahlivo.

Z toho však automaticky nevyplýva tvrdenie typu `difúzne uzavretá vrstva spôsobila vlhkosť`. Paropriepustnosť, skladba vrstiev a kauzálny význam zostávajú diagnostickou interpretáciou/hypotézou, pokiaľ neboli priamo overené.

## 5. Odhadovaný rozmer musí zostať odhadom

Ak rozmer nevznikol meraním, ale vizuálnym alebo terénnym odhadom, musí sa tak označiť. Odhad `0–3 cm` sa nesmie v reportovej vrstve prezentovať ako presné meranie.

## 6. Human QA nesmie prepisovať source observation

Nová odpoveď inšpektora môže:

- doplniť evidenčný kontext;
- zmeniť hypothesis/confidence;
- vytvoriť alebo zrušiť verification;
- upraviť scoring rationale;
- upraviť recommendation alebo cost uncertainty.

Nemá spätne prepisovať pôvodnú source observation tak, aby vyzerala istejšie, než bola pri obhliadke.

## 7. Formulár musí ponúkať epistemicky správne možnosti

Budúci QA formulár nemá všade používať iba binárne `áno/nie`.

Podľa otázky musí vedieť ponúknuť napríklad:

- `áno / nie / neviem`;
- `potvrdené / nepotvrdené / nebolo overované`;
- `osobne pozorované / informácia od tretej osoby / neoverené`;
- `merané / odhadované / neznáme`.

Výber odpovede musí zachovať význam a nesmie nútiť inšpektora k falošnej istote.
