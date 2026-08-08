<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsDeliveryException.php';

final class DiagnosticsClientProjection
{
    private const IMPACT_DIMENSIONS = [
        'safety',
        'structural',
        'moisture',
        'health',
        'durability',
        'usability',
        'financial',
    ];

    /** @var array<string, bool> */
    private $visibleEvidenceIds = [];

    /** @var array<string, array{media_reference: string, content_type: string}> */
    private $visibleMedia = [];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $inspection
     * @param array<string, mixed> $diagnosis
     * @return array<string, mixed>
     */
    public function build(array $manifest, array $inspection, array $diagnosis): array
    {
        $this->visibleEvidenceIds = [];
        $this->visibleMedia = [];
        $this->assertIdentity($manifest, $inspection, $diagnosis);

        $reportVersion = $this->object($manifest, 'report_version');
        $propertySource = $this->object($inspection, 'property');
        $inspectionSource = $this->object($inspection, 'inspection');
        $manifestFiles = $this->manifestFileIndex($this->list($manifest, 'files'));

        $observationIndex = $this->indexById($this->list($inspection, 'observations'), 'observation');
        $evidenceIndex = $this->indexById($this->list($inspection, 'evidence'), 'evidence');
        $issueIndex = $this->indexById($this->list($diagnosis, 'issues'), 'issue');
        $hypothesisIndex = $this->indexById($this->list($diagnosis, 'hypotheses'), 'hypothesis');
        $verificationIndex = $this->indexById($this->list($diagnosis, 'verifications'), 'verification');
        $recommendationIndex = $this->indexById($this->list($diagnosis, 'recommendations'), 'recommendation');

        $visibleIssueSources = [];
        foreach ($issueIndex as $issueId => $issue) {
            if ($this->string($issue, 'status') !== 'superseded') {
                $visibleIssueSources[$issueId] = $issue;
            }
        }
        uasort($visibleIssueSources, function (array $left, array $right): int {
            $priority = $this->priorityRank($this->string($left, 'priority')) <=>
                $this->priorityRank($this->string($right, 'priority'));
            return $priority !== 0 ? $priority : strcmp($this->stableKey($left), $this->stableKey($right));
        });
        $visibleIssueIds = array_fill_keys(array_keys($visibleIssueSources), true);

        $issueObservationIds = array_fill_keys(array_keys($visibleIssueSources), []);
        $observationIssueIds = [];
        foreach ($this->list($diagnosis, 'issue_observation_links') as $link) {
            $link = $this->asObject($link, 'issue observation link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $issueId = $this->string($link, 'issue_id');
            $observationId = $this->string($link, 'observation_id');
            if (!isset($issueIndex[$issueId]) || !isset($observationIndex[$observationId])) {
                $this->fail('An active issue observation link is dangling.');
            }
            if (!isset($visibleIssueIds[$issueId]) || $this->string($observationIndex[$observationId], 'status') !== 'active') {
                continue;
            }
            $issueObservationIds[$issueId][$observationId] = true;
            $observationIssueIds[$observationId][$issueId] = true;
        }

        $issueEvidenceIds = array_fill_keys(array_keys($visibleIssueSources), []);
        foreach ($this->list($diagnosis, 'issue_evidence_links') as $link) {
            $link = $this->asObject($link, 'issue evidence link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $issueId = $this->string($link, 'issue_id');
            $evidenceId = $this->string($link, 'evidence_id');
            if (!isset($issueIndex[$issueId]) || !isset($evidenceIndex[$evidenceId])) {
                $this->fail('An active issue evidence link is dangling.');
            }
            if (isset($visibleIssueIds[$issueId])) {
                $issueEvidenceIds[$issueId][$evidenceId] = true;
            }
        }

        foreach ($this->list($inspection, 'observation_evidence_links') as $link) {
            $link = $this->asObject($link, 'observation evidence link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $observationId = $this->string($link, 'observation_id');
            $evidenceId = $this->string($link, 'evidence_id');
            if (!isset($observationIndex[$observationId]) || !isset($evidenceIndex[$evidenceId])) {
                $this->fail('An active observation evidence link is dangling.');
            }
            foreach (array_keys($observationIssueIds[$observationId] ?? []) as $issueId) {
                $issueEvidenceIds[$issueId][$evidenceId] = true;
            }
        }

        $visibleHypothesisIds = [];
        $hypothesesByIssue = array_fill_keys(array_keys($visibleIssueSources), []);
        foreach ($hypothesisIndex as $hypothesisId => $hypothesis) {
            $issueId = $this->string($hypothesis, 'diagnostic_issue_id');
            if (!isset($issueIndex[$issueId])) {
                $this->fail('A hypothesis references an unknown issue.');
            }
            if (isset($visibleIssueIds[$issueId])) {
                $visibleHypothesisIds[$hypothesisId] = true;
                $hypothesesByIssue[$issueId][] = $hypothesis;
            }
        }

        $verificationIssueIds = $this->linkedIssueIdsForVerifications(
            $diagnosis,
            $verificationIndex,
            $issueIndex,
            $hypothesisIndex,
            $visibleIssueIds,
            $visibleHypothesisIds
        );
        $visibleVerificationSources = [];
        foreach ($verificationIndex as $verificationId => $verification) {
            if (($verificationIssueIds[$verificationId] ?? []) !== []) {
                $visibleVerificationSources[$verificationId] = $verification;
            }
        }
        uasort($visibleVerificationSources, function (array $left, array $right): int {
            return strcmp($this->stableKey($left), $this->stableKey($right));
        });

        $recommendationIssueIds = $this->linkedIssueIdsForRecommendations(
            $diagnosis,
            $recommendationIndex,
            $issueIndex,
            $hypothesisIndex,
            $visibleIssueIds,
            $visibleHypothesisIds
        );
        $visibleRecommendationSources = [];
        foreach ($recommendationIndex as $recommendationId => $recommendation) {
            if ($this->string($recommendation, 'status') !== 'cancelled' &&
                ($recommendationIssueIds[$recommendationId] ?? []) !== []) {
                $visibleRecommendationSources[$recommendationId] = $recommendation;
            }
        }
        $orderedRecommendationIds = $this->topologicalRecommendationOrder(
            $visibleRecommendationSources,
            $recommendationIndex,
            $this->list($diagnosis, 'recommendation_dependencies')
        );
        $recommendationPosition = array_flip($orderedRecommendationIds);
        $dependsOn = $this->recommendationDependencies(
            $visibleRecommendationSources,
            $recommendationIndex,
            $this->list($diagnosis, 'recommendation_dependencies'),
            $recommendationPosition
        );

        $verifications = [];
        foreach ($visibleVerificationSources as $verificationId => $verification) {
            $verifications[] = $this->projectVerification($verification);
        }
        $verificationOrder = [];
        foreach ($verifications as $position => $verification) {
            $verificationOrder[$verification['id']] = $position;
        }

        $recommendations = [];
        foreach ($orderedRecommendationIds as $recommendationId) {
            $recommendations[] = $this->projectRecommendation(
                $visibleRecommendationSources[$recommendationId],
                $recommendationIssueIds[$recommendationId],
                $dependsOn[$recommendationId] ?? []
            );
        }

        $impactsByIssue = array_fill_keys(array_keys($visibleIssueSources), []);
        foreach ($this->list($diagnosis, 'impacts') as $impact) {
            $impact = $this->asObject($impact, 'impact');
            $issueId = $this->string($impact, 'diagnostic_issue_id');
            if (!isset($issueIndex[$issueId])) {
                $this->fail('An impact references an unknown issue.');
            }
            if (isset($visibleIssueIds[$issueId])) {
                $impactsByIssue[$issueId][] = $impact;
            }
        }

        $issues = [];
        $unverifiedItems = [];
        foreach ($visibleIssueSources as $issueId => $issue) {
            $observations = [];
            $observationIds = array_keys($issueObservationIds[$issueId]);
            usort($observationIds, function (string $left, string $right) use ($observationIndex): int {
                return strcmp($this->stableKey($observationIndex[$left]), $this->stableKey($observationIndex[$right]));
            });
            foreach ($observationIds as $observationId) {
                $observations[] = $this->projectObservation($observationIndex[$observationId]);
            }

            $evidence = [];
            $candidateEvidenceIds = array_keys($issueEvidenceIds[$issueId]);
            usort($candidateEvidenceIds, function (string $left, string $right) use ($evidenceIndex): int {
                return strcmp($this->stableKey($evidenceIndex[$left]), $this->stableKey($evidenceIndex[$right]));
            });
            foreach ($candidateEvidenceIds as $evidenceId) {
                $sourceEvidence = $evidenceIndex[$evidenceId];
                if (!$this->isClientVisibleEvidence($sourceEvidence)) {
                    continue;
                }
                $evidence[] = $this->projectEvidence($sourceEvidence, $manifestFiles);
                $this->visibleEvidenceIds[$evidenceId] = true;
            }

            $hypotheses = $hypothesesByIssue[$issueId];
            usort($hypotheses, function (array $left, array $right): int {
                return strcmp($this->stableKey($left), $this->stableKey($right));
            });
            $projectedHypotheses = [];
            foreach ($hypotheses as $hypothesis) {
                $projectedHypotheses[] = $this->projectHypothesis($hypothesis);
            }

            $projectedImpacts = $this->projectImpacts($impactsByIssue[$issueId]);
            $missingInformation = [];
            foreach ($this->list($issue, 'missing_information') as $missing) {
                $projectedMissing = $this->projectMissingInformation($this->asObject($missing, 'missing information'));
                $missingInformation[] = $projectedMissing;
                $unverifiedItems[] = array_merge(['issue_id' => $issueId], $projectedMissing);
            }

            $issueVerificationIds = [];
            foreach ($verificationIssueIds as $verificationId => $linkedIssueIds) {
                if (isset($visibleVerificationSources[$verificationId]) && in_array($issueId, $linkedIssueIds, true)) {
                    $issueVerificationIds[] = $verificationId;
                }
            }
            usort($issueVerificationIds, function (string $left, string $right) use ($verificationOrder): int {
                return $verificationOrder[$left] <=> $verificationOrder[$right];
            });

            $issueRecommendationIds = [];
            foreach ($recommendationIssueIds as $recommendationId => $linkedIssueIds) {
                if (isset($visibleRecommendationSources[$recommendationId]) && in_array($issueId, $linkedIssueIds, true)) {
                    $issueRecommendationIds[] = $recommendationId;
                }
            }
            usort($issueRecommendationIds, function (string $left, string $right) use ($recommendationPosition): int {
                return $recommendationPosition[$left] <=> $recommendationPosition[$right];
            });

            $issues[] = $this->projectIssue(
                $issue,
                $observations,
                $evidence,
                $projectedHypotheses,
                $projectedImpacts,
                $missingInformation,
                $issueVerificationIds,
                $issueRecommendationIds
            );
        }

        $issueRelations = $this->projectIssueRelations(
            $this->list($diagnosis, 'issue_relations'),
            $issueIndex,
            $visibleIssueIds
        );

        return [
            'schema_version' => '1.0.0',
            'document_type' => 'client_report',
            'report' => $this->projectReport($reportVersion),
            'property' => $this->projectProperty($propertySource),
            'inspection' => $this->projectInspection($inspectionSource),
            'overview' => $this->buildOverview($issues, $recommendations, count($unverifiedItems)),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'verifications' => $verifications,
            'issue_relations' => $issueRelations,
            'unverified_items' => $unverifiedItems,
            'generated_at' => $this->string($reportVersion, 'generated_at'),
        ];
    }

    /** @return array<int, string> */
    public function clientVisibleEvidenceIds(): array
    {
        $ids = array_keys($this->visibleEvidenceIds);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return array{media_reference: string, content_type: string}|null */
    public function clientVisibleMedia(string $evidenceId): ?array
    {
        if (!isset($this->visibleEvidenceIds[$evidenceId], $this->visibleMedia[$evidenceId])) {
            return null;
        }
        return $this->visibleMedia[$evidenceId];
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $inspection @param array<string, mixed> $diagnosis */
    private function assertIdentity(array $manifest, array $inspection, array $diagnosis): void
    {
        if (($manifest['schema_version'] ?? null) !== '1.0.0' ||
            ($manifest['document_type'] ?? null) !== 'report_package' ||
            ($inspection['schema_version'] ?? null) !== '1.0.0' ||
            ($inspection['document_type'] ?? null) !== 'inspection' ||
            ($diagnosis['schema_version'] ?? null) !== '1.0.0' ||
            ($diagnosis['document_type'] ?? null) !== 'diagnosis') {
            $this->fail('The delivery source contract is unsupported.');
        }
        $report = $this->object($manifest, 'report');
        $reportVersion = $this->object($manifest, 'report_version');
        $inspectionId = $this->string($inspection, 'id');
        if ($this->string($report, 'inspection_id') !== $inspectionId ||
            $this->string($diagnosis, 'id') !== $inspectionId ||
            $this->string($diagnosis, 'inspection_id') !== $inspectionId ||
            $this->string($reportVersion, 'report_id') !== $this->string($report, 'id') ||
            $this->string($reportVersion, 'status') !== 'published') {
            $this->fail('The delivery source identities do not match.');
        }
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectReport(array $source): array
    {
        $result = [
            'version' => $this->string($source, 'version'),
            'change_type' => $this->string($source, 'change_type'),
            'change_summary' => $this->string($source, 'change_summary'),
            'published_at' => $this->string($source, 'published_at'),
            'renderer_contract_version' => $this->string($source, 'renderer_contract_version'),
        ];
        $this->copyOptionalString($result, $source, 'approved_at');
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectProperty(array $source): array
    {
        $location = $this->object($source, 'location');
        $projectedLocation = [
            'country_code' => $this->string($location, 'country_code'),
            'region' => $this->string($location, 'region'),
        ];
        $this->copyOptionalString($projectedLocation, $location, 'municipality');
        $this->copyOptionalString($projectedLocation, $location, 'district');
        return [
            'display_name' => $this->string($source, 'display_name'),
            'property_type' => $this->string($source, 'property_type'),
            'location' => $projectedLocation,
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectInspection(array $source): array
    {
        return [
            'inspection_type' => $this->string($source, 'inspection_type'),
            'performed_at' => $this->string($source, 'performed_at'),
            'scope' => $this->stringList($source, 'scope'),
            'limitations' => $this->stringList($source, 'limitations'),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<string, mixed>> $observations
     * @param array<int, array<string, mixed>> $evidence
     * @param array<int, array<string, mixed>> $hypotheses
     * @param array<int, array<string, mixed>> $impacts
     * @param array<int, array<string, mixed>> $missingInformation
     * @param array<int, string> $verificationIds
     * @param array<int, string> $recommendationIds
     * @return array<string, mixed>
     */
    private function projectIssue(
        array $source,
        array $observations,
        array $evidence,
        array $hypotheses,
        array $impacts,
        array $missingInformation,
        array $verificationIds,
        array $recommendationIds
    ): array {
        $result = [
            'id' => $this->string($source, 'id'),
            'title' => $this->string($source, 'title'),
            'category' => $this->string($source, 'category'),
            'affected_areas' => $this->projectAreas($this->list($source, 'affected_areas')),
            'summary' => $this->string($source, 'summary'),
            'severity' => $this->string($source, 'severity'),
            'severity_rationale' => $this->string($source, 'severity_rationale'),
            'likelihood' => $this->string($source, 'likelihood'),
            'likelihood_subject' => $this->string($source, 'likelihood_subject'),
            'likelihood_subject_kind' => $this->string($source, 'likelihood_subject_kind'),
            'likelihood_rationale' => $this->string($source, 'likelihood_rationale'),
            'urgency' => $this->string($source, 'urgency'),
            'urgency_rationale' => $this->string($source, 'urgency_rationale'),
            'priority' => $this->string($source, 'priority'),
            'priority_rationale' => $this->string($source, 'priority_rationale'),
            'confidence' => $this->string($source, 'confidence'),
            'deterioration_rate' => $this->string($source, 'deterioration_rate'),
            'deterioration_rationale' => $this->string($source, 'deterioration_rationale'),
            'short_term_risk' => $this->projectRisk($this->object($source, 'short_term_risk')),
            'long_term_risk' => $this->projectRisk($this->object($source, 'long_term_risk')),
            'cost_estimate' => $this->projectCostEstimate($this->object($source, 'cost_estimate')),
            'cost_escalation' => $this->projectCostEscalation($this->object($source, 'cost_escalation')),
            'status' => $this->string($source, 'status'),
            'limitations' => $this->stringList($source, 'limitations'),
            'missing_information' => $missingInformation,
            'observations' => $observations,
            'evidence' => $evidence,
            'hypotheses' => $hypotheses,
            'impacts' => $impacts,
            'verification_ids' => $verificationIds,
            'recommendation_ids' => $recommendationIds,
        ];
        $this->copyOptionalString($result, $source, 'display_code');
        $this->copyOptionalString($result, $source, 'category_label');
        $this->copyOptionalString($result, $source, 'interpretation');
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectObservation(array $source): array
    {
        $result = [
            'id' => $this->string($source, 'id'),
            'statement' => $this->string($source, 'statement'),
            'type' => $this->string($source, 'type'),
            'area' => $this->projectArea($this->object($source, 'area')),
            'status' => $this->string($source, 'status'),
            'limitations' => $this->stringList($source, 'limitations'),
        ];
        $this->copyOptionalString($result, $source, 'display_code');
        if (array_key_exists('measurement', $source)) {
            $result['measurement'] = $this->projectMeasurement($this->object($source, 'measurement'));
        }
        return $result;
    }

    /** @param array<string, mixed> $source @param array<string, array<string, mixed>> $manifestFiles @return array<string, mixed> */
    private function projectEvidence(array $source, array $manifestFiles): array
    {
        $evidenceId = $this->string($source, 'id');
        $mediaReference = $source['media_reference'] ?? null;
        $manifestEntry = is_string($mediaReference) && isset($manifestFiles[$mediaReference])
            ? $manifestFiles[$mediaReference]
            : null;
        $hasMedia = is_array($manifestEntry) &&
            in_array($manifestEntry['role'] ?? null, ['media', 'attachment'], true) &&
            in_array($manifestEntry['privacy'] ?? null, ['public', 'client_private'], true) &&
            isset($manifestEntry['content_type']) && is_string($manifestEntry['content_type']);

        $result = [
            'id' => $evidenceId,
            'type' => $this->string($source, 'type'),
            'title' => $this->string($source, 'title'),
            'description' => $this->string($source, 'description'),
            'has_media' => $hasMedia,
        ];
        $this->copyOptionalString($result, $source, 'display_code');
        if ($hasMedia) {
            $contentType = (string)$manifestEntry['content_type'];
            $result['content_type'] = $contentType;
            $result['media_url'] = 'api/diagnostics-media.php?evidence=' . rawurlencode($evidenceId);
            $this->visibleMedia[$evidenceId] = [
                'media_reference' => (string)$mediaReference,
                'content_type' => $contentType,
            ];
        } else {
            $this->copyOptionalString($result, $source, 'content_type');
        }
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectHypothesis(array $source): array
    {
        $result = [
            'id' => $this->string($source, 'id'),
            'statement' => $this->string($source, 'statement'),
            'mechanism' => $this->string($source, 'mechanism'),
            'confidence' => $this->string($source, 'confidence'),
            'status' => $this->string($source, 'status'),
        ];
        $this->copyOptionalString($result, $source, 'display_code');
        return $result;
    }

    /** @param array<int, array<string, mixed>> $sources @return array<int, array<string, mixed>> */
    private function projectImpacts(array $sources): array
    {
        if (count($sources) !== count(self::IMPACT_DIMENSIONS)) {
            $this->fail('A client-visible issue must contain exactly seven impacts.');
        }
        $byDimension = [];
        foreach ($sources as $source) {
            $dimension = $this->string($source, 'dimension');
            if (!in_array($dimension, self::IMPACT_DIMENSIONS, true) || isset($byDimension[$dimension])) {
                $this->fail('A client-visible issue has invalid impact dimensions.');
            }
            $byDimension[$dimension] = [
                'dimension' => $dimension,
                'level' => $this->string($source, 'level'),
                'description' => $this->string($source, 'description'),
                'time_horizon' => $this->string($source, 'time_horizon'),
                'confidence' => $this->string($source, 'confidence'),
                'rationale' => $this->string($source, 'rationale'),
            ];
        }
        if (count($byDimension) !== count(self::IMPACT_DIMENSIONS)) {
            $this->fail('A client-visible issue has incomplete impact dimensions.');
        }
        $result = [];
        foreach (self::IMPACT_DIMENSIONS as $dimension) {
            $result[] = $byDimension[$dimension];
        }
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectVerification(array $source): array
    {
        $result = [
            'id' => $this->string($source, 'id'),
            'verification_type' => $this->string($source, 'verification_type'),
            'method' => $this->string($source, 'method'),
            'purpose' => $this->string($source, 'purpose'),
            'status' => $this->string($source, 'status'),
            'limitations' => $this->stringList($source, 'limitations'),
            'specialist_required' => $this->boolean($source, 'specialist_required'),
        ];
        $this->copyOptionalString($result, $source, 'display_code');
        $this->copyOptionalString($result, $source, 'result_summary');
        if ($result['status'] === 'completed') {
            $this->copyOptionalString($result, $source, 'performed_at');
        }
        if (array_key_exists('responsible_specialty', $source)) {
            $result['responsible_specialty'] = $this->projectSpecialty($this->object($source, 'responsible_specialty'));
        }
        return $result;
    }

    /** @param array<string, mixed> $source @param array<int, string> $issueIds @param array<int, array<string, string>> $dependsOn @return array<string, mixed> */
    private function projectRecommendation(array $source, array $issueIds, array $dependsOn): array
    {
        sort($issueIds, SORT_STRING);
        $result = [
            'id' => $this->string($source, 'id'),
            'display_code' => $this->string($source, 'display_code'),
            'type' => $this->string($source, 'type'),
            'title' => $this->string($source, 'title'),
            'description' => $this->string($source, 'description'),
            'rationale' => $this->string($source, 'rationale'),
            'status' => $this->string($source, 'status'),
            'target_timeframe' => $this->projectTargetTimeframe($this->object($source, 'target_timeframe')),
            'responsible_specialty' => $this->projectSpecialty($this->object($source, 'responsible_specialty')),
            'acceptance_or_follow_up' => $this->string($source, 'acceptance_or_follow_up'),
            'conditional' => $this->boolean($source, 'conditional'),
            'issue_ids' => $issueIds,
            'depends_on' => $dependsOn,
        ];
        $this->copyOptionalString($result, $source, 'condition_description');
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectTargetTimeframe(array $source): array
    {
        $result = [
            'urgency' => $this->string($source, 'urgency'),
            'recommended_by' => $this->string($source, 'recommended_by'),
            'text' => $this->string($source, 'text'),
        ];
        $this->copyOptionalString($result, $source, 'recommended_before');
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectMissingInformation(array $source): array
    {
        $result = [
            'statement' => $this->string($source, 'statement'),
            'why_it_matters' => $this->string($source, 'why_it_matters'),
            'how_to_obtain' => $this->string($source, 'how_to_obtain'),
            'blocking' => $this->boolean($source, 'blocking'),
        ];
        if (array_key_exists('recommended_specialty', $source)) {
            $result['recommended_specialty'] = $this->projectSpecialty($this->object($source, 'recommended_specialty'));
        }
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectRisk(array $source): array
    {
        return [
            'level' => $this->string($source, 'level'),
            'description' => $this->string($source, 'description'),
            'horizon' => $this->string($source, 'horizon'),
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectCostEstimate(array $source): array
    {
        $status = $this->string($source, 'status');
        if ($status === 'not_estimated') {
            return ['status' => $status, 'reason' => $this->string($source, 'reason')];
        }
        if ($status !== 'estimated') {
            $this->fail('A client cost estimate has an invalid status.');
        }
        return [
            'status' => $status,
            'min' => $this->number($source, 'min'),
            'expected' => $this->number($source, 'expected'),
            'max' => $this->number($source, 'max'),
            'currency' => $this->string($source, 'currency'),
            'confidence' => $this->string($source, 'confidence'),
            'price_basis_date' => $this->string($source, 'price_basis_date'),
            'scope' => $this->string($source, 'scope'),
            'assumptions' => $this->stringList($source, 'assumptions'),
            'exclusions' => $this->stringList($source, 'exclusions'),
            'vat_status' => $this->string($source, 'vat_status'),
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectCostEscalation(array $source): array
    {
        return [
            'level' => $this->string($source, 'level'),
            'mechanism' => $this->string($source, 'mechanism'),
            'trigger' => $this->string($source, 'trigger'),
            'preventive_step' => $this->string($source, 'preventive_step'),
            'confidence' => $this->string($source, 'confidence'),
            'rationale' => $this->string($source, 'rationale'),
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectMeasurement(array $source): array
    {
        $result = [
            'quantity' => $this->string($source, 'quantity'),
            'value' => $this->number($source, 'value'),
            'unit_kind' => $this->string($source, 'unit_kind'),
            'unit_code' => $this->string($source, 'unit_code'),
            'method' => $this->string($source, 'method'),
            'measured_at' => $this->string($source, 'measured_at'),
        ];
        foreach (['unit_label', 'instrument', 'conditions', 'notes'] as $key) {
            $this->copyOptionalString($result, $source, $key);
        }
        foreach (['uncertainty', 'reference_min', 'reference_max'] as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $this->number($source, $key);
            }
        }
        return $result;
    }

    /** @param array<int, mixed> $sources @return array<int, array<string, mixed>> */
    private function projectAreas(array $sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            $result[] = $this->projectArea($this->asObject($source, 'area'));
        }
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectArea(array $source): array
    {
        $result = [
            'area_type' => $this->string($source, 'area_type'),
            'label' => $this->string($source, 'label'),
        ];
        foreach (['level', 'floor', 'room', 'orientation'] as $key) {
            $this->copyOptionalString($result, $source, $key);
        }
        return $result;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function projectSpecialty(array $source): array
    {
        $result = ['specialty' => $this->string($source, 'specialty')];
        $this->copyOptionalString($result, $source, 'label');
        return $result;
    }

    /** @param array<int, mixed> $relations @param array<string, array<string, mixed>> $issueIndex @param array<string, bool> $visibleIssueIds @return array<int, array<string, mixed>> */
    private function projectIssueRelations(array $relations, array $issueIndex, array $visibleIssueIds): array
    {
        $result = [];
        foreach ($relations as $relation) {
            $relation = $this->asObject($relation, 'issue relation');
            if ($this->string($relation, 'status') !== 'active') {
                continue;
            }
            $from = $this->string($relation, 'from_issue_id');
            $to = $this->string($relation, 'to_issue_id');
            if (!isset($issueIndex[$from], $issueIndex[$to]) || !isset($visibleIssueIds[$from], $visibleIssueIds[$to])) {
                $this->fail('An active client issue relation is dangling or hidden.');
            }
            $result[] = [
                'from_issue_id' => $from,
                'to_issue_id' => $to,
                'relation_type' => $this->string($relation, 'relation_type'),
                'description' => $this->string($relation, 'description'),
                'confidence' => $this->string($relation, 'confidence'),
            ];
        }
        usort($result, function (array $left, array $right): int {
            return strcmp(
                $left['from_issue_id'] . '|' . $left['to_issue_id'] . '|' . $left['relation_type'],
                $right['from_issue_id'] . '|' . $right['to_issue_id'] . '|' . $right['relation_type']
            );
        });
        return $result;
    }

    /** @param array<int, array<string, mixed>> $issues @param array<int, array<string, mixed>> $recommendations @return array<string, mixed> */
    private function buildOverview(array $issues, array $recommendations, int $unverifiedCount): array
    {
        $priorityCounts = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0, 'P5' => 0];
        $severityCounts = ['S1' => 0, 'S2' => 0, 'S3' => 0, 'S4' => 0, 'S5' => 0];
        $highestPriority = null;
        $highestSeverity = null;
        foreach ($issues as $issue) {
            $priority = (string)$issue['priority'];
            $severity = (string)$issue['severity'];
            if (!array_key_exists($priority, $priorityCounts) || !array_key_exists($severity, $severityCounts)) {
                $this->fail('A client issue contains an invalid priority or severity.');
            }
            $priorityCounts[$priority]++;
            $severityCounts[$severity]++;
            if ($highestPriority === null || $this->priorityRank($priority) < $this->priorityRank($highestPriority)) {
                $highestPriority = $priority;
            }
            if ($highestSeverity === null || $this->severityRank($severity) > $this->severityRank($highestSeverity)) {
                $highestSeverity = $severity;
            }
        }
        $immediate = 0;
        $verification = 0;
        foreach ($recommendations as $recommendation) {
            $immediate += $recommendation['type'] === 'IMMEDIATE' ? 1 : 0;
            $verification += $recommendation['type'] === 'VERIFY' ? 1 : 0;
        }
        return [
            'issue_count' => count($issues),
            'priority_counts' => $priorityCounts,
            'severity_counts' => $severityCounts,
            'immediate_recommendation_count' => $immediate,
            'verification_recommendation_count' => $verification,
            'unverified_item_count' => $unverifiedCount,
            'highest_priority' => $highestPriority,
            'highest_severity' => $highestSeverity,
        ];
    }

    /** @param array<string, array<string, mixed>> $verificationIndex @param array<string, array<string, mixed>> $issueIndex @param array<string, array<string, mixed>> $hypothesisIndex @param array<string, bool> $visibleIssueIds @param array<string, bool> $visibleHypothesisIds @return array<string, array<int, string>> */
    private function linkedIssueIdsForVerifications(array $diagnosis, array $verificationIndex, array $issueIndex, array $hypothesisIndex, array $visibleIssueIds, array $visibleHypothesisIds): array
    {
        $result = array_fill_keys(array_keys($verificationIndex), []);
        foreach ($this->list($diagnosis, 'verification_issue_links') as $link) {
            $link = $this->asObject($link, 'verification issue link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $verificationId = $this->string($link, 'verification_id');
            $issueId = $this->string($link, 'issue_id');
            if (!isset($verificationIndex[$verificationId], $issueIndex[$issueId])) {
                $this->fail('An active verification issue link is dangling.');
            }
            if (isset($visibleIssueIds[$issueId])) {
                $result[$verificationId][$issueId] = true;
            }
        }
        foreach ($this->list($diagnosis, 'verification_hypothesis_links') as $link) {
            $link = $this->asObject($link, 'verification hypothesis link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $verificationId = $this->string($link, 'verification_id');
            $hypothesisId = $this->string($link, 'hypothesis_id');
            if (!isset($verificationIndex[$verificationId], $hypothesisIndex[$hypothesisId])) {
                $this->fail('An active verification hypothesis link is dangling.');
            }
            if (isset($visibleHypothesisIds[$hypothesisId])) {
                $result[$verificationId][$this->string($hypothesisIndex[$hypothesisId], 'diagnostic_issue_id')] = true;
            }
        }
        foreach ($result as $id => $ids) {
            $result[$id] = array_keys($ids);
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $recommendationIndex @param array<string, array<string, mixed>> $issueIndex @param array<string, array<string, mixed>> $hypothesisIndex @param array<string, bool> $visibleIssueIds @param array<string, bool> $visibleHypothesisIds @return array<string, array<int, string>> */
    private function linkedIssueIdsForRecommendations(array $diagnosis, array $recommendationIndex, array $issueIndex, array $hypothesisIndex, array $visibleIssueIds, array $visibleHypothesisIds): array
    {
        $result = array_fill_keys(array_keys($recommendationIndex), []);
        foreach ($this->list($diagnosis, 'recommendation_issue_links') as $link) {
            $link = $this->asObject($link, 'recommendation issue link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $recommendationId = $this->string($link, 'recommendation_id');
            $issueId = $this->string($link, 'issue_id');
            if (!isset($recommendationIndex[$recommendationId], $issueIndex[$issueId])) {
                $this->fail('An active recommendation issue link is dangling.');
            }
            if (isset($visibleIssueIds[$issueId])) {
                $result[$recommendationId][$issueId] = true;
            }
        }
        foreach ($this->list($diagnosis, 'recommendation_hypothesis_links') as $link) {
            $link = $this->asObject($link, 'recommendation hypothesis link');
            if ($this->string($link, 'status') !== 'active') {
                continue;
            }
            $recommendationId = $this->string($link, 'recommendation_id');
            $hypothesisId = $this->string($link, 'hypothesis_id');
            if (!isset($recommendationIndex[$recommendationId], $hypothesisIndex[$hypothesisId])) {
                $this->fail('An active recommendation hypothesis link is dangling.');
            }
            if (isset($visibleHypothesisIds[$hypothesisId])) {
                $result[$recommendationId][$this->string($hypothesisIndex[$hypothesisId], 'diagnostic_issue_id')] = true;
            }
        }
        foreach ($result as $id => $ids) {
            $result[$id] = array_keys($ids);
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $visible @param array<string, array<string, mixed>> $all @param array<int, mixed> $dependencies @return array<int, string> */
    private function topologicalRecommendationOrder(array $visible, array $all, array $dependencies): array
    {
        $adjacency = array_fill_keys(array_keys($visible), []);
        $indegree = array_fill_keys(array_keys($visible), 0);
        foreach ($this->dependencyEdges($visible, $all, $dependencies) as $edge) {
            [$before, $after] = $edge;
            if (!isset($adjacency[$before][$after])) {
                $adjacency[$before][$after] = true;
                $indegree[$after]++;
            }
        }
        $result = [];
        while (count($result) < count($visible)) {
            $available = [];
            foreach ($indegree as $id => $degree) {
                if ($degree === 0 && !in_array($id, $result, true)) {
                    $available[] = $id;
                }
            }
            if ($available === []) {
                $this->fail('The client recommendation dependency graph contains a cycle.');
            }
            usort($available, function (string $leftId, string $rightId) use ($visible): int {
                $leftUrgency = $this->string($this->object($visible[$leftId], 'target_timeframe'), 'urgency');
                $rightUrgency = $this->string($this->object($visible[$rightId], 'target_timeframe'), 'urgency');
                $urgency = $this->urgencyRank($leftUrgency) <=> $this->urgencyRank($rightUrgency);
                return $urgency !== 0 ? $urgency : strcmp($this->stableKey($visible[$leftId]), $this->stableKey($visible[$rightId]));
            });
            $selected = $available[0];
            $result[] = $selected;
            $indegree[$selected] = -1;
            foreach (array_keys($adjacency[$selected]) as $after) {
                $indegree[$after]--;
            }
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $visible @param array<string, array<string, mixed>> $all @param array<int, mixed> $dependencies @return array<int, array{0: string, 1: string, 2: string}> */
    private function dependencyEdges(array $visible, array $all, array $dependencies): array
    {
        $edges = [];
        foreach ($dependencies as $dependency) {
            $dependency = $this->asObject($dependency, 'recommendation dependency');
            if ($this->string($dependency, 'status') !== 'active') {
                continue;
            }
            $from = $this->string($dependency, 'from_recommendation_id');
            $to = $this->string($dependency, 'to_recommendation_id');
            if (!isset($all[$from], $all[$to])) {
                $this->fail('An active recommendation dependency is dangling.');
            }
            $fromVisible = isset($visible[$from]);
            $toVisible = isset($visible[$to]);
            if (!$fromVisible && !$toVisible) {
                continue;
            }
            if (!$fromVisible || !$toVisible) {
                $this->fail('An active client recommendation dependency references a hidden recommendation.');
            }
            $type = $this->string($dependency, 'dependency_type');
            if ($type === 'requires') {
                $edges[] = [$to, $from, $type];
            } elseif (in_array($type, ['precedes', 'blocks_until_completed'], true)) {
                $edges[] = [$from, $to, $type];
            } else {
                $this->fail('A recommendation dependency type is invalid.');
            }
        }
        return $edges;
    }

    /** @param array<string, array<string, mixed>> $visible @param array<string, array<string, mixed>> $all @param array<int, mixed> $dependencies @param array<string, int> $positions @return array<string, array<int, array<string, string>>> */
    private function recommendationDependencies(array $visible, array $all, array $dependencies, array $positions): array
    {
        $result = array_fill_keys(array_keys($visible), []);
        foreach ($this->dependencyEdges($visible, $all, $dependencies) as $edge) {
            [$before, $after, $type] = $edge;
            $result[$after][] = ['recommendation_id' => $before, 'dependency_type' => $type];
        }
        foreach ($result as $id => $items) {
            usort($items, function (array $left, array $right) use ($positions): int {
                return $positions[$left['recommendation_id']] <=> $positions[$right['recommendation_id']];
            });
            $result[$id] = $items;
        }
        return $result;
    }

    /** @param array<int, mixed> $files @return array<string, array<string, mixed>> */
    private function manifestFileIndex(array $files): array
    {
        $result = [];
        foreach ($files as $file) {
            $file = $this->asObject($file, 'manifest file');
            $path = $this->string($file, 'path');
            if (isset($result[$path])) {
                $this->fail('The package manifest contains a duplicate path.');
            }
            $result[$path] = $file;
        }
        return $result;
    }

    /** @param array<int, mixed> $items @return array<string, array<string, mixed>> */
    private function indexById(array $items, string $label): array
    {
        $prefixes = [
            'observation' => 'obs',
            'evidence' => 'ev',
            'issue' => 'issue',
            'hypothesis' => 'hyp',
            'verification' => 'ver',
            'recommendation' => 'rec',
        ];
        $result = [];
        foreach ($items as $item) {
            $item = $this->asObject($item, $label);
            $id = $this->string($item, 'id');
            if (!isset($prefixes[$label]) || preg_match('/^' . $prefixes[$label] . '_[0-9a-f]{16,32}$/D', $id) !== 1) {
                $this->fail('The delivery source contains an invalid ' . $label . ' identifier.');
            }
            if (isset($result[$id])) {
                $this->fail('The delivery source contains a duplicate ' . $label . ' identifier.');
            }
            $result[$id] = $item;
        }
        return $result;
    }

    /** @param array<string, mixed> $evidence */
    private function isClientVisibleEvidence(array $evidence): bool
    {
        return $this->string($evidence, 'status') === 'active' &&
            in_array($this->string($evidence, 'privacy'), ['public', 'client_private'], true);
    }

    /** @param array<string, mixed> $source */
    private function stableKey(array $source): string
    {
        $displayCode = $source['display_code'] ?? null;
        return (is_string($displayCode) ? $displayCode : '') . '|' . $this->string($source, 'id');
    }

    private function priorityRank(string $priority): int
    {
        $ranks = ['P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5];
        return $ranks[$priority] ?? 99;
    }

    private function severityRank(string $severity): int
    {
        $ranks = ['S1' => 1, 'S2' => 2, 'S3' => 3, 'S4' => 4, 'S5' => 5];
        return $ranks[$severity] ?? 0;
    }

    private function urgencyRank(string $urgency): int
    {
        $ranks = ['U1' => 1, 'U2' => 2, 'U3' => 3, 'U4' => 4, 'U5' => 5];
        return $ranks[$urgency] ?? 99;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function object(array $source, string $key): array
    {
        if (!array_key_exists($key, $source)) {
            $this->fail('The delivery source is missing an object field.');
        }
        return $this->asObject($source[$key], $key);
    }

    /** @param mixed $value @return array<string, mixed> */
    private function asObject($value, string $label): array
    {
        if (!is_array($value) || $this->isList($value)) {
            $this->fail('The delivery source contains an invalid ' . $label . ' object.');
        }
        return $value;
    }

    /** @param array<string, mixed> $source @return array<int, mixed> */
    private function list(array $source, string $key): array
    {
        if (!array_key_exists($key, $source) || !is_array($source[$key]) || !$this->isList($source[$key])) {
            $this->fail('The delivery source contains an invalid list field.');
        }
        return $source[$key];
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): string
    {
        if (!array_key_exists($key, $source) || !is_string($source[$key]) || trim($source[$key]) === '') {
            $this->fail('The delivery source contains an invalid string field.');
        }
        return $source[$key];
    }

    /** @param array<string, mixed> $source */
    private function boolean(array $source, string $key): bool
    {
        if (!array_key_exists($key, $source) || !is_bool($source[$key])) {
            $this->fail('The delivery source contains an invalid boolean field.');
        }
        return $source[$key];
    }

    /** @param array<string, mixed> $source @return int|float */
    private function number(array $source, string $key)
    {
        if (!array_key_exists($key, $source) || (!is_int($source[$key]) && !is_float($source[$key])) || !is_finite((float)$source[$key])) {
            $this->fail('The delivery source contains an invalid number field.');
        }
        return $source[$key];
    }

    /** @param array<string, mixed> $source @return array<int, string> */
    private function stringList(array $source, string $key): array
    {
        $result = [];
        foreach ($this->list($source, $key) as $value) {
            if (!is_string($value) || trim($value) === '') {
                $this->fail('The delivery source contains an invalid string list.');
            }
            $result[] = $value;
        }
        return $result;
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $source */
    private function copyOptionalString(array &$target, array $source, string $key): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }
        if (!is_string($source[$key])) {
            $this->fail('The delivery source contains an invalid optional string field.');
        }
        $target[$key] = $source[$key];
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

    private function fail(string $message): void
    {
        throw new DiagnosticsDeliveryException('DELIVERY_PROJECTION', $message);
    }
}
