<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$identifier = trim((string)($_GET['server_identifier'] ?? ''));

if ($identifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($identifier, 'control.console');

    $result = pteroGetConsoleWebsocket($identifier);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500));
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to get console websocket details.',
        ]);
        exit;
    }

    $socket = (string)($result['data']['data']['socket'] ?? '');
    $token  = (string)($result['data']['data']['token'] ?? '');

    if ($socket === '' || $token === '') {
        pteroJsonError(500, 'Console websocket response was missing socket or token.');
    }

    echo json_encode([
        'ok' => true,
        'socket' => $socket,
        'token' => $token,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to get console websocket details.');
}