<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use Throwable;

require_once __DIR__ . '/DiagnosticsStorage.php';
require_once __DIR__ . '/DiagnosticsStorageException.php';

/**
 * Mutable, inspection-record-owned client outputs kept outside the immutable
 * diagnostics package. All public selectors are opaque IDs; filesystem names
 * and paths are generated and resolved only inside this class.
 */
final class DiagnosticsClientOutputStore
{
    private const DOCUMENT_VERSION = '1.0.0-helper';
    private const MAX_PDF_BYTES = 52428800;
    private const MAX_IMAGE_BYTES = 20971520;
    private const MAX_OUTPUTS = 100;
    private const MAX_GALLERY_PHOTOS = 240;

    private const LINK_TYPES = [
        'google_docs' => ['docs.google.com', 'drive.google.com'],
        'panoraven' => ['panoraven.com'],
        'video_hd' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        'video_360' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
    ];

    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var DiagnosticsStorage */
    private $storage;

    /** @var string */
    private $root;

    /** @var string */
    private $locksRoot;

    public function __construct(DiagnosticsStorage $storage)
    {
        $this->storage = $storage;
        $this->root = $this->ensureDirectory(
            $storage->getRoot() . DIRECTORY_SEPARATOR . 'client-outputs',
            0700
        );
        $this->locksRoot = $this->ensureDirectory(
            $storage->getRoot() . DIRECTORY_SEPARATOR . 'locks',
            0700
        );
    }

    /** @return array<string, mixed> */
    public function list(string $inspectionRecordId): array
    {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        return $this->withLock($inspectionRecordId, function () use ($inspectionRecordId): array {
            return $this->adminProjection($this->loadUnlocked($inspectionRecordId));
        });
    }

    /** @return array{outputs: array<int, array<string, mixed>>, galleries: array<int, array<string, mixed>>} */
    public function clientProjection(string $inspectionRecordId): array
    {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        return $this->withLock($inspectionRecordId, function () use ($inspectionRecordId): array {
            $document = $this->loadUnlocked($inspectionRecordId);
            $outputs = [];
            $galleries = [];
            foreach ($document['outputs'] as $output) {
                if ($output['kind'] === 'link') {
                    $outputs[] = [
                        'id' => $output['id'],
                        'type' => $output['type'],
                        'title' => $output['title'],
                        'description' => $output['description'],
                        'url' => $output['url'],
                    ];
                    continue;
                }
                if ($output['kind'] === 'pdf') {
                    $outputs[] = [
                        'id' => $output['id'],
                        'type' => 'pdf',
                        'title' => $output['title'],
                        'description' => $output['description'],
                        'url' => 'api/diagnostics-output-media.php?media=' . $output['media']['id'],
                    ];
                    continue;
                }
                $photos = [];
                foreach ($output['photos'] as $photo) {
                    $photos[] = [
                        'id' => $photo['id'],
                        'title' => $photo['title'],
                        'caption' => $photo['caption'],
                        'media_url' => 'api/diagnostics-output-media.php?media=' . $photo['media']['id'],
                    ];
                }
                $galleries[] = [
                    'id' => $output['id'],
                    'title' => $output['title'],
                    'description' => $output['description'],
                    'photos' => $photos,
                ];
            }
            return ['outputs' => $outputs, 'galleries' => $galleries];
        });
    }

    /** @return array<string, mixed> */
    public function createLink(
        string $inspectionRecordId,
        int $expectedRevision,
        string $type,
        string $title,
        string $description,
        string $url
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $type = strtolower(trim($type));
        if (!isset(self::LINK_TYPES[$type])) {
            throw $this->validation('The client output link type is invalid.');
        }
        $url = $this->validateExternalUrl($url, self::LINK_TYPES[$type]);
        $title = $this->cleanText($title, 140);
        $description = $this->cleanText($description, 600);
        if ($title === '') {
            $title = [
                'google_docs' => 'Google Docs správa',
                'panoraven' => 'Virtuálna prehliadka',
                'video_hd' => 'Video Full HD',
                'video_360' => 'Video 360',
            ][$type];
        }
        return $this->mutate($inspectionRecordId, $expectedRevision, function (array &$document) use (
            $type,
            $title,
            $description,
            $url
        ): void {
            $this->assertOutputCapacity($document);
            $document['outputs'][] = [
                'id' => $this->newId('out'),
                'kind' => 'link',
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'url' => $url,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        });
    }

    /** @param array<string, mixed> $upload
     *  @return array<string, mixed>
     */
    public function uploadPdf(
        string $inspectionRecordId,
        int $expectedRevision,
        array $upload,
        string $title,
        string $description
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $prepared = $this->prepareUpload($upload, ['application/pdf'], self::MAX_PDF_BYTES);
        $title = $this->cleanText($title, 140);
        $description = $this->cleanText($description, 600);
        if ($title === '') {
            $title = 'Správa v PDF';
        }
        return $this->withLock($inspectionRecordId, function () use (
            $inspectionRecordId,
            $expectedRevision,
            $prepared,
            $title,
            $description
        ): array {
            $document = $this->loadUnlocked($inspectionRecordId);
            $this->assertRevision($document, $expectedRevision);
            $this->assertOutputCapacity($document);
            $media = $this->persistUploadUnlocked($inspectionRecordId, $prepared);
            try {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $document['outputs'][] = [
                    'id' => $this->newId('out'),
                    'kind' => 'pdf',
                    'type' => 'pdf',
                    'title' => $title,
                    'description' => $description,
                    'media' => $media,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                return $this->commitUnlocked($inspectionRecordId, $document);
            } catch (Throwable $error) {
                $this->removeMediaFileUnlocked($inspectionRecordId, $media);
                throw $error;
            }
        });
    }

    /** @return array<string, mixed> */
    public function createGallery(
        string $inspectionRecordId,
        int $expectedRevision,
        string $title,
        string $description
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $title = $this->cleanText($title, 140);
        if ($title === '') {
            throw $this->validation('The gallery title is required.');
        }
        $description = $this->cleanText($description, 600);
        return $this->mutate($inspectionRecordId, $expectedRevision, function (array &$document) use ($title, $description): void {
            $this->assertOutputCapacity($document);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $document['outputs'][] = [
                'id' => $this->newId('out'),
                'kind' => 'gallery',
                'type' => 'gallery',
                'title' => $title,
                'description' => $description,
                'photos' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
    }

    /**
     * @param array<int, array<string, mixed>> $uploads
     * @param array<int, string> $titles
     * @param array<int, string> $captions
     * @return array<string, mixed>
     */
    public function uploadGalleryPhotos(
        string $inspectionRecordId,
        int $expectedRevision,
        string $galleryId,
        array $uploads,
        array $titles = [],
        array $captions = []
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $galleryId = $this->validateOutputId($galleryId);
        if ($uploads === [] || count($uploads) > 30) {
            throw $this->validation('The gallery upload count is invalid.');
        }
        $prepared = [];
        foreach ($uploads as $upload) {
            $prepared[] = $this->prepareUpload($upload, ['image/jpeg', 'image/png', 'image/webp'], self::MAX_IMAGE_BYTES);
        }
        return $this->withLock($inspectionRecordId, function () use (
            $inspectionRecordId,
            $expectedRevision,
            $galleryId,
            $prepared,
            $titles,
            $captions
        ): array {
            $document = $this->loadUnlocked($inspectionRecordId);
            $this->assertRevision($document, $expectedRevision);
            $index = $this->findOutputIndex($document, $galleryId);
            if ($index < 0 || $document['outputs'][$index]['kind'] !== 'gallery') {
                throw $this->notFound('The client gallery does not exist.');
            }
            if (count($document['outputs'][$index]['photos']) + count($prepared) > self::MAX_GALLERY_PHOTOS) {
                throw $this->validation('The client gallery photo limit was exceeded.');
            }
            $savedMedia = [];
            try {
                foreach ($prepared as $position => $upload) {
                    $media = $this->persistUploadUnlocked($inspectionRecordId, $upload);
                    $savedMedia[] = $media;
                    $document['outputs'][$index]['photos'][] = [
                        'id' => $this->newId('outp'),
                        'title' => $this->cleanText($titles[$position] ?? '', 140) ?: 'Fotografia ' . (count($document['outputs'][$index]['photos']) + 1),
                        'caption' => $this->cleanText($captions[$position] ?? '', 600),
                        'media' => $media,
                        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ];
                }
                $document['outputs'][$index]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                return $this->commitUnlocked($inspectionRecordId, $document);
            } catch (Throwable $error) {
                foreach ($savedMedia as $media) {
                    $this->removeMediaFileUnlocked($inspectionRecordId, $media);
                }
                throw $error;
            }
        });
    }

    /** @return array<string, mixed> */
    public function update(
        string $inspectionRecordId,
        int $expectedRevision,
        string $outputId,
        array $changes,
        ?string $photoId = null
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $outputId = $this->validateOutputId($outputId);
        return $this->mutate($inspectionRecordId, $expectedRevision, function (array &$document) use (
            $outputId,
            $changes,
            $photoId
        ): void {
            $index = $this->findOutputIndex($document, $outputId);
            if ($index < 0) {
                throw $this->notFound('The client output does not exist.');
            }
            if ($photoId !== null) {
                if ($document['outputs'][$index]['kind'] !== 'gallery') {
                    throw $this->validation('The selected output is not a gallery.');
                }
                $photoId = $this->validatePhotoId($photoId);
                $photoIndex = $this->findPhotoIndex($document['outputs'][$index], $photoId);
                if ($photoIndex < 0) {
                    throw $this->notFound('The gallery photo does not exist.');
                }
                if (array_key_exists('title', $changes)) {
                    $title = $this->cleanText((string)$changes['title'], 140);
                    $document['outputs'][$index]['photos'][$photoIndex]['title'] = $title !== '' ? $title : 'Fotografia';
                }
                if (array_key_exists('caption', $changes)) {
                    $document['outputs'][$index]['photos'][$photoIndex]['caption'] = $this->cleanText((string)$changes['caption'], 600);
                }
                $document['outputs'][$index]['photos'][$photoIndex]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $document['outputs'][$index]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                return;
            }
            if (array_key_exists('title', $changes)) {
                $title = $this->cleanText((string)$changes['title'], 140);
                if ($title === '') {
                    throw $this->validation('The client output title is required.');
                }
                $document['outputs'][$index]['title'] = $title;
            }
            if (array_key_exists('description', $changes)) {
                $document['outputs'][$index]['description'] = $this->cleanText((string)$changes['description'], 600);
            }
            if (array_key_exists('url', $changes)) {
                if ($document['outputs'][$index]['kind'] !== 'link') {
                    throw $this->validation('Only an external output has an editable URL.');
                }
                $type = $document['outputs'][$index]['type'];
                $document['outputs'][$index]['url'] = $this->validateExternalUrl((string)$changes['url'], self::LINK_TYPES[$type]);
            }
            $document['outputs'][$index]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
    }

    /** @return array<string, mixed> */
    public function reorder(
        string $inspectionRecordId,
        int $expectedRevision,
        string $outputId,
        string $direction,
        ?string $photoId = null
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $outputId = $this->validateOutputId($outputId);
        if (!in_array($direction, ['up', 'down'], true)) {
            throw $this->validation('The reorder direction is invalid.');
        }
        return $this->mutate($inspectionRecordId, $expectedRevision, function (array &$document) use (
            $outputId,
            $direction,
            $photoId
        ): void {
            $index = $this->findOutputIndex($document, $outputId);
            if ($index < 0) {
                throw $this->notFound('The client output does not exist.');
            }
            if ($photoId === null) {
                $this->swap($document['outputs'], $index, $direction);
                return;
            }
            if ($document['outputs'][$index]['kind'] !== 'gallery') {
                throw $this->validation('The selected output is not a gallery.');
            }
            $photoIndex = $this->findPhotoIndex($document['outputs'][$index], $this->validatePhotoId($photoId));
            if ($photoIndex < 0) {
                throw $this->notFound('The gallery photo does not exist.');
            }
            $this->swap($document['outputs'][$index]['photos'], $photoIndex, $direction);
            $document['outputs'][$index]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        });
    }

    /** @return array<string, mixed> */
    public function delete(
        string $inspectionRecordId,
        int $expectedRevision,
        string $outputId,
        ?string $photoId = null
    ): array {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $outputId = $this->validateOutputId($outputId);
        return $this->withLock($inspectionRecordId, function () use (
            $inspectionRecordId,
            $expectedRevision,
            $outputId,
            $photoId
        ): array {
            $document = $this->loadUnlocked($inspectionRecordId);
            $this->assertRevision($document, $expectedRevision);
            $index = $this->findOutputIndex($document, $outputId);
            if ($index < 0) {
                throw $this->notFound('The client output does not exist.');
            }
            $mediaToRemove = [];
            if ($photoId !== null) {
                if ($document['outputs'][$index]['kind'] !== 'gallery') {
                    throw $this->validation('The selected output is not a gallery.');
                }
                $photoIndex = $this->findPhotoIndex($document['outputs'][$index], $this->validatePhotoId($photoId));
                if ($photoIndex < 0) {
                    throw $this->notFound('The gallery photo does not exist.');
                }
                $mediaToRemove[] = $document['outputs'][$index]['photos'][$photoIndex]['media'];
                array_splice($document['outputs'][$index]['photos'], $photoIndex, 1);
                $document['outputs'][$index]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            } else {
                $output = $document['outputs'][$index];
                if ($output['kind'] === 'pdf') {
                    $mediaToRemove[] = $output['media'];
                } elseif ($output['kind'] === 'gallery') {
                    foreach ($output['photos'] as $photo) {
                        $mediaToRemove[] = $photo['media'];
                    }
                }
                array_splice($document['outputs'], $index, 1);
            }
            $result = $this->commitUnlocked($inspectionRecordId, $document);
            foreach ($mediaToRemove as $media) {
                $this->removeMediaFileUnlocked($inspectionRecordId, $media);
            }
            return $result;
        });
    }

    /** @return array<string, mixed>|null */
    public function resolveMedia(string $inspectionRecordId, string $mediaId): ?array
    {
        $inspectionRecordId = $this->validateInspectionRecordId($inspectionRecordId);
        $mediaId = $this->validateMediaId($mediaId);
        return $this->withLock($inspectionRecordId, function () use ($inspectionRecordId, $mediaId): ?array {
            $document = $this->loadUnlocked($inspectionRecordId);
            foreach ($document['outputs'] as $output) {
                if ($output['kind'] === 'pdf' && $output['media']['id'] === $mediaId) {
                    return $this->resolveMediaUnlocked($inspectionRecordId, $output['media']);
                }
                if ($output['kind'] === 'gallery') {
                    foreach ($output['photos'] as $photo) {
                        if ($photo['media']['id'] === $mediaId) {
                            return $this->resolveMediaUnlocked($inspectionRecordId, $photo['media']);
                        }
                    }
                }
            }
            return null;
        });
    }

    /** @return array<string, mixed> */
    private function mutate(string $inspectionRecordId, int $expectedRevision, callable $callback): array
    {
        return $this->withLock($inspectionRecordId, function () use (
            $inspectionRecordId,
            $expectedRevision,
            $callback
        ): array {
            $document = $this->loadUnlocked($inspectionRecordId);
            $this->assertRevision($document, $expectedRevision);
            $callback($document);
            return $this->commitUnlocked($inspectionRecordId, $document);
        });
    }

    /** @return array<string, mixed> */
    private function commitUnlocked(string $inspectionRecordId, array $document): array
    {
        $document['revision'] = (int)$document['revision'] + 1;
        $document['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->validateDocument($document, $inspectionRecordId);
        $directory = $this->recordDirectory($inspectionRecordId, true);
        $path = $directory . DIRECTORY_SEPARATOR . 'outputs.json';
        $this->atomicWrite($path, $document);
        return $this->adminProjection($document);
    }

    /** @return array<string, mixed> */
    private function loadUnlocked(string $inspectionRecordId): array
    {
        $directory = $this->recordDirectory($inspectionRecordId, false);
        if ($directory === null) {
            return $this->emptyDocument($inspectionRecordId);
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'outputs.json';
        if (is_link($path)) {
            throw $this->integrity('The client output document path is unsafe.');
        }
        if (!file_exists($path)) {
            return $this->emptyDocument($inspectionRecordId);
        }
        $this->assertSafeRegularFile($path);
        $raw = @file_get_contents($path);
        $document = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($document)) {
            throw $this->integrity('The client output document is invalid.');
        }
        $this->validateDocument($document, $inspectionRecordId);
        return $document;
    }

    /** @return array<string, mixed> */
    private function emptyDocument(string $inspectionRecordId): array
    {
        return [
            'schema_version' => self::DOCUMENT_VERSION,
            'document_type' => 'client_outputs',
            'inspection_record_id' => $inspectionRecordId,
            'revision' => 0,
            'updated_at' => null,
            'outputs' => [],
        ];
    }

    private function validateDocument(array $document, string $inspectionRecordId): void
    {
        if (($document['schema_version'] ?? null) !== self::DOCUMENT_VERSION ||
            ($document['document_type'] ?? null) !== 'client_outputs' ||
            ($document['inspection_record_id'] ?? null) !== $inspectionRecordId ||
            !is_int($document['revision'] ?? null) || $document['revision'] < 0 ||
            !is_array($document['outputs'] ?? null) || count($document['outputs']) > self::MAX_OUTPUTS) {
            throw $this->integrity('The client output document structure is invalid.');
        }
        $outputIds = [];
        $mediaIds = [];
        $photoIds = [];
        foreach ($document['outputs'] as $output) {
            if (!is_array($output)) {
                throw $this->integrity('The client output entry is invalid.');
            }
            $outputId = $this->validateOutputId((string)($output['id'] ?? ''));
            if (isset($outputIds[$outputId])) {
                throw $this->integrity('The client output ID is duplicated.');
            }
            $outputIds[$outputId] = true;
            $kind = $output['kind'] ?? null;
            if (!in_array($kind, ['link', 'pdf', 'gallery'], true) ||
                !is_string($output['title'] ?? null) || !is_string($output['description'] ?? null)) {
                throw $this->integrity('The client output fields are invalid.');
            }
            if ($kind === 'link') {
                $type = $output['type'] ?? null;
                if (!is_string($type) || !isset(self::LINK_TYPES[$type]) || !is_string($output['url'] ?? null)) {
                    throw $this->integrity('The client output link is invalid.');
                }
                $this->validateExternalUrl($output['url'], self::LINK_TYPES[$type]);
            } elseif ($kind === 'pdf') {
                $this->validateMediaMetadata($output['media'] ?? null, ['application/pdf'], $mediaIds);
            } else {
                if (!is_array($output['photos'] ?? null) || count($output['photos']) > self::MAX_GALLERY_PHOTOS) {
                    throw $this->integrity('The client gallery is invalid.');
                }
                foreach ($output['photos'] as $photo) {
                    if (!is_array($photo) || !is_string($photo['title'] ?? null) || !is_string($photo['caption'] ?? null)) {
                        throw $this->integrity('The gallery photo metadata is invalid.');
                    }
                    $photoId = $this->validatePhotoId((string)($photo['id'] ?? ''));
                    if (isset($photoIds[$photoId])) {
                        throw $this->integrity('The gallery photo ID is duplicated.');
                    }
                    $photoIds[$photoId] = true;
                    $this->validateMediaMetadata($photo['media'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], $mediaIds);
                }
            }
        }
    }

    /** @param array<string, bool> $mediaIds */
    private function validateMediaMetadata($media, array $allowedTypes, array &$mediaIds): void
    {
        if (!is_array($media)) {
            throw $this->integrity('The client output media metadata is invalid.');
        }
        $id = $this->validateMediaId((string)($media['id'] ?? ''));
        $type = $media['content_type'] ?? null;
        $extension = is_string($type) ? (self::MIME_EXTENSIONS[$type] ?? null) : null;
        if (isset($mediaIds[$id]) || !in_array($type, $allowedTypes, true) ||
            $extension === null || ($media['filename'] ?? null) !== $id . '.' . $extension ||
            !is_int($media['size_bytes'] ?? null) || $media['size_bytes'] < 1 ||
            !is_string($media['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $media['sha256']) !== 1) {
            throw $this->integrity('The client output media metadata is invalid.');
        }
        $mediaIds[$id] = true;
    }

    /** @return array<string, mixed> */
    private function adminProjection(array $document): array
    {
        $outputs = [];
        foreach ($document['outputs'] as $output) {
            $safe = [
                'id' => $output['id'],
                'kind' => $output['kind'],
                'type' => $output['type'],
                'title' => $output['title'],
                'description' => $output['description'],
            ];
            if ($output['kind'] === 'link') {
                $safe['url'] = $output['url'];
            } elseif ($output['kind'] === 'pdf') {
                $safe['mediaId'] = $output['media']['id'];
                $safe['contentType'] = $output['media']['content_type'];
                $safe['sizeBytes'] = $output['media']['size_bytes'];
            } else {
                $safe['photos'] = [];
                foreach ($output['photos'] as $photo) {
                    $safe['photos'][] = [
                        'id' => $photo['id'],
                        'title' => $photo['title'],
                        'caption' => $photo['caption'],
                        'mediaId' => $photo['media']['id'],
                        'contentType' => $photo['media']['content_type'],
                        'sizeBytes' => $photo['media']['size_bytes'],
                    ];
                }
            }
            $outputs[] = $safe;
        }
        return [
            'schemaVersion' => self::DOCUMENT_VERSION,
            'inspectionRecordId' => $document['inspection_record_id'],
            'revision' => $document['revision'],
            'updatedAt' => $document['updated_at'],
            'outputs' => $outputs,
        ];
    }

    /** @param array<string, mixed> $upload
     *  @return array{source: string, content_type: string, extension: string, size_bytes: int, sha256: string}
     */
    private function prepareUpload(array $upload, array $allowedTypes, int $maximumBytes): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw $this->validation('The uploaded client output file is invalid.');
        }
        $size = @filesize($upload['tmp_name']);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw $this->validation('The uploaded client output file size is invalid.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->file($upload['tmp_name']);
        if (!is_string($contentType) || !in_array($contentType, $allowedTypes, true) ||
            !isset(self::MIME_EXTENSIONS[$contentType])) {
            throw $this->validation('The uploaded client output file type is not allowed.');
        }
        $hash = @hash_file('sha256', $upload['tmp_name']);
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw $this->integrity('The uploaded client output file cannot be verified.');
        }
        return [
            'source' => $upload['tmp_name'],
            'content_type' => $contentType,
            'extension' => self::MIME_EXTENSIONS[$contentType],
            'size_bytes' => $size,
            'sha256' => $hash,
        ];
    }

    /** @param array{source: string, content_type: string, extension: string, size_bytes: int, sha256: string} $upload
     *  @return array<string, mixed>
     */
    private function persistUploadUnlocked(string $inspectionRecordId, array $upload): array
    {
        $directory = $this->recordDirectory($inspectionRecordId, true);
        $files = $this->ensureDirectory($directory . DIRECTORY_SEPARATOR . 'files', 0700);
        $mediaId = $this->newId('outm');
        $filename = $mediaId . '.' . $upload['extension'];
        $destination = $files . DIRECTORY_SEPARATOR . $filename;
        $temporary = $files . DIRECTORY_SEPARATOR . '.upload-' . bin2hex(random_bytes(12)) . '.tmp';
        $source = @fopen($upload['source'], 'rb');
        $target = @fopen($temporary, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($temporary);
            throw $this->io('The client output file cannot be staged.');
        }
        $copyError = null;
        try {
            if (stream_copy_to_stream($source, $target) !== $upload['size_bytes'] || !fflush($target)) {
                throw $this->io('The client output file cannot be copied.');
            }
            if (function_exists('fsync') && !@fsync($target)) {
                throw $this->io('The client output file cannot be synchronized.');
            }
        } catch (Throwable $error) {
            $copyError = $error;
        } finally {
            fclose($source);
            fclose($target);
        }
        if ($copyError !== null) {
            @unlink($temporary);
            throw $copyError;
        }
        if (@hash_file('sha256', $temporary) !== $upload['sha256'] || is_link($temporary) || !@rename($temporary, $destination)) {
            @unlink($temporary);
            throw $this->integrity('The staged client output file failed verification.');
        }
        @chmod($destination, 0640);
        return [
            'id' => $mediaId,
            'filename' => $filename,
            'content_type' => $upload['content_type'],
            'size_bytes' => $upload['size_bytes'],
            'sha256' => $upload['sha256'],
        ];
    }

    /** @return array<string, mixed> */
    private function resolveMediaUnlocked(string $inspectionRecordId, array $media): array
    {
        $directory = $this->recordDirectory($inspectionRecordId, false);
        if ($directory === null) {
            throw $this->notFound('The client output media does not exist.');
        }
        $files = $directory . DIRECTORY_SEPARATOR . 'files';
        $this->assertSafeDirectory($files);
        $path = $files . DIRECTORY_SEPARATOR . $media['filename'];
        if (is_link($path)) {
            throw $this->integrity('The client output media path is unsafe.');
        }
        if (!file_exists($path)) {
            throw $this->notFound('The client output media does not exist.');
        }
        $this->assertSafeRegularFile($path);
        $canonicalFiles = realpath($files);
        $canonicalPath = realpath($path);
        if ($canonicalFiles === false || $canonicalPath === false ||
            dirname($canonicalPath) !== $canonicalFiles ||
            @filesize($canonicalPath) !== $media['size_bytes'] ||
            @hash_file('sha256', $canonicalPath) !== $media['sha256']) {
            throw $this->integrity('The client output media failed integrity verification.');
        }
        return [
            'id' => $media['id'],
            'path' => $canonicalPath,
            'content_type' => $media['content_type'],
            'size_bytes' => $media['size_bytes'],
        ];
    }

    private function removeMediaFileUnlocked(string $inspectionRecordId, array $media): void
    {
        try {
            $resolved = $this->resolveMediaUnlocked($inspectionRecordId, $media);
            @unlink($resolved['path']);
        } catch (DiagnosticsStorageException $error) {
            if ($error->getStorageCode() !== 'STORAGE_OUTPUT_NOT_FOUND') {
                throw $error;
            }
        }
    }

    /** @return string|null */
    private function recordDirectory(string $inspectionRecordId, bool $create): ?string
    {
        $this->assertSafeDirectory($this->root);
        $path = $this->root . DIRECTORY_SEPARATOR . $inspectionRecordId;
        if (is_link($path)) {
            throw $this->integrity('The client output record directory is unsafe.');
        }
        if (!file_exists($path)) {
            if (!$create) {
                return null;
            }
            return $this->ensureDirectory($path, 0700);
        }
        $this->assertSafeDirectory($path);
        $canonicalRoot = realpath($this->root);
        $canonicalPath = realpath($path);
        if ($canonicalRoot === false || $canonicalPath === false || dirname($canonicalPath) !== $canonicalRoot) {
            throw $this->integrity('The client output record directory is unsafe.');
        }
        return $canonicalPath;
    }

    /** @return mixed */
    private function withLock(string $inspectionRecordId, callable $callback)
    {
        $this->assertSafeDirectory($this->locksRoot);
        $path = $this->locksRoot . DIRECTORY_SEPARATOR . 'client-output-' . hash('sha256', $inspectionRecordId) . '.lock';
        if (is_link($path)) {
            throw $this->integrity('The client output lock is unsafe.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $this->io('The client output lock cannot be acquired.');
        }
        try {
            if (is_link($path)) {
                throw $this->integrity('The client output lock is unsafe.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($path, 0600);
        }
    }

    private function atomicWrite(string $path, array $document): void
    {
        if (is_link($path)) {
            throw $this->integrity('The client output document path is unsafe.');
        }
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw $this->integrity('The client output document cannot be serialized.');
        }
        $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.outputs-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw $this->io('The client output document cannot be staged.');
        }
        $writeError = null;
        try {
            $payload = $json . "\n";
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw $this->io('The client output document cannot be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw $this->io('The client output document cannot be synchronized.');
            }
        } catch (Throwable $error) {
            $writeError = $error;
        } finally {
            fclose($handle);
        }
        if ($writeError !== null) {
            @unlink($temporary);
            throw $writeError;
        }
        $check = json_decode((string)@file_get_contents($temporary), true);
        if (!is_array($check) || is_link($temporary) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw $this->io('The client output document cannot be committed.');
        }
        @chmod($path, 0640);
    }

    private function ensureDirectory(string $path, int $mode): string
    {
        if (is_link($path)) {
            throw $this->integrity('The client output directory is unsafe.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
            throw $this->io('The client output directory cannot be created.');
        }
        $this->assertSafeDirectory($path);
        @chmod($path, $mode);
        $canonical = realpath($path);
        if ($canonical === false) {
            throw $this->io('The client output directory cannot be resolved.');
        }
        return $canonical;
    }

    private function assertSafeDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            throw $this->integrity('The client output directory is unsafe.');
        }
    }

    private function assertSafeRegularFile(string $path): void
    {
        if (is_link($path) || !is_file($path)) {
            throw $this->integrity('The client output file is unsafe.');
        }
    }

    private function assertRevision(array $document, int $expectedRevision): void
    {
        if ($expectedRevision < 0 || $document['revision'] !== $expectedRevision) {
            throw new DiagnosticsStorageException('STORAGE_OUTPUT_REVISION_CONFLICT', 'The client output revision is stale.');
        }
    }

    private function assertOutputCapacity(array $document): void
    {
        if (count($document['outputs']) >= self::MAX_OUTPUTS) {
            throw $this->validation('The client output limit was exceeded.');
        }
    }

    private function findOutputIndex(array $document, string $outputId): int
    {
        foreach ($document['outputs'] as $index => $output) {
            if (($output['id'] ?? null) === $outputId) {
                return $index;
            }
        }
        return -1;
    }

    private function findPhotoIndex(array $gallery, string $photoId): int
    {
        foreach ($gallery['photos'] as $index => $photo) {
            if (($photo['id'] ?? null) === $photoId) {
                return $index;
            }
        }
        return -1;
    }

    private function swap(array &$items, int $index, string $direction): void
    {
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($items)) {
            return;
        }
        $temporary = $items[$target];
        $items[$target] = $items[$index];
        $items[$index] = $temporary;
    }

    private function validateInspectionRecordId(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $value) !== 1) {
            throw $this->validation('The inspection record ID is invalid.');
        }
        return $value;
    }

    private function validateOutputId(string $value): string
    {
        if (preg_match('/^out_[0-9a-f]{32}$/D', $value) !== 1) {
            throw $this->validation('The client output ID is invalid.');
        }
        return $value;
    }

    private function validatePhotoId(string $value): string
    {
        if (preg_match('/^outp_[0-9a-f]{32}$/D', $value) !== 1) {
            throw $this->validation('The gallery photo ID is invalid.');
        }
        return $value;
    }

    private function validateMediaId(string $value): string
    {
        if (preg_match('/^outm_[0-9a-f]{32}$/D', $value) !== 1) {
            throw $this->validation('The client output media ID is invalid.');
        }
        return $value;
    }

    private function validateExternalUrl(string $value, array $allowedHosts): string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw $this->validation('The client output URL is invalid.');
        }
        $parts = parse_url($value);
        $host = is_array($parts) && is_string($parts['host'] ?? null)
            ? strtolower(rtrim($parts['host'], '.'))
            : '';
        $allowed = false;
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || substr($host, -strlen('.' . $allowedHost)) === '.' . $allowedHost) {
                $allowed = true;
                break;
            }
        }
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
            $host === '' || isset($parts['user']) || isset($parts['pass']) || !$allowed) {
            throw $this->validation('The client output URL is not allowed.');
        }
        return $value;
    }

    private function cleanText(string $value, int $limit): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?: '');
        return function_exists('mb_substr') ? mb_substr($clean, 0, $limit) : substr($clean, 0, $limit);
    }

    private function newId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    private function validation(string $message): DiagnosticsStorageException
    {
        return new DiagnosticsStorageException('STORAGE_OUTPUT_VALIDATION', $message);
    }

    private function notFound(string $message): DiagnosticsStorageException
    {
        return new DiagnosticsStorageException('STORAGE_OUTPUT_NOT_FOUND', $message);
    }

    private function integrity(string $message): DiagnosticsStorageException
    {
        return new DiagnosticsStorageException('STORAGE_OUTPUT_INTEGRITY', $message);
    }

    private function io(string $message): DiagnosticsStorageException
    {
        return new DiagnosticsStorageException('STORAGE_OUTPUT_IO', $message);
    }
}
