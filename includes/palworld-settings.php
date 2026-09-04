<?php
declare(strict_types=1);

function fbgPalworldSettingsConfig(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/palworld-settings.php';
    }

    return $config;
}

function fbgPalworldIsProtonServer(array $server): bool
{
    $source = strtolower(trim(
        (string)($server['egg_name'] ?? '') . ' ' .
        (string)($server['name'] ?? '') . ' ' .
        (string)($server['description'] ?? '') . ' ' .
        (string)($server['docker_image'] ?? '') . ' ' .
        (string)($server['image'] ?? '')
    ));

    return str_contains($source, 'palworld') && str_contains($source, 'proton');
}

function fbgPalworldConfigPath(?array $server = null): string
{
    $config = fbgPalworldSettingsConfig();

    if ($server !== null && fbgPalworldIsProtonServer($server)) {
        return (string)($config['windows_path'] ?? '/Pal/Saved/Config/WindowsServer/PalWorldSettings.ini');
    }

    return (string)($config['linux_path'] ?? $config['path'] ?? '/Pal/Saved/Config/LinuxServer/PalWorldSettings.ini');
}

function fbgPalworldCanonicalDefault(): string
{
    $config = fbgPalworldSettingsConfig();
    return rtrim((string)($config['canonical_default'] ?? ''), "\r\n") . "\n";
}

function fbgPalworldMetadata(): array
{
    $config = fbgPalworldSettingsConfig();
    $metadata = $config['metadata'] ?? [];
    return is_array($metadata) ? $metadata : [];
}

function fbgPalworldIsServer(array $server): bool
{
    $source = strtolower(trim(
        (string)($server['egg_name'] ?? '') . ' ' .
        (string)($server['name'] ?? '') . ' ' .
        (string)($server['description'] ?? '')
    ));

    return str_contains($source, 'palworld');
}

function fbgPalworldSplitOptionPairs(string $body): array
{
    $pairs = [];
    $buffer = '';
    $inQuote = false;
    $escaped = false;
    $nestedDepth = 0;
    $length = strlen($body);

    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];

        if ($escaped) {
            $buffer .= $char;
            $escaped = false;
            continue;
        }

        if ($inQuote && $char === '\\') {
            $buffer .= $char;
            $escaped = true;
            continue;
        }

        if ($char === '"') {
            $inQuote = !$inQuote;
            $buffer .= $char;
            continue;
        }

        if (!$inQuote && $char === '(') {
            $nestedDepth++;
            $buffer .= $char;
            continue;
        }

        if (!$inQuote && $char === ')') {
            if ($nestedDepth > 0) {
                $nestedDepth--;
            }
            $buffer .= $char;
            continue;
        }

        if (!$inQuote && $nestedDepth === 0 && $char === ',') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $pairs[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if ($inQuote) {
        throw new RuntimeException('PalWorldSettings.ini contains an unterminated quoted value.');
    }

    if ($nestedDepth !== 0) {
        throw new RuntimeException('PalWorldSettings.ini contains a malformed list value.');
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $pairs[] = $trimmed;
    }

    return $pairs;
}

function fbgPalworldFindEqualsOutsideQuotes(string $pair): int
{
    $inQuote = false;
    $escaped = false;
    $length = strlen($pair);

    for ($i = 0; $i < $length; $i++) {
        $char = $pair[$i];

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($inQuote && $char === '\\') {
            $escaped = true;
            continue;
        }

        if ($char === '"') {
            $inQuote = !$inQuote;
            continue;
        }

        if (!$inQuote && $char === '=') {
            return $i;
        }
    }

    return -1;
}

function fbgPalworldDecodeValue(string $raw): array
{
    $trimmed = trim($raw);
    $quoted = strlen($trimmed) >= 2 && $trimmed[0] === '"' && substr($trimmed, -1) === '"';

    if ($quoted) {
        $inner = substr($trimmed, 1, -1);
        return [
            'value' => str_replace(['\\"', '\\\\'], ['"', '\\'], $inner),
            'kind' => 'string',
            'quoted' => true,
        ];
    }

    if (strcasecmp($trimmed, 'true') === 0 || strcasecmp($trimmed, 'false') === 0) {
        return [
            'value' => strcasecmp($trimmed, 'true') === 0,
            'kind' => 'boolean',
            'quoted' => false,
        ];
    }

    if (preg_match('/^-?\d+$/', $trimmed) === 1) {
        return [
            'value' => (int)$trimmed,
            'kind' => 'integer',
            'quoted' => false,
        ];
    }

    if (preg_match('/^-?(?:\d+\.\d+|\d+\.|\.\d+)(?:[eE][+-]?\d+)?$/', $trimmed) === 1) {
        return [
            'value' => (float)$trimmed,
            'kind' => 'float',
            'quoted' => false,
        ];
    }

    if (strlen($trimmed) >= 2 && $trimmed[0] === '(' && substr($trimmed, -1) === ')') {
        $inner = substr($trimmed, 1, -1);
        $items = [];

        if (trim($inner) !== '') {
            foreach (fbgPalworldSplitOptionPairs($inner) as $item) {
                $items[] = trim($item);
            }
        }

        return [
            'value' => $items,
            'kind' => 'list',
            'quoted' => false,
        ];
    }

    return [
        'value' => $trimmed,
        'kind' => 'enum',
        'quoted' => false,
    ];
}

function fbgPalworldExtractOptionBody(string $contents): array
{
    $offset = strpos($contents, 'OptionSettings=(');
    if ($offset === false) {
        throw new RuntimeException('PalWorldSettings.ini is missing its OptionSettings block.');
    }

    $bodyStart = $offset + strlen('OptionSettings=(');
    $inQuote = false;
    $escaped = false;
    $depth = 1;
    $length = strlen($contents);

    for ($i = $bodyStart; $i < $length; $i++) {
        $char = $contents[$i];

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($inQuote && $char === '\\') {
            $escaped = true;
            continue;
        }

        if ($char === '"') {
            $inQuote = !$inQuote;
            continue;
        }

        if ($inQuote) {
            continue;
        }

        if ($char === '(') {
            $depth++;
            continue;
        }

        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return [
                    'prefix' => substr($contents, 0, $bodyStart),
                    'body' => substr($contents, $bodyStart, $i - $bodyStart),
                    'suffix' => substr($contents, $i),
                ];
            }
        }
    }

    throw new RuntimeException('PalWorldSettings.ini has a malformed OptionSettings block.');
}

function fbgPalworldParseConfig(string $contents): array
{
    $parts = fbgPalworldExtractOptionBody($contents);
    $settings = [];
    $byKey = [];

    foreach (fbgPalworldSplitOptionPairs($parts['body']) as $pair) {
        $equals = fbgPalworldFindEqualsOutsideQuotes($pair);
        if ($equals <= 0) {
            throw new RuntimeException('PalWorldSettings.ini contains a malformed setting: ' . $pair);
        }

        $key = trim(substr($pair, 0, $equals));
        $raw = trim(substr($pair, $equals + 1));

        if ($key === '' || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
            throw new RuntimeException('PalWorldSettings.ini contains an invalid setting key.');
        }

        if (array_key_exists($key, $byKey)) {
            throw new RuntimeException('PalWorldSettings.ini contains a duplicate setting: ' . $key);
        }

        $decoded = fbgPalworldDecodeValue($raw);
        $entry = [
            'key' => $key,
            'raw' => $raw,
            'value' => $decoded['value'],
            'kind' => $decoded['kind'],
            'quoted' => $decoded['quoted'],
        ];

        $settings[] = $entry;
        $byKey[$key] = $entry;
    }

    return [
        'prefix' => $parts['prefix'],
        'suffix' => $parts['suffix'],
        'settings' => $settings,
        'by_key' => $byKey,
    ];
}

function fbgPalworldInferControlType(array $entry, array $metadata): string
{
    $type = (string)($metadata['type'] ?? '');
    if ($type !== '') {
        return $type;
    }

    return match ((string)$entry['kind']) {
        'boolean' => 'boolean',
        'integer' => 'integer',
        'float' => 'float',
        'list' => 'list',
        default => 'string',
    };
}

function fbgPalworldFriendlyLabel(string $key): string
{
    $label = preg_replace('/^b(?=[A-Z])/', '', $key);
    $label = preg_replace('/(?<!^)([A-Z])/', ' $1', (string)$label);
    $label = str_replace([' Hp ', ' HP ', ' R C O N ', ' I P ', ' P v P '], [' HP ', ' HP ', ' RCON ', ' IP ', ' PvP '], (string)$label);

    return trim((string)$label) ?: $key;
}

function fbgPalworldHydrateSettings(array $settings, array $metadata): array
{
    $hydrated = [];

    foreach ($settings as $entry) {
        $key = (string)$entry['key'];
        $meta = $metadata[$key] ?? [];
        $known = is_array($meta) && $meta !== [];
        $controlType = fbgPalworldInferControlType($entry, is_array($meta) ? $meta : []);

        $hydrated[] = [
            'key' => $key,
            'label' => (string)($meta['label'] ?? fbgPalworldFriendlyLabel($key)),
            'description' => (string)($meta['description'] ?? 'This setting was found in your Palworld config file.'),
            'category' => (string)($meta['category'] ?? 'Other Settings'),
            'type' => $controlType,
            'value' => $entry['value'],
            'raw' => $entry['raw'],
            'kind' => $entry['kind'],
            'quoted' => (bool)$entry['quoted'],
            'known' => $known,
            'sensitive' => !empty($meta['sensitive']) || in_array($key, ['AdminPassword', 'ServerPassword'], true),
            'options' => array_values((array)($meta['options'] ?? [])),
            'min' => $meta['min'] ?? null,
            'max' => $meta['max'] ?? null,
            'step' => $meta['step'] ?? null,
        ];
    }

    return $hydrated;
}

function fbgPalworldFindMissingDefaultKeys(array $currentByKey, array $defaultByKey): array
{
    $missing = [];

    foreach ($defaultByKey as $key => $_entry) {
        if (!array_key_exists($key, $currentByKey)) {
            $missing[] = (string)$key;
        }
    }

    return $missing;
}

function fbgPalworldSerializeString(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function fbgPalworldValidateAndSerializeValue(array $entry, mixed $value, array $metadata): string
{
    $key = (string)$entry['key'];
    $type = fbgPalworldInferControlType($entry, $metadata);

    if ($type === 'boolean') {
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
            return 'True';
        }

        if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
            return 'False';
        }

        throw new RuntimeException($key . ' must be enabled or disabled.');
    }

    if ($type === 'integer') {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException($key . ' must be a whole number.');
        }

        $number = (int)$value;
        if (array_key_exists('min', $metadata) && $number < (int)$metadata['min']) {
            throw new RuntimeException($key . ' is below the allowed minimum.');
        }
        if (array_key_exists('max', $metadata) && $number > (int)$metadata['max']) {
            throw new RuntimeException($key . ' is above the allowed maximum.');
        }

        return (string)$number;
    }

    if ($type === 'float') {
        if (!is_numeric($value)) {
            throw new RuntimeException($key . ' must be a number.');
        }

        $number = (float)$value;
        if (array_key_exists('min', $metadata) && $number < (float)$metadata['min']) {
            throw new RuntimeException($key . ' is below the allowed minimum.');
        }
        if (array_key_exists('max', $metadata) && $number > (float)$metadata['max']) {
            throw new RuntimeException($key . ' is above the allowed maximum.');
        }

        return number_format($number, 6, '.', '');
    }

    if ($type === 'select') {
        $string = trim((string)$value);
        $options = array_values((array)($metadata['options'] ?? []));

        if ($options !== [] && !in_array($string, $options, true)) {
            throw new RuntimeException($key . ' has an invalid value.');
        }

        return $string;
    }

    if ($type === 'multiselect' || (string)($entry['kind'] ?? '') === 'list') {
        $values = is_array($value)
            ? $value
            : array_map('trim', explode(',', (string)$value));
        $options = array_values((array)($metadata['options'] ?? []));
        $clean = [];

        foreach ($values as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }

            if ($options !== [] && !in_array($item, $options, true)) {
                throw new RuntimeException($key . ' has an invalid value.');
            }

            if (preg_match('/^[A-Za-z0-9_.:-]+$/', $item) !== 1) {
                throw new RuntimeException($key . ' contains an invalid list value.');
            }

            $clean[] = $item;
        }

        return '(' . implode(',', array_values(array_unique($clean))) . ')';
    }

    $string = (string)$value;

    if ((bool)($entry['quoted'] ?? false) || in_array((string)$entry['kind'], ['string'], true)) {
        return fbgPalworldSerializeString($string);
    }

    if (preg_match('/^[A-Za-z0-9_.:\/-]*$/', $string) !== 1) {
        return fbgPalworldSerializeString($string);
    }

    return $string;
}

function fbgPalworldSerializeParsedConfig(array $parsed): string
{
    $pairs = [];

    foreach ((array)($parsed['settings'] ?? []) as $entry) {
        $pairs[] = (string)$entry['key'] . '=' . (string)$entry['raw'];
    }

    $prefix = (string)($parsed['prefix'] ?? "[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(");
    $suffix = (string)($parsed['suffix'] ?? ')');

    return $prefix . implode(',', $pairs) . $suffix;
}

function fbgPalworldApplySubmittedValues(array $parsed, array $submitted): array
{
    $metadata = fbgPalworldMetadata();

    foreach ($parsed['settings'] as $index => $entry) {
        $key = (string)$entry['key'];
        if (!array_key_exists($key, $submitted)) {
            continue;
        }

        $meta = is_array($metadata[$key] ?? null) ? $metadata[$key] : [];
        $parsed['settings'][$index]['raw'] = fbgPalworldValidateAndSerializeValue($entry, $submitted[$key], $meta);
        $decoded = fbgPalworldDecodeValue($parsed['settings'][$index]['raw']);
        $parsed['settings'][$index]['value'] = $decoded['value'];
        $parsed['settings'][$index]['kind'] = $decoded['kind'];
        $parsed['settings'][$index]['quoted'] = $decoded['quoted'];
    }

    return $parsed;
}

function fbgPalworldMergeMissingDefaults(string $currentContents): string
{
    $current = fbgPalworldParseConfig($currentContents);
    $defaults = fbgPalworldParseConfig(fbgPalworldCanonicalDefault());
    $currentByKey = $current['by_key'];

    foreach ($defaults['settings'] as $defaultEntry) {
        $key = (string)$defaultEntry['key'];
        if (!array_key_exists($key, $currentByKey)) {
            $current['settings'][] = $defaultEntry;
            $currentByKey[$key] = $defaultEntry;
        }
    }

    return fbgPalworldSerializeParsedConfig($current);
}
