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

const html = fs.readFileSync(path.join(repoRoot, "inspekcia.html"), "utf8");
assert.match(html, /<meta name="robots" content="noindex,nofollow,noarchive">/);
assert.match(html, /<meta name="referrer" content="no-referrer">/);
assert.doesNotMatch(html, /(?:src|href)=["']https?:\/\//i);
assert.doesNotMatch(html, /analytics|gtag|googletagmanager|facebook\.net/i);

console.log("Diagnostics renderer tests passed: labels, priority, currency, URL boundary and client safety.");
