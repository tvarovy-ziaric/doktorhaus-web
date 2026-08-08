<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsAccessException.php';

final class DiagnosticsSecurityConfig
{
    /** @var string */
    private $pinPepper;

    /** @var string */
    private $auditHmacKey;

    /** @var int */
    private $sessionIdleSeconds;

    /** @var int */
    private $sessionAbsoluteSeconds;

    /** @var int */
    private $rateWindowSeconds;

    /** @var int */
    private $rateAccessIpMax;

    /** @var int */
    private $rateIpMax;

    /** @var int */
    private $rateLockoutSeconds;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->pinPepper = $this->requireSecret($values['pin_pepper'] ?? null);
        $this->auditHmacKey = $this->requireSecret($values['audit_hmac_key'] ?? null);
        $this->sessionIdleSeconds = $this->positiveInt($values['session_idle_seconds'] ?? 3600);
        $this->sessionAbsoluteSeconds = $this->positiveInt($values['session_absolute_seconds'] ?? 43200);
        $this->rateWindowSeconds = $this->positiveInt($values['rate_window_seconds'] ?? 900);
        $this->rateAccessIpMax = $this->positiveInt($values['rate_access_ip_max'] ?? 6);
        $this->rateIpMax = $this->positiveInt($values['rate_ip_max'] ?? 30);
        $this->rateLockoutSeconds = $this->positiveInt($values['rate_lockout_seconds'] ?? 900);

        if (hash_equals($this->pinPepper, $this->auditHmacKey)) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security secrets must be distinct.');
        }
        if ($this->sessionAbsoluteSeconds < $this->sessionIdleSeconds) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is invalid.');
        }
        if ($this->rateIpMax < $this->rateAccessIpMax) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is invalid.');
        }
    }

    public static function fromEnvironment(): self
    {
        $fileConfig = [];
        $configFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'diagnostics.config.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (!is_array($loaded)) {
                throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is invalid.');
            }
            $fileConfig = $loaded;
        }

        return new self([
            'pin_pepper' => self::environmentOrConfig('DIAGNOSTICS_PIN_PEPPER', $fileConfig, 'pin_pepper'),
            'audit_hmac_key' => self::environmentOrConfig('DIAGNOSTICS_AUDIT_HMAC_KEY', $fileConfig, 'audit_hmac_key'),
            'session_idle_seconds' => self::environmentOrConfig(
                'DIAGNOSTICS_SESSION_IDLE_SECONDS',
                $fileConfig,
                'session_idle_seconds',
                3600
            ),
            'session_absolute_seconds' => self::environmentOrConfig(
                'DIAGNOSTICS_SESSION_ABSOLUTE_SECONDS',
                $fileConfig,
                'session_absolute_seconds',
                43200
            ),
            'rate_window_seconds' => self::environmentOrConfig(
                'DIAGNOSTICS_RATE_WINDOW_SECONDS',
                $fileConfig,
                'rate_window_seconds',
                900
            ),
            'rate_access_ip_max' => self::environmentOrConfig(
                'DIAGNOSTICS_RATE_ACCESS_IP_MAX',
                $fileConfig,
                'rate_access_ip_max',
                6
            ),
            'rate_ip_max' => self::environmentOrConfig(
                'DIAGNOSTICS_RATE_IP_MAX',
                $fileConfig,
                'rate_ip_max',
                30
            ),
            'rate_lockout_seconds' => self::environmentOrConfig(
                'DIAGNOSTICS_RATE_LOCKOUT_SECONDS',
                $fileConfig,
                'rate_lockout_seconds',
                900
            ),
        ]);
    }

    public function getPinPepper(): string
    {
        return $this->pinPepper;
    }

    public function getAuditHmacKey(): string
    {
        return $this->auditHmacKey;
    }

    public function getSessionIdleSeconds(): int
    {
        return $this->sessionIdleSeconds;
    }

    public function getSessionAbsoluteSeconds(): int
    {
        return $this->sessionAbsoluteSeconds;
    }

    public function getRateWindowSeconds(): int
    {
        return $this->rateWindowSeconds;
    }

    public function getRateAccessIpMax(): int
    {
        return $this->rateAccessIpMax;
    }

    public function getRateIpMax(): int
    {
        return $this->rateIpMax;
    }

    public function getRateLockoutSeconds(): int
    {
        return $this->rateLockoutSeconds;
    }

    /** @param mixed $value */
    private function requireSecret($value): string
    {
        if (!is_string($value) || strlen($value) < 32) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is incomplete.');
        }
        return $value;
    }

    /** @param mixed $value */
    private function positiveInt($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 1) {
            throw new DiagnosticsAccessException('ACCESS_CONFIG', 'The diagnostics security configuration is invalid.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @param mixed $default
     * @return mixed
     */
    private static function environmentOrConfig(string $environmentName, array $config, string $key, $default = null)
    {
        $environmentValue = getenv($environmentName);
        if (is_string($environmentValue) && $environmentValue !== '') {
            return $environmentValue;
        }
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }
}
