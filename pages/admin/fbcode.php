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

                <form class="fbg-admin-form fbg-fbcode-form" data-fbg-fbcode-form enctype="multipart/form-data">
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

                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label>Pattern Style</label>
                        <div class="fbg-fbcode-style-tiles" role="radiogroup" aria-label="Pattern Style">
                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="module_style" value="square">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-pattern-preview is-square" aria-hidden="true">
                                    <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                                </span>
                                <span>Square</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="module_style" value="rounded" checked>
                                <span class="fbg-fbcode-style-preview fbg-fbcode-pattern-preview is-rounded" aria-hidden="true">
                                    <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                                </span>
                                <span>Rounded</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="module_style" value="dots">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-pattern-preview is-dots" aria-hidden="true">
                                    <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                                </span>
                                <span>Dots</span>
                            </label>
                        </div>
                        <p class="fbg-admin-help-text">Pattern Style changes the small data modules. Simpler styles are easier for older scanners.</p>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label>Eye Style</label>
                        <div class="fbg-fbcode-style-tiles" role="radiogroup" aria-label="Eye Style">
                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="eye_style" value="square" checked>
                                <span class="fbg-fbcode-style-preview fbg-fbcode-eye-preview is-square" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                </span>
                                <span>Square</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="eye_style" value="dot">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-eye-preview is-dot" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                </span>
                                <span>Round Dot</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="eye_style" value="match">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-eye-preview is-match" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                </span>
                                <span>Match</span>
                            </label>
                        </div>
                        <p class="fbg-admin-help-text">Eye Style changes the three large scanner targets using the QR-safe shapes supported by the renderer.</p>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label>Logo</label>
                        <div class="fbg-fbcode-style-tiles" role="radiogroup" aria-label="Logo">
                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="logo_mode" value="none">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-logo-preview is-none" aria-hidden="true">
                                    <span></span>
                                </span>
                                <span>No Logo</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="logo_mode" value="frostbyt3" checked>
                                <span class="fbg-fbcode-style-preview fbg-fbcode-logo-preview is-frostbyt3" aria-hidden="true">
                                    <img src="<?= htmlspecialchars(asset('/backend/img/Snowflake.png'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                                </span>
                                <span>Frostbyt3 Logo</span>
                            </label>

                            <label class="fbg-fbcode-style-tile">
                                <input type="radio" name="logo_mode" value="custom">
                                <span class="fbg-fbcode-style-preview fbg-fbcode-logo-preview is-custom" aria-hidden="true">
                                    <span></span>
                                </span>
                                <span>Custom Logo</span>
                            </label>
                        </div>
                        <p class="fbg-admin-help-text">No Logo creates a normal center-free FBCode. Logo options reserve protected space for scan reliability.</p>
                    </div>

                    <div class="fbg-admin-form-grid fbg-fbcode-logo-options" data-fbg-fbcode-logo-options>
                        <div class="fbg-admin-field">
                            <label for="fbcode-logo-scale">Logo Scale</label>
                            <input id="fbcode-logo-scale" name="logo_scale" type="range" min="0.12" max="0.30" step="0.01" value="<?= htmlspecialchars((string)$defaults['logo_scale'], ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">How large the selected logo appears in the cleared center space.</p>
                        </div>

                        <div class="fbg-admin-field" data-fbg-fbcode-custom-logo-field>
                            <label for="fbcode-logo-image">Custom Logo Image</label>
                            <input id="fbcode-logo-image" name="logo_image" type="file" accept="image/png,image/jpeg">
                            <p class="fbg-admin-help-text">Upload a square PNG/JPEG logo, max 1 MB. The image is only used for this preview or download.</p>
                        </div>
                    </div>

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

                <form class="fbg-admin-form fbg-fbcode-download-form" method="POST" action="/api/admin/fbcode-download.php" enctype="multipart/form-data" data-fbg-fbcode-download-form>
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
