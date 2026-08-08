<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsPackageVerifier;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsStorage.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$assertions = 0;
$skips = 0;
$packageSequence = 0;
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'doktorhaus-diagnostics-' . bin2hex(random_bytes(8));
$failureMessage = null;
$requireSymlinkTests = getenv('DIAGNOSTICS_REQUIRE_SYMLINK_TESTS') === '1';

function testAssert(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}

function expectStorageCode(string $code, callable $callback, string $message): void
{
    global $assertions;
    try {
        $callback();
    } catch (DiagnosticsStorageException $error) {
        if ($error->getStorageCode() !== $code) {
            throw new RuntimeException($message . ' Expected ' . $code . ', got ' . $error->getStorageCode() . '.');
        }
        $assertions++;
        return;
    }
    throw new RuntimeException($message . ' Expected ' . $code . ', but no exception was thrown.');
}

/** @param array<string, mixed> $data */
function writeTestJson(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create a test directory.');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write a test JSON file.');
    }
}

/** @return array<string, mixed> */
function readTestJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('Cannot read a test JSON file.');
    }
    return $decoded;
}

function fixtureDocument(string $name): array
{
    return readTestJson(__DIR__ . '/../docs/diagnostics/fixtures/valid/' . $name);
}

function reportVersionId(string $reportId, string $version): string
{
    return 'rptv_' . substr(hash('sha256', $reportId . '|' . $version), 0, 16);
}

function createPackage(string $testRoot, string $reportId, string $version): string
{
    global $packageSequence;
    $packageSequence++;
    $package = $testRoot . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'package-' . $packageSequence;
    if (!mkdir($package, 0700, true)) {
        throw new RuntimeException('Cannot create a source package.');
    }

    $inspection = fixtureDocument('inspection-minimal.json');
    $diagnosis = fixtureDocument('diagnosis-minimal.json');
    writeTestJson($package . DIRECTORY_SEPARATOR . 'inspection.json', $inspection);
    writeTestJson($package . DIRECTORY_SEPARATOR . 'diagnosis.json', $diagnosis);

    $versionId = reportVersionId($reportId, $version);
    $manifest = [
        'schema_version' => '1.0.0',
        'document_type' => 'report_package',
        'report' => [
            'id' => $reportId,
            'inspection_id' => $inspection['id'],
            'status' => 'active',
            'current_published_version_id' => $versionId,
        ],
        'report_version' => [
            'id' => $versionId,
            'report_id' => $reportId,
            'version' => $version,
            'change_type' => $version === '1.0' ? 'initial' : 'evidence_update',
            'change_summary' => 'Anonymizovaná syntetická testovacia verzia.',
            'status' => 'published',
            'generated_at' => '2026-08-08T08:00:00+02:00',
            'approved_by' => 'inspector_test',
            'approved_at' => '2026-08-08T08:10:00+02:00',
            'published_at' => '2026-08-08T08:20:00+02:00',
            'renderer_contract_version' => '1.0.0',
            'limitations_snapshot' => [],
            'unverified_items_snapshot' => [],
        ],
        'actors' => [
            ['id' => 'inspector_test', 'display_name' => 'Testovací inšpektor', 'role' => 'inspector'],
        ],
        'files' => [],
        'created_at' => '2026-08-08T08:20:00+02:00',
    ];
    foreach ([
        ['inspection_data', 'inspection.json'],
        ['diagnosis_data', 'diagnosis.json'],
    ] as $file) {
        $path = $package . DIRECTORY_SEPARATOR . $file[1];
        $manifest['files'][] = [
            'role' => $file[0],
            'path' => $file[1],
            'sha256' => hash_file('sha256', $path),
            'content_type' => 'application/json',
            'size_bytes' => filesize($path),
            'privacy' => 'client_private',
        ];
    }
    writeTestJson($package . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    return $package;
}

/** @param callable(array<string, mixed>): void $mutation */
function mutateManifest(string $package, callable $mutation): void
{
    $path = $package . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = readTestJson($path);
    $mutation($manifest);
    writeTestJson($path, $manifest);
}

function refreshManifestEntry(string $package, string $relativePath): void
{
    mutateManifest($package, function (array &$manifest) use ($package, $relativePath): void {
        foreach ($manifest['files'] as &$entry) {
            if ($entry['path'] === $relativePath) {
                $path = $package . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $entry['sha256'] = hash_file('sha256', $path);
                $entry['size_bytes'] = filesize($path);
            }
        }
        unset($entry);
    });
}

function removeTestTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0600);
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0700);
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                removeTestTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
}

try {
    if (!mkdir($testRoot, 0700, true)) {
        throw new RuntimeException('Cannot create the temporary test root.');
    }

    $webRoot = $testRoot . DIRECTORY_SEPARATOR . 'web';
    mkdir($webRoot, 0700, true);
    expectStorageCode('STORAGE_UNSAFE_ROOT', function () use ($webRoot): void {
        new DiagnosticsStorage($webRoot, $webRoot);
    }, 'Storage equal to the web root must be rejected.');
    expectStorageCode('STORAGE_UNSAFE_ROOT', function () use ($webRoot): void {
        new DiagnosticsStorage($webRoot . DIRECTORY_SEPARATOR . 'private', $webRoot);
    }, 'Storage inside the web root must be rejected.');

    $storage = new DiagnosticsStorage($testRoot . DIRECTORY_SEPARATOR . 'storage', $webRoot);
    echo "webroot safety tests: PASS\n";
    $inspection = fixtureDocument('inspection-minimal.json');
    $diagnosis = fixtureDocument('diagnosis-minimal.json');
    $inspectionId = (string)$inspection['id'];

    $meta = $storage->saveDraftInspection($inspection, 0);
    testAssert($meta['storage_revision'] === 1, 'The first draft revision must be 1.');
    testAssert($storage->loadDraftInspection($inspectionId) === $inspection, 'The inspection draft must round-trip.');
    testAssert($storage->draftExists($inspectionId), 'The saved draft must exist.');

    $inspection['updated_at'] = '2026-08-08T09:00:00+02:00';
    expectStorageCode('STORAGE_REVISION_CONFLICT', function () use ($storage, $inspection): void {
        $storage->saveDraftInspection($inspection);
    }, 'An existing draft must not be overwritten without its revision.');
    $meta = $storage->saveDraftInspection($inspection, 1);
    testAssert($meta['storage_revision'] === 2, 'An atomic overwrite must increment the revision.');
    testAssert($storage->loadDraftInspection($inspectionId)['updated_at'] === $inspection['updated_at'], 'The complete new draft must replace the old one.');
    expectStorageCode('STORAGE_REVISION_CONFLICT', function () use ($storage, $inspection): void {
        $storage->saveDraftInspection($inspection, 1);
    }, 'A stale writer must not overwrite a newer draft.');
    echo "revision guard tests: PASS\n";

    $meta = $storage->saveDraftDiagnosis($diagnosis, 2);
    testAssert($meta['storage_revision'] === 3, 'Inspection and diagnosis must share one draft revision.');
    testAssert($storage->loadDraftDiagnosis($inspectionId) === $diagnosis, 'The diagnosis draft must round-trip.');

    $pendingMarker = $storage->getRoot() . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $inspectionId . DIRECTORY_SEPARATOR . '.draft-write-pending.json';
    writeTestJson($pendingMarker, ['inspection_id' => $inspectionId, 'target' => 'inspection.json']);
    expectStorageCode('STORAGE_INTEGRITY', function () use ($storage, $inspectionId): void {
        $storage->loadDraftInspection($inspectionId);
    }, 'An incomplete draft transaction must block reads.');
    unlink($pendingMarker);

    $mismatchedDiagnosis = $diagnosis;
    $mismatchedDiagnosis['inspection_id'] = 'insp_2222222222222222';
    expectStorageCode('STORAGE_ID_MISMATCH', function () use ($storage, $mismatchedDiagnosis): void {
        $storage->saveDraftDiagnosis($mismatchedDiagnosis);
    }, 'Mismatched diagnosis identifiers must fail.');

    $invalidInspection = $inspection;
    $invalidInspection['id'] = 'invalid-id';
    expectStorageCode('STORAGE_INVALID_ID', function () use ($storage, $invalidInspection): void {
        $storage->saveDraftInspection($invalidInspection);
    }, 'An invalid inspection identifier must fail.');

    $invalidStructure = $inspection;
    $invalidStructure['document_type'] = 'diagnosis';
    expectStorageCode('STORAGE_INTEGRITY', function () use ($storage, $invalidStructure): void {
        $storage->saveDraftInspection($invalidStructure);
    }, 'An invalid draft document structure must fail.');

    $draftPath = $storage->getRoot() . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $inspectionId . DIRECTORY_SEPARATOR . 'inspection.json';
    file_put_contents($draftPath, '{broken-json');
    expectStorageCode('STORAGE_JSON', function () use ($storage, $inspectionId): void {
        $storage->loadDraftInspection($inspectionId);
    }, 'Corrupted stored JSON must fail.');
    $storage->saveDraftInspection($inspection, 3);

    $verifier = new DiagnosticsPackageVerifier();
    $validPackage = createPackage($testRoot, 'rpt_aaaaaaaaaaaaaaaa', '1.0');
    $verified = $verifier->verifyPackage($validPackage);
    testAssert($verified['manifest']['report']['id'] === 'rpt_aaaaaaaaaaaaaaaa', 'A valid synthetic package must verify.');

    $wrongHash = createPackage($testRoot, 'rpt_bbbbbbbbbbbbbbbb', '1.0');
    file_put_contents($wrongHash . DIRECTORY_SEPARATOR . 'inspection.json', "changed-after-manifest\n", FILE_APPEND);
    expectStorageCode('STORAGE_HASH_MISMATCH', function () use ($verifier, $wrongHash): void {
        $verifier->verifyPackage($wrongHash);
    }, 'A declared file changed after manifest creation must fail its hash check.');

    $wrongSize = createPackage($testRoot, 'rpt_cccccccccccccccc', '1.0');
    mutateManifest($wrongSize, function (array &$manifest): void {
        $manifest['files'][0]['size_bytes']++;
    });
    expectStorageCode('STORAGE_SIZE_MISMATCH', function () use ($verifier, $wrongSize): void {
        $verifier->verifyPackage($wrongSize);
    }, 'A wrong file size must fail.');
    echo "SHA and size integrity tests: PASS\n";

    $missingFile = createPackage($testRoot, 'rpt_dddddddddddddddd', '1.0');
    unlink($missingFile . DIRECTORY_SEPARATOR . 'inspection.json');
    expectStorageCode('STORAGE_MISSING_FILE', function () use ($verifier, $missingFile): void {
        $verifier->verifyPackage($missingFile);
    }, 'A missing declared file must fail.');

    $unexpectedFile = createPackage($testRoot, 'rpt_eeeeeeeeeeeeeeee', '1.0');
    file_put_contents($unexpectedFile . DIRECTORY_SEPARATOR . 'unexpected.bin', 'unexpected');
    expectStorageCode('STORAGE_UNEXPECTED_FILE', function () use ($verifier, $unexpectedFile): void {
        $verifier->verifyPackage($unexpectedFile);
    }, 'An undeclared regular file must fail.');

    $unsafeTraversal = createPackage($testRoot, 'rpt_f1f1f1f1f1f1f1f1', '1.0');
    mutateManifest($unsafeTraversal, function (array &$manifest): void {
        $manifest['files'][0]['path'] = 'media/../../inspection.json';
    });
    expectStorageCode('STORAGE_PATH', function () use ($verifier, $unsafeTraversal): void {
        $verifier->verifyPackage($unsafeTraversal);
    }, 'A traversal path must fail.');

    DiagnosticsPackageVerifier::assertSafeRelativePath('media/photo-001.jpg');
    testAssert(true, 'A safe media path must be accepted.');
    foreach ([
        '../secret.pdf',
        'media/../../secret.pdf',
        '/etc/passwd',
        'C:\\secret.pdf',
        '\\\\server\\share\\secret.pdf',
        'https://example.com/file.pdf',
        'C:/private/file.json',
        'media//file.json',
        'NUL',
        'media/file.',
        'bad' . "\0" . 'name',
    ] as $unsafePath) {
        expectStorageCode('STORAGE_PATH', function () use ($unsafePath): void {
            DiagnosticsPackageVerifier::assertSafeRelativePath($unsafePath);
        }, 'An absolute, URL, UNC, or backslash path must fail.');
    }
    echo "package path tests: PASS\n";

    $duplicatePath = createPackage($testRoot, 'rpt_f2f2f2f2f2f2f2f2', '1.0');
    mutateManifest($duplicatePath, function (array &$manifest): void {
        $manifest['files'][] = $manifest['files'][0];
    });
    expectStorageCode('STORAGE_MANIFEST', function () use ($verifier, $duplicatePath): void {
        $verifier->verifyPackage($duplicatePath);
    }, 'A duplicate manifest path must fail.');

    $invalidReport = createPackage($testRoot, 'rpt_f3f3f3f3f3f3f3f3', '1.0');
    mutateManifest($invalidReport, function (array &$manifest): void {
        $manifest['report']['id'] = 'bad-report';
        $manifest['report_version']['report_id'] = 'bad-report';
    });
    expectStorageCode('STORAGE_INVALID_ID', function () use ($verifier, $invalidReport): void {
        $verifier->verifyPackage($invalidReport);
    }, 'An invalid report identifier must fail.');

    $invalidVersion = createPackage($testRoot, 'rpt_f4f4f4f4f4f4f4f4', '1.0');
    mutateManifest($invalidVersion, function (array &$manifest): void {
        $manifest['report_version']['version'] = 'v1';
    });
    expectStorageCode('STORAGE_INVALID_VERSION', function () use ($verifier, $invalidVersion): void {
        $verifier->verifyPackage($invalidVersion);
    }, 'An invalid report version must fail.');

    foreach (['approved', 'draft'] as $invalidStatus) {
        $invalidState = createPackage($testRoot, 'rpt_' . str_repeat($invalidStatus === 'approved' ? 'a1' : 'd1', 8), '1.0');
        mutateManifest($invalidState, function (array &$manifest) use ($invalidStatus): void {
            $manifest['report_version']['status'] = $invalidStatus;
        });
        expectStorageCode('STORAGE_PACKAGE_STATE', function () use ($verifier, $invalidState): void {
            $verifier->verifyPackage($invalidState);
        }, 'An approved or draft package is not installable as published.');
    }

    foreach (['approved_at', 'published_at'] as $missingTimestamp) {
        $missingApproval = createPackage($testRoot, 'rpt_' . str_repeat($missingTimestamp === 'approved_at' ? 'a2' : 'b2', 8), '1.0');
        mutateManifest($missingApproval, function (array &$manifest) use ($missingTimestamp): void {
            unset($manifest['report_version'][$missingTimestamp]);
        });
        expectStorageCode('STORAGE_PACKAGE_STATE', function () use ($verifier, $missingApproval): void {
            $verifier->verifyPackage($missingApproval);
        }, 'Missing approval or publish timestamps must fail.');
    }

    $identityMismatch = createPackage($testRoot, 'rpt_f5f5f5f5f5f5f5f5', '1.0');
    $badDiagnosis = readTestJson($identityMismatch . DIRECTORY_SEPARATOR . 'diagnosis.json');
    $badDiagnosis['inspection_id'] = 'insp_2222222222222222';
    writeTestJson($identityMismatch . DIRECTORY_SEPARATOR . 'diagnosis.json', $badDiagnosis);
    refreshManifestEntry($identityMismatch, 'diagnosis.json');
    expectStorageCode('STORAGE_ID_MISMATCH', function () use ($verifier, $identityMismatch): void {
        $verifier->verifyPackage($identityMismatch);
    }, 'Mismatched package document identifiers must fail.');

    $symlinkFilePackage = createPackage($testRoot, 'rpt_f6f6f6f6f6f6f6f6', '1.0');
    $outsideFile = $testRoot . DIRECTORY_SEPARATOR . 'outside-inspection.json';
    copy($symlinkFilePackage . DIRECTORY_SEPARATOR . 'inspection.json', $outsideFile);
    unlink($symlinkFilePackage . DIRECTORY_SEPARATOR . 'inspection.json');
    if (function_exists('symlink') && @symlink($outsideFile, $symlinkFilePackage . DIRECTORY_SEPARATOR . 'inspection.json')) {
        expectStorageCode('STORAGE_SYMLINK', function () use ($verifier, $symlinkFilePackage): void {
            $verifier->verifyPackage($symlinkFilePackage);
        }, 'A symlinked package file must fail.');
        echo "symlink file test: PASS\n";
    } else {
        if ($requireSymlinkTests) {
            throw new RuntimeException('The CI environment must permit the symlink file test.');
        }
        $skips++;
        echo "SKIP: filesystem does not permit a file symlink test.\n";
    }

    $symlinkDirectoryPackage = createPackage($testRoot, 'rpt_f7f7f7f7f7f7f7f7', '1.0');
    $outsideDirectory = $testRoot . DIRECTORY_SEPARATOR . 'outside-media';
    mkdir($outsideDirectory, 0700, true);
    file_put_contents($outsideDirectory . DIRECTORY_SEPARATOR . 'sample.bin', 'sample-media');
    if (function_exists('symlink') && @symlink($outsideDirectory, $symlinkDirectoryPackage . DIRECTORY_SEPARATOR . 'media')) {
        mutateManifest($symlinkDirectoryPackage, function (array &$manifest) use ($outsideDirectory): void {
            $media = $outsideDirectory . DIRECTORY_SEPARATOR . 'sample.bin';
            $manifest['files'][] = [
                'role' => 'media',
                'path' => 'media/sample.bin',
                'sha256' => hash_file('sha256', $media),
                'content_type' => 'application/octet-stream',
                'size_bytes' => filesize($media),
                'privacy' => 'client_private',
            ];
        });
        expectStorageCode('STORAGE_SYMLINK', function () use ($verifier, $symlinkDirectoryPackage): void {
            $verifier->verifyPackage($symlinkDirectoryPackage);
        }, 'A symlinked package directory must fail.');
        echo "symlink directory test: PASS\n";
    } else {
        if ($requireSymlinkTests) {
            throw new RuntimeException('The CI environment must permit the symlink directory test.');
        }
        $skips++;
        echo "SKIP: filesystem does not permit a directory symlink test.\n";
    }

    $reportId = 'rpt_1234567890abcdef';
    $package10 = createPackage($testRoot, $reportId, '1.0');
    $installed = $storage->installPublishedPackage($package10);
    testAssert($installed['version'] === '1.0' && is_dir($installed['path']), 'A valid published package must install.');
    testAssert($storage->loadPublishedManifest($reportId, '1.0')['report']['id'] === $reportId, 'The published manifest must load.');
    testAssert($storage->loadPublishedInspection($reportId, '1.0')['document_type'] === 'inspection', 'The published inspection must load.');
    testAssert($storage->loadPublishedDiagnosis($reportId, '1.0')['document_type'] === 'diagnosis', 'The published diagnosis must load.');
    $resolved = $storage->resolvePublishedFile($reportId, '1.0', 'inspection.json');
    testAssert(is_file($resolved['path']) && $resolved['role'] === 'inspection_data', 'A declared published file must resolve with metadata.');
    expectStorageCode('STORAGE_PATH', function () use ($storage, $reportId): void {
        $storage->resolvePublishedFile($reportId, '1.0', 'undeclared.bin');
    }, 'An undeclared published file must not resolve.');
    expectStorageCode('STORAGE_ALREADY_EXISTS', function () use ($storage, $package10): void {
        $storage->installPublishedPackage($package10);
    }, 'A published version must never be overwritten.');

    foreach (['1.1', '1.10', '2.0'] as $version) {
        $storage->installPublishedPackage(createPackage($testRoot, $reportId, $version));
    }
    $reportDirectory = $storage->getRoot() . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $reportId;
    mkdir($reportDirectory . DIRECTORY_SEPARATOR . 'notes', 0700);
    mkdir($reportDirectory . DIRECTORY_SEPARATOR . '3.0', 0700);
    testAssert(
        $storage->listPublishedVersions($reportId) === ['1.0', '1.1', '1.10', '2.0'],
        'Published versions must be listed in numeric major.minor order.'
    );
    echo "immutable package tests: PASS\n";

    testAssert($storage->deleteDraft($inspectionId), 'An existing draft must be deletable.');
    testAssert(!$storage->draftExists($inspectionId), 'A deleted draft must no longer exist.');
    testAssert(!$storage->deleteDraft($inspectionId), 'Deleting an absent draft must be a no-op.');

} catch (Throwable $error) {
    $failureMessage = $error->getMessage();
    if ($requireSymlinkTests && $failureMessage === 'The report package cannot be installed atomically.') {
        $lastError = error_get_last();
        if (is_array($lastError) && isset($lastError['message']) && is_string($lastError['message'])) {
            $failureMessage .= ' CI filesystem detail: ' . $lastError['message'];
        }
    }
} finally {
    removeTestTree($testRoot);
}

if ((is_dir($testRoot) || is_link($testRoot)) && $failureMessage === null) {
    $failureMessage = 'The temporary test directory was not cleaned up.';
}

if ($failureMessage !== null) {
    fwrite(STDERR, 'Diagnostics storage tests failed: ' . $failureMessage . "\n");
    exit(1);
}

echo "temporary storage cleanup test: PASS\n";
echo 'Diagnostics storage tests passed: ' . $assertions . ' assertions';
if ($skips > 0) {
    echo ', ' . $skips . ' symlink checks skipped';
}
echo ".\n";
