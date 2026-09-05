<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$currentAdminPage = 'admin-receipts';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_receipts_message'] ?? '');
$messageType = (string)($_SESSION['admin_receipts_message_type'] ?? 'success');
unset($_SESSION['admin_receipts_message'], $_SESSION['admin_receipts_message_type']);

function fbgAdminReceiptsRedirect(string $message, string $type = 'success', ?int $viewReceiptId = null): void
{
    $_SESSION['admin_receipts_message'] = $message;
    $_SESSION['admin_receipts_message_type'] = $type;

    $query = $_GET;
    $query['name'] = 'admin-receipts';

    if ($viewReceiptId !== null && $viewReceiptId > 0) {
        $query['view'] = $viewReceiptId;
    } else {
        unset($query['view']);
    }

    fbgRedirect('/page.php?' . http_build_query($query));
    exit;
}

function fbgAdminReceiptsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminReceiptsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminReceiptsDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function fbgAdminReceiptsSourceLabel(string $sourceType): string
{
    return match ($sourceType) {
        'payment' => 'Balance Upload',
        'server_purchase' => 'Server Rental',
        'server_renewal' => 'Server Renewal',
        default => ucwords(str_replace('_', ' ', $sourceType ?: 'Receipt')),
    };
}

function fbgAdminReceiptsProviderLabel(string $provider): string
{
    $provider = trim($provider);
    if ($provider === '') {
        return '-';
    }

    if (strtolower($provider) === 'account_balance') {
        return 'Wallet Balance';
    }

    return ucwords(str_replace('_', ' ', $provider));
}

function fbgAdminReceiptsStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'void' => 'Void',
        'refunded' => 'Refunded',
        default => 'Paid',
    };
}

function fbgAdminReceiptsBaseUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-receipts';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminReceiptsVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));
    $receiptId = (int)($_POST['receipt_id'] ?? 0);

    if ($action === 'resend_receipt' && $receiptId > 0) {
        $sent = fbgResendFrontendReceiptEmailNotification($receiptId);
        fbgAdminReceiptsRedirect(
            $sent ? 'Receipt email resent successfully.' : 'The receipt email could not be resent.',
            $sent ? 'success' : 'error',
            $receiptId
        );
    }

    if (in_array($action, ['void_receipt', 'refund_receipt'], true) && $receiptId > 0) {
        $newStatus = $action === 'void_receipt' ? 'void' : 'refunded';
        $result = fbgUpdateFrontendReceiptStatus(
            $receiptId,
            $newStatus,
            $newStatus === 'void'
                ? 'Receipt was marked as void from the admin area.'
                : 'Receipt was marked as refunded from the admin area.',
            ['admin_action' => $action]
        );

        fbgAdminReceiptsRedirect(
            !empty($result['ok'])
                ? 'Receipt marked as ' . fbgAdminReceiptsStatusLabel($newStatus) . '.'
                : (string)($result['error'] ?? 'The receipt status could not be updated.'),
            !empty($result['ok']) ? 'success' : 'error',
            $receiptId
        );
    }

    fbgAdminReceiptsRedirect('That receipt action is not available.', 'error', $receiptId > 0 ? $receiptId : null);
}

$search = trim((string)($_GET['search'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$sourceType = strtolower(trim((string)($_GET['source_type'] ?? '')));
$perPage = max(5, min(100, (int)($_GET['per_page'] ?? 25)));
$pageNum = fbgPaginationRequestedPage();
$viewReceiptId = (int)($_GET['view'] ?? 0);
$viewReceipt = $viewReceiptId > 0 ? fbgGetFrontendReceiptDetail($viewReceiptId, 1, true) : null;

$filters = [
    'search' => $search,
    'status' => $status,
    'source_type' => $sourceType,
];

$initialResults = fbgGetAdminFrontendReceipts($filters, $perPage, 0);
$pagination = fbgNormalizePagination((int)$initialResults['total'], $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$receiptResults = fbgGetAdminFrontendReceipts($filters, $perPage, (int)$pagination['offset']);
$receipts = $receiptResults['rows'];
$totalRows = (int)$receiptResults['total'];
$currencyFallback = fbgGetShopCurrency();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Receipts</h1>
                <p class="fbg-admin-subtext">Review customer receipts, resend receipt emails, and inspect delivery history.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Receipts',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <div>
                        <h2>Receipt List</h2>
                        <p>Find receipts by receipt number, customer, email, username, or source ID.</p>
                    </div>
                </div>

                <form method="GET" class="fbg-admin-form-grid">
                    <input type="hidden" name="name" value="admin-receipts">

                    <div class="fbg-admin-field">
                        <label for="receipt-search">Search</label>
                        <input id="receipt-search" name="search" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Receipt, customer, email, source ID">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-status">Status</label>
                        <select id="receipt-status" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach (['paid', 'void', 'refunded'] as $option): ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $status === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($option), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-source-type">Type</label>
                        <select id="receipt-source-type" name="source_type">
                            <option value="">All Types</option>
                            <?php foreach (['payment', 'server_purchase', 'server_renewal'] as $option): ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $sourceType === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(fbgAdminReceiptsSourceLabel($option), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="receipt-per-page">Per Page</label>
                        <select id="receipt-per-page" name="per_page">
                            <?php foreach ([10, 25, 50, 100] as $option): ?>
                                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a class="btn fbg-neutral-button" href="./page.php?name=admin-receipts">Reset</a>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Customer</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Provider</th>
                                <th>Created</th>
                                <th>Paid</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($receipts)): ?>
                                <tr>
                                    <td colspan="9">No receipts found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($receipts as $receipt): ?>
                                <?php
                                $receiptId = (int)($receipt['id'] ?? 0);
                                $currency = trim((string)($receipt['currency'] ?? '')) ?: $currencyFallback;
                                $customer = trim((string)($receipt['customer_name'] ?? ''));
                                if ($customer === '') {
                                    $customer = trim((string)($receipt['customer_username'] ?? 'Customer'));
                                }
                                ?>
                                <tr>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminReceiptsBaseUrl(['view' => $receiptId]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)($receipt['receipt_number'] ?? ('Receipt #' . $receiptId)), ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <small><?= htmlspecialchars((string)($receipt['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars(fbgAdminReceiptsSourceLabel((string)($receipt['source_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php $receiptStatus = strtolower((string)($receipt['status'] ?? 'paid')); ?>
                                        <span class="fbg-admin-status-pill is-<?= htmlspecialchars($receiptStatus, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(fbgAdminReceiptsStatusLabel($receiptStatus), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(fbgFormatCredit((float)($receipt['total'] ?? 0), $currency), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminReceiptsProviderLabel((string)($receipt['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminReceiptsDate($receipt['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminReceiptsDate($receipt['paid_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="fbg-admin-table-actions">
                                            <a class="btn btn-sm fbg-neutral-button" href="<?= htmlspecialchars(fbgAdminReceiptsBaseUrl(['view' => $receiptId]), ENT_QUOTES, 'UTF-8') ?>">View</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'receipt', ['remove' => ['view']]); ?>
            </section>

            <?php if ($viewReceiptId > 0): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full fbg-admin-receipt-detail">
                    <?php if (!$viewReceipt): ?>
                        <div class="fbg-admin-empty-state">
                            <p>Receipt could not be found.</p>
                            <a class="btn fbg-neutral-button" href="<?= htmlspecialchars(fbgAdminReceiptsBaseUrl(['view' => null]), ENT_QUOTES, 'UTF-8') ?>">Back to Receipts</a>
                        </div>
                    <?php else: ?>
                        <?php
                        $detailCurrency = trim((string)($viewReceipt['currency'] ?? '')) ?: $currencyFallback;
                        $detailTaxLabel = trim((string)($viewReceipt['tax_label'] ?? 'Tax')) ?: 'Tax';
                        $detailStatus = strtolower((string)($viewReceipt['status'] ?? 'paid'));
                        $hasTax = round((float)($viewReceipt['tax_rate'] ?? 0), 4) > 0 || round((float)($viewReceipt['tax_amount'] ?? 0), 2) > 0;
                        ?>
                        <div class="fbg-admin-panel-header">
                            <div>
                                <h2><?= htmlspecialchars((string)($viewReceipt['receipt_number'] ?? 'Receipt'), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars(fbgAdminReceiptsSourceLabel((string)($viewReceipt['source_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)($viewReceipt['customer_name'] ?: $viewReceipt['customer_username'] ?: 'Customer'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="fbg-admin-table-actions">
                                 <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=receipt&id=<?= (int)$viewReceipt['id'] ?>" target="_blank" rel="noopener noreferrer">Open Customer View</a>
                                 <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=receipt-pdf&id=<?= (int)$viewReceipt['id'] ?>" target="_blank" rel="noopener noreferrer">Download PDF</a>
                                 <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="resend_receipt">
                                     <input type="hidden" name="receipt_id" value="<?= (int)$viewReceipt['id'] ?>">
                                     <button type="submit" class="btn btn-sm">Resend Email</button>
                                 </form>
                                <?php if ($detailStatus === 'paid'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="void_receipt">
                                        <input type="hidden" name="receipt_id" value="<?= (int)$viewReceipt['id'] ?>">
                                        <button type="submit" class="btn btn-sm fbg-admin-void-button">Mark Void</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="refund_receipt">
                                        <input type="hidden" name="receipt_id" value="<?= (int)$viewReceipt['id'] ?>">
                                        <button type="submit" class="btn btn-sm fbg-admin-refund-button">Mark Refunded</button>
                                    </form>
                                <?php endif; ?>
                             </div>
                         </div>

                        <div class="fbg-admin-receipt-summary-grid">
                             <div class="fbg-admin-receipt-summary-card">
                                 <span>Status</span>
                                <strong><?= htmlspecialchars(fbgAdminReceiptsStatusLabel($detailStatus), ENT_QUOTES, 'UTF-8') ?></strong>
                             </div>
                            <div class="fbg-admin-receipt-summary-card">
                                <span>Total</span>
                                <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewReceipt['total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="fbg-admin-receipt-summary-card">
                                <span>Created</span>
                                <strong><?= htmlspecialchars(fbgAdminReceiptsDate($viewReceipt['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="fbg-admin-receipt-summary-card">
                                <span>Paid</span>
                                <strong><?= htmlspecialchars(fbgAdminReceiptsDate($viewReceipt['paid_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>

                        <div class="fbg-admin-table-wrap fbg-admin-receipt-line-items">
                            <table class="fbg-admin-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Qty</th>
                                        <th>Unit</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($viewReceipt['line_items'] ?? []) as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(number_format((float)($item['quantity'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgFormatFrontendReceiptUnitDisplay($item, $detailCurrency), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgFormatCredit((float)($item['line_total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="fbg-admin-receipt-lower-grid">
                            <div class="fbg-admin-receipt-payment-card">
                                <span>Payment Provider</span>
                                <strong><?= htmlspecialchars(fbgAdminReceiptsProviderLabel((string)($viewReceipt['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>

                            <div class="fbg-admin-receipt-totals">
                                <div>
                                    <span>Subtotal</span>
                                    <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewReceipt['subtotal'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <?php if ($hasTax): ?>
                                    <div>
                                        <span><?= htmlspecialchars($detailTaxLabel, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(number_format((float)($viewReceipt['tax_rate'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?>%</span>
                                        <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewReceipt['tax_amount'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="is-total">
                                    <span>Total</span>
                                    <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewReceipt['total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>
                        </div>

                        <hr class="fbg-admin-receipt-divider">

                        <h3 class="fbg-admin-receipt-section-title">Event History</h3>
                        <div class="fbg-admin-table-wrap">
                            <table class="fbg-admin-table">
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Note</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($viewReceipt['events'])): ?>
                                        <tr>
                                            <td colspan="3">No events have been recorded for this receipt.</td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach (($viewReceipt['events'] ?? []) as $event): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(ucwords(str_replace('-', ' ', (string)($event['event_type'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)($event['event_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgAdminReceiptsDate($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>
