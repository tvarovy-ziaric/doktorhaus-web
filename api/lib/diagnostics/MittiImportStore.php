<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';
require_once __DIR__ . '/DiagnosticsStorage.php';

final class MittiImportStore
{
    /** @var DiagnosticsStorage */ private $storage;
    /** @var string */ private $root;
    /** @var string */ private $jobsRoot;
    /** @var string */ private $locksRoot;

    public function __construct(DiagnosticsStorage $storage)
    {
        $this->storage = $storage;
        $this->root = $this->ensureDirectory($storage->getRoot() . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . 'mitti');
        $this->jobsRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'jobs');
        $this->locksRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'locks');
        $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'tmp');
        $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'audit');
    }

    /**
     * @param array<string, mixed> $inspection
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $answers
     * @param array<int, array<string, mixed>> $media
     * @return array<string, mixed>
     */
    public function createSnapshot(
        string $sourceInspectionId,
        string $sourceModifiedAt,
        array $inspection,
        array $template,
        array $answers,
        array $media
    ): array {
        if ($sourceInspectionId === '' || strlen($sourceInspectionId) > 160) {
            throw new DiagnosticsIngestException('IMPORT_SOURCE_ID', 'Neplatná identita zdrojovej inšpekcie.');
        }
        $inspectionJson = $this->json($inspection);
        $templateJson = $this->json($template);
        $answerLines = [];
        foreach ($answers as $answer) {
            $answerLines[] = trim($this->json($answer));
        }
        $answersNdjson = $answerLines === [] ? '' : implode("\n", $answerLines) . "\n";
        $rawHash = hash('sha256', $inspectionJson . "\n" . $templateJson . "\n" . $answersNdjson);
        $sourceKey = substr(hash('sha256', 'doktorhaus:mitti:source:v1|' . $sourceInspectionId), 0, 24);
        $sourceRevision = substr(hash('sha256', $sourceInspectionId . '|' . $sourceModifiedAt . '|' . $rawHash), 0, 24);
        $sourceRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . $sourceKey);
        $target = $sourceRoot . DIRECTORY_SEPARATOR . $sourceRevision;

        return $this->withLock('source-' . $sourceKey, function () use (
            $target,
            $sourceKey,
            $sourceRevision,
            $sourceInspectionId,
            $sourceModifiedAt,
            $inspectionJson,
            $templateJson,
            $answersNdjson,
            $rawHash,
            $media
        ): array {
            if (is_dir($target)) {
                $existing = $this->readJson($target . DIRECTORY_SEPARATOR . 'manifest.json');
                if (($existing['raw_payload_sha256'] ?? null) !== $rawHash) {
                    throw new DiagnosticsIngestException('IMPORT_INTEGRITY', 'Existujúca Mitti revízia má odlišný obsah.');
                }
                return ['noOp' => true, 'sourceKey' => $sourceKey, 'sourceRevision' => $sourceRevision, 'manifest' => $existing];
            }
            $stage = $this->root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'snapshot-' . bin2hex(random_bytes(12));
            $this->ensureDirectory($stage);
            try {
                $this->writeExclusive($stage . DIRECTORY_SEPARATOR . 'inspection.raw.json', $inspectionJson);
                $this->writeExclusive($stage . DIRECTORY_SEPARATOR . 'template.raw.json', $templateJson);
                $this->writeExclusive($stage . DIRECTORY_SEPARATOR . 'answers.raw.ndjson', $answersNdjson);
                $this->ensureDirectory($stage . DIRECTORY_SEPARATOR . 'media');
                $normalizedMedia = $this->normalizeMedia($media);
                $manifest = [
                    'document_type' => 'mitti_import_manifest',
                    'version' => 1,
                    'source_system' => 'mitti',
                    'source_inspection_id' => $sourceInspectionId,
                    'source_key' => $sourceKey,
                    'source_revision' => $sourceRevision,
                    'template_id' => $this->findString($inspection, ['template_id', 'templateId']),
                    'source_modified_at' => $sourceModifiedAt,
                    'imported_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'raw_payload_sha256' => $rawHash,
                    'files' => [
                        ['name' => 'inspection.raw.json', 'sha256' => hash('sha256', $inspectionJson)],
                        ['name' => 'template.raw.json', 'sha256' => hash('sha256', $templateJson)],
                        ['name' => 'answers.raw.ndjson', 'sha256' => hash('sha256', $answersNdjson)],
                    ],
                    'media' => $normalizedMedia,
                    'download_status' => $normalizedMedia === [] ? 'complete' : 'pending',
                ];
                $this->writeExclusive($stage . DIRECTORY_SEPARATOR . 'manifest.json', $this->json($manifest));
                $this->writeExclusive($stage . DIRECTORY_SEPARATOR . 'import-meta.json', $this->json([
                    'source_key' => $sourceKey,
                    'source_revision' => $sourceRevision,
                    'created_at' => $manifest['imported_at'],
                    'warnings' => [],
                ]));
                if (!@rename($stage, $target)) {
                    throw new DiagnosticsIngestException('IMPORT_IO', 'Immutable Mitti snapshot sa nepodarilo dokončiť.');
                }
                // The snapshot directory remains private and writable for its append-only
                // media/canonical metadata phase; raw payload files are never rewritten.
                @chmod($target, 0700);
                $this->appendAudit('mitti_import_completed', 'success', ['source_key' => $sourceKey, 'source_revision' => $sourceRevision]);
                return ['noOp' => false, 'sourceKey' => $sourceKey, 'sourceRevision' => $sourceRevision, 'manifest' => $manifest];
            } catch (\Throwable $error) {
                $this->removeTree($stage);
                throw $error;
            }
        });
    }

    /** @return array<string, mixed> */
    public function createJob(string $sourceKey, string $sourceRevision, bool $noOp): array
    {
        $this->assertOpaque($sourceKey, 'source key');
        $this->assertOpaque($sourceRevision, 'source revision');
        $job = [
            'jobId' => 'job_' . bin2hex(random_bytes(16)),
            'jobRevision' => 1,
            'sourceKey' => $sourceKey,
            'sourceRevision' => $sourceRevision,
            'state' => $noOp ? 'raw_import_noop' : 'raw_imported',
            'progress' => ['step' => 1, 'mediaDownloaded' => 0, 'mediaTotal' => 0],
            'warnings' => [],
            'errorCode' => null,
            'draftInspectionId' => null,
            'storageRevision' => null,
            'normalizedInspectionSha256' => null,
            'normalizedDiagnosisSha256' => null,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $manifest = $this->loadManifest($sourceKey, $sourceRevision);
        $job['progress']['mediaTotal'] = count((array)($manifest['media'] ?? []));
        $this->atomicWriteJson($this->jobPath($job['jobId']), $job, true);
        $this->appendAudit('mitti_import_started', 'success', ['job_id' => $job['jobId'], 'source_key' => $sourceKey]);
        return $job;
    }

    /** @return array<string, mixed> */
    public function loadJob(string $jobId): array
    {
        $this->assertJobId($jobId);
        return $this->withLock($jobId, function () use ($jobId): array {
            return $this->readJson($this->jobPath($jobId));
        });
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateJob(string $jobId, int $expectedRevision, array $changes): array
    {
        $this->assertJobId($jobId);
        return $this->withLock($jobId, function () use ($jobId, $expectedRevision, $changes): array {
            $path = $this->jobPath($jobId);
            $job = $this->readJson($path);
            if ((int)($job['jobRevision'] ?? 0) !== $expectedRevision) {
                throw new DiagnosticsIngestException('IMPORT_JOB_CONFLICT', 'Stav spracovania sa medzitým zmenil.');
            }
            $allowed = ['state', 'progress', 'warnings', 'errorCode', 'draftInspectionId', 'storageRevision', 'normalizedInspectionSha256', 'normalizedDiagnosisSha256', 'validation'];
            foreach ($changes as $key => $value) {
                if (in_array($key, $allowed, true)) {
                    $job[$key] = $value;
                }
            }
            $job['jobRevision'] = $expectedRevision + 1;
            $job['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->atomicWriteJson($path, $job, false);
            return $job;
        });
    }

    /** @return array<string, mixed> */
    public function loadManifest(string $sourceKey, string $sourceRevision): array
    {
        return $this->readJson($this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    /** @return array<string, mixed> */
    public function loadRawInspection(string $sourceKey, string $sourceRevision): array
    {
        return $this->readJson($this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'inspection.raw.json');
    }

    /** @return array<string, mixed> */
    public function loadRawTemplate(string $sourceKey, string $sourceRevision): array
    {
        return $this->readJson($this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'template.raw.json');
    }

    /** @return array<int, array<string, mixed>> */
    public function loadRawAnswers(string $sourceKey, string $sourceRevision): array
    {
        $path = $this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'answers.raw.ndjson';
        $lines = preg_split('/\r?\n/', trim((string)@file_get_contents($path))) ?: [];
        $items = [];
        foreach ($lines as $line) {
            if ($line === '') { continue; }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new DiagnosticsIngestException('IMPORT_INTEGRITY', 'Uložený Mitti answers stream je poškodený.');
            }
            $items[] = $decoded;
        }
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function nextPendingMedia(string $sourceKey, string $sourceRevision): ?array
    {
        $manifest = $this->loadManifest($sourceKey, $sourceRevision);
        foreach ((array)($manifest['media'] ?? []) as $media) {
            if (is_array($media) && ($media['status'] ?? null) === 'pending' && empty($media['pending_reason'])) {
                return $media;
            }
        }
        return null;
    }

    public function mediaTemporaryPath(string $sourceKey, string $sourceRevision, string $storageName): string
    {
        $this->assertMediaName($storageName);
        $directory = $this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'media';
        return $directory . DIRECTORY_SEPARATOR . '.' . $storageName . '.part-' . bin2hex(random_bytes(6));
    }

    /** @param array<string, mixed> $result */
    public function completeMedia(string $sourceKey, string $sourceRevision, string $mediaId, string $temporaryPath, array $result): array
    {
        return $this->withLock('source-' . $sourceKey, function () use ($sourceKey, $sourceRevision, $mediaId, $temporaryPath, $result): array {
            $directory = $this->snapshotDirectory($sourceKey, $sourceRevision);
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = $this->readJson($manifestPath);
            $found = false;
            foreach ($manifest['media'] as &$media) {
                if (($media['source_media_id'] ?? null) !== $mediaId) { continue; }
                $found = true;
                $name = (string)$media['storage_filename'];
                $this->assertMediaName($name);
                $target = $directory . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $name;
                if (is_file($target)) {
                    @unlink($temporaryPath);
                } elseif (!@rename($temporaryPath, $target)) {
                    throw new DiagnosticsIngestException('IMPORT_MEDIA_IO', 'Mitti médium sa nepodarilo atomicky dokončiť.');
                }
                $media['status'] = 'downloaded';
                $media['content_type'] = (string)($result['contentType'] ?? 'application/octet-stream');
                $media['size'] = (int)($result['size'] ?? 0);
                $media['sha256'] = (string)($result['sha256'] ?? '');
                break;
            }
            unset($media);
            if (!$found) {
                @unlink($temporaryPath);
                throw new DiagnosticsIngestException('IMPORT_MEDIA_ID', 'Mitti médium nie je v manifeste.');
            }
            $pending = array_filter($manifest['media'], static function ($media): bool { return ($media['status'] ?? null) === 'pending'; });
            $manifest['download_status'] = $pending === [] ? 'complete' : 'pending';
            $this->atomicWriteJson($manifestPath, $manifest, false);
            return $manifest;
        });
    }

    public function markMediaPendingReason(string $sourceKey, string $sourceRevision, string $mediaId, string $reason): array
    {
        return $this->withLock('source-' . $sourceKey, function () use ($sourceKey, $sourceRevision, $mediaId, $reason): array {
            $path = $this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = $this->readJson($path);
            foreach ($manifest['media'] as &$media) {
                if (($media['source_media_id'] ?? null) === $mediaId) {
                    $media['status'] = 'pending';
                    $media['pending_reason'] = substr($reason, 0, 80);
                }
            }
            unset($media);
            $this->atomicWriteJson($path, $manifest, false);
            return $manifest;
        });
    }

    /** @param array<string, mixed> $meta */
    public function saveCanonicalMeta(string $sourceKey, string $sourceRevision, array $meta): void
    {
        $path = $this->snapshotDirectory($sourceKey, $sourceRevision) . DIRECTORY_SEPARATOR . 'canonical-meta.json';
        $this->atomicWriteJson($path, $meta, false);
    }

    /** @return array<int, array<string, mixed>> */
    public function canonicalBaselines(string $sourceKey): array
    {
        $this->assertOpaque($sourceKey, 'source key');
        $sourceRoot = $this->root . DIRECTORY_SEPARATOR . $sourceKey;
        if (is_link($sourceRoot) || !is_dir($sourceRoot)) { return []; }
        $result = [];
        foreach (scandir($sourceRoot) ?: [] as $revision) {
            if (preg_match('/^[0-9a-f]{24}$/D', $revision) !== 1) { continue; }
            $path = $sourceRoot . DIRECTORY_SEPARATOR . $revision . DIRECTORY_SEPARATOR . 'canonical-meta.json';
            if (is_file($path) && !is_link($path)) { $result[] = $this->readJson($path); }
        }
        return $result;
    }

    public function appendAudit(string $event, string $outcome, array $metadata = []): void
    {
        $allowed = ['mitti_import_started', 'mitti_import_completed', 'mitti_import_failed', 'diagnostic_draft_generated', 'diagnostic_draft_validated', 'diagnostic_draft_replaced_by_human'];
        if (!in_array($event, $allowed, true) || !in_array($outcome, ['success', 'failure', 'blocked'], true)) {
            throw new DiagnosticsIngestException('IMPORT_AUDIT', 'Neplatná ingest audit udalosť.');
        }
        $safe = [];
        foreach (['job_id', 'source_key', 'source_revision', 'inspection_id', 'reason_code'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && $metadata[$key] !== '') {
                $safe[$key] = substr($metadata[$key], 0, 80);
            }
        }
        $entry = ['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event, 'outcome' => $outcome, 'metadata' => $safe];
        $path = $this->root . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.jsonl';
        $handle = @fopen($path, 'ab');
        if (!is_resource($handle)) {
            throw new DiagnosticsIngestException('IMPORT_AUDIT', 'Ingest audit sa nepodarilo zapísať.');
        }
        flock($handle, LOCK_EX);
        fwrite($handle, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0640);
    }

    /** @param array<int, array<string, mixed>> $media */
    private function normalizeMedia(array $media): array
    {
        $result = [];
        $seen = [];
        foreach ($media as $item) {
            if (!is_array($item)) { continue; }
            $id = trim((string)($item['id'] ?? $item['media_id'] ?? ''));
            if ($id === '' || isset($seen[$id]) || strlen($id) > 160) { continue; }
            $seen[$id] = true;
            $type = strtolower(trim((string)($item['media_type'] ?? $item['type'] ?? 'image')));
            $declared = strtolower(trim((string)($item['content_type'] ?? $item['mime_type'] ?? '')));
            $extension = $this->extensionFor($declared, $type);
            $result[] = [
                'source_media_id' => $id,
                'source_item_id' => substr((string)($item['source_item_id'] ?? $item['item_id'] ?? ''), 0, 160),
                'context' => substr((string)($item['context'] ?? $item['question'] ?? ''), 0, 500),
                'media_type' => preg_match('/^[a-z0-9_\-]{1,40}$/D', $type) ? $type : 'other',
                'original_filename' => basename(str_replace('\\', '/', (string)($item['filename'] ?? $item['name'] ?? ''))),
                'declared_content_type' => substr($declared, 0, 100),
                'storage_filename' => 'm_' . substr(hash('sha256', 'mitti-media|' . $id), 0, 24) . $extension,
                'status' => $type === 'video' ? 'pending' : 'pending',
            ];
        }
        return $result;
    }

    private function extensionFor(string $mime, string $type): string
    {
        $map = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'application/pdf' => '.pdf', 'video/mp4' => '.mp4', 'video/quicktime' => '.mov', 'video/webm' => '.webm'];
        return $map[$mime] ?? ($type === 'video' ? '.mp4' : '.bin');
    }

    private function snapshotDirectory(string $sourceKey, string $sourceRevision): string
    {
        $this->assertOpaque($sourceKey, 'source key');
        $this->assertOpaque($sourceRevision, 'source revision');
        $directory = $this->root . DIRECTORY_SEPARATOR . $sourceKey . DIRECTORY_SEPARATOR . $sourceRevision;
        if (is_link($directory) || !is_dir($directory)) {
            throw new DiagnosticsIngestException('IMPORT_NOT_FOUND', 'Mitti source snapshot sa nenašiel.');
        }
        return $directory;
    }

    private function jobPath(string $jobId): string { return $this->jobsRoot . DIRECTORY_SEPARATOR . $jobId . '.json'; }
    private function assertJobId(string $jobId): void { if (preg_match('/^job_[0-9a-f]{32}$/D', $jobId) !== 1) { throw new DiagnosticsIngestException('IMPORT_JOB_ID', 'Neplatná identita spracovania.'); } }
    private function assertOpaque(string $value, string $label): void { if (preg_match('/^[0-9a-f]{24}$/D', $value) !== 1) { throw new DiagnosticsIngestException('IMPORT_PATH', 'Neplatný ' . $label . '.'); } }
    private function assertMediaName(string $value): void { if (preg_match('/^m_[0-9a-f]{24}\.(jpg|png|webp|pdf|mp4|mov|webm|bin)$/D', $value) !== 1) { throw new DiagnosticsIngestException('IMPORT_PATH', 'Neplatný názov média.'); } }

    private function ensureDirectory(string $path): string
    {
        if (is_link($path)) { throw new DiagnosticsIngestException('IMPORT_SYMLINK', 'Ingest storage cesta je nebezpečná.'); }
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) { throw new DiagnosticsIngestException('IMPORT_IO', 'Ingest storage sa nepodarilo vytvoriť.'); }
        if (is_link($path) || !is_dir($path)) { throw new DiagnosticsIngestException('IMPORT_SYMLINK', 'Ingest storage cesta je nebezpečná.'); }
        @chmod($path, 0700);
        return $path;
    }

    private function json(array $data): string
    {
        $this->sortRecursive($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) { throw new DiagnosticsIngestException('IMPORT_JSON', 'Ingest JSON sa nepodarilo serializovať.'); }
        return $json . "\n";
    }

    private function sortRecursive(array &$value): void
    {
        if (!$this->isList($value)) { ksort($value); }
        foreach ($value as &$item) { if (is_array($item)) { $this->sortRecursive($item); } }
        unset($item);
    }

    private function isList(array $value): bool { $index = 0; foreach ($value as $key => $_) { if ($key !== $index++) { return false; } } return true; }

    private function readJson(string $path): array
    {
        if (is_link($path) || !is_file($path)) { throw new DiagnosticsIngestException('IMPORT_NOT_FOUND', 'Ingest dokument sa nenašiel.'); }
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded) || $this->isList($decoded)) { throw new DiagnosticsIngestException('IMPORT_INTEGRITY', 'Ingest dokument je poškodený.'); }
        return $decoded;
    }

    private function writeExclusive(string $path, string $content): void
    {
        if (is_link($path)) { throw new DiagnosticsIngestException('IMPORT_SYMLINK', 'Ingest cieľ je nebezpečný.'); }
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) { throw new DiagnosticsIngestException('IMPORT_IO', 'Ingest súbor sa nepodarilo vytvoriť.'); }
        if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) { fclose($handle); @unlink($path); throw new DiagnosticsIngestException('IMPORT_IO', 'Ingest súbor sa nepodarilo zapísať.'); }
        fclose($handle);
        @chmod($path, 0640);
    }

    private function atomicWriteJson(string $path, array $data, bool $mustNotExist): void
    {
        if ($mustNotExist && file_exists($path)) { throw new DiagnosticsIngestException('IMPORT_EXISTS', 'Ingest dokument už existuje.'); }
        if (is_link($path)) { throw new DiagnosticsIngestException('IMPORT_SYMLINK', 'Ingest cieľ je nebezpečný.'); }
        $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $this->writeExclusive($temporary, $this->json($data));
        if (!@rename($temporary, $path)) { @unlink($temporary); throw new DiagnosticsIngestException('IMPORT_IO', 'Ingest dokument sa nepodarilo atomicky uložiť.'); }
    }

    private function withLock(string $name, callable $callback)
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,100}$/D', $name) !== 1) { throw new DiagnosticsIngestException('IMPORT_LOCK', 'Neplatný ingest lock.'); }
        $path = $this->locksRoot . DIRECTORY_SEPARATOR . $name . '.lock';
        if (is_link($path)) { throw new DiagnosticsIngestException('IMPORT_SYMLINK', 'Ingest lock je nebezpečný.'); }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) { if (is_resource($handle)) { fclose($handle); } throw new DiagnosticsIngestException('IMPORT_LOCK', 'Ingest lock sa nepodarilo získať.'); }
        try { return $callback(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path) || is_link($path)) { return; }
        $items = scandir($path) ?: [];
        foreach ($items as $item) { if ($item === '.' || $item === '..') { continue; } $child = $path . DIRECTORY_SEPARATOR . $item; if (is_dir($child) && !is_link($child)) { $this->removeTree($child); } else { @unlink($child); } }
        @rmdir($path);
    }

    private function findString(array $data, array $keys): string { foreach ($keys as $key) { if (isset($data[$key]) && (is_string($data[$key]) || is_numeric($data[$key]))) { return substr((string)$data[$key], 0, 160); } } return ''; }
}
