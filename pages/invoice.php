<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$invoiceId = (int)($_GET['id'] ?? 0);
$canViewAllInvoices = canAccess(4);
$invoice = fbgGetFrontendInvoiceDetail($invoiceId, $userId, $canViewAllInvoices);
$currency = (string)($invoice['currency'] ?? fbgGetShopCurrency());

if (!$invoice) {
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
        'account_balance' => 'Account Balance',
        default => $provider !== '' ? ucwords(str_replace(['_', '-'], ' ', $provider)) : 'Payment',
    };
};

$hasTax = $invoice
    && (
        round((float)($invoice['tax_rate'] ?? 0), 4) > 0
        || round((float)($invoice['tax_amount'] ?? 0), 2) > 0
    );
$taxLabel = trim((string)($invoice['tax_label'] ?? 'Tax')) ?: 'Tax';
?>

<section class="fbg-account-page fbg-credit-page">
    <div class="fbg-dashboard-shell">
        <div class="fbg-dashboard-layout">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div class="fbg-dashboard-main">
                <div class="fbg-account-shell">
                    <div class="fbg-account-header">
                        <div>
                            <h1>Invoice</h1>
                            <p>Review invoice details for your Frostbyt3 Gaming account.</p>
                        </div>
                        <a href="./page.php?name=wallet" class="btn fbg-neutral-button">
                            Back to Wallet
                        </a>
                    </div>

                    <?php if (!$invoice): ?>
                        <section class="fbg-account-section">
                            <div class="fbg-empty-state">
                                Invoice not found.
                            </div>
                        </section>
                    <?php else: ?>
                        <section class="fbg-account-section fbg-invoice-summary">
                            <div>
                                <span class="fbg-meta-label">Invoice Number</span>
                                <strong><?php echo htmlspecialchars((string)($invoice['invoice_number'] ?? 'Invoice'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Status</span>
                                <span class="fbg-credit-status is-complete">
                                    <?php echo htmlspecialchars(ucfirst((string)($invoice['status'] ?? 'paid')), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Created</span>
                                <strong><?php echo htmlspecialchars($formatDate((string)($invoice['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div>
                                <span class="fbg-meta-label">Paid</span>
                                <strong><?php echo htmlspecialchars($formatDate((string)($invoice['paid_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </section>

                        <div class="fbg-invoice-detail-grid">
                            <section class="fbg-account-section">
                                <div class="fbg-settings-section-header">
                                    <h3>From</h3>
                                </div>
                                <div class="fbg-invoice-party">
                                    <strong><?php echo htmlspecialchars((string)($invoice['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($invoice['company_address'])): ?>
                                        <p><?php echo nl2br(htmlspecialchars((string)$invoice['company_address'], ENT_QUOTES, 'UTF-8')); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['company_phone'])): ?>
                                        <p><?php echo htmlspecialchars((string)$invoice['company_phone'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['company_email'])): ?>
                                        <p><?php echo htmlspecialchars((string)$invoice['company_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['company_code']) || !empty($invoice['company_vat'])): ?>
                                        <p>
                                            <?php if (!empty($invoice['company_code'])): ?>
                                                Code: <?php echo htmlspecialchars((string)$invoice['company_code'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($invoice['company_vat'])): ?>
                                                <?php echo !empty($invoice['company_code']) ? '<br>' : ''; ?>
                                                VAT: <?php echo htmlspecialchars((string)$invoice['company_vat'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <section class="fbg-account-section">
                                <div class="fbg-settings-section-header">
                                    <h3>Bill To</h3>
                                </div>
                                <div class="fbg-invoice-party">
                                    <strong>
                                        <?php echo htmlspecialchars((string)($invoice['customer_name'] ?: $invoice['customer_username'] ?: 'Customer'), ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>
                                    <?php if (!empty($invoice['customer_username'])): ?>
                                        <p><?php echo htmlspecialchars((string)$invoice['customer_username'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['customer_email'])): ?>
                                        <p><?php echo htmlspecialchars((string)$invoice['customer_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>

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
                                        <?php foreach (($invoice['line_items'] ?? []) as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars(number_format((float)($item['quantity'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars($formatMoney($item['unit_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="fbg-credit-table-amount"><?php echo htmlspecialchars($formatMoney($item['line_total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="fbg-invoice-totals">
                                <div>
                                    <span>Subtotal</span>
                                    <strong><?php echo htmlspecialchars($formatMoney($invoice['subtotal'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                                <?php if ($hasTax): ?>
                                    <div>
                                        <span><?php echo htmlspecialchars($taxLabel, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(number_format((float)($invoice['tax_rate'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>%</span>
                                        <strong><?php echo htmlspecialchars($formatMoney($invoice['tax_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="is-total">
                                    <span>Total</span>
                                    <strong><?php echo htmlspecialchars($formatMoney($invoice['total'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </div>
                        </section>

                        <section class="fbg-account-section">
                            <div class="fbg-settings-section-header">
                                <h3>Payment</h3>
                            </div>

                            <div class="fbg-invoice-payment-grid">
                                <div>
                                    <span class="fbg-meta-label">Provider</span>
                                    <strong><?php echo htmlspecialchars($formatProvider((string)($invoice['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
