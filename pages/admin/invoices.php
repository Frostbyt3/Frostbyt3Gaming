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

$currentAdminPage = 'admin-invoices';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_invoices_message'] ?? '');
$messageType = (string)($_SESSION['admin_invoices_message_type'] ?? 'success');
unset($_SESSION['admin_invoices_message'], $_SESSION['admin_invoices_message_type']);

function fbgAdminInvoicesRedirect(string $message, string $type = 'success', ?int $viewInvoiceId = null): void
{
    $_SESSION['admin_invoices_message'] = $message;
    $_SESSION['admin_invoices_message_type'] = $type;

    $query = $_GET;
    $query['name'] = 'admin-invoices';

    if ($viewInvoiceId !== null && $viewInvoiceId > 0) {
        $query['view'] = $viewInvoiceId;
    } else {
        unset($query['view']);
    }

    fbgRedirect('/page.php?' . http_build_query($query));
    exit;
}

function fbgAdminInvoicesVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminInvoicesRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminInvoicesDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function fbgAdminInvoicesSourceLabel(string $sourceType): string
{
    return match ($sourceType) {
        'payment' => 'Balance Upload',
        'server_purchase' => 'Server Rental',
        'server_renewal' => 'Server Renewal',
        default => ucwords(str_replace('_', ' ', $sourceType ?: 'Invoice')),
    };
}

function fbgAdminInvoicesProviderLabel(string $provider): string
{
    $provider = trim($provider);
    if ($provider === '') {
        return '-';
    }

    return ucwords(str_replace('_', ' ', $provider));
}

function fbgAdminInvoicesStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'void' => 'Void',
        'refunded' => 'Refunded',
        default => 'Paid',
    };
}

function fbgAdminInvoicesBaseUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-invoices';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminInvoicesVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($action === 'resend_invoice' && $invoiceId > 0) {
        $sent = fbgResendFrontendInvoiceEmailNotification($invoiceId);
        fbgAdminInvoicesRedirect(
            $sent ? 'Invoice email resent successfully.' : 'The invoice email could not be resent.',
            $sent ? 'success' : 'error',
            $invoiceId
        );
    }

    if (in_array($action, ['void_invoice', 'refund_invoice'], true) && $invoiceId > 0) {
        $newStatus = $action === 'void_invoice' ? 'void' : 'refunded';
        $result = fbgUpdateFrontendInvoiceStatus(
            $invoiceId,
            $newStatus,
            $newStatus === 'void'
                ? 'Invoice was marked as void from the admin area.'
                : 'Invoice was marked as refunded from the admin area.',
            ['admin_action' => $action]
        );

        fbgAdminInvoicesRedirect(
            !empty($result['ok'])
                ? 'Invoice marked as ' . fbgAdminInvoicesStatusLabel($newStatus) . '.'
                : (string)($result['error'] ?? 'The invoice status could not be updated.'),
            !empty($result['ok']) ? 'success' : 'error',
            $invoiceId
        );
    }

    fbgAdminInvoicesRedirect('That invoice action is not available.', 'error', $invoiceId > 0 ? $invoiceId : null);
}

$search = trim((string)($_GET['search'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$sourceType = strtolower(trim((string)($_GET['source_type'] ?? '')));
$perPage = max(5, min(100, (int)($_GET['per_page'] ?? 25)));
$pageNum = fbgPaginationRequestedPage();
$viewInvoiceId = (int)($_GET['view'] ?? 0);
$viewInvoice = $viewInvoiceId > 0 ? fbgGetFrontendInvoiceDetail($viewInvoiceId, 1, true) : null;

$filters = [
    'search' => $search,
    'status' => $status,
    'source_type' => $sourceType,
];

$initialResults = fbgGetAdminFrontendInvoices($filters, $perPage, 0);
$pagination = fbgNormalizePagination((int)$initialResults['total'], $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$invoiceResults = fbgGetAdminFrontendInvoices($filters, $perPage, (int)$pagination['offset']);
$invoices = $invoiceResults['rows'];
$totalRows = (int)$invoiceResults['total'];
$currencyFallback = fbgGetShopCurrency();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Invoices</h1>
                <p class="fbg-admin-subtext">Review customer invoices, resend invoice emails, and inspect delivery history.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Invoices',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <div>
                        <h2>Invoice List</h2>
                        <p>Find invoices by invoice number, customer, email, username, or source ID.</p>
                    </div>
                </div>

                <form method="GET" class="fbg-admin-form-grid">
                    <input type="hidden" name="name" value="admin-invoices">

                    <div class="fbg-admin-field">
                        <label for="invoice-search">Search</label>
                        <input id="invoice-search" name="search" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Invoice, customer, email, source ID">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-status">Status</label>
                        <select id="invoice-status" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach (['paid', 'void', 'refunded'] as $option): ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $status === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($option), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-source-type">Type</label>
                        <select id="invoice-source-type" name="source_type">
                            <option value="">All Types</option>
                            <?php foreach (['payment', 'server_purchase', 'server_renewal'] as $option): ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $sourceType === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(fbgAdminInvoicesSourceLabel($option), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="invoice-per-page">Per Page</label>
                        <select id="invoice-per-page" name="per_page">
                            <?php foreach ([10, 25, 50, 100] as $option): ?>
                                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a class="btn fbg-neutral-button" href="./page.php?name=admin-invoices">Reset</a>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
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
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="9">No invoices found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $invoiceId = (int)($invoice['id'] ?? 0);
                                $currency = trim((string)($invoice['currency'] ?? '')) ?: $currencyFallback;
                                $customer = trim((string)($invoice['customer_name'] ?? ''));
                                if ($customer === '') {
                                    $customer = trim((string)($invoice['customer_username'] ?? 'Customer'));
                                }
                                ?>
                                <tr>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminInvoicesBaseUrl(['view' => $invoiceId]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)($invoice['invoice_number'] ?? ('Invoice #' . $invoiceId)), ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <small><?= htmlspecialchars((string)($invoice['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars(fbgAdminInvoicesSourceLabel((string)($invoice['source_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php $invoiceStatus = strtolower((string)($invoice['status'] ?? 'paid')); ?>
                                        <span class="fbg-admin-status-pill is-<?= htmlspecialchars($invoiceStatus, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(fbgAdminInvoicesStatusLabel($invoiceStatus), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(fbgFormatCredit((float)($invoice['total'] ?? 0), $currency), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminInvoicesProviderLabel((string)($invoice['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminInvoicesDate($invoice['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminInvoicesDate($invoice['paid_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="fbg-admin-table-actions">
                                            <a class="btn btn-sm fbg-neutral-button" href="<?= htmlspecialchars(fbgAdminInvoicesBaseUrl(['view' => $invoiceId]), ENT_QUOTES, 'UTF-8') ?>">View</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'invoice', ['remove' => ['view']]); ?>
            </section>

            <?php if ($viewInvoiceId > 0): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full fbg-admin-invoice-detail">
                    <?php if (!$viewInvoice): ?>
                        <div class="fbg-admin-empty-state">
                            <p>Invoice could not be found.</p>
                            <a class="btn fbg-neutral-button" href="<?= htmlspecialchars(fbgAdminInvoicesBaseUrl(['view' => null]), ENT_QUOTES, 'UTF-8') ?>">Back to Invoices</a>
                        </div>
                    <?php else: ?>
                        <?php
                        $detailCurrency = trim((string)($viewInvoice['currency'] ?? '')) ?: $currencyFallback;
                        $detailTaxLabel = trim((string)($viewInvoice['tax_label'] ?? 'Tax')) ?: 'Tax';
                        $detailStatus = strtolower((string)($viewInvoice['status'] ?? 'paid'));
                        $hasTax = round((float)($viewInvoice['tax_rate'] ?? 0), 4) > 0 || round((float)($viewInvoice['tax_amount'] ?? 0), 2) > 0;
                        ?>
                        <div class="fbg-admin-panel-header">
                            <div>
                                <h2><?= htmlspecialchars((string)($viewInvoice['invoice_number'] ?? 'Invoice'), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars(fbgAdminInvoicesSourceLabel((string)($viewInvoice['source_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)($viewInvoice['customer_name'] ?: $viewInvoice['customer_username'] ?: 'Customer'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="fbg-admin-table-actions">
                                 <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=invoice&id=<?= (int)$viewInvoice['id'] ?>" target="_blank" rel="noopener noreferrer">Open Customer View</a>
                                 <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=invoice-pdf&id=<?= (int)$viewInvoice['id'] ?>" target="_blank" rel="noopener noreferrer">Download PDF</a>
                                 <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="resend_invoice">
                                     <input type="hidden" name="invoice_id" value="<?= (int)$viewInvoice['id'] ?>">
                                     <button type="submit" class="btn btn-sm">Resend Email</button>
                                 </form>
                                <?php if ($detailStatus === 'paid'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="void_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= (int)$viewInvoice['id'] ?>">
                                        <button type="submit" class="btn btn-sm fbg-admin-void-button">Mark Void</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="refund_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= (int)$viewInvoice['id'] ?>">
                                        <button type="submit" class="btn btn-sm fbg-admin-refund-button">Mark Refunded</button>
                                    </form>
                                <?php endif; ?>
                             </div>
                         </div>

                        <div class="fbg-admin-invoice-summary-grid">
                             <div class="fbg-admin-invoice-summary-card">
                                 <span>Status</span>
                                <strong><?= htmlspecialchars(fbgAdminInvoicesStatusLabel($detailStatus), ENT_QUOTES, 'UTF-8') ?></strong>
                             </div>
                            <div class="fbg-admin-invoice-summary-card">
                                <span>Total</span>
                                <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewInvoice['total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="fbg-admin-invoice-summary-card">
                                <span>Created</span>
                                <strong><?= htmlspecialchars(fbgAdminInvoicesDate($viewInvoice['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="fbg-admin-invoice-summary-card">
                                <span>Paid</span>
                                <strong><?= htmlspecialchars(fbgAdminInvoicesDate($viewInvoice['paid_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>

                        <div class="fbg-admin-table-wrap fbg-admin-invoice-line-items">
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
                                    <?php foreach (($viewInvoice['line_items'] ?? []) as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(number_format((float)($item['quantity'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgFormatFrontendInvoiceUnitDisplay($item, $detailCurrency), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgFormatCredit((float)($item['line_total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="fbg-admin-invoice-lower-grid">
                            <div class="fbg-admin-invoice-payment-card">
                                <span>Payment Provider</span>
                                <strong><?= htmlspecialchars(fbgAdminInvoicesProviderLabel((string)($viewInvoice['payment_provider'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>

                            <div class="fbg-admin-invoice-totals">
                                <div>
                                    <span>Subtotal</span>
                                    <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewInvoice['subtotal'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <?php if ($hasTax): ?>
                                    <div>
                                        <span><?= htmlspecialchars($detailTaxLabel, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(number_format((float)($viewInvoice['tax_rate'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?>%</span>
                                        <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewInvoice['tax_amount'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="is-total">
                                    <span>Total</span>
                                    <strong><?= htmlspecialchars(fbgFormatCredit((float)($viewInvoice['total'] ?? 0), $detailCurrency), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>
                        </div>

                        <hr class="fbg-admin-invoice-divider">

                        <h3 class="fbg-admin-invoice-section-title">Event History</h3>
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
                                    <?php if (empty($viewInvoice['events'])): ?>
                                        <tr>
                                            <td colspan="3">No events have been recorded for this invoice.</td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach (($viewInvoice['events'] ?? []) as $event): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(ucwords(str_replace('-', ' ', (string)($event['event_type'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)($event['event_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(fbgAdminInvoicesDate($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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
