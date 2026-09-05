<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

function settingsJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    settingsJsonResponse(405, [
        'ok'    => false,
        'error' => 'Method not allowed.',
        'data'  => null,
    ]);
}

if (!isset($_SESSION['user_id'])) {
    settingsJsonResponse(403, [
        'ok'    => false,
        'error' => 'Not authenticated.',
        'data'  => null,
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    settingsJsonResponse(400, [
        'ok'    => false,
        'error' => 'Invalid request body.',
        'data'  => null,
    ]);
}

$csrfToken        = (string)($input['csrf_token'] ?? '');
$serverIdentifier = trim((string)($input['id'] ?? ''));

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
) {
    settingsJsonResponse(403, [
        'ok'    => false,
        'error' => 'Security check failed.',
        'data'  => null,
    ]);
}

if ($serverIdentifier === '') {
    settingsJsonResponse(400, [
        'ok'    => false,
        'error' => 'Missing server identifier.',
        'data'  => null,
    ]);
}

try {
    pteroEnsureServerAccessSession(false);
    pteroRequireServerPermission($serverIdentifier, 'settings.rename');

    $server = pteroGetSessionServerMeta($serverIdentifier);

    if (empty($server) || empty($server['id'])) {
        pteroEnsureServerAccessSession(true);
        $server = pteroGetSessionServerMeta($serverIdentifier);
    }

    if (empty($server) || empty($server['id'])) {
        settingsJsonResponse(404, [
            'ok'    => false,
            'error' => 'Server not found.',
            'data'  => null,
        ]);
    }

    $panelUserId = (int)($_SESSION['user_id'] ?? 0);
    $serverId = (int)($server['id'] ?? 0);

    if ($serverId <= 0) {
        settingsJsonResponse(400, [
            'ok'    => false,
            'error' => 'Invalid server ID.',
            'data'  => null,
        ]);
    }

    $pdo = fbgPteroDb();
    $pdo->beginTransaction();

    $hasSuspendManualColumn = function_exists('fbgEnsurePteroServersSuspendManualColumn')
        ? fbgEnsurePteroServersSuspendManualColumn()
        : false;
    $suspendManualSelect = $hasSuspendManualColumn ? 'suspend_manual' : '0 AS suspend_manual';

    $serverStmt = $pdo->prepare(
        'SELECT id, name, product_id, expired_at, status, ' . $suspendManualSelect . '
         FROM servers
         WHERE id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $serverStmt->execute([
        ':id' => $serverId,
    ]);
    $serverRow = $serverStmt->fetch(PDO::FETCH_ASSOC);

    if (!$serverRow) {
        throw new RuntimeException('Server not found.');
    }

    if (empty($serverRow['product_id'])) {
        $repairedServerRow = fbgRepairShopServerMetadataFromDefaultName($serverId);

        if ($repairedServerRow) {
            $serverRow['product_id'] = $repairedServerRow['product_id'];
            $serverRow['expired_at'] = $repairedServerRow['expired_at'];
        }
    }

    if (empty($serverRow['product_id'])) {
        throw new RuntimeException("You can't renew this server.");
    }

    if (!empty($serverRow['suspend_manual'])) {
        throw new RuntimeException('This server cannot be renewed while it is manually suspended. Please contact support.');
    }

    if (empty($serverRow['expired_at'])) {
        throw new RuntimeException('This server is missing expiration information and cannot be renewed. Please contact support.');
    }

    $gameStmt = $pdo->prepare(
        'SELECT id, name, egg_id, price
         FROM games
         WHERE id = :id
         LIMIT 1'
    );
    $gameStmt->execute([
        ':id' => (int)$serverRow['product_id'],
    ]);
    $gameRow = $gameStmt->fetch(PDO::FETCH_ASSOC);

    if (!$gameRow) {
        throw new RuntimeException('Game package not found.');
    }

    $eggName = '';
    if (!empty($gameRow['egg_id']) && function_exists('fbgShopGetEggData')) {
        $eggRow = fbgShopGetEggData((int)$gameRow['egg_id']);
        $eggName = is_array($eggRow) ? (string)($eggRow['name'] ?? '') : '';
    }

    $price = round((float)($gameRow['price'] ?? 0), 2);
    $tax = fbgCalculateShopTax($price);
    $totalPrice = (float)$tax['total'];

    if ($price <= 0) {
        throw new RuntimeException("You can't renew this server.");
    }

    $userStmt = $pdo->prepare(
        'SELECT id, credit
         FROM users
         WHERE id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $userStmt->execute([
        ':id' => $panelUserId,
    ]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        throw new RuntimeException('User not found.');
    }

    $currentCredit = (float)($userRow['credit'] ?? 0);

    if ($currentCredit < $totalPrice) {
        throw new RuntimeException("You don't have enough balance to renew this server.");
    }

    $newCredit = $currentCredit - $totalPrice;
    $oldExpiredAt = fbgNormalizeExpirationHistoryValue((string)($serverRow['expired_at'] ?? ''));

    $expiryBase = new DateTimeImmutable((string)$serverRow['expired_at']);

    $newExpiry = $expiryBase->modify('+30 days');
    $newExpiryForDb = $newExpiry->format('Y-m-d H:i:s');

    $updateUserStmt = $pdo->prepare(
        'UPDATE users
         SET credit = :credit
         WHERE id = :id'
    );
    $updateUserStmt->execute([
        ':credit' => $newCredit,
        ':id'     => $panelUserId,
    ]);

    $updateServerStmt = $pdo->prepare(
        'UPDATE servers
         SET expired_at = :expired_at
         WHERE id = :id'
    );
    $updateServerStmt->execute([
        ':expired_at' => $newExpiryForDb,
        ':id'         => $serverId,
    ]);

    $pdo->commit();

    $actor = fbgCurrentExpirationHistoryActor();
    fbgTryRecordServerExpirationHistory(
        $serverId,
        'renewal',
        'frontend_renewal',
        $oldExpiredAt,
        $newExpiryForDb,
        $actor['user_id'] ?? null,
        $actor['label'] ?? null
    );

    session_write_close();

    $unsuspendWarning = null;
    if (($serverRow['status'] ?? '') === 'suspended') {
        $unsuspendResult = pteroUnsuspendServer($serverId);

        if (empty($unsuspendResult['ok'])) {
            $unsuspendWarning = 'Server renewed successfully, but it could not be unsuspended automatically. Please contact support.';
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (
        isset($_SESSION['server_meta']) &&
        is_array($_SESSION['server_meta']) &&
        isset($_SESSION['server_meta'][$serverIdentifier]) &&
        is_array($_SESSION['server_meta'][$serverIdentifier])
    ) {
        $_SESSION['server_meta'][$serverIdentifier]['expired_at'] = $newExpiryForDb;
        $_SESSION['server_meta'][$serverIdentifier]['is_expired'] = false;

        if ($unsuspendWarning === null) {
            $_SESSION['server_meta'][$serverIdentifier]['suspended'] = false;
        }
    }

    session_write_close();

    $currency = 'USD';

    $currencyStmt = $pdo->prepare(
        "SELECT value
         FROM settings
         WHERE `key` = 'settings::shop::currency'
         LIMIT 1"
    );
    $currencyStmt->execute();
    $currencyRow = $currencyStmt->fetch(PDO::FETCH_ASSOC);

    if ($currencyRow && !empty($currencyRow['value'])) {
        $currency = (string)$currencyRow['value'];
    }

    $canRenewAgain = $newCredit >= $totalPrice;
    $renewWarning  = $canRenewAgain
        ? ''
        : 'You do not have enough balance to renew this server again.';
    $receipt = fbgCreateFrontendReceiptForServerRenewal(
        $panelUserId,
        $serverId,
        (int)($gameRow['id'] ?? 0),
        (string)($gameRow['name'] ?? 'Game Server'),
        $price,
        $currency,
        $newExpiryForDb,
        $oldExpiredAt
    );

    settingsJsonResponse(200, [
        'ok'    => true,
        'error' => null,
        'data'  => [
            'message'             => 'Server renewed successfully.',
            'game_name'           => (string)($gameRow['name'] ?? 'Game Server'),
            'egg_name'            => $eggName,
            'confirmation_background_image' => function_exists('fbgResolveConfirmationBackgroundForContext')
                ? (string)fbgResolveConfirmationBackgroundForContext($eggName, (string)($gameRow['name'] ?? 'Game Server'), (string)($serverRow['name'] ?? ''))
                : '',
            'duration_days'       => 30,
            'old_expired_at'      => $oldExpiredAt,
            'old_expired_at_display' => $oldExpiredAt !== null && strtotime((string)$oldExpiredAt) !== false
                ? date('M j, Y', strtotime((string)$oldExpiredAt))
                : '',
            'expired_at'          => $newExpiry->format('Y-m-d H:i:s'),
            'expired_at_display'  => $newExpiry->format('M j, Y g:i A'),
            'expired_date_display' => $newExpiry->format('M j, Y'),
            'server_panel_url'    => '/page.php?name=serverpanel&id=' . rawurlencode($serverIdentifier),
            'dashboard_url'       => '/page.php?name=dashboard',
            'balance'             => round($newCredit, 2),
            'currency'            => $currency,
            'subtotal'            => (float)$tax['subtotal'],
            'tax_rate'            => (float)$tax['tax_rate'],
            'tax_amount'          => (float)$tax['tax_amount'],
            'total'               => $totalPrice,
            'can_renew'           => $canRenewAgain,
            'renew_warning'       => $renewWarning,
            'unsuspend_warning'   => $unsuspendWarning,
            'receipt'             => $receipt,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    settingsJsonResponse(400, [
        'ok'    => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to renew server.',
        'data'  => null,
    ]);
}
