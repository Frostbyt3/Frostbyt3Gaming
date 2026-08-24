<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../api/pterodactyl.php';

$selector = trim((string)($_GET['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$errors = [];
$success = null;

if ($selector === '' || !preg_match('/^[a-f0-9]{16}$/', $selector)) {
    $errors[] = 'Invalid email verification link.';
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $errors[] = 'Invalid email verification token.';
}

$pending = null;

if (empty($errors)) {
    fbgDeleteExpiredPendingEmailChanges();
    $pending = fbgFindPendingEmailChangeBySelector($selector);

    if (!$pending) {
        $errors[] = 'That email change could not be found or has expired.';
    }
}

if (empty($errors) && fbgIsPendingEmailChangeExpired($pending)) {
    $errors[] = 'That email change has expired. Please start a new request from your account page.';
}

if (empty($errors) && !fbgVerifyPendingEmailChangeToken($pending, $token)) {
    $errors[] = 'That email verification token is invalid.';
}

if (empty($errors)) {
    $userId = (int)($pending['user_id'] ?? 0);
    $newEmail = strtolower(trim((string)($pending['new_email'] ?? '')));
    $existingUser = fbgAccountKnownUserEmail($newEmail, $userId);

    if ($existingUser) {
        $errors[] = 'That email address is already in use.';
    } else {
        $result = pteroUpdatePanelUser($userId, [
            'email' => $newEmail,
        ]);

        if (empty($result['ok'])) {
            $errors[] = $result['error'] ?? 'Could not update your email address.';
        } elseif (!fbgMarkPendingEmailChangeConsumed((int)$pending['id'])) {
            $errors[] = 'Your email was updated, but the verification record could not be finalized.';
        } else {
            fbgDeleteAllRememberedLoginsForUser($userId);

            if ((int)($_SESSION['user_id'] ?? 0) === $userId) {
                $updatedUser = $result['data']['attributes'] ?? pteroGetPanelUserById($userId);
                if (is_array($updatedUser)) {
                    fbgRefreshLoggedInUserSession($updatedUser);
                }
                fbgClearRememberCookie();
            }

            $success = 'Email address updated.';
        }
    }
}
?>

<section class="auth-section">
    <div class="auth-card">
        <h1>Email Verification</h1>

        <?php if (!empty($errors)): ?>
            <div class="fbg-dashboard-alert error is-visible">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>

            <p><a href="./page.php?name=account">Back to account</a></p>
        <?php else: ?>
            <div class="fbg-dashboard-alert success is-visible">
                <div><?php echo htmlspecialchars((string)$success); ?></div>
            </div>

            <p><a href="./page.php?name=account">Manage account</a></p>
        <?php endif; ?>
    </div>
</section>