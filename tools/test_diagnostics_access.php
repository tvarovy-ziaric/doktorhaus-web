<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessException;
use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsAccessStore;
use DoktorHaus\Diagnostics\DiagnosticsClientSession;
use DoktorHaus\Diagnostics\DiagnosticsRateLimiter;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsAccessService.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsClientSession.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$assertions = 0;
$packageSequence = 0;

function accessAssert(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}

function expectAccessCode(string $code, callable $callback, string $message): void
{
    global $assertions;
    try {
        $callback();
    } catch (DiagnosticsAccessException $error) {
        if ($error->getAccessCode() !== $code) {
            throw new RuntimeException($message . ' Expected ' . $code . ', got ' . $error->getAccessCode() . '.');
        }
        $assertions++;
        return;
    }
    throw new RuntimeException($message . ' Expected ' . $code . ', but no exception was thrown.');
}

/** @param array<string, mixed> $data */
function accessWriteJson(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create a test directory.');
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write test JSON.');
    }
}

/** @return array<string, mixed> */
function accessReadJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('Cannot read test JSON.');
    }
    return $decoded;
}

function accessRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0600);
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0700);
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                accessRemoveTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
}

function accessFixture(string $name): array
{
    return accessReadJson(__DIR__ . '/../docs/diagnostics/fixtures/valid/' . $name);
}

function accessReportVersionId(string $reportId, string $version): string
{
    return 'rptv_' . substr(hash('sha256', $reportId . '|' . $version), 0, 16);
}

function accessCreatePackage(string $testRoot, string $reportId, string $version): string
{
    global $packageSequence;
    $packageSequence++;
    $package = $testRoot . DIRECTORY_SEPARATOR . 'source-' . $packageSequence;
    if (!mkdir($package, 0700, true)) {
        throw new RuntimeException('Cannot create a source package.');
    }
    $inspection = accessFixture('inspection-minimal.json');
    $diagnosis = accessFixture('diagnosis-minimal.json');
    accessWriteJson($package . DIRECTORY_SEPARATOR . 'inspection.json', $inspection);
    accessWriteJson($package . DIRECTORY_SEPARATOR . 'diagnosis.json', $diagnosis);
    $versionId = accessReportVersionId($reportId, $version);
    $manifest = [
        'schema_version' => '1.0.0',
        'document_type' => 'report_package',
        'report' => [
            'id' => $reportId,
            'inspection_id' => $inspection['id'],
            'status' => 'active',
            'current_published_version_id' => $versionId,
        ],
        'report_version' => [
            'id' => $versionId,
            'report_id' => $reportId,
            'version' => $version,
            'change_type' => 'initial',
            'change_summary' => 'Synthetic access security test package.',
            'status' => 'published',
            'generated_at' => '2026-08-08T08:00:00+02:00',
            'approved_by' => 'inspector_test',
            'approved_at' => '2026-08-08T08:10:00+02:00',
            'published_at' => '2026-08-08T08:20:00+02:00',
            'renderer_contract_version' => '1.0.0',
            'limitations_snapshot' => [],
            'unverified_items_snapshot' => [],
        ],
        'actors' => [
            ['id' => 'inspector_test', 'display_name' => 'Test inspector', 'role' => 'inspector'],
        ],
        'files' => [],
        'created_at' => '2026-08-08T08:20:00+02:00',
    ];
    foreach ([['inspection_data', 'inspection.json'], ['diagnosis_data', 'diagnosis.json']] as $file) {
        $path = $package . DIRECTORY_SEPARATOR . $file[1];
        $manifest['files'][] = [
            'role' => $file[0],
            'path' => $file[1],
            'sha256' => hash_file('sha256', $path),
            'content_type' => 'application/json',
            'size_bytes' => filesize($path),
            'privacy' => 'client_private',
        ];
    }
    accessWriteJson($package . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    return $package;
}

/** @return array<string, mixed> */
function accessConfigValues(array $overrides = []): array
{
    return array_merge([
        'pin_pepper' => 'test-pin-pepper-0123456789-abcdef-XYZ',
        'audit_hmac_key' => 'test-audit-key-0123456789-abcdef-XYZ',
        'session_idle_seconds' => 60,
        'session_absolute_seconds' => 300,
        'rate_window_seconds' => 120,
        'rate_access_ip_max' => 3,
        'rate_ip_max' => 20,
        'rate_lockout_seconds' => 120,
    ], $overrides);
}

/** @return array{storage: DiagnosticsStorage, config: DiagnosticsSecurityConfig, service: DiagnosticsAccessService} */
function accessRuntime(string $root, ?callable $clock = null, array $overrides = []): array
{
    $storage = new DiagnosticsStorage($root, dirname($root) . DIRECTORY_SEPARATOR . 'webroot');
    $config = new DiagnosticsSecurityConfig(accessConfigValues($overrides));
    $service = new DiagnosticsAccessService($storage, $config, null, null, null, $clock);
    return ['storage' => $storage, 'config' => $config, 'service' => $service];
}

function accessPrepareHttpFixture(string $root): void
{
    $storage = new DiagnosticsStorage($root, dirname($root) . DIRECTORY_SEPARATOR . 'webroot');
    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $service = new DiagnosticsAccessService($storage, $config);
    $reportId = 'rpt_' . substr(hash('sha256', $root), 0, 16);
    $storage->installPublishedPackage(accessCreatePackage(dirname($root), $reportId, '1.0'));
    $grant = $service->createGrant($reportId, '1.0');
    echo json_encode($grant, JSON_UNESCAPED_SLASHES) . "\n";
}

function accessHttpMutation(string $operation, string $accessId): void
{
    $storage = DiagnosticsStorage::fromEnvironment();
    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $service = new DiagnosticsAccessService($storage, $config);
    $result = $operation === '--rotate-http'
        ? $service->rotatePin($accessId)
        : $service->revokeGrant($accessId);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
}

if (($argv[1] ?? '') === '--prepare-http' && isset($argv[2])) {
    accessPrepareHttpFixture($argv[2]);
    exit(0);
}
if (in_array(($argv[1] ?? ''), ['--rotate-http', '--revoke-http'], true) && isset($argv[2])) {
    accessHttpMutation($argv[1], $argv[2]);
    exit(0);
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'doktorhaus-access-' . bin2hex(random_bytes(8));
$failure = null;
try {
    expectAccessCode('ACCESS_CONFIG', function (): void {
        new DiagnosticsSecurityConfig([]);
    }, 'Missing secrets must fail closed.');
    expectAccessCode('ACCESS_CONFIG', function (): void {
        new DiagnosticsSecurityConfig(accessConfigValues(['pin_pepper' => 'short']));
    }, 'Short PIN pepper must fail closed.');
    expectAccessCode('ACCESS_CONFIG', function (): void {
        $values = accessConfigValues();
        $values['audit_hmac_key'] = $values['pin_pepper'];
        new DiagnosticsSecurityConfig($values);
    }, 'PIN and audit secrets must be distinct.');

    $now = 1786147200;
    $clock = function () use (&$now): int {
        return $now;
    };
    $runtime = accessRuntime($testRoot . DIRECTORY_SEPARATOR . 'storage', $clock);
    $storage = $runtime['storage'];
    $config = $runtime['config'];
    $service = $runtime['service'];
    $reportId = 'rpt_aaaaaaaaaaaaaaaa';
    $storage->installPublishedPackage(accessCreatePackage($testRoot, $reportId, '1.0'));
    expectAccessCode('ACCESS_PACKAGE_MISMATCH', function () use ($service): void {
        $service->createGrant('rpt_bbbbbbbbbbbbbbbb', '1.0');
    }, 'A grant for a missing report must fail.');
    expectAccessCode('ACCESS_PACKAGE_MISMATCH', function () use ($service, $reportId): void {
        $service->createGrant($reportId, '9.9');
    }, 'A grant for a missing version must fail.');
    expectAccessCode('ACCESS_INVALID_ID', function () use ($service): void {
        $service->getGrantStatus('../unsafe');
    }, 'Unsafe access identifiers must fail.');

    $issued = $service->createGrant($reportId, '1.0', gmdate('Y-m-d\TH:i:s\Z', $now + 600));
    accessAssert(preg_match('/^acc_[0-9a-f]{32}$/D', $issued['access_id']) === 1, 'Access ID must be opaque.');
    accessAssert(preg_match('/^[0-9]{6}$/D', $issued['pin']) === 1, 'PIN must be six digits.');
    $grantPath = $storage->getRoot() . '/access/grants/' . $issued['access_id'] . '.json';
    $grantRaw = file_get_contents($grantPath);
    accessAssert(is_string($grantRaw) && strpos($grantRaw, '"pin"') === false, 'Plaintext PIN field must not persist.');
    accessAssert(is_string($grantRaw) && strpos($grantRaw, $issued['pin']) === false, 'Plaintext PIN value must not persist.');
    $stored = accessReadJson($grantPath);
    accessAssert($stored['pin_hash'] !== $issued['pin'], 'Stored PIN must be a password hash.');
    accessAssert($stored['report_version_id'] === accessReportVersionId($reportId, '1.0'), 'Grant must bind version ID.');
    accessAssert(hash_equals($stored['package_manifest_sha256'], $storage->getPublishedManifestSha256($reportId, '1.0')), 'Grant must bind manifest hash.');

    $request = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'AccessTest/1.0'];
    $verified = $service->verifyPin($issued['access_id'], $issued['pin'], $request);
    accessAssert($verified['access_id'] === $issued['access_id'], 'Correct PIN must authenticate.');
    expectAccessCode('ACCESS_PIN_INVALID', function () use ($service, $issued): void {
        $service->verifyPin($issued['access_id'], '000000', ['REMOTE_ADDR' => '203.0.113.11']);
    }, 'Wrong PIN must fail.');
    expectAccessCode('ACCESS_NOT_FOUND', function () use ($service): void {
        $service->verifyPin('acc_ffffffffffffffffffffffffffffffff', '000000', ['REMOTE_ADDR' => '203.0.113.12']);
    }, 'Unknown opaque access ID must execute the dummy verification path.');

    $wrongPepper = new DiagnosticsSecurityConfig(accessConfigValues([
        'pin_pepper' => 'different-pin-pepper-0123456789-XYZ',
    ]));
    $wrongPepperService = new DiagnosticsAccessService($storage, $wrongPepper, null, null, null, $clock);
    expectAccessCode('ACCESS_PIN_INVALID', function () use ($wrongPepperService, $issued): void {
        $wrongPepperService->verifyPin($issued['access_id'], $issued['pin'], ['REMOTE_ADDR' => '203.0.113.13']);
    }, 'A different pepper must not verify a persisted PIN hash.');

    $clientSession = new DiagnosticsClientSession($service, $config, null, $clock);
    $context = $clientSession->buildContext($verified, $now);
    $validated = $clientSession->validateContext($context, $now + 10);
    accessAssert($validated['last_seen_at'] === $now + 10, 'Valid session must refresh last_seen_at.');
    expectAccessCode('ACCESS_SESSION_EXPIRED', function () use ($clientSession, $context, $now): void {
        $clientSession->validateContext($context, $now + 60);
    }, 'Idle timeout must expire a session.');
    $absoluteContext = $context;
    $absoluteContext['last_seen_at'] = $now + 299;
    expectAccessCode('ACCESS_SESSION_EXPIRED', function () use ($clientSession, $absoluteContext, $now): void {
        $clientSession->validateContext($absoluteContext, $now + 300);
    }, 'Absolute timeout must expire a session.');
    $unexpectedContext = $context;
    $unexpectedContext['unexpected'] = 'value';
    expectAccessCode('ACCESS_SESSION_INVALID', function () use ($clientSession, $unexpectedContext, $now): void {
        $clientSession->validateContext($unexpectedContext, $now + 1);
    }, 'Unexpected server-side session fields must fail closed.');
    $clientSession->assertCsrf($context, $context['csrf_token']);
    expectAccessCode('ACCESS_CSRF', function () use ($clientSession, $context): void {
        $clientSession->assertCsrf($context, str_repeat('0', 64));
    }, 'Wrong CSRF token must fail.');
    $productionCookie = DiagnosticsClientSession::cookieParameters(true);
    accessAssert($productionCookie['secure'] === true, 'Production cookie must be Secure.');
    accessAssert($productionCookie['httponly'] === true && $productionCookie['samesite'] === 'Strict', 'Cookie must be HttpOnly and SameSite Strict.');
    accessAssert($productionCookie['path'] === '/' && $productionCookie['lifetime'] === 0, 'Cookie path and lifetime policy must be explicit.');
    accessAssert(!DiagnosticsClientSession::isSecureRequest(['HTTP_X_FORWARDED_PROTO' => 'https']), 'Forwarded headers must not establish HTTPS.');
    accessAssert(DiagnosticsClientSession::isSecureRequest(['HTTPS' => 'on']), 'Direct HTTPS indicator must be accepted.');
    putenv('DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST=1');
    accessAssert(!DiagnosticsClientSession::isLocalInsecureOverride(['REMOTE_ADDR' => '127.0.0.1']), 'Insecure override must not work in production or CLI SAPIs.');
    putenv('DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST');

    $rotated = $service->rotatePin($issued['access_id']);
    accessAssert($rotated['generation'] === 2 && $rotated['pin'] !== $issued['pin'], 'Rotation must replace PIN and increment generation.');
    expectAccessCode('ACCESS_SESSION_INVALID', function () use ($clientSession, $context, $now): void {
        $clientSession->validateContext($context, $now + 11);
    }, 'Rotation must invalidate an existing session.');
    expectAccessCode('ACCESS_PIN_INVALID', function () use ($service, $issued): void {
        $service->verifyPin($issued['access_id'], $issued['pin'], ['REMOTE_ADDR' => '203.0.113.14']);
    }, 'Old PIN must fail after rotation.');
    $rotatedGrant = $service->verifyPin($issued['access_id'], $rotated['pin'], ['REMOTE_ADDR' => '203.0.113.14']);
    $rotatedContext = $clientSession->buildContext($rotatedGrant, $now);
    $revoked = $service->revokeGrant($issued['access_id']);
    accessAssert($revoked['status'] === 'revoked' && $revoked['generation'] === 3, 'Revocation must increment generation.');
    accessAssert($service->revokeGrant($issued['access_id'])['generation'] === 3, 'Repeated revocation must be idempotent.');
    expectAccessCode('ACCESS_SESSION_INVALID', function () use ($clientSession, $rotatedContext, $now): void {
        $clientSession->validateContext($rotatedContext, $now + 1);
    }, 'Revocation must invalidate an existing session.');
    expectAccessCode('ACCESS_INACTIVE', function () use ($service, $rotated, $issued): void {
        $service->verifyPin($issued['access_id'], $rotated['pin'], ['REMOTE_ADDR' => '203.0.113.15']);
    }, 'Revoked access must fail authentication.');

    $expiryGrant = $service->createGrant($reportId, '1.0', gmdate('Y-m-d\TH:i:s\Z', $now + 5));
    $expiryContext = $clientSession->buildContext($service->getStore()->load($expiryGrant['access_id']), $now);
    $now += 5;
    expectAccessCode('ACCESS_EXPIRED', function () use ($service, $expiryGrant): void {
        $service->verifyPin($expiryGrant['access_id'], $expiryGrant['pin'], ['REMOTE_ADDR' => '203.0.113.16']);
    }, 'Grant expiration must fail at the exact expiry instant.');
    expectAccessCode('ACCESS_SESSION_EXPIRED', function () use ($clientSession, $expiryContext, $now): void {
        $clientSession->validateContext($expiryContext, $now);
    }, 'Grant expiration must invalidate an existing session.');
    $now -= 5;

    $resetGrant = $service->createGrant($reportId, '1.0');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expectAccessCode('ACCESS_PIN_INVALID', function () use ($service, $resetGrant): void {
            $service->verifyPin($resetGrant['access_id'], '000000', ['REMOTE_ADDR' => '203.0.113.19']);
        }, 'Pre-success failures must be recorded.');
    }
    $service->verifyPin($resetGrant['access_id'], $resetGrant['pin'], ['REMOTE_ADDR' => '203.0.113.19']);
    expectAccessCode('ACCESS_PIN_INVALID', function () use ($service, $resetGrant): void {
        $service->verifyPin($resetGrant['access_id'], '000000', ['REMOTE_ADDR' => '203.0.113.19']);
    }, 'Successful auth must reset the access+IP failure bucket.');

    $rateGrant = $service->createGrant($reportId, '1.0');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        expectAccessCode('ACCESS_PIN_INVALID', function () use ($service, $rateGrant): void {
            $service->verifyPin($rateGrant['access_id'], '000000', ['REMOTE_ADDR' => '203.0.113.20']);
        }, 'Failure below the threshold must remain an authentication failure.');
    }
    $freshLimiter = new DiagnosticsRateLimiter(new DiagnosticsAccessStore($storage), $config);
    $fingerprint = $service->getAudit()->requestFingerprint(['REMOTE_ADDR' => '203.0.113.20']);
    expectAccessCode('ACCESS_RATE_LIMITED', function () use ($freshLimiter, $rateGrant, $fingerprint, $now): void {
        $freshLimiter->assertAllowed($rateGrant['access_id'], $fingerprint['ip_hash'], $now);
    }, 'Rate-limit state must persist across instances.');
    expectAccessCode('ACCESS_RATE_LIMITED', function () use ($service, $rateGrant): void {
        $service->verifyPin($rateGrant['access_id'], '000000', ['REMOTE_ADDR' => '203.0.113.20']);
    }, 'Blocked auth must fail before password verification and emit the rate-limit path.');

    $globalConfig = new DiagnosticsSecurityConfig(accessConfigValues([
        'rate_access_ip_max' => 3,
        'rate_ip_max' => 3,
    ]));
    $globalService = new DiagnosticsAccessService($storage, $globalConfig, null, null, null, $clock);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $unknownId = 'acc_' . str_pad(dechex($attempt + 1), 32, '0', STR_PAD_LEFT);
        expectAccessCode('ACCESS_NOT_FOUND', function () use ($globalService, $unknownId): void {
            $globalService->verifyPin($unknownId, '000000', ['REMOTE_ADDR' => '203.0.113.21']);
        }, 'Different access IDs must still count toward the global IP limit.');
    }
    expectAccessCode('ACCESS_RATE_LIMITED', function () use ($globalService): void {
        $globalService->verifyPin('acc_00000000000000000000000000000004', '000000', ['REMOTE_ADDR' => '203.0.113.21']);
    }, 'Global IP rate limit must work across access IDs.');

    $rateBucketKey = hash_hmac(
        'sha256',
        'doktorhaus-diagnostics-rate-access-ip-v1:' . $rateGrant['access_id'] . ':' . $fingerprint['ip_hash'],
        $config->getAuditHmacKey()
    );
    $rateStatePath = $storage->getRoot() . '/access/rate-limit/access-ip/' . $rateBucketKey . '.json';
    $rateStateRaw = file_get_contents($rateStatePath);
    file_put_contents($rateStatePath, "{broken\n");
    expectAccessCode('ACCESS_JSON', function () use ($freshLimiter, $rateGrant, $fingerprint, $now): void {
        $freshLimiter->assertAllowed($rateGrant['access_id'], $fingerprint['ip_hash'], $now);
    }, 'Corrupt rate-limit state must fail closed.');
    file_put_contents($rateStatePath, $rateStateRaw);
    $rateStateSafe = $rateStatePath . '.safe';
    if (@rename($rateStatePath, $rateStateSafe) && @symlink($rateStateSafe, $rateStatePath)) {
        expectAccessCode('ACCESS_SYMLINK', function () use ($freshLimiter, $rateGrant, $fingerprint, $now): void {
            $freshLimiter->assertAllowed($rateGrant['access_id'], $fingerprint['ip_hash'], $now);
        }, 'Symlinked rate-limit state must fail closed.');
        @unlink($rateStatePath);
        @rename($rateStateSafe, $rateStatePath);
        echo "symlink rate state test: PASS\n";
    } elseif (is_file($rateStateSafe) && !is_file($rateStatePath)) {
        @rename($rateStateSafe, $rateStatePath);
    }

    $rollbackGrant = $service->createGrant($reportId, '1.0');
    $rollbackBefore = $service->getStore()->load($rollbackGrant['access_id']);
    $unauditedRevoke = $rollbackBefore;
    $unauditedRevoke['status'] = 'revoked';
    $unauditedRevoke['generation']++;
    $unauditedRevoke['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
    $unauditedRevoke['revoked_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
    expectAccessCode('ACCESS_AUDIT', function () use ($service, $rollbackGrant, $rollbackBefore, $unauditedRevoke): void {
        $service->getStore()->update($rollbackGrant['access_id'], $rollbackBefore['generation'], $unauditedRevoke);
    }, 'Generation-changing store mutations must require audit.');

    $raceGrant = $service->createGrant($reportId, '1.0');
    $raceBefore = $service->getStore()->load($raceGrant['access_id']);
    $service->rotatePin($raceGrant['access_id']);
    $staleRotation = $raceBefore;
    $staleRotation['generation']++;
    $staleRotation['pin_hash'] = password_hash(
        hash_hmac('sha256', 'doktorhaus-diagnostics-pin-v1:123456', $config->getPinPepper()),
        PASSWORD_DEFAULT
    );
    $staleRotation['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
    $staleRotation['last_pin_rotated_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
    expectAccessCode('ACCESS_INTEGRITY', function () use ($service, $raceGrant, $raceBefore, $staleRotation): void {
        $service->getStore()->update(
            $raceGrant['access_id'],
            $raceBefore['generation'],
            $staleRotation,
            function (array $_grant): void {
            }
        );
    }, 'A stale concurrent generation update must fail.');

    $now += 86400;
    $blockedAuditPath = $storage->getRoot() . '/audit/' . gmdate('Y-m-d', $now) . '.jsonl';
    if (!mkdir($blockedAuditPath, 0700)) {
        throw new RuntimeException('Cannot prepare audit failure test.');
    }
    expectAccessCode('ACCESS_AUDIT', function () use ($service, $rollbackGrant): void {
        $service->rotatePin($rollbackGrant['access_id']);
    }, 'Critical mutation must fail when its audit cannot be written.');
    $rollbackAfter = $service->getStore()->load($rollbackGrant['access_id']);
    accessAssert($rollbackAfter['generation'] === $rollbackBefore['generation'] && $rollbackAfter['pin_hash'] === $rollbackBefore['pin_hash'], 'Failed audit must roll back the grant mutation.');
    expectAccessCode('ACCESS_AUDIT', function () use ($service, $rollbackGrant): void {
        $service->revokeGrant($rollbackGrant['access_id']);
    }, 'Revocation must fail when its audit cannot be written.');
    $grantCountBefore = count(glob($storage->getRoot() . '/access/grants/*.json') ?: []);
    expectAccessCode('ACCESS_AUDIT', function () use ($service, $reportId): void {
        $service->createGrant($reportId, '1.0');
    }, 'Grant creation must fail when its audit cannot be written.');
    $grantCountAfter = count(glob($storage->getRoot() . '/access/grants/*.json') ?: []);
    accessAssert($grantCountAfter === $grantCountBefore, 'Unaudited grant creation must be rolled back.');
    accessRemoveTree($blockedAuditPath);

    $corruptGrant = $service->createGrant($reportId, '1.0');
    $corruptPath = $storage->getRoot() . '/access/grants/' . $corruptGrant['access_id'] . '.json';
    file_put_contents($corruptPath, "{broken\n");
    expectAccessCode('ACCESS_JSON', function () use ($service, $corruptGrant): void {
        $service->getStore()->load($corruptGrant['access_id']);
    }, 'Corrupt grant JSON must fail closed.');

    if (DIRECTORY_SEPARATOR === '/') {
        accessAssert((fileperms($grantPath) & 0777) <= 0640, 'Grant files must not be broadly readable.');
    }

    $symlinkSource = $storage->getRoot() . '/access/grants/' . $rollbackGrant['access_id'] . '.json';
    $symlinkSafe = $symlinkSource . '.safe';
    $fileSymlinkPassed = false;
    if (@rename($symlinkSource, $symlinkSafe) && @symlink($symlinkSafe, $symlinkSource)) {
        expectAccessCode('ACCESS_SYMLINK', function () use ($service, $rollbackGrant): void {
            $service->getStore()->load($rollbackGrant['access_id']);
        }, 'Symlinked grant file must fail closed.');
        @unlink($symlinkSource);
        @rename($symlinkSafe, $symlinkSource);
        $fileSymlinkPassed = true;
        echo "symlink access file test: PASS\n";
    } else {
        if (is_file($symlinkSafe) && !is_file($symlinkSource)) {
            @rename($symlinkSafe, $symlinkSource);
        }
        echo "symlink access file test: SKIP\n";
    }

    $grantDirectory = $storage->getRoot() . '/access/grants';
    $safeDirectory = $storage->getRoot() . '/access/grants-safe';
    if (@rename($grantDirectory, $safeDirectory) && @symlink($safeDirectory, $grantDirectory)) {
        expectAccessCode('ACCESS_SYMLINK', function () use ($service, $rollbackGrant): void {
            $service->getStore()->load($rollbackGrant['access_id']);
        }, 'Symlinked grant directory must fail closed.');
        @unlink($grantDirectory);
        @rename($safeDirectory, $grantDirectory);
        echo "symlink access directory test: PASS\n";
    } else {
        if (is_dir($safeDirectory) && !is_dir($grantDirectory)) {
            @rename($safeDirectory, $grantDirectory);
        }
        if (getenv('DIAGNOSTICS_REQUIRE_SYMLINK_TESTS') === '1') {
            throw new RuntimeException('Required access symlink test could not run.');
        }
        echo "symlink access directory test: SKIP\n";
    }
    if (getenv('DIAGNOSTICS_REQUIRE_SYMLINK_TESTS') === '1' && !$fileSymlinkPassed) {
        throw new RuntimeException('Required access file symlink test could not run.');
    }

    $auditFiles = glob($storage->getRoot() . '/audit/*.jsonl');
    $auditText = '';
    foreach (is_array($auditFiles) ? $auditFiles : [] as $auditFile) {
        $contents = file_get_contents($auditFile);
        $auditText .= is_string($contents) ? $contents : '';
    }
    accessAssert(strpos($auditText, 'access_grant_created') !== false && strpos($auditText, 'auth_success') !== false, 'Required security events must be audited.');
    foreach (['access_pin_rotated', 'access_revoked', 'auth_failure', 'auth_rate_limited'] as $requiredEvent) {
        accessAssert(strpos($auditText, $requiredEvent) !== false, 'Audit must contain ' . $requiredEvent . '.');
    }
    accessAssert(strpos($auditText, '203.0.113.') === false && strpos($auditText, 'AccessTest/1.0') === false, 'Audit must not contain raw IP or user agent.');
    accessAssert(strpos($auditText, 'pin_hash') === false && strpos($auditText, 'DH_DIAGSESSID') === false, 'Audit must not contain PIN hashes or session identifiers.');
    $rateFiles = glob($storage->getRoot() . '/access/rate-limit/*/*.json');
    foreach (is_array($rateFiles) ? $rateFiles : [] as $rateFile) {
        $rateText = file_get_contents($rateFile);
        accessAssert(is_string($rateText) && strpos($rateText, '203.0.113.') === false, 'Rate-limit files must not contain raw IP addresses.');
    }

    $publishedManifest = $storage->getRoot() . '/reports/' . $reportId . '/1.0/manifest.json';
    @chmod($publishedManifest, 0600);
    file_put_contents($publishedManifest, file_get_contents($publishedManifest) . " \n");
    expectAccessCode('ACCESS_PACKAGE_MISMATCH', function () use ($service, $rollbackGrant): void {
        $service->assertGrantPackageBinding($service->getStore()->load($rollbackGrant['access_id']));
    }, 'Manifest mutation must break the exact package binding.');

    echo 'Diagnostics access security tests passed: ' . $assertions . " assertions.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    accessRemoveTree($testRoot);
}

restore_error_handler();
if ($failure !== null) {
    fwrite(STDERR, 'Diagnostics access security tests failed: ' . $failure->getMessage() . "\n");
    exit(1);
}
