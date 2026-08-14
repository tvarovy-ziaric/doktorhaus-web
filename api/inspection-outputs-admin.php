<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsClientOutputStore;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsClientOutputStore.php';

@ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');

/** @param array<string, mixed> $body */
function inspectionOutputsAdminRespond(int $status, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo is_string($json) ? $json : '{"ok":false,"error":"Server error."}';
    exit;
}

/** @return array<string, mixed> */
function inspectionOutputsAdminPayload(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'multipart/form-data') === 0) {
        return $_POST;
    }
    if (strpos($contentType, 'application/json') !== 0) {
        inspectionOutputsAdminRespond(415, ['ok' => false, 'error' => 'Nepodporovaný typ požiadavky.']);
    }
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        inspectionOutputsAdminRespond(400, ['ok' => false, 'error' => 'Neplatná požiadavka.']);
    }
    return $decoded;
}

function inspectionOutputsAdminPin(): string
{
    $localConfig = __DIR__ . '/inspections.config.php';
    $config = is_file($localConfig) ? require $localConfig : [];
    return (string)(getenv('INSPECTIONS_ADMIN_PIN')
        ?: getenv('PUBLIC_HELP_PIN')
        ?: (is_array($config) ? ($config['admin_pin'] ?? '') : ''));
}

/** @return array<int, array<string, mixed>> */
function inspectionOutputsAdminRecords(): array
{
    $path = __DIR__ . '/../data/inspections.json';
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Inspection records cannot be read.');
    }
    try {
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $records = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($records)) {
        throw new RuntimeException('Inspection records are invalid.');
    }
    return $records;
}

/** @param array<int, array<string, mixed>> $records */
function inspectionOutputsAdminRequireRecord(array $records, string $inspectionId): array
{
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $inspectionId) !== 1) {
        inspectionOutputsAdminRespond(422, ['ok' => false, 'error' => 'Neplatná identita inšpekcie.']);
    }
    foreach ($records as $record) {
        if (is_array($record) && ($record['id'] ?? null) === $inspectionId) {
            return $record;
        }
    }
    inspectionOutputsAdminRespond(404, ['ok' => false, 'error' => 'Inšpekcia sa nenašla.']);
}

function inspectionOutputsAdminText($value): string
{
    return is_string($value) ? $value : '';
}

function inspectionOutputsAdminRevision($value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
        return (int)$value;
    }
    inspectionOutputsAdminRespond(422, ['ok' => false, 'error' => 'Chýba platná revízia klientských výstupov.']);
}

/** @return array<int, string> */
function inspectionOutputsAdminStringList($value): array
{
    if (is_string($value)) {
        $value = json_decode($value, true);
    }
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $item) {
        $result[] = is_string($item) ? $item : '';
    }
    return $result;
}

/** @return array<int, array<string, mixed>> */
function inspectionOutputsAdminUploads(string $field): array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files)) {
        return [];
    }
    if (!is_array($files['name'] ?? null)) {
        return [$files];
    }
    $uploads = [];
    foreach ($files['name'] as $index => $_name) {
        $uploads[] = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $uploads;
}

/** @param array<string, mixed> $document */
function inspectionOutputsAdminSuccess(array $document): void
{
    inspectionOutputsAdminRespond(200, ['ok' => true, 'document' => $document]);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        inspectionOutputsAdminRespond(405, ['ok' => false, 'error' => 'Nepodporovaná metóda.']);
    }
    $payload = inspectionOutputsAdminPayload();
    $configuredPin = inspectionOutputsAdminPin();
    $submittedPin = inspectionOutputsAdminText($payload['adminPin'] ?? null);
    if ($configuredPin === '') {
        inspectionOutputsAdminRespond(503, ['ok' => false, 'error' => 'Admin služba nie je nakonfigurovaná.']);
    }
    if ($submittedPin === '' || !hash_equals($configuredPin, $submittedPin)) {
        inspectionOutputsAdminRespond(403, ['ok' => false, 'error' => 'Nesprávny Admin PIN.']);
    }

    $action = strtoupper(str_replace('_', '-', inspectionOutputsAdminText($payload['action'] ?? null)));
    $inspectionId = inspectionOutputsAdminText($payload['inspectionId'] ?? null);
    inspectionOutputsAdminRequireRecord(inspectionOutputsAdminRecords(), $inspectionId);
    $store = new DiagnosticsClientOutputStore(DiagnosticsStorage::fromEnvironment());

    if ($action === 'LIST') {
        inspectionOutputsAdminSuccess($store->list($inspectionId));
    }

    if ($action === 'READ-MEDIA') {
        $mediaId = inspectionOutputsAdminText($payload['mediaId'] ?? null);
        $media = $store->resolveMedia($inspectionId, $mediaId);
        if ($media === null) {
            inspectionOutputsAdminRespond(404, ['ok' => false, 'error' => 'Médium sa nenašlo.']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Content-Type: ' . $media['content_type']);
        header('Content-Length: ' . $media['size_bytes']);
        header('Content-Disposition: inline; filename="doktorhaus-' . $media['id'] . '"');
        readfile($media['path']);
        exit;
    }

    $revision = inspectionOutputsAdminRevision($payload['expectedRevision'] ?? null);
    if ($action === 'CREATE-LINK') {
        inspectionOutputsAdminSuccess($store->createLink(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['type'] ?? null),
            inspectionOutputsAdminText($payload['title'] ?? null),
            inspectionOutputsAdminText($payload['description'] ?? null),
            inspectionOutputsAdminText($payload['url'] ?? null)
        ));
    }
    if ($action === 'UPLOAD-PDF') {
        $upload = inspectionOutputsAdminUploads('file');
        inspectionOutputsAdminSuccess($store->uploadPdf(
            $inspectionId,
            $revision,
            $upload[0] ?? [],
            inspectionOutputsAdminText($payload['title'] ?? null),
            inspectionOutputsAdminText($payload['description'] ?? null)
        ));
    }
    if ($action === 'CREATE-GALLERY') {
        inspectionOutputsAdminSuccess($store->createGallery(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['title'] ?? null),
            inspectionOutputsAdminText($payload['description'] ?? null)
        ));
    }
    if ($action === 'UPLOAD-GALLERY-PHOTOS') {
        inspectionOutputsAdminSuccess($store->uploadGalleryPhotos(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['galleryId'] ?? null),
            inspectionOutputsAdminUploads('photos'),
            inspectionOutputsAdminStringList($payload['titles'] ?? []),
            inspectionOutputsAdminStringList($payload['captions'] ?? [])
        ));
    }
    if ($action === 'UPDATE') {
        $changes = $payload['changes'] ?? [];
        if (is_string($changes)) {
            $changes = json_decode($changes, true);
        }
        if (!is_array($changes)) {
            inspectionOutputsAdminRespond(422, ['ok' => false, 'error' => 'Neplatné údaje úpravy.']);
        }
        inspectionOutputsAdminSuccess($store->update(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['outputId'] ?? null),
            $changes,
            isset($payload['photoId']) ? inspectionOutputsAdminText($payload['photoId']) : null
        ));
    }
    if ($action === 'REORDER') {
        inspectionOutputsAdminSuccess($store->reorder(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['outputId'] ?? null),
            inspectionOutputsAdminText($payload['direction'] ?? null),
            isset($payload['photoId']) ? inspectionOutputsAdminText($payload['photoId']) : null
        ));
    }
    if ($action === 'DELETE') {
        inspectionOutputsAdminSuccess($store->delete(
            $inspectionId,
            $revision,
            inspectionOutputsAdminText($payload['outputId'] ?? null),
            isset($payload['photoId']) ? inspectionOutputsAdminText($payload['photoId']) : null
        ));
    }
    inspectionOutputsAdminRespond(400, ['ok' => false, 'error' => 'Neznáma akcia.']);
} catch (DiagnosticsStorageException $error) {
    $code = $error->getStorageCode();
    if ($code === 'STORAGE_OUTPUT_REVISION_CONFLICT') {
        inspectionOutputsAdminRespond(409, ['ok' => false, 'error' => 'Výstupy medzitým upravil iný používateľ. Načítajte ich znova.']);
    }
    if ($code === 'STORAGE_OUTPUT_NOT_FOUND') {
        inspectionOutputsAdminRespond(404, ['ok' => false, 'error' => 'Klientský výstup sa nenašiel.']);
    }
    if ($code === 'STORAGE_OUTPUT_VALIDATION') {
        inspectionOutputsAdminRespond(422, ['ok' => false, 'error' => 'Údaje alebo súbor klientského výstupu nie sú platné.']);
    }
    inspectionOutputsAdminRespond(503, ['ok' => false, 'error' => 'Klientské výstupy sa momentálne nedajú spravovať.']);
} catch (Throwable $error) {
    inspectionOutputsAdminRespond(500, ['ok' => false, 'error' => 'Klientské výstupy sa momentálne nedajú spravovať.']);
}
