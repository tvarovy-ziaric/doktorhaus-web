# Klientsky renderer diagnostickej správy

Krok 5A pridáva samostatnú súkromnú stránku `inspekcia.html?access=acc_…`. Je tenkým klientom nad autorizačným kontraktom kroku 4A a client-safe delivery kontraktom kroku 4B. Nevytvára diagnózu, nemení poradie ani význam publikovaných dát a neposkytuje alternatívnu cestu k raw balíku.

## Súbory a hranice

- `inspekcia.html` obsahuje iba statický shell stavov, ovládanie, modál a privacy metadata;
- `JSS/diagnostics-client.js` vlastní session/PIN/report/logout lifecycle a sieťové požiadavky;
- `JSS/diagnostics-report.js` je čistý DOM renderer a sada testovateľných mapovaní/validátorov;
- `styles/diagnostics-report.css` izoluje reportové, responsive a print štýly cez prefix `diag-`;
- `inspekcie.html` môže pre linked inspection po serverovom overení existujúceho PINu presmerovať na túto stránku; grant, session a reportové endpointy zostávajú autoritatívne a legacy záznam bez bindingu sa naďalej vykreslí pôvodným spôsobom;

Stránka nenačítava externé fonty, knižnice, obrázky ani analytiku. Má `robots=noindex,nofollow,noarchive` a `referrer=no-referrer`. Nezapisuje access handle, PIN, CSRF token ani report do `localStorage`, `sessionStorage`, IndexedDB alebo service workera.

## Stavový model

Klient rozlišuje tieto používateľské stavy:

1. úvodné overovanie session;
2. PIN je potrebný;
3. PIN sa overuje;
4. report sa načítava;
5. report je pripravený;
6. session vypršala alebo prístup nie je aktívny;
7. všeobecná technická chyba.

Query string je fail-closed. Povolený je iba žiadny parameter alebo presne jeden `access` s tvarom `^acc_[0-9a-f]{32}$`. Neplatný, duplicitný alebo doplnkový parameter nespustí unlock.

Pri načítaní klient volá `GET api/diagnostics-auth.php` s cookies rovnakého originu a `cache: no-store`. Platná session bez `access` otvorí report viazaný na session. Rovnaký `access` otvorí ten istý report. Ak URL ukazuje na iný grant než aktívna session, klient vyžiada PIN pre explicitný odkaz a nikdy neprepne report iba podľa query parametra.

Unlock posiela výhradne JSON `{ action, accessId, pin }` na auth endpoint. PIN musí mať presne šesť číslic, po odpovedi sa input vymaže a nikdy sa neloguje. Klient rozlišuje bezpečné UX pre 401, 429 s približným `Retry-After`, 503 a sieťovú chybu, ale používateľovi nezobrazuje serverový text chyby.

Po úspechu sa report načíta iba cez `GET api/diagnostics-report.php`; klient neposiela report ID, verziu ani path. Pri 401 odstráni celý vykreslený report z DOM, zahodí CSRF token a vráti používateľa k PINu. Logout posiela `{ action: "logout", csrfToken }`, potom rovnako odstráni obsah. Access ID a CSRF existujú iba v premenných otvoreného dokumentu.

## XSS a media hranica

Všetky reportové hodnoty vstupujú do dokumentu cez `textContent`, `createTextNode`, `createElement`, `append` a `replaceChildren`. Renderer nepoužíva HTML parser pre obsah reportu, inline event handlery, `eval` ani dynamický script. Texty zo servera preto zostávajú textom aj vtedy, ak obsahujú HTML-like payload.

Media URL sa pred použitím znovu overuje v klientovi. Musí:

- mať rovnaký origin ako stránka;
- smerovať presne na `api/diagnostics-media.php`;
- obsahovať presne jeden parameter `evidence`;
- mať opaque ID `^ev_[0-9a-f]{16,32}$`;
- nemať fragment, userinfo ani ďalší selector.

Neplatná URL sa nezapíše do `src` ani `href`; karta zobrazí bezpečný textový fallback. Táto kontrola nenahrádza serverovú BOLA autorizáciu, je druhou obrannou hranicou proti chybnej alebo kompromitovanej projekcii.

## Informačná architektúra reportu

Renderer zachováva poradie publikovaného kontraktu a zobrazí:

- identitu nehnuteľnosti, dátum obhliadky, verziu a zmenu verzie;
- prehľad počtov a najvyššej priority/závažnosti bez house score;
- sekciu „Čo riešiť ako prvé“ z U1/U2 a odporúčaní naviazaných na P1/P2;
- voliteľný samostatný „Finančný rámec“ z report-level pricing komponentov;
- zistenia s pokojnou zbalenou orientáciou a úplným rozbaliteľným detailom;
- pozorovania a merania, interpretáciu, hypotézy, riziká, sedem dopadov, nákladový interval, eskaláciu, dôkazy, chýbajúce informácie, odporúčania a obmedzenia;
- samostatné poradie nápravy, neoverené položky, plán overení, vzťahy, rozsah/limity a verziu reportu.

Severity, likelihood, urgency, priority, confidence, risk, deterioration, statusy, typy, kategórie, odbornosť, dopady a vzťahy majú centrálne slovenské mapovanie. Neznámy enum sa neprepisuje ako odborný záver; zobrazí sa neutrálne „neuvedené“.

Náklady sa formátujú cez slovenské locale a zobrazujú sa iba v rámci jednotlivého issue ako minimum–maximum a sekundárna očakávaná hodnota. Renderer nevytvára súčet naprieč issues a zachová scope, confidence, predpoklady, výluky, cenový dátum aj disclaimer.

### Report-level pricing boundary

`renderCost(issue)` naďalej renderuje výhradne whole-issue `issue.cost_estimate`; jeho význam sa nezmenil. Voliteľné `report.pricing` renderuje samostatný `renderReportPricing()` medzi sekciami „Čo riešiť ako prvé“ a „Hlavné zistenia“. Ak pricing chýba, nevznikne prázdna sekcia.

Komponenty sa zoskupujú iba podľa štruktúrovaných polí do skupín „Bez priameho nákladu“, „Samostatne nacenené kroky“, „Materiál a jednotkové ceny“, „Podmienené náklady“ a „Zatiaľ nemožno poctivo naceniť“. Renderer nezoskupuje podľa názvu, issue textu ani case-specific ID. `no_direct_cost` sa označuje textom „Bez priameho nákladu“, nie ako oprava za 0 €. `not_estimated` nezobrazuje falošnú sumu a conditional komponent má viditeľný text „Podmienené ďalším overením“.

Pri `not_computed` sa nezobrazuje prázdny total, ale vysvetlenie so source reason. Pri `subtotal` sa používa označenie „Súčet vybraných položiek“ a explicitné upozornenie, že nejde o cenu všetkých opráv. Issue/recommendation väzby sa mapujú na display code + title alebo title; raw `issue_…`, `rec_…` a `rpc_…` nie sú primárnym klientskym textom. Assumptions a exclusions sú natívne `<details>` a print lifecycle ich dočasne otvorí spolu s issue detailmi.

## Médiá, prístupnosť a tlač

Obrázky používajú lazy loading a prvých osem dôkazov sa vykreslí bez ďalšieho zásahu; zvyšok otvorí vedomé tlačidlo. Fotografie jedného issue tvoria modálnu galériu s predchádzajúcou/ďalšou položkou, Escape, zatvorením cez pozadie, základným focus trapom a návratom fokusu. Video a audio majú natívne ovládanie bez autoplay. PDF/dokument ostáva autorizovaný same-origin odkaz.

Stavy používajú nadpisy a live status, PIN má explicitný label a numerickú mobilnú klávesnicu, interaktívne prvky sú klávesnicovo dostupné a `prefers-reduced-motion` potláča nepodstatný pohyb. Mobilný layout sa skladá do jedného stĺpca bez horizontálneho scrolovania.

Pred tlačou sa dočasne otvoria všetky detaily a po tlači sa obnoví ich pôvodný stav. Print CSS odstráni súkromné ovládanie, modal a prehrávače, ponechá fotografie a pridá jednoduchú hlavičku/pätu. Tlač z prehliadača je pohodlný výstup, nie samostatný auditovateľný PDF artifact ani PDF generátor.

## Overenie

```text
node tools/test_diagnostics_renderer.js
bash tools/test_diagnostics_renderer_http.sh
```

Node test overuje mapovania, urgentný výber, menu, access/media URL hranicu, pricing grouping a formulácie, umiestnenie sekcie, nezmenený `renderCost(issue)` boundary a zakázané klientské API. HTTP test na syntetickom immutable balíku overí stránku a assets, noindex/referrer, neprístupný report pred unlockom a reálny PIN unlock → client report tok. Existujúce projection, auth, storage a media testy zostávajú autoritatívnym dôkazom serverovej hranice.

`tools/test_inspection_diagnostics_bridge_http.sh` navyše overuje legacy fallback, admin binding validáciu, vynútenie rovnakého PINu, vytvorenie existujúcej diagnostics session, report po jednom PINe, logout, neaktívny/expirovaný grant, rate limit a neprítomnosť bindingu/PINu v nepovolených klientskych výstupoch.

## Čo krok 5A nerobí

Krok 5A nevytvára Babiná ani iné produkčné dáta, nevydáva grant/PIN, nemení backoffice, neintegruje SafetyCulture, nemigruje legacy inšpekcie, negeneruje klientsky PDF artifact, nezavádza databázu a nemení serverové schémy, projekciu, session či media autorizáciu.

## Source documentation appendix

Produkčný klient načíta voliteľný appendix zo samostatného session-bound endpointu. `404` znamená, že konkrétna verzia appendix nemá; iné chyby zlyhajú bezpečne. Produkčný klient a owner preview používajú rovnakú renderer funkciu. Sekcia a navigačná položka vzniknú iba pri reálnom appendixe; interaktívne menu ostáva v tlači skryté a fotografie appendixu sú zahrnuté.
