<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../includes/auth.php';
requireLogin();

if (!function_exists('canAccess')) {
    require_once __DIR__ . '/../../../includes/functions.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fbgRedirect('/page.php?name=admin-webp2png');
    exit;
}

if (!canAccess(4)) {
    http_response_code(403);
    exit('Forbidden');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

function fbgWebp2PngFail(string $message): void
{
    $_SESSION['admin_webp2png_error'] = $message;
    fbgRedirect('/page.php?name=admin-webp2png');
    exit;
}

function fbgWebp2PngFormatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 2) . ' KB';
    return number_format($bytes / 1048576, 2) . ' MB';
}

$mode = ($_POST['mode'] ?? 'upload') === 'url' ? 'url' : 'upload';
$maxBytes = 20 * 1024 * 1024;

$tmpPath = '';
$origName = 'converted.webp';
$tmpFile = null;

if (!extension_loaded('gd') || !function_exists('imagecreatefromwebp')) {
    fbgWebp2PngFail('Server does not support WEBP conversion. GD with WEBP support is required.');
}

if ($mode === 'url') {
    $url = trim((string)($_POST['webp_url'] ?? ''));

    if ($url === '') {
        fbgWebp2PngFail('Paste a WEBP URL first.');
    }

    if (!preg_match('#^https?://#i', $url)) {
        fbgWebp2PngFail('URL must start with http:// or https://');
    }

    if (!function_exists('curl_init')) {
        fbgWebp2PngFail('Server is missing cURL support.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'webp_');
    if ($tmpFile === false) {
        fbgWebp2PngFail('Failed to create temp file.');
    }

    $fp = fopen($tmpFile, 'wb');
    if ($fp === false) {
        @unlink($tmpFile);
        fbgWebp2PngFail('Failed to open temp file.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'FBG-WEBP2PNG/1.0',
        CURLOPT_FAILONERROR    => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmpFile);
        fbgWebp2PngFail('Failed to fetch URL (HTTP ' . $httpCode . ')' . ($curlErr !== '' ? '. cURL: ' . $curlErr : ''));
    }

    $downloadedSize = filesize($tmpFile);
    if ($downloadedSize === false || $downloadedSize <= 0) {
        @unlink($tmpFile);
        fbgWebp2PngFail('Downloaded file is empty.');
    }

    if ($downloadedSize > $maxBytes) {
        @unlink($tmpFile);
        fbgWebp2PngFail('Downloaded file is too large (max ' . fbgWebp2PngFormatBytes($maxBytes) . ').');
    }

    $tmpPath = $tmpFile;
    $pathPart = parse_url($url, PHP_URL_PATH);
    $base = $pathPart ? basename((string)$pathPart) : 'converted.webp';
    $origName = $base !== '' ? $base : 'converted.webp';
} else {
    if (!isset($_FILES['webp'])) {
        fbgWebp2PngFail('Select a WEBP file first.');
    }

    $err = (int)($_FILES['webp']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds PHP upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'Select a WEBP file first.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp folder (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'Server failed writing file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
        ];

        fbgWebp2PngFail($errors[$err] ?? ('Upload failed (PHP error ' . $err . ').'));
    }

    $tmpPath = (string)($_FILES['webp']['tmp_name'] ?? '');
    $origName = (string)($_FILES['webp']['name'] ?? 'converted.webp');
    $fileSize = (int)($_FILES['webp']['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        fbgWebp2PngFail('Invalid upload (temp file missing).');
    }

    if ($fileSize <= 0) {
        fbgWebp2PngFail('Uploaded file is empty.');
    }

    if ($fileSize > $maxBytes) {
        fbgWebp2PngFail('File too large (max ' . fbgWebp2PngFormatBytes($maxBytes) . ').');
    }
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath) ?: '';

if ($mime !== 'image/webp') {
    if ($tmpFile !== null) {
        @unlink($tmpFile);
    }
    fbgWebp2PngFail("That doesn't look like a WEBP file (detected: {$mime}).");
}

$im = @imagecreatefromwebp($tmpPath);
if ($im === false) {
    if ($tmpFile !== null) {
        @unlink($tmpFile);
    }
    fbgWebp2PngFail('Failed to decode WEBP (file may be corrupted).');
}

if ($tmpFile !== null) {
    @unlink($tmpFile);
    $tmpFile = null;
}

imagesavealpha($im, true);
imagealphablending($im, false);

$baseName = pathinfo($origName, PATHINFO_FILENAME);
$baseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName) ?? 'converted';
$baseName = trim($baseName, '_-');
if ($baseName === '') {
    $baseName = 'converted';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $baseName . '.png"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

imagepng($im, null, 6);
imagedestroy($im);
exit;