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
assert(runtime.includes("dh_preview_storage_probe"), "Private storage write/capacity probe is missing.");
assert(runtime.includes("disk_free_space"), "Private storage free-space check is missing.");
assert(runtime.includes("dh_preview_write_latest_pointer"), "Durable latest-preview fallback is missing.");
assert(runtime.includes("preview-meta.json"), "Legacy completed preview discovery is missing.");
assert(runtime.includes("owner-preview-sessions"), "Private owner-session directory is missing.");
assert(runtime.includes("session.save_path"), "Owner sessions must use the private diagnostics storage.");
assert(runtime.includes("session.save_handler', 'files"), "Owner sessions must use the deterministic file handler.");
assert(runtime.includes("session.use_cookies', '1"), "Owner session cookies must be enabled explicitly.");
assert(runtime.includes("dh_preview_send_session_cookie"), "Owner session cookie must be emitted explicitly.");
assert(runtime.includes("automatically started PHP session could not be released"), "Hosting auto-started sessions must be replaced safely.");
assert(runtime.includes("OWNER_SESSION_COOKIE_MISSING"), "Owner session failures must distinguish a missing cookie.");
assert(runtime.includes("OWNER_SESSION_STATE_MISSING"), "Owner session failures must distinguish missing server state.");
assert(runtime.includes("'httponly' => true"), "HttpOnly cookie policy is missing.");
assert(runtime.includes("'samesite' => 'Strict'"), "SameSite cookie policy is missing.");
assert(runtime.includes("session_regenerate_id") === false, "Session regeneration belongs in the auth endpoint.");
for (const php81Only of ["array_is_list", "str_starts_with", "str_ends_with", "str_contains", ": never", "mixed $"]) {
  assert(!runtime.includes(php81Only), `PHP 7.4 compatibility regression: ${php81Only}`);
}

const auth = read("diagnostika-preview/api/auth.php");
assert(auth.includes("session_regenerate_id(true)"), "Login must regenerate the session ID.");
assert(auth.includes("dh_preview_send_session_cookie()"), "Login must emit the regenerated session cookie explicitly.");
assert(!auth.match(/\$_GET\s*\[\s*['\"]pin/), "PIN must never be read from the URL.");
assert(auth.includes("dh_preview_latest_id()"), "Login must recover the latest stored preview.");

const storage = read("diagnostika-preview/api/storage.php");
assert(storage.includes("dh_preview_storage_probe"), "Storage preflight endpoint is missing.");
assert(storage.includes("dh_preview_require_owner"), "Storage preflight must require owner authentication.");
assert(storage.includes("class_exists('ZipArchive')"), "Storage preflight must report ZIP runtime support.");
assert(storage.includes("function_exists('finfo_open')"), "Storage preflight must report Fileinfo runtime support.");

const upload = read("diagnostika-preview/api/upload.php");
assert(upload.includes("DhPreviewStorageException"), "Upload must distinguish insufficient private storage.");
assert(upload.includes("507"), "Insufficient private storage must fail explicitly.");
assert(upload.includes("CONTENT_LENGTH") && upload.includes("413"), "Active server request-limit failures must be explicit.");
assert(upload.includes("session_write_close()"), "Upload must release the owner-session lock before ZIP processing.");
assert(!upload.includes("$_SESSION['latest_preview_id']"), "Latest preview must not depend on session state.");
assert(upload.includes("validationCode"), "Controlled bundle failures must return a stable validation code.");

const publish = read("diagnostika-preview/api/publish.php");
assert(publish.includes("dh_preview_require_same_origin_post"), "Published upload must require same-origin POST.");
assert(publish.includes("dh_preview_require_owner"), "Published upload must require owner authentication.");
assert(publish.includes("dh_preview_require_csrf"), "Published upload must require CSRF.");
assert(publish.includes("DhPublishedBundleInstaller::install"), "Published upload must use the controlled installer.");
assert(publish.includes("validationCode"), "Published upload must return controlled validation codes.");

const publishedBundle = read("diagnostika-preview/lib/published-bundle.php");
for (const marker of [
  "installPublishedPackage",
  "ZIP symlinks are not allowed",
  "ZIP contains duplicate paths",
  "MAX_UNCOMPRESSED_BYTES",
  "STORAGE_ALREADY_EXISTS",
  "getPublishedManifestSha256",
  "owner-publish.ndjson"
]) {
  assert(publishedBundle.includes(marker), `Published bundle safety marker missing: ${marker}`);
}

const app = read("diagnostika-preview/app.js");
assert(app.includes('"/diagnostika-preview/api/storage.php"'), "Owner UI must use the canonical absolute storage endpoint.");
assert(app.includes('"/diagnostika-preview/api/auth.php"'), "Owner UI must use the canonical absolute auth endpoint.");
assert(app.includes('"/diagnostika-preview/api/upload.php"'), "Owner UI must use the canonical absolute upload endpoint.");
assert(app.includes('"/diagnostika-preview/api/publish.php"'), "Owner UI must use the canonical absolute publish endpoint.");
assert(app.includes("150000"), "Owner UI must bound a stalled upload.");
assert(app.includes("posledný úspešne uložený preview"), "Owner UI must explain durable upload recovery.");
assert(app.includes("body.zipSupported"), "Owner UI must stop when ZIP support is unavailable.");
assert(app.includes("body.fileInfoSupported"), "Owner UI must stop when Fileinfo support is unavailable.");
assert(app.includes("validationCode"), "Owner UI must display the controlled validation code.");

const previewIndex = read("diagnostika-preview/index.php");
assert(previewIndex.includes("preg_replace('#/+#'"), "Owner entrypoint must normalize repeated path slashes.");
assert(previewIndex.includes("Location: /diagnostika-preview/"), "Owner entrypoint canonical redirect is missing.");

const bundle = read("diagnostika-preview/lib/preview-bundle.php");
for (const marker of [
  "ZIP symlinks are not allowed",
  "ZIP contains duplicate paths",
  "Client report linked media do not match the manifest records",
  "Source documentation media do not match the manifest records",
  "Source documentation page reference is invalid",
  "VIDEO_SOURCE_REQUIRED"
]) {
  assert(bundle.includes(marker), `Bundle validator marker missing: ${marker}`);
}

const preview = read("diagnostika-preview/preview.js");
const renderer = read("JSS/diagnostics-report.js");
assert(preview.includes("DoktorHausDiagnosticsReport"), "Production renderer reuse is missing.");
assert(preview.includes("renderer.renderSourceDocumentationAppendix"), "Shared appendix renderer reuse is missing.");
assert(renderer.includes("Zdrojová fotodokumentácia"), "Shared appendix composer is missing.");
assert(renderer.includes('"zdrojova-fotodokumentacia"'), "Appendix stable anchor is missing.");
assert(renderer.includes("appendix.photo_count !== appendix.items.length"), "Appendix count guard is missing.");
assert(preview.includes("renderer.refreshSectionNavigation(content, document)"), "Appendix navigation refresh is missing.");
assert(renderer.includes("sanitizePhotoCaption(item.source_caption)"), "Photo boilerplate is not removed from the client caption.");
assert(renderer.includes("sanitizePhotoCaption(evidence.description)"), "Linked photo boilerplate is not removed from the client caption.");
assert(renderer.includes("button.append(image, meta, caption)"), "Recovered photos must remain visible in the appendix.");

console.log("Protected diagnostics preview static tests: PASS");
