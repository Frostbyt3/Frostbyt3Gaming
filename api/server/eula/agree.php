<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondMinecraftEula(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondMinecraftEula(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondMinecraftEula(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput !== false ? $rawInput : '{}', true);

if (!is_array($input)) {
    respondMinecraftEula(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$csrfToken = (string)($input['csrf_token'] ?? '');

if (
    $csrfToken === '' ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    respondMinecraftEula(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '') {
    respondMinecraftEula(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.read-content');
    pteroRequireServerPermission($serverIdentifier, 'file.update');

    session_write_close();

    $contents = pteroReadServerFile($serverIdentifier, '/eula.txt');
    $updated = preg_replace('/^(\s*eula\s*=\s*)false\s*$/mi', '$1true', $contents, 1, $count);

    if ($updated === null) {
        throw new RuntimeException('Unable to update the EULA file.');
    }

    if ($count === 0) {
        if (preg_match('/^\s*eula\s*=\s*true\s*$/mi', $contents) === 1) {
            respondMinecraftEula(200, [
                'ok' => true,
                'message' => 'Minecraft EULA is already accepted.',
            ]);
        }

        $updated = rtrim($contents) . PHP_EOL . 'eula=true' . PHP_EOL;
    }

    pteroWriteServerFile($serverIdentifier, '/eula.txt', $updated);

    respondMinecraftEula(200, [
        'ok' => true,
        'message' => 'Minecraft EULA accepted.',
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    respondMinecraftEula(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to accept Minecraft EULA.',
    ]);
}
