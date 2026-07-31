<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!function_exists('canAccess')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fbgWebp2PngFormatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    if ($bytes < 1073741824) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return number_format($bytes / 1073741824, 2) . ' GB';
}

$maxBytes = 20 * 1024 * 1024;

$currentAdminPage = 'admin-webp-png';
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>WEBP to PNG</h1>
                <p class="fbg-admin-subtext">Convert uploaded WEBP images or direct WEBP URLs into downloadable PNG files.</p>
            </div>
        </header>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Convert Image</h2>
                </div>

                <form
                    method="POST"
                    action="/api/admin/webp2png/convert.php"
                    enctype="multipart/form-data"
                    class="fbg-admin-form"
                    id="fbg-webp2png-form"
                >
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fbg-admin-field">
                        <label>Source</label>

                       <div class="fbg-admin-inline-options">
                            <div class="fbg-admin-radio-group">
                            <label class="fbg-admin-radio-option">
                                <input type="radio" name="mode" value="upload" checked>
                                <span>Upload File</span>
                            </label>

                            <label class="fbg-admin-radio-option">
                                <input type="radio" name="mode" value="url">
                                <span>From URL</span>
                            </label>
                        </div>
                    </div>

                    <div id="fbg-webp-upload-block">
                        <div class="fbg-admin-field">
                            <label for="webp-upload-file">Choose WEBP</label>
                            <input id="webp-upload-file" type="file" name="webp" accept="image/webp">
                            <p class="fbg-admin-help-text">Upload a .webp file and it will be converted and downloaded immediately.</p>
                        </div>
                    </div>

                    <div id="fbg-webp-url-block" style="display:none;">
                        <div class="fbg-admin-field">
                            <label for="webp-url">WEBP URL</label>
                            <input id="webp-url" type="url" name="webp_url" placeholder="https://example.com/image.webp">
                            <p class="fbg-admin-help-text">Paste a direct WEBP URL to convert it into PNG.</p>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Convert &amp; Download</button>
                    </div>
                </form>
                
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Accepted Input</span>
                        <strong>.webp only</strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Output Format</span>
                        <strong>.png</strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Max File Size</span>
                        <strong><?= htmlspecialchars(fbgWebp2PngFormatBytes($maxBytes), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Notes</h2>
                </div>

                <div class="fbg-admin-note">
                    <p>This tool does not permanently store uploaded files.</p>
                    <p>URL imports are downloaded to a temporary file, validated as WEBP, converted, then cleaned up.</p>
                    <p>Transparency is preserved in the PNG output when possible.</p>
                </div>
            </section>
        </div>
    </div>
</section>

<script>
(() => {
    const uploadBlock = document.getElementById('fbg-webp-upload-block');
    const urlBlock = document.getElementById('fbg-webp-url-block');
    const fileInput = document.getElementById('webp-upload-file');
    const urlInput = document.getElementById('webp-url');
    const radios = document.querySelectorAll('input[name="mode"]');
    const form = document.getElementById('fbg-webp2png-form');

    function setMode(mode) {
        uploadBlock.style.display = mode === 'url' ? 'none' : '';
        urlBlock.style.display = mode === 'url' ? '' : 'none';
    }

    radios.forEach((radio) => {
        radio.addEventListener('change', () => setMode(radio.value));
    });

    form.addEventListener('submit', (event) => {
        const selectedMode = document.querySelector('input[name="mode"]:checked')?.value || 'upload';

        if (selectedMode === 'upload' && (!fileInput.files || !fileInput.files[0])) {
            event.preventDefault();
            alert('Select a WEBP file first.');
            return;
        }

        if (selectedMode === 'url' && !urlInput.value.trim()) {
            event.preventDefault();
            alert('Paste a WEBP URL first.');
        }
    });

    setMode('upload');
})();
</script>