<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsStorageException.php';

final class DiagnosticsPackageVerifier
{
    private const INSPECTION_ID_PATTERN = '/^insp_[0-9a-f]{16,32}$/D';
    private const REPORT_ID_PATTERN = '/^rpt_[0-9a-f]{16,32}$/D';
    private const REPORT_VERSION_ID_PATTERN = '/^rptv_[0-9a-f]{16,32}$/D';
    private const VERSION_PATTERN = '/^[1-9][0-9]*\.[0-9]+$/D';
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /**
     * Verify the complete package without loading binary files into memory.
     *
     * @return array{manifest: array<string, mixed>, files: array<string, array<string, mixed>>, inspection: array<string, mixed>, diagnosis: array<string, mixed>}
     */
    public function verifyPackage(string $packageDirectory): array
    {
        $packageDirectory = rtrim($packageDirectory, "/\\");
        if ($packageDirectory === '' || is_link($packageDirectory)) {
            throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The report package root is not a safe directory.');
        }
        if (!is_dir($packageDirectory)) {
            throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'The report package directory is missing.');
        }

        $packageRoot = realpath($packageDirectory);
        if ($packageRoot === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The report package directory cannot be resolved.');
        }

        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertPackageFile($packageRoot, 'manifest.json', $manifestPath);
        $manifest = $this->readJsonObject($manifestPath, 'STORAGE_MANIFEST');
        $entries = $this->validateManifest($manifest);

        $declaredPaths = [];
        $inspectionEntry = null;
        $diagnosisEntry = null;
        foreach ($entries as $relativePath => $entry) {
            $filePath = $this->pathFromRelative($packageRoot, $relativePath);
            $this->assertPackageFile($packageRoot, $relativePath, $filePath);
            $this->verifyFileIntegrity($filePath, $entry);
            $declaredPaths[$relativePath] = true;

            if ($entry['role'] === 'inspection_data') {
                $inspectionEntry = $entry;
            } elseif ($entry['role'] === 'diagnosis_data') {
                $diagnosisEntry = $entry;
            }
        }

        $actualPaths = [];
        $this->collectRegularFiles($packageRoot, '', $actualPaths);
        unset($actualPaths['manifest.json']);
        foreach ($actualPaths as $relativePath => $_present) {
            if (!isset($declaredPaths[$relativePath])) {
                throw new DiagnosticsStorageException('STORAGE_UNEXPECTED_FILE', 'The report package contains an undeclared file.');
            }
        }

        foreach ($declaredPaths as $relativePath => $_present) {
            if (!isset($actualPaths[$relativePath])) {
                throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'A declared report package file is missing.');
            }
        }

        if ($inspectionEntry === null || $diagnosisEntry === null) {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package data roles are incomplete.');
        }

        $inspection = $this->readJsonObject(
            $this->pathFromRelative($packageRoot, (string)$inspectionEntry['path']),
            'STORAGE_JSON'
        );
        $diagnosis = $this->readJsonObject(
            $this->pathFromRelative($packageRoot, (string)$diagnosisEntry['path']),
            'STORAGE_JSON'
        );
        $this->validateDataIdentity($manifest, $inspection, $diagnosis);

        return [
            'manifest' => $manifest,
            'files' => $entries,
            'inspection' => $inspection,
            'diagnosis' => $diagnosis,
        ];
    }

    public static function assertInspectionId(string $inspectionId): void
    {
        if (preg_match(self::INSPECTION_ID_PATTERN, $inspectionId) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_INVALID_ID', 'The inspection identifier is invalid.');
        }
    }

    public static function assertReportId(string $reportId): void
    {
        if (preg_match(self::REPORT_ID_PATTERN, $reportId) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_INVALID_ID', 'The report identifier is invalid.');
        }
    }

    public static function assertVersion(string $version): void
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_INVALID_VERSION', 'The report version is invalid.');
        }
    }

    public static function assertSafeRelativePath(string $relativePath): void
    {
        if ($relativePath === '' || strlen($relativePath) > 1024 || strpos($relativePath, "\0") !== false) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path is invalid.');
        }
        if ($relativePath[0] === '/' || $relativePath[0] === '\\') {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path must be relative.');
        }
        if (strpos($relativePath, '\\') !== false) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path must use safe separators.');
        }
        if (preg_match('/^[A-Za-z]:/', $relativePath) === 1 || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $relativePath) === 1) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path cannot be absolute or use a protocol.');
        }
        if (preg_match('/^[A-Za-z0-9._\/-]+$/D', $relativePath) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path contains unsupported characters.');
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path contains an unsafe segment.');
            }
            if (substr($segment, -1) === '.' ||
                preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iD', $segment) === 1) {
                throw new DiagnosticsStorageException('STORAGE_PATH', 'The package path is not portable across supported filesystems.');
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, array<string, mixed>>
     */
    private function validateManifest(array $manifest): array
    {
        foreach (['schema_version', 'document_type', 'report', 'report_version', 'actors', 'files', 'created_at'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package manifest is incomplete.');
            }
        }
        if ($manifest['schema_version'] !== '1.0.0' || $manifest['document_type'] !== 'report_package') {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package manifest has an unsupported contract.');
        }
        if (!is_array($manifest['report']) || $this->isList($manifest['report']) ||
            !is_array($manifest['report_version']) || $this->isList($manifest['report_version'])) {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package identity is invalid.');
        }

        $report = $manifest['report'];
        $reportVersion = $manifest['report_version'];
        foreach (['id', 'inspection_id', 'status', 'current_published_version_id'] as $key) {
            if (!array_key_exists($key, $report)) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report identity is incomplete.');
            }
        }
        foreach (['id', 'report_id', 'version', 'status', 'approved_by', 'approved_at', 'published_at'] as $key) {
            if (!array_key_exists($key, $reportVersion)) {
                throw new DiagnosticsStorageException('STORAGE_PACKAGE_STATE', 'The published report metadata is incomplete.');
            }
        }

        self::assertReportId(is_string($report['id']) ? $report['id'] : '');
        self::assertInspectionId(is_string($report['inspection_id']) ? $report['inspection_id'] : '');
        if (!is_string($reportVersion['id']) || preg_match(self::REPORT_VERSION_ID_PATTERN, $reportVersion['id']) !== 1) {
            throw new DiagnosticsStorageException('STORAGE_INVALID_ID', 'The report version identifier is invalid.');
        }
        self::assertReportId(is_string($reportVersion['report_id']) ? $reportVersion['report_id'] : '');
        self::assertVersion(is_string($reportVersion['version']) ? $reportVersion['version'] : '');
        if ($reportVersion['report_id'] !== $report['id']) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The report package identifiers do not match.');
        }
        if ($report['current_published_version_id'] !== null && $report['current_published_version_id'] !== $reportVersion['id']) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The current report version identifier does not match.');
        }
        if ($reportVersion['status'] !== 'published') {
            throw new DiagnosticsStorageException('STORAGE_PACKAGE_STATE', 'Only a published report package can be installed.');
        }
        foreach (['approved_by', 'approved_at', 'published_at'] as $key) {
            if (!is_string($reportVersion[$key]) || trim($reportVersion[$key]) === '') {
                throw new DiagnosticsStorageException('STORAGE_PACKAGE_STATE', 'The published report approval metadata is incomplete.');
            }
        }
        if (!$this->hasTimezone((string)$reportVersion['approved_at']) || !$this->hasTimezone((string)$reportVersion['published_at'])) {
            throw new DiagnosticsStorageException('STORAGE_PACKAGE_STATE', 'The published report timestamps are invalid.');
        }

        if (!is_array($manifest['actors']) || !$this->isList($manifest['actors']) || count($manifest['actors']) === 0) {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package actors are invalid.');
        }
        $approverRole = null;
        foreach ($manifest['actors'] as $actor) {
            if (!is_array($actor) || !isset($actor['id'], $actor['role'])) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package actor entry is invalid.');
            }
            if ($actor['id'] === $reportVersion['approved_by']) {
                $approverRole = $actor['role'];
            }
        }
        if (!in_array($approverRole, ['inspector', 'reviewer'], true)) {
            throw new DiagnosticsStorageException('STORAGE_PACKAGE_STATE', 'The published report approver is invalid.');
        }

        if (!is_array($manifest['files']) || !$this->isList($manifest['files']) || count($manifest['files']) < 2) {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package file list is invalid.');
        }

        $entries = [];
        $caseFoldedPaths = [];
        $hashes = [];
        $roleCounts = ['inspection_data' => 0, 'diagnosis_data' => 0];
        $allowedRoles = ['inspection_data', 'diagnosis_data', 'media', 'attachment', 'source_report', 'other'];
        $allowedPrivacy = ['public', 'client_private', 'internal'];
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry) || $this->isList($entry)) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'A report package file entry is invalid.');
            }
            foreach (['role', 'path', 'sha256', 'content_type', 'privacy'] as $key) {
                if (!array_key_exists($key, $entry)) {
                    throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'A report package file entry is incomplete.');
                }
            }
            if (!is_string($entry['role']) || !in_array($entry['role'], $allowedRoles, true) ||
                !is_string($entry['path']) || !is_string($entry['sha256']) ||
                preg_match(self::HASH_PATTERN, $entry['sha256']) !== 1 ||
                !is_string($entry['content_type']) || trim($entry['content_type']) === '' ||
                !is_string($entry['privacy']) || !in_array($entry['privacy'], $allowedPrivacy, true)) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'A report package file entry is invalid.');
            }
            if (array_key_exists('size_bytes', $entry) && (!is_int($entry['size_bytes']) || $entry['size_bytes'] < 0)) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'A report package file size is invalid.');
            }

            self::assertSafeRelativePath($entry['path']);
            if ($entry['path'] === 'manifest.json') {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The package manifest cannot declare itself.');
            }
            $foldedPath = strtolower($entry['path']);
            if (isset($caseFoldedPaths[$foldedPath])) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package contains a duplicate path.');
            }
            if (isset($hashes[$entry['sha256']])) {
                throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package contains a duplicate file hash.');
            }
            $caseFoldedPaths[$foldedPath] = true;
            $hashes[$entry['sha256']] = true;
            $entries[$entry['path']] = $entry;
            if (array_key_exists($entry['role'], $roleCounts)) {
                $roleCounts[$entry['role']]++;
            }
        }

        if ($roleCounts['inspection_data'] !== 1 || $roleCounts['diagnosis_data'] !== 1) {
            throw new DiagnosticsStorageException('STORAGE_MANIFEST', 'The report package must declare exactly one inspection and one diagnosis document.');
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function verifyFileIntegrity(string $filePath, array $entry): void
    {
        $actualHash = hash_file('sha256', $filePath);
        if ($actualHash === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A report package file cannot be read.');
        }
        if (!hash_equals((string)$entry['sha256'], $actualHash)) {
            throw new DiagnosticsStorageException('STORAGE_HASH_MISMATCH', 'A report package file failed its integrity check.');
        }
        if (array_key_exists('size_bytes', $entry)) {
            $actualSize = filesize($filePath);
            if ($actualSize === false) {
                throw new DiagnosticsStorageException('STORAGE_IO', 'A report package file size cannot be read.');
            }
            if ($actualSize !== $entry['size_bytes']) {
                throw new DiagnosticsStorageException('STORAGE_SIZE_MISMATCH', 'A report package file failed its size check.');
            }
        }
    }

    private function assertPackageFile(string $packageRoot, string $relativePath, string $filePath): void
    {
        self::assertSafeRelativePath($relativePath);
        $current = $packageRoot;
        foreach (explode('/', $relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The report package contains a symbolic link.');
            }
        }
        if (!is_file($filePath)) {
            throw new DiagnosticsStorageException('STORAGE_MISSING_FILE', 'A report package file is missing.');
        }
        $resolved = realpath($filePath);
        if ($resolved === false || !$this->isPathWithin($packageRoot, $resolved)) {
            throw new DiagnosticsStorageException('STORAGE_PATH', 'A report package file resolves outside its package.');
        }
    }

    /** @param array<string, bool> $paths */
    private function collectRegularFiles(string $directory, string $prefix, array &$paths): void
    {
        $names = scandir($directory);
        if ($names === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'The report package directory cannot be read.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $absolute = $directory . DIRECTORY_SEPARATOR . $name;
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            if (is_link($absolute)) {
                throw new DiagnosticsStorageException('STORAGE_SYMLINK', 'The report package contains a symbolic link.');
            }
            if (is_dir($absolute)) {
                $this->collectRegularFiles($absolute, $relative, $paths);
            } elseif (is_file($absolute)) {
                self::assertSafeRelativePath($relative);
                $paths[$relative] = true;
            } else {
                throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The report package contains an unsupported filesystem entry.');
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $inspection
     * @param array<string, mixed> $diagnosis
     */
    private function validateDataIdentity(array $manifest, array $inspection, array $diagnosis): void
    {
        if (($inspection['document_type'] ?? null) !== 'inspection' || !is_string($inspection['id'] ?? null)) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The inspection data document is invalid.');
        }
        self::assertInspectionId($inspection['id']);
        if (($diagnosis['document_type'] ?? null) !== 'diagnosis' ||
            !is_string($diagnosis['id'] ?? null) || !is_string($diagnosis['inspection_id'] ?? null)) {
            throw new DiagnosticsStorageException('STORAGE_INTEGRITY', 'The diagnosis data document is invalid.');
        }
        self::assertInspectionId($diagnosis['id']);
        self::assertInspectionId($diagnosis['inspection_id']);

        $reportInspectionId = $manifest['report']['inspection_id'];
        if ($inspection['id'] !== $reportInspectionId || $diagnosis['id'] !== $reportInspectionId ||
            $diagnosis['inspection_id'] !== $reportInspectionId) {
            throw new DiagnosticsStorageException('STORAGE_ID_MISMATCH', 'The package data identifiers do not match.');
        }
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $filePath, string $errorCode): array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new DiagnosticsStorageException('STORAGE_IO', 'A JSON document cannot be read.');
        }
        if (substr(ltrim($raw), 0, 1) !== '{') {
            throw new DiagnosticsStorageException($errorCode, 'A JSON document must contain an object.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || $this->isList($decoded)) {
            throw new DiagnosticsStorageException($errorCode, 'A JSON document is invalid.');
        }
        return $decoded;
    }

    private function pathFromRelative(string $root, string $relativePath): string
    {
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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

    private function hasTimezone(string $value): bool
    {
        return preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) === 1 && strtotime($value) !== false;
    }
}
