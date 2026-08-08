# Architektúra diagnostickej vrstvy

## Účel

Cieľom je rozšíriť existujúci web DoktorHaus o diagnostickú vrstvu bez predčasnej zmeny technologického smeru. Vrstva má premeniť terénne pozorovania a dôkazy na kontrolovateľné diagnostické problémy, hypotézy, overenia, odporúčania a verziovaný klientsky report.

## Súčasná relevantná architektúra podľa repozitára

### Verejný a klientsky frontend

- Web tvoria samostatné HTML stránky, globálny `styles/style.css` a inline vanilla JavaScript.
- `JSS/header.html` a `JSS/footer.html` sa do stránok vkladajú klientskym `fetch()`; navigácia už obsahuje verejnú položku `Inšpekcie` a samostatnú obsahovú vetvu `Diagnostika`.
- `inspekcie.html` obsahuje verejný odkaz na statickú vzorovú stránku a formulár pre šesťmiestny klientsky PIN. Po úspešnom POSTe vykreslí v tej istej stránke názov, opis, odkazy na médiá a fotogalériu.
- `inspekcia-vzor.html` je samostatná, napevno napísaná verejná ukážka. Obsahuje PDF v `uploads/inspekcie/vzor/`, externý Google Docs odkaz, Panoraven, YouTube a ukážkové SVG fotografie. Nie je generovaná z diagnostického dátového modelu.
- Všetky uvedené stránky používajú existujúci vizuálny jazyk a triedy z globálneho CSS.

### Backoffice

- `backoffice.html` je rozcestník interných modulov. PIN overuje cez `api/backoffice.php`, ale úspešný stav rozcestníka drží iba ako príznak v `sessionStorage`.
- `inspekcie-admin.html` je samostatný vanilla JS formulár. Pri každej operácii posiela Admin PIN do `api/inspections.php`.
- Formulár spravuje základné metadáta, email, priame URL dokumentov a médií a ručne vložené JSON pole fotografií.
- Aktuálne stavy sú `draft`, `ready` a `sent`. Akcia `mark-ready` je dnešná manuálna publikačná brána: nastaví `ready` a prvýkrát vygeneruje PIN. `send-email` používa PHP `mail()` a nastaví `sent`; `set-draft` vráti záznam do konceptu.

### PHP a runtime dáta

- `api/inspections.php` je jeden POST endpoint prepínaný poľom `action`: `unlock`, `admin-list`, `save`, `mark-ready`, `send-email`, `set-draft`.
- Konfigurácia sa číta z environment premenných alebo z lokálneho `api/inspections.config.php`; Admin PIN môže spätne použiť aj `PUBLIC_HELP_PIN`.
- Dáta sa ukladajú ako jedno JSON pole v runtime súbore `data/inspections.json`. Zápis používa `LOCK_EX`; produkčná databáza neexistuje.
- Klientsky `unlock` prehľadá záznamy podľa PINu a vráti záznam iba v stave `ready` alebo `sent`.
- `api/.htaccess` blokuje priame načítanie konfiguračných PHP súborov. `data/.htaccess` blokuje celý adresár. `uploads/.htaccess` blokuje vykonanie PHP, ale nebráni priamemu načítaniu obrázkov a dokumentov.

### Nasadenie

- `.github/workflows/deploy-ftp.yml` je ručne spúšťaný GitHub Actions workflow. Checkoutnutý strom zrkadlí cez `lftp` na FTP/FTPS hosting.
- Workflow explicitne chráni runtime dáta a uploady modulu Pomoc verejnosti, ale nemá zodpovedajúce výnimky pre `api/inspections.config.php`, `data/inspections.json` ani budúce súkromné médiá inšpekcií.
- `.gitignore` ignoruje `data/inspections.json`, ale podľa aktuálneho súboru neignoruje `api/inspections.config.php`. To je nesúlad, ktorý treba vyriešiť pred produkčným ukladaním tajomstiev do tohto súboru.

## Cieľová logická architektúra

Technologicky zostáva vhodný jednoduchý HTML/CSS/vanilla JS frontend a PHP backend. Nasledujúce sú logické hranice, nie požiadavka na framework alebo samostatné služby.

### 1. Vrstva zdrojových dát

Vlastní pôvodný SafetyCulture export alebo budúcu API/webhook odpoveď, prílohy a importné metadáta. Uchováva zdrojový identifikátor, čas importu a pôvod hodnoty. Zdrojové dáta sa pri diagnostickej úprave neprepisujú.

### 2. Normalizačná vrstva

Prevedie zdroj do kanonických objektov `property`, `inspection`, `observation` a `evidence`. Zachová väzbu späť na zdroj. Nevykonáva diagnózu a nemení checklistové položky na potvrdené príčiny.

### 3. Diagnostická doménová vrstva

Spravuje `diagnostic_issue`, `hypothesis`, `verification`, `impact`, `recommendation` a väzby medzi nimi. Tu platia pravidlá oddelenia pozorovania, interpretácie, hypotézy a potvrdeného zistenia. Skóre a náklady sa ukladajú s odôvodnením, nie iba ako čísla.

### 4. Validácia a QA

Kontroluje povinné polia, dovolené hodnoty, referenčnú integritu, interval nákladov, neúplné hypotézy, chýbajúce dôkazy, konflikt skóre a publikačné podmienky. Strojová validácia nenahrádza odborné schválenie inšpektorom.

### 5. Reportová vrstva

Z vybranej, schválenej verzie zostaví klientsky rozhodovací pohľad. Report je projekcia diagnostických dát, nie druhé miesto, kde sa ručne vytvára diagnóza. Publikovaná verzia je nemenný snapshot; doplnenie merania vytvorí novú verziu.

### 6. Autorizačná a publikačná vrstva

Server overuje interného používateľa aj klienta, vytvára autorizovanú session a až po `APPROVE` sprístupní konkrétnu verziu reportu. Súkromné dáta a médiá sa vydávajú cez autorizovaný serverový tok, nie cez verejné predvídateľné URL.

### 7. Prezentačná vrstva

Existujúci klientsky vstup `inspekcie.html`, backoffice a globálny vizuálny jazyk sa majú zachovať. Budúci renderer smie čítať iba publikovaný reportový kontrakt a nesmie odvodzovať diagnózu v prehliadači.

## Hranice a smer toku

- SafetyCulture je evidencia terénneho zberu, nie autorita pre výslednú diagnózu.
- Normalizácia môže čistiť formát, ale nesmie dopĺňať nevyslovené závery.
- Diagnostická vrstva môže zoskupiť viac pozorovaní do jedného problému; pôvodné pozorovania zostávajú dohľadateľné.
- Validátor môže zablokovať publikovanie, ale nemôže sám udeliť odborné `APPROVE`.
- Report nesmie spätne meniť doménové dáta. Zmena záveru vzniká v diagnostike a vedie k novej verzii reportu.
- Autorizácia sa uplatňuje na dáta aj binárne médiá. Skrytie odkazu v HTML nie je ochrana.

## Čo sa zachová

- jednoduchý HTML/CSS/vanilla JS frontend;
- PHP pre serverové operácie;
- spoločný header, footer, globálne CSS a vizuálny jazyk DoktorHaus;
- `inspekcie.html` ako klientsky vstup a existujúci backoffice ako miesto interného workflow;
- manuálne odborné schválenie pred publikovaním;
- GitHub ako zdroj kódu a FTP workflow, pokiaľ hosting zostane rovnaký.

## Čo sa neskôr zmení

- plochý runtime záznam inšpekcie sa oddelí na zdrojové, diagnostické a reportové objekty;
- otvorený klientsky PIN sa nahradí hashom a serverovou session;
- `ready` sa spresní na auditovateľný `APPROVE` viazaný na konkrétnu verziu;
- priame súkromné URL sa nahradia autorizovaným doručením;
- pribudne validácia referencií, stavov a publikačného kontraktu;
- report získa explicitné verzie a históriu;
- deploy výnimky a práca s runtime dátami sa zosúladia s bezpečnostným modelom;
- budúci import môže nahradiť manuálny PDF tok SafetyCulture API/webhookom bez zmeny diagnostického modelu.

## Čo teraz explicitne nezavádzame

- React, Vue, Next, Laravel ani Node backend;
- produkčnú databázu alebo migrácie;
- JSON Schema;
- nový renderer klientského reportu;
- autentifikáciu, session, rate limiting alebo privátny media endpoint;
- upload súborov;
- SafetyCulture API, webhook alebo automatickú diagnostiku;
- zmenu existujúceho HTML, PHP, CSS, JS alebo deploy workflow.

## Architektonické rozhodnutia otvorené pred implementáciou schém

1. Formát stabilných ID a pravidlá ich generovania naprieč importmi.
2. Presná hranica medzi `inspection.json` a `diagnosis.json`, najmä umiestnenie normalizovaných observations a evidence.
3. Číselník kategórií, oblastí, stavov a odborných špecializácií.
4. Stupnice jednotlivých impact dimensions a risk levels.
5. Či MVP ostane na oddelených JSON súboroch na hostingu alebo sa databáza zavedie až po overení workflow.
6. Spôsob nemenného snapshotu reportu a referencovania médií medzi verziami.
7. Kto môže vykonať `APPROVE`, ako sa identifikuje a aký auditný záznam je minimálny.
8. Cieľové úložisko privátnych médií a spôsob autorizovaného streamovania alebo sťahovania.
9. Politika expirácie, obnovy a zrušenia klientskych prístupov.
10. Pravidlá mapovania SafetyCulture identifikátorov a opakovaných importov bez duplikácie.
