<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';
require_once __DIR__ . '/DiagnosticsIngestConfig.php';

final class MittiClient
{
    /** @var DiagnosticsIngestConfig */ private $config;
    /** @var callable|null */ private $transport;

    public function __construct(DiagnosticsIngestConfig $config, ?callable $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    /** @return array<string, mixed> */
    public function connectionStatus(): array
    {
        return [
            'enabled' => $this->config->getMode() !== 'off',
            'mode' => $this->config->getMode(),
            'mittiConfigured' => $this->config->getMittiToken() !== '',
            'llmConfigured' => $this->config->getOpenAiKey() !== '',
            'templateFilterConfigured' => $this->config->getTemplateId() !== '',
            'model' => $this->config->getLlmModel(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listCompleted(int $days = 30): array
    {
        $this->config->assertMittiConfigured();
        $days = max(1, min(90, $days));
        $query = [
            'completed' => 'true',
            'modified_after' => gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400)),
            'order' => 'desc',
            'limit' => '100',
        ];
        if ($this->config->getTemplateId() !== '') {
            $query['template'] = $this->config->getTemplateId();
        }
        $payload = $this->requestJson('/audits/search', $query);
        $rows = $payload['audits'] ?? $payload['data'] ?? $payload['items'] ?? $payload;
        if (!is_array($rows)) {
            throw new DiagnosticsIngestException('MITTI_RESPONSE', 'Mitti zoznam nemá podporovaný tvar.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isCompleted($row)) {
                continue;
            }
            $id = $this->firstString($row, ['audit_id', 'inspection_id', 'id']);
            if ($id === '') {
                continue;
            }
            $result[] = [
                'sourceInspectionId' => $id,
                'title' => $this->firstString($row, ['name', 'title', 'audit_title'], 'Ukončená inšpekcia'),
                'location' => $this->nestedString($row, [['site', 'name'], ['site_name'], ['location'], ['audit_data', 'site', 'name']]),
                'completedAt' => $this->nestedString($row, [['completed_at'], ['date_completed'], ['audit_data', 'date_completed']]),
                'template' => $this->nestedString($row, [['template_name'], ['template', 'name'], ['audit_data', 'template_name']]),
                'templateId' => $this->nestedString($row, [['template_id'], ['template', 'id'], ['audit_data', 'template_id']]),
                'inspector' => $this->nestedString($row, [['owner', 'name'], ['author', 'name'], ['inspector_name'], ['audit_data', 'author']]),
                'modifiedAt' => $this->nestedString($row, [['modified_at'], ['last_modified_at'], ['audit_data', 'date_modified']]),
            ];
        }
        usort($result, static function (array $left, array $right): int {
            return strcmp((string)$right['modifiedAt'], (string)$left['modifiedAt']);
        });
        return $result;
    }

    /** @return array<string, mixed> */
    public function getInspection(string $sourceInspectionId): array
    {
        $this->assertSourceId($sourceInspectionId);
        return $this->requestJson(
            '/inspections/v1/inspections/' . rawurlencode($sourceInspectionId) . '/details',
            ['include_media_url' => 'false']
        );
    }

    /** @return array<string, mixed> */
    public function getTemplate(string $sourceInspectionId): array
    {
        $this->assertSourceId($sourceInspectionId);
        return $this->requestJson('/templates/v1/templates/inspections/' . rawurlencode($sourceInspectionId));
    }

    /** @return array<int, array<string, mixed>> */
    public function getAnswers(string $sourceInspectionId): array
    {
        $this->assertSourceId($sourceInspectionId);
        $response = $this->request('/inspections/v1/answers/' . rawurlencode($sourceInspectionId), [], false);
        $lines = preg_split('/\r?\n/', trim((string)$response['body'])) ?: [];
        $answers = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new DiagnosticsIngestException('MITTI_NDJSON', 'Mitti answers stream obsahuje neplatný JSON.', ['line' => $index + 1]);
            }
            $answers[] = $decoded;
        }
        return $answers;
    }

    /**
     * Download through the official signed-media flow. The browser never provides a URL.
     * @return array<string, mixed>
     */
    public function downloadMedia(string $mediaId, string $mediaType, string $destination): array
    {
        $this->assertSourceId($mediaId);
        if (!preg_match('/^[a-z0-9_\-]{1,40}$/D', $mediaType)) {
            throw new DiagnosticsIngestException('MITTI_MEDIA', 'Neplatný typ Mitti média.');
        }
        $signed = $this->requestJson('/media/v1/download/' . rawurlencode($mediaId), ['media_type' => $mediaType]);
        $url = (string)($signed['url'] ?? $signed['download_url'] ?? $signed['signed_url'] ?? '');
        $this->assertSafeSignedUrl($url);
        return $this->streamBinaryUrl($url, $destination);
    }

    /** @return array<string, mixed> */
    private function requestJson(string $path, array $query = []): array
    {
        $response = $this->request($path, $query, true);
        $decoded = json_decode((string)$response['body'], true);
        if (!is_array($decoded)) {
            throw new DiagnosticsIngestException('MITTI_JSON', 'Mitti API vrátilo neplatný JSON.');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function request(string $path, array $query, bool $expectJson): array
    {
        $this->config->assertMittiConfigured();
        if ($path === '' || $path[0] !== '/' || strpos($path, '..') !== false) {
            throw new DiagnosticsIngestException('MITTI_PATH', 'Neplatná Mitti API cesta.');
        }
        $url = $this->config->getMittiBaseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [
            'Accept: ' . ($expectJson ? 'application/json, application/x-ndjson' : 'application/x-ndjson, application/json'),
            'Authorization: Bearer ' . $this->config->getMittiToken(),
            'User-Agent: DoktorHaus-diagnostics/1.0',
        ];
        $attempt = 0;
        do {
            $attempt++;
            $response = $this->transport
                ? call_user_func($this->transport, 'GET', $url, $headers, null, $this->config->getMaxJsonBytes())
                : $this->streamRequest($url, $headers, $this->config->getMaxJsonBytes());
            $status = (int)($response['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                return $response;
            }
            if (($status === 429 || $status >= 500) && $attempt < 3) {
                usleep(100000 * $attempt);
                continue;
            }
            throw new DiagnosticsIngestException(
                $status === 429 ? 'MITTI_RATE_LIMIT' : 'MITTI_HTTP',
                'Mitti API požiadavka zlyhala.',
                ['status' => $status]
            );
        } while ($attempt < 3);
        throw new DiagnosticsIngestException('MITTI_HTTP', 'Mitti API požiadavka zlyhala.');
    }

    /** @return array<string, mixed> */
    private function streamRequest(string $url, array $headers, int $maxBytes): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->config->getConnectTimeout(),
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if (!is_resource($handle)) {
            throw new DiagnosticsIngestException('MITTI_NETWORK', 'Mitti API nie je dostupné.');
        }
        stream_set_timeout($handle, $this->config->getReadTimeout());
        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                fclose($handle);
                throw new DiagnosticsIngestException('MITTI_NETWORK', 'Mitti API odpoveď sa nepodarila načítať.');
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                fclose($handle);
                throw new DiagnosticsIngestException('MITTI_SIZE', 'Mitti API odpoveď prekročila limit.');
            }
        }
        $metadata = stream_get_meta_data($handle);
        fclose($handle);
        $responseHeaders = $metadata['wrapper_data'] ?? [];
        return ['status' => $this->httpStatus($responseHeaders), 'headers' => $responseHeaders, 'body' => $body];
    }

    /** @return array<string, mixed> */
    private function streamBinaryUrl(string $url, string $destination): array
    {
        if ($this->transport) {
            $response = call_user_func($this->transport, 'GET', $url, ['Accept: application/octet-stream', 'User-Agent: DoktorHaus-diagnostics/1.0'], $destination, $this->config->getMaxMediaBytes());
            if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
                throw new DiagnosticsIngestException('MITTI_MEDIA_HTTP', 'Mitti médium sa nepodarilo stiahnuť.');
            }
            if (isset($response['body']) && file_put_contents($destination, (string)$response['body']) === false) {
                throw new DiagnosticsIngestException('MITTI_MEDIA_IO', 'Mitti médium sa nepodarilo uložiť.');
            }
            return $this->inspectDownloadedMedia($destination, (array)($response['headers'] ?? []));
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $this->config->getConnectTimeout(), 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: DoktorHaus-diagnostics/1.0\r\nAccept: application/octet-stream"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $source = @fopen($url, 'rb', false, $context);
        if (!is_resource($source)) {
            throw new DiagnosticsIngestException('MITTI_MEDIA_NETWORK', 'Mitti médium nie je dostupné.');
        }
        stream_set_timeout($source, $this->config->getReadTimeout());
        $metadata = stream_get_meta_data($source);
        $headers = (array)($metadata['wrapper_data'] ?? []);
        $status = $this->httpStatus($headers);
        if ($status < 200 || $status >= 300) {
            fclose($source);
            throw new DiagnosticsIngestException('MITTI_MEDIA_HTTP', 'Mitti médium sa nepodarilo stiahnuť.', ['status' => $status]);
        }
        $target = @fopen($destination, 'xb');
        if (!is_resource($target)) {
            fclose($source);
            throw new DiagnosticsIngestException('MITTI_MEDIA_IO', 'Mitti médium sa nepodarilo bezpečne uložiť.');
        }
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new DiagnosticsIngestException('MITTI_MEDIA_NETWORK', 'Sťahovanie Mitti média bolo prerušené.');
                }
                $size += strlen($chunk);
                if ($size > $this->config->getMaxMediaBytes() || fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new DiagnosticsIngestException('MITTI_MEDIA_SIZE', 'Mitti médium prekročilo limit alebo sa neuložilo celé.');
                }
            }
            fflush($target);
        } catch (\Throwable $error) {
            fclose($source);
            fclose($target);
            @unlink($destination);
            throw $error;
        }
        fclose($source);
        fclose($target);
        return $this->inspectDownloadedMedia($destination, $headers);
    }

    /** @return array<string, mixed> */
    private function inspectDownloadedMedia(string $path, array $headers): array
    {
        if (!is_file($path)) {
            throw new DiagnosticsIngestException('MITTI_MEDIA_IO', 'Stiahnuté Mitti médium chýba.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string)$finfo->file($path);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4', 'video/quicktime', 'video/webm'];
        if (!in_array($detected, $allowed, true)) {
            @unlink($path);
            throw new DiagnosticsIngestException('MITTI_MEDIA_MIME', 'Mitti médium nemá povolený obsahový typ.');
        }
        $declared = $this->headerValue($headers, 'content-type');
        if ($declared !== '' && stripos($declared, 'text/html') !== false) {
            @unlink($path);
            throw new DiagnosticsIngestException('MITTI_MEDIA_MIME', 'Mitti server vrátil HTML namiesto média.');
        }
        return ['contentType' => $detected, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    private function assertSafeSignedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new DiagnosticsIngestException('MITTI_MEDIA_URL', 'Mitti media URL nie je bezpečná HTTPS adresa.');
        }
        $host = strtolower((string)$parts['host']);
        if ($host === 'localhost' || substr($host, -6) === '.local' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            throw new DiagnosticsIngestException('MITTI_MEDIA_URL', 'Mitti media URL smeruje na nepovolenú sieť.');
        }
    }

    private function isCompleted(array $row): bool
    {
        if (($row['completed'] ?? null) === true) {
            return true;
        }
        $status = strtolower($this->firstString($row, ['status', 'audit_status']));
        if (in_array($status, ['completed', 'complete', 'finished'], true)) {
            return true;
        }
        return $this->nestedString($row, [['completed_at'], ['date_completed'], ['audit_data', 'date_completed']]) !== '';
    }

    private function assertSourceId(string $value): void
    {
        if ($value === '' || strlen($value) > 160 || preg_match('/^[A-Za-z0-9_.:\-]+$/D', $value) !== 1) {
            throw new DiagnosticsIngestException('MITTI_ID', 'Neplatná Mitti identita.');
        }
    }

    private function firstString(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_numeric($row[$key]))) {
                return trim((string)$row[$key]);
            }
        }
        return $default;
    }

    private function nestedString(array $row, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $row;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) || is_numeric($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }

    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                return (int)$match[1];
            }
        }
        return 0;
    }

    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (is_string($header) && stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }
}
