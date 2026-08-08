# Diagnostické pravidlá

## Základné pravidlo

Pozorovanie, interpretácia, hypotéza, potvrdené zistenie a odporúčanie sú rozdielne typy tvrdení. Nesmú sa zlúčiť do jednej vety tak, že neistá príčina začne pôsobiť ako fakt.

Príklad správneho oddelenia:

- Pozorovanie: „Vrchná časť muriva je vychýlená smerom von.“
- Súvisiace zistenia: „Dažďové zvody sú nevhodne ukončené, v oblasti je zvýšená vlhkosť a na murive sú trhliny.“
- Hypotéza: „Dlhodobé zavodňovanie podložia môže prispievať k pohybu muriva.“
- Confidence: `medium`.
- Chýbajúce informácie: „Nie je známe založenie, charakter podložia ani aktivita pohybu.“
- Verification: „Podľa charakteru poruchy sonda podložia, monitoring trhlín a statické posúdenie.“

Bez potrebných dôkazov sa nesmie uviesť: „Stena sa pohla kvôli podmočeniu základov.“

## Typy tvrdení

### Observation – pozorovanie

Priamo zistený fakt: vizuálny prejav, hodnota merania, obsah dokumentu alebo presne označené tvrdenie tretej osoby. Má uvádzať miesto, čas, metódu a podmienky, ak sú relevantné.

Observation odpovedá na „čo bolo zistené“, nie „prečo sa to stalo“. Slová ako „spôsobené“, „v dôsledku“ alebo „pretože“ do observation nepatria, pokiaľ iba necitujú jasne označený externý zdroj.

### Interpretation – interpretácia

Odborný význam pozorovania bez definitívneho určenia mechanizmu. Napríklad: „Vzor trhlín je nezvyčajný pre bežnú povrchovú vlasovú trhlinu a vyžaduje overenie aktivity.“

Interpretácia musí byť dohľadateľne spojená s observations/evidence a nesmie sa vydávať za meranie. V klientskom reporte sa používa opatrne, spravidla v summary issue alebo rationale hypotézy.

### Hypothesis – hypotéza

Testovateľné možné vysvetlenie príčiny alebo mechanizmu. Musí mať statement, mechanism, confidence, status, rationale, supporting evidence, contradicting evidence a missing information.

Jeden issue môže mať viac hypotéz. Alternatívy sa nesmú odstrániť iba preto, že jedna znie intuitívnejšie. Ak sa mechanizmy môžu súčasne podieľať na probléme, treba to uviesť.

### Confirmed finding – potvrdené zistenie

Tvrdenie, ktorého rozsah je primerane podopretý vhodnou metódou a dostupnými dôkazmi. „Potvrdené“ sa vzťahuje iba na presný obsah tvrdenia, nie na všetky možné príčiny a následky.

Podmienky:

- existuje jednoznačný statement a definovaný rozsah;
- použitá metóda je vhodná pre dané tvrdenie;
- supporting evidence je dohľadateľné a dostatočné;
- relevantné contradicting evidence bolo vyhodnotené;
- významné missing information je buď doplnené, alebo nebráni presnému záveru;
- tvrdenie schválil oprávnený inšpektor alebo externý špecialista v rozsahu svojej odbornosti;
- limitations zostávajú uvedené.

Vizuálna inšpekcia môže potvrdiť napríklad prítomnosť trhliny v dostupnom povrchu. Bez ďalšieho overenia zvyčajne nepotvrdzuje príčinu pohybu základov.

### Recommendation – odporúčanie

Samostatný objekt opisujúci ďalší krok, nie diagnózu. Uvádza typ akcie, dôvod, časový rámec, vhodnú odbornosť, dependencies a spôsob následného overenia.

## Confidence

Confidence vyjadruje istotu konkrétneho diagnostického tvrdenia vzhľadom na kvalitu, množstvo a súlad podkladov. Nie je to likelihood javu ani pravdepodobnosť, že sa porucha zhorší.

Spoločná pracovná stupnica:

- `low`: podklady sú obmedzené, nepriame alebo existujú významné alternatívy; záver je orientačný;
- `medium`: viac relevantných podkladov smeruje k rovnakému vysvetleniu, ale chýba rozhodujúce overenie alebo zostáva významná alternatíva;
- `high`: vhodné a navzájom súladné podklady priamo podporujú presne vymedzené tvrdenie; významné alternatívy boli primerane preverované;
- `unknown`: confidence zatiaľ nemožno poctivo určiť.

Confidence sa musí prehodnotiť po doplnení alebo spochybnení evidence. Vysoká confidence neodstraňuje limitations a nerobí z technickej inšpekcie znalecký či statický posudok.

## Supporting a contradicting evidence

Evidence samo osebe nemá univerzálnu rolu. Rovnaká fotografia môže podporiť jedno tvrdenie a byť neutrálna k inému.

Každá väzba evidence ↔ hypothesis alebo finding má obsahovať:

- rolu `supporting` alebo `contradicting`;
- krátke vysvetlenie, čo evidence pre dané tvrdenie znamená;
- obmedzenia kvality alebo pôvodu;
- autora a čas priradenia.

Contradicting evidence sa nesmie vymazať ani skryť, aby výsledok pôsobil jednoznačnejšie. Má znižovať confidence, meniť status alebo viesť k ďalšiemu verification. Ak je konflikt nevyriešený, report ho pokojne prizná.

## Missing information

Missing information je explicitný zoznam chýbajúcich údajov, ktoré môžu zmeniť diagnózu, skóre, náklady alebo odporúčanie. Má uvádzať:

- čo konkrétne chýba;
- prečo je to dôležité;
- ako možno údaj získať;
- či chýbanie blokuje potvrdenie alebo publikovanie;
- kto je vhodný na doplnenie.

Príklady: neprístupná časť krovu, neznáma skladba konštrukcie, chýbajúca projektová dokumentácia, jednorazové meranie bez časovej série, neznáma aktivita trhliny.

„Neoverené“ neznamená „bez problému“. Zároveň neznamená, že problém existuje.

## Pravidlá formulovania diagnóz

1. Začať pozorovaným stavom a dotknutou oblasťou.
2. Oddeliť prejavy, možné mechanizmy, dôsledky a ďalšie kroky.
3. Použiť len takú mieru istoty, akú unesú dôkazy.
4. Pri hypotéze používať formulácie „môže súvisieť“, „môže prispievať“, „jednou z možností je“ alebo „dostupné podklady podporujú“.
5. Slová „spôsobuje“, „príčinou je“, „potvrdzuje“ a „určite“ použiť iba pri splnení podmienok potvrdeného zistenia.
6. Uviesť významné alternatívy a protichodné dôkazy.
7. Neodvodzovať skrytý stav iba z absencie viditeľného prejavu.
8. Nerozširovať záver z dostupnej časti na celý objekt bez opory.
9. Rozlíšiť súčasný stav, krátkodobé riziko a dlhodobé riziko.
10. Nespájať severity, likelihood, urgency, priority ani confidence do jedného neurčitého označenia „vážne“.
11. Neuvádzať presný náklad bez definovaného scope, assumptions a confidence.
12. Zachovať pokojný, vecný jazyk bez strašenia a marketingového balastu.

## Externé odborné preverenie

Odporúčanie externého preverenia sa vytvorí ako recommendation typu `VERIFY`, prípadne nadväzujúce `DOCUMENT`. Musí pomenovať otázku, nie iba všeobecne „zavolať odborníka“.

Externé preverenie sa odporúča najmä vtedy, keď:

- následok môže byť kritický pre bezpečnosť alebo nosnú konštrukciu;
- je potrebný statický výpočet, projekt, revízia, skúška, laboratórna analýza alebo deštruktívna sonda;
- dostupná inšpekčná metóda nevie rozlíšiť významné hypotézy;
- právny alebo regulačný význam vyžaduje oprávnenú osobu;
- ide o elektrické, plynové, požiarne, zdravotné alebo environmentálne riziko mimo rozsahu inšpekcie;
- nákladné alebo nevratné rozhodnutie stojí na nízkej či strednej confidence;
- monitoring ukazuje progresívny alebo rýchly vývoj.

Odporúčanie má uviesť vhodnú profesiu, otázku na overenie, potrebné podklady, naliehavosť a závislosti. Nemá sľubovať výsledok externého posúdenia.

Technická inšpekcia DoktorHaus nie je automaticky znalecký posudok, statický posudok, revízna správa ani laboratórne vyšetrenie. Report musí toto obmedzenie uviesť bez znižovania praktickej hodnoty zistení.

## Minimálna QA kontrola tvrdenia

Pred `APPROVE` musí inšpektor pri každom významnom issue potvrdiť:

- observation je napísané bez neoverenej príčiny;
- hypotézy sú oddelené a majú rationale;
- supporting aj contradicting evidence je dohľadateľné;
- missing information a limitations sú viditeľné;
- confidence zodpovedá podkladom;
- skóre má odôvodnenie a osi sa nezamieňajú;
- recommendation je samostatná, vykonateľná a má dependencies;
- klientsky text neprekračuje rozsah inšpekcie.
