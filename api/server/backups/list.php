<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondList(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondList(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondList(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));

if ($serverIdentifier === '') {
    respondList(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'backup.read');

    session_write_close();

    $result = pteroGetServerBackups($serverIdentifier);

    if (empty($result['ok'])) {
        respondList((int)($result['status'] ?? 500), [
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load backups.',
        ]);
    }

    $rows = [];

    foreach (($result['data']['data'] ?? []) as $item) {
        $attr = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];

        $rows[] = [
            'uuid'          => trim((string)($attr['uuid'] ?? '')),
            'name'          => trim((string)($attr['name'] ?? '')),
            'bytes'         => (int)($attr['bytes'] ?? 0),
            'checksum'      => trim((string)($attr['checksum'] ?? '')),
            'is_locked'     => !empty($attr['is_locked']),
            'is_successful' => array_key_exists('is_successful', $attr)
                ? (bool)$attr['is_successful']
                : true,
            'created_at'    => (string)($attr['created_at'] ?? ''),
            'completed_at'  => (string)($attr['completed_at'] ?? ''),
        ];
    }

    respondList(200, [
        'ok' => true,
        'data' => [
            'backups' => $rows,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    respondList(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to load backups.',
    ]);
}