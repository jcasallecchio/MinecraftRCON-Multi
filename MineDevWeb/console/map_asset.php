<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_login();

$instanceId = (string)($_GET['instance'] ?? '');
if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null) {
    http_response_code(404);
    exit;
}

$asset = (string)($_GET['asset'] ?? '');
$asset = rawurldecode($asset);
if ($asset === '') {
    $asset = 'index.html';
}
if (str_contains($asset, "\0") || str_contains($asset, '\\') || str_starts_with($asset, '/') || str_starts_with($asset, '.')) {
    http_response_code(404);
    exit;
}
$segments = explode('/', $asset);
foreach ($segments as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
        http_response_code(404);
        exit;
    }
}

$mapRoot = STORAGE_DIR . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $instanceId;
$rootReal = realpath($mapRoot);
$fileReal = realpath($mapRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
if ($rootReal === false || $fileReal === false || !is_file($fileReal) || !str_starts_with($fileReal, $rootReal . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$mimeTypes = [
    'css' => 'text/css; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
    'html' => 'text/html; charset=utf-8',
    'ico' => 'image/x-icon',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
];
$extension = strtolower(pathinfo($fileReal, PATHINFO_EXTENSION));
if (!isset($mimeTypes[$extension])) {
    http_response_code(404);
    exit;
}

session_write_close();
header('Content-Type: ' . $mimeTypes[$extension]);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: ' . ($extension === 'html' || basename($fileReal) === 'unmined.map.properties.js' ? 'private, no-store' : 'private, max-age=3600'));
if ($extension === 'html') {
    header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
}
header('Content-Length: ' . (string)filesize($fileReal));
readfile($fileReal);
