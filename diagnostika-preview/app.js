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
  var storageStatus = document.getElementById("preview-storage-status");
  var uploadSubmit = document.getElementById("preview-upload-submit");
  var result = document.getElementById("preview-result");
  var latest = document.getElementById("preview-latest");
  var storageState = null;
  var publishedForm = document.getElementById("published-upload-form");
  var publishedInput = document.getElementById("published-bundle");
  var publishedSubmit = document.getElementById("published-upload-submit");
  var publishedStatus = document.getElementById("published-upload-status");

  function show(target) {
    [loading, login, upload].forEach(function (node) { node.hidden = node !== target; });
  }

  async function requestJson(url, options, timeoutMs) {
    var controller = typeof AbortController === "function" ? new AbortController() : null;
    var timeout = controller ? setTimeout(function () { controller.abort(); }, timeoutMs || 20000) : null;
    try {
      var requestOptions = Object.assign({ credentials: "same-origin", cache: "no-store" }, options || {});
      if (controller) {
        requestOptions.signal = controller.signal;
      }
      var response = await fetch(url, requestOptions);
      var body = await response.json().catch(function () { return {}; });
      if (!response.ok) {
        var error = new Error("request_failed");
        error.status = response.status;
        error.payload = body;
        throw error;
      }
      return body;
    } catch (error) {
      if (error && error.name === "AbortError") {
        var timeoutError = new Error("request_timeout");
        timeoutError.code = "timeout";
        throw timeoutError;
      }
      throw error;
    } finally {
      if (timeout) {
        clearTimeout(timeout);
      }
    }
  }

  function formatMiB(bytes) {
    return Math.floor(bytes / 1048576).toLocaleString("sk-SK") + " MB";
  }

  function setLatestPreview(previewId) {
    if (typeof previewId === "string" && /^pvw_[0-9a-f]{32}$/.test(previewId)) {
      document.getElementById("preview-latest-link").href = "/diagnostika-preview/" + previewId + "/";
      latest.hidden = false;
    }
  }

  async function checkStorage() {
    storageStatus.textContent = "Overujem súkromné úložisko…";
    uploadSubmit.disabled = true;
    storageState = null;
    try {
      var body = await requestJson("/diagnostika-preview/api/storage.php", null, 20000);
      storageState = body;
      if (!body.writable) {
        throw new Error("storage_not_writable");
      }
      setLatestPreview(body.latestPreviewId);
      if (!body.zipSupported) {
        storageState = null;
        storageStatus.textContent = "Hosting nemá aktívne PHP rozšírenie ZipArchive. Upload ZIP-u je bezpečne zastavený.";
        return;
      }
      if (!body.fileInfoSupported) {
        storageState = null;
        storageStatus.textContent = "Hosting nemá aktívne PHP rozšírenie Fileinfo. Overenie formátu fotografií nie je dostupné a upload je zastavený.";
        return;
      }
      if (!body.capacityKnown || !Number.isFinite(body.availableBytes)) {
        storageState = null;
        storageStatus.textContent = "Súkromné úložisko je zapisovateľné, ale hosting neposkytol údaj o voľnej kapacite. Upload je bezpečne zastavený.";
        return;
      }
      storageStatus.textContent = "Súkromné úložisko je zapisovateľné · voľné " + formatMiB(body.availableBytes) + ".";
      uploadSubmit.disabled = false;
      publishedSubmit.disabled = false;
    } catch (error) {
      if (error.status === 401) {
        csrfToken = null;
        show(login);
        loginStatus.textContent = error.payload && error.payload.code === "OWNER_SESSION_COOKIE_MISSING"
          ? "Prehliadač neposlal owner cookie (kód COOKIE_MISSING). Skontrolujte blokovanie cookies pre doktorhaus.sk."
          : "Server nenačítal uloženú owner session (kód STATE_MISSING).";
        return;
      }
      storageStatus.textContent = "Súkromné úložisko nie je dostupné alebo neprešlo zápisovým testom. Upload je bezpečne zastavený.";
    }
  }

  function setAuthenticated(body) {
    csrfToken = body.csrfToken;
    show(upload);
    setLatestPreview(body.latestPreviewId);
    checkStorage();
  }

  async function loadStatus() {
    try {
      var body = await requestJson("/diagnostika-preview/api/auth.php");
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
      var body = await requestJson("/diagnostika-preview/api/auth.php", {
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
    if (!storageState || !storageState.writable) {
      uploadStatus.textContent = "Najprv musí úspešne prejsť kontrola súkromného úložiska.";
      checkStorage();
      return;
    }
    if (input.files[0].size > storageState.uploadLimitBytes) {
      uploadStatus.textContent = "ZIP je väčší než povolený limit " + formatMiB(storageState.uploadLimitBytes) + ".";
      return;
    }
    if (storageState.capacityKnown && Number.isFinite(storageState.availableBytes)
        && storageState.availableBytes < input.files[0].size + storageState.safetyReserveBytes) {
      uploadStatus.textContent = "V súkromnom úložisku nie je dostatok voľného miesta. Nič sa neuložilo.";
      return;
    }
    var data = new FormData();
    data.append("csrfToken", csrfToken || "");
    data.append("bundle", input.files[0], input.files[0].name);
    uploadStatus.textContent = "Overujem a ukladám bundle…";
    uploadSubmit.disabled = true;
    input.disabled = true;
    result.hidden = true;
    try {
      var body = await requestJson("/diagnostika-preview/api/upload.php", { method: "POST", body: data }, 150000);
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
        loginStatus.textContent = "Session počas uploadu vypršala. Po prihlásení sa zobrazí posledný úspešne uložený preview.";
      } else if (error.status === 507) {
        uploadStatus.textContent = "Súkromné úložisko nemá dostatok miesta alebo nie je zapisovateľné. Nič sa neuložilo.";
        checkStorage();
      } else if (error.status === 413 || error.status === 400) {
        uploadStatus.textContent = "Server odmietol veľkosť uploadu. Skontrolujte aktívne PHP limity pre priečinok diagnostika-preview.";
      } else if (error.code === "timeout") {
        uploadStatus.textContent = "Server neodpovedal do 150 sekúnd. Obnovte stránku; posledný úspešný preview sa načíta zo súkromného úložiska.";
      } else {
        var validationCode = error.payload && typeof error.payload.validationCode === "string"
          ? error.payload.validationCode
          : "";
        uploadStatus.textContent = error.status === 422 || validationCode
          ? "ZIP neprešiel kontrolou" + (validationCode ? " · kód " + validationCode : "") + "."
          : "Bundle sa nepodarilo nahrať.";
      }
    } finally {
      input.disabled = false;
      uploadSubmit.disabled = !storageState || !storageState.writable;
    }
  });

  publishedForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (!publishedInput.files || publishedInput.files.length !== 1) {
      publishedStatus.textContent = "Vyberte jeden schválený produkčný ZIP.";
      return;
    }
    if (!storageState || !storageState.writable) {
      publishedStatus.textContent = "Najprv musí úspešne prejsť kontrola súkromného úložiska.";
      checkStorage();
      return;
    }
    if (publishedInput.files[0].size > storageState.uploadLimitBytes) {
      publishedStatus.textContent = "ZIP je väčší než povolený limit " + formatMiB(storageState.uploadLimitBytes) + ".";
      return;
    }
    var data = new FormData();
    data.append("csrfToken", csrfToken || "");
    data.append("bundle", publishedInput.files[0], publishedInput.files[0].name);
    publishedStatus.textContent = "Overujem schválenie, manifest, súbory a ukladám nemennú verziu…";
    publishedSubmit.disabled = true;
    publishedInput.disabled = true;
    try {
      var body = await requestJson("/diagnostika-preview/api/publish.php", { method: "POST", body: data }, 150000);
      publishedStatus.textContent = body.alreadyInstalled
        ? "Táto identická nemenná verzia už bola bezpečne publikovaná."
        : "Schválený report bol bezpečne publikovaný. Teraz ho možno naviazať v administrácii klientov.";
      publishedInput.value = "";
    } catch (error) {
      if (error.status === 401) {
        csrfToken = null;
        show(login);
        loginStatus.textContent = "Session počas publikovania vypršala.";
      } else {
        var code = error.payload && typeof error.payload.validationCode === "string"
          ? error.payload.validationCode
          : "";
        publishedStatus.textContent = error.status === 422
          ? "Produkčný ZIP neprešiel kontrolou" + (code ? " · kód " + code : "") + "."
          : "Publikovanie sa nepodarilo.";
      }
    } finally {
      publishedInput.disabled = false;
      publishedSubmit.disabled = !storageState || !storageState.writable;
    }
  });

  document.getElementById("preview-logout").addEventListener("click", async function () {
    try {
      await requestJson("/diagnostika-preview/api/auth.php", {
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
