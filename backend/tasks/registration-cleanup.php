<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/registration.php';

$marked = fbgMarkExpiredPendingRegistrations();
$deleted = fbgCleanupExpiredPendingRegistrations();

echo json_encode([
    'ok' => true,
    'marked_expired' => $marked,
    'deleted_retained' => $deleted,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
