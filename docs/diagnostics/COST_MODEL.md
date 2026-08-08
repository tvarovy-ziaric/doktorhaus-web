# Finančný model

## Účel a hranice

Finančný model dáva klientovi orientačný rámec pre rozhodovanie. Nie je rozpočet, cenová ponuka ani záväzná trhová cena. V tejto fáze neobsahuje slovenský cenník ani databázu jednotkových cien.

Každý odhad musí byť viazaný na konkrétny scope, assumptions, exclusions, dátum/cenovú bázu a confidence. Ak tieto údaje chýbajú, vhodnejšie je uviesť `not_estimated` než presné číslo.

## Povinné hodnoty odhadu

- `estimated_cost_min`: rozumná dolná hranica pri opísanom priaznivom scenári;
- `estimated_cost_expected`: najpravdepodobnejší orientačný rámec v rámci známeho scope, nie aritmetický priemer;
- `estimated_cost_max`: rozumná horná hranica pri opísaných neistotách, nie teoreticky najhorší možný prípad;
- `currency`: jednotná mena všetkých troch hodnôt;
- `cost_estimate_confidence`: low, medium, high alebo unknown;
- `price_basis_date`: dátum alebo obdobie, ku ktorému rámec patrí;
- `scope`, `assumptions`, `exclusions`;
- `source_method`: expert_range, comparable_work, unit_price_database, supplier_quote alebo budúci číselník;
- `vat_status`: included, excluded, mixed alebo unknown, ak je relevantné.

Musí platiť `min ≤ expected ≤ max`. Nula je povolená iba vtedy, keď naozaj znamená žiadny predpokladaný náklad v definovanom scope; neznámy náklad sa zapisuje ako chýbajúci odhad.

## Confidence odhadu

- `low`: scope alebo množstvá sú nejasné, chýba sonda/ponuka, existujú významné skryté práce alebo je použitý iba široký expertný rámec;
- `medium`: rozsah je primerane opísaný a existujú porovnateľné práce alebo čiastkové množstvá, stále však zostávajú významné trhové či realizačné neistoty;
- `high`: scope, množstvá a technické riešenie sú dobre definované a podopreté aktuálnymi položkovými cenami alebo relevantnými ponukami;
- `unknown`: odhad nie je dostatočne podložený na určenie confidence.

Samotná technická obhliadka bude mať pri nákladoch často low alebo medium confidence. High sa nemá používať bez podkladov podobných rozpočtu alebo konkrétnym ponukám.

## Scope, assumptions a exclusions

`scope` presne vymedzuje, čo interval zahŕňa: oblasť, množstvo, typ zásahu, nadväzujúce práce a požadovaný výsledok.

`assumptions` pomenúva podmienky, na ktorých interval stojí, napríklad prístupnosť, predpokladanú skladbu, lokálny rozsah alebo možnosť zachovať existujúce prvky.

`exclusions` uvádza, čo v intervale nie je: projekt, statické posúdenie, laboratórium, dočasné ubytovanie, obnova skrytých konštrukcií, DPH alebo iné konkrétne položky podľa prípadu.

Ak významná missing information môže zásadne zmeniť rozsah, report má uviesť samostatný náklad verification a podmienený rámec opravy, nie ich zlúčiť do jedného presného čísla.

## Finančné pásma pre klientsky UI

UI má vždy prioritne zobraziť zaokrúhlený interval a confidence. Voliteľné pásmo slúži iba na rýchlu orientáciu:

- `not_estimated`: bez poctivého finančného rámca;
- `A – drobný zásah`;
- `B – menší rozsah`;
- `C – stredný rozsah`;
- `D – významný zásah`;
- `E – investične náročný zásah`.

Číselné hranice A–E sa v tejto foundation verzii zámerne neurčujú. Nie sú slovenským cenníkom a nesmú sa natvrdo rozptýliť po klientskom UI. Pred implementáciou sa zvolia ako samostatná, verziovaná konfigurácia podľa meny, obdobia a overených cenových dát. Pásmo sa počíta z `expected` a šírky intervalu; nesmie nahradiť zobrazenie min–max.

Ak interval prechádza viacerými pásmami alebo má low confidence, UI to má priznať namiesto výberu optimistickejšieho pásma.

## Pravidlá proti falošnej presnosti

- Zaokrúhľovať podľa veľkosti a confidence; nepoužívať centy ani neodôvodnené jednotky eur.
- Uprednostniť „2 000–5 000 EUR, confidence low“ pred „3 417 EUR“ bez rozpočtu.
- Expected nie je cenová ponuka a nemá sa vizuálne prezentovať ako jediná výsledná cena.
- Šírka intervalu má odrážať neistotu. Úzky interval pri low confidence je nekonzistentný.
- Nezlučovať odlišné varianty riešenia do jedného priemeru. Každý variant má vlastný scope a interval.
- Neuvádzať náklady na definitívnu sanáciu, kým nie je primerane známa príčina a rozsah; možno uviesť náklad verification.
- Nezamieňať súčet jednotlivých issue odhadov za cenu celého projektu. Spoločné práce sa môžu prekrývať a dependencies môžu rozsah meniť.
- Vždy uviesť obdobie cenovej bázy a upozorniť na regionálne, dodávateľské a trhové rozdiely.

## Cost escalation risk

Cost escalation risk je samostatné hodnotenie. Vysvetľuje, ako môže odklad, pokračujúce pôsobenie vody, nesprávne poradie alebo prekrytie príčiny finálnou úpravou zväčšiť budúci rozsah.

Má obsahovať:

- level podľa [SCORING_RULES.md](SCORING_RULES.md);
- mechanizmus eskalácie;
- časový alebo podmienkový spúšťač;
- lacnejší preventívny/verification krok, ak existuje;
- affected recommendations a dependencies;
- confidence.

Budúci náklad po eskalácii sa uvedie len vtedy, keď má vlastný scope, assumptions a interval. Inak sa opisuje kvalitatívne.

## Agregácia do reportu

Finančný rámec reportu má rozlišovať:

- okamžité ochranné kroky;
- verification a odborné posúdenia;
- prioritné opravy;
- plánované opravy a údržbu;
- podmienené práce, ktoré sa nesčítavajú, kým sa nepotvrdí variant.

Report nemá bez úpravy sčítať min/expected/max všetkých issues. Agregácia musí odstrániť duplicity, rešpektovať spoločné recommendations a označiť podmienené varianty. Metóda agregácie musí byť dohľadateľná.

## Budúca databáza jednotkových cien

Neskorší cenový modul môže obsahovať verziované položky s jednotkou, regiónom, platnosťou, zdrojom, DPH, rozsahom dodávky a confidence. Diagnostický issue nebude vlastniť jednotkové ceny; odhad si uloží použitú verziu vstupov alebo snapshot, aby sa publikovaný report spätne nezmenil.

Napojenie musí podporiť:

- množstvá a jednotky;
- varianty technického riešenia;
- vedľajšie a prípravné práce;
- indexáciu v čase bez prepisu starého reportu;
- manuálnu odbornú korekciu s rationale;
- výpočet min/expected/max namiesto jediného bodu.

Výber zdroja cien, hranice UI pásiem, pravidlá zaokrúhľovania a agregácie sú otvorené rozhodnutia pred implementáciou finančnej schémy.
