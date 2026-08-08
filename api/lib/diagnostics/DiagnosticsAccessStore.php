<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsAccessException.php';
require_once __DIR__ . '/DiagnosticsStorage.php';

final class DiagnosticsAccessStore
{
    private const ACCESS_ID_PATTERN = '/^acc_[0-9a-f]{32}$/D';
    private const REPORT_VERSION_ID_PATTERN = '/^rptv_[0-9a-f]{16,32}$/D';
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /** @var string */
    private $root;

    /** @var string */
    private $grantsRoot;

    public function __construct(DiagnosticsStorage $storage)
    {
        $this->root = $storage->getRoot();
        $accessRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'access', 0700);
        $this->grantsRoot = $this->ensureDirectory($accessRoot . DIRECTORY_SEPARATOR . 'grants', 0700);
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public static function assertAccessId(string $accessId): void
    {
        if (preg_match(self::ACCESS_ID_PATTERN, $accessId) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_INVALID_ID', 'The access identifier is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    public function create(array $grant, callable $beforeCommit): array
    {
        $this->validateGrant($grant);
        $accessId = (string)$grant['access_id'];

        return $this->withGrantLock($accessId, function () use ($accessId, $grant, $beforeCommit): array {
            $path = $this->grantPath($accessId);
            if (is_link($path)) {
                throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The access grant path is unsafe.');
            }
            if (file_exists($path)) {
                throw new DiagnosticsAccessException('ACCESS_ALREADY_EXISTS', 'The access grant already exists.');
            }
            $temporaryPath = $this->prepareGrantJson($grant);
            try {
                $beforeCommit($grant);
                $this->commitPreparedJson($temporaryPath, $path, false);
            } catch (Throwable $error) {
                @unlink($temporaryPath);
                throw $error;
            }
            return $grant;
        });
    }

    /** @return array<string, mixed> */
    public function load(string $accessId): array
    {
        self::assertAccessId($accessId);
        return $this->withGrantLock($accessId, function () use ($accessId): array {
            return $this->loadUnlocked($accessId);
        });
    }

    /**
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    public function update(
        string $accessId,
        int $expectedGeneration,
        array $grant,
        ?callable $beforeCommit = null
    ): array
    {
        self::assertAccessId($accessId);
        if ($expectedGeneration < 1 || ($grant['access_id'] ?? null) !== $accessId) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant update is invalid.');
        }
        $this->validateGrant($grant);

        return $this->withGrantLock($accessId, function () use (
            $accessId,
            $expectedGeneration,
            $grant,
            $beforeCommit
        ): array {
            $current = $this->loadUnlocked($accessId);
            if ($current['generation'] !== $expectedGeneration) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant was changed by another writer.');
            }
            $newGeneration = (int)$grant['generation'];
            if ($newGeneration < $expectedGeneration || $newGeneration > $expectedGeneration + 1) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant generation transition is invalid.');
            }
            if ($newGeneration === $expectedGeneration) {
                $allowedRehash = $current;
                $allowedRehash['pin_hash'] = $grant['pin_hash'];
                $allowedRehash['updated_at'] = $grant['updated_at'];
                if ($allowedRehash !== $grant) {
                    throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'A same-generation access update is invalid.');
                }
            } else {
                if ($beforeCommit === null) {
                    throw new DiagnosticsAccessException('ACCESS_AUDIT', 'A security audit callback is required.');
                }
                $this->validateGenerationChange($current, $grant);
            }
            $temporaryPath = $this->prepareGrantJson($grant);
            try {
                if ($beforeCommit !== null) {
                    $beforeCommit($grant);
                }
                $this->commitPreparedJson($temporaryPath, $this->grantPath($accessId), true);
            } catch (Throwable $error) {
                @unlink($temporaryPath);
                throw $error;
            }
            return $grant;
        });
    }

    /** @return array<string, mixed> */
    private function loadUnlocked(string $accessId): array
    {
        $path = $this->grantPath($accessId);
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The access grant path is unsafe.');
        }
        if (!is_file($path)) {
            throw new DiagnosticsAccessException('ACCESS_NOT_FOUND', 'The access grant was not found.');
        }
        $grant = $this->readJsonObject($path);
        $this->validateGrant($grant);
        if ($grant['access_id'] !== $accessId) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant identity does not match its path.');
        }
        return $grant;
    }

    /** @param array<string, mixed> $grant */
    private function validateGrant(array $grant): void
    {
        $required = [
            'access_id', 'report_id', 'report_version', 'report_version_id', 'package_manifest_sha256',
            'status', 'pin_hash', 'generation', 'created_at', 'updated_at', 'expires_at', 'revoked_at',
            'last_pin_rotated_at',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $grant)) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant structure is incomplete.');
            }
        }
        if ($this->isList($grant) || !is_string($grant['access_id']) || !is_string($grant['report_id']) ||
            !is_string($grant['report_version']) || !is_string($grant['report_version_id']) ||
            !is_string($grant['package_manifest_sha256']) || !is_string($grant['status']) ||
            !is_string($grant['pin_hash']) || !is_int($grant['generation'])) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant structure is invalid.');
        }
        self::assertAccessId($grant['access_id']);
        try {
            DiagnosticsPackageVerifier::assertReportId($grant['report_id']);
            DiagnosticsPackageVerifier::assertVersion($grant['report_version']);
        } catch (DiagnosticsStorageException $error) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant report binding is invalid.', $error);
        }
        $passwordInfo = password_get_info($grant['pin_hash']);
        if (preg_match(self::REPORT_VERSION_ID_PATTERN, $grant['report_version_id']) !== 1 ||
            preg_match(self::HASH_PATTERN, $grant['package_manifest_sha256']) !== 1 ||
            !in_array($grant['status'], ['active', 'revoked'], true) || strlen($grant['pin_hash']) < 20 ||
            ($passwordInfo['algoName'] ?? 'unknown') === 'unknown' || $grant['generation'] < 1) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant values are invalid.');
        }
        foreach (['created_at', 'updated_at'] as $key) {
            if (!is_string($grant[$key]) || !$this->isTimestamp($grant[$key])) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant timestamp is invalid.');
            }
        }
        foreach (['expires_at', 'revoked_at', 'last_pin_rotated_at'] as $key) {
            if ($grant[$key] !== null && (!is_string($grant[$key]) || !$this->isTimestamp($grant[$key]))) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant timestamp is invalid.');
            }
        }
        if (($grant['status'] === 'active' && $grant['revoked_at'] !== null) ||
            ($grant['status'] === 'revoked' && $grant['revoked_at'] === null)) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The access grant status is inconsistent.');
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     */
    private function validateGenerationChange(array $current, array $next): void
    {
        foreach ([
            'access_id', 'report_id', 'report_version', 'report_version_id', 'package_manifest_sha256',
            'created_at', 'expires_at',
        ] as $key) {
            if ($current[$key] !== $next[$key]) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The immutable access binding cannot change.');
            }
        }
        if ($current['status'] !== 'active') {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'An inactive access grant cannot be changed.');
        }
        if ($next['status'] === 'active') {
            if ($current['pin_hash'] === $next['pin_hash'] || $next['last_pin_rotated_at'] === null ||
                $next['revoked_at'] !== null) {
                throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The PIN rotation transition is invalid.');
            }
            return;
        }
        if ($next['status'] !== 'revoked' || $current['pin_hash'] !== $next['pin_hash'] ||
            $current['last_pin_rotated_at'] !== $next['last_pin_rotated_at'] || $next['revoked_at'] === null) {
            throw new DiagnosticsAccessException('ACCESS_INTEGRITY', 'The revocation transition is invalid.');
        }
    }

    private function grantPath(string $accessId): string
    {
        self::assertAccessId($accessId);
        $this->assertSafeDirectory($this->root . DIRECTORY_SEPARATOR . 'access');
        $this->assertSafeDirectory($this->grantsRoot);
        return $this->grantsRoot . DIRECTORY_SEPARATOR . $accessId . '.json';
    }

    /** @return mixed */
    private function withGrantLock(string $accessId, callable $callback)
    {
        self::assertAccessId($accessId);
        $locksRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'locks', 0700);
        $lockPath = $locksRoot . DIRECTORY_SEPARATOR . 'access-' . $accessId . '.lock';
        if (is_link($lockPath)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The access lock path is unsafe.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new DiagnosticsAccessException('ACCESS_LOCK', 'The access lock cannot be opened.');
        }
        if (is_link($lockPath)) {
            fclose($handle);
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The access lock path is unsafe.');
        }
        @chmod($lockPath, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new DiagnosticsAccessException('ACCESS_LOCK', 'The access lock cannot be acquired.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $data */
    private function prepareGrantJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The access grant cannot be serialized.');
        }
        $json .= "\n";
        $temporaryPath = $this->grantsRoot . DIRECTORY_SEPARATOR . '.write-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temporaryPath, 'x+b');
        if ($handle === false) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'A temporary access file cannot be created.');
        }
        try {
            $this->writeAll($handle, $json);
            if (!fflush($handle)) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant cannot be flushed.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant cannot be synchronized.');
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temporaryPath);
            if ($error instanceof DiagnosticsAccessException) {
                throw $error;
            }
            throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant write failed.', $error);
        }
        fclose($handle);
        @chmod($temporaryPath, 0640);
        try {
            $roundTrip = $this->readJsonObject($temporaryPath);
            $this->validateGrant($roundTrip);
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }
        return $temporaryPath;
    }

    private function commitPreparedJson(string $temporaryPath, string $targetPath, bool $allowReplace): void
    {
        if (is_link($targetPath)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The access grant path is unsafe.');
        }
        if (!$allowReplace && file_exists($targetPath)) {
            throw new DiagnosticsAccessException('ACCESS_ALREADY_EXISTS', 'The access grant already exists.');
        }
        if (file_exists($targetPath) && !is_file($targetPath)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant target is invalid.');
        }
        if (!@rename($temporaryPath, $targetPath)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant cannot be committed atomically.');
        }
        @chmod($targetPath, 0640);
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > 262144) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The access grant JSON is invalid.');
        }
        $raw = file_get_contents($path);
        if ($raw === false || substr(ltrim($raw), 0, 1) !== '{') {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The access grant JSON is invalid.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || $this->isList($decoded)) {
            throw new DiagnosticsAccessException('ACCESS_JSON', 'The access grant JSON is invalid.');
        }
        return $decoded;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new DiagnosticsAccessException('ACCESS_IO', 'The access grant cannot be written.');
            }
            $offset += $written;
        }
    }

    private function ensureDirectory(string $path, int $mode): string
    {
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'An access storage directory is unsafe.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'An access storage directory cannot be created.');
        }
        $this->assertSafeDirectory($path);
        @chmod($path, $mode);
        return $path;
    }

    private function assertSafeDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'An access storage directory is unsafe.');
        }
        if (!is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_IO', 'An access storage directory is missing.');
        }
    }

    private function isTimestamp(string $value): bool
    {
        return preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) === 1 && strtotime($value) !== false;
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }
}
