# Diagnostická vrstva DoktorHaus

Tento adresár je záväzný doménový a architektonický základ pre budúci systém klientskych diagnostických reportov. Oddeľuje terénne záznamy od odborného uvažovania a klientského výstupu. Neopisuje hotovú implementáciu a v tomto kroku nemení správanie webu.

Základný tok informácií je:

`PROPERTY → INSPECTION → OBSERVATIONS → EVIDENCE → DIAGNOSTIC ISSUES → HYPOTHESES → VERIFICATIONS → RECOMMENDATIONS → CLIENT REPORT`

SafetyCulture zostáva zdrojom terénneho zberu a surových záznamov. Diagnostická vrstva nad nimi má zoskupiť súvisiace prejavy, pomenovať neistotu, oddeliť možné mechanizmy od potvrdených zistení a pripraviť pokojný rozhodovací výstup pre klienta.

## Odporúčané poradie čítania

1. [ARCHITECTURE.md](ARCHITECTURE.md) – súčasný stav repozitára, cieľové vrstvy a hranice.
2. [DIAGNOSTIC_RULES.md](DIAGNOSTIC_RULES.md) – význam diagnostických tvrdení a pravidlá práce s neistotou.
3. [DATA_MODEL.md](DATA_MODEL.md) – entity, vlastníctvo, väzby a význam polí.
4. [SCORING_RULES.md](SCORING_RULES.md) – nezávislé osi závažnosti, pravdepodobnosti, naliehavosti a priority.
5. [COST_MODEL.md](COST_MODEL.md) – intervalové odhady nákladov a ochrana pred falošnou presnosťou.
6. [REPORT_CONTRACT.md](REPORT_CONTRACT.md) – obsah a pravidlá klientského reportu.
7. [SECURITY_MODEL.md](SECURITY_MODEL.md) – dnešný prototyp a cieľová ochrana klientskych dát.
8. [WORKFLOW.md](WORKFLOW.md) – manuálny MVP tok a budúce napojenie na SafetyCulture.

## Pravidlo zmeny

Zmena dátového modelu, diagnostických pravidiel, skórovania, finančného modelu, reportového kontraktu alebo bezpečnostnej hranice sa musí najprv premietnuť do týchto dokumentov. Až po odsúhlasení dokumentácie sa má meniť JSON Schema alebo produkčná implementácia.

Ak sa dokumentácia rozchádza s reálnym kódom, pri opise súčasného stavu je zdrojom pravdy repozitár. Rozdiel sa nemá potichu prekryť: treba ho pomenovať ako dlh, rozhodnutie alebo plánovanú migráciu.

## Rozsah tejto foundation verzie

Táto verzia vedome neobsahuje JSON Schema, databázové tabuľky, renderer reportu, autentifikáciu, upload, SafetyCulture API ani webhook. Nezavádza nový framework ani dependency.
