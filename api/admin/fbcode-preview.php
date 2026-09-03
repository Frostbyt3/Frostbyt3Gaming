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

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('Security check failed. Please refresh and try again.');
    }

    $_POST['format'] = 'svg';
    $result = fbgCodeGenerate($_POST, $_FILES['logo_image'] ?? null);

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
