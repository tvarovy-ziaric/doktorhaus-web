<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    dh_preview_fail(405, 'Method not allowed.');
}
dh_preview_require_owner();
$query = dh_preview_query_exact(['preview']);
$directory = dh_preview_directory($query['preview']);

try {
    $meta = dh_preview_load_json_file($directory . DIRECTORY_SEPARATOR . 'preview-meta.json');
    $path = $directory . DIRECTORY_SEPARATOR . 'client-report-preview-v1.0.json';
    $expected = $meta['files']['client-report-preview-v1.0.json'] ?? null;
    $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Report integrity failure.');
    }
    $body = file_get_contents($path);
    if (!is_string($body)) {
        throw new RuntimeException('Report read failure.');
    }
} catch (RuntimeException $error) {
    dh_preview_fail(503, 'Preview report is unavailable.');
}

dh_preview_security_headers();
header('Vary: Cookie');
http_response_code(200);
session_write_close();
echo $body;
