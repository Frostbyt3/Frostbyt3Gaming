<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

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
$provider = strtolower(trim((string)($input['provider'] ?? '')));
$modpackId = trim((string)($input['modpack_id'] ?? ''));
$versionId = trim((string)($input['modpack_version_id'] ?? ''));
$deleteServerFiles = !empty($input['delete_server_files']);
$allowedProviders = ['atlauncher', 'curseforge', 'feedthebeast', 'modrinth', 'technic', 'voidswrath'];

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if (!in_array($provider, $allowedProviders, true)) {
    pteroJsonError(422, 'Invalid modpack provider.');
}

if ($modpackId === '' || $versionId === '') {
    pteroJsonError(400, 'Select a modpack and version before installing.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.create');

    if ($deleteServerFiles) {
        pteroRequireServerPermission($serverIdentifier, 'file.delete');
    }

    session_write_close();

    $result = pteroClientRequest('POST', "servers/{$serverIdentifier}/minecraft-modpacks/install", [
        'provider' => $provider,
        'modpack_id' => $modpackId,
        'modpack_version_id' => $versionId,
        'delete_server_files' => $deleteServerFiles,
    ]);

    if (empty($result['ok'])) {
        pteroJsonError((int)($result['status'] ?? 500), $result['error'] ?? 'Failed to start modpack installation.');
    }

    if (function_exists('fbgMarkPteroServerInstallCompletionEmailPending')) {
        fbgMarkPteroServerInstallCompletionEmailPending($serverIdentifier, 'modpack');
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'message' => 'Modpack installation has started.',
        'data' => [
            'message' => 'Modpack installation has started.',
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to start modpack installation.');
}
