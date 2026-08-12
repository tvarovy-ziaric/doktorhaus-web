(function () {
  "use strict";

  var csrfToken = null;
  var loading = document.getElementById("preview-loading");
  var login = document.getElementById("preview-login");
  var upload = document.getElementById("preview-upload");
  var loginForm = document.getElementById("preview-login-form");
  var uploadForm = document.getElementById("preview-upload-form");
  var loginStatus = document.getElementById("preview-login-status");
  var uploadStatus = document.getElementById("preview-upload-status");
  var result = document.getElementById("preview-result");
  var latest = document.getElementById("preview-latest");

  function show(target) {
    [loading, login, upload].forEach(function (node) { node.hidden = node !== target; });
  }

  async function requestJson(url, options) {
    var response = await fetch(url, Object.assign({ credentials: "same-origin", cache: "no-store" }, options || {}));
    var body = await response.json().catch(function () { return {}; });
    if (!response.ok) {
      var error = new Error("request_failed");
      error.status = response.status;
      throw error;
    }
    return body;
  }

  function setAuthenticated(body) {
    csrfToken = body.csrfToken;
    show(upload);
    if (typeof body.latestPreviewId === "string" && /^pvw_[0-9a-f]{32}$/.test(body.latestPreviewId)) {
      var href = body.latestPreviewId + "/";
      document.getElementById("preview-latest-link").href = href;
      latest.hidden = false;
    }
  }

  async function loadStatus() {
    try {
      var body = await requestJson("api/auth.php");
      if (body.authenticated) {
        setAuthenticated(body);
      } else {
        show(login);
      }
    } catch (_error) {
      show(login);
      loginStatus.textContent = "Owner preview je momentálne nedostupný.";
    }
  }

  loginForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    var pin = document.getElementById("preview-owner-pin");
    loginStatus.textContent = "Overujem prístup…";
    try {
      var body = await requestJson("api/auth.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "login", pin: pin.value })
      });
      pin.value = "";
      setAuthenticated(body);
      loginStatus.textContent = "";
    } catch (error) {
      pin.value = "";
      loginStatus.textContent = error.status === 429
        ? "Príliš veľa pokusov. Skúste to neskôr."
        : "Prihlásenie sa nepodarilo.";
    }
  });

  uploadForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    var input = document.getElementById("preview-bundle");
    if (!input.files || input.files.length !== 1) {
      uploadStatus.textContent = "Vyberte jeden preview ZIP.";
      return;
    }
    var data = new FormData();
    data.append("csrfToken", csrfToken || "");
    data.append("bundle", input.files[0], input.files[0].name);
    uploadStatus.textContent = "Overujem a ukladám bundle…";
    result.hidden = true;
    try {
      var body = await requestJson("api/upload.php", { method: "POST", body: data });
      uploadStatus.textContent = "Bundle prešiel serverovou kontrolou.";
      document.getElementById("preview-open-link").href = body.previewUrl;
      document.getElementById("preview-result-counts").textContent =
        body.counts.media + " fotografií · " + body.counts.linked_evidence + " prepojených · " +
        body.counts.source_documentation_appendix + " v zdrojovej fotodokumentácii · " +
        body.counts.videos_pending + " videí pending";
      result.hidden = false;
      latest.hidden = true;
      input.value = "";
    } catch (error) {
      if (error.status === 401) {
        csrfToken = null;
        show(login);
      } else {
        uploadStatus.textContent = error.status === 422
          ? "ZIP neprešiel bezpečnostnou alebo integritnou kontrolou."
          : "Bundle sa nepodarilo nahrať.";
      }
    }
  });

  document.getElementById("preview-logout").addEventListener("click", async function () {
    try {
      await requestJson("api/auth.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "logout", csrfToken: csrfToken })
      });
    } catch (_error) {
      // Local private state is cleared even if the server response is unavailable.
    }
    csrfToken = null;
    result.hidden = true;
    latest.hidden = true;
    show(login);
  });

  loadStatus();
})();
