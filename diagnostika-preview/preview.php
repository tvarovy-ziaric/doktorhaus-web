<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/preview-runtime.php';
dh_preview_require_https();
$previewId = is_string($_GET['preview'] ?? null) ? $_GET['preview'] : '';
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$canonicalRoute = is_string($requestPath)
    && preg_match('#^/diagnostika-preview/pvw_[0-9a-f]{32}/?$#D', $requestPath) === 1;
if (!dh_preview_valid_id($previewId) || count($_GET) !== 1 || !$canonicalRoute) {
    http_response_code(404);
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
  <title>Náhľad technickej diagnostiky | DoktorHaus</title>
  <link rel="stylesheet" href="/styles/style.css?v=14">
  <link rel="stylesheet" href="/styles/diagnostics-report.css?v=2">
  <link rel="stylesheet" href="../preview.css?v=1">
</head>
<body class="diag-page dh-preview-report-page">
  <div class="dh-preview-banner" role="status">NÁHĽAD — NEPUBLIKOVANÁ VERZIA</div>
  <a class="diag-skip-link" href="#diag-main">Preskočiť na obsah správy</a>

  <header class="diag-site-header">
    <div class="container diag-site-header-inner">
      <a href="../" class="brand" aria-label="DoktorHaus – owner preview">
        <img src="/icons/doktorhaus-symbol.svg" alt="" class="brand-symbol" aria-hidden="true">
        <span class="brand-word">doktorhaus</span>
      </a>
      <span class="diag-private-label">Súkromný pracovný náhľad</span>
    </div>
  </header>

  <main id="diag-main" class="diag-shell">
    <section class="diag-state-card" id="preview-report-loading" aria-labelledby="preview-report-loading-title">
      <p class="eyebrow">Technická diagnostika</p>
      <h1 id="preview-report-loading-title">Načítavam chránený draft…</h1>
    </section>

    <section class="diag-state-card" id="preview-report-blocked" aria-labelledby="preview-report-blocked-title" hidden>
      <p class="eyebrow">Owner prístup</p>
      <h1 id="preview-report-blocked-title">Pre otvorenie náhľadu sa prihláste.</h1>
      <p>Preview ID nie je heslo a bez platnej owner session nesprístupní report ani fotografie.</p>
      <a class="btn primary" href="../">Prejsť na owner login</a>
    </section>

    <section class="diag-state-card" id="preview-report-error" aria-labelledby="preview-report-error-title" hidden>
      <p class="eyebrow">Technická diagnostika</p>
      <h1 id="preview-report-error-title">Náhľad sa nepodarilo načítať.</h1>
      <p>Skontrolujte owner session alebo skúste požiadavku zopakovať.</p>
      <button class="btn primary" id="preview-report-retry" type="button">Skúsiť znova</button>
    </section>

    <article class="diag-report" id="diag-report" aria-labelledby="diag-report-title" hidden>
      <div class="diag-report-toolbar" aria-label="Ovládanie pracovného náhľadu">
        <span>NÁHĽAD — NEPUBLIKOVANÁ VERZIA</span>
        <div>
          <button class="btn" id="diag-print" type="button">Vytlačiť</button>
          <button class="btn" id="diag-logout" type="button">Odhlásiť</button>
        </div>
      </div>
      <div class="diag-print-header" aria-hidden="true">
        <strong>DoktorHaus — NÁHĽAD</strong>
        <span id="diag-print-property"></span>
        <span id="diag-print-meta"></span>
      </div>
      <div id="diag-report-content"></div>
      <footer class="diag-print-footer" aria-hidden="true">NÁHĽAD — NEPUBLIKOVANÁ VERZIA</footer>
    </article>
  </main>

  <div class="diag-photo-modal" id="diag-photo-modal" role="dialog" aria-modal="true" aria-labelledby="diag-photo-title" aria-hidden="true" hidden>
    <button class="diag-photo-backdrop" type="button" data-diag-photo-close aria-label="Zavrieť fotogalériu"></button>
    <section class="diag-photo-panel">
      <header class="diag-photo-header">
        <div><p id="diag-photo-code"></p><h2 id="diag-photo-title">Fotografia</h2></div>
        <button class="diag-modal-button" type="button" data-diag-photo-close>Zavrieť</button>
      </header>
      <div class="diag-photo-view">
        <button class="diag-photo-nav" id="diag-photo-prev" type="button" aria-label="Predchádzajúca fotografia">‹</button>
        <div class="diag-photo-stage"><img id="diag-photo-image" alt=""><p id="diag-photo-error" role="status" hidden>Médium sa nepodarilo načítať.</p></div>
        <button class="diag-photo-nav" id="diag-photo-next" type="button" aria-label="Ďalšia fotografia">›</button>
      </div>
    </section>
  </div>

  <script src="/JSS/diagnostics-report.js?v=2" defer></script>
  <script src="../preview.js?v=1" defer></script>
</body>
</html>
