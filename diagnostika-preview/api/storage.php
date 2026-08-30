<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    dh_preview_fail(405, 'Method not allowed.');
}
if (array_keys($_GET) !== []) {
    dh_preview_fail(400, 'Invalid request selector.');
}

dh_preview_require_owner();
session_write_close();
try {
    $storage = dh_preview_storage_probe();
} catch (RuntimeException $error) {
    dh_preview_fail(503, 'Private preview storage is unavailable.');
}

dh_preview_json(200, [
    'ok' => true,
    'writable' => $storage['writable'],
    'capacityKnown' => $storage['capacity_known'],
    'availableBytes' => $storage['available_bytes'],
    'uploadLimitBytes' => DH_PREVIEW_MAX_ZIP_BYTES,
    'safetyReserveBytes' => DH_PREVIEW_STORAGE_RESERVE_BYTES,
    'zipSupported' => class_exists('ZipArchive'),
    'fileInfoSupported' => function_exists('finfo_open'),
    'latestPreviewId' => dh_preview_latest_id(),
]);
