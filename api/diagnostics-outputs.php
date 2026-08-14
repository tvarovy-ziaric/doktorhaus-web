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
function diagnosticsOutputsRespond(int $status, array $body): void
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        http_response_code(500);
        echo '{"ok":false,"error":"Server error."}';
        exit;
    }
    echo $json;
    exit;
}

/** @return array<int, array<string, mixed>> */
function diagnosticsOutputsLoadRecords(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Inspection output records cannot be read.');
    }
    try {
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    if (!is_string($raw)) {
        throw new RuntimeException('Inspection output records cannot be read.');
    }
    $records = json_decode($raw, true);
    if (!is_array($records) || ($records !== [] && array_keys($records) !== range(0, count($records) - 1))) {
        throw new RuntimeException('Inspection output records are invalid.');
    }
    foreach ($records as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Inspection output records are invalid.');
        }
    }
    return $records;
}

function diagnosticsOutputsHostAllowed(string $host, array $allowedHosts): bool
{
    $host = strtolower(rtrim($host, '.'));
    foreach ($allowedHosts as $allowedHost) {
        $suffix = '.' . $allowedHost;
        if ($host === $allowedHost || substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

function diagnosticsOutputsHttpsUrl($value, array $allowedHosts): ?string
{
    if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        !diagnosticsOutputsHostAllowed((string)$parts['host'], $allowedHosts)) {
        return null;
    }
    return $value;
}

function diagnosticsOutputsPdfUrl($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (preg_match('~^/?api/diagnostics-media\.php\?evidence=ev_[0-9a-f]{16,32}$~D', $value) !== 1) {
        return null;
    }
    return $value;
}

/** @param array<int, array<string, mixed>> $records
 *  @return array<int, array{type: string, url: string}>
 */
function diagnosticsOutputsProject(array $records, string $accessId): array
{
    $match = null;
    foreach ($records as $record) {
        if (($record['diagnosticsAccessId'] ?? null) !== $accessId) {
            continue;
        }
        if ($match !== null) {
            throw new RuntimeException('The diagnostics output binding is ambiguous.');
        }
        $match = $record;
    }
    if ($match === null) {
        return [];
    }
    $media = is_array($match['media'] ?? null) ? $match['media'] : [];
    $candidates = [
        ['google_docs', diagnosticsOutputsHttpsUrl($media['docsUrl'] ?? null, ['docs.google.com', 'drive.google.com'])],
        ['pdf', diagnosticsOutputsPdfUrl($media['reportUrl'] ?? null)],
        ['panoraven', diagnosticsOutputsHttpsUrl($media['panoravenUrl'] ?? null, ['panoraven.com'])],
        ['video_hd', diagnosticsOutputsHttpsUrl($media['videoHdUrl'] ?? null, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'])],
        ['video_360', diagnosticsOutputsHttpsUrl($media['video360Url'] ?? null, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'])],
    ];
    $outputs = [];
    foreach ($candidates as [$type, $url]) {
        if (is_string($url)) {
            $outputs[] = ['type' => $type, 'url' => $url];
        }
    }
    return $outputs;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        diagnosticsOutputsRespond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if (!is_string($queryString) || $queryString !== '' || $_GET !== []) {
        throw new DiagnosticsDeliveryException('DELIVERY_INVALID_REQUEST', 'The output request query is invalid.');
    }

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $clientSession = new DiagnosticsClientSession($access, $config);
    $clientSession->startHttp($_SERVER);
    $context = $clientSession->current($_SERVER);
    $outputs = diagnosticsOutputsProject(
        diagnosticsOutputsLoadRecords(__DIR__ . '/../data/inspections.json'),
        (string)$context['access_id']
    );

    $fingerprint = $access->getAudit()->requestFingerprint($_SERVER);
    $access->getAudit()->append('outputs_viewed', 'success', [
        'access_id' => (string)$context['access_id'],
        'report_id' => (string)$context['report_id'],
        'report_version' => (string)$context['report_version'],
        'ip_hash' => $fingerprint['ip_hash'],
        'user_agent_hash' => $fingerprint['user_agent_hash'],
        'metadata' => ['output_count' => count($outputs)],
    ]);
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new DiagnosticsDeliveryException('DELIVERY_SESSION_REQUIRED', 'The client session cannot be released.');
    }
    diagnosticsOutputsRespond(200, [
        'schema_version' => '1.0.0-helper',
        'document_type' => 'diagnostics_outputs',
        'outputs' => $outputs,
    ]);
} catch (DiagnosticsDeliveryException $error) {
    if ($error->getDeliveryCode() === 'DELIVERY_INVALID_REQUEST') {
        diagnosticsOutputsRespond(400, ['ok' => false, 'error' => 'Invalid request.']);
    }
    diagnosticsOutputsRespond(500, ['ok' => false, 'error' => 'Server error.']);
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
        diagnosticsOutputsRespond(401, ['ok' => false, 'error' => 'Authentication required.']);
    }
    if (in_array($error->getAccessCode(), ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED', 'ACCESS_AUDIT'], true)) {
        diagnosticsOutputsRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
    }
    diagnosticsOutputsRespond(500, ['ok' => false, 'error' => 'Server error.']);
} catch (DiagnosticsStorageException $error) {
    diagnosticsOutputsRespond(503, ['ok' => false, 'error' => 'Service unavailable.']);
} catch (Throwable $error) {
    diagnosticsOutputsRespond(500, ['ok' => false, 'error' => 'Server error.']);
}
