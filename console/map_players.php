<?php
declare(strict_types=1);

/** Parse only a complete, single-player response from Bedrock's querytarget command. */
function parse_querytarget_position(array $lines): ?array
{
    $json = null;
    $capture = [];
    foreach ($lines as $line) {
        if (!is_string($line)) return null;
        if (preg_match('/\bTarget data:\s*(\[.*)$/', $line, $match)) {
            if ($json !== null || $capture !== []) return null;
            $capture[] = $match[1];
        } elseif ($capture !== []) {
            $capture[] = $line;
        }
        if ($capture !== [] && trim($line) === ']') {
            $json = implode("\n", $capture);
            $capture = [];
        }
    }
    if ($json === null || $capture !== []) return null;
    $targets = json_decode($json, true, 8);
    if (!is_array($targets) || count($targets) !== 1 || !is_array($targets[0])) return null;
    $target = $targets[0];
    $position = $target['position'] ?? null;
    $dimension = $target['dimension'] ?? null;
    if (!is_array($position) || !is_int($dimension) || !in_array($dimension, [0, 1, 2], true)) return null;
    $coordinates = [];
    foreach (['x', 'y', 'z'] as $axis) {
        $value = $position[$axis] ?? null;
        if (!is_int($value) && !is_float($value)) return null;
        $value = (float)$value;
        if (!is_finite($value) || abs($value) > 30000000) return null;
        $coordinates[$axis] = round($value, 2);
    }
    return ['dimension' => $dimension, 'x' => $coordinates['x'], 'y' => $coordinates['y'], 'z' => $coordinates['z']];
}

/** Limit names passed to selectors; all other online names remain listed without a location. */
function querytarget_command(string $name): ?string
{
    if (!preg_match('/^[A-Za-z0-9_ ]{1,16}$/D', $name)) return null;
    return 'querytarget @a[name="' . $name . '"]';
}

/** Match Bedrock's block-coordinate display for a standing player. */
function querytarget_block_position(array $position): array
{
    return [
        'x' => (int)floor($position['x']),
        'y' => (int)floor($position['y'] - 1.62 + 0.0001),
        'z' => (int)floor($position['z']),
    ];
}
