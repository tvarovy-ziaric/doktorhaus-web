<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsAccessService;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsAccessService.php';

/** @return array<string, mixed> */
function deliveryFixtureRead(string $name): array
{
    $raw = file_get_contents(__DIR__ . '/../docs/diagnostics/fixtures/valid/' . $name);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Cannot read delivery source fixture.');
    }
    return $decoded;
}

/** @param array<string, mixed> $value */
function deliveryFixtureWriteJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write delivery fixture JSON.');
    }
}

function deliveryFixtureWriteLarge(string $path, int $size): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot create large delivery fixture.');
    }
    $chunk = str_repeat("0123456789abcdef", 4096);
    $remaining = $size;
    while ($remaining > 0) {
        $written = fwrite($handle, substr($chunk, 0, min(strlen($chunk), $remaining)));
        if ($written === false || $written === 0) {
            fclose($handle);
            throw new RuntimeException('Cannot write large delivery fixture.');
        }
        $remaining -= $written;
    }
    fclose($handle);
}

/** @param array<string, mixed> $inspection @param array<string, mixed> $diagnosis */
function deliveryFixtureAddEvidence(
    array &$inspection,
    array &$diagnosis,
    string $id,
    string $type,
    string $privacy,
    string $status,
    string $contentType,
    string $mediaReference,
    bool $linked
): void {
    $evidence = $inspection['evidence'][0];
    $evidence['id'] = $id;
    $evidence['display_code'] = 'EV-' . substr($id, -3);
    $evidence['type'] = $type;
    $evidence['title'] = 'Synthetic delivery evidence ' . $id;
    $evidence['description'] = 'Anonymized synthetic evidence for authorized delivery tests.';
    $evidence['privacy'] = $privacy;
    $evidence['status'] = $status;
    $evidence['content_type'] = $contentType;
    $evidence['media_reference'] = $mediaReference;
    unset($evidence['sha256']);
    $inspection['evidence'][] = $evidence;
    if ($linked) {
        $diagnosis['issue_evidence_links'][] = [
            'id' => 'rel_' . substr(hash('sha256', $id), 0, 16),
            'issue_id' => $diagnosis['issues'][0]['id'],
            'evidence_id' => $id,
            'role' => 'supporting',
            'rationale' => 'Synthetic delivery test link.',
            'created_at' => '2026-08-08T10:00:00+02:00',
            'created_by' => 'inspector_example',
            'status' => 'active',
        ];
    }
}

/** @return array<string, mixed> */
function deliveryFixturePricing(string $reportId, string $versionId, string $inspectionId, string $issueId, string $recommendationId): array
{
    return [
        'schema_version' => '1.0.0',
        'document_type' => 'report_pricing',
        'report_id' => $reportId,
        'report_version_id' => $versionId,
        'inspection_id' => $inspectionId,
        'components' => [[
            'id' => 'rpc_5555555555555551',
            'display_code' => 'RP-HTTP-01',
            'linked_issue_ids' => [$issueId],
            'linked_recommendation_ids' => [$recommendationId],
            'title' => 'Syntetické odborné overenie',
            'scope' => 'Samostatné overenie; nejde o cenu odstránenia celého problému.',
            'assumptions' => ['Kontrolovaná časť je prístupná.'],
            'exclusions' => ['Definitívna oprava.'],
            'conditional' => false,
            'shared_across_issues' => false,
            'ownership' => 'service',
            'client_visible' => true,
            'client_caveat' => 'Cena overenia nie je cenou všetkých opráv.',
            'quantity' => ['value' => null, 'unit' => null, 'status' => 'not_applicable'],
            'pricing_kind' => 'total_range',
            'pricing' => [
                'min' => 100,
                'expected' => 150,
                'max' => 200,
                'currency' => 'EUR',
                'confidence' => 'medium',
                'price_basis_date' => '2026-08-11',
                'vat_status' => 'unknown',
            ],
            'provenance' => [
                'source_method' => 'expert_range',
                'source_ids' => ['SYNTHETIC-HTTP-PRICE'],
                'snapshot_references' => [],
            ],
        ]],
        'aggregation' => [
            'status' => 'subtotal',
            'method' => 'explicit_component_allowlist',
            'component_ids' => ['rpc_5555555555555551'],
            'min' => 100,
            'expected' => 150,
            'max' => 200,
            'currency' => 'EUR',
        ],
        'generated_at' => '2026-08-11T10:00:00+02:00',
    ];
}

/** @return array<string, mixed> */
function deliveryPrepare(string $storageRoot): array
{
    $sourceRoot = dirname($storageRoot) . DIRECTORY_SEPARATOR . 'delivery-package';
    if (!mkdir($sourceRoot . DIRECTORY_SEPARATOR . 'media', 0700, true)) {
        throw new RuntimeException('Cannot create delivery source package.');
    }
    if (!mkdir($sourceRoot . '/media/photos', 0700, true) ||
        !mkdir($sourceRoot . '/media/measurements', 0700, true)) {
        throw new RuntimeException('Cannot create delivery media directories.');
    }
    $inspection = deliveryFixtureRead('inspection-example.json');
    $diagnosis = deliveryFixtureRead('diagnosis-example.json');

    $pdfId = 'ev_4444444444444441';
    $unsafeId = 'ev_4444444444444442';
    $internalId = 'ev_4444444444444443';
    $orphanId = 'ev_4444444444444444';
    $largeId = 'ev_4444444444444445';
    $sourceReportId = 'ev_4444444444444446';
    deliveryFixtureAddEvidence($inspection, $diagnosis, $pdfId, 'document', 'client_private', 'active', 'application/pdf', 'media/client.pdf', true);
    deliveryFixtureAddEvidence($inspection, $diagnosis, $unsafeId, 'document', 'client_private', 'active', 'image/svg+xml', 'media/unsafe.svg', true);
    deliveryFixtureAddEvidence($inspection, $diagnosis, $internalId, 'photo', 'internal', 'active', 'image/jpeg', 'media/internal.jpg', true);
    deliveryFixtureAddEvidence($inspection, $diagnosis, $orphanId, 'photo', 'client_private', 'active', 'image/jpeg', 'media/orphan.jpg', false);
    deliveryFixtureAddEvidence($inspection, $diagnosis, $largeId, 'video', 'client_private', 'active', 'video/mp4', 'media/large.mp4', true);
    deliveryFixtureAddEvidence($inspection, $diagnosis, $sourceReportId, 'document', 'client_private', 'active', 'application/pdf', 'media/source-report.pdf', true);

    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'inspection.json', $inspection);
    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'diagnosis.json', $diagnosis);
    file_put_contents($sourceRoot . '/media/photos/downpipe-001.jpg', "\xFF\xD8delivery-photo-one\xFF\xD9");
    file_put_contents($sourceRoot . '/media/measurements/basement-wme-002.json', "{\"measurement\":72}\n");
    file_put_contents($sourceRoot . '/media/photos/wall-deformation-003.jpg', "\xFF\xD8delivery-photo-three-different\xFF\xD9");
    file_put_contents($sourceRoot . '/media/client.pdf', "%PDF-1.4\nSynthetic authorized PDF\n%%EOF\n");
    file_put_contents($sourceRoot . '/media/unsafe.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n");
    file_put_contents($sourceRoot . '/media/internal.jpg', "internal-only-binary\n");
    file_put_contents($sourceRoot . '/media/orphan.jpg', "orphan-private-binary\n");
    deliveryFixtureWriteLarge($sourceRoot . '/media/large.mp4', 6 * 1024 * 1024);
    file_put_contents($sourceRoot . '/media/source-report.pdf', "%PDF-1.4\nInternal source report role\n%%EOF\n");

    $pathByEvidence = [
        'ev_2222222222222221' => ['media/photos/downpipe-001.jpg', 'media', 'image/jpeg', 'client_private'],
        'ev_2222222222222222' => ['media/measurements/basement-wme-002.json', 'attachment', 'application/json', 'client_private'],
        'ev_2222222222222223' => ['media/photos/wall-deformation-003.jpg', 'media', 'image/jpeg', 'client_private'],
        $pdfId => ['media/client.pdf', 'attachment', 'application/pdf', 'client_private'],
        $unsafeId => ['media/unsafe.svg', 'attachment', 'image/svg+xml', 'client_private'],
        $internalId => ['media/internal.jpg', 'media', 'image/jpeg', 'internal'],
        $orphanId => ['media/orphan.jpg', 'media', 'image/jpeg', 'client_private'],
        $largeId => ['media/large.mp4', 'media', 'video/mp4', 'client_private'],
        $sourceReportId => ['media/source-report.pdf', 'source_report', 'application/pdf', 'client_private'],
    ];

    $reportId = 'rpt_4444444444444444';
    $version = '1.0';
    $versionId = 'rptv_4444444444444444';
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
            'change_type' => 'initial',
            'change_summary' => 'Synthetic client delivery test package.',
            'status' => 'published',
            'generated_at' => '2026-08-08T09:30:00+02:00',
            'approved_by' => 'inspector_example',
            'approved_at' => '2026-08-08T09:45:00+02:00',
            'published_at' => '2026-08-08T10:00:00+02:00',
            'renderer_contract_version' => '1.0.0',
            'limitations_snapshot' => $inspection['inspection']['limitations'],
            'unverified_items_snapshot' => [],
        ],
        'actors' => [
            ['id' => 'inspector_example', 'display_name' => 'Synthetic inspector', 'role' => 'inspector'],
        ],
        'files' => [],
        'created_at' => '2026-08-08T10:00:00+02:00',
    ];
    foreach ([['inspection_data', 'inspection.json'], ['diagnosis_data', 'diagnosis.json']] as $sourceFile) {
        $absolute = $sourceRoot . DIRECTORY_SEPARATOR . $sourceFile[1];
        $manifest['files'][] = [
            'role' => $sourceFile[0],
            'path' => $sourceFile[1],
            'sha256' => hash_file('sha256', $absolute),
            'content_type' => 'application/json',
            'size_bytes' => filesize($absolute),
            'privacy' => 'client_private',
        ];
    }
    foreach ($pathByEvidence as $evidenceId => $metadata) {
        [$relativePath, $role, $contentType, $privacy] = $metadata;
        $absolute = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $manifest['files'][] = [
            'role' => $role,
            'path' => $relativePath,
            'sha256' => hash_file('sha256', $absolute),
            'content_type' => $contentType,
            'size_bytes' => filesize($absolute),
            'privacy' => $privacy,
        ];
    }
    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

    $storage = new DiagnosticsStorage($storageRoot, dirname($storageRoot) . DIRECTORY_SEPARATOR . 'webroot');
    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $service = new DiagnosticsAccessService($storage, $config);
    $storage->installPublishedPackage($sourceRoot);
    $grant = $service->createGrant($reportId, $version);

    $pricedReportId = 'rpt_5555555555555555';
    $pricedVersionId = 'rptv_5555555555555555';
    $pricing = deliveryFixturePricing(
        $pricedReportId,
        $pricedVersionId,
        $inspection['id'],
        $diagnosis['issues'][0]['id'],
        $diagnosis['recommendations'][0]['id']
    );
    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'report-pricing.json', $pricing);
    $manifest['report']['id'] = $pricedReportId;
    $manifest['report']['current_published_version_id'] = $pricedVersionId;
    $manifest['report_version']['id'] = $pricedVersionId;
    $manifest['report_version']['report_id'] = $pricedReportId;
    $pricingPath = $sourceRoot . DIRECTORY_SEPARATOR . 'report-pricing.json';
    $manifest['files'][] = [
        'role' => 'report_pricing',
        'path' => 'report-pricing.json',
        'sha256' => hash_file('sha256', $pricingPath),
        'content_type' => 'application/json',
        'size_bytes' => filesize($pricingPath),
        'privacy' => 'client_private',
    ];
    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $storage->installPublishedPackage($sourceRoot);
    $pricedGrant = $service->createGrant($pricedReportId, $version);

    $mismatchedPricingBlocked = false;
    $mismatchedReportId = 'rpt_6666666666666666';
    $mismatchedVersionId = 'rptv_6666666666666666';
    $manifest['report']['id'] = $mismatchedReportId;
    $manifest['report']['current_published_version_id'] = $mismatchedVersionId;
    $manifest['report_version']['id'] = $mismatchedVersionId;
    $manifest['report_version']['report_id'] = $mismatchedReportId;
    $pricing['report_version_id'] = 'rptv_ffffffffffffffff';
    deliveryFixtureWriteJson($pricingPath, $pricing);
    foreach ($manifest['files'] as &$file) {
        if (($file['role'] ?? null) === 'report_pricing') {
            $file['sha256'] = hash_file('sha256', $pricingPath);
            $file['size_bytes'] = filesize($pricingPath);
        }
    }
    unset($file);
    deliveryFixtureWriteJson($sourceRoot . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    try {
        $storage->installPublishedPackage($sourceRoot);
    } catch (DiagnosticsStorageException $error) {
        $mismatchedPricingBlocked = $error->getStorageCode() === 'STORAGE_ID_MISMATCH';
    }
    return array_merge($grant, [
        'report_id' => $reportId,
        'priced_access_id' => $pricedGrant['access_id'],
        'priced_pin' => $pricedGrant['pin'],
        'priced_report_id' => $pricedReportId,
        'mismatched_pricing_blocked' => $mismatchedPricingBlocked,
        'photo_evidence_id' => 'ev_2222222222222221',
        'pdf_evidence_id' => $pdfId,
        'unsafe_evidence_id' => $unsafeId,
        'internal_evidence_id' => $internalId,
        'orphan_evidence_id' => $orphanId,
        'large_evidence_id' => $largeId,
        'source_report_evidence_id' => $sourceReportId,
    ]);
}

$operation = $argv[1] ?? '';
if ($operation === '--prepare' && isset($argv[2])) {
    echo json_encode(deliveryPrepare($argv[2]), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if (in_array($operation, ['--rotate', '--revoke'], true) && isset($argv[2])) {
    $storage = DiagnosticsStorage::fromEnvironment();
    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $service = new DiagnosticsAccessService($storage, $config);
    $result = $operation === '--rotate' ? $service->rotatePin($argv[2]) : $service->revokeGrant($argv[2]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if ($operation === '--create' && isset($argv[2], $argv[3])) {
    $storage = DiagnosticsStorage::fromEnvironment();
    $config = DiagnosticsSecurityConfig::fromEnvironment();
    $service = new DiagnosticsAccessService($storage, $config);
    echo json_encode($service->createGrant($argv[2], $argv[3]), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
fwrite(STDERR, "Usage: php test_diagnostics_delivery_fixture.php --prepare <storage-root>|--create <report-id> <version>|--rotate <access-id>|--revoke <access-id>\n");
exit(2);
