<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../pterodactyl.php';
require_once __DIR__ . '/../../../includes/auth.php';

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

    $serverStmt = $pdo->prepare(
        'SELECT id, product_id, expired_at, status
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
        throw new RuntimeException("You can't renew this server.");
    }

    $gameStmt = $pdo->prepare(
        'SELECT id, price
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

    $price = (float)($gameRow['price'] ?? 0);

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

    if ($currentCredit < $price) {
        throw new RuntimeException("You don't have enough balance to renew this server.");
    }

    $newCredit = $currentCredit - $price;

    $expiryBase = !empty($serverRow['expired_at'])
        ? new DateTimeImmutable((string)$serverRow['expired_at'])
        : new DateTimeImmutable('now');

    $newExpiry = $expiryBase->modify('+1 month');

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
        ':expired_at' => $newExpiry->format('Y-m-d H:i:s'),
        ':id'         => $serverId,
    ]);

    $pdo->commit();

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
        $_SESSION['server_meta'][$serverIdentifier]['expired_at'] = $newExpiry->format('Y-m-d H:i:s');
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

    $canRenewAgain = $newCredit >= $price;
    $renewWarning  = $canRenewAgain
        ? ''
        : 'You do not have enough balance to renew this server again.';

    settingsJsonResponse(200, [
        'ok'    => true,
        'error' => null,
        'data'  => [
            'message'             => 'Server renewed successfully.',
            'expired_at'          => $newExpiry->format('Y-m-d H:i:s'),
            'expired_at_display'  => $newExpiry->format('M j, Y g:i A'),
            'balance'             => round($newCredit, 2),
            'currency'            => $currency,
            'can_renew'           => $canRenewAgain,
            'renew_warning'       => $renewWarning,
            'unsuspend_warning'   => $unsuspendWarning,
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
