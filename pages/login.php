<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/secrets.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailOrUsername = trim((string)($_POST['email_or_username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    if ($emailOrUsername === '' || $password === '') {
        $error = 'Please enter your login credentials.';
    } else {
        $user = fbgFindPanelUserByLogin($emailOrUsername);

        if ($user && password_verify($password, $user['password'])) {
            fbgLogUserIn($user);

            if ($rememberMe) {
                fbgRememberLogin((int)$user['id']);
            }

            fbgRedirect('/page.php?name=dashboard');
            exit;
        } else {
            $error = 'Invalid login credentials.';
        }
    }
}
?>
<section class="auth-section">

    <div class="auth-card">

        <h1>Login</h1>

        <?php if ($error): ?>
            <div class="fbg-dashboard-alert error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="./page.php?name=login">

            <div class="form-group">
                <label for="email_or_username">Email or Username</label>
                <input
                    id="email_or_username"
                    type="text"
                    name="email_or_username"
                    placeholder="Email or Username"
                    autocomplete="username"
                    required
                    value="<?php echo htmlspecialchars($_POST['email_or_username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Password"
                    autocomplete="current-password"
                    required>
            </div>

            <div class="form-group">
                <label class="fbg-checkbox-label" for="remember_me">
                    <input
                        id="remember_me"
                        type="checkbox"
                        name="remember_me"
                        value="1"
                        <?php echo !empty($_POST['remember_me']) ? 'checked' : ''; ?>
                    >
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit" class="btn">Login</button>

        </form>

        <div class="auth-footer">
            Don't have an account?
            <a href="./page.php?name=register">Create one</a>
        </div>

    </div>

</section>