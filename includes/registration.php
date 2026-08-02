<?php
declare(strict_types=1);

// registration.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registration-security.php';

function fbgPendingRegistrationDb(): PDO
{
    return db(); // change this if your DB helper uses a different function name
}

function fbgGenerateVerificationTokenPair(): array
{
    $selector = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));

    return [
        'selector' => $selector,
        'token' => $token,
        'token_hash' => hash('sha256', $token),
    ];
}

function fbgDeleteExpiredPendingRegistrations(): void
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'UPDATE pending_registrations
         SET rejection_reason = :rejection_reason,
             rejected_at = UTC_TIMESTAMP()
         WHERE consumed_at IS NULL
           AND email_verified_at IS NULL
           AND rejected_at IS NULL
           AND verification_expires_at < UTC_TIMESTAMP()'
    );

    $stmt->execute([
        ':rejection_reason' => FbgRegistrationRejectionReason::VERIFICATION_EXPIRED,
    ]);

    if (fbgRegistrationSettingBool('registration_cleanup_enabled', true)) {
        fbgCleanupExpiredPendingRegistrations();
    }
}

function fbgCleanupExpiredPendingRegistrations(): int
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();
    $retentionDays = fbgRegistrationRetentionDays();

    $stmt = $pdo->prepare(
        "DELETE FROM pending_registrations
         WHERE consumed_at IS NULL
           AND rejected_at IS NOT NULL
           AND rejected_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$retentionDays} DAY)"
    );

    $stmt->execute();
    $deleted = $stmt->rowCount();

    if ($deleted > 0) {
        error_log('Expired pending registration cleanup deleted ' . $deleted . ' row(s).');
    }

    return $deleted;
}

function fbgFindPendingRegistrationByEmail(string $email): ?array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'SELECT *
         FROM pending_registrations
         WHERE email = :email
           AND consumed_at IS NULL
           AND rejected_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([
        ':email' => $email,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgFindPendingRegistrationByUsername(string $username): ?array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'SELECT *
         FROM pending_registrations
         WHERE username = :username
           AND consumed_at IS NULL
           AND rejected_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([
        ':username' => $username,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgFindPendingRegistrationBySelector(string $selector): ?array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'SELECT *
         FROM pending_registrations
         WHERE verification_selector = :selector
           AND consumed_at IS NULL
           AND rejected_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([
        ':selector' => $selector,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgCreatePendingRegistration(array $data): array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $tokenPair = fbgGenerateVerificationTokenPair();

    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . fbgRegistrationVerificationExpiryHours() . ' hours')
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO pending_registrations (
            username,
            email,
            first_name,
            last_name,
            verification_selector,
            verification_token_hash,
            verification_expires_at,
            ip_address
         ) VALUES (
            :username,
            :email,
            :first_name,
            :last_name,
            :verification_selector,
            :verification_token_hash,
            :verification_expires_at,
            :ip_address
         )'
    );

    try {
        $stmt->execute([
            ':username' => (string)$data['username'],
            ':email' => (string)$data['email'],
            ':first_name' => (string)$data['first_name'],
            ':last_name' => (string)$data['last_name'],
            ':verification_selector' => $tokenPair['selector'],
            ':verification_token_hash' => $tokenPair['token_hash'],
            ':verification_expires_at' => $expiresAt,
            ':ip_address' => (string)($data['ip_address'] ?? fbgRegistrationClientIp()),
        ]);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Failed to save pending registration.',
        ];
    }

    return [
        'ok' => true,
        'id' => (int)$pdo->lastInsertId(),
        'selector' => $tokenPair['selector'],
        'token' => $tokenPair['token'],
        'expires_at' => $expiresAt,
    ];
}

function fbgVerifyPendingRegistrationToken(array $pendingRegistration, string $token): bool
{
    $expectedHash = (string)($pendingRegistration['verification_token_hash'] ?? '');

    if ($expectedHash === '' || $token === '') {
        return false;
    }

    return hash_equals($expectedHash, hash('sha256', $token));
}

function fbgMarkPendingRegistrationEmailVerified(int $id): bool
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'UPDATE pending_registrations
         SET email_verified_at = UTC_TIMESTAMP()
         WHERE id = :id
           AND consumed_at IS NULL
           AND email_verified_at IS NULL
           AND rejected_at IS NULL'
    );

    $stmt->execute([
        ':id' => $id,
    ]);

    return $stmt->rowCount() > 0;
}

function fbgMarkPendingRegistrationConsumed(int $id): bool
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'UPDATE pending_registrations
         SET consumed_at = UTC_TIMESTAMP()
         WHERE id = :id
           AND consumed_at IS NULL
           AND rejected_at IS NULL'
    );

    $stmt->execute([
        ':id' => $id,
    ]);

    return $stmt->rowCount() > 0;
}

function fbgIsPendingRegistrationExpired(array $pendingRegistration): bool
{
    $expiresAt = (string)($pendingRegistration['verification_expires_at'] ?? '');

    if ($expiresAt === '') {
        return true;
    }

    try {
        $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $expiry < $now;
    } catch (Throwable $e) {
        return true;
    }
}

function fbgIsPendingRegistrationVerified(array $pendingRegistration): bool
{
    return !empty($pendingRegistration['email_verified_at']);
}

function fbgIsPendingRegistrationConsumed(array $pendingRegistration): bool
{
    return !empty($pendingRegistration['consumed_at']);
}

function fbgFindPendingRegistrationByEmailForResend(string $email): ?array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $stmt = $pdo->prepare(
        'SELECT *
         FROM pending_registrations
         WHERE email = :email
           AND consumed_at IS NULL
           AND rejected_at IS NULL
         LIMIT 1'
    );

    $stmt->execute([
        ':email' => $email,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgPendingRegistrationResendCooldownRemaining(array $pendingRegistration): int
{
    $resentAt = (string)($pendingRegistration['verification_resent_at'] ?? '');
    if ($resentAt === '') {
        return 0;
    }

    try {
        $lastSentAt = new DateTimeImmutable($resentAt, new DateTimeZone('UTC'));
        $availableAt = $lastSentAt->modify('+' . fbgRegistrationResendCooldownSeconds() . ' seconds');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return max(0, $availableAt->getTimestamp() - $now->getTimestamp());
    } catch (Throwable $e) {
        return 0;
    }
}

function fbgRefreshPendingRegistrationVerificationToken(int $id): array
{
    fbgEnsurePendingRegistrationSecuritySchema();
    $pdo = fbgPendingRegistrationDb();

    $tokenPair = fbgGenerateVerificationTokenPair();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . fbgRegistrationVerificationExpiryHours() . ' hours')
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'UPDATE pending_registrations
         SET verification_selector = :selector,
             verification_token_hash = :token_hash,
             verification_expires_at = :expires_at,
             verification_resent_at = UTC_TIMESTAMP(),
             verification_resend_count = verification_resend_count + 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
           AND consumed_at IS NULL
           AND rejected_at IS NULL'
    );

    $stmt->execute([
        ':id' => $id,
        ':selector' => $tokenPair['selector'],
        ':token_hash' => $tokenPair['token_hash'],
        ':expires_at' => $expiresAt,
    ]);

    if ($stmt->rowCount() < 1) {
        return [
            'ok' => false,
            'error' => 'Could not refresh verification token.',
        ];
    }

    return [
        'ok' => true,
        'selector' => $tokenPair['selector'],
        'token' => $tokenPair['token'],
        'expires_at' => $expiresAt,
    ];
}
