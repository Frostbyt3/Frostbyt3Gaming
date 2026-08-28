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
$currentAdminPage = 'admin-invoice-settings';

function fbgAdminInvoiceSettingsPostedBool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function fbgAdminInvoiceSettingsSaveSiteSetting(string $key, string $value): void
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
            $invoiceEnabled = fbgAdminInvoiceSettingsPostedBool('invoice_enabled');
            $invoiceEmailEnabled = fbgAdminInvoiceSettingsPostedBool('invoice_email_enabled');
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
            $invoiceMailFromName = trim(strip_tags((string)($_POST['invoice_mail_from_name'] ?? '')));
            $invoiceMailFromEmail = trim(strip_tags((string)($_POST['invoice_mail_from_email'] ?? '')));
            $invoiceMailReplyToName = trim(strip_tags((string)($_POST['invoice_mail_reply_to_name'] ?? '')));
            $invoiceMailReplyToEmail = trim(strip_tags((string)($_POST['invoice_mail_reply_to_email'] ?? '')));

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

            if ($invoiceMailFromEmail !== '' && !filter_var($invoiceMailFromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invoice From email must be a valid email address.');
            }

            if ($invoiceMailReplyToEmail !== '' && !filter_var($invoiceMailReplyToEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invoice Reply-To email must be a valid email address.');
            }

            if ($invoiceMailReplyToName !== '' && $invoiceMailReplyToEmail === '') {
                throw new RuntimeException('Reply-To email is required when a Reply-To name is set.');
            }

            if ($invoiceEnabled === '1' && ($invoiceName === '' || $invoiceAddress === '' || $invoicePhone === '')) {
                throw new RuntimeException('Invoice name, address, and phone are required when invoices are enabled.');
            }

            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_enabled', $invoiceEnabled);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_email_enabled', $invoiceEmailEnabled);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_prefix', $invoicePrefix);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_starting_number', (string)$invoiceStartingNumber);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_next_number', (string)$invoiceNextNumber);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_name', $invoiceName);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_address', $invoiceAddress);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_phone', $invoicePhone);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_email', $invoiceEmail);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_code', $invoiceCode);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_company_vat', $invoiceVat);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_tax_label', $invoiceTaxLabel);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_mail_from_name', $invoiceMailFromName);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_mail_from_email', $invoiceMailFromEmail);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_mail_reply_to_name', $invoiceMailReplyToName);
            fbgAdminInvoiceSettingsSaveSiteSetting('fbg_invoice_mail_reply_to_email', $invoiceMailReplyToEmail);
            fbgResetSettingsCache();

            $message = 'Invoice settings updated.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Invoice settings could not be saved.';
            $messageType = 'error';
        }
    }
}

$invoiceSettings = [
    'enabled' => (string)fbgGetSetting('fbg_invoice_enabled', '0') === '1',
    'email_enabled' => (string)fbgGetSetting('fbg_invoice_email_enabled', '1') === '1',
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
    'mail_from_name' => (string)fbgGetSetting('fbg_invoice_mail_from_name', ''),
    'mail_from_email' => (string)fbgGetSetting('fbg_invoice_mail_from_email', ''),
    'mail_reply_to_name' => (string)fbgGetSetting('fbg_invoice_mail_reply_to_name', ''),
    'mail_reply_to_email' => (string)fbgGetSetting('fbg_invoice_mail_reply_to_email', ''),
];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main fbg-admin-invoice-settings-page">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Invoice</p>
                <h1>Invoice Settings</h1>
                <p class="fbg-admin-subtext">Configure invoice numbering, company details, email delivery, and recovery tools.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Invoice Settings',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <form id="invoice-settings-form" method="POST" class="fbg-admin-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_settings">

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Invoice Settings</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="invoice_enabled" value="1" <?= $invoiceSettings['enabled'] ? 'checked' : '' ?>>
                            <span>Enable invoice generation</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="invoice_email_enabled" value="1" <?= $invoiceSettings['email_enabled'] ? 'checked' : '' ?>>
                            <span>Send invoice emails</span>
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

                    <div class="fbg-admin-field">
                        <label for="invoice-tax-label">Tax Label</label>
                        <input id="invoice-tax-label" name="invoice_tax_label" type="text" maxlength="64" value="<?= htmlspecialchars($invoiceSettings['tax_label'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Email Delivery</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="invoice-mail-from-name">From Name</label>
                        <input
                            id="invoice-mail-from-name"
                            name="invoice_mail_from_name"
                            type="text"
                            placeholder="<?= defined('SMTP_FROM_NAME') ? htmlspecialchars((string)SMTP_FROM_NAME, ENT_QUOTES, 'UTF-8') : 'Frostbyt3 Gaming' ?>"
                            value="<?= htmlspecialchars($invoiceSettings['mail_from_name'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Leave blank to use the default mail sender name.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-mail-from-email">From Email</label>
                        <input
                            id="invoice-mail-from-email"
                            name="invoice_mail_from_email"
                            type="email"
                            placeholder="<?= defined('SMTP_FROM_EMAIL') ? htmlspecialchars((string)SMTP_FROM_EMAIL, ENT_QUOTES, 'UTF-8') : 'billing@example.com' ?>"
                            value="<?= htmlspecialchars($invoiceSettings['mail_from_email'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Leave blank to use the default mail sender address.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-mail-reply-to-name">Reply-To Name</label>
                        <input
                            id="invoice-mail-reply-to-name"
                            name="invoice_mail_reply_to_name"
                            type="text"
                            value="<?= htmlspecialchars($invoiceSettings['mail_reply_to_name'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-mail-reply-to-email">Reply-To Email</label>
                        <input
                            id="invoice-mail-reply-to-email"
                            name="invoice_mail_reply_to_email"
                            type="email"
                            value="<?= htmlspecialchars($invoiceSettings['mail_reply_to_email'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Replies to invoice emails will go here when set.</p>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Company Details</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="invoice-name">Name</label>
                        <input id="invoice-name" name="invoice_name" type="text" value="<?= htmlspecialchars($invoiceSettings['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
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
                </div>
            </section>

        </form>

        <section class="fbg-admin-panel fbg-admin-panel-full">
            <div class="fbg-admin-panel-header">
                <h2>Manual Invoice Recovery</h2>
            </div>

            <p class="fbg-admin-help-text">
                Generate a missing invoice for a completed wallet top-up by payment ID.
            </p>

            <form method="POST" class="fbg-admin-form-grid">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="generate_invoice">

                <div class="fbg-admin-field">
                    <label for="manual-invoice-payment-id">Payment ID</label>
                    <input
                        id="manual-invoice-payment-id"
                        name="payment_id"
                        type="number"
                        min="1"
                        step="1"
                        placeholder="Completed payment ID"
                    >
                </div>

                <div class="fbg-admin-form-actions">
                    <button type="submit" class="btn fbg-neutral-button">
                        Generate Invoice
                    </button>
                </div>
            </form>
        </section>

        <section class="fbg-admin-panel fbg-admin-panel-full">
            <div class="fbg-admin-form-actions">
                <button type="submit" form="invoice-settings-form" class="btn">Save Invoice Settings</button>
            </div>
        </section>
    </div>
</section>
