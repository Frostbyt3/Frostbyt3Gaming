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
$name             = trim((string)($input['name'] ?? ''));
$minute           = trim((string)($input['minute'] ?? '*'));
$hour             = trim((string)($input['hour'] ?? '*'));
$dayOfMonth       = trim((string)($input['day_of_month'] ?? '*'));
$month            = trim((string)($input['month'] ?? '*'));
$dayOfWeek        = trim((string)($input['day_of_week'] ?? '*'));
$onlyWhenOnline   = !empty($input['only_when_online']);
$isActive         = !empty($input['is_active']);

if ($serverIdentifier === '') {
    pteroJsonError(400, 'Missing server identifier.');
}

if ($name === '') {
    pteroJsonError(422, 'Schedule name is required.');
}

if (mb_strlen($name) > 191) {
    pteroJsonError(422, 'Schedule name is too long.');
}

$minute     = $minute !== '' ? $minute : '*';
$hour       = $hour !== '' ? $hour : '*';
$dayOfMonth = $dayOfMonth !== '' ? $dayOfMonth : '*';
$month      = $month !== '' ? $month : '*';
$dayOfWeek  = $dayOfWeek !== '' ? $dayOfWeek : '*';

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'schedule.create');

    $payload = [
        'name'             => $name,
        'minute'           => $minute,
        'hour'             => $hour,
        'day_of_month'     => $dayOfMonth,
        'month'            => $month,
        'day_of_week'      => $dayOfWeek,
        'only_when_online' => $onlyWhenOnline,
        'is_active'        => $isActive,
    ];

    session_write_close();

    $result = pteroCreateSchedule($serverIdentifier, $payload);

    if (empty($result['ok'])) {
        http_response_code((int)($result['status'] ?? 500) ?: 500);
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to create schedule.',
            'data' => null,
        ]);
        exit;
    }

    $item = $result['data']['attributes'] ?? null;

    echo json_encode([
        'ok' => true,
        'error' => null,
        'data' => [
            'message' => 'Schedule created successfully.',
            'item' => $item,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());
    pteroJsonError(500, $message !== '' ? $message : 'Failed to create schedule.');
}