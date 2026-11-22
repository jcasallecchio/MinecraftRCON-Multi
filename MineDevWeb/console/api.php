<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'map_players.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
cleanup_expired_logs();

function read_payload(): array
{
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    return is_array($decoded) ? $decoded : [];
}
function reject_method(string $expected): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
        header('Allow: ' . $expected);
        json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Método não permitido.']], 405);
    }
}
function login_rate_limited(): bool
{
    $count = 0;
    foreach (read_jsonl('login_attempts.jsonl') as $record) {
        if ((int)($record['attempted_at'] ?? 0) > time() - 900 && hash_equals((string)($record['ip_hash'] ?? ''), client_fingerprint())) {
            $count++;
        }
    }
    return $count >= 8;
}
function bedrock_failure(array $result): never
{
    json_response([
        'error' => [
            'code' => $result['http_status'] === 0 ? 'bedrock_unavailable' : 'bedrock_rejected',
            'message' => (string)($result['error'] ?? 'Falha de comunicação com o Bedrock.'),
        ],
        'upstream_status' => (int)($result['http_status'] ?? 0),
    ], 502);
}

if ($action === 'csrf') {
    reject_method('GET');
    json_response(['csrf' => (string)$_SESSION['csrf']]);
}

if ($action === 'login') {
    reject_method('POST');
    $payload = read_payload();
    require_csrf($payload);
    if (login_rate_limited()) {
        audit_event('unknown', 'login', '', 'rate_limited', 'Limite temporário de tentativas atingido.');
        json_response(['error' => ['code' => 'rate_limited', 'message' => 'Muitas tentativas. Aguarde 15 minutos e tente novamente.']], 429);
    }
    append_login_attempt(client_fingerprint());
    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if (!hash_equals($GLOBALS['APP_USERNAME'], $username) || !password_verify($password, $GLOBALS['APP_PASSWORD_HASH'])) {
        audit_event('unknown', 'login', '', 'denied', 'Credenciais inválidas.');
        usleep(250000);
        json_response(['error' => ['code' => 'login_failed', 'message' => 'Usuário ou senha inválidos.']], 401);
    }
    clear_login_attempts(client_fingerprint());
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $GLOBALS['APP_USERNAME'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    audit_event(authenticated_actor(), 'login', '', 'success');
    json_response(['ok' => true, 'username' => authenticated_actor(), 'csrf' => $_SESSION['csrf']]);
}

require_login();
if ($method !== 'GET') {
    require_csrf(read_payload());
}

if ($action === 'logout') {
    reject_method('POST');
    audit_event(authenticated_actor(), 'logout', '', 'success');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
    json_response(['ok' => true]);
}

if ($action === 'status') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $instance = bedrock_instance_config($instanceId);
    $info = bedrock_request('/api/v1/info', 'GET', null, $instanceId);
    $telemetry = bedrock_request('/api/v1/telemetry', 'GET', null, $instanceId);
    json_response([
        'ok' => $info['ok'] && $telemetry['ok'],
        'configured' => $instance !== null,
        'instance' => $instance !== null ? ['id' => $instance['id'], 'name' => $instance['name']] : null,
        'connection_error' => $info['error'] ?? $telemetry['error'] ?? null,
        'info' => $info['data'],
        'telemetry' => $telemetry['data'],
    ]);
}

if ($action === 'events') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $after = max(0, (int)($_GET['after'] ?? 0));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $result = bedrock_request('/api/v1/events?after=' . $after . '&limit=' . $limit, 'GET', null, $instanceId);
    if (!$result['ok']) {
        bedrock_failure($result);
    }
    json_response($result['data']);
}

if ($action === 'player-stats') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $result = bedrock_request('/api/v1/player-stats', 'GET', null, $instanceId);
    if (!$result['ok']) {
        bedrock_failure($result);
    }
    json_response($result['data']);
}

if ($action === 'command') {
    reject_method('POST');
    $payload = read_payload();
    $command = trim((string)($payload['command'] ?? ''));
    if (str_starts_with($command, '/')) {
        $command = ltrim(substr($command, 1));
    }
    $commandLength = function_exists('mb_strlen') ? mb_strlen($command) : strlen($command);
    if ($command === '' || $commandLength > 1024 || preg_match('/[\\x00-\\x1F\\x7F]/u', $command)) {
        audit_event(authenticated_actor(), 'command', $command, 'invalid', 'Comando vazio ou inválido.');
        json_response(['error' => ['code' => 'invalid_command', 'message' => 'Informe um comando Bedrock válido com até 1024 caracteres.']], 400);
    }
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $result = bedrock_request('/api/v1/commands', 'POST', ['command' => $command], $instanceId);
    if (!$result['ok']) {
        audit_event(authenticated_actor(), 'command', $command, 'failed', (string)$result['error']);
        bedrock_failure($result);
    }
    $data = $result['data'];
    $lines = is_array($data['console_output'] ?? null) ? $data['console_output'] : [];
    $detail = implode("\n", array_map('strval', $lines));
    $status = !empty($data['sent']) ? 'sent' : 'failed';
    audit_event(authenticated_actor(), 'command', $command, $status, $detail !== '' ? $detail : (string)($data['message'] ?? ''));
    json_response([
        'ok' => true,
        'request_id' => $data['request_id'] ?? null,
        'sent' => (bool)($data['sent'] ?? false),
        'execution_confirmed' => (bool)($data['execution_confirmed'] ?? false),
        'console_output' => $lines,
        'message' => (string)($data['message'] ?? 'Comando enviado.'),
    ]);
}

if ($action === 'control') {
    reject_method('POST');
    $payload = read_payload();
    $controlAction = (string)($payload['action'] ?? '');
    if (!in_array($controlAction, ['start', 'stop', 'restart'], true)) {
        json_response(['error' => ['code' => 'invalid_action', 'message' => 'Ação inválida para a instância.']], 400);
    }
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $result = bedrock_request('/api/v1/control', 'POST', ['action' => $controlAction], $instanceId);
    if (!$result['ok']) {
        audit_event(authenticated_actor(), 'instance_' . $controlAction, '', 'failed', (string)$result['error']);
        bedrock_failure($result);
    }
    audit_event(authenticated_actor(), 'instance_' . $controlAction, '', 'success', 'Instância ' . $instanceId);
    json_response($result['data']);
}

if ($action === 'server-properties') {
    if (!in_array($method, ['GET', 'PUT'], true)) {
        header('Allow: GET, PUT');
        json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Método não permitido.']], 405);
    }
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $payload = null;
    if ($method === 'PUT') {
        $input = read_payload();
        $content = $input['content'] ?? null;
        $revision = $input['revision'] ?? null;
        if (!is_string($content) || !is_string($revision) || strlen($content) > 65536) {
            json_response(['error' => ['code' => 'invalid_properties', 'message' => 'Arquivo de propriedades inválido ou grande demais.']], 400);
        }
        $payload = ['content' => $content, 'revision' => $revision, 'restart' => ($input['restart'] ?? false) === true];
    }
    $result = bedrock_request('/api/v1/server-properties', $method, $payload, $instanceId);
    if (!$result['ok']) {
        if ($method === 'PUT') audit_event(authenticated_actor(), 'properties_save', '', 'failed', 'Instância ' . $instanceId . ': ' . (string)$result['error']);
        if ($result['http_status'] === 409) {
            json_response(['error' => ['code' => 'properties_conflict', 'message' => (string)$result['error']]], 409);
        }
        bedrock_failure($result);
    }
    if ($method === 'PUT') {
        audit_event(authenticated_actor(), 'properties_save', '', 'success', 'Instância ' . $instanceId . (!empty($payload['restart']) ? '; reinício solicitado' : ''));
    }
    json_response($result['data']);
}

if ($action === 'instances') {
    reject_method('GET');
    json_response(['instances' => bedrock_instance_public_list()]);
}

if ($action === 'map-status') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null) {
        json_response(['error' => ['code' => 'invalid_instance', 'message' => 'Instância inválida.']], 400);
    }
    $mapDirectory = STORAGE_DIR . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $instanceId;
    $manifestPath = $mapDirectory . DIRECTORY_SEPARATOR . 'map-info.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
    json_response([
        'available' => is_file($mapDirectory . DIRECTORY_SEPARATOR . 'index.html'),
        'instance_id' => $instanceId,
        'world_name' => is_array($manifest) ? (string)($manifest['world_name'] ?? '') : '',
        'generated_at' => is_array($manifest) ? (string)($manifest['snapshot_at'] ?? $manifest['generated_at'] ?? '') : '',
    ]);
}

if ($action === 'map-players') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null) {
        json_response(['error' => ['code' => 'invalid_instance', 'message' => 'Instância inválida.']], 400);
    }
    $cachePath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'map-players-' . $instanceId . '.json';
    $lockPath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'map-players-' . $instanceId . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        json_response(['error' => ['code' => 'map_players_unavailable', 'message' => 'Não foi possível consultar os jogadores.']], 503);
    }
    try {
        $cached = is_file($cachePath) ? json_decode((string)file_get_contents($cachePath), true) : null;
        $now = time();
        if (is_array($cached) && ($cached['schema_version'] ?? null) === 2 && ($cached['instance_id'] ?? null) === $instanceId &&
            isset($cached['sampled_epoch'], $cached['refresh_seconds']) &&
            is_int($cached['sampled_epoch']) && is_int($cached['refresh_seconds']) &&
            $now >= $cached['sampled_epoch'] && $now - $cached['sampled_epoch'] < $cached['refresh_seconds']) {
            $snapshot = $cached;
        } else {
            $telemetry = bedrock_request('/api/v1/telemetry', 'GET', null, $instanceId);
            $players = $telemetry['data']['players'] ?? [];
            $names = is_array($players['names'] ?? null) ? array_values(array_filter($players['names'], 'is_string')) : [];
            $onlineCount = (int)($players['online_count'] ?? 0);
            $ready = $telemetry['ok'] && ($telemetry['data']['server']['status'] ?? null) === 'running' &&
                ($players['stale'] ?? true) === false && $onlineCount >= 0 && $onlineCount <= 100 &&
                count($names) === $onlineCount && count(array_unique(array_map('strtolower', $names))) === $onlineCount;
            $refreshSeconds = $ready ? max(6, min(30, 3 * max(1, $onlineCount))) : 5;
            $located = [];
            if ($ready) {
                foreach ($names as $name) {
                    $position = null;
                    $command = querytarget_command($name);
                    if ($command !== null) {
                        $result = bedrock_request('/api/v1/commands', 'POST', ['command' => $command], $instanceId);
                        if ($result['ok'] && !empty($result['data']['sent']) && is_array($result['data']['console_output'] ?? null)) {
                            $position = parse_querytarget_position($result['data']['console_output']);
                        }
                    }
                    $located[] = [
                        'name' => $name,
                        'position' => $position,
                        'block' => $position !== null ? querytarget_block_position($position) : null,
                    ];
                }
            }
            $snapshot = [
                'schema_version' => 2,
                'instance_id' => $instanceId,
                'available' => $ready,
                'online_count' => $ready ? $onlineCount : null,
                'players' => $located,
                'sampled_at' => gmdate('c'),
                'sampled_epoch' => time(),
                'refresh_seconds' => $refreshSeconds,
                'source' => 'querytarget',
            ];
            $temporary = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temporary, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
                if (!rename($temporary, $cachePath)) @unlink($temporary);
            }
            if (is_file($temporary)) @unlink($temporary);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    unset($snapshot['sampled_epoch']);
    header('Cache-Control: no-store');
    json_response($snapshot);
}

if ($action === 'map-markers') {
    if (!in_array($method, ['GET', 'PUT'], true)) {
        header('Allow: GET, PUT');
        json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Método não permitido.']], 405);
    }
    $instanceId = (string)($_GET['instance_id'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null) {
        json_response(['error' => ['code' => 'invalid_instance', 'message' => 'Instância inválida.']], 400);
    }
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . 'map-markers-' . $instanceId . '.json';
    $readMarkers = static function () use ($path): array {
        if (!is_file($path)) return [];
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    };
    if ($method === 'GET') {
        $markers = with_storage_lock($readMarkers);
        json_response(['markers' => $markers, 'revision' => hash('sha256', json_encode($markers))]);
    }
    $payload = read_payload();
    $markers = $payload['markers'] ?? null;
    if (!is_array($markers) || !array_is_list($markers) || count($markers) > 100) {
        json_response(['error' => ['code' => 'invalid_markers', 'message' => 'Lista de marcadores inválida ou muito grande.']], 400);
    }
    $validated = [];
    $seen = [];
    foreach ($markers as $marker) {
        if (!is_array($marker) || !isset($marker['id'], $marker['name'], $marker['x'], $marker['z']) ||
            !is_string($marker['id']) || !preg_match('/^[a-f0-9]{16,32}$/', $marker['id']) || isset($seen[$marker['id']]) ||
            !is_string($marker['name']) || trim($marker['name']) === '' ||
            preg_match('//u', $marker['name']) !== 1 || preg_match_all('/./us', $marker['name']) > 64 ||
            !is_numeric($marker['x']) || !is_numeric($marker['z']) ||
            !is_finite((float)$marker['x']) || !is_finite((float)$marker['z']) ||
            abs((float)$marker['x']) > 30000000 || abs((float)$marker['z']) > 30000000) {
            json_response(['error' => ['code' => 'invalid_marker', 'message' => 'Um marcador contém nome ou coordenadas inválidas.']], 400);
        }
        $seen[$marker['id']] = true;
        $validated[] = [
            'id' => $marker['id'],
            'name' => trim($marker['name']),
            'x' => (int)round((float)$marker['x']),
            'z' => (int)round((float)$marker['z']),
        ];
    }
    $expectedRevision = (string)($payload['revision'] ?? '');
    $result = with_storage_lock(static function () use ($readMarkers, $expectedRevision, $validated, $path): array {
        $current = $readMarkers();
        if (!hash_equals(hash('sha256', json_encode($current)), $expectedRevision)) return ['conflict' => true];
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Não foi possível salvar os marcadores.');
        }
        if (DIRECTORY_SEPARATOR !== '\\') @chmod($path, 0600);
        return ['markers' => $validated, 'revision' => hash('sha256', json_encode($validated))];
    });
    if (isset($result['conflict'])) json_response(['error' => ['code' => 'markers_conflict', 'message' => 'Os marcadores mudaram em outra sessão. Recarregue a lista.']], 409);
    audit_event(authenticated_actor(), 'map_markers_save', '', 'success', 'Instância ' . $instanceId . '; ' . count($validated) . ' marcadores');
    json_response($result);
}

if ($action === 'seed-layer') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null) {
        json_response(['error' => ['code' => 'invalid_instance', 'message' => 'Instância inválida.']], 400);
    }
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-layer-' . $instanceId . '.json';
    if (!is_file($path)) {
        json_response(['error' => ['code' => 'seed_layer_missing', 'message' => 'Os dados da seed ainda não foram gerados para esta instância.']], 404);
    }
    $data = json_decode((string)file_get_contents($path), true);
    $revision = is_array($data) ? (string)($data['image_revision'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{24}$/', $revision) || ($data['instance_id'] ?? null) !== $instanceId) {
        json_response(['error' => ['code' => 'seed_layer_invalid', 'message' => 'Arquivo da seed inválido.']], 500);
    }
    $data['image_url'] = '../../seed_asset.php?instance_id=' . rawurlencode($instanceId) . '&revision=' . $revision;
    foreach ([
        ['type' => 'fossil', 'name' => 'Fóssil'],
        ['type' => 'cave', 'name' => 'Caverna'],
        ['type' => 'ore_vein', 'name' => 'Veio de minério'],
    ] as $featureType) {
        if (!in_array($featureType['type'], array_column($data['types'] ?? [], 'type'), true)) {
            $data['types'][] = $featureType;
        }
    }
    $slimeRevision = (string)($data['slime_revision'] ?? '');
    if (preg_match('/^[a-f0-9]{24}$/', $slimeRevision)) {
        $data['slime_url'] = '../../seed_asset.php?instance_id=' . rawurlencode($instanceId) . '&revision=' . $slimeRevision . '&kind=slime';
    }
    json_response($data);
}

if ($action === 'seed-tile') {
    reject_method('GET');
    $instanceId = (string)($_GET['instance_id'] ?? '');
    $tileX = filter_var($_GET['x'] ?? null, FILTER_VALIDATE_INT);
    $tileZ = filter_var($_GET['z'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $instanceId) || bedrock_instance_config($instanceId) === null ||
        $tileX === false || $tileZ === false || $tileX === null || $tileZ === null ||
        $tileX < -16 || $tileX > 15 || $tileZ < -16 || $tileZ > 15) {
        json_response(['error' => ['code' => 'invalid_tile', 'message' => 'Setor inválido.']], 400);
    }
    $metadata = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-layer-' . $instanceId . '.json';
    $info = is_file($metadata) ? json_decode((string)file_get_contents($metadata), true) : null;
    $revision = is_array($info) ? (string)($info['tile_revision'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{24}$/', $revision) || ($info['instance_id'] ?? null) !== $instanceId) {
        json_response(['error' => ['code' => 'seed_tile_missing', 'message' => 'Setores da seed indisponíveis.']], 404);
    }
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-tiles-' . $instanceId . '-' . $revision . DIRECTORY_SEPARATOR . $tileX . '_' . $tileZ . '.json';
    $wantDungeons = (string)($_GET['dungeons'] ?? '') === '1';
    $wantFeatures = (string)($_GET['features'] ?? '') === '1';
    $dungeonPath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-dungeons-' . $instanceId . '-' . $revision . DIRECTORY_SEPARATOR . $tileX . '_' . $tileZ . '.json';
    $featurePath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-features-' . $instanceId . '-' . $revision . DIRECTORY_SEPARATOR . $tileX . '_' . $tileZ . '.json';
    if (!is_file($path) || ($wantDungeons && !is_file($dungeonPath)) || ($wantFeatures && !is_file($featurePath))) {
        $queue = STORAGE_DIR . DIRECTORY_SEPARATOR . 'seed-jobs';
        if (!is_dir($queue) && !mkdir($queue, 0700, true) && !is_dir($queue)) {
            json_response(['error' => ['code' => 'seed_worker_unavailable', 'message' => 'Não foi possível criar a fila de cálculo.']], 503);
        }
        $jobId = hash('sha256', $instanceId . ':' . $revision . ':' . $tileX . ':' . $tileZ . ':' . ($wantDungeons ? 'd' : '-') . ':' . ($wantFeatures ? 'f' : '-'));
        $jobPath = $queue . DIRECTORY_SEPARATOR . $jobId . '.json';
        with_storage_lock(static function () use ($jobPath, $instanceId, $revision, $tileX, $tileZ, $wantDungeons, $wantFeatures): void {
            if (is_file($jobPath)) return;
            $temp = $jobPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $job = ['instance_id' => $instanceId, 'revision' => $revision, 'x' => $tileX, 'z' => $tileZ,
                'dungeons' => $wantDungeons, 'features' => $wantFeatures, 'created_at' => time(), 'status' => 'queued'];
            if (file_put_contents($temp, json_encode($job, JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($temp, $jobPath)) {
                @unlink($temp);
                throw new RuntimeException('Não foi possível enfileirar o cálculo do setor.');
            }
            @chmod($jobPath, 0600);
        });
        header('Retry-After: 5');
        json_response(['instance_id' => $instanceId, 'revision' => $revision, 'x' => $tileX, 'z' => $tileZ,
            'status' => 'queued', 'points' => []], 202);
    }
    $points = json_decode((string)file_get_contents($path), true);
    if (!is_array($points)) json_response(['error' => ['code' => 'seed_tile_invalid', 'message' => 'Setor calculado inválido.']], 500);
    if ($wantDungeons) {
        $dungeons = json_decode((string)file_get_contents($dungeonPath), true);
        if (!is_array($dungeons)) json_response(['error' => ['code' => 'seed_dungeon_tile_invalid', 'message' => 'Masmorras do setor inválidas.']], 500);
        $points = array_merge($points, $dungeons);
    }
    if ($wantFeatures) {
        $features = json_decode((string)file_get_contents($featurePath), true);
        if (!is_array($features)) json_response(['error' => ['code' => 'seed_feature_tile_invalid', 'message' => 'Recursos do setor inválidos.']], 500);
        $points = array_merge($points, $features);
    }
    json_response(['instance_id' => $instanceId, 'revision' => $revision, 'x' => $tileX, 'z' => $tileZ,
        'status' => 'ready', 'points' => $points]);
}

if ($action === 'history') {
    reject_method('GET');
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));
    $before = max(0, (int)($_GET['before'] ?? 0));
    $events = read_jsonl('audit-events.jsonl');
    usort($events, fn($left, $right) => (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
    if ($before > 0) {
        $events = array_values(array_filter($events, fn($event) => (int)($event['id'] ?? 0) < $before));
    }
    json_response(['events' => array_slice($events, 0, $limit), 'retention_days' => $GLOBALS['LOG_RETENTION_DAYS']]);
}

if ($action === 'export') {
    reject_method('GET');
    header('Content-Type: text/csv; charset=utf-8');
    $retentionDays = $GLOBALS['LOG_RETENTION_DAYS'];
    header('Content-Disposition: attachment; filename="minedev-audit-' . $retentionDays . 'd.csv"');
    header('Cache-Control: no-store, private');
    $cutoff = time() - ($retentionDays * 86400);
    $events = array_values(array_filter(read_jsonl('audit-events.jsonl'), fn($event) => (int)($event['created_at'] ?? 0) >= $cutoff));
    usort($events, fn($left, $right) => (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['data_unix', 'usuario', 'evento', 'comando', 'estado', 'retorno'], escape: '');
    foreach ($events as $row) {
        foreach (['command', 'detail', 'actor', 'event_type', 'status'] as $key) {
            if (isset($row[$key]) && preg_match('/^[=+@\\-]/', (string)$row[$key])) {
                $row[$key] = "'" . $row[$key];
            }
        }
        $values = [date(DATE_ATOM, (int)($row['created_at'] ?? 0)), $row['actor'] ?? '', $row['event_type'] ?? '', $row['command'] ?? '', $row['status'] ?? '', $row['detail'] ?? ''];
        fputcsv($out, $values, escape: '');
    }
    fclose($out);
    exit;
}

if ($action === 'catalog') {
    reject_method('GET');
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'catalog_manager.php';
    $catalog = bedrock_catalog_load();
    $instanceId = (string)($_GET['instance_id'] ?? 'main');
    $telemetry = bedrock_request('/api/v1/telemetry', 'GET', null, $instanceId);
    $serverVersion = $telemetry['data']['server']['version'] ?? null;
    if (is_string($serverVersion) && $serverVersion !== '') {
        $catalog['catalog_metadata']['server_version'] = $serverVersion;
    }
    json_response($catalog);
}

json_response(['error' => ['code' => 'not_found', 'message' => 'Ação da API não encontrada.']], 404);
