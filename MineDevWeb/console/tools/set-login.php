<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "Execute primeiro: php tools/initialize.php\n");
    exit(1);
}
function read_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $hidden = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');
    if ($hidden) {
        @shell_exec('stty -echo');
    }
    $value = trim((string)fgets(STDIN));
    if ($hidden) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}
$username = trim(read_hidden('Novo usuário: '));
$password = read_hidden('Nova senha (mínimo 14 caracteres): ');
$confirm = read_hidden('Confirme a senha: ');
if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) || strlen($password) < 14 || !hash_equals($password, $confirm)) {
    fwrite(STDERR, "Dados inválidos: usuário de 3 a 64 caracteres e senhas iguais com pelo menos 14 caracteres.\n");
    exit(1);
}
$lines = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];
$replacements = [
    'APP_USERNAME' => $username,
    'APP_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT),
];
$seen = [];
foreach ($lines as &$line) {
    foreach ($replacements as $key => $value) {
        if (str_starts_with($line, $key . '=')) {
            $line = $key . '=' . $value;
            $seen[$key] = true;
        }
    }
}
unset($line);
foreach ($replacements as $key => $value) {
    if (!isset($seen[$key])) {
        $lines[] = $key . '=' . $value;
    }
}
if (file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Não foi possível atualizar .env.\n");
    exit(1);
}
if (DIRECTORY_SEPARATOR !== '\\') {
    chmod($envPath, 0600);
}
fwrite(STDOUT, "Credenciais atualizadas no .env.\n");
