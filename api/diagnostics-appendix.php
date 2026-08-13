<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessException;
use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsClientSession;
use DoktorHaus\Diagnostics\DiagnosticsDeliveryException;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessException.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessService.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientSession.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsDeliveryException.php';

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Vary: Cookie');

/** @param array<string, mixed> $body */
function diagnosticsAppendixRespond(int $status, array $body): void
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        http_response_code(500);
        echo '{"ok":false,"error":"Server error."}';
        exit;
    }
    echo $json;
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        diagnosticsAppendixRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if (!is_string($queryString) || $queryString !== '' || $_GET !== []) {
        throw new DiagnosticsDeliveryException('DELIVERY_INVALID_REQUEST', 'The appendix request query is invalid.');
    }

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $clientSession = new DiagnosticsClientSession($access, $config);
    $clientSession->startHttp($_SERVER);
    $context = $clientSession->current($_SERVER);
    $package = $access->consumeVerifiedPackage($context);
    $appendix = $package['source_documentation_appendix'] ?? null;
    if (!is_array($appendix)) {
        diagnosticsAppendixRespond(404, ['ok' => false, 'error' => 'Appendix not found.']);
    }

    $fingerprint = $access->getAudit()->requestFingerprint($_SERVER);
    $access->getAudit()->append('appendix_viewed', 'success', [
        'access_id' => (string)$context['access_id'],
        'report_id' => (string)$context['report_id'],
        'report_version' => (string)$context['report_version'],
        'ip_hash' => $fingerprint['ip_hash'],
        'user_agent_hash' => $fingerprint['user_agent_hash'],
        'metadata' => ['photo_count' => count($appendix['items'] ?? [])],
    ]);
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new DiagnosticsDeliveryException('DELIVERY_SESSION_REQUIRED', 'The client session cannot be released.');
    }
    diagnosticsAppendixRespond(200, $appendix);
} catch (DiagnosticsDeliveryException $error) {
    if ($error->getDeliveryCode() === 'DELIVERY_INVALID_REQUEST') {
        diagnosticsAppendixRespond(400, ['ok' => false, 'error' => 'Invalid request.']);
    }
    diagnosticsAppendixRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsAccessException $error) {
    if (in_array($error->getAccessCode(), [
        'ACCESS_INVALID_ID',
        'ACCESS_NOT_FOUND',
        'ACCESS_INACTIVE',
        'ACCESS_EXPIRED',
        'ACCESS_SESSION_INVALID',
        'ACCESS_SESSION_EXPIRED',
        'ACCESS_PACKAGE_MISMATCH',
    ], true)) {
        diagnosticsAppendixRespond(401, ['ok' => false, 'error' => 'Authentication required.']);
    }
    if (in_array($error->getAccessCode(), ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED', 'ACCESS_AUDIT'], true)) {
        diagnosticsAppendixRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
    }
    diagnosticsAppendixRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsStorageException $error) {
    diagnosticsAppendixRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
} catch (Throwable $error) {
    diagnosticsAppendixRespond(500, ['ok' => false, 'error' => 'Server error.']);
}
