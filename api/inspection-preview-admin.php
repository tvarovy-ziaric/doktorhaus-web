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

/** @param array<mixed> $value */
function inspectionPreviewIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/** @param array<string, mixed> $body */
function inspectionPreviewRespond(int $status, array $body): void
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        http_response_code(500);
        echo '{"ok":false,"error":"N\u00e1h\u013ead sa nepodarilo pripravi\u0165."}';
        exit;
    }
    echo $json;
    exit;
}

/** @return array<string, mixed> */
function inspectionPreviewPayload(): array
{
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        inspectionPreviewRespond(415, ['ok' => false, 'error' => 'Požiadavka nemá platný formát.']);
    }
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload) || inspectionPreviewIsList($payload) ||
        array_diff(array_keys($payload), ['adminPin', 'id']) !== []) {
        inspectionPreviewRespond(400, ['ok' => false, 'error' => 'Požiadavka nemá platný formát.']);
    }
    return $payload;
}

/** @return array<int, array<string, mixed>> */
function inspectionPreviewRecords(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $records = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($records) && inspectionPreviewIsList($records) ? $records : [];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        inspectionPreviewRespond(405, ['ok' => false, 'error' => 'Nepodporovaná metóda.']);
    }

    $payload = inspectionPreviewPayload();
    $localConfigPath = __DIR__ . '/inspections.config.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    $adminPin = getenv('INSPECTIONS_ADMIN_PIN')
        ?: getenv('PUBLIC_HELP_PIN')
        ?: (is_array($localConfig) ? (string)($localConfig['admin_pin'] ?? '') : '');
    if ($adminPin === '') {
        inspectionPreviewRespond(503, ['ok' => false, 'error' => 'Administrátorský náhľad nie je dostupný.']);
    }
    $candidatePin = $payload['adminPin'] ?? null;
    if (!is_string($candidatePin) || !hash_equals($adminPin, $candidatePin)) {
        inspectionPreviewRespond(403, ['ok' => false, 'error' => 'Nesprávny Admin PIN.']);
    }

    $recordId = $payload['id'] ?? null;
    if (!is_string($recordId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $recordId) !== 1) {
        inspectionPreviewRespond(422, ['ok' => false, 'error' => 'Vyberte platnú inšpekciu.']);
    }
    $record = null;
    foreach (inspectionPreviewRecords(__DIR__ . '/../data/inspections.json') as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $recordId) {
            $record = $candidate;
            break;
        }
    }
    if (!is_array($record)) {
        inspectionPreviewRespond(404, ['ok' => false, 'error' => 'Inšpekcia sa nenašla.']);
    }
    if (!in_array(($record['status'] ?? null), ['ready', 'sent'], true)) {
        inspectionPreviewRespond(409, ['ok' => false, 'error' => 'Náhľad je dostupný pre pripravenú alebo odoslanú inšpekciu.']);
    }
    $accessId = $record['diagnosticsAccessId'] ?? null;
    if (!is_string($accessId) || preg_match('/^acc_[0-9a-f]{32}$/D', $accessId) !== 1) {
        inspectionPreviewRespond(409, ['ok' => false, 'error' => 'Diagnostická správa nie je pripravená na klientsky náhľad.']);
    }

    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $storage = DiagnosticsStorage::fromEnvironment();
    $access = new DiagnosticsAccessService($storage, $config);
    $grant = $access->getStore()->load($accessId);
    $expiresAt = $grant['expires_at'] ?? null;
    if (($grant['status'] ?? null) !== 'active' ||
        (is_string($expiresAt) && (int)strtotime($expiresAt) <= time())) {
        inspectionPreviewRespond(409, ['ok' => false, 'error' => 'Klientsky prístup k tejto inšpekcii nie je aktívny.']);
    }
    $access->assertGrantPackageBinding($grant);

    $session = new DiagnosticsClientSession($access, $config);
    $session->startHttp($_SERVER);
    $context = $session->establish($grant, $_SERVER);
    try {
        $fingerprint = $access->getAudit()->requestFingerprint($_SERVER);
        $access->getAudit()->append('admin_client_preview_started', 'success', [
            'access_id' => (string)$context['access_id'],
            'report_id' => (string)$context['report_id'],
            'report_version' => (string)$context['report_version'],
            'ip_hash' => $fingerprint['ip_hash'],
            'user_agent_hash' => $fingerprint['user_agent_hash'],
            'metadata' => ['inspection_record_id' => $recordId],
        ]);
    } catch (Throwable $error) {
        $session->destroy();
        throw $error;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The preview session cannot be released.');
    }
    inspectionPreviewRespond(200, [
        'ok' => true,
        'redirectUrl' => '/inspekcia.html?access=' . rawurlencode($accessId),
    ]);
} catch (DiagnosticsAccessException $error) {
    if (in_array($error->getAccessCode(), ['ACCESS_INACTIVE', 'ACCESS_EXPIRED', 'ACCESS_PACKAGE_MISMATCH', 'ACCESS_NOT_FOUND'], true)) {
        inspectionPreviewRespond(409, ['ok' => false, 'error' => 'Klientsky náhľad pre túto inšpekciu nie je aktívny.']);
    }
    if (in_array($error->getAccessCode(), ['ACCESS_CONFIG', 'ACCESS_HTTPS_REQUIRED', 'ACCESS_AUDIT'], true)) {
        inspectionPreviewRespond(503, ['ok' => false, 'error' => 'Administrátorský náhľad nie je dostupný.']);
    }
    inspectionPreviewRespond(500, ['ok' => false, 'error' => 'Náhľad sa nepodarilo pripraviť.']);
} catch (DiagnosticsStorageException $error) {
    inspectionPreviewRespond(503, ['ok' => false, 'error' => 'Administrátorský náhľad nie je dostupný.']);
} catch (Throwable $error) {
    inspectionPreviewRespond(500, ['ok' => false, 'error' => 'Náhľad sa nepodarilo pripraviť.']);
}
