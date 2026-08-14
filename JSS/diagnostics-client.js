(function () {
  "use strict";

  const reportToolkit = window.DoktorHausDiagnosticsReport;
  if (!reportToolkit) {
    return;
  }

  const API = Object.freeze({
    auth: "api/diagnostics-auth.php",
    report: "api/diagnostics-report.php",
    appendix: "api/diagnostics-appendix.php",
    outputs: "api/diagnostics-outputs.php"
  });
  const views = [
    document.getElementById("diag-loading"),
    document.getElementById("diag-pin-screen"),
    document.getElementById("diag-access-required"),
    document.getElementById("diag-error-screen"),
    document.getElementById("diag-portal"),
    document.getElementById("diag-report")
  ];
  const loading = document.getElementById("diag-loading");
  const loadingTitle = document.getElementById("diag-loading-title");
  const pinScreen = document.getElementById("diag-pin-screen");
  const pinTitle = document.getElementById("diag-pin-title");
  const pinIntro = document.getElementById("diag-pin-intro");
  const pinForm = document.getElementById("diag-pin-form");
  const pinInput = document.getElementById("diag-pin");
  const pinSubmit = document.getElementById("diag-pin-submit");
  const authStatus = document.getElementById("diag-auth-status");
  const accessRequired = document.getElementById("diag-access-required");
  const accessRequiredTitle = document.getElementById("diag-access-required-title");
  const accessRequiredMessage = document.getElementById("diag-access-required-message");
  const errorScreen = document.getElementById("diag-error-screen");
  const errorTitle = document.getElementById("diag-error-title");
  const errorMessage = document.getElementById("diag-error-message");
  const retryButton = document.getElementById("diag-retry");
  const portalShell = document.getElementById("diag-portal");
  const portalContent = document.getElementById("diag-portal-content");
  const portalLogoutButton = document.getElementById("diag-portal-logout");
  const reportShell = document.getElementById("diag-report");
  const reportContent = document.getElementById("diag-report-content");
  const backToPortalButton = document.getElementById("diag-back-to-portal");
  const printButton = document.getElementById("diag-print");
  const logoutButton = document.getElementById("diag-logout");

  const photoViewer = reportToolkit.createPhotoViewer(document);
  reportToolkit.installPrintBehavior(document);

  let accessId = null;
  let csrfToken = null;
  let busy = false;
  let clientContentReady = false;

  function focus(element) {
    window.requestAnimationFrame(function () {
      element.focus();
    });
  }

  function showView(activeView) {
    views.forEach(function (view) {
      view.hidden = view !== activeView;
    });
  }

  function clearReport() {
    portalContent.replaceChildren();
    reportContent.replaceChildren();
    clientContentReady = false;
    document.getElementById("diag-print-property").textContent = "";
    document.getElementById("diag-print-meta").textContent = "";
  }

  function showLoading(message) {
    loadingTitle.textContent = message || "Načítavam výstupy z inšpekcie…";
    showView(loading);
  }

  function setPinBusy(isBusy) {
    busy = isBusy;
    pinInput.disabled = isBusy;
    pinSubmit.disabled = isBusy;
    pinSubmit.textContent = isBusy ? "Overujem…" : "Otvoriť výstupy";
  }

  function reportTargetFromHash() {
    const route = reportToolkit.resolveClientRoute(window.location.hash, function (id) {
      const candidate = document.getElementById(id);
      return Boolean(candidate && reportShell.contains(candidate));
    });
    if (route.view !== "report") {
      return null;
    }
    const target = route.targetId === "sprava" ? null : document.getElementById(route.targetId);
    return {id: route.targetId, element: target};
  }

  function showPortalView(focusHeading, scrollTop) {
    showView(portalShell);
    document.body.dataset.diagClientView = "portal";
    if (scrollTop) {
      window.scrollTo({top: 0, behavior: "auto"});
    }
    if (focusHeading) {
      const heading = document.getElementById("diag-portal-title");
      if (heading) {
        focus(heading);
      }
    }
  }

  function showReportView(target, focusTarget) {
    showView(reportShell);
    document.body.dataset.diagClientView = "report";
    window.requestAnimationFrame(function () {
      if (!target || target.id === "sprava" || !target.element) {
        window.scrollTo({top: 0, behavior: "auto"});
        const heading = document.getElementById("diag-report-title");
        if (heading && focusTarget) {
          heading.focus();
        }
        return;
      }
      target.element.scrollIntoView({block: "start", behavior: "auto"});
      if (focusTarget) {
        if (!target.element.hasAttribute("tabindex")) {
          target.element.setAttribute("tabindex", "-1");
        }
        target.element.focus({preventScroll: true});
      }
    });
  }

  function applyClientRoute(focusTarget) {
    if (!clientContentReady) {
      return;
    }
    const target = reportTargetFromHash();
    if (target) {
      showReportView(target, focusTarget);
    } else {
      showPortalView(focusTarget, true);
    }
  }

  function navigateToHash(hash) {
    const targetUrl = window.location.pathname + window.location.search + hash;
    window.history.pushState({dhDiagnosticsView: "report"}, "", targetUrl);
    applyClientRoute(true);
  }

  function returnToPortal() {
    const targetUrl = window.location.pathname + window.location.search;
    window.history.replaceState({dhDiagnosticsView: "portal"}, "", targetUrl);
    showPortalView(true, true);
  }

  function showPin(message) {
    clearReport();
    pinIntro.textContent = message || "Zadajte 6-miestny PIN, ktorý ste dostali spolu s odkazom.";
    authStatus.textContent = "";
    setPinBusy(false);
    showView(pinScreen);
    focus(pinTitle);
    window.requestAnimationFrame(function () {
      pinInput.focus();
    });
  }

  function showAccessRequired(message, title) {
    clearReport();
    csrfToken = null;
    accessRequiredTitle.textContent = title || "Prístup k správe nie je aktívny";
    accessRequiredMessage.textContent = message || "Otvorte pôvodný odkaz, ktorý ste dostali spolu s PINom.";
    showView(accessRequired);
    focus(accessRequiredTitle);
  }

  function showError(message, title) {
    clearReport();
    errorTitle.textContent = title || "Správu sa momentálne nepodarilo načítať.";
    errorMessage.textContent = message || "Skúste to prosím neskôr.";
    showView(errorScreen);
    focus(errorTitle);
  }

  function parseAccessId() {
    const parameters = new URLSearchParams(window.location.search);
    const keys = Array.from(parameters.keys());
    if (keys.length === 0) {
      return { valid: true, value: null };
    }
    if (keys.length !== 1 || keys[0] !== "access") {
      return { valid: false, value: null };
    }
    const values = parameters.getAll("access");
    if (values.length !== 1 || !reportToolkit.validateAccessId(values[0])) {
      return { valid: false, value: null };
    }
    return { valid: true, value: values[0] };
  }

  async function readJson(response) {
    try {
      return await response.json();
    } catch (error) {
      return null;
    }
  }

  async function request(url, options) {
    const settings = Object.assign({
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    }, options || {});
    return window.fetch(url, settings);
  }

  async function loadReport() {
    showLoading("Načítavam výstupy z inšpekcie…");
    try {
      const responses = await Promise.all([request(API.report), request(API.appendix), request(API.outputs)]);
      const response = responses[0];
      const appendixResponse = responses[1];
      const outputsResponse = responses[2];
      if (response.status === 401 || appendixResponse.status === 401 || outputsResponse.status === 401) {
        csrfToken = null;
        showPin("Platnosť prístupu v tomto okne vypršala. Zadajte PIN znova.");
        return;
      }
      if (response.status === 503) {
        showError("Služba je dočasne nedostupná. Skúste to prosím neskôr.");
        return;
      }
      if (!response.ok) {
        showError();
        return;
      }
      if (!appendixResponse.ok && appendixResponse.status !== 404) {
        showError(appendixResponse.status === 503
          ? "Služba je dočasne nedostupná. Skúste to prosím neskôr."
          : undefined);
        return;
      }
      const report = await readJson(response);
      if (!report) {
        showError();
        return;
      }
      let appendix = null;
      if (appendixResponse.ok) {
        appendix = await readJson(appendixResponse);
        if (!appendix) {
          showError();
          return;
        }
      }
      const outputs = outputsResponse.ok ? await readJson(outputsResponse) : null;
      reportToolkit.renderClientPortal(report, {
        document: document,
        pageUrl: window.location.href,
        container: portalContent,
        photoViewer: photoViewer,
        appendix: appendix,
        outputs: outputs
      });
      reportToolkit.renderReport(report, {
        document: document,
        pageUrl: window.location.href,
        container: reportContent,
        photoViewer: photoViewer,
        appendix: appendix
      });
      clientContentReady = true;
      window.history.replaceState(
        {dhDiagnosticsView: reportTargetFromHash() ? "report" : "portal"},
        "",
        window.location.href
      );
      applyClientRoute(true);
    } catch (error) {
      showError("Skontrolujte internetové pripojenie a skúste to znova.");
    }
  }

  async function verifySession(requestedAccessId) {
    showLoading("Overujem bezpečný prístup…");
    try {
      const response = await request(API.auth);
      if (response.status === 401) {
        if (requestedAccessId) {
          accessId = requestedAccessId;
          showPin();
        } else {
          showAccessRequired();
        }
        return;
      }
      if (response.status === 503) {
        showError("Služba je dočasne nedostupná. Skúste to prosím neskôr.");
        return;
      }
      if (!response.ok) {
        showError();
        return;
      }
      const session = await readJson(response);
      if (!session || session.authenticated !== true || !reportToolkit.validateAccessId(session.accessId)) {
        showError();
        return;
      }
      if (requestedAccessId && requestedAccessId !== session.accessId) {
        accessId = requestedAccessId;
        csrfToken = null;
        showPin("Tento odkaz patrí k inej správe. Zadajte PIN pre správu z otvoreného odkazu.");
        return;
      }
      accessId = session.accessId;
      csrfToken = typeof session.csrfToken === "string" ? session.csrfToken : null;
      await loadReport();
    } catch (error) {
      showError("Skontrolujte internetové pripojenie a skúste to znova.");
    }
  }

  async function unlock(event) {
    event.preventDefault();
    if (busy || !accessId) {
      return;
    }
    const pin = pinInput.value;
    if (!/^[0-9]{6}$/.test(pin)) {
      authStatus.textContent = "Zadajte presne 6 číslic.";
      pinInput.focus();
      return;
    }
    setPinBusy(true);
    authStatus.textContent = "Overujem prístup…";
    try {
      const response = await request(API.auth, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ action: "unlock", accessId: accessId, pin: pin })
      });
      pinInput.value = "";
      if (response.status === 401) {
        authStatus.textContent = "PIN nie je správny alebo odkaz už nie je platný.";
        setPinBusy(false);
        pinInput.focus();
        return;
      }
      if (response.status === 429) {
        const retryAfter = Number.parseInt(response.headers.get("Retry-After"), 10);
        authStatus.textContent = Number.isFinite(retryAfter) && retryAfter > 0
          ? "Priveľa pokusov. Skúste to znova približne o " + retryAfter + " sekúnd."
          : "Priveľa pokusov. Počkajte chvíľu a skúste to znova.";
        setPinBusy(false);
        return;
      }
      if (response.status === 503) {
        setPinBusy(false);
        showError("Služba je dočasne nedostupná. Skúste to prosím neskôr.");
        return;
      }
      if (!response.ok) {
        authStatus.textContent = "Prístup sa nepodarilo overiť. Skúste to znova.";
        setPinBusy(false);
        return;
      }
      const session = await readJson(response);
      if (!session || session.authenticated !== true || session.accessId !== accessId) {
        setPinBusy(false);
        showError();
        return;
      }
      csrfToken = typeof session.csrfToken === "string" ? session.csrfToken : null;
      setPinBusy(false);
      await loadReport();
    } catch (error) {
      setPinBusy(false);
      authStatus.textContent = "Skontrolujte internetové pripojenie a skúste to znova.";
    }
  }

  async function logout() {
    if (busy) {
      return;
    }
    busy = true;
    logoutButton.disabled = true;
    portalLogoutButton.disabled = true;
    try {
      const response = await request(API.auth, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ action: "logout", csrfToken: csrfToken || "" })
      });
      if (response.ok || response.status === 401 || response.status === 403) {
        csrfToken = null;
        if (accessId) {
          showPin("Boli ste bezpečne odhlásení. Na opätovné otvorenie zadajte PIN.");
        } else {
          showAccessRequired();
        }
      } else if (response.status === 503) {
        showError("Odhlásenie sa momentálne nepodarilo dokončiť. Skúste to prosím znova.");
      } else {
        showError("Odhlásenie sa nepodarilo dokončiť. Skúste to prosím znova.");
      }
    } catch (error) {
      showError("Odhlásenie sa nepodarilo dokončiť. Skontrolujte pripojenie a skúste to znova.");
    } finally {
      busy = false;
      logoutButton.disabled = false;
      portalLogoutButton.disabled = false;
    }
  }

  pinForm.addEventListener("submit", unlock);
  retryButton.addEventListener("click", function () {
    verifySession(accessId);
  });
  printButton.addEventListener("click", function () {
    window.print();
  });
  logoutButton.addEventListener("click", logout);
  portalLogoutButton.addEventListener("click", logout);
  backToPortalButton.addEventListener("click", returnToPortal);
  portalContent.addEventListener("click", function (event) {
    const link = event.target.closest('a[href^="#"]');
    if (!link) {
      return;
    }
    const hash = link.getAttribute("href");
    if (hash === "#sprava" || hash.indexOf("#zistenie-") === 0 || hash === "#zdrojova-fotodokumentacia") {
      event.preventDefault();
      navigateToHash(hash);
    }
  });
  reportContent.addEventListener("click", function (event) {
    const target = event.target.closest("[data-diag-logout]");
    if (target) {
      logout();
    }
  });
  window.addEventListener("popstate", function () {
    applyClientRoute(false);
  });
  window.addEventListener("hashchange", function () {
    applyClientRoute(false);
  });

  const parsedAccess = parseAccessId();
  if (!parsedAccess.valid) {
    showAccessRequired(
      "Neplatný alebo neúplný odkaz k inšpekcii. Skontrolujte celý odkaz, ktorý ste dostali.",
      "Odkaz k správe nie je platný"
    );
  } else {
    verifySession(parsedAccess.value);
  }
})();
