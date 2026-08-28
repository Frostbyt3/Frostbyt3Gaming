<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/registration-security.php';

requireLogin();

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function settingsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function settingsSaveSiteSetting(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $stmt->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function settingsPostedBool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function settingsPostedInt(string $key, int $default, int $min, int $max): string
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);

    if ($value === false || $value === null) {
        $value = $default;
    }

    return (string)max($min, min($max, (int)$value));
}

$message = null;
$messageType = 'success';
$currentAdminPage = 'admin-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    settingsVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_registration') {
        $value = isset($_POST['allow_public_registration']) ? '1' : '0';
        settingsSaveSiteSetting('allow_public_registration', $value);

        fbgResetSettingsCache();

        $message = 'Registration settings updated.';
        $messageType = 'success';
    }

    if ($action === 'update_registration_security') {
        $trustedProxies = trim((string)($_POST['registration_trusted_proxies'] ?? ''));

        $settings = [
            'registration_honeypot_enabled' => settingsPostedBool('registration_honeypot_enabled'),
            'registration_timing_enabled' => settingsPostedBool('registration_timing_enabled'),
            'registration_minimum_time_seconds' => settingsPostedInt('registration_minimum_time_seconds', 3, 0, 3600),
            'registration_rate_limit_enabled' => settingsPostedBool('registration_rate_limit_enabled'),
            'registration_rate_limit_max_attempts' => settingsPostedInt('registration_rate_limit_max_attempts', 5, 1, 1000),
            'registration_rate_limit_window_seconds' => settingsPostedInt('registration_rate_limit_window_seconds', 900, 60, 86400),
            'registration_verification_expiration_hours' => settingsPostedInt('registration_verification_expiration_hours', 24, 1, 720),
            'registration_verification_resend_cooldown_seconds' => settingsPostedInt('registration_verification_resend_cooldown_seconds', 300, 0, 86400),
            'registration_cleanup_enabled' => settingsPostedBool('registration_cleanup_enabled'),
            'registration_cleanup_retention_days' => settingsPostedInt('registration_cleanup_retention_days', 14, 0, 3650),
            'registration_trusted_proxies' => $trustedProxies,
        ];

        foreach ($settings as $key => $value) {
            settingsSaveSiteSetting($key, $value);
        }

        fbgResetSettingsCache();

        $message = 'Registration security settings updated.';
        $messageType = 'success';
    }

    if ($action === 'update_maintenance') {
        $mode = isset($_POST['maintenance_mode']) ? '1' : '0';
        $messageText = trim((string)($_POST['maintenance_message'] ?? ''));

        if ($messageText === '') {
            $messageText = 'We are currently performing maintenance. Please check back shortly.';
            $message = 'Maintenance settings updated. The default maintenance message was used.';
            $messageType = 'warning';
        } else {
            $message = 'Maintenance settings updated.';
            $messageType = 'success';
        }

        $stmt = db()->prepare("
            INSERT INTO site_settings (setting_key, setting_value)
            VALUES (:setting_key_1, :setting_value_1),
                (:setting_key_2, :setting_value_2)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        $stmt->execute([
            'setting_key_1'   => 'maintenance_mode',
            'setting_value_1' => $mode,
            'setting_key_2'   => 'maintenance_message',
            'setting_value_2' => $messageText,
        ]);

        fbgResetSettingsCache();
    }
}
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Site Settings</h1>
                <p class="fbg-admin-subtext">Adjust site-wide settings, configuration, and future integrations.</p>
            </div>
        </header>

        <?php if ($message !== null): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Settings',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Registration</h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <input type="hidden" name="action" value="update_registration">

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input
                                type="checkbox"
                                name="allow_public_registration"
                                value="1"
                                <?= (int)fbgGetSetting('allow_public_registration', 1) === 1 ? 'checked' : '' ?>
                            >
                            <span>Allow Public Registration</span>
                        </label>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Save Registration Settings</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Maintenance Mode</h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_maintenance">

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="maintenance_mode" value="1" <?= (int)fbgGetSetting('maintenance_mode', 0) === 1 ? 'checked' : '' ?>>
                            <span>Enable Maintenance Mode</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="maintenance_message">Maintenance Message</label>
                        <textarea id="maintenance_message" name="maintenance_message" rows="4"><?= htmlspecialchars((string)fbgGetSetting('maintenance_message', 'We are currently performing maintenance. Please check back shortly.'), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Save Maintenance Settings</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-wide">
                <div class="fbg-admin-panel-header">
                    <h2>Registration Security</h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_registration_security">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox">
                                <input type="checkbox" name="registration_honeypot_enabled" value="1" <?= fbgRegistrationSettingBool('registration_honeypot_enabled', true) ? 'checked' : '' ?>>
                                <span>Enable Honeypot</span>
                            </label>
                        </div>

                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox">
                                <input type="checkbox" name="registration_timing_enabled" value="1" <?= fbgRegistrationSettingBool('registration_timing_enabled', true) ? 'checked' : '' ?>>
                                <span>Enable Timing Check</span>
                            </label>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_minimum_time_seconds">Minimum Submit Time</label>
                            <input id="registration_minimum_time_seconds" type="number" name="registration_minimum_time_seconds" min="0" max="3600" value="<?= fbgRegistrationSettingInt('registration_minimum_time_seconds', 3, 0, 3600) ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox">
                                <input type="checkbox" name="registration_rate_limit_enabled" value="1" <?= fbgRegistrationSettingBool('registration_rate_limit_enabled', true) ? 'checked' : '' ?>>
                                <span>Enable Rate Limit</span>
                            </label>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_rate_limit_max_attempts">Max Attempts</label>
                            <input id="registration_rate_limit_max_attempts" type="number" name="registration_rate_limit_max_attempts" min="1" max="1000" value="<?= fbgRegistrationSettingInt('registration_rate_limit_max_attempts', 5, 1, 1000) ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_rate_limit_window_seconds">Rate Limit Window</label>
                            <input id="registration_rate_limit_window_seconds" type="number" name="registration_rate_limit_window_seconds" min="60" max="86400" value="<?= fbgRegistrationSettingInt('registration_rate_limit_window_seconds', 900, 60, 86400) ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_verification_expiration_hours">Verification Expiration</label>
                            <input id="registration_verification_expiration_hours" type="number" name="registration_verification_expiration_hours" min="1" max="720" value="<?= fbgRegistrationVerificationExpiryHours() ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_verification_resend_cooldown_seconds">Resend Cooldown</label>
                            <input id="registration_verification_resend_cooldown_seconds" type="number" name="registration_verification_resend_cooldown_seconds" min="0" max="86400" value="<?= fbgRegistrationResendCooldownSeconds() ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox">
                                <input type="checkbox" name="registration_cleanup_enabled" value="1" <?= fbgRegistrationSettingBool('registration_cleanup_enabled', true) ? 'checked' : '' ?>>
                                <span>Enable Retention Cleanup</span>
                            </label>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration_cleanup_retention_days">Retention Days</label>
                            <input id="registration_cleanup_retention_days" type="number" name="registration_cleanup_retention_days" min="0" max="3650" value="<?= fbgRegistrationRetentionDays() ?>">
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="registration_trusted_proxies">Trusted Proxy CIDRs</label>
                            <textarea id="registration_trusted_proxies" name="registration_trusted_proxies" rows="4" placeholder="One IP or CIDR per line"><?= htmlspecialchars(fbgRegistrationSettingString('registration_trusted_proxies', ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Save Security Settings</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Public Registration</span>
                        <strong><?= (int)fbgGetSetting('allow_public_registration', 1) === 1 ? 'Enabled' : 'Disabled' ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Maintenance Mode</span>
                        <strong><?= (int)fbgGetSetting('maintenance_mode', 1) === 1 ? 'Enabled' : 'Disabled' ?></strong>
                        <span class="fbg-admin-stat-label">Maintenance Message:</span>
                        <strong><?= htmlspecialchars((string)fbgGetSetting('maintenance_message', 'We are currently performing maintenance. Please check back shortly.'), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Settings Area</span>
                        <strong>Site Configuration</strong>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>