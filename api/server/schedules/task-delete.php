<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pteroJsonError(405, 'Method not allowed.');
}

if (!isset($_SESSION['user_id'])) {
    pteroJsonError(403, 'Not authenticated.');
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    pteroJsonError(400, 'Invalid request body.');
}

$csrfToken = (string)($input['csrf_token'] ?? '');

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)
) {
    pteroJsonError(403, 'Security check failed.');
}

$serverIdentifier = trim((string)($input['id'] ?? ''));
$scheduleId       = (int)($input['schedule_id'] ?? 0);
$taskId           = (int)($input['task_id'] ?? 0);

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
    pteroRequireServerPermission($serverIdentifier, 'schedule.update');

    session_write_close();

    $result = pteroDeleteScheduleTask($serverIdentifier, $scheduleId, $taskId);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to delete task.',
            'data' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Task deleted successfully.',
            'schedule_id' => $scheduleId,
            'task_id' => $taskId,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to delete task.');
}