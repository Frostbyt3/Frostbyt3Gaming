<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$invoiceId = (int)($_GET['id'] ?? 0);
$canViewAllInvoices = canAccess(4);
$invoice = fbgGetFrontendInvoiceDetail($invoiceId, $userId, $canViewAllInvoices);

if (!$invoice) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invoice not found.';
    exit;
}

$pdf = fbgCreateFrontendInvoicePdf($invoice);
$invoiceNumber = trim((string)($invoice['invoice_number'] ?? 'invoice')) ?: 'invoice';
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) ?: 'invoice';

fbgLogFrontendInvoiceEvent(
    (int)$invoice['id'],
    'downloaded',
    'Invoice PDF downloaded.',
    ['user_id' => $userId]
);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf;
exit;
