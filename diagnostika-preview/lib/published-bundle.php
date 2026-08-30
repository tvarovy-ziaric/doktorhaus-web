<?php
declare(strict_types=1);

require_once __DIR__ . '/preview-runtime.php';
require_once dh_preview_site_root() . '/api/lib/diagnostics/DiagnosticsStorage.php';

use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

final class DhPublishedBundleException extends RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

/** Owner-only installer for a pre-approved immutable diagnostics package. */
final class DhPublishedBundleInstaller
{
    private const MAX_ENTRIES = 128;
    private const MAX_UNCOMPRESSED_BYTES = 128 * 1024 * 1024;
    private const MAX_ENTRY_BYTES = 16 * 1024 * 1024;

    /** @return array{reportId:string,version:string,inspectionId:string,alreadyInstalled:bool} */
    public static function install(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new DhPublishedBundleException('ZIP_UNAVAILABLE', 'ZIP support is unavailable.');
        }
        if (!is_file($zipPath) || is_link($zipPath)) {
            throw new DhPublishedBundleException('UPLOAD_MISSING', 'Uploaded package is unavailable.');
        }
        $zipSize = filesize($zipPath);
        if (!is_int($zipSize) || $zipSize <= 0 || $zipSize > DH_PREVIEW_MAX_ZIP_BYTES) {
            throw new DhPublishedBundleException('UPLOAD_SIZE', 'Uploaded package exceeds the allowed size.');
        }

        $config = dh_preview_config();
        $stagingRoot = $config['storage_root'] . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'owner-publish-uploads';
        dh_preview_ensure_private_directory($stagingRoot);
        $stagingDirectory = $stagingRoot . DIRECTORY_SEPARATOR . '.package-' . bin2hex(random_bytes(16));
        dh_preview_ensure_private_directory($stagingDirectory);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            self::removeTree($stagingDirectory);
            throw new DhPublishedBundleException('ZIP_INVALID', 'Uploaded file is not a valid ZIP archive.');
        }

        try {
            self::extractSafely($zip, $stagingDirectory);
            $storage = new DiagnosticsStorage($config['storage_root'], dh_preview_site_root());
            $manifestPath = $stagingDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifestSha256 = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
            if (!is_string($manifestSha256)) {
                throw new DhPublishedBundleException('MANIFEST_MISSING', 'Published manifest is missing.');
            }

            try {
                $installed = $storage->installPublishedPackage($stagingDirectory);
                $manifest = $storage->loadPublishedManifest($installed['report_id'], $installed['version']);
                $result = [
                    'reportId' => $installed['report_id'],
                    'version' => $installed['version'],
                    'inspectionId' => (string)$manifest['report']['inspection_id'],
                    'alreadyInstalled' => false,
                ];
                self::audit($config['storage_root'], 'published_package_installed', $result);
                return $result;
            } catch (DiagnosticsStorageException $error) {
                if ($error->getStorageCode() === 'STORAGE_ALREADY_EXISTS') {
                    $sourceManifest = self::readManifestIdentity($manifestPath);
                    $existingSha256 = $storage->getPublishedManifestSha256($sourceManifest['reportId'], $sourceManifest['version']);
                    if (hash_equals($existingSha256, $manifestSha256)) {
                        $result = $sourceManifest + ['alreadyInstalled' => true];
                        self::audit($config['storage_root'], 'published_package_idempotent', $result);
                        return $result;
                    }
                }
                self::audit($config['storage_root'], 'published_package_rejected', ['code' => $error->getStorageCode()]);
                throw new DhPublishedBundleException($error->getStorageCode(), 'Published package verification or installation failed.');
            }
        } finally {
            $zip->close();
            self::removeTree($stagingDirectory);
        }
    }

    private static function extractSafely(ZipArchive $zip, string $targetRoot): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new DhPublishedBundleException('ZIP_ENTRY_COUNT', 'ZIP entry count is not allowed.');
        }
        $seen = [];
        $totalBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
                throw new DhPublishedBundleException('ZIP_ENTRY_INVALID', 'ZIP entry metadata is invalid.');
            }
            $name = (string)$stat['name'];
            $isDirectory = dh_preview_ends_with($name, '/');
            $relative = $isDirectory ? rtrim($name, '/') : $name;
            self::assertSafeRelativePath($relative);
            $folded = strtolower($relative);
            if (isset($seen[$folded])) {
                throw new DhPublishedBundleException('ZIP_DUPLICATE_PATH', 'ZIP contains duplicate paths.');
            }
            $seen[$folded] = true;
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $operations = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
                    && ((($attributes >> 16) & 0xF000) === 0xA000)) {
                    throw new DhPublishedBundleException('ZIP_SYMLINK', 'ZIP symlinks are not allowed.');
                }
            }
            $size = (int)$stat['size'];
            if ($size < 0 || $size > self::MAX_ENTRY_BYTES || (!$isDirectory && $size === 0)) {
                throw new DhPublishedBundleException('ZIP_ENTRY_SIZE', 'ZIP entry size is not allowed.');
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new DhPublishedBundleException('ZIP_EXPANDED_SIZE', 'ZIP uncompressed size exceeds the allowed limit.');
            }

            $target = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($isDirectory) {
                dh_preview_ensure_private_directory($target);
                continue;
            }
            dh_preview_ensure_private_directory(dirname($target));
            $input = $zip->getStream($name);
            $output = @fopen($target, 'xb');
            if (!is_resource($input) || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new DhPublishedBundleException('ZIP_EXTRACT', 'ZIP entry could not be extracted.');
            }
            $written = stream_copy_to_stream($input, $output, self::MAX_ENTRY_BYTES + 1);
            $flushed = fflush($output);
            fclose($input);
            fclose($output);
            if (!is_int($written) || $written !== $size || !$flushed) {
                throw new DhPublishedBundleException('ZIP_EXTRACT', 'ZIP entry extraction was incomplete.');
            }
            @chmod($target, 0600);
        }
    }

    private static function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || strlen($path) > 1024 || strpos($path, "\0") !== false || strpos($path, '\\') !== false
            || $path[0] === '/' || preg_match('/^[A-Za-z]:/D', $path) === 1
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $path) === 1
            || preg_match('/^[A-Za-z0-9._\/-]+$/D', $path) !== 1) {
            throw new DhPublishedBundleException('ZIP_PATH', 'ZIP contains an unsafe path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new DhPublishedBundleException('ZIP_PATH', 'ZIP contains an unsafe path.');
            }
        }
    }

    /** @return array{reportId:string,version:string,inspectionId:string} */
    private static function readManifestIdentity(string $path): array
    {
        try {
            $manifest = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DhPublishedBundleException('MANIFEST_INVALID', 'Published manifest is invalid.');
        }
        $reportId = is_array($manifest) ? ($manifest['report']['id'] ?? null) : null;
        $version = is_array($manifest) ? ($manifest['report_version']['version'] ?? null) : null;
        $inspectionId = is_array($manifest) ? ($manifest['report']['inspection_id'] ?? null) : null;
        if (!is_string($reportId) || !is_string($version) || !is_string($inspectionId)) {
            throw new DhPublishedBundleException('MANIFEST_IDENTITY', 'Published manifest identity is invalid.');
        }
        return ['reportId' => $reportId, 'version' => $version, 'inspectionId' => $inspectionId];
    }

    private static function audit(string $storageRoot, string $event, array $context): void
    {
        try {
            $auditRoot = $storageRoot . DIRECTORY_SEPARATOR . 'audit';
            dh_preview_ensure_private_directory($auditRoot);
            $path = $auditRoot . DIRECTORY_SEPARATOR . 'owner-publish.ndjson';
            $line = json_encode([
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'event' => $event,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $handle = @fopen($path, 'ab');
            if ($handle !== false) {
                @flock($handle, LOCK_EX);
                @fwrite($handle, $line);
                @fflush($handle);
                @flock($handle, LOCK_UN);
                fclose($handle);
                @chmod($path, 0600);
            }
        } catch (Throwable $ignored) {
            // The publish result remains authoritative; audit failure is not exposed to the browser.
        }
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_link($path))) {
            return;
        }
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if (is_array($items)) {
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
        }
        @rmdir($path);
    }
}
