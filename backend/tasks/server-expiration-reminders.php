<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

function fbgEnsureServerExpirationNotificationTable(): bool
{
    if (!fbgEnsurePteroDbHelper()) {
        return false;
    }

    try {
        fbgPteroDb()->exec("
            CREATE TABLE IF NOT EXISTS server_expiration_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                server_id INT UNSIGNED NOT NULL,
                notification_type VARCHAR(32) NOT NULL,
                expiration_key DATETIME NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY server_expiration_notification_unique (server_id, notification_type, expiration_key),
                KEY server_expiration_notification_sent_idx (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return true;
    } catch (Throwable $e) {
        error_log('Could not ensure server_expiration_notifications table: ' . $e->getMessage());
        return false;
    }
}

function fbgHasSentServerExpirationNotification(int $serverId, string $notificationType, string $expirationKey): bool
{
    if ($serverId <= 0 || $notificationType === '' || $expirationKey === '' || !fbgEnsureServerExpirationNotificationTable()) {
        return false;
    }

    $stmt = fbgPteroDb()->prepare("
        SELECT id
        FROM server_expiration_notifications
        WHERE server_id = :server_id
          AND notification_type = :notification_type
          AND expiration_key = :expiration_key
        LIMIT 1
    ");
    $stmt->execute([
        ':server_id' => $serverId,
        ':notification_type' => $notificationType,
        ':expiration_key' => $expirationKey,
    ]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function fbgMarkServerExpirationNotificationSent(int $serverId, string $notificationType, string $expirationKey): void
{
    if ($serverId <= 0 || $notificationType === '' || $expirationKey === '' || !fbgEnsureServerExpirationNotificationTable()) {
        return;
    }

    $stmt = fbgPteroDb()->prepare("
        INSERT INTO server_expiration_notifications (
            server_id,
            notification_type,
            expiration_key,
            sent_at
        ) VALUES (
            :server_id,
            :notification_type,
            :expiration_key,
            NOW()
        )
        ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)
    ");
    $stmt->execute([
        ':server_id' => $serverId,
        ':notification_type' => $notificationType,
        ':expiration_key' => $expirationKey,
    ]);
}

function fbgGetServerExpirationReminderCandidates(): array
{
    if (!fbgEnsurePteroDbHelper()) {
        return [];
    }

    $stmt = fbgPteroDb()->query("
        SELECT
            s.id AS server_id,
            s.name AS server_name,
            s.expired_at,
            s.owner_id,
            u.email AS owner_email,
            u.name_first,
            u.name_last
        FROM servers s
        INNER JOIN users u ON u.id = s.owner_id
        WHERE s.expired_at IS NOT NULL
          AND s.expired_at <> '0000-00-00 00:00:00'
          AND u.email IS NOT NULL
          AND u.email <> ''
        ORDER BY s.expired_at ASC, s.id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function fbgBuildServerSettingsUrl(string $identifier): string
{
    return rtrim(fbgShopBaseUrl(), '/') . '/page.php?name=serverpanel&id=' . rawurlencode($identifier) . '&tab=settings#renew';
}

function fbgDetermineServerExpirationNotificationType(DateTimeImmutable $expiry, DateTimeImmutable $now): ?array
{
    $secondsRemaining = $expiry->getTimestamp() - $now->getTimestamp();
    $daysRemaining = (int)floor($secondsRemaining / 86400);

    if ($secondsRemaining < 0) {
        return [
            'type' => 'expired_notice',
            'days_remaining' => 0,
        ];
    }

    if ($daysRemaining >= 6 && $daysRemaining <= 7) {
        return [
            'type' => 'reminder_7_days',
            'days_remaining' => 7,
        ];
    }

    if ($daysRemaining >= 2 && $daysRemaining <= 3) {
        return [
            'type' => 'reminder_3_days',
            'days_remaining' => 3,
        ];
    }

    if ($daysRemaining >= 0 && $daysRemaining <= 1) {
        return [
            'type' => 'reminder_1_day',
            'days_remaining' => $daysRemaining,
        ];
    }

    return null;
}

$result = [
    'ok' => true,
    'checked' => 0,
    'sent' => 0,
    'skipped' => 0,
    'errors' => [],
];

if (!fbgEnsureServerExpirationNotificationTable()) {
    echo json_encode([
        'ok' => false,
        'error' => 'Could not initialize server expiration reminder storage.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$deleteDays = (int)fbgGetShopSetting('settings::shop::servers::days', '0');
$rows = fbgGetServerExpirationReminderCandidates();
$result['checked'] = count($rows);
$now = new DateTimeImmutable('now');

foreach ($rows as $row) {
    try {
        $serverId = (int)($row['server_id'] ?? 0);
        $serverName = trim((string)($row['server_name'] ?? ''));
        $ownerEmail = trim((string)($row['owner_email'] ?? ''));
        $firstName = trim((string)($row['first_name'] ?? ''));
        $expiredAtRaw = trim((string)($row['expired_at'] ?? ''));

        if ($serverId <= 0 || $serverName === '' || $ownerEmail === '' || $expiredAtRaw === '') {
            $result['skipped']++;
            continue;
        }

        $expiry = new DateTimeImmutable($expiredAtRaw);
        $notification = fbgDetermineServerExpirationNotificationType($expiry, $now);

        if ($notification === null) {
            $result['skipped']++;
            continue;
        }

        $notificationType = (string)$notification['type'];
        $daysRemaining = (int)$notification['days_remaining'];
        $expirationKey = $expiry->format('Y-m-d H:i:s');

        if (fbgHasSentServerExpirationNotification($serverId, $notificationType, $expirationKey)) {
            $result['skipped']++;
            continue;
        }

        $identifier = '';
        try {
            $panelResponse = fbgShopApplicationRequest('GET', 'servers/' . $serverId, [], 20);
            if (!empty($panelResponse['ok'])) {
                $identifier = trim((string)($panelResponse['data']['attributes']['identifier'] ?? ''));
            }
        } catch (Throwable $e) {
            $identifier = '';
        }

        if ($identifier === '') {
            $result['errors'][] = [
                'server_id' => $serverId,
                'server_name' => $serverName,
                'error' => 'Server identifier could not be loaded for email link generation.',
            ];
            continue;
        }

        $payload = [
            'to_email' => $ownerEmail,
            'first_name' => $firstName,
            'server_name' => $serverName,
            'settings_url' => fbgBuildServerSettingsUrl($identifier),
            'expires_at_display' => $expiry->format('M j, Y g:i A'),
        ];

        if ($notificationType === 'expired_notice') {
            $sent = fbgSendServerExpiredEmail($payload + [
                'delete_days' => $deleteDays,
            ]);
        } else {
            $sent = fbgSendServerExpiryReminderEmail($payload + [
                'days_remaining' => $daysRemaining,
            ]);
        }

        if ($sent) {
            fbgMarkServerExpirationNotificationSent($serverId, $notificationType, $expirationKey);
            $result['sent']++;
        } else {
            $result['errors'][] = [
                'server_id' => $serverId,
                'server_name' => $serverName,
                'error' => 'Email send returned false.',
            ];
        }
    } catch (Throwable $e) {
        $result['errors'][] = [
            'server_id' => (int)($row['server_id'] ?? 0),
            'server_name' => (string)($row['server_name'] ?? ''),
            'error' => $e->getMessage(),
        ];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
