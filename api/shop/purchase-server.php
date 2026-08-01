<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ignore_user_abort(true);
set_time_limit(180);

ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if (!$error || !in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server purchase failed because of a backend error.',
        'data' => null,
    ]);
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../api/pterodactyl.php';

function fbgShopPurchaseJson(int $statusCode, array $payload): void
{
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fbgShopPurchaseJson(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
        'data' => null,
    ]);
}

if (empty($_SESSION['user_id'])) {
    fbgShopPurchaseJson(403, [
        'ok' => false,
        'error' => 'Please log in before purchasing a server.',
        'data' => [
            'redirect_url' => '/page.php?name=login',
        ],
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    fbgShopPurchaseJson(400, [
        'ok' => false,
        'error' => 'Invalid request body.',
        'data' => null,
    ]);
}

$csrfToken = (string)($input['csrf_token'] ?? '');

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    fbgShopPurchaseJson(403, [
        'ok' => false,
        'error' => 'Security check failed.',
        'data' => null,
    ]);
}

$gameId = (int)($input['game_id'] ?? 0);
$result = fbgPurchaseShopGame((int)$_SESSION['user_id'], $gameId);

if (empty($result['ok'])) {
    fbgShopPurchaseJson(400, [
        'ok' => false,
        'error' => (string)($result['error'] ?? 'Could not purchase server.'),
        'data' => null,
    ]);
}

fbgShopPurchaseJson(200, [
    'ok' => true,
    'error' => null,
    'data' => $result['data'],
]);
