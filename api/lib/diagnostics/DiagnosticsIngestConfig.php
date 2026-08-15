<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';

final class DiagnosticsIngestConfig
{
    /** @var string */ private $mode;
    /** @var string */ private $mittiToken;
    /** @var string */ private $mittiBaseUrl;
    /** @var string */ private $templateId;
    /** @var string */ private $openAiKey;
    /** @var string */ private $llmModel;
    /** @var bool */ private $vision;
    /** @var int */ private $connectTimeout;
    /** @var int */ private $readTimeout;
    /** @var int */ private $maxJsonBytes;
    /** @var int */ private $maxMediaBytes;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        $mode = strtolower(trim((string)($values['mitti_ingest_mode'] ?? 'shadow')));
        if (!in_array($mode, ['off', 'shadow', 'active'], true)) {
            throw new DiagnosticsIngestException('INGEST_CONFIG', 'Neplatný režim Mitti ingestu.');
        }
        $baseUrl = rtrim(trim((string)($values['mitti_api_base_url'] ?? 'https://api.mitti.com')), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new DiagnosticsIngestException('INGEST_CONFIG', 'Mitti API base musí byť bezpečná HTTPS adresa.');
        }
        $this->mode = $mode;
        $this->mittiToken = trim((string)($values['mitti_api_token'] ?? ''));
        $this->mittiBaseUrl = $baseUrl;
        $this->templateId = trim((string)($values['mitti_template_id'] ?? ''));
        $this->openAiKey = trim((string)($values['openai_api_key'] ?? ''));
        $this->llmModel = trim((string)($values['diagnostics_llm_model'] ?? 'gpt-5.6-terra'));
        $this->vision = (bool)($values['diagnostics_llm_vision'] ?? false);
        $this->connectTimeout = max(2, min(30, (int)($values['mitti_connect_timeout'] ?? 8)));
        $this->readTimeout = max(5, min(120, (int)($values['mitti_read_timeout'] ?? 45)));
        $this->maxJsonBytes = max(1048576, min(52428800, (int)($values['mitti_max_json_bytes'] ?? 12582912)));
        $this->maxMediaBytes = max(1048576, min(536870912, (int)($values['mitti_max_media_bytes'] ?? 157286400)));
        if ($this->llmModel === '') {
            throw new DiagnosticsIngestException('INGEST_CONFIG', 'Chýba model diagnostickej LLM vrstvy.');
        }
    }

    public static function fromEnvironment(): self
    {
        $local = dirname(__DIR__, 2) . '/diagnostics.config.php';
        $config = is_file($local) ? require $local : [];
        if (!is_array($config)) {
            $config = [];
        }
        $value = static function (string $environment, string $key, $default = '') use ($config) {
            $environmentValue = getenv($environment);
            return $environmentValue !== false && $environmentValue !== ''
                ? $environmentValue
                : ($config[$key] ?? $default);
        };
        return new self([
            'mitti_ingest_mode' => $value('MITTI_INGEST_MODE', 'mitti_ingest_mode', 'shadow'),
            'mitti_api_token' => $value('MITTI_API_TOKEN', 'mitti_api_token'),
            'mitti_api_base_url' => $value('MITTI_API_BASE_URL', 'mitti_api_base_url', 'https://api.mitti.com'),
            'mitti_template_id' => $value('MITTI_INSPECTION_TEMPLATE_ID', 'mitti_template_id'),
            'openai_api_key' => $value('OPENAI_API_KEY', 'openai_api_key'),
            'diagnostics_llm_model' => $value('DIAGNOSTICS_LLM_MODEL', 'diagnostics_llm_model', 'gpt-5.6-terra'),
            'diagnostics_llm_vision' => in_array(strtolower((string)$value('DIAGNOSTICS_LLM_VISION', 'diagnostics_llm_vision', '0')), ['1', 'true', 'yes'], true),
            'mitti_connect_timeout' => $value('MITTI_CONNECT_TIMEOUT', 'mitti_connect_timeout', 8),
            'mitti_read_timeout' => $value('MITTI_READ_TIMEOUT', 'mitti_read_timeout', 45),
            'mitti_max_json_bytes' => $value('MITTI_MAX_JSON_BYTES', 'mitti_max_json_bytes', 12582912),
            'mitti_max_media_bytes' => $value('MITTI_MAX_MEDIA_BYTES', 'mitti_max_media_bytes', 157286400),
        ]);
    }

    public function assertEnabled(): void
    {
        if ($this->mode === 'off') {
            throw new DiagnosticsIngestException('INGEST_DISABLED', 'Mitti ingest je vypnutý.');
        }
    }

    public function assertMittiConfigured(): void
    {
        $this->assertEnabled();
        if ($this->mittiToken === '') {
            throw new DiagnosticsIngestException('MITTI_NOT_CONFIGURED', 'Mitti API pripojenie nie je nakonfigurované.');
        }
    }

    public function assertOpenAiConfigured(): void
    {
        if ($this->openAiKey === '') {
            throw new DiagnosticsIngestException('LLM_NOT_CONFIGURED', 'Diagnostická LLM vrstva nie je nakonfigurovaná.');
        }
    }

    public function getMode(): string { return $this->mode; }
    public function getMittiToken(): string { return $this->mittiToken; }
    public function getMittiBaseUrl(): string { return $this->mittiBaseUrl; }
    public function getTemplateId(): string { return $this->templateId; }
    public function getOpenAiKey(): string { return $this->openAiKey; }
    public function getLlmModel(): string { return $this->llmModel; }
    public function isVisionEnabled(): bool { return $this->vision; }
    public function getConnectTimeout(): int { return $this->connectTimeout; }
    public function getReadTimeout(): int { return $this->readTimeout; }
    public function getMaxJsonBytes(): int { return $this->maxJsonBytes; }
    public function getMaxMediaBytes(): int { return $this->maxMediaBytes; }
}
