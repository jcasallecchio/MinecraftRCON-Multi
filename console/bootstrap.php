<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('STORAGE_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'storage');

function load_dotenv_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo .env ausente. Execute php tools/initialize.php na pasta do console.');
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            $values[$key] = $value;
        }
    }
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
    return $values;
}

$APP_ENV = load_dotenv_file(APP_ROOT . DIRECTORY_SEPARATOR . '.env');
$APP_SECRET = getenv('APP_SECRET') ?: '';
$APP_USERNAME = getenv('APP_USERNAME') ?: '';
$APP_PASSWORD_HASH = getenv('APP_PASSWORD_HASH') ?: '';
$BEDROCK_API_URL = rtrim(getenv('BEDROCK_API_URL') ?: '', '/');
$BEDROCK_API_TOKEN = getenv('BEDROCK_API_TOKEN') ?: '';
$BEDROCK_INSTANCES_JSON = getenv('BEDROCK_INSTANCES') ?: '';
$LOG_RETENTION_DAYS = max(1, min(30, (int)(getenv('LOG_RETENTION_DAYS') ?: 7)));
if (strlen($APP_SECRET) < 64 || $APP_USERNAME === '' || $APP_PASSWORD_HASH === '') {
    throw new RuntimeException('Configuração de autenticação inválida no .env.');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0700, true) && !is_dir(STORAGE_DIR)) {
    throw new RuntimeException('Não foi possível criar a pasta privada storage/.');
}
@chmod(STORAGE_DIR, 0700);
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
session_name('MINEDSESSID');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
    'httponly' => true, 'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_start();
if (!empty($_SESSION['authenticated']) && isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 28800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (!empty($_SESSION['authenticated'])) {
    $_SESSION['last_activity'] = time();
}

function with_storage_lock(callable $callback)
{
    $lock = fopen(STORAGE_DIR . DIRECTORY_SEPARATOR . '.storage.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível abrir o bloqueio do armazenamento privado.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o armazenamento privado.');
        }
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function read_jsonl_unlocked(string $name): array
{
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        return [];
    }
    $records = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        $record = json_decode($line, true);
        if (is_array($record)) {
            $records[] = $record;
        }
    }
    fclose($handle);
    return $records;
}
function read_jsonl(string $name): array
{
    return with_storage_lock(fn() => read_jsonl_unlocked($name));
}
function append_jsonl_unlocked(string $name, array $record): void
{
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . $name;
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível gravar no armazenamento privado.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o arquivo de auditoria.');
        }
        fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($path, 0600);
    }
}
function append_login_attempt(string $ipHash): void
{
    with_storage_lock(fn() => append_jsonl_unlocked('login_attempts.jsonl', ['ip_hash' => $ipHash, 'attempted_at' => time()]));
}
function clear_login_attempts(string $ipHash): void
{
    with_storage_lock(function () use ($ipHash): void {
        $records = array_values(array_filter(read_jsonl_unlocked('login_attempts.jsonl'), fn($record) => !hash_equals((string)($record['ip_hash'] ?? ''), $ipHash)));
        replace_jsonl_unlocked('login_attempts.jsonl', $records);
    });
}
function replace_jsonl_unlocked(string $name, array $records): void
{
    $path = STORAGE_DIR . DIRECTORY_SEPARATOR . $name;
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $handle = fopen($temp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível atualizar o armazenamento privado.');
    }
    foreach ($records as $record) {
        fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    fflush($handle);
    fclose($handle);
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Não foi possível concluir a limpeza do armazenamento privado.');
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($path, 0600);
    }
}
function csrf_valid(array $payload = []): bool
{
    $provided = (string)($payload['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $provided !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $provided);
}
function require_login(): void
{
    if (empty($_SESSION['authenticated']) || empty($_SESSION['username'])) {
        json_response(['error' => ['code' => 'unauthorized', 'message' => 'Faça login para continuar.']], 401);
    }
}
function require_csrf(array $payload = []): void
{
    if (!csrf_valid($payload)) {
        json_response(['error' => ['code' => 'csrf_invalid', 'message' => 'Sessão expirada. Atualize a página e tente novamente.']], 403);
    }
}
function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function client_fingerprint(): string
{
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $GLOBALS['APP_SECRET']);
}
function audit_event(string $actor, string $type, string $command, string $status, string $detail = ''): void
{
    $clip = static fn(string $value, int $limit): string => function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    with_storage_lock(function () use ($clip, $actor, $type, $command, $status, $detail): void {
        append_jsonl_unlocked('audit-events.jsonl', [
            'id' => (int)floor(microtime(true) * 1000000), 'created_at' => time(),
            'actor' => $clip($actor, 80), 'event_type' => $clip($type, 40),
            'command' => $clip($command, 2048), 'status' => $clip($status, 40),
            'detail' => $clip($detail, 12000),
        ]);
    });
}
function cleanup_expired_logs(): void
{
    $marker = STORAGE_DIR . DIRECTORY_SEPARATOR . '.retention-pruned-at';
    if (is_file($marker) && time() - (int)@file_get_contents($marker) < 300) {
        return;
    }
    with_storage_lock(function () use ($marker): void {
        if (is_file($marker) && time() - (int)@file_get_contents($marker) < 300) {
            return;
        }
        $eventCutoff = time() - ((int)$GLOBALS['LOG_RETENTION_DAYS'] * 86400);
        $events = array_values(array_filter(read_jsonl_unlocked('audit-events.jsonl'), fn($record) => (int)($record['created_at'] ?? 0) >= $eventCutoff));
        $attemptCutoff = time() - 86400;
        $attempts = array_values(array_filter(read_jsonl_unlocked('login_attempts.jsonl'), fn($record) => (int)($record['attempted_at'] ?? 0) >= $attemptCutoff));
        replace_jsonl_unlocked('audit-events.jsonl', $events);
        replace_jsonl_unlocked('login_attempts.jsonl', $attempts);
        file_put_contents($marker, (string)time(), LOCK_EX);
    });
}
function bedrock_instance_configs(): array
{
    $raw = (string)$GLOBALS['BEDROCK_INSTANCES_JSON'];
    $configured = $raw !== '' ? json_decode($raw, true) : null;
    $instances = [];
    if (is_array($configured)) {
        foreach ($configured as $entry) {
            if (!is_array($entry)) continue;
            $id = (string)($entry['id'] ?? '');
            $url = rtrim((string)($entry['url'] ?? ''), '/');
            $token = (string)($entry['token'] ?? '');
            $name = trim((string)($entry['name'] ?? $id));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id) || $url === '' || $token === '') continue;
            $instances[$id] = ['id' => $id, 'name' => $name !== '' ? $name : $id, 'url' => $url, 'token' => $token];
        }
    }
    if ($instances === [] && $GLOBALS['BEDROCK_API_URL'] !== '' && $GLOBALS['BEDROCK_API_TOKEN'] !== '') {
        $instances['main'] = ['id' => 'main', 'name' => 'Servidor Bedrock', 'url' => $GLOBALS['BEDROCK_API_URL'], 'token' => $GLOBALS['BEDROCK_API_TOKEN']];
    }
    return $instances;
}
function bedrock_instance_public_list(): array
{
    return array_values(array_map(static fn(array $item): array => ['id' => $item['id'], 'name' => $item['name']], bedrock_instance_configs()));
}
function bedrock_instance_config(string $instanceId): ?array
{
    return bedrock_instance_configs()[$instanceId] ?? null;
}
function bedrock_request(string $path, string $method = 'GET', ?array $payload = null, string $instanceId = 'main'): array
{
    $instance = bedrock_instance_config($instanceId);
    if ($instance === null) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'Instância Bedrock inválida ou sem configuração no .env.', 'data' => null];
    }
    $url = $instance['url'] . $path;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'BEDROCK_API_URL inválida no .env.', 'data' => null];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'A extensão PHP cURL precisa estar habilitada no servidor web.', 'data' => null];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'Não foi possível iniciar o cliente HTTP.', 'data' => null];
    }
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $instance['token']];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data)) {
        return ['ok' => false, 'http_status' => $httpStatus, 'error' => $curlError !== '' ? $curlError : 'Resposta JSON inválida da API Bedrock.', 'data' => null];
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['ok' => false, 'http_status' => $httpStatus, 'error' => (string)($data['message'] ?? $data['error']['message'] ?? 'A API Bedrock recusou a solicitação.'), 'data' => $data];
    }
    return ['ok' => true, 'http_status' => $httpStatus, 'error' => null, 'data' => $data];
}
function authenticated_actor(): string
{
    return (string)($_SESSION['username'] ?? 'admin');
}
