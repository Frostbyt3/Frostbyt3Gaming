<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function fbgDatabasePasswordFromItem(array $item): string
{
    $paths = [
        ['attributes', 'password'],
        ['relationships', 'password', 'data', 'attributes', 'password'],
        ['relationships', 'password', 'attributes', 'password'],
        ['attributes', 'relationships', 'password', 'data', 'attributes', 'password'],
        ['attributes', 'relationships', 'password', 'attributes', 'password'],
    ];

    foreach ($paths as $path) {
        $value = $item;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                continue 2;
            }

            $value = $value[$key];
        }

        if (is_scalar($value) && trim((string)$value) !== '') {
            return (string)$value;
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$databaseId = trim((string)($_GET['database_id'] ?? ''));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($databaseId === '') {
    pteroJsonError(422, 'Missing database ID.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'database.read');
    pteroRequireServerPermission($serverIdentifier, 'database.view_password');

    $result = pteroListDatabases($serverIdentifier, true);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load database details.',
            'data' => null,
        ]);
        exit;
    }

    $items = $result['data']['data'] ?? [];
    $match = null;

    foreach ($items as $item) {
        $attributes = $item['attributes'] ?? [];
        if ((string)($attributes['id'] ?? '') === $databaseId) {
            $match = $item;
            break;
        }
    }

    if ($match === null) {
        pteroJsonError(404, 'Database could not be found.');
    }

    $password = fbgDatabasePasswordFromItem($match);
    if ($password !== '') {
        $match['attributes']['password'] = $password;
    }

    echo json_encode([
        'ok' => true,
        'data' => $match,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load database details.');
}
