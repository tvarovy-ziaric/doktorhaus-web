# Finančný model

## Účel a hranice

Finančný model dáva klientovi orientačný rámec pre rozhodovanie. Nie je rozpočet, cenová ponuka ani záväzná trhová cena. V tejto fáze neobsahuje slovenský cenník ani databázu jednotkových cien.

Každý odhad musí byť viazaný na konkrétny scope, assumptions, exclusions, dátum/cenovú bázu a confidence. Ak tieto údaje chýbajú, vhodnejšie je uviesť `not_estimated` než presné číslo.

Finančný model má dve odlišné vrstvy:

- `issue.cost_estimate` je odhad celého definovaného technického scope issue;
- report-level pricing components sú čiastkové ceny overení, materiálu, jednotkových položiek, podmienených prác alebo presne vymedzených beznákladových opatrení.

Cena jednej recommendation sa nesmie zapísať do `issue.cost_estimate`, ak nepokrýva celý issue scope. Validný stav je napríklad issue `not_estimated`, report pricing odborné overenie `100–200 EUR`, materiál `30 EUR/ks` a definitívna oprava `not_estimated`.

## Kontrakt cost estimate 1.0.0

`cost_estimate.status` je `estimated` alebo `not_estimated`.

Ak je status `estimated`, povinné sú:

- `min`: rozumná dolná hranica pri opísanom priaznivom scenári;
- `expected`: najpravdepodobnejší orientačný rámec v rámci známeho scope, nie aritmetický priemer;
- `max`: rozumná horná hranica pri opísaných neistotách, nie teoreticky najhorší možný prípad;
- `currency`: jednotná mena všetkých troch hodnôt;
- `confidence`: low, medium, high alebo unknown;
- `price_basis_date`: dátum alebo obdobie, ku ktorému rámec patrí;
- `scope`, `assumptions`, `exclusions`;
- `source_method`: expert_range, comparable_work, unit_price_database, supplier_quote alebo budúci číselník;
- `vat_status`: included, excluded, mixed alebo unknown, ak je relevantné.

Ak je status `not_estimated`, povinný je neprázdny `reason` a min/expected/max sa nepoužijú.

Musí platiť `min ≤ expected ≤ max`. JSON Schema kontroluje štruktúru a typy; poradie čísel kontroluje domain lint ako `E_COST_RANGE`. Nula je povolená iba vtedy, keď naozaj znamená žiadny predpokladaný náklad v definovanom scope; neznámy náklad sa zapisuje ako `not_estimated`.

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

## Finančné pásmo pre klientsky UI

Kontrakt 1.0.0 neurčuje pevné EUR A–E pásma. `financial_ui_band` je voliteľný string alebo null a je výslovne budúcou konfigurovanou hodnotou, nie zdrojom ceny. UI má vždy prioritne zobraziť zaokrúhlený interval a confidence. Hranice, mena a verzia budúcej konfigurácie sa musia určiť mimo diagnostického objektu.

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

Kontrakt ho ukladá ako object `cost_escalation` s poľami:

- `level` podľa [SCORING_RULES.md](SCORING_RULES.md);
- `mechanism`;
- `trigger`;
- `preventive_step`;
- `confidence`;
- `rationale`.

Budúci náklad po eskalácii sa uvedie len vtedy, keď má vlastný scope, assumptions a interval. Inak sa opisuje kvalitatívne.

## Agregácia do reportu

Finančný rámec reportu má rozlišovať:

- okamžité ochranné kroky;
- verification a odborné posúdenia;
- prioritné opravy;
- plánované opravy a údržbu;
- podmienené práce, ktoré sa nesčítavajú, kým sa nepotvrdí variant.

Report nemá bez úpravy sčítať min/expected/max všetkých issues. Agregácia musí odstrániť duplicity, rešpektovať spoločné recommendations a označiť podmienené varianty. Metóda agregácie musí byť dohľadateľná.

## Report-level pricing components

Normatívny additive kontrakt je `schemas/report-pricing.schema.json`. Podporuje:

- `total_range` pre legitímne vymedzený celkový scope komponentu;
- `unit_range` a `fixed_unit` pre materiál alebo službu podľa jednotky;
- `no_direct_cost` ako explicitné `0/0/0` pre presný scope, nie ako náhradu neznámej ceny;
- `not_estimated` s neprázdnym dôvodom a voliteľným zoznamom údajov, ktoré treba doplniť.

Jednotková položka bez známeho množstva je platná, ale nesmie mať `computed_total` ani vstúpiť do subtotalu. Podmienený komponent je mimo nepodmieneného subtotalu. Zdieľaný komponent môže odkazovať na viac issues, ale v explicitnom allowliste subtotalu sa objaví iba raz. `not_estimated` sa nesčítava. `no_direct_cost` môže prispieť nulou, ale nesmie meniť interpretáciu agregácie.

Agregácia má iba dva stavy:

- `not_computed` s dôvodom — preferovaný pri neúplných alebo neporovnateľných údajoch;
- `subtotal` nad explicitným allowlistom spôsobilých komponentov, s kontrolou meny, duplicít a súčtu min/expected/max.

## Ownership a interné obchodné dáta

Client-owned consumable alebo material možno klientsky naceniť iba po skutočnom výbere daného riešenia. Service-provider equipment je opakovane používané vybavenie poskytovateľa; jeho obstarávacia cena nie je klientsky remediation cost.

Interný tarif DoktorHaus, interný labour costing, equipment acquisition cost, interné cestovné, margin, markup, interné obchodné poznámky a súkromné dodávateľské rokovania nesmú vstúpiť do client diagnostic report, client pricing projection, client delivery payload ani client-visible report package projection. Môžu existovať iba v samostatnom private/internal business systéme. Klientské kontrakty sú allowlist-based a tieto polia nesmú odvodiť ani serializovať.

## Budúca databáza jednotkových cien

Neskorší cenový modul môže obsahovať verziované položky s jednotkou, regiónom, platnosťou, zdrojom, DPH, rozsahom dodávky a confidence. Diagnostický issue nebude vlastniť jednotkové ceny; odhad si uloží použitú verziu vstupov alebo snapshot, aby sa publikovaný report spätne nezmenil.

Napojenie musí podporiť:

- množstvá a jednotky;
- varianty technického riešenia;
- vedľajšie a prípravné práce;
- indexáciu v čase bez prepisu starého reportu;
- manuálnu odbornú korekciu s rationale;
- výpočet min/expected/max namiesto jediného bodu.

Zostávajú otvorené: konkrétny zdroj cien, hranice a verzovanie budúcej UI konfigurácie, pravidlá zaokrúhľovania a agregácia naprieč issues bez dvojitého započítania. Samotná štruktúra issue cost estimate je kontraktom 1.0.0 uzavretá.
