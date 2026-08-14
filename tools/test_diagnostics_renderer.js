"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const renderer = require("../JSS/diagnostics-report.js");

const repoRoot = path.resolve(__dirname, "..");
const pageUrl = "https://doktorhaus.sk/inspekcia.html?access=acc_0123456789abcdef0123456789abcdef";

assert.equal(renderer.validateAccessId("acc_0123456789abcdef0123456789abcdef"), true);
for (const candidate of [
  "acc_0123456789abcdef",
  "acc_0123456789ABCDEF0123456789ABCDEF",
  "acc_0123456789abcdef0123456789abcdef/extra",
  "0123456789abcdef0123456789abcdef"
]) {
  assert.equal(renderer.validateAccessId(candidate), false, candidate);
}

const evidenceId = "ev_0123456789abcdef";
assert.equal(
  renderer.validateMediaUrl("api/diagnostics-media.php?evidence=" + evidenceId, pageUrl),
  "https://doktorhaus.sk/api/diagnostics-media.php?evidence=" + evidenceId
);
assert.equal(
  renderer.validateMediaUrl("/api/diagnostics-media.php?evidence=" + evidenceId, pageUrl),
  "https://doktorhaus.sk/api/diagnostics-media.php?evidence=" + evidenceId
);
for (const candidate of [
  "https://example.test/api/diagnostics-media.php?evidence=" + evidenceId,
  "api/diagnostics-media.php?evidence=" + evidenceId + "&path=photo.jpg",
  "api/diagnostics-media.php?evidence=" + evidenceId + "#fragment",
  "api/diagnostics-report.php?evidence=" + evidenceId,
  "api/diagnostics-media.php?evidence=bad",
  "javascript:alert(1)"
]) {
  assert.equal(renderer.validateMediaUrl(candidate, pageUrl), null, candidate);
}

assert.equal(renderer.LABELS.severity.S1, "zanedbateľná");
assert.equal(renderer.LABELS.severity.S5, "kritická");
assert.equal(renderer.LABELS.priority.P1, "riešiť ako prvé");
assert.equal(renderer.LABELS.urgency.U2, "približne do 1 mesiaca");
assert.equal(renderer.LABELS.risk.high, "vysoké");
assert.equal(renderer.LABELS.verificationType.specialist_assessment, "posúdenie špecialistom");
assert.deepEqual(
  renderer.scoreLegendEntries("priority", "P1").map((entry) => [entry.code, entry.label, entry.current]),
  [
    ["P1", "riešiť ako prvé", true],
    ["P2", "vysoká priorita", false],
    ["P3", "plánovať", false],
    ["P4", "nižšia priorita", false],
    ["P5", "sledovať", false]
  ]
);
assert.deepEqual(
  renderer.scoreLegendEntries("severity", "S4").map((entry) => [entry.code, entry.label, entry.current]),
  [
    ["S1", "zanedbateľná", false],
    ["S2", "nízka", false],
    ["S3", "stredná", false],
    ["S4", "vysoká", true],
    ["S5", "kritická", false]
  ]
);
assert.deepEqual(
  renderer.scoreLegendEntries("urgency", "U1").map((entry) => [entry.code, entry.label, entry.current]),
  [
    ["U1", "bez odkladu", true],
    ["U2", "približne do 1 mesiaca", false],
    ["U3", "približne do 3 mesiacov", false],
    ["U4", "približne do 12 mesiacov", false],
    ["U5", "plánovaná údržba / sledovanie", false]
  ]
);
assert.deepEqual(
  renderer.scoreLegendEntries("confidence", "medium").map((entry) => [entry.code, entry.label, entry.current]),
  [
    [null, "neurčená", false],
    [null, "nízka", false],
    [null, "stredná", true],
    [null, "vysoká", false]
  ]
);
assert.equal(renderer.SCORE_LEGENDS.priority.title, "Priorita");
assert.equal(renderer.SCORE_LEGENDS.severity.title, "Závažnosť");
assert.equal(renderer.SCORE_LEGENDS.urgency.title, "Naliehavosť");
assert.equal(renderer.SCORE_LEGENDS.confidence.title, "Istota záveru");

class FakeEventTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, handler) {
    const handlers = this.listeners.get(type) || [];
    handlers.push(handler);
    this.listeners.set(type, handlers);
  }

  removeEventListener(type, handler) {
    this.listeners.set(type, (this.listeners.get(type) || []).filter((item) => item !== handler));
  }

  emit(type, properties = {}) {
    const event = Object.assign({
      key: null,
      relatedTarget: null,
      target: this,
      defaultPrevented: false,
      preventDefault() { this.defaultPrevented = true; }
    }, properties);
    for (const handler of this.listeners.get(type) || []) {
      handler(event);
    }
    return event;
  }
}

class FakeNode extends FakeEventTarget {
  constructor(className = "", tagName = "div") {
    super();
    this.className = className;
    this.tagName = tagName.toUpperCase();
    this.nodeType = 1;
    this.children = [];
    this.attributes = new Map();
    this.dataset = {};
    this.hidden = false;
    this.focused = false;
    this.id = "";
    this.parentNode = null;
    this.textContent = "";
    this.classList = {
      add: (...names) => {
        const classes = new Set(this.className.split(/\s+/).filter(Boolean));
        names.forEach((name) => classes.add(name));
        this.className = Array.from(classes).join(" ");
      }
    };
  }

  append(...children) {
    children.forEach((child) => { child.parentNode = this; });
    this.children.push(...children);
  }

  insertBefore(child, reference) {
    child.parentNode = this;
    const index = reference ? this.children.indexOf(reference) : -1;
    if (index >= 0) {
      this.children.splice(index, 0, child);
    } else {
      this.children.push(child);
    }
  }

  replaceChildren(...children) {
    this.children.forEach((child) => { child.parentNode = null; });
    this.children = [];
    this.append(...children);
  }

  remove() {
    if (!this.parentNode) {
      return;
    }
    this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
    this.parentNode = null;
  }

  get nextSibling() {
    if (!this.parentNode) {
      return null;
    }
    const index = this.parentNode.children.indexOf(this);
    return index >= 0 ? this.parentNode.children[index + 1] || null : null;
  }

  get firstChild() {
    return this.children[0] || null;
  }

  get childElementCount() {
    return this.children.filter((child) => child.nodeType === 1).length;
  }

  contains(target) {
    return target === this || this.children.some((child) => child.contains && child.contains(target));
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }

  querySelectorAll(selector) {
    const matches = [];
    const visit = (node) => {
      if (node.nodeType === 1 && node.matches(selector)) {
        matches.push(node);
      }
      node.children.forEach(visit);
    };
    visit(this);
    return matches;
  }

  matches(selector) {
    if (selector.startsWith("#")) {
      return this.id === selector.slice(1);
    }
    const classMatch = selector.match(/^\.([a-z0-9_-]+)/i);
    if (classMatch && !this.className.split(/\s+/).includes(classMatch[1])) {
      return false;
    }
    if (selector.includes("[id]") && !this.id) {
      return false;
    }
    if (selector.includes("[data-diag-navigation-label]") && !this.dataset.diagNavigationLabel) {
      return false;
    }
    if (selector.includes("[data-current=\"true\"]") && this.dataset.current !== "true") {
      return false;
    }
    return Boolean(classMatch);
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.has(name) ? this.attributes.get(name) : null;
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  focus() {
    this.focused = true;
  }
}

class FakeTextNode extends FakeNode {
  constructor(text) {
    super("", "#text");
    this.nodeType = 3;
    this.textContent = String(text);
  }

  matches() {
    return false;
  }
}

class FakeDocument extends FakeEventTarget {
  constructor(hoverCapable) {
    super();
    this.defaultView = {
      location: { href: pageUrl },
      matchMedia: () => ({ matches: hoverCapable })
    };
  }

  createElement(tagName) {
    return new FakeNode("", tagName);
  }

  createTextNode(text) {
    return new FakeTextNode(text);
  }

  getElementById() {
    return null;
  }
}

function scorePopoverFixture(hoverCapable) {
  const documentRef = new FakeEventTarget();
  documentRef.defaultView = { matchMedia: () => ({ matches: hoverCapable }) };
  const container = new FakeNode();
  const wrapper = new FakeNode("diag-score-help");
  const trigger = new FakeNode("diag-score-trigger");
  const popover = new FakeNode("diag-score-popover");
  trigger.setAttribute("aria-expanded", "false");
  popover.hidden = true;
  wrapper.append(trigger, popover);
  container.querySelectorAll = (selector) => selector === ".diag-score-help" ? [wrapper] : [];
  return { documentRef, container, wrapper, trigger, popover, outside: new FakeNode() };
}

const desktopPopover = scorePopoverFixture(true);
const cleanupDesktopPopover = renderer.installScorePopoverBehavior(desktopPopover.documentRef, desktopPopover.container);
desktopPopover.wrapper.emit("mouseenter");
assert.equal(desktopPopover.trigger.getAttribute("aria-expanded"), "true");
assert.equal(desktopPopover.popover.hidden, false);
desktopPopover.wrapper.emit("mouseleave");
assert.equal(desktopPopover.trigger.getAttribute("aria-expanded"), "false");
desktopPopover.wrapper.emit("focusin", { target: desktopPopover.trigger });
assert.equal(desktopPopover.popover.hidden, false);
const escapeEvent = desktopPopover.documentRef.emit("keydown", { key: "Escape" });
assert.equal(escapeEvent.defaultPrevented, true);
assert.equal(desktopPopover.popover.hidden, true);
assert.equal(desktopPopover.trigger.focused, true);
desktopPopover.wrapper.emit("focusin", { target: desktopPopover.trigger });
desktopPopover.wrapper.emit("focusout", { relatedTarget: desktopPopover.outside });
assert.equal(desktopPopover.popover.hidden, true);
cleanupDesktopPopover();

const mobilePopover = scorePopoverFixture(false);
const cleanupMobilePopover = renderer.installScorePopoverBehavior(mobilePopover.documentRef, mobilePopover.container);
mobilePopover.trigger.emit("pointerdown");
mobilePopover.wrapper.emit("focusin", { target: mobilePopover.trigger });
mobilePopover.trigger.emit("click");
assert.equal(mobilePopover.popover.hidden, false);
mobilePopover.documentRef.emit("click", { target: mobilePopover.outside });
assert.equal(mobilePopover.popover.hidden, true);
mobilePopover.trigger.emit("pointerdown");
mobilePopover.trigger.emit("click");
assert.equal(mobilePopover.popover.hidden, false);
mobilePopover.trigger.emit("pointerdown");
mobilePopover.trigger.emit("click");
assert.equal(mobilePopover.popover.hidden, true);
cleanupMobilePopover();

const recommendations = [
  { id: "rec_1", target_timeframe: { urgency: "U3" }, issue_ids: ["issue_1"] },
  { id: "rec_2", target_timeframe: { urgency: "U1" }, issue_ids: [] },
  { id: "rec_3", target_timeframe: { urgency: "U2" }, issue_ids: [] },
  { id: "rec_4", target_timeframe: { urgency: "U5" }, issue_ids: ["issue_2"] }
];
const issues = [
  { id: "issue_1", priority: "P2" },
  { id: "issue_2", priority: "P4" }
];
assert.deepEqual(
  renderer.selectPriorityRecommendations(recommendations, issues).map((item) => item.id),
  ["rec_1", "rec_2", "rec_3"]
);

const euro = renderer.formatCurrency(2000, "EUR");
assert.match(euro, /2[\s\u00a0\u202f]?000/);
assert.match(euro, /€/);
assert.match(renderer.formatCurrency(19.5, "not-a-currency"), /€/);
assert.equal(renderer.formatCurrency(19.5, "EUR"), "20 €");

const pricingCurrencyCases = [
  [15.10, "15,10 €"],
  [20.50, "20,50 €"],
  [29.32, "29,32 €"],
  [48.35, "48,35 €"],
  [59.61, "59,61 €"],
  [196.80, "196,80 €"],
  [250, "250 €"]
];
for (const [value, expected] of pricingCurrencyCases) {
  assert.equal(renderer.formatPricingCurrency(value, "EUR"), expected);
}
assert.equal(renderer.formatPricingCurrency(0.1 + 0.2, "EUR"), "0,30 €");
const recoveredPhotoCaption = renderer.sanitizePhotoCaption(
  "Fotografia priložená v SafetyCulture reporte k otázke „Je detail v poriadku?“. " +
  "Originálny mediálny súbor nebol extrahovaný."
);
assert.equal(recoveredPhotoCaption, "Fotografia priložená v SafetyCulture reporte k otázke „Je detail v poriadku?“.");
assert.doesNotMatch(recoveredPhotoCaption, /Originálny mediálny súbor nebol extrahovaný\./);

const fixturePath = path.join(repoRoot, "docs", "diagnostics", "fixtures", "valid", "client-report-example.json");
const fixture = JSON.parse(fs.readFileSync(fixturePath, "utf8"));
const stalePhotoBoilerplate = "Originálny mediálny súbor nebol extrahovaný.";
fixture.issues[0].evidence[0].description += " " + stalePhotoBoilerplate;
assert.doesNotThrow(() => renderer.assertClientReport(fixture));
assert.ok(renderer.selectPriorityRecommendations(fixture.recommendations, fixture.issues).length > 0);
const renderDocument = new FakeDocument(true);
const renderContainer = new FakeNode();
const renderedReport = renderer.renderReport(fixture, {
  document: renderDocument,
  container: renderContainer,
  pageUrl,
  photoViewer: { open() {} }
});
const renderedEvidenceText = renderedReport.querySelectorAll(".diag-evidence-copy")
  .flatMap((node) => node.children.map((child) => child.textContent))
  .join(" ");
assert.doesNotMatch(renderedEvidenceText, /Originálny mediálny súbor nebol extrahovaný./);

const emptyPhotoFixture = JSON.parse(JSON.stringify(fixture));
emptyPhotoFixture.issues[0].evidence[0].description = stalePhotoBoilerplate;
const emptyPhotoContainer = new FakeNode();
const emptyPhotoReport = renderer.renderReport(emptyPhotoFixture, {
  document: new FakeDocument(false),
  container: emptyPhotoContainer,
  pageUrl,
  photoViewer: { open() {} }
});
const emptyPhotoCopy = emptyPhotoReport.querySelector(".diag-evidence-copy");
assert.equal(emptyPhotoCopy.children.some((child) => child.tagName === "P"), false);

const documentFixture = JSON.parse(JSON.stringify(fixture));
documentFixture.issues[0].evidence[0].type = "document";
documentFixture.issues[0].evidence[0].description = stalePhotoBoilerplate;
const documentReport = renderer.renderReport(documentFixture, {
  document: new FakeDocument(false),
  container: new FakeNode(),
  pageUrl,
  photoViewer: { open() {} }
});
const documentEvidenceText = documentReport.querySelectorAll(".diag-evidence-copy")
  .flatMap((node) => node.children.map((child) => child.textContent))
  .join(" ");
assert.match(documentEvidenceText, /Originálny mediálny súbor nebol extrahovaný./);
const renderedScoreTriggers = renderContainer.querySelectorAll(".diag-score-trigger");
assert.equal(renderedScoreTriggers.length, fixture.issues.length * 4);
for (const trigger of renderedScoreTriggers) {
  assert.equal(trigger.tagName, "BUTTON");
  assert.equal(trigger.getAttribute("aria-haspopup"), "dialog");
  assert.equal(trigger.getAttribute("aria-expanded"), "false");
  assert.ok(renderedReport.querySelector("#" + trigger.getAttribute("aria-controls")));
  assert.equal(trigger.getAttribute("title"), null);
}
const renderedPriorityPopover = renderedReport.querySelector(".diag-score-popover");
assert.equal(renderedPriorityPopover.getAttribute("role"), "dialog");
assert.equal(renderedPriorityPopover.querySelectorAll(".diag-score-legend-row").length, 5);
assert.equal(renderedPriorityPopover.querySelectorAll(".diag-score-legend-row[data-current=\"true\"]").length, 1);
const renderedFirstWrapper = renderedReport.querySelector(".diag-score-help");
renderedFirstWrapper.emit("mouseenter");
assert.equal(renderedScoreTriggers[0].getAttribute("aria-expanded"), "true");
renderedFirstWrapper.emit("mouseleave");
assert.equal(renderedScoreTriggers[0].getAttribute("aria-expanded"), "false");

const appendixDocument = new FakeDocument(false);
const appendix = {
  schema_version: "1.0.0-helper",
  document_type: "source_documentation_appendix",
  title: "Zdrojov\u00e1 fotodokument\u00e1cia",
  intro: "P\u00f4vodn\u00e1 dokument\u00e1cia obhliadky.",
  photo_count: 18,
  items: Array.from({length: 18}, (_, index) => {
    const suffix = (index + 1).toString(16).padStart(16, "0");
    return {
      evidence_id: "ev_" + suffix,
      display_code: "EV-" + String(index + 1).padStart(3, "0"),
      source_identity: "Photo " + (index + 1),
      source_caption: "Photo " + (index + 1) + " \u2013 Detail. Origin\u00e1lny medi\u00e1lny s\u00fabor nebol extrahovan\u00fd.",
      media_url: "api/diagnostics-media.php?evidence=ev_" + suffix,
      content_type: "image/jpeg",
      order: index + 1
    };
  })
};
const appendixSection = renderer.renderSourceDocumentationAppendix(appendix, {
  document: appendixDocument,
  pageUrl,
  photoViewer: {open() {}}
});
assert.equal(appendixSection.id, "diag-section-source-photos");
assert.equal(appendixSection.dataset.diagNavigationLabel, "Zdrojov\u00e1 fotodokument\u00e1cia");
assert.equal(appendixSection.querySelectorAll(".dh-source-card").length, 18);
assert.equal(appendixSection.querySelectorAll(".dh-source-caption").length, 18);
for (const caption of appendixSection.querySelectorAll(".dh-source-caption")) {
  assert.doesNotMatch(caption.textContent, /Origin\u00e1lny medi\u00e1lny s\u00fabor nebol extrahovan\u00fd\./);
}

assert.equal(Object.prototype.hasOwnProperty.call(fixture, "pricing"), false);
const pricingComponents = [
  {
    id: "rpc_0123456789abcdef",
    title: "Beznákladové opatrenie",
    pricing_kind: "no_direct_cost",
    ownership: "not_applicable",
    conditional: false,
    pricing: { min: 0, expected: 0, max: 0, currency: "EUR" }
  },
  {
    id: "rpc_1123456789abcdef",
    title: "Samostatná služba",
    pricing_kind: "total_range",
    ownership: "service",
    conditional: false,
    pricing: { min: 100, expected: 150, max: 200, currency: "EUR" }
  },
  {
    id: "rpc_2123456789abcdef",
    title: "Jednotkový materiál",
    pricing_kind: "unit_range",
    ownership: "client_owned_material",
    conditional: false,
    pricing: { min: 20, expected: 30, max: 40, currency: "EUR", unit: "EUR/ks" }
  },
  {
    id: "rpc_3123456789abcdef",
    title: "Pevná jednotka",
    pricing_kind: "fixed_unit",
    ownership: "service",
    conditional: true,
    pricing: { amount: 25, currency: "EUR", unit: "EUR/ks" }
  },
  {
    id: "rpc_4123456789abcdef",
    title: "Nenacenený rozsah",
    pricing_kind: "not_estimated",
    ownership: "service",
    conditional: false,
    pricing: { reason: "Chýba výmera." }
  }
];
const pricedFixture = JSON.parse(JSON.stringify(fixture));
pricedFixture.pricing = {
  components: pricingComponents,
  aggregation: { status: "not_computed", reason: "Časť položiek závisí od výmery." }
};
assert.doesNotThrow(() => renderer.assertClientReport(pricedFixture));
assert.throws(() => renderer.assertClientReport(Object.assign({}, fixture, { pricing: [] })), /pricing/);
assert.deepEqual(
  renderer.groupReportPricing(pricingComponents).map((group) => group.title),
  [
    "Bez priameho nákladu",
    "Samostatne nacenené kroky",
    "Materiál a jednotkové ceny",
    "Podmienené náklady",
    "Zatiaľ nemožno poctivo naceniť"
  ]
);
assert.equal(renderer.pricingPrimaryText(pricingComponents[0]), "Bez priameho nákladu");
assert.doesNotMatch(renderer.pricingPrimaryText(pricingComponents[0]), /0\s*€|oprava/i);
assert.match(renderer.pricingPrimaryText(pricingComponents[1]), /100/);
assert.match(renderer.pricingPrimaryText(pricingComponents[1]), /200/);
assert.match(renderer.pricingPrimaryText(pricingComponents[2]), /\/ ks/);
assert.match(renderer.pricingPrimaryText(pricingComponents[3]), /25/);
assert.match(renderer.pricingPrimaryText(pricingComponents[3]), /\/ ks/);
assert.equal(renderer.pricingPrimaryText(pricingComponents[4]), "Zatiaľ bez poctivého cenového rámca");
assert.doesNotMatch(renderer.pricingPrimaryText(pricingComponents[4]), /€|EUR/);
assert.equal(renderer.pricingPrimaryText({
  pricing_kind: "unit_range",
  pricing: { min: 15.10, max: 59.61, currency: "EUR", unit: "bm" }
}), "15,10 € – 59,61 € / bm");
assert.equal(renderer.pricingPrimaryText({
  pricing_kind: "fixed_unit",
  pricing: { amount: 196.80, currency: "EUR", unit: "objekt do 3 vzoriek" }
}), "196,80 € / objekt do 3 vzoriek");

const inspectedFiles = [
  "inspekcia.html",
  "JSS/diagnostics-report.js",
  "JSS/diagnostics-client.js"
];
const forbiddenClientTokens = [
  "inner" + "HTML",
  "outer" + "HTML",
  "insertAdjacent" + "HTML",
  "document." + "write",
  "local" + "Storage",
  "session" + "Storage",
  "indexed" + "DB",
  "service" + "Worker",
  "new " + "Function",
  "eval" + "("
];
for (const relative of inspectedFiles) {
  const source = fs.readFileSync(path.join(repoRoot, relative), "utf8");
  for (const token of forbiddenClientTokens) {
    assert.equal(source.includes(token), false, relative + " contains forbidden client token " + token);
  }
}

const rendererSource = fs.readFileSync(path.join(repoRoot, "JSS/diagnostics-report.js"), "utf8");
const adminSource = fs.readFileSync(path.join(repoRoot, "inspekcie-admin.html"), "utf8");
assert.doesNotMatch(adminSource, /<input[^>]+name=["']diagnosticsAccessId["']/i);
assert.doesNotMatch(adminSource, /<input[^>]+name=["']diagnosticsReportId["']/i);
assert.doesNotMatch(adminSource, /<input[^>]+name=["']diagnosticsVersion["']/i);
assert.match(adminSource, /<select[^>]+id="diagnostics-report-select"/i);
assert.match(adminSource, /action:\s*"available-diagnostics"/);
assert.match(adminSource, /availableDiagnostics\.length === 1/);
assert.match(adminSource, /availableDiagnostics\.length > 1/);
assert.match(adminSource, /reportId:\s*selectedReport\.reportId/);
assert.match(adminSource, /version:\s*selectedReport\.version/);
assert.doesNotMatch(adminSource, /includes\([^\n]*(?:title|location)|localeCompare\([^\n]*(?:title|location)/i);
for (const section of [
  ["diag-section-overview", "Prehľad"],
  ["diag-section-priority", "Čo riešiť ako prvé"],
  ["diag-section-pricing", "Finančný rámec"],
  ["diag-section-findings", "Hlavné zistenia"],
  ["diag-section-actions", "Odporúčané poradie krokov"],
  ["diag-section-unverified", "Čo nebolo overené"],
  ["diag-section-verifications", "Odporúčané a vykonané overenia"],
  ["diag-section-relations", "Súvislosti medzi zisteniami"]
]) {
  assert.match(rendererSource, new RegExp(section[0]));
  assert.match(rendererSource, new RegExp(section[1]));
}
assert.match(rendererSource, /querySelectorAll\("\.diag-section\[id\]\[data-diag-navigation-label\]"\)/);
assert.match(rendererSource, /link\.setAttribute\("href", "#" \+ target\.id\)/);
assert.match(rendererSource, /refreshSectionNavigation\(content, documentRef\)/);
const renderFlowStart = rendererSource.indexOf("content.append(renderPriorityRecommendations");
const pricingFlow = rendererSource.indexOf("const pricing = renderReportPricing", renderFlowStart);
const issuesFlow = rendererSource.indexOf("content.append(renderIssues", pricingFlow);
assert.ok(renderFlowStart >= 0 && pricingFlow > renderFlowStart && issuesFlow > pricingFlow,
  "Report-level pricing must render after first steps and before main findings.");
const costStart = rendererSource.indexOf("function renderCost(documentRef, issue)");
const costEnd = rendererSource.indexOf("function renderCostEscalation", costStart);
const costSource = rendererSource.slice(costStart, costEnd);
assert.match(costSource, /issue\.cost_estimate/);
assert.doesNotMatch(costSource, /report\.pricing|pricing_kind|linked_issue_ids/);
assert.match(costSource, /formatCurrency\(/);
assert.doesNotMatch(costSource, /formatPricingCurrency\(/);
const pricingStart = rendererSource.indexOf("function pricingPrimaryText");
const pricingEnd = rendererSource.indexOf("function renderScaleDetails", pricingStart);
const pricingSource = rendererSource.slice(pricingStart, pricingEnd);
assert.match(pricingSource, /formatPricingCurrency\(/);
assert.doesNotMatch(pricingSource, /formatCurrency\(/);
assert.match(rendererSource, /Podmienené ďalším overením/);
assert.match(rendererSource, /Celkový súčet neuvádzame\./);
assert.match(rendererSource, /Súčet vybraných položiek/);
assert.match(rendererSource, /nie je cenou všetkých opráv/);
assert.doesNotMatch(rendererSource, /Celková cena opráv|0 € oprava/);

const html = fs.readFileSync(path.join(repoRoot, "inspekcia.html"), "utf8");
assert.match(html, /<meta name="robots" content="noindex,nofollow,noarchive">/);
assert.match(html, /<meta name="referrer" content="no-referrer">/);
assert.doesNotMatch(html, /(?:src|href)=["']https?:\/\//i);
assert.doesNotMatch(html, /analytics|gtag|googletagmanager|facebook\.net/i);

const rendererCss = fs.readFileSync(path.join(repoRoot, "styles", "diagnostics-report.css"), "utf8");
assert.match(rendererCss, /\.diag-report-navigation-scroll\s*\{[^}]*overflow-x:\s*auto/s);
assert.match(rendererCss, /\.diag-section\[id\]\s*\{[^}]*scroll-margin-top:/s);
assert.match(rendererCss, /@media print[\s\S]*\.diag-report-navigation,[\s\S]*display:\s*none\s*!important/);
assert.match(rendererCss, /\.diag-score-popover\s*\{[^}]*width:\s*min\(18rem, calc\(100vw - 2rem\)\)/s);
assert.match(rendererCss, /@media \(max-width: 619px\)[\s\S]*\.diag-score-popover,[\s\S]*width:\s*auto/s);
assert.match(rendererCss, /\.diag-score-legend-row\[data-current="true"\]\s*\{[^}]*background:\s*var\(--accent-soft\)/s);
assert.match(rendererCss, /@media print[\s\S]*\.diag-score-popover,[\s\S]*display:\s*none\s*!important/);
const printCss = rendererCss.slice(rendererCss.indexOf("@media print"));
assert.match(printCss, /\.diag-score-help-mark,/);
assert.doesNotMatch(printCss, /\.diag-score-trigger/);

console.log("Diagnostics renderer tests passed: labels, priority, currency, URL boundary and client safety.");
