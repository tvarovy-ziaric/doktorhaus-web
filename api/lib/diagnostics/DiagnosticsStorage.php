<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsStorageException.php';
require_once __DIR__ . '/DiagnosticsPackageVerifier.php';

final class DiagnosticsStorage
{
    /** @var string */
    private $root;

    /** @var DiagnosticsPackageVerifier */
    private $verifier;

    public function __construct(string $root, ?string $documentRoot = null)
    {
        $root = trim($root);
        if ($root === '') {
            throw new DiagnosticsStorageException('STORAGE_CONFIG', 'The diagnostics storage root is not configured.');
        }
        if (!$this->isAbsolutePath($root)) {
            throw new DiagnosticsStorageException('STORAGE_CONFIG', 'The diagnostics storage root must be an absolute path.');
        }

        $potentialRoot = $this->resolvePotentialPath($root);
        if ($this->isFilesystemRoot($potentialRoot)) {
            throw new DiagnosticsStorageException('STORAGE_UNSAFE_ROOT', 'The diagnostics storage root is too broad.');
        }

        if ($documentRoot === null && isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
            $documentRoot = $_SERVER['DOCUMENT_ROOT'];
        }
        if (is_string($documentRoot) && trim($documentRoot) !== '') {
            $potentialDocumentRoot = $this->resolvePotentialPath($documentRoot);
            if ($this->isPathWithin($potentialDocumentRoot, $potentialRoot)) {
                throw new DiagnosticsStorageException('STORAGE_UNSAFE_ROOT', 'The diagnostics storage root must be outside the web root.');
            }
        }

        if (is_link($root)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The diagnostics storage root cannot be a symbolic link.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The diagnostics storage root cannot be created.');
        }
        if (!is_dir($root)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The diagnostics storage root is not a directory.');
        }

        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The diagnostics storage root cannot be resolved.');
        }
        if (is_string($documentRoot) && trim($documentRoot) !== '') {
            $canonicalDocumentRoot = $this->resolvePotentialPath($documentRoot);
            if ($this->isPathWithin($canonicalDocumentRoot, $canonicalRoot)) {
                throw new DiagnosticsStorageException('STORAGE_UNSAFE_ROOT', 'The diagnostics storage root must be outside the web root.');
            }
        }

        $this->root = rtrim($canonicalRoot, "/\\");
        @chmod($this->root, 0700);
        foreach (['drafts', 'reports', 'locks', 'tmp'] as $directory) {
            $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . $directory, 0700);
        }
        $this->verifier = new DiagnosticsPackageVerifier();
    }

    public static function fromEnvironment(): self
    {
        $root = getenv('DIAGNOSTICS_STORAGE_ROOT');
        $root = is_string($root) ? trim($root) : '';
        if ($root === '') {
            $configFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'diagnostics.config.php';
            if (is_file($configFile)) {
                $config = require $configFile;
                if (!is_array($config)) {
                    throw new DiagnosticsStorageException('STORAGE_CONFIG', 'The diagnostics storage configuration is invalid.');
                }
                $configuredRoot = $config['storage_root'] ?? '';
                $root = is_string($configuredRoot) ? trim($configuredRoot) : '';
            }
        }
        if ($root === '') {
            throw new DiagnosticsStorageException('STORAGE_CONFIG', 'The diagnostics storage root is not configured.');
        }
        return new self($root);
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function saveDraftInspection(array $document, ?int $expectedRevision = null): array
    {
        $inspectionId = $this->validateInspectionDocument($document, null);
        return $this->saveDraftDocument($inspectionId, 'inspection.json', $document, $expectedRevision);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function saveDraftDiagnosis(array $document, ?int $expectedRevision = null): array
    {
        $inspectionId = $this->validateDiagnosisDocument($document, null);
        return $this->saveDraftDocument($inspectionId, 'diagnosis.json', $document, $expectedRevision);
    }

    /** @return array<string, mixed> */
    public function loadDraftInspection(string $inspectionId): array
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        return $this->withLock('draft-' . $inspectionId, function () use ($inspectionId): array {
            $document = $this->readDraftDocument($inspectionId, 'inspection.json');
            $this->validateInspectionDocument($document, $inspectionId);
            return $document;
        });
    }

    /** @return array<string, mixed> */
    public function loadDraftDiagnosis(string $inspectionId): array
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        return $this->withLock('draft-' . $inspectionId, function () use ($inspectionId): array {
            $document = $this->readDraftDocument($inspectionId, 'diagnosis.json');
            $this->validateDiagnosisDocument($document, $inspectionId);
            return $document;
        });
    }

    /** @return array<string, mixed> */
    public function loadDraftMeta(string $inspectionId): array
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        return $this->withLock('draft-' . $inspectionId, function () use ($inspectionId): array {
            $draftDirectory = $this->draftDirectory($inspectionId, false);
            $this->assertNoPendingDraftWrite($draftDirectory);
            $path = $draftDirectory . DIRECTORY_SEPARATOR . 'draft-meta.json';
            if (is_link($path)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft metadata cannot be a symbolic link.');
            }
            if (!is_file($path)) {
                throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'The draft metadata is missing.');
            }
            return $this->readJsonObject($path);
        });
    }

    public function draftExists(string $inspectionId): bool
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        return $this->withLock('draft-' . $inspectionId, function () use ($inspectionId): bool {
            $draftsRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'drafts', 0700);
            $directory = $draftsRoot . DIRECTORY_SEPARATOR . $inspectionId;
            if (is_link($directory)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft directory cannot be a symbolic link.');
            }
            $inspectionPath = $directory . DIRECTORY_SEPARATOR . 'inspection.json';
            $diagnosisPath = $directory . DIRECTORY_SEPARATOR . 'diagnosis.json';
            if (is_link($inspectionPath) || is_link($diagnosisPath)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A draft document cannot be a symbolic link.');
            }
            if (is_dir($directory)) {
                $this->assertNoPendingDraftWrite($directory);
            }
            return is_file($inspectionPath) || is_file($diagnosisPath);
        });
    }

    public function deleteDraft(string $inspectionId): bool
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        return $this->withLock('draft-' . $inspectionId, function () use ($inspectionId): bool {
            $draftsRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'drafts', 0700);
            $directory = $draftsRoot . DIRECTORY_SEPARATOR . $inspectionId;
            if (is_link($directory)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft directory cannot be a symbolic link.');
            }
            if (!is_dir($directory)) {
                return false;
            }
            $this->removeTree($directory);
            return true;
        });
    }

    /**
     * Install a pre-approved, published package as a new immutable version.
     *
     * @return array{report_id: string, version: string, path: string}
     */
    public function installPublishedPackage(string $sourceDirectory): array
    {
        $verified = $this->verifier->verifyPackage($sourceDirectory);
        $reportId = (string)$verified['manifest']['report']['id'];
        $version = (string)$verified['manifest']['report_version']['version'];
        DiagnosticsPackageVerifier::assertReportId($reportId);
        DiagnosticsPackageVerifier::assertVersion($version);

        return $this->withLock('publish-' . $reportId . '-' . str_replace('.', '-', $version), function () use (
            $sourceDirectory,
            $verified,
            $reportId,
            $version
        ): array {
            $reportsRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'reports', 0700);
            $reportDirectory = $this->ensureDirectory($reportsRoot . DIRECTORY_SEPARATOR . $reportId, 0750);
            $finalDirectory = $reportDirectory . DIRECTORY_SEPARATOR . $version;
            if (file_exists($finalDirectory) || is_link($finalDirectory)) {
                throw new DiagnosticsStorageException('STORAGE_ALREADY_EXISTS', 'This published report version already exists.');
            }

            $stagingDirectory = $reportDirectory . DIRECTORY_SEPARATOR . '.package-' . bin2hex(random_bytes(16));
            if (!@mkdir($stagingDirectory, 0700)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A package staging directory cannot be created.');
            }

            try {
                $sourceRoot = realpath($sourceDirectory);
                if ($sourceRoot === false) {
                    throw new DiagnosticsStorageException('STORAGE_IO', 'The source package cannot be resolved.');
                }
                $this->copyFileSafely($sourceRoot, 'manifest.json', $stagingDirectory);
                foreach ($verified['files'] as $relativePath => $_entry) {
                    $this->copyFileSafely($sourceRoot, $relativePath, $stagingDirectory);
                }

                $staged = $this->verifier->verifyPackage($stagingDirectory);
                if ($staged['manifest']['report']['id'] !== $reportId ||
                    $staged['manifest']['report_version']['version'] !== $version) {
                    throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The staged package identity changed.');
                }
                $this->makeTreeReadOnly($stagingDirectory);

                clearstatcache(true, $finalDirectory);
                if (file_exists($finalDirectory) || is_link($finalDirectory)) {
                    throw new DiagnosticsStorageException('STORAGE_ALREADY_EXISTS', 'This published report version already exists.');
                }
                $stageStat = @stat($stagingDirectory);
                $destinationStat = @stat($reportDirectory);
                if (is_array($stageStat) && is_array($destinationStat) &&
                    isset($stageStat['dev'], $destinationStat['dev']) && $stageStat['dev'] !== $destinationStat['dev']) {
                    throw new DiagnosticsStorageException('STORAGE_IO', 'The staging and report directories must share a filesystem.');
                }
                if (!@rename($stagingDirectory, $finalDirectory)) {
                    throw new DiagnosticsStorageException('STORAGE_IO', 'The report package cannot be installed atomically.');
                }
                $stagingDirectory = '';

                return [
                    'report_id' => $reportId,
                    'version' => $version,
                    'path' => $finalDirectory,
                ];
            } catch (Throwable $error) {
                if ($stagingDirectory !== '' && (is_dir($stagingDirectory) || is_link($stagingDirectory))) {
                    $this->removeTree($stagingDirectory);
                }
                if ($error instanceof DiagnosticsStorageException) {
                    throw $error;
                }
                throw new DiagnosticsStorageException('STORAGE_IO', 'The report package installation failed.', $error);
            }
        });
    }

    /** @return array<string, mixed> */
    public function loadPublishedManifest(string $reportId, string $version): array
    {
        return $this->verifyPublishedPackage($reportId, $version)['manifest'];
    }

    public function getPublishedManifestSha256(string $reportId, string $version): string
    {
        return $this->loadPublishedManifestBinding($reportId, $version)['sha256'];
    }

    /**
     * @return array{
     *   manifest: array<string, mixed>,
     *   sha256: string,
     *   package: array{manifest: array<string, mixed>, files: array<string, array<string, mixed>>, inspection: array<string, mixed>, diagnosis: array<string, mixed>}
     * }
     */
    public function loadPublishedManifestBinding(string $reportId, string $version): array
    {
        $manifestPath = $this->publishedDirectory($reportId, $version) . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_link($manifestPath) || !is_file($manifestPath)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The published manifest path is unsafe.');
        }
        $hashBefore = hash_file('sha256', $manifestPath);
        $verified = $this->verifyPublishedPackage($reportId, $version);
        $hashAfter = hash_file('sha256', $manifestPath);
        if (!is_string($hashBefore) || !is_string($hashAfter) ||
            preg_match('/^[0-9a-f]{64}$/D', $hashAfter) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The published manifest cannot be hashed.');
        }
        if (!hash_equals($hashBefore, $hashAfter)) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The published manifest changed during verification.');
        }
        return ['manifest' => $verified['manifest'], 'sha256' => $hashAfter, 'package' => $verified];
    }

    /** @return array<string, mixed> */
    public function loadPublishedInspection(string $reportId, string $version): array
    {
        return $this->verifyPublishedPackage($reportId, $version)['inspection'];
    }

    /** @return array<string, mixed> */
    public function loadPublishedDiagnosis(string $reportId, string $version): array
    {
        return $this->verifyPublishedPackage($reportId, $version)['diagnosis'];
    }

    /**
     * Resolve a declared package file for an already-authorized internal caller.
     *
     * @return array{path: string, role: string, sha256: string, content_type: string, privacy: string, size_bytes?: int}
     */
    public function resolvePublishedFile(string $reportId, string $version, string $relativePath): array
    {
        DiagnosticsPackageVerifier::assertSafeRelativePath($relativePath);
        $verified = $this->verifyPublishedPackage($reportId, $version);
        return $this->resolveVerifiedPublishedFile($reportId, $version, $relativePath, $verified);
    }

    /**
     * Resolve from a package snapshot that was fully verified in this request.
     * This avoids hashing every large package file a second time after session binding.
     *
     * @param array{manifest: array<string, mixed>, files: array<string, array<string, mixed>>, inspection: array<string, mixed>, diagnosis: array<string, mixed>} $verified
     * @return array{path: string, role: string, sha256: string, content_type: string, privacy: string, size_bytes?: int}
     */
    public function resolveVerifiedPublishedFile(
        string $reportId,
        string $version,
        string $relativePath,
        array $verified
    ): array {
        DiagnosticsPackageVerifier::assertReportId($reportId);
        DiagnosticsPackageVerifier::assertVersion($version);
        DiagnosticsPackageVerifier::assertSafeRelativePath($relativePath);
        if (($verified['manifest']['report']['id'] ?? null) !== $reportId ||
            ($verified['manifest']['report_version']['version'] ?? null) !== $version ||
            !isset($verified['files']) || !is_array($verified['files'])) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The verified package snapshot identity is invalid.');
        }
        if (!isset($verified['files'][$relativePath])) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The requested report file is not declared by the manifest.');
        }
        $entry = $verified['files'][$relativePath];
        if (!is_array($entry) || !isset($entry['role'], $entry['sha256'], $entry['content_type'], $entry['privacy']) ||
            !is_string($entry['role']) || !is_string($entry['sha256']) ||
            !is_string($entry['content_type']) || !is_string($entry['privacy'])) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The verified package snapshot entry is invalid.');
        }
        $packageDirectory = $this->publishedDirectory($reportId, $version);
        $absolute = $packageDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertNoSymlinkComponents($packageDirectory, $relativePath);
        $canonical = realpath($absolute);
        if ($canonical === false || !$this->isPathWithin($packageDirectory, $canonical) || !is_file($canonical)) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The requested report file cannot be resolved safely.');
        }
        $result = [
            'path' => $canonical,
            'role' => (string)$entry['role'],
            'sha256' => (string)$entry['sha256'],
            'content_type' => (string)$entry['content_type'],
            'privacy' => (string)$entry['privacy'],
        ];
        if (isset($entry['size_bytes']) && is_int($entry['size_bytes'])) {
            $result['size_bytes'] = $entry['size_bytes'];
        }
        return $result;
    }

    /** @return array<int, string> */
    public function listPublishedVersions(string $reportId): array
    {
        DiagnosticsPackageVerifier::assertReportId($reportId);
        $reportsRoot = $this->root . DIRECTORY_SEPARATOR . 'reports';
        if (is_link($reportsRoot)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The reports directory cannot be a symbolic link.');
        }
        $reportDirectory = $reportsRoot . DIRECTORY_SEPARATOR . $reportId;
        if (is_link($reportDirectory)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The report directory cannot be a symbolic link.');
        }
        if (!is_dir($reportDirectory)) {
            return [];
        }
        $names = scandir($reportDirectory);
        if ($names === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The report directory cannot be read.');
        }
        $versions = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $directory = $reportDirectory . DIRECTORY_SEPARATOR . $name;
            if (is_link($directory)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The report directory contains a symbolic link.');
            }
            try {
                DiagnosticsPackageVerifier::assertVersion($name);
            } catch (DiagnosticsStorageException $error) {
                continue;
            }
            if (is_dir($directory) && is_file($directory . DIRECTORY_SEPARATOR . 'manifest.json')) {
                try {
                    $verified = $this->verifier->verifyPackage($directory);
                    if ($verified['manifest']['report']['id'] === $reportId &&
                        $verified['manifest']['report_version']['version'] === $name) {
                        $versions[] = $name;
                    }
                } catch (DiagnosticsStorageException $error) {
                    continue;
                }
            }
        }
        usort($versions, 'version_compare');
        return $versions;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function saveDraftDocument(
        string $inspectionId,
        string $fileName,
        array $document,
        ?int $expectedRevision
    ): array {
        if ($expectedRevision !== null && $expectedRevision < 0) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The expected draft revision is invalid.');
        }

        return $this->withLock('draft-' . $inspectionId, function () use (
            $inspectionId,
            $fileName,
            $document,
            $expectedRevision
        ): array {
            $draftDirectory = $this->draftDirectory($inspectionId, true);
            $metaPath = $draftDirectory . DIRECTORY_SEPARATOR . 'draft-meta.json';
            $pendingPath = $draftDirectory . DIRECTORY_SEPARATOR . '.draft-write-pending.json';
            $this->assertNoPendingDraftWrite($draftDirectory);
            $currentRevision = 0;
            if (is_link($metaPath)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft metadata cannot be a symbolic link.');
            }
            if (is_file($metaPath)) {
                $meta = $this->readJsonObject($metaPath);
                if (($meta['inspection_id'] ?? null) !== $inspectionId ||
                    !isset($meta['storage_revision']) || !is_int($meta['storage_revision']) || $meta['storage_revision'] < 0) {
                    throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The draft metadata is inconsistent.');
                }
                $currentRevision = $meta['storage_revision'];
            } elseif (is_file($draftDirectory . DIRECTORY_SEPARATOR . 'inspection.json') ||
                is_file($draftDirectory . DIRECTORY_SEPARATOR . 'diagnosis.json')) {
                throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'Draft documents exist without revision metadata.');
            }
            if ($currentRevision > 0 && $expectedRevision === null) {
                throw new DiagnosticsStorageException('STORAGE_REVISION_CONFLICT', 'An existing draft requires its current revision.');
            }
            if ($expectedRevision !== null && $expectedRevision !== $currentRevision) {
                throw new DiagnosticsStorageException('STORAGE_REVISION_CONFLICT', 'The draft was changed by another writer.');
            }

            $meta = [
                'inspection_id' => $inspectionId,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'storage_revision' => $currentRevision + 1,
            ];
            $this->atomicWriteJson($pendingPath, [
                'inspection_id' => $inspectionId,
                'target' => $fileName,
                'from_revision' => $currentRevision,
                'to_revision' => $meta['storage_revision'],
            ]);
            $documentCommitted = false;
            try {
                $this->atomicWriteJson($draftDirectory . DIRECTORY_SEPARATOR . $fileName, $document);
                $documentCommitted = true;
                $this->atomicWriteJson($metaPath, $meta);
                if (!@unlink($pendingPath)) {
                    throw new DiagnosticsStorageException('STORAGE_IO', 'The draft write marker cannot be removed.');
                }
                return $meta;
            } catch (Throwable $error) {
                if (!$documentCommitted) {
                    @unlink($pendingPath);
                }
                throw $error;
            }
        });
    }

    /** @return array<string, mixed> */
    private function readDraftDocument(string $inspectionId, string $fileName): array
    {
        $directory = $this->draftDirectory($inspectionId, false);
        $this->assertNoPendingDraftWrite($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($path)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A draft document cannot be a symbolic link.');
        }
        if (!is_file($path)) {
            throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'The requested draft document is missing.');
        }
        return $this->readJsonObject($path);
    }

    private function draftDirectory(string $inspectionId, bool $create): string
    {
        DiagnosticsPackageVerifier::assertInspectionId($inspectionId);
        $draftsRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'drafts', 0700);
        $directory = $draftsRoot . DIRECTORY_SEPARATOR . $inspectionId;
        if (is_link($directory)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft directory cannot be a symbolic link.');
        }
        if ($create) {
            return $this->ensureDirectory($directory, 0700);
        }
        if (!is_dir($directory)) {
            throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'The requested draft is missing.');
        }
        return $directory;
    }

    private function assertNoPendingDraftWrite(string $draftDirectory): void
    {
        $pendingPath = $draftDirectory . DIRECTORY_SEPARATOR . '.draft-write-pending.json';
        if (is_link($pendingPath)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The draft write marker cannot be a symbolic link.');
        }
        if (file_exists($pendingPath)) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The draft contains an incomplete storage transaction.');
        }
    }

    /** @param array<string, mixed> $document */
    private function validateInspectionDocument(array $document, ?string $expectedId): string
    {
        if ($this->isList($document) || ($document['document_type'] ?? null) !== 'inspection' ||
            !isset($document['id']) || !is_string($document['id'])) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The inspection draft structure is invalid.');
        }
        DiagnosticsPackageVerifier::assertInspectionId($document['id']);
        if ($expectedId !== null && $document['id'] !== $expectedId) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The inspection draft identifier does not match.');
        }
        return $document['id'];
    }

    /** @param array<string, mixed> $document */
    private function validateDiagnosisDocument(array $document, ?string $expectedId): string
    {
        if ($this->isList($document) || ($document['document_type'] ?? null) !== 'diagnosis' ||
            !isset($document['id'], $document['inspection_id']) ||
            !is_string($document['id']) || !is_string($document['inspection_id'])) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The diagnosis draft structure is invalid.');
        }
        DiagnosticsPackageVerifier::assertInspectionId($document['id']);
        DiagnosticsPackageVerifier::assertInspectionId($document['inspection_id']);
        if ($document['id'] !== $document['inspection_id'] ||
            ($expectedId !== null && $document['id'] !== $expectedId)) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The diagnosis draft identifiers do not match.');
        }
        return $document['id'];
    }

    /** @param array<string, mixed> $data */
    private function atomicWriteJson(string $targetPath, array $data): void
    {
        $directory = dirname($targetPath);
        $this->ensureDirectory($directory, 0700);
        if (is_link($targetPath)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A storage file cannot be a symbolic link.');
        }
        if (file_exists($targetPath) && !is_file($targetPath)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A storage target is not a regular file.');
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new DiagnosticsStorageException('STORAGE_JSON', 'A JSON document cannot be serialized.');
        }
        $json .= "\n";
        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.write-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temporaryPath, 'x+b');
        if ($handle === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A temporary storage file cannot be created.');
        }

        try {
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new DiagnosticsStorageException('STORAGE_IO', 'A storage file cannot be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A storage file cannot be flushed.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A storage file cannot be synchronized.');
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temporaryPath);
            if ($error instanceof DiagnosticsStorageException) {
                throw $error;
            }
            throw new DiagnosticsStorageException('STORAGE_IO', 'A storage write failed.', $error);
        }
        fclose($handle);
        @chmod($temporaryPath, 0640);

        try {
            $this->readJsonObject($temporaryPath);
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }
        if (!@rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);
            throw new DiagnosticsStorageException('STORAGE_IO', 'A storage file cannot be replaced atomically.');
        }
        @chmod($targetPath, 0640);
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A JSON document cannot be read.');
        }
        if (substr(ltrim($raw), 0, 1) !== '{') {
            throw new DiagnosticsStorageException('STORAGE_JSON', 'A stored JSON document must contain an object.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || $this->isList($decoded)) {
            throw new DiagnosticsStorageException('STORAGE_JSON', 'A stored JSON document is invalid.');
        }
        return $decoded;
    }

    /** @return mixed */
    private function withLock(string $lockName, callable $callback)
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $lockName) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_LOCK', 'The storage lock name is invalid.');
        }
        $locksRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'locks', 0700);
        $lockPath = $locksRoot . DIRECTORY_SEPARATOR . $lockName . '.lock';
        if (is_link($lockPath)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A storage lock cannot be a symbolic link.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new DiagnosticsStorageException('STORAGE_LOCK', 'A storage lock cannot be opened.');
        }
        @chmod($lockPath, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new DiagnosticsStorageException('STORAGE_LOCK', 'A storage lock cannot be acquired.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, files: array<string, array<string, mixed>>, inspection: array<string, mixed>, diagnosis: array<string, mixed>}
     */
    private function verifyPublishedPackage(string $reportId, string $version): array
    {
        $directory = $this->publishedDirectory($reportId, $version);
        $verified = $this->verifier->verifyPackage($directory);
        if ($verified['manifest']['report']['id'] !== $reportId ||
            $verified['manifest']['report_version']['version'] !== $version) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The published package identity does not match its storage path.');
        }
        return $verified;
    }

    private function publishedDirectory(string $reportId, string $version): string
    {
        DiagnosticsPackageVerifier::assertReportId($reportId);
        DiagnosticsPackageVerifier::assertVersion($version);
        $reportsRoot = $this->root . DIRECTORY_SEPARATOR . 'reports';
        $reportDirectory = $reportsRoot . DIRECTORY_SEPARATOR . $reportId;
        $versionDirectory = $reportDirectory . DIRECTORY_SEPARATOR . $version;
        foreach ([$reportsRoot, $reportDirectory, $versionDirectory] as $directory) {
            if (is_link($directory)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A published report directory cannot be a symbolic link.');
            }
        }
        if (!is_dir($versionDirectory)) {
            throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'The published report version is missing.');
        }
        $canonical = realpath($versionDirectory);
        if ($canonical === false || !$this->isPathWithin($this->root, $canonical)) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The published report version cannot be resolved safely.');
        }
        return $canonical;
    }

    private function copyFileSafely(string $sourceRoot, string $relativePath, string $destinationRoot): void
    {
        DiagnosticsPackageVerifier::assertSafeRelativePath($relativePath);
        $this->assertNoSymlinkComponents($sourceRoot, $relativePath);
        $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A source package entry is not a safe regular file.');
        }

        $segments = explode('/', $relativePath);
        array_pop($segments);
        $destinationDirectory = $destinationRoot;
        foreach ($segments as $segment) {
            $destinationDirectory = $this->ensureDirectory($destinationDirectory . DIRECTORY_SEPARATOR . $segment, 0700);
        }
        $destinationPath = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (file_exists($destinationPath) || is_link($destinationPath)) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'A staging file already exists.');
        }

        $before = @lstat($sourcePath);
        $source = @fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A source package file cannot be opened.');
        }
        $opened = fstat($source);
        if (!is_array($before) || !is_array($opened) ||
            (isset($before['dev'], $before['ino'], $opened['dev'], $opened['ino']) &&
                ($before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']))) {
            fclose($source);
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A source package file changed during verification.');
        }

        $destination = @fopen($destinationPath, 'xb');
        if ($destination === false) {
            fclose($source);
            throw new DiagnosticsStorageException('STORAGE_IO', 'A staging file cannot be created.');
        }
        try {
            if (stream_copy_to_stream($source, $destination) === false || !fflush($destination)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A package file cannot be copied.');
            }
            if (function_exists('fsync') && !@fsync($destination)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A package file cannot be synchronized.');
            }
        } catch (Throwable $error) {
            fclose($source);
            fclose($destination);
            @unlink($destinationPath);
            if ($error instanceof DiagnosticsStorageException) {
                throw $error;
            }
            throw new DiagnosticsStorageException('STORAGE_IO', 'A package copy failed.', $error);
        }
        fclose($source);
        fclose($destination);
        @chmod($destinationPath, 0640);
    }

    private function assertNoSymlinkComponents(string $root, string $relativePath): void
    {
        $current = $root;
        foreach (explode('/', $relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A package path contains a symbolic link.');
            }
        }
    }

    private function makeTreeReadOnly(string $path): void
    {
        if (is_link($path)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A staging path cannot be a symbolic link.');
        }
        if (is_file($path)) {
            @chmod($path, 0440);
            return;
        }
        $names = scandir($path);
        if ($names === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A staging directory cannot be read.');
        }
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->makeTreeReadOnly($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        @chmod($path, 0550);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A temporary storage entry cannot be removed.');
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        $names = scandir($path);
        if ($names === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A temporary storage directory cannot be read.');
        }
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        if (!@rmdir($path)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A temporary storage directory cannot be removed.');
        }
    }

    private function ensureDirectory(string $path, int $mode): string
    {
        if (is_link($path)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'A storage directory cannot be a symbolic link.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A storage directory cannot be created.');
        }
        if (!is_dir($path)) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A storage path is not a directory.');
        }
        @chmod($path, $mode);
        return $path;
    }

    private function resolvePotentialPath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '' && DIRECTORY_SEPARATOR === '/') {
            return '/';
        }
        if (preg_match('/^[A-Za-z]:$/', $path) === 1) {
            $path .= DIRECTORY_SEPARATOR;
        }

        $remaining = [];
        $cursor = $path;
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            array_unshift($remaining, basename($cursor));
            $cursor = $parent;
        }
        $resolved = realpath($cursor);
        if ($resolved === false) {
            $resolved = $cursor;
        }
        foreach ($remaining as $segment) {
            $resolved = rtrim($resolved, "/\\") . DIRECTORY_SEPARATOR . $segment;
        }
        return rtrim($resolved, "/\\");
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ||
            strpos($path, '\\\\') === 0 || strpos($path, '//') === 0 || strpos($path, '/') === 0;
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        return $normalized === '' || $normalized === '/' ||
            preg_match('/^[A-Za-z]:\/?$/D', $normalized) === 1 ||
            preg_match('#^//[^/]+/[^/]+/?$#D', $normalized) === 1;
    }

    private function isPathWithin(string $root, string $path): bool
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
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
