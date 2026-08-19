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
$provider = strtolower(trim((string)($_GET['provider'] ?? 'modrinth')));
$searchQuery = trim((string)($_GET['search_query'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(50, (int)($_GET['page_size'] ?? 25)));
$allowedProviders = ['atlauncher', 'curseforge', 'feedthebeast', 'modrinth', 'technic', 'voidswrath'];

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if (!in_array($provider, $allowedProviders, true)) {
    pteroJsonError(422, 'Invalid modpack provider.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.create');

    session_write_close();

    $query = http_build_query([
        'provider' => $provider,
        'search_query' => $searchQuery,
        'page_size' => $pageSize,
        'page' => $page,
    ]);

    $result = pteroClientRequest('GET', "servers/{$serverIdentifier}/minecraft-modpacks?{$query}");

    if (empty($result['ok'])) {
        pteroJsonError((int)($result['status'] ?? 500), $result['error'] ?? 'Failed to load modpacks.');
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => $result['data'],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load modpacks.');
}
