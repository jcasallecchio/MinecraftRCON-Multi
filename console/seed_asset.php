<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_login();

$instanceId = (string)($_GET['instance_id'] ?? '');
$revision = (string)($_GET['revision'] ?? '');
$kind = (string)($_GET['kind'] ?? 'biomes');
if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) ||
    !preg_match('/^[a-f0-9]{24}$/', $revision) || !in_array($kind, ['biomes', 'slime'], true) ||
    bedrock_instance_config($instanceId) === null) {
    http_response_code(404);
    exit;
}
$metadataPath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-layer-' . $instanceId . '.json';
$metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null;
if (!is_array($metadata) || ($metadata[$kind === 'slime' ? 'slime_revision' : 'image_revision'] ?? null) !== $revision) {
    http_response_code(404);
    exit;
}
$image = STORAGE_DIR . DIRECTORY_SEPARATOR . ($kind === 'slime' ? 'slime-layer-' : 'seed-layer-') . $instanceId . '-' . $revision . '.png';
if (!is_file($image)) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . (string)filesize($image));
readfile($image);
