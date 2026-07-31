<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function respondRename(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

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
    respondRename(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondRename(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput ?: '{}', true);

if (!is_array($input)) {
    respondRename(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$targetPath = normalizePath((string)($input['path'] ?? ''));
$newName = trim((string)($input['name'] ?? ''));

if ($serverIdentifier === '') {
    respondRename(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

if ($targetPath === '/' || $targetPath === '') {
    respondRename(400, [
        'ok' => false,
        'error' => 'Missing file or folder path.',
    ]);
}

if ($newName === '') {
    respondRename(400, [
        'ok' => false,
        'error' => 'Missing new name.',
    ]);
}

if ($newName === '.' || $newName === '..' || str_contains($newName, '/') || str_contains($newName, '\\')) {
    respondRename(400, [
        'ok' => false,
        'error' => 'That file or folder name is not valid.',
    ]);
}

$allowedServers = array_values(array_filter(
    array_map('strval', $_SESSION['allowed_servers'] ?? [])
));

if (!in_array($serverIdentifier, $allowedServers, true)) {
    respondRename(403, [
        'ok' => false,
        'error' => 'You do not have access to that server.',
    ]);
}

try {
    [$root, $oldName] = splitParentAndName($targetPath);

    if ($oldName === $newName) {
        respondRename(200, [
            'ok' => true,
            'message' => 'Nothing changed.',
            'renamed' => [
                'root' => $root,
                'from' => $oldName,
                'to' => $newName,
                'path' => $targetPath,
            ],
        ]);
    }

    pteroRenameServerFiles($serverIdentifier, $root, [
        [
            'from' => $oldName,
            'to'   => $newName,
        ],
    ]);

    $newPath = rtrim($root, '/');
    $newPath = ($newPath === '' ? '' : $newPath) . '/' . $newName;
    if ($newPath === '') {
        $newPath = '/';
    }

    respondRename(200, [
        'ok' => true,
        'message' => 'Item renamed successfully.',
        'renamed' => [
            'root' => $root,
            'from' => $oldName,
            'to' => $newName,
            'old_path' => $targetPath,
            'new_path' => $newPath,
        ],
    ]);
} catch (Throwable $e) {
    respondRename(500, [
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to rename item.',
    ]);
}