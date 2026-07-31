<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../api/pterodactyl.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];
$success = [];

if (empty($_SESSION['account_csrf_token'])) {
    $_SESSION['account_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['account_csrf_token'];

function fbgAccountValidatePassword(string $password, string $confirmPassword): array
{
    $errors = [];
    $weakPasswords = [
        'password',
        'password123',
        '12345678',
        '123456789',
        '1234567890',
        'qwerty',
        'qwerty123',
        'letmein',
        'welcome',
        'admin',
        'admin123',
        'abc123',
        'iloveyou',
    ];

    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one lowercase letter.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one uppercase letter.';
    }

    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must include at least one number.';
    }

    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = 'Password must include at least one special character.';
    }

    if (in_array(strtolower($password), $weakPasswords, true)) {
        $errors[] = 'That password is too common. Please choose a stronger one.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    return $errors;
}

function fbgAccountBaseUrl(): string
{
    return 'https://frostbyt3gaming.com';
}

$panelUser = pteroGetPanelUserById($userId);

if (!$panelUser) {
    $fallbackUser = fbgFindPanelUserCredentialsById($userId);

    if ($fallbackUser) {
        $panelUser = [
            'id' => (int)$fallbackUser['id'],
            'username' => (string)$fallbackUser['username'],
            'email' => (string)$fallbackUser['email'],
            'first_name' => (string)($fallbackUser['name_first'] ?? ''),
            'last_name' => (string)($fallbackUser['name_last'] ?? ''),
        ];
    }
}

if (!$panelUser) {
    $errors[] = 'Your account could not be loaded from Pterodactyl right now.';
    $panelUser = [
        'id' => $userId,
        'username' => (string)($_SESSION['username'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'first_name' => (string)($_SESSION['name'] ?? ''),
        'last_name' => '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['account_action'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    } elseif ($action === 'profile') {
        $username = trim((string)($_POST['username'] ?? ''));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));

        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            $errors[] = 'Username must be 3-32 characters and only use letters, numbers, dots, underscores, or hyphens.';
        }

        if ($firstName === '' || strlen($firstName) > 191) {
            $errors[] = 'First name is required and must be 191 characters or fewer.';
        }

        if ($lastName === '' || strlen($lastName) > 191) {
            $errors[] = 'Last name is required and must be 191 characters or fewer.';
        }

        if (empty($errors) && fbgAccountKnownUsername($username, $userId)) {
            $errors[] = 'That username is already in use.';
        }

        if (empty($errors)) {
            $result = pteroUpdatePanelUser($userId, [
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            if (empty($result['ok'])) {
                $errors[] = $result['error'] ?? 'Could not update your account details.';
            } else {
                $updatedUser = $result['data']['attributes'] ?? pteroGetPanelUserById($userId);
                if (is_array($updatedUser)) {
                    fbgRefreshLoggedInUserSession($updatedUser);
                    $panelUser = $updatedUser;
                }

                $success[] = 'Account details updated.';
            }
        }
    } elseif ($action === 'password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!fbgVerifyCurrentPanelPassword($userId, $currentPassword)) {
            $errors[] = 'Current password is incorrect.';
        }

        $errors = array_merge($errors, fbgAccountValidatePassword($newPassword, $confirmPassword));

        if ($currentPassword !== '' && hash_equals($currentPassword, $newPassword)) {
            $errors[] = 'New password must be different from your current password.';
        }

        if (empty($errors)) {
            $result = pteroUpdatePanelUser($userId, [
                'password' => $newPassword,
            ]);

            if (empty($result['ok'])) {
                $errors[] = $result['error'] ?? 'Could not update your password.';
            } else {
                fbgDeleteAllRememberedLoginsForUser($userId);
                fbgClearRememberCookie();
                $success[] = 'Password updated. Remembered logins on other devices were cleared.';
            }
        }
    } elseif ($action === 'email') {
        $currentPassword = (string)($_POST['email_current_password'] ?? '');
        $newEmail = strtolower(trim((string)($_POST['new_email'] ?? '')));
        $currentEmail = strtolower(trim((string)($panelUser['email'] ?? $_SESSION['email'] ?? '')));

        if (!fbgVerifyCurrentPanelPassword($userId, $currentPassword)) {
            $errors[] = 'Current password is incorrect.';
        }

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($newEmail === $currentEmail) {
            $errors[] = 'New email must be different from your current email.';
        }

        if (empty($errors) && fbgAccountKnownUserEmail($newEmail, $userId)) {
            $errors[] = 'That email address is already in use.';
        }

        if (empty($errors)) {
            $pending = fbgCreatePendingEmailChange($userId, $currentEmail, $newEmail);

            if (empty($pending['ok'])) {
                $errors[] = $pending['error'] ?? 'Could not create email verification.';
            } else {
                $verificationUrl = fbgAccountBaseUrl()
                    . '/page.php?name=verify-email-change'
                    . '&selector=' . urlencode((string)$pending['selector'])
                    . '&token=' . urlencode((string)$pending['token']);

                try {
                    $sent = fbgSendAccountEmailChangeVerification([
                        'to_email' => $newEmail,
                        'first_name' => (string)($panelUser['first_name'] ?? ''),
                        'verification_url' => $verificationUrl,
                    ]);
                } catch (Throwable $e) {
                    $sent = false;
                }

                if (!$sent) {
                    $errors[] = 'Could not send the verification email. Please try again later.';
                } else {
                    $success[] = 'Verification sent. Check your new email address to finish the change.';
                }
            }
        }
    }
}

$username = (string)($panelUser['username'] ?? $_SESSION['username'] ?? '');
$email = (string)($panelUser['email'] ?? $_SESSION['email'] ?? '');
$firstName = (string)($panelUser['first_name'] ?? $panelUser['name_first'] ?? '');
$lastName = (string)($panelUser['last_name'] ?? $panelUser['name_last'] ?? '');
?>

<section class="fbg-account-page">
    <div class="fbg-account-shell">
        <div class="fbg-account-header">
            <div>
                <h1>Manage Account</h1>
                <p>Update the identity and login details connected to your Pterodactyl account.</p>
            </div>
            <a href="./page.php?name=dashboard" class="btn fbg-neutral-button">
                Dashboard
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="fbg-dashboard-alert error is-visible">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="fbg-dashboard-alert success is-visible">
                <?php foreach ($success as $message): ?>
                    <div><?php echo htmlspecialchars($message); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="fbg-account-grid">
            <section class="fbg-account-section">
                <div class="fbg-settings-section-header">
                    <h3>Profile</h3>
                </div>

                <form method="post" action="./page.php?name=account" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="account_action" value="profile">

                    <div class="fbg-settings-field-grid">
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-username">Username</label>
                            <input id="account-username" class="fbg-text-input" type="text" name="username" value="<?php echo htmlspecialchars($username); ?>" autocomplete="username" required>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-email-current">Email</label>
                            <input id="account-email-current" class="fbg-text-input" type="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-first-name">First Name</label>
                            <input id="account-first-name" class="fbg-text-input" type="text" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" autocomplete="given-name" required>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-last-name">Last Name</label>
                            <input id="account-last-name" class="fbg-text-input" type="text" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" autocomplete="family-name" required>
                        </div>
                    </div>

                    <div class="fbg-settings-section-footer">
                        <button type="submit" class="btn fbg-primary-button">Save Profile</button>
                    </div>
                </form>
            </section>

            <section class="fbg-account-section">
                <div class="fbg-settings-section-header">
                    <h3>Change Email</h3>
                </div>

                <form method="post" action="./page.php?name=account" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="account_action" value="email">

                    <div class="fbg-settings-field-grid">
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-new-email">New Email</label>
                            <input id="account-new-email" class="fbg-text-input" type="email" name="new_email" autocomplete="email" required>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-email-password">Current Password</label>
                            <input id="account-email-password" class="fbg-text-input" type="password" name="email_current_password" autocomplete="current-password" required>
                        </div>
                    </div>

                    <p class="fbg-settings-note">A verification link will be sent to the new address before it replaces your current email.</p>

                    <div class="fbg-settings-section-footer">
                        <button type="submit" class="btn fbg-primary-button">Send Verification</button>
                    </div>
                </form>
            </section>

            <section class="fbg-account-section">
                <div class="fbg-settings-section-header">
                    <h3>Change Password</h3>
                </div>

                <form method="post" action="./page.php?name=account" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="account_action" value="password">

                    <div class="fbg-settings-field-grid">
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-current-password">Current Password</label>
                            <input id="account-current-password" class="fbg-text-input" type="password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-new-password">New Password</label>
                            <input id="account-new-password" class="fbg-text-input" type="password" name="new_password" autocomplete="new-password" required>
                        </div>
                        <div class="fbg-settings-field">
                            <label class="fbg-meta-label" for="account-confirm-password">Confirm Password</label>
                            <input id="account-confirm-password" class="fbg-text-input" type="password" name="confirm_password" autocomplete="new-password" required>
                        </div>
                    </div>

                    <p class="fbg-settings-note">Use 10+ characters with uppercase, lowercase, a number, and a special character.</p>

                    <div class="fbg-settings-section-footer">
                        <button type="submit" class="btn fbg-primary-button">Update Password</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</section>
