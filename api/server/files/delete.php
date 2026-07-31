<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function normalizePath(string $path): string
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

function splitParentAndName(string $fullPath): array
{
    $normalized = normalizePath($fullPath);

    if ($normalized === '/' || $normalized === '') {
        throw new RuntimeException('Invalid path.');
    }

    $parts = explode('/', trim($normalized, '/'));
    $name = array_pop($parts);

    if (!is_string($name) || $name === '') {
        throw new RuntimeException('Invalid file or folder name.');
    }

    $root = '/' . implode('/', $parts);
    if ($root === '') {
        $root = '/';
    }

    return [$root, $name];
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
$targetPath = normalizePath((string)($input['path'] ?? ''));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($targetPath === '/' || $targetPath === '') {
    pteroJsonError(400, 'Missing file or folder path.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.delete');

    [$root, $name] = splitParentAndName($targetPath);

    session_write_close();

    $result = pteroDeleteServerFiles($serverIdentifier, $root, [$name]);

    if (is_array($result) && empty($result['ok'])) {
        pteroJsonError((int)($result['status'] ?? 500), $result['error'] ?? 'Failed to delete item.');
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Item deleted successfully.',
        'data' => [
            'deleted' => [
                'root' => $root,
                'name' => $name,
                'path' => $targetPath,
            ],
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to delete item.');
}