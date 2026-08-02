<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/mailer.php';

$errors = [];
$success = null;

$email = trim((string)($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        fbgDeleteExpiredPendingRegistrations();

        $pending = fbgFindPendingRegistrationByEmailForResend($email);

        if (
            $pending
            && !fbgIsPendingRegistrationConsumed($pending)
            && !fbgIsPendingRegistrationVerified($pending)
            && fbgPendingRegistrationResendCooldownRemaining($pending) === 0
        ) {
            $refreshResult = fbgRefreshPendingRegistrationVerificationToken((int)$pending['id']);

            if (!empty($refreshResult['ok'])) {
                $verificationUrl =
                    'https://frostbyt3gaming.com/page.php?name=verify'
                    . '&selector=' . urlencode((string)$refreshResult['selector'])
                    . '&token=' . urlencode((string)$refreshResult['token']);

                fbgSendVerificationEmail([
                    'to_email' => (string)$pending['email'],
                    'to_name' => trim((string)$pending['first_name'] . ' ' . (string)$pending['last_name']),
                    'first_name' => (string)$pending['first_name'],
                    'verification_url' => $verificationUrl,
                    'expires_at' => (string)$refreshResult['expires_at'],
                ]);
            }
        }

        $success = 'if there\'s a pending registration for that email, we\'ll send a new verification link.';
        $email = '';
    }
}
?>

<section class="auth-section">
    <div class="auth-card">
        <h1>Resend Verification Email</h1>
        <p class="form-help-text">Enter the email address you used to register and we'll send a fresh verification link.</p>

        <?php if (!empty($errors)): ?>
            <div class="fbg-dashboard-alert error is-visible">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="fbg-dashboard-alert success is-visible">
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <form method="post" action="./page.php?name=resend-verification" novalidate>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="Email Address"
                    value="<?php echo htmlspecialchars($email); ?>"
                    autocomplete="email"
                    required>
            </div>

            <div class="auth-footer">
                <button type="submit" class="btn">Resend Verification Email</button>
            </div>
        </form>
    </div>
</section>
