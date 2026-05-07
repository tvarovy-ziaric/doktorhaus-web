# Inšpekcie a PIN prístup

## Produktová logika

- Verejne sa zobrazuje iba vzorová inšpekcia.
- Klientske inšpekcie sa nezobrazujú vo verejnom zozname.
- Klient dostane email s linkom na `inspekcie.html` a 6-miestnym PINom.
- Po zadaní PINu sa klientovi zobrazí iba jeho vlastná inšpekcia.
- PIN sa má vygenerovať až po ručnom potvrdení v backoffice, že výstupy sú pripravené.
- Nie každá inšpekcia musí obsahovať všetky typy médií. Pri bytoch napríklad nemusí existovať oblet dronom alebo 360 video.
- Fotogaléria má byť voliteľná súčasť výstupu. Fotky majú byť číslované podľa PDF správy a pomenované podľa ID nálezu, napríklad `Foto 001` a `P102 - Krov typický`.

## Backoffice stav

Navrhované stavy inšpekcie:

- `draft`: pripravuje sa, bez PINu, nič sa neposiela klientovi.
- `ready`: ručne potvrdené v backoffice, systém vygeneruje 6-miestny PIN.
- `sent`: klientovi bol odoslaný email s poďakovaním, linkom a PINom.

## Fotogaléria

Navrhované údaje pri fotke:

- číslo fotky podľa správy, napríklad `Foto 001`,
- ID a krátky názov nálezu, napríklad `P102 - Krov typický`,
- thumbnail obrázok,
- plný obrázok.

Preferovaný štart je hosting na doktorhaus.sk v štruktúre podobnej `uploads/inspekcie/{slug}/fotky/`. Neskôr sa dá nahradiť externým úložiskom, ak bude fotiek alebo dát príliš veľa.

## Email klientovi

Email má byť krátky:

- poďakovanie za spoluprácu,
- link na `inspekcie.html`,
- 6-miestny PIN,
- krátka informácia, že po zadaní PINu sa zobrazia jeho výstupy.
