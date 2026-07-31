<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
$fileConfig = require __DIR__ . '/../../../config/files.php';

function getAllowedEditableExtensions(array $fileConfig): array
{
    return array_values(array_filter(
        array_map(
            static fn($ext): string => strtolower(trim((string)$ext)),
            (array)($fileConfig['editable_extensions'] ?? [])
        ),
        static fn(string $ext): bool => $ext !== ''
    ));
}

function normalizeFilePath(string $path): string
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$filePath = normalizeFilePath((string)($_GET['file'] ?? ''));
$allowedExtensions = getAllowedEditableExtensions($fileConfig);
$fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($filePath === '/' || $filePath === '') {
    pteroJsonError(400, 'Missing file path.');
}

if ($fileExtension === '' || !in_array($fileExtension, $allowedExtensions, true)) {
    pteroJsonError(400, 'This file type is not editable in the browser.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.read-content');

    session_write_close();

    $contents = pteroReadServerFile($serverIdentifier, $filePath);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'path' => $filePath,
        'contents' => $contents,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to read file.');
}