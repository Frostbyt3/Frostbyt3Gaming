<?php
declare(strict_types=1);

// complete-registration.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/auth.php';

$selector = trim((string)($_GET['selector'] ?? ''));

$errors = [];
$success = null;

$password = '';
$confirmPassword = '';

if ($selector === '') {
    $errors[] = 'Invalid registration completion link.';
}

if (empty($errors) && !preg_match('/^[a-f0-9]{16}$/', $selector)) {
    $errors[] = 'Invalid registration selector.';
}

$pending = null;

if (empty($errors)) {
    fbgDeleteExpiredPendingRegistrations();

    $pending = fbgFindPendingRegistrationBySelector($selector);

    if (!$pending) {
        $errors[] = 'That registration could not be found or has expired.';
    }
}

if (empty($errors) && fbgIsPendingRegistrationConsumed($pending)) {
    $errors[] = 'That registration has already been completed.';
}

if (empty($errors) && !fbgIsPendingRegistrationVerified($pending)) {
    $errors[] = 'You must verify your email before completing registration.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $errors = array_merge($errors, fbgValidatePassword($password, $confirmPassword));

    if (empty($errors)) {
        $result = fbgCreatePterodactylUserFromPendingRegistration($pending, $password);

        if (empty($result['ok'])) {
            $errors[] = $result['error'] ?? 'Failed to create your account.';
        } else {
            $created = $result['data']['attributes'] ?? [];

            $_SESSION['user_id'] = (int)($created['id'] ?? 0);
            $_SESSION['username'] = $created['username'] ?? (string)$pending['username'];
            $_SESSION['email'] = $created['email'] ?? (string)$pending['email'];
            $_SESSION['name'] = trim(
                ((string)($created['first_name'] ?? $pending['first_name'])) . ' ' .
                ((string)($created['last_name'] ?? $pending['last_name']))
            );

            fbgRedirect('./page.php?name=dashboard');
            exit;
        }
    }
}
?>

<section class="auth-section">
    <div class="auth-card">
        <h1>Complete Registration</h1>
        <p class="form-help-text">Your email is verified. Set your password to finish creating your account.</p>

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

        <?php if ($pending && !fbgIsPendingRegistrationConsumed($pending) && fbgIsPendingRegistrationVerified($pending)): ?>
            <form method="post" action="./page.php?name=complete-registration&amp;selector=<?php echo urlencode($selector); ?>" novalidate>
                <div class="form-group">
                    <label>Email</label>
                    <input
                        type="email"
                        value="<?php echo htmlspecialchars((string)$pending['email']); ?>"
                        disabled>
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <input
                        type="text"
                        value="<?php echo htmlspecialchars((string)$pending['username']); ?>"
                        disabled>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <div class="fbg-password-input-wrap">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Password"
                            autocomplete="new-password"
                            required
                            aria-describedby="password-help password-strength-text">

                        <button
                            type="button"
                            class="fbg-password-toggle"
                            data-toggle-password="#password"
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show password">
                            <span class="fbg-password-toggle-icon" aria-hidden="true">
                                <i class="fas fa-eye-slash"></i>
                            </span>
                        </button>
                    </div>

                    <div class="password-strength-wrap">
                        <div class="password-strength-bar" aria-hidden="true">
                            <div id="password-strength-fill" class="password-strength-fill"></div>
                        </div>
                        <div id="password-strength-text" class="password-strength-text">Enter a password</div>
                    </div>

                    <div id="password-help" class="form-help-text">
                        Use 10+ characters with uppercase, lowercase, a number, and a special character.
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>

                    <div class="fbg-password-input-wrap">
                        <input
                            id="confirm_password"
                            type="password"
                            name="confirm_password"
                            placeholder="Confirm Password"
                            autocomplete="new-password"
                            required>

                        <button
                            type="button"
                            class="fbg-password-toggle"
                            data-toggle-password="#confirm_password"
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show password">
                            <span class="fbg-password-toggle-icon" aria-hidden="true">
                                <i class="fas fa-eye-slash"></i>
                            </span>
                        </button>
                    </div>

                    <div id="password-match-text" class="form-help-text"></div>
                </div>

                <button type="submit" class="btn">Create Account</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<script src="<?php echo asset('/backend/js/registration.js'); ?>"></script>