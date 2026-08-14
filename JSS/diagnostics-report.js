(function (root, factory) {
  "use strict";

  const api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.DoktorHausDiagnosticsReport = api;
  }
})(typeof window !== "undefined" ? window : null, function () {
  "use strict";

  const LABELS = Object.freeze({
    severity: Object.freeze({
      S1: "zanedbateľná",
      S2: "nízka",
      S3: "stredná",
      S4: "vysoká",
      S5: "kritická"
    }),
    priority: Object.freeze({
      P1: "riešiť ako prvé",
      P2: "vysoká priorita",
      P3: "plánovať",
      P4: "nižšia priorita",
      P5: "sledovať"
    }),
    urgency: Object.freeze({
      U1: "bez odkladu",
      U2: "približne do 1 mesiaca",
      U3: "približne do 3 mesiacov",
      U4: "približne do 12 mesiacov",
      U5: "plánovaná údržba / sledovanie"
    }),
    confidence: Object.freeze({
      unknown: "neurčená",
      low: "nízka",
      medium: "stredná",
      high: "vysoká"
    }),
    likelihood: Object.freeze({
      L1: "nepravdepodobné",
      L2: "možné",
      L3: "pravdepodobné",
      L4: "veľmi pravdepodobné",
      L5: "prebiehajúce / pozorované"
    }),
    risk: Object.freeze({
      none: "nezistené",
      low: "nízke",
      moderate: "stredné",
      high: "vysoké",
      critical: "kritické",
      unknown: "neurčené"
    }),
    deterioration: Object.freeze({
      stable: "bez preukázanej zmeny",
      slow: "pomalé zhoršovanie",
      progressive: "postupné zhoršovanie",
      rapid: "rýchle zhoršovanie",
      unknown: "vývoj nebol určený"
    }),
    recommendationType: Object.freeze({
      IMMEDIATE: "Okamžitý krok",
      VERIFY: "Overiť",
      REPAIR: "Oprava",
      MONITOR: "Sledovať",
      MAINTENANCE: "Údržba",
      DOCUMENT: "Dokumentácia"
    }),
    recommendationStatus: Object.freeze({
      proposed: "odporúčané",
      approved: "schválené",
      completed: "vykonané",
      deferred: "odložené"
    }),
    issueStatus: Object.freeze({
      active: "aktívne zistenie",
      monitoring: "sledovať",
      resolved: "vyriešené"
    }),
    hypothesisStatus: Object.freeze({
      proposed: "pracovná hypotéza",
      under_verification: "overuje sa",
      supported: "podporená dostupnými podkladmi",
      contradicted: "podklady jej odporujú",
      inconclusive: "bez jednoznačného záveru"
    }),
    verificationStatus: Object.freeze({
      proposed: "odporúčané",
      scheduled: "naplánované",
      completed: "vykonané",
      not_feasible: "momentálne nevykonateľné",
      declined: "nevykonané z rozhodnutia klienta"
    }),
    verificationType: Object.freeze({
      inspection: "odborná obhliadka",
      measurement: "meranie",
      monitoring: "monitoring",
      destructive_probe: "sonda",
      laboratory_test: "laboratórna skúška",
      specialist_assessment: "posúdenie špecialistom",
      document_review: "kontrola dokumentácie",
      other: "iné overenie"
    }),
    specialty: Object.freeze({
      building_inspector: "technický inšpektor",
      structural_engineer: "statik",
      roofer: "strechár",
      chimney_specialist: "kominár / odborník na spalinové cesty",
      electrician: "elektrikár",
      heating_technician: "technik vykurovania",
      plumber: "inštalatér",
      waterproofing_specialist: "špecialista na hydroizolácie",
      geotechnical_specialist: "geotechnik",
      laboratory: "odborné laboratórium",
      surveyor: "geodet",
      designer: "projektant",
      other: "iný odborník"
    }),
    category: Object.freeze({
      drainage: "odvodnenie",
      moisture: "vlhkosť",
      structural: "nosné konštrukcie",
      masonry: "murivo",
      foundation: "základy",
      facade: "fasáda",
      roof: "strecha",
      chimney_flue: "komín a spalinové cesty",
      electrical: "elektroinštalácia",
      heating: "vykurovanie",
      plumbing: "vodoinštalácia",
      sewerage: "kanalizácia",
      ventilation: "vetranie",
      indoor_climate: "vnútorné prostredie",
      biological: "biologické poškodenie",
      windows_doors: "okná a dvere",
      site_exterior: "pozemok a exteriér",
      documentation: "dokumentácia",
      fire_safety: "požiarna bezpečnosť",
      other: "iné"
    }),
    impact: Object.freeze({
      safety: "bezpečnosť",
      structural: "konštrukcia",
      moisture: "vlhkosť",
      health: "zdravie",
      durability: "životnosť",
      usability: "používanie",
      financial: "financie"
    }),
    relation: Object.freeze({
      contributes_to: "môže prispievať k",
      caused_by: "je spôsobené zistením",
      aggravates: "môže zhoršovať",
      depends_on: "závisí od",
      same_mechanism: "môže mať spoločný mechanizmus s",
      supersedes: "nahrádza"
    }),
    observationType: Object.freeze({
      visual: "vizuálne zistenie",
      measured: "meranie",
      reported: "uvedené klientom",
      document: "zistenie z dokumentácie",
      functional_test: "funkčná skúška",
      thermal: "termografické zistenie",
      other: "iné zistenie"
    }),
    vatStatus: Object.freeze({
      included: "DPH zahrnutá",
      excluded: "bez DPH",
      mixed: "DPH sa medzi položkami líši",
      unknown: "DPH nebola potvrdená",
      not_applicable: "DPH sa neuplatňuje"
    })
  });

  const ACCESS_ID_PATTERN = /^acc_[0-9a-f]{32}$/;
  const EVIDENCE_ID_PATTERN = /^ev_[0-9a-f]{16,32}$/;
  const OUTPUT_ID_PATTERN = /^out_[0-9a-f]{32}$/;
  const OUTPUT_PHOTO_ID_PATTERN = /^outp_[0-9a-f]{32}$/;
  const OUTPUT_MEDIA_ID_PATTERN = /^outm_[0-9a-f]{32}$/;
  const PHOTO_CAPTION_BOILERPLATE = "Originálny mediálny súbor nebol extrahovaný.";
  const IMAGE_TYPES = new Set(["photo", "thermal_image", "drone_photo", "photo_360"]);
  const MAX_INITIAL_IMAGES = 8;
  const OUTPUT_DEFINITIONS = Object.freeze({
    google_docs: Object.freeze({ icon: "DOC", title: "Google Docs správa", description: "Online dokument správy.", action: "Otvoriť dokument" }),
    pdf: Object.freeze({ icon: "PDF", title: "Správa v PDF", description: "Statická verzia správy vhodná na uloženie alebo tlač.", action: "Otvoriť PDF" }),
    panoraven: Object.freeze({ icon: "360", title: "Virtuálna prehliadka exteriéru", description: "Priestorová prehliadka dostupných častí exteriéru.", action: "Otvoriť prehliadku" }),
    video_hd: Object.freeze({ icon: "HD", title: "Dron video Full HD", description: "Videozáznam nehnuteľnosti z dronu.", action: "Otvoriť video" }),
    video_360: Object.freeze({ icon: "VR", title: "Dron video 360", description: "Priestorový videozáznam nehnuteľnosti z dronu.", action: "Otvoriť video" })
  });
  const SCORE_LEGENDS = Object.freeze({
    priority: Object.freeze({ title: "Priorita", values: Object.freeze(["P1", "P2", "P3", "P4", "P5"]), showCodes: true }),
    severity: Object.freeze({ title: "Závažnosť", values: Object.freeze(["S1", "S2", "S3", "S4", "S5"]), showCodes: true }),
    urgency: Object.freeze({ title: "Naliehavosť", values: Object.freeze(["U1", "U2", "U3", "U4", "U5"]), showCodes: true }),
    confidence: Object.freeze({ title: "Istota záveru", values: Object.freeze(["unknown", "low", "medium", "high"]), showCodes: false })
  });
  const scorePopoverCleanupByContainer = new WeakMap();
  let scorePopoverSequence = 0;

  function validateAccessId(value) {
    return typeof value === "string" && ACCESS_ID_PATTERN.test(value);
  }

  function validateMediaUrl(value, pageUrl) {
    if (typeof value !== "string" || value === "" || typeof pageUrl !== "string") {
      return null;
    }
    try {
      const page = new URL(pageUrl);
      const media = new URL(value, page);
      const expected = new URL("api/diagnostics-media.php", page);
      const evidenceValues = media.searchParams.getAll("evidence");
      if (media.origin !== page.origin || media.pathname !== expected.pathname || media.hash !== "" ||
          media.username !== "" || media.password !== "" || evidenceValues.length !== 1 ||
          media.searchParams.size !== 1 || !EVIDENCE_ID_PATTERN.test(evidenceValues[0])) {
        return null;
      }
      return media.href;
    } catch (_error) {
      return null;
    }
  }

  function formatCurrency(value, currency) {
    const amount = Number(value);
    const code = typeof currency === "string" && /^[A-Z]{3}$/.test(currency) ? currency : "EUR";
    if (!Number.isFinite(amount)) {
      return "suma nie je uvedená";
    }
    try {
      return new Intl.NumberFormat("sk-SK", {
        style: "currency",
        currency: code,
        maximumFractionDigits: 0
      }).format(amount);
    } catch (_error) {
      const formatted = new Intl.NumberFormat("sk-SK", { maximumFractionDigits: 0 }).format(amount);
      return formatted + " " + code;
    }
  }

  function formatPricingCurrency(value, currency) {
    const amount = Number(value);
    const code = typeof currency === "string" && /^[A-Z]{3}$/.test(currency) ? currency : "EUR";
    if (!Number.isFinite(amount)) {
      return "suma nie je uvedená";
    }
    const rounded = Math.round((amount + Number.EPSILON) * 100) / 100;
    const fractionDigits = Number.isInteger(rounded) ? 0 : 2;
    try {
      return new Intl.NumberFormat("sk-SK", {
        style: "currency",
        currency: code,
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits
      }).format(rounded);
    } catch (_error) {
      const formatted = new Intl.NumberFormat("sk-SK", {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits
      }).format(rounded);
      return formatted + " " + code;
    }
  }

  function selectPriorityRecommendations(recommendations, issues) {
    const priorityByIssue = new Map((Array.isArray(issues) ? issues : []).map(function (issue) {
      return [issue.id, issue.priority];
    }));
    return (Array.isArray(recommendations) ? recommendations : []).filter(function (recommendation) {
      const urgency = recommendation && recommendation.target_timeframe
        ? recommendation.target_timeframe.urgency
        : null;
      const urgentByTime = urgency === "U1" || urgency === "U2";
      const urgentByIssue = (Array.isArray(recommendation.issue_ids) ? recommendation.issue_ids : []).some(function (issueId) {
        const priority = priorityByIssue.get(issueId);
        return priority === "P1" || priority === "P2";
      });
      return urgentByTime || urgentByIssue;
    });
  }

  function label(group, value, fallback) {
    const values = LABELS[group] || {};
    return Object.prototype.hasOwnProperty.call(values, value) ? values[value] : (fallback || "neuvedené");
  }

  function scaleText(group, value) {
    const translated = label(group, value);
    return translated === "neuvedené" ? translated : value + " — " + translated;
  }

  function formatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return "dátum nie je uvedený";
    }
    return new Intl.DateTimeFormat("sk-SK", { dateStyle: "long" }).format(date);
  }

  function formatNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? new Intl.NumberFormat("sk-SK").format(number) : "—";
  }

  function array(value) {
    return Array.isArray(value) ? value : [];
  }

  function validateOutputMediaUrl(value, pageUrl) {
    if (typeof value !== "string" || value === "" || typeof pageUrl !== "string") {
      return null;
    }
    try {
      const page = new URL(pageUrl);
      const media = new URL(value, page);
      const expected = new URL("api/diagnostics-output-media.php", page);
      const mediaValues = media.searchParams.getAll("media");
      if (media.origin !== page.origin || media.pathname !== expected.pathname || media.hash !== "" ||
          media.username !== "" || media.password !== "" || mediaValues.length !== 1 ||
          media.searchParams.size !== 1 || !OUTPUT_MEDIA_ID_PATTERN.test(mediaValues[0])) {
        return null;
      }
      return media.href;
    } catch (_error) {
      return null;
    }
  }

  function scoreLegendEntries(group, currentValue) {
    const legend = SCORE_LEGENDS[group];
    if (!legend || !LABELS[group]) {
      return [];
    }
    return legend.values.map(function (value) {
      return {
        value: value,
        code: legend.showCodes ? value : null,
        label: LABELS[group][value],
        current: value === currentValue
      };
    });
  }

  function sanitizePhotoCaption(value) {
    if (typeof value !== "string") {
      return "";
    }
    return value.replace(PHOTO_CAPTION_BOILERPLATE, "").trim();
  }

  function hostAllowed(hostname, allowedHosts) {
    const host = String(hostname || "").toLowerCase().replace(/\.$/, "");
    return allowedHosts.some(function (allowedHost) {
      return host === allowedHost || host.endsWith("." + allowedHost);
    });
  }

  function validateOutputUrl(type, value, pageUrl) {
    if (!Object.prototype.hasOwnProperty.call(OUTPUT_DEFINITIONS, type) ||
        typeof value !== "string" || value === "" || typeof pageUrl !== "string") {
      return null;
    }
    if (type === "pdf") {
      return validateMediaUrl(value, pageUrl) || validateOutputMediaUrl(value, pageUrl);
    }
    const allowedHosts = type === "google_docs"
      ? ["docs.google.com", "drive.google.com"]
      : type === "panoraven"
        ? ["panoraven.com"]
        : ["youtube.com", "youtu.be", "youtube-nocookie.com"];
    try {
      const output = new URL(value);
      if (output.protocol !== "https:" || output.username !== "" || output.password !== "" ||
          !hostAllowed(output.hostname, allowedHosts)) {
        return null;
      }
      return output.href;
    } catch (_error) {
      return null;
    }
  }

  function slug(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  function buildIssueAnchors(issues) {
    const used = new Set();
    const anchors = new Map();
    array(issues).forEach(function (issue, index) {
      const displayCode = typeof issue.display_code === "string" ? issue.display_code.trim() : "";
      const displaySlug = slug(displayCode);
      const preferred = displaySlug ? "zistenie-" + displaySlug : "";
      let anchor = preferred && !used.has(preferred) ? preferred : "zistenie-" + String(index + 1).padStart(2, "0");
      while (used.has(anchor)) {
        anchor += "-" + String(index + 1);
      }
      used.add(anchor);
      anchors.set(issue.id, {
        id: anchor,
        label: (displayCode ? displayCode + " — " : "") + issue.title
      });
    });
    return anchors;
  }

  function resolveClientRoute(hash, hasReportTarget) {
    const raw = typeof hash === "string" ? hash.replace(/^#/, "") : "";
    if (!raw) {
      return {view: "portal", targetId: null};
    }
    let targetId;
    try {
      targetId = decodeURIComponent(raw);
    } catch (_error) {
      return {view: "portal", targetId: null};
    }
    if (targetId === "sprava") {
      return {view: "report", targetId: targetId};
    }
    if (typeof hasReportTarget === "function" && hasReportTarget(targetId)) {
      return {view: "report", targetId: targetId};
    }
    return {view: "portal", targetId: null};
  }

  function splitPhotoIdentity(label, title, fallback) {
    const titleText = typeof title === "string" ? title.trim() : "";
    const titleMatch = titleText.match(/^((?:Photo|Foto)\s*\d+)\s*[–—-]\s*(.+)$/i);
    if (titleMatch) {
      return {
        label: titleMatch[1].replace(/\s+/, " "),
        title: titleMatch[2].trim()
      };
    }
    const labelText = typeof label === "string" ? label.trim() : "";
    const labelMatch = labelText.match(/\b((?:Photo|Foto)\s*\d+)/i);
    const fallbackMatch = typeof fallback === "string" ? fallback.match(/^EV-(\d+)$/i) : null;
    return {
      label: labelMatch
        ? labelMatch[1].replace(/\s+/, " ")
        : labelText || (fallbackMatch ? "Photo " + Number.parseInt(fallbackMatch[1], 10) : fallback) || "Fotografia",
      title: titleText || fallback || "Fotografia"
    };
  }

  function photoCaptionWithoutRepeatedIdentity(caption, identity) {
    const cleaned = sanitizePhotoCaption(caption);
    const withoutPrefix = cleaned.replace(/^\s*(?:Photo|Foto)\s*\d+\s*[–—-]\s*/i, "").trim();
    return withoutPrefix === identity.title ? "" : cleaned;
  }

  function buildPhotoGallery(report, appendix, pageUrl, issueAnchors) {
    const anchors = issueAnchors || buildIssueAnchors(report.issues);
    const items = [];
    const byEvidenceId = new Map();
    array(report.issues).forEach(function (issue) {
      const issueLink = anchors.get(issue.id);
      array(issue.evidence).forEach(function (evidence) {
        if (!evidence || !evidence.has_media || !IMAGE_TYPES.has(evidence.type) ||
            !String(evidence.content_type || "").startsWith("image/")) {
          return;
        }
        const mediaUrl = validateMediaUrl(evidence.media_url, pageUrl);
        if (!mediaUrl) {
          return;
        }
        let item = byEvidenceId.get(evidence.id);
        if (!item) {
          const identity = splitPhotoIdentity(null, evidence.title, evidence.display_code);
          item = {
            evidenceId: evidence.id,
            source: "linked",
            url: mediaUrl,
            label: identity.label,
            title: identity.title,
            caption: sanitizePhotoCaption(evidence.description),
            related: []
          };
          byEvidenceId.set(evidence.id, item);
          items.push(item);
        }
        if (issueLink && !item.related.some(function (link) { return link.href === "#" + issueLink.id; })) {
          item.related.push({ href: "#" + issueLink.id, label: issueLink.label });
        }
      });
    });
    if (appendix && appendix.schema_version === "1.0.0-helper" &&
        appendix.document_type === "source_documentation_appendix" &&
        Array.isArray(appendix.items) && appendix.photo_count === appendix.items.length) {
      appendix.items.forEach(function (entry) {
        if (!entry || byEvidenceId.has(entry.evidence_id)) {
          return;
        }
        const mediaUrl = validateMediaUrl(entry.media_url, pageUrl);
        if (!mediaUrl) {
          throw new Error("Unsupported source documentation appendix media.");
        }
        const cleanedSourceCaption = sanitizePhotoCaption(entry.source_caption);
        const identity = splitPhotoIdentity(entry.source_identity, cleanedSourceCaption, entry.display_code);
        const item = {
          evidenceId: entry.evidence_id,
          source: "appendix",
          url: mediaUrl,
          label: identity.label,
          title: identity.title,
          caption: photoCaptionWithoutRepeatedIdentity(cleanedSourceCaption, identity),
          related: [{ href: "#zdrojova-fotodokumentacia", label: "Zdrojová fotodokumentácia" }]
        };
        byEvidenceId.set(entry.evidence_id, item);
        items.push(item);
      });
    }
    return items;
  }

  function element(documentRef, tag, className, text) {
    const node = documentRef.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  function section(documentRef, title, description, id, navigationLabel) {
    const wrapper = element(documentRef, "section", "diag-section");
    if (id) {
      wrapper.id = id;
    }
    if (navigationLabel) {
      wrapper.dataset.diagNavigationLabel = navigationLabel;
    }
    const heading = element(documentRef, "div", "diag-section-heading");
    heading.append(element(documentRef, "h2", null, title));
    if (description) {
      heading.append(element(documentRef, "p", null, description));
    }
    wrapper.append(heading);
    return wrapper;
  }

  function refreshSectionNavigation(content, documentRef) {
    const existing = content.querySelector(".diag-report-navigation");
    if (existing) {
      existing.remove();
    }
    const sections = Array.from(content.querySelectorAll(".diag-section[id][data-diag-navigation-label]"));
    if (!sections.length) {
      return null;
    }
    const navigation = element(documentRef, "nav", "diag-report-navigation");
    navigation.setAttribute("aria-label", "Obsah správy");
    navigation.append(element(documentRef, "span", "diag-report-navigation-label", "Obsah správy"));
    const scroll = element(documentRef, "div", "diag-report-navigation-scroll");
    const list = element(documentRef, "ul", "diag-report-navigation-list");
    sections.forEach(function (target) {
      const item = element(documentRef, "li");
      const link = element(documentRef, "a", null, target.dataset.diagNavigationLabel);
      link.setAttribute("href", "#" + target.id);
      item.append(link);
      list.append(item);
    });
    scroll.append(list);
    navigation.append(scroll);
    const header = content.querySelector(".diag-report-header");
    content.insertBefore(navigation, header ? header.nextSibling : content.firstChild);
    return navigation;
  }

  function subsection(documentRef, title) {
    const wrapper = element(documentRef, "section", "diag-subsection");
    wrapper.append(element(documentRef, "h4", null, title));
    return wrapper;
  }

  function badge(documentRef, code, text, tone) {
    const node = element(documentRef, "span", "diag-badge");
    if (tone) {
      node.dataset.tone = tone;
    }
    if (code) {
      node.append(element(documentRef, "strong", null, code));
    }
    node.append(documentRef.createTextNode(text ? " " + text : ""));
    return node;
  }

  function scoreBadge(documentRef, group, value, code, text, tone) {
    const legend = SCORE_LEGENDS[group];
    const entries = scoreLegendEntries(group, value);
    if (!legend || !entries.length) {
      return badge(documentRef, code, text, tone);
    }
    scorePopoverSequence += 1;
    const popoverId = "diag-score-popover-" + scorePopoverSequence;
    const titleId = popoverId + "-title";
    const wrapper = element(documentRef, "span", "diag-score-help");
    const trigger = element(documentRef, "button", "diag-badge diag-score-trigger");
    trigger.type = "button";
    if (tone) {
      trigger.dataset.tone = tone;
    }
    if (code) {
      trigger.append(element(documentRef, "strong", null, code));
    }
    trigger.append(documentRef.createTextNode(text ? " " + text : ""));
    trigger.append(element(documentRef, "span", "diag-score-help-mark", "?"));
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-controls", popoverId);
    trigger.setAttribute("aria-haspopup", "dialog");
    trigger.setAttribute("aria-label", (code ? code + " " : "") + text + ". Zobraziť vysvetlenie celej stupnice.");

    const popover = element(documentRef, "span", "diag-score-popover");
    popover.id = popoverId;
    popover.hidden = true;
    popover.setAttribute("role", "dialog");
    popover.setAttribute("aria-labelledby", titleId);
    const popoverTitle = element(documentRef, "strong", "diag-score-popover-title", legend.title);
    popoverTitle.id = titleId;
    popover.append(popoverTitle);
    const list = element(documentRef, "span", "diag-score-legend");
    list.setAttribute("role", "list");
    entries.forEach(function (entry) {
      const row = element(documentRef, "span", "diag-score-legend-row");
      row.setAttribute("role", "listitem");
      if (entry.current) {
        row.dataset.current = "true";
        row.setAttribute("aria-current", "true");
      }
      if (entry.code) {
        row.append(element(documentRef, "strong", "diag-score-legend-code", entry.code));
        row.append(documentRef.createTextNode(" — "));
      }
      row.append(element(documentRef, "span", null, entry.label));
      list.append(row);
    });
    popover.append(list);
    wrapper.append(trigger, popover);
    return wrapper;
  }

  function installScorePopoverBehavior(documentRef, container) {
    const previousCleanup = scorePopoverCleanupByContainer.get(container);
    if (previousCleanup) {
      previousCleanup();
    }
    const records = Array.from(container.querySelectorAll(".diag-score-help")).map(function (wrapper) {
      return {
        wrapper: wrapper,
        trigger: wrapper.querySelector(".diag-score-trigger"),
        popover: wrapper.querySelector(".diag-score-popover"),
        pointerStartedOpen: false,
        pointerInside: false,
        focusInside: false
      };
    }).filter(function (record) {
      return record.trigger && record.popover;
    });
    const removers = [];
    const defaultView = documentRef.defaultView;
    const hoverCapable = !defaultView || typeof defaultView.matchMedia !== "function"
      ? true
      : defaultView.matchMedia("(hover: hover)").matches;

    function listen(target, type, handler) {
      target.addEventListener(type, handler);
      removers.push(function () { target.removeEventListener(type, handler); });
    }

    function setOpen(record, open) {
      record.trigger.setAttribute("aria-expanded", open ? "true" : "false");
      record.popover.hidden = !open;
    }

    function closeAll(except) {
      records.forEach(function (record) {
        if (record !== except) {
          setOpen(record, false);
        }
      });
    }

    function open(record) {
      closeAll(record);
      setOpen(record, true);
    }

    records.forEach(function (record) {
      if (hoverCapable) {
        listen(record.wrapper, "mouseenter", function () {
          record.pointerInside = true;
          open(record);
        });
        listen(record.wrapper, "mouseleave", function () {
          record.pointerInside = false;
          if (!record.focusInside) {
            setOpen(record, false);
          }
        });
      }
      listen(record.wrapper, "focusin", function () {
        record.focusInside = true;
        open(record);
      });
      listen(record.wrapper, "focusout", function (event) {
        record.focusInside = record.wrapper.contains(event.relatedTarget);
        if (!record.focusInside && !record.pointerInside) {
          setOpen(record, false);
        }
      });
      listen(record.trigger, "pointerdown", function () {
        record.pointerStartedOpen = record.trigger.getAttribute("aria-expanded") === "true";
      });
      listen(record.trigger, "click", function (event) {
        event.preventDefault();
        if (hoverCapable || !record.pointerStartedOpen) {
          open(record);
        } else {
          setOpen(record, false);
        }
        record.pointerStartedOpen = false;
      });
    });

    function handleDocumentClick(event) {
      if (!records.some(function (record) { return record.wrapper.contains(event.target); })) {
        closeAll(null);
      }
    }

    function handleEscape(event) {
      if (event.key !== "Escape") {
        return;
      }
      const active = records.find(function (record) {
        return record.trigger.getAttribute("aria-expanded") === "true";
      });
      if (active) {
        event.preventDefault();
        active.trigger.focus();
        setOpen(active, false);
      }
    }

    listen(documentRef, "click", handleDocumentClick);
    listen(documentRef, "keydown", handleEscape);
    const cleanup = function () {
      removers.forEach(function (remove) { remove(); });
      scorePopoverCleanupByContainer.delete(container);
    };
    scorePopoverCleanupByContainer.set(container, cleanup);
    return cleanup;
  }

  function toneForScale(value) {
    if (["P1", "S5", "critical"].includes(value)) {
      return "high";
    }
    if (["P2", "S4", "U1", "U2", "high"].includes(value)) {
      return "attention";
    }
    if (["S1", "P5", "none", "low"].includes(value)) {
      return "quiet";
    }
    return "neutral";
  }

  function areaText(area) {
    if (!area || typeof area !== "object") {
      return "miesto nie je uvedené";
    }
    return [area.label, area.floor, area.room, area.orientation].filter(Boolean).join(" · ");
  }

  function specialtyText(specialty) {
    if (!specialty || typeof specialty !== "object") {
      return "odbornosť nie je uvedená";
    }
    return specialty.label || label("specialty", specialty.specialty, "iný odborník");
  }

  function appendList(documentRef, parent, items) {
    if (!items.length) {
      return;
    }
    const listNode = element(documentRef, "ul", "diag-simple-list");
    items.forEach(function (item) {
      listNode.append(element(documentRef, "li", null, item));
    });
    parent.append(listNode);
  }

  function definitionList(documentRef, entries) {
    const listNode = element(documentRef, "dl", "diag-definition-list");
    entries.filter(function (entry) {
      return entry[1] !== undefined && entry[1] !== null && entry[1] !== "";
    }).forEach(function (entry) {
      const row = element(documentRef, "div");
      row.append(element(documentRef, "dt", null, entry[0]));
      row.append(element(documentRef, "dd", null, entry[1]));
      listNode.append(row);
    });
    return listNode;
  }

  function renderHeader(documentRef, report) {
    const header = element(documentRef, "header", "diag-report-header");
    header.append(element(documentRef, "p", "eyebrow", "DoktorHaus"));
    const title = element(documentRef, "h1", null, "Technická diagnostika nehnuteľnosti");
    title.id = "diag-report-title";
    title.tabIndex = -1;
    header.append(title);
    header.append(element(documentRef, "p", "diag-report-subtitle", report.property.display_name));

    const location = report.property.location || {};
    const locationText = [location.municipality, location.district, location.region]
      .filter(Boolean)
      .filter(function (value, index, values) { return values.indexOf(value) === index; })
      .join(" · ");
    const meta = element(documentRef, "dl", "diag-report-meta");
    [
      ["Lokalita", locationText || location.country_code],
      ["Kontrola", formatDate(report.inspection.performed_at)],
      ["Verzia správy", report.report.version],
      ["Vydané", formatDate(report.report.published_at)]
    ].forEach(function (entry) {
      const item = element(documentRef, "div");
      item.append(element(documentRef, "dt", null, entry[0]));
      item.append(element(documentRef, "dd", null, entry[1]));
      meta.append(item);
    });
    header.append(meta);

    if (report.report.change_type !== "initial" && report.report.change_summary) {
      const note = element(documentRef, "p", "diag-version-note");
      note.append(element(documentRef, "strong", null, "Čo sa v tejto verzii zmenilo: "));
      note.append(documentRef.createTextNode(report.report.change_summary));
      header.append(note);
    }
    return header;
  }

  function renderPortalHeader(documentRef, report) {
    const header = element(documentRef, "header", "diag-portal-header inspection-hero");
    const copy = element(documentRef, "div");
    copy.append(element(documentRef, "p", "eyebrow", "Klientský portál inšpekcie"));
    const title = element(documentRef, "h1", null, report.property.display_name);
    title.id = "diag-portal-title";
    title.tabIndex = -1;
    copy.append(title);
    copy.append(element(
      documentRef,
      "p",
      "diag-portal-intro",
      "Dokumenty, technická správa a fotodokumentácia z inšpekcie na jednom mieste."
    ));

    const location = report.property.location || {};
    const locationText = [location.municipality, location.district, location.region]
      .filter(Boolean)
      .filter(function (value, index, values) { return values.indexOf(value) === index; })
      .join(" · ");
    const summary = element(documentRef, "aside", "inspection-summary");
    summary.setAttribute("aria-label", "Základné údaje inšpekcie");
    const meta = element(documentRef, "dl");
    [
      ["Nehnuteľnosť", report.property.display_name],
      ["Lokalita", locationText || location.country_code],
      ["Kontrola", formatDate(report.inspection.performed_at)],
      ["Verzia správy", report.report.version]
    ].forEach(function (entry) {
      const row = element(documentRef, "div");
      row.append(element(documentRef, "dt", null, entry[0]));
      row.append(element(documentRef, "dd", null, entry[1]));
      meta.append(row);
    });
    summary.append(meta);
    header.append(copy, summary);
    return header;
  }

  function slovakCount(value, singular, few, many) {
    const count = Number(value);
    const absolute = Math.abs(count);
    const form = absolute === 1 ? singular : absolute >= 2 && absolute <= 4 ? few : many;
    return formatNumber(count) + " " + form;
  }

  function normalizeOutputs(outputsDocument, pageUrl) {
    if (!outputsDocument || outputsDocument.schema_version !== "1.0.0-helper" ||
        outputsDocument.document_type !== "diagnostics_outputs" || !Array.isArray(outputsDocument.outputs)) {
      return [];
    }
    const normalized = [];
    const seenUrls = new Set();
    outputsDocument.outputs.forEach(function (output) {
      if (!output) {
        return;
      }
      const safeUrl = validateOutputUrl(output.type, output.url, pageUrl);
      if (!safeUrl || seenUrls.has(safeUrl)) {
        return;
      }
      seenUrls.add(safeUrl);
      const definition = OUTPUT_DEFINITIONS[output.type];
      normalized.push({
        id: typeof output.id === "string" && OUTPUT_ID_PATTERN.test(output.id) ? output.id : null,
        type: output.type,
        url: safeUrl,
        definition: definition,
        title: typeof output.title === "string" && output.title.trim() ? output.title.trim() : definition.title,
        description: typeof output.description === "string" && output.description.trim()
          ? output.description.trim()
          : definition.description
      });
    });
    return normalized;
  }

  function normalizeSupplementalGalleries(outputsDocument, pageUrl) {
    if (!outputsDocument || outputsDocument.schema_version !== "1.0.0-helper" ||
        outputsDocument.document_type !== "diagnostics_outputs" || !Array.isArray(outputsDocument.galleries)) {
      return [];
    }
    const galleryIds = new Set();
    const photoIds = new Set();
    return outputsDocument.galleries.map(function (gallery) {
      if (!gallery || typeof gallery.id !== "string" || !OUTPUT_ID_PATTERN.test(gallery.id) ||
          galleryIds.has(gallery.id) || typeof gallery.title !== "string" || !gallery.title.trim() ||
          !Array.isArray(gallery.photos)) {
        return null;
      }
      galleryIds.add(gallery.id);
      const photos = gallery.photos.map(function (photo) {
        if (!photo || typeof photo.id !== "string" || !OUTPUT_PHOTO_ID_PATTERN.test(photo.id) ||
            photoIds.has(photo.id)) {
          return null;
        }
        const mediaUrl = validateOutputMediaUrl(photo.media_url, pageUrl);
        if (!mediaUrl) {
          return null;
        }
        photoIds.add(photo.id);
        return {
          id: photo.id,
          title: typeof photo.title === "string" && photo.title.trim() ? photo.title.trim() : "Fotografia",
          caption: typeof photo.caption === "string" ? photo.caption.trim() : "",
          url: mediaUrl
        };
      }).filter(Boolean);
      return {
        id: gallery.id,
        title: gallery.title.trim(),
        description: typeof gallery.description === "string" ? gallery.description.trim() : "",
        photos: photos
      };
    }).filter(function (gallery) {
      return gallery && gallery.photos.length > 0;
    });
  }

  function renderOutputsHub(documentRef, report, photoCount, outputsDocument, pageUrl) {
    const wrapper = section(documentRef, "Všetky výstupy z inšpekcie", null, "vystupy");
    wrapper.classList.add("diag-portal-section", "diag-output-hub");
    const heading = wrapper.querySelector(".diag-section-heading");
    heading.insertBefore(element(documentRef, "p", "eyebrow", "VÝSTUPY"), heading.firstChild);

    const grid = element(documentRef, "div", "media-grid diag-portal-media-grid");
    const primary = element(documentRef, "a", "media-card primary-media diag-portal-report-card");
    primary.setAttribute("href", "#sprava");
    const primaryIcon = element(documentRef, "span", "media-icon", "WWW");
    primaryIcon.setAttribute("aria-hidden", "true");
    const primaryCopy = element(documentRef, "span", "media-copy");
    primaryCopy.append(element(documentRef, "strong", null, "Kompletná inšpekčná správa"));
    primaryCopy.append(element(
      documentRef,
      "small",
      null,
      "Interaktívna technická správa so zisteniami, prioritami, odporúčaniami, finančným rámcom a fotodokumentáciou."
    ));
    const counts = [];
    if (report.overview && Number.isInteger(report.overview.issue_count)) {
      counts.push(slovakCount(report.overview.issue_count, "zistenie", "zistenia", "zistení"));
    }
    if (Number.isInteger(photoCount)) {
      counts.push(slovakCount(photoCount, "fotografia", "fotografie", "fotografií"));
    }
    if (counts.length) {
      primaryCopy.append(element(documentRef, "small", "diag-output-meta", counts.join(" · ")));
    }
    const primaryAction = element(documentRef, "span", "media-action", "Otvoriť správu");
    primary.append(primaryIcon, primaryCopy, primaryAction);
    grid.append(primary);

    const outputs = normalizeOutputs(outputsDocument, pageUrl);
    if (outputs.length) {
      outputs.forEach(function (output) {
        const card = element(documentRef, "a", "media-card diag-external-output-card");
        card.dataset.outputType = output.type;
        card.setAttribute("href", output.url);
        card.setAttribute("target", "_blank");
        card.setAttribute("rel", "noopener noreferrer");
        const icon = element(documentRef, "span", "media-icon", output.definition.icon);
        icon.setAttribute("aria-hidden", "true");
        const copy = element(documentRef, "span", "media-copy");
        copy.append(element(documentRef, "strong", null, output.title));
        copy.append(element(documentRef, "small", null, output.description));
        const action = element(documentRef, "span", "media-action", output.definition.action);
        card.append(icon, copy, action);
        grid.append(card);
      });
    }
    wrapper.append(grid);
    return wrapper;
  }

  function renderSupplementalGalleries(documentRef, galleries, viewer) {
    if (!galleries.length) {
      return null;
    }
    const wrapper = section(
      documentRef,
      "Doplnková fotodokumentácia",
      "Samostatné pomenované galérie doplnené k odovzdaným výstupom. Nie sú súčasťou diagnostických evidence väzieb.",
      "doplnkova-fotodokumentacia"
    );
    wrapper.classList.add("diag-portal-section", "diag-supplemental-galleries");
    const heading = wrapper.querySelector(".diag-section-heading");
    heading.insertBefore(element(documentRef, "p", "eyebrow", "DOPLNKOVÁ FOTODOKUMENTÁCIA"), heading.firstChild);
    galleries.forEach(function (gallery) {
      const group = element(documentRef, "section", "diag-supplemental-gallery");
      group.dataset.galleryId = gallery.id;
      group.append(element(documentRef, "h3", null, gallery.title));
      if (gallery.description) {
        group.append(element(documentRef, "p", "diag-supplemental-gallery-description", gallery.description));
      }
      const grid = element(documentRef, "div", "diag-client-photo-grid");
      const viewerItems = gallery.photos.map(function (photo) {
        return { url: photo.url, title: photo.title, code: gallery.title, alt: photo.title };
      });
      gallery.photos.forEach(function (photo, index) {
        const card = element(documentRef, "article", "diag-client-photo-card");
        card.dataset.photoSource = "client-output";
        const button = element(documentRef, "button", "diag-client-photo-button");
        button.type = "button";
        button.setAttribute("aria-label", "Otvoriť fotografiu: " + photo.title);
        const image = element(documentRef, "img");
        image.src = photo.url;
        image.alt = photo.title;
        image.loading = "lazy";
        image.decoding = "async";
        const fallback = createMediaFallback(documentRef);
        fallback.hidden = true;
        image.addEventListener("error", function () {
          image.hidden = true;
          fallback.hidden = false;
        }, { once: true });
        button.append(image, fallback);
        button.addEventListener("click", function () {
          viewer.open(viewerItems, index, button);
        });
        const copy = element(documentRef, "div", "diag-client-photo-copy");
        copy.append(element(documentRef, "p", "diag-card-kicker", gallery.title));
        copy.append(element(documentRef, "h3", null, photo.title));
        if (photo.caption && photo.caption !== photo.title) {
          copy.append(element(documentRef, "p", "diag-client-photo-caption", photo.caption));
        }
        card.append(button, copy);
        grid.append(card);
      });
      group.append(grid);
      wrapper.append(group);
    });
    return wrapper;
  }

  function renderPhotoGallery(documentRef, galleryItems, viewer) {
    const wrapper = section(documentRef, "Fotogaléria z inšpekcie", null, "fotodokumentacia");
    wrapper.classList.add("diag-portal-section", "diag-client-gallery");
    const heading = wrapper.querySelector(".diag-section-heading");
    heading.insertBefore(element(documentRef, "p", "eyebrow", "FOTODOKUMENTÁCIA"), heading.firstChild);
    heading.append(element(
      documentRef,
      "p",
      "diag-gallery-count",
      slovakCount(galleryItems.length, "fotografia", "fotografie", "fotografií")
    ));
    const grid = element(documentRef, "div", "diag-client-photo-grid");
    const viewerItems = galleryItems.map(function (item) {
      return {
        url: item.url,
        title: item.title,
        code: item.label,
        alt: item.label + " — " + item.title
      };
    });
    let appendixMarkerAdded = false;
    galleryItems.forEach(function (item, index) {
      if (item.source === "appendix" && !appendixMarkerAdded) {
        appendixMarkerAdded = true;
        const marker = element(documentRef, "div", "diag-gallery-source-marker");
        marker.id = "portal-zdrojova-fotodokumentacia";
        marker.append(element(documentRef, "strong", null, "Zdrojová fotodokumentácia"));
        marker.append(element(documentRef, "span", null, "Fotografie bez vytvorenej analytickej väzby na konkrétne zistenie."));
        grid.append(marker);
      }
      const card = element(documentRef, "article", "diag-client-photo-card");
      card.dataset.photoSource = item.source;
      const button = element(documentRef, "button", "diag-client-photo-button");
      button.type = "button";
      button.setAttribute("aria-label", "Otvoriť fotografiu: " + item.title);
      const image = element(documentRef, "img");
      image.src = item.url;
      image.alt = item.label + " — " + item.title;
      image.loading = "lazy";
      image.decoding = "async";
      const fallback = createMediaFallback(documentRef);
      fallback.hidden = true;
      image.addEventListener("error", function () {
        image.hidden = true;
        fallback.hidden = false;
      }, { once: true });
      button.append(image, fallback);
      button.addEventListener("click", function () {
        viewer.open(viewerItems, index, button);
      });
      const copy = element(documentRef, "div", "diag-client-photo-copy");
      copy.append(element(documentRef, "p", "diag-card-kicker", item.label));
      copy.append(element(documentRef, "h3", null, item.title));
      if (item.caption && item.caption !== item.title) {
        copy.append(element(documentRef, "p", "diag-client-photo-caption", item.caption));
      }
      const context = element(documentRef, "div", "diag-photo-context");
      context.append(element(documentRef, "strong", null, "Súvisí s:"));
      const list = element(documentRef, "ul");
      item.related.forEach(function (related) {
        const listItem = element(documentRef, "li");
        const link = element(documentRef, "a", null, related.label);
        link.setAttribute("href", related.href);
        listItem.append(link);
        list.append(listItem);
      });
      context.append(list);
      copy.append(context);
      card.append(button, copy);
      grid.append(card);
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderCompleteReportTransition(documentRef) {
    const wrapper = element(documentRef, "section", "diag-complete-report-transition");
    wrapper.id = "sprava";
    wrapper.append(element(documentRef, "p", "eyebrow", "KOMPLETNÁ SPRÁVA"));
    wrapper.append(element(documentRef, "h2", null, "Technická diagnostika nehnuteľnosti"));
    wrapper.append(element(documentRef, "p", null, "Podrobný technický výstup so zisteniami, odporúčaniami, overeniami a obmedzeniami kontroly."));
    return wrapper;
  }

  function renderOverview(documentRef, report) {
    const wrapper = section(
      documentRef,
      "Prehľad",
      "Rýchla orientácia v hlavných zisteniach a odporúčaných krokoch. Hodnoty nemenia odborný obsah správy.",
      "diag-section-overview",
      "Prehľad"
    );
    const overview = report.overview || {};
    const values = [
      ["Hlavné zistenia", formatNumber(overview.issue_count), "samostatné diagnostické okruhy"],
      ["Najvyššia priorita", overview.highest_priority || "—", overview.highest_priority ? label("priority", overview.highest_priority) : "bez zistení"],
      ["Najvyššia závažnosť", overview.highest_severity || "—", overview.highest_severity ? label("severity", overview.highest_severity) : "bez zistení"],
      ["Okamžité kroky", formatNumber(overview.immediate_recommendation_count), "odporúčania typu okamžitý krok"],
      ["Odporúčané overenia", formatNumber(overview.verification_recommendation_count), "kroky na spresnenie záverov"]
    ];
    const grid = element(documentRef, "div", "diag-overview-grid");
    values.forEach(function (value) {
      const item = element(documentRef, "div", "diag-overview-item");
      item.append(element(documentRef, "span", null, value[0]));
      item.append(element(documentRef, "strong", null, value[1]));
      item.append(element(documentRef, "small", null, value[2]));
      grid.append(item);
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderRecommendationCard(documentRef, recommendation, urgent) {
    const card = element(documentRef, "article", "diag-recommendation-card" + (urgent ? " is-urgent" : ""));
    const kicker = element(documentRef, "div", "diag-card-kicker");
    kicker.append(element(documentRef, "span", null, recommendation.display_code));
    kicker.append(element(documentRef, "span", null, label("recommendationType", recommendation.type)));
    kicker.append(element(documentRef, "span", null, label("recommendationStatus", recommendation.status)));
    card.append(kicker);
    card.append(element(documentRef, "h3", null, recommendation.title));
    card.append(element(documentRef, "p", null, recommendation.description));
    card.append(definitionList(documentRef, [
      ["Prečo", recommendation.rationale],
      ["Kedy", recommendation.target_timeframe && recommendation.target_timeframe.text],
      ["Naliehavosť", recommendation.target_timeframe ? scaleText("urgency", recommendation.target_timeframe.urgency) : null],
      ["Vhodná odbornosť", specialtyText(recommendation.responsible_specialty)],
      ["Kontrola výsledku / ďalší krok", recommendation.acceptance_or_follow_up]
    ]));
    if (recommendation.conditional) {
      const condition = element(documentRef, "p", "diag-conditional-note", "Tento krok závisí od výsledku ďalšieho overenia.");
      if (recommendation.condition_description) {
        condition.append(documentRef.createTextNode(" " + recommendation.condition_description));
      }
      card.append(condition);
    }
    return card;
  }

  function renderPriorityRecommendations(documentRef, report) {
    const wrapper = section(
      documentRef,
      "Čo riešiť ako prvé",
      "Kroky sú prevzaté zo schválenej správy a zachovávajú jej poradie aj väzby.",
      "diag-section-priority",
      "Čo riešiť ako prvé"
    );
    const priority = selectPriorityRecommendations(report.recommendations, report.issues);
    if (!priority.length) {
      wrapper.append(element(documentRef, "p", "diag-empty-note", "Správa neobsahuje krok označený na okamžité riešenie."));
      return wrapper;
    }
    const listNode = element(documentRef, "div", "diag-priority-list");
    priority.forEach(function (recommendation) {
      listNode.append(renderRecommendationCard(documentRef, recommendation, true));
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function pricingUnitLabel(unit, currency) {
    const value = typeof unit === "string" ? unit.trim() : "";
    const prefix = typeof currency === "string" ? currency + "/" : "";
    return prefix && value.startsWith(prefix) ? value.slice(prefix.length) : value;
  }

  function pricingPrimaryText(component) {
    const pricing = component && component.pricing ? component.pricing : {};
    const kind = component ? component.pricing_kind : null;
    if (kind === "no_direct_cost") {
      return "Bez priameho nákladu";
    }
    if (kind === "not_estimated") {
      return "Zatiaľ bez poctivého cenového rámca";
    }
    if (kind === "total_range") {
      return formatPricingCurrency(pricing.min, pricing.currency) + " – " + formatPricingCurrency(pricing.max, pricing.currency);
    }
    const unit = pricingUnitLabel(pricing.unit, pricing.currency);
    if (kind === "unit_range") {
      return formatPricingCurrency(pricing.min, pricing.currency) + " – " + formatPricingCurrency(pricing.max, pricing.currency) +
        (unit ? " / " + unit : "");
    }
    if (kind === "fixed_unit") {
      return formatPricingCurrency(pricing.amount, pricing.currency) + (unit ? " / " + unit : "");
    }
    return "Cena nie je uvedená";
  }

  function groupReportPricing(components) {
    const groups = [
      { key: "no_direct_cost", title: "Bez priameho nákladu", components: [] },
      { key: "service", title: "Samostatne nacenené kroky", components: [] },
      { key: "unit_material", title: "Materiál a jednotkové ceny", components: [] },
      { key: "conditional", title: "Podmienené náklady", components: [] },
      { key: "not_estimated", title: "Zatiaľ nemožno poctivo naceniť", components: [] }
    ];
    const byKey = new Map(groups.map(function (group) { return [group.key, group]; }));
    array(components).forEach(function (component) {
      let key;
      if (component.pricing_kind === "no_direct_cost") {
        key = "no_direct_cost";
      } else if (component.pricing_kind === "not_estimated") {
        key = "not_estimated";
      } else if (component.conditional === true) {
        key = "conditional";
      } else if (component.ownership === "service") {
        key = "service";
      } else {
        key = "unit_material";
      }
      byKey.get(key).components.push(component);
    });
    return groups.filter(function (group) { return group.components.length > 0; });
  }

  function pricingReferenceText(item) {
    if (!item) {
      return null;
    }
    return item.display_code ? item.display_code + " — " + item.title : item.title;
  }

  function renderPricingComponent(documentRef, component, issueById, recommendationById) {
    const card = element(documentRef, "article", "diag-pricing-card");
    if (component.conditional) {
      card.dataset.conditional = "true";
    }
    card.append(element(documentRef, "h4", null, component.title));
    if (component.conditional) {
      card.append(element(documentRef, "p", "diag-pricing-condition", "Podmienené ďalším overením"));
    }
    card.append(element(documentRef, "div", "diag-pricing-value", pricingPrimaryText(component)));

    const pricing = component.pricing || {};
    if (component.pricing_kind === "total_range") {
      card.append(element(documentRef, "p", "diag-pricing-expected",
        "Bežný očakávaný rámec približne " + formatPricingCurrency(pricing.expected, pricing.currency) + "."));
    } else if (component.pricing_kind === "unit_range" && pricing.computed_total) {
      card.append(element(documentRef, "p", "diag-pricing-computed",
        "Pri známom množstve: " + formatPricingCurrency(pricing.computed_total.min, pricing.computed_total.currency) +
        " – " + formatPricingCurrency(pricing.computed_total.max, pricing.computed_total.currency) + "."));
    } else if (component.pricing_kind === "fixed_unit" && pricing.computed_total) {
      card.append(element(documentRef, "p", "diag-pricing-computed",
        "Pri známom množstve: " + formatPricingCurrency(pricing.computed_total.min, pricing.computed_total.currency) +
        " – " + formatPricingCurrency(pricing.computed_total.max, pricing.computed_total.currency) + "."));
    } else if (component.pricing_kind === "not_estimated" && pricing.reason) {
      card.append(element(documentRef, "p", "diag-pricing-reason", pricing.reason));
      if (array(pricing.information_needed).length) {
        const needed = element(documentRef, "div", "diag-pricing-needed");
        needed.append(element(documentRef, "strong", null, "Čo treba doplniť"));
        appendList(documentRef, needed, array(pricing.information_needed));
        card.append(needed);
      }
    }

    const scope = element(documentRef, "p", "diag-pricing-scope");
    scope.append(element(documentRef, "strong", null, "Rozsah: "));
    scope.append(documentRef.createTextNode(component.scope));
    card.append(scope);

    if (!["not_estimated"].includes(component.pricing_kind)) {
      const meta = [
        ["Istota odhadu", label("confidence", pricing.confidence)],
        ["Cenová báza", formatDate(pricing.price_basis_date)]
      ];
      if (pricing.vat_status && pricing.vat_status !== "not_applicable") {
        meta.push(["DPH", label("vatStatus", pricing.vat_status)]);
      }
      card.append(definitionList(documentRef, meta));
    }

    if (component.client_caveat) {
      card.append(element(documentRef, "p", "diag-pricing-caveat", component.client_caveat));
    }

    const issueReferences = array(component.linked_issue_ids).map(function (id) {
      return pricingReferenceText(issueById.get(id));
    }).filter(Boolean);
    const recommendationReferences = array(component.linked_recommendation_ids).map(function (id) {
      return pricingReferenceText(recommendationById.get(id));
    }).filter(Boolean);
    if (issueReferences.length || recommendationReferences.length) {
      card.append(definitionList(documentRef, [
        ["Súvisiace zistenia", issueReferences.join("; ")],
        ["Súvisiace kroky", recommendationReferences.join("; ")]
      ]));
    }

    if (array(component.assumptions).length || array(component.exclusions).length) {
      const details = element(documentRef, "details", "diag-pricing-details");
      details.append(element(documentRef, "summary", null, "Čo tento rámec zahŕňa a nezahŕňa"));
      if (array(component.assumptions).length) {
        details.append(element(documentRef, "h5", null, "Predpoklady"));
        appendList(documentRef, details, array(component.assumptions));
      }
      if (array(component.exclusions).length) {
        details.append(element(documentRef, "h5", null, "Čo rámec nezahŕňa"));
        appendList(documentRef, details, array(component.exclusions));
      }
      card.append(details);
    }
    return card;
  }

  function renderPricingAggregation(documentRef, aggregation) {
    const panel = element(documentRef, "aside", "diag-pricing-aggregation");
    if (aggregation.status === "subtotal") {
      panel.append(element(documentRef, "span", null, "Súčet vybraných položiek"));
      panel.append(element(documentRef, "strong", null,
        formatPricingCurrency(aggregation.min, aggregation.currency) + " – " + formatPricingCurrency(aggregation.max, aggregation.currency)));
      panel.append(element(documentRef, "p", null,
        "Bežný očakávaný rámec vybraných položiek je približne " +
        formatPricingCurrency(aggregation.expected, aggregation.currency) + "."));
      panel.append(element(documentRef, "p", "diag-estimate-note",
        "Tento súčet zahŕňa iba explicitne uvedené položky a nie je cenou všetkých opráv."));
    } else {
      panel.append(element(documentRef, "strong", null, "Celkový súčet neuvádzame."));
      panel.append(element(documentRef, "p", null,
        "Dôvod uvedený v správe: " + aggregation.reason));
    }
    return panel;
  }

  function renderReportPricing(documentRef, report) {
    if (!report.pricing) {
      return null;
    }
    const wrapper = section(
      documentRef,
      "Finančný rámec",
      "Uvedené sumy sú orientačné rámce jednotlivých krokov alebo materiálov, nie rozpočet celej opravy. Tam, kde rozsah zatiaľ nie je známy, cenu neuvádzame.",
      "diag-section-pricing",
      "Finančný rámec"
    );
    const issueById = new Map(array(report.issues).map(function (item) { return [item.id, item]; }));
    const recommendationById = new Map(array(report.recommendations).map(function (item) { return [item.id, item]; }));
    groupReportPricing(report.pricing.components).forEach(function (group) {
      const groupNode = element(documentRef, "section", "diag-pricing-group");
      groupNode.append(element(documentRef, "h3", null, group.title));
      const grid = element(documentRef, "div", "diag-pricing-grid");
      group.components.forEach(function (component) {
        grid.append(renderPricingComponent(documentRef, component, issueById, recommendationById));
      });
      groupNode.append(grid);
      wrapper.append(groupNode);
    });
    wrapper.append(renderPricingAggregation(documentRef, report.pricing.aggregation));
    return wrapper;
  }

  function renderScaleDetails(documentRef, issue) {
    const wrapper = subsection(documentRef, "Odborné hodnotenie");
    const grid = element(documentRef, "div", "diag-scale-grid");
    [
      ["Závažnosť", scaleText("severity", issue.severity), issue.severity_rationale],
      ["Priorita", scaleText("priority", issue.priority), issue.priority_rationale],
      ["Kedy riešiť", scaleText("urgency", issue.urgency), issue.urgency_rationale],
      ["Pravdepodobnosť", scaleText("likelihood", issue.likelihood), issue.likelihood_rationale],
      ["Istota záveru", label("confidence", issue.confidence), null],
      ["Vývoj stavu", label("deterioration", issue.deterioration_rate), issue.deterioration_rationale],
      ["Stav zistenia", label("issueStatus", issue.status), null]
    ].forEach(function (entry) {
      const card = element(documentRef, "div", "diag-scale-card");
      card.append(element(documentRef, "span", null, entry[0]));
      card.append(element(documentRef, "strong", null, entry[1]));
      if (entry[2]) {
        card.append(element(documentRef, "p", null, entry[2]));
      }
      grid.append(card);
    });
    wrapper.append(grid);
    if (issue.likelihood_subject) {
      wrapper.append(definitionList(documentRef, [["Čo pravdepodobnosť opisuje", issue.likelihood_subject]]));
    }
    return wrapper;
  }

  function renderObservations(documentRef, observations) {
    if (!observations.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Čo bolo zistené");
    const listNode = element(documentRef, "div", "diag-observation-list");
    observations.forEach(function (observation) {
      const card = element(documentRef, "article", "diag-observation-card");
      card.append(element(documentRef, "h4", null, observation.statement));
      const meta = element(documentRef, "div", "diag-observation-meta");
      if (observation.display_code) {
        meta.append(element(documentRef, "span", null, observation.display_code));
      }
      meta.append(element(documentRef, "span", null, label("observationType", observation.type)));
      meta.append(element(documentRef, "span", null, areaText(observation.area)));
      card.append(meta);
      if (observation.measurement) {
        const measurement = observation.measurement;
        const measurementNode = element(documentRef, "div", "diag-measurement");
        measurementNode.append(element(documentRef, "strong", null,
          formatNumber(measurement.value) + " " + (measurement.unit_label || measurement.unit_code)));
        measurementNode.append(element(documentRef, "span", null, measurement.quantity + " · " + measurement.method));
        if (measurement.notes) {
          measurementNode.append(element(documentRef, "span", null, measurement.notes));
        }
        card.append(measurementNode);
      }
      appendList(documentRef, card, array(observation.limitations));
      listNode.append(card);
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function renderInterpretation(documentRef, issue) {
    if (!issue.interpretation) {
      return null;
    }
    const wrapper = subsection(documentRef, "Čo to môže znamenať");
    wrapper.append(element(documentRef, "p", "diag-subsection-copy", issue.interpretation));
    return wrapper;
  }

  function renderHypotheses(documentRef, hypotheses) {
    if (!hypotheses.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Pracovné vysvetlenie");
    const listNode = element(documentRef, "div", "diag-hypothesis-list");
    hypotheses.forEach(function (hypothesis) {
      const card = element(documentRef, "article", "diag-hypothesis-card");
      card.append(element(documentRef, "h4", null, hypothesis.statement));
      card.append(element(documentRef, "p", null, hypothesis.mechanism));
      const meta = element(documentRef, "div", "diag-hypothesis-meta");
      if (hypothesis.display_code) {
        meta.append(element(documentRef, "span", null, hypothesis.display_code));
      }
      meta.append(element(documentRef, "span", null, label("hypothesisStatus", hypothesis.status)));
      meta.append(element(documentRef, "span", null, "Istota: " + label("confidence", hypothesis.confidence)));
      card.append(meta);
      listNode.append(card);
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function renderRisks(documentRef, issue) {
    const wrapper = subsection(documentRef, "Riziko v čase");
    const grid = element(documentRef, "div", "diag-risk-grid");
    [
      ["Krátkodobé", issue.short_term_risk],
      ["Dlhodobé", issue.long_term_risk]
    ].forEach(function (entry) {
      const risk = entry[1] || {};
      const card = element(documentRef, "article", "diag-risk-card");
      card.dataset.level = risk.level || "unknown";
      card.append(element(documentRef, "span", null, entry[0]));
      card.append(element(documentRef, "strong", null, label("risk", risk.level)));
      card.append(element(documentRef, "p", null, risk.description));
      card.append(element(documentRef, "p", null, "Časový horizont: " + risk.horizon));
      grid.append(card);
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderImpacts(documentRef, impacts) {
    if (!impacts.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Dopad");
    const grid = element(documentRef, "div", "diag-impact-grid");
    impacts.forEach(function (impact) {
      const card = element(documentRef, "article", "diag-impact-card");
      card.dataset.level = impact.level || "unknown";
      card.append(element(documentRef, "span", null, label("impact", impact.dimension)));
      card.append(element(documentRef, "strong", null, label("risk", impact.level)));
      card.append(element(documentRef, "p", null, impact.description));
      const details = element(documentRef, "details");
      details.append(element(documentRef, "summary", null, "Dôvod a istota"));
      details.append(element(documentRef, "p", null, impact.rationale));
      details.append(element(documentRef, "p", null, "Istota: " + label("confidence", impact.confidence)));
      card.append(details);
      grid.append(card);
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderCost(documentRef, issue) {
    const wrapper = subsection(documentRef, "Finančný rámec");
    const estimate = issue.cost_estimate || {};
    if (estimate.status === "estimated") {
      const panel = element(documentRef, "article", "diag-cost-panel");
      panel.append(element(documentRef, "span", null, "Orientačný finančný rozsah"));
      panel.append(element(documentRef, "div", "diag-cost-range",
        formatCurrency(estimate.min, estimate.currency) + " – " + formatCurrency(estimate.max, estimate.currency)));
      panel.append(element(documentRef, "p", null,
        "Bežný očakávaný rámec: približne " + formatCurrency(estimate.expected, estimate.currency) + "."));
      panel.append(definitionList(documentRef, [
        ["Istota odhadu", label("confidence", estimate.confidence)],
        ["Cenová báza", formatDate(estimate.price_basis_date)],
        ["Rozsah", estimate.scope]
      ]));
      const details = element(documentRef, "details");
      details.append(element(documentRef, "summary", null, "Predpoklady a výluky odhadu"));
      details.append(element(documentRef, "h5", null, "Predpoklady odhadu"));
      appendList(documentRef, details, array(estimate.assumptions));
      details.append(element(documentRef, "h5", null, "Čo odhad nezahŕňa"));
      appendList(documentRef, details, array(estimate.exclusions));
      panel.append(details);
      panel.append(element(documentRef, "p", "diag-estimate-note", "Nejde o cenovú ponuku ani položkový rozpočet."));
      wrapper.append(panel);
    } else {
      const panel = element(documentRef, "article", "diag-cost-panel");
      panel.append(element(documentRef, "strong", null, "Finančný rozsah zatiaľ nemožno poctivo určiť."));
      if (estimate.reason) {
        panel.append(element(documentRef, "p", null, estimate.reason));
      }
      wrapper.append(panel);
    }
    return wrapper;
  }

  function renderCostEscalation(documentRef, escalation) {
    if (!escalation) {
      return null;
    }
    const wrapper = subsection(documentRef, "Riziko rastu nákladov");
    const panel = element(documentRef, "article", "diag-cost-panel");
    panel.dataset.level = escalation.level || "unknown";
    panel.append(element(documentRef, "span", null, "Úroveň rizika"));
    panel.append(element(documentRef, "strong", null, label("risk", escalation.level)));
    panel.append(definitionList(documentRef, [
      ["Ako môžu náklady rásť", escalation.mechanism],
      ["Spúšťač", escalation.trigger],
      ["Preventívny krok", escalation.preventive_step],
      ["Istota", label("confidence", escalation.confidence)]
    ]));
    wrapper.append(panel);
    return wrapper;
  }

  function createMediaFallback(documentRef, text) {
    return element(documentRef, "p", "diag-media-fallback", text || "Médium sa nepodarilo načítať.");
  }

  function renderEvidenceCard(documentRef, evidence, safeUrl, imageGroup, imageIndex, viewer) {
    const card = element(documentRef, "article", "diag-evidence-card");
    if (safeUrl && IMAGE_TYPES.has(evidence.type) && String(evidence.content_type || "").startsWith("image/")) {
      const button = element(documentRef, "button", "diag-image-button");
      button.type = "button";
      button.setAttribute("aria-label", "Otvoriť fotografiu: " + evidence.title);
      const image = element(documentRef, "img");
      image.loading = "lazy";
      image.src = safeUrl;
      image.alt = evidence.title;
      const fallback = createMediaFallback(documentRef);
      fallback.hidden = true;
      image.addEventListener("error", function () {
        image.hidden = true;
        fallback.hidden = false;
      }, { once: true });
      button.addEventListener("click", function () {
        viewer.open(imageGroup, imageIndex, button);
      });
      button.append(image, fallback);
      card.append(button);
    } else if (safeUrl && evidence.type === "video") {
      const video = element(documentRef, "video", "diag-media-player");
      video.controls = true;
      video.preload = "metadata";
      video.src = safeUrl;
      const fallback = createMediaFallback(documentRef);
      fallback.hidden = true;
      video.addEventListener("error", function () {
        video.hidden = true;
        fallback.hidden = false;
      }, { once: true });
      card.append(video, fallback);
    } else if (safeUrl && evidence.type === "audio") {
      const audio = element(documentRef, "audio", "diag-audio-player");
      audio.controls = true;
      audio.preload = "metadata";
      audio.src = safeUrl;
      const fallback = createMediaFallback(documentRef);
      fallback.hidden = true;
      audio.addEventListener("error", function () {
        audio.hidden = true;
        fallback.hidden = false;
      }, { once: true });
      card.append(audio, fallback);
    }

    const copy = element(documentRef, "div", "diag-evidence-copy");
    const kicker = element(documentRef, "div", "diag-card-kicker");
    if (evidence.display_code) {
      kicker.append(element(documentRef, "span", null, evidence.display_code));
    }
    kicker.append(element(documentRef, "span", null, evidence.type === "document" ? "Dokument" : "Dôkaz"));
    copy.append(kicker);
    copy.append(element(documentRef, "h5", null, evidence.title));
    const description = IMAGE_TYPES.has(evidence.type)
      ? sanitizePhotoCaption(evidence.description)
      : evidence.description;
    if (description) {
      copy.append(element(documentRef, "p", null, description));
    }
    card.append(copy);

    if (safeUrl && (evidence.type === "document" || evidence.content_type === "application/pdf")) {
      const link = element(documentRef, "a", "btn diag-document-link", "Otvoriť dokument");
      link.href = safeUrl;
      link.target = "_blank";
      link.rel = "noopener";
      card.append(link);
    } else if (safeUrl && !IMAGE_TYPES.has(evidence.type) && evidence.type !== "video" && evidence.type !== "audio") {
      const link = element(documentRef, "a", "btn diag-media-link", "Otvoriť médium");
      link.href = safeUrl;
      link.target = "_blank";
      link.rel = "noopener";
      card.append(link);
    } else if (evidence.has_media && !safeUrl) {
      card.append(createMediaFallback(documentRef, "Médium nie je možné bezpečne otvoriť."));
    }
    return card;
  }

  function renderEvidence(documentRef, issue, pageUrl, viewer) {
    const evidenceItems = array(issue.evidence);
    if (!evidenceItems.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Dôkazy a dokumentácia");
    const grid = element(documentRef, "div", "diag-evidence-grid");
    const prepared = evidenceItems.map(function (evidence) {
      return {
        evidence: evidence,
        safeUrl: evidence.has_media ? validateMediaUrl(evidence.media_url, pageUrl) : null
      };
    });
    const imageGroup = prepared.filter(function (item) {
      return item.safeUrl && IMAGE_TYPES.has(item.evidence.type) && String(item.evidence.content_type || "").startsWith("image/");
    }).map(function (item) {
      return {
        url: item.safeUrl,
        title: item.evidence.title,
        code: item.evidence.display_code || "Fotografia"
      };
    });
    let renderedImages = 0;

    function appendPrepared(item) {
      let imageIndex = -1;
      if (item.safeUrl && IMAGE_TYPES.has(item.evidence.type) && String(item.evidence.content_type || "").startsWith("image/")) {
        imageIndex = renderedImages;
        renderedImages += 1;
      }
      grid.append(renderEvidenceCard(documentRef, item.evidence, item.safeUrl, imageGroup, imageIndex, viewer));
    }

    const initial = prepared.slice(0, MAX_INITIAL_IMAGES);
    initial.forEach(appendPrepared);
    wrapper.append(grid);
    if (prepared.length > MAX_INITIAL_IMAGES) {
      const more = element(documentRef, "button", "btn diag-show-more",
        "Zobraziť ďalšie médiá (" + (prepared.length - MAX_INITIAL_IMAGES) + ")");
      more.type = "button";
      more.addEventListener("click", function () {
        prepared.slice(MAX_INITIAL_IMAGES).forEach(appendPrepared);
        more.remove();
      }, { once: true });
      wrapper.append(more);
    }
    return wrapper;
  }

  function renderMissing(documentRef, missingItems) {
    if (!missingItems.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Čo ešte nevieme");
    const listNode = element(documentRef, "div", "diag-missing-list");
    missingItems.forEach(function (item) {
      const card = element(documentRef, "article", "diag-missing-card");
      card.append(element(documentRef, "h4", null, item.statement));
      card.append(definitionList(documentRef, [
        ["Prečo je to dôležité", item.why_it_matters],
        ["Ako to zistiť", item.how_to_obtain],
        ["Vhodná odbornosť", item.recommended_specialty ? specialtyText(item.recommended_specialty) : null]
      ]));
      if (item.blocking) {
        card.append(element(documentRef, "p", "diag-blocking-note", "Bez tohto overenia nemožno záver uzavrieť."));
      }
      listNode.append(card);
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function renderLinkedRecommendations(documentRef, issue, recommendationById) {
    const linked = array(issue.recommendation_ids).map(function (id) {
      return recommendationById.get(id);
    }).filter(Boolean);
    if (!linked.length) {
      return null;
    }
    const wrapper = subsection(documentRef, "Súvisiace odporúčané kroky");
    appendList(documentRef, wrapper, linked.map(function (item) {
      return item.display_code + " — " + item.title;
    }));
    return wrapper;
  }

  function renderIssue(documentRef, issue, recommendationById, pageUrl, viewer, issueAnchor) {
    const article = element(documentRef, "article", "diag-issue");
    if (issueAnchor) {
      article.id = issueAnchor.id;
    }
    article.dataset.priority = issue.priority;
    const main = element(documentRef, "div", "diag-issue-main");
    const kicker = element(documentRef, "div", "diag-card-kicker");
    if (issue.display_code) {
      kicker.append(element(documentRef, "span", null, issue.display_code));
    }
    kicker.append(element(documentRef, "span", null, issue.category_label || label("category", issue.category)));
    main.append(kicker);
    main.append(element(documentRef, "h3", null, issue.title));
    main.append(element(documentRef, "p", "diag-issue-area",
      array(issue.affected_areas).map(areaText).join(" · ")));
    const badges = element(documentRef, "div", "diag-badge-row");
    badges.append(scoreBadge(documentRef, "priority", issue.priority, issue.priority, label("priority", issue.priority), toneForScale(issue.priority)));
    badges.append(scoreBadge(documentRef, "severity", issue.severity, issue.severity, label("severity", issue.severity), toneForScale(issue.severity)));
    badges.append(scoreBadge(documentRef, "urgency", issue.urgency, issue.urgency, label("urgency", issue.urgency), toneForScale(issue.urgency)));
    badges.append(scoreBadge(documentRef, "confidence", issue.confidence, null, "Istota: " + label("confidence", issue.confidence), "neutral"));
    main.append(badges);
    main.append(element(documentRef, "p", "diag-issue-summary", issue.summary));
    article.append(main);

    const details = element(documentRef, "details", "diag-issue-details");
    details.append(element(documentRef, "summary", null, "Pozrieť podrobnosti k zisteniu"));
    const expanded = element(documentRef, "div", "diag-issue-expanded");
    [
      renderScaleDetails(documentRef, issue),
      renderObservations(documentRef, array(issue.observations)),
      renderInterpretation(documentRef, issue),
      renderHypotheses(documentRef, array(issue.hypotheses)),
      renderRisks(documentRef, issue),
      renderImpacts(documentRef, array(issue.impacts)),
      renderCost(documentRef, issue),
      renderCostEscalation(documentRef, issue.cost_escalation),
      renderEvidence(documentRef, issue, pageUrl, viewer),
      renderMissing(documentRef, array(issue.missing_information)),
      renderLinkedRecommendations(documentRef, issue, recommendationById)
    ].filter(Boolean).forEach(function (node) {
      expanded.append(node);
    });
    if (array(issue.limitations).length) {
      const limitations = subsection(documentRef, "Obmedzenia záveru");
      appendList(documentRef, limitations, array(issue.limitations));
      expanded.append(limitations);
    }
    details.append(expanded);
    article.append(details);
    return article;
  }

  function renderIssues(documentRef, report, pageUrl, viewer, issueAnchors) {
    const wrapper = section(
      documentRef,
      "Hlavné zistenia",
      "Pozorovaný stav, možné vysvetlenie, riziká a odporúčania sú oddelené, aby bolo zrejmé, čo vieme a čo ešte treba overiť.",
      "diag-section-findings",
      "Hlavné zistenia"
    );
    const issues = array(report.issues);
    if (!issues.length) {
      wrapper.append(element(documentRef, "p", "diag-empty-note", "Správa neobsahuje hlavné diagnostické zistenia."));
      return wrapper;
    }
    const recommendationById = new Map(array(report.recommendations).map(function (item) {
      return [item.id, item];
    }));
    const listNode = element(documentRef, "div", "diag-issues");
    issues.forEach(function (issue) {
      listNode.append(renderIssue(documentRef, issue, recommendationById, pageUrl, viewer, issueAnchors.get(issue.id)));
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function dependencyText(dependency, recommendationById) {
    const related = recommendationById.get(dependency.recommendation_id);
    if (!related) {
      return null;
    }
    const prefix = {
      precedes: "Predchádzajúci krok",
      requires: "Vyžadovaný predchádzajúci krok",
      blocks_until_completed: "Najprv dokončiť"
    }[dependency.dependency_type] || "Súvisiaci predchádzajúci krok";
    return prefix + ": " + related.display_code + " — " + related.title;
  }

  function renderRemediationOrder(documentRef, recommendations) {
    const wrapper = section(
      documentRef,
      "Odporúčané poradie krokov",
      "Poradie je prevzaté z reportu. Číslovanie pomáha pri orientácii, ale nevytvára novú technickú závislosť.",
      "diag-section-actions",
      "Odporúčané poradie krokov"
    );
    if (!recommendations.length) {
      wrapper.append(element(documentRef, "p", "diag-empty-note", "Správa neobsahuje odporúčané kroky."));
      return wrapper;
    }
    const byId = new Map(recommendations.map(function (item) { return [item.id, item]; }));
    const listNode = element(documentRef, "ol", "diag-process-list");
    recommendations.forEach(function (recommendation) {
      const step = element(documentRef, "li", "diag-process-step");
      step.append(element(documentRef, "div", "diag-card-kicker",
        recommendation.display_code + " · " + label("recommendationType", recommendation.type)));
      step.append(element(documentRef, "h3", null, recommendation.title));
      step.append(element(documentRef, "p", null, recommendation.description));
      const dependencies = array(recommendation.depends_on).map(function (dependency) {
        return dependencyText(dependency, byId);
      }).filter(Boolean);
      if (dependencies.length) {
        const dependencyNode = element(documentRef, "div", "diag-dependency-note");
        appendList(documentRef, dependencyNode, dependencies);
        step.append(dependencyNode);
      }
      listNode.append(step);
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function uniqueUnverified(items) {
    const seen = new Set();
    return items.filter(function (item) {
      const key = [item.issue_id, item.statement, item.how_to_obtain].join("|");
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    });
  }

  function renderUnverified(documentRef, items) {
    const unique = uniqueUnverified(items);
    if (!unique.length) {
      return null;
    }
    const wrapper = section(
      documentRef,
      "Čo nebolo overené",
      "Otvorené otázky, ktoré môžu ovplyvniť ďalší postup alebo rozsah opravy.",
      "diag-section-unverified",
      "Čo nebolo overené"
    );
    const listNode = element(documentRef, "div", "diag-missing-list");
    unique.forEach(function (item) {
      const card = element(documentRef, "article", "diag-missing-card");
      card.append(element(documentRef, "h3", null, item.statement));
      card.append(definitionList(documentRef, [
        ["Prečo je to dôležité", item.why_it_matters],
        ["Ako to zistiť", item.how_to_obtain],
        ["Vhodná odbornosť", item.recommended_specialty ? specialtyText(item.recommended_specialty) : null]
      ]));
      if (item.blocking) {
        card.append(element(documentRef, "p", "diag-blocking-note", "Bez tohto overenia nemožno záver uzavrieť."));
      }
      listNode.append(card);
    });
    wrapper.append(listNode);
    return wrapper;
  }

  function renderVerifications(documentRef, verifications) {
    if (!verifications.length) {
      return null;
    }
    const wrapper = section(
      documentRef,
      "Odporúčané a vykonané overenia",
      "Overenia spresňujú otvorené otázky; ich odporúčanie ešte neznamená, že už boli vykonané.",
      "diag-section-verifications",
      "Odporúčané a vykonané overenia"
    );
    const grid = element(documentRef, "div", "diag-verification-grid");
    verifications.forEach(function (verification) {
      const card = element(documentRef, "article", "diag-verification-card");
      const kicker = element(documentRef, "div", "diag-card-kicker");
      if (verification.display_code) {
        kicker.append(element(documentRef, "span", null, verification.display_code));
      }
      kicker.append(element(documentRef, "span", null, label("verificationStatus", verification.status)));
      card.append(kicker);
      card.append(element(documentRef, "h3", null, label("verificationType", verification.verification_type)));
      card.append(element(documentRef, "p", null, verification.purpose));
      card.append(definitionList(documentRef, [
        ["Metóda", verification.method],
        ["Výsledok", verification.result_summary],
        ["Odbornosť", verification.responsible_specialty ? specialtyText(verification.responsible_specialty) : null]
      ]));
      appendList(documentRef, card, array(verification.limitations));
      grid.append(card);
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderRelations(documentRef, relations, issues) {
    if (!relations.length) {
      return null;
    }
    const wrapper = section(
      documentRef,
      "Súvislosti medzi zisteniami",
      "Zobrazené sú iba väzby, ktoré sú priamo uvedené v publikovanej správe.",
      "diag-section-relations",
      "Súvislosti medzi zisteniami"
    );
    const issueById = new Map(issues.map(function (issue) { return [issue.id, issue]; }));
    const listNode = element(documentRef, "div", "diag-relation-list");
    relations.forEach(function (relation) {
      const from = issueById.get(relation.from_issue_id);
      const to = issueById.get(relation.to_issue_id);
      if (!from || !to) {
        return;
      }
      const card = element(documentRef, "article", "diag-relation-card");
      card.append(element(documentRef, "h3", null,
        from.title + " — " + label("relation", relation.relation_type) + " — " + to.title));
      card.append(element(documentRef, "p", null, relation.description));
      card.append(element(documentRef, "p", null, "Istota väzby: " + label("confidence", relation.confidence)));
      listNode.append(card);
    });
    if (!listNode.childElementCount) {
      return null;
    }
    wrapper.append(listNode);
    return wrapper;
  }

  function renderScope(documentRef, inspection) {
    const wrapper = section(
      documentRef,
      "Rozsah správy",
      "Správa vychádza iba z uvedeného rozsahu kontroly a priznaných obmedzení.",
      "diag-section-scope"
    );
    const grid = element(documentRef, "div", "diag-scope-grid");
    const scope = element(documentRef, "article", "diag-scope-panel");
    scope.append(element(documentRef, "strong", null, "Čo bolo súčasťou kontroly"));
    appendList(documentRef, scope, array(inspection.scope));
    grid.append(scope);
    if (array(inspection.limitations).length) {
      const limitations = element(documentRef, "article", "diag-scope-panel");
      limitations.append(element(documentRef, "strong", null, "Obmedzenia kontroly"));
      appendList(documentRef, limitations, array(inspection.limitations));
      grid.append(limitations);
    }
    wrapper.append(grid);
    wrapper.append(element(
      documentRef,
      "p",
      "diag-estimate-note",
      "Technická inšpekcia v uvedenom rozsahu nenahrádza znalecký, statický ani revízny posudok tam, kde je také odborné posúdenie potrebné."
    ));
    return wrapper;
  }

  function renderVersion(documentRef, report) {
    const wrapper = section(documentRef, "Informácia o verzii správy", null, "diag-section-version");
    wrapper.append(definitionList(documentRef, [
      ["Verzia", report.report.version],
      ["Vydané", formatDate(report.report.published_at)],
      ["Schválené", report.report.approved_at ? formatDate(report.report.approved_at) : null],
      ["Popis vydania", report.report.change_summary]
    ]));
    return wrapper;
  }

  function createPhotoViewer(documentRef) {
    const modal = documentRef.getElementById("diag-photo-modal");
    const image = documentRef.getElementById("diag-photo-image");
    const title = documentRef.getElementById("diag-photo-title");
    const code = documentRef.getElementById("diag-photo-code");
    const error = documentRef.getElementById("diag-photo-error");
    const previousButton = documentRef.getElementById("diag-photo-prev");
    const nextButton = documentRef.getElementById("diag-photo-next");
    const closeButtons = Array.from(modal.querySelectorAll("[data-diag-photo-close]"));
    let items = [];
    let index = 0;
    let returnFocus = null;

    function show(activeIndex) {
      if (!items.length) {
        return;
      }
      index = (activeIndex + items.length) % items.length;
      const item = items[index];
      error.hidden = true;
      image.hidden = false;
      image.src = item.url;
      image.alt = item.title;
      title.textContent = item.title;
      code.textContent = item.code;
      previousButton.hidden = items.length < 2;
      nextButton.hidden = items.length < 2;
    }

    function close() {
      if (modal.hidden) {
        return;
      }
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
      image.removeAttribute("src");
      documentRef.body.classList.remove("diag-modal-open");
      if (returnFocus && typeof returnFocus.focus === "function") {
        returnFocus.focus();
      }
    }

    function open(nextItems, activeIndex, trigger) {
      if (!Array.isArray(nextItems) || !nextItems.length) {
        return;
      }
      items = nextItems;
      returnFocus = trigger || documentRef.activeElement;
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
      documentRef.body.classList.add("diag-modal-open");
      show(activeIndex);
      closeButtons[closeButtons.length - 1].focus();
    }

    image.addEventListener("error", function () {
      image.hidden = true;
      error.hidden = false;
    });
    previousButton.addEventListener("click", function () { show(index - 1); });
    nextButton.addEventListener("click", function () { show(index + 1); });
    closeButtons.forEach(function (button) { button.addEventListener("click", close); });
    documentRef.addEventListener("keydown", function (event) {
      if (modal.hidden) {
        return;
      }
      if (event.key === "Escape") {
        event.preventDefault();
        close();
      } else if (event.key === "ArrowLeft" && items.length > 1) {
        event.preventDefault();
        show(index - 1);
      } else if (event.key === "ArrowRight" && items.length > 1) {
        event.preventDefault();
        show(index + 1);
      } else if (event.key === "Tab") {
        const controls = [previousButton, nextButton, closeButtons[closeButtons.length - 1]].filter(function (node) {
          return !node.hidden;
        });
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && documentRef.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && documentRef.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    return { open: open, close: close };
  }

  function installPrintBehavior(documentRef) {
    let states = [];
    function beforePrint() {
      states = Array.from(documentRef.querySelectorAll(".diag-issue-details, .diag-pricing-details")).map(function (details) {
        const state = details.open;
        details.open = true;
        return [details, state];
      });
    }
    function afterPrint() {
      states.forEach(function (entry) {
        entry[0].open = entry[1];
      });
      states = [];
    }
    if (documentRef.defaultView) {
      documentRef.defaultView.addEventListener("beforeprint", beforePrint);
      documentRef.defaultView.addEventListener("afterprint", afterPrint);
    }
  }

  function assertClientReport(report) {
    if (!report || typeof report !== "object" || report.document_type !== "client_report" ||
        report.schema_version !== "1.0.0" || !report.report || !report.property || !report.inspection ||
        !report.overview || !Array.isArray(report.issues) || !Array.isArray(report.recommendations)) {
      throw new Error("Unsupported client report.");
    }
    if (Object.prototype.hasOwnProperty.call(report, "pricing") &&
        (!report.pricing || typeof report.pricing !== "object" || Array.isArray(report.pricing) ||
          !Array.isArray(report.pricing.components) || !report.pricing.aggregation ||
          typeof report.pricing.aggregation !== "object" || Array.isArray(report.pricing.aggregation))) {
      throw new Error("Unsupported client report pricing.");
    }
  }

  function renderSourceDocumentationAppendix(appendix, options) {
    if (!appendix || appendix.schema_version !== "1.0.0-helper" ||
        appendix.document_type !== "source_documentation_appendix" ||
        !Array.isArray(appendix.items) || appendix.items.length < 1 ||
        appendix.photo_count !== appendix.items.length) {
      throw new Error("Unsupported source documentation appendix.");
    }
    const documentRef = options && options.document ? options.document : document;
    const pageUrl = options && options.pageUrl
      ? options.pageUrl
      : documentRef.defaultView.location.href;
    const viewer = options && options.photoViewer
      ? options.photoViewer
      : createPhotoViewer(documentRef);
    const wrapper = section(
      documentRef,
      typeof appendix.title === "string" ? appendix.title : "Zdrojová fotodokumentácia",
      typeof appendix.intro === "string" ? appendix.intro : null,
      "zdrojova-fotodokumentacia",
      "Zdrojová fotodokumentácia"
    );
    wrapper.classList.add("dh-source-appendix");
    const heading = wrapper.querySelector(".diag-section-heading");
    if (heading) {
      heading.classList.add("dh-source-appendix-intro");
      heading.insertBefore(
        element(documentRef, "p", "eyebrow", "Pôvodná dokumentácia obhliadky"),
        heading.firstChild
      );
    }
    const grid = element(documentRef, "div", "dh-source-grid");
    const viewerItems = [];
    appendix.items.forEach(function (item) {
      if (!item || typeof item !== "object" ||
          !/^ev_[0-9a-f]{16,32}$/.test(item.evidence_id || "") ||
          typeof item.display_code !== "string" || typeof item.source_identity !== "string") {
        throw new Error("Unsupported source documentation appendix item.");
      }
      const mediaUrl = validateMediaUrl(item.media_url, pageUrl);
      if (!mediaUrl) {
        throw new Error("Unsupported source documentation appendix media.");
      }
      const displayCaption = sanitizePhotoCaption(item.source_caption) ||
        ("Dokumentačná fotografia · " + item.source_identity);
      const card = element(documentRef, "article", "dh-source-card");
      const button = element(documentRef, "button", "dh-source-photo-button");
      button.type = "button";
      const image = element(documentRef, "img");
      image.src = mediaUrl;
      image.alt = displayCaption;
      image.loading = "lazy";
      image.decoding = "async";
      const meta = element(documentRef, "span", "dh-source-meta", item.display_code + " · " + item.source_identity);
      const caption = element(documentRef, "span", "dh-source-caption", displayCaption);
      button.append(image, meta, caption);
      card.append(button);
      grid.append(card);
      const index = viewerItems.length;
      viewerItems.push({url: mediaUrl, title: displayCaption, code: meta.textContent, alt: image.alt});
      button.addEventListener("click", function () { viewer.open(viewerItems, index, button); });
    });
    wrapper.append(grid);
    return wrapper;
  }

  function renderDetailedReport(documentRef, report, pageUrl, viewer, issueAnchors, includeHeader, appendix) {
    const content = element(documentRef, "div", "diag-report-content");
    if (includeHeader) {
      content.append(renderHeader(documentRef, report));
    }
    content.append(renderOverview(documentRef, report));
    content.append(renderPriorityRecommendations(documentRef, report));
    const pricing = renderReportPricing(documentRef, report);
    if (pricing) {
      content.append(pricing);
    }
    content.append(renderIssues(documentRef, report, pageUrl, viewer, issueAnchors));
    content.append(renderRemediationOrder(documentRef, array(report.recommendations)));
    [
      renderUnverified(documentRef, array(report.unverified_items)),
      renderVerifications(documentRef, array(report.verifications)),
      renderRelations(documentRef, array(report.issue_relations), array(report.issues)),
      renderScope(documentRef, report.inspection),
      renderVersion(documentRef, report)
    ].filter(Boolean).forEach(function (node) {
      content.append(node);
    });
    if (appendix) {
      content.append(renderSourceDocumentationAppendix(appendix, {
        document: documentRef,
        pageUrl: pageUrl,
        photoViewer: viewer
      }));
    }
    const end = section(documentRef, "Koniec správy", null, "diag-section-end");
    end.classList.add("diag-report-end");
    end.append(element(documentRef, "p", null, "Po skončení práce so správou sa môžete bezpečne odhlásiť."));
    const logout = element(documentRef, "button", "btn", "Odhlásiť");
    logout.type = "button";
    logout.dataset.diagLogout = "";
    end.append(logout);
    content.append(end);
    refreshSectionNavigation(content, documentRef);
    return content;
  }

  function updatePrintMeta(documentRef, report) {
    const printProperty = documentRef.getElementById("diag-print-property");
    const printMeta = documentRef.getElementById("diag-print-meta");
    if (printProperty) {
      printProperty.textContent = report.property.display_name;
    }
    if (printMeta) {
      printMeta.textContent = formatDate(report.inspection.performed_at) + " · verzia " + report.report.version;
    }
  }

  function renderReport(report, options) {
    assertClientReport(report);
    scorePopoverSequence = 0;
    const documentRef = options && options.document ? options.document : document;
    const pageUrl = options && options.pageUrl
      ? options.pageUrl
      : documentRef.defaultView.location.href;
    const container = options && options.container
      ? options.container
      : documentRef.getElementById("diag-report-content");
    const viewer = options && options.photoViewer
      ? options.photoViewer
      : createPhotoViewer(documentRef);
    const issueAnchors = buildIssueAnchors(report.issues);
    const appendix = options && options.appendix ? options.appendix : null;
    const content = renderDetailedReport(documentRef, report, pageUrl, viewer, issueAnchors, true, appendix);
    container.replaceChildren(content);
    installScorePopoverBehavior(documentRef, container);
    updatePrintMeta(documentRef, report);
    return content;
  }

  function renderClientPortal(report, options) {
    assertClientReport(report);
    scorePopoverSequence = 0;
    const documentRef = options && options.document ? options.document : document;
    const pageUrl = options && options.pageUrl
      ? options.pageUrl
      : documentRef.defaultView.location.href;
    const container = options && options.container
      ? options.container
      : documentRef.getElementById("diag-report-content");
    const viewer = options && options.photoViewer
      ? options.photoViewer
      : createPhotoViewer(documentRef);
    const appendix = options && options.appendix ? options.appendix : null;
    const outputs = options && options.outputs ? options.outputs : null;
    const issueAnchors = buildIssueAnchors(report.issues);
    const galleryItems = buildPhotoGallery(report, appendix, pageUrl, issueAnchors);
    const supplementalGalleries = normalizeSupplementalGalleries(outputs, pageUrl);
    const portal = element(documentRef, "div", "diag-client-portal");
    portal.append(renderPortalHeader(documentRef, report));
    portal.append(renderOutputsHub(documentRef, report, galleryItems.length, outputs, pageUrl));
    portal.append(renderPhotoGallery(documentRef, galleryItems, viewer));
    const supplemental = renderSupplementalGalleries(documentRef, supplementalGalleries, viewer);
    if (supplemental) {
      portal.append(supplemental);
    }
    container.replaceChildren(portal);
    updatePrintMeta(documentRef, report);
    return portal;
  }

  return Object.freeze({
    LABELS: LABELS,
    validateAccessId: validateAccessId,
    validateMediaUrl: validateMediaUrl,
    validateOutputMediaUrl: validateOutputMediaUrl,
    validateOutputUrl: validateOutputUrl,
    formatCurrency: formatCurrency,
    formatPricingCurrency: formatPricingCurrency,
    sanitizePhotoCaption: sanitizePhotoCaption,
    buildIssueAnchors: buildIssueAnchors,
    resolveClientRoute: resolveClientRoute,
    buildPhotoGallery: buildPhotoGallery,
    normalizeSupplementalGalleries: normalizeSupplementalGalleries,
    SCORE_LEGENDS: SCORE_LEGENDS,
    scoreLegendEntries: scoreLegendEntries,
    pricingPrimaryText: pricingPrimaryText,
    groupReportPricing: groupReportPricing,
    selectPriorityRecommendations: selectPriorityRecommendations,
    assertClientReport: assertClientReport,
    createPhotoViewer: createPhotoViewer,
    refreshSectionNavigation: refreshSectionNavigation,
    renderSourceDocumentationAppendix: renderSourceDocumentationAppendix,
    installScorePopoverBehavior: installScorePopoverBehavior,
    installPrintBehavior: installPrintBehavior,
    renderReport: renderReport,
    renderClientPortal: renderClientPortal
  });
});
