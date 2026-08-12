<?php
declare(strict_types=1);

require_once __DIR__ . '/../diagnostika-preview/lib/preview-bundle.php';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive unavailable.\n");
    exit(1);
}

function preview_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function preview_test_remove(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        preview_test_remove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function preview_test_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}

function preview_test_bundle(string $path, bool $badHash = false): void
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==', true);
    if (!is_string($jpeg)) {
        throw new RuntimeException('JPEG fixture failed.');
    }
    $media = [];
    $linkedEvidence = [];
    $appendixItems = [];
    for ($index = 1; $index <= 71; $index++) {
        $id = 'ev_' . str_pad(dechex($index), 16, '0', STR_PAD_LEFT);
        $mediaPath = 'media/' . $id . '.jpg';
        $placement = $index <= 53 ? 'linked_evidence' : 'source_documentation_appendix';
        $media[] = [
            'evidence_id' => $id,
            'path' => $mediaPath,
            'sha256' => $badHash && $index === 71 ? str_repeat('0', 64) : hash('sha256', $jpeg),
            'content_type' => 'image/jpeg',
            'placement_kind' => $placement,
        ];
        if ($index <= 53) {
            $linkedEvidence[] = [
                'id' => $id,
                'evidence_type' => 'photo',
                'title' => 'Photo ' . $index,
                'privacy' => 'client_private',
                'status' => 'active',
                'has_media' => true,
                'content_type' => 'image/jpeg',
                'media_url' => 'api/diagnostics-media.php?evidence=' . $id,
            ];
        } else {
            $appendixItems[] = [
                'evidence_id' => $id,
                'display_code' => 'EV-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'source_caption' => 'Dokumentačná fotografia · Photo ' . $index,
                'media_reference' => $mediaPath,
                'media_url' => 'api/diagnostics-media.php?evidence=' . $id,
                'content_type' => 'image/jpeg',
                'source_identity' => 'Photo ' . $index,
                'source_pdf_page' => $index,
                'provenance_source_page' => $index,
                'order' => $index,
            ];
        }
    }
    $impacts = [];
    foreach (['safety', 'structural', 'moisture', 'health', 'durability', 'usability', 'financial'] as $dimension) {
        $impacts[] = ['dimension' => $dimension, 'level' => 'low', 'description' => 'Test', 'time_horizon' => 'both', 'confidence' => 'high', 'rationale' => 'Test'];
    }
    $client = [
        'schema_version' => '1.0.0',
        'document_type' => 'client_report',
        'report' => ['version' => '1.0'],
        'property' => ['display_name' => 'Test'],
        'inspection' => ['performed_at' => '2026-08-12T10:00:00+02:00'],
        'overview' => ['issue_count' => 1],
        'issues' => [[
            'id' => 'issue_0000000000000001',
            'title' => 'Test issue',
            'impacts' => $impacts,
            'evidence' => $linkedEvidence,
        ]],
        'recommendations' => [],
        'verifications' => [],
        'issue_relations' => [],
        'unverified_items' => [],
        'generated_at' => '2026-08-12T10:00:00+02:00',
    ];
    $appendix = [
        'schema_version' => '1.0.0-helper',
        'document_type' => 'source_documentation_appendix',
        'title' => 'Zdrojová fotodokumentácia',
        'intro' => 'Test',
        'report_id' => 'rpt_0000000000000001',
        'report_version_id' => 'rptv_0000000000000001',
        'inspection_id' => 'insp_0000000000000001',
        'generated_at' => '2026-08-12T10:00:00+02:00',
        'photo_count' => 18,
        'items' => $appendixItems,
    ];
    $clientRaw = preview_test_json($client);
    $appendixRaw = preview_test_json($appendix);
    $manifest = [
        'schema_version' => '1.0.0-helper',
        'document_type' => 'diagnostics_preview_bundle',
        'report_id' => 'rpt_0000000000000001',
        'report_version_id' => 'rptv_0000000000000001',
        'inspection_id' => 'insp_0000000000000001',
        'preview_state' => 'draft',
        'client_report' => ['path' => 'client-report-preview-v1.0.json', 'sha256' => hash('sha256', $clientRaw), 'content_type' => 'application/json'],
        'source_documentation_appendix' => ['path' => 'source-documentation-appendix-v1.0.json', 'sha256' => hash('sha256', $appendixRaw), 'content_type' => 'application/json', 'photo_count' => 18],
        'media' => $media,
        'missing_media' => [],
    ];
    for ($index = 72; $index <= 76; $index++) {
        $manifest['missing_media'][] = [
            'evidence_id' => 'ev_' . str_pad(dechex($index), 16, '0', STR_PAD_LEFT),
            'display_code' => 'EV-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
            'media_kind' => 'video',
            'source_reference' => 'video-' . $index . '.mp4',
            'status' => 'VIDEO_SOURCE_REQUIRED',
        ];
    }
    $zip = new ZipArchive();
    preview_test_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Could not create ZIP fixture.');
    $zip->addFromString('client-report-preview-v1.0.json', $clientRaw);
    $zip->addFromString('source-documentation-appendix-v1.0.json', $appendixRaw);
    $zip->addFromString('preview-manifest.json', preview_test_json($manifest));
    foreach ($media as $item) {
        $zip->addFromString($item['path'], $jpeg);
    }
    $zip->close();
}

if (($argv[1] ?? '') === '--write-fixture') {
    $fixturePath = $argv[2] ?? '';
    if (!is_string($fixturePath) || $fixturePath === '') {
        fwrite(STDERR, "Fixture path required.\n");
        exit(2);
    }
    preview_test_bundle($fixturePath);
    echo $fixturePath . "\n";
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dh-preview-test-' . bin2hex(random_bytes(6));
$storage = $root . DIRECTORY_SEPARATOR . 'private';
$webroot = $root . DIRECTORY_SEPARATOR . 'webroot';
mkdir($storage, 0700, true);
mkdir($webroot, 0700, true);
putenv('INSPECTIONS_ADMIN_PIN=test-owner-pin');
putenv('DIAGNOSTICS_STORAGE_ROOT=' . $storage);
$_SERVER['DOCUMENT_ROOT'] = $webroot;

try {
    $validZip = $root . DIRECTORY_SEPARATOR . 'valid.zip';
    preview_test_bundle($validZip);
    $meta = DhPreviewBundleInstaller::install($validZip);
    preview_test_assert(preg_match(DH_PREVIEW_ID_PATTERN, $meta['preview_id']) === 1, 'Preview ID format failed.');
    preview_test_assert($meta['counts']['media'] === 71, 'Media count failed.');
    preview_test_assert($meta['counts']['linked_evidence'] === 53, 'Linked count failed.');
    preview_test_assert($meta['counts']['source_documentation_appendix'] === 18, 'Appendix count failed.');
    preview_test_assert($meta['counts']['videos_pending'] === 5, 'Pending video count failed.');
    $installed = $storage . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'owner-previews' . DIRECTORY_SEPARATOR . $meta['preview_id'];
    preview_test_assert(is_file($installed . DIRECTORY_SEPARATOR . 'preview-meta.json'), 'Preview metadata missing.');
    preview_test_assert(count(glob($installed . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . '*') ?: []) === 71, 'Installed media count failed.');

    $badZip = $root . DIRECTORY_SEPARATOR . 'bad-hash.zip';
    preview_test_bundle($badZip, true);
    $badRejected = false;
    try {
        DhPreviewBundleInstaller::install($badZip);
    } catch (DhPreviewBundleException $error) {
        $badRejected = true;
    }
    preview_test_assert($badRejected, 'Tampered media hash was not rejected.');

    $traversalZip = $root . DIRECTORY_SEPARATOR . 'traversal.zip';
    $zip = new ZipArchive();
    $zip->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../escape.json', '{}');
    $zip->close();
    $traversalRejected = false;
    try {
        DhPreviewBundleInstaller::install($traversalZip);
    } catch (DhPreviewBundleException $error) {
        $traversalRejected = true;
    }
    preview_test_assert($traversalRejected, 'Traversal path was not rejected.');

    echo "Protected diagnostics preview tests: PASS\n";
} finally {
    preview_test_remove($root);
}
