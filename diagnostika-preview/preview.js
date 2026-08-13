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
      content.append(renderer.renderSourceDocumentationAppendix(responses[1], {
        document: document,
        pageUrl: window.location.href,
        photoViewer: viewer
      }));
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
