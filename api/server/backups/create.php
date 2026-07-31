<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondCreate(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondCreate(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondCreate(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    respondCreate(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$csrfToken        = (string)($input['csrf_token'] ?? '');
$name             = trim((string)($input['name'] ?? ''));
$ignored          = $input['ignored'] ?? [];
$isLocked         = !empty($input['is_locked']);

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    respondCreate(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '') {
    respondCreate(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

if (!is_array($ignored)) {
    $ignored = [];
}

$ignored = array_values(array_filter(
    array_map(
        static fn($line): string => trim((string)$line),
        $ignored
    ),
    static fn(string $line): bool => $line !== ''
));

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'backup.create');

    session_write_close();

    $payload = [
        'name'      => $name,
        'ignored'   => implode("\n", $ignored),
        'is_locked' => $isLocked,
    ];

    $result = pteroCreateServerBackup($serverIdentifier, $payload);

    if (empty($result['ok'])) {
        respondCreate((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to create backup.',
        ]);
    }

    respondCreate(200, [
        'ok' => true,
        'message' => 'Backup creation started.',
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    respondCreate(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to create backup.',
    ]);
}