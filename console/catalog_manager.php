<?php
declare(strict_types=1);

/**
 * Keeps the MineDev command and item suggestions aligned with Mojang's
 * versioned Bedrock samples. A private cached copy keeps the console usable
 * when GitHub is temporarily unavailable.
 */
const BEDROCK_CATALOG_TTL = 86400;
const BEDROCK_SAMPLES_VERSION_URL = 'https://raw.githubusercontent.com/Mojang/bedrock-samples/main/version.json';
const BEDROCK_SAMPLES_COMMANDS_PATH = 'metadata/command_modules/mojang-commands.json';
const BEDROCK_SAMPLES_ITEMS_PATH = 'metadata/vanilladata_modules/mojang-items.json';
const BEDROCK_SAMPLES_BLOCKS_PATH = 'metadata/vanilladata_modules/mojang-blocks.json';
const BEDROCK_SAMPLES_ENTITIES_PATH = 'metadata/vanilladata_modules/mojang-entities.json';

function bedrock_catalog_http_get(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'MineDev-Bedrock-Catalog/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return is_string($body) && $status === 200 ? $body : null;
}

function bedrock_catalog_http_get_many(array $urls): array
{
    if (!function_exists('curl_multi_init')) {
        return [];
    }
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $handle = curl_init((string)$url);
        if ($handle === false) {
            continue;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MineDev-Bedrock-Catalog/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $handles[$key] = $handle;
        curl_multi_add_handle($multi, $handle);
    }
    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running && $status === CURLM_OK) {
            $selected = curl_multi_select($multi, 0.5);
            if ($selected === -1) {
                usleep(100000);
            }
        }
    } while ($running && $status === CURLM_OK);

    $responses = [];
    foreach ($handles as $key => $handle) {
        $body = curl_multi_getcontent($handle);
        $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (is_string($body) && $httpStatus === 200) {
            $responses[$key] = $body;
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $responses;
}

function bedrock_catalog_atomic_write(string $path, string $contents): bool
{
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        return false;
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($temporary, 0600);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function bedrock_catalog_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function bedrock_catalog_category(string $name): string
{
    $groups = [
        'Jogadores' => ['clear', 'clearspawnpoint', 'deop', 'effect', 'enchant', 'gamemode', 'give', 'inputpermission', 'kick', 'kill', 'op', 'permission', 'replaceitem', 'spawnpoint', 'spreadplayers', 'tag', 'teleport', 'tp', 'xp'],
        'Administração' => ['allowlist', 'changesetting', 'difficulty', 'help', 'list', 'reload', 'reloadconfig', 'reloadpacketlimitconfig', 'save', 'setmaxplayers', 'stop', 'transfer'],
        'Mundo' => ['clone', 'daylock', 'execute', 'fill', 'gamerule', 'locate', 'place', 'setblock', 'setworldspawn', 'structure', 'tickingarea', 'time', 'toggledownfall', 'weather'],
        'Entidades' => ['damage', 'event', 'ride', 'summon', 'testfor'],
        'Chat' => ['me', 'say', 'tell', 'tellraw', 'title', 'titleraw'],
        'Desenvolvimento' => ['function', 'gametest', 'reload', 'script', 'scriptevent', 'wsserver'],
        'Educação' => ['agent', 'codebuilder_actorinfo'],
    ];
    foreach ($groups as $category => $names) {
        if (in_array($name, $names, true)) {
            return $category;
        }
    }
    return 'Administração';
}

function bedrock_catalog_argument_name(array $parameter): string
{
    $name = (string)($parameter['name'] ?? 'value');
    $type = strtoupper((string)($parameter['type']['name'] ?? ''));
    $normalized = strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $name));
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized);
    if ($type === 'SELECTION' || preg_match('/\b(player|players|target|targets|victim)\b/', $normalized)) {
        return 'player';
    }
    if ($type === 'ITEM' || str_contains($normalized, 'item')) {
        return 'item';
    }
    if (str_contains($normalized, 'block')) {
        return 'block';
    }
    if (str_contains($normalized, 'position') || str_contains($normalized, 'coordinate')) {
        return 'position';
    }
    if (str_contains($normalized, 'message') || str_contains($normalized, 'text')) {
        return 'message';
    }
    if (str_contains($normalized, 'json') || str_contains($normalized, 'component')) {
        return 'components';
    }
    if (str_contains($normalized, 'amount') || str_contains($normalized, 'count')) {
        return 'amount';
    }
    if ($normalized === '') {
        $normalized = strtolower($type !== '' ? $type : 'value');
    }
    return preg_replace('/\s+/', '_', $normalized) ?: 'value';
}

function bedrock_catalog_command_syntax(string $name, array $command): string
{
    $overloads = $command['overloads'] ?? [];
    if (!is_array($overloads) || $overloads === []) {
        return $name;
    }
    $forms = [];
    foreach (array_slice($overloads, 0, 3) as $overload) {
        $parts = [$name];
        foreach (($overload['params'] ?? []) as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $token = '<' . bedrock_catalog_argument_name($parameter) . '>';
            $parts[] = !empty($parameter['is_optional']) ? '[' . substr($token, 1, -1) . ']' : $token;
        }
        $forms[] = implode(' ', $parts);
    }
    if (count($overloads) > 3) {
        $forms[] = '…';
    }
    return implode(' | ', $forms);
}

function bedrock_catalog_merge(array $base, array $commandsDocument, array $itemsDocument, array $blocksDocument, array $entitiesDocument, string $sourceVersion): array
{
    $curated = [];
    foreach (($base['commands'] ?? []) as $item) {
        if (is_array($item) && !empty($item['name'])) {
            $curated[(string)$item['name']] = $item;
        }
    }
    $commands = [];
    $seen = [];
    $enumValues = [];
    foreach (($commandsDocument['command_enums'] ?? []) as $enum) {
        if (!is_array($enum) || empty($enum['name'])) {
            continue;
        }
        $enumValues[strtoupper((string)$enum['name'])] = array_values(array_unique(array_map(
            static fn(array $value): string => (string)($value['value'] ?? ''),
            array_filter($enum['values'] ?? [], 'is_array')
        )));
    }
    $commandSchemas = [];
    foreach (($commandsDocument['commands'] ?? []) as $official) {
        if (!is_array($official) || empty($official['name'])) {
            continue;
        }
        $name = strtolower((string)$official['name']);
        $syntax = bedrock_catalog_command_syntax($name, $official);
        $overloadSchemas = [];
        foreach (($official['overloads'] ?? []) as $overload) {
            $arguments = [];
            foreach (($overload['params'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                $type = strtoupper((string)($parameter['type']['name'] ?? ''));
                $arguments[] = [
                    'name' => (string)($parameter['name'] ?? ''),
                    'type' => $type,
                    'optional' => !empty($parameter['is_optional']),
                ];
            }
            $overloadSchemas[] = ['args' => $arguments];
        }
        $commandSchemas[$name] = $overloadSchemas;
        $entry = $curated[$name] ?? [];
        $commands[] = [
            'name' => $name,
            'description' => (string)($entry['description'] ?? $official['description'] ?? 'Comando do Minecraft Bedrock.'),
            'syntax' => $syntax,
            'category' => (string)($entry['category'] ?? bedrock_catalog_category($name)),
        ];
        $seen[$name] = true;
        foreach (($official['aliases'] ?? []) as $alias) {
            $aliasName = strtolower((string)(is_array($alias) ? ($alias['name'] ?? '') : $alias));
            if ($aliasName === '' || isset($seen[$aliasName])) {
                continue;
            }
            $aliasEntry = $curated[$aliasName] ?? [];
            $aliasSyntax = $syntax === $name ? $aliasName : $aliasName . substr($syntax, strlen($name));
            $commands[] = [
                'name' => $aliasName,
                'description' => (string)($aliasEntry['description'] ?? ('Alias de /' . $name . '.')),
                'syntax' => (string)($aliasEntry['syntax'] ?? $aliasSyntax),
                'category' => (string)($aliasEntry['category'] ?? bedrock_catalog_category($name)),
            ];
            $seen[$aliasName] = true;
            $commandSchemas[$aliasName] = $overloadSchemas;
        }
    }
    // Keep locally documented server/Education commands that are not present
    // in Mojang's public metadata, while the official list remains primary.
    foreach ($curated as $name => $entry) {
        if (!isset($seen[$name])) {
            $commands[] = $entry;
            $seen[$name] = true;
            if (!isset($commandSchemas[$name]) && preg_match('/^Alias de \/([a-z0-9_]+)\./i', (string)($entry['description'] ?? ''), $match)) {
                $commandSchemas[$name] = $commandSchemas[strtolower($match[1])] ?? [];
            }
        }
    }

    $items = [];
    foreach (($itemsDocument['data_items'] ?? []) as $item) {
        $identifier = is_array($item) ? trim((string)($item['command_name'] ?? '')) : '';
        if ($identifier !== '' && preg_match('/^[a-z0-9_.-]+:[a-z0-9_./-]+$/i', $identifier)) {
            $items[$identifier] = true;
        }
    }
    $itemNames = array_keys($items);
    sort($itemNames, SORT_STRING);

    $blocks = [];
    foreach (($blocksDocument['data_items'] ?? []) as $block) {
        $identifier = is_array($block) ? trim((string)($block['name'] ?? '')) : '';
        if ($identifier !== '' && preg_match('/^[a-z0-9_.-]+:[a-z0-9_./-]+$/i', $identifier)) {
            $blocks[$identifier] = true;
        }
    }
    $blockNames = array_keys($blocks);
    sort($blockNames, SORT_STRING);

    $entities = [];
    foreach (($entitiesDocument['data_items'] ?? []) as $entity) {
        $identifier = is_array($entity) ? trim((string)($entity['name'] ?? '')) : '';
        if ($identifier !== '' && preg_match('/^[a-z0-9_.-]+:[a-z0-9_./-]+$/i', $identifier)) {
            $entities[$identifier] = true;
        }
    }
    $entityNames = array_keys($entities);
    sort($entityNames, SORT_STRING);

    $base['commands'] = $commands;
    $base['items'] = $itemNames;
    $base['blocks'] = $blockNames;
    $base['entities'] = $entityNames;
    $base['command_schemas'] = $commandSchemas;
    $base['command_enums'] = $enumValues;
    $base['catalog_metadata'] = [
        'provider' => 'Mojang bedrock-samples',
        'source_version' => $sourceVersion,
        'updated_at' => date(DATE_ATOM),
        'command_count' => count($commands),
        'item_count' => count($itemNames),
        'block_count' => count($blockNames),
        'entity_count' => count($entityNames),
    ];
    return $base;
}

function bedrock_catalog_refresh(array $base): ?array
{
    $versionBody = bedrock_catalog_http_get(BEDROCK_SAMPLES_VERSION_URL);
    $versionDocument = is_string($versionBody) ? json_decode($versionBody, true) : null;
    $version = is_array($versionDocument) ? (string)($versionDocument['latest']['version'] ?? '') : '';
    if (!preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version)) {
        return null;
    }
    $tag = 'v' . $version;
    $root = 'https://raw.githubusercontent.com/Mojang/bedrock-samples/' . $tag . '/';
    $documents = bedrock_catalog_http_get_many([
        'commands' => $root . BEDROCK_SAMPLES_COMMANDS_PATH,
        'items' => $root . BEDROCK_SAMPLES_ITEMS_PATH,
        'blocks' => $root . BEDROCK_SAMPLES_BLOCKS_PATH,
        'entities' => $root . BEDROCK_SAMPLES_ENTITIES_PATH,
    ]);
    if (count($documents) !== 4) {
        return null;
    }
    $commandsDocument = json_decode($documents['commands'], true);
    $itemsDocument = json_decode($documents['items'], true);
    $blocksDocument = json_decode($documents['blocks'], true);
    $entitiesDocument = json_decode($documents['entities'], true);
    if (!is_array($commandsDocument) || count($commandsDocument['commands'] ?? []) < 20 || !is_array($itemsDocument) || count($itemsDocument['data_items'] ?? []) < 100 || !is_array($blocksDocument) || count($blocksDocument['data_items'] ?? []) < 100 || !is_array($entitiesDocument) || count($entitiesDocument['data_items'] ?? []) < 20) {
        return null;
    }
    return bedrock_catalog_merge($base, $commandsDocument, $itemsDocument, $blocksDocument, $entitiesDocument, $version);
}

function bedrock_catalog_load(): array
{
    $basePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'commands-bedrock.json';
    $base = bedrock_catalog_read_json($basePath) ?? ['commands' => [], 'selectors' => [], 'glossary' => []];
    $fallbackItemsPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'bedrock-items.json';
    $fallbackItems = bedrock_catalog_read_json($fallbackItemsPath);
    if (is_array($fallbackItems) && is_array($fallbackItems['items'] ?? null)) {
        $base['items'] = $fallbackItems['items'];
        $base['catalog_metadata'] = $fallbackItems['catalog_metadata'] ?? [];
    }
    $fallbackCommandData = bedrock_catalog_read_json(__DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'bedrock-command-data.json');
    if (is_array($fallbackCommandData)) {
        foreach (['command_schemas', 'command_enums', 'blocks', 'entities'] as $key) {
            if (is_array($fallbackCommandData[$key] ?? null)) {
                $base[$key] = $fallbackCommandData[$key];
            }
        }
    }

    $cachePath = STORAGE_DIR . DIRECTORY_SEPARATOR . 'bedrock_catalog.json';
    $cached = bedrock_catalog_read_json($cachePath);
    if ($cached !== null && isset($cached['_checked_at']) && time() - (int)$cached['_checked_at'] < BEDROCK_CATALOG_TTL) {
        unset($cached['_checked_at']);
        return bedrock_catalog_fill_missing($cached, $base);
    }

    $lockPath = STORAGE_DIR . DIRECTORY_SEPARATOR . '.bedrock_catalog.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        if ($cached !== null) {
            unset($cached['_checked_at']);
            return bedrock_catalog_fill_missing($cached, $base);
        }
        return $base;
    }

    try {
        $cached = bedrock_catalog_read_json($cachePath);
        if ($cached !== null && isset($cached['_checked_at']) && time() - (int)$cached['_checked_at'] < BEDROCK_CATALOG_TTL) {
            unset($cached['_checked_at']);
            return bedrock_catalog_fill_missing($cached, $base);
        }
        $catalog = bedrock_catalog_refresh($base);
        if ($catalog === null) {
            $catalog = $cached ?? $base;
            if (!isset($catalog['items']) || !is_array($catalog['items'])) {
                $catalog['items'] = $base['items'] ?? [];
            }
        }
        $catalog['_checked_at'] = time();
        $encoded = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            bedrock_catalog_atomic_write($cachePath, $encoded);
        }
        unset($catalog['_checked_at']);
        return bedrock_catalog_fill_missing($catalog, $base);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function bedrock_catalog_fill_missing(array $catalog, array $fallback): array
{
    foreach (['commands', 'selectors', 'items', 'blocks', 'entities', 'command_schemas', 'command_enums'] as $key) {
        if (empty($catalog[$key]) && !empty($fallback[$key])) {
            $catalog[$key] = $fallback[$key];
        }
    }
    $catalog['catalog_metadata'] = array_merge(
        $fallback['catalog_metadata'] ?? [],
        $catalog['catalog_metadata'] ?? []
    );
    $catalog['catalog_metadata']['item_count'] = count($catalog['items'] ?? []);
    return $catalog;
}
