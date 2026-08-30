<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';
require_once __DIR__ . '/../lib/preview-bundle.php';

dh_preview_require_same_origin_post();
dh_preview_require_owner();
$contentLength = (string)($_SERVER['CONTENT_LENGTH'] ?? '');
if ($contentLength !== '' && preg_match('/^[0-9]+$/D', $contentLength) === 1) {
    $requestBytes = (int)$contentLength;
    if ($requestBytes > DH_PREVIEW_MAX_ZIP_BYTES + 1048576
        || ($requestBytes > 0 && $_POST === [] && $_FILES === [])) {
        dh_preview_fail(413, 'Preview upload exceeds the active server request limit.');
    }
}
dh_preview_require_csrf(is_string($_POST['csrfToken'] ?? null) ? $_POST['csrfToken'] : null);
session_write_close();

if (array_diff(array_keys($_POST), ['csrfToken']) || array_diff(array_keys($_FILES), ['bundle'])) {
    dh_preview_fail(400, 'Invalid upload request.');
}
$bundle = $_FILES['bundle'] ?? null;
if (!is_array($bundle) || ($bundle['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($bundle['tmp_name'] ?? null) || !is_uploaded_file($bundle['tmp_name'])
    || !is_int($bundle['size'] ?? null) || $bundle['size'] <= 0 || $bundle['size'] > DH_PREVIEW_MAX_ZIP_BYTES) {
    dh_preview_fail(400, 'Valid preview ZIP is required.');
}

try {
    $meta = DhPreviewBundleInstaller::install($bundle['tmp_name']);
} catch (DhPreviewStorageException $error) {
    dh_preview_fail(507, 'Private preview storage is unavailable or has insufficient free space.');
} catch (DhPreviewBundleException $error) {
    $message = $error->getMessage();
    $validationCode = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $message), '_'));
    if ($validationCode === '' || strlen($validationCode) > 96) {
        $validationCode = 'BUNDLE_VALIDATION_FAILED';
    }
    $status = in_array($message, [
        'ZIP support is unavailable.',
        'Media type verification is unavailable.',
    ], true) ? 503 : 422;
    dh_preview_json($status, [
        'ok' => false,
        'error' => 'Preview ZIP validation failed.',
        'validationCode' => $validationCode,
    ]);
} catch (Throwable $error) {
    dh_preview_fail(503, 'Preview upload is temporarily unavailable.');
}

dh_preview_json(201, [
    'ok' => true,
    'previewId' => $meta['preview_id'],
    'previewUrl' => '/diagnostika-preview/' . $meta['preview_id'] . '/',
    'counts' => $meta['counts'],
]);
