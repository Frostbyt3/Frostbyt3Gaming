<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function normalizeDirectoryPath(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '/';
    }

    $path = str_replace('\\', '/', $path);

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $parts = explode('/', $path);
    $clean = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($clean);
            continue;
        }

        $clean[] = $part;
    }

    return '/' . implode('/', $clean);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput !== false ? $rawInput : '{}', true);

if (!is_array($input)) {
    pteroJsonError(400, 'Invalid request body.');
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$directory = normalizeDirectoryPath((string)($input['directory'] ?? '/'));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.create');

    session_write_close();

    $uploadUrl = pteroGetServerFileUploadUrl($serverIdentifier);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'directory' => $directory,
        'upload_url' => $uploadUrl,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to get upload URL.');
}