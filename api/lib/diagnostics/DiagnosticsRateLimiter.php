<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsAccessException.php';
require_once __DIR__ . '/DiagnosticsAccessStore.php';
require_once __DIR__ . '/DiagnosticsSecurityConfig.php';

final class DiagnosticsRateLimiter
{
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /** @var string */
    private $root;

    /** @var string */
    private $accessIpRoot;

    /** @var string */
    private $ipRoot;

    /** @var string */
    private $hmacKey;

    /** @var int */
    private $windowSeconds;

    /** @var int */
    private $accessIpMax;

    /** @var int */
    private $ipMax;

    /** @var int */
    private $lockoutSeconds;

    public function __construct(DiagnosticsAccessStore $store, DiagnosticsSecurityConfig $config)
    {
        $this->root = $store->getRoot();
        $this->hmacKey = $config->getAuditHmacKey();
        $this->windowSeconds = $config->getRateWindowSeconds();
        $this->accessIpMax = $config->getRateAccessIpMax();
        $this->ipMax = $config->getRateIpMax();
        $this->lockoutSeconds = $config->getRateLockoutSeconds();

        $accessRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'access', 0700);
        $rateRoot = $this->ensureDirectory($accessRoot . DIRECTORY_SEPARATOR . 'rate-limit', 0700);
        $this->accessIpRoot = $this->ensureDirectory($rateRoot . DIRECTORY_SEPARATOR . 'access-ip', 0700);
        $this->ipRoot = $this->ensureDirectory($rateRoot . DIRECTORY_SEPARATOR . 'ip', 0700);
    }

    public function assertAllowed(string $accessId, string $ipHash, ?int $now = null): void
    {
        DiagnosticsAccessStore::assertAccessId($accessId);
        $this->assertIpHash($ipHash);
        $now = $now === null ? time() : $now;

        $this->withAttemptLock($ipHash, function () use ($accessId, $ipHash, $now): void {
            $this->assertAllowedUnlocked($accessId, $ipHash, $now);
        });
    }

    /**
     * Serialize rate check, password verification and failure/success accounting per IP pseudonym.
     */
    public function executeAttempt(
        string $accessId,
        string $ipHash,
        callable $verification,
        ?int $now = null
    ): bool
    {
        DiagnosticsAccessStore::assertAccessId($accessId);
        $this->assertIpHash($ipHash);
        $now = $now === null ? time() : $now;

        return $this->withAttemptLock($ipHash, function () use ($accessId, $ipHash, $verification, $now): bool {
            $this->assertAllowedUnlocked($accessId, $ipHash, $now);
            $authenticated = $verification();
            if (!is_bool($authenticated)) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The authentication result is invalid.');
            }
            if ($authenticated) {
                $this->deleteBucketUnlocked('access-ip', $this->accessBucketKey($accessId, $ipHash));
                return true;
            }
            $this->incrementBucketUnlocked('ip', $this->globalBucketKey($ipHash), $this->ipMax, $now);
            $this->incrementBucketUnlocked(
                'access-ip',
                $this->accessBucketKey($accessId, $ipHash),
                $this->accessIpMax,
                $now
            );
            return false;
        });
    }

    private function assertAllowedUnlocked(string $accessId, string $ipHash, int $now): void
    {
        $globalRetry = $this->retryAfterUnlocked('ip', $this->globalBucketKey($ipHash), $now);
        $accessRetry = $this->retryAfterUnlocked(
            'access-ip',
            $this->accessBucketKey($accessId, $ipHash),
            $now
        );
        $retryAfter = max($globalRetry, $accessRetry);
        if ($retryAfter > 0) {
            throw new DiagnosticsAccessException(
                'ACCESS_RATE_LIMITED',
                'Authentication attempts are temporarily limited.',
                null,
                $retryAfter
            );
        }
    }

    private function retryAfterUnlocked(string $scope, string $bucketKey, int $now): int
    {
        $state = $this->loadState($scope, $bucketKey);
        return $state !== null && $state['blocked_until'] > $now
            ? max(1, $state['blocked_until'] - $now)
            : 0;
    }

    private function incrementBucketUnlocked(string $scope, string $bucketKey, int $maximum, int $now): void
    {
        $state = $this->loadState($scope, $bucketKey);
        if ($state === null || $now - $state['window_started_at'] >= $this->windowSeconds) {
            $state = [
                'window_started_at' => $now,
                'failures' => 0,
                'blocked_until' => 0,
                'updated_at' => $now,
            ];
        }
        $state['failures']++;
        $state['updated_at'] = $now;
        if ($state['failures'] >= $maximum) {
            $state['blocked_until'] = max($state['blocked_until'], $now + $this->lockoutSeconds);
        }
        $this->writeState($scope, $bucketKey, $state);
    }

    private function deleteBucketUnlocked(string $scope, string $bucketKey): void
    {
        $path = $this->statePath($scope, $bucketKey);
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit state path is unsafe.');
        }
        if (is_file($path) && !@unlink($path)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state cannot be reset.');
        }
    }

    /** @return array{window_started_at: int, failures: int, blocked_until: int, updated_at: int}|null */
    private function loadState(string $scope, string $bucketKey): ?array
    {
        $path = $this->statePath($scope, $bucketKey);
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit state path is unsafe.');
        }
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path)) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The rate-limit state is invalid.');
        }
        $raw = file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || json_last_error() !== JSON_ERROR_NONE) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The rate-limit state is invalid.');
        }
        foreach (['window_started_at', 'failures', 'blocked_until', 'updated_at'] as $key) {
            if (!isset($state[$key]) || !is_int($state[$key]) || $state[$key] < 0) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The rate-limit state is invalid.');
            }
        }
        return [
            'window_started_at' => $state['window_started_at'],
            'failures' => $state['failures'],
            'blocked_until' => $state['blocked_until'],
            'updated_at' => $state['updated_at'],
        ];
    }

    /** @param array{window_started_at: int, failures: int, blocked_until: int, updated_at: int} $state */
    private function writeState(string $scope, string $bucketKey, array $state): void
    {
        $path = $this->statePath($scope, $bucketKey);
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit state path is unsafe.');
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The rate-limit state cannot be serialized.');
        }
        $directory = dirname($path);
        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.write-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temporaryPath, 'x+b');
        if ($handle === false) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'A temporary rate-limit file cannot be created.');
        }
        try {
            $this->writeAll($handle, $json . "\n");
            if (!fflush($handle)) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state cannot be flushed.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state cannot be synchronized.');
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temporaryPath);
            if ($error instanceof DiagnosticsAccessException) {
                throw $error;
            }
            throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state write failed.', $error);
        }
        fclose($handle);
        @chmod($temporaryPath, 0640);
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state cannot be committed atomically.');
        }
        @chmod($path, 0640);
    }

    /** @return mixed */
    private function withAttemptLock(string $ipHash, callable $callback)
    {
        $this->assertIpHash($ipHash);
        $locksRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'locks', 0700);
        $lockPath = $locksRoot . DIRECTORY_SEPARATOR . 'rate-attempt-' . $ipHash . '.lock';
        if (is_link($lockPath)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit lock path is unsafe.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new DiagnosticsAccessException('ACCESS_LOCK', 'The rate-limit lock cannot be opened.');
        }
        if (is_link($lockPath)) {
            fclose($handle);
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit lock path is unsafe.');
        }
        @chmod($lockPath, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new DiagnosticsAccessException('ACCESS_LOCK', 'The rate-limit lock cannot be acquired.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function statePath(string $scope, string $bucketKey): string
    {
        $this->assertScopeAndKey($scope, $bucketKey);
        $directory = $scope === 'access-ip' ? $this->accessIpRoot : $this->ipRoot;
        if (is_link($directory) || !is_dir($directory)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The rate-limit directory is unsafe.');
        }
        return $directory . DIRECTORY_SEPARATOR . $bucketKey . '.json';
    }

    private function accessBucketKey(string $accessId, string $ipHash): string
    {
        return hash_hmac(
            'sha256',
            'doktorhaus-diagnostics-rate-access-ip-v1:' . $accessId . ':' . $ipHash,
            $this->hmacKey
        );
    }

    private function globalBucketKey(string $ipHash): string
    {
        return hash_hmac('sha256', 'doktorhaus-diagnostics-rate-ip-v1:' . $ipHash, $this->hmacKey);
    }

    private function assertScopeAndKey(string $scope, string $bucketKey): void
    {
        if (!in_array($scope, ['access-ip', 'ip'], true) || preg_match(self::HASH_PATTERN, $bucketKey) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The rate-limit bucket is invalid.');
        }
    }

    private function assertIpHash(string $ipHash): void
    {
        if (preg_match(self::HASH_PATTERN, $ipHash) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The request fingerprint is invalid.');
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The rate-limit state cannot be written.');
            }
            $offset += $written;
        }
    }

    private function ensureDirectory(string $path, int $mode): string
    {
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'A rate-limit directory is unsafe.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'A rate-limit directory cannot be created.');
        }
        if (!is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'A rate-limit directory is invalid.');
        }
        @chmod($path, $mode);
        return $path;
    }
}
