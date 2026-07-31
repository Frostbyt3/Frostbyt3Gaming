<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
$fileConfig = require __DIR__ . '/../../../config/files.php';
$maxEditorFileSize = (int)($fileConfig['max_editor_file_size'] ?? (1024 * 1024));

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
$filePath = normalizeFilePath((string)($input['path'] ?? ''));
$contents = (string)($input['contents'] ?? '');
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

/*
|--------------------------------------------------------------------------
| Optional backend size guard
|--------------------------------------------------------------------------
*/
if (strlen($contents) > $maxEditorFileSize) {
    pteroJsonError(400, 'This file is too large to save in the browser editor.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'file.update');

    session_write_close();

    pteroWriteServerFile($serverIdentifier, $filePath, $contents);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'File saved successfully.',
        'path' => $filePath,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to save file.');
}