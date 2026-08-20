<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function fbgServerDatabaseCreateErrorMessage(mixed $value, string $fallback): string
{
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (is_array($value)) {
        $paths = [
            ['errors', 0, 'detail'],
            ['errors', 0, 'message'],
            ['error'],
            ['message'],
            ['data', 'errors', 0, 'detail'],
            ['data', 'errors', 0, 'message'],
            ['data', 'error'],
            ['data', 'message'],
        ];

        foreach ($paths as $path) {
            $current = $value;
            foreach ($path as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    continue 2;
                }

                $current = $current[$key];
            }

            if (is_string($current) && trim($current) !== '') {
                return trim($current);
            }
        }
    }

    return $fallback;
}

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

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$databaseName = trim((string)($input['database'] ?? ''));
$remote = trim((string)($input['remote'] ?? '%'));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $databaseName)) {
    pteroJsonError(422, 'Database name may only contain letters, numbers, dashes, and underscores.');
}

if (mb_strlen($databaseName) < 3 || mb_strlen($databaseName) > 48) {
    pteroJsonError(422, 'Database name must be between 3 and 48 characters.');
}

if ($remote === '') {
    $remote = '%';
}

if (!preg_match('/^[0-9%.]{1,15}$/', $remote)) {
    pteroJsonError(422, 'Connections From must be a valid IP address pattern or % wildcard.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'database.create');

    session_write_close();

    $result = pteroCreateDatabase($serverIdentifier, [
        'database' => $databaseName,
        'remote' => $remote,
    ]);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => fbgServerDatabaseCreateErrorMessage($result, 'Failed to create database.'),
            'data' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Database created successfully.',
            'item' => $result['data']['attributes'] ?? null,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to create database.');
}
