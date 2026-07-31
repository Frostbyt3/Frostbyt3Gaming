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

$serverIdentifier  = trim((string)($input['id'] ?? ''));
$scheduleId        = (int)($input['schedule_id'] ?? 0);
$taskId            = (int)($input['task_id'] ?? 0);
$action            = trim((string)($input['action'] ?? ''));
$payload           = trim((string)($input['payload'] ?? ''));
$timeOffset        = (int)($input['time_offset'] ?? 0);
$continueOnFailure = !empty($input['continue_on_failure']);

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($scheduleId <= 0) {
    pteroJsonError(422, 'Missing or invalid schedule ID.');
}

if ($taskId <= 0) {
    pteroJsonError(422, 'Missing or invalid task ID.');
}

$allowedActions = [
    'command',
    'power',
];

if (!in_array($action, $allowedActions, true)) {
    pteroJsonError(422, 'Invalid task action.');
}

if ($timeOffset < 0) {
    pteroJsonError(422, 'Time offset must be 0 or greater.');
}

if ($payload === '') {
    pteroJsonError(422, 'Task payload is required.');
}

if (mb_strlen($payload) > 191) {
    pteroJsonError(422, 'Task payload is too long.');
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'schedule.update');

    session_write_close();

    $result = pteroUpdateScheduleTask($serverIdentifier, $scheduleId, $taskId, [
        'action'              => $action,
        'payload'             => $payload,
        'time_offset'         => $timeOffset,
        'continue_on_failure' => $continueOnFailure,
    ]);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to update task.',
            'data' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Task updated successfully.',
            'item' => $result['data']['attributes'] ?? null,
            'schedule_id' => $scheduleId,
            'task_id' => $taskId,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to update task.');
}