<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/preview-runtime.php';
dh_preview_require_https();
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (is_string($requestPath)
    && $requestPath !== '/diagnostika-preview/'
    && preg_replace('#/+#', '/', $requestPath) === '/diagnostika-preview/') {
    header('Cache-Control: no-store, private, max-age=0');
    header('Location: /diagnostika-preview/', true, 302);
    exit;
}
dh_preview_page_headers();
?>
<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="referrer" content="no-referrer">
  <title>Draft preview diagnostiky | DoktorHaus</title>
  <link rel="stylesheet" href="/styles/style.css?v=14">
  <link rel="stylesheet" href="/styles/diagnostics-report.css?v=2">
  <link rel="stylesheet" href="preview.css?v=1">
</head>
<body class="diag-page dh-preview-admin-page">
  <header class="diag-site-header">
    <div class="container diag-site-header-inner">
      <a href="/" class="brand" aria-label="DoktorHaus – domovská stránka">
        <img src="/icons/doktorhaus-symbol.svg" alt="" class="brand-symbol" aria-hidden="true">
        <span class="brand-word">doktorhaus</span>
      </a>
      <span class="diag-private-label">Owner draft preview</span>
    </div>
  </header>

  <main class="diag-shell dh-preview-admin" id="main">
    <section class="diag-state-card" id="preview-loading" aria-labelledby="preview-loading-title">
      <p class="eyebrow">Chránený pracovný priestor</p>
      <h1 id="preview-loading-title">Overujem owner session…</h1>
    </section>

    <section class="diag-state-card" id="preview-login" aria-labelledby="preview-login-title" hidden>
      <p class="eyebrow">Owner login</p>
      <h1 id="preview-login-title">Online náhľad diagnostického draftu</h1>
      <p>Prihlásenie sprístupní iba súkromný pracovný preview priestor. Nevytvára klientsky grant, PIN ani publikovanú verziu reportu.</p>
      <form id="preview-login-form" class="dh-preview-form" novalidate>
        <label for="preview-owner-pin">Owner PIN</label>
        <div class="diag-pin-row">
          <input id="preview-owner-pin" name="pin" type="password" autocomplete="current-password" maxlength="128" required>
          <button class="btn primary" type="submit">Prihlásiť</button>
        </div>
        <p id="preview-login-status" class="diag-form-status" role="status" aria-live="polite"></p>
      </form>
    </section>

    <section class="diag-state-card" id="preview-upload" aria-labelledby="preview-upload-title" hidden>
      <p class="eyebrow">Súkromný draft</p>
      <h1 id="preview-upload-title">Nahrať preview ZIP</h1>
      <p>ZIP sa serverovo overí a uloží mimo verejného webrootu. Obsah balíka sa nikdy nevykonáva ako HTML, JavaScript ani PHP.</p>
      <p id="preview-storage-status" class="diag-form-status" role="status" aria-live="polite">Overujem súkromné úložisko…</p>
      <form id="preview-upload-form" class="dh-preview-form" enctype="multipart/form-data" novalidate>
        <label for="preview-bundle">Preview bundle ZIP</label>
        <input id="preview-bundle" name="bundle" type="file" accept=".zip,application/zip" required>
        <div class="dh-preview-actions">
          <button class="btn primary" id="preview-upload-submit" type="submit" disabled>Bezpečne nahrať</button>
          <button class="btn" id="preview-logout" type="button">Odhlásiť</button>
        </div>
        <p id="preview-upload-status" class="diag-form-status" role="status" aria-live="polite"></p>
      </form>
      <div id="preview-result" class="dh-preview-result" hidden>
        <p><strong>Preview je pripravený.</strong></p>
        <p id="preview-result-counts"></p>
        <a class="btn primary" id="preview-open-link" href="">Otvoriť draft preview</a>
      </div>
      <div id="preview-latest" class="dh-preview-result" hidden>
        <p>V tejto session je dostupný naposledy nahraný preview.</p>
        <a class="text-link" id="preview-latest-link" href="">Otvoriť posledný preview</a>
      </div>

      <hr class="dh-preview-divider">
      <h2>Publikovať schválený report</h2>
      <p>Tento krok prijíma iba balík so stavom <code>published</code>, údajmi o schválení a úplným manifestom. Balík sa po integritnej kontrole uloží ako nemenná verzia.</p>
      <form id="published-upload-form" class="dh-preview-form" enctype="multipart/form-data" novalidate>
        <label for="published-bundle">Schválený produkčný ZIP</label>
        <input id="published-bundle" name="bundle" type="file" accept=".zip,application/zip" required>
        <button class="btn primary" id="published-upload-submit" type="submit" disabled>Overiť a publikovať</button>
        <p id="published-upload-status" class="diag-form-status" role="status" aria-live="polite"></p>
      </form>
    </section>
  </main>

  <script src="app.js?v=7" defer></script>
</body>
</html>
