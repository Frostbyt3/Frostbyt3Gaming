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
    ): ?array {
        if (
            $userId <= 0 ||
            $serverId <= 0 ||
            $gameId <= 0 ||
            !fbgEnsureShopServerPurchaseTable()
        ) {
            return null;
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

            $purchaseStmt = fbgPteroDb()->prepare("
                SELECT id, user_id, server_id, game_id, game_name, amount, currency, created_at
                FROM shop_server_purchases
                WHERE server_id = :server_id
                LIMIT 1
            ");
            $purchaseStmt->execute([':server_id' => $serverId]);
            $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);

            return is_array($purchase) ? $purchase : null;
        } catch (Throwable $e) {
            // History should never block a successful server rental.
            return null;
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

if (!function_exists('fbgEnsureFrontendInvoiceTables')) {
    function fbgEnsureFrontendInvoiceTables(): bool
    {
        try {
            $pdo = db();

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS fbg_invoices (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    invoice_number VARCHAR(64) NULL,
                    user_id INT UNSIGNED NOT NULL,
                    source_type VARCHAR(64) NOT NULL,
                    source_id VARCHAR(191) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'paid',
                    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
                    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
                    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    tax_label VARCHAR(64) NOT NULL DEFAULT 'Tax',
                    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    company_name VARCHAR(191) NOT NULL DEFAULT '',
                    company_address TEXT NULL,
                    company_phone VARCHAR(64) NOT NULL DEFAULT '',
                    company_email VARCHAR(191) NOT NULL DEFAULT '',
                    company_code VARCHAR(191) NOT NULL DEFAULT '',
                    company_vat VARCHAR(191) NOT NULL DEFAULT '',
                    customer_name VARCHAR(191) NOT NULL DEFAULT '',
                    customer_email VARCHAR(191) NOT NULL DEFAULT '',
                    customer_username VARCHAR(191) NOT NULL DEFAULT '',
                    payment_provider VARCHAR(64) NOT NULL DEFAULT '',
                    payment_reference VARCHAR(191) NOT NULL DEFAULT '',
                    paid_at DATETIME NULL,
                    metadata_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY fbg_invoices_invoice_number_unique (invoice_number),
                    UNIQUE KEY fbg_invoices_source_unique (source_type, source_id),
                    KEY fbg_invoices_user_created_idx (user_id, created_at),
                    KEY fbg_invoices_status_idx (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $taxLabelColumn = $pdo->query("SHOW COLUMNS FROM fbg_invoices LIKE 'tax_label'")->fetch(PDO::FETCH_ASSOC);
            if (!$taxLabelColumn) {
                $pdo->exec("ALTER TABLE fbg_invoices ADD COLUMN tax_label VARCHAR(64) NOT NULL DEFAULT 'Tax' AFTER tax_amount");
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS fbg_invoice_line_items (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    invoice_id INT UNSIGNED NOT NULL,
                    description VARCHAR(255) NOT NULL,
                    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    line_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
                    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    metadata_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY fbg_invoice_line_items_invoice_idx (invoice_id),
                    CONSTRAINT fbg_invoice_line_items_invoice_fk
                        FOREIGN KEY (invoice_id) REFERENCES fbg_invoices (id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS fbg_invoice_events (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    invoice_id INT UNSIGNED NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    event_note TEXT NULL,
                    metadata_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY fbg_invoice_events_invoice_idx (invoice_id),
                    KEY fbg_invoice_events_type_idx (event_type),
                    CONSTRAINT fbg_invoice_events_invoice_fk
                        FOREIGN KEY (invoice_id) REFERENCES fbg_invoices (id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            return true;
        } catch (Throwable $e) {
            error_log('Unable to ensure frontend invoice tables: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fbgGetFrontendInvoiceSettings')) {
    function fbgGetFrontendInvoiceSettings(): array
    {
        return [
            'enabled' => (string)fbgGetSetting('fbg_invoice_enabled', '1') === '1',
            'prefix' => (string)fbgGetSetting('fbg_invoice_prefix', 'FBG-'),
            'starting_number' => max(1, (int)fbgGetSetting('fbg_invoice_starting_number', '1001')),
            'next_number' => max(1, (int)fbgGetSetting('fbg_invoice_next_number', fbgGetSetting('fbg_invoice_starting_number', '1001'))),
            'company_name' => (string)fbgGetSetting('fbg_invoice_company_name', 'Frostbyt3 Gaming, LLC.'),
            'company_address' => (string)fbgGetSetting('fbg_invoice_company_address', ''),
            'company_phone' => (string)fbgGetSetting('fbg_invoice_company_phone', ''),
            'company_email' => (string)fbgGetSetting('fbg_invoice_company_email', ''),
            'company_code' => (string)fbgGetSetting('fbg_invoice_company_code', ''),
            'company_vat' => (string)fbgGetSetting('fbg_invoice_company_vat', ''),
            'tax_rate' => max(0, (float)fbgGetSetting('fbg_invoice_tax_rate', '0')),
            'tax_label' => (string)fbgGetSetting('fbg_invoice_tax_label', 'Tax'),
            'mail_from_name' => (string)fbgGetSetting('fbg_invoice_mail_from_name', ''),
            'mail_from_email' => (string)fbgGetSetting('fbg_invoice_mail_from_email', ''),
            'mail_reply_to_name' => (string)fbgGetSetting('fbg_invoice_mail_reply_to_name', ''),
            'mail_reply_to_email' => (string)fbgGetSetting('fbg_invoice_mail_reply_to_email', ''),
        ];
    }
}

if (!function_exists('fbgGetShopTaxRate')) {
    function fbgGetShopTaxRate(): float
    {
        return max(0, round((float)fbgGetSetting('fbg_invoice_tax_rate', '0'), 4));
    }
}

if (!function_exists('fbgCalculateShopTax')) {
    function fbgCalculateShopTax(float $subtotal): array
    {
        $subtotal = round(max(0, $subtotal), 2);
        $taxRate = fbgGetShopTaxRate();
        $taxAmount = round($subtotal * ($taxRate / 100), 2);

        return [
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
        ];
    }
}

if (!function_exists('fbgFormatFrontendInvoiceNumber')) {
    function fbgFormatFrontendInvoiceNumber(int $invoiceId, array $settings): string
    {
        $prefix = trim((string)($settings['prefix'] ?? 'FBG-'));
        $startingNumber = max(1, (int)($settings['starting_number'] ?? 1001));
        $number = $startingNumber + max(0, $invoiceId - 1);

        return $prefix . str_pad((string)$number, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('fbgReserveFrontendInvoiceNumber')) {
    function fbgReserveFrontendInvoiceNumber(PDO $pdo, array $settings): string
    {
        $prefix = substr(trim((string)($settings['prefix'] ?? 'FBG-')), 0, 24);
        $startingNumber = max(1, (int)($settings['starting_number'] ?? 1001));
        $nextNumber = max($startingNumber, (int)($settings['next_number'] ?? $startingNumber));
        $settingKey = 'fbg_invoice_next_number';

        $ensureStmt = $pdo->prepare("
            INSERT INTO site_settings (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = setting_value
        ");
        $ensureStmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => (string)$nextNumber,
        ]);

        $lockStmt = $pdo->prepare("
            SELECT setting_value
            FROM site_settings
            WHERE setting_key = :setting_key
            LIMIT 1
            FOR UPDATE
        ");
        $lockStmt->execute([':setting_key' => $settingKey]);
        $storedNext = $lockStmt->fetchColumn();
        $nextNumber = max($startingNumber, (int)$storedNext);

        do {
            $invoiceNumber = $prefix . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);
            $existsStmt = $pdo->prepare("
                SELECT id
                FROM fbg_invoices
                WHERE invoice_number = :invoice_number
                LIMIT 1
            ");
            $existsStmt->execute([':invoice_number' => $invoiceNumber]);

            if (!$existsStmt->fetchColumn()) {
                break;
            }

            $nextNumber++;
        } while (true);

        $updateStmt = $pdo->prepare("
            UPDATE site_settings
            SET setting_value = :setting_value
            WHERE setting_key = :setting_key
        ");
        $updateStmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => (string)($nextNumber + 1),
        ]);

        return $invoiceNumber;
    }
}

if (!function_exists('fbgLogFrontendInvoiceEvent')) {
    function fbgLogFrontendInvoiceEvent(int $invoiceId, string $eventType, string $eventNote = '', array $metadata = []): void
    {
        if ($invoiceId <= 0 || $eventType === '' || !fbgEnsureFrontendInvoiceTables()) {
            return;
        }

        try {
            $stmt = db()->prepare("
                INSERT INTO fbg_invoice_events (invoice_id, event_type, event_note, metadata_json, created_at)
                VALUES (:invoice_id, :event_type, :event_note, :metadata_json, NOW())
            ");
            $stmt->execute([
                ':invoice_id' => $invoiceId,
                ':event_type' => substr($eventType, 0, 64),
                ':event_note' => $eventNote,
                ':metadata_json' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable $e) {
            error_log('Unable to log frontend invoice event: ' . $e->getMessage());
        }
    }
}

if (!function_exists('fbgCreateFrontendInvoice')) {
    function fbgCreateFrontendInvoice(array $data): ?array
    {
        $userId = (int)($data['user_id'] ?? 0);
        $sourceType = trim((string)($data['source_type'] ?? ''));
        $sourceId = trim((string)($data['source_id'] ?? ''));

        if ($userId <= 0 || $sourceType === '' || $sourceId === '') {
            return null;
        }

        if (!fbgEnsureFrontendInvoiceTables()) {
            return null;
        }

        $settings = fbgGetFrontendInvoiceSettings();
        if (empty($settings['enabled'])) {
            return null;
        }

        $pdo = db();

        try {
            $existingStmt = $pdo->prepare("
                SELECT *
                FROM fbg_invoices
                WHERE source_type = :source_type
                AND source_id = :source_id
                LIMIT 1
            ");
            $existingStmt->execute([
                ':source_type' => $sourceType,
                ':source_id' => $sourceId,
            ]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                return $existing;
            }

            $lineItems = $data['line_items'] ?? [];
            if (!is_array($lineItems) || empty($lineItems)) {
                return null;
            }

            $currency = strtoupper(substr(trim((string)($data['currency'] ?? fbgGetShopCurrency())), 0, 8)) ?: 'USD';
            $taxRate = round((float)($data['tax_rate'] ?? $settings['tax_rate']), 4);
            $subtotal = 0.0;
            $normalizedItems = [];

            foreach ($lineItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $description = trim((string)($item['description'] ?? 'Frostbyt3 Gaming rental'));
                $quantity = max(0.01, round((float)($item['quantity'] ?? 1), 2));
                $unitAmount = round((float)($item['unit_amount'] ?? 0), 2);
                $lineSubtotal = round($quantity * $unitAmount, 2);

                if ($description === '' || $lineSubtotal <= 0) {
                    continue;
                }

                $subtotal += $lineSubtotal;
                $normalizedItems[] = [
                    'description' => substr($description, 0, 255),
                    'quantity' => $quantity,
                    'unit_amount' => $unitAmount,
                    'line_subtotal' => $lineSubtotal,
                    'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                ];
            }

            if (empty($normalizedItems) || $subtotal <= 0) {
                return null;
            }

            $subtotal = round($subtotal, 2);
            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $total = round($subtotal + $taxAmount, 2);
            $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];

            $pdo->beginTransaction();
            $invoiceNumber = fbgReserveFrontendInvoiceNumber($pdo, $settings);

            $insertStmt = $pdo->prepare("
                INSERT INTO fbg_invoices (
                    invoice_number,
                    user_id,
                    source_type,
                    source_id,
                    status,
                    currency,
                    subtotal,
                    tax_rate,
                    tax_amount,
                    tax_label,
                    total,
                    company_name,
                    company_address,
                    company_phone,
                    company_email,
                    company_code,
                    company_vat,
                    customer_name,
                    customer_email,
                    customer_username,
                    payment_provider,
                    payment_reference,
                    paid_at,
                    metadata_json,
                    created_at,
                    updated_at
                )
                VALUES (
                    :invoice_number,
                    :user_id,
                    :source_type,
                    :source_id,
                    :status,
                    :currency,
                    :subtotal,
                    :tax_rate,
                    :tax_amount,
                    :tax_label,
                    :total,
                    :company_name,
                    :company_address,
                    :company_phone,
                    :company_email,
                    :company_code,
                    :company_vat,
                    :customer_name,
                    :customer_email,
                    :customer_username,
                    :payment_provider,
                    :payment_reference,
                    :paid_at,
                    :metadata_json,
                    NOW(),
                    NOW()
                )
            ");
            $insertStmt->execute([
                ':invoice_number' => $invoiceNumber,
                ':user_id' => $userId,
                ':source_type' => substr($sourceType, 0, 64),
                ':source_id' => substr($sourceId, 0, 191),
                ':status' => substr((string)($data['status'] ?? 'paid'), 0, 32),
                ':currency' => $currency,
                ':subtotal' => number_format($subtotal, 2, '.', ''),
                ':tax_rate' => number_format($taxRate, 4, '.', ''),
                ':tax_amount' => number_format($taxAmount, 2, '.', ''),
                ':tax_label' => substr(trim((string)($settings['tax_label'] ?? 'Tax')) ?: 'Tax', 0, 64),
                ':total' => number_format($total, 2, '.', ''),
                ':company_name' => substr((string)$settings['company_name'], 0, 191),
                ':company_address' => (string)$settings['company_address'],
                ':company_phone' => substr((string)$settings['company_phone'], 0, 64),
                ':company_email' => substr((string)$settings['company_email'], 0, 191),
                ':company_code' => substr((string)$settings['company_code'], 0, 191),
                ':company_vat' => substr((string)$settings['company_vat'], 0, 191),
                ':customer_name' => substr((string)($customer['name'] ?? ''), 0, 191),
                ':customer_email' => substr((string)($customer['email'] ?? ''), 0, 191),
                ':customer_username' => substr((string)($customer['username'] ?? ''), 0, 191),
                ':payment_provider' => substr((string)($data['payment_provider'] ?? ''), 0, 64),
                ':payment_reference' => substr((string)($data['payment_reference'] ?? ''), 0, 191),
                ':paid_at' => !empty($data['paid_at']) ? (string)$data['paid_at'] : date('Y-m-d H:i:s'),
                ':metadata_json' => !empty($data['metadata']) && is_array($data['metadata'])
                    ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES)
                    : null,
            ]);

            $invoiceId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO fbg_invoice_line_items (
                    invoice_id,
                    description,
                    quantity,
                    unit_amount,
                    line_subtotal,
                    tax_rate,
                    tax_amount,
                    line_total,
                    metadata_json,
                    created_at
                )
                VALUES (
                    :invoice_id,
                    :description,
                    :quantity,
                    :unit_amount,
                    :line_subtotal,
                    :tax_rate,
                    :tax_amount,
                    :line_total,
                    :metadata_json,
                    NOW()
                )
            ");

            foreach ($normalizedItems as $item) {
                $lineTax = round((float)$item['line_subtotal'] * ($taxRate / 100), 2);
                $lineTotal = round((float)$item['line_subtotal'] + $lineTax, 2);

                $itemStmt->execute([
                    ':invoice_id' => $invoiceId,
                    ':description' => $item['description'],
                    ':quantity' => number_format((float)$item['quantity'], 2, '.', ''),
                    ':unit_amount' => number_format((float)$item['unit_amount'], 2, '.', ''),
                    ':line_subtotal' => number_format((float)$item['line_subtotal'], 2, '.', ''),
                    ':tax_rate' => number_format($taxRate, 4, '.', ''),
                    ':tax_amount' => number_format($lineTax, 2, '.', ''),
                    ':line_total' => number_format($lineTotal, 2, '.', ''),
                    ':metadata_json' => !empty($item['metadata'])
                        ? json_encode($item['metadata'], JSON_UNESCAPED_SLASHES)
                        : null,
                ]);
            }

            $pdo->commit();

            $invoiceStmt = $pdo->prepare("
                SELECT *
                FROM fbg_invoices
                WHERE id = :id
                LIMIT 1
            ");
            $invoiceStmt->execute([':id' => $invoiceId]);

            $invoice = $invoiceStmt->fetch() ?: null;

            if ($invoice) {
                fbgLogFrontendInvoiceEvent($invoiceId, 'generated', 'Invoice generated.');
                fbgSendFrontendInvoiceEmailNotification($invoiceId, $userId);
            }

            return $invoice;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Unable to create frontend invoice: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fbgSendFrontendInvoiceEmailNotification')) {
    function fbgSendFrontendInvoiceEmailNotification(int $invoiceId, int $userId): bool
    {
        if ($invoiceId <= 0 || $userId <= 0) {
            return false;
        }

        if ((string)fbgGetSetting('fbg_invoice_email_enabled', '1') !== '1') {
            fbgLogFrontendInvoiceEvent($invoiceId, 'email-skipped', 'Invoice email delivery is disabled.');
            return false;
        }

        $invoice = fbgGetFrontendInvoiceDetail($invoiceId, $userId, true);
        if (!$invoice || empty($invoice['customer_email'])) {
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email was not sent because no customer email address was available.');
            return false;
        }

        require_once __DIR__ . '/mailer.php';

        if (!function_exists('fbgSendInvoiceEmail')) {
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email helper is unavailable.');
            return false;
        }

        try {
            $invoiceUrl = fbgShopBaseUrl() . '/page.php?name=invoice&id=' . rawurlencode((string)$invoiceId);
            $sent = fbgSendInvoiceEmail($invoice, $invoiceUrl);

            if ($sent) {
                fbgLogFrontendInvoiceEvent($invoiceId, 'emailed', 'Invoice email sent to ' . (string)$invoice['customer_email'] . '.');
                return true;
            }

            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email could not be sent.');
            return false;
        } catch (Throwable $e) {
            error_log('Invoice email failed: ' . $e->getMessage());
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email failed to send.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

if (!function_exists('fbgCreateFrontendInvoiceForPayment')) {
    function fbgCreateFrontendInvoiceForPayment(int $paymentId, string $provider, string $reference = ''): ?array
    {
        if ($paymentId <= 0 || !fbgEnsurePteroDbHelper()) {
            return null;
        }

        try {
            $paymentStmt = fbgPteroDb()->prepare("
                SELECT id, user_id, amount, payment_type, session_id, completed, created_at
                FROM payments
                WHERE id = :id
                LIMIT 1
            ");
            $paymentStmt->execute([':id' => $paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || (int)($payment['completed'] ?? 0) !== 1) {
                return null;
            }

            $userId = (int)($payment['user_id'] ?? 0);
            $panelUser = function_exists('fbgFindPanelUserById') ? fbgFindPanelUserById($userId) : null;
            $customerName = trim((string)($_SESSION['name'] ?? ''));

            if ($customerName === '' && is_array($panelUser)) {
                $customerName = trim((string)($panelUser['name_first'] ?? ''));
            }

            $invoice = fbgCreateFrontendInvoice([
                'user_id' => $userId,
                'source_type' => 'payment',
                'source_id' => (string)$paymentId,
                'status' => 'paid',
                'currency' => fbgGetShopCurrency(),
                'tax_rate' => 0,
                'payment_provider' => $provider !== '' ? $provider : (string)($payment['payment_type'] ?? ''),
                'payment_reference' => $reference !== '' ? $reference : (string)($payment['session_id'] ?? ''),
                'paid_at' => date('Y-m-d H:i:s'),
                'customer' => [
                    'name' => $customerName,
                    'email' => is_array($panelUser) ? (string)($panelUser['email'] ?? '') : (string)($_SESSION['email'] ?? ''),
                    'username' => is_array($panelUser) ? (string)($panelUser['username'] ?? '') : (string)($_SESSION['username'] ?? ''),
                ],
                'line_items' => [
                    [
                        'description' => 'Account balance upload',
                        'quantity' => 1,
                        'unit_amount' => (float)($payment['amount'] ?? 0),
                        'metadata' => [
                            'payment_id' => $paymentId,
                            'payment_type' => (string)($payment['payment_type'] ?? ''),
                        ],
                    ],
                ],
                'metadata' => [
                    'payment_id' => $paymentId,
                    'payment_session_id' => (string)($payment['session_id'] ?? ''),
                ],
            ]);

            if ($invoice && !empty($invoice['invoice_number'])) {
                try {
                    $updatePaymentStmt = fbgPteroDb()->prepare("
                        UPDATE payments
                        SET invoice_number = :invoice_number
                        WHERE id = :id
                        AND (invoice_number IS NULL OR invoice_number = '')
                    ");
                    $updatePaymentStmt->execute([
                        ':invoice_number' => (string)$invoice['invoice_number'],
                        ':id' => $paymentId,
                    ]);
                } catch (Throwable $e) {
                    error_log('Unable to backfill payment invoice number: ' . $e->getMessage());
                }
            }

            return $invoice;
        } catch (Throwable $e) {
            error_log('Unable to create payment invoice: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fbgGetFrontendInvoiceCustomerForUser')) {
    function fbgGetFrontendInvoiceCustomerForUser(int $userId): array
    {
        $customer = [
            'name' => '',
            'email' => '',
            'username' => '',
        ];

        if ($userId <= 0 || !fbgEnsurePteroDbHelper()) {
            return $customer;
        }

        try {
            $stmt = fbgPteroDb()->prepare("
                SELECT username, email, name_first, name_last
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($user)) {
                $firstName = trim((string)($user['name_first'] ?? ''));
                $lastName = trim((string)($user['name_last'] ?? ''));

                $customer['name'] = trim($firstName . ' ' . $lastName);
                $customer['email'] = (string)($user['email'] ?? '');
                $customer['username'] = (string)($user['username'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('Unable to load invoice customer details: ' . $e->getMessage());
        }

        if ($customer['name'] === '') {
            $customer['name'] = trim((string)($_SESSION['name'] ?? ''));
        }

        if ($customer['email'] === '') {
            $customer['email'] = trim((string)($_SESSION['email'] ?? ''));
        }

        if ($customer['username'] === '') {
            $customer['username'] = trim((string)($_SESSION['username'] ?? ''));
        }

        return $customer;
    }
}

if (!function_exists('fbgCreateFrontendInvoiceForServerPurchase')) {
    function fbgCreateFrontendInvoiceForServerPurchase(array $purchase): ?array
    {
        $purchaseId = (int)($purchase['id'] ?? 0);
        $userId = (int)($purchase['user_id'] ?? 0);
        $serverId = (int)($purchase['server_id'] ?? 0);
        $gameId = (int)($purchase['game_id'] ?? 0);
        $gameName = trim((string)($purchase['game_name'] ?? 'Game Server'));
        $amount = round((float)($purchase['invoice_subtotal'] ?? $purchase['amount'] ?? 0), 2);
        $taxRate = fbgGetShopTaxRate();

        if ($purchaseId <= 0 || $userId <= 0 || $serverId <= 0 || $amount <= 0) {
            return null;
        }

        try {
            return fbgCreateFrontendInvoice([
                'user_id' => $userId,
                'source_type' => 'server_purchase',
                'source_id' => (string)$purchaseId,
                'status' => 'paid',
                'currency' => (string)($purchase['currency'] ?? fbgGetShopCurrency()),
                'tax_rate' => $taxRate,
                'payment_provider' => 'account_balance',
                'paid_at' => date('Y-m-d H:i:s'),
                'customer' => fbgGetFrontendInvoiceCustomerForUser($userId),
                'line_items' => [
                    [
                        'description' => $gameName . ' server rental (30 Days)',
                        'quantity' => 1,
                        'unit_amount' => $amount,
                        'metadata' => [
                            'purchase_id' => $purchaseId,
                            'server_id' => $serverId,
                            'game_id' => $gameId,
                        ],
                    ],
                ],
                'metadata' => [
                    'purchase_id' => $purchaseId,
                    'server_id' => $serverId,
                    'game_id' => $gameId,
                    'game_name' => $gameName,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('Unable to create server rental invoice: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fbgCreateFrontendInvoiceForServerRenewal')) {
    function fbgCreateFrontendInvoiceForServerRenewal(
        int $userId,
        int $serverId,
        int $gameId,
        string $gameName,
        float $amount,
        string $currency,
        string $newExpiresAt,
        ?string $oldExpiresAt = null
    ): ?array {
        $normalizedNewExpiry = fbgNormalizeExpirationHistoryValue($newExpiresAt);
        $taxRate = fbgGetShopTaxRate();

        if ($userId <= 0 || $serverId <= 0 || $amount <= 0 || $normalizedNewExpiry === null) {
            return null;
        }

        try {
            return fbgCreateFrontendInvoice([
                'user_id' => $userId,
                'source_type' => 'renewal',
                'source_id' => $serverId . ':' . $normalizedNewExpiry,
                'status' => 'paid',
                'currency' => $currency !== '' ? $currency : fbgGetShopCurrency(),
                'tax_rate' => $taxRate,
                'payment_provider' => 'account_balance',
                'paid_at' => date('Y-m-d H:i:s'),
                'customer' => fbgGetFrontendInvoiceCustomerForUser($userId),
                'line_items' => [
                    [
                        'description' => trim($gameName) !== '' ? trim($gameName) . ' server renewal (30 Days)' : 'Server renewal (30 Days)',
                        'quantity' => 1,
                        'unit_amount' => round($amount, 2),
                        'metadata' => [
                            'server_id' => $serverId,
                            'game_id' => $gameId,
                            'old_expires_at' => $oldExpiresAt,
                            'new_expires_at' => $normalizedNewExpiry,
                        ],
                    ],
                ],
                'metadata' => [
                    'server_id' => $serverId,
                    'game_id' => $gameId,
                    'game_name' => $gameName,
                    'old_expires_at' => $oldExpiresAt,
                    'new_expires_at' => $normalizedNewExpiry,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('Unable to create server renewal invoice: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fbgAdminGenerateFrontendInvoiceForPayment')) {
    function fbgAdminGenerateFrontendInvoiceForPayment(int $paymentId): array
    {
        if ($paymentId <= 0 || !fbgEnsurePteroDbHelper()) {
            return [
                'ok' => false,
                'error' => 'Choose a valid completed order before generating an invoice.',
                'invoice' => null,
            ];
        }

        try {
            $paymentStmt = fbgPteroDb()->prepare("
                SELECT id, payment_type, session_id, completed
                FROM payments
                WHERE id = :id
                LIMIT 1
            ");
            $paymentStmt->execute([':id' => $paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                return [
                    'ok' => false,
                    'error' => 'That order could not be found.',
                    'invoice' => null,
                ];
            }

            if ((int)($payment['completed'] ?? 0) !== 1) {
                return [
                    'ok' => false,
                    'error' => 'Invoices can only be generated for completed orders.',
                    'invoice' => null,
                ];
            }

            $invoice = fbgCreateFrontendInvoiceForPayment(
                $paymentId,
                (string)($payment['payment_type'] ?? ''),
                (string)($payment['session_id'] ?? '')
            );

            if (!$invoice) {
                return [
                    'ok' => false,
                    'error' => 'The invoice could not be generated. Check invoice settings and try again.',
                    'invoice' => null,
                ];
            }

            fbgLogFrontendInvoiceEvent(
                (int)$invoice['id'],
                'manual-generation',
                'Invoice was generated manually from the admin area.',
                ['payment_id' => $paymentId]
            );

            return [
                'ok' => true,
                'error' => null,
                'invoice' => $invoice,
            ];
        } catch (Throwable $e) {
            error_log('Unable to manually generate frontend invoice: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'The invoice could not be generated. Check the logs and try again.',
                'invoice' => null,
            ];
        }
    }
}

if (!function_exists('fbgGetUserFrontendInvoices')) {
    function fbgGetUserFrontendInvoices(int $userId, int $limit = 100): array
    {
        if ($userId <= 0 || !fbgEnsureFrontendInvoiceTables()) {
            return [];
        }

        $limit = max(1, min(250, $limit));

        try {
            $stmt = db()->prepare("
                SELECT
                    id,
                    invoice_number,
                    source_type,
                    source_id,
                    status,
                    currency,
                    subtotal,
                    tax_amount,
                    total,
                    payment_provider,
                    paid_at,
                    created_at
                FROM fbg_invoices
                WHERE user_id = :user_id
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll();

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            error_log('Unable to load frontend invoices: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('fbgGetFrontendInvoiceDetail')) {
    function fbgGetFrontendInvoiceDetail(int $invoiceId, int $viewerUserId, bool $canViewAll = false): ?array
    {
        if ($invoiceId <= 0 || $viewerUserId <= 0 || !fbgEnsureFrontendInvoiceTables()) {
            return null;
        }

        try {
            $where = 'id = :id';
            $params = [':id' => $invoiceId];

            if (!$canViewAll) {
                $where .= ' AND user_id = :user_id';
                $params[':user_id'] = $viewerUserId;
            }

            $stmt = db()->prepare("
                SELECT *
                FROM fbg_invoices
                WHERE {$where}
                LIMIT 1
            ");
            $stmt->execute($params);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                return null;
            }

            $itemsStmt = db()->prepare("
                SELECT
                    id,
                    description,
                    quantity,
                    unit_amount,
                    line_subtotal,
                    tax_rate,
                    tax_amount,
                    line_total,
                    metadata_json,
                    created_at
                FROM fbg_invoice_line_items
                WHERE invoice_id = :invoice_id
                ORDER BY id ASC
            ");
            $itemsStmt->execute([':invoice_id' => $invoiceId]);

            $invoice['line_items'] = $itemsStmt->fetchAll() ?: [];

            $eventsStmt = db()->prepare("
                SELECT
                    id,
                    event_type,
                    event_note,
                    metadata_json,
                    created_at
                FROM fbg_invoice_events
                WHERE invoice_id = :invoice_id
                ORDER BY created_at DESC, id DESC
            ");
            $eventsStmt->execute([':invoice_id' => $invoiceId]);

            $invoice['events'] = $eventsStmt->fetchAll() ?: [];

            return $invoice;
        } catch (Throwable $e) {
            error_log('Unable to load frontend invoice detail: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('fbgCreateFrontendInvoicePdf')) {
    function fbgCreateFrontendInvoicePdf(array $invoice): string
    {
        $currency = trim((string)($invoice['currency'] ?? 'USD')) ?: 'USD';
        $invoiceNumber = trim((string)($invoice['invoice_number'] ?? 'Invoice')) ?: 'Invoice';
        $taxLabel = trim((string)($invoice['tax_label'] ?? 'Tax')) ?: 'Tax';
        $hasTax = round((float)($invoice['tax_rate'] ?? 0), 4) > 0 || round((float)($invoice['tax_amount'] ?? 0), 2) > 0;

        $formatDate = static function ($value): string {
            $value = trim((string)$value);
            if ($value === '') {
                return 'Unknown';
            }

            $timestamp = strtotime($value);
            return $timestamp ? date('M j, Y g:i A', $timestamp) : 'Unknown';
        };

        $formatMoney = static function ($amount) use ($currency): string {
            return function_exists('fbgFormatCredit')
                ? fbgFormatCredit((float)$amount, $currency)
                : number_format((float)$amount, 2) . ' ' . $currency;
        };

        $normalize = static function ($value): string {
            $text = preg_replace('/\s+/', ' ', trim((string)$value)) ?? '';
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }

            return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
        };

        $estimateWidth = static function (string $text, int $size = 10): float {
            $units = 0.0;
            $wide = 'ABCDEFGHIJKLMNOPQRSTUVWXYZmwMW@#%&';
            $narrow = 'ilI.,:;!| ';

            foreach (str_split($text) as $char) {
                if (strpos($narrow, $char) !== false) {
                    $units += 0.34;
                } elseif (strpos($wide, $char) !== false) {
                    $units += 0.86;
                } elseif (ctype_upper($char)) {
                    $units += 0.72;
                } elseif (ctype_digit($char)) {
                    $units += 0.56;
                } else {
                    $units += 0.52;
                }
            }

            return $units * $size;
        };

        $wrapByWidth = static function (string $text, int $size, float $maxWidth) use ($estimateWidth): array {
            $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
            if ($text === '') {
                return [''];
            }

            $lines = [];
            $current = '';
            foreach (explode(' ', $text) as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($current !== '' && $estimateWidth($candidate, $size) > $maxWidth) {
                    $lines[] = $current;
                    $current = $word;
                    continue;
                }

                if ($current === '' && $estimateWidth($candidate, $size) > $maxWidth) {
                    $piece = '';
                    foreach (str_split($word) as $char) {
                        if ($piece !== '' && $estimateWidth($piece . $char, $size) > $maxWidth) {
                            $lines[] = $piece;
                            $piece = $char;
                        } else {
                            $piece .= $char;
                        }
                    }
                    $current = $piece;
                    continue;
                }

                $current = $candidate;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            return $lines ?: [''];
        };

        $pages = [];
        $content = '';
        $y = 760;

        $newPage = static function () use (&$pages, &$content, &$y): void {
            if ($content !== '') {
                $pages[] = $content;
            }

            $content = '';
            $y = 760;
        };

        $ensureSpace = static function (int $needed) use (&$y, $newPage): void {
            if ($y - $needed < 70) {
                $newPage();
            }
        };

        $line = static function (
            string $text,
            int $x = 50,
            int $size = 10,
            bool $bold = false,
            int $leading = 14
        ) use (&$content, &$y, $normalize, $newPage): void {
            if ($y < 70) {
                $newPage();
            }

            $font = $bold ? 'F2' : 'F1';
            $content .= "BT /{$font} {$size} Tf {$x} {$y} Td (" . $normalize($text) . ") Tj ET\n";
            $y -= $leading;
        };

        $wrapped = static function (
            string $text,
            int $x = 50,
            int $size = 10,
            bool $bold = false,
            int $maxChars = 82
        ) use ($line): void {
            $text = str_replace(["\r\n", "\r"], "\n", trim($text));
            foreach (explode("\n", $text) as $segment) {
                $wrapped = wordwrap($segment, $maxChars, "\n", true);
                foreach (explode("\n", $wrapped) as $part) {
                    $line($part, $x, $size, $bold, $size + 5);
                }
            }
        };

        $rule = static function () use (&$content, &$y, $newPage): void {
            if ($y < 70) {
                $newPage();
            }

            $content .= "0.80 0.80 0.80 RG 50 {$y} m 545 {$y} l S\n";
            $y -= 18;
        };

        $tableHeader = static function () use (&$content, &$y, $line): void {
            $line('Description', 50, 9, true, 0);
            $line('Qty', 330, 9, true, 0);
            $line('Unit', 380, 9, true, 0);
            $line('Total', 515, 9, true, 14);
            $content .= "0.70 0.70 0.70 RG 50 {$y} m 545 {$y} l S\n";
            $y -= 10;
        };

        $line('INVOICE', 50, 26, true, 34);
        $line($invoiceNumber, 50, 14, true, 24);
        $line('Status: ' . ucfirst((string)($invoice['status'] ?? 'paid')), 50, 10, false, 15);
        $line('Created: ' . $formatDate($invoice['created_at'] ?? ''), 50, 10, false, 15);
        $line('Paid: ' . $formatDate($invoice['paid_at'] ?? ''), 50, 10, false, 22);
        $rule();

        $line('From', 50, 13, true, 18);
        $wrapped((string)($invoice['company_name'] ?? 'Frostbyt3 Gaming, LLC.'), 50, 10, true, 70);
        if (!empty($invoice['company_address'])) {
            $wrapped((string)$invoice['company_address'], 50, 10, false, 70);
        }
        if (!empty($invoice['company_phone'])) {
            $line((string)$invoice['company_phone']);
        }
        if (!empty($invoice['company_email'])) {
            $line((string)$invoice['company_email']);
        }
        if (!empty($invoice['company_code'])) {
            $line('Code: ' . (string)$invoice['company_code']);
        }
        if (!empty($invoice['company_vat'])) {
            $line('VAT: ' . (string)$invoice['company_vat']);
        }

        $y -= 10;
        $line('Bill To', 50, 13, true, 18);
        $wrapped((string)($invoice['customer_name'] ?: $invoice['customer_username'] ?: 'Customer'), 50, 10, true, 70);
        if (!empty($invoice['customer_username'])) {
            $line((string)$invoice['customer_username']);
        }
        if (!empty($invoice['customer_email'])) {
            $line((string)$invoice['customer_email']);
        }

        $y -= 14;
        $ensureSpace(90);
        $rule();
        $line('Line Items', 50, 13, true, 22);
        $tableHeader();

        foreach (($invoice['line_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = (string)($item['description'] ?? 'Invoice item');
            $descriptionLines = $wrapByWidth($description, 10, 260);
            $rowHeight = max(18, count($descriptionLines) * 13 + 5);

            if ($y - $rowHeight < 70) {
                $newPage();
                $line($invoiceNumber . ' - Line Items', 50, 13, true, 22);
                $tableHeader();
            }

            $rowStartY = $y;
            foreach ($descriptionLines as $index => $descriptionLine) {
                $line($descriptionLine, 50, 10, false, $index === count($descriptionLines) - 1 ? 0 : 13);
            }

            $y = $rowStartY;
            $line(number_format((float)($item['quantity'] ?? 0), 2), 330, 10, false, 0);
            $line(fbgFormatFrontendInvoiceUnitDisplay($item, $currency), 380, 7, false, 0);
            $line($formatMoney($item['line_total'] ?? 0), 515, 10, false, 0);
            $y = $rowStartY - $rowHeight;
        }

        $y -= 8;
        $ensureSpace($hasTax ? 118 : 96);
        $rule();
        $line('Payment Provider: ' . ucwords(str_replace(['_', '-'], ' ', (string)($invoice['payment_provider'] ?? 'Payment'))), 50, 10, false, 22);
        $line('Subtotal: ' . $formatMoney($invoice['subtotal'] ?? 0), 390, 11, false, 16);
        if ($hasTax) {
            $line($taxLabel . ' ' . number_format((float)($invoice['tax_rate'] ?? 0), 2) . '%: ' . $formatMoney($invoice['tax_amount'] ?? 0), 390, 11, false, 16);
        }
        $line('Total: ' . $formatMoney($invoice['total'] ?? 0), 390, 13, true, 20);

        $y = max(45, $y - 20);
        $line('Thank you for choosing Frostbyt3 Gaming.', 50, 9, false, 12);

        if ($content !== '') {
            $pages[] = $content;
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pageObjectIds = [];

        foreach ($pages as $index => $pageContent) {
            $contentObjectId = 5 + ($index * 2);
            $pageObjectId = $contentObjectId + 1;
            $pageObjectIds[] = $pageObjectId . ' 0 R';
            $objects[$contentObjectId - 1] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
            $objects[$pageObjectId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageObjectIds) . '] /Count ' . count($pages) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}

if (!function_exists('fbgFormatFrontendInvoiceUnitDisplay')) {
    function fbgFormatFrontendInvoiceUnitDisplay(array $item, string $currency): string
    {
        $currency = trim($currency) !== '' ? trim($currency) : 'USD';
        $unitAmount = (float)($item['unit_amount'] ?? 0);
        $taxAmount = round((float)($item['tax_amount'] ?? 0), 2);
        $taxRate = round((float)($item['tax_rate'] ?? 0), 4);
        $unitDisplay = fbgFormatCredit($unitAmount, $currency);

        if ($taxAmount <= 0 && $taxRate <= 0) {
            return $unitDisplay;
        }

        return $unitDisplay
            . ' + '
            . fbgFormatCredit($taxAmount, $currency)
            . ' tax ('
            . number_format($taxRate, 2)
            . '%)';
    }
}

if (!function_exists('fbgGetAdminFrontendInvoices')) {
    function fbgGetAdminFrontendInvoices(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if (!fbgEnsureFrontendInvoiceTables()) {
            return ['rows' => [], 'total' => 0];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = [];
        $params = [];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(
                invoice_number LIKE :search
                OR customer_name LIKE :search
                OR customer_email LIKE :search
                OR customer_username LIKE :search
                OR source_id LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }

        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $sourceType = strtolower(trim((string)($filters['source_type'] ?? '')));
        if ($sourceType !== '') {
            $where[] = 'source_type = :source_type';
            $params[':source_type'] = $sourceType;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            $countStmt = db()->prepare("SELECT COUNT(*) FROM fbg_invoices {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = db()->prepare("
                SELECT
                    id,
                    invoice_number,
                    user_id,
                    source_type,
                    source_id,
                    status,
                    currency,
                    subtotal,
                    tax_amount,
                    total,
                    customer_name,
                    customer_email,
                    customer_username,
                    payment_provider,
                    paid_at,
                    created_at
                FROM fbg_invoices
                {$whereSql}
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);

            return [
                'rows' => $stmt->fetchAll() ?: [],
                'total' => $total,
            ];
        } catch (Throwable $e) {
            error_log('Unable to load admin frontend invoices: ' . $e->getMessage());
            return ['rows' => [], 'total' => 0];
        }
    }
}

if (!function_exists('fbgResendFrontendInvoiceEmailNotification')) {
    function fbgResendFrontendInvoiceEmailNotification(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        if ((string)fbgGetSetting('fbg_invoice_email_enabled', '1') !== '1') {
            fbgLogFrontendInvoiceEvent($invoiceId, 'email-skipped', 'Invoice email delivery is disabled.');
            return false;
        }

        $invoice = fbgGetFrontendInvoiceDetail($invoiceId, 1, true);
        if (!$invoice || empty($invoice['customer_email'])) {
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email was not resent because no customer email address was available.');
            return false;
        }

        require_once __DIR__ . '/mailer.php';

        if (!function_exists('fbgSendInvoiceEmail')) {
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email helper is unavailable.');
            return false;
        }

        try {
            $invoiceUrl = fbgShopBaseUrl() . '/page.php?name=invoice&id=' . rawurlencode((string)$invoiceId);
            $sent = fbgSendInvoiceEmail($invoice, $invoiceUrl);

            if ($sent) {
                fbgLogFrontendInvoiceEvent($invoiceId, 'resent', 'Invoice email resent to ' . (string)$invoice['customer_email'] . '.');
                return true;
            }

            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email could not be resent.');
            return false;
        } catch (Throwable $e) {
            error_log('Invoice resend email failed: ' . $e->getMessage());
            fbgLogFrontendInvoiceEvent($invoiceId, 'failed-email', 'Invoice email failed to resend.', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
                $invoice = fbgCreateFrontendInvoiceForPayment((int)$payment['id'], 'stripe', $sessionId);
                $pdo->commit();
                return [
                    'ok' => true,
                    'error' => null,
                    'message' => 'Payment already applied.',
                    'invoice' => $invoice,
                ];
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
            $invoice = fbgCreateFrontendInvoiceForPayment((int)$payment['id'], 'stripe', $sessionId);

            return [
                'ok' => true,
                'error' => null,
                'message' => 'Account balance updated.',
                'invoice' => $invoice,
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
                $invoice = fbgCreateFrontendInvoiceForPayment((int)$payment['id'], 'paypal', $orderId);
                $pdo->commit();
                return [
                    'ok' => true,
                    'error' => null,
                    'message' => 'Payment already applied.',
                    'invoice' => $invoice,
                ];
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
            $invoice = fbgCreateFrontendInvoiceForPayment((int)$payment['id'], 'paypal', $orderId);

            return [
                'ok' => true,
                'error' => null,
                'message' => 'Account balance updated.',
                'invoice' => $invoice,
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
        $tax = fbgCalculateShopTax($price);
        $totalPrice = (float)$tax['total'];

        if ($price <= 0) {
            return ['ok' => false, 'error' => 'This server plan cannot be rented right now.', 'data' => null];
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
            if ($currentCredit < $totalPrice) {
                throw new RuntimeException("You don't have enough account balance for this server.");
            }

            $newCredit = round($currentCredit - $totalPrice, 2);
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
            $purchaseRecord = fbgRecordShopServerPurchase(
                $userId,
                $serverId,
                (int)$game['id'],
                (string)($game['name'] ?? 'Game Server'),
                $totalPrice,
                fbgGetShopCurrency()
            );
            if ($purchaseRecord) {
                $purchaseRecord['invoice_subtotal'] = $price;
                $purchaseRecord['invoice_tax_rate'] = (float)$tax['tax_rate'];
                $purchaseRecord['invoice_tax_amount'] = (float)$tax['tax_amount'];
                $purchaseRecord['invoice_total'] = $totalPrice;
            }
            $invoice = $purchaseRecord ? fbgCreateFrontendInvoiceForServerPurchase($purchaseRecord) : null;

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
                    'subtotal' => (float)$tax['subtotal'],
                    'tax_rate' => (float)$tax['tax_rate'],
                    'tax_amount' => (float)$tax['tax_amount'],
                    'total' => $totalPrice,
                    'message' => $provisionWarning ?? 'Server rental started and provisioning has begun.',
                    'invoice' => $invoice,
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (!empty($creditReserved) && empty($serverCreated)) {
                fbgRefundShopPurchaseCredit($userId, $totalPrice);
            }

            return [
                'ok' => false,
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Server rental failed.',
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
