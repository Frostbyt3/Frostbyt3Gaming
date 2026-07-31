<?php
function asset($path)
{
  $full = $_SERVER['DOCUMENT_ROOT'] . $path;
  return $path . '?v=' . (file_exists($full) ? filemtime($full) : time());
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

?>