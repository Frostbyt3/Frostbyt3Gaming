<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$currentAdminPage = 'admin-nests';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fbgAdminNestsPdo(): PDO
{
    return fbgPteroDb();
}

function fbgAdminNestsRedirect(string $message, string $type = 'success', array $params = []): void
{
    $_SESSION['admin_nests_message'] = $message;
    $_SESSION['admin_nests_message_type'] = $type;

    $query = array_merge(['name' => 'admin-nests'], $params);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    fbgRedirect('/page.php?' . http_build_query($query));
    exit;
}

function fbgAdminNestsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminNestsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminNestsUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function fbgAdminNestsSlug(string $value): string
{
    $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'egg';
}

function fbgAdminNestsJsonDecode(?string $json, mixed $fallback, bool $assoc = true): mixed
{
    $json = trim((string)$json);
    if ($json === '') {
        return $fallback;
    }

    $decoded = json_decode($json, $assoc);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function fbgAdminNestsNormalizeJsonField(string $value, mixed $emptyValue = [], ?string $expectedType = null): array
{
    $value = trim($value);
    if ($value === '') {
        return [
            'ok' => true,
            'value' => fbgAdminNestsJsonEncode($emptyValue),
        ];
    }

    $decoded = json_decode($value);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => json_last_error_msg()];
    }

    if ($expectedType === 'array' && !is_array($decoded)) {
        return ['ok' => false, 'error' => 'Expected a JSON array.'];
    }

    if ($expectedType === 'object' && !($decoded instanceof stdClass)) {
        return ['ok' => false, 'error' => 'Expected a JSON object.'];
    }

    return [
        'ok' => true,
        'value' => fbgAdminNestsJsonEncode($decoded),
    ];
}

function fbgAdminNestsDockerImagesFromTextarea(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '{}';
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    $images = [];
    foreach (preg_split('/\R+/', $value) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (str_contains($line, '|')) {
            [$label, $image] = array_map('trim', explode('|', $line, 2));
            if ($label !== '' && $image !== '') {
                $images[$label] = $image;
            }
        } else {
            $images[$line] = $line;
        }
    }

    return json_encode($images, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function fbgAdminNestsDockerImagesTextarea(?string $value): string
{
    $decoded = fbgAdminNestsJsonDecode($value, []);
    if (!is_array($decoded)) {
        return (string)$value;
    }

    $lines = [];
    foreach ($decoded as $label => $image) {
        $label = (string)$label;
        $image = (string)$image;
        $lines[] = $label === $image ? $image : ($label . ' | ' . $image);
    }

    return implode("\n", $lines);
}

function fbgAdminNestsFirstDockerImage(?string $value): string
{
    $decoded = fbgAdminNestsJsonDecode($value, []);
    if (!is_array($decoded) || empty($decoded)) {
        return '-';
    }

    $firstKey = array_key_first($decoded);
    $firstValue = $decoded[$firstKey];

    return (string)$firstKey === (string)$firstValue ? (string)$firstValue : ((string)$firstKey . ' | ' . (string)$firstValue);
}

function fbgAdminNestsJsonEncode(mixed $value): string
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return $encoded !== false ? $encoded : 'null';
}

function fbgAdminNestsNormalizeExportJsonValue(mixed $value, mixed $fallback): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return fbgAdminNestsJsonEncode($fallback);
        }

        $decoded = json_decode($trimmed);
        if (json_last_error() === JSON_ERROR_NONE) {
            return fbgAdminNestsJsonEncode($decoded);
        }

        return fbgAdminNestsJsonEncode($value);
    }

    if ($value === null) {
        return fbgAdminNestsJsonEncode($fallback);
    }

    return fbgAdminNestsJsonEncode($value);
}

function fbgAdminNestsPayloadError(string $message): array
{
    return ['ok' => false, 'message' => $message];
}

function fbgAdminNestsScalarToString(mixed $value): ?string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return null;
}

function fbgAdminNestsStringField(array $payload, string $key, string $label, string $fallback = '', bool $required = false): array
{
    if (!array_key_exists($key, $payload) || $payload[$key] === null) {
        if ($required) {
            return fbgAdminNestsPayloadError($label . ' is required.');
        }

        return ['ok' => true, 'value' => $fallback];
    }

    $value = fbgAdminNestsScalarToString($payload[$key]);
    if ($value === null) {
        return fbgAdminNestsPayloadError($label . ' must be a text value.');
    }

    if ($required && trim($value) === '') {
        return fbgAdminNestsPayloadError($label . ' is required.');
    }

    return ['ok' => true, 'value' => $value];
}

function fbgAdminNestsBoolField(mixed $value, string $label): array
{
    if (is_bool($value)) {
        return ['ok' => true, 'value' => $value ? 1 : 0];
    }

    if (is_int($value)) {
        return ['ok' => true, 'value' => $value !== 0 ? 1 : 0];
    }

    if (is_float($value)) {
        return ['ok' => true, 'value' => $value != 0.0 ? 1 : 0];
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return ['ok' => true, 'value' => 1];
        }

        if (in_array($normalized, ['', '0', 'false', 'no', 'off'], true)) {
            return ['ok' => true, 'value' => 0];
        }
    }

    return fbgAdminNestsPayloadError($label . ' must be true or false.');
}

function fbgAdminNestsJsonExportField(mixed $value, string $label, mixed $fallback): array
{
    if ($value === null) {
        return ['ok' => true, 'value' => $fallback];
    }

    if (is_array($value)) {
        return ['ok' => true, 'value' => $value];
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => $fallback];
        }

        json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return fbgAdminNestsPayloadError($label . ' must contain valid JSON data.');
        }

        return ['ok' => true, 'value' => $value];
    }

    return fbgAdminNestsPayloadError($label . ' must be valid JSON data.');
}

function fbgAdminNestsValidateDockerImages(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        return fbgAdminNestsPayloadError('That egg export is missing Docker image data.');
    }

    $images = [];
    foreach ($value as $label => $image) {
        $imageValue = fbgAdminNestsScalarToString($image);
        if ($imageValue === null || trim($imageValue) === '') {
            return fbgAdminNestsPayloadError('Docker image entries must be text values.');
        }

        $labelValue = is_string($label) && trim($label) !== '' ? $label : $imageValue;
        $images[(string)$labelValue] = $imageValue;
    }

    return ['ok' => true, 'value' => $images];
}

function fbgAdminNestsValidateFileDenylist(mixed $value): array
{
    if ($value === null) {
        return ['ok' => true, 'value' => []];
    }

    if (!is_array($value)) {
        return fbgAdminNestsPayloadError('The file denylist must be a list of file paths.');
    }

    $denylist = [];
    foreach ($value as $item) {
        $denylistItem = fbgAdminNestsScalarToString($item);
        if ($denylistItem === null) {
            return fbgAdminNestsPayloadError('File denylist entries must be text values.');
        }

        if (trim($denylistItem) !== '') {
            $denylist[] = $denylistItem;
        }
    }

    return ['ok' => true, 'value' => $denylist];
}

function fbgAdminNestsValidateEggVariables(mixed $value): array
{
    if ($value === null) {
        return ['ok' => true, 'value' => []];
    }

    if (!is_array($value)) {
        return fbgAdminNestsPayloadError('That egg export has invalid variable data.');
    }

    $variables = [];
    foreach ($value as $index => $variable) {
        $position = is_int($index) ? ($index + 1) : (count($variables) + 1);
        if (!is_array($variable)) {
            return fbgAdminNestsPayloadError('Variable #' . $position . ' must be an object.');
        }

        $name = fbgAdminNestsStringField($variable, 'name', 'Variable #' . $position . ' name', '', true);
        if (empty($name['ok'])) {
            return $name;
        }

        $description = fbgAdminNestsStringField($variable, 'description', 'Variable #' . $position . ' description');
        if (empty($description['ok'])) {
            return $description;
        }

        $envVariable = fbgAdminNestsStringField($variable, 'env_variable', 'Variable #' . $position . ' environment variable', '', true);
        if (empty($envVariable['ok'])) {
            return $envVariable;
        }

        $defaultValue = fbgAdminNestsStringField($variable, 'default_value', 'Variable #' . $position . ' default value');
        if (empty($defaultValue['ok'])) {
            return $defaultValue;
        }

        $rules = fbgAdminNestsStringField($variable, 'rules', 'Variable #' . $position . ' rules');
        if (empty($rules['ok'])) {
            return $rules;
        }

        $fieldType = fbgAdminNestsStringField($variable, 'field_type', 'Variable #' . $position . ' field type', 'text');
        if (empty($fieldType['ok'])) {
            return $fieldType;
        }

        $userViewable = fbgAdminNestsBoolField($variable['user_viewable'] ?? false, 'Variable #' . $position . ' user-viewable flag');
        if (empty($userViewable['ok'])) {
            return $userViewable;
        }

        $userEditable = fbgAdminNestsBoolField($variable['user_editable'] ?? false, 'Variable #' . $position . ' user-editable flag');
        if (empty($userEditable['ok'])) {
            return $userEditable;
        }

        $variables[] = [
            'name' => (string)$name['value'],
            'description' => (string)$description['value'],
            'env_variable' => strtoupper(trim((string)$envVariable['value'])),
            'default_value' => (string)$defaultValue['value'],
            'user_viewable' => (int)$userViewable['value'],
            'user_editable' => (int)$userEditable['value'],
            'rules' => (string)$rules['value'],
            'field_type' => (string)$fieldType['value'],
        ];
    }

    return ['ok' => true, 'value' => $variables];
}

function fbgAdminNestsValidateEggExportPayload(array $payload): array
{
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        return fbgAdminNestsPayloadError('That JSON file does not look like a supported Pterodactyl egg export.');
    }

    $version = fbgAdminNestsStringField($payload['meta'], 'version', 'Egg export version', '', true);
    if (empty($version['ok'])) {
        return fbgAdminNestsPayloadError('That JSON file does not look like a supported Pterodactyl egg export.');
    }

    $versionValue = (string)$version['value'];
    if (!in_array($versionValue, ['PTDL_v1', 'PTDL_v2'], true)) {
        return fbgAdminNestsPayloadError('That JSON file does not look like a supported Pterodactyl egg export.');
    }
    $payload['meta']['version'] = $versionValue;

    $updateUrl = fbgAdminNestsStringField($payload['meta'], 'update_url', 'Egg update URL');
    if (empty($updateUrl['ok'])) {
        return $updateUrl;
    }
    $payload['meta']['update_url'] = (string)$updateUrl['value'];

    foreach ([
        ['name', 'Egg name', '', true],
        ['author', 'Egg author', 'support@frostbyt3gaming.com', false],
        ['description', 'Egg description', '', false],
        ['startup', 'Startup command', '', true],
    ] as [$key, $label, $fallback, $required]) {
        $result = fbgAdminNestsStringField($payload, $key, $label, $fallback, $required);
        if (empty($result['ok'])) {
            return $result;
        }
        $payload[$key] = (string)$result['value'];
    }

    if ($versionValue === 'PTDL_v1') {
        $legacyImages = null;
        if (array_key_exists('images', $payload)) {
            if (!is_array($payload['images'])) {
                return fbgAdminNestsPayloadError('Legacy egg image data must be a list of Docker images.');
            }
            $legacyImages = $payload['images'];
        } elseif (array_key_exists('image', $payload)) {
            $legacyImage = fbgAdminNestsScalarToString($payload['image']);
            if ($legacyImage === null || trim($legacyImage) === '') {
                return fbgAdminNestsPayloadError('Legacy egg image data must be a Docker image.');
            }
            $legacyImages = [$legacyImage];
        } else {
            $legacyImages = ['nil'];
        }

        $payload['docker_images'] = [];
        foreach ($legacyImages as $image) {
            $imageValue = fbgAdminNestsScalarToString($image);
            if ($imageValue === null || trim($imageValue) === '') {
                return fbgAdminNestsPayloadError('Legacy egg image entries must be text values.');
            }
            $payload['docker_images'][$imageValue] = $imageValue;
        }
        unset($payload['images'], $payload['image']);
    }

    $dockerImages = fbgAdminNestsValidateDockerImages($payload['docker_images'] ?? null);
    if (empty($dockerImages['ok'])) {
        return $dockerImages;
    }
    $payload['docker_images'] = $dockerImages['value'];

    $fileDenylist = fbgAdminNestsValidateFileDenylist($payload['file_denylist'] ?? null);
    if (empty($fileDenylist['ok'])) {
        return $fileDenylist;
    }
    $payload['file_denylist'] = $fileDenylist['value'];

    if (array_key_exists('features', $payload) && $payload['features'] !== null && !is_array($payload['features'])) {
        return fbgAdminNestsPayloadError('Egg features must be a list.');
    }

    if (array_key_exists('config', $payload) && $payload['config'] !== null && !is_array($payload['config'])) {
        return fbgAdminNestsPayloadError('Egg configuration must be an object.');
    }
    $payload['config'] = is_array($payload['config'] ?? null) ? $payload['config'] : [];

    foreach ([
        ['files', 'Configuration files'],
        ['startup', 'Startup configuration'],
        ['logs', 'Log configuration'],
    ] as [$key, $label]) {
        $result = fbgAdminNestsJsonExportField($payload['config'][$key] ?? null, $label, new stdClass());
        if (empty($result['ok'])) {
            return $result;
        }
        $payload['config'][$key] = $result['value'];
    }

    $configStop = fbgAdminNestsStringField($payload['config'], 'stop', 'Stop command');
    if (empty($configStop['ok'])) {
        return $configStop;
    }
    $payload['config']['stop'] = (string)$configStop['value'];

    if (array_key_exists('scripts', $payload) && $payload['scripts'] !== null && !is_array($payload['scripts'])) {
        return fbgAdminNestsPayloadError('Egg scripts must be an object.');
    }
    $payload['scripts'] = is_array($payload['scripts'] ?? null) ? $payload['scripts'] : [];

    if (array_key_exists('installation', $payload['scripts']) && $payload['scripts']['installation'] !== null && !is_array($payload['scripts']['installation'])) {
        return fbgAdminNestsPayloadError('Egg installation script settings must be an object.');
    }
    $payload['scripts']['installation'] = is_array($payload['scripts']['installation'] ?? null) ? $payload['scripts']['installation'] : [];

    foreach ([
        ['script', 'Installation script', ''],
        ['container', 'Installation script container', 'alpine:3.4'],
        ['entrypoint', 'Installation script entrypoint', 'ash'],
    ] as [$key, $label, $fallback]) {
        $result = fbgAdminNestsStringField($payload['scripts']['installation'], $key, $label, $fallback);
        if (empty($result['ok'])) {
            return $result;
        }
        $payload['scripts']['installation'][$key] = (string)$result['value'];
    }

    if (array_key_exists('privileged', $payload['scripts']['installation'])) {
        $privileged = fbgAdminNestsBoolField($payload['scripts']['installation']['privileged'], 'Installation script privileged flag');
        if (empty($privileged['ok'])) {
            return $privileged;
        }
        $payload['scripts']['installation']['privileged'] = (int)$privileged['value'];
    }

    if (array_key_exists('force_outgoing_ip', $payload)) {
        $forceOutgoingIp = fbgAdminNestsBoolField($payload['force_outgoing_ip'], 'Force outgoing IP flag');
        if (empty($forceOutgoingIp['ok'])) {
            return $forceOutgoingIp;
        }
        $payload['force_outgoing_ip'] = (int)$forceOutgoingIp['value'];
    }

    $variables = fbgAdminNestsValidateEggVariables($payload['variables'] ?? []);
    if (empty($variables['ok'])) {
        return $variables;
    }
    $payload['variables'] = $variables['value'];

    return ['ok' => true, 'payload' => $payload];
}

function fbgAdminNestsReadEggExportPayload(?array $file): array
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Choose a valid egg JSON file.'];
    }

    $json = file_get_contents((string)$file['tmp_name']);
    $payload = json_decode((string)$json, true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'message' => 'That egg file is not valid JSON.'];
    }

    return fbgAdminNestsValidateEggExportPayload($payload);
}

function fbgAdminNestsEggDataFromExport(array $payload, array $existingEgg = []): array
{
    $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
    $script = is_array($payload['scripts']['installation'] ?? null) ? $payload['scripts']['installation'] : [];
    $features = array_key_exists('features', $payload) ? $payload['features'] : null;
    $scriptPrivileged = array_key_exists('privileged', $script)
        ? (!empty($script['privileged']) ? 1 : 0)
        : (int)($existingEgg['script_is_privileged'] ?? 1);
    $forceOutgoingIp = array_key_exists('force_outgoing_ip', $payload)
        ? (!empty($payload['force_outgoing_ip']) ? 1 : 0)
        : (int)($existingEgg['force_outgoing_ip'] ?? 0);

    return [
        'author' => fbgAdminNestsScalarToString($payload['author'] ?? null) ?? 'support@frostbyt3gaming.com',
        'name' => fbgAdminNestsScalarToString($payload['name'] ?? null) ?? 'Imported Egg',
        'description' => fbgAdminNestsScalarToString($payload['description'] ?? null) ?? '',
        'features' => fbgAdminNestsJsonEncode($features),
        'docker_images' => fbgAdminNestsJsonEncode(is_array($payload['docker_images'] ?? null) ? $payload['docker_images'] : []),
        'file_denylist' => fbgAdminNestsJsonEncode(is_array($payload['file_denylist'] ?? null) ? $payload['file_denylist'] : []),
        'update_url' => fbgAdminNestsScalarToString($payload['meta']['update_url'] ?? null) ?? '',
        'config_files' => fbgAdminNestsNormalizeExportJsonValue($config['files'] ?? null, new stdClass()),
        'config_startup' => fbgAdminNestsNormalizeExportJsonValue($config['startup'] ?? null, new stdClass()),
        'config_logs' => fbgAdminNestsNormalizeExportJsonValue($config['logs'] ?? null, new stdClass()),
        'config_stop' => fbgAdminNestsScalarToString($config['stop'] ?? null) ?? '',
        'startup' => fbgAdminNestsScalarToString($payload['startup'] ?? null) ?? '',
        'script_container' => fbgAdminNestsScalarToString($script['container'] ?? null) ?? 'alpine:3.4',
        'script_entry' => fbgAdminNestsScalarToString($script['entrypoint'] ?? null) ?? 'ash',
        'script_is_privileged' => $scriptPrivileged,
        'script_install' => fbgAdminNestsScalarToString($script['script'] ?? null) ?? '',
        'force_outgoing_ip' => $forceOutgoingIp,
        'variables' => is_array($payload['variables'] ?? null) ? $payload['variables'] : [],
    ];
}

function fbgAdminNestsSnippet(?string $value, int $length = 100): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value) > $length ? (mb_substr($value, 0, $length - 1) . '...') : $value;
    }

    return strlen($value) > $length ? (substr($value, 0, $length - 1) . '...') : $value;
}

function fbgAdminNestsFormatDate(mixed $value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : '-';
}

function fbgAdminNestsBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-nests';
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

function fbgAdminNestsSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $nextDirection = ($currentSort === $targetSort && $currentDirection === 'asc') ? 'desc' : 'asc';

    return fbgAdminNestsBaseQuery([
        'sort' => $targetSort,
        'dir' => $nextDirection,
        'page_num' => 1,
        'nest' => null,
        'egg' => null,
        'create' => null,
        'tab' => null,
        'new_egg' => null,
    ]);
}

function fbgAdminNestsEggSortUrl(int $nestId, string $targetSort, string $currentSort, string $currentDirection): string
{
    $nextDirection = ($currentSort === $targetSort && $currentDirection === 'asc') ? 'desc' : 'asc';

    return fbgAdminNestsBaseQuery([
        'nest' => $nestId,
        'egg_sort' => $targetSort,
        'egg_dir' => $nextDirection,
        'egg' => null,
        'tab' => null,
        'new_egg' => null,
    ]);
}

function fbgAdminNestsSortLabel(string $label, string $targetSort, string $currentSort, string $currentDirection): string
{
    if ($currentSort !== $targetSort) {
        return $label;
    }

    return $label . ($currentDirection === 'asc' ? ' ↑' : ' ↓');
}

function fbgAdminNestsTabUrl(int $nestId, string $tab, ?int $eggId = null): string
{
    return './page.php?' . http_build_query([
        'name' => 'admin-nests',
        'nest' => $nestId,
        'egg' => $eggId,
        'tab' => $tab,
    ]);
}

function fbgAdminNestsFetchEgg(int $eggId): ?array
{
    $stmt = fbgAdminNestsPdo()->prepare('
        SELECT e.*, n.name AS nest_name
        FROM eggs e
        INNER JOIN nests n ON n.id = e.nest_id
        WHERE e.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $eggId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fbgAdminNestsExportEgg(int $eggId): void
{
    $egg = fbgAdminNestsFetchEgg($eggId);
    if (!$egg) {
        http_response_code(404);
        echo 'Egg not found.';
        return;
    }

    $variablesStmt = fbgAdminNestsPdo()->prepare('
        SELECT name, description, env_variable, default_value, user_viewable, user_editable, rules
        FROM egg_variables
        WHERE egg_id = :egg_id
        ORDER BY id ASC
    ');
    $variablesStmt->execute(['egg_id' => $eggId]);

    $payload = [
        '_comment' => 'Generated by Frostbyt3 Gaming frontend admin tools.',
        'meta' => [
            'version' => 'PTDL_v2',
            'update_url' => $egg['update_url'] ?: null,
        ],
        'exported_at' => date(DATE_ATOM),
        'name' => $egg['name'],
        'author' => $egg['author'],
        'description' => $egg['description'],
        'features' => fbgAdminNestsJsonDecode($egg['features'] ?? null, null),
        'docker_images' => fbgAdminNestsJsonDecode($egg['docker_images'] ?? null, []),
        'file_denylist' => fbgAdminNestsJsonDecode($egg['file_denylist'] ?? null, []),
        'startup' => $egg['startup'],
        'config' => [
            'files' => fbgAdminNestsJsonDecode($egg['config_files'] ?? null, new stdClass(), false),
            'startup' => fbgAdminNestsJsonDecode($egg['config_startup'] ?? null, new stdClass(), false),
            'logs' => fbgAdminNestsJsonDecode($egg['config_logs'] ?? null, new stdClass(), false),
            'stop' => $egg['config_stop'],
        ],
        'scripts' => [
            'installation' => [
                'script' => $egg['script_install'],
                'container' => $egg['script_container'],
                'entrypoint' => $egg['script_entry'],
            ],
        ],
        'variables' => $variablesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];

    foreach ($payload['variables'] as &$variable) {
        $variable['field_type'] = 'text';
    }
    unset($variable);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="egg-' . fbgAdminNestsSlug((string)$egg['name']) . '.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_egg'])) {
    fbgAdminNestsExportEgg(max(0, (int)$_GET['export_egg']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminNestsVerifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    $pdo = fbgAdminNestsPdo();

    if ($action === 'create_nest' || $action === 'update_nest') {
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $name = trim((string)($_POST['nest_name'] ?? ''));
        $description = trim((string)($_POST['nest_description'] ?? ''));
        $author = trim((string)($_POST['nest_author'] ?? 'support@frostbyt3gaming.com'));

        if ($name === '') {
            fbgAdminNestsRedirect('Nest name is required.', 'error', $nestId > 0 ? ['nest' => $nestId] : ['create' => 1]);
        }

        if ($action === 'create_nest') {
            $stmt = $pdo->prepare('
                INSERT INTO nests (uuid, author, name, description, created_at, updated_at)
                VALUES (:uuid, :author, :name, :description, NOW(), NOW())
            ');
            $stmt->execute([
                'uuid' => fbgAdminNestsUuid(),
                'author' => $author,
                'name' => $name,
                'description' => $description,
            ]);
            fbgAdminNestsRedirect('Nest created successfully.', 'success', ['nest' => (int)$pdo->lastInsertId()]);
        }

        $stmt = $pdo->prepare('
            UPDATE nests
            SET author = :author, name = :name, description = :description, updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $nestId,
            'author' => $author,
            'name' => $name,
            'description' => $description,
        ]);
        fbgAdminNestsRedirect('Nest updated successfully.', 'success', ['nest' => $nestId]);
    }

    if ($action === 'delete_nest') {
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        if ((string)($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            fbgAdminNestsRedirect('Type DELETE to confirm nest deletion.', 'error', ['nest' => $nestId]);
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM eggs WHERE nest_id = :id');
        $stmt->execute(['id' => $nestId]);
        if ((int)$stmt->fetchColumn() > 0) {
            fbgAdminNestsRedirect('Nest cannot be deleted while eggs are assigned to it.', 'error', ['nest' => $nestId]);
        }

        $stmt = $pdo->prepare('DELETE FROM nests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $nestId]);
        fbgAdminNestsRedirect('Nest deleted successfully.');
    }

    if ($action === 'import_egg') {
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $result = fbgAdminNestsReadEggExportPayload($_FILES['egg_file'] ?? null);
        if (empty($result['ok'])) {
            fbgAdminNestsRedirect((string)$result['message'], 'error', ['nest' => $nestId]);
        }

        $eggData = fbgAdminNestsEggDataFromExport($result['payload']);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO eggs (
                    uuid, nest_id, author, name, description, features, docker_images, file_denylist, update_url,
                    config_files, config_startup, config_logs, config_stop, config_from, startup,
                    script_container, copy_script_from, script_entry, script_is_privileged, script_install,
                    created_at, updated_at, force_outgoing_ip
                ) VALUES (
                    :uuid, :nest_id, :author, :name, :description, :features, :docker_images, :file_denylist, :update_url,
                    :config_files, :config_startup, :config_logs, :config_stop, NULL, :startup,
                    :script_container, NULL, :script_entry, :script_is_privileged, :script_install,
                    NOW(), NOW(), :force_outgoing_ip
                )
            ');
            $stmt->execute([
                'uuid' => fbgAdminNestsUuid(),
                'nest_id' => $nestId,
                'author' => $eggData['author'],
                'name' => $eggData['name'],
                'description' => $eggData['description'],
                'features' => $eggData['features'],
                'docker_images' => $eggData['docker_images'],
                'file_denylist' => $eggData['file_denylist'],
                'update_url' => $eggData['update_url'],
                'config_files' => $eggData['config_files'],
                'config_startup' => $eggData['config_startup'],
                'config_logs' => $eggData['config_logs'],
                'config_stop' => $eggData['config_stop'],
                'startup' => $eggData['startup'],
                'script_container' => $eggData['script_container'],
                'script_entry' => $eggData['script_entry'],
                'script_is_privileged' => $eggData['script_is_privileged'],
                'script_install' => $eggData['script_install'],
                'force_outgoing_ip' => $eggData['force_outgoing_ip'],
            ]);

            $eggId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('
                INSERT INTO egg_variables (egg_id, name, description, env_variable, default_value, user_viewable, user_editable, rules, created_at, updated_at)
                VALUES (:egg_id, :name, :description, :env_variable, :default_value, :user_viewable, :user_editable, :rules, NOW(), NOW())
            ');
            foreach ($eggData['variables'] as $variable) {
                $stmt->execute([
                    'egg_id' => $eggId,
                    'name' => $variable['name'],
                    'description' => $variable['description'],
                    'env_variable' => $variable['env_variable'],
                    'default_value' => $variable['default_value'],
                    'user_viewable' => $variable['user_viewable'],
                    'user_editable' => $variable['user_editable'],
                    'rules' => $variable['rules'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            fbgAdminNestsRedirect('Egg import failed. Check the JSON file and try again.', 'error', ['nest' => $nestId]);
        }

        fbgAdminNestsRedirect('Egg imported successfully.', 'success', ['nest' => $nestId, 'egg' => $eggId]);
    }

    if ($action === 'update_egg_file') {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $existingEgg = fbgAdminNestsFetchEgg($eggId);

        if (!$existingEgg || (int)$existingEgg['nest_id'] !== $nestId) {
            fbgAdminNestsRedirect('Egg not found for this nest.', 'error', ['nest' => $nestId]);
        }

        $result = fbgAdminNestsReadEggExportPayload($_FILES['egg_file'] ?? null);
        if (empty($result['ok'])) {
            fbgAdminNestsRedirect((string)$result['message'], 'error', ['nest' => $nestId, 'egg' => $eggId]);
        }

        $eggData = fbgAdminNestsEggDataFromExport($result['payload'], $existingEgg);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE eggs
                SET author = :author,
                    name = :name,
                    description = :description,
                    features = :features,
                    docker_images = :docker_images,
                    file_denylist = :file_denylist,
                    update_url = :update_url,
                    config_files = :config_files,
                    config_startup = :config_startup,
                    config_logs = :config_logs,
                    config_stop = :config_stop,
                    config_from = NULL,
                    startup = :startup,
                    copy_script_from = NULL,
                    script_container = :script_container,
                    script_entry = :script_entry,
                    script_is_privileged = :script_is_privileged,
                    script_install = :script_install,
                    force_outgoing_ip = :force_outgoing_ip,
                    updated_at = NOW()
                WHERE id = :id AND nest_id = :nest_id
                LIMIT 1
            ');
            $stmt->execute([
                'id' => $eggId,
                'nest_id' => $nestId,
                'author' => $eggData['author'],
                'name' => $eggData['name'],
                'description' => $eggData['description'],
                'features' => $eggData['features'],
                'docker_images' => $eggData['docker_images'],
                'file_denylist' => $eggData['file_denylist'],
                'update_url' => $eggData['update_url'],
                'config_files' => $eggData['config_files'],
                'config_startup' => $eggData['config_startup'],
                'config_logs' => $eggData['config_logs'],
                'config_stop' => $eggData['config_stop'],
                'startup' => $eggData['startup'],
                'script_container' => $eggData['script_container'],
                'script_entry' => $eggData['script_entry'],
                'script_is_privileged' => $eggData['script_is_privileged'],
                'script_install' => $eggData['script_install'],
                'force_outgoing_ip' => $eggData['force_outgoing_ip'],
            ]);

            $stmt = $pdo->prepare('DELETE FROM egg_variables WHERE egg_id = :egg_id');
            $stmt->execute(['egg_id' => $eggId]);

            $stmt = $pdo->prepare('
                INSERT INTO egg_variables (egg_id, name, description, env_variable, default_value, user_viewable, user_editable, rules, created_at, updated_at)
                VALUES (:egg_id, :name, :description, :env_variable, :default_value, :user_viewable, :user_editable, :rules, NOW(), NOW())
            ');
            foreach ($eggData['variables'] as $variable) {
                $envVariable = strtoupper(trim($variable['env_variable']));
                if ($envVariable === '') {
                    throw new RuntimeException('Egg variable is missing its environment variable.');
                }

                $stmt->execute([
                    'egg_id' => $eggId,
                    'name' => $variable['name'],
                    'description' => $variable['description'],
                    'env_variable' => $envVariable,
                    'default_value' => $variable['default_value'],
                    'user_viewable' => $variable['user_viewable'],
                    'user_editable' => $variable['user_editable'],
                    'rules' => $variable['rules'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            fbgAdminNestsRedirect('Egg update failed. The current egg was left unchanged.', 'error', ['nest' => $nestId, 'egg' => $eggId]);
        }

        fbgAdminNestsRedirect('Egg updated from JSON successfully.', 'success', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'configuration']);
    }

    if (in_array($action, ['create_egg', 'update_egg'], true)) {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $name = trim((string)($_POST['egg_name'] ?? ''));
        $author = trim((string)($_POST['egg_author'] ?? 'support@frostbyt3gaming.com'));
        $description = trim((string)($_POST['egg_description'] ?? ''));
        $dockerImages = fbgAdminNestsDockerImagesFromTextarea((string)($_POST['docker_images'] ?? ''));
        $fileDenylist = fbgAdminNestsNormalizeJsonField((string)($_POST['file_denylist'] ?? ''), [], 'array');
        $features = fbgAdminNestsNormalizeJsonField((string)($_POST['features'] ?? ''), [], 'array');
        $configFiles = fbgAdminNestsNormalizeJsonField((string)($_POST['config_files'] ?? ''), new stdClass(), 'object');
        $configStartup = fbgAdminNestsNormalizeJsonField((string)($_POST['config_startup'] ?? ''), new stdClass(), 'object');
        $configLogs = fbgAdminNestsNormalizeJsonField((string)($_POST['config_logs'] ?? ''), new stdClass(), 'object');

        foreach (['file denylist' => $fileDenylist, 'features' => $features, 'configuration files' => $configFiles, 'startup configuration' => $configStartup, 'log configuration' => $configLogs] as $label => $result) {
            if (!$result['ok']) {
                fbgAdminNestsRedirect('Invalid JSON in ' . $label . ': ' . $result['error'], 'error', ['nest' => $nestId, 'egg' => $eggId ?: null]);
            }
        }

        if ($name === '') {
            fbgAdminNestsRedirect('Egg name is required.', 'error', ['nest' => $nestId, 'egg' => $eggId ?: null]);
        }

        $params = [
            'nest_id' => $nestId,
            'author' => $author,
            'name' => $name,
            'description' => $description,
            'features' => $features['value'],
            'docker_images' => $dockerImages,
            'file_denylist' => $fileDenylist['value'],
            'update_url' => trim((string)($_POST['update_url'] ?? '')),
            'config_files' => $configFiles['value'],
            'config_startup' => $configStartup['value'],
            'config_logs' => $configLogs['value'],
            'config_stop' => trim((string)($_POST['config_stop'] ?? '')),
            'config_from' => ((int)($_POST['config_from'] ?? 0)) ?: null,
            'startup' => trim((string)($_POST['startup'] ?? '')),
            'force_outgoing_ip' => isset($_POST['force_outgoing_ip']) ? 1 : 0,
        ];

        if ($action === 'create_egg') {
            $stmt = $pdo->prepare('
                INSERT INTO eggs (
                    uuid, nest_id, author, name, description, features, docker_images, file_denylist, update_url,
                    config_files, config_startup, config_logs, config_stop, config_from, startup,
                    script_container, copy_script_from, script_entry, script_is_privileged, script_install,
                    created_at, updated_at, force_outgoing_ip
                ) VALUES (
                    :uuid, :nest_id, :author, :name, :description, :features, :docker_images, :file_denylist, :update_url,
                    :config_files, :config_startup, :config_logs, :config_stop, :config_from, :startup,
                    "alpine:3.4", NULL, "ash", 1, "", NOW(), NOW(), :force_outgoing_ip
                )
            ');
            $params['uuid'] = fbgAdminNestsUuid();
            $stmt->execute($params);
            fbgAdminNestsRedirect('Egg created successfully.', 'success', ['nest' => $nestId, 'egg' => (int)$pdo->lastInsertId()]);
        }

        $params['id'] = $eggId;
        $stmt = $pdo->prepare('
            UPDATE eggs
            SET nest_id = :nest_id, author = :author, name = :name, description = :description,
                features = :features, docker_images = :docker_images, file_denylist = :file_denylist,
                update_url = :update_url, config_files = :config_files, config_startup = :config_startup,
                config_logs = :config_logs, config_stop = :config_stop, config_from = :config_from,
                startup = :startup, force_outgoing_ip = :force_outgoing_ip, updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute($params);
        fbgAdminNestsRedirect('Egg configuration saved.', 'success', ['nest' => $nestId, 'egg' => $eggId]);
    }

    if ($action === 'update_install_script') {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $stmt = $pdo->prepare('
            UPDATE eggs
            SET copy_script_from = :copy_script_from,
                script_container = :script_container,
                script_entry = :script_entry,
                script_is_privileged = :script_is_privileged,
                script_install = :script_install,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $eggId,
            'copy_script_from' => ((int)($_POST['copy_script_from'] ?? 0)) ?: null,
            'script_container' => trim((string)($_POST['script_container'] ?? 'alpine:3.4')),
            'script_entry' => trim((string)($_POST['script_entry'] ?? 'ash')),
            'script_is_privileged' => isset($_POST['script_is_privileged']) ? 1 : 0,
            'script_install' => (string)($_POST['script_install'] ?? ''),
        ]);
        fbgAdminNestsRedirect('Install script saved.', 'success', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'install']);
    }

    if (in_array($action, ['create_variable', 'update_variable'], true)) {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $variableId = max(0, (int)($_POST['variable_id'] ?? 0));
        $params = [
            'egg_id' => $eggId,
            'name' => trim((string)($_POST['variable_name'] ?? '')),
            'description' => trim((string)($_POST['variable_description'] ?? '')),
            'env_variable' => strtoupper(trim((string)($_POST['env_variable'] ?? ''))),
            'default_value' => (string)($_POST['default_value'] ?? ''),
            'user_viewable' => isset($_POST['user_viewable']) ? 1 : 0,
            'user_editable' => isset($_POST['user_editable']) ? 1 : 0,
            'rules' => trim((string)($_POST['rules'] ?? '')),
        ];

        if ($params['name'] === '' || $params['env_variable'] === '') {
            fbgAdminNestsRedirect('Variable name and environment variable are required.', 'error', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'variables']);
        }

        if ($action === 'create_variable') {
            $stmt = $pdo->prepare('
                INSERT INTO egg_variables (egg_id, name, description, env_variable, default_value, user_viewable, user_editable, rules, created_at, updated_at)
                VALUES (:egg_id, :name, :description, :env_variable, :default_value, :user_viewable, :user_editable, :rules, NOW(), NOW())
            ');
            $stmt->execute($params);
            fbgAdminNestsRedirect('Variable created.', 'success', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'variables']);
        }

        $params['id'] = $variableId;
        $stmt = $pdo->prepare('
            UPDATE egg_variables
            SET name = :name, description = :description, env_variable = :env_variable, default_value = :default_value,
                user_viewable = :user_viewable, user_editable = :user_editable, rules = :rules, updated_at = NOW()
            WHERE id = :id AND egg_id = :egg_id
            LIMIT 1
        ');
        $stmt->execute($params);
        fbgAdminNestsRedirect('Variable saved.', 'success', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'variables']);
    }

    if ($action === 'delete_variable') {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $variableId = max(0, (int)($_POST['variable_id'] ?? 0));
        $stmt = $pdo->prepare('DELETE FROM egg_variables WHERE id = :id AND egg_id = :egg_id LIMIT 1');
        $stmt->execute(['id' => $variableId, 'egg_id' => $eggId]);
        fbgAdminNestsRedirect('Variable deleted.', 'warning', ['nest' => $nestId, 'egg' => $eggId, 'tab' => 'variables']);
    }

    if ($action === 'delete_egg') {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        if ((string)($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            fbgAdminNestsRedirect('Type DELETE to confirm egg deletion.', 'error', ['nest' => $nestId, 'egg' => $eggId]);
        }

        $stmt = $pdo->prepare('
            SELECT
                (SELECT COUNT(*) FROM servers WHERE egg_id = :server_egg_id) AS server_count,
                (SELECT COUNT(*) FROM games WHERE egg_id = :game_egg_id) AS game_count
        ');
        $stmt->execute(['server_egg_id' => $eggId, 'game_egg_id' => $eggId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($counts['server_count'] ?? 0) > 0 || (int)($counts['game_count'] ?? 0) > 0) {
            fbgAdminNestsRedirect('Egg cannot be deleted while servers or shop plans still reference it.', 'error', ['nest' => $nestId, 'egg' => $eggId]);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM egg_variables WHERE egg_id = :egg_id');
            $stmt->execute(['egg_id' => $eggId]);
            $stmt = $pdo->prepare('DELETE FROM eggs WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $eggId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            fbgAdminNestsRedirect('Egg could not be deleted.', 'error', ['nest' => $nestId, 'egg' => $eggId]);
        }
        fbgAdminNestsRedirect('Egg deleted.', 'warning', ['nest' => $nestId]);
    }
}

$message = (string)($_SESSION['admin_nests_message'] ?? '');
$messageType = (string)($_SESSION['admin_nests_message_type'] ?? 'success');
unset($_SESSION['admin_nests_message'], $_SESSION['admin_nests_message_type']);

$pdo = fbgAdminNestsPdo();
$search = trim((string)($_GET['q'] ?? ''));
$pageNum = fbgPaginationRequestedPage();
$perPage = 25;
$openCreate = isset($_GET['create']) && (string)$_GET['create'] === '1';
$nestId = max(0, (int)($_GET['nest'] ?? 0));
$eggId = max(0, (int)($_GET['egg'] ?? 0));
$sort = (string)($_GET['sort'] ?? 'id');
$sortDirection = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$sortMap = [
    'id' => 'n.id',
    'name' => 'n.name',
    'description' => 'n.description',
];
if (!array_key_exists($sort, $sortMap)) {
    $sort = 'id';
}
$sortSql = $sortMap[$sort] . ' ' . strtoupper($sortDirection) . ', n.id ASC';
$eggSort = (string)($_GET['egg_sort'] ?? 'id');
$eggSortDirection = strtolower((string)($_GET['egg_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$eggSortMap = [
    'id' => 'e.id',
    'name' => 'e.name',
    'description' => 'e.description',
];
if (!array_key_exists($eggSort, $eggSortMap)) {
    $eggSort = 'id';
}
$eggSortSql = $eggSortMap[$eggSort] . ' ' . strtoupper($eggSortDirection) . ', e.id ASC';
$activeEggTab = (string)($_GET['tab'] ?? 'configuration');
if (!in_array($activeEggTab, ['configuration', 'variables', 'install'], true)) {
    $activeEggTab = 'configuration';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(n.name LIKE :search_name OR n.description LIKE :search_description OR n.author LIKE :search_author OR CAST(n.id AS CHAR) = :search_exact)';
    $params = [
        'search_name' => '%' . $search . '%',
        'search_description' => '%' . $search . '%',
        'search_author' => '%' . $search . '%',
        'search_exact' => $search,
    ];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM nests n {$whereSql}");
$countStmt->execute($params);
$pagination = fbgNormalizePagination((int)$countStmt->fetchColumn(), $pageNum, $perPage);

$nestsStmt = $pdo->prepare("
    SELECT
        n.*,
        COUNT(DISTINCT e.id) AS egg_count,
        COUNT(DISTINCT s.id) AS server_count
    FROM nests n
    LEFT JOIN eggs e ON e.nest_id = n.id
    LEFT JOIN servers s ON s.nest_id = n.id
    {$whereSql}
    GROUP BY n.id
    ORDER BY {$sortSql}
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$nestsStmt->execute($params);
$nests = $nestsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allNests = $pdo->query('SELECT id, name FROM nests ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$allEggs = $pdo->query('SELECT id, nest_id, name FROM eggs ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editingNest = null;
$nestEggs = [];
if ($nestId > 0) {
    $stmt = $pdo->prepare('
        SELECT
            n.*,
            COUNT(DISTINCT e.id) AS egg_count,
            COUNT(DISTINCT s.id) AS server_count
        FROM nests n
        LEFT JOIN eggs e ON e.nest_id = n.id
        LEFT JOIN servers s ON s.nest_id = n.id
        WHERE n.id = :id
        GROUP BY n.id
        LIMIT 1
    ');
    $stmt->execute(['id' => $nestId]);
    $editingNest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingNest) {
        $stmt = $pdo->prepare('
            SELECT
                e.*,
                COUNT(DISTINCT s.id) AS server_count,
                COUNT(DISTINCT g.id) AS game_count
            FROM eggs e
            LEFT JOIN servers s ON s.egg_id = e.id
            LEFT JOIN games g ON g.egg_id = e.id
            WHERE e.nest_id = :nest_id
            GROUP BY e.id
            ORDER BY ' . $eggSortSql . '
        ');
        $stmt->execute(['nest_id' => $nestId]);
        $nestEggs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$editingEgg = null;
$eggVariables = [];
$eggServerCount = 0;
$eggGameCount = 0;
if ($eggId > 0) {
    $editingEgg = fbgAdminNestsFetchEgg($eggId);
    if ($editingEgg) {
        $nestId = (int)$editingEgg['nest_id'];
        if (!$editingNest) {
            $stmt = $pdo->prepare('SELECT * FROM nests WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $nestId]);
            $editingNest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM servers WHERE egg_id = :egg_id');
        $stmt->execute(['egg_id' => $eggId]);
        $eggServerCount = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE egg_id = :egg_id');
        $stmt->execute(['egg_id' => $eggId]);
        $eggGameCount = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM egg_variables WHERE egg_id = :egg_id ORDER BY name ASC, id ASC');
        $stmt->execute(['egg_id' => $eggId]);
        $eggVariables = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$blankNest = ['id' => 0, 'uuid' => '', 'author' => 'support@frostbyt3gaming.com', 'name' => '', 'description' => '', 'egg_count' => 0, 'server_count' => 0];
$modalNest = $editingNest ?: $blankNest;
$blankEgg = [
    'id' => 0,
    'uuid' => '',
    'nest_id' => $nestId,
    'author' => 'support@frostbyt3gaming.com',
    'name' => '',
    'description' => '',
    'features' => '[]',
    'docker_images' => '{}',
    'file_denylist' => '[]',
    'update_url' => '',
    'config_files' => '{}',
    'config_startup' => '{}',
    'config_logs' => '{}',
    'config_stop' => 'stop',
    'config_from' => null,
    'startup' => '',
    'script_container' => 'alpine:3.4',
    'copy_script_from' => null,
    'script_entry' => 'ash',
    'script_is_privileged' => 1,
    'script_install' => '',
    'force_outgoing_ip' => 0,
];
$modalEgg = $editingEgg ?: $blankEgg;
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Nests & Eggs</h1>
                <p class="fbg-admin-subtext">Manage Pterodactyl nests, eggs, variables, and install scripts.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Nests & Eggs',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <?php if (!$editingNest): ?>
        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header fbg-admin-node-list-header">
                    <h2>Configured Nests</h2>
                    <div class="fbg-admin-table-actions">
                        <a class="btn btn-sm" href="<?= htmlspecialchars(fbgAdminNestsBaseQuery(['create' => 1, 'nest' => null, 'egg' => null]), ENT_QUOTES, 'UTF-8') ?>">Create Nest</a>
                    </div>
                </div>

                <div class="fbg-admin-warning-box fbg-admin-nests-danger-note">
                    Eggs control how servers are installed and started. Editing the wrong values can break new server builds, so export a backup before making large changes.
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-nests">
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="nest-search">Search</label>
                            <input id="nest-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, name, description, or author">
                        </div>
                    </div>
                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn btn-sm">Search</button>
                        <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests">Reset</a>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsSortUrl('id', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('ID', 'id', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsSortUrl('name', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('Name', 'name', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsSortUrl('description', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('Description', 'description', $sort, $sortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                <th>Author</th>
                                <th>Eggs</th>
                                <th>Servers</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nests)): ?>
                                <tr><td colspan="6">No nests found.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($nests as $nest): ?>
                                <tr>
                                    <td><?= (int)$nest['id'] ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminNestsBaseQuery(['nest' => (int)$nest['id'], 'egg' => null, 'create' => null]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$nest['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars((string)($nest['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars((string)$nest['author'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= (int)$nest['egg_count'] ?></td>
                                    <td><?= (int)$nest['server_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'nest', ['remove' => ['nest', 'egg', 'create', 'tab']]); ?>
            </section>
        </div>
        <?php endif; ?>

        <?php if ($editingNest): ?>
            <?php
            $nestDeleteDisabled = (int)($editingNest['egg_count'] ?? 0) > 0 || (int)($editingNest['server_count'] ?? 0) > 0;
            ?>
            <div class="fbg-admin-grid">
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header fbg-admin-node-list-header">
                        <div>
                            <h2>Edit <?= htmlspecialchars((string)$editingNest['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="fbg-admin-help-text">Update the nest and manage its eggs.</p>
                        </div>
                        <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests">Back to Nests</a>
                    </div>

                    <form method="POST" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_nest">
                        <input type="hidden" name="nest_id" value="<?= (int)$editingNest['id'] ?>">

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="nest-page-name">Name</label>
                                <input id="nest-page-name" name="nest_name" type="text" required value="<?= htmlspecialchars((string)$editingNest['name'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="fbg-admin-field">
                                <label for="nest-page-author">Author</label>
                                <input id="nest-page-author" name="nest_author" type="email" required value="<?= htmlspecialchars((string)$editingNest['author'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="nest-page-description">Description</label>
                                <textarea id="nest-page-description" name="nest_description" rows="4"><?= htmlspecialchars((string)($editingNest['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </div>

                        <div class="fbg-admin-node-summary-grid">
                            <div class="fbg-admin-node-summary-card">
                                <span>Nest ID</span>
                                <strong><?= (int)$editingNest['id'] ?></strong>
                            </div>
                            <div class="fbg-admin-node-summary-card">
                                <span>UUID</span>
                                <strong><?= htmlspecialchars((string)$editingNest['uuid'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="fbg-admin-node-summary-card">
                                <span>Eggs</span>
                                <strong><?= (int)($editingNest['egg_count'] ?? 0) ?></strong>
                            </div>
                            <div class="fbg-admin-node-summary-card">
                                <span>Servers</span>
                                <strong><?= (int)($editingNest['server_count'] ?? 0) ?></strong>
                            </div>
                        </div>

                        <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                            <button type="submit" class="btn btn-sm">Save Nest</button>
                        </div>
                    </form>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header fbg-admin-node-list-header">
                        <h2>Nest Eggs</h2>
                        <button type="button" class="btn btn-sm" id="admin-egg-create-open">New Egg</button>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="fbg-admin-form fbg-admin-nest-import-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="import_egg">
                        <input type="hidden" name="nest_id" value="<?= (int)$editingNest['id'] ?>">
                        <div class="fbg-admin-field">
                            <label for="egg-page-import-file">Import Egg JSON</label>
                            <input id="egg-page-import-file" name="egg_file" type="file" accept="application/json,.json">
                        </div>
                        <button type="submit" class="btn btn-sm">Import Egg</button>
                    </form>

                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsEggSortUrl((int)$editingNest['id'], 'id', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('ID', 'id', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                    <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsEggSortUrl((int)$editingNest['id'], 'name', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('Name', 'name', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                    <th><a class="fbg-admin-sort-link" href="<?= htmlspecialchars(fbgAdminNestsEggSortUrl((int)$editingNest['id'], 'description', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(fbgAdminNestsSortLabel('Description', 'description', $eggSort, $eggSortDirection), ENT_QUOTES, 'UTF-8') ?></a></th>
                                    <th>Docker Image</th>
                                    <th>Startup Command</th>
                                    <th>Author</th>
                                    <th>Servers</th>
                                    <th>Export</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($nestEggs)): ?>
                                    <tr><td colspan="8">No eggs are assigned to this nest.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($nestEggs as $egg): ?>
                                    <tr>
                                        <td><?= (int)$egg['id'] ?></td>
                                        <td>
                                            <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminNestsTabUrl((int)$editingNest['id'], 'configuration', (int)$egg['id']), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)$egg['name'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars((string)($egg['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><code><?= htmlspecialchars(fbgAdminNestsFirstDockerImage($egg['docker_images'] ?? null), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code><?= htmlspecialchars(fbgAdminNestsSnippet($egg['startup'] ?? null), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code><?= htmlspecialchars((string)$egg['author'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><?= (int)$egg['server_count'] ?></td>
                                        <td>
                                            <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests&export_egg=<?= (int)$egg['id'] ?>">
                                                <i class="fas fa-download"></i> Export
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="fbg-admin-node-danger-panel fbg-admin-nest-delete-panel">
                    <div>
                        <h2>Delete Nest</h2>
                        <p>Deleting a nest is permanent. It must not contain eggs or servers before it can be deleted.</p>
                        <?php if ($nestDeleteDisabled): ?>
                            <p class="fbg-admin-help-text">Remove <?= (int)($editingNest['egg_count'] ?? 0) ?> egg reference<?= (int)($editingNest['egg_count'] ?? 0) === 1 ? '' : 's' ?> and <?= (int)($editingNest['server_count'] ?? 0) ?> server reference<?= (int)($editingNest['server_count'] ?? 0) === 1 ? '' : 's' ?> first.</p>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-delete" id="admin-nest-delete-open" <?= $nestDeleteDisabled ? 'disabled' : '' ?>>Delete Nest</button>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($openCreate): ?>
    <?php $isEditingNest = false; ?>
    <div class="fbg-modal-overlay" id="admin-nest-modal">
        <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-node-modal fbg-admin-nest-modal" role="dialog" aria-modal="true" aria-labelledby="admin-nest-modal-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-nests" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-nest-modal-title"><?= $isEditingNest ? 'Edit ' . htmlspecialchars((string)$modalNest['name'], ENT_QUOTES, 'UTF-8') : 'Create Nest' ?></h3>
                <p><?= $isEditingNest ? 'Update the nest and manage its eggs.' : 'Create a new group for related server eggs.' ?></p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= $isEditingNest ? 'update_nest' : 'create_nest' ?>">
                <input type="hidden" name="nest_id" value="<?= (int)$modalNest['id'] ?>">

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="nest-name">Name</label>
                        <input id="nest-name" name="nest_name" type="text" required value="<?= htmlspecialchars((string)$modalNest['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="fbg-admin-field">
                        <label for="nest-author">Author</label>
                        <input id="nest-author" name="nest_author" type="email" required value="<?= htmlspecialchars((string)$modalNest['author'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="nest-description">Description</label>
                        <textarea id="nest-description" name="nest_description" rows="4"><?= htmlspecialchars((string)($modalNest['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <div class="fbg-admin-node-summary-grid">
                    <div class="fbg-admin-node-summary-card">
                        <span>Nest ID</span>
                        <strong><?= (int)$modalNest['id'] ?: 'New' ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>UUID</span>
                        <strong><?= htmlspecialchars((string)($modalNest['uuid'] ?: 'Generated on create'), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>Eggs</span>
                        <strong><?= (int)($modalNest['egg_count'] ?? 0) ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>Servers</span>
                        <strong><?= (int)($modalNest['server_count'] ?? 0) ?></strong>
                    </div>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn btn-sm"><?= $isEditingNest ? 'Save Nest' : 'Create Nest' ?></button>
                    <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests">Cancel</a>
                </div>
            </form>

            <?php if ($isEditingNest): ?>
                <hr>
                <div class="fbg-admin-panel-header fbg-admin-node-list-header">
                    <h2>Nest Eggs</h2>
                    <button type="button" class="btn btn-sm" id="admin-egg-create-open">New Egg</button>
                </div>

                <form method="POST" enctype="multipart/form-data" class="fbg-admin-form fbg-admin-nest-import-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="import_egg">
                    <input type="hidden" name="nest_id" value="<?= (int)$modalNest['id'] ?>">
                    <div class="fbg-admin-field">
                        <label for="egg-import-file">Import Egg JSON</label>
                        <input id="egg-import-file" name="egg_file" type="file" accept="application/json,.json">
                    </div>
                    <button type="submit" class="btn btn-sm">Import Egg</button>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Docker Image</th>
                                <th>Startup Command</th>
                                <th>Author</th>
                                <th>Servers</th>
                                <th>Export</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nestEggs)): ?>
                                <tr><td colspan="8">No eggs are assigned to this nest.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($nestEggs as $egg): ?>
                                <tr>
                                    <td><?= (int)$egg['id'] ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminNestsTabUrl((int)$modalNest['id'], 'configuration', (int)$egg['id']), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$egg['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars((string)($egg['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars(fbgAdminNestsFirstDockerImage($egg['docker_images'] ?? null), ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= htmlspecialchars(fbgAdminNestsSnippet($egg['startup'] ?? null), ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= htmlspecialchars((string)$egg['author'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= (int)$egg['server_count'] ?></td>
                                    <td>
                                        <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests&export_egg=<?= (int)$egg['id'] ?>">
                                            <i class="fas fa-download"></i> Export
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $nestDeleteDisabled = (int)($modalNest['egg_count'] ?? 0) > 0; ?>
                <section class="fbg-admin-node-danger-panel">
                    <div>
                        <h2>Delete Nest</h2>
                        <p>Deleting a nest is permanent. All eggs must be removed before the nest can be deleted.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-delete" id="admin-nest-delete-open" <?= $nestDeleteDisabled ? 'disabled' : '' ?>>Delete Nest</button>
                </section>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($editingEgg || ($editingNest && isset($_GET['new_egg']))): ?>
    <?php $isEditingEgg = (bool)$editingEgg; ?>
    <div class="fbg-modal-overlay" id="admin-egg-modal">
        <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-node-modal fbg-admin-nest-modal" role="dialog" aria-modal="true" aria-labelledby="admin-egg-modal-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="<?= htmlspecialchars(fbgAdminNestsBaseQuery(['nest' => $nestId, 'egg' => null, 'tab' => null]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-egg-modal-title"><?= $isEditingEgg ? htmlspecialchars((string)$modalEgg['name'], ENT_QUOTES, 'UTF-8') : 'Create Egg' ?></h3>
                <p><?= $isEditingEgg ? 'Manage egg configuration, variables, and install script.' : 'Add a new egg to this nest.' ?></p>
            </div>

            <?php if ($isEditingEgg): ?>
                <nav class="fbg-admin-node-tabs" aria-label="Egg sections">
                    <a class="fbg-admin-node-tab <?= $activeEggTab === 'configuration' ? 'is-active' : '' ?>" href="<?= htmlspecialchars(fbgAdminNestsTabUrl($nestId, 'configuration', (int)$modalEgg['id']), ENT_QUOTES, 'UTF-8') ?>">Configuration</a>
                    <a class="fbg-admin-node-tab <?= $activeEggTab === 'variables' ? 'is-active' : '' ?>" href="<?= htmlspecialchars(fbgAdminNestsTabUrl($nestId, 'variables', (int)$modalEgg['id']), ENT_QUOTES, 'UTF-8') ?>">Variables</a>
                    <a class="fbg-admin-node-tab <?= $activeEggTab === 'install' ? 'is-active' : '' ?>" href="<?= htmlspecialchars(fbgAdminNestsTabUrl($nestId, 'install', (int)$modalEgg['id']), ENT_QUOTES, 'UTF-8') ?>">Install Script</a>
                </nav>
            <?php endif; ?>

            <?php if (!$isEditingEgg || $activeEggTab === 'configuration'): ?>
                <?php if ($isEditingEgg): ?>
                    <section class="fbg-admin-panel fbg-admin-egg-update-panel">
                        <div class="fbg-admin-egg-section-header">
                            <h3>Egg Update</h3>
                            <p>Upload a Pterodactyl egg JSON export to refresh this egg's configuration, variables, and install script.</p>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="fbg-admin-form fbg-admin-nest-import-form" id="admin-egg-update-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="update_egg_file">
                            <input type="hidden" name="nest_id" value="<?= $nestId ?>">
                            <input type="hidden" name="egg_id" value="<?= (int)$modalEgg['id'] ?>">
                            <div class="fbg-admin-field">
                                <label for="egg-update-file">Egg File</label>
                                <input id="egg-update-file" name="egg_file" type="file" accept="application/json,.json" required>
                                <p>Upload a supported PTDL egg export. The egg ID and current nest assignment will be preserved.</p>
                            </div>
                            <button type="submit" class="btn btn-sm btn-delete">Update Egg</button>
                        </form>
                    </section>
                <?php endif; ?>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $isEditingEgg ? 'update_egg' : 'create_egg' ?>">
                    <input type="hidden" name="egg_id" value="<?= (int)$modalEgg['id'] ?>">

                    <section class="fbg-admin-egg-section">
                        <div class="fbg-admin-egg-section-header">
                            <h3>Configuration</h3>
                            <p>Basic egg information, startup settings, and Docker images.</p>
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label>Egg ID</label>
                                <input type="text" value="<?= (int)$modalEgg['id'] ?: 'New' ?>" readonly>
                                <p>This is the numeric ID for the Egg.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label>UUID</label>
                                <input type="text" value="<?= htmlspecialchars((string)($modalEgg['uuid'] ?: 'Generated on create'), ENT_QUOTES, 'UTF-8') ?>" readonly>
                                <p>This is the globally unique identifier for this Egg which the Daemon uses as an identifier.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-nest-id">Nest</label>
                                <select id="egg-nest-id" name="nest_id" required>
                                    <?php foreach ($allNests as $nest): ?>
                                        <option value="<?= (int)$nest['id'] ?>" <?= (int)$modalEgg['nest_id'] === (int)$nest['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$nest['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p>The nest that the current Egg is assigned to.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-name">Name</label>
                                <input id="egg-name" name="egg_name" type="text" required value="<?= htmlspecialchars((string)$modalEgg['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <p>A simple, human-readable name to use as an identifier for this Egg.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-author">Author</label>
                                <input id="egg-author" name="egg_author" type="email" required value="<?= htmlspecialchars((string)$modalEgg['author'], ENT_QUOTES, 'UTF-8') ?>" readonly>
                                <p>The author of this version of the Egg. Uploading a new Egg configuration from a different author will change this.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-update-url">Update URL</label>
                                <input id="egg-update-url" name="update_url" type="url" value="<?= htmlspecialchars((string)($modalEgg['update_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <p>The URL that the author of the Egg has provided to update the Egg.</p>
                            </div>

                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="egg-description">Description</label>
                                <textarea id="egg-description" name="egg_description" rows="4"><?= htmlspecialchars((string)($modalEgg['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>A description of this Egg that will be displayed throughout the Panel as needed.</p>
                            </div>

                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="egg-startup">Startup Command</label>
                                <textarea id="egg-startup" name="startup" rows="4"><?= htmlspecialchars((string)($modalEgg['startup'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>The default startup command that should be used for new servers using this Egg.</p>
                            </div>

                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="egg-docker-images">Docker Images</label>
                                <textarea id="egg-docker-images" name="docker_images" rows="5"><?= htmlspecialchars(fbgAdminNestsDockerImagesTextarea($modalEgg['docker_images'] ?? '{}'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>Use one image per line. Optionally use Display Name | image/url.</p>
                            </div>

                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label class="fbg-admin-checkbox">
                                    <input type="checkbox" name="force_outgoing_ip" value="1" <?= (int)($modalEgg['force_outgoing_ip'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <span>Force outgoing IP</span>
                                </label>
                                <p>Forces all outgoing network traffic to have its Source IP NATed to the IP of the server's primary allocation IP. Required for certain games to work properly when the Node has multiple public IP addresses.</p>
                                <p><strong>Enabling this option will disable internal networking for any servers using this egg, causing them to be unable to internally access other servers on the same node.</strong></p>
                            </div>
                        </div>
                    </section>

                    <fbgadminhr></fbgadminhr>

                    <section class="fbg-admin-egg-section">
                        <div class="fbg-admin-egg-section-header">
                            <h3>Process Management</h3>
                            <p>Advanced process, configuration, and runtime behavior for this egg.</p>
                            <section class="fbg-admin-warning-panel">
                                <div>
                                    <strong>Caution</strong>
                                    <p>The following configuration options should not be edited unless you understand how this system works. If wrongly modified it is possible for the daemon to break.</p>
                                    <p>All fields are required unless you select a separate option from the 'Copy Settings From' dropdown, in which case fields may be left blank to use the values from that Egg.</p>
                                </div>
                            </section>
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="egg-config-stop">Stop Command</label>
                                <input id="egg-config-stop" name="config_stop" type="text" value="<?= htmlspecialchars((string)($modalEgg['config_stop'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <p>The command that should be sent to server processes to stop them gracefully. If you need to send a <span class="fbg-admin-code-box-thing">SIGINT</span> you should enter <span class="fbg-admin-code-box-thing">^C</span> here.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-config-from">Copy Settings From</label>
                                <select id="egg-config-from" name="config_from">
                                    <option value="0">None</option>
                                    <?php foreach ($allEggs as $eggOption): ?>
                                        <option value="<?= (int)$eggOption['id'] ?>" <?= (int)($modalEgg['config_from'] ?? 0) === (int)$eggOption['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$eggOption['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p>If you would like to default to settings from another Egg select it from the menu above.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-features">Features JSON</label>
                                <textarea id="egg-features" name="features" rows="6"><?= htmlspecialchars((string)($modalEgg['features'] ?? '[]'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>A JSON array of additional features supported by this Egg. Features may enable special Panel or daemon behavior for supported server configurations.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-file-denylist">File Denylist JSON</label>
                                <textarea id="egg-file-denylist" name="file_denylist" rows="6"><?= htmlspecialchars((string)($modalEgg['file_denylist'] ?? '[]'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>This should be a JSON array of files or directories that users should not be allowed to modify through the File Manager.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-config-files">Configuration Files JSON</label>
                                <textarea id="egg-config-files" name="config_files" rows="10"><?= htmlspecialchars((string)($modalEgg['config_files'] ?? '{}'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>This should be a JSON representation of configuration files to modify and what parts should be changed.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-config-startup">Startup Configuration JSON</label>
                                <textarea id="egg-config-startup" name="config_startup" rows="10"><?= htmlspecialchars((string)($modalEgg['config_startup'] ?? '{}'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>This should be a JSON representation of what values the daemon should be looking for when booting a server to determine completion.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="egg-config-logs">Log Configuration JSON</label>
                                <textarea id="egg-config-logs" name="config_logs" rows="8"><?= htmlspecialchars((string)($modalEgg['config_logs'] ?? '{}'), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <p>This should be a JSON representation of where log files are stored, and whether or not the daemon should be creating custom logs.</p>
                            </div>
                        </div>
                    </section>

                    <fbgadminhr></fbgadminhr>

                    <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                        <button type="submit" class="btn btn-sm"><?= $isEditingEgg ? 'Save Egg' : 'Create Egg' ?></button>
                        <?php if ($isEditingEgg): ?>
                            <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nests&export_egg=<?= (int)$modalEgg['id'] ?>">
                                Export Egg
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($isEditingEgg && $activeEggTab === 'variables'): ?>
                <div class="fbg-admin-nest-variable-grid">
                    <section class="fbg-admin-panel fbg-admin-nest-variable-card">
                        <h3>Create Variable</h3>
                        <form method="POST" class="fbg-admin-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="create_variable">
                            <input type="hidden" name="nest_id" value="<?= $nestId ?>">
                            <input type="hidden" name="egg_id" value="<?= (int)$modalEgg['id'] ?>">
                            <div class="fbg-admin-form-grid">
                                <div class="fbg-admin-field"><label>Name</label><input name="variable_name" type="text" required></div>
                                <div class="fbg-admin-field"><label>Environment Variable</label><input name="env_variable" type="text" required placeholder="SERVER_JARFILE"></div>
                                <div class="fbg-admin-field fbg-admin-field-full"><label>Description</label><textarea name="variable_description" rows="3"></textarea></div>
                                <div class="fbg-admin-field"><label>Default Value</label><input name="default_value" type="text"></div>
                                <div class="fbg-admin-field"><label>Rules</label><input name="rules" type="text" placeholder="required|string|max:20"></div>
                                <div class="fbg-admin-field fbg-admin-field-full fbg-admin-table-actions">
                                    <label class="fbg-admin-checkbox"><input type="checkbox" name="user_viewable" value="1" checked><span>User-viewable</span></label>
                                    <label class="fbg-admin-checkbox"><input type="checkbox" name="user_editable" value="1" checked><span>User-editable</span></label>
                                </div>
                            </div>
                            <div class="fbg-admin-form-actions"><button type="submit" class="btn btn-sm">Create Variable</button></div>
                        </form>
                    </section>

                    <?php foreach ($eggVariables as $variable): ?>
                        <form method="POST" class="fbg-admin-panel fbg-admin-nest-variable-card">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="update_variable">
                            <input type="hidden" name="nest_id" value="<?= $nestId ?>">
                            <input type="hidden" name="egg_id" value="<?= (int)$modalEgg['id'] ?>">
                            <input type="hidden" name="variable_id" value="<?= (int)$variable['id'] ?>">
                            <div class="fbg-admin-form-grid">
                                <div class="fbg-admin-field"><label>Name</label><input name="variable_name" type="text" value="<?= htmlspecialchars((string)$variable['name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="fbg-admin-field"><label>Environment Variable</label><input name="env_variable" type="text" value="<?= htmlspecialchars((string)$variable['env_variable'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="fbg-admin-field fbg-admin-field-full"><label>Description</label><textarea name="variable_description" rows="3"><?= htmlspecialchars((string)$variable['description'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
                                <div class="fbg-admin-field"><label>Default Value</label><input name="default_value" type="text" value="<?= htmlspecialchars((string)$variable['default_value'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="fbg-admin-field"><label>Rules</label><input name="rules" type="text" value="<?= htmlspecialchars((string)($variable['rules'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="fbg-admin-field fbg-admin-field-full fbg-admin-table-actions">
                                    <label class="fbg-admin-checkbox"><input type="checkbox" name="user_viewable" value="1" <?= (int)$variable['user_viewable'] === 1 ? 'checked' : '' ?>><span>User-viewable</span></label>
                                    <label class="fbg-admin-checkbox"><input type="checkbox" name="user_editable" value="1" <?= (int)$variable['user_editable'] === 1 ? 'checked' : '' ?>><span>User-editable</span></label>
                                </div>
                            </div>
                            <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                                <button type="submit" class="btn btn-sm">Save Variable</button>
                                <button type="submit" name="action" value="delete_variable" class="btn btn-sm btn-delete">Delete</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($isEditingEgg && $activeEggTab === 'install'): ?>
                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_install_script">
                    <input type="hidden" name="nest_id" value="<?= $nestId ?>">
                    <input type="hidden" name="egg_id" value="<?= (int)$modalEgg['id'] ?>">

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="egg-script-install">Install Script</label>
                        <textarea id="egg-script-install" name="script_install" rows="18" class="fbg-admin-code-textarea"><?= htmlspecialchars((string)($modalEgg['script_install'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="egg-copy-script-from">Copy Script From</label>
                            <select id="egg-copy-script-from" name="copy_script_from">
                                <option value="0">None</option>
                                <?php foreach ($allEggs as $eggOption): ?>
                                    <option value="<?= (int)$eggOption['id'] ?>" <?= (int)($modalEgg['copy_script_from'] ?? 0) === (int)$eggOption['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$eggOption['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fbg-admin-field"><label for="egg-script-container">Script Container</label><input id="egg-script-container" name="script_container" type="text" value="<?= htmlspecialchars((string)$modalEgg['script_container'], ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="fbg-admin-field"><label for="egg-script-entry">Script Entrypoint</label><input id="egg-script-entry" name="script_entry" type="text" value="<?= htmlspecialchars((string)$modalEgg['script_entry'], ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox"><input type="checkbox" name="script_is_privileged" value="1" <?= (int)$modalEgg['script_is_privileged'] === 1 ? 'checked' : '' ?>><span>Privileged install script</span></label>
                        </div>
                    </div>
                    <div class="fbg-admin-form-actions fbg-admin-user-modal-actions"><button type="submit" class="btn btn-sm">Save Install Script</button></div>
                </form>
            <?php endif; ?>

            <?php if ($isEditingEgg && $activeEggTab === 'configuration'): ?>
                <section class="fbg-admin-node-danger-panel fbg-admin-nest-delete-panel">
                    <div>
                        <h2>Delete Egg</h2>
                        <p>Deleting an egg is permanent. It must not be used by active servers or shop plans.</p>
                        <?php if ($eggServerCount > 0 || $eggGameCount > 0): ?>
                            <p class="fbg-admin-help-text">Remove <?= $eggServerCount ?> server reference<?= $eggServerCount === 1 ? '' : 's' ?> and <?= $eggGameCount ?> shop reference<?= $eggGameCount === 1 ? '' : 's' ?> first.</p>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-delete" id="admin-egg-delete-open" <?= ($eggServerCount > 0 || $eggGameCount > 0) ? 'disabled' : '' ?>>Delete Egg</button>
                </section>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const nestModal = document.getElementById('admin-nest-modal');
    const eggModal = document.getElementById('admin-egg-modal');
    if (nestModal || eggModal) {
        document.body.classList.add('fbg-modal-open');
    }

    const eggCreateOpen = document.getElementById('admin-egg-create-open');
    if (eggCreateOpen) {
        eggCreateOpen.addEventListener('click', () => {
            window.location.href = <?= json_encode(fbgAdminNestsBaseQuery(['nest' => (int)$modalNest['id'], 'new_egg' => 1, 'egg' => null, 'tab' => null]), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        });
    }

    const eggUpdateForm = document.getElementById('admin-egg-update-form');
    if (eggUpdateForm) {
        eggUpdateForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const fileInput = eggUpdateForm.querySelector('input[name="egg_file"]');
            if (!fileInput?.files?.length) {
                window.FBGToast?.({
                    type: 'error',
                    title: 'Egg Update',
                    message: 'Choose an egg JSON file before updating.',
                });
                return;
            }

            if (typeof window.FBGConfirm !== 'function') {
                window.FBGToast?.({
                    type: 'error',
                    title: 'Egg Update',
                    message: 'The confirmation window could not be opened. Please refresh and try again.',
                });
                return;
            }

            const ok = await window.FBGConfirm({
                type: 'warning',
                title: 'Update egg?',
                message: 'This will replace this egg\'s configuration, variables, and install script with the uploaded JSON.\n\nExisting servers will keep using the same egg ID.',
                confirmText: 'Update Egg',
                cancelText: 'Cancel',
            });

            if (ok) {
                eggUpdateForm.submit();
            }
        });
    }

    const confirmDelete = (buttonId, title, message, formHtml) => {
        const button = document.getElementById(buttonId);
        if (!button) return;
        button.addEventListener('click', async () => {
            if (typeof window.FBGConfirm !== 'function') return;
            const ok = await window.FBGConfirm({
                title,
                message,
                confirmText: 'Delete',
                cancelText: 'Cancel',
                type: 'danger',
            });
            if (!ok) return;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = formHtml;
            document.body.appendChild(wrapper);
            wrapper.querySelector('form')?.submit();
        });
    };

    confirmDelete(
        'admin-nest-delete-open',
        'Delete nest?',
        'This nest will be permanently removed.',
        <?= json_encode('<form method="POST"><input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="delete_nest"><input type="hidden" name="nest_id" value="' . (int)$modalNest['id'] . '"><input type="hidden" name="delete_confirmation" value="DELETE"></form>', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    );

    confirmDelete(
        'admin-egg-delete-open',
        'Delete egg?',
        'This egg and its variables will be permanently removed.',
        <?= json_encode('<form method="POST"><input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="delete_egg"><input type="hidden" name="nest_id" value="' . (int)$nestId . '"><input type="hidden" name="egg_id" value="' . (int)$modalEgg['id'] . '"><input type="hidden" name="delete_confirmation" value="DELETE"></form>', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    );
});
</script>
