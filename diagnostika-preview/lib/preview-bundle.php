<?php
declare(strict_types=1);

require_once __DIR__ . '/preview-runtime.php';

final class DhPreviewBundleException extends RuntimeException
{
}

final class DhPreviewStorageException extends RuntimeException
{
}

final class DhPreviewBundleInstaller
{
    private const MAX_ZIP_BYTES = DH_PREVIEW_MAX_ZIP_BYTES;
    private const MAX_UNCOMPRESSED_BYTES = 128 * 1024 * 1024;
    private const MAX_ENTRY_BYTES = 16 * 1024 * 1024;
    private const MAX_JSON_BYTES = 4 * 1024 * 1024;
    private const MAX_ENTRIES = 128;
    private const CLIENT_PATH = 'client-report-preview-v1.0.json';
    private const APPENDIX_PATH = 'source-documentation-appendix-v1.0.json';
    private const MANIFEST_PATH = 'preview-manifest.json';

    public static function install(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new DhPreviewBundleException('ZIP support is unavailable.');
        }
        if (!is_file($zipPath) || is_link($zipPath)) {
            throw new DhPreviewBundleException('Uploaded bundle is unavailable.');
        }
        $zipSize = filesize($zipPath);
        if (!is_int($zipSize) || $zipSize <= 0 || $zipSize > self::MAX_ZIP_BYTES) {
            throw new DhPreviewBundleException('Uploaded bundle exceeds the allowed size.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new DhPreviewBundleException('Uploaded file is not a valid ZIP archive.');
        }
        try {
            $entryMap = self::inspectEntries($zip);
            $manifestRaw = self::readJsonEntry($zip, self::MANIFEST_PATH, $entryMap);
            $clientRaw = self::readJsonEntry($zip, self::CLIENT_PATH, $entryMap);
            $appendixRaw = self::readJsonEntry($zip, self::APPENDIX_PATH, $entryMap);
            $manifest = self::decodeObject($manifestRaw, 'preview manifest');
            $client = self::decodeObject($clientRaw, 'client report');
            $appendix = self::decodeObject($appendixRaw, 'source documentation appendix');
            $validated = self::validateBundle($manifest, $client, $appendix, $manifestRaw, $clientRaw, $appendixRaw, $entryMap);

            $requiredStorageBytes = DH_PREVIEW_STORAGE_RESERVE_BYTES;
            foreach (array_keys($validated['file_hashes']) as $declaredPath) {
                $requiredStorageBytes += (int)$entryMap[$declaredPath]['size'];
            }
            try {
                $storage = dh_preview_storage_probe($requiredStorageBytes);
            } catch (RuntimeException $error) {
                throw new DhPreviewStorageException('Private preview storage is unavailable.');
            }
            if (!$storage['sufficient']) {
                throw new DhPreviewStorageException('Private preview storage has insufficient free space.');
            }

            $config = dh_preview_config();
            $previewRoot = $config['preview_root'];
            dh_preview_ensure_private_directory($previewRoot);
            $previewId = 'pvw_' . bin2hex(random_bytes(16));
            $finalDirectory = $previewRoot . DIRECTORY_SEPARATOR . $previewId;
            $stagingDirectory = $previewRoot . DIRECTORY_SEPARATOR . '.staging-' . bin2hex(random_bytes(16));
            if (file_exists($finalDirectory) || file_exists($stagingDirectory)) {
                throw new DhPreviewBundleException('Could not allocate preview storage.');
            }
            dh_preview_ensure_private_directory($stagingDirectory);
            $published = false;
            try {
                self::extractValidated($zip, $entryMap, $validated['file_hashes'], $stagingDirectory);
                $bundleSha256 = hash_file('sha256', $zipPath);
                if (!is_string($bundleSha256)) {
                    throw new DhPreviewBundleException('Bundle hash could not be calculated.');
                }
                $meta = [
                    'preview_id' => $previewId,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'bundle_sha256' => $bundleSha256,
                    'report_id' => $manifest['report_id'],
                    'report_version_id' => $manifest['report_version_id'],
                    'inspection_id' => $manifest['inspection_id'],
                    'counts' => $validated['counts'],
                    'files' => $validated['file_hashes'],
                ];
                self::writeMeta($stagingDirectory . DIRECTORY_SEPARATOR . 'preview-meta.json', $meta);
                if (!rename($stagingDirectory, $finalDirectory)) {
                    throw new DhPreviewBundleException('Could not publish private preview storage.');
                }
                $published = true;
                @chmod($finalDirectory, 0700);
                dh_preview_write_latest_pointer($previewId, $bundleSha256);
            } catch (Throwable $error) {
                self::removeTree($published ? $finalDirectory : $stagingDirectory);
                throw $error;
            }
            return $meta;
        } finally {
            $zip->close();
        }
    }

    private static function inspectEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new DhPreviewBundleException('ZIP entry count is not allowed.');
        }
        $entries = [];
        $caseFolded = [];
        $totalSize = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
                throw new DhPreviewBundleException('ZIP entry metadata is invalid.');
            }
            $name = (string)$stat['name'];
            self::assertSafeRelativePath($name);
            $isDirectory = dh_preview_ends_with($name, '/');
            if ($isDirectory && $name !== 'media/') {
                throw new DhPreviewBundleException('ZIP contains a non-allowlisted directory.');
            }
            if (!$isDirectory && !self::isAllowedFileName($name)) {
                throw new DhPreviewBundleException('ZIP contains a non-allowlisted file.');
            }
            if (isset($entries[$name]) || isset($caseFolded[strtolower($name)])) {
                throw new DhPreviewBundleException('ZIP contains duplicate paths.');
            }
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $operations = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
                    $fileType = ($attributes >> 16) & 0xF000;
                    if ($fileType === 0xA000) {
                        throw new DhPreviewBundleException('ZIP symlinks are not allowed.');
                    }
                }
            }
            $size = (int)$stat['size'];
            if ($size < 0 || $size > self::MAX_ENTRY_BYTES || (!$isDirectory && $size === 0)) {
                throw new DhPreviewBundleException('ZIP entry size is not allowed.');
            }
            $totalSize += $size;
            if ($totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                throw new DhPreviewBundleException('ZIP uncompressed size exceeds the allowed limit.');
            }
            $entries[$name] = ['index' => $index, 'size' => $size, 'directory' => $isDirectory];
            $caseFolded[strtolower($name)] = true;
        }
        return $entries;
    }

    private static function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || dh_preview_contains($path, "\0") || dh_preview_contains($path, '\\') || dh_preview_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/D', $path) === 1 || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $path) === 1) {
            throw new DhPreviewBundleException('ZIP contains an unsafe path.');
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new DhPreviewBundleException('ZIP contains an unsafe path.');
            }
        }
    }

    private static function isAllowedFileName(string $name): bool
    {
        if (in_array($name, [self::MANIFEST_PATH, self::CLIENT_PATH, self::APPENDIX_PATH], true)) {
            return true;
        }
        return preg_match('/^media\/ev_[0-9a-f]{16,32}\.(?:jpg|jpeg|png|webp)$/D', $name) === 1;
    }

    private static function readJsonEntry(ZipArchive $zip, string $name, array $entries): string
    {
        if (!isset($entries[$name]) || $entries[$name]['directory'] || $entries[$name]['size'] > self::MAX_JSON_BYTES) {
            throw new DhPreviewBundleException('Required JSON file is missing or too large.');
        }
        $raw = $zip->getFromIndex($entries[$name]['index'], self::MAX_JSON_BYTES, ZipArchive::FL_UNCHANGED);
        if (!is_string($raw) || strlen($raw) !== $entries[$name]['size']) {
            throw new DhPreviewBundleException('Required JSON file could not be read.');
        }
        return $raw;
    }

    private static function decodeObject(string $raw, string $label): array
    {
        try {
            $value = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DhPreviewBundleException('Invalid ' . $label . ' JSON.');
        }
        if (!is_array($value) || dh_preview_is_list($value)) {
            throw new DhPreviewBundleException('Invalid ' . $label . ' object.');
        }
        return $value;
    }

    private static function assertExactKeys(array $object, array $required, array $optional = []): void
    {
        $keys = array_keys($object);
        $allowed = array_values(array_unique(array_merge($required, $optional)));
        foreach ($required as $key) {
            if (!array_key_exists($key, $object)) {
                throw new DhPreviewBundleException('Required bundle field is missing.');
            }
        }
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new DhPreviewBundleException('Bundle contains an unknown field.');
            }
        }
    }

    private static function validateBundle(
        array $manifest,
        array $client,
        array $appendix,
        string $manifestRaw,
        string $clientRaw,
        string $appendixRaw,
        array $entries
    ): array {
        self::assertExactKeys($manifest, [
            'schema_version', 'document_type', 'report_id', 'report_version_id', 'inspection_id',
            'preview_state', 'client_report', 'source_documentation_appendix', 'media', 'missing_media',
        ]);
        if ($manifest['document_type'] !== 'diagnostics_preview_bundle' || $manifest['preview_state'] !== 'draft'
            || !self::validId($manifest['report_id'] ?? null, 'rpt')
            || !self::validId($manifest['report_version_id'] ?? null, 'rptv')
            || !self::validId($manifest['inspection_id'] ?? null, 'insp')) {
            throw new DhPreviewBundleException('Preview manifest identity is invalid.');
        }
        self::assertFileDescriptor($manifest['client_report'] ?? null, self::CLIENT_PATH, hash('sha256', $clientRaw), null);
        $appendixPhotoCount = is_array($appendix['items'] ?? null) && dh_preview_is_list($appendix['items'])
            ? count($appendix['items'])
            : null;
        self::assertFileDescriptor(
            $manifest['source_documentation_appendix'] ?? null,
            self::APPENDIX_PATH,
            hash('sha256', $appendixRaw),
            $appendixPhotoCount
        );

        if (!is_array($manifest['media']) || !dh_preview_is_list($manifest['media'])) {
            throw new DhPreviewBundleException('Preview manifest media must be a list.');
        }
        $mediaById = [];
        $fileHashes = [
            self::MANIFEST_PATH => hash('sha256', $manifestRaw),
            self::CLIENT_PATH => hash('sha256', $clientRaw),
            self::APPENDIX_PATH => hash('sha256', $appendixRaw),
        ];
        $placementCounts = ['linked_evidence' => 0, 'source_documentation_appendix' => 0];
        foreach ($manifest['media'] as $media) {
            if (!is_array($media) || dh_preview_is_list($media)) {
                throw new DhPreviewBundleException('Preview media descriptor is invalid.');
            }
            self::assertExactKeys($media, ['evidence_id', 'path', 'sha256', 'content_type', 'placement_kind']);
            $evidenceId = (string)($media['evidence_id'] ?? '');
            $path = (string)($media['path'] ?? '');
            $sha256 = (string)($media['sha256'] ?? '');
            $contentType = (string)($media['content_type'] ?? '');
            $placement = (string)($media['placement_kind'] ?? '');
            if (!self::validId($evidenceId, 'ev') || isset($mediaById[$evidenceId])
                || preg_match('/^media\/' . preg_quote($evidenceId, '/') . '\.(?:jpg|jpeg|png|webp)$/D', $path) !== 1
                || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
                || !isset($placementCounts[$placement]) || !isset($entries[$path]) || $entries[$path]['directory']) {
                throw new DhPreviewBundleException('Preview media descriptor is invalid.');
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $expectedTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            if (($expectedTypes[$extension] ?? '') !== $contentType) {
                throw new DhPreviewBundleException('Preview media content type is invalid.');
            }
            $mediaById[$evidenceId] = $media;
            $fileHashes[$path] = $sha256;
            $placementCounts[$placement]++;
        }
        $expectedEntries = array_fill_keys(array_keys($fileHashes), true);
        if (isset($entries['media/'])) {
            $expectedEntries['media/'] = true;
        }
        if (array_diff_key($entries, $expectedEntries) || array_diff_key($expectedEntries, $entries)) {
            throw new DhPreviewBundleException('ZIP contents do not match the preview manifest.');
        }

        self::validateClientReport($client, $mediaById, $manifest);
        self::validateAppendix($appendix, $mediaById, $manifest);
        self::validateMissingVideos($manifest['missing_media']);
        return [
            'file_hashes' => $fileHashes,
            'counts' => [
                'media' => count($mediaById),
                'linked_evidence' => $placementCounts['linked_evidence'],
                'source_documentation_appendix' => $placementCounts['source_documentation_appendix'],
                'videos_pending' => count($manifest['missing_media']),
            ],
        ];
    }

    private static function assertFileDescriptor($descriptor, string $path, string $sha256, ?int $photoCount): void
    {
        if (!is_array($descriptor) || dh_preview_is_list($descriptor)) {
            throw new DhPreviewBundleException('Preview file descriptor is invalid.');
        }
        $required = ['path', 'sha256', 'content_type'];
        if ($photoCount !== null) {
            $required[] = 'photo_count';
        }
        self::assertExactKeys($descriptor, $required);
        if (($descriptor['path'] ?? '') !== $path || ($descriptor['sha256'] ?? '') !== $sha256
            || ($descriptor['content_type'] ?? '') !== 'application/json'
            || ($photoCount !== null && ($descriptor['photo_count'] ?? null) !== $photoCount)) {
            throw new DhPreviewBundleException('Preview file descriptor integrity failed.');
        }
    }

    private static function validateClientReport(array $client, array $mediaById, array $manifest): void
    {
        self::assertExactKeys($client, [
            'schema_version', 'document_type', 'report', 'property', 'inspection', 'overview', 'issues',
            'recommendations', 'verifications', 'issue_relations', 'unverified_items', 'generated_at',
        ], ['pricing']);
        if (($client['schema_version'] ?? '') !== '1.0.0' || ($client['document_type'] ?? '') !== 'client_report'
            || !is_array($client['report'] ?? null) || !is_array($client['property'] ?? null)
            || !is_array($client['inspection'] ?? null) || !is_array($client['overview'] ?? null)
            || !is_array($client['issues'] ?? null) || !dh_preview_is_list($client['issues'])
            || !is_array($client['recommendations'] ?? null) || !dh_preview_is_list($client['recommendations'])
            || !is_array($client['verifications'] ?? null) || !dh_preview_is_list($client['verifications'])
            || !is_array($client['issue_relations'] ?? null) || !dh_preview_is_list($client['issue_relations'])
            || !is_array($client['unverified_items'] ?? null) || !dh_preview_is_list($client['unverified_items'])
            || !self::validDateTime($client['generated_at'] ?? null)) {
            throw new DhPreviewBundleException('Client report contract is invalid.');
        }
        if (isset($client['pricing']) && (!is_array($client['pricing']) || dh_preview_is_list($client['pricing']))) {
            throw new DhPreviewBundleException('Client report pricing contract is invalid.');
        }
        self::rejectForbiddenClientFields($client);

        $linkedIds = [];
        self::collectClientMedia($client, $linkedIds);
        $linkedIds = array_values(array_unique($linkedIds));
        sort($linkedIds);
        $manifestLinked = [];
        foreach ($mediaById as $id => $media) {
            if ($media['placement_kind'] === 'linked_evidence') {
                $manifestLinked[] = $id;
            }
        }
        sort($manifestLinked);
        if ($linkedIds !== $manifestLinked) {
            throw new DhPreviewBundleException('Client report linked media do not match the manifest records.');
        }
        foreach ($client['issues'] as $issue) {
            if (!is_array($issue) || dh_preview_is_list($issue) || !self::validId($issue['id'] ?? null, 'issue')
                || !is_array($issue['impacts'] ?? null) || count($issue['impacts']) !== 7) {
                throw new DhPreviewBundleException('Client report issue contract is invalid.');
            }
        }
    }

    private static function rejectForbiddenClientFields($value): void
    {
        $forbidden = array_fill_keys([
            'qa', 'actors', 'actor_ids', 'approved_by', 'observed_by', 'captured_by', 'performed_by',
            'provenance', 'import_metadata', 'source_system', 'source_inspection_id', 'source_item_id',
            'source_media_id', 'source_reference', 'source_hash', 'pin', 'pin_hash', 'csrf_token',
            'session_id', 'report_id', 'report_version_id', 'package_manifest_sha256', 'media_reference',
            'sha256', 'address_private', 'storage_path', 'filesystem_path', 'internal_tariff',
            'internal_labour_cost', 'equipment_acquisition_cost', 'travel_costing', 'margin', 'markup',
            'internal_business_notes', 'private_supplier_negotiations',
        ], true);
        $walk = function ($node) use (&$walk, $forbidden): void {
            if (!is_array($node)) {
                if (is_string($node) && dh_preview_contains($node, "\0")) {
                    throw new DhPreviewBundleException('Client report contains an invalid string.');
                }
                return;
            }
            foreach ($node as $key => $child) {
                if (is_string($key) && isset($forbidden[$key])) {
                    throw new DhPreviewBundleException('Client report contains a forbidden internal field.');
                }
                $walk($child);
            }
        };
        $walk($value);
    }

    private static function collectClientMedia($value, array &$ids): void
    {
        if (!is_array($value)) {
            return;
        }
        if (!dh_preview_is_list($value) && array_key_exists('has_media', $value)) {
            if (!is_bool($value['has_media'])) {
                throw new DhPreviewBundleException('Client report media flag is invalid.');
            }
            if ($value['has_media'] === true) {
                $url = $value['media_url'] ?? null;
                if (!is_string($url) || preg_match('/^api\/diagnostics-media\.php\?evidence=(ev_[0-9a-f]{16,32})$/D', $url, $match) !== 1
                    || !is_string($value['content_type'] ?? null) || !dh_preview_starts_with($value['content_type'], 'image/')) {
                    throw new DhPreviewBundleException('Client report media URL is invalid.');
                }
                $ids[] = $match[1];
            } elseif (array_key_exists('media_url', $value)) {
                throw new DhPreviewBundleException('Client report exposes media for a no-media evidence record.');
            }
        }
        foreach ($value as $child) {
            self::collectClientMedia($child, $ids);
        }
    }

    private static function validateAppendix(array $appendix, array $mediaById, array $manifest): void
    {
        self::assertExactKeys($appendix, [
            'schema_version', 'document_type', 'title', 'intro', 'report_id', 'report_version_id',
            'inspection_id', 'generated_at', 'photo_count', 'items',
        ]);
        if (($appendix['document_type'] ?? '') !== 'source_documentation_appendix'
            || ($appendix['report_id'] ?? '') !== $manifest['report_id']
            || ($appendix['report_version_id'] ?? '') !== $manifest['report_version_id']
            || ($appendix['inspection_id'] ?? '') !== $manifest['inspection_id']
            || !is_array($appendix['items'] ?? null) || !dh_preview_is_list($appendix['items'])
            || !is_int($appendix['photo_count'] ?? null)
            || $appendix['photo_count'] !== count($appendix['items'])) {
            throw new DhPreviewBundleException('Source documentation appendix contract is invalid.');
        }
        $analyticKeys = array_fill_keys([
            'issue_id', 'issue_ids', 'approved_issue_ids', 'observation_id', 'observation_ids',
            'approved_observation_ids', 'hypothesis_id', 'hypothesis_ids', 'recommendation_id',
            'verification_id', 'impact_id', 'diagnostic_link', 'analytic_link',
        ], true);
        $appendixIds = [];
        foreach ($appendix['items'] as $item) {
            if (!is_array($item) || dh_preview_is_list($item)) {
                throw new DhPreviewBundleException('Source documentation item is invalid.');
            }
            self::assertExactKeys($item, [
                'evidence_id', 'display_code', 'source_caption', 'media_reference', 'media_url', 'content_type',
                'source_identity', 'order',
            ], ['source_section', 'source_location', 'source_pdf_page', 'provenance_source_page']);
            foreach (['source_pdf_page', 'provenance_source_page'] as $pageField) {
                if (array_key_exists($pageField, $item)
                    && (!is_int($item[$pageField]) || $item[$pageField] < 1)) {
                    throw new DhPreviewBundleException('Source documentation page reference is invalid.');
                }
            }
            foreach (array_keys($item) as $key) {
                if (isset($analyticKeys[$key])) {
                    throw new DhPreviewBundleException('Source documentation must not contain analytic links.');
                }
            }
            $id = (string)($item['evidence_id'] ?? '');
            if (!isset($mediaById[$id]) || $mediaById[$id]['placement_kind'] !== 'source_documentation_appendix'
                || ($item['media_reference'] ?? '') !== $mediaById[$id]['path']
                || ($item['content_type'] ?? '') !== $mediaById[$id]['content_type']
                || ($item['media_url'] ?? '') !== 'api/diagnostics-media.php?evidence=' . $id
                || isset($appendixIds[$id])) {
                throw new DhPreviewBundleException('Source documentation media mapping is invalid.');
            }
            $appendixIds[$id] = true;
        }
        $manifestAppendixIds = [];
        foreach ($mediaById as $id => $media) {
            if ($media['placement_kind'] === 'source_documentation_appendix') {
                $manifestAppendixIds[] = $id;
            }
        }
        sort($manifestAppendixIds);
        $validatedAppendixIds = array_keys($appendixIds);
        sort($validatedAppendixIds);
        if ($validatedAppendixIds !== $manifestAppendixIds) {
            throw new DhPreviewBundleException('Source documentation media do not match the manifest records.');
        }
    }

    private static function validateMissingVideos($items): void
    {
        if (!is_array($items) || !dh_preview_is_list($items)) {
            throw new DhPreviewBundleException('Pending media must be a list.');
        }
        foreach ($items as $item) {
            if (!is_array($item) || dh_preview_is_list($item)) {
                throw new DhPreviewBundleException('Pending video descriptor is invalid.');
            }
            self::assertExactKeys($item, ['evidence_id', 'display_code', 'media_kind', 'source_reference', 'status']);
            if (!self::validId($item['evidence_id'] ?? null, 'ev') || ($item['media_kind'] ?? '') !== 'video'
                || ($item['status'] ?? '') !== 'VIDEO_SOURCE_REQUIRED') {
                throw new DhPreviewBundleException('Pending video descriptor is invalid.');
            }
            foreach ($item as $key => $value) {
                if (is_string($key) && dh_preview_contains(strtolower($key), 'url')) {
                    throw new DhPreviewBundleException('Pending video URL must not be fabricated.');
                }
            }
        }
    }

    private static function validId($value, string $prefix): bool
    {
        return is_string($value) && preg_match('/^' . preg_quote($prefix, '/') . '_[0-9a-f]{16,32}$/D', $value) === 1;
    }

    private static function validDateTime($value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            return true;
        } catch (Exception $error) {
            return false;
        }
    }

    private static function extractValidated(ZipArchive $zip, array $entries, array $fileHashes, string $destination): void
    {
        foreach ($fileHashes as $name => $expectedHash) {
            $entry = $entries[$name] ?? null;
            if (!is_array($entry) || $entry['directory']) {
                throw new DhPreviewBundleException('Declared bundle file is missing.');
            }
            $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
            $parent = dirname($target);
            dh_preview_ensure_private_directory($parent);
            $source = $zip->getStream($name);
            $output = fopen($target, 'xb');
            if ($source === false || $output === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new DhPreviewBundleException('Bundle file extraction failed.');
            }
            $hash = hash_init('sha256');
            $written = 0;
            try {
                while (!feof($source)) {
                    $chunk = fread($source, 65536);
                    if ($chunk === false) {
                        throw new DhPreviewBundleException('Bundle file extraction failed.');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $written += strlen($chunk);
                    if ($written > $entry['size'] || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new DhPreviewBundleException('Bundle file extraction failed.');
                    }
                    hash_update($hash, $chunk);
                }
                fflush($output);
            } finally {
                fclose($source);
                fclose($output);
            }
            if ($written !== $entry['size'] || !hash_equals($expectedHash, hash_final($hash))) {
                throw new DhPreviewBundleException('Bundle file hash verification failed.');
            }
            @chmod($target, 0600);
            if (dh_preview_starts_with($name, 'media/')) {
                if (!function_exists('finfo_open')) {
                    throw new DhPreviewBundleException('Media type verification is unavailable.');
                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected = $finfo !== false ? finfo_file($finfo, $target) : false;
                if ($finfo !== false) {
                    finfo_close($finfo);
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $expectedTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                if (!is_string($detected) || $detected !== ($expectedTypes[$extension] ?? '')) {
                    throw new DhPreviewBundleException('Media content does not match its declared type.');
                }
            }
        }
    }

    private static function writeMeta(string $path, array $meta): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $handle = fopen($path, 'xb');
        if ($handle === false || fwrite($handle, $json) !== strlen($json)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new DhPreviewBundleException('Preview metadata could not be written.');
        }
        fflush($handle);
        fclose($handle);
        @chmod($path, 0600);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
