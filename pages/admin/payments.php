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

$message = '';
$messageType = 'success';
$currentAdminPage = 'admin-payments';

function fbgAdminPaymentsPostedBool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function fbgAdminPaymentsSaveSecret(string $settingKey, string $postedValue): void
{
    $postedValue = trim($postedValue);

    if ($postedValue !== '') {
        fbgSetShopSetting($settingKey, $postedValue);
    }
}

function fbgAdminPaymentsSaveSiteSetting(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? 'save_settings');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        $message = 'Security check failed. Please refresh and try again.';
        $messageType = 'error';
    } else {
        try {
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            $minAmount = round((float)($_POST['min_amount'] ?? 0), 2);
            $maxAmount = round((float)($_POST['max_amount'] ?? 100), 2);
            $stripeMode = (string)($_POST['stripe_mode'] ?? 'live');
            $paypalMode = (string)($_POST['paypal_mode'] ?? 'live');
            $deleteDays = (int)($_POST['delete_days'] ?? 0);
            $tosUrl = trim((string)($_POST['tos_url'] ?? ''));
            $tosContent = (string)($_POST['tos_content'] ?? '');
            $receiptTax = round((float)($_POST['receipt_tax'] ?? 0), 2);

            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new RuntimeException('Currency must be a 3-letter currency code.');
            }

            if ($minAmount < 0 || $maxAmount < 0 || ($maxAmount > 0 && $minAmount > $maxAmount)) {
                throw new RuntimeException('Deposit amount limits are invalid.');
            }

            if (!in_array($stripeMode, ['live', 'sandbox'], true)) {
                throw new RuntimeException('Stripe mode is invalid.');
            }

            if (!in_array($paypalMode, ['live', 'sandbox'], true)) {
                throw new RuntimeException('PayPal mode is invalid.');
            }

            if ($deleteDays < 0) {
                throw new RuntimeException('Delete Days must be 0 or greater.');
            }

            if ($tosUrl !== '' && !filter_var($tosUrl, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Terms of Service URL must be a valid URL.');
            }

            if ($receiptTax < 0) {
                throw new RuntimeException('Server rental tax rate must be 0 or greater.');
            }

            fbgSetShopSetting('settings::shop::currency', $currency);
            fbgSetShopSetting('settings::shop::min_amount', number_format($minAmount, 2, '.', ''));
            fbgSetShopSetting('settings::shop::max_amount', number_format($maxAmount, 2, '.', ''));
            fbgSetShopSetting('settings::shop::servers::days', (string)$deleteDays);
            fbgSetShopSetting('settings::shop::tos_url', $tosUrl);
            fbgSetShopSetting('settings::shop::tos', $tosContent);

            fbgSetShopSetting('settings::shop::stripe::enabled', fbgAdminPaymentsPostedBool('stripe_enabled'));
            fbgSetShopSetting('settings::shop::stripe::mode', $stripeMode);
            fbgAdminPaymentsSaveSecret('settings::shop::stripe::key', (string)($_POST['stripe_key'] ?? ''));
            fbgAdminPaymentsSaveSecret('settings::shop::stripe::secret', (string)($_POST['stripe_secret'] ?? ''));

            fbgSetShopSetting('settings::shop::paypal::enabled', fbgAdminPaymentsPostedBool('paypal_enabled'));
            fbgSetShopSetting('settings::shop::paypal::mode', $paypalMode);
            fbgAdminPaymentsSaveSecret('settings::shop::paypal::key', (string)($_POST['paypal_key'] ?? ''));
            fbgAdminPaymentsSaveSecret('settings::shop::paypal::secret', (string)($_POST['paypal_secret'] ?? ''));

            fbgAdminPaymentsSaveSiteSetting('fbg_receipt_tax_rate', number_format($receiptTax, 2, '.', ''));
            fbgResetSettingsCache();

            $message = 'Shop settings updated.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Shop settings could not be saved.';
            $messageType = 'error';
        }
    }
}

$settings = fbgGetShopPaymentSettings();
$currency = (string)$settings['currency'];
$minAmount = (float)$settings['min_amount'];
$maxAmount = (float)$settings['max_amount'];
$deleteDays = (int)fbgGetShopSetting('settings::shop::servers::days', '0');
$tosUrl = fbgGetShopSetting('settings::shop::tos_url', '');
$tosContent = fbgGetShopSetting('settings::shop::tos', '');
$serverRentalTaxRate = fbgGetShopTaxRate();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Payment Settings</h1>
                <p class="fbg-admin-subtext">Configure balance uploads, server lifecycle behavior, and shop terms.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Payments',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <form method="POST" class="fbg-admin-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_settings">

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Global Settings</h2>
                </div>

                <div class="fbg-admin-field">
                    <label for="payment-currency">Currency</label>
                    <input id="payment-currency" name="currency" type="text" maxlength="3" value="<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="fbg-admin-field">
                    <label for="payment-min">Minimum Deposit Amount</label>
                    <input id="payment-min" name="min_amount" type="number" min="0" step="0.01" value="<?= htmlspecialchars(number_format($minAmount, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="fbg-admin-field">
                    <label for="payment-max">Maximum Deposit Amount</label>
                    <input id="payment-max" name="max_amount" type="number" min="0" step="0.01" value="<?= htmlspecialchars(number_format($maxAmount, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Server Settings</h2>
                </div>

                <div class="fbg-admin-field">
                    <label for="server-delete-days">Delete Days</label>
                    <input id="server-delete-days" name="delete_days" type="number" min="0" step="1" value="<?= htmlspecialchars((string)$deleteDays, ENT_QUOTES, 'UTF-8') ?>">
                    <p class="fbg-admin-help-text">
                        Servers are deleted this many days after expiration. Use 0 to delete immediately after expiration.
                    </p>
                </div>

                <div class="fbg-admin-field">
                    <label for="server-rental-tax">Server Rental Tax Rate</label>
                    <input id="server-rental-tax" name="receipt_tax" type="number" min="0" step="0.01" value="<?= htmlspecialchars(number_format($serverRentalTaxRate, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    <p class="fbg-admin-help-text">
                        Applied to server rentals and renewals. Balance uploads are not taxed.
                    </p>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-wide">
                <div class="fbg-admin-panel-header">
                    <h2>Stripe Settings</h2>
                </div>

                <div class="fbg-admin-field">
                    <label class="fbg-admin-checkbox">
                        <input type="checkbox" name="stripe_enabled" value="1" <?= $settings['stripe_enabled'] ? 'checked' : '' ?>>
                        <span>Enable Stripe balance uploads</span>
                    </label>
                </div>

                <div class="fbg-admin-field">
                    <label for="stripe-mode">Mode</label>
                    <select id="stripe-mode" name="stripe_mode">
                        <option value="live" <?= $settings['stripe_mode'] === 'live' ? 'selected' : '' ?>>Live</option>
                        <option value="sandbox" <?= $settings['stripe_mode'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    </select>
                </div>

                <div class="fbg-admin-field">
                    <label for="stripe-key">Publishable Key</label>
                    <input id="stripe-key" name="stripe_key" type="text" placeholder="<?= $settings['stripe_key_configured'] ? 'Configured - leave blank to keep current value' : 'Stripe publishable key' ?>">
                </div>

                <div class="fbg-admin-field">
                    <label for="stripe-secret">Secret Key</label>
                    <input id="stripe-secret" name="stripe_secret" type="password" autocomplete="new-password" placeholder="<?= $settings['stripe_secret_configured'] ? 'Configured - leave blank to keep current value' : 'Stripe secret key' ?>">
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-wide">
                <div class="fbg-admin-panel-header">
                    <h2>PayPal Settings</h2>
                </div>

                <div class="fbg-admin-field">
                    <label class="fbg-admin-checkbox">
                        <input type="checkbox" name="paypal_enabled" value="1" <?= $settings['paypal_enabled'] ? 'checked' : '' ?>>
                        <span>Enable PayPal configuration</span>
                    </label>
                </div>

                <div class="fbg-admin-field">
                    <label for="paypal-mode">Mode</label>
                    <select id="paypal-mode" name="paypal_mode">
                        <option value="live" <?= $settings['paypal_mode'] === 'live' ? 'selected' : '' ?>>Live</option>
                        <option value="sandbox" <?= $settings['paypal_mode'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    </select>
                </div>

                <div class="fbg-admin-field">
                    <label for="paypal-key">Client ID / API Key</label>
                    <input id="paypal-key" name="paypal_key" type="text" placeholder="<?= $settings['paypal_key_configured'] ? 'Configured - leave blank to keep current value' : 'PayPal client ID or API key' ?>">
                </div>

                <div class="fbg-admin-field">
                    <label for="paypal-secret">Secret</label>
                    <input id="paypal-secret" name="paypal_secret" type="password" autocomplete="new-password" placeholder="<?= $settings['paypal_secret_configured'] ? 'Configured - leave blank to keep current value' : 'PayPal secret' ?>">
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Terms of Service Settings</h2>
                </div>

                <div class="fbg-admin-field">
                    <label for="tos-url">Terms of Service URL</label>
                    <input id="tos-url" name="tos_url" type="url" value="<?= htmlspecialchars($tosUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/terms">
                    <p class="fbg-admin-help-text">
                        If a URL is set, it takes priority over the written terms below.
                    </p>
                </div>

                <div class="fbg-admin-field">
                    <label for="payment-tos-content">Written Terms of Service</label>
                    <div class="fbg-tinymce-wrap">
                        <textarea id="payment-tos-content" name="tos_content" rows="14"><?= htmlspecialchars($tosContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-form-actions">
                    <button type="submit" class="btn">Save Shop Settings</button>
                </div>
            </section>
        </form>

    </div>
</section>

<script src="https://cdn.tiny.cloud/1/xxgyxwiaqqglhni5qardovr11rmsswgfu5ahsnrtcphvyyun/tinymce/8/tinymce.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        if (!window.tinymce) return;

        tinymce.init({
            selector: "#payment-tos-content",
            height: 440,
            menubar: "file edit view insert format table help",
            plugins: "advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount help autoresize",
            toolbar: "undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code preview fullscreen | removeformat help",
            toolbar_mode: "sliding",
            resize: true,
            skin: "oxide-dark",
            content_css: "dark",
            branding: false,
            promotion: false,
            convert_urls: false,
            relative_urls: false,
            remove_script_host: false,
            content_style: [
                "html { background: #101010; }",
                "body { background: #101010; color: #f4f7fb; font-family: Inter, Arial, sans-serif; font-size: 15px; line-height: 1.6; padding: 18px 20px; }",
                "a { color: #00aeef; }",
                "h1, h2, h3, h4, h5, h6 { color: #ffffff; font-weight: 800; line-height: 1.22; }",
                "blockquote { border-left: 3px solid #00aeef; color: #cbd5df; margin-left: 0; padding-left: 16px; }",
                "code { background: #1c1c1c; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 6px; color: #ffc848; padding: 2px 5px; }",
                "table { border-collapse: collapse; width: 100%; }",
                "th, td { border: 1px solid rgba(255, 255, 255, 0.14); padding: 10px; }",
                "th { background: #181818; color: #ffffff; }"
            ].join(" ")
        });
    });
</script>
