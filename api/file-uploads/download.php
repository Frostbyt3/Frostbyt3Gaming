<?php
declare(strict_types=1);

include_once(__DIR__ . "/../../includes/functions.php");
require_once __DIR__ . "/../../includes/auth.php";
/* requireLogin();

$allowedUsernames = ['Skyler', 'Art']; // change these to exact usernames
$currentUsername = trim((string)($_SESSION['username'] ?? ''));

if (!in_array($currentUsername, $allowedUsernames, true)) {
    http_response_code(403);
    exit('Access denied.');
} */

$uploadDir = '/mnt/disks/GS_slot06/file-uploads';

$file = basename((string)($_GET['file'] ?? ''));
if ($file === '') {
    http_response_code(400);
    exit('Missing file.');
}

$path = rtrim($uploadDir, '/') . '/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = mime_content_type($path);
if (!$mime) {
    $mime = 'application/octet-stream';
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . rawurlencode($file) . '"');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;