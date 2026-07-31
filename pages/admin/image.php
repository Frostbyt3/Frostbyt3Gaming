<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fbgVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

$uploadDir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/backend/uplimg';
$publicBase = '/backend/uplimg';
$maxBytes = 20 * 1024 * 1024;

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$msg = '';
$uploadedUrl = null;

// ---------------- Handle Deletion ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image'])) {
    fbgVerifyCsrf();

    $fileToDelete = basename((string)$_POST['delete_image']);
    $targetPath = rtrim($uploadDir, '/') . '/' . $fileToDelete;

    if (is_file($targetPath)) {
        if (@unlink($targetPath)) {
            $_SESSION['flash_msg'] = "Deleted: {$fileToDelete}";
        } else {
            $_SESSION['flash_msg'] = "Failed to delete: {$fileToDelete}";
        }
    } else {
        $_SESSION['flash_msg'] = "File not found: {$fileToDelete}";
    }

    header('Location: ./page.php?name=admin-image-upload');
    exit;
}

// ---------------- Handle Upload ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    fbgVerifyCsrf();

    if (!isset($_FILES['image'])) {
        $msg = "Upload failed: no file field received.";
    } else {
        $err = (int)$_FILES['image']['error'];

        if ($err !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds PHP upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE (form limit).',
                UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary server folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];

            $msg = 'Upload failed: ' . ($errors[$err] ?? 'Unknown upload error.');
        } else {
            $tmpPath  = (string)$_FILES['image']['tmp_name'];
            $origName = (string)$_FILES['image']['name'];
            $fileSize = (int)$_FILES['image']['size'];

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                $msg = 'Upload failed: temp file missing or invalid.';
            } elseif ($fileSize <= 0) {
                $msg = 'Upload failed: file size is 0 bytes.';
            } elseif ($fileSize > $maxBytes) {
                $msg = 'File too large. Max is 20 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($tmpPath);

                if (!$mime) {
                    $msg = 'Upload failed: could not determine MIME type.';
                } elseif (!isset($allowedMime[$mime])) {
                    $msg = "Unsupported file type ({$mime}). Please upload JPG, PNG, WEBP, or GIF.";
                } elseif (@getimagesize($tmpPath) === false) {
                    $msg = "That file doesn't appear to be a valid image.";
                } else {
                    $ext = $allowedMime[$mime];
                    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                    $destPath = rtrim($uploadDir, '/') . '/' . $safeName;

                    if (!move_uploaded_file($tmpPath, $destPath)) {
                        $msg = 'Failed to save file on server. Check folder permissions.';
                    } else {
                        @chmod($destPath, 0644);

                        $msg = 'Upload successful!';
                        $uploadedUrl = rtrim($publicBase, '/') . '/' . $safeName;

                        $logLine = sprintf(
                            "[%s] user=%s ip=%s orig=%s saved=%s mime=%s size=%d\n",
                            date('c'),
                            $_SESSION['username'] ?? 'unknown',
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            $origName,
                            $safeName,
                            $mime,
                            $fileSize
                        );

                        @file_put_contents($uploadDir . '/upload.log', $logLine, FILE_APPEND);
                    }
                }
            }
        }
    }
}

// ---------------- Gallery listing ----------------
$gallery = [];
$dir = rtrim($uploadDir, '/');

if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }

        $path = $dir . '/' . $f;

        if (!is_file($path)) {
            continue;
        }

        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $gallery[] = [
            'name'  => $f,
            'mtime' => filemtime($path) ?: 0,
        ];
    }
}

usort($gallery, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
$gallery = array_slice($gallery, 0, 60);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($publicBase, '/');

$currentAdminPage = 'admin-image-upload';
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Image Upload</h1>
                <p class="fbg-admin-subtext">Upload, copy, and manage images used across the Frostbyt3 Gaming website.</p>
            </div>
        </header>

        <?php if (!empty($_SESSION['flash_msg'])): ?>
            <div class="fbg-dashboard-alert success is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars((string)$_SESSION['flash_msg'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_msg']); ?>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
            <div class="fbg-dashboard-alert success is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($uploadedUrl !== null): ?>
            <?php
            $fullUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $uploadedUrl;
            ?>
            <div class="fbg-dashboard-alert is-visible" style="margin-bottom: 20px;">
                Uploaded file:
                <a href="<?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                    <?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Upload Image</h2>
                </div>

                <form method="POST" enctype="multipart/form-data" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fbg-admin-field">
                        <label for="image-upload-file">Choose Image</label>
                        <input
                            id="image-upload-file"
                            type="file"
                            name="image"
                            accept="image/png,image/jpeg,image/webp,image/gif"
                            required
                        >
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Upload Image</button>
                    </div>

                    <p class="fbg-admin-help-text">Allowed: JPG, PNG, WEBP, GIF. Max: 20 MB.</p>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Stored Images</span>
                        <strong><?= count($gallery) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Upload Path</span>
                        <strong>/backend/uplimg</strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Max File Size</span>
                        <strong>20 MB</strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Uploaded Images</h2>
                </div>

                <?php if (empty($gallery)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No images found yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-gallery">
                        <?php foreach ($gallery as $img): ?>
                            <?php $url = $baseUrl . '/' . rawurlencode((string)$img['name']); ?>
                            <div class="fbg-gallery-item">
                                <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                    <img
                                        src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                                        alt="<?= htmlspecialchars((string)$img['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        loading="lazy"
                                    >
                                </a>

                                <div class="fbg-gallery-meta">
                                    <div class="fbg-gallery-url" title="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$img['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>

                                    <button
                                        class="btn btn-sm"
                                        type="button"
                                        onclick="copyToClipboard('<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>', this)"
                                    >
                                        Copy link
                                    </button>

                                    <form method="POST" class="fbg-admin-inline-form" style="margin-top: 8px;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="delete_image" value="<?= htmlspecialchars((string)$img['name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-delete"
                                            onclick="return confirm('Delete this image permanently?');"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<script>
function copyToClipboard(text, element) {
    navigator.clipboard.writeText(text).then(function () {
        const original = element.innerHTML;
        element.textContent = 'Copied!';

        setTimeout(function () {
            element.innerHTML = original;
        }, 2000);
    }).catch(function (err) {
        console.error('Clipboard copy failed:', err);
    });
}
</script>