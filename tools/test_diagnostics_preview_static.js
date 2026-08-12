"use strict";

const fs = require("fs");
const path = require("path");
const root = path.resolve(__dirname, "..");

function read(relative) {
  return fs.readFileSync(path.join(root, relative), "utf8");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const workflow = read(".github/workflows/deploy-diagnostics-preview.yml");
assert(workflow.includes("workflow_dispatch:"), "Selective workflow must be manually dispatched.");
assert(workflow.includes("diagnostika-preview/"), "Selective workflow target is missing.");
assert(!workflow.includes("--delete"), "Selective workflow must never delete remote files.");
assert(!workflow.includes("./ \"$FTP_SERVER_DIR\""), "Full-site FTP mirror is forbidden.");

const uploadConfig = read("diagnostika-preview/.user.ini");
assert(uploadConfig.includes("upload_max_filesize=64M"), "Preview ZIP upload capacity is missing.");
assert(uploadConfig.includes("max_file_uploads=1"), "Preview must accept only one uploaded file.");

const runtime = read("diagnostika-preview/lib/preview-runtime.php");
assert(runtime.includes("INSPECTIONS_ADMIN_PIN"), "Owner credential reuse is missing.");
assert(runtime.includes("DIAGNOSTICS_STORAGE_ROOT"), "Private storage root is missing.");
assert(runtime.includes("'httponly' => true"), "HttpOnly cookie policy is missing.");
assert(runtime.includes("'samesite' => 'Strict'"), "SameSite cookie policy is missing.");
assert(runtime.includes("session_regenerate_id") === false, "Session regeneration belongs in the auth endpoint.");
for (const php81Only of ["array_is_list", "str_starts_with", "str_ends_with", "str_contains", ": never", "mixed $"]) {
  assert(!runtime.includes(php81Only), `PHP 7.4 compatibility regression: ${php81Only}`);
}

const auth = read("diagnostika-preview/api/auth.php");
assert(auth.includes("session_regenerate_id(true)"), "Login must regenerate the session ID.");
assert(!auth.match(/\$_GET\s*\[\s*['\"]pin/), "PIN must never be read from the URL.");

const bundle = read("diagnostika-preview/lib/preview-bundle.php");
for (const marker of ["ZIP symlinks are not allowed", "ZIP contains duplicate paths", "53 linked and 18 appendix", "VIDEO_SOURCE_REQUIRED"]) {
  assert(bundle.includes(marker), `Bundle validator marker missing: ${marker}`);
}

const preview = read("diagnostika-preview/preview.js");
assert(preview.includes("DoktorHausDiagnosticsReport"), "Production renderer reuse is missing.");
assert(preview.includes("Zdrojová fotodokumentácia"), "Draft appendix composer is missing.");
assert(preview.includes("appendix.photo_count !== 18"), "Appendix exact-count guard is missing.");

console.log("Protected diagnostics preview static tests: PASS");
