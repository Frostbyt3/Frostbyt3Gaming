<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registration.php';

function fbgAccountKnownUserEmail(string $email, int $currentUserId): ?array
{
    if ($email === '' || !function_exists('pteroFindUserByEmail')) {
        return null;
    }

    $existing = pteroFindUserByEmail($email);

    if (!$existing) {
        return null;
    }

    return (int)($existing['id'] ?? 0) !== $currentUserId ? $existing : null;
}

function fbgAccountKnownUsername(string $username, int $currentUserId): ?array
{
    if ($username === '' || !function_exists('pteroFindUserByUsername')) {
        return null;
    }

    $existing = pteroFindUserByUsername($username);

    if (!$existing) {
        return null;
    }

    return (int)($existing['id'] ?? 0) !== $currentUserId ? $existing : null;
}

function fbgEnsureAccountEmailChangeTable(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS account_email_changes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            old_email VARCHAR(255) NOT NULL,
            new_email VARCHAR(255) NOT NULL,
            verification_selector CHAR(16) NOT NULL,
            verification_token_hash CHAR(64) NOT NULL,
            verification_expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY account_email_changes_selector_unique (verification_selector),
            KEY account_email_changes_user_active_idx (user_id, consumed_at),
            KEY account_email_changes_new_email_idx (new_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function fbgDeleteExpiredPendingEmailChanges(): void
{
    fbgEnsureAccountEmailChangeTable();

    $stmt = db()->prepare("
        DELETE FROM account_email_changes
        WHERE consumed_at IS NULL
          AND verification_expires_at < UTC_TIMESTAMP()
    ");
    $stmt->execute();
}

function fbgCreatePendingEmailChange(int $userId, string $oldEmail, string $newEmail): array
{
    fbgEnsureAccountEmailChangeTable();
    fbgDeleteExpiredPendingEmailChanges();

    $tokenPair = fbgGenerateVerificationTokenPair();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+24 hours')
        ->format('Y-m-d H:i:s');

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $closeStmt = $pdo->prepare("
            UPDATE account_email_changes
            SET consumed_at = UTC_TIMESTAMP()
            WHERE user_id = :user_id
              AND consumed_at IS NULL
        ");
        $closeStmt->execute([':user_id' => $userId]);

        $stmt = $pdo->prepare("
            INSERT INTO account_email_changes (
                user_id,
                old_email,
                new_email,
                verification_selector,
                verification_token_hash,
                verification_expires_at
            ) VALUES (
                :user_id,
                :old_email,
                :new_email,
                :selector,
                :token_hash,
                :expires_at
            )
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':old_email' => $oldEmail,
            ':new_email' => $newEmail,
            ':selector' => $tokenPair['selector'],
            ':token_hash' => $tokenPair['token_hash'],
            ':expires_at' => $expiresAt,
        ]);

        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        return [
            'ok' => false,
            'error' => 'Could not save the pending email change.',
        ];
    }

    return [
        'ok' => true,
        'id' => $id,
        'selector' => $tokenPair['selector'],
        'token' => $tokenPair['token'],
        'expires_at' => $expiresAt,
    ];
}

function fbgFindPendingEmailChangeBySelector(string $selector): ?array
{
    fbgEnsureAccountEmailChangeTable();

    $stmt = db()->prepare("
        SELECT *
        FROM account_email_changes
        WHERE verification_selector = :selector
          AND consumed_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':selector' => $selector]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgVerifyPendingEmailChangeToken(array $pendingEmailChange, string $token): bool
{
    $expectedHash = (string)($pendingEmailChange['verification_token_hash'] ?? '');

    if ($expectedHash === '' || $token === '') {
        return false;
    }

    return hash_equals($expectedHash, hash('sha256', $token));
}

function fbgIsPendingEmailChangeExpired(array $pendingEmailChange): bool
{
    $expiresAt = (string)($pendingEmailChange['verification_expires_at'] ?? '');

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

function fbgMarkPendingEmailChangeConsumed(int $id): bool
{
    fbgEnsureAccountEmailChangeTable();

    $stmt = db()->prepare("
        UPDATE account_email_changes
        SET verified_at = UTC_TIMESTAMP(),
            consumed_at = UTC_TIMESTAMP()
        WHERE id = :id
          AND consumed_at IS NULL
    ");
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}
