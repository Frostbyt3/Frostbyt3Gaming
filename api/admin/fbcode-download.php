<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fbcode.php';

try {
    requireLogin();

    if (!function_exists('canAccess') || !canAccess(4)) {
        http_response_code(403);
        exit('Forbidden');
    }

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Security check failed.');
    }

    $result = fbgCodeGenerate($_POST, $_FILES['logo_image'] ?? null);
    $filename = fbgCodeDownloadFilename($result['options']);

    header('Content-Type: ' . $result['mime']);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($result['body']));

    echo $result['body'];
} catch (Throwable $e) {
    http_response_code(422);
    echo $e instanceof FBGCodeException || $e instanceof RuntimeException
        ? $e->getMessage()
        : 'The FBCode download could not be generated.';
}
