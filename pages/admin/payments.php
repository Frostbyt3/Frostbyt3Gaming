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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

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

            fbgSetShopSetting('settings::shop::currency', $currency);
            fbgSetShopSetting('settings::shop::min_amount', number_format($minAmount, 2, '.', ''));
            fbgSetShopSetting('settings::shop::max_amount', number_format($maxAmount, 2, '.', ''));

            fbgSetShopSetting('settings::shop::stripe::enabled', fbgAdminPaymentsPostedBool('stripe_enabled'));
            fbgSetShopSetting('settings::shop::stripe::mode', $stripeMode);
            fbgAdminPaymentsSaveSecret('settings::shop::stripe::key', (string)($_POST['stripe_key'] ?? ''));
            fbgAdminPaymentsSaveSecret('settings::shop::stripe::secret', (string)($_POST['stripe_secret'] ?? ''));

            fbgSetShopSetting('settings::shop::paypal::enabled', fbgAdminPaymentsPostedBool('paypal_enabled'));
            fbgSetShopSetting('settings::shop::paypal::mode', $paypalMode);
            fbgAdminPaymentsSaveSecret('settings::shop::paypal::key', (string)($_POST['paypal_key'] ?? ''));
            fbgAdminPaymentsSaveSecret('settings::shop::paypal::secret', (string)($_POST['paypal_secret'] ?? ''));

            $message = 'Payment settings updated.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Payment settings could not be saved.';
            $messageType = 'error';
        }
    }
}

$settings = fbgGetShopPaymentSettings();
$currency = (string)$settings['currency'];
$minAmount = (float)$settings['min_amount'];
$maxAmount = (float)$settings['max_amount'];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Payment Settings</h1>
                <p class="fbg-admin-subtext">Configure the account balance upload providers used by the frontend shop.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="fbg-admin-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

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
                <div class="fbg-admin-form-actions">
                    <button type="submit" class="btn">Save Payment Settings</button>
                </div>
            </section>
        </form>
    </div>
</section>
