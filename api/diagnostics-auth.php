<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessException;
use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsClientSession;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessException.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessService.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientSession.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');

/** @param array<string, mixed> $body */
function diagnosticsAuthRespond(int $status, array $body): void
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        http_response_code(500);
        echo '{"ok":false,"authenticated":false,"error":"Server error."}';
        exit;
    }
    echo $json;
    exit;
}

/** @return array<string, mixed> */
function diagnosticsAuthReadJson(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!is_string($contentType) || preg_match('/^application\/json(?:\s*;.*)?$/iD', trim($contentType)) !== 1) {
        throw new DiagnosticsAccessException('ACCESS_JSON', 'A JSON request body is required.');
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '' || strlen($raw) > 16384 || substr(ltrim($raw), 0, 1) !== '{') {
        throw new DiagnosticsAccessException('ACCESS_JSON', 'The JSON request body is invalid.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new DiagnosticsAccessException('ACCESS_JSON', 'The JSON request body is invalid.');
    }
    foreach (array_keys($decoded) as $key) {
        if (!is_string($key)) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The JSON request body is invalid.');
        }
    }
    return $decoded;
}

/** @param array<string, mixed> $context */
function diagnosticsAuthSuccess(array $context): array
{
    return [
        'ok' => true,
        'authenticated' => true,
        'accessId' => (string)$context['access_id'],
        'version' => (string)$context['report_version'],
        'csrfToken' => (string)$context['csrf_token'],
    ];
}

$unauthorized = [
    'ok' => false,
    'authenticated' => false,
    'error' => 'Authentication required.',
];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        diagnosticsAuthRespond(405, [
            'ok' => false,
            'authenticated' => false,
            'error' => 'Method not allowed.',
        ]);
    }

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $clientSession = new DiagnosticsClientSession($access, $config);
    $clientSession->startHttp($_SERVER);

    if ($method === 'GET') {
        diagnosticsAuthRespond(200, diagnosticsAuthSuccess($clientSession->current($_SERVER)));
    }

    $request = diagnosticsAuthReadJson();
    $action = $request['action'] ?? null;
    if (!is_string($action)) {
        throw new DiagnosticsAccessException('ACCESS_JSON', 'The JSON request body is invalid.');
    }

    if ($action === 'unlock') {
        $accessId = $request['accessId'] ?? null;
        $pin = $request['pin'] ?? null;
        if (!is_string($accessId) || !is_string($pin)) {
            throw new DiagnosticsAccessException('ACCESS_PIN_INVALID', 'The access credentials are invalid.');
        }
        $grant = $access->verifyPin($accessId, $pin, $_SERVER);
        diagnosticsAuthRespond(200, diagnosticsAuthSuccess($clientSession->establish($grant, $_SERVER)));
    }

    if ($action === 'logout') {
        $csrfToken = $request['csrfToken'] ?? null;
        if (!is_string($csrfToken)) {
            throw new DiagnosticsAccessException('ACCESS_CSRF', 'The CSRF token is invalid.');
        }
        $clientSession->logout($csrfToken, $_SERVER);
        diagnosticsAuthRespond(200, ['ok' => true, 'authenticated' => false]);
    }

    throw new DiagnosticsAccessException('ACCESS_JSON', 'The requested action is invalid.');
} catch (DiagnosticsAccessException $error) {
    $code = $error->getAccessCode();
    if ($code === 'ACCESS_RATE_LIMITED') {
        $retryAfter = max(1, (int)$error->getRetryAfter());
        header('Retry-After: ' . $retryAfter);
        diagnosticsAuthRespond(429, [
            'ok' => false,
            'authenticated' => false,
            'error' => 'Too many attempts. Try again later.',
        ]);
    }
    if ($code === 'ACCESS_CSRF') {
        diagnosticsAuthRespond(403, [
            'ok' => false,
            'authenticated' => true,
            'error' => 'Request verification failed.',
        ]);
    }
    if ($code === 'ACCESS_JSON') {
        diagnosticsAuthRespond(400, [
            'ok' => false,
            'authenticated' => false,
            'error' => 'Invalid request.',
        ]);
    }
    if (in_array($code, [
        'ACCESS_INVALID_ID',
        'ACCESS_NOT_FOUND',
        'ACCESS_INACTIVE',
        'ACCESS_EXPIRED',
        'ACCESS_PIN_INVALID',
        'ACCESS_SESSION_INVALID',
        'ACCESS_SESSION_EXPIRED',
        'ACCESS_PACKAGE_MISMATCH',
    ], true)) {
        diagnosticsAuthRespond(401, $unauthorized);
    }
    if (in_array($code, ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED'], true)) {
        diagnosticsAuthRespond(503, [
            'ok' => false,
            'authenticated' => false,
            'error' => 'Service unavailable.',
        ]);
    }
    diagnosticsAuthRespond(500, [
        'ok' => false,
        'authenticated' => false,
        'error' => 'Server error.',
    ]);
} catch (DiagnosticsStorageException $error) {
    diagnosticsAuthRespond(503, [
        'ok' => false,
        'authenticated' => false,
        'error' => 'Service unavailable.',
    ]);
} catch (Throwable $error) {
    diagnosticsAuthRespond(500, [
        'ok' => false,
        'authenticated' => false,
        'error' => 'Server error.',
    ]);
}
