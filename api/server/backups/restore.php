<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondRestore(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondRestore(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondRestore(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    respondRestore(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$backupUuid       = trim((string)($input['backup'] ?? ''));
$csrfToken        = (string)($input['csrf_token'] ?? '');
$truncate         = array_key_exists('truncate', $input) ? !empty($input['truncate']) : true;

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    respondRestore(403, [
        'ok' => false,
        'error' => 'Security check failed.',
    ]);
}

if ($serverIdentifier === '' || $backupUuid === '') {
    respondRestore(400, [
        'ok' => false,
        'error' => 'Missing backup request data.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'backup.restore');

    session_write_close();

    $result = pteroRestoreServerBackup($serverIdentifier, $backupUuid, $truncate);

    if (empty($result['ok'])) {
        respondRestore((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to restore backup.',
        ]);
    }

    respondRestore(200, [
        'ok' => true,
        'message' => 'Backup restore queued successfully.',
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    respondRestore(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to restore backup.',
    ]);
}