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
            "Why would anyone do drugs when they could just mow a lawn?",
            "Drug free since 2023!",
            "I didn't do it.",
            "Do your patch!",
            "Have you tried turning it off and back on again?",
            "An actual company now!",
            "Perfectly legal",
            "I am 30 to 40 years old and do not need this",
            "Arise, chicken, arise!",
            "1-800 Billy Witch Doctor"
        ];

        // Pick one at random
        return $messages[array_rand($messages)];
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