<?php
#merge-defaults.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
require_once __DIR__ . '/../../../includes/palworld-settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    pteroJsonError(400, 'Invalid request body.');
}

$csrfToken = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    pteroJsonError(403, 'Security check failed.');
}

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

try {
    pteroEnsureServerAccessSession(false);

    $server = pteroGetSessionServerMeta($serverIdentifier);
    if (empty($server)) {
        pteroEnsureServerAccessSession(true);
        $server = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($server)) {
        pteroJsonError(404, 'Server not found.');
    }

    if (!fbgPalworldIsServer($server)) {
        pteroJsonError(404, 'Palworld settings are not available for this server.');
    }

    pteroRequireServerPermission($serverIdentifier, 'file.read-content');
    pteroRequireServerPermission($serverIdentifier, 'file.update');

    session_write_close();

    $path = fbgPalworldConfigPath($server);
    $contents = pteroReadServerFile($serverIdentifier, $path);
    $merged = fbgPalworldMergeMissingDefaults($contents);

    pteroWriteServerFile($serverIdentifier, $path, rtrim($merged, "\r\n") . "\n");

    $parsed = fbgPalworldParseConfig($merged);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'data' => [
            'path' => $path,
            'settings' => fbgPalworldHydrateSettings($parsed['settings'], fbgPalworldMetadata()),
            'missing_defaults' => [],
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to add missing Palworld settings.');
}
