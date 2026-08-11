<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsClientProjection;
use DoktorHaus\Diagnostics\DiagnosticsDeliveryException;
use DoktorHaus\Diagnostics\DiagnosticsMediaDelivery;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsDeliveryException.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsClientProjection.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsMediaDelivery.php';

function projectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function projectionReadJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Cannot read projection fixture: ' . basename($path));
    }
    return $decoded;
}

function expectDeliveryCode(string $code, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DiagnosticsDeliveryException $error) {
        projectionAssert($error->getDeliveryCode() === $code, $message . ' Wrong code: ' . $error->getDeliveryCode());
        return;
    }
    throw new RuntimeException($message . ' No exception was raised.');
}

/** @param mixed $value @param array<string, bool> $forbidden */
function assertNoForbiddenKeys($value, array $forbidden, string $path = '$'): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            projectionAssert(!isset($forbidden[$key]), 'Forbidden key leaked at ' . $path . '.' . $key);
            assertNoForbiddenKeys($child, $forbidden, $path . '.' . $key);
        } else {
            assertNoForbiddenKeys($child, $forbidden, $path . '[' . $key . ']');
        }
    }
}

/** @param array<string, mixed> $inspection @param array<string, mixed> $diagnosis @param array<string, mixed> $manifest */
function addEvidenceCase(
    array &$inspection,
    array &$diagnosis,
    array &$manifest,
    string $id,
    string $privacy,
    string $status,
    bool $linked,
    string $path
): void {
    $evidence = $inspection['evidence'][0];
    $evidence['id'] = $id;
    $evidence['display_code'] = 'EV-' . substr($id, -3);
    $evidence['title'] = 'Synthetic evidence ' . $id;
    $evidence['privacy'] = $privacy;
    $evidence['status'] = $status;
    $evidence['media_reference'] = $path;
    $inspection['evidence'][] = $evidence;
    $manifest['files'][] = [
        'role' => 'media',
        'path' => $path,
        'sha256' => str_repeat(substr($id, -1), 64),
        'content_type' => 'image/jpeg',
        'size_bytes' => 10,
        'privacy' => $privacy,
    ];
    if ($linked) {
        $diagnosis['issue_evidence_links'][] = [
            'id' => 'rel_' . substr(hash('sha256', $id), 0, 16),
            'issue_id' => $diagnosis['issues'][0]['id'],
            'evidence_id' => $id,
            'role' => 'supporting',
            'rationale' => 'Synthetic projection test link.',
            'created_at' => '2026-06-04T11:00:00+02:00',
            'created_by' => 'inspector_example',
            'status' => 'active',
        ];
    }
}

$failure = null;
try {
    $fixtureRoot = __DIR__ . '/../docs/diagnostics/fixtures/valid';
    $inspection = projectionReadJson($fixtureRoot . '/inspection-example.json');
    $diagnosis = projectionReadJson($fixtureRoot . '/diagnosis-example.json');
    $manifest = projectionReadJson($fixtureRoot . '/report-package-example.json');

    $inspection['property']['location']['address_private'] = [
        'privacy' => 'client_private',
        'address_lines' => ['Never expose this exact address'],
        'country_code' => 'SK',
    ];
    $supersededIssue = $diagnosis['issues'][0];
    $supersededIssue['id'] = 'issue_3333333333333333';
    $supersededIssue['display_code'] = 'ISS-OLD';
    $supersededIssue['status'] = 'superseded';
    $diagnosis['issues'][] = $supersededIssue;

    $internalId = 'ev_3333333333333331';
    $withdrawnId = 'ev_3333333333333332';
    $orphanId = 'ev_3333333333333333';
    $publicId = 'ev_3333333333333334';
    addEvidenceCase($inspection, $diagnosis, $manifest, $internalId, 'internal', 'active', true, 'media/internal.jpg');
    addEvidenceCase($inspection, $diagnosis, $manifest, $withdrawnId, 'client_private', 'withdrawn', true, 'media/withdrawn.jpg');
    addEvidenceCase($inspection, $diagnosis, $manifest, $orphanId, 'client_private', 'active', false, 'media/orphan.jpg');
    addEvidenceCase($inspection, $diagnosis, $manifest, $publicId, 'public', 'active', true, 'media/public.jpg');

    $diagnosis['recommendations'] = array_reverse($diagnosis['recommendations']);
    $projectionBuilder = new DiagnosticsClientProjection();
    $report = $projectionBuilder->build($manifest, $inspection, $diagnosis);

    projectionAssert($report['document_type'] === 'client_report', 'Projection document type must be client_report.');
    projectionAssert($report['schema_version'] === '1.0.0', 'Projection schema version must be 1.0.0.');
    projectionAssert($report['report']['version'] === $manifest['report_version']['version'], 'Report version must be preserved.');
    projectionAssert($report['overview']['issue_count'] === 1, 'Superseded issue must not affect issue count.');
    projectionAssert(count($report['issues']) === 1 && $report['issues'][0]['id'] === $diagnosis['issues'][0]['id'], 'Only the visible issue may remain.');
    projectionAssert($report['overview']['priority_counts']['P2'] === 1, 'Priority counts must be deterministic.');
    $sourceSeverity = $diagnosis['issues'][0]['severity'];
    projectionAssert($report['overview']['severity_counts'][$sourceSeverity] === 1, 'Severity counts must be deterministic.');
    projectionAssert($report['overview']['highest_priority'] === 'P2', 'Highest priority must be correct.');
    projectionAssert($report['overview']['highest_severity'] === $sourceSeverity, 'Highest severity must be correct.');

    $clientEvidence = [];
    foreach ($report['issues'][0]['evidence'] as $evidence) {
        $clientEvidence[$evidence['id']] = $evidence;
    }
    projectionAssert(isset($clientEvidence['ev_2222222222222221']), 'Relevant client-private evidence must be included.');
    projectionAssert(isset($clientEvidence[$publicId]), 'Relevant public evidence must be included.');
    projectionAssert(!isset($clientEvidence[$internalId]), 'Internal evidence must be invisible.');
    projectionAssert(!isset($clientEvidence[$withdrawnId]), 'Withdrawn evidence must be invisible.');
    projectionAssert(!isset($clientEvidence[$orphanId]), 'Orphan client-private evidence must be invisible.');
    foreach ($clientEvidence as $evidence) {
        if (!$evidence['has_media']) {
            continue;
        }
        projectionAssert(preg_match('/^api\/diagnostics-media\.php\?evidence=ev_[0-9a-f]{16,32}$/D', $evidence['media_url']) === 1, 'Media URL must contain only an evidence ID.');
        projectionAssert(strpos($evidence['media_url'], $manifest['report']['id']) === false, 'Media URL must not contain a report ID.');
        projectionAssert(strpos($evidence['media_url'], 'media/') === false, 'Media URL must not contain a storage path.');
    }
    $visibleIds = $projectionBuilder->clientVisibleEvidenceIds();
    projectionAssert(in_array($publicId, $visibleIds, true) && !in_array($internalId, $visibleIds, true), 'Visible evidence set must match projection visibility.');
    projectionAssert($projectionBuilder->clientVisibleMedia($internalId) === null, 'Internal media lookup must behave as absent.');
    projectionAssert($projectionBuilder->clientVisibleMedia($orphanId) === null, 'Orphan media lookup must behave as absent.');

    $forbiddenKeys = array_fill_keys([
        'qa', 'actors', 'actor_ids', 'approved_by', 'observed_by', 'captured_by', 'performed_by',
        'provenance', 'import_metadata', 'source_system', 'source_inspection_id', 'source_item_id',
        'source_media_id', 'source_reference', 'source_hash', 'pin', 'pin_hash', 'csrf_token', 'session_id',
        'report_id', 'report_version_id', 'package_manifest_sha256', 'media_reference', 'sha256',
        'address_private', 'storage_path', 'filesystem_path',
        'internal_tariff', 'internal_labour_cost', 'equipment_acquisition_cost', 'travel_costing',
        'margin', 'markup', 'internal_business_notes', 'private_supplier_negotiations',
    ], true);
    assertNoForbiddenKeys($report, $forbiddenKeys);
    projectionAssert(strpos(json_encode($report), 'Never expose this exact address') === false, 'Private address value must not leak.');

    $recommendationIds = array_column($report['recommendations'], 'id');
    projectionAssert($recommendationIds === [
        'rec_2222222222222221',
        'rec_2222222222222222',
        'rec_2222222222222223',
        'rec_2222222222222224',
    ], 'Recommendations must honor topological constraints before source order.');
    projectionAssert(count($report['issues'][0]['impacts']) === 7, 'Each visible issue must contain exactly seven impacts.');
    projectionAssert($report['issues'][0]['summary'] === $diagnosis['issues'][0]['summary'], 'Projection must not generate a new diagnostic summary.');
    projectionAssert($report['issues'][0]['severity'] === $diagnosis['issues'][0]['severity'], 'Projection must not change severity.');
    projectionAssert(count($report['issues'][0]['hypotheses']) === 1, 'Projection must not create hypotheses.');
    projectionAssert(!array_key_exists('rationale', $report['issues'][0]['hypotheses'][0]), 'Hypothesis internal rationale must stay excluded.');
    projectionAssert(!array_key_exists('observed_at', $report['issues'][0]['observations'][0]), 'Observation timestamps are minimized from current view.');
    projectionAssert(!array_key_exists('instrument_id', $report['issues'][0]['observations'][1]['measurement']), 'Instrument IDs must not leak.');
    projectionAssert(!array_key_exists('source_method', $report['issues'][0]['cost_estimate']), 'Internal cost source method must not leak.');

    $cycleDiagnosis = $diagnosis;
    $cycleDiagnosis['recommendation_dependencies'][] = [
        'id' => 'rel_3333333333333399',
        'from_recommendation_id' => 'rec_2222222222222224',
        'to_recommendation_id' => 'rec_2222222222222221',
        'dependency_type' => 'precedes',
        'rationale' => 'Synthetic cycle.',
        'created_at' => '2026-06-04T11:00:00+02:00',
        'created_by' => 'inspector_example',
        'status' => 'active',
    ];
    expectDeliveryCode('DELIVERY_PROJECTION', function () use ($manifest, $inspection, $cycleDiagnosis): void {
        (new DiagnosticsClientProjection())->build($manifest, $inspection, $cycleDiagnosis);
    }, 'Dependency cycle must fail closed.');

    $relationDiagnosis = $diagnosis;
    $relationDiagnosis['issue_relations'][] = [
        'id' => 'rel_3333333333333388',
        'from_issue_id' => $diagnosis['issues'][0]['id'],
        'to_issue_id' => $supersededIssue['id'],
        'relation_type' => 'contributes_to',
        'description' => 'Synthetic hidden relation.',
        'confidence' => 'C2',
        'rationale' => 'Synthetic test.',
        'created_at' => '2026-06-04T11:00:00+02:00',
        'created_by' => 'inspector_example',
        'status' => 'active',
    ];
    expectDeliveryCode('DELIVERY_PROJECTION', function () use ($manifest, $inspection, $relationDiagnosis): void {
        (new DiagnosticsClientProjection())->build($manifest, $inspection, $relationDiagnosis);
    }, 'Relation to a hidden issue must fail closed.');

    $media = new DiagnosticsMediaDelivery();
    $full = $media->parseRange(null, 1000);
    projectionAssert($full === ['status' => 200, 'start' => 0, 'end' => 999, 'length' => 1000, 'partial' => false], 'Full range plan is invalid.');
    $bounded = $media->parseRange('bytes=0-99', 1000);
    projectionAssert($bounded['status'] === 206 && $bounded['start'] === 0 && $bounded['end'] === 99 && $bounded['length'] === 100, 'Bounded range is invalid.');
    $open = $media->parseRange('bytes=100-', 1000);
    projectionAssert($open['start'] === 100 && $open['end'] === 999 && $open['length'] === 900, 'Open-ended range is invalid.');
    $suffix = $media->parseRange('bytes=-100', 1000);
    projectionAssert($suffix['start'] === 900 && $suffix['end'] === 999 && $suffix['length'] === 100, 'Suffix range is invalid.');
    foreach (['bytes=-', 'bytes=100-50', 'bytes=999999-', 'bytes=0-1,3-4', 'bytes=abc', 'bytes=', 'items=1-2'] as $invalidRange) {
        expectDeliveryCode('DELIVERY_RANGE', function () use ($media, $invalidRange): void {
            $media->parseRange($invalidRange, 1000);
        }, 'Invalid range must fail: ' . $invalidRange);
    }
    projectionAssert($media->responseType('ev_2222222222222221', 'image/jpeg')['disposition'] === 'inline; filename="doktorhaus-ev_2222222222222221.jpg"', 'JPEG must be safe inline media.');
    projectionAssert($media->responseType('ev_2222222222222221', 'application/pdf')['inline'] === true, 'PDF must be safe inline media.');
    foreach (['image/svg+xml', 'text/html', 'application/javascript', 'application/x-unknown'] as $unsafeType) {
        $unsafe = $media->responseType('ev_2222222222222221', $unsafeType);
        projectionAssert($unsafe['content_type'] === 'application/octet-stream' && $unsafe['inline'] === false && strpos($unsafe['disposition'], 'attachment;') === 0, 'Unsafe MIME must be a binary attachment.');
    }

    $mediaSource = file_get_contents(__DIR__ . '/../api/lib/diagnostics/DiagnosticsMediaDelivery.php');
    $endpointSource = file_get_contents(__DIR__ . '/../api/diagnostics-media.php');
    projectionAssert(is_string($mediaSource) && strpos($mediaSource, "fopen(\$path, 'rb')") !== false && strpos($mediaSource, 'fread(') !== false, 'Media delivery must stream in chunks.');
    projectionAssert(is_string($mediaSource) && strpos($mediaSource, 'file_get_contents') === false, 'Media delivery must not load a whole binary file.');
    projectionAssert(is_string($endpointSource) && strpos($endpointSource, 'session_write_close()') < strpos($endpointSource, '->stream('), 'Session lock must be released before streaming.');
} catch (Throwable $error) {
    $failure = $error;
}

if ($failure !== null) {
    fwrite(STDERR, 'Diagnostics client projection tests failed: ' . $failure->getMessage() . "\n");
    exit(1);
}

echo "Diagnostics client projection and media unit tests passed.\n";
