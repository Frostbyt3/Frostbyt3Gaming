<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../pterodactyl.php';

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

function normalizeFileEntry(array $entry, string $currentDirectory): array
{
    $name       = (string)($entry['name'] ?? 'Unnamed');
    $isFile     = (bool)($entry['is_file'] ?? false);
    $isSymlink  = (bool)($entry['is_symlink'] ?? false);
    $size       = (int)($entry['size'] ?? 0);
    $modifiedAt = (string)($entry['modified_at'] ?? '');

    $path = $currentDirectory === '/'
        ? '/' . $name
        : rtrim($currentDirectory, '/') . '/' . $name;

    return [
        'name'        => $name,
        'path'        => $path,
        'is_file'     => $isFile,
        'is_symlink'  => $isSymlink,
        'size'        => $size,
        'modified_at' => $modifiedAt,
        'mime'        => (string)($entry['mimetype'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$directory        = normalizeDirectoryPath((string)($_GET['dir'] ?? '/'));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.read');

    $entries = pteroListServerFiles($serverIdentifier, $directory);

    $items = array_map(
        static fn(array $entry): array => normalizeFileEntry($entry, $directory),
        $entries
    );

    usort($items, static function (array $a, array $b): int {
        if ($a['is_file'] !== $b['is_file']) {
            return $a['is_file'] ? 1 : -1; // folders first
        }

        return strnatcasecmp($a['name'], $b['name']);
    });

    echo json_encode([
        'ok'        => true,
        'directory' => $directory,
        'items'     => $items,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load files.');
}