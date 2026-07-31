<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

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

$message = '';
$messageType = 'success';
$currentAdminPage = 'admin-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    settingsVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'update_registration') {
        $value = isset($_POST['allow_public_registration']) ? '1' : '0';

        $stmt = db()->prepare("
            INSERT INTO site_settings (setting_key, setting_value)
            VALUES ('allow_public_registration', :insert_value)
            ON DUPLICATE KEY UPDATE setting_value = :update_value
        ");

        $stmt->execute([
            'insert_value' => $value,
            'update_value' => $value,
        ]);

        fbgResetSettingsCache();

        $message = 'Registration settings updated.';
        $messageType = 'success';
    }

    if ($action === 'update_maintenance') {
        $mode = isset($_POST['maintenance_mode']) ? '1' : '0';
        $messageText = trim((string)($_POST['maintenance_message'] ?? ''));

        if ($messageText === '') {
            $messageText = 'We are currently performing maintenance. Please check back shortly.';
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

        $message = 'Maintenance settings updated.';
        $messageType = 'success';
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

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
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