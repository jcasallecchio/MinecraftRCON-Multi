<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    fwrite(STDERR, ".env já existe; não foi sobrescrito.\n");
    exit(1);
}
$password = 'MDev-' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$hash = password_hash($password, PASSWORD_DEFAULT);
$secret = bin2hex(random_bytes(32));
$env = implode(PHP_EOL, [
    'APP_SECRET=' . $secret,
    'APP_USERNAME=admin',
    'APP_PASSWORD_HASH=' . $hash,
    'BEDROCK_API_URL=',
    'BEDROCK_API_TOKEN=',
    'APP_TIMEZONE=America/Sao_Paulo',
    'LOG_RETENTION_DAYS=7',
    '',
]);
umask(0077);
if (file_put_contents($envPath, $env, LOCK_EX) === false) {
    fwrite(STDERR, "Não foi possível criar .env.\n");
    exit(1);
}
if (DIRECTORY_SEPARATOR !== '\\') {
    chmod($envPath, 0600);
}
echo json_encode([
    'username' => 'admin',
    'initial_password' => $password,
    'message' => 'Guarde a senha agora. Ela não é armazenada em texto puro.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
