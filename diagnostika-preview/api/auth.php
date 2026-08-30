<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';

try {
    dh_preview_start_session();
} catch (RuntimeException $error) {
    dh_preview_fail(503, 'Owner preview is unavailable.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method === 'GET') {
    if (!dh_preview_owner_authenticated()) {
        session_write_close();
        dh_preview_json(200, ['ok' => true, 'authenticated' => false]);
    }
    $csrfToken = dh_preview_csrf_token();
    $latestPreviewId = dh_preview_latest_id();
    session_write_close();
    dh_preview_json(200, [
        'ok' => true,
        'authenticated' => true,
        'csrfToken' => $csrfToken,
        'latestPreviewId' => $latestPreviewId,
    ]);
}

dh_preview_require_same_origin_post();
$payload = dh_preview_read_json_body();
$action = $payload['action'] ?? null;

if ($action === 'login') {
    $payloadKeys = array_keys($payload);
    sort($payloadKeys);
    if ($payloadKeys !== ['action', 'pin']) {
        dh_preview_fail(400, 'Invalid request.');
    }
    try {
        $config = dh_preview_config();
        dh_preview_ensure_private_directory($config['preview_root']);
    } catch (RuntimeException $error) {
        dh_preview_fail(503, 'Owner preview is unavailable.');
    }
    $pin = $payload['pin'] ?? null;
    if (!is_string($pin) || strlen($pin) > 128) {
        $pin = '';
    }
    try {
        dh_preview_rate_check_and_update($config['owner_pin'], false);
    } catch (RuntimeException $error) {
        dh_preview_fail(503, 'Owner login protection is unavailable.');
    }
    if (!hash_equals($config['owner_pin'], $pin)) {
        dh_preview_fail(401, 'Owner authentication failed.');
    }
    try {
        dh_preview_rate_check_and_update($config['owner_pin'], true);
    } catch (RuntimeException $error) {
        dh_preview_fail(503, 'Owner login protection is unavailable.');
    }
    if (!session_regenerate_id(true)) {
        dh_preview_fail(503, 'Owner session could not be rotated.');
    }
    try {
        dh_preview_send_session_cookie();
    } catch (RuntimeException $error) {
        dh_preview_fail(503, 'Owner session cookie is unavailable.');
    }
    $_SESSION = [
        'owner_authenticated' => true,
        'owner_created_at' => time(),
        'owner_last_seen_at' => time(),
        'owner_csrf' => bin2hex(random_bytes(32)),
    ];
    $csrfToken = $_SESSION['owner_csrf'];
    $latestPreviewId = dh_preview_latest_id();
    session_write_close();
    dh_preview_json(200, [
        'ok' => true,
        'authenticated' => true,
        'csrfToken' => $csrfToken,
        'latestPreviewId' => $latestPreviewId,
    ]);
}

if ($action === 'logout') {
    dh_preview_require_owner();
    dh_preview_require_csrf(is_string($payload['csrfToken'] ?? null) ? $payload['csrfToken'] : null);
    dh_preview_destroy_session();
    dh_preview_json(200, ['ok' => true, 'authenticated' => false]);
}

dh_preview_fail(400, 'Invalid request.');
