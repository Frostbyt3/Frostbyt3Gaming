<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pteroJsonError(405, 'Method not allowed');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated');
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput !== false ? $rawInput : '{}', true);

if (!is_array($input)) {
    pteroJsonError(400, 'Invalid JSON payload');
}

$id = trim((string)($input['id'] ?? ''));
$path = trim((string)($input['path'] ?? '/'));
$name = trim((string)($input['name'] ?? ''));

if ($id === '' || $name === '') {
    pteroJsonError(400, 'Invalid request');
}

if ($name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
    pteroJsonError(400, 'Invalid folder name');
}

pteroEnsureServerAccessSession(false);
pteroRequireServerPermission($id, 'file.create');

$root = $path !== '' ? $path : '/';

try {
    pteroClientRequest(
        'POST',
        'servers/' . rawurlencode($id) . '/files/create-folder',
        [
            'root' => $root,
            'name' => $name,
        ]
    );

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Folder created',
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to create folder');
}