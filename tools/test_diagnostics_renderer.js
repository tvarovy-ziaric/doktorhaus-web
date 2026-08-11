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

const fixturePath = path.join(repoRoot, "docs", "diagnostics", "fixtures", "valid", "client-report-example.json");
const fixture = JSON.parse(fs.readFileSync(fixturePath, "utf8"));
assert.doesNotThrow(() => renderer.assertClientReport(fixture));
assert.ok(renderer.selectPriorityRecommendations(fixture.recommendations, fixture.issues).length > 0);

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

console.log("Diagnostics renderer tests passed: labels, priority, currency, URL boundary and client safety.");
