<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

final class FbgRegistrationRejectionReason
{
    public const HONEYPOT_TRIGGERED = 'honeypot_triggered';
    public const SUBMITTED_TOO_FAST = 'submitted_too_fast';
    public const RATE_LIMITED = 'rate_limited';
    public const VERIFICATION_EXPIRED = 'verification_expired';
    public const INVALID_EMAIL = 'invalid_email';
    public const MANUAL_CLEANUP = 'manual_cleanup';

    public static function all(): array
    {
        return [
            self::HONEYPOT_TRIGGERED,
            self::SUBMITTED_TOO_FAST,
            self::RATE_LIMITED,
            self::VERIFICATION_EXPIRED,
            self::INVALID_EMAIL,
            self::MANUAL_CLEANUP,
        ];
    }
}

function fbgRegistrationSettingBool(string $key, bool $default): bool
{
    return (int)fbgGetSetting($key, $default ? 1 : 0) === 1;
}

function fbgRegistrationSettingInt(string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = (int)fbgGetSetting($key, $default);

    return max($min, min($max, $value));
}

function fbgRegistrationSettingString(string $key, string $default = ''): string
{
    return trim((string)fbgGetSetting($key, $default));
}

function fbgEnsurePendingRegistrationSecuritySchema(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $ensured = true;
    $pdo = db();

    $columns = [
        'rejection_reason' => "ALTER TABLE pending_registrations ADD COLUMN rejection_reason VARCHAR(64) NULL",
        'rejected_at' => "ALTER TABLE pending_registrations ADD COLUMN rejected_at DATETIME NULL",
        'ip_address' => "ALTER TABLE pending_registrations ADD COLUMN ip_address VARCHAR(45) NULL",
        'verification_resent_at' => "ALTER TABLE pending_registrations ADD COLUMN verification_resent_at DATETIME NULL",
        'verification_resend_count' => "ALTER TABLE pending_registrations ADD COLUMN verification_resend_count INT UNSIGNED NOT NULL DEFAULT 0",
        'approved_by_admin_id' => "ALTER TABLE pending_registrations ADD COLUMN approved_by_admin_id INT UNSIGNED NULL",
        'manual_approval_reason' => "ALTER TABLE pending_registrations ADD COLUMN manual_approval_reason TEXT NULL",
        'manually_approved_at' => "ALTER TABLE pending_registrations ADD COLUMN manually_approved_at DATETIME NULL",
    ];

    $existingStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'pending_registrations'
    ");
    $existingStmt->execute();
    $existing = array_flip(array_map('strtolower', $existingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

    foreach ($columns as $column => $sql) {
        if (isset($existing[strtolower($column)])) {
            continue;
        }

        $pdo->exec($sql);
    }
}

function fbgPendingRegistrationColumnExists(string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'pending_registrations'
        ");
        $stmt->execute();
        $columns = array_flip(array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    return isset($columns[strtolower($column)]);
}

function fbgRegistrationBaseUrl(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return 'https://frostbyt3gaming.com';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host;
}

function fbgRegistrationIpMatchesCidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }

    if (!str_contains($cidr, '/')) {
        return hash_equals($cidr, $ip);
    }

    [$network, $prefixLength] = explode('/', $cidr, 2);
    $networkPacked = @inet_pton(trim($network));
    $ipPacked = @inet_pton($ip);

    if ($networkPacked === false || $ipPacked === false || strlen($networkPacked) !== strlen($ipPacked)) {
        return false;
    }

    $prefix = max(0, min((int)$prefixLength, strlen($ipPacked) * 8));
    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($networkPacked, 0, $fullBytes) !== substr($ipPacked, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($networkPacked[$fullBytes]) & $mask) === (ord($ipPacked[$fullBytes]) & $mask);
}

function fbgRegistrationTrustedProxyCidrs(): array
{
    $raw = fbgRegistrationSettingString('registration_trusted_proxies', '');
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: [])));
}

function fbgRegistrationRemoteAddressIsTrustedProxy(string $remoteAddress): bool
{
    if ($remoteAddress === '') {
        return false;
    }

    foreach (fbgRegistrationTrustedProxyCidrs() as $cidr) {
        if (fbgRegistrationIpMatchesCidr($remoteAddress, $cidr)) {
            return true;
        }
    }

    return false;
}

function fbgRegistrationClientIp(): string
{
    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $clientIp = $remoteAddress;

    if ($remoteAddress !== '' && fbgRegistrationRemoteAddressIsTrustedProxy($remoteAddress)) {
        $cloudflareIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
            return $cloudflareIp;
        }

        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $firstForwardedIp = trim(explode(',', $forwardedFor)[0] ?? '');
            if (filter_var($firstForwardedIp, FILTER_VALIDATE_IP)) {
                return $firstForwardedIp;
            }
        }
    }

    return filter_var($clientIp, FILTER_VALIDATE_IP) ? $clientIp : '';
}

function fbgPrepareRegistrationFormSecurity(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $fieldName = 'company_website_' . bin2hex(random_bytes(8));
    $_SESSION['registration_honeypot_field'] = $fieldName;
    $_SESSION['registration_form_generated_at'] = microtime(true);

    return [
        'honeypot_field' => $fieldName,
    ];
}

function fbgRecordRejectedRegistrationAttempt(array $data, string $reason, ?string $ipAddress = null): void
{
    if (!in_array($reason, FbgRegistrationRejectionReason::all(), true)) {
        $reason = FbgRegistrationRejectionReason::MANUAL_CLEANUP;
    }

    try {
        fbgEnsurePendingRegistrationSecuritySchema();

        $tokenPair = function_exists('fbgGenerateVerificationTokenPair')
            ? fbgGenerateVerificationTokenPair()
            : [
                'selector' => bin2hex(random_bytes(8)),
                'token_hash' => hash('sha256', random_bytes(32)),
            ];

        $stmt = db()->prepare("
            INSERT INTO pending_registrations (
                username,
                email,
                first_name,
                last_name,
                verification_selector,
                verification_token_hash,
                verification_expires_at,
                rejection_reason,
                rejected_at,
                ip_address
            ) VALUES (
                :username,
                :email,
                :first_name,
                :last_name,
                :verification_selector,
                :verification_token_hash,
                UTC_TIMESTAMP(),
                :rejection_reason,
                UTC_TIMESTAMP(),
                :ip_address
            )
        ");

        $syntheticId = bin2hex(random_bytes(8));

        $stmt->execute([
            ':username' => 'rejected_' . $syntheticId,
            ':email' => 'rejected+' . $syntheticId . '@invalid.frostbyt3.local',
            ':first_name' => '',
            ':last_name' => '',
            ':verification_selector' => $tokenPair['selector'],
            ':verification_token_hash' => $tokenPair['token_hash'],
            ':rejection_reason' => $reason,
            ':ip_address' => $ipAddress ?? fbgRegistrationClientIp(),
        ]);

        error_log('Registration rejected: reason=' . $reason . ' ip=' . (string)($ipAddress ?? fbgRegistrationClientIp()));
    } catch (Throwable $e) {
        error_log('Failed to record rejected registration attempt: ' . $e->getMessage());
    }
}

function fbgRegistrationRateLimitExceeded(string $ipAddress): bool
{
    if ($ipAddress === '' || !fbgRegistrationSettingBool('registration_rate_limit_enabled', true)) {
        return false;
    }

    fbgEnsurePendingRegistrationSecuritySchema();

    $maxAttempts = fbgRegistrationSettingInt('registration_rate_limit_max_attempts', 5, 1, 1000);
    $windowSeconds = fbgRegistrationSettingInt('registration_rate_limit_window_seconds', 900, 60, 86400);
    $timestampColumn = fbgPendingRegistrationColumnExists('created_at') ? 'created_at' : 'rejected_at';

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM pending_registrations
        WHERE ip_address = :ip_address
        AND {$timestampColumn} IS NOT NULL
        AND {$timestampColumn} >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$windowSeconds} SECOND)
    ");
    $stmt->execute([':ip_address' => $ipAddress]);

    return (int)$stmt->fetchColumn() >= $maxAttempts;
}

function fbgRegistrationValidateInvisibleSecurity(array $post): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (fbgRegistrationSettingBool('registration_honeypot_enabled', true)) {
        $honeypotField = (string)($_SESSION['registration_honeypot_field'] ?? '');
        if ($honeypotField === '' || trim((string)($post[$honeypotField] ?? '')) !== '') {
            return FbgRegistrationRejectionReason::HONEYPOT_TRIGGERED;
        }
    }

    if (fbgRegistrationSettingBool('registration_timing_enabled', true)) {
        $generatedAt = (float)($_SESSION['registration_form_generated_at'] ?? 0);
        $minimumSeconds = fbgRegistrationSettingInt('registration_minimum_time_seconds', 3, 0, 3600);

        if ($minimumSeconds > 0 && ($generatedAt <= 0 || microtime(true) - $generatedAt < $minimumSeconds)) {
            return FbgRegistrationRejectionReason::SUBMITTED_TOO_FAST;
        }
    }

    $clientIp = fbgRegistrationClientIp();
    if (fbgRegistrationRateLimitExceeded($clientIp)) {
        return FbgRegistrationRejectionReason::RATE_LIMITED;
    }

    return null;
}

function fbgRegistrationVerificationExpiryHours(): int
{
    return fbgRegistrationSettingInt('registration_verification_expiration_hours', 24, 1, 720);
}

function fbgRegistrationResendCooldownSeconds(): int
{
    return fbgRegistrationSettingInt('registration_verification_resend_cooldown_seconds', 300, 0, 86400);
}

function fbgRegistrationRetentionDays(): int
{
    return fbgRegistrationSettingInt('registration_cleanup_retention_days', 14, 0, 3650);
}