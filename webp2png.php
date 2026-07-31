<?php
// IMPORTANT: Make sure there is NO whitespace before <?php in this file.

function asset($path) {
    $full = $_SERVER['DOCUMENT_ROOT'] . $path;
    return $path . '?v=' . (file_exists($full) ? filemtime($full) : time());
}

$errorMsg = '';
$successMsg = '';

// ---------------- Convert (POST) ----------------
// ---------------- Convert (POST) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mode     = $_POST['mode'] ?? 'upload';
    $maxBytes = 20 * 1024 * 1024;

    $tmpPath  = '';
    $origName = 'converted.webp';
    $tmpFile  = null; // URL temp file path for cleanup

    // Capability checks (apply to both modes)
    if (!function_exists('imagecreatefromwebp')) {
        $errorMsg = "Server does not support WEBP conversion (GD WEBP missing).";
    }

    // ---------- Acquire WEBP input (Upload or URL) ----------
    if (empty($errorMsg)) {

        if ($mode === 'url') {

            $url = trim((string)($_POST['webp_url'] ?? ''));

            if ($url === '') {
                $errorMsg = "Paste a WEBP URL first.";
            } elseif (!preg_match('#^https?://#i', $url)) {
                $errorMsg = "URL must start with http:// or https://";
            } elseif (!function_exists('curl_init')) {
                $errorMsg = "Server is missing cURL support.";
            } else {

                $tmpFile = tempnam(sys_get_temp_dir(), 'webp_');
                if ($tmpFile === false) {
                    $errorMsg = "Failed to create temp file.";
                } else {

                    $fp = fopen($tmpFile, 'wb');
                    if (!$fp) {
                        @unlink($tmpFile);
                        $tmpFile = null;
                        $errorMsg = "Failed to open temp file.";
                    } else {

                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_FILE            => $fp,
                            CURLOPT_FOLLOWLOCATION  => true,
                            CURLOPT_MAXREDIRS       => 3,
                            CURLOPT_CONNECTTIMEOUT  => 5,
                            CURLOPT_TIMEOUT         => 20,
                            CURLOPT_USERAGENT       => 'FBG-WEBP2PNG/1.0',
                            CURLOPT_FAILONERROR     => true,
                            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        ]);

                        $ok       = curl_exec($ch);
                        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                        $curlErr  = curl_error($ch);
                        curl_close($ch);
                        fclose($fp);

                        if (!$ok || $httpCode < 200 || $httpCode >= 300) {
                            @unlink($tmpFile);
                            $tmpFile = null;
                            $errorMsg = "Failed to fetch URL (HTTP {$httpCode})." . ($curlErr ? " cURL: {$curlErr}" : "");
                        } else {

                            $downloadedSize = filesize($tmpFile);
                            if ($downloadedSize === false || $downloadedSize <= 0) {
                                @unlink($tmpFile);
                                $tmpFile = null;
                                $errorMsg = "Downloaded file is empty.";
                            } elseif ($downloadedSize > $maxBytes) {
                                @unlink($tmpFile);
                                $tmpFile = null;
                                $errorMsg = "Downloaded file is too large (max 20 MB).";
                            } else {
                                $tmpPath = $tmpFile;

                                // Derive filename for the download prompt
                                $pathPart = parse_url($url, PHP_URL_PATH);
                                $base = $pathPart ? basename($pathPart) : 'converted.webp';
                                $origName = $base ?: 'converted.webp';
                            }
                        }
                    }
                }
            }

        } else {
            // ---------- Upload mode ----------
            if (!isset($_FILES['webp'])) {
                $errorMsg = "Select a WEBP file first.";
            } else {

                $err = (int)$_FILES['webp']['error'];
                if ($err !== UPLOAD_ERR_OK) {

                    $errors = [
                        UPLOAD_ERR_INI_SIZE   => "File exceeds PHP upload_max_filesize.",
                        UPLOAD_ERR_FORM_SIZE  => "File exceeds form size limit.",
                        UPLOAD_ERR_PARTIAL    => "File only partially uploaded.",
                        UPLOAD_ERR_NO_FILE    => "Select a WEBP file first.",
                        UPLOAD_ERR_NO_TMP_DIR => "Server missing temp folder (upload_tmp_dir).",
                        UPLOAD_ERR_CANT_WRITE => "Server failed writing file to disk (permissions).",
                        UPLOAD_ERR_EXTENSION  => "Upload blocked by a PHP extension.",
                    ];

                    $errorMsg = $errors[$err] ?? "Upload failed (PHP error {$err}).";

                } else {

                    $tmpPath  = $_FILES['webp']['tmp_name'] ?? '';
                    $origName = (string)($_FILES['webp']['name'] ?? 'converted.webp');
                    $fileSize = (int)($_FILES['webp']['size'] ?? 0);

                    if (!$tmpPath || !is_uploaded_file($tmpPath)) {
                        $errorMsg = "Invalid upload (temp file missing).";
                    } elseif ($fileSize <= 0) {
                        $errorMsg = "Uploaded file is empty.";
                    } elseif ($fileSize > $maxBytes) {
                        $errorMsg = "File too large (max 20 MB).";
                    }
                }
            }
        }
    }

    // ---------- Convert if we have a temp path ----------
    if (empty($errorMsg) && $tmpPath !== '') {

        // Sniff MIME (trust this more than extension/content-type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);

        if ($mime !== 'image/webp') {
            if ($tmpFile) @unlink($tmpFile);
            $errorMsg = "That doesn't look like a WEBP file (detected: {$mime}).";
        } else {

            $im = @imagecreatefromwebp($tmpPath);
            if (!$im) {
                if ($tmpFile) @unlink($tmpFile);
                $errorMsg = "Failed to decode WEBP (file may be corrupted).";
            } else {

                // If URL mode, remove temp file once decoded successfully
                if ($tmpFile) {
                    @unlink($tmpFile);
                    $tmpFile = null;
                }

                // Preserve alpha
                imagesavealpha($im, true);
                imagealphablending($im, false);

                // Sanitize filename
                $baseName = pathinfo($origName, PATHINFO_FILENAME);
                $baseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);
                $baseName = trim($baseName, '_-');
                if ($baseName === '') $baseName = 'converted';

                // Prevent any buffered output corrupting the PNG
                while (ob_get_level()) { ob_end_clean(); }

                // Stream PNG download
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="' . $baseName . '.png"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');

                imagepng($im, null, 6);
                imagedestroy($im);
                exit;
            }
        }
    }

    // If we reach here, $errorMsg is set and the page renders normally.
}
// ---------------- Styled Page (GET) ----------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?php echo asset("./backend/css/style.css"); ?>">
    <title>FBG WEBP → PNG</title>
</head>
<body>
<main>
    <section class="hero">
        <div class="linksmain">
            <div class="subpagetitle">
                <div class="hero-content">
                    <h1>FBG WEBP → PNG CONVERSION TOOL</h1>
                </div>
            </div>

           <section class="webpform">
            <div class="webpcard">

                <form method="POST" enctype="multipart/form-data">
                    <div class="fbg-filepicker">

                        <!-- Mode toggle -->
                        <div class="fbg-filepicker-row">
                            <label style="display:flex; gap:10px; align-items:center; cursor:pointer;">
                            <input type="radio" name="mode" value="upload" checked>
                            <span>Upload</span>
                            </label>

                            <label style="display:flex; gap:10px; align-items:center; cursor:pointer;">
                            <input type="radio" name="mode" value="url">
                            <span>From URL</span>
                            </label>
                        </div>

                        <!-- Upload picker -->
                        <div id="uploadBlock">
                            <div class="fbg-file-hint"><p>Upload a .webp file — converts to .png and downloads.</p></div>
                            <input id="webpInput" type="file" name="webp" accept="image/webp">

                            <div class="fbg-filepicker-row">
                            <button type="button" class="btn" id="pickWebpBtn">
                                <i class="fas fa-file-image"></i> Select WEBP
                            </button>

                            <div id="fileName" class="fbg-file-name is-empty" title="No file selected">
                                No file selected
                            </div>
                            </div>
                        </div>

                        <!-- URL input -->
                        <div id="urlBlock" style="display:none;">
                            <div class="fbg-file-hint"><p>Paste a direct .webp URL — converts to .png and downloads.</p></div>
                            <div class="fbg-filepicker-row">
                            <input
                                type="url"
                                name="webp_url"
                                id="webpUrl"
                                placeholder="https://example.com/image.webp"
                                style="background-color:#212121; border:2px solid #424242; border-radius:6px; color:#eee; padding:10px; width:520px; max-width:90vw;"
                            >
                            </div>
                        </div>

                        </div>

                        <?php if (!empty($errorMsg)): ?>
                            <p style="color:#ff3b3b; font-weight:bold; margin: 0 0 1rem 0;">
                                <?php echo htmlspecialchars($errorMsg); ?>
                            </p>
                        <?php endif; ?>

                    <p>
                        <button type="submit" class="btn">
                            <i class="fas fa-right-left"></i> Convert &amp; Download
                        </button>
                    </p>
                </form>

            </div>
        </section>
        </div>
    </section>
</main>
<script>
    (() => {
        const input = document.getElementById('webpInput');
        const btn = document.getElementById('pickWebpBtn');
        const fileName = document.getElementById('fileName');
        const uploadBlock = document.getElementById('uploadBlock');
        const urlBlock = document.getElementById('urlBlock');
        const urlInput = document.getElementById('webpUrl');
        const radios = document.querySelectorAll('input[name="mode"]');
        const form = document.querySelector('form');

        function setMode(mode) {
            if (mode === 'url') {
            uploadBlock.style.display = 'none';
            urlBlock.style.display = 'block';
            if (input) input.value = '';
            if (fileName) {
                fileName.textContent = "No file selected";
                fileName.classList.add('is-empty');
                fileName.title = "No file selected";
            }
            if (urlInput) urlInput.focus();
            } else {
            uploadBlock.style.display = 'block';
            urlBlock.style.display = 'none';
            if (urlInput) urlInput.value = '';
            }
        }

        radios.forEach(r => r.addEventListener('change', () => setMode(r.value)));

        if (btn && input) btn.addEventListener('click', () => input.click());
        if (fileName && input) fileName.addEventListener('click', () => input.click());

        if (input && fileName) {
            input.addEventListener('change', () => {
            const f = input.files && input.files[0] ? input.files[0] : null;
            if (!f) {
                fileName.textContent = "No file selected";
                fileName.classList.add('is-empty');
                fileName.title = "No file selected";
                return;
            }
            fileName.textContent = f.name;
            fileName.classList.remove('is-empty');
            fileName.title = f.name;
            });
        }

        if (form) {
            form.addEventListener('submit', (e) => {
            const mode = document.querySelector('input[name="mode"]:checked')?.value || 'upload';

            if (mode === 'upload') {
                if (!input || !input.files || !input.files[0]) {
                e.preventDefault();
                alert("Select a WEBP file first.");
                }
            } else {
                if (!urlInput || !urlInput.value.trim()) {
                e.preventDefault();
                alert("Paste a WEBP URL first.");
                }
            }
            });
        }

        setMode('upload');
    })();
</script>
</body>
</html>