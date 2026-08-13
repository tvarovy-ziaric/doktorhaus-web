(function () {
  "use strict";

  var match = window.location.pathname.match(/\/(pvw_[0-9a-f]{32})\/?$/);
  var previewId = match ? match[1] : null;
  var csrfToken = null;
  var loading = document.getElementById("preview-report-loading");
  var blocked = document.getElementById("preview-report-blocked");
  var errorScreen = document.getElementById("preview-report-error");
  var reportArticle = document.getElementById("diag-report");
  var renderer = window.DoktorHausDiagnosticsReport;

  function show(target) {
    [loading, blocked, errorScreen, reportArticle].forEach(function (node) { node.hidden = node !== target; });
  }

  async function fetchJson(url) {
    var response = await fetch(url, { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) {
      var fetchError = new Error("request_failed");
      fetchError.status = response.status;
      throw fetchError;
    }
    return response.json();
  }

  function clientPhotoCaption(item) {
    var fallback = "Dokumentačná fotografia · " + item.source_identity;
    var caption = renderer.sanitizePhotoCaption(item.source_caption);
    return caption || fallback;
  }

  function appendixSection(appendix, viewer) {
    if (!appendix || appendix.document_type !== "source_documentation_appendix" || appendix.photo_count !== 18 ||
        !Array.isArray(appendix.items) || appendix.items.length !== 18) {
      throw new Error("invalid_appendix");
    }
    var section = document.createElement("section");
    section.className = "diag-section dh-source-appendix";
    section.id = "diag-section-source-photos";
    section.dataset.diagNavigationLabel = "Zdrojová fotodokumentácia";
    section.setAttribute("aria-labelledby", "dh-source-appendix-title");
    var eyebrow = document.createElement("p");
    eyebrow.className = "eyebrow";
    eyebrow.textContent = "Pôvodná dokumentácia obhliadky";
    var title = document.createElement("h2");
    title.id = "dh-source-appendix-title";
    title.textContent = "Zdrojová fotodokumentácia";
    var intro = document.createElement("p");
    intro.className = "dh-source-appendix-intro";
    intro.textContent = appendix.intro;
    var grid = document.createElement("div");
    grid.className = "dh-source-grid";
    var viewerItems = [];

    appendix.items.forEach(function (item, index) {
      var mediaUrl = renderer.validateMediaUrl(item.media_url, window.location.href);
      if (!mediaUrl) {
        throw new Error("invalid_appendix_media");
      }
      var card = document.createElement("article");
      card.className = "dh-source-card";
      var button = document.createElement("button");
      button.className = "dh-source-photo-button";
      button.type = "button";
      var image = document.createElement("img");
      var displayCaption = clientPhotoCaption(item);
      image.src = mediaUrl;
      image.alt = displayCaption || item.source_identity || "Dokumentačná fotografia";
      image.loading = "lazy";
      image.decoding = "async";
      var meta = document.createElement("span");
      meta.className = "dh-source-meta";
      meta.textContent = item.display_code + " · " + item.source_identity;
      var caption = document.createElement("span");
      caption.className = "dh-source-caption";
      caption.textContent = displayCaption;
      button.append(image, meta, caption);
      card.append(button);
      grid.append(card);
      viewerItems.push({ url: mediaUrl, title: caption.textContent, code: meta.textContent, alt: image.alt });
      button.addEventListener("click", function () { viewer.open(viewerItems, index, button); });
    });
    section.append(eyebrow, title, intro, grid);
    return section;
  }

  async function loadPreview() {
    if (!previewId || !renderer) {
      show(errorScreen);
      return;
    }
    show(loading);
    try {
      var auth = await fetchJson("../api/auth.php");
      if (!auth.authenticated) {
        show(blocked);
        return;
      }
      csrfToken = auth.csrfToken;
      var responses = await Promise.all([
        fetchJson("../api/report.php?preview=" + encodeURIComponent(previewId)),
        fetchJson("../api/appendix.php?preview=" + encodeURIComponent(previewId))
      ]);
      var viewer = renderer.createPhotoViewer(document);
      var content = renderer.renderReport(responses[0], {
        document: document,
        container: document.getElementById("diag-report-content"),
        photoViewer: viewer,
        pageUrl: window.location.href
      });
      content.append(appendixSection(responses[1], viewer));
      renderer.refreshSectionNavigation(content, document);
      renderer.installPrintBehavior(document);
      show(reportArticle);
    } catch (loadError) {
      if (loadError.status === 401) {
        show(blocked);
      } else {
        show(errorScreen);
      }
    }
  }

  document.getElementById("preview-report-retry").addEventListener("click", loadPreview);
  document.getElementById("diag-print").addEventListener("click", function () { window.print(); });
  document.addEventListener("click", function (event) {
    var logoutButton = event.target.closest("#diag-logout, [data-diag-logout]");
    if (!logoutButton) {
      return;
    }
    fetch("../api/auth.php", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "logout", csrfToken: csrfToken })
    }).finally(function () {
      csrfToken = null;
      document.getElementById("diag-report-content").replaceChildren();
      show(blocked);
    });
  });

  loadPreview();
})();
