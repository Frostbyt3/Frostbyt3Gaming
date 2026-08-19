<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$modpackId = trim((string)($_GET['modpack_id'] ?? ''));
$allowedProviders = ['atlauncher', 'curseforge', 'feedthebeast', 'modrinth', 'technic', 'voidswrath'];

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if (!in_array($provider, $allowedProviders, true)) {
    pteroJsonError(422, 'Invalid modpack provider.');
}

if ($modpackId === '') {
    pteroJsonError(400, 'Missing modpack ID.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.create');

    session_write_close();

    $query = http_build_query([
        'provider' => $provider,
        'modpack_id' => $modpackId,
    ]);

    $result = pteroClientRequest('GET', "servers/{$serverIdentifier}/minecraft-modpacks/versions?{$query}");

    if (empty($result['ok'])) {
        pteroJsonError((int)($result['status'] ?? 500), $result['error'] ?? 'Failed to load modpack versions.');
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => $result['data'],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load modpack versions.');
}
