<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../pterodactyl.php';

function failDownload(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function normalizeFilePath(string $path): string
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    failDownload(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    failDownload(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$filePath = normalizeFilePath((string)($_GET['file'] ?? ''));

if ($serverIdentifier === '') {
    failDownload(400, 'Missing server identifier.');
}

if ($filePath === '/' || $filePath === '') {
    failDownload(400, 'Missing file path.');
}

$allowedServers = array_values(array_filter(
    array_map('strval', $_SESSION['allowed_servers'] ?? [])
));

if (!in_array($serverIdentifier, $allowedServers, true)) {
    failDownload(403, 'You do not have access to that server.');
}

session_write_close();

try {
    $downloadUrl = pteroGetServerFileDownloadUrl($serverIdentifier, $filePath);

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: ' . $downloadUrl, true, 302);
    exit;
} catch (Throwable $e) {
    failDownload(
        500,
        $e->getMessage() !== '' ? $e->getMessage() : 'Failed to start file download.'
    );
}