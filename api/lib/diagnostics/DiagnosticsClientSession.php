<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsAccessException.php';
require_once __DIR__ . '/DiagnosticsAccessService.php';
require_once __DIR__ . '/DiagnosticsSecurityConfig.php';

final class DiagnosticsClientSession
{
    public const SESSION_NAME = 'DH_DIAGSESSID';

    /** @var DiagnosticsAccessService */
    private $accessService;

    /** @var DiagnosticsSecurityConfig */
    private $config;

    /** @var DiagnosticsAuditLog */
    private $audit;

    /** @var callable */
    private $clock;

    /** @var bool */
    private $cookieSecure = true;

    public function __construct(
        DiagnosticsAccessService $accessService,
        DiagnosticsSecurityConfig $config,
        ?DiagnosticsAuditLog $audit = null,
        ?callable $clock = null
    ) {
        $this->accessService = $accessService;
        $this->config = $config;
        $this->audit = $audit ?? $accessService->getAudit();
        $this->clock = $clock ?? function (): int {
            return time();
        };
    }

    /** @param array<string, mixed> $server */
    public function startHttp(array $server): void
    {
        $secureRequest = self::isSecureRequest($server);
        $localOverride = !$secureRequest && self::isLocalInsecureOverride($server);
        if (!$secureRequest && !$localOverride) {
            throw new DiagnosticsAccessException('ACCESS_HTTPS_REQUIRED', 'HTTPS is required.');
        }
        $this->cookieSecure = $secureRequest;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== self::SESSION_NAME) {
                throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
            }
            return;
        }
        if (headers_sent()) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session cannot be started.');
        }
        foreach ([
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
        ] as $setting => $value) {
            $previous = ini_set($setting, $value);
            $actual = strtolower((string)ini_get($setting));
            $enabled = in_array($actual, ['1', 'on', 'yes', 'true'], true);
            if ($previous === false || $enabled !== ($value === '1')) {
                throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The PHP session policy cannot be enforced.');
            }
        }
        session_name(self::SESSION_NAME);
        if (session_name() !== self::SESSION_NAME ||
            !session_set_cookie_params(self::cookieParameters($this->cookieSecure))) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The PHP session cookie policy cannot be enforced.');
        }
        $cookie = session_get_cookie_params();
        if (($cookie['lifetime'] ?? null) !== 0 || ($cookie['path'] ?? null) !== '/' ||
            ($cookie['secure'] ?? null) !== $this->cookieSecure || ($cookie['httponly'] ?? null) !== true ||
            ($cookie['samesite'] ?? null) !== 'Strict') {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The PHP session cookie policy cannot be enforced.');
        }
        if (!@session_start()) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session cannot be started.');
        }
    }

    /**
     * @param array<string, mixed> $grant
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function establish(array $grant, array $server): array
    {
        $this->requireStarted();
        if (!@session_regenerate_id(true)) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session cannot be renewed.');
        }
        $now = $this->now();
        $context = $this->buildContext($grant, $now);
        $_SESSION = $context;
        try {
            $this->audit->append('session_created', 'success', array_merge(
                $this->sessionAuditFields($context, $server),
                [
                    'metadata' => [
                        'generation' => (int)$context['grant_generation'],
                        'idle_seconds' => $this->config->getSessionIdleSeconds(),
                        'absolute_seconds' => $this->config->getSessionAbsoluteSeconds(),
                    ],
                ]
            ), $now);
        } catch (DiagnosticsAccessException $error) {
            $this->destroy();
            throw $error;
        }
        return $context;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function current(array $server): array
    {
        $this->requireStarted();
        if (!isset($_SESSION['access_id'])) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is not authenticated.');
        }
        $previous = $_SESSION;
        try {
            $validated = $this->validateContext($previous, $this->now());
            $_SESSION = $validated;
            return $validated;
        } catch (DiagnosticsAccessException $error) {
            $event = $error->getAccessCode() === 'ACCESS_SESSION_EXPIRED'
                ? 'session_expired'
                : 'session_invalidated';
            $reason = strtolower(substr($error->getAccessCode(), 7));
            $fields = isset($previous['access_id']) && is_string($previous['access_id'])
                ? $this->sessionAuditFields($previous, $server)
                : $this->requestAuditFields($server);
            $fields['reason_code'] = $reason;
            try {
                $this->audit->append($event, 'failure', $fields, $this->now());
            } finally {
                $this->destroy();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $server */
    public function logout(string $csrfToken, array $server): void
    {
        $context = $this->current($server);
        $this->assertCsrf($context, $csrfToken);
        try {
            $this->audit->append('logout', 'success', $this->sessionAuditFields($context, $server), $this->now());
        } finally {
            $this->destroy();
        }
    }

    /**
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    public function buildContext(array $grant, ?int $now = null): array
    {
        $now = $now ?? $this->now();
        foreach (['access_id', 'report_id', 'report_version', 'report_version_id', 'generation'] as $key) {
            if (!array_key_exists($key, $grant)) {
                throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The session grant is incomplete.');
            }
        }
        return [
            'access_id' => (string)$grant['access_id'],
            'report_id' => (string)$grant['report_id'],
            'report_version' => (string)$grant['report_version'],
            'report_version_id' => (string)$grant['report_version_id'],
            'grant_generation' => (int)$grant['generation'],
            'authenticated_at' => $now,
            'last_seen_at' => $now,
            'absolute_expires_at' => $now + $this->config->getSessionAbsoluteSeconds(),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validateContext(array $context, ?int $now = null): array
    {
        $now = $now ?? $this->now();
        $stringKeys = ['access_id', 'report_id', 'report_version', 'report_version_id', 'csrf_token'];
        $intKeys = ['grant_generation', 'authenticated_at', 'last_seen_at', 'absolute_expires_at'];
        $expectedKeys = array_merge($stringKeys, $intKeys);
        if (count($context) !== count($expectedKeys) || array_diff(array_keys($context), $expectedKeys) !== []) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
        }
        foreach ($stringKeys as $key) {
            if (!isset($context[$key]) || !is_string($context[$key]) || $context[$key] === '') {
                throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
            }
        }
        foreach ($intKeys as $key) {
            if (!isset($context[$key]) || !is_int($context[$key]) || $context[$key] < 1) {
                throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
            }
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $context['csrf_token']) !== 1) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
        }
        if ($context['authenticated_at'] > $context['last_seen_at'] || $context['last_seen_at'] > $now ||
            $context['absolute_expires_at'] !== $context['authenticated_at'] + $this->config->getSessionAbsoluteSeconds()) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
        }
        if ($now >= $context['absolute_expires_at'] ||
            $now - $context['last_seen_at'] >= $this->config->getSessionIdleSeconds()) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_EXPIRED', 'The diagnostics session has expired.');
        }

        $grant = $this->accessService->getStore()->load($context['access_id']);
        if ($grant['status'] !== 'active') {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
        }
        if (is_string($grant['expires_at']) && (int)strtotime($grant['expires_at']) <= $now) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_EXPIRED', 'The diagnostics access has expired.');
        }
        $bindings = [
            'report_id' => 'report_id',
            'report_version' => 'report_version',
            'report_version_id' => 'report_version_id',
            'grant_generation' => 'generation',
        ];
        foreach ($bindings as $sessionKey => $grantKey) {
            if ($context[$sessionKey] !== $grant[$grantKey]) {
                throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is invalid.');
            }
        }
        $this->accessService->assertGrantPackageBinding($grant);
        $context['last_seen_at'] = $now;
        return $context;
    }

    /** @param array<string, mixed> $context */
    public function assertCsrf(array $context, string $candidate): void
    {
        $expected = $context['csrf_token'] ?? null;
        if (!is_string($expected) || !hash_equals($expected, $candidate)) {
            throw new DiagnosticsAccessException('ACCESS_CSRF', 'The CSRF token is invalid.');
        }
    }

    /** @param array<string, mixed> $server */
    public static function isSecureRequest(array $server): bool
    {
        $https = isset($server['HTTPS']) && is_string($server['HTTPS'])
            ? strtolower($server['HTTPS'])
            : '';
        return in_array($https, ['on', '1'], true) || (string)($server['SERVER_PORT'] ?? '') === '443';
    }

    /** @param array<string, mixed> $server */
    public static function isLocalInsecureOverride(array $server): bool
    {
        if (getenv('DIAGNOSTICS_ALLOW_INSECURE_LOCAL_TEST') !== '1' || PHP_SAPI !== 'cli-server') {
            return false;
        }
        $remoteAddress = $server['REMOTE_ADDR'] ?? null;
        return is_string($remoteAddress) && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public static function cookieParameters(bool $secure = true): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $parameters = self::cookieParameters($this->cookieSecure);
        setcookie(self::SESSION_NAME, '', [
            'expires' => time() - 3600,
            'path' => $parameters['path'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'],
        ]);
        session_destroy();
    }

    private function requireStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== self::SESSION_NAME) {
            throw new DiagnosticsAccessException('ACCESS_SESSION_INVALID', 'The diagnostics session is not started.');
        }
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now < 1) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security clock is invalid.');
        }
        return $now;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function sessionAuditFields(array $context, array $server): array
    {
        $fields = $this->requestAuditFields($server);
        foreach (['access_id', 'report_id', 'report_version'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $fields[$key] = $context[$key];
            }
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function requestAuditFields(array $server): array
    {
        $fingerprint = $this->audit->requestFingerprint($server);
        return [
            'ip_hash' => $fingerprint['ip_hash'],
            'user_agent_hash' => $fingerprint['user_agent_hash'],
        ];
    }
}
