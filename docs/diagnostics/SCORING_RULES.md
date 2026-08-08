# Pravidlá skórovania

## Nezávislé osi

Severity, likelihood, urgency, priority, deterioration rate, risk, impact, confidence a cost escalation risk nie sú synonymá. Každá odpovedá na inú otázku:

- severity: aký závažný je následok;
- likelihood: ako pravdepodobný alebo pozorovaný je hodnotený jav;
- urgency: dokedy treba konať;
- priority: v akom poradí a s akou rozhodovacou váhou má klient konať;
- deterioration rate: ako rýchlo sa stav mení;
- short/long-term risk: aká je expozícia v danom časovom horizonte;
- impact: v ktorej oblasti a v akej miere problém pôsobí;
- confidence: ako isté je diagnostické tvrdenie;
- cost escalation risk: ako môže odklad zvýšiť rozsah alebo cenu zásahu.

Skóre sa nesmie určiť iba mechanickým násobením čísiel. Automatizácia môže upozorniť na nekonzistentnosť, konečné hodnotenie a rationale schvaľuje inšpektor.

## Severity S1–S5

Severity vyjadruje závažnosť rozumného relevantného následku, nie čas opravy.

- `S1 – zanedbateľné`: bez podstatného vplyvu na bezpečnosť, funkciu alebo životnosť; prevažne kozmetický či informačný význam.
- `S2 – nízke`: lokálny obmedzený následok, jednoduchá údržba alebo menšie zníženie funkcie bez významného rizika.
- `S3 – stredné`: citeľný vplyv na funkciu, trvanlivosť, vlhkosť, použiteľnosť alebo náklady; vyžaduje plánovaný zásah.
- `S4 – vysoké`: vážny následok pre bezpečnosť, konštrukciu, zdravie, funkciu alebo financie; môže vyžadovať odborné preverenie a skorý zásah.
- `S5 – kritické`: možný alebo prítomný následok s neprijateľným dopadom, najmä bezprostredné ohrozenie života, zásadná strata stability alebo nepoužiteľnosť; vyžaduje okamžitú kontrolu primeraných opatrení.

Severity sa určuje pre presne opísaný scenár následku. Ak je scenár neistý, likelihood a confidence zostávajú samostatné.

## Likelihood L1–L5

Likelihood hodnotí samotný jav alebo scenár, ktorý je pri issue výslovne pomenovaný.

- `L1 – nepravdepodobné`: dostupné údaje scenár výrazne nepodporujú, hoci ho nemožno úplne vylúčiť.
- `L2 – možné`: scenár je technicky možný, podpora je však obmedzená alebo existujú rovnocenné alternatívy.
- `L3 – pravdepodobné`: viac relevantných podkladov smeruje k scenáru a je pravdepodobnejší než hlavné alternatívy.
- `L4 – veľmi pravdepodobné`: silné, navzájom súladné podklady; alternatívy sú podstatne menej pravdepodobné.
- `L5 – prebiehajúce alebo priamo pozorované`: hodnotený jav práve prebieha alebo bol priamo a vhodne pozorovaný/zmeraný.

L5 sa nesmie použiť na nepozorovanú príčinu iba preto, že jej prejav je viditeľný. Likelihood musí pomenovať predmet hodnotenia.

## Urgency U1–U5

Urgency určuje odporúčaný čas začatia bezpečnostného opatrenia, overenia alebo zásahu.

- `U1 – okamžite`: konať bez zbytočného odkladu; podľa situácie obmedziť používanie alebo prizvať príslušného špecialistu.
- `U2 – približne do 1 mesiaca`: zabezpečiť odborné preverenie alebo zásah v krátkom termíne.
- `U3 – približne do 3 mesiacov`: zaradiť do najbližšieho plánovania a nenechať bez vlastníka.
- `U4 – približne do 12 mesiacov`: riešiť v ročnom pláne údržby alebo opráv.
- `U5 – plánovaná údržba / sledovanie`: bez potreby skorého zásahu, ale s určeným monitoringom alebo bežnou údržbou.

Čas je orientačný a musí ho sprevádzať textový dôvod. Zmena prejavov alebo podmienok môže urgency zvýšiť.

## Priority P1–P5

Priority je rozhodovacie poradie. Nie je kópiou severity a nevyjadruje confidence.

- `P1 – okamžite riešiť`: ochranné opatrenie, urgentné preverenie alebo zásah nesmie čakať na bežný plán.
- `P2 – vysoká priorita`: skorý krok s významným vplyvom na riziko, degradáciu, náklady alebo nadväzujúce práce.
- `P3 – plánovať`: dôležitý krok, ktorý má dostať rozpočet, termín a zodpovednosť.
- `P4 – nízka priorita`: vhodné riešiť pri súvisiacej údržbe alebo rekonštrukcii.
- `P5 – sledovať`: aktuálne postačuje dokumentované sledovanie alebo bežná údržba.

Pri určení priority sa posudzuje minimálne:

- safety impact;
- severity a likelihood pomenovaného scenára;
- urgency;
- deterioration rate;
- cost escalation risk;
- dependency – či krok odblokuje alebo chráni ďalšie opravy;
- možnosť dočasného bezpečného opatrenia;
- confidence a potreba najprv overiť príčinu.

Príklady rozdielu:

- S4 môže mať P2, ak nejde o bezprostredný stav, ale vyžaduje skoré odborné posúdenie.
- S2 môže mať P2, ak lacné odvedenie vody zabráni rýchlej a drahej degradácii.
- Issue s nízkou confidence môže mať vysokú prioritu pre `VERIFY`, nie automaticky pre definitívnu opravu.

## Deterioration rate

- `stable`: dostupné porovnanie neukazuje zmenu v relevantnom období;
- `slow`: stav sa zhoršuje pomaly, typicky v horizonte rokov alebo sezón;
- `progressive`: zhoršovanie je preukázateľné a pokračuje; časový rámec treba uviesť;
- `rapid`: významná zmena v krátkom období alebo podmienky podporujú rýchlu eskaláciu;
- `unknown`: nie je dostatok časových údajov.

Jednorazová obhliadka spravidla neoprávňuje označiť stav ako stable. Bez porovnania je vhodné `unknown`, prípadne odôvodnený odhad s obmedzením.

## Krátkodobé a dlhodobé riziko

Risk kombinuje scenár, následok, expozíciu a časový horizont. Obidva horizonty majú samostatný level a description.

Spoločné úrovne:

- `none`: v definovanom rozsahu sa relevantné riziko nezistilo;
- `low`: obmedzený následok alebo expozícia, postačuje bežná kontrola;
- `moderate`: reálny scenár vyžadujúci plánované opatrenie alebo overenie;
- `high`: významný scenár, pri ktorom odklad alebo expozícia nie sú rozumné bez opatrenia;
- `critical`: neprijateľné riziko vyžadujúce okamžité ochranné kroky alebo odborné rozhodnutie;
- `unknown`: údaje nestačia na poctivé hodnotenie.

Krátkodobý horizont je obdobie do vykonania najbližšieho odporúčaného kroku, najviac približne 12 mesiacov. Dlhodobý horizont je ďalší životný cyklus objektu a opráv, typicky nad 12 mesiacov. Konkrétny horizont sa uvedie v description.

Level bez description nie je dostatočný. Description obsahuje scenár, dotknuté osoby alebo konštrukcie, predpokladané podmienky a čo môže level zmeniť.

## Impact dimensions

Každý issue sa posúdi v siedmich povinných rozmeroch:

- `safety_impact`: zranenie, pád, požiar, kolaps alebo iné ohrozenie osôb;
- `structural_impact`: stabilita, únosnosť, deformácie a integrita nosných častí;
- `moisture_impact`: vnikanie, transport alebo akumulácia vody a vlhkosti;
- `health_impact`: plesne, kvalita vnútorného prostredia a zdravotne relevantná expozícia;
- `durability_impact`: životnosť materiálov a konštrukcií;
- `usability_impact`: funkcia, komfort, dostupnosť a obmedzenie používania;
- `financial_impact`: rozsah súčasných nákladov, strata hodnoty alebo finančná expozícia.

Pre každý rozmer sa použije `none`, `low`, `moderate`, `high`, `critical` alebo `unknown`, doplnené konkrétnym opisom a confidence. Hodnota `none` znamená vyhodnotené bez relevantného dopadu; `unknown` znamená nevyhodnotiteľné.

## Cost escalation risk

Cost escalation risk hodnotí pravdepodobnosť a veľkosť zvýšenia budúcich nákladov pri odklade alebo nesprávnom poradí prác. Používa úrovne `none`, `low`, `moderate`, `high`, `critical`, `unknown` a textové rationale.

Posudzuje najmä:

- či aktívny mechanizmus rozširuje poškodenie;
- či lacný predchádzajúci krok chráni drahšiu konštrukciu;
- sezónnosť a časové okno zásahu;
- riziko znehodnotenia novej opravy nevyriešenou príčinou;
- závislosti medzi recommendations;
- neistotu rozsahu skrytého poškodenia.

Cost escalation risk nie je dnešný odhad ceny a nemá sa automaticky premietnuť do severity.

## Konzistenčné kontroly

Validácia má upozorniť, nie automaticky prepísať hodnotenie, napríklad keď:

- S5 alebo critical safety impact nemá U1/P1 alebo jasné rationale;
- rapid deterioration má U4/U5 bez vysvetlenia;
- L5 opisuje príčinu, ktorá nebola priamo pozorovaná;
- short-term risk je critical, ale chýba IMMEDIATE/VERIFY recommendation;
- priority kopíruje severity pri všetkých issues bez individuálneho rationale;
- impact je high/critical bez description alebo evidence;
- confidence je high pri významnom contradicting evidence bez vysvetlenia;
- cost escalation risk je high/critical bez dependency alebo mechanizmu eskalácie.
