<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

function normalizeCompressPath(string $path): string
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

function splitCompressParentAndName(string $fullPath): array
{
    $normalized = normalizeCompressPath($fullPath);

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

    return [$root, $name, $normalized];
}

function buildFriendlyArchiveName(string $name): string
{
    $base = trim($name);
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $base) ?: 'item';
    $base = trim((string)$base, ". \t\n\r\0\x0B-");

    if ($base === '') {
        $base = 'item';
    }

    return $base . '-' . date('Y-m-d-His') . '.tar.gz';
}

function respondCompress(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondCompress(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondCompress(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
    ]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput !== false ? $rawInput : '{}', true);

if (!is_array($input)) {
    respondCompress(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
    ]);
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$targetPath = normalizeCompressPath((string)($input['path'] ?? ''));

if ($serverIdentifier === '') {
    respondCompress(400, [
        'ok' => false,
        'error' => 'Missing server identifier.',
    ]);
}

if ($targetPath === '/' || $targetPath === '') {
    respondCompress(400, [
        'ok' => false,
        'error' => 'Missing file or folder path.',
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.archive');

    [$root, $name, $normalizedPath] = splitCompressParentAndName($targetPath);
    $friendlyName = buildFriendlyArchiveName($name);

    session_write_close();

    $archive = pteroCompressServerFiles($serverIdentifier, $root, [$name]);
    $archiveName = trim((string)($archive['name'] ?? ''));
    $renamed = false;

    if ($archiveName !== '' && $archiveName !== $friendlyName) {
        try {
            pteroRenameServerFiles($serverIdentifier, $root, [
                [
                    'from' => $archiveName,
                    'to' => $friendlyName,
                ],
            ]);
            $archiveName = $friendlyName;
            $archive['name'] = $friendlyName;
            $renamed = true;
        } catch (Throwable $renameError) {
            $archive['rename_error'] = $renameError->getMessage();
        }
    }

    respondCompress(200, [
        'ok' => true,
        'message' => 'Item compressed successfully.',
        'data' => [
            'source' => [
                'root' => $root,
                'name' => $name,
                'path' => $normalizedPath,
            ],
            'archive' => $archive,
            'archive_name' => $archiveName,
            'renamed' => $renamed,
        ],
    ]);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    respondCompress(500, [
        'ok' => false,
        'error' => $message !== '' ? $message : 'Failed to compress item.',
    ]);
}
