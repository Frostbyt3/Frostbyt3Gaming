<?php

// includes/pterodactyl.php

require_once __DIR__ . '/../config/secrets.php';

if (!defined('PTERO_BASE_URL')) {
    define('PTERO_BASE_URL', 'https://panel.frostbyt3gaming.com');
}

if (!function_exists('pteroRequest')) {
    function pteroRequest(string $method, string $endpoint, ?array $body = null): array
    {
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

        $headers = [
            'Accept: Application/vnd.pterodactyl.v1+json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . PTERO_API_KEY,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
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
        $curlErr  = curl_error($ch);

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

if (!function_exists('pteroClientRequest')) {
    function pteroClientRequest(string $method, string $endpoint, ?array $body = null): array
    {
        if (!defined('PTERO_CLIENT_API_KEY') || PTERO_CLIENT_API_KEY === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Pterodactyl Client API key is not configured.',
                'data' => null,
            ];
        }

        $url = rtrim(PTERO_BASE_URL, '/') . '/api/client/' . ltrim($endpoint, '/');

        $ch = curl_init($url);

        $headers = [
            'Accept: Application/vnd.pterodactyl.v1+json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . PTERO_CLIENT_API_KEY,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
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
        $curlErr  = curl_error($ch);

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
                    'error' => 'Invalid JSON response from Pterodactyl Client API.',
                    'data' => $response,
                ];
            }
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'ok' => true,
                'status' => $httpCode,
                'error' => null,
                'data' => $decoded,
            ];
        }

        return [
            'ok' => false,
            'status' => $httpCode,
            'error' => $decoded['errors'][0]['detail'] ?? 'Unknown Client API error',
            'data' => $decoded,
        ];
    }
}

if (!function_exists('pteroGetServers')) {
    function pteroGetServers(int $page = 1, int $perPage = 100): array
    {
        return pteroRequest('GET', "servers?page={$page}&per_page={$perPage}&include=node,allocations,user,egg");
    }
}

if (!function_exists('pteroGetServer')) {
    function pteroGetServer(int $serverId): array
    {
        return pteroRequest('GET', "servers/{$serverId}?include=node,allocations,user,egg");
    }
}

if (!function_exists('pteroGetNode')) {
    function pteroGetNode(int $nodeId): array
    {
        return pteroRequest('GET', "nodes/{$nodeId}");
    }
}

if (!function_exists('pteroGetUsers')) {
    function pteroGetUsers(int $page = 1, int $perPage = 100): array
    {
        return pteroRequest('GET', "users?page={$page}&per_page={$perPage}");
    }
}

if (!function_exists('pteroGetAllUsers')) {
    function pteroGetAllUsers(int $perPage = 100): array
    {
        $page = 1;
        $allUsers = [];

        do {
            $result = pteroGetUsers($page, $perPage);

            if (!$result['ok']) {
                break;
            }

            $data = $result['data']['data'] ?? [];
            $meta = $result['data']['meta']['pagination'] ?? [];

            foreach ($data as $user) {
                $allUsers[] = $user['attributes'] ?? [];
            }

            $currentPage = (int)($meta['current_page'] ?? $page);
            $totalPages  = (int)($meta['total_pages'] ?? $page);

            $page++;
        } while ($currentPage < $totalPages);

        return $allUsers;
    }
}

if (!function_exists('pteroFindUserByEmail')) {
    function pteroFindUserByEmail(string $email): ?array
    {
        $users = pteroGetAllUsers();

        foreach ($users as $user) {
            if (!empty($user['email']) && strcasecmp($user['email'], $email) === 0) {
                return $user;
            }
        }

        return null;
    }
}

if (!function_exists('pteroGetAllServers')) {
    function pteroGetAllServers(int $perPage = 100): array
    {
        $page = 1;
        $allServers = [];

        do {
            $result = pteroGetServers($page, $perPage);

            if (!$result['ok']) {
                break;
            }

            $data = $result['data']['data'] ?? [];
            $meta = $result['data']['meta']['pagination'] ?? [];

            foreach ($data as $server) {
                $allServers[] = $server;
            }

            $currentPage = (int) ($meta['current_page'] ?? $page);
            $totalPages = (int) ($meta['total_pages'] ?? $page);

            $page++;
        } while ($currentPage < $totalPages);

        return $allServers;
    }
}

if (!function_exists('pteroGetServersForUserId')) {
    function pteroGetServersForUserId(int $panelUserId): array
    {
        $servers = pteroGetAllServers();

        return array_values(array_filter($servers, function (array $server) use ($panelUserId) {
            $attrs = $server['attributes'] ?? $server;
            return (int) ($attrs['user'] ?? 0) === $panelUserId;
        }));
    }
}

if (!function_exists('pteroPrimaryAllocation')) {
    function pteroPrimaryAllocation(array $serverAttributes): ?array
    {
        $allocs = $serverAttributes['relationships']['allocations']['data'] ?? [];

        foreach ($allocs as $allocation) {
            $attrs = $allocation['attributes'] ?? [];
            if (!empty($attrs['is_default'])) {
                return $attrs;
            }
        }

        return $allocs[0]['attributes'] ?? null;
    }
}

if (!function_exists('pteroSanitizeServerForSite')) {
    function pteroSanitizeServerForSite(array $server): array
    {
        $attrs = $server['attributes'] ?? $server;

        $relationships = $server['relationships']
            ?? $attrs['relationships']
            ?? [];

        $eggRel = $relationships['egg']['data']['attributes'] ?? [];
        $node   = $relationships['node']['data']['attributes'] ?? [];
        $user   = $relationships['user']['data']['attributes'] ?? [];

        $nodeId = (int)($attrs['node'] ?? 0);

        if (
            $nodeId > 0 &&
            (
                empty($node['fqdn']) ||
                empty($node['daemon_sftp'])
            )
        ) {
            $nodeResult = pteroGetNode($nodeId);

            if (!empty($nodeResult['ok'])) {
                $nodeAttrs = $nodeResult['data']['attributes'] ?? $nodeResult['attributes'] ?? [];

                if (is_array($nodeAttrs) && !empty($nodeAttrs)) {
                    $node = array_merge($nodeAttrs, $node);
                }
            }
        }

        $allocs = $relationships['allocations']['data'] ?? [];
        $allocation = null;

        foreach ($allocs as $item) {
            $a = $item['attributes'] ?? [];
            if (!empty($a['is_default'])) {
                $allocation = $a;
                break;
            }
        }

        if ($allocation === null && !empty($allocs[0])) {
            $allocation = $allocs[0]['attributes'] ?? [];
        }

        $eggId  = (int)($attrs['egg'] ?? $attrs['egg_id'] ?? 0);
        $nestId = (int)($attrs['nest'] ?? $attrs['nest_id'] ?? 0);

        $eggName = (string)($eggRel['name'] ?? '');

        if ($eggName === '' || strtolower($eggName) === 'unknown') {
            if (function_exists('pteroResolveEggName')) {
                $resolvedEggName = pteroResolveEggName($nestId, $eggId);

                if (is_string($resolvedEggName) && trim($resolvedEggName) !== '') {
                    $eggName = trim($resolvedEggName);
                }
            }
        }

        $rawStatus = strtolower(trim((string)($attrs['status'] ?? '')));
        $isInstalling = !empty($attrs['is_installing']) || $rawStatus === 'installing';

        return [
            'id' => (int)($attrs['id'] ?? 0),
            'uuid' => $attrs['uuid'] ?? '',
            'identifier' => $attrs['identifier'] ?? '',
            'name' => $attrs['name'] ?? 'Unnamed Server',
            'description' => $attrs['description'] ?? '',
            'suspended' => !empty($attrs['suspended']),
            'is_installing' => $isInstalling,
            'install_status' => $rawStatus,

            'owner_id' => (int)($attrs['user'] ?? 0),
            'owner_username' => $user['username'] ?? '',
            'owner_email' => $user['email'] ?? '',

            'node_id' => $nodeId,
            'node_name' => $node['name'] ?? '',
            'node_fqdn' => (string)($node['fqdn'] ?? ''),
            'node_scheme' => (string)($node['scheme'] ?? 'https'),
            'node_daemon_base' => (string)($node['daemon_base'] ?? ''),
            'node_daemon_sftp' => (string)($node['daemon_sftp'] ?? ''),

            'allocation_ip' => (string)($allocation['ip'] ?? ''),
            'allocation_alias' => (string)($allocation['alias'] ?? ''),
            'allocation_port' => (string)($allocation['port'] ?? ''),

            'memory' => (int)($attrs['limits']['memory'] ?? 0),
            'disk' => (int)($attrs['limits']['disk'] ?? 0),
            'cpu' => (int)($attrs['limits']['cpu'] ?? 0),
            'feature_allocations' => (int)($attrs['feature_limits']['allocations'] ?? 0),

            'created_at' => $attrs['created_at'] ?? '',
            'updated_at' => $attrs['updated_at'] ?? '',
            'expired_at' => $attrs['expired_at'] ?? null,
            'is_expired' => !empty($attrs['expired_at']) && strtotime($attrs['expired_at']) <= time(),

            'egg_id' => $eggId,
            'nest_id' => $nestId,
            'egg_name' => $eggName !== '' ? $eggName : 'unknown',
        ];
    }
}

if (!function_exists('pteroGetServerStatus')) {
    function pteroGetServerStatus(string $identifier): ?string
    {
        $resources = pteroGetServerResources($identifier);
        return $resources['status'] ?? null;
    }
}

if (!function_exists('pteroGetServerResources')) {
    function pteroGetServerResources(string $identifier): array
    {
        $result = pteroClientRequest('GET', "servers/{$identifier}/resources");

        if (!$result['ok']) {
            return [
                'status' => 'unknown',
                'cpu' => 0,
                'memory_bytes' => 0,
                'disk_bytes' => 0,
                'uptime' => 0,
            ];
        }

        $attributes = $result['data']['attributes'] ?? [];
        $resources  = $attributes['resources'] ?? [];

        return [
            'status' => $attributes['current_state'] ?? 'unknown',
            'cpu' => (float) ($resources['cpu_absolute'] ?? 0),
            'memory_bytes' => (int) ($resources['memory_bytes'] ?? 0),
            'disk_bytes' => (int) ($resources['disk_bytes'] ?? 0),
            'uptime' => (int) ($resources['uptime'] ?? 0),
        ];
    }
}

if (!function_exists('pteroSendPowerAction')) {
    function pteroSendPowerAction(string $identifier, string $signal): array
    {
        $allowedSignals = ['start', 'stop', 'restart', 'kill'];

        if (!in_array($signal, $allowedSignals, true)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid power signal.',
                'data' => null,
            ];
        }

        return pteroClientRequest('POST', "servers/{$identifier}/power", [
            'signal' => $signal,
        ]);
    }
}

if (!function_exists('pteroGetAllServersForUserId')) {
    function pteroGetAllServersForUserId(int $panelUserId, int $perPage = 100): array
    {
        $page = 1;
        $allServers = [];

        do {
            $result = pteroRequest(
                'GET',
                "servers?page={$page}&per_page={$perPage}&include=node,allocations,user,egg"
            );

            if (!$result['ok']) {
                break;
            }

            $data = $result['data']['data'] ?? [];
            $meta = $result['data']['meta']['pagination'] ?? [];

            foreach ($data as $server) {
                $attrs = $server['attributes'] ?? [];

                if ((int)($attrs['user'] ?? 0) === $panelUserId) {
                    $allServers[] = $server;
                }
            }

            $currentPage = (int)($meta['current_page'] ?? $page);
            $totalPages  = (int)($meta['total_pages'] ?? $page);

            $page++;
        } while ($currentPage < $totalPages);

        return $allServers;
    }
}

if (!function_exists('pteroGetAccessibleServersForCurrentUser')) {
    function pteroGetAccessibleServersForCurrentUser(): array
    {
        if (!function_exists('canAccess')) {
            $functionsFile = __DIR__ . '/../includes/functions.php';
            if (is_file($functionsFile)) {
                require_once $functionsFile;
            }
        }

        $panelUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($panelUserId <= 0) {
            return [];
        }

        if (function_exists('isShowingAllServers') && isShowingAllServers()) {
            return pteroGetAllServers();
        }

        return pteroGetAllServersForUserId($panelUserId);
    }
}

if (!function_exists('pteroGetSanitizedAccessibleServersForCurrentUser')) {
    function pteroGetSanitizedAccessibleServersForCurrentUser(): array
    {
        $servers = pteroGetAccessibleServersForCurrentUser();
        $cleanServers = [];

        foreach ($servers as $server) {
            $clean = pteroSanitizeServerForSite($server);
            $cleanServers[] = $clean;
        }

        return $cleanServers;
    }
}

if (!function_exists('pteroSyncAllowedServersSession')) {
    function pteroSyncAllowedServersSession(array $servers): void
    {
        $_SESSION['allowed_servers'] = array_values(array_filter(array_map(
            static fn(array $server) => (string)($server['identifier'] ?? ''),
            $servers
        )));
    }
}

if (!function_exists('pteroGetEgg')) {
    function pteroGetEgg(int $nestId, int $eggId): array
    {
        return pteroRequest('GET', "nests/{$nestId}/eggs/{$eggId}");
    }
}

if (!function_exists('pteroResolveEggName')) {
    function pteroResolveEggName(int $nestId, int $eggId): string
    {
        if ($nestId <= 0 || $eggId <= 0) {
            return '';
        }

        $result = pteroGetEgg($nestId, $eggId);

        if (empty($result['ok'])) {
            return '';
        }

        $data = $result['data'] ?? $result['attributes'] ?? [];
        $attrs = $data['attributes'] ?? $data;

        return trim((string)($attrs['name'] ?? ''));
    }
}

if (!function_exists('pteroRegistrationRequest')) {
    function pteroRegistrationRequest(string $method, string $endpoint, ?array $body = null): array
    {
        if (!defined('PTERO_REGISTRATION_API_KEY') || PTERO_REGISTRATION_API_KEY === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Pterodactyl registration API key is not configured.',
                'data' => null,
            ];
        }

        $url = rtrim(PTERO_BASE_URL, '/') . '/api/application/' . ltrim($endpoint, '/');

        $ch = curl_init($url);

        $headers = [
            'Accept: Application/vnd.pterodactyl.v1+json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . PTERO_REGISTRATION_API_KEY,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr) {
            return [
                'ok' => false,
                'status' => $httpCode ?: 0,
                'error' => 'cURL error: ' . $curlErr,
                'data' => null,
            ];
        }

        $decoded = json_decode($response, true);

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

if (!function_exists('pteroCreateUser')) {
    function pteroCreateUser(array $userData): array
    {
        return pteroRegistrationRequest('POST', 'users', [
            'email' => trim((string)($userData['email'] ?? '')),
            'username' => trim((string)($userData['username'] ?? '')),
            'first_name' => trim((string)($userData['first_name'] ?? '')),
            'last_name' => trim((string)($userData['last_name'] ?? '')),
            'password' => (string)($userData['password'] ?? ''),
        ]);
    }
}

if (!function_exists('pteroFindUserByUsername')) {
    function pteroFindUserByUsername(string $username): ?array
    {
        $users = pteroGetAllUsers();

        foreach ($users as $user) {
            if (!empty($user['username']) && strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }

        return null;
    }
}

if (!function_exists('pteroSendConsoleCommand')) {
    function pteroSendConsoleCommand(string $identifier, string $command): array
    {
        $identifier = trim($identifier);
        $command = trim($command);

        if ($identifier === '' || $command === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing server identifier or command.',
                'data' => null,
            ];
        }

        return pteroClientRequest('POST', "servers/{$identifier}/command", [
            'command' => $command,
        ]);
    }
}

if (!function_exists('pteroGetConsoleWebsocket')) {
    function pteroGetConsoleWebsocket(string $identifier): array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing server identifier.',
                'data' => null,
            ];
        }

        return pteroClientRequest('GET', "servers/{$identifier}/websocket");
    }
}

if (!function_exists('pteroUpdateServerDetails')) {
    function pteroUpdateServerDetails(int $serverId, string $name, string $description = ''): array
    {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        $server = pteroGetServer($serverId);

        if (empty($server['ok'])) {
            return [
                'ok' => false,
                'status' => $server['status'] ?? 0,
                'error' => $server['error'] ?? 'Failed to load server details.',
                'data' => null,
            ];
        }

        $attrs = $server['data']['attributes'] ?? [];

        $userId = (int)($attrs['user'] ?? 0);

        if ($userId <= 0) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Server owner could not be determined.',
                'data' => null,
            ];
        }

        $payload = [
            'name' => $name,
            'user' => $userId,
            'description' => $description,
        ];

        if (array_key_exists('external_id', $attrs)) {
            $payload['external_id'] = $attrs['external_id'];
        }

        return pteroRequest('PATCH', "servers/{$serverId}/details", $payload);
    }
}

if (!function_exists('pteroListServerFiles')) {
    function pteroListServerFiles(string $identifier, string $directory = '/'): array
    {
        $directory = trim($directory);
        if ($directory === '') {
            $directory = '/';
        }

        $result = pteroClientRequest(
            'GET',
            'servers/' . rawurlencode($identifier) . '/files/list?directory=' . rawurlencode($directory)
        );

        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? 'Failed to load server files.');
        }

        $data = $result['data']['data'] ?? [];
        $items = [];

        foreach ($data as $item) {
            $attributes = $item['attributes'] ?? null;
            if (is_array($attributes)) {
                $items[] = $attributes;
            }
        }

        return $items;
    }
}

if (!function_exists('pteroGetServerFileDownloadUrl')) {
    function pteroGetServerFileDownloadUrl(string $identifier, string $filePath): string
    {
        $identifier = trim($identifier);
        $filePath = trim(str_replace('\\', '/', $filePath));

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        if ($filePath === '') {
            throw new RuntimeException('Missing file path.');
        }

        if ($filePath[0] !== '/') {
            $filePath = '/' . $filePath;
        }

        $result = pteroClientRequest(
            'GET',
            'servers/' . rawurlencode($identifier) . '/files/download?file=' . rawurlencode($filePath)
        );

        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? 'Failed to generate download URL.');
        }

        $url = $result['data']['attributes']['url'] ?? null;

        if (!is_string($url) || trim($url) === '') {
            throw new RuntimeException('Pterodactyl did not return a valid download URL.');
        }

        return $url;
    }
}

if (!function_exists('pteroDeleteServerFiles')) {
    function pteroDeleteServerFiles(string $identifier, string $root, array $files): bool
    {
        $identifier = trim($identifier);
        $root = trim(str_replace('\\', '/', $root));

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        if ($root === '') {
            $root = '/';
        }

        if ($root[0] !== '/') {
            $root = '/' . $root;
        }

        $cleanFiles = [];

        foreach ($files as $file) {
            $file = trim((string)$file);

            if ($file === '' || $file === '.' || $file === '..') {
                continue;
            }

            if (str_contains($file, '/')
                || str_contains($file, '\\')
            ) {
                throw new RuntimeException('Delete payload contains an invalid file name.');
            }

            $cleanFiles[] = $file;
        }

        if ($cleanFiles === []) {
            throw new RuntimeException('No files were provided for deletion.');
        }

        $result = pteroClientRequest(
            'POST',
            'servers/' . rawurlencode($identifier) . '/files/delete',
            [
                'root'  => $root,
                'files' => array_values($cleanFiles),
            ]
        );

        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? 'Failed to delete file(s).');
        }

        return true;
    }
}

if (!function_exists('pteroRenameServerFiles')) {
    function pteroRenameServerFiles(string $identifier, string $root, array $files): bool
    {
        $identifier = trim($identifier);
        $root = trim(str_replace('\\', '/', $root));

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        if ($root === '') {
            $root = '/';
        }

        if ($root[0] !== '/') {
            $root = '/' . $root;
        }

        $cleanFiles = [];

        foreach ($files as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $from = trim((string)($entry['from'] ?? ''));
            $to   = trim((string)($entry['to'] ?? ''));

            if ($from === '' || $to === '') {
                continue;
            }

            if ($from === '.' || $from === '..' || $to === '.' || $to === '..') {
                throw new RuntimeException('Rename payload contains an invalid file name.');
            }

            if (str_contains($from, '/') || str_contains($from, '\\')) {
                throw new RuntimeException('Rename source must be a file or folder name only.');
            }

            if (str_contains($to, '/') || str_contains($to, '\\')) {
                throw new RuntimeException('Rename destination must be a file or folder name only.');
            }

            $cleanFiles[] = [
                'from' => $from,
                'to'   => $to,
            ];
        }

        if ($cleanFiles === []) {
            throw new RuntimeException('No rename operations were provided.');
        }

        $result = pteroClientRequest(
            'PUT',
            'servers/' . rawurlencode($identifier) . '/files/rename',
            [
                'root'  => $root,
                'files' => array_values($cleanFiles),
            ]
        );

        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? 'Failed to rename file(s).');
        }

        return true;
    }
}

if (!function_exists('pteroGetServerFileUploadUrl')) {
    function pteroGetServerFileUploadUrl(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        $result = pteroClientRequest(
            'GET',
            'servers/' . rawurlencode($identifier) . '/files/upload'
        );

        if (!$result['ok']) {
            throw new RuntimeException($result['error'] ?? 'Failed to generate upload URL.');
        }

        $url = $result['data']['attributes']['url'] ?? null;

        if (!is_string($url) || trim($url) === '') {
            throw new RuntimeException('Pterodactyl did not return a valid upload URL.');
        }

        return $url;
    }
}

if (!function_exists('pteroReadServerFile')) {
    function pteroReadServerFile(string $identifier, string $filePath): string
    {
        if (!defined('PTERO_CLIENT_API_KEY') || PTERO_CLIENT_API_KEY === '') {
            throw new RuntimeException('Pterodactyl Client API key is not configured.');
        }

        $identifier = trim($identifier);
        $filePath = trim(str_replace('\\', '/', $filePath));

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        if ($filePath === '') {
            throw new RuntimeException('Missing file path.');
        }

        if ($filePath[0] !== '/') {
            $filePath = '/' . $filePath;
        }

        $url = rtrim(PTERO_BASE_URL, '/') . '/api/client/servers/' . rawurlencode($identifier)
            . '/files/contents?file=' . rawurlencode($filePath);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => [
                'Accept: Application/vnd.pterodactyl.v1+json',
                'Authorization: Bearer ' . PTERO_CLIENT_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr) {
            throw new RuntimeException('cURL error: ' . $curlErr);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($response, true);
            $message = $decoded['errors'][0]['detail'] ?? 'Failed to read file contents.';
            throw new RuntimeException($message);
        }

        return (string)$response;
    }
}

if (!function_exists('pteroWriteServerFile')) {
    function pteroWriteServerFile(string $identifier, string $filePath, string $contents): bool
    {
        if (!defined('PTERO_CLIENT_API_KEY') || PTERO_CLIENT_API_KEY === '') {
            throw new RuntimeException('Pterodactyl Client API key is not configured.');
        }

        $identifier = trim($identifier);
        $filePath = trim(str_replace('\\', '/', $filePath));

        if ($identifier === '') {
            throw new RuntimeException('Missing server identifier.');
        }

        if ($filePath === '') {
            throw new RuntimeException('Missing file path.');
        }

        if ($filePath[0] !== '/') {
            $filePath = '/' . $filePath;
        }

        $url = rtrim(PTERO_BASE_URL, '/') . '/api/client/servers/' . rawurlencode($identifier)
            . '/files/write?file=' . rawurlencode($filePath);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $contents,
            CURLOPT_HTTPHEADER     => [
                'Accept: Application/vnd.pterodactyl.v1+json',
                'Content-Type: text/plain; charset=utf-8',
                'Authorization: Bearer ' . PTERO_CLIENT_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curlErr) {
            throw new RuntimeException('cURL error: ' . $curlErr);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($response, true);
            $message = $decoded['errors'][0]['detail'] ?? 'Failed to save file.';
            throw new RuntimeException($message);
        }

        return true;
    }
}

if (!function_exists('pteroListSchedules')) {
    function pteroListSchedules(string $serverIdentifier): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/schedules");
    }
}

if (!function_exists('pteroGetSchedule')) {
    function pteroGetSchedule(string $serverIdentifier, int $scheduleId): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/schedules/{$scheduleId}");
    }
}

if (!function_exists('pteroCreateSchedule')) {
    function pteroCreateSchedule(string $serverIdentifier, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/schedules", $payload);
    }
}

if (!function_exists('pteroUpdateSchedule')) {
    function pteroUpdateSchedule(string $serverIdentifier, int $scheduleId, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/schedules/{$scheduleId}", $payload);
    }
}

if (!function_exists('pteroDeleteSchedule')) {
    function pteroDeleteSchedule(string $serverIdentifier, int $scheduleId): array
    {
        return pteroClientRequest('DELETE', "servers/{$serverIdentifier}/schedules/{$scheduleId}");
    }
}

if (!function_exists('pteroExecuteSchedule')) {
    function pteroExecuteSchedule(string $serverIdentifier, int $scheduleId): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/schedules/{$scheduleId}/execute");
    }
}

if (!function_exists('pteroCreateScheduleTask')) {
    function pteroCreateScheduleTask(string $serverIdentifier, int $scheduleId, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/schedules/{$scheduleId}/tasks", $payload);
    }
}

if (!function_exists('pteroUpdateScheduleTask')) {
    function pteroUpdateScheduleTask(string $serverIdentifier, int $scheduleId, int $taskId, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/schedules/{$scheduleId}/tasks/{$taskId}", $payload);
    }
}

if (!function_exists('pteroDeleteScheduleTask')) {
    function pteroDeleteScheduleTask(string $serverIdentifier, int $scheduleId, int $taskId): array
    {
        return pteroClientRequest('DELETE', "servers/{$serverIdentifier}/schedules/{$scheduleId}/tasks/{$taskId}");
    }
}

if (!function_exists('pteroListSubusers')) {
    function pteroListSubusers(string $serverIdentifier): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/users");
    }
}

if (!function_exists('pteroGetSubuser')) {
    function pteroGetSubuser(string $serverIdentifier, string $subuserUuid): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/users/{$subuserUuid}");
    }
}

if (!function_exists('pteroCreateSubuser')) {
    function pteroCreateSubuser(string $serverIdentifier, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/users", $payload);
    }
}

if (!function_exists('pteroUpdateSubuser')) {
    function pteroUpdateSubuser(string $serverIdentifier, string $subuserUuid, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/users/{$subuserUuid}", $payload);
    }
}

if (!function_exists('pteroDeleteSubuser')) {
    function pteroDeleteSubuser(string $serverIdentifier, string $subuserUuid): array
    {
        return pteroClientRequest('DELETE', "servers/{$serverIdentifier}/users/{$subuserUuid}");
    }
}

if (!function_exists('pteroSubuserPermissionCatalog')) {
    function pteroSubuserPermissionCatalog(): array
    {
        return [
            'Server Control' => [
                'control.console'   => 'View console output and send commands',
                'control.start'     => 'Start the server',
                'control.stop'      => 'Stop the server and force kill it if it is already stopping',
                'control.restart'   => 'Restart the server',
            ],
            'Files' => [
                'file.create'  => 'Create files and folders',
                'file.read'    => 'Read files and browse folders',
                'file.update'  => 'Edit existing files',
                'file.delete'  => 'Delete files and folders',
                'file.archive' => 'Archive and extract files',
                'file.sftp'    => 'Access files over SFTP',
            ],
            'Backups' => [
                'backup.create'   => 'Create backups',
                'backup.read'     => 'View backups',
                'backup.delete'   => 'Delete backups',
                'backup.download' => 'Download backups',
                'backup.restore'  => 'Restore backups',
            ],
            'Allocations' => [
                'allocation.read'   => 'View allocations',
                'allocation.create' => 'Assign allocations',
                'allocation.update' => 'Edit allocation notes/settings',
                'allocation.delete' => 'Remove allocations',
            ],
            'Databases' => [
                'database.create' => 'Create databases',
                'database.read'   => 'View databases',
                'database.update' => 'Rotate database passwords',
                'database.delete' => 'Delete databases',
            ],
            'Schedules' => [
                'schedule.create' => 'Create schedules',
                'schedule.read'   => 'View schedules and tasks',
                'schedule.update' => 'Edit schedules and tasks',
                'schedule.delete' => 'Delete schedules',
            ],
            'Users' => [
                'user.create' => 'Create subusers',
                'user.read'   => 'View subusers',
                'user.update' => 'Edit subuser permissions',
                'user.delete' => 'Delete subusers',
            ],
            'Startup' => [
                'startup.read'   => 'View startup settings',
                'startup.update' => 'Edit startup settings',
            ],
            'Settings' => [
                'settings.rename'    => 'Allows a user to rename this server and change the description of it.',
                'settings.reinstall' => 'Allows a user to trigger a reinstall of this server.',
            ],
            'Activity' => [
                'activity.read'     => 'Allows a user to read this server\'s activity logs.',
            ]
        ];
    }
}

if (!function_exists('pteroSubuserPermissionTemplates')) {
    function pteroSubuserPermissionTemplates(): array
    {
        return [
            'readonly' => [
                'label' => 'Read Only',
                'permissions' => [
                    'control.console',
                    'file.read',
                    'backup.read',
                    'allocation.read',
                    'database.read',
                    'schedule.read',
                    'user.read',
                    'startup.read',
                    'activity.read',
                ],
            ],
            'moderator' => [
                'label' => 'Moderator',
                'permissions' => [
                    'control.console',
                    'control.start',
                    'control.stop',
                    'control.restart',
                    'file.read',
                    'file.update',
                    'backup.read',
                    'backup.create',
                    'settings.rename',
                    'activity.read',
                ],
            ],
            'developer' => [
                'label' => 'Developer',
                'permissions' => [
                    'control.console',
                    'control.start',
                    'control.stop',
                    'control.restart',
                    'file.create',
                    'file.read',
                    'file.update',
                    'file.delete',
                    'file.archive',
                    'file.sftp',
                    'backup.create',
                    'backup.read',
                    'backup.download',
                    'database.read',
                    'startup.read',
                    'settings.rename',
                    'settings.reinstall',
                    'activity.read',
                ],
            ],
            'admin' => [
                'label' => 'Administrator',
                'permissions' => [
                    'control.console',
                    'control.start',
                    'control.stop',
                    'control.restart',
                    'file.create',
                    'file.read',
                    'file.update',
                    'file.delete',
                    'file.archive',
                    'backup.create',
                    'backup.read',
                    'backup.delete',
                    'backup.download',
                    'backup.restore',
                    'allocation.read',
                    'allocation.create',
                    'allocation.update',
                    'allocation.delete',
                    'database.create',
                    'database.read',
                    'database.update',
                    'database.delete',
                    'schedule.create',
                    'schedule.read',
                    'schedule.update',
                    'schedule.delete',
                    'startup.read',
                    'startup.update',
                    'settings.rename',
                    'settings.reinstall',
                    'activity.read',
                ],
            ],
        ];
    }
}

if (!function_exists('pteroDecodeSubuserPermissions')) {
    function pteroDecodeSubuserPermissions(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('strval', $raw))));
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $decoded))));
    }
}

if (!function_exists('pteroGetOwnerPermissionSet')) {
    function pteroGetOwnerPermissionSet(): array
    {
        return [
            'control.console',
            'control.start',
            'control.stop',
            'control.restart',

            'file.create',
            'file.read',
            'file.read-content',
            'file.update',
            'file.delete',
            'file.archive',
            'file.sftp',

            'backup.create',
            'backup.read',
            'backup.delete',
            'backup.download',
            'backup.restore',

            'allocation.read',
            'allocation.create',
            'allocation.update',
            'allocation.delete',

            'startup.read',
            'startup.update',
            'startup.docker-image',

            'database.create',
            'database.read',
            'database.update',
            'database.delete',
            'database.view_password',

            'schedule.create',
            'schedule.read',
            'schedule.update',
            'schedule.delete',

            'user.create',
            'user.read',
            'user.update',
            'user.delete',

            'settings.rename',
            'settings.reinstall',

            'activity.read',

            'websocket.connect',
        ];
    }
}

if (!function_exists('pteroGetServerAccessMapForUser')) {
    function pteroGetServerAccessMapForUser(int $panelUserId, bool $includeAdminAllServers = true): array
    {
        if ($panelUserId <= 0) {
            return [];
        }

        $isPanelAdmin = pteroUserIsPanelAdmin($panelUserId);
        $servers = ($isPanelAdmin && !$includeAdminAllServers)
            ? pteroGetAllServersForUserId($panelUserId)
            : pteroGetAllServers();
        $accessMap = [];

        foreach ($servers as $server) {
            $clean = pteroSanitizeServerForSite($server);

            $identifier = (string)($clean['identifier'] ?? '');
            $serverDbId = (int)($clean['id'] ?? 0);
            $ownerId    = (int)($clean['owner_id'] ?? 0);

            if ($identifier === '' || $serverDbId <= 0) {
                continue;
            }

            if ($isPanelAdmin && ($includeAdminAllServers || $ownerId === $panelUserId)) {
                $accessMap[$identifier] = [
                    'server' => $clean,
                    'is_owner' => ($ownerId === $panelUserId),
                    'is_panel_admin' => true,
                    'permissions' => pteroGetOwnerPermissionSet(),
                ];
                continue;
            }

            if ($ownerId === $panelUserId) {
                $accessMap[$identifier] = [
                    'server' => $clean,
                    'is_owner' => true,
                    'is_panel_admin' => false,
                    'permissions' => pteroGetOwnerPermissionSet(),
                ];
                continue;
            }

            $stmt = fbgPteroDb()->prepare("
                SELECT permissions
                FROM subusers
                WHERE server_id = :server_id
                  AND user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                'server_id' => $serverDbId,
                'user_id'   => $panelUserId,
            ]);

            $row = $stmt->fetch();

            if ($row) {
                $accessMap[$identifier] = [
                    'server' => $clean,
                    'is_owner' => false,
                    'is_panel_admin' => false,
                    'permissions' => pteroDecodeSubuserPermissions($row['permissions'] ?? null),
                ];
            }
        }

        return $accessMap;
    }
}

if (!function_exists('pteroGetAccessibleServersForCurrentUser')) {
    function pteroGetAccessibleServersForCurrentUser(): array
    {
        $panelUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($panelUserId <= 0) {
            return [];
        }

        $accessMap = pteroGetServerAccessMapForUser($panelUserId);

        return array_values(array_map(
            static fn(array $entry): array => $entry['server'],
            $accessMap
        ));
    }
}

if (!function_exists('pteroSyncServerAccessSession')) {
    function pteroSyncServerAccessSession(array $accessMap, bool $includesAdminAllServers = true): void
    {
        $allowedServers = [];
        $serverPermissions = [];
        $serverOwnership = [];
        $serverPanelAdmin = [];
        $serverMeta = [];

        foreach ($accessMap as $identifier => $entry) {
            $server = is_array($entry['server'] ?? null) ? $entry['server'] : [];
            $identifier = (string)$identifier;

            if ($identifier === '') {
                continue;
            }

            $allowedServers[] = $identifier;
            $serverPermissions[$identifier] = array_values(array_unique(array_filter(
                array_map('strval', $entry['permissions'] ?? [])
            )));
            $serverOwnership[$identifier] = !empty($entry['is_owner']);
            $serverPanelAdmin[$identifier] = !empty($entry['is_panel_admin']);

            $serverMeta[$identifier] = [
                'id' => (int)($server['id'] ?? 0),
                'identifier' => $identifier,
                'name' => (string)($server['name'] ?? ''),
                'description' => (string)($server['description'] ?? ''),
                'uuid' => (string)($server['uuid'] ?? ''),
                'is_installing' => !empty($server['is_installing']),
                'install_status' => (string)($server['install_status'] ?? ''),
                'allocation_ip' => (string)($server['allocation_ip'] ?? ''),
                'allocation_alias' => (string)($server['allocation_alias'] ?? ''),
                'allocation_port' => (string)($server['allocation_port'] ?? ''),
                'memory' => (int)($server['memory'] ?? 0),
                'disk' => (int)($server['disk'] ?? 0),
                'cpu' => (int)($server['cpu'] ?? 0),
                'node_id' => (int)($server['node_id'] ?? 0),
                'node_name' => (string)($server['node_name'] ?? ''),
                'egg_name' => (string)($server['egg_name'] ?? ''),
                'suspended' => !empty($server['suspended']),
                'expired_at' => (string)($server['expired_at'] ?? ''),
                'owner_username' => (string)($server['owner_username'] ?? ''),
            ];
        }

        $_SESSION['allowed_servers'] = array_values(array_unique($allowedServers));
        $_SESSION['server_permissions'] = $serverPermissions;
        $_SESSION['server_is_owner'] = $serverOwnership;
        $_SESSION['server_is_panel_admin'] = $serverPanelAdmin;
        $_SESSION['server_meta'] = $serverMeta;
        $_SESSION['server_access_last_sync'] = time();
        $_SESSION['server_access_includes_admin_all'] = $includesAdminAllServers ? 1 : 0;
    }
}

if (!function_exists('pteroEnsureServerAccessSession')) {
    function pteroEnsureServerAccessSession(bool $forceRefresh = false, bool $includeAdminAllServers = true): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $panelUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($panelUserId <= 0) {
            return [];
        }

        $allowedServers = $_SESSION['allowed_servers'] ?? [];
        $cachedIncludesAdminAll = !empty($_SESSION['server_access_includes_admin_all']);

        if (
            !$forceRefresh &&
            is_array($allowedServers) &&
            !empty($allowedServers) &&
            (!$includeAdminAllServers || $cachedIncludesAdminAll)
        ) {
            return [
                'allowed_servers' => $allowedServers,
                'server_permissions' => $_SESSION['server_permissions'] ?? [],
                'server_is_owner' => $_SESSION['server_is_owner'] ?? [],
                'server_is_panel_admin' => $_SESSION['server_is_panel_admin'] ?? [],
                'server_meta' => $_SESSION['server_meta'] ?? [],
                'last_sync' => (int)($_SESSION['server_access_last_sync'] ?? 0),
                'includes_admin_all' => $cachedIncludesAdminAll,
            ];
        }

        $accessMap = pteroGetServerAccessMapForUser($panelUserId, $includeAdminAllServers);

        if (!empty($accessMap)) {
            pteroSyncServerAccessSession($accessMap, $includeAdminAllServers);
        }

        return [
            'allowed_servers' => $_SESSION['allowed_servers'] ?? [],
            'server_permissions' => $_SESSION['server_permissions'] ?? [],
            'server_is_owner' => $_SESSION['server_is_owner'] ?? [],
            'server_is_panel_admin' => $_SESSION['server_is_panel_admin'] ?? [],
            'server_meta' => $_SESSION['server_meta'] ?? [],
            'last_sync' => (int)($_SESSION['server_access_last_sync'] ?? 0),
            'includes_admin_all' => !empty($_SESSION['server_access_includes_admin_all']),
        ];
    }
}

if (!function_exists('pteroGetSessionServerMeta')) {
    function pteroGetSessionServerMeta(string $identifier): array
    {
        $allMeta = $_SESSION['server_meta'] ?? [];

        if (!is_array($allMeta)) {
            return [];
        }

        $meta = $allMeta[$identifier] ?? [];

        return is_array($meta) ? $meta : [];
    }
}

if (!function_exists('pteroUserHasServerPermission')) {
    function pteroUserHasServerPermission(string $identifier, string $permission): bool
    {
        if (!empty($_SESSION['server_is_owner'][$identifier])) {
            return true;
        }

        if (!empty($_SESSION['server_is_panel_admin'][$identifier])) {
            return true;
        }

        $all = $_SESSION['server_permissions'][$identifier] ?? [];

        if (!is_array($all)) {
            return false;
        }

        return in_array($permission, $all, true);
    }
}

if (!function_exists('pteroCurrentUserCanAccessServer')) {
    function pteroCurrentUserCanAccessServer(string $identifier): bool
    {
        $allowedServers = $_SESSION['allowed_servers'] ?? [];
        return is_array($allowedServers) && in_array($identifier, $allowedServers, true);
    }
}

if (!function_exists('pteroRequireServerPermission')) {
    function pteroRequireServerPermission(string $identifier, string $permission): void
    {
        pteroEnsureServerAccessSession(false);

        $allowedServers = array_values(array_filter(array_map(
            'strval',
            $_SESSION['allowed_servers'] ?? []
        )));

        if (!in_array($identifier, $allowedServers, true)) {
            pteroEnsureServerAccessSession(true);

            $allowedServers = array_values(array_filter(array_map(
                'strval',
                $_SESSION['allowed_servers'] ?? []
            )));
        }

        if (!in_array($identifier, $allowedServers, true)) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'Forbidden.',
                'data' => null,
            ]);
            exit;
        }

        $permissions = array_values(array_filter(array_map(
            'strval',
            $_SESSION['server_permissions'][$identifier] ?? []
        )));

        $isOwner = !empty($_SESSION['server_is_owner'][$identifier]);
        $isPanelAdmin = !empty($_SESSION['server_is_panel_admin'][$identifier]);

        if (!$isOwner && !$isPanelAdmin && !in_array($permission, $permissions, true)) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'You do not have permission to perform this action.',
                'data' => null,
            ]);
            exit;
        }
    }
}

if (!function_exists('pteroGetPanelUserById')) {
    function pteroGetPanelUserById(int $panelUserId): ?array
    {
        if ($panelUserId <= 0) {
            return null;
        }

        $result = pteroRequest('GET', 'users/' . $panelUserId);

        if (!$result['ok']) {
            return null;
        }

        return $result['data']['attributes'] ?? null;
    }
}

if (!function_exists('pteroUserIsPanelAdmin')) {
    function pteroUserIsPanelAdmin(int $panelUserId): bool
    {
        $user = pteroGetPanelUserById($panelUserId);

        if (!$user) {
            return false;
        }

        return !empty($user['root_admin']);
    }
}

if (!function_exists('pteroGetServerBackups')) {
    function pteroGetServerBackups(string $serverIdentifier): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/backups");
    }
}

if (!function_exists('pteroCreateServerBackup')) {
    function pteroCreateServerBackup(string $serverIdentifier, array $payload = []): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/backups", $payload);
    }
}

if (!function_exists('pteroGetServerBackupDownload')) {
    function pteroGetServerBackupDownload(string $serverIdentifier, string $backupUuid): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/backups/{$backupUuid}/download");
    }
}

function pteroRestoreServerBackup(string $serverIdentifier, string $backupUuid, bool $truncate = true): array
{
    return pteroClientRequest(
        'POST',
        "/servers/{$serverIdentifier}/backups/{$backupUuid}/restore",
        [
            'truncate' => $truncate,
        ]
    );
}

if (!function_exists('pteroToggleServerBackupLock')) {
    function pteroToggleServerBackupLock(string $serverIdentifier, string $backupUuid): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/backups/{$backupUuid}/lock");
    }
}

if (!function_exists('pteroDeleteServerBackup')) {
    function pteroDeleteServerBackup(string $serverIdentifier, string $backupUuid): array
    {
        return pteroClientRequest('DELETE', "servers/{$serverIdentifier}/backups/{$backupUuid}");
    }
}

if (!function_exists('pteroGetServerNetworkAllocations')) {
    function pteroGetServerNetworkAllocations(string $serverIdentifier): array
    {
        return pteroClientRequest('GET', "servers/{$serverIdentifier}/network/allocations");
    }
}

if (!function_exists('pteroCreateServerNetworkAllocation')) {
    function pteroCreateServerNetworkAllocation(string $serverIdentifier): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/network/allocations");
    }
}

if (!function_exists('pteroUpdateServerNetworkAllocation')) {
    function pteroUpdateServerNetworkAllocation(string $serverIdentifier, int $allocationId, array $payload): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/network/allocations/{$allocationId}", $payload);
    }
}

if (!function_exists('pteroSetPrimaryServerNetworkAllocation')) {
    function pteroSetPrimaryServerNetworkAllocation(string $serverIdentifier, int $allocationId): array
    {
        return pteroClientRequest('POST', "servers/{$serverIdentifier}/network/allocations/{$allocationId}/primary");
    }
}

if (!function_exists('pteroDeleteServerNetworkAllocation')) {
    function pteroDeleteServerNetworkAllocation(string $serverIdentifier, int $allocationId): array
    {
        return pteroClientRequest('DELETE', "servers/{$serverIdentifier}/network/allocations/{$allocationId}");
    }
}

if (!function_exists('pteroGetServerStartup')) {
    function pteroGetServerStartup(string $serverIdentifier): array
    {
        $serverIdentifier = trim($serverIdentifier);

        if ($serverIdentifier === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Missing server identifier.',
                'data' => null,
            ];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Not authenticated.',
                'data' => null,
            ];
        }

        pteroEnsureServerAccessSession(false);

        $selectedServer = pteroGetSessionServerMeta($serverIdentifier);

        if (empty($selectedServer) || empty($selectedServer['id'])) {
            pteroEnsureServerAccessSession(true);
            $selectedServer = pteroGetSessionServerMeta($serverIdentifier);
        }

        if (empty($selectedServer) || empty($selectedServer['id'])) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Server not found.',
                'data' => null,
            ];
        }

        $serverId = (int)($selectedServer['id'] ?? 0);
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        $clientResult = pteroClientRequest('GET', "servers/{$serverIdentifier}/startup");
        if (empty($clientResult['ok'])) {
            return $clientResult;
        }

        $settingsResult = pteroGetServerStartupSettings($serverId);
        if (empty($settingsResult['ok'])) {
            return $settingsResult;
        }

        $clientData = is_array($clientResult['data'] ?? null) ? $clientResult['data'] : [];
        $settingsData = is_array($settingsResult['data'] ?? null) ? $settingsResult['data'] : [];

        $serverAttributes = is_array($settingsData['attributes'] ?? null) ? $settingsData['attributes'] : [];
        $relationships = is_array($settingsData['relationships'] ?? null) ? $settingsData['relationships'] : [];

        $eggAttributes = is_array($relationships['egg']['attributes'] ?? null)
            ? $relationships['egg']['attributes']
            : [];

        $clientMeta = is_array($clientData['meta'] ?? null) ? $clientData['meta'] : [];
        $clientVariables = is_array($clientData['data'] ?? null) ? $clientData['data'] : [];

        $startupCommand = (string)(
            $clientMeta['startup_command']
            ?? $clientMeta['raw_startup_command']
            ?? ($serverAttributes['container']['startup_command'] ?? null)
            ?? $serverAttributes['startup']
            ?? ''
        );

        $dockerImage = (string)(
            $serverAttributes['container']['image']
            ?? $clientMeta['docker_image']
            ?? $serverAttributes['image']
            ?? $serverAttributes['docker_image']
            ?? ''
        );

        $dockerImagesRaw = is_array($clientMeta['docker_images'] ?? null)
            ? $clientMeta['docker_images']
            : (is_array($eggAttributes['docker_images'] ?? null) ? $eggAttributes['docker_images'] : []);

        $dockerImages = [];
        foreach ($dockerImagesRaw as $label => $image) {
            if (!is_string($label) || !is_string($image) || $image === '') {
                continue;
            }

            $dockerImages[$image] = $label;
        }

        $normalizedVariables = [];

        foreach ($clientVariables as $item) {
            if (!is_array($item)) {
                continue;
            }

            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;

            $normalizedVariables[] = [
                'name'          => (string)($attributes['name'] ?? $attributes['env_variable'] ?? ''),
                'description'   => (string)($attributes['description'] ?? ''),
                'env_variable'  => (string)($attributes['env_variable'] ?? ''),
                'default_value' => (string)($attributes['default_value'] ?? ''),
                'server_value'  => (string)($attributes['server_value'] ?? $attributes['value'] ?? ''),
                'is_editable'   => array_key_exists('is_editable', $attributes)
                    ? (bool)$attributes['is_editable']
                    : (array_key_exists('user_editable', $attributes) ? (bool)$attributes['user_editable'] : false),
                'is_viewable'   => array_key_exists('is_viewable', $attributes)
                    ? (bool)$attributes['is_viewable']
                    : (array_key_exists('user_viewable', $attributes) ? (bool)$attributes['user_viewable'] : true),
                'rules'         => (string)($attributes['rules'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'meta' => [
                    'startup_command' => $startupCommand,
                    'docker_image'    => $dockerImage,
                    'docker_images'   => $dockerImages,
                ],
                'data' => $normalizedVariables,
                'server' => [
                    'id'    => $serverId,
                    'egg'   => (int)($selectedServer['egg_id'] ?? $selectedServer['egg'] ?? 0),
                    'image' => $dockerImage,
                ],
            ],
        ];
    }
}

if (!function_exists('pteroUpdateServerStartupSettings')) {
    function pteroUpdateServerStartupSettings(
        int $serverId,
        string $startupCommand,
        array $environment,
        int $eggId,
        string $dockerImage
    ): array {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        $payload = [
            'startup' => $startupCommand,
            'environment' => $environment,
            'egg' => $eggId,
            'image' => $dockerImage,
            'skip_scripts' => false,
        ];

        return pteroRequest('PATCH', "servers/{$serverId}/startup", $payload);
    }
}

if (!function_exists('pteroUpdateServerStartupVariable')) {
    function pteroUpdateServerStartupVariable(string $serverIdentifier, string $key, string $value): array
    {
        return pteroClientRequest('PUT', "servers/{$serverIdentifier}/startup/variable", [
            'key'   => $key,
            'value' => $value,
        ]);
    }
}

if (!function_exists('pteroGetServerStartupSettings')) {
    function pteroGetServerStartupSettings(int $serverId): array
    {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        return pteroRequest('GET', "servers/{$serverId}?include=egg,variables");
    }
}

if (!function_exists('pteroReinstallServer')) {
    function pteroReinstallServer(int $serverId): array
    {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        return pteroRequest('POST', "servers/{$serverId}/reinstall");
    }
}

if (!function_exists('pteroUnsuspendServer')) {
    function pteroUnsuspendServer(int $serverId): array
    {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => null,
            ];
        }

        return pteroRequest('POST', "servers/{$serverId}/unsuspend");
    }
}

if (!function_exists('pteroGetServerActivityLogs')) {
    function pteroGetServerActivityLogs(int $serverId, int $limit = 100): array
    {
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid server ID.',
                'data' => [],
            ];
        }

        $limit = max(1, min($limit, 250));

        try {
            if (!function_exists('fbgPteroDb')) {
                require_once __DIR__ . '/../includes/auth.php';
            }

            $sql = "
                SELECT
                    al.id,
                    al.event,
                    al.ip,
                    al.description,
                    al.actor_type,
                    al.actor_id,
                    al.api_key_id,
                    al.properties,
                    al.`timestamp`,
                    u.username AS actor_username
                FROM activity_logs al
                INNER JOIN activity_log_subjects als
                    ON als.activity_log_id = al.id
                LEFT JOIN users u
                    ON al.actor_type = 'user'
                   AND u.id = al.actor_id
                WHERE als.subject_type = 'server'
                  AND als.subject_id = :server_id
                ORDER BY al.`timestamp` DESC, al.id DESC
                LIMIT {$limit}
            ";

            $stmt = fbgPteroDb()->prepare($sql);
            $stmt->execute([
                'server_id' => $serverId,
            ]);

            $rows = $stmt->fetchAll();
            $items = [];

            foreach ($rows as $row) {
                $properties = [];
                $rawProperties = $row['properties'] ?? null;

                if (is_string($rawProperties) && trim($rawProperties) !== '') {
                    $decoded = json_decode($rawProperties, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $properties = $decoded;
                    }
                } elseif (is_array($rawProperties)) {
                    $properties = $rawProperties;
                }

                $items[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'event' => (string)($row['event'] ?? ''),
                    'ip' => (string)($row['ip'] ?? ''),
                    'description' => isset($row['description']) ? (string)$row['description'] : null,
                    'actor_type' => isset($row['actor_type']) ? (string)$row['actor_type'] : null,
                    'actor_id' => isset($row['actor_id']) ? (int)$row['actor_id'] : null,
                    'actor_username' => isset($row['actor_username']) ? (string)$row['actor_username'] : null,
                    'api_key_id' => isset($row['api_key_id']) ? (int)$row['api_key_id'] : null,
                    'properties' => $properties,
                    'timestamp' => isset($row['timestamp']) ? (string)$row['timestamp'] : null,
                ];
            }

            return [
                'ok' => true,
                'status' => 200,
                'data' => $items,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to load server activity logs.',
                'data' => [],
            ];
        }
    }
}

function pteroJsonError(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => $message,
    ]);
    exit;
}

function pteroGetCurrentServerAddress(string $serverIdentifier): string
{
    $result = pteroGetServerNetworkAllocations($serverIdentifier);

    if (empty($result['ok'])) {
        return 'Unavailable';
    }

    $items = $result['data']['data'] ?? [];
    $primary = null;

    foreach ($items as $item) {
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];

        if (!empty($attributes['is_default']) || !empty($attributes['default'])) {
            $primary = $attributes;
            break;
        }
    }

    if ($primary === null && !empty($items[0]['attributes']) && is_array($items[0]['attributes'])) {
        $primary = $items[0]['attributes'];
    }

    if (!is_array($primary)) {
        return 'Unavailable';
    }

    $host = trim((string)($primary['alias'] ?? $primary['ip_alias'] ?? $primary['ip'] ?? ''));
    $port = trim((string)($primary['port'] ?? ''));

    return ($host !== '' && $port !== '') ? ($host . ':' . $port) : 'Unavailable';
}
