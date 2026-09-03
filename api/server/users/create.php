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

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));
$email            = trim((string)($input['email'] ?? ''));
$permissions      = $input['permissions'] ?? [];

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    pteroJsonError(422, 'A valid email address is required.');
}

if (!is_array($permissions)) {
    $permissions = [];
}

$permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));

$catalog = pteroSubuserPermissionCatalog();
$validPermissions = [];

foreach ($catalog as $groupPermissions) {
    if (!is_array($groupPermissions)) {
        continue;
    }

    foreach ($groupPermissions as $permission => $_label) {
        $validPermissions[] = (string)$permission;
    }
}

$validPermissions = array_values(array_unique($validPermissions));

$permissions = array_values(array_filter(
    $permissions,
    static fn(string $permission): bool => in_array($permission, $validPermissions, true)
));

if (!$permissions) {
    pteroJsonError(422, 'At least one permission is required.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'user.create');
    $serverMeta = pteroGetSessionServerMeta($serverIdentifier);

    session_write_close();

    $result = pteroCreateSubuser($serverIdentifier, [
        'email'       => $email,
        'permissions' => $permissions,
    ]);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to create subuser.',
            'data' => null,
        ]);
        exit;
    }

    $emailSent = false;
    try {
        require_once __DIR__ . '/../../../includes/mailer.php';

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = function_exists('fbgShopBaseUrl')
            ? rtrim((string)fbgShopBaseUrl(), '/')
            : ($host !== '' ? "{$scheme}://{$host}" : 'https://frostbyt3gaming.com');
        $serverPanelUrl = $baseUrl . '/page.php?name=serverpanel&id=' . rawurlencode($serverIdentifier);
        $serverName = trim((string)($serverMeta['name'] ?? ''));

        $emailSent = fbgSendServerSubuserAccessEmail([
            'type' => 'added',
            'to_email' => $email,
            'server_name' => $serverName !== '' ? $serverName : 'your server',
            'server_panel_url' => $serverPanelUrl,
        ]);
    } catch (Throwable $mailError) {
        error_log('Subuser creation email failed: ' . $mailError->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Subuser created successfully.',
            'item' => $result['data']['attributes'] ?? null,
            'email_sent' => $emailSent,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to create subuser.');
}
