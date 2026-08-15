<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';

final class MittiInspectionMapper
{
    /** @var string */ private $namespace = 'doktorhaus:diagnostics:mitti-normalization:v1';

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $answers
     * @param array<string, mixed> $manifest
     * @return array{document: array<string, mixed>, warnings: array<int, array<string, mixed>>}
     */
    public function map(array $raw, array $template, array $answers, array $manifest): array
    {
        $sourceId = (string)($manifest['source_inspection_id'] ?? '');
        $sourceHash = (string)($manifest['raw_payload_sha256'] ?? '');
        if ($sourceId === '' || preg_match('/^[0-9a-f]{64}$/D', $sourceHash) !== 1) {
            throw new DiagnosticsIngestException('MAPPER_SOURCE', 'Mitti source manifest nie je platný.');
        }
        $inspectionId = $this->id('insp', $sourceId, 'inspection', $sourceId);
        $propertyId = $this->id('prop', $sourceId, 'property', 'property');
        $actorName = $this->nestedString($raw, [['owner', 'name'], ['author', 'name'], ['inspector_name'], ['audit_data', 'author']], 'Inšpektor DoktorHaus');
        $actorId = $this->id('actor', $sourceId, 'actor', $actorName);
        $performedAt = $this->timestamp($this->nestedString($raw, [['completed_at'], ['date_completed'], ['audit_data', 'date_completed'], ['performed_at']]), (string)($manifest['imported_at'] ?? gmdate('c')));
        $importedAt = $this->timestamp((string)($manifest['imported_at'] ?? ''), gmdate('c'));
        $modifiedAt = $this->timestamp((string)($manifest['source_modified_at'] ?? ''), $performedAt);
        $title = $this->nestedString($raw, [['name'], ['title'], ['audit_data', 'name']], 'Mitti inšpekcia');
        $location = $this->nestedString($raw, [['site', 'name'], ['site_name'], ['location'], ['audit_data', 'site', 'name']], 'Lokalita neuvedená');

        $templateLabels = [];
        $this->collectTemplateLabels($template, '', $templateLabels);
        $answerByItem = [];
        foreach ($answers as $answer) {
            $id = $this->firstString($answer, ['item_id', 'question_id', 'id']);
            if ($id !== '') { $answerByItem[$id] = $answer; }
        }
        $items = $this->findItems($raw);
        $observations = [];
        $evidence = [];
        $links = [];
        $warnings = [];
        $observationBySource = [];

        foreach ($items as $index => $item) {
            $sourceItemId = $this->firstString($item, ['item_id', 'question_id', 'id'], 'item-' . ($index + 1));
            $type = strtolower($this->firstString($item, ['type', 'item_type', 'response_type'], 'unknown'));
            $supported = ['question', 'response', 'yes_no', 'checkbox', 'multiple_choice', 'single_choice', 'number', 'numeric', 'measurement', 'text', 'note', 'date', 'datetime'];
            if (!in_array($type, $supported, true)) {
                $warnings[] = ['code' => 'W_MITTI_UNSUPPORTED_ITEM', 'sourceItemId' => $sourceItemId, 'message' => 'Položka vyžaduje kontrolu mapovania.', 'itemType' => substr($type, 0, 80)];
                continue;
            }
            $resolved = isset($answerByItem[$sourceItemId]) ? array_merge($item, $answerByItem[$sourceItemId]) : $item;
            $question = $this->firstString($resolved, ['question', 'label', 'title', 'name']);
            if ($question === '' && isset($templateLabels[$sourceItemId])) { $question = $templateLabels[$sourceItemId]['label']; }
            $section = $this->firstString($resolved, ['section', 'section_name', 'category']);
            if ($section === '' && isset($templateLabels[$sourceItemId])) { $section = $templateLabels[$sourceItemId]['section']; }
            $answerText = $this->answerText($resolved, $templateLabels[$sourceItemId]['options'] ?? []);
            $note = $this->firstString($resolved, ['note', 'notes', 'comment', 'inspector_note']);
            if ($question === '' && $answerText === '' && $note === '') { continue; }
            $parts = [];
            if ($question !== '') { $parts[] = 'Otázka: ' . $question; }
            if ($answerText !== '') { $parts[] = 'Zaznamenaná odpoveď: ' . $answerText; }
            if ($note !== '') { $parts[] = 'Poznámka inšpektora: ' . $note; }
            $observationId = $this->id('obs', $sourceId, 'observation', $sourceItemId);
            $observation = [
                'id' => $observationId,
                'display_code' => sprintf('OBS-%03d', count($observations) + 1),
                'inspection_id' => $inspectionId,
                'statement' => implode(' ', $parts),
                'type' => in_array($type, ['number', 'numeric', 'measurement'], true) ? 'measured' : ($type === 'note' ? 'reported' : 'document'),
                'area' => ['area_type' => $this->areaType($section), 'label' => $section !== '' ? $section : 'Mitti checklist'],
                'status' => 'active',
                'provenance' => $this->provenance($sourceId, $sourceItemId, $importedAt, $modifiedAt, $sourceHash, $question),
                'observed_at' => $performedAt,
                'observed_by' => $actorId,
                'limitations' => ['Automatická normalizácia zachováva zdrojovú odpoveď; neurčuje príčinu ani diagnostický záver.'],
            ];
            $measurement = $this->measurement($resolved, $performedAt);
            if ($measurement !== null) { $observation['measurement'] = $measurement; }
            $observations[] = $observation;
            $observationBySource[$sourceItemId] = $observationId;
        }

        foreach ((array)($manifest['media'] ?? []) as $mediaIndex => $media) {
            if (!is_array($media)) { continue; }
            $mediaId = (string)($media['source_media_id'] ?? '');
            if ($mediaId === '') { continue; }
            $sourceItemId = (string)($media['source_item_id'] ?? '');
            $observationId = $observationBySource[$sourceItemId] ?? null;
            $mediaType = strtolower((string)($media['media_type'] ?? 'image'));
            $evidenceType = $mediaType === 'video' ? 'video' : (($media['content_type'] ?? $media['declared_content_type'] ?? '') === 'application/pdf' ? 'document' : 'photo');
            $evidenceId = $this->id('ev', $sourceId, 'evidence', $mediaId);
            $description = (string)($media['context'] ?? '');
            if (($media['status'] ?? null) !== 'downloaded') {
                $description = trim($description . ' Originálny mediálny súbor zostáva v stave pending.');
            }
            $item = [
                'id' => $evidenceId,
                'display_code' => sprintf('EV-%03d', count($evidence) + 1),
                'inspection_id' => $inspectionId,
                'type' => $evidenceType,
                'title' => $evidenceType === 'video' ? 'Mitti video evidence' : ($evidenceType === 'document' ? 'Mitti dokument' : 'Mitti fotografia'),
                'description' => $description !== '' ? $description : 'Médium priložené k Mitti checklistu.',
                'captured_at' => $performedAt,
                'captured_by' => $actorId,
                'provenance' => $this->provenance($sourceId, $sourceItemId !== '' ? $sourceItemId : $mediaId, $importedAt, $modifiedAt, (string)($media['sha256'] ?? $sourceHash), 'Mitti media'),
                'media_reference' => 'imports/mitti/' . (string)$manifest['source_key'] . '/' . (string)$manifest['source_revision'] . '/media/' . (string)$media['storage_filename'],
                'privacy' => 'client_private',
                'status' => 'active',
                'content_type' => (string)($media['content_type'] ?? $media['declared_content_type'] ?? 'application/octet-stream'),
            ];
            $item['provenance']['source_media_id'] = $mediaId;
            if (preg_match('/^[0-9a-f]{64}$/D', (string)($media['sha256'] ?? '')) === 1) { $item['sha256'] = $media['sha256']; }
            $evidence[] = $item;
            if (is_string($observationId)) {
                $links[] = [
                    'id' => $this->id('rel', $sourceId, 'observation-evidence-link', $sourceItemId . '|' . $mediaId),
                    'observation_id' => $observationId,
                    'evidence_id' => $evidenceId,
                    'role' => $evidenceType === 'photo' || $evidenceType === 'video' ? 'depicts' : 'documents',
                    'relevance_note' => 'Médium bolo v Mitti priložené k tejto zdrojovej položke.',
                    'created_at' => $importedAt,
                    'created_by' => $actorId,
                    'status' => 'active',
                ];
            } else {
                $warnings[] = ['code' => 'W_MITTI_ORPHAN_MEDIA', 'sourceMediaId' => $mediaId, 'message' => 'Médium nemá podporovanú zdrojovú položku.'];
            }
        }

        if ($observations === []) {
            throw new DiagnosticsIngestException('MAPPER_EMPTY', 'Mitti inšpekcia neobsahuje podporované zdrojové položky.');
        }
        $document = [
            'schema_version' => '1.0.0',
            'document_type' => 'inspection',
            'id' => $inspectionId,
            'property' => [
                'id' => $propertyId,
                'display_name' => $title,
                'property_type' => 'house',
                'location' => ['country_code' => 'SK', 'region' => $location],
            ],
            'inspection' => [
                'property_id' => $propertyId,
                'inspection_type' => 'initial',
                'performed_at' => $performedAt,
                'processing_status' => 'normalized',
                'scope' => ['Normalizácia dostupných odpovedí, poznámok, meraní a médií z ukončenej Mitti inšpekcie.'],
                'limitations' => ['Automatický import nemení zdrojové fakty na diagnózu a nepovažuje neoverené oblasti za bezchybné.'],
                'actor_ids' => [$actorId],
                'provenance' => [
                    'source_kind' => 'safetyculture', 'source_system' => 'Mitti', 'source_inspection_id' => $sourceId,
                    'source_reference' => 'Mitti API completed inspection', 'imported_at' => $importedAt,
                    'source_updated_at' => $modifiedAt, 'source_hash' => $sourceHash,
                ],
            ],
            'actors' => [['id' => $actorId, 'display_name' => $actorName, 'role' => 'inspector']],
            'observations' => $observations,
            'evidence' => $evidence,
            'observation_evidence_links' => $links,
            'import_metadata' => [
                'import_id' => $this->id('import', $sourceId, 'import', (string)$manifest['source_revision']),
                'imported_at' => $importedAt, 'source_hash' => $sourceHash,
                'source_system' => 'Mitti', 'source_inspection_id' => $sourceId,
                'notes' => $warnings === [] ? 'Deterministický Mitti API import.' : count($warnings) . ' položiek vyžaduje kontrolu mapovania.',
            ],
            'created_at' => $importedAt,
            'updated_at' => $importedAt,
        ];
        return ['document' => $document, 'warnings' => $warnings];
    }

    /** @return array<int, array<string, mixed>> */
    public function extractMedia(array $raw): array
    {
        $result = [];
        $this->walkMedia($raw, '', '', $result);
        $unique = [];
        foreach ($result as $media) { $unique[(string)$media['id']] = $media; }
        return array_values($unique);
    }

    private function walkMedia($node, string $itemId, string $context, array &$result): void
    {
        if (!is_array($node)) { return; }
        $currentItem = $this->firstString($node, ['item_id', 'question_id'], $itemId);
        $currentContext = $this->firstString($node, ['question', 'label', 'title'], $context);
        $mediaCollections = [];
        foreach (['media', 'media_items', 'attachments', 'photos', 'videos'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) { $mediaCollections[] = $node[$key]; }
        }
        foreach ($mediaCollections as $collection) {
            foreach ($collection as $media) {
                if (!is_array($media)) { continue; }
                $id = $this->firstString($media, ['media_id', 'id', 'uuid']);
                if ($id === '') { continue; }
                $type = strtolower($this->firstString($media, ['media_type', 'type'], 'image'));
                if (strpos($type, 'video') !== false) { $type = 'video'; }
                elseif (strpos($type, 'pdf') !== false || strpos((string)($media['content_type'] ?? ''), 'pdf') !== false) { $type = 'document'; }
                else { $type = 'image'; }
                $result[] = [
                    'id' => $id, 'source_item_id' => $currentItem, 'context' => $currentContext,
                    'media_type' => $type, 'content_type' => $this->firstString($media, ['content_type', 'mime_type']),
                    'filename' => $this->firstString($media, ['filename', 'name']),
                ];
            }
        }
        foreach ($node as $key => $child) {
            if (in_array($key, ['media', 'media_items', 'attachments', 'photos', 'videos'], true)) { continue; }
            if (is_array($child)) { $this->walkMedia($child, $currentItem, $currentContext, $result); }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function findItems(array $raw): array
    {
        foreach ([['inspection_items'], ['items'], ['audit_data', 'items'], ['audit_data', 'responses']] as $path) {
            $value = $raw;
            foreach ($path as $segment) { if (!is_array($value) || !isset($value[$segment])) { $value = null; break; } $value = $value[$segment]; }
            if (is_array($value) && $this->isList($value)) { return array_values(array_filter($value, 'is_array')); }
        }
        return [];
    }

    private function collectTemplateLabels($node, string $section, array &$labels): void
    {
        if (!is_array($node)) { return; }
        $type = strtolower($this->firstString($node, ['type', 'item_type']));
        $label = $this->firstString($node, ['label', 'title', 'question', 'name']);
        if (in_array($type, ['section', 'category', 'page'], true) && $label !== '') { $section = $label; }
        $id = $this->firstString($node, ['item_id', 'question_id', 'id']);
        if ($id !== '' && $label !== '') {
            $options = [];
            foreach ((array)($node['options'] ?? $node['response_set']['responses'] ?? []) as $option) {
                if (!is_array($option)) { continue; }
                $key = $this->firstString($option, ['id', 'value', 'key']);
                $value = $this->firstString($option, ['label', 'text', 'name']);
                if ($key !== '' && $value !== '') { $options[$key] = $value; }
            }
            $labels[$id] = ['label' => $label, 'section' => $section, 'options' => $options];
        }
        foreach ($node as $child) { if (is_array($child)) { $this->collectTemplateLabels($child, $section, $labels); } }
    }

    private function answerText(array $item, array $options): string
    {
        $value = $item['answer'] ?? $item['response'] ?? $item['value'] ?? $item['selected'] ?? '';
        if (is_array($value)) {
            if (isset($value['label'])) { return trim((string)$value['label']); }
            $values = [];
            foreach ($value as $part) { if (is_string($part) || is_numeric($part)) { $key = (string)$part; $values[] = $options[$key] ?? $key; } }
            return implode(', ', $values);
        }
        if (is_bool($value)) { return $value ? 'áno' : 'nie'; }
        if (is_string($value) || is_numeric($value)) { $key = trim((string)$value); return $options[$key] ?? $key; }
        return '';
    }

    private function measurement(array $item, string $performedAt): ?array
    {
        $value = $item['numeric_value'] ?? $item['measurement']['value'] ?? $item['value'] ?? $item['answer'] ?? null;
        if (!is_numeric($value)) { return null; }
        $unit = $this->firstString($item, ['unit', 'unit_code'], $this->firstString((array)($item['measurement'] ?? []), ['unit', 'unit_code'], 'unit'));
        return [
            'quantity' => $this->firstString($item, ['question', 'label', 'title'], 'hodnota zo zdrojovej inšpekcie'),
            'value' => (float)$value, 'unit_kind' => 'custom', 'unit_code' => $unit !== '' ? $unit : 'unit',
            'unit_label' => $unit !== '' ? $unit : 'zdrojová jednotka', 'method' => 'Mitti checklist entry', 'measured_at' => $performedAt,
        ];
    }

    private function provenance(string $sourceId, string $itemId, string $importedAt, string $updatedAt, string $hash, string $reference): array
    {
        $value = ['source_kind' => 'safetyculture', 'source_system' => 'Mitti', 'source_inspection_id' => $sourceId, 'source_item_id' => $itemId, 'source_reference' => $reference !== '' ? $reference : 'Mitti checklist item', 'imported_at' => $importedAt, 'source_updated_at' => $updatedAt];
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) === 1) { $value['source_hash'] = $hash; }
        return $value;
    }

    private function id(string $prefix, string $sourceId, string $entity, string $key): string { return $prefix . '_' . substr(hash('sha256', $this->namespace . '|' . $sourceId . '|' . $entity . '|' . $key), 0, 24); }
    private function timestamp(string $value, string $fallback): string { try { return (new \DateTimeImmutable($value !== '' ? $value : $fallback))->format(DATE_ATOM); } catch (\Throwable $e) { return gmdate('Y-m-d\TH:i:s\Z'); } }
    private function areaType(string $section): string { $text = strtolower($section); if (strpos($text, 'roof') !== false || strpos($text, 'strech') !== false) return 'roof'; if (strpos($text, 'piv') !== false || strpos($text, 'basement') !== false) return 'basement'; if (strpos($text, 'exter') !== false) return 'exterior'; if (strpos($text, 'inter') !== false) return 'interior'; return 'whole_building'; }
    private function firstString(array $row, array $keys, string $default = ''): string { foreach ($keys as $key) { if (isset($row[$key]) && (is_string($row[$key]) || is_numeric($row[$key]))) return trim((string)$row[$key]); } return $default; }
    private function nestedString(array $row, array $paths, string $default = ''): string { foreach ($paths as $path) { $value = $row; foreach ($path as $segment) { if (!is_array($value) || !array_key_exists($segment, $value)) { $value = null; break; } $value = $value[$segment]; } if (is_string($value) || is_numeric($value)) { $text = trim((string)$value); if ($text !== '') return $text; } } return $default; }
    private function isList(array $value): bool { $index = 0; foreach ($value as $key => $_) { if ($key !== $index++) return false; } return true; }
}
