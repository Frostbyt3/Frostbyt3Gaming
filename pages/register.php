<?php
// register.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../api/pterodactyl.php';
require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/mailer.php';

if ((int)fbgGetSetting('allow_public_registration', 1) !== 1) {
    echo    '<section class="auth-section">
                <div class="auth-card">
                    <div style="font-size: 5rem; text-align: center;"><i class="fas fa-user-slash"></i></div>
                    <h1>Registration is currently unavailable.</h1>
                    <p>We\'re prepping things behind the scenes — Check back soon.</p>
                    <div class="auth-footer">
                        Already have an account? <a href="/page.php?name=login">Sign in</a>
                    </div>
                </div>
            </section>';
    return;
}

if (!empty($_SESSION['user_id'])) {
    fbgRedirect('./page.php?name=dashboard');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = null;
$securityContext = [];
$genericRegistrationFailure = 'Registration could not be completed. Please try again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $errors[] = 'Security check failed. Please refresh and try again.';
    }

    $clientIp = fbgRegistrationClientIp();
    $securityRejection = empty($errors) ? fbgRegistrationValidateInvisibleSecurity($_POST) : null;

    if ($securityRejection !== null) {
        fbgRecordRejectedRegistrationAttempt([
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], $securityRejection, $clientIp);

        $errors[] = $genericRegistrationFailure;
    }

    if ($username === '' || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';

        if ($email !== '') {
            fbgRecordRejectedRegistrationAttempt([
                'username' => $username,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], FbgRegistrationRejectionReason::INVALID_EMAIL, $clientIp);
        }
    }

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'First and last name are required.';
    }

    if (empty($errors)) {
        $existingEmailUser = pteroFindUserByEmail($email);
        $existingUsernameUser = pteroFindUserByUsername($username);

        if ($existingEmailUser) {
            $errors[] = 'An account with that email already exists.';
        }

        if ($existingUsernameUser) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (empty($errors)) {
        fbgDeleteExpiredPendingRegistrations();

        $existingPendingEmail = fbgFindPendingRegistrationByEmail($email);
        $existingPendingUsername = fbgFindPendingRegistrationByUsername($username);

        if ($existingPendingEmail) {
            $errors[] = 'That email may already have a registration in progress. If needed, you can <a href="/page.php?name=resend-verification">resend</a> the verification email.';
        }

        if ($existingPendingUsername) {
            $errors[] = 'That username is already reserved by a pending registration.';
        }
    }

    if (empty($errors)) {
        $pendingResult = fbgCreatePendingRegistration([
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'ip_address' => $clientIp,
        ]);

        if (empty($pendingResult['ok'])) {
            $errors[] = $pendingResult['error'] ?? 'Failed to start registration.';
        } else {
            $baseUrl = isLocal()
                ? 'http://127.0.0.1'
                : 'https://frostbyt3gaming.com';

            $verificationUrl =
                $baseUrl . '/page.php?name=verify'
                . '&selector=' . urlencode((string)$pendingResult['selector'])
                . '&token=' . urlencode((string)$pendingResult['token']);

            try {
                $mailSent = fbgSendVerificationEmail([
                    'to_email' => $email,
                    'to_name' => trim($firstName . ' ' . $lastName),
                    'first_name' => $firstName,
                    'verification_url' => $verificationUrl,
                    'expires_at' => (string)$pendingResult['expires_at'],
                ]);
            } catch (Throwable $e) {
                $mailSent = false;
                $errors[] = 'Mailer error: ' . $e->getMessage();
            }

            if (!$mailSent) {
                $errors[] = 'Your registration was saved, but we could not send the verification email. Please try again.';
            } else {
                $success = 'Check your email for a verification link before your account can be created.';
                $_POST = [];
            }
        }
    }
}

$securityContext = fbgPrepareRegistrationFormSecurity();
?>

<section class="auth-section">

    <div class="auth-card">

        <h1>Create Account</h1>

        <?php if (!empty($success)): ?>
            <div class="fbg-dashboard-alert success is-visible">
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="fbg-dashboard-alert error is-visible">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo $error; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="./page.php?name=register">

            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="fbg-registration-honeypot" aria-hidden="true">
                <label for="registration-honeypot">Company Website</label>
                <input
                    id="registration-honeypot"
                    type="text"
                    name="<?php echo htmlspecialchars((string)$securityContext['honeypot_field'], ENT_QUOTES, 'UTF-8'); ?>"
                    value=""
                    autocomplete="off"
                    tabindex="-1">
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input
                    id="username"
                    type="text"
                    name="username"
                    placeholder="Username"
                    autocomplete="username"
                    required
                    value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="Email Address"
                    autocomplete="email"
                    required
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="first_name">First Name</label>
                <input
                    id="first_name"
                    type="text"
                    name="first_name"
                    placeholder="First Name"
                    autocomplete="given-name"
                    required
                    value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="last_name">Last Name</label>
                <input
                    id="last_name"
                    type="text"
                    name="last_name"
                    placeholder="Last Name"
                    autocomplete="family-name"
                    required
                    value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <p class="form-help-text">
                    Already signed up but didn't get the email?
                    <a href="./page.php?name=resend-verification">Resend verification</a>
                </p>
            </div>

            <button type="submit" class="btn">
                Create Account
            </button>

        </form>

        <div class="auth-footer">
            Already have an account?
            <a href="./page.php?name=login">Sign in</a>
        </div>

    </div>

</section>

<style>
.fbg-registration-honeypot {
    position: absolute;
    left: -10000px;
    top: auto;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
</style>
