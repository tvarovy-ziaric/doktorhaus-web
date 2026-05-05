# Pomoc verejnosti - backend

Tento modul používa malý PHP endpoint `api/public-help.php`.

## Nastavenie

1. Hosting musí spúšťať PHP. Pri obyčajnom statickom serveri sa PHP nevykoná.
2. Nastavte premenné prostredia:
   - `PUBLIC_HELP_PIN` - PIN pre admin akcie.
   - `OPENAI_API_KEY` - voliteľné, ak má návrhy generovať OpenAI.
   - `OPENAI_MODEL` - voliteľné, napríklad `gpt-5.4-mini`.
3. Ak hosting nevie env premenné, skopírujte `api/public-help.config.example.php` ako `api/public-help.config.php` a vyplňte hodnoty.

## Použitie

- Verejná stránka: `pomoc-verejnosti.html`
- Interné zadávanie: `pomoc-admin.html`
- Fotky sú voliteľné. Pri publikovaní sa ukladajú do `uploads/pomoc-verejnosti/`.
- Podporované obrázky: JPG, PNG, WEBP, najviac 5 fotiek po 5 MB.

Ak `OPENAI_API_KEY` nie je nastavený alebo server nemá PHP cURL, admin stránka stále vytvorí jednoduchý lokálny návrh z vložených textov.
