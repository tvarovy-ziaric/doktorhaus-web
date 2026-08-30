<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    dh_preview_fail(405, 'Method not allowed.');
}
dh_preview_require_owner();
session_write_close();
$query = dh_preview_query_exact(['preview', 'evidence']);
$previewId = $query['preview'];
$evidenceId = $query['evidence'];
if (preg_match('/^ev_[0-9a-f]{16,32}$/D', $evidenceId) !== 1) {
    dh_preview_fail(400, 'Invalid evidence identifier.');
}
$directory = dh_preview_directory($previewId);

try {
    $meta = dh_preview_load_json_file($directory . DIRECTORY_SEPARATOR . 'preview-meta.json');
    $manifestPath = $directory . DIRECTORY_SEPARATOR . 'preview-manifest.json';
    $manifestHash = $meta['files']['preview-manifest.json'] ?? null;
    $manifestActual = is_file($manifestPath) && !is_link($manifestPath) ? hash_file('sha256', $manifestPath) : false;
    if (!is_string($manifestHash) || !is_string($manifestActual) || !hash_equals($manifestHash, $manifestActual)) {
        throw new RuntimeException('Manifest integrity failure.');
    }
    $manifest = dh_preview_load_json_file($manifestPath);
    $selected = null;
    foreach (($manifest['media'] ?? []) as $media) {
        if (is_array($media) && ($media['evidence_id'] ?? null) === $evidenceId) {
            $selected = $media;
            break;
        }
    }
    if (!is_array($selected)) {
        dh_preview_fail(404, 'Media not found.');
    }
    $relative = $selected['path'] ?? null;
    $contentType = $selected['content_type'] ?? null;
    $expectedHash = $selected['sha256'] ?? null;
    if (!is_string($relative) || preg_match('/^media\/ev_[0-9a-f]{16,32}\.(?:jpg|jpeg|png|webp)$/D', $relative) !== 1
        || !is_string($contentType) || !in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
        || !is_string($expectedHash) || !hash_equals((string)($meta['files'][$relative] ?? ''), $expectedHash)) {
        throw new RuntimeException('Media descriptor failure.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $mediaRoot = realpath($directory . DIRECTORY_SEPARATOR . 'media');
    $realPath = realpath($path);
    $actualHash = is_string($realPath) && is_file($realPath) && !is_link($realPath) ? hash_file('sha256', $realPath) : false;
    if ($mediaRoot === false || $realPath === false || dirname($realPath) !== $mediaRoot || !is_file($realPath) || is_link($realPath)
        || !is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Media integrity failure.');
    }
    $size = filesize($realPath);
    if (!is_int($size) || $size < 1) {
        throw new RuntimeException('Media size failure.');
    }
} catch (RuntimeException $error) {
    dh_preview_fail(503, 'Preview media is unavailable.');
}

$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
dh_preview_security_headers($contentType);
header('Vary: Cookie');
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="doktorhaus-' . $evidenceId . '.' . $extensions[$contentType] . '"');
http_response_code(200);
if ($method === 'HEAD') {
    exit;
}
$handle = fopen($realPath, 'rb');
if ($handle === false) {
    exit;
}
while (!feof($handle)) {
    $chunk = fread($handle, 65536);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
}
fclose($handle);
