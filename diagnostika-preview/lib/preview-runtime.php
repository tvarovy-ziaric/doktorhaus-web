<?php
declare(strict_types=1);

const DH_PREVIEW_SESSION_NAME = 'DH_PREVIEWSESSID';
const DH_PREVIEW_SESSION_IDLE = 3600;
const DH_PREVIEW_SESSION_ABSOLUTE = 28800;
const DH_PREVIEW_ID_PATTERN = '/^pvw_[0-9a-f]{32}$/D';
const DH_PREVIEW_MAX_ZIP_BYTES = 67108864;
const DH_PREVIEW_STORAGE_RESERVE_BYTES = 16777216;

function dh_preview_is_list(array $value): bool
{
    if ($value === []) {
        return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function dh_preview_starts_with(string $value, string $prefix): bool
{
    return $prefix === '' || strpos($value, $prefix) === 0;
}

function dh_preview_ends_with(string $value, string $suffix): bool
{
    if ($suffix === '') {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function dh_preview_contains(string $value, string $needle): bool
{
    return $needle === '' || strpos($value, $needle) !== false;
}

function dh_preview_site_root(): string
{
    return dirname(__DIR__, 2);
}

function dh_preview_is_local_test(): bool
{
    if (getenv('PREVIEW_ALLOW_INSECURE_LOCAL_TEST') !== '1' || PHP_SAPI !== 'cli-server') {
        return false;
    }
    return in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

function dh_preview_is_https(): bool
{
    return (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function dh_preview_security_headers(string $contentType = 'application/json; charset=utf-8'): void
{
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function dh_preview_page_headers(): void
{
    dh_preview_security_headers('text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
}

function dh_preview_json(int $status, array $payload): void
{
    dh_preview_security_headers();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dh_preview_fail(int $status, string $message): void
{
    dh_preview_json($status, ['ok' => false, 'error' => $message]);
}

function dh_preview_require_https(): void
{
    if (!dh_preview_is_https() && !dh_preview_is_local_test()) {
        dh_preview_fail(400, 'Secure HTTPS connection required.');
    }
}

function dh_preview_load_array_config(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function dh_preview_config(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $siteRoot = dh_preview_site_root();
    $inspectionConfig = dh_preview_load_array_config($siteRoot . '/api/inspections.config.php');
    $diagnosticsConfig = dh_preview_load_array_config($siteRoot . '/api/diagnostics.config.php');

    $ownerPin = (string)(getenv('INSPECTIONS_ADMIN_PIN') ?: '');
    if ($ownerPin === '') {
        $ownerPin = (string)($inspectionConfig['admin_pin'] ?? '');
    }
    $storageRoot = (string)(getenv('DIAGNOSTICS_STORAGE_ROOT') ?: '');
    if ($storageRoot === '') {
        $storageRoot = (string)($diagnosticsConfig['storage_root'] ?? '');
    }
    if ($ownerPin === '' || strlen($ownerPin) < 6) {
        throw new RuntimeException('Owner preview credential is not configured.');
    }
    if ($storageRoot === '' || preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $storageRoot) !== 1) {
        throw new RuntimeException('Private diagnostics storage is not configured.');
    }

    $storageReal = realpath($storageRoot);
    if ($storageReal === false || !is_dir($storageReal) || is_link($storageReal)) {
        throw new RuntimeException('Private diagnostics storage is unavailable.');
    }
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $storageComparable = rtrim(str_replace('\\', '/', strtolower($storageReal)), '/');
        $documentComparable = rtrim(str_replace('\\', '/', strtolower($documentRoot)), '/');
        if ($storageComparable === $documentComparable || dh_preview_starts_with($storageComparable . '/', $documentComparable . '/')) {
            throw new RuntimeException('Private diagnostics storage is unsafe.');
        }
    }

    $cached = [
        'owner_pin' => $ownerPin,
        'storage_root' => $storageReal,
        'preview_root' => $storageReal . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'owner-previews',
        'session_root' => $storageReal . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'owner-preview-sessions',
    ];
    return $cached;
}

function dh_preview_ensure_private_directory(string $path): void
{
    if (is_link($path)) {
        throw new RuntimeException('Unsafe private storage entry.');
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Private preview storage is unavailable.');
    }
    @chmod($path, 0700);
}

function dh_preview_storage_probe(int $requiredBytes = 0): array
{
    if ($requiredBytes < 0) {
        throw new RuntimeException('Invalid storage requirement.');
    }
    $config = dh_preview_config();
    $previewRoot = $config['preview_root'];
    dh_preview_ensure_private_directory($previewRoot);
    $rootReal = realpath($previewRoot);
    if ($rootReal === false || !is_dir($rootReal) || is_link($rootReal) || !is_writable($rootReal)) {
        throw new RuntimeException('Private preview storage is not writable.');
    }

    $probePath = $rootReal . DIRECTORY_SEPARATOR . '.storage-probe-' . bin2hex(random_bytes(16));
    $probe = @fopen($probePath, 'xb');
    if ($probe === false) {
        throw new RuntimeException('Private preview storage write test failed.');
    }
    $probePayload = random_bytes(4096);
    $written = @fwrite($probe, $probePayload);
    $flushed = @fflush($probe);
    fclose($probe);
    $removed = @unlink($probePath);
    if ($written !== strlen($probePayload) || !$flushed || !$removed) {
        @unlink($probePath);
        throw new RuntimeException('Private preview storage write test failed.');
    }

    $free = @disk_free_space($rootReal);
    $capacityKnown = is_float($free) || is_int($free);
    $availableBytes = $capacityKnown ? max(0, (int)$free) : null;
    $sufficient = $requiredBytes === 0
        ? true
        : ($capacityKnown && $availableBytes >= $requiredBytes);
    return [
        'writable' => true,
        'capacity_known' => $capacityKnown,
        'available_bytes' => $availableBytes,
        'required_bytes' => $requiredBytes,
        'sufficient' => $sufficient,
    ];
}

function dh_preview_latest_pointer_path(): string
{
    $config = dh_preview_config();
    return $config['preview_root'] . DIRECTORY_SEPARATOR . 'latest-preview.json';
}

function dh_preview_latest_id(): ?string
{
    try {
        $config = dh_preview_config();
    } catch (RuntimeException $error) {
        return null;
    }
    $rootReal = realpath($config['preview_root']);
    if ($rootReal === false || !is_dir($rootReal) || is_link($rootReal)) {
        return null;
    }
    $path = $rootReal . DIRECTORY_SEPARATOR . 'latest-preview.json';
    if (is_file($path) && !is_link($path) && filesize($path) <= 2048) {
        try {
            $pointer = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $pointer = null;
        }
        $previewId = is_array($pointer) ? ($pointer['preview_id'] ?? null) : null;
        if (is_string($previewId) && dh_preview_valid_id($previewId)) {
            $previewReal = realpath($rootReal . DIRECTORY_SEPARATOR . $previewId);
            if ($previewReal !== false && is_dir($previewReal) && !is_link($previewReal)
                && dirname($previewReal) === $rootReal) {
                return $previewId;
            }
        }
    }

    $items = scandir($rootReal);
    if (!is_array($items)) {
        return null;
    }
    $latestId = null;
    $latestTime = 0;
    foreach ($items as $item) {
        if (!is_string($item) || !dh_preview_valid_id($item)) {
            continue;
        }
        $previewReal = realpath($rootReal . DIRECTORY_SEPARATOR . $item);
        if ($previewReal === false || !is_dir($previewReal) || is_link($previewReal)
            || dirname($previewReal) !== $rootReal) {
            continue;
        }
        $metaPath = $previewReal . DIRECTORY_SEPARATOR . 'preview-meta.json';
        if (!is_file($metaPath) || is_link($metaPath) || filesize($metaPath) > 65536) {
            continue;
        }
        try {
            $meta = json_decode((string)file_get_contents($metaPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            continue;
        }
        if (!is_array($meta) || ($meta['preview_id'] ?? null) !== $item) {
            continue;
        }
        $createdAt = is_string($meta['created_at'] ?? null) ? strtotime($meta['created_at']) : false;
        $candidateTime = $createdAt !== false ? $createdAt : (int)filemtime($metaPath);
        if ($candidateTime >= $latestTime) {
            $latestTime = $candidateTime;
            $latestId = $item;
        }
    }
    return $latestId;
}

function dh_preview_write_latest_pointer(string $previewId, string $bundleSha256): void
{
    if (!dh_preview_valid_id($previewId) || preg_match('/^[0-9a-f]{64}$/D', $bundleSha256) !== 1) {
        throw new RuntimeException('Invalid latest preview pointer.');
    }
    $config = dh_preview_config();
    dh_preview_ensure_private_directory($config['preview_root']);
    $path = dh_preview_latest_pointer_path();
    $temporary = $config['preview_root'] . DIRECTORY_SEPARATOR . '.latest-' . bin2hex(random_bytes(16));
    $payload = json_encode([
        'preview_id' => $previewId,
        'bundle_sha256' => $bundleSha256,
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = @fopen($temporary, 'xb');
    if ($handle === false || @fwrite($handle, $payload) !== strlen($payload) || !@fflush($handle)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($temporary);
        throw new RuntimeException('Latest preview pointer could not be written.');
    }
    fclose($handle);
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Latest preview pointer could not be published.');
    }
    @chmod($path, 0600);
}

function dh_preview_send_session_cookie(): void
{
    $sessionId = session_id();
    if ($sessionId === '' || !setcookie(DH_PREVIEW_SESSION_NAME, $sessionId, [
        'expires' => 0,
        'path' => '/diagnostika-preview/',
        'secure' => !dh_preview_is_local_test(),
        'httponly' => true,
        'samesite' => 'Strict',
    ])) {
        throw new RuntimeException('Owner session cookie could not be sent.');
    }
}

function dh_preview_start_session(): void
{
    dh_preview_require_https();
    $config = dh_preview_config();
    $sessionRoot = $config['session_root'];
    dh_preview_ensure_private_directory($sessionRoot);
    $sessionReal = realpath($sessionRoot);
    if ($sessionReal === false || !is_dir($sessionReal) || is_link($sessionReal) || !is_writable($sessionReal)) {
        throw new RuntimeException('Owner session storage is unavailable.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $activePath = session_save_path();
        $activeReal = $activePath !== '' ? realpath($activePath) : false;
        if (session_name() === DH_PREVIEW_SESSION_NAME && $activeReal === $sessionReal) {
            dh_preview_send_session_cookie();
            return;
        }
        if (!session_write_close()) {
            throw new RuntimeException('An automatically started PHP session could not be released.');
        }
    }
    if (ini_set('session.save_handler', 'files') === false
        || ini_set('session.save_path', $sessionReal) === false
        || ini_set('session.gc_maxlifetime', (string)DH_PREVIEW_SESSION_ABSOLUTE) === false
        || ini_set('session.use_cookies', '1') === false) {
        throw new RuntimeException('Owner session storage could not be configured.');
    }
    session_name(DH_PREVIEW_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/diagnostika-preview/',
        'secure' => !dh_preview_is_local_test(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    if (!session_start()) {
        throw new RuntimeException('Owner session is unavailable.');
    }
    dh_preview_send_session_cookie();
}

function dh_preview_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string)$params['path'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => (string)($params['samesite'] ?? 'Strict'),
        ]);
    }
    session_destroy();
}

function dh_preview_owner_authenticated(): bool
{
    if (($_SESSION['owner_authenticated'] ?? false) !== true) {
        return false;
    }
    $now = time();
    $created = (int)($_SESSION['owner_created_at'] ?? 0);
    $lastSeen = (int)($_SESSION['owner_last_seen_at'] ?? 0);
    if ($created <= 0 || $lastSeen <= 0 || $now - $lastSeen > DH_PREVIEW_SESSION_IDLE || $now - $created > DH_PREVIEW_SESSION_ABSOLUTE) {
        dh_preview_destroy_session();
        return false;
    }
    $_SESSION['owner_last_seen_at'] = $now;
    return true;
}

function dh_preview_require_owner(): void
{
    $cookiePresent = isset($_COOKIE[DH_PREVIEW_SESSION_NAME])
        && is_string($_COOKIE[DH_PREVIEW_SESSION_NAME])
        && $_COOKIE[DH_PREVIEW_SESSION_NAME] !== '';
    dh_preview_start_session();
    if (!dh_preview_owner_authenticated()) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        dh_preview_json(401, [
            'ok' => false,
            'error' => 'Owner authentication required.',
            'code' => $cookiePresent ? 'OWNER_SESSION_STATE_MISSING' : 'OWNER_SESSION_COOKIE_MISSING',
        ]);
    }
}

function dh_preview_csrf_token(): string
{
    if (!isset($_SESSION['owner_csrf']) || !is_string($_SESSION['owner_csrf'])) {
        $_SESSION['owner_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['owner_csrf'];
}

function dh_preview_require_csrf(?string $token): void
{
    $expected = $_SESSION['owner_csrf'] ?? null;
    if (!is_string($token) || !is_string($expected) || !hash_equals($expected, $token)) {
        dh_preview_fail(403, 'Request verification failed.');
    }
}

function dh_preview_require_same_origin_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        dh_preview_fail(405, 'Method not allowed.');
    }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $expected = (dh_preview_is_https() ? 'https://' : 'http://') . $host;
        if (!hash_equals($expected, rtrim($origin, '/'))) {
            dh_preview_fail(403, 'Request origin rejected.');
        }
    }
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        dh_preview_fail(403, 'Request origin rejected.');
    }
}

function dh_preview_read_json_body(): array
{
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        dh_preview_fail(415, 'JSON request required.');
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 8192) {
        dh_preview_fail(400, 'Invalid request.');
    }
    try {
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        dh_preview_fail(400, 'Invalid request.');
    }
    if (!is_array($payload) || dh_preview_is_list($payload)) {
        dh_preview_fail(400, 'Invalid request.');
    }
    return $payload;
}

function dh_preview_valid_id(string $previewId): bool
{
    return preg_match(DH_PREVIEW_ID_PATTERN, $previewId) === 1;
}

function dh_preview_query_exact(array $allowed): array
{
    $keys = array_keys($_GET);
    sort($keys);
    $expected = $allowed;
    sort($expected);
    if ($keys !== $expected) {
        dh_preview_fail(400, 'Invalid request selector.');
    }
    foreach ($_GET as $value) {
        if (!is_string($value)) {
            dh_preview_fail(400, 'Invalid request selector.');
        }
    }
    return $_GET;
}

function dh_preview_directory(string $previewId): string
{
    if (!dh_preview_valid_id($previewId)) {
        dh_preview_fail(400, 'Invalid preview identifier.');
    }
    try {
        $config = dh_preview_config();
    } catch (RuntimeException $error) {
        dh_preview_fail(503, 'Preview storage unavailable.');
    }
    $root = $config['preview_root'];
    $path = $root . DIRECTORY_SEPARATOR . $previewId;
    if (!is_dir($path) || is_link($path)) {
        dh_preview_fail(404, 'Preview not found.');
    }
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false || dirname($pathReal) !== $rootReal) {
        dh_preview_fail(404, 'Preview not found.');
    }
    return $pathReal;
}

function dh_preview_load_json_file(string $path): array
{
    if (!is_file($path) || is_link($path) || filesize($path) > 4 * 1024 * 1024) {
        throw new RuntimeException('Invalid preview data.');
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Invalid preview data.');
    }
    if (!is_array($value) || dh_preview_is_list($value)) {
        throw new RuntimeException('Invalid preview data.');
    }
    return $value;
}

function dh_preview_rate_path(string $ownerPin): string
{
    $config = dh_preview_config();
    $rateRoot = $config['preview_root'] . DIRECTORY_SEPARATOR . 'rate-limit';
    dh_preview_ensure_private_directory($rateRoot);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $rateRoot . DIRECTORY_SEPARATOR . hash_hmac('sha256', 'owner-preview-login:' . $ip, $ownerPin) . '.json';
}

function dh_preview_rate_check_and_update(string $ownerPin, bool $success): void
{
    $path = dh_preview_rate_path($ownerPin);
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Login protection unavailable.');
    }
    $raw = stream_get_contents($handle);
    $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $now = time();
    if (!is_array($state) || $now - (int)($state['window_started_at'] ?? 0) >= 900) {
        $state = ['window_started_at' => $now, 'failures' => 0];
    }
    if (!$success && (int)$state['failures'] >= 8) {
        flock($handle, LOCK_UN);
        fclose($handle);
        header('Retry-After: 900');
        dh_preview_fail(429, 'Too many attempts. Try again later.');
    }
    if ($success) {
        $state = ['window_started_at' => $now, 'failures' => 0];
    } else {
        $state['failures'] = (int)$state['failures'] + 1;
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
}
