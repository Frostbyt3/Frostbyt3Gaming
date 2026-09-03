<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fbcode.php';

header('Content-Type: application/json');

try {
    requireLogin();

    if (!function_exists('canAccess') || !canAccess(4)) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission to generate FBCodes.');
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('The FBCode request could not be read.');
    }

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrf_token'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('Security check failed. Please refresh and try again.');
    }

    $payload['format'] = 'svg';
    $result = fbgCodeGenerate($payload);

    echo json_encode([
        'ok' => true,
        'svg' => $result['body'],
        'warnings' => $result['warnings'],
    ]);
} catch (Throwable $e) {
    if (http_response_code() === 200) {
        http_response_code(422);
    }

    echo json_encode([
        'ok' => false,
        'error' => $e instanceof FBGCodeException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The FBCode preview could not be generated.',
    ]);
}
