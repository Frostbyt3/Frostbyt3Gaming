<?php

    declare(strict_types=1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../config/secrets.php';

    const FBG_REMEMBER_COOKIE = 'fbg_remember';
    const FBG_REMEMBER_DAYS = 30;

    function fbgRequestIsHttps(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    function fbgRememberCookieDomain(): ?string
    {
        if (isLocal()) {
            return null;
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?: '';

        if ($host === 'frostbyt3gaming.com' || str_ends_with($host, '.frostbyt3gaming.com')) {
            return '.frostbyt3gaming.com';
        }

        return null;
    }

    function fbgIsSafeInternalRedirect(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (!str_starts_with($path, '/')) {
            return false;
        }

        if (str_starts_with($path, '//')) {
            return false;
        }

        if (preg_match('#^/[\\\\/]#', $path)) {
            return false;
        }

        if (str_contains($path, "\r") || str_contains($path, "\n")) {
            return false;
        }

        return true;
    }

    function fbgStoreRedirectAfterLogin(?string $requestUri = null): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        $requestUri ??= $_SERVER['REQUEST_URI'] ?? '';

        if (!is_string($requestUri) || $requestUri === '') {
            return;
        }

        if (!fbgIsSafeInternalRedirect($requestUri)) {
            return;
        }

        if (
            str_contains($requestUri, 'name=login') ||
            str_contains($requestUri, 'name=logout')
        ) {
            return;
        }

        $_SESSION['redirect_after_login'] = $requestUri;
    }

    function fbgGetRedirectAfterLogin(string $fallback = '/page.php?name=dashboard'): string
    {
        $redirect = $fallback;

        if (!empty($_SESSION['redirect_after_login']) && is_string($_SESSION['redirect_after_login'])) {
            $candidate = $_SESSION['redirect_after_login'];
            unset($_SESSION['redirect_after_login']);

            if (fbgIsSafeInternalRedirect($candidate)) {
                $redirect = $candidate;
            }
        }

        return $redirect;
    }

    function requireLogin(string $loginUrl = '/page.php?name=login'): void
    {
        if (isset($_SESSION['user_id'])) {
            return;
        }

        if (fbgAttemptRememberMeLogin()) {
            return;
        }

        fbgStoreRedirectAfterLogin();

        fbgRedirect($loginUrl);
        exit;
    }

    function fbgPteroDb(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        if (isLocal()) {
            $host = PTERO_DB_HOST_L;
            $name = PTERO_DB_NAME_L;
            $user = PTERO_DB_USER_L;
            $pass = PTERO_DB_PASS_L;
        } else {
            $host = PTERO_DB_HOST;
            $name = PTERO_DB_NAME;
            $user = PTERO_DB_USER;
            $pass = PTERO_DB_PASS;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $host,
            $name
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }

    function fbgFindPanelUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT id, username, email, name_first
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    function fbgFindPanelUserCredentialsById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT id, username, email, name_first, name_last, password
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    function fbgVerifyCurrentPanelPassword(int $userId, string $password): bool
    {
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $user = fbgFindPanelUserCredentialsById($userId);

        if (!$user || empty($user['password'])) {
            return false;
        }

        return password_verify($password, (string)$user['password']);
    }

    function fbgRefreshLoggedInUserSession(array $panelUser): void
    {
        $_SESSION['user_id'] = (int)($panelUser['id'] ?? $_SESSION['user_id'] ?? 0);
        $_SESSION['username'] = (string)($panelUser['username'] ?? $_SESSION['username'] ?? '');
        $_SESSION['email'] = (string)($panelUser['email'] ?? $_SESSION['email'] ?? '');

        $firstName = trim((string)($panelUser['first_name'] ?? $panelUser['name_first'] ?? ''));
        $lastName = trim((string)($panelUser['last_name'] ?? $panelUser['name_last'] ?? ''));
        $_SESSION['name'] = trim($firstName . ' ' . $lastName);
    }

    function fbgFindPanelUserByLogin(string $emailOrUsername): ?array
    {
        $emailOrUsername = trim($emailOrUsername);

        if ($emailOrUsername === '') {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT id, username, email, name_first, password
            FROM users
            WHERE email = :email OR username = :username
            LIMIT 1
        ");
        $stmt->execute([
            'email' => $emailOrUsername,
            'username' => $emailOrUsername,
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    function fbgLogUserIn(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)($user['id'] ?? 0);
        $_SESSION['username'] = $user['username'] ?? '';
        $_SESSION['email'] = $user['email'] ?? '';
        $_SESSION['name'] = $user['name_first'] ?? '';
    }

    function fbgRememberCookieParams(int $expiresTimestamp): array
    {
        $params = [
            'expires'  => $expiresTimestamp,
            'path'     => '/',
            'secure'   => fbgRequestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        $domain = fbgRememberCookieDomain();
        if ($domain !== null) {
            $params['domain'] = $domain;
        }

        return $params;
    }

    function fbgSetRememberCookie(string $selector, string $validator, int $expiresTimestamp): void
    {
        if (headers_sent()) {
            return;
        }

        $value = $selector . ':' . $validator;
        $params = fbgRememberCookieParams($expiresTimestamp);
        setcookie(FBG_REMEMBER_COOKIE, $value, $params);

        if (array_key_exists('domain', $params)) {
            unset($params['domain']);
            setcookie(FBG_REMEMBER_COOKIE, $value, $params);
        }
    }

    function fbgClearRememberCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $params = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => fbgRequestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie(FBG_REMEMBER_COOKIE, '', $params);

        $domain = fbgRememberCookieDomain();
        if ($domain !== null) {
            $params['domain'] = $domain;
            setcookie(FBG_REMEMBER_COOKIE, '', $params);
        }
    }

    function fbgDeleteAllRememberedLoginsForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = db()->prepare("
            DELETE FROM remembered_logins
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
    }

    function fbgDeleteRememberedLoginBySelector(string $selector): void
    {
        if ($selector === '') {
            return;
        }

        $stmt = db()->prepare("
            DELETE FROM remembered_logins
            WHERE selector = :selector
        ");
        $stmt->execute(['selector' => $selector]);
    }

    function fbgRememberLogin(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $validator);
        $expiresTimestamp = time() + (FBG_REMEMBER_DAYS * 86400);
        $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);

        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $stmt = db()->prepare("
            INSERT INTO remembered_logins (
                user_id,
                selector,
                token_hash,
                expires_at,
                user_agent,
                ip_address
            ) VALUES (
                :user_id,
                :selector,
                :token_hash,
                :expires_at,
                :user_agent,
                :ip_address
            )
        ");
        $stmt->execute([
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
        ]);

        fbgSetRememberCookie($selector, $validator, $expiresTimestamp);
    }

    function fbgRotateRememberLogin(int $userId, string $selector): void
    {
        fbgDeleteRememberedLoginBySelector($selector);
        fbgRememberLogin($userId);
    }

    function fbgRefreshRememberLoginExpiry(int $rememberedLoginId, string $selector, string $validator): void
    {
        if ($rememberedLoginId <= 0 || $selector === '' || $validator === '') {
            return;
        }

        $expiresTimestamp = time() + (FBG_REMEMBER_DAYS * 86400);
        $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);

        $stmt = db()->prepare("
            UPDATE remembered_logins
            SET expires_at = :expires_at,
                last_used_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'expires_at' => $expiresAt,
            'id' => $rememberedLoginId,
        ]);

        fbgSetRememberCookie($selector, $validator, $expiresTimestamp);
    }

    function fbgAttemptRememberMeLogin(): bool
    {
        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        $cookie = (string)($_COOKIE[FBG_REMEMBER_COOKIE] ?? '');

        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        if (
            $selector === '' ||
            $validator === '' ||
            !preg_match('/^[a-f0-9]{24}$/', $selector) ||
            !preg_match('/^[a-f0-9]{64}$/', $validator)
        ) {
            fbgClearRememberCookie();
            return false;
        }

        $stmt = db()->prepare("
            SELECT id, user_id, selector, token_hash, expires_at
            FROM remembered_logins
            WHERE selector = :selector
            LIMIT 1
        ");
        $stmt->execute(['selector' => $selector]);

        $row = $stmt->fetch();

        if (!$row) {
            fbgClearRememberCookie();
            return false;
        }

        if (strtotime((string)$row['expires_at']) < time()) {
            fbgDeleteRememberedLoginBySelector($selector);
            fbgClearRememberCookie();
            return false;
        }

        $expectedHash = (string)($row['token_hash'] ?? '');
        $actualHash = hash('sha256', $validator);

        if (!hash_equals($expectedHash, $actualHash)) {
            fbgDeleteRememberedLoginBySelector($selector);
            fbgClearRememberCookie();
            return false;
        }

        $userId = (int)($row['user_id'] ?? 0);
        $user = fbgFindPanelUserById($userId);

        if (!$user) {
            fbgDeleteRememberedLoginBySelector($selector);
            fbgClearRememberCookie();
            return false;
        }

        fbgLogUserIn($user);

        fbgRefreshRememberLoginExpiry((int)$row['id'], $selector, $validator);

        return true;
    }

    function fbgLogout(bool $forgetAllDevices = false): void
    {
        $cookie = (string)($_COOKIE[FBG_REMEMBER_COOKIE] ?? '');

        if ($forgetAllDevices && !empty($_SESSION['user_id'])) {
            fbgDeleteAllRememberedLoginsForUser((int)$_SESSION['user_id']);
        } elseif ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
                fbgDeleteRememberedLoginBySelector($selector);
            }
        }

        fbgClearRememberCookie();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }

        session_destroy();
    }
