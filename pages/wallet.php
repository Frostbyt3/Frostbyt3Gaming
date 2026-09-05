<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$messages = [];
$errors = [];
$balanceUploadResult = null;

if (!empty($_GET['stripe_session_id'])) {
    $result = fbgCompleteStripeBalanceCheckout($userId, trim((string)$_GET['stripe_session_id']));

    if (!empty($result['ok'])) {
        $balanceUploadResult = $result;
        $messages[] = (string)($result['message'] ?? 'Account balance updated.');
    } else {
        $errors[] = (string)($result['error'] ?? 'Could not verify payment.');
    }
}

if (($_GET['payment_provider'] ?? '') === 'paypal' && !empty($_GET['token'])) {
    $result = fbgCompletePayPalBalanceCheckout($userId, trim((string)$_GET['token']));

    if (!empty($result['ok'])) {
        $balanceUploadResult = $result;
        $messages[] = (string)($result['message'] ?? 'Account balance updated.');
    } else {
        $errors[] = (string)($result['error'] ?? 'Could not verify PayPal payment.');
    }
}

if (!empty($_GET['payment_cancelled'])) {
    $errors[] = 'Payment was cancelled before your account balance was updated.';
}

$currency = fbgGetShopCurrency();
$paymentSettings = fbgGetShopPaymentSettings();
$hasOnlineBalanceUploads = $paymentSettings['stripe_enabled'] || $paymentSettings['paypal_enabled'];
$balance = fbgGetUserCreditBalance($userId);
$purchaseConfirmation = null;

if (is_array($balanceUploadResult) && is_array($balanceUploadResult['receipt'] ?? null)) {
    $receipt = $balanceUploadResult['receipt'];
    $addedAmount = (float)($receipt['subtotal'] ?? $receipt['total'] ?? 0);
    $totalCharged = (float)($receipt['total'] ?? $addedAmount);
    $receiptId = (int)($receipt['id'] ?? 0);
    $receiptNumber = trim((string)($receipt['receipt_number'] ?? ''));

    if ($addedAmount > 0) {
        $purchaseConfirmation = [
            'type' => 'balance_upload',
            'title' => 'Thanks for your order!',
            'message' => 'Your wallet balance was updated successfully.',
            'label' => 'Balance Upload',
            'currency' => $currency,
            'details' => [
                [
                    'label' => 'Added',
                    'value' => fbgFormatCredit($addedAmount, $currency),
                ],
                [
                    'label' => 'Processing / Tax',
                    'value' => fbgFormatCredit(0.0, $currency),
                ],
                [
                    'label' => 'Total Charged',
                    'value' => fbgFormatCredit($totalCharged, $currency),
                ],
            ],
            'totals' => [],
            'balance' => [
                'label' => 'New Wallet Balance',
                'value' => fbgFormatCredit($balance, $currency),
            ],
            'note' => '',
            'receipt' => $receiptId > 0 && $receiptNumber !== ''
                ? [
                    'number' => $receiptNumber,
                    'url' => '/page.php?name=receipt&id=' . rawurlencode((string)$receiptId),
                ]
                : null,
            'actions' => [
                [
                    'label' => 'Manage Wallet',
                    'url' => '/page.php?name=wallet',
                    'primary' => true,
                ],
                [
                    'label' => 'Browse Shop',
                    'url' => '/page.php?name=servers',
                ],
            ],
        ];

        $messages = [];
    }
}

$transactions = fbgGetUserPaymentHistory($userId);
$frontendReceipts = fbgGetUserFrontendReceipts($userId);
$serverPurchases = fbgGetUserServerPurchaseHistory($userId);
$showPendingTransactions = (string)($_GET['show_pending'] ?? '') === '1';
$visibleTransactions = [];
$hiddenPendingTransactions = 0;
$receiptByPaymentId = [];

foreach ($frontendReceipts as $receipt) {
    if (($receipt['source_type'] ?? '') !== 'payment') {
        continue;
    }

    $sourceId = (int)($receipt['source_id'] ?? 0);
    if ($sourceId > 0) {
        $receiptByPaymentId[$sourceId] = $receipt;
    }
}

foreach ($transactions as $transaction) {
    $completed = (int)($transaction['completed'] ?? 0) === 1;
    $createdAt = (string)($transaction['created_at'] ?? '');
    $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
    $isOldPending = !$completed && $timestamp !== false && $timestamp < (time() - 86400);

    if ($isOldPending && !$showPendingTransactions) {
        $hiddenPendingTransactions++;
        continue;
    }

    $visibleTransactions[] = $transaction;
}

$minAmount = max(0.01, (float)$paymentSettings['min_amount']);
$maxAmount = (float)$paymentSettings['max_amount'];
$defaultAmount = max($minAmount, min($maxAmount > 0 ? $maxAmount : 10.00, 10.00));
?>

<section class="fbg-account-page fbg-credit-page">
    <div class="fbg-dashboard-shell">
        <div class="fbg-dashboard-layout">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div class="fbg-dashboard-main">
                <div class="fbg-account-shell">
                    <div class="fbg-account-header">
                        <div>
                            <h1>Manage Wallet</h1>
                            <p>Add funds, review wallet balance, and view completed transactions.</p>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="fbg-dashboard-alert error is-visible fbg-auto-dismiss-alert" style="margin-bottom: 16px;">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($messages)): ?>
                        <div class="fbg-dashboard-alert success is-visible fbg-auto-dismiss-alert" style="margin-bottom: 16px;">
                            <?php foreach ($messages as $message): ?>
                                <div><?php echo htmlspecialchars($message); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <section class="fbg-account-section fbg-credit-summary">
                        <div>
                            <span class="fbg-meta-label">Wallet Balance</span>
                            <strong>$<?php echo htmlspecialchars(fbgFormatCredit($balance, $currency)); ?></strong>
                        </div>
                        <a href="./page.php?name=servers" class="btn fbg-primary-button">
                            Browse Servers
                        </a>
                    </section>

                    <section class="fbg-account-section fbg-credit-add-funds">
                        <div class="fbg-settings-section-header">
                            <h3>Add Balance</h3>
                        </div>

                        <?php if (!$hasOnlineBalanceUploads): ?>
                            <div class="fbg-empty-state">
                                Online balance uploads are not enabled right now.
                            </div>
                        <?php else: ?>
                            <form id="fbg-add-balance-form" class="fbg-credit-form" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token']); ?>">

                                <div class="fbg-settings-field-grid">
                                    <div class="fbg-settings-field">
                                        <label class="fbg-meta-label" for="credit-amount">Amount</label>
                                        <div class="fbg-credit-input-row">
                                            <input
                                                id="credit-amount"
                                                class="fbg-text-input"
                                                type="number"
                                                name="amount"
                                                min="<?php echo htmlspecialchars(number_format($minAmount, 2, '.', '')); ?>"
                                                <?php if ($maxAmount > 0): ?>
                                                    max="<?php echo htmlspecialchars(number_format($maxAmount, 2, '.', '')); ?>"
                                                <?php endif; ?>
                                                step="0.01"
                                                value="<?php echo htmlspecialchars(number_format($defaultAmount, 2, '.', '')); ?>"
                                                required
                                            >
                                            <span><?php echo htmlspecialchars($currency); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <p class="fbg-wallet-note">
                                    Checkout will open securely. Your wallet balance updates after payment is verified.
                                </p>

                                <p class="fbg-wallet-note">
                                    <i><strong>Note:</strong> A small temporary authorization hold may appear on your payment method while the payment processor verifies it. This is not an additional Frostbyt3 Gaming charge and should disappear automatically.</i>
                                </p>

                                <p class="fbg-wallet-note">
                                    <i>By adding funds, you agree to the <highlight>Frostbyt3 Gaming <a href="./page.php?name=legal&doc=terms">Terms of Service</a></highlight> and acknowledge that <highlight>Wallet Balance</highlight> is subject to our payment and refund terms.</i>
                                </p>

                                <div id="fbg-add-balance-message" class="fbg-dashboard-alert" style="display:none; margin-top: 12px;"></div>

                                <div class="fbg-settings-section-footer">
                                    <button type="submit" class="btn fbg-primary-button" data-default-text="Add Balance with Stripe" <?php echo (!$paymentSettings['stripe_enabled'] || !$paymentSettings['stripe_secret_configured']) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-lock"></i> Add Balance with Stripe
                                    </button>
                                    <button type="button" class="btn fbg-paypal-button" id="fbg-paypal-balance-button" <?php echo (!$paymentSettings['paypal_enabled'] || !$paymentSettings['paypal_key_configured'] || !$paymentSettings['paypal_secret_configured']) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-lock"></i> Add Balance with PayPal
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="fbg-account-section">
                        <div class="fbg-settings-section-header fbg-credit-transactions-header">
                            <div>
                                <h3>Transaction History</h3>
                                <?php if ($hiddenPendingTransactions > 0 && !$showPendingTransactions): ?>
                                    <p class="fbg-settings-note">
                                        <?php echo $hiddenPendingTransactions; ?> pending transaction<?php echo $hiddenPendingTransactions === 1 ? '' : 's'; ?> older than 24 hours hidden.
                                    </p>
                                <?php endif; ?>
                            </div>

                            <label class="fbg-toggle-row fbg-credit-pending-toggle">
                                <span class="fbg-toggle-label">Show Pending</span>
                                <span class="fbg-toggle-switch">
                                    <input
                                        type="checkbox"
                                        id="fbg-show-pending-transactions"
                                        <?php echo $showPendingTransactions ? 'checked' : ''; ?>
                                    >
                                    <span class="fbg-toggle-slider"></span>
                                </span>
                            </label>
                        </div>

                        <?php if (empty($visibleTransactions)): ?>
                            <div class="fbg-empty-state">
                                No transactions found.
                            </div>
                        <?php else: ?>
                            <div class="fbg-credit-table-wrap fbg-wallet-table-scroll">
                                <table class="fbg-credit-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Receipt</th>
                                            <th class="fbg-credit-table-amount">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($visibleTransactions as $transaction): ?>
                                            <?php
                                            $createdAt = (string)($transaction['created_at'] ?? '');
                                            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
                                            $dateDisplay = $timestamp ? date('M j, Y g:i A', $timestamp) : 'Unknown';
                                            $type = ucfirst((string)($transaction['payment_type'] ?? 'Payment'));
                                            $completed = (int)($transaction['completed'] ?? 0) === 1;
                                            $receipt = $receiptByPaymentId[(int)($transaction['id'] ?? 0)] ?? null;
                                            $receiptNumber = trim((string)($receipt['receipt_number'] ?? $transaction['receipt_number'] ?? ''));
                                            $receiptUrl = $receipt && !empty($receipt['id'])
                                                ? './page.php?name=receipt&id=' . rawurlencode((string)$receipt['id'])
                                                : '';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dateDisplay); ?></td>
                                                <td><?php echo htmlspecialchars($type); ?></td>
                                                <td>
                                                    <span class="fbg-credit-status <?php echo $completed ? 'is-complete' : 'is-pending'; ?>">
                                                        <?php echo $completed ? 'Complete' : 'Pending'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($receiptNumber !== '' && $receiptUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-receipt-link">
                                                            <?php echo htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8'); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        &mdash;
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fbg-credit-table-amount">
                                                    <?php echo htmlspecialchars(fbgFormatCredit((float)($transaction['amount'] ?? 0), $currency)); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="fbg-account-section">
                        <div class="fbg-settings-section-header">
                            <div>
                                <h3>Receipts</h3>
                                <p class="fbg-settings-note">
                                    Receipts for completed wallet activity.
                                </p>
                            </div>
                        </div>

                        <?php if (empty($frontendReceipts)): ?>
                            <div class="fbg-empty-state">
                                No receipts found.
                            </div>
                        <?php else: ?>
                            <div class="fbg-credit-table-wrap fbg-wallet-table-scroll">
                                <table class="fbg-credit-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt</th>
                                            <th>Status</th>
                                            <th>Source</th>
                                            <th class="fbg-credit-table-amount">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($frontendReceipts as $receipt): ?>
                                            <?php
                                            $createdAt = (string)($receipt['created_at'] ?? '');
                                            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
                                            $dateDisplay = $timestamp ? date('M j, Y g:i A', $timestamp) : 'Unknown';
                                            $receiptNumber = trim((string)($receipt['receipt_number'] ?? ''));
                                            $receiptStatus = ucfirst((string)($receipt['status'] ?? 'Paid'));
                                            $sourceType = (string)($receipt['source_type'] ?? 'purchase');
                                            $sourceLabel = match ($sourceType) {
                                                'payment' => 'Balance Upload',
                                                'server_purchase' => 'Server Rental',
                                                'server_renewal' => 'Server Renewal',
                                                default => 'Rental',
                                            };
                                            $receiptCurrency = trim((string)($receipt['currency'] ?? '')) ?: $currency;
                                            $receiptUrl = './page.php?name=receipt&id=' . rawurlencode((string)($receipt['id'] ?? 0));
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dateDisplay); ?></td>
                                                <td>
                                                    <?php if ($receiptNumber !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fbg-receipt-link">
                                                            <?php echo htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8'); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        &mdash;
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fbg-credit-status is-complete">
                                                        <?php echo htmlspecialchars($receiptStatus); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($sourceLabel); ?></td>
                                                <td class="fbg-credit-table-amount">
                                                    <?php echo htmlspecialchars(fbgFormatCredit((float)($receipt['total'] ?? 0), $receiptCurrency)); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="fbg-account-section fbg-credit-server-purchases">
                        <div class="fbg-settings-section-header">
                            <div>
                                <h3>Server Rental History</h3>
                                <p class="fbg-settings-note">
                                     Server rentals paid from your wallet balance.
                                </p>
                            </div>
                        </div>

                        <?php if (empty($serverPurchases)): ?>
                            <div class="fbg-empty-state">
                                No server rentals found.
                            </div>
                        <?php else: ?>
                            <div class="fbg-credit-table-wrap fbg-wallet-table-scroll">
                                <table class="fbg-credit-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Plan</th>
                                            <th class="fbg-credit-table-amount">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($serverPurchases as $purchase): ?>
                                            <?php
                                            $createdAt = (string)($purchase['created_at'] ?? '');
                                            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
                                            $dateDisplay = $timestamp ? date('M j, Y g:i A', $timestamp) : 'Unknown';
                                            $purchaseCurrency = trim((string)($purchase['currency'] ?? '')) ?: $currency;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dateDisplay); ?></td>
                                                <td><?php echo htmlspecialchars((string)($purchase['game_name'] ?? 'Game Server')); ?></td>
                                                <td class="fbg-credit-table-amount">
                                                    <?php echo htmlspecialchars(fbgFormatCredit((float)($purchase['amount'] ?? 0), $purchaseCurrency)); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                    
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (is_array($purchaseConfirmation)): ?>
    <script>
    window.addEventListener("DOMContentLoaded", () => {
        if (typeof window.FBGPurchaseConfirmation === "function") {
            window.FBGPurchaseConfirmation(<?php echo json_encode($purchaseConfirmation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>);
        }
    });
    </script>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("fbg-add-balance-form");
    const message = document.getElementById("fbg-add-balance-message");
    const paypalButton = document.getElementById("fbg-paypal-balance-button");
    const showPendingToggle = document.getElementById("fbg-show-pending-transactions");
    const autoDismissAlerts = document.querySelectorAll(".fbg-auto-dismiss-alert");

    if (showPendingToggle) {
        showPendingToggle.addEventListener("change", () => {
            const url = new URL(window.location.href);

            if (showPendingToggle.checked) {
                url.searchParams.set("show_pending", "1");
            } else {
                url.searchParams.delete("show_pending");
            }

            window.location.href = url.toString();
        });
    }

    autoDismissAlerts.forEach((alert) => {
        setTimeout(() => {
            alert.classList.remove("is-visible");
            alert.style.display = "none";
        }, 8000);
    });

    if (!form || !message) return;

    const showMessage = (text, type) => {
        message.textContent = text;
        message.className = "fbg-dashboard-alert is-visible" + (type === "error" ? " error" : " success");
        message.style.display = "block";
    };

    const startCheckout = async (endpoint, button, loadingText, fallbackText) => {
        const formData = new FormData(form);

        if (button) {
            button.disabled = true;
            button.textContent = loadingText;
        }

        try {
            const response = await fetch(endpoint, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                body: JSON.stringify({
                    csrf_token: formData.get("csrf_token"),
                    amount: formData.get("amount")
                })
            });

            const payload = await response.json();

            if (!response.ok || !payload.ok || !payload.data?.redirect_url) {
                throw new Error(payload.error || "Could not start checkout.");
            }

            window.location.href = payload.data.redirect_url;
        } catch (error) {
            showMessage(error.message || "Could not start checkout.", "error");

            if (button) {
                button.disabled = false;
                button.textContent = button.dataset.defaultText || fallbackText;
            }
        }
    };

    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const button = form.querySelector("button[type='submit']");
        await startCheckout("/api/shop/stripe-checkout.php", button, "Starting Stripe...", "Add Balance with Stripe");
    });

    if (paypalButton) {
        paypalButton.dataset.defaultText = "Add Balance with PayPal";

        paypalButton.addEventListener("click", async () => {
            await startCheckout("/api/shop/paypal-checkout.php", paypalButton, "Starting PayPal...", "Add Balance with PayPal");
        });
    }
});
</script>
