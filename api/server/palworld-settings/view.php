<?php
#view.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
require_once __DIR__ . '/../../../includes/palworld-settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));

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

    $path = fbgPalworldConfigPath();
    $directory = dirname($path);
    $fileName = basename($path);
    $created = false;

    $files = pteroListServerFiles($serverIdentifier, $directory);
    $exists = false;

    foreach ($files as $file) {
        if (strcasecmp((string)($file['name'] ?? ''), $fileName) === 0 && !empty($file['is_file'])) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        pteroRequireServerPermission($serverIdentifier, 'file.update');
        session_write_close();
        pteroWriteServerFile($serverIdentifier, $path, fbgPalworldCanonicalDefault());
        $created = true;
    } else {
        session_write_close();
    }

    $contents = pteroReadServerFile($serverIdentifier, $path);
    $parsed = fbgPalworldParseConfig($contents);
    $defaultParsed = fbgPalworldParseConfig(fbgPalworldCanonicalDefault());
    $missing = $created ? [] : fbgPalworldFindMissingDefaultKeys($parsed['by_key'], $defaultParsed['by_key']);
    $metadata = fbgPalworldMetadata();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'data' => [
            'path' => $path,
            'created' => $created,
            'settings' => fbgPalworldHydrateSettings($parsed['settings'], $metadata),
            'missing_defaults' => $missing,
            'categories' => ['Gameplay & Balance', 'Server & Network', 'Performance', 'Advanced', 'Other Settings'],
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load Palworld settings.');
}
