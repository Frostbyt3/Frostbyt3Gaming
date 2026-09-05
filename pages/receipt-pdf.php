<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$receiptId = (int)($_GET['id'] ?? 0);
$canViewAllReceipts = canAccess(4);
$receipt = fbgGetFrontendReceiptDetail($receiptId, $userId, $canViewAllReceipts);

if (!$receipt) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Receipt not found.';
    exit;
}

$pdf = fbgCreateFrontendReceiptPdf($receipt);
$receiptNumber = trim((string)($receipt['receipt_number'] ?? 'receipt')) ?: 'receipt';
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $receiptNumber) ?: 'receipt';

fbgLogFrontendReceiptEvent(
    (int)$receipt['id'],
    'downloaded',
    'Receipt PDF downloaded.',
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
