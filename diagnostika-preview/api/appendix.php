<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/preview-runtime.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    dh_preview_fail(405, 'Method not allowed.');
}
dh_preview_require_owner();
session_write_close();
$query = dh_preview_query_exact(['preview']);
$directory = dh_preview_directory($query['preview']);

try {
    $meta = dh_preview_load_json_file($directory . DIRECTORY_SEPARATOR . 'preview-meta.json');
    $fileName = 'source-documentation-appendix-v1.0.json';
    $path = $directory . DIRECTORY_SEPARATOR . $fileName;
    $expected = $meta['files'][$fileName] ?? null;
    $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Appendix integrity failure.');
    }
    $body = file_get_contents($path);
    if (!is_string($body)) {
        throw new RuntimeException('Appendix read failure.');
    }
} catch (RuntimeException $error) {
    dh_preview_fail(503, 'Preview appendix is unavailable.');
}

dh_preview_security_headers();
header('Vary: Cookie');
http_response_code(200);
echo $body;
