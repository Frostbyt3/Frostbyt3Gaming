<?php
declare(strict_types=1);

    require_once __DIR__ . '/db.php';

    function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    function isAllowedIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $stmt = db()->prepare("
            SELECT id
            FROM allowed_ips
            WHERE ip_address = :ip
            AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip]);

        return (bool) $stmt->fetch();
    }

    function getUserAccessLevel(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = db()->prepare("
            SELECT access_level
            FROM admin_access
            WHERE user_id = :user_id
            AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch();

        return $row ? (int)$row['access_level'] : 0;
    }

    function canAccess(int $requiredLevel = 4): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        /* =======================
           IP RESTRICTION START
           ======================= */
        /* $clientIp = getClientIp();

        if (!isAllowedIp($clientIp)) {
            return false;
        } */
        /* =======================
           IP RESTRICTION END
           ======================= */

        $userId = (int)$_SESSION['user_id'];

        return getUserAccessLevel($userId) >= $requiredLevel;
    }

    function fbgRedirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }

        echo '<script>window.location.href = ' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }

    function asset(string $path): string
    {
        $fullPath = __DIR__ . '/../' . ltrim($path, './');
        $version = file_exists($fullPath) ? filemtime($fullPath) : time();
        return $path . '?v=' . $version;
    }

    function getRandomTitle() {
        $messages = [
            "As seen on TV!",
            "Awesome!",
            "Stop being reasonable, this is the internet!",
            "Any computer is a laptop if you're brave enough.",
            "Very handsome!",
            "Limited edition!",
            "DUB Edition!",
            "The funniest thing around!",
            "The most exciting thing around!",
            "We're the best!",
            "You're the best!",
            "I don't know what I'm doing anymore!",
            "I can has cheezburger?",
            "Straight outta 1991!",
            "I didn't do it.",
            "Do your patch!",
            "Have you tried turning it off and back on again?",
            "An actual company now!",
            "Perfectly legal",
            "Arise, chicken, arise!",
            "Powered by caffeine",
            "May contain dragons",
            "Now with 10% more uptime!",
            "It's probably the DNS...",
            "Works on my server",
            "Deploy. Play. Repeat.",
            "No hamsters were harmed",
            "99 little bugs in the code...",
            "Ctrl+S is your friend",
            "Keep calm and restart the server",
            "Here be game servers",
            "Built by gamers, for gamers",
            "Have you backed up today?",
            "Adventure awaits!",
            "Powered by bad decisions and good coffee",
            "One more deployment...",
            "Certified Beach Bob approved",
            "No creepers beyond this point",
            "Your next adventure starts here!",
            "Cloudy with a chance of uptime",
            "404? Not here",
            "Running on duct tape and determination",
            "Powered by Linux",
            "Feature, not bug",
            "Shipped on Friday!",
            "May contain rubber ducks",
            "Your TPS is showing",
            "Rolling nat 20s since 2024",
            "Hello there!",
            "General Kenobi!",
            "The cake is not a lie",
            "The cake might actually be a lie",
            "Half-Life 3 confirmed",
            "Loading terrain...",
            "Punching trees since 2009",
            "Noobs welcome!",
            "Respawning soon...",
            "Achievement unlocked!",
            "Would survive a Creeper explosion",
            "Don't feed the gremlins",
            "Check the cable first",
            "Powered by hopes and dreams",
            "Compiling... please stand by",
            "May the source be with you",
            "Please don't press Alt+F4",
            "Works 99% of the time",
            "Some assembly required",
            "Welcome back, nerd",
            "Adventure is only a click away",
            "Your server misses you",
            "Guaranteed 100% less dial-up",
            "Because self-hosting is a personality trait",
            "Beach Bob approved",
            "Spawn responsibly",
            "No dodos were harmed",
            "Emergency maintenance complete!",
            "Dragons?",
            "I used to be an adventurer like you..."
        ];

        // If there is only one message, just return it.
        if (count($messages) <= 1) {
            return $messages[0] ?? '';
        }

        do {
            $title = $messages[array_rand($messages)];
        } while (
            isset($_SESSION['lastRandomTitle']) &&
            $title === $_SESSION['lastRandomTitle']
        );

        $_SESSION['lastRandomTitle'] = $title;

        return $title;
    }

    function getMinecraftStatus($host, $port, $timeout = 2)
{
  $server = ['online' => false, 'motd' => null, 'players' => 0, 'max' => 0, 'version' => null];

  $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
  if (!$sock)
    return $server;

  stream_set_timeout($sock, $timeout);

  // --- Handshake packet ---
  $data = pack('C', 0x00);                       // Packet ID
  $data .= pack('C', 0x04);                      // Protocol version (47 = 1.8, but 0x04 is fine for status ping)
  $data .= pack('C', strlen($host)) . $host;     // Host
  $data .= pack('n', $port);                     // Port
  $data .= pack('C', 0x01);                      // Next state: status

  $packet = pack('C', strlen($data)) . $data;
  fwrite($sock, $packet);

  // --- Request packet ---
  fwrite($sock, "\x01\x00");

  // --- Read response length ---
  $length = readVarInt($sock);
  if ($length < 10)
    return $server;

  $packetId = readVarInt($sock);
  if ($packetId != 0x00)
    return $server;

  $jsonLength = readVarInt($sock);
  $json = '';
  while (strlen($json) < $jsonLength) {
    $json .= fread($sock, $jsonLength - strlen($json));
  }

  fclose($sock);

  $data = json_decode($json, true);
  if ($data) {
    $server['online'] = true;
    $server['version'] = $data['version']['name'] ?? null;

    // Handle MOTD formatting (can be plain text or structured)
    if (is_array($data['description'])) {
      if (isset($data['description']['text'])) {
        $motd = $data['description']['text'];
      } elseif (isset($data['description']['extra'])) {
        $motd = implode("", array_column($data['description']['extra'], 'text'));
      } else {
        $motd = '';
      }
    } else {
      $motd = $data['description'];
    }
    $server['motd'] = strip_tags(preg_replace("/§./", "", $motd));

    $server['players'] = $data['players']['online'] ?? 0;
    $server['max'] = $data['players']['max'] ?? 0;
  }

  return $server;
}

function readVarInt($sock)
{
  $i = 0;
  $j = 0;
  while (true) {
    $k = ord(fread($sock, 1));
    $i |= ($k & 0x7F) << ($j++ * 7);
    if ($j > 5)
      throw new Exception('VarInt too big');
    if (($k & 0x80) != 128)
      break;
  }
  return $i;
}

function getSteamServerStatus($host, $port, $timeout = 2)
{
  $server = ['online' => false, 'name' => null, 'map' => null, 'players' => 0, 'max' => 0];

  $sock = @fsockopen("udp://$host", $port, $errno, $errstr, $timeout);
  if (!$sock)
    return $server;

  stream_set_timeout($sock, $timeout);
  stream_set_blocking($sock, true);

  // A2S_INFO request
  $packet = "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00";
  fwrite($sock, $packet);

  $data = fread($sock, 4096);
  if (!$data) {
    fclose($sock);
    return $server;
  }

  // Check if it's a challenge response (0x41)
  if (substr($data, 4, 1) === "\x41") {
    $challenge = substr($data, 5); // 4xFF + 0x41 = 5 bytes
    $packet = "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00" . $challenge;
    fwrite($sock, $packet);
    $data = fread($sock, 4096);
  }

  fclose($sock);

  if (!$data)
    return $server;

  // Parse response
  $data = substr($data, 4); // strip leading 0xFFs
  if (strlen($data) < 6)
    return $server;

  $server['online'] = true;

  // skip header (0x49 + protocol byte)
  $offset = 2;

  // Server name
  $end = strpos($data, "\x00", $offset);
  $server['name'] = substr($data, $offset, $end - $offset);
  $offset = $end + 1;

  // Map name
  $end = strpos($data, "\x00", $offset);
  $map = substr($data, $offset, $end - $offset);
  $offset = $end + 1;

  // Let's normalize the map field before it gets passed along
  if ($map === '' || strtolower($map) === 'server in lobby mode') {
    $server['map'] = 'Server in Lobby Mode';
  } else {
    $server['map'] = $map;
  }

  // Folder (skip)
  $end = strpos($data, "\x00", $offset);
  $offset = $end + 1;

  // Game (skip)
  $end = strpos($data, "\x00", $offset);
  $offset = $end + 1;

  // AppID (skip 2 bytes)
  $offset += 2;

  // Players
  $server['players'] = ord($data[$offset++]);
  $server['max'] = ord($data[$offset++]);

  return $server;
}

if (!function_exists('getGravatarUrl')) {
    function getGravatarUrl(string $email, int $size = 160, string $default = 'mp'): string
    {
        $normalizedEmail = strtolower(trim($email));
        $hash = md5($normalizedEmail);

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d={$default}";
    }
}

if (!function_exists('fbgEnsurePteroDbHelper')) {
    function fbgEnsurePteroDbHelper(): bool
    {
        if (function_exists('fbgPteroDb')) {
            return true;
        }

        $authPath = __DIR__ . '/auth.php';
        if (file_exists($authPath)) {
            require_once $authPath;
        }

        return function_exists('fbgPteroDb');
    }
}

if (!function_exists('fbgGetShopCurrency')) {
    function fbgGetShopCurrency(string $default = 'USD'): string
    {
        if (!fbgEnsurePteroDbHelper()) {
            return $default;
        }

        try {
            $stmt = fbgPteroDb()->prepare("
                SELECT value
                FROM settings
                WHERE `key` = 'settings::shop::currency'
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $currency = strtoupper(trim((string)($row['value'] ?? '')));

            return $currency !== '' ? $currency : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('fbgFormatCredit')) {
    function fbgFormatCredit(float $amount, ?string $currency = null): string
    {
        $currency = strtoupper(trim((string)($currency ?? fbgGetShopCurrency())));
        $currency = $currency !== '' ? $currency : 'USD';

        return number_format($amount, 2) . ' ' . $currency;
    }
}

if (!function_exists('fbgGetUserCreditBalance')) {
    function fbgGetUserCreditBalance(int $userId): float
    {
        if ($userId <= 0 || !fbgEnsurePteroDbHelper()) {
            return 0.0;
        }

        try {
            $stmt = fbgPteroDb()->prepare("
                SELECT credit
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? (float)($row['credit'] ?? 0) : 0.0;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('fbgGetUserPaymentHistory')) {
    function fbgGetUserPaymentHistory(int $userId, int $limit = 100): array
    {
        if ($userId <= 0 || !fbgEnsurePteroDbHelper()) {
            return [];
        }

        $limit = max(1, min(250, $limit));

        try {
            $stmt = fbgPteroDb()->prepare("
                SELECT id, payment_type, amount, invoice_number, session_id, completed, created_at
                FROM payments
                WHERE user_id = :user_id
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fbgEnsureShopServerPurchaseTable')) {
    function fbgEnsureShopServerPurchaseTable(): bool
    {
        if (!fbgEnsurePteroDbHelper()) {
            return false;
        }

        try {
            fbgPteroDb()->exec("
                CREATE TABLE IF NOT EXISTS shop_server_purchases (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    server_id INT UNSIGNED NOT NULL,
                    game_id INT UNSIGNED NOT NULL,
                    game_name VARCHAR(191) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY shop_server_purchases_server_id_unique (server_id),
                    KEY shop_server_purchases_user_created_idx (user_id, created_at),
                    KEY shop_server_purchases_game_id_idx (game_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('fbgRecordShopServerPurchase')) {
    function fbgRecordShopServerPurchase(
        int $userId,
        int $serverId,
        int $gameId,
        string $gameName,
        float $amount,
        ?string $currency = null
    ): void {
        if (
            $userId <= 0 ||
            $serverId <= 0 ||
            $gameId <= 0 ||
            !fbgEnsureShopServerPurchaseTable()
        ) {
            return;
        }

        try {
            $stmt = fbgPteroDb()->prepare("
                INSERT INTO shop_server_purchases (
                    user_id,
                    server_id,
                    game_id,
                    game_name,
                    amount,
                    currency,
                    created_at
                )
                VALUES (
                    :user_id,
                    :server_id,
                    :game_id,
                    :game_name,
                    :amount,
                    :currency,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    game_id = VALUES(game_id),
                    game_name = VALUES(game_name),
                    amount = VALUES(amount),
                    currency = VALUES(currency)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':server_id' => $serverId,
                ':game_id' => $gameId,
                ':game_name' => substr(trim($gameName), 0, 191),
                ':amount' => number_format(max(0, $amount), 2, '.', ''),
                ':currency' => strtoupper(substr(trim((string)($currency ?? fbgGetShopCurrency())), 0, 8)) ?: 'USD',
            ]);
        } catch (Throwable $e) {
            // History should never block a successful server purchase.
        }
    }
}

if (!function_exists('fbgGetUserServerPurchaseHistory')) {
    function fbgGetUserServerPurchaseHistory(int $userId, int $limit = 100): array
    {
        if ($userId <= 0 || !fbgEnsureShopServerPurchaseTable()) {
            return [];
        }

        $limit = max(1, min(250, $limit));

        try {
            $backfillStmt = fbgPteroDb()->prepare("
                INSERT IGNORE INTO shop_server_purchases (
                    user_id,
                    server_id,
                    game_id,
                    game_name,
                    amount,
                    currency,
                    created_at
                )
                SELECT
                    s.owner_id,
                    s.id,
                    g.id,
                    g.name,
                    g.price,
                    :currency,
                    COALESCE(s.created_at, NOW())
                FROM servers s
                INNER JOIN games g ON g.id = s.product_id
                WHERE s.owner_id = :user_id
                AND s.product_id IS NOT NULL
                AND s.product_id <> 0
            ");
            $backfillStmt->execute([
                ':currency' => fbgGetShopCurrency(),
                ':user_id' => $userId,
            ]);

            $stmt = fbgPteroDb()->prepare("
                SELECT
                    id,
                    server_id,
                    game_id,
                    game_name,
                    amount,
                    currency,
                    created_at
                FROM shop_server_purchases
                WHERE user_id = :user_id
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fbgGetShopSetting')) {
    function fbgGetShopSetting(string $key, string $default = ''): string
    {
        if ($key === '' || !fbgEnsurePteroDbHelper()) {
            return $default;
        }

        try {
            $stmt = fbgPteroDb()->prepare("
                SELECT value
                FROM settings
                WHERE `key` = :setting_key
                LIMIT 1
            ");
            $stmt->execute([':setting_key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row && isset($row['value']) ? (string)$row['value'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('fbgSetShopSetting')) {
    function fbgSetShopSetting(string $key, string $value): bool
    {
        if ($key === '' || !fbgEnsurePteroDbHelper()) {
            return false;
        }

        $stmt = fbgPteroDb()->prepare("
            INSERT INTO settings (`key`, value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");

        return $stmt->execute([
            ':setting_key'   => $key,
            ':setting_value' => $value,
        ]);
    }
}

if (!function_exists('fbgGetShopPaymentSettings')) {
    function fbgGetShopPaymentSettings(): array
    {
        return [
            'currency' => fbgGetShopCurrency(),
            'min_amount' => (float)fbgGetShopSetting('settings::shop::min_amount', '0'),
            'max_amount' => (float)fbgGetShopSetting('settings::shop::max_amount', '100'),
            'stripe_enabled' => (int)fbgGetShopSetting('settings::shop::stripe::enabled', '0') === 1,
            'stripe_mode' => fbgGetShopSetting('settings::shop::stripe::mode', 'live'),
            'stripe_key_configured' => fbgGetShopSetting('settings::shop::stripe::key', '') !== '',
            'stripe_secret_configured' => fbgGetShopSetting('settings::shop::stripe::secret', '') !== '',
            'paypal_enabled' => (int)fbgGetShopSetting('settings::shop::paypal::enabled', '0') === 1,
            'paypal_mode' => fbgGetShopSetting('settings::shop::paypal::mode', 'live'),
            'paypal_key_configured' => fbgGetShopSetting('settings::shop::paypal::key', '') !== '',
            'paypal_secret_configured' => fbgGetShopSetting('settings::shop::paypal::secret', '') !== '',
        ];
    }
}

if (!function_exists('fbgShopBaseUrl')) {
    function fbgShopBaseUrl(): string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return 'https://frostbyt3gaming.com';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!isLocal()) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }
}

if (!function_exists('fbgStripeRequest')) {
    function fbgStripeRequest(string $method, string $endpoint, array $params = []): array
    {
        $secret = fbgGetShopSetting('settings::shop::stripe::secret', '');

        if ($secret === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Stripe secret key is not configured.',
                'data' => null,
            ];
        }

        $method = strtoupper($method);
        $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            return [
                'ok' => false,
                'status' => $httpCode ?: 0,
                'error' => 'Stripe cURL error: ' . $curlErr,
                'data' => null,
            ];
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => 'Invalid JSON response from Stripe.',
                'data' => $response,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string)($decoded['error']['message'] ?? 'Stripe request failed.');

            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => $message,
                'data' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'status' => $httpCode,
            'error' => null,
            'data' => $decoded,
        ];
    }
}

if (!function_exists('fbgCreateStripeBalanceCheckout')) {
    function fbgCreateStripeBalanceCheckout(int $userId, float $amount): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Not authenticated.', 'redirect_url' => null];
        }

        $settings = fbgGetShopPaymentSettings();

        if (!$settings['stripe_enabled']) {
            return ['ok' => false, 'error' => 'Stripe payments are not enabled.', 'redirect_url' => null];
        }

        if (!$settings['stripe_secret_configured']) {
            return ['ok' => false, 'error' => 'Stripe secret key is not configured.', 'redirect_url' => null];
        }

        $minAmount = (float)$settings['min_amount'];
        $maxAmount = (float)$settings['max_amount'];

        if ($amount <= 0 || ($minAmount > 0 && $amount < $minAmount) || ($maxAmount > 0 && $amount > $maxAmount)) {
            return [
                'ok' => false,
                'error' => 'Enter an amount between ' . fbgFormatCredit($minAmount, $settings['currency']) . ' and ' . fbgFormatCredit($maxAmount, $settings['currency']) . '.',
                'redirect_url' => null,
            ];
        }

        $amount = round($amount, 2);
        $unitAmount = (int)round($amount * 100);
        $currency = strtolower((string)$settings['currency']);
        $baseUrl = fbgShopBaseUrl();
        $sessionParams = [
            'mode' => 'payment',
            'client_reference_id' => (string)$userId,
            'success_url' => $baseUrl . '/page.php?name=wallet&stripe_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $baseUrl . '/page.php?name=wallet&payment_cancelled=1',
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $unitAmount,
                        'product_data' => [
                            'name' => 'Frostbyt3 Gaming Account Balance',
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'fbg_user_id' => (string)$userId,
                'fbg_credit_amount' => number_format($amount, 2, '.', ''),
            ],
        ];

        $email = trim((string)($_SESSION['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sessionParams['customer_email'] = $email;
        }

        $stripeResult = fbgStripeRequest('POST', 'checkout/sessions', $sessionParams);

        if (empty($stripeResult['ok']) || empty($stripeResult['data']['id']) || empty($stripeResult['data']['url'])) {
            return [
                'ok' => false,
                'error' => (string)($stripeResult['error'] ?? 'Could not create Stripe checkout session.'),
                'redirect_url' => null,
            ];
        }

        $stmt = fbgPteroDb()->prepare("
            INSERT INTO payments (payment_type, user_id, amount, invoice_number, session_id, completed, created_at)
            VALUES ('stripe', :user_id, :amount, '', :session_id, 0, NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => number_format($amount, 2, '.', ''),
            ':session_id' => (string)$stripeResult['data']['id'],
        ]);

        return [
            'ok' => true,
            'error' => null,
            'redirect_url' => (string)$stripeResult['data']['url'],
        ];
    }
}

if (!function_exists('fbgCompleteStripeBalanceCheckout')) {
    function fbgCompleteStripeBalanceCheckout(int $userId, string $sessionId): array
    {
        if ($userId <= 0 || $sessionId === '') {
            return ['ok' => false, 'error' => 'Invalid payment session.'];
        }

        $stripeResult = fbgStripeRequest('GET', 'checkout/sessions/' . rawurlencode($sessionId));

        if (empty($stripeResult['ok']) || !is_array($stripeResult['data'])) {
            return [
                'ok' => false,
                'error' => (string)($stripeResult['error'] ?? 'Could not verify Stripe payment.'),
            ];
        }

        $session = $stripeResult['data'];

        if ((string)($session['payment_status'] ?? '') !== 'paid') {
            return ['ok' => false, 'error' => 'That payment has not completed yet.'];
        }

        $pdo = fbgPteroDb();
        $pdo->beginTransaction();

        try {
            $paymentStmt = $pdo->prepare("
                SELECT id, amount, completed
                FROM payments
                WHERE payment_type = 'stripe'
                AND session_id = :session_id
                AND user_id = :user_id
                LIMIT 1
                FOR UPDATE
            ");
            $paymentStmt->execute([
                ':session_id' => $sessionId,
                ':user_id' => $userId,
            ]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new RuntimeException('Payment record not found.');
            }

            if ((int)($payment['completed'] ?? 0) === 1) {
                $pdo->commit();
                return ['ok' => true, 'error' => null, 'message' => 'Payment already applied.'];
            }

            $amount = round((float)($payment['amount'] ?? 0), 2);
            $expectedTotal = (int)round($amount * 100);
            $actualTotal = (int)($session['amount_total'] ?? 0);
            $sessionUserId = (int)($session['client_reference_id'] ?? 0);

            if ($amount <= 0 || $actualTotal !== $expectedTotal || $sessionUserId !== $userId) {
                throw new RuntimeException('Payment verification failed.');
            }

            $userStmt = $pdo->prepare("
                SELECT credit
                FROM users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $newCredit = round((float)($user['credit'] ?? 0) + $amount, 2);

            $updateUserStmt = $pdo->prepare("
                UPDATE users
                SET credit = :credit
                WHERE id = :id
            ");
            $updateUserStmt->execute([
                ':credit' => number_format($newCredit, 2, '.', ''),
                ':id' => $userId,
            ]);

            $updatePaymentStmt = $pdo->prepare("
                UPDATE payments
                SET completed = 1
                WHERE id = :id
            ");
            $updatePaymentStmt->execute([':id' => (int)$payment['id']]);

            $pdo->commit();

            return [
                'ok' => true,
                'error' => null,
                'message' => 'Account balance updated.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not apply payment.',
            ];
        }
    }
}

if (!function_exists('fbgPayPalBaseUrl')) {
    function fbgPayPalBaseUrl(): string
    {
        $mode = strtolower(fbgGetShopSetting('settings::shop::paypal::mode', 'live'));

        return $mode === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}

if (!function_exists('fbgPayPalAccessToken')) {
    function fbgPayPalAccessToken(): array
    {
        $clientId = fbgGetShopSetting('settings::shop::paypal::key', '');
        $secret = fbgGetShopSetting('settings::shop::paypal::secret', '');

        if ($clientId === '' || $secret === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'PayPal credentials are not configured.',
                'token' => null,
            ];
        }

        $ch = curl_init(fbgPayPalBaseUrl() . '/v1/oauth2/token');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $clientId . ':' . $secret,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Language: en_US',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            return [
                'ok' => false,
                'status' => $httpCode ?: 0,
                'error' => 'PayPal cURL error: ' . $curlErr,
                'token' => null,
            ];
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => 'Invalid JSON response from PayPal.',
                'token' => null,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['access_token'])) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => (string)($decoded['error_description'] ?? $decoded['error'] ?? 'Could not authenticate with PayPal.'),
                'token' => null,
            ];
        }

        return [
            'ok' => true,
            'status' => $httpCode,
            'error' => null,
            'token' => (string)$decoded['access_token'],
        ];
    }
}

if (!function_exists('fbgPayPalRequest')) {
    function fbgPayPalRequest(string $method, string $endpoint, ?array $body = null): array
    {
        $tokenResult = fbgPayPalAccessToken();

        if (empty($tokenResult['ok']) || empty($tokenResult['token'])) {
            return [
                'ok' => false,
                'status' => (int)($tokenResult['status'] ?? 0),
                'error' => (string)($tokenResult['error'] ?? 'Could not authenticate with PayPal.'),
                'data' => null,
            ];
        }

        $url = fbgPayPalBaseUrl() . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $tokenResult['token'],
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES);

            if ($jsonBody === false) {
                curl_close($ch);

                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Failed to encode PayPal request body.',
                    'data' => null,
                ];
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            return [
                'ok' => false,
                'status' => $httpCode ?: 0,
                'error' => 'PayPal cURL error: ' . $curlErr,
                'data' => null,
            ];
        }

        $decoded = $response !== '' ? json_decode((string)$response, true) : [];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => 'Invalid JSON response from PayPal.',
                'data' => $response,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => (string)($decoded['message'] ?? $decoded['name'] ?? 'PayPal request failed.'),
                'data' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'status' => $httpCode,
            'error' => null,
            'data' => $decoded,
        ];
    }
}

if (!function_exists('fbgCreatePayPalBalanceCheckout')) {
    function fbgCreatePayPalBalanceCheckout(int $userId, float $amount): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Not authenticated.', 'redirect_url' => null];
        }

        $settings = fbgGetShopPaymentSettings();

        if (!$settings['paypal_enabled']) {
            return ['ok' => false, 'error' => 'PayPal payments are not enabled.', 'redirect_url' => null];
        }

        if (!$settings['paypal_key_configured'] || !$settings['paypal_secret_configured']) {
            return ['ok' => false, 'error' => 'PayPal credentials are not configured.', 'redirect_url' => null];
        }

        $minAmount = (float)$settings['min_amount'];
        $maxAmount = (float)$settings['max_amount'];

        if ($amount <= 0 || ($minAmount > 0 && $amount < $minAmount) || ($maxAmount > 0 && $amount > $maxAmount)) {
            return [
                'ok' => false,
                'error' => 'Enter an amount between ' . fbgFormatCredit($minAmount, $settings['currency']) . ' and ' . fbgFormatCredit($maxAmount, $settings['currency']) . '.',
                'redirect_url' => null,
            ];
        }

        $amount = round($amount, 2);
        $currency = strtoupper((string)$settings['currency']);
        $baseUrl = fbgShopBaseUrl();

        $paypalResult = fbgPayPalRequest('POST', 'v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'fbg-balance-' . $userId . '-' . time(),
                    'description' => 'Frostbyt3 Gaming Account Balance',
                    'custom_id' => (string)$userId,
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                ],
            ],
            'application_context' => [
                'brand_name' => 'Frostbyt3 Gaming',
                'landing_page' => 'LOGIN',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $baseUrl . '/page.php?name=wallet&payment_provider=paypal',
                'cancel_url' => $baseUrl . '/page.php?name=wallet&payment_cancelled=paypal',
            ],
        ]);

        if (empty($paypalResult['ok']) || empty($paypalResult['data']['id']) || empty($paypalResult['data']['links'])) {
            return [
                'ok' => false,
                'error' => (string)($paypalResult['error'] ?? 'Could not create PayPal checkout.'),
                'redirect_url' => null,
            ];
        }

        $approvalUrl = '';
        foreach ($paypalResult['data']['links'] as $link) {
            if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
                $approvalUrl = (string)$link['href'];
                break;
            }
        }

        if ($approvalUrl === '') {
            return [
                'ok' => false,
                'error' => 'PayPal approval link was not returned.',
                'redirect_url' => null,
            ];
        }

        $stmt = fbgPteroDb()->prepare("
            INSERT INTO payments (payment_type, user_id, amount, invoice_number, session_id, completed, created_at)
            VALUES ('paypal', :user_id, :amount, '', :session_id, 0, NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => number_format($amount, 2, '.', ''),
            ':session_id' => (string)$paypalResult['data']['id'],
        ]);

        return [
            'ok' => true,
            'error' => null,
            'redirect_url' => $approvalUrl,
        ];
    }
}

if (!function_exists('fbgCompletePayPalBalanceCheckout')) {
    function fbgCompletePayPalBalanceCheckout(int $userId, string $orderId): array
    {
        if ($userId <= 0 || $orderId === '') {
            return ['ok' => false, 'error' => 'Invalid PayPal payment session.'];
        }

        $pdo = fbgPteroDb();
        $pdo->beginTransaction();

        try {
            $paymentStmt = $pdo->prepare("
                SELECT id, amount, completed
                FROM payments
                WHERE payment_type = 'paypal'
                AND session_id = :session_id
                AND user_id = :user_id
                LIMIT 1
                FOR UPDATE
            ");
            $paymentStmt->execute([
                ':session_id' => $orderId,
                ':user_id' => $userId,
            ]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new RuntimeException('Payment record not found.');
            }

            if ((int)($payment['completed'] ?? 0) === 1) {
                $pdo->commit();
                return ['ok' => true, 'error' => null, 'message' => 'Payment already applied.'];
            }

            $amount = round((float)($payment['amount'] ?? 0), 2);

            if ($amount <= 0) {
                throw new RuntimeException('Payment verification failed.');
            }

            $captureResult = fbgPayPalRequest('POST', 'v2/checkout/orders/' . rawurlencode($orderId) . '/capture', []);

            if (empty($captureResult['ok']) || !is_array($captureResult['data'])) {
                throw new RuntimeException((string)($captureResult['error'] ?? 'Could not capture PayPal payment.'));
            }

            $capture = $captureResult['data'];
            if ((string)($capture['status'] ?? '') !== 'COMPLETED') {
                throw new RuntimeException('PayPal payment was not completed.');
            }

            $purchaseUnit = $capture['purchase_units'][0] ?? [];
            $paymentCapture = $purchaseUnit['payments']['captures'][0] ?? [];
            $capturedAmount = round((float)($paymentCapture['amount']['value'] ?? 0), 2);
            $capturedCurrency = strtoupper((string)($paymentCapture['amount']['currency_code'] ?? ''));
            $expectedCurrency = fbgGetShopCurrency();

            if (abs($capturedAmount - $amount) > 0.001 || $capturedCurrency !== strtoupper($expectedCurrency)) {
                throw new RuntimeException('PayPal payment amount verification failed.');
            }

            $userStmt = $pdo->prepare("
                SELECT credit
                FROM users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $newCredit = round((float)($user['credit'] ?? 0) + $amount, 2);

            $updateUserStmt = $pdo->prepare("
                UPDATE users
                SET credit = :credit
                WHERE id = :id
            ");
            $updateUserStmt->execute([
                ':credit' => number_format($newCredit, 2, '.', ''),
                ':id' => $userId,
            ]);

            $updatePaymentStmt = $pdo->prepare("
                UPDATE payments
                SET completed = 1
                WHERE id = :id
            ");
            $updatePaymentStmt->execute([':id' => (int)$payment['id']]);

            $pdo->commit();

            return [
                'ok' => true,
                'error' => null,
                'message' => 'Account balance updated.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not apply PayPal payment.',
            ];
        }
    }
}

if (!function_exists('fbgNormalizeShopImageUrl')) {
    function fbgNormalizeShopImageUrl(string $imageUrl): string
    {
        $imageUrl = trim($imageUrl);

        if ($imageUrl === '') {
            return './backend/img/Snowflake.png';
        }

        return $imageUrl;
    }
}

if (!function_exists('fbgGetShopCatalog')) {
    function fbgGetShopCatalog(): array
    {
        if (!fbgEnsurePteroDbHelper()) {
            return [];
        }

        try {
            $pdo = fbgPteroDb();

            $categoryStmt = $pdo->prepare("
                SELECT id, title, image_url, short_url, sort
                FROM game_category
                WHERE hide = 0
                ORDER BY sort ASC, id ASC
            ");
            $categoryStmt->execute();
            $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($categories) || empty($categories)) {
                return [];
            }

            $gameStmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    category_id,
                    image_url,
                    short_url,
                    egg_id,
                    cpu,
                    memory,
                    swap,
                    disk,
                    database_limit,
                    backup_limit,
                    allocation_limit,
                    node_ids,
                    price,
                    hide,
                    sort
                FROM games
                WHERE hide = 0
                ORDER BY sort ASC, id ASC
            ");
            $gameStmt->execute();
            $games = $gameStmt->fetchAll(PDO::FETCH_ASSOC);

            $gamesByCategory = [];
            foreach ($games as $game) {
                $categoryId = (int)($game['category_id'] ?? 0);
                $gamesByCategory[$categoryId][] = $game;
            }

            foreach ($categories as &$category) {
                $category['image_url'] = fbgNormalizeShopImageUrl((string)($category['image_url'] ?? ''));
                $category['games'] = array_map(static function (array $game): array {
                    $game['image_url'] = fbgNormalizeShopImageUrl((string)($game['image_url'] ?? ''));
                    $game['price'] = (float)($game['price'] ?? 0);
                    return $game;
                }, $gamesByCategory[(int)$category['id']] ?? []);
            }
            unset($category);

            return $categories;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fbgGetVisibleShopGame')) {
    function fbgGetVisibleShopGame(int $gameId): ?array
    {
        if ($gameId <= 0 || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT
                g.*,
                c.title AS category_title,
                c.short_url AS category_short_url
            FROM games g
            INNER JOIN game_category c ON c.id = g.category_id
            WHERE g.id = :id
            AND g.hide = 0
            AND c.hide = 0
            LIMIT 1
        ");
        $stmt->execute([':id' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        return $game ?: null;
    }
}

if (!function_exists('fbgShopNodeIds')) {
    function fbgShopNodeIds(string $nodeIds): array
    {
        $ids = [];

        foreach (explode(',', $nodeIds) as $id) {
            $id = (int)trim($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('fbgShopGetEggData')) {
    function fbgShopGetEggData(int $eggId): ?array
    {
        if ($eggId <= 0 || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT id, nest_id, startup, docker_images
            FROM eggs
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $eggId]);
        $egg = $stmt->fetch(PDO::FETCH_ASSOC);

        return $egg ?: null;
    }
}

if (!function_exists('fbgShopGetEggEnvironment')) {
    function fbgShopGetEggEnvironment(int $eggId): array
    {
        if ($eggId <= 0 || !fbgEnsurePteroDbHelper()) {
            return [];
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT env_variable, default_value
            FROM egg_variables
            WHERE egg_id = :egg_id
        ");
        $stmt->execute([':egg_id' => $eggId]);
        $variables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $environment = [];
        foreach ($variables as $variable) {
            $key = trim((string)($variable['env_variable'] ?? ''));
            if ($key !== '') {
                $environment[$key] = (string)($variable['default_value'] ?? '');
            }
        }

        return $environment;
    }
}

if (!function_exists('fbgShopSelectDockerImage')) {
    function fbgShopSelectDockerImage(string $dockerImages): string
    {
        $decoded = json_decode($dockerImages, true);

        if (is_array($decoded) && !empty($decoded)) {
            $first = reset($decoded);
            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        return trim($dockerImages);
    }
}

if (!function_exists('fbgShopSelectFreeAllocation')) {
    function fbgShopSelectFreeAllocation(array $nodeIds): ?array
    {
        if (empty($nodeIds) || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        shuffle($nodeIds);
        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));

        $stmt = fbgPteroDb()->prepare("
            SELECT id, node_id
            FROM allocations
            WHERE server_id IS NULL
            AND node_id IN ({$placeholders})
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute($nodeIds);
        $allocation = $stmt->fetch(PDO::FETCH_ASSOC);

        return $allocation ?: null;
    }
}

if (!function_exists('fbgShopDefaultServerName')) {
    function fbgShopDefaultServerName(array $game): string
    {
        $name = trim((string)($game['name'] ?? 'Game Server'));
        $owner = trim((string)($_SESSION['username'] ?? $_SESSION['name'] ?? 'Player'));

        return substr($owner . "'s " . $name, 0, 255);
    }
}

if (!function_exists('fbgShopApplicationRequest')) {
    function fbgShopApplicationRequest(string $method, string $endpoint, ?array $body = null, int $timeout = 90): array
    {
        if (!defined('PTERO_BASE_URL') || !defined('PTERO_API_KEY')) {
            require_once __DIR__ . '/../api/pterodactyl.php';
        }

        if (!defined('PTERO_API_KEY') || PTERO_API_KEY === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Pterodactyl API key is not configured.',
                'data' => null,
            ];
        }

        $url = rtrim(PTERO_BASE_URL, '/') . '/api/application/' . ltrim($endpoint, '/');
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => [
                'Accept: Application/vnd.pterodactyl.v1+json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . PTERO_API_KEY,
            ],
            CURLOPT_TIMEOUT        => max(20, $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);

            if ($jsonBody === false) {
                curl_close($ch);

                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Failed to encode request body as JSON.',
                    'data' => null,
                ];
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            return [
                'ok' => false,
                'status' => $httpCode ?: 0,
                'error' => 'cURL error: ' . $curlErr,
                'data' => null,
            ];
        }

        $decoded = null;
        if ($response !== '' && $response !== null) {
            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'ok' => false,
                    'status' => $httpCode,
                    'error' => 'Invalid JSON response from Pterodactyl Application API.',
                    'data' => $response,
                ];
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'status' => $httpCode,
                'error' => $decoded['errors'][0]['detail'] ?? 'Unknown API error',
                'data' => $decoded,
            ];
        }

        return [
            'ok' => true,
            'status' => $httpCode,
            'error' => null,
            'data' => $decoded,
        ];
    }
}

if (!function_exists('fbgShopPurchaseExternalId')) {
    function fbgShopPurchaseExternalId(int $userId, int $gameId): string
    {
        return sprintf(
            'fbg-shop-u%d-g%d-%s',
            $userId,
            $gameId,
            bin2hex(random_bytes(8))
        );
    }
}

if (!function_exists('fbgShopFindProvisionedServerByExternalId')) {
    function fbgShopFindProvisionedServerByExternalId(string $externalId): ?array
    {
        if ($externalId === '' || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT id, uuidShort AS identifier, name
            FROM servers
            WHERE external_id = :external_id
            LIMIT 1
        ");
        $stmt->execute([':external_id' => $externalId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        return $server ?: null;
    }
}

if (!function_exists('fbgShopInitialServerExpiry')) {
    function fbgShopInitialServerExpiry(): string
    {
        return (new DateTimeImmutable('today'))
            ->modify('+30 days')
            ->format('Y-m-d H:i:s');
    }
}

if (!function_exists('fbgRefundShopPurchaseCredit')) {
    function fbgRefundShopPurchaseCredit(int $userId, float $amount): void
    {
        if ($userId <= 0 || $amount <= 0 || !fbgEnsurePteroDbHelper()) {
            return;
        }

        $stmt = fbgPteroDb()->prepare("
            UPDATE users
            SET credit = credit + :amount
            WHERE id = :id
        ");
        $stmt->execute([
            ':amount' => number_format($amount, 2, '.', ''),
            ':id' => $userId,
        ]);
    }
}

if (!function_exists('fbgApplyShopServerPurchaseMetadata')) {
    function fbgApplyShopServerPurchaseMetadata(int $serverId, int $gameId, string $expiresAt): void
    {
        if ($serverId <= 0 || $gameId <= 0 || !fbgEnsurePteroDbHelper()) {
            throw new RuntimeException('Server purchase metadata could not be saved.');
        }

        $stmt = fbgPteroDb()->prepare("
            UPDATE servers
            SET product_id = :product_id,
                expired_at = :expired_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':product_id' => $gameId,
            ':expired_at' => $expiresAt,
            ':id' => $serverId,
        ]);

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Server purchase metadata could not be saved.');
        }

        fbgTryRecordServerExpirationHistory(
            $serverId,
            'provision',
            'frontend',
            null,
            $expiresAt,
            !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            !empty($_SESSION['username']) ? (string)$_SESSION['username'] : 'Frontend Provisioning'
        );
    }
}

if (!function_exists('fbgRepairShopServerMetadataFromDefaultName')) {
    function fbgRepairShopServerMetadataFromDefaultName(int $serverId): ?array
    {
        if ($serverId <= 0 || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        $pdo = fbgPteroDb();
        $serverStmt = $pdo->prepare("
            SELECT
                s.id,
                s.name,
                s.product_id,
                s.expired_at,
                u.username AS owner_username
            FROM servers s
            LEFT JOIN users u ON u.id = s.owner_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $serverStmt->execute([':id' => $serverId]);
        $server = $serverStmt->fetch(PDO::FETCH_ASSOC);

        if (!$server || !empty($server['product_id'])) {
            return null;
        }

        $serverName = trim((string)($server['name'] ?? ''));
        $ownerUsername = trim((string)($server['owner_username'] ?? ''));

        if ($serverName === '' || $ownerUsername === '') {
            return null;
        }

        $gameStmt = $pdo->prepare("
            SELECT id, name, price
            FROM games
            ORDER BY LENGTH(name) DESC, name ASC
        ");
        $gameStmt->execute();
        $games = $gameStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($games as $game) {
            $expectedName = $ownerUsername . "'s " . trim((string)($game['name'] ?? ''));

            if ($expectedName !== $serverName) {
                continue;
            }

            $expiresAt = !empty($server['expired_at'])
                ? (new DateTimeImmutable((string)$server['expired_at']))->format('Y-m-d H:i:s')
                : fbgShopInitialServerExpiry();

            $updateStmt = $pdo->prepare("
                UPDATE servers
                SET product_id = :product_id,
                    expired_at = :expired_at
                WHERE id = :id
                AND (product_id IS NULL OR product_id = 0)
            ");
            $updateStmt->execute([
                ':product_id' => (int)$game['id'],
                ':expired_at' => $expiresAt,
                ':id' => $serverId,
            ]);

            return [
                'product_id' => (int)$game['id'],
                'expired_at' => $expiresAt,
                'price' => (float)($game['price'] ?? 0),
            ];
        }

        return null;
    }
}

if (!function_exists('fbgPurchaseShopGame')) {
    function fbgPurchaseShopGame(int $userId, int $gameId): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Not authenticated.', 'data' => null];
        }

        if (!function_exists('pteroRequest')) {
            require_once __DIR__ . '/../api/pterodactyl.php';
        }

        $pdo = fbgPteroDb();
        $game = fbgGetVisibleShopGame($gameId);

        if (!$game) {
            return ['ok' => false, 'error' => 'Server plan not found.', 'data' => null];
        }

        $price = round((float)($game['price'] ?? 0), 2);
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'This server plan cannot be purchased right now.', 'data' => null];
        }

        $nodeIds = fbgShopNodeIds((string)($game['node_ids'] ?? ''));
        if (empty($nodeIds)) {
            return ['ok' => false, 'error' => 'This server plan has no nodes configured.', 'data' => null];
        }

        $egg = fbgShopGetEggData((int)($game['egg_id'] ?? 0));
        if (!$egg) {
            return ['ok' => false, 'error' => 'This server plan has no valid egg configured.', 'data' => null];
        }

        $allocation = fbgShopSelectFreeAllocation($nodeIds);
        if (!$allocation) {
            return ['ok' => false, 'error' => 'There are no available allocations for this plan.', 'data' => null];
        }

        $dockerImage = fbgShopSelectDockerImage((string)($egg['docker_images'] ?? ''));
        if ($dockerImage === '') {
            return ['ok' => false, 'error' => 'This server plan has no Docker image configured.', 'data' => null];
        }

        try {
            $currentCredit = 0.0;
            $newCredit = 0.0;
            $creditReserved = false;
            $serverCreated = false;
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare("
                SELECT credit
                FROM users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $currentCredit = round((float)($user['credit'] ?? 0), 2);
            if ($currentCredit < $price) {
                throw new RuntimeException("You don't have enough account balance for this server.");
            }

            $newCredit = round($currentCredit - $price, 2);
            $creditStmt = $pdo->prepare("
                UPDATE users
                SET credit = :credit
                WHERE id = :id
            ");
            $creditStmt->execute([
                ':credit' => number_format($newCredit, 2, '.', ''),
                ':id' => $userId,
            ]);

            $pdo->commit();
            $creditReserved = true;

            $externalId = fbgShopPurchaseExternalId($userId, $gameId);

            $payload = [
                'name' => fbgShopDefaultServerName($game),
                'external_id' => $externalId,
                'user' => $userId,
                'egg' => (int)$egg['id'],
                'docker_image' => $dockerImage,
                'startup' => (string)($egg['startup'] ?? ''),
                'environment' => fbgShopGetEggEnvironment((int)$egg['id']),
                'limits' => [
                    'memory' => (int)($game['memory'] ?? 0),
                    'swap' => (int)($game['swap'] ?? 0),
                    'disk' => (int)($game['disk'] ?? 0),
                    'io' => 500,
                    'cpu' => (int)($game['cpu'] ?? 0),
                    'oom_disabled' => false,
                ],
                'feature_limits' => [
                    'databases' => (int)($game['database_limit'] ?? 0),
                    'allocations' => (int)($game['allocation_limit'] ?? 0),
                    'backups' => (int)($game['backup_limit'] ?? 0),
                ],
                'allocation' => [
                    'default' => (int)$allocation['id'],
                ],
                'start_on_completion' => true,
            ];

            $createResult = fbgShopApplicationRequest('POST', 'servers', $payload, 60);
            $provisionWarning = null;

            if (empty($createResult['ok'])) {
                $serverAttrs = null;

                for ($attempt = 0; $attempt < 10; $attempt++) {
                    $provisionedServer = fbgShopFindProvisionedServerByExternalId($externalId);

                    if ($provisionedServer) {
                        $serverAttrs = $provisionedServer;
                        $provisionWarning = 'Server provisioning completed after a delayed response.';
                        break;
                    }

                    if ($attempt < 9) {
                        sleep(2);
                    }
                }

                if (!$serverAttrs) {
                    throw new RuntimeException((string)($createResult['error'] ?? 'Server provisioning failed.'));
                }
            } else {
                $serverAttrs = $createResult['data']['attributes'] ?? [];
            }

            $serverId = (int)($serverAttrs['id'] ?? 0);
            $identifier = (string)($serverAttrs['identifier'] ?? '');

            if ($serverId <= 0) {
                throw new RuntimeException('Server was created, but the new server ID could not be read.');
            }

            $expiresAt = fbgShopInitialServerExpiry();
            $serverCreated = true;
            fbgApplyShopServerPurchaseMetadata($serverId, (int)$game['id'], $expiresAt);
            fbgRecordShopServerPurchase(
                $userId,
                $serverId,
                (int)$game['id'],
                (string)($game['name'] ?? 'Game Server'),
                $price,
                fbgGetShopCurrency()
            );

            if (isset($_SESSION['server_meta'])) {
                unset($_SESSION['server_meta']);
            }
            unset($_SESSION['allowed_servers'], $_SESSION['allowed_servers_loaded_at']);

            return [
                'ok' => true,
                'error' => null,
                'data' => [
                    'server_id' => $serverId,
                    'identifier' => $identifier,
                    'balance' => $newCredit,
                    'balance_display' => fbgFormatCredit($newCredit),
                    'message' => $provisionWarning ?? 'Server purchased and provisioning has started.',
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (!empty($creditReserved) && empty($serverCreated)) {
                fbgRefundShopPurchaseCredit($userId, $price);
            }

            return [
                'ok' => false,
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Server purchase failed.',
                'data' => null,
            ];
        }
    }
}

if (!function_exists('fbgNormalizeExpirationHistoryValue')) {
    function fbgNormalizeExpirationHistoryValue(?string $value): ?string
    {
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($trimmed))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('fbgCurrentExpirationHistoryActor')) {
    function fbgCurrentExpirationHistoryActor(): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $username = trim((string)($_SESSION['username'] ?? ''));
        $fullName = trim((string)($_SESSION['name'] ?? ''));
        $email = trim((string)($_SESSION['email'] ?? ''));

        $parts = [];
        if ($fullName !== '') {
            $parts[] = $fullName;
        }
        if ($username !== '') {
            $parts[] = '(' . $username . ')';
        } elseif ($email !== '') {
            $parts[] = '(' . $email . ')';
        }

        $label = trim(implode(' ', $parts));
        if ($label === '') {
            $label = $username !== '' ? $username : ($email !== '' ? $email : null);
        }

        return [
            'user_id' => $userId > 0 ? $userId : null,
            'label' => $label,
        ];
    }
}

if (!function_exists('fbgEnsureServerExpirationHistoryTable')) {
    function fbgEnsureServerExpirationHistoryTable(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        db()->exec("
            CREATE TABLE IF NOT EXISTS server_expiration_history (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                server_id INT UNSIGNED NOT NULL,
                action VARCHAR(32) NOT NULL,
                source VARCHAR(64) NOT NULL,
                old_expired_at DATETIME NULL,
                new_expired_at DATETIME NULL,
                changed_by_user_id INT UNSIGNED NULL,
                changed_by_label VARCHAR(191) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_server_expiration_history_server_id (server_id),
                KEY idx_server_expiration_history_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }
}

if (!function_exists('fbgRecordServerExpirationHistory')) {
    function fbgRecordServerExpirationHistory(
        int $serverId,
        string $action,
        string $source,
        ?string $oldExpiredAt,
        ?string $newExpiredAt,
        ?int $changedByUserId = null,
        ?string $changedByLabel = null
    ): void {
        if ($serverId <= 0) {
            return;
        }

        fbgEnsureServerExpirationHistoryTable();

        $stmt = db()->prepare("
            INSERT INTO server_expiration_history (
                server_id,
                action,
                source,
                old_expired_at,
                new_expired_at,
                changed_by_user_id,
                changed_by_label
            ) VALUES (
                :server_id,
                :action,
                :source,
                :old_expired_at,
                :new_expired_at,
                :changed_by_user_id,
                :changed_by_label
            )
        ");
        $stmt->execute([
            ':server_id' => $serverId,
            ':action' => trim($action),
            ':source' => trim($source),
            ':old_expired_at' => fbgNormalizeExpirationHistoryValue($oldExpiredAt),
            ':new_expired_at' => fbgNormalizeExpirationHistoryValue($newExpiredAt),
            ':changed_by_user_id' => $changedByUserId,
            ':changed_by_label' => $changedByLabel !== null && trim($changedByLabel) !== '' ? trim($changedByLabel) : null,
        ]);
    }
}

if (!function_exists('fbgTryRecordServerExpirationHistory')) {
    function fbgTryRecordServerExpirationHistory(
        int $serverId,
        string $action,
        string $source,
        ?string $oldExpiredAt,
        ?string $newExpiredAt,
        ?int $changedByUserId = null,
        ?string $changedByLabel = null
    ): bool {
        try {
            fbgRecordServerExpirationHistory(
                $serverId,
                $action,
                $source,
                $oldExpiredAt,
                $newExpiredAt,
                $changedByUserId,
                $changedByLabel
            );

            return true;
        } catch (Throwable $e) {
            error_log('[FBG] Failed to record server expiration history: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fbgGetServerExpirationHistoryCount')) {
    function fbgGetServerExpirationHistoryCount(int $serverId): int
    {
        if ($serverId <= 0) {
            return 0;
        }

        try {
            fbgEnsureServerExpirationHistoryTable();
            $stmt = db()->prepare('SELECT COUNT(*) FROM server_expiration_history WHERE server_id = :server_id');
            $stmt->execute([':server_id' => $serverId]);

            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('fbgGetServerExpirationHistoryEntries')) {
    function fbgGetServerExpirationHistoryEntries(int $serverId, int $limit = 100): array
    {
        if ($serverId <= 0) {
            return [];
        }

        try {
            fbgEnsureServerExpirationHistoryTable();
            $limit = max(1, min(500, $limit));
            $stmt = db()->prepare("
                SELECT
                    id,
                    server_id,
                    action,
                    source,
                    old_expired_at,
                    new_expired_at,
                    changed_by_user_id,
                    changed_by_label,
                    created_at
                FROM server_expiration_history
                WHERE server_id = :server_id
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':server_id' => $serverId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fbgGetServerLastKnownExpiration')) {
    function fbgGetServerLastKnownExpiration(int $serverId, ?string $excludeCurrent = null): ?string
    {
        if ($serverId <= 0) {
            return null;
        }

        $excluded = fbgNormalizeExpirationHistoryValue($excludeCurrent);

        foreach (fbgGetServerExpirationHistoryEntries($serverId, 250) as $entry) {
            $candidate = fbgNormalizeExpirationHistoryValue((string)($entry['new_expired_at'] ?? ''));
            if ($candidate === null) {
                continue;
            }

            if ($excluded !== null && $candidate === $excluded) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}

if (!function_exists('fbgServerExpirationActionLabel')) {
    function fbgServerExpirationActionLabel(string $action): string
    {
        return [
            'provision' => 'Provisioned',
            'renewal' => 'Renewed',
            'admin_edit' => 'Admin Edit',
            'admin_clear' => 'Admin Cleared',
            'admin_restore' => 'Expiration Restored',
        ][trim($action)] ?? ucfirst(str_replace('_', ' ', trim($action)));
    }
}

if (!function_exists('fbgServerExpirationSourceLabel')) {
    function fbgServerExpirationSourceLabel(string $source): string
    {
        return [
            'frontend_provisioning' => 'Frontend Provisioning',
            'frontend_renewal' => 'Frontend Renewal',
            'admin_server_editor' => 'Admin Server Editor',
            'admin_restore_control' => 'Admin Restore Control',
        ][trim($source)] ?? ucfirst(str_replace('_', ' ', trim($source)));
    }
}
function isShowingAllServers(): bool
{
    return canAccess(4) && !empty($_SESSION['show_all_servers']);
}

function setShowAllServers(bool $showAll): void
{
    $_SESSION['show_all_servers'] = (canAccess(4) && $showAll) ? 1 : 0;
}

if (!function_exists('fbgGetServerCards')) {
    function fbgGetServerCards(): array
    {
        $pdo = db();

        $sql = "
            SELECT
                id,
                title,
                body,
                btnlink,
                buttontext,
                sort_order
            FROM server_cards
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function (array $row): array {
            return [
                'id'         => (int)($row['id'] ?? 0),
                'title'      => trim((string)($row['title'] ?? '')),
                'body'       => trim((string)($row['body'] ?? '')),
                'btnlink'    => trim((string)($row['btnlink'] ?? '')),
                'buttontext' => trim((string)($row['buttontext'] ?? '')),
                'sort_order' => (int)($row['sort_order'] ?? 0),
            ];
        }, $rows);
    }
}

function fbgSiteSettingsCache(?array $newCache = null, bool $reset = false): array
{
    static $cache = null;

    if ($reset) {
        $cache = null;
    }

    if (is_array($newCache)) {
        $cache = $newCache;
    }

    if ($cache === null) {
        $stmt = db()->query("SELECT setting_key, setting_value FROM site_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cache = [];
        foreach ($rows as $row) {
            $cache[(string)$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache;
}

function fbgGetSetting(string $key, $default = null)
{
    $cache = fbgSiteSettingsCache();
    return $cache[$key] ?? $default;
}

function fbgResetSettingsCache(): void
{
    fbgSiteSettingsCache(null, true);
}

if (!function_exists('fbgIsMaintenanceMode')) {
    function fbgIsMaintenanceMode(): bool
    {
        return (int)fbgGetSetting('maintenance_mode', 0) === 1;
    }
}

if (!function_exists('fbgGetMaintenanceMessage')) {
    function fbgGetMaintenanceMessage(): string
    {
        return trim((string)fbgGetSetting(
            'maintenance_message',
            'We are currently performing maintenance. Please check back shortly.'
        ));
    }
}

if (!function_exists('fbgCurrentUserCanBypassMaintenance')) {
    function fbgCurrentUserCanBypassMaintenance(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            return false;
        }

        if (!function_exists('canAccess')) {
            require_once __DIR__ . '/functions.php';
        }

        return canAccess(4);
    }
}
