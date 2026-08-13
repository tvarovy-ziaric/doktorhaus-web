<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsAccessException.php';
require_once __DIR__ . '/DiagnosticsAccessStore.php';
require_once __DIR__ . '/DiagnosticsAuditLog.php';
require_once __DIR__ . '/DiagnosticsRateLimiter.php';
require_once __DIR__ . '/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/DiagnosticsStorage.php';

final class DiagnosticsAccessService
{
    private const DUMMY_PIN_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    /** @var DiagnosticsStorage */
    private $storage;

    /** @var DiagnosticsAccessStore */
    private $store;

    /** @var DiagnosticsRateLimiter */
    private $rateLimiter;

    /** @var DiagnosticsAuditLog */
    private $audit;

    /** @var DiagnosticsSecurityConfig */
    private $config;

    /** @var callable */
    private $clock;

    /** @var array<string, mixed>|null */
    private $lastVerifiedPackage;

    public function __construct(
        DiagnosticsStorage $storage,
        DiagnosticsSecurityConfig $config,
        ?DiagnosticsAccessStore $store = null,
        ?DiagnosticsRateLimiter $rateLimiter = null,
        ?DiagnosticsAuditLog $audit = null,
        ?callable $clock = null
    ) {
        $this->storage = $storage;
        $this->config = $config;
        $this->store = $store ?? new DiagnosticsAccessStore($storage);
        $this->audit = $audit ?? new DiagnosticsAuditLog($storage, $config->getAuditHmacKey());
        $this->rateLimiter = $rateLimiter ?? new DiagnosticsRateLimiter($this->store, $config);
        $this->clock = $clock ?? function (): int {
            return time();
        };
    }

    /**
     * Create an access grant. The returned plaintext PIN is the only plaintext copy.
     *
     * @return array{access_id: string, pin: string, report_version: string, expires_at: string|null, generation: int}
     */
    public function createGrant(string $reportId, string $version, ?string $expiresAt = null): array
    {
        $pin = (string)random_int(100000, 999999);
        $grant = $this->createGrantUsingPin($reportId, $version, $pin, $expiresAt);
        $grant['pin'] = $pin;
        return $grant;
    }

    /**
     * Create a grant bound to an already-issued six-digit client PIN.
     * The plaintext PIN is deliberately not returned or persisted.
     *
     * @return array{access_id: string, report_version: string, expires_at: string|null, generation: int}
     */
    public function createGrantWithPin(string $reportId, string $version, string $pin, ?string $expiresAt = null): array
    {
        if (preg_match('/^[0-9]{6}$/D', $pin) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_PIN_INVALID', 'The access credentials are invalid.');
        }
        return $this->createGrantUsingPin($reportId, $version, $pin, $expiresAt);
    }

    /**
     * @return array{access_id: string, report_version: string, expires_at: string|null, generation: int}
     */
    private function createGrantUsingPin(string $reportId, string $version, string $pin, ?string $expiresAt): array
    {
        $now = $this->now();
        try {
            $binding = $this->storage->loadPublishedManifestBinding($reportId, $version);
            $manifest = $binding['manifest'];
            $manifestHash = $binding['sha256'];
        } catch (DiagnosticsStorageException $error) {
            throw new DiagnosticsAccessException(
                'ACCESS_PACKAGE_MISMATCH',
                'The published report package cannot be bound to access.',
                $error
            );
        }
        $reportVersionId = $manifest['report_version']['id'] ?? null;
        if (!is_string($reportVersionId)) {
            throw new DiagnosticsAccessException('ACCESS_PACKAGE_MISMATCH', 'The published report version is invalid.');
        }
        $canonicalExpiry = $this->canonicalExpiry($expiresAt, $now);
        $accessId = 'acc_' . bin2hex(random_bytes(16));
        $timestamp = $this->timestamp($now);
        $grant = [
            'access_id' => $accessId,
            'report_id' => $reportId,
            'report_version' => $version,
            'report_version_id' => $reportVersionId,
            'package_manifest_sha256' => $manifestHash,
            'status' => 'active',
            'pin_hash' => $this->hashPin($pin),
            'generation' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'expires_at' => $canonicalExpiry,
            'revoked_at' => null,
            'last_pin_rotated_at' => null,
        ];

        $this->store->create($grant, function (array $writtenGrant) use ($now): void {
            $this->audit->append('access_grant_created', 'success', $this->grantAuditFields($writtenGrant), $now);
        });

        return [
            'access_id' => $accessId,
            'report_version' => $version,
            'expires_at' => $canonicalExpiry,
            'generation' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $requestContext
     * @return array<string, mixed>
     */
    public function verifyPin(string $accessId, string $pin, array $requestContext): array
    {
        DiagnosticsAccessStore::assertAccessId($accessId);
        if (preg_match('/^[0-9]{6}$/D', $pin) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_PIN_INVALID', 'The access credentials are invalid.');
        }
        $now = $this->now();
        $fingerprint = $this->audit->requestFingerprint($requestContext);
        $grant = null;
        $failureCode = null;
        try {
            $authenticated = $this->rateLimiter->executeAttempt(
                $accessId,
                $fingerprint['ip_hash'],
                function () use ($accessId, $pin, &$grant, &$failureCode): bool {
                    try {
                        $grant = $this->store->load($accessId);
                    } catch (DiagnosticsAccessException $error) {
                        if ($error->getAccessCode() !== 'ACCESS_NOT_FOUND') {
                            throw $error;
                        }
                    }

                    $candidate = $this->prehashPin($pin);
                    $hash = is_array($grant) ? (string)$grant['pin_hash'] : self::DUMMY_PIN_HASH;
                    $pinMatches = password_verify($candidate, $hash);
                    if ($grant === null) {
                        $failureCode = 'ACCESS_NOT_FOUND';
                    } elseif ($grant['status'] !== 'active') {
                        $failureCode = 'ACCESS_INACTIVE';
                    } elseif ($this->isExpired($grant, $this->now())) {
                        $failureCode = 'ACCESS_EXPIRED';
                    } elseif (!$pinMatches) {
                        $failureCode = 'ACCESS_PIN_INVALID';
                    } else {
                        try {
                            $this->assertGrantPackageBinding($grant);
                        } catch (DiagnosticsAccessException $error) {
                            $failureCode = 'ACCESS_PACKAGE_MISMATCH';
                        }
                    }
                    return $failureCode === null;
                },
                $now
            );
        } catch (DiagnosticsAccessException $error) {
            if ($error->getAccessCode() !== 'ACCESS_RATE_LIMITED') {
                throw $error;
            }
            $this->audit->append('auth_rate_limited', 'blocked', [
                'access_id' => $accessId,
                'ip_hash' => $fingerprint['ip_hash'],
                'user_agent_hash' => $fingerprint['user_agent_hash'],
                'reason_code' => 'rate_limit',
                'metadata' => ['retry_after' => (int)$error->getRetryAfter()],
            ], $now);
            throw $error;
        }
        if (!$authenticated || $failureCode !== null || !is_array($grant)) {
            $failureCode = is_string($failureCode) ? $failureCode : 'ACCESS_PIN_INVALID';
            $this->audit->append('auth_failure', 'failure', [
                'access_id' => $accessId,
                'ip_hash' => $fingerprint['ip_hash'],
                'user_agent_hash' => $fingerprint['user_agent_hash'],
                'reason_code' => strtolower(substr($failureCode, 7)),
            ], $now);
            throw new DiagnosticsAccessException($failureCode, 'The access credentials are invalid.');
        }

        if (password_needs_rehash((string)$grant['pin_hash'], PASSWORD_DEFAULT)) {
            $rehash = $grant;
            $rehash['pin_hash'] = $this->hashPin($pin);
            $rehash['updated_at'] = $this->timestamp($now);
            $grant = $this->store->update($accessId, (int)$grant['generation'], $rehash);
        }
        $this->audit->append('auth_success', 'success', array_merge(
            $this->grantAuditFields($grant),
            [
                'ip_hash' => $fingerprint['ip_hash'],
                'user_agent_hash' => $fingerprint['user_agent_hash'],
            ]
        ), $now);
        return $grant;
    }

    /** @return array{access_id: string, pin: string, report_version: string, expires_at: string|null, generation: int} */
    public function rotatePin(string $accessId): array
    {
        $now = $this->now();
        $grant = $this->store->load($accessId);
        $this->assertGrantActive($grant, $now);
        $this->assertGrantPackageBinding($grant);
        do {
            $pin = (string)random_int(100000, 999999);
        } while (password_verify($this->prehashPin($pin), (string)$grant['pin_hash']));
        $rotated = $grant;
        $rotated['pin_hash'] = $this->hashPin($pin);
        $rotated['generation'] = (int)$grant['generation'] + 1;
        $rotated['updated_at'] = $this->timestamp($now);
        $rotated['last_pin_rotated_at'] = $this->timestamp($now);
        $this->store->update($accessId, (int)$grant['generation'], $rotated, function (array $writtenGrant) use ($now): void {
            $this->audit->append('access_pin_rotated', 'success', $this->grantAuditFields($writtenGrant), $now);
        });
        return [
            'access_id' => $accessId,
            'pin' => $pin,
            'report_version' => (string)$rotated['report_version'],
            'expires_at' => $rotated['expires_at'],
            'generation' => (int)$rotated['generation'],
        ];
    }

    /** @return array<string, mixed> */
    public function revokeGrant(string $accessId): array
    {
        $now = $this->now();
        $grant = $this->store->load($accessId);
        if ($grant['status'] === 'revoked') {
            return $this->getGrantStatus($accessId);
        }
        $revoked = $grant;
        $revoked['status'] = 'revoked';
        $revoked['generation'] = (int)$grant['generation'] + 1;
        $revoked['updated_at'] = $this->timestamp($now);
        $revoked['revoked_at'] = $this->timestamp($now);
        $this->store->update($accessId, (int)$grant['generation'], $revoked, function (array $writtenGrant) use ($now): void {
            $this->audit->append('access_revoked', 'success', $this->grantAuditFields($writtenGrant), $now);
        });
        return $this->statusView($revoked, $now);
    }

    /** @return array<string, mixed> */
    public function getGrantStatus(string $accessId): array
    {
        $now = $this->now();
        $grant = $this->store->load($accessId);
        return $this->statusView($grant, $now);
    }

    /** @param array<string, mixed> $grant */
    public function assertGrantPackageBinding(array $grant): void
    {
        $this->lastVerifiedPackage = null;
        try {
            $binding = $this->storage->loadPublishedManifestBinding(
                (string)$grant['report_id'],
                (string)$grant['report_version']
            );
            $manifest = $binding['manifest'];
            $manifestHash = $binding['sha256'];
        } catch (DiagnosticsStorageException $error) {
            throw new DiagnosticsAccessException('ACCESS_PACKAGE_MISMATCH', 'The access package binding is invalid.', $error);
        }
        if (($manifest['report_version']['id'] ?? null) !== $grant['report_version_id'] ||
            !hash_equals((string)$grant['package_manifest_sha256'], $manifestHash)) {
            throw new DiagnosticsAccessException('ACCESS_PACKAGE_MISMATCH', 'The access package binding is invalid.');
        }
        $this->lastVerifiedPackage = $binding['package'];
    }

    /**
     * Consume the immutable package snapshot verified by the immediately preceding grant/session check.
     *
     * @param array<string, mixed> $context
     * @return array{manifest: array<string, mixed>, files: array<string, array<string, mixed>>, inspection: array<string, mixed>, diagnosis: array<string, mixed>, report_pricing: array<string, mixed>|null}
     */
    public function consumeVerifiedPackage(array $context): array
    {
        $package = $this->lastVerifiedPackage;
        $this->lastVerifiedPackage = null;
        if (!is_array($package) ||
            ($package['manifest']['report']['id'] ?? null) !== ($context['report_id'] ?? null) ||
            ($package['manifest']['report_version']['version'] ?? null) !== ($context['report_version'] ?? null) ||
            ($package['manifest']['report_version']['id'] ?? null) !== ($context['report_version_id'] ?? null)) {
            throw new DiagnosticsAccessException('ACCESS_PACKAGE_MISMATCH', 'The verified package snapshot is unavailable.');
        }
        return $package;
    }

    public function getStore(): DiagnosticsAccessStore
    {
        return $this->store;
    }

    public function getAudit(): DiagnosticsAuditLog
    {
        return $this->audit;
    }

    /** @param array<string, mixed> $grant */
    private function assertGrantActive(array $grant, int $now): void
    {
        if ($grant['status'] !== 'active') {
            throw new DiagnosticsAccessException('ACCESS_INACTIVE', 'The access grant is inactive.');
        }
        if ($this->isExpired($grant, $now)) {
            throw new DiagnosticsAccessException('ACCESS_EXPIRED', 'The access grant has expired.');
        }
    }

    /** @param array<string, mixed> $grant */
    private function isExpired(array $grant, int $now): bool
    {
        return is_string($grant['expires_at']) && (int)strtotime($grant['expires_at']) <= $now;
    }

    private function prehashPin(string $pin): string
    {
        return hash_hmac(
            'sha256',
            'doktorhaus-diagnostics-pin-v1:' . $pin,
            $this->config->getPinPepper()
        );
    }

    private function hashPin(string $pin): string
    {
        $hash = password_hash($this->prehashPin($pin), PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The PIN password algorithm is unavailable.');
        }
        return $hash;
    }

    private function canonicalExpiry(?string $expiresAt, int $now): ?string
    {
        if ($expiresAt === null) {
            return null;
        }
        if (preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $expiresAt) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access expiry is invalid.');
        }
        $timestamp = strtotime($expiresAt);
        if ($timestamp === false || $timestamp <= $now) {
            throw new DiagnosticsAccessException('ACCESS_EXPIRED', 'The access expiry must be in the future.');
        }
        return $this->timestamp($timestamp);
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now < 1) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security clock is invalid.');
        }
        return $now;
    }

    private function timestamp(int $unixTime): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $unixTime);
    }

    /**
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    private function grantAuditFields(array $grant): array
    {
        return [
            'access_id' => (string)$grant['access_id'],
            'report_id' => (string)$grant['report_id'],
            'report_version' => (string)$grant['report_version'],
            'metadata' => ['generation' => (int)$grant['generation']],
        ];
    }

    /**
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    private function statusView(array $grant, int $now): array
    {
        return [
            'access_id' => (string)$grant['access_id'],
            'report_id' => (string)$grant['report_id'],
            'report_version' => (string)$grant['report_version'],
            'report_version_id' => (string)$grant['report_version_id'],
            'status' => (string)$grant['status'],
            'generation' => (int)$grant['generation'],
            'expires_at' => $grant['expires_at'],
            'expired' => $this->isExpired($grant, $now),
            'revoked_at' => $grant['revoked_at'],
            'last_pin_rotated_at' => $grant['last_pin_rotated_at'],
        ];
    }
}
