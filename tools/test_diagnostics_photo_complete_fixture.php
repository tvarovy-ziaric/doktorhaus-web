<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsClientProjection;
use DoktorHaus\Diagnostics\DiagnosticsSecurityConfig;
use DoktorHaus\Diagnostics\DiagnosticsStorage;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsSecurityConfig.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsClientProjection.php';

/** @return array<string, mixed> */
function photoCompleteRead(string $name): array
{
    $value = json_decode((string)file_get_contents(__DIR__ . '/../docs/diagnostics/fixtures/valid/' . $name), true);
    if (!is_array($value)) {
        throw new RuntimeException('Cannot read a diagnostics fixture.');
    }
    return $value;
}

/** @param array<string, mixed> $value */
function photoCompleteWriteJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write a diagnostics fixture.');
    }
}

/** @return array<string, mixed> */
function photoCompletePrepare(string $storageRoot, string $webRoot, string $pin): array
{
    if (preg_match('/^[0-9]{6}$/D', $pin) !== 1) {
        throw new RuntimeException('The synthetic PIN is invalid.');
    }
    $packageRoot = dirname($storageRoot) . DIRECTORY_SEPARATOR . 'photo-complete-package';
    if (!mkdir($packageRoot . DIRECTORY_SEPARATOR . 'media', 0700, true) ||
        !mkdir($packageRoot . DIRECTORY_SEPARATOR . 'attachments', 0700, true)) {
        throw new RuntimeException('Cannot create the synthetic package.');
    }

    $inspection = photoCompleteRead('inspection-example.json');
    $diagnosis = photoCompleteRead('diagnosis-example.json');
    $template = $inspection['evidence'][0];
    $inspection['evidence'] = [];
    $inspection['observation_evidence_links'] = [];
    $diagnosis['issue_evidence_links'] = [];
    $diagnosis['hypothesis_evidence_links'] = [];
    $diagnosis['verification_evidence_links'] = [];

    $reportId = 'rpt_7777777777777777';
    $versionId = 'rptv_7777777777777777';
    $version = '1.0';
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
            'change_summary' => 'Synthetic photo-complete report.',
            'status' => 'published',
            'generated_at' => '2026-08-13T12:00:00+02:00',
            'approved_by' => $inspection['actors'][0]['id'],
            'approved_at' => '2026-08-13T12:00:00+02:00',
            'published_at' => '2026-08-13T12:01:00+02:00',
            'renderer_contract_version' => '1.0.0',
            'limitations_snapshot' => $inspection['inspection']['limitations'],
            'unverified_items_snapshot' => [],
        ],
        'actors' => [$inspection['actors'][0]],
        'files' => [],
        'created_at' => '2026-08-13T12:01:00+02:00',
    ];

    $attachments = [];
    $appendixItems = [];
    for ($number = 1; $number <= 71; $number++) {
        $evidenceId = 'ev_' . str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
        $relative = 'media/' . $evidenceId . '.jpg';
        $absolute = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        file_put_contents($absolute, "\xFF\xD8synthetic-photo-" . str_pad((string)$number, 3, '0', STR_PAD_LEFT) . "\xFF\xD9");
        $evidence = $template;
        $evidence['id'] = $evidenceId;
        $evidence['display_code'] = 'EV-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
        $evidence['type'] = 'photo';
        $evidence['title'] = 'Synthetic photo ' . $number;
        $evidence['description'] = 'Synthetic photo-complete delivery evidence.';
        $evidence['privacy'] = 'client_private';
        $evidence['status'] = 'active';
        $evidence['media_reference'] = 'source-photo:' . $number;
        $evidence['content_type'] = 'image/jpeg';
        $inspection['evidence'][] = $evidence;
        $placement = $number <= 53 ? 'linked_evidence' : 'source_documentation_appendix';
        if ($placement === 'linked_evidence') {
            $diagnosis['issue_evidence_links'][] = [
                'id' => 'rel_' . substr(hash('sha256', 'photo-' . $number), 0, 16),
                'issue_id' => $diagnosis['issues'][0]['id'],
                'evidence_id' => $evidenceId,
                'role' => 'supporting',
                'rationale' => 'Synthetic approved photo relation.',
                'created_at' => '2026-08-13T12:00:00+02:00',
                'created_by' => $inspection['actors'][0]['id'],
                'status' => 'active',
            ];
        } else {
            $appendixItems[] = [
                'evidence_id' => $evidenceId,
                'display_code' => $evidence['display_code'],
                'source_caption' => $evidence['title'],
                'media_reference' => $relative,
                'media_url' => 'api/diagnostics-media.php?evidence=' . $evidenceId,
                'content_type' => 'image/jpeg',
                'source_identity' => 'Photo ' . $number,
                'source_pdf_page' => $number,
                'provenance_source_page' => $number,
                'order' => $number,
            ];
        }
        $hash = hash_file('sha256', $absolute);
        $attachments[] = [
            'evidence_id' => $evidenceId,
            'canonical_file' => $relative,
            'sha256' => $hash,
            'content_type' => 'image/jpeg',
            'placement_kind' => $placement,
        ];
        $manifest['files'][] = [
            'role' => 'media',
            'path' => $relative,
            'sha256' => $hash,
            'content_type' => 'image/jpeg',
            'size_bytes' => filesize($absolute),
            'privacy' => 'client_private',
        ];
    }

    photoCompleteWriteJson($packageRoot . '/inspection.json', $inspection);
    photoCompleteWriteJson($packageRoot . '/diagnosis.json', $diagnosis);
    foreach ([['inspection_data', 'inspection.json'], ['diagnosis_data', 'diagnosis.json']] as $file) {
        $absolute = $packageRoot . DIRECTORY_SEPARATOR . $file[1];
        $manifest['files'][] = [
            'role' => $file[0], 'path' => $file[1], 'sha256' => hash_file('sha256', $absolute),
            'content_type' => 'application/json', 'size_bytes' => filesize($absolute), 'privacy' => 'client_private',
        ];
    }

    $projection = new DiagnosticsClientProjection();
    $clientReport = $projection->build($manifest, $inspection, $diagnosis);
    $linkedCount = 0;
    foreach ($clientReport['issues'] as &$issue) {
        foreach ($issue['evidence'] as &$evidence) {
            $evidence['has_media'] = true;
            $evidence['content_type'] = 'image/jpeg';
            $evidence['media_url'] = 'api/diagnostics-media.php?evidence=' . $evidence['id'];
            $linkedCount++;
        }
        unset($evidence);
    }
    unset($issue);
    if ($linkedCount !== 53) {
        throw new RuntimeException('The synthetic linked photo count is invalid.');
    }
    $appendix = [
        'schema_version' => '1.0.0-helper', 'document_type' => 'source_documentation_appendix',
        'title' => 'Zdrojová fotodokumentácia', 'intro' => 'Syntetická zdrojová fotodokumentácia.',
        'report_id' => $reportId, 'report_version_id' => $versionId, 'inspection_id' => $inspection['id'],
        'generated_at' => '2026-08-13T12:01:00+02:00', 'photo_count' => 18, 'items' => $appendixItems,
    ];
    $mediaMap = [
        'schema_version' => '1.0.0-helper', 'document_type' => 'media_attachments',
        'report_id' => $reportId, 'report_version_id' => $versionId, 'inspection_id' => $inspection['id'],
        'generated_at' => '2026-08-13T12:01:00+02:00',
        'counts' => ['total_photos' => 71, 'linked_evidence' => 53, 'source_documentation_appendix' => 18],
        'attachments' => $attachments,
    ];
    $companions = [
        ['client_report', 'attachments/client-report.json', $clientReport, 'client_private'],
        ['source_documentation_appendix', 'attachments/source-documentation-appendix.json', $appendix, 'client_private'],
        ['media_attachments', 'attachments/media-attachments.json', $mediaMap, 'internal'],
    ];
    foreach ($companions as $companion) {
        [$label, $relative, $body, $privacy] = $companion;
        $absolute = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        photoCompleteWriteJson($absolute, $body);
        $manifest['files'][] = [
            'role' => 'attachment', 'role_label' => $label, 'path' => $relative,
            'sha256' => hash_file('sha256', $absolute), 'content_type' => 'application/json',
            'size_bytes' => filesize($absolute), 'privacy' => $privacy,
        ];
    }
    photoCompleteWriteJson($packageRoot . '/manifest.json', $manifest);

    $storage = new DiagnosticsStorage($storageRoot, $webRoot);
    $storage->installPublishedPackage($packageRoot);
    if (!is_dir($webRoot . DIRECTORY_SEPARATOR . 'data') && !mkdir($webRoot . DIRECTORY_SEPARATOR . 'data', 0700, true)) {
        throw new RuntimeException('Cannot create the synthetic inspections directory.');
    }
    photoCompleteWriteJson($webRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'inspections.json', [[
        'id' => 'photo-complete-inspection', 'title' => 'Synthetic photo-complete inspection',
        'location' => 'Test region', 'clientEmail' => '', 'summary' => 'Synthetic one-PIN delivery test.',
        'status' => 'ready', 'pin' => $pin, 'media' => [], 'photos' => [],
        'createdAt' => '2026-08-13T12:00:00+02:00', 'updatedAt' => '2026-08-13T12:00:00+02:00',
    ]]);
    return ['report_id' => $reportId, 'version' => $version, 'inspection_record_id' => 'photo-complete-inspection', 'pin' => $pin];
}

if (($argv[1] ?? '') === '--prepare' && isset($argv[2], $argv[3], $argv[4])) {
    echo json_encode(photoCompletePrepare($argv[2], $argv[3], $argv[4]), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
fwrite(STDERR, "Usage: php test_diagnostics_photo_complete_fixture.php --prepare <storage-root> <web-root> <pin>\n");
exit(2);
