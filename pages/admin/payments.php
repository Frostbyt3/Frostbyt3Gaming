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
    } elseif ($action === 'generate_invoice') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $result = fbgAdminGenerateFrontendInvoiceForPayment($paymentId);

        if (!empty($result['ok']) && !empty($result['invoice'])) {
            $invoiceNumber = (string)($result['invoice']['invoice_number'] ?? '');
            $message = $invoiceNumber !== ''
                ? 'Invoice ' . $invoiceNumber . ' is ready.'
                : 'Invoice generated successfully.';
            $messageType = 'success';
        } else {
            $message = (string)($result['error'] ?? 'The invoice could not be generated.');
            $messageType = 'error';
        }
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
            $invoiceEnabled = fbgAdminPaymentsPostedBool('invoice_enabled');
            $invoicePrefix = trim(strip_tags((string)($_POST['invoice_prefix'] ?? 'FBG-')));
            $invoiceStartingNumber = max(1, (int)($_POST['invoice_starting_number'] ?? 1001));
            $invoiceNextNumber = max(1, (int)($_POST['invoice_next_number'] ?? $invoiceStartingNumber));
            $invoiceName = trim(strip_tags((string)($_POST['invoice_name'] ?? '')));
            $invoiceAddress = trim(strip_tags((string)($_POST['invoice_address'] ?? '')));
            $invoicePhone = trim(strip_tags((string)($_POST['invoice_phone'] ?? '')));
            $invoiceEmail = trim(strip_tags((string)($_POST['invoice_email'] ?? '')));
            $invoiceCode = trim(strip_tags((string)($_POST['invoice_code'] ?? '')));
            $invoiceVat = trim(strip_tags((string)($_POST['invoice_vat'] ?? '')));
            $invoiceTaxLabel = trim(strip_tags((string)($_POST['invoice_tax_label'] ?? 'Tax')));
            $invoiceTax = round((float)($_POST['invoice_tax'] ?? 0), 2);

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

            if ($invoiceTax < 0) {
                throw new RuntimeException('Server rental tax rate must be 0 or greater.');
            }

            if ($invoicePrefix === '' || strlen($invoicePrefix) > 24) {
                throw new RuntimeException('Invoice prefix must be between 1 and 24 characters.');
            }

            if ($invoiceStartingNumber < 1 || $invoiceNextNumber < 1) {
                throw new RuntimeException('Invoice numbers must be 1 or greater.');
            }

            if ($invoiceNextNumber < $invoiceStartingNumber) {
                throw new RuntimeException('Next invoice number cannot be lower than the starting number.');
            }

            if ($invoiceEmail !== '' && !filter_var($invoiceEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invoice email must be a valid email address.');
            }

            if ($invoiceTaxLabel === '' || strlen($invoiceTaxLabel) > 64) {
                throw new RuntimeException('Tax label must be between 1 and 64 characters.');
            }

            if ($invoiceEnabled === '1' && ($invoiceName === '' || $invoiceAddress === '' || $invoicePhone === '')) {
                throw new RuntimeException('Invoice name, address, and phone are required when invoices are enabled.');
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

            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_enabled', $invoiceEnabled);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_prefix', $invoicePrefix);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_starting_number', (string)$invoiceStartingNumber);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_next_number', (string)$invoiceNextNumber);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_name', $invoiceName);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_address', $invoiceAddress);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_phone', $invoicePhone);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_email', $invoiceEmail);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_code', $invoiceCode);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_company_vat', $invoiceVat);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_tax_label', $invoiceTaxLabel);
            fbgAdminPaymentsSaveSiteSetting('fbg_invoice_tax_rate', number_format($invoiceTax, 2, '.', ''));
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
$invoiceSettings = [
    'enabled' => (string)fbgGetSetting('fbg_invoice_enabled', '0') === '1',
    'prefix' => (string)fbgGetSetting('fbg_invoice_prefix', 'FBG-'),
    'starting_number' => max(1, (int)fbgGetSetting('fbg_invoice_starting_number', '1001')),
    'next_number' => max(1, (int)fbgGetSetting('fbg_invoice_next_number', fbgGetSetting('fbg_invoice_starting_number', '1001'))),
    'name' => (string)fbgGetSetting('fbg_invoice_company_name', ''),
    'address' => (string)fbgGetSetting('fbg_invoice_company_address', ''),
    'phone' => (string)fbgGetSetting('fbg_invoice_company_phone', ''),
    'email' => (string)fbgGetSetting('fbg_invoice_company_email', ''),
    'code' => (string)fbgGetSetting('fbg_invoice_company_code', ''),
    'vat' => (string)fbgGetSetting('fbg_invoice_company_vat', ''),
    'tax_label' => (string)fbgGetSetting('fbg_invoice_tax_label', 'Tax'),
    'tax' => fbgGetShopTaxRate(),
];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Payment Settings</h1>
                <p class="fbg-admin-subtext">Configure balance uploads, server lifecycle behavior, terms, and invoices used by the frontend shop.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
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
                    <input id="server-rental-tax" name="invoice_tax" type="number" min="0" step="0.01" value="<?= htmlspecialchars(number_format($invoiceSettings['tax'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
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
                <div class="fbg-admin-panel-header">
                    <h2>Invoice Settings</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="invoice_enabled" value="1" <?= $invoiceSettings['enabled'] ? 'checked' : '' ?>>
                            <span>Enable invoice generation</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-prefix">Invoice Prefix</label>
                        <input id="invoice-prefix" name="invoice_prefix" type="text" maxlength="24" value="<?= htmlspecialchars($invoiceSettings['prefix'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-starting-number">Starting Number</label>
                        <input id="invoice-starting-number" name="invoice_starting_number" type="number" min="1" step="1" value="<?= htmlspecialchars((string)$invoiceSettings['starting_number'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-next-number">Next Invoice Number</label>
                        <input id="invoice-next-number" name="invoice_next_number" type="number" min="1" step="1" value="<?= htmlspecialchars((string)$invoiceSettings['next_number'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="fbg-admin-help-text">
                            The next generated invoice will use this number with the configured prefix.
                        </p>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="invoice-name">Name</label>
                        <input id="invoice-name" name="invoice_name" type="text" value="<?= htmlspecialchars($invoiceSettings['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-address">Address</label>
                        <input id="invoice-address" name="invoice_address" type="text" value="<?= htmlspecialchars($invoiceSettings['address'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-phone">Phone</label>
                        <input id="invoice-phone" name="invoice_phone" type="text" value="<?= htmlspecialchars($invoiceSettings['phone'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-email">Email</label>
                        <input id="invoice-email" name="invoice_email" type="email" value="<?= htmlspecialchars($invoiceSettings['email'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-code">Code</label>
                        <input id="invoice-code" name="invoice_code" type="text" value="<?= htmlspecialchars($invoiceSettings['code'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-vat">VAT Code</label>
                        <input id="invoice-vat" name="invoice_vat" type="text" value="<?= htmlspecialchars($invoiceSettings['vat'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-tax-label">Tax Label</label>
                        <input id="invoice-tax-label" name="invoice_tax_label" type="text" maxlength="64" value="<?= htmlspecialchars($invoiceSettings['tax_label'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Manual Invoice Recovery</h2>
                </div>

                <p class="fbg-admin-help-text">
                    Generate a missing frontend invoice for a completed wallet top-up by payment ID.
                </p>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="manual-invoice-payment-id">Payment ID</label>
                        <input
                            id="manual-invoice-payment-id"
                            name="manual_invoice_payment_id"
                            type="number"
                            min="1"
                            step="1"
                            form="manual-invoice-form"
                            placeholder="Completed payment ID"
                        >
                    </div>
                </div>

                <div class="fbg-admin-form-actions">
                    <button
                        type="submit"
                        class="btn fbg-neutral-button"
                        form="manual-invoice-form"
                    >
                        Generate Invoice
                    </button>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-form-actions">
                    <button type="submit" class="btn">Save Shop Settings</button>
                </div>
            </section>
        </form>

        <form method="POST" id="manual-invoice-form" hidden>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="generate_invoice">
            <input type="hidden" name="payment_id" value="">
        </form>
    </div>
</section>

<script src="https://cdn.tiny.cloud/1/xxgyxwiaqqglhni5qardovr11rmsswgfu5ahsnrtcphvyyun/tinymce/8/tinymce.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const manualInvoiceForm = document.getElementById("manual-invoice-form");
        const manualInvoiceInput = document.getElementById("manual-invoice-payment-id");

        if (manualInvoiceForm && manualInvoiceInput) {
            manualInvoiceForm.addEventListener("submit", () => {
                const paymentIdInput = manualInvoiceForm.querySelector('input[name="payment_id"]');
                if (paymentIdInput) {
                    paymentIdInput.value = manualInvoiceInput.value;
                }
            });
        }

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
