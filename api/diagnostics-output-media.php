<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessException;
use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsClientOutputStore;
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
require_once __DIR__ . '/lib/diagnostics/DiagnosticsMediaDelivery.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientOutputStore.php';

@ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Vary: Cookie');

/** @param array<string, mixed> $body */
function diagnosticsOutputMediaRespond(int $status, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    echo is_string($json) ? $json : '{"ok":false,"error":"Server error."}';
    exit;
}

/** @return array<int, array<string, mixed>> */
function diagnosticsOutputMediaRecords(): array
{
    $path = __DIR__ . '/../data/inspections.json';
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Inspection records cannot be read.');
    }
    try {
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $records = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($records)) {
        throw new RuntimeException('Inspection records are invalid.');
    }
    return $records;
}

function diagnosticsOutputMediaInspectionId(array $records, string $accessId): ?string
{
    $inspectionId = null;
    foreach ($records as $record) {
        if (!is_array($record) || ($record['diagnosticsAccessId'] ?? null) !== $accessId) {
            continue;
        }
        $candidate = $record['id'] ?? null;
        if (!is_string($candidate) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $candidate) !== 1 ||
            $inspectionId !== null) {
            throw new RuntimeException('The diagnostics output binding is invalid.');
        }
        $inspectionId = $candidate;
    }
    return $inspectionId;
}

$rangeSize = null;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        diagnosticsOutputMediaRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if (!is_string($query) || preg_match('/^media=(outm_[0-9a-f]{32})$/D', $query, $match) !== 1 ||
        count($_GET) !== 1 || !isset($_GET['media']) || !is_string($_GET['media']) || $_GET['media'] !== $match[1]) {
        throw new DiagnosticsDeliveryException('DELIVERY_INVALID_REQUEST', 'The output media query is invalid.');
    }
    $mediaId = $match[1];

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $session = new DiagnosticsClientSession($access, $config);
    $session->startHttp($_SERVER);
    $context = $session->current($_SERVER);
    $inspectionId = diagnosticsOutputMediaInspectionId(
        diagnosticsOutputMediaRecords(),
        (string)$context['access_id']
    );
    if ($inspectionId === null) {
        throw new DiagnosticsDeliveryException('DELIVERY_MEDIA_NOT_FOUND', 'The output media is unavailable.');
    }
    $store = new DiagnosticsClientOutputStore($storage);
    $media = $store->resolveMedia($inspectionId, $mediaId);
    if ($media === null) {
        throw new DiagnosticsDeliveryException('DELIVERY_MEDIA_NOT_FOUND', 'The output media is unavailable.');
    }

    $size = $media['size_bytes'];
    $rangeSize = $size;
    $delivery = new DiagnosticsMediaDelivery();
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    if ($rangeHeader !== null && !is_string($rangeHeader)) {
        throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
    }
    $range = $delivery->parseRange($rangeHeader, $size);
    $responseType = $delivery->responseType($mediaId, $media['content_type']);
    $requestType = $method === 'HEAD' ? 'head' : ($range['partial'] ? 'range' : 'full');

    $fingerprint = $access->getAudit()->requestFingerprint($_SERVER);
    $access->getAudit()->append('output_media_accessed', 'success', [
        'access_id' => (string)$context['access_id'],
        'report_id' => (string)$context['report_id'],
        'report_version' => (string)$context['report_version'],
        'ip_hash' => $fingerprint['ip_hash'],
        'user_agent_hash' => $fingerprint['user_agent_hash'],
        'metadata' => ['media_id' => $mediaId, 'request_type' => $requestType],
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
        $delivery->stream($media['path'], $range, $method === 'HEAD');
    } catch (Throwable $error) {
        error_log('DoktorHaus diagnostics output media stream failed.');
    }
    exit;
} catch (DiagnosticsDeliveryException $error) {
    $code = $error->getDeliveryCode();
    if ($code === 'DELIVERY_INVALID_REQUEST') {
        diagnosticsOutputMediaRespond(400, ['ok' => false, 'error' => 'Invalid request.']);
    }
    if ($code === 'DELIVERY_MEDIA_NOT_FOUND') {
        diagnosticsOutputMediaRespond(404, ['ok' => false, 'error' => 'Media not found.']);
    }
    if ($code === 'DELIVERY_RANGE') {
        if (is_int($rangeSize)) {
            header('Content-Range: bytes */' . $rangeSize);
        }
        diagnosticsOutputMediaRespond(416, ['ok' => false, 'error' => 'Requested range not satisfiable.']);
    }
    diagnosticsOutputMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsAccessException $error) {
    if (in_array($error->getAccessCode(), [
        'ACCESS_INVALID_ID', 'ACCESS_NOT_FOUND', 'ACCESS_INACTIVE', 'ACCESS_EXPIRED',
        'ACCESS_SESSION_INVALID', 'ACCESS_SESSION_EXPIRED', 'ACCESS_PACKAGE_MISMATCH',
    ], true)) {
        diagnosticsOutputMediaRespond(401, ['ok' => false, 'error' => 'Authentication required.']);
    }
    if (in_array($error->getAccessCode(), ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED', 'ACCESS_AUDIT'], true)) {
        diagnosticsOutputMediaRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
    }
    diagnosticsOutputMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsStorageException $error) {
    if ($error->getStorageCode() === 'STORAGE_OUTPUT_NOT_FOUND') {
        diagnosticsOutputMediaRespond(404, ['ok' => false, 'error' => 'Media not found.']);
    }
    diagnosticsOutputMediaRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
} catch (Throwable $error) {
    diagnosticsOutputMediaRespond(500, ['ok' => false, 'error' => 'Server error.']);
}
