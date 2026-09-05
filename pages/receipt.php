<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$receiptId = (int)($_GET['id'] ?? 0);
$canViewAllReceipts = canAccess(4);
$receipt = fbgGetFrontendReceiptDetail($receiptId, $userId, $canViewAllReceipts);
$currency = (string)($receipt['currency'] ?? fbgGetShopCurrency());

if (!$receipt) {
    http_response_code(404);
}

$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'Unknown';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : 'Unknown';
};

$formatMoney = static function ($amount) use ($currency): string {
    return fbgFormatCredit((float)$amount, $currency);
};

$formatProvider = static function (?string $provider): string {
    $provider = strtolower(trim((string)$provider));

    return match ($provider) {
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'account_balance' => 'Wallet Balance',
        default => $provider !== '' ? ucwords(str_replace(['_', '-'], ' ', $provider)) : 'Payment',
    };
};

$hasTax = $receipt
    && (
        round((float)($receipt['tax_rate'] ?? 0), 4) > 0
        || round((float)($receipt['tax_amount'] ?? 0), 2) > 0
    );
$taxLabel = trim((string)($receipt['tax_label'] ?? 'Tax')) ?: 'Tax';
?>

<section class="fbg-account-page fbg-credit-page">
    <div class="fbg-dashboard-shell">
        <div class="fbg-dashboard-layout">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div class="fbg-dashboard-main">
                <div class="fbg-account-shell">
                    <div class="fbg-account-header">
                        <div>
                            <h1>RECEIPT</h1>
                            <p>Review receipt details for your Frostbyt3 Gaming account.</p>
                        </div>
                        <div class="fbg-admin-table-actions">
                            <?php if ($receipt): ?>
                                <a href="./page.php?name=receipt-pdf&id=<?= (int)$receipt['id'] ?>" class="btn" target="_blank" rel="noopener noreferrer">
                                    Download PDF
                                </a>
                            <?php endif; ?>
                            <a href="./page.php?name=wallet" class="btn fbg-neutral-button">
                                Back to Wallet
                            </a>
                        </div>
                    </div>

                    <?php if (!$receipt): ?>
                        <section class="fbg-account-section">
                            <div class="fbg-empty-state">
                                Receipt not found.
                            </div>
                        </section>
                    <?php else: ?>
                        <section class="fbg-account-section fbg-receipt-summary">
                            <div>
                                <span class="fbg-meta-label">Receipt Number</span>
                                <strong><?php echo htmlspecialchars((string)($receipt['receipt_number'] ?? 'Receipt'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Status</span>
                                <span class="fbg-credit-status is-complete">
                                    <?php echo htmlspecialchars(ucfirst((string)($receipt['status'] ?? 'paid')), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Created</span>
                                <strong><?php echo htmlspecialchars($formatDate((string)($receipt['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Paid</span>
                                <strong><?php echo htmlspecialchars($formatDate((string)($receipt['paid_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </section>

                        <section class="fbg-account-section">
                            <div class="fbg-settings-section-header">
                                <h3>Merchant</h3>
                            </div>
                            <div class="fbg-receipt-party">
                                <strong><?php echo htmlspecialchars((string)($receipt['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($receipt['company_address'])): ?>
                                    <p><?php echo nl2br(htmlspecialchars((string)$receipt['company_address'], ENT_QUOTES, 'UTF-8')); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($receipt['company_phone'])): ?>
                                    <p><?php echo htmlspecialchars((string)$receipt['company_phone'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($receipt['company_email'])): ?>
                                    <p><?php echo htmlspecialchars((string)$receipt['company_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($receipt['company_code']) || !empty($receipt['company_vat'])): ?>
                                    <p>
                                        <?php if (!empty($receipt['company_code'])): ?>
                                            Code: <?php echo htmlspecialchars((string)$receipt['company_code'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($receipt['company_vat'])): ?>
                                            <?php echo !empty($receipt['company_code']) ? '<br>' : ''; ?>
                                            VAT: <?php echo htmlspecialchars((string)$receipt['company_vat'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="fbg-account-section">
                            <div class="fbg-settings-section-header">
                                <h3>Line Items</h3>
                            </div>

                            <div class="fbg-credit-table-wrap">
                                <table class="fbg-credit-table">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th class="fbg-credit-table-amount">Qty</th>
                                            <th class="fbg-credit-table-amount">Unit</th>
                                            <th class="fbg-credit-table-amount">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($receipt['line_items'] ?? []) as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars(number_format((float)($item['quantity'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars(fbgFormatFrontendReceiptUnitDisplay($item, $currency), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars($formatMoney($item['line_total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="fbg-receipt-totals">
                                <div>
                                    <span>Subtotal</span>
                                    <strong><?php echo htmlspecialchars($formatMoney($receipt['subtotal'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                                <?php if ($hasTax): ?>
                                    <div>
                                        <span><?php echo htmlspecialchars($taxLabel, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(number_format((float)($receipt['tax_rate'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>%</span>
                                        <strong><?php echo htmlspecialchars($formatMoney($receipt['tax_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="is-total">
                                    <span>Total</span>
                                    <strong><?php echo htmlspecialchars($formatMoney($receipt['total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </div>
                        </section>

                        <section class="fbg-account-section">
                            <div class="fbg-settings-section-header">
                                <h3>Payment Details</h3>
                            </div>

                            <div class="fbg-receipt-payment-grid">
                                <div>
                                    <span class="fbg-meta-label">Provider</span>
                                    <strong><?php echo htmlspecialchars($formatProvider((string)($receipt['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
