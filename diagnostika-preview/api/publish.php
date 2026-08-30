<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';
require_once __DIR__ . '/../lib/published-bundle.php';

dh_preview_require_same_origin_post();
dh_preview_require_owner();
$contentLength = (string)($_SERVER['CONTENT_LENGTH'] ?? '');
if ($contentLength !== '' && preg_match('/^[0-9]+$/D', $contentLength) === 1
    && (int)$contentLength > DH_PREVIEW_MAX_ZIP_BYTES + 1048576) {
    dh_preview_fail(413, 'Published package upload exceeds the active server request limit.');
}
dh_preview_require_csrf(is_string($_POST['csrfToken'] ?? null) ? $_POST['csrfToken'] : null);
session_write_close();

if (array_diff(array_keys($_POST), ['csrfToken']) || array_diff(array_keys($_FILES), ['bundle'])) {
    dh_preview_fail(400, 'Invalid publish request.');
}
$bundle = $_FILES['bundle'] ?? null;
if (!is_array($bundle) || ($bundle['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($bundle['tmp_name'] ?? null) || !is_uploaded_file($bundle['tmp_name'])
    || !is_int($bundle['size'] ?? null) || $bundle['size'] <= 0 || $bundle['size'] > DH_PREVIEW_MAX_ZIP_BYTES) {
    dh_preview_fail(400, 'Valid published package ZIP is required.');
}

try {
    $result = DhPublishedBundleInstaller::install($bundle['tmp_name']);
} catch (DhPublishedBundleException $error) {
    dh_preview_json(422, [
        'ok' => false,
        'error' => 'Published package validation failed.',
        'validationCode' => $error->errorCode(),
    ]);
} catch (Throwable $error) {
    dh_preview_fail(503, 'Published package upload is temporarily unavailable.');
}

dh_preview_json(201, ['ok' => true] + $result);
