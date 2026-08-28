<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function normalizeDecompressPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));

    if ($path === '') {
        return '/';
    }

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

function splitDecompressParentAndName(string $fullPath): array
{
    $normalized = normalizeDecompressPath($fullPath);

    if ($normalized === '/' || $normalized === '') {
        throw new RuntimeException('Invalid path.');
    }

    $parts = explode('/', trim($normalized, '/'));
    $name = array_pop($parts);

    if (!is_string($name) || $name === '') {
        throw new RuntimeException('Invalid file name.');
    }

    $root = '/' . implode('/', $parts);
    if ($root === '') {
        $root = '/';
    }

    return [$root, $name, $normalized];
}

function respondDecompress(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondDecompress(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondDecompress(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput !== false ? $rawInput : '{}', true);

if (!is_array($input)) {
    respondDecompress(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$targetPath = normalizeDecompressPath((string)($input['path'] ?? ''));

if ($serverIdentifier === '') {
    respondDecompress(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

if ($targetPath === '/' || $targetPath === '') {
    respondDecompress(400, [
        'ok' => false,
        'error' => 'Missing file path.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.create');

    [$root, $name, $normalizedPath] = splitDecompressParentAndName($targetPath);

    session_write_close();

    pteroDecompressServerFile($serverIdentifier, $root, $name);

    respondDecompress(200, [
        'ok' => true,
        'message' => 'Item decompressed successfully.',
        'data' => [
            'source' => [
                'root' => $root,
                'name' => $name,
                'path' => $normalizedPath,
            ],
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    respondDecompress(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to decompress item.',
    ]);
}
