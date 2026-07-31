<?php
declare(strict_types=1);

include_once(__DIR__ . '/../../includes/functions.php');
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * IMPORTANT:
 * Since you're allowing all file types, keep uploads OUTSIDE web root.
 * Example final path:
 * /mnt/disks/GS_slot06/file-uploads
 */
$uploadDir = '/mnt/disks/GS_slot06/file-uploads';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$maxBytes = 100 * 1024 * 1024; // 100 MB
$msg = '';
$msgType = 'success';

function verifyFileUploadCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

/**
 * Helpers
 */
function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}

function safeDisplayName(string $name): string
{
    $name = trim($name);
    $name = str_replace(["\0", "/", "\\", ":"], '-', $name);
    $name = preg_replace('/[^\w\-.() \[\]]+/u', '_', $name) ?? 'file';
    $name = preg_replace('/\s+/', ' ', $name) ?? 'file';
    $name = trim($name, ". \t\n\r\0\x0B");

    if ($name === '') {
        $name = 'file';
    }

    return $name;
}

function uniqueStoredName(string $dir, string $originalName): string
{
    $safeName = safeDisplayName($originalName);

    $extension = pathinfo($safeName, PATHINFO_EXTENSION);
    $baseName  = pathinfo($safeName, PATHINFO_FILENAME);

    if ($baseName === '') {
        $baseName = 'file';
    }

    $candidate = $safeName;
    $i = 1;

    while (file_exists(rtrim($dir, '/') . '/' . $candidate)) {
        $suffix = ' (' . $i . ')';
        $candidate = $baseName . $suffix . ($extension !== '' ? '.' . $extension : '');
        $i++;
    }

    return $candidate;
}

/**
 * Handle delete
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    verifyFileUploadCsrf();

    $fileToDelete = basename((string)$_POST['delete_file']);
    $targetPath = rtrim($uploadDir, '/') . '/' . $fileToDelete;

    if (is_file($targetPath)) {
        if (@unlink($targetPath)) {
            $_SESSION['flash_msg'] = "Deleted: {$fileToDelete}";
            $_SESSION['flash_msg_type'] = 'success';
        } else {
            $_SESSION['flash_msg'] = "Failed to delete: {$fileToDelete}";
            $_SESSION['flash_msg_type'] = 'error';
        }
    } else {
        $_SESSION['flash_msg'] = "File not found: {$fileToDelete}";
        $_SESSION['flash_msg_type'] = 'error';
    }

    fbgRedirect('/page.php?name=admin-file-upload');
    exit;
}

/**
 * Handle upload
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    verifyFileUploadCsrf();

    if (!isset($_FILES['upload_file'])) {
        $msg = 'Upload failed: no file field received.';
        $msgType = 'error';
    } else {
        $err = (int)($_FILES['upload_file']['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($err !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds PHP upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE (form limit).',
                UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];

            $msg = $errors[$err] ?? 'Unknown upload error.';
            $msgType = 'error';
        } else {
            $tmpPath  = (string)($_FILES['upload_file']['tmp_name'] ?? '');
            $origName = (string)($_FILES['upload_file']['name'] ?? 'file');
            $fileSize = (int)($_FILES['upload_file']['size'] ?? 0);

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                $msg = 'Upload failed: temp file missing or invalid.';
                $msgType = 'error';
            } elseif ($fileSize <= 0) {
                $msg = 'Upload failed: file size is 0 bytes.';
                $msgType = 'error';
            } elseif ($fileSize > $maxBytes) {
                $msg = 'File too large. Max is ' . formatBytes($maxBytes) . '.';
                $msgType = 'error';
            } else {
                $storedName = uniqueStoredName($uploadDir, $origName);
                $destPath   = rtrim($uploadDir, '/') . '/' . $storedName;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    $msg = 'Failed to save file on server. Check folder permissions.';
                    $msgType = 'error';
                } else {
                    @chmod($destPath, 0644);

                    $msg = 'Upload successful!';
                    $msgType = 'success';

                    $logLine = sprintf(
                        "[%s] user=%s ip=%s orig=%s saved=%s size=%d\n",
                        date('c'),
                        $_SESSION['username'] ?? 'unknown',
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $origName,
                        $storedName,
                        $fileSize
                    );

                    @file_put_contents($uploadDir . '/upload.log', $logLine, FILE_APPEND);
                }
            }
        }
    }
}

/**
 * Build file list
 */
$files = [];
$dir = rtrim($uploadDir, '/');

if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === 'upload.log') {
            continue;
        }

        $path = $dir . '/' . $f;

        if (!is_file($path)) {
            continue;
        }

        $files[] = [
            'name'  => $f,
            'size'  => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
        ];
    }
}

usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$currentAdminPage = 'admin-file-upload';
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>File Upload</h1>
                <p class="fbg-admin-subtext">Upload and manage general files stored outside the public web root.</p>
            </div>
        </header>

        <?php if (!empty($_SESSION['flash_msg'])): ?>
            <div class="fbg-dashboard-alert <?= ($_SESSION['flash_msg_type'] ?? 'success') === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars((string)$_SESSION['flash_msg'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']); ?>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
            <div class="fbg-dashboard-alert <?= $msgType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Upload File</h2>
                </div>

                <form method="POST" enctype="multipart/form-data" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fbg-admin-field">
                        <label for="admin-upload-file">Choose File</label>
                        <input id="admin-upload-file" type="file" name="upload_file" required>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Upload File</button>
                    </div>

                    <p class="fbg-admin-help-text">
                        Allowed: any file type. Max: <?= htmlspecialchars(formatBytes($maxBytes), ENT_QUOTES, 'UTF-8') ?>.
                    </p>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Stored Files</span>
                        <strong><?= count($files) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Storage Path</span>
                        <strong>/mnt/disks/GS_slot06/file-uploads</strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Max File Size</span>
                        <strong><?= htmlspecialchars(formatBytes($maxBytes), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Uploaded Files</h2>
                </div>

                <?php if (empty($files)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No files found yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>File</th>
                                    <th>Size</th>
                                    <th>Uploaded</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($files as $file): ?>
                                    <?php
                                    $downloadUrl = '/api/file-uploads/download.php?file=' . rawurlencode((string)$file['name']);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$file['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(formatBytes((int)$file['size']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y g:i A', (int)$file['mtime']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm">
                                                    Download
                                                </a>

                                                <button
                                                    class="btn btn-sm"
                                                    type="button"
                                                    onclick="copyToClipboard('<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>', this)"
                                                >
                                                    Copy Link
                                                </button>

                                                <form method="POST" class="fbg-admin-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="delete_file" value="<?= htmlspecialchars((string)$file['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-delete"
                                                        onclick="return confirm('Delete this file permanently?');"
                                                    >
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<script>
function copyToClipboard(text, element) {
    const absoluteUrl = window.location.origin + text;

    navigator.clipboard.writeText(absoluteUrl).then(function () {
        const original = element.innerHTML;
        element.innerHTML = 'Copied!';

        setTimeout(function () {
            element.innerHTML = original;
        }, 2000);
    }).catch(function (err) {
        console.error('Clipboard copy failed:', err);
    });
}
</script>