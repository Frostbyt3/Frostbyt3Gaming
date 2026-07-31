<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondDownload(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondDownload(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondDownload(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$backupUuid       = trim((string)($_GET['backup'] ?? ''));

if ($serverIdentifier === '' || $backupUuid === '') {
    respondDownload(400, [
        'ok' => false,
        'error' => 'Missing backup request data.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'backup.download');

    session_write_close();

    $result = pteroGetServerBackupDownload($serverIdentifier, $backupUuid);

    if (empty($result['ok'])) {
        respondDownload((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to get backup download URL.',
        ]);
    }

    $url = trim((string)(
        $result['data']['attributes']['url']
        ?? $result['data']['data']['attributes']['url']
        ?? ''
    ));

    if ($url === '') {
        respondDownload(500, [
            'ok' => false,
            'error' => 'Backup download response did not include a URL.',
        ]);
    }

    respondDownload(200, [
        'ok' => true,
        'data' => [
            'url' => $url,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    respondDownload(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to get backup download URL.',
    ]);
}