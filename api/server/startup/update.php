<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function startupJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    startupJsonResponse(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    startupJsonResponse(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    startupJsonResponse(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));
$variableKey      = trim((string)($input['variable_key'] ?? ''));
$value            = (string)($input['value'] ?? '');
$dockerImage      = trim((string)($input['docker_image'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    startupJsonResponse(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '') {
    startupJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

if ($variableKey === '' && $dockerImage === '') {
    startupJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing required values.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'startup.update');

    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroEnsureServerAccessSession(true);
        $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        startupJsonResponse(404, [
            'ok' => false,
            'error' => 'Server not found.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Docker image update
    |--------------------------------------------------------------------------
    */
    if ($dockerImage !== '') {
        $serverId = (int)($selectedServer['id'] ?? 0);
        $eggId = (int)($selectedServer['egg_id'] ?? $selectedServer['egg'] ?? 0);

        if ($serverId <= 0) {
            startupJsonResponse(400, [
                'ok' => false,
                'error' => 'Invalid server ID.',
            ]);
        }

        $startupData = pteroGetServerStartup($serverIdentifier);
        $payload = is_array($startupData['data'] ?? null) ? $startupData['data'] : [];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $variables = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $startupCommand = (string)($meta['startup_command'] ?? $meta['startup'] ?? '');
        $currentDockerImage = (string)($meta['docker_image'] ?? $meta['image'] ?? '');
        $availableDockerImages = is_array($meta['docker_images'] ?? null) ? $meta['docker_images'] : [];

        if ($dockerImage === $currentDockerImage) {
            startupJsonResponse(200, [
                'ok' => true,
                'message' => 'Docker image unchanged.',
                'data' => [
                    'docker_image' => $currentDockerImage,
                    'docker_image_label' => $availableDockerImages[$currentDockerImage] ?? $currentDockerImage,
                ],
            ]);
        }

        if (!empty($availableDockerImages) && !array_key_exists($dockerImage, $availableDockerImages)) {
            startupJsonResponse(400, [
                'ok' => false,
                'error' => 'Invalid Docker image selection.',
            ]);
        }

        $environment = [];

        foreach ($variables as $item) {
            if (!is_array($item)) {
                continue;
            }

            $envKey = (string)($item['env_variable'] ?? $item['envVariable'] ?? '');
            if ($envKey === '') {
                continue;
            }

            $environment[$envKey] = (string)($item['server_value'] ?? $item['value'] ?? '');
        }

        session_write_close();

        $result = pteroUpdateServerStartupSettings(
            $serverId,
            $startupCommand,
            $environment,
            $eggId,
            $dockerImage
        );

        if (empty($result['ok'])) {
            startupJsonResponse((int)($result['status'] ?? 500), [
                'ok' => false,
                'error' => $result['error'] ?? 'Failed to update Docker image.',
            ]);
        }

        startupJsonResponse(200, [
            'ok' => true,
            'message' => 'Docker image updated successfully.',
            'data' => [
                'docker_image' => $dockerImage,
                'docker_image_label' => $availableDockerImages[$dockerImage] ?? $dockerImage,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Startup variable update
    |--------------------------------------------------------------------------
    */
    session_write_close();

    $result = pteroUpdateServerStartupVariable($serverIdentifier, $variableKey, $value);

    if (empty($result['ok'])) {
        startupJsonResponse((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to update startup variable.',
        ]);
    }

    startupJsonResponse(200, [
        'ok' => true,
        'message' => 'Startup variable updated successfully.',
        'data' => [
            'item' => $result['data']['attributes'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    startupJsonResponse(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to update startup settings.',
    ]);
}