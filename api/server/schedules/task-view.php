<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$serverIdentifier = trim((string)($_GET['id'] ?? ''));
$scheduleId       = (int)($_GET['schedule_id'] ?? 0);
$taskId           = (int)($_GET['task_id'] ?? 0);

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($scheduleId <= 0) {
    pteroJsonError(422, 'Missing or invalid schedule ID.');
}

if ($taskId <= 0) {
    pteroJsonError(422, 'Missing or invalid task ID.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'schedule.read');

    $result = pteroGetScheduleTask($serverIdentifier, $scheduleId, $taskId);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500));
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to load task.',
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'item' => $result['data']['attributes'] ?? null,
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to load task.');
}