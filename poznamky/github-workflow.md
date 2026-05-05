# GitHub workflow pre DoktorHaus

## Čo bude GitHub robiť

- GitHub bude hlavná kópia kódu.
- Každá úprava bude uložená ako commit.
- Funkčnú verziu môžete neskôr ručne nasadiť na hosting cez GitHub Actions.

## Testovanie

GitHub Pages vie zobraziť statické HTML, CSS a obrázky. Nevykonáva PHP, preto:

- bežné stránky webu sa dajú testovať cez GitHub Pages,
- `pomoc-admin.html` a PHP backend treba plnohodnotne testovať na PHP hostingu alebo staging subdoméne.

## Deploy na hosting

Workflow je v `.github/workflows/deploy-ftp.yml`.

Pred prvým spustením treba v GitHub repozitári nastaviť Secrets:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_SERVER_DIR`

Deploy sa spúšťa ručne v záložke Actions cez workflow `Deploy website to FTP`.

## Čo sa neposiela na GitHub alebo hosting deployom

- `api/public-help.config.php` - obsahuje PIN a prípadne API kľúč.
- `data/public-help.json` - živé publikované odpovede vznikajú na hostingu.
- `uploads/pomoc-verejnosti/` - fotky nahrané na hostingu sa nemajú prepisovať lokálnou verziou.
