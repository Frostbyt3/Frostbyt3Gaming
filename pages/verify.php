<?php
declare(strict_types=1);

// verify.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/registration.php';

$selector = trim((string)($_GET['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

$errors = [];
$success = null;

if ($selector === '' || $token === '') {
    $errors[] = 'Invalid verification link.';
}

if (empty($errors) && !preg_match('/^[a-f0-9]{16}$/', $selector)) {
    $errors[] = 'Invalid verification selector.';
}

if (empty($errors) && !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $errors[] = 'Invalid verification token.';
}

$pending = null;

if (empty($errors)) {
    fbgDeleteExpiredPendingRegistrations();

    $pending = fbgFindPendingRegistrationBySelector($selector);

    if (!$pending) {
        $errors[] = 'That verification link is invalid or has expired.';
    }
}

if (empty($errors) && fbgIsPendingRegistrationExpired($pending)) {
    $errors[] = 'That verification link has expired.';
}

if (empty($errors) && !fbgVerifyPendingRegistrationToken($pending, $token)) {
    $errors[] = 'That verification link is invalid.';
}

if (empty($errors) && fbgIsPendingRegistrationVerified($pending)) {
    fbgRedirect('./page.php?name=complete-registration&selector=' . urlencode($selector));
    exit;
}

if (empty($errors)) {
    $verified = fbgMarkPendingRegistrationEmailVerified((int)$pending['id']);

    if (!$verified) {
        $errors[] = 'We could not verify your email. Please try again.';
    }
}

if (empty($errors)) {
    fbgRedirect('./page.php?name=complete-registration&selector=' . urlencode($selector));
    exit;
}
?>

<section class="auth-section">
    <div class="auth-card">
        <h1>Verify Email</h1>

        <?php if (!empty($errors)): ?>
            <div class="fbg-dashboard-alert error is-visible">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>

            <div class="auth-footer">
                <p><a href="./page.php?name=resend-verification">Resend Verification</a></p>
                </br>
                <p><a href="./page.php?name=register">Back to Register</a></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="fbg-dashboard-alert success is-visible">
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>
    </div>
</section>