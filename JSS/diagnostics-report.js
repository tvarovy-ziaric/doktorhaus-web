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
    })
  });

  const ACCESS_ID_PATTERN = /^acc_[0-9a-f]{32}$/;
  const EVIDENCE_ID_PATTERN = /^ev_[0-9a-f]{16,32}$/;
  const IMAGE_TYPES = new Set(["photo", "thermal_image", "drone_photo", "photo_360"]);
  const MAX_INITIAL_IMAGES = 8;

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

  function section(documentRef, title, description, id) {
    const wrapper = element(documentRef, "section", "diag-section");
    if (id) {
      wrapper.id = id;
    }
    const heading = element(documentRef, "div", "diag-section-heading");
    heading.append(element(documentRef, "h2", null, title));
    if (description) {
      heading.append(element(documentRef, "p", null, description));
    }
    wrapper.append(heading);
    return wrapper;
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

  function renderOverview(documentRef, report) {
    const wrapper = section(
      documentRef,
      "Prehľad",
      "Rýchla orientácia v hlavných zisteniach a odporúčaných krokoch. Hodnoty nemenia odborný obsah správy."
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
      "Kroky sú prevzaté zo schválenej správy a zachovávajú jej poradie aj väzby."
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
    copy.append(element(documentRef, "p", null, evidence.description));
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

  function renderIssue(documentRef, issue, recommendationById, pageUrl, viewer) {
    const article = element(documentRef, "article", "diag-issue");
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
    badges.append(badge(documentRef, issue.priority, label("priority", issue.priority), toneForScale(issue.priority)));
    badges.append(badge(documentRef, issue.severity, label("severity", issue.severity), toneForScale(issue.severity)));
    badges.append(badge(documentRef, issue.urgency, label("urgency", issue.urgency), toneForScale(issue.urgency)));
    badges.append(badge(documentRef, null, "Istota: " + label("confidence", issue.confidence), "neutral"));
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

  function renderIssues(documentRef, report, pageUrl, viewer) {
    const wrapper = section(
      documentRef,
      "Hlavné zistenia",
      "Pozorovaný stav, možné vysvetlenie, riziká a odporúčania sú oddelené, aby bolo zrejmé, čo vieme a čo ešte treba overiť."
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
      listNode.append(renderIssue(documentRef, issue, recommendationById, pageUrl, viewer));
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
      "Poradie je prevzaté z reportu. Číslovanie pomáha pri orientácii, ale nevytvára novú technickú závislosť."
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
      "Otvorené otázky, ktoré môžu ovplyvniť ďalší postup alebo rozsah opravy."
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
      "Overenia spresňujú otvorené otázky; ich odporúčanie ešte neznamená, že už boli vykonané."
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
      "Zobrazené sú iba väzby, ktoré sú priamo uvedené v publikovanej správe."
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
      "Správa vychádza iba z uvedeného rozsahu kontroly a priznaných obmedzení."
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
    const wrapper = section(documentRef, "Informácia o verzii správy", null);
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
      states = Array.from(documentRef.querySelectorAll(".diag-issue-details")).map(function (details) {
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
  }

  function renderReport(report, options) {
    assertClientReport(report);
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
    const content = element(documentRef, "div", "diag-report-content");
    content.append(renderHeader(documentRef, report));
    content.append(renderOverview(documentRef, report));
    content.append(renderPriorityRecommendations(documentRef, report));
    content.append(renderIssues(documentRef, report, pageUrl, viewer));
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
    const end = section(documentRef, "Koniec správy", null);
    end.classList.add("diag-report-end");
    end.append(element(documentRef, "p", null, "Po skončení práce so správou sa môžete bezpečne odhlásiť."));
    const logout = element(documentRef, "button", "btn", "Odhlásiť");
    logout.type = "button";
    logout.dataset.diagLogout = "";
    end.append(logout);
    content.append(end);
    container.replaceChildren(content);

    const printProperty = documentRef.getElementById("diag-print-property");
    const printMeta = documentRef.getElementById("diag-print-meta");
    if (printProperty) {
      printProperty.textContent = report.property.display_name;
    }
    if (printMeta) {
      printMeta.textContent = formatDate(report.inspection.performed_at) + " · verzia " + report.report.version;
    }
    return content;
  }

  return Object.freeze({
    LABELS: LABELS,
    validateAccessId: validateAccessId,
    validateMediaUrl: validateMediaUrl,
    formatCurrency: formatCurrency,
    selectPriorityRecommendations: selectPriorityRecommendations,
    assertClientReport: assertClientReport,
    createPhotoViewer: createPhotoViewer,
    installPrintBehavior: installPrintBehavior,
    renderReport: renderReport
  });
});
