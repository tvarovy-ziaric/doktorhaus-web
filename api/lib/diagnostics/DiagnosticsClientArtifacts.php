<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsStorageException.php';

/**
 * Validates optional immutable client companions without widening the
 * client-report 1.0.0 contract or trusting client-supplied selectors.
 */
final class DiagnosticsClientArtifacts
{
    public const CLIENT_REPORT_LABEL = 'client_report';
    public const APPENDIX_LABEL = 'source_documentation_appendix';
    public const MEDIA_MAP_LABEL = 'media_attachments';

    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $inspection
     * @param array<string, array<string, mixed>> $files
     * @param array<string, mixed>|null $clientReport
     * @param array<string, mixed>|null $appendix
     * @param array<string, mixed>|null $mediaMap
     * @return array{client_report: array<string, mixed>|null, source_documentation_appendix: array<string, mixed>|null, media_attachments: array<string, array<string, mixed>>}
     */
    public static function validate(
        array $manifest,
        array $inspection,
        array $files,
        ?array $clientReport,
        ?array $appendix,
        ?array $mediaMap
    ): array {
        $reportId = self::string(self::object($manifest, 'report'), 'id');
        $inspectionId = self::string(self::object($manifest, 'report'), 'inspection_id');
        $reportVersion = self::object($manifest, 'report_version');
        $reportVersionId = self::string($reportVersion, 'id');
        $version = self::string($reportVersion, 'version');

        $inspectionEvidence = [];
        foreach (self::list($inspection, 'evidence') as $evidence) {
            if (!is_array($evidence) || self::isList($evidence)) {
                self::fail('The inspection evidence list is invalid.');
            }
            $evidenceId = self::string($evidence, 'id');
            if (isset($inspectionEvidence[$evidenceId])) {
                self::fail('The inspection evidence identifiers are duplicated.');
            }
            $inspectionEvidence[$evidenceId] = $evidence;
        }

        $media = [];
        if ($mediaMap !== null) {
            if (($mediaMap['schema_version'] ?? null) !== '1.0.0-helper' ||
                ($mediaMap['document_type'] ?? null) !== 'media_attachments' ||
                ($mediaMap['report_id'] ?? null) !== $reportId ||
                ($mediaMap['report_version_id'] ?? null) !== $reportVersionId ||
                ($mediaMap['inspection_id'] ?? null) !== $inspectionId) {
                self::fail('The media attachment companion identity is invalid.');
            }
            $placementCounts = [
                'linked_evidence' => 0,
                'source_documentation_appendix' => 0,
            ];
            foreach (self::list($mediaMap, 'attachments') as $attachment) {
                if (!is_array($attachment) || self::isList($attachment)) {
                    self::fail('A media attachment descriptor is invalid.');
                }
                $evidenceId = self::evidenceId(self::string($attachment, 'evidence_id'));
                $path = self::string($attachment, 'canonical_file');
                $contentType = self::string($attachment, 'content_type');
                $placement = self::string($attachment, 'placement_kind');
                if (isset($media[$evidenceId]) || !isset($inspectionEvidence[$evidenceId]) ||
                    ($inspectionEvidence[$evidenceId]['type'] ?? null) !== 'photo' ||
                    !in_array($inspectionEvidence[$evidenceId]['privacy'] ?? null, ['public', 'client_private'], true) ||
                    !in_array($contentType, self::IMAGE_TYPES, true) ||
                    !in_array($placement, ['linked_evidence', 'source_documentation_appendix'], true)) {
                    self::fail('A media attachment descriptor is not client deliverable.');
                }
                $file = $files[$path] ?? null;
                if (!is_array($file) || ($file['role'] ?? null) !== 'media' ||
                    !in_array($file['privacy'] ?? null, ['public', 'client_private'], true) ||
                    ($file['content_type'] ?? null) !== $contentType ||
                    ($file['sha256'] ?? null) !== ($attachment['sha256'] ?? null)) {
                    self::fail('A media attachment does not match its immutable package file.');
                }
                $media[$evidenceId] = [
                    'evidence_id' => $evidenceId,
                    'media_reference' => $path,
                    'content_type' => $contentType,
                    'placement_kind' => $placement,
                ];
                $placementCounts[$placement]++;
            }
            $counts = self::object($mediaMap, 'counts');
            if (($counts['total_photos'] ?? null) !== count($media) ||
                ($counts['linked_evidence'] ?? null) !== $placementCounts['linked_evidence'] ||
                ($counts['source_documentation_appendix'] ?? null) !== $placementCounts['source_documentation_appendix']) {
                self::fail('The media attachment companion counts are invalid.');
            }
        }

        $linkedIds = [];
        if ($clientReport !== null) {
            self::validateClientReport($clientReport, $version);
            foreach (self::list($clientReport, 'issues') as $issue) {
                if (!is_array($issue) || self::isList($issue)) {
                    self::fail('A client report issue is invalid.');
                }
                foreach (self::list($issue, 'evidence') as $evidence) {
                    if (!is_array($evidence) || self::isList($evidence)) {
                        self::fail('A client report evidence record is invalid.');
                    }
                    $evidenceId = self::evidenceId(self::string($evidence, 'id'));
                    if (($evidence['has_media'] ?? false) !== true) {
                        continue;
                    }
                    if (($evidence['media_url'] ?? null) !== self::mediaUrl($evidenceId) ||
                        !isset($media[$evidenceId]) || $media[$evidenceId]['placement_kind'] !== 'linked_evidence') {
                        self::fail('A client report media reference is not authorized by its companion map.');
                    }
                    $linkedIds[$evidenceId] = true;
                }
            }
        }

        $appendixProjection = null;
        $appendixIds = [];
        if ($appendix !== null) {
            if (($appendix['schema_version'] ?? null) !== '1.0.0-helper' ||
                ($appendix['document_type'] ?? null) !== 'source_documentation_appendix' ||
                ($appendix['report_id'] ?? null) !== $reportId ||
                ($appendix['report_version_id'] ?? null) !== $reportVersionId ||
                ($appendix['inspection_id'] ?? null) !== $inspectionId) {
                self::fail('The source documentation appendix identity is invalid.');
            }
            $items = [];
            $previousOrder = 0;
            foreach (self::list($appendix, 'items') as $item) {
                if (!is_array($item) || self::isList($item)) {
                    self::fail('A source documentation appendix item is invalid.');
                }
                $evidenceId = self::evidenceId(self::string($item, 'evidence_id'));
                $order = self::integer($item, 'order');
                if (isset($appendixIds[$evidenceId]) || !isset($media[$evidenceId]) ||
                    $media[$evidenceId]['placement_kind'] !== 'source_documentation_appendix' ||
                    ($item['media_reference'] ?? null) !== $media[$evidenceId]['media_reference'] ||
                    ($item['media_url'] ?? null) !== self::mediaUrl($evidenceId) ||
                    ($item['content_type'] ?? null) !== $media[$evidenceId]['content_type']) {
                    self::fail('A source documentation appendix item is not authorized by its companion map.');
                }
                if ($order <= $previousOrder) {
                    self::fail('The source documentation appendix order is invalid.');
                }
                $previousOrder = $order;
                $appendixIds[$evidenceId] = true;
                $items[] = [
                    'evidence_id' => $evidenceId,
                    'display_code' => self::string($item, 'display_code'),
                    'source_caption' => self::string($item, 'source_caption'),
                    'media_url' => self::mediaUrl($evidenceId),
                    'content_type' => self::string($item, 'content_type'),
                    'source_identity' => self::string($item, 'source_identity'),
                    'order' => $order,
                ];
            }
            if (($appendix['photo_count'] ?? null) !== count($items)) {
                self::fail('The source documentation appendix count is invalid.');
            }
            $appendixProjection = [
                'schema_version' => '1.0.0-helper',
                'document_type' => 'source_documentation_appendix',
                'title' => self::string($appendix, 'title'),
                'intro' => self::string($appendix, 'intro'),
                'photo_count' => count($items),
                'items' => $items,
            ];
        }

        foreach ($media as $evidenceId => $descriptor) {
            $authorized = $descriptor['placement_kind'] === 'linked_evidence'
                ? isset($linkedIds[$evidenceId])
                : isset($appendixIds[$evidenceId]);
            if (!$authorized) {
                self::fail('The media companion contains an orphan client file.');
            }
        }

        return [
            'client_report' => $clientReport,
            'source_documentation_appendix' => $appendixProjection,
            'media_attachments' => $media,
        ];
    }

    /** @param array<string, mixed> $report */
    private static function validateClientReport(array $report, string $version): void
    {
        if (($report['schema_version'] ?? null) !== '1.0.0' ||
            ($report['document_type'] ?? null) !== 'client_report' ||
            !isset($report['report']) || !is_array($report['report']) ||
            ($report['report']['version'] ?? null) !== $version) {
            self::fail('The immutable client report companion is invalid.');
        }
        self::assertNoInternalFields($report);
    }

    /** @param mixed $value */
    private static function assertNoInternalFields($value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:actor|provenance|source_item|source_media|source_reference|approval|credential|password|pin|session|package|filesystem|sha256|relative_path|canonical_file)/i', $key) === 1) {
                self::fail('The immutable client report companion contains an internal field.');
            }
            self::assertNoInternalFields($item);
        }
    }

    private static function mediaUrl(string $evidenceId): string
    {
        return 'api/diagnostics-media.php?evidence=' . $evidenceId;
    }

    private static function evidenceId(string $value): string
    {
        if (preg_match('/^ev_[0-9a-f]{16,32}$/D', $value) !== 1) {
            self::fail('An evidence identifier is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private static function object(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || self::isList($value)) {
            self::fail('A required object is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $source @return array<int, mixed> */
    private static function list(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || !self::isList($value)) {
            self::fail('A required list is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            self::fail('A required text value is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function integer(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            self::fail('A required integer value is invalid.');
        }
        return $value;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function fail(string $message): void
    {
        throw new DiagnosticsStorageException('STORAGE_MANIFEST', $message);
    }
}
