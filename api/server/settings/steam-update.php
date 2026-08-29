<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function steamUpdateJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function steamUpdateFindAppManifests(string $identifier): array
{
    $files = pteroListServerFiles($identifier, '/steamapps');
    $manifests = [];

    foreach ($files as $file) {
        $fileName = trim((string)($file['name'] ?? ''));

        if ($fileName !== '' && preg_match('/^appmanifest_(\d+)\.acf$/i', $fileName, $matches)) {
            $manifests[] = [
                'name' => $fileName,
                'app_id' => $matches[1],
            ];
        }
    }

    return $manifests;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    steamUpdateJsonResponse(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
        'data' => null,
    ]);
}

if (!isset($_SESSION['user_id'])) {
    steamUpdateJsonResponse(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
        'data' => null,
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    steamUpdateJsonResponse(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
        'data' => null,
    ]);
}

$csrfToken = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    steamUpdateJsonResponse(403, [
        'ok' => false,
        'error' => 'Security check failed.',
        'data' => null,
    ]);
}

if ($serverIdentifier === '') {
    steamUpdateJsonResponse(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
        'data' => null,
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.read');
    pteroRequireServerPermission($serverIdentifier, 'file.delete');
    pteroRequireServerPermission($serverIdentifier, 'control.restart');

    $selectedServer = pteroGetSessionServerMeta($serverIdentifier);

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        pteroEnsureServerAccessSession(true);
        $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($selectedServer) || empty($selectedServer['id'])) {
        steamUpdateJsonResponse(404, [
            'ok' => false,
            'error' => 'Server not found.',
            'data' => null,
        ]);
    }

    $manifests = steamUpdateFindAppManifests($serverIdentifier);

    if ($manifests === []) {
        steamUpdateJsonResponse(404, [
            'ok' => false,
            'error' => 'No Steam app manifest was found for this server.',
            'data' => null,
        ]);
    }

    $manifestNames = array_values(array_column($manifests, 'name'));
    $appIds = array_values(array_column($manifests, 'app_id'));

    session_write_close();

    pteroDeleteServerFiles($serverIdentifier, '/steamapps', $manifestNames);

    $restartResult = pteroSendPowerAction($serverIdentifier, 'restart');

    if (empty($restartResult['ok'])) {
        steamUpdateJsonResponse((int)($restartResult['status'] ?? 500) ?: 500, [
            'ok' => false,
            'error' => $restartResult['error'] ?? 'The update check was queued, but the server could not be restarted.',
            'data' => [
                'app_ids' => $appIds,
                'manifests' => $manifestNames,
            ],
        ]);
    }

    steamUpdateJsonResponse(200, [
        'ok' => true,
        'error' => null,
        'message' => 'Steam update check has been queued.',
        'data' => [
            'message' => 'Steam update check has been queued.',
            'app_ids' => $appIds,
            'manifests' => $manifestNames,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    steamUpdateJsonResponse(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to queue Steam update check.',
        'data' => null,
    ]);
}
