<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsAccessException.php';
require_once __DIR__ . '/DiagnosticsStorage.php';

final class DiagnosticsAuditLog
{
    private const ALLOWED_EVENTS = [
        'access_grant_created',
        'access_pin_rotated',
        'access_revoked',
        'auth_success',
        'auth_failure',
        'auth_rate_limited',
        'session_created',
        'session_expired',
        'session_invalidated',
        'logout',
    ];

    private const ALLOWED_METADATA = [
        'generation',
        'retry_after',
        'idle_seconds',
        'absolute_seconds',
    ];

    /** @var string */
    private $auditRoot;

    /** @var string */
    private $hmacKey;

    public function __construct(DiagnosticsStorage $storage, string $hmacKey)
    {
        if (strlen($hmacKey) < 32) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is incomplete.');
        }
        $this->hmacKey = $hmacKey;
        $this->auditRoot = $this->ensureDirectory($storage->getRoot() . DIRECTORY_SEPARATOR . 'audit', 0700);
    }

    public function pseudonymizeIp(string $remoteAddress): string
    {
        return hash_hmac('sha256', 'doktorhaus-diagnostics-ip-v1:' . $remoteAddress, $this->hmacKey);
    }

    public function pseudonymizeUserAgent(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        return hash_hmac('sha256', 'doktorhaus-diagnostics-user-agent-v1:' . $userAgent, $this->hmacKey);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{ip_hash: string, user_agent_hash: string|null}
     */
    public function requestFingerprint(array $server): array
    {
        $remoteAddress = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])
            ? $server['REMOTE_ADDR']
            : '';
        $userAgent = isset($server['HTTP_USER_AGENT']) && is_string($server['HTTP_USER_AGENT'])
            ? $server['HTTP_USER_AGENT']
            : '';

        return [
            'ip_hash' => $this->pseudonymizeIp($remoteAddress),
            'user_agent_hash' => $this->pseudonymizeUserAgent($userAgent),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function append(string $event, string $outcome, array $fields = [], ?int $now = null): array
    {
        if (!in_array($event, self::ALLOWED_EVENTS, true) || !in_array($outcome, ['success', 'failure', 'blocked'], true)) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit event is invalid.');
        }
        $now = $now === null ? time() : $now;
        $entry = [
            'event_id' => 'evt_' . bin2hex(random_bytes(16)),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'event' => $event,
            'outcome' => $outcome,
        ];
        foreach (['access_id', 'report_id', 'report_version', 'ip_hash', 'user_agent_hash', 'reason_code'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null) {
                if (!is_string($fields[$key]) || $fields[$key] === '') {
                    throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit field is invalid.');
                }
                $entry[$key] = $fields[$key];
            }
        }
        $metadata = [];
        if (isset($fields['metadata'])) {
            if (!is_array($fields['metadata'])) {
                throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit metadata is invalid.');
            }
            foreach ($fields['metadata'] as $key => $value) {
                if (!is_string($key) || !in_array($key, self::ALLOWED_METADATA, true) ||
                    (!is_int($value) && !is_bool($value) && !is_string($value))) {
                    throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit metadata is invalid.');
                }
                $metadata[$key] = $value;
            }
        }
        $entry['metadata'] = $metadata;

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit event cannot be serialized.');
        }
        $path = $this->auditRoot . DIRECTORY_SEPARATOR . gmdate('Y-m-d', $now) . '.jsonl';
        $this->appendLine($path, $json . "\n");
        return $entry;
    }

    private function appendLine(string $path, string $line): void
    {
        $this->assertAuditRoot();
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The security audit path is unsafe.');
        }
        if (file_exists($path) && !is_file($path)) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit target is invalid.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit cannot be opened.');
        }
        $locked = false;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new DiagnosticsAccessException('ACCESS_LOCK', 'The security audit lock cannot be acquired.');
            }
            $locked = true;
            if (is_link($path) || fseek($handle, 0, SEEK_END) !== 0) {
                throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The security audit path is unsafe.');
            }
            $offset = 0;
            $length = strlen($line);
            while ($offset < $length) {
                $written = fwrite($handle, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit cannot be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit cannot be flushed.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit cannot be synchronized.');
            }
        } catch (Throwable $error) {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            if ($error instanceof DiagnosticsAccessException) {
                throw $error;
            }
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit write failed.', $error);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0640);
    }

    private function ensureDirectory(string $path, int $mode): string
    {
        if (is_link($path)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The security audit directory is unsafe.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit directory cannot be created.');
        }
        if (!is_dir($path)) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit directory is invalid.');
        }
        @chmod($path, $mode);
        return $path;
    }

    private function assertAuditRoot(): void
    {
        if (is_link($this->auditRoot)) {
            throw new DiagnosticsAccessException('ACCESS_SYMLINK', 'The security audit directory is unsafe.');
        }
        if (!is_dir($this->auditRoot)) {
            throw new DiagnosticsAccessException('ACCESS_AUDIT', 'The security audit directory is missing.');
        }
    }
}
