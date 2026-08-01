<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

function fbgPayPalCheckoutJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fbgPayPalCheckoutJson(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
        'data' => null,
    ]);
}

if (empty($_SESSION['user_id'])) {
    fbgPayPalCheckoutJson(403, [
        'ok' => false,
        'error' => 'Not authenticated.',
        'data' => null,
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    fbgPayPalCheckoutJson(400, [
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
    fbgPayPalCheckoutJson(403, [
        'ok' => false,
        'error' => 'Security check failed.',
        'data' => null,
    ]);
}

$amount = round((float)($input['amount'] ?? 0), 2);
$result = fbgCreatePayPalBalanceCheckout((int)$_SESSION['user_id'], $amount);

if (empty($result['ok'])) {
    fbgPayPalCheckoutJson(400, [
        'ok' => false,
        'error' => (string)($result['error'] ?? 'Could not start PayPal checkout.'),
        'data' => null,
    ]);
}

fbgPayPalCheckoutJson(200, [
    'ok' => true,
    'error' => null,
    'data' => [
        'redirect_url' => (string)$result['redirect_url'],
    ],
]);

