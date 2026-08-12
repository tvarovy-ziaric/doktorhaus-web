<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';
require_once __DIR__ . '/../lib/preview-bundle.php';

dh_preview_require_same_origin_post();
dh_preview_require_owner();
dh_preview_require_csrf(is_string($_POST['csrfToken'] ?? null) ? $_POST['csrfToken'] : null);

if (array_diff(array_keys($_POST), ['csrfToken']) || array_diff(array_keys($_FILES), ['bundle'])) {
    dh_preview_fail(400, 'Invalid upload request.');
}
$bundle = $_FILES['bundle'] ?? null;
if (!is_array($bundle) || ($bundle['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($bundle['tmp_name'] ?? null) || !is_uploaded_file($bundle['tmp_name'])
    || !is_int($bundle['size'] ?? null) || $bundle['size'] <= 0 || $bundle['size'] > 64 * 1024 * 1024) {
    dh_preview_fail(400, 'Valid preview ZIP is required.');
}

try {
    $meta = DhPreviewBundleInstaller::install($bundle['tmp_name']);
} catch (DhPreviewBundleException $error) {
    dh_preview_fail(422, 'Preview ZIP validation failed.');
} catch (Throwable $error) {
    dh_preview_fail(503, 'Preview upload is temporarily unavailable.');
}

$_SESSION['latest_preview_id'] = $meta['preview_id'];
dh_preview_json(201, [
    'ok' => true,
    'previewId' => $meta['preview_id'],
    'previewUrl' => '../' . $meta['preview_id'] . '/',
    'counts' => $meta['counts'],
]);
