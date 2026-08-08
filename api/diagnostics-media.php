<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessException;
use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsClientProjection;
use DoktorHaus\Diagnostics\DiagnosticsClientSession;
use DoktorHaus\Diagnostics\DiagnosticsDeliveryException;
use DoktorHaus\Diagnostics\DiagnosticsMediaDelivery;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessException.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsAccessService.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientSession.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsDeliveryException.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientProjection.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsMediaDelivery.php';

@ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Vary: Cookie');

/** @param array<string, mixed> $body */
function diagnosticsMediaRespond(int $status, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
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

$rangeSize = null;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        diagnosticsMediaRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if (!is_string($queryString) || preg_match('/^evidence=(ev_[0-9a-f]{16,32})$/D', $queryString, $queryMatch) !== 1 ||
        count($_GET) !== 1 || !isset($_GET['evidence']) || !is_string($_GET['evidence']) ||
        $_GET['evidence'] !== $queryMatch[1]) {
        throw new DiagnosticsDeliveryException('DELIVERY_INVALID_REQUEST', 'The media request query is invalid.');
    }
    $evidenceId = $queryMatch[1];

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $clientSession = new DiagnosticsClientSession($access, $config);
    $clientSession->startHttp($_SERVER);
    $context = $clientSession->current($_SERVER);
    $package = $access->consumeVerifiedPackage($context);

    $projection = new DiagnosticsClientProjection();
    $projection->build($package['manifest'], $package['inspection'], $package['diagnosis']);
    $visibleMedia = $projection->clientVisibleMedia($evidenceId);
    if ($visibleMedia === null) {
        throw new DiagnosticsDeliveryException('DELIVERY_MEDIA_NOT_FOUND', 'The requested media is unavailable.');
    }

    $file = $storage->resolveVerifiedPublishedFile(
        (string)$context['report_id'],
        (string)$context['report_version'],
        $visibleMedia['media_reference'],
        $package
    );
    if (!in_array($file['role'], ['media', 'attachment'], true) ||
        !in_array($file['privacy'], ['public', 'client_private'], true)) {
        throw new DiagnosticsDeliveryException('DELIVERY_MEDIA_NOT_FOUND', 'The requested media is unavailable.');
    }
    $size = @filesize($file['path']);
    if (!is_int($size) || $size < 0 || (isset($file['size_bytes']) && $file['size_bytes'] !== $size)) {
        throw new DiagnosticsDeliveryException('DELIVERY_INTEGRITY', 'The media size is invalid.');
    }
    $rangeSize = $size;
    $delivery = new DiagnosticsMediaDelivery();
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    if ($rangeHeader !== null && !is_string($rangeHeader)) {
        throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
    }
    $range = $delivery->parseRange($rangeHeader, $size);
    $responseType = $delivery->responseType($evidenceId, (string)$file['content_type']);

    $requestType = $method === 'HEAD' ? 'head' : ($range['partial'] ? 'range' : 'full');
    $fingerprint = $access->getAudit()->requestFingerprint($_SERVER);
    $access->getAudit()->append('media_accessed', 'success', [
        'access_id' => (string)$context['access_id'],
        'report_id' => (string)$context['report_id'],
        'report_version' => (string)$context['report_version'],
        'ip_hash' => $fingerprint['ip_hash'],
        'user_agent_hash' => $fingerprint['user_agent_hash'],
        'metadata' => [
            'evidence_id' => $evidenceId,
            'request_type' => $requestType,
        ],
    ]);
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new DiagnosticsDeliveryException('DELIVERY_SESSION_REQUIRED', 'The client session cannot be released.');
    }

    DiagnosticsMediaDelivery::discardOutputBuffers();
    http_response_code($range['status']);
    header('Content-Type: ' . $responseType['content_type']);
    header('Content-Disposition: ' . $responseType['disposition']);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $range['length']);
    if ($range['partial']) {
        header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
    }
    try {
        $delivery->stream($file['path'], $range, $method === 'HEAD');
    } catch (Throwable $error) {
        error_log('DoktorHaus diagnostics media stream failed.');
    }
    exit;
} catch (DiagnosticsDeliveryException $error) {
    $code = $error->getDeliveryCode();
    if ($code === 'DELIVERY_INVALID_REQUEST') {
        diagnosticsMediaRespond(400, ['ok' => false, 'error' => 'Invalid request.']);
    }
    if ($code === 'DELIVERY_MEDIA_NOT_FOUND') {
        diagnosticsMediaRespond(404, ['ok' => false, 'error' => 'Media not found.']);
    }
    if ($code === 'DELIVERY_RANGE') {
        if (is_int($rangeSize)) {
            header('Content-Range: bytes */' . $rangeSize);
        }
        diagnosticsMediaRespond(416, ['ok' => false, 'error' => 'Requested range not satisfiable.']);
    }
    diagnosticsMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
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
        diagnosticsMediaRespond(401, ['ok' => false, 'error' => 'Authentication required.']);
    }
    if (in_array($error->getAccessCode(), ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED', 'ACCESS_AUDIT'], true)) {
        diagnosticsMediaRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
    }
    diagnosticsMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsStorageException $error) {
    diagnosticsMediaRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
} catch (Throwable $error) {
    diagnosticsMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
}
