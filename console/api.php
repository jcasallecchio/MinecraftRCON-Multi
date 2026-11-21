<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
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
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'commands-bedrock.json';
    $catalog = json_decode((string)file_get_contents($path), true);
    if (!is_array($catalog)) {
        json_response(['error' => ['code' => 'catalog_error', 'message' => 'Catálogo de comandos indisponível.']], 500);
    }
    json_response($catalog);
}

json_response(['error' => ['code' => 'not_found', 'message' => 'Ação da API não encontrada.']], 404);
