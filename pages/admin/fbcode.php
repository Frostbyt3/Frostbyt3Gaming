<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fbcode.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$currentAdminPage = 'admin-fbcode';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$defaults = fbgCodeDefaultOptions();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main fbg-admin-fbcode-page fbg-fbcode-page">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Tools</p>
                <h1>FBCode Generator</h1>
                <p class="fbg-admin-subtext">Create branded QR-style FBCodes for links, text, and future account flows.</p>
            </div>
        </header>

        <div class="fbg-admin-grid fbg-fbcode-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <div>
                        <h2>Designer</h2>
                        <p>Enter any text or destination, then tune the generated code.</p>
                    </div>
                </div>

                <form class="fbg-admin-form fbg-fbcode-form" data-fbg-fbcode-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fbg-admin-field">
                        <label for="fbcode-content">Content or Destination</label>
                        <textarea id="fbcode-content" name="content" rows="5" maxlength="2500" required><?= htmlspecialchars((string)$defaults['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        <p class="fbg-admin-help-text">This can be a URL, plain text, setup code, or any other scannable value.</p>
                    </div>

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="fbcode-pattern-color">Pattern Color</label>
                            <input id="fbcode-pattern-color" name="pattern_color" type="color" value="<?= htmlspecialchars((string)$defaults['pattern_color'], ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">The dark modules scanners read.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="fbcode-background-color">Background Color</label>
                            <input id="fbcode-background-color" name="background_color" type="color" value="<?= htmlspecialchars((string)$defaults['background_color'], ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">Keep enough contrast for camera scanning.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="fbcode-module-style">Module Appearance</label>
                            <select id="fbcode-module-style" name="module_style">
                                <option value="rounded" selected>Rounded</option>
                                <option value="square">Square</option>
                            </select>
                            <p class="fbg-admin-help-text">Rounded modules feel more branded; square is the safest classic style.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="fbcode-size">Output Size</label>
                            <input id="fbcode-size" name="size" type="number" min="192" max="1600" step="16" value="<?= (int)$defaults['size'] ?>">
                            <p class="fbg-admin-help-text">Pixel size used for downloaded PNGs.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="fbcode-format">Download Format</label>
                            <select id="fbcode-format" name="format">
                                <option value="svg" selected>SVG</option>
                                <option value="png">PNG</option>
                            </select>
                            <p class="fbg-admin-help-text">SVG is best for web and print scaling. PNG is best for quick sharing.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="fbcode-logo-scale">Logo Scale</label>
                            <input id="fbcode-logo-scale" name="logo_scale" type="range" min="0.12" max="0.30" step="0.01" value="<?= htmlspecialchars((string)$defaults['logo_scale'], ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">How large the Frostbyt3 snowflake appears in the cleared center space.</p>
                        </div>
                    </div>

                    <label class="fbg-admin-check-row">
                        <input type="checkbox" name="logo_enabled" value="1" checked>
                        <span>Use Frostbyt3 snowflake logo</span>
                    </label>

                    <details class="fbg-fbcode-advanced">
                        <summary>Advanced Settings</summary>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="fbcode-ecc-level">Error Correction</label>
                                <select id="fbcode-ecc-level" name="ecc_level">
                                    <option value="L">Low</option>
                                    <option value="M">Medium</option>
                                    <option value="Q">Quartile</option>
                                    <option value="H" selected>High</option>
                                </select>
                                <p class="fbg-admin-help-text">Higher correction helps codes survive logos, print noise, and small damage.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="fbcode-quiet-zone">Quiet Zone</label>
                                <input id="fbcode-quiet-zone" name="quiet_zone" type="number" min="0" max="12" step="1" value="<?= (int)$defaults['quiet_zone'] ?>">
                                <p class="fbg-admin-help-text">Blank space around the code. Most scanners prefer at least 4.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="fbcode-finder-style">Finder Pattern Style</label>
                                <select id="fbcode-finder-style" name="finder_style">
                                    <option value="square" selected>Keep Corners Square</option>
                                    <option value="match">Match Module Style</option>
                                </select>
                                <p class="fbg-admin-help-text">Square corners are easier for scanners to locate.</p>
                            </div>
                        </div>

                        <label class="fbg-admin-check-row">
                            <input type="checkbox" name="draw_light_modules" value="1" checked>
                            <span>Draw background modules</span>
                        </label>

                        <label class="fbg-admin-check-row">
                            <input type="checkbox" name="connect_paths" value="1" checked>
                            <span>Connect SVG paths</span>
                        </label>
                    </details>

                    <div class="fbg-admin-form-actions">
                        <button type="button" class="btn btn-sm fbg-neutral-button" data-fbg-fbcode-reset>Reset Defaults</button>
                        <button type="submit" class="btn btn-sm">Refresh Preview</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel fbg-fbcode-preview-card">
                <div class="fbg-admin-panel-header">
                    <div>
                        <h2>Preview</h2>
                        <p>Live preview updates after a short pause while typing.</p>
                    </div>
                </div>

                <div class="fbg-fbcode-preview" data-fbg-fbcode-preview>
                    <div class="fbg-fbcode-preview-placeholder">Generating preview...</div>
                </div>

                <div class="fbg-fbcode-warning" data-fbg-fbcode-warning hidden></div>

                <form class="fbg-admin-form fbg-fbcode-download-form" method="POST" action="/api/admin/fbcode-download.php" data-fbg-fbcode-download-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div data-fbg-fbcode-download-fields></div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Download FBCode</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</section>

<script>
    window.FBGCodeDefaults = <?= json_encode($defaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= htmlspecialchars(asset('/backend/js/fbcode-generator.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
