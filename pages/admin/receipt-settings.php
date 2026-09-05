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
$currentAdminPage = 'admin-receipt-settings';

function fbgAdminReceiptSettingsPostedBool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function fbgAdminReceiptSettingsSaveSiteSetting(string $key, string $value): void
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
    } elseif ($action === 'generate_receipt') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $result = fbgAdminGenerateFrontendReceiptForPayment($paymentId);

        if (!empty($result['ok']) && !empty($result['receipt'])) {
            $receiptNumber = (string)($result['receipt']['receipt_number'] ?? '');
            $message = $receiptNumber !== ''
                ? 'Receipt ' . $receiptNumber . ' is ready.'
                : 'Receipt generated successfully.';
            $messageType = 'success';
        } else {
            $message = (string)($result['error'] ?? 'The receipt could not be generated.');
            $messageType = 'error';
        }
    } else {
        try {
            $receiptEnabled = fbgAdminReceiptSettingsPostedBool('receipt_enabled');
            $receiptEmailEnabled = fbgAdminReceiptSettingsPostedBool('receipt_email_enabled');
            $receiptPrefix = trim(strip_tags((string)($_POST['receipt_prefix'] ?? 'FBG-')));
            $receiptStartingNumber = max(1, (int)($_POST['receipt_starting_number'] ?? 1001));
            $receiptNextNumber = max(1, (int)($_POST['receipt_next_number'] ?? $receiptStartingNumber));
            $receiptName = trim(strip_tags((string)($_POST['receipt_name'] ?? '')));
            $receiptAddress = trim(strip_tags((string)($_POST['receipt_address'] ?? '')));
            $receiptPhone = trim(strip_tags((string)($_POST['receipt_phone'] ?? '')));
            $receiptEmail = trim(strip_tags((string)($_POST['receipt_email'] ?? '')));
            $receiptCode = trim(strip_tags((string)($_POST['receipt_code'] ?? '')));
            $receiptVat = trim(strip_tags((string)($_POST['receipt_vat'] ?? '')));
            $receiptTaxLabel = trim(strip_tags((string)($_POST['receipt_tax_label'] ?? 'Tax')));
            $receiptMailFromName = trim(strip_tags((string)($_POST['receipt_mail_from_name'] ?? '')));
            $receiptMailFromEmail = trim(strip_tags((string)($_POST['receipt_mail_from_email'] ?? '')));
            $receiptMailReplyToName = trim(strip_tags((string)($_POST['receipt_mail_reply_to_name'] ?? '')));
            $receiptMailReplyToEmail = trim(strip_tags((string)($_POST['receipt_mail_reply_to_email'] ?? '')));

            if ($receiptPrefix === '' || strlen($receiptPrefix) > 24) {
                throw new RuntimeException('Receipt prefix must be between 1 and 24 characters.');
            }

            if ($receiptStartingNumber < 1 || $receiptNextNumber < 1) {
                throw new RuntimeException('Receipt numbers must be 1 or greater.');
            }

            if ($receiptNextNumber < $receiptStartingNumber) {
                throw new RuntimeException('Next receipt number cannot be lower than the starting number.');
            }

            if ($receiptEmail !== '' && !filter_var($receiptEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Receipt email must be a valid email address.');
            }

            if ($receiptTaxLabel === '' || strlen($receiptTaxLabel) > 64) {
                throw new RuntimeException('Tax label must be between 1 and 64 characters.');
            }

            if ($receiptMailFromEmail !== '' && !filter_var($receiptMailFromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Receipt From email must be a valid email address.');
            }

            if ($receiptMailReplyToEmail !== '' && !filter_var($receiptMailReplyToEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Receipt Reply-To email must be a valid email address.');
            }

            if ($receiptMailReplyToName !== '' && $receiptMailReplyToEmail === '') {
                throw new RuntimeException('Reply-To email is required when a Reply-To name is set.');
            }

            if ($receiptEnabled === '1' && ($receiptName === '' || $receiptAddress === '' || $receiptPhone === '')) {
                throw new RuntimeException('Receipt name, address, and phone are required when receipts are enabled.');
            }

            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_enabled', $receiptEnabled);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_email_enabled', $receiptEmailEnabled);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_prefix', $receiptPrefix);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_starting_number', (string)$receiptStartingNumber);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_next_number', (string)$receiptNextNumber);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_name', $receiptName);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_address', $receiptAddress);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_phone', $receiptPhone);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_email', $receiptEmail);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_code', $receiptCode);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_company_vat', $receiptVat);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_tax_label', $receiptTaxLabel);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_mail_from_name', $receiptMailFromName);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_mail_from_email', $receiptMailFromEmail);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_mail_reply_to_name', $receiptMailReplyToName);
            fbgAdminReceiptSettingsSaveSiteSetting('fbg_receipt_mail_reply_to_email', $receiptMailReplyToEmail);
            fbgResetSettingsCache();

            $message = 'Receipt settings updated.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Receipt settings could not be saved.';
            $messageType = 'error';
        }
    }
}

$receiptSettings = [
    'enabled' => (string)fbgGetSetting('fbg_receipt_enabled', '0') === '1',
    'email_enabled' => (string)fbgGetSetting('fbg_receipt_email_enabled', '1') === '1',
    'prefix' => (string)fbgGetSetting('fbg_receipt_prefix', 'FBG-'),
    'starting_number' => max(1, (int)fbgGetSetting('fbg_receipt_starting_number', '1001')),
    'next_number' => max(1, (int)fbgGetSetting('fbg_receipt_next_number', fbgGetSetting('fbg_receipt_starting_number', '1001'))),
    'name' => (string)fbgGetSetting('fbg_receipt_company_name', ''),
    'address' => (string)fbgGetSetting('fbg_receipt_company_address', ''),
    'phone' => (string)fbgGetSetting('fbg_receipt_company_phone', ''),
    'email' => (string)fbgGetSetting('fbg_receipt_company_email', ''),
    'code' => (string)fbgGetSetting('fbg_receipt_company_code', ''),
    'vat' => (string)fbgGetSetting('fbg_receipt_company_vat', ''),
    'tax_label' => (string)fbgGetSetting('fbg_receipt_tax_label', 'Tax'),
    'mail_from_name' => (string)fbgGetSetting('fbg_receipt_mail_from_name', ''),
    'mail_from_email' => (string)fbgGetSetting('fbg_receipt_mail_from_email', ''),
    'mail_reply_to_name' => (string)fbgGetSetting('fbg_receipt_mail_reply_to_name', ''),
    'mail_reply_to_email' => (string)fbgGetSetting('fbg_receipt_mail_reply_to_email', ''),
];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main fbg-admin-receipt-settings-page">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Receipt</p>
                <h1>Receipt Settings</h1>
                <p class="fbg-admin-subtext">Configure receipt numbering, company details, email delivery, and recovery tools.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Receipt Settings',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <form id="receipt-settings-form" method="POST" class="fbg-admin-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_settings">

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Receipt Settings</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="receipt_enabled" value="1" <?= $receiptSettings['enabled'] ? 'checked' : '' ?>>
                            <span>Enable receipt generation</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="receipt_email_enabled" value="1" <?= $receiptSettings['email_enabled'] ? 'checked' : '' ?>>
                            <span>Send receipt emails</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-prefix">Receipt Prefix</label>
                        <input id="receipt-prefix" name="receipt_prefix" type="text" maxlength="24" value="<?= htmlspecialchars($receiptSettings['prefix'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-starting-number">Starting Number</label>
                        <input id="receipt-starting-number" name="receipt_starting_number" type="number" min="1" step="1" value="<?= htmlspecialchars((string)$receiptSettings['starting_number'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-next-number">Next Receipt Number</label>
                        <input id="receipt-next-number" name="receipt_next_number" type="number" min="1" step="1" value="<?= htmlspecialchars((string)$receiptSettings['next_number'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="fbg-admin-help-text">
                            The next generated receipt will use this number with the configured prefix.
                        </p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-tax-label">Tax Label</label>
                        <input id="receipt-tax-label" name="receipt_tax_label" type="text" maxlength="64" value="<?= htmlspecialchars($receiptSettings['tax_label'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Email Delivery</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="receipt-mail-from-name">From Name</label>
                        <input
                            id="receipt-mail-from-name"
                            name="receipt_mail_from_name"
                            type="text"
                            placeholder="<?= defined('SMTP_FROM_NAME') ? htmlspecialchars((string)SMTP_FROM_NAME, ENT_QUOTES, 'UTF-8') : 'Frostbyt3 Gaming' ?>"
                            value="<?= htmlspecialchars($receiptSettings['mail_from_name'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Leave blank to use the default mail sender name.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-mail-from-email">From Email</label>
                        <input
                            id="receipt-mail-from-email"
                            name="receipt_mail_from_email"
                            type="email"
                            placeholder="<?= defined('SMTP_FROM_EMAIL') ? htmlspecialchars((string)SMTP_FROM_EMAIL, ENT_QUOTES, 'UTF-8') : 'billing@example.com' ?>"
                            value="<?= htmlspecialchars($receiptSettings['mail_from_email'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Leave blank to use the default mail sender address.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-mail-reply-to-name">Reply-To Name</label>
                        <input
                            id="receipt-mail-reply-to-name"
                            name="receipt_mail_reply_to_name"
                            type="text"
                            value="<?= htmlspecialchars($receiptSettings['mail_reply_to_name'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-mail-reply-to-email">Reply-To Email</label>
                        <input
                            id="receipt-mail-reply-to-email"
                            name="receipt_mail_reply_to_email"
                            type="email"
                            value="<?= htmlspecialchars($receiptSettings['mail_reply_to_email'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Replies to receipt emails will go here when set.</p>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Company Details</h2>
                </div>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="receipt-name">Name</label>
                        <input id="receipt-name" name="receipt_name" type="text" value="<?= htmlspecialchars($receiptSettings['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="receipt-address">Address</label>
                        <input id="receipt-address" name="receipt_address" type="text" value="<?= htmlspecialchars($receiptSettings['address'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-phone">Phone</label>
                        <input id="receipt-phone" name="receipt_phone" type="text" value="<?= htmlspecialchars($receiptSettings['phone'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-email">Email</label>
                        <input id="receipt-email" name="receipt_email" type="email" value="<?= htmlspecialchars($receiptSettings['email'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-code">Code</label>
                        <input id="receipt-code" name="receipt_code" type="text" value="<?= htmlspecialchars($receiptSettings['code'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-vat">VAT Code</label>
                        <input id="receipt-vat" name="receipt_vat" type="text" value="<?= htmlspecialchars($receiptSettings['vat'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </section>

        </form>

        <section class="fbg-admin-panel fbg-admin-panel-full">
            <div class="fbg-admin-panel-header">
                <h2>Manual Receipt Recovery</h2>
            </div>

            <p class="fbg-admin-help-text">
                Generate a missing receipt for a completed wallet top-up by payment ID.
            </p>

            <form method="POST" class="fbg-admin-form-grid">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="generate_receipt">

                <div class="fbg-admin-field">
                    <label for="manual-receipt-payment-id">Payment ID</label>
                    <input
                        id="manual-receipt-payment-id"
                        name="payment_id"
                        type="number"
                        min="1"
                        step="1"
                        placeholder="Completed payment ID"
                    >
                </div>

                <div class="fbg-admin-form-actions">
                    <button type="submit" class="btn fbg-neutral-button">
                        Generate Receipt
                    </button>
                </div>
            </form>
        </section>

        <section class="fbg-admin-panel fbg-admin-panel-full">
            <div class="fbg-admin-form-actions">
                <button type="submit" form="receipt-settings-form" class="btn">Save Receipt Settings</button>
            </div>
        </section>
    </div>
</section>
