<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsClientOutputStore;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsClientOutputStore.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dh-client-output-store-' . bin2hex(random_bytes(8));
$web = $root . DIRECTORY_SEPARATOR . 'web';
$storageRoot = $root . DIRECTORY_SEPARATOR . 'storage';
if (!mkdir($web, 0700, true)) {
    throw new RuntimeException('Cannot create client output test root.');
}

$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

try {
    $storage = new DiagnosticsStorage($storageRoot, $web);
    $store = new DiagnosticsClientOutputStore($storage);
    $empty = $store->list('safe-inspection');
    $assert($empty['revision'] === 0 && $empty['outputs'] === [], 'An empty output document is invalid.');

    try {
        $store->list('../reports');
        $assert(false, 'Traversal inspection ID was accepted.');
    } catch (DiagnosticsStorageException $error) {
        $assert($error->getStorageCode() === 'STORAGE_OUTPUT_VALIDATION', 'Traversal failed with the wrong code.');
    }

    $outside = $root . DIRECTORY_SEPARATOR . 'outside';
    mkdir($outside, 0700, true);
    $recordLink = $storageRoot . DIRECTORY_SEPARATOR . 'client-outputs' . DIRECTORY_SEPARATOR . 'linked-record';
    if (!symlink($outside, $recordLink)) {
        throw new RuntimeException('Cannot create required record symlink fixture.');
    }
    try {
        $store->list('linked-record');
        $assert(false, 'A symlinked output record was accepted.');
    } catch (DiagnosticsStorageException $error) {
        $assert($error->getStorageCode() === 'STORAGE_OUTPUT_INTEGRITY', 'Record symlink failed with the wrong code.');
    }
    unlink($recordLink);

    $lockId = 'lock-symlink-record';
    $lockPath = $storageRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR .
        'client-output-' . hash('sha256', $lockId) . '.lock';
    $lockTarget = $outside . DIRECTORY_SEPARATOR . 'lock-target';
    file_put_contents($lockTarget, 'target');
    if (!symlink($lockTarget, $lockPath)) {
        throw new RuntimeException('Cannot create required lock symlink fixture.');
    }
    try {
        $store->list($lockId);
        $assert(false, 'A symlinked client output lock was accepted.');
    } catch (DiagnosticsStorageException $error) {
        $assert($error->getStorageCode() === 'STORAGE_OUTPUT_INTEGRITY', 'Lock symlink failed with the wrong code.');
    }

    echo 'Diagnostics client output store tests passed: ' . $assertions . " assertions.\n";
} finally {
    $remove($root);
}
