<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../api/pterodactyl.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$currentAdminPage = 'admin-nodes';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_nodes_message'] ?? '');
$messageType = (string)($_SESSION['admin_nodes_message_type'] ?? 'success');
unset($_SESSION['admin_nodes_message'], $_SESSION['admin_nodes_message_type']);

function fbgAdminNodesRedirect(string $message, string $type = 'success', ?int $editNodeId = null, bool $openCreate = false, ?string $tab = null): void
{
    $_SESSION['admin_nodes_message'] = $message;
    $_SESSION['admin_nodes_message_type'] = $type;

    $url = '/page.php?name=admin-nodes';
    if ($editNodeId !== null && $editNodeId > 0) {
        $url .= '&edit=' . $editNodeId;
        if ($tab !== null && $tab !== '') {
            $url .= '&tab=' . rawurlencode($tab);
        }
    } elseif ($openCreate) {
        $url .= '&create=1';
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminNodesVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminNodesRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminNodesSafeDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function fbgAdminNodesFormatMiB(mixed $value): string
{
    return number_format(max(0, (int)$value)) . ' MiB';
}

function fbgAdminNodesFormatCapacity(mixed $value): string
{
    $mib = max(0, (int)$value);
    if ($mib >= 1024) {
        $gib = $mib / 1024;
        $formatted = abs($gib - round($gib)) < 0.01
            ? number_format((int)round($gib))
            : number_format($gib, 1);

        return $formatted . ' GiB';
    }

    return number_format($mib) . ' MiB';
}

function fbgAdminNodesAllocationLimit(mixed $physicalCapacity, mixed $overallocate): ?int
{
    $physicalCapacity = max(0, (int)$physicalCapacity);
    $overallocate = (int)$overallocate;

    if ($overallocate === -1) {
        return null;
    }

    if ($overallocate <= 0) {
        return $physicalCapacity;
    }

    return (int)round($physicalCapacity * ($overallocate / 100));
}

function fbgAdminNodesFormatPercent(float $value): string
{
    return number_format($value, 1) . '%';
}

function fbgAdminNodesEncryptionKey(): ?string
{
    $rawKey = '';
    if (defined('PTERO_APP_KEY')) {
        $rawKey = (string)PTERO_APP_KEY;
    } elseif (defined('PTERODACTYL_APP_KEY')) {
        $rawKey = (string)PTERODACTYL_APP_KEY;
    }

    $rawKey = trim($rawKey);
    if ($rawKey === '') {
        return null;
    }

    if (str_starts_with($rawKey, 'base64:')) {
        $decoded = base64_decode(substr($rawKey, 7), true);
        return $decoded !== false && strlen($decoded) === 32 ? $decoded : null;
    }

    return strlen($rawKey) === 32 ? $rawKey : null;
}

function fbgAdminNodesDecryptValue(mixed $encryptedValue): ?string
{
    $key = fbgAdminNodesEncryptionKey();
    if ($key === null) {
        return null;
    }

    $payload = json_decode(base64_decode((string)$encryptedValue, true) ?: '', true);
    if (!is_array($payload) || empty($payload['iv']) || empty($payload['value']) || empty($payload['mac'])) {
        return null;
    }

    $iv = base64_decode((string)$payload['iv'], true);
    $value = base64_decode((string)$payload['value'], true);
    if ($iv === false || $value === false) {
        return null;
    }

    $expectedMac = hash_hmac('sha256', (string)$payload['iv'] . (string)$payload['value'], $key);
    if (!hash_equals($expectedMac, (string)$payload['mac'])) {
        return null;
    }

    $decrypted = openssl_decrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return null;
    }

    $unserialized = @unserialize($decrypted, ['allowed_classes' => false]);
    return is_string($unserialized) ? $unserialized : $decrypted;
}

function fbgAdminNodesFetchSystemInformation(array $node): array
{
    $daemonToken = fbgAdminNodesDecryptValue($node['daemon_token'] ?? '');
    if ($daemonToken === null || $daemonToken === '') {
        return [
            'ok' => false,
            'error' => 'Unable to decrypt this node daemon token. Confirm PTERO_APP_KEY matches the Pterodactyl APP_KEY value.',
            'data' => null,
        ];
    }

    $scheme = in_array((string)($node['scheme'] ?? 'https'), ['http', 'https'], true) ? (string)$node['scheme'] : 'https';
    $fqdn = trim((string)($node['fqdn'] ?? ''));
    $port = (int)($node['daemonListen'] ?? 0);
    if ($fqdn === '' || $port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'error' => 'Node connection details are incomplete.',
            'data' => null,
        ];
    }

    $ch = curl_init($scheme . '://' . $fqdn . ':' . $port . '/api/system');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $daemonToken,
        ],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'ok' => false,
            'error' => 'Unable to connect to Wings: ' . $curlError,
            'data' => null,
        ];
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'Wings returned an invalid system information response.',
            'data' => null,
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'error' => $decoded['error'] ?? $decoded['errors'][0]['detail'] ?? 'Wings rejected the system information request.',
            'data' => $decoded,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'data' => $decoded,
    ];
}

function fbgAdminNodesFormatOverallocate(mixed $value): string
{
    $value = (int)$value;

    if ($value === -1) {
        return 'Unlimited';
    }

    if ($value === 0) {
        return 'No overallocation';
    }

    return $value . '%';
}

function fbgAdminNodesBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-nodes';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

function fbgAdminNodesTabUrl(int $nodeId, string $tab): string
{
    return fbgAdminNodesBaseQuery([
        'edit' => $nodeId,
        'tab' => $tab,
        'create' => null,
    ]);
}

function fbgAdminNodesSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
    $query = $_GET;
    $query['name'] = 'admin-nodes';
    $query['sort'] = $targetSort;
    $query['dir'] = $direction;
    $query['page_num'] = 1;
    unset($query['edit'], $query['create']);

    return './page.php?' . http_build_query($query);
}

function fbgAdminNodesNormalizeInput(): array
{
    return [
        'name' => trim((string)($_POST['node_name'] ?? '')),
        'description' => trim((string)($_POST['node_description'] ?? '')),
        'location_id' => (int)($_POST['location_id'] ?? 0),
        'fqdn' => trim((string)($_POST['fqdn'] ?? '')),
        'scheme' => (string)($_POST['scheme'] ?? 'https'),
        'public' => isset($_POST['public']) ? 1 : 0,
        'behind_proxy' => isset($_POST['behind_proxy']) ? 1 : 0,
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0,
        'memory' => (int)($_POST['memory'] ?? 0),
        'memory_overallocate' => (int)($_POST['memory_overallocate'] ?? 0),
        'disk' => (int)($_POST['disk'] ?? 0),
        'disk_overallocate' => (int)($_POST['disk_overallocate'] ?? 0),
        'upload_size' => (int)($_POST['upload_size'] ?? 100),
        'daemon_base' => trim((string)($_POST['daemon_base'] ?? '/var/lib/pterodactyl/volumes')),
        'daemon_listen' => (int)($_POST['daemon_listen'] ?? 8080),
        'daemon_sftp' => (int)($_POST['daemon_sftp'] ?? 2022),
    ];
}

function fbgAdminNodesValidateInput(array $input, array $locationsById): ?string
{
    if ($input['name'] === '' || strlen((string)$input['name']) > 100 || !preg_match('/^[\w .-]+$/', (string)$input['name'])) {
        return 'Node name is required and may only contain letters, numbers, spaces, dots, hyphens, and underscores.';
    }

    if ((int)$input['location_id'] <= 0 || !isset($locationsById[(int)$input['location_id']])) {
        return 'Select a valid location.';
    }

    if ($input['fqdn'] === '' || strlen((string)$input['fqdn']) > 255) {
        return 'FQDN is required.';
    }

    if (!in_array((string)$input['scheme'], ['http', 'https'], true)) {
        return 'Scheme must be HTTP or HTTPS.';
    }

    if ((int)$input['memory'] < 1) {
        return 'Memory must be at least 1 MiB.';
    }

    if ((int)$input['memory_overallocate'] < -1) {
        return 'Memory overallocation must be -1 or higher.';
    }

    if ((int)$input['disk'] < 1) {
        return 'Disk must be at least 1 MiB.';
    }

    if ((int)$input['disk_overallocate'] < -1) {
        return 'Disk overallocation must be -1 or higher.';
    }

    if ((int)$input['upload_size'] < 1 || (int)$input['upload_size'] > 1024) {
        return 'Upload size must be between 1 and 1024 MiB.';
    }

    if (!preg_match('/^\/[\d\w.\/-]+$/', (string)$input['daemon_base'])) {
        return 'Daemon base path must be an absolute Linux path.';
    }

    if ((int)$input['daemon_listen'] < 1 || (int)$input['daemon_listen'] > 65535) {
        return 'Daemon listen port must be between 1 and 65535.';
    }

    if ((int)$input['daemon_sftp'] < 1 || (int)$input['daemon_sftp'] > 65535) {
        return 'Daemon SFTP port must be between 1 and 65535.';
    }

    return null;
}

function fbgAdminNodesApiPayload(array $input): array
{
    return [
        'name' => $input['name'],
        'location_id' => (int)$input['location_id'],
        'fqdn' => $input['fqdn'],
        'scheme' => $input['scheme'],
        'public' => (bool)$input['public'],
        'behind_proxy' => (bool)$input['behind_proxy'],
        'maintenance_mode' => (bool)$input['maintenance_mode'],
        'memory' => (int)$input['memory'],
        'memory_overallocate' => (int)$input['memory_overallocate'],
        'disk' => (int)$input['disk'],
        'disk_overallocate' => (int)$input['disk_overallocate'],
        'upload_size' => (int)$input['upload_size'],
        'daemon_base' => $input['daemon_base'],
        'daemon_listen' => (int)$input['daemon_listen'],
        'daemon_sftp' => (int)$input['daemon_sftp'],
    ];
}

function fbgAdminNodesApiError(array $result): string
{
    $errors = $result['data']['errors'] ?? null;
    if (is_array($errors) && $errors !== []) {
        $messages = [];
        foreach ($errors as $error) {
            $detail = trim((string)($error['detail'] ?? ''));
            if ($detail !== '') {
                $messages[] = $detail;
            }
        }

        if ($messages !== []) {
            return implode(' ', $messages);
        }
    }

    return trim((string)($result['error'] ?? '')) ?: 'Pterodactyl could not complete the node request.';
}

function fbgAdminNodesUpdateDescription(int $nodeId, string $description): void
{
    $stmt = fbgPteroDb()->prepare('UPDATE nodes SET description = :description, updated_at = NOW() WHERE id = :id LIMIT 1');
    $stmt->execute([
        'id' => $nodeId,
        'description' => $description === '' ? null : $description,
    ]);
}

function fbgAdminNodesFindCreatedNodeId(array $result, array $input): int
{
    $nodeId = (int)($result['data']['attributes']['id'] ?? 0);
    if ($nodeId > 0) {
        return $nodeId;
    }

    $stmt = fbgPteroDb()->prepare('
        SELECT id
        FROM nodes
        WHERE fqdn = :fqdn
            AND name = :name
            AND location_id = :location_id
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([
        'fqdn' => $input['fqdn'],
        'name' => $input['name'],
        'location_id' => (int)$input['location_id'],
    ]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function fbgAdminNodesParsePorts(string $rawPorts): array
{
    $parts = preg_split('/[\s,]+/', trim($rawPorts)) ?: [];
    $ports = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        if (!preg_match('/^\d{4,5}(?:-\d{4,5})?$/', $part)) {
            throw new InvalidArgumentException('Ports must be individual ports or ranges like 25565 or 25565-25575.');
        }

        if (str_contains($part, '-')) {
            [$start, $end] = array_map('intval', explode('-', $part, 2));
            if ($start > $end || $start <= 1024 || $end > 65535 || ($end - $start + 1) > 1000) {
                throw new InvalidArgumentException('Port ranges must be higher than 1024, no higher than 65535, and contain 1000 ports or fewer.');
            }
        } else {
            $port = (int)$part;
            if ($port <= 1024 || $port > 65535) {
                throw new InvalidArgumentException('Ports must be higher than 1024 and no higher than 65535.');
            }
        }

        $ports[] = $part;
    }

    if ($ports === []) {
        throw new InvalidArgumentException('Enter at least one port or port range.');
    }

    return array_values(array_unique($ports));
}

function fbgAdminNodesYamlScalar(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if ($value === null) {
        return 'null';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    $value = (string)$value;
    if ($value === '') {
        return "''";
    }

    if (preg_match('/^[A-Za-z0-9_\.\/:-]+$/', $value)) {
        return $value;
    }

    return "'" . str_replace("'", "''", $value) . "'";
}

function fbgAdminNodesYaml(array $data, int $indent = 0): string
{
    $lines = [];
    $prefix = str_repeat(' ', $indent);

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if ($value === []) {
                $lines[] = $prefix . $key . ': []';
                continue;
            }

            $lines[] = $prefix . $key . ':';
            foreach ($value as $childKey => $childValue) {
                if (is_int($childKey)) {
                    if (is_array($childValue)) {
                        $lines[] = $prefix . '  -';
                        $nested = fbgAdminNodesYaml($childValue, $indent + 4);
                        if ($nested !== '') {
                            $lines[] = $nested;
                        }
                    } else {
                        $lines[] = $prefix . '  - ' . fbgAdminNodesYamlScalar($childValue);
                    }
                    continue;
                }

                $nested = fbgAdminNodesYaml([$childKey => $childValue], $indent + 2);
                if ($nested !== '') {
                    $lines[] = $nested;
                }
            }
            continue;
        }

        $lines[] = $prefix . $key . ': ' . fbgAdminNodesYamlScalar($value);
    }

    return implode("\n", $lines);
}

$locations = [];
$locationsById = [];
$locationsStmt = fbgPteroDb()->query('SELECT id, short, `long` FROM locations ORDER BY short ASC');
foreach (($locationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $location) {
    $location['id'] = (int)$location['id'];
    $locations[] = $location;
    $locationsById[(int)$location['id']] = $location;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminNodesVerifyCsrf();

    $action = (string)($_POST['action'] ?? '');
    $nodeId = max(0, (int)($_POST['node_id'] ?? 0));

    if ($action === 'create_node') {
        $input = fbgAdminNodesNormalizeInput();
        $error = fbgAdminNodesValidateInput($input, $locationsById);
        if ($error !== null) {
            fbgAdminNodesRedirect($error, 'error', null, true);
        }

        $result = pteroRequest('POST', 'nodes', fbgAdminNodesApiPayload($input));
        if (!$result['ok']) {
            fbgAdminNodesRedirect(fbgAdminNodesApiError($result), 'error', null, true);
        }

        $createdNodeId = fbgAdminNodesFindCreatedNodeId($result, $input);
        if ($createdNodeId > 0) {
            fbgAdminNodesUpdateDescription($createdNodeId, (string)$input['description']);
        }

        fbgAdminNodesRedirect('Node created successfully.');
    }

    if ($action === 'update_node') {
        if ($nodeId <= 0) {
            fbgAdminNodesRedirect('Select a valid node.', 'error');
        }

        $input = fbgAdminNodesNormalizeInput();
        $error = fbgAdminNodesValidateInput($input, $locationsById);
        if ($error !== null) {
            fbgAdminNodesRedirect($error, 'error', $nodeId, false, 'settings');
        }

        $result = pteroRequest('PATCH', 'nodes/' . $nodeId, fbgAdminNodesApiPayload($input));
        if (!$result['ok']) {
            fbgAdminNodesRedirect(fbgAdminNodesApiError($result), 'error', $nodeId, false, 'settings');
        }

        fbgAdminNodesUpdateDescription($nodeId, (string)$input['description']);
        fbgAdminNodesRedirect('Node updated successfully.', 'success', $nodeId, false, 'settings');
    }

    if ($action === 'create_allocations') {
        if ($nodeId <= 0) {
            fbgAdminNodesRedirect('Select a valid node.', 'error');
        }

        $allocationIp = trim((string)($_POST['allocation_ip'] ?? ''));
        $allocationAlias = trim((string)($_POST['allocation_alias'] ?? ''));
        $allocationPortsRaw = (string)($_POST['allocation_ports'] ?? '');

        if ($allocationIp === '') {
            fbgAdminNodesRedirect('Enter an IP address for the new allocations.', 'error', $nodeId, false, 'allocations');
        }

        if (strlen($allocationAlias) > 191) {
            fbgAdminNodesRedirect('IP alias must be 191 characters or fewer.', 'error', $nodeId, false, 'allocations');
        }

        try {
            $allocationPorts = fbgAdminNodesParsePorts($allocationPortsRaw);
        } catch (InvalidArgumentException $e) {
            fbgAdminNodesRedirect($e->getMessage(), 'error', $nodeId, false, 'allocations');
        }

        $result = pteroRequest('POST', 'nodes/' . $nodeId . '/allocations', [
            'ip' => $allocationIp,
            'alias' => $allocationAlias === '' ? null : $allocationAlias,
            'ports' => $allocationPorts,
        ]);

        if (!$result['ok']) {
            fbgAdminNodesRedirect(fbgAdminNodesApiError($result), 'error', $nodeId, false, 'allocations');
        }

        fbgAdminNodesRedirect('Allocations created successfully.', 'success', $nodeId, false, 'allocations');
    }

    if ($action === 'update_allocation_alias') {
        if ($nodeId <= 0) {
            fbgAdminNodesRedirect('Select a valid node.', 'error');
        }

        $allocationId = max(0, (int)($_POST['allocation_id'] ?? 0));
        $allocationAlias = trim((string)($_POST['allocation_alias'] ?? ''));

        if ($allocationId <= 0) {
            fbgAdminNodesRedirect('Select a valid allocation.', 'error', $nodeId, false, 'allocations');
        }

        if (strlen($allocationAlias) > 191) {
            fbgAdminNodesRedirect('IP alias must be 191 characters or fewer.', 'error', $nodeId, false, 'allocations');
        }

        $stmt = fbgPteroDb()->prepare('
            UPDATE allocations
            SET ip_alias = :ip_alias
            WHERE id = :id
                AND node_id = :node_id
                AND server_id IS NULL
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $allocationId,
            'node_id' => $nodeId,
            'ip_alias' => $allocationAlias === '' ? null : $allocationAlias,
        ]);

        if ($stmt->rowCount() <= 0) {
            fbgAdminNodesRedirect('Only free allocations can be modified.', 'error', $nodeId, false, 'allocations');
        }

        fbgAdminNodesRedirect('Allocation alias updated.', 'success', $nodeId, false, 'allocations');
    }

    if ($action === 'delete_allocations') {
        if ($nodeId <= 0) {
            fbgAdminNodesRedirect('Select a valid node.', 'error');
        }

        $allocationIds = array_values(array_unique(array_map('intval', (array)($_POST['allocation_ids'] ?? []))));
        $allocationIds = array_values(array_filter($allocationIds, static fn (int $id): bool => $id > 0));

        if ($allocationIds === []) {
            fbgAdminNodesRedirect('Select at least one free allocation to delete.', 'error', $nodeId, false, 'allocations');
        }

        $placeholders = implode(',', array_fill(0, count($allocationIds), '?'));
        $stmt = fbgPteroDb()->prepare("
            SELECT id
            FROM allocations
            WHERE node_id = ?
                AND server_id IS NULL
                AND id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$nodeId], $allocationIds));
        $freeAllocationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (count($freeAllocationIds) !== count($allocationIds)) {
            fbgAdminNodesRedirect('Only free allocations can be deleted.', 'error', $nodeId, false, 'allocations');
        }

        foreach ($freeAllocationIds as $allocationId) {
            $result = pteroRequest('DELETE', 'nodes/' . $nodeId . '/allocations/' . $allocationId);
            if (!$result['ok']) {
                fbgAdminNodesRedirect(fbgAdminNodesApiError($result), 'error', $nodeId, false, 'allocations');
            }
        }

        fbgAdminNodesRedirect('Selected allocations deleted.', 'success', $nodeId, false, 'allocations');
    }

    if ($action === 'delete_node') {
        if ($nodeId <= 0) {
            fbgAdminNodesRedirect('Select a valid node.', 'error');
        }

        if ((string)($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            fbgAdminNodesRedirect('Type DELETE to confirm node deletion.', 'error', $nodeId);
        }

        $countStmt = fbgPteroDb()->prepare('
            SELECT
                (SELECT COUNT(*) FROM servers WHERE node_id = :server_node_id) AS server_count,
                (SELECT COUNT(*) FROM allocations WHERE node_id = :allocation_node_id) AS allocation_count
        ');
        $countStmt->execute([
            'server_node_id' => $nodeId,
            'allocation_node_id' => $nodeId,
        ]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int)($counts['server_count'] ?? 0) > 0) {
            fbgAdminNodesRedirect('Node cannot be deleted while servers are assigned to it.', 'error', $nodeId);
        }

        if ((int)($counts['allocation_count'] ?? 0) > 0) {
            fbgAdminNodesRedirect('Node cannot be deleted while allocations are assigned to it.', 'error', $nodeId);
        }

        $result = pteroRequest('DELETE', 'nodes/' . $nodeId);
        if (!$result['ok']) {
            fbgAdminNodesRedirect(fbgAdminNodesApiError($result), 'error', $nodeId);
        }

        fbgAdminNodesRedirect('Node deleted successfully.');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$locationFilter = max(0, (int)($_GET['location_id'] ?? 0));
$sort = (string)($_GET['sort'] ?? 'name');
$direction = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$perPage = 25;
$pageNum = fbgPaginationRequestedPage();
$editNodeId = max(0, (int)($_GET['edit'] ?? 0));
$openCreate = isset($_GET['create']) && (string)$_GET['create'] === '1';
$nodeTabs = ['about', 'settings', 'configuration', 'allocations', 'servers', 'system'];
$activeNodeTab = strtolower((string)($_GET['tab'] ?? 'about'));
if (!in_array($activeNodeTab, $nodeTabs, true)) {
    $activeNodeTab = 'about';
}
if ($openCreate) {
    $activeNodeTab = 'settings';
}

$sortMap = [
    'id' => 'n.id',
    'name' => 'n.name',
    'location' => 'l.short',
    'fqdn' => 'n.fqdn',
    'memory' => 'n.memory',
    'disk' => 'n.disk',
    'allocations' => 'allocation_count',
    'servers' => 'server_count',
];
if (!isset($sortMap[$sort])) {
    $sort = 'name';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(n.name LIKE :search_name OR n.fqdn LIKE :search_fqdn OR n.description LIKE :search_description OR CAST(n.id AS CHAR) = :search_exact)';
    $searchLike = '%' . $search . '%';
    $params['search_name'] = $searchLike;
    $params['search_fqdn'] = $searchLike;
    $params['search_description'] = $searchLike;
    $params['search_exact'] = $search;
}

if ($locationFilter > 0) {
    $where[] = 'n.location_id = :location_id';
    $params['location_id'] = $locationFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM nodes n LEFT JOIN locations l ON l.id = n.location_id {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$offset = $pagination['offset'];
$orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);

$nodesStmt = fbgPteroDb()->prepare("
    SELECT
        n.id,
        n.uuid,
        n.public,
        n.name,
        n.description,
        n.location_id,
        l.short AS location_short,
        l.long AS location_long,
        n.fqdn,
        n.scheme,
        n.behind_proxy,
        n.maintenance_mode,
        n.memory,
        n.memory_overallocate,
        n.disk,
        n.disk_overallocate,
        n.upload_size,
        n.daemonListen,
        n.daemonSFTP,
        n.daemonBase,
        COUNT(DISTINCT a.id) AS allocation_count,
        COUNT(DISTINCT CASE WHEN a.server_id IS NULL THEN a.id END) AS free_allocation_count,
        COUNT(DISTINCT s.id) AS server_count
    FROM nodes n
    LEFT JOIN locations l ON l.id = n.location_id
    LEFT JOIN allocations a ON a.node_id = n.id
    LEFT JOIN servers s ON s.node_id = n.id
    {$whereSql}
    GROUP BY n.id, n.uuid, n.public, n.name, n.description, n.location_id, l.short, l.long, n.fqdn, n.scheme, n.behind_proxy, n.maintenance_mode, n.memory, n.memory_overallocate, n.disk, n.disk_overallocate, n.upload_size, n.daemonListen, n.daemonSFTP, n.daemonBase
    ORDER BY {$orderSql}, n.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$nodesStmt->execute($params);
$nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editingNode = null;
$editingServers = [];
$editingAllocations = [];
$editingConfiguration = null;
$editingConfigurationError = '';
$editingSystemInformation = null;
$editingSystemInformationError = '';
if ($editNodeId > 0) {
    $nodeStmt = fbgPteroDb()->prepare('
        SELECT
            n.*,
            l.short AS location_short,
            l.long AS location_long,
            COUNT(DISTINCT a.id) AS allocation_count,
            COUNT(DISTINCT CASE WHEN a.server_id IS NULL THEN a.id END) AS free_allocation_count,
            COUNT(DISTINCT s.id) AS server_count
        FROM nodes n
        LEFT JOIN locations l ON l.id = n.location_id
        LEFT JOIN allocations a ON a.node_id = n.id
        LEFT JOIN servers s ON s.node_id = n.id
        WHERE n.id = :id
        GROUP BY n.id
        LIMIT 1
    ');
    $nodeStmt->execute(['id' => $editNodeId]);
    $editingNode = $nodeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingNode) {
        $serversStmt = fbgPteroDb()->prepare('
            SELECT
                s.id,
                s.uuidShort,
                s.name,
                s.owner_id,
                u.username,
                e.name AS egg_name,
                s.memory,
                s.disk,
                a.ip,
                a.port
            FROM servers s
            LEFT JOIN users u ON u.id = s.owner_id
            LEFT JOIN eggs e ON e.id = s.egg_id
            LEFT JOIN allocations a ON a.id = s.allocation_id
            WHERE s.node_id = :node_id
            ORDER BY s.name ASC
        ');
        $serversStmt->execute(['node_id' => $editNodeId]);
        $editingServers = $serversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allocationsStmt = fbgPteroDb()->prepare('
            SELECT
                a.id,
                a.ip,
                a.ip_alias,
                a.port,
                a.server_id,
                a.notes,
                s.name AS server_name
            FROM allocations a
            LEFT JOIN servers s ON s.id = a.server_id
            WHERE a.node_id = :node_id
            ORDER BY INET_ATON(a.ip) ASC, a.port ASC
        ');
        $allocationsStmt->execute(['node_id' => $editNodeId]);
        $editingAllocations = $allocationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($activeNodeTab === 'configuration') {
            $configResult = pteroRequest('GET', 'nodes/' . $editNodeId . '/configuration');
            if ($configResult['ok'] && is_array($configResult['data'])) {
                $editingConfiguration = $configResult['data'];
            } else {
                $editingConfigurationError = fbgAdminNodesApiError($configResult);
            }
        }

        if ($activeNodeTab === 'system') {
            $systemResult = fbgAdminNodesFetchSystemInformation($editingNode);
            if ($systemResult['ok'] && is_array($systemResult['data'])) {
                $editingSystemInformation = $systemResult['data'];
            } else {
                $editingSystemInformationError = (string)($systemResult['error'] ?? 'Unable to load node system information.');
            }
        }
    }
}
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Nodes</h1>
                <p class="fbg-admin-subtext">Manage Pterodactyl nodes, capacity settings, and daemon connection details.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Nodes',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header fbg-admin-node-list-header">
                    <h2>Node List</h2>
                    <a class="btn" href="<?= htmlspecialchars(fbgAdminNodesBaseQuery(['create' => 1, 'edit' => null]), ENT_QUOTES, 'UTF-8') ?>">Create Node</a>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-nodes">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="node-search">Search</label>
                            <input id="node-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, name, FQDN, or description">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="node-location">Location</label>
                            <select id="node-location" name="location_id">
                                <option value="0">All Locations</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?= (int)$location['id'] ?>" <?= $locationFilter === (int)$location['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$location['short'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="node-sort">Sort</label>
                            <select id="node-sort" name="sort">
                                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID</option>
                                <option value="location" <?= $sort === 'location' ? 'selected' : '' ?>>Location</option>
                                <option value="fqdn" <?= $sort === 'fqdn' ? 'selected' : '' ?>>FQDN</option>
                                <option value="memory" <?= $sort === 'memory' ? 'selected' : '' ?>>Memory</option>
                                <option value="disk" <?= $sort === 'disk' ? 'selected' : '' ?>>Disk</option>
                                <option value="allocations" <?= $sort === 'allocations' ? 'selected' : '' ?>>Allocations</option>
                                <option value="servers" <?= $sort === 'servers' ? 'selected' : '' ?>>Servers</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="node-dir">Direction</label>
                            <select id="node-dir" name="dir">
                                <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
                                <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                            </select>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table fbg-admin-nodes-table">
                        <thead>
                            <tr>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('id', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">ID</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('name', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Name</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('location', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Location</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('fqdn', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">FQDN</a></th>
                                <th>Scheme</th>
                                <th>Status</th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('memory', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Memory</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('disk', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Disk</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('allocations', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Allocations</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminNodesSortUrl('servers', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Servers</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nodes)): ?>
                                <tr>
                                    <td colspan="10">No nodes found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($nodes as $node): ?>
                                <tr>
                                    <td><?= (int)$node['id'] ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminNodesBaseQuery(['edit' => (int)$node['id'], 'create' => null]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars((string)($node['location_short'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars((string)$node['fqdn'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= htmlspecialchars(strtoupper((string)$node['scheme']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ((int)$node['maintenance_mode'] === 1): ?>
                                            <span class="fbg-admin-status-pill is-installing">Maintenance</span>
                                        <?php elseif ((int)$node['public'] === 1): ?>
                                            <span class="fbg-admin-status-pill is-active">Public</span>
                                        <?php else: ?>
                                            <span class="fbg-admin-status-pill">Private</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= fbgAdminNodesFormatMiB($node['memory']) ?></td>
                                    <td><?= fbgAdminNodesFormatMiB($node['disk']) ?></td>
                                    <td><?= (int)$node['allocation_count'] ?> total, <?= (int)$node['free_allocation_count'] ?> free</td>
                                    <td><?= (int)$node['server_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'node', ['remove' => ['edit', 'create']]); ?>
            </section>

            <?php if ($editNodeId > 0 && !$editingNode): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-empty-state">
                        <p>Node could not be found.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
$blankNode = [
    'id' => 0,
    'name' => '',
    'description' => '',
    'location_id' => $locations[0]['id'] ?? 0,
    'fqdn' => '',
    'scheme' => 'https',
    'public' => 1,
    'behind_proxy' => 0,
    'maintenance_mode' => 0,
    'memory' => 1024,
    'memory_overallocate' => 0,
    'disk' => 1024,
    'disk_overallocate' => 0,
    'upload_size' => 100,
    'daemonListen' => 8080,
    'daemonSFTP' => 2022,
    'daemonBase' => '/var/lib/pterodactyl/volumes',
    'server_count' => 0,
    'allocation_count' => 0,
    'free_allocation_count' => 0,
];
$modalNode = $editingNode ?: $blankNode;
$isEditing = (bool)$editingNode;
?>

<?php if ($openCreate || $editingNode): ?>
    <?php
    $modalTitle = $isEditing ? ('Edit ' . (string)$modalNode['name']) : 'Create Node';
    $modalDescription = $isEditing
        ? 'Update node details, capacity limits, and daemon connection settings.'
        : 'Add a new node using the same core fields Pterodactyl requires.';
    ?>
    <div class="fbg-modal-overlay" id="admin-node-modal">
        <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-node-modal" role="dialog" aria-modal="true" aria-labelledby="admin-node-modal-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-nodes" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-node-modal-title"><?= htmlspecialchars($modalTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars($modalDescription, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <?php if ($isEditing): ?>
                <?php
                $tabLabels = [
                    'about' => 'About',
                    'settings' => 'Settings',
                    'configuration' => 'Configuration',
                    'allocations' => 'Allocations',
                    'servers' => 'Servers',
                    'system' => 'System Information',
                ];
                ?>
                <nav class="fbg-admin-node-tabs" aria-label="Node sections">
                    <?php foreach ($tabLabels as $tabKey => $tabLabel): ?>
                        <a
                            class="fbg-admin-node-tab <?= $activeNodeTab === $tabKey ? 'is-active' : '' ?>"
                            href="<?= htmlspecialchars(fbgAdminNodesTabUrl((int)$modalNode['id'], $tabKey), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if (!$isEditing || $activeNodeTab === 'settings'): ?>
            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update_node' : 'create_node' ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="node_id" value="<?= (int)$modalNode['id'] ?>">
                <?php endif; ?>

                <h3>Node Details</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="node-name">Name</label>
                        <input id="node-name" name="node_name" type="text" required maxlength="100" value="<?= htmlspecialchars((string)$modalNode['name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Hooper-Node-1">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-location-id">Location</label>
                        <select id="node-location-id" name="location_id" required>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int)$location['id'] ?>" <?= (int)$modalNode['location_id'] === (int)$location['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$location['short'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="node-description">Description</label>
                        <textarea id="node-description" name="node_description" rows="3" placeholder="Short note about this node"><?= htmlspecialchars((string)($modalNode['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <h3>Connection</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="node-fqdn">FQDN</label>
                        <input id="node-fqdn" name="fqdn" type="text" required value="<?= htmlspecialchars((string)$modalNode['fqdn'], ENT_QUOTES, 'UTF-8') ?>" placeholder="node1.frostbyt3gaming.com">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-scheme">Scheme</label>
                        <select id="node-scheme" name="scheme" required>
                            <option value="https" <?= (string)$modalNode['scheme'] === 'https' ? 'selected' : '' ?>>HTTPS</option>
                            <option value="http" <?= (string)$modalNode['scheme'] === 'http' ? 'selected' : '' ?>>HTTP</option>
                        </select>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-daemon-listen">Daemon Port</label>
                        <input id="node-daemon-listen" name="daemon_listen" type="number" min="1" max="65535" required value="<?= (int)$modalNode['daemonListen'] ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-daemon-sftp">SFTP Port</label>
                        <input id="node-daemon-sftp" name="daemon_sftp" type="number" min="1" max="65535" required value="<?= (int)$modalNode['daemonSFTP'] ?>">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="node-daemon-base">Daemon Base Path</label>
                        <input id="node-daemon-base" name="daemon_base" type="text" required value="<?= htmlspecialchars((string)$modalNode['daemonBase'], ENT_QUOTES, 'UTF-8') ?>">
                        <p class="fbg-admin-help-text">This is the Wings volume path, usually /var/lib/pterodactyl/volumes.</p>
                    </div>
                </div>

                <h3>Limits</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="node-memory">Memory Limit (MiB)</label>
                        <input id="node-memory" name="memory" type="number" min="1" required value="<?= (int)$modalNode['memory'] ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-memory-overallocate">Memory Overallocation</label>
                        <input id="node-memory-overallocate" name="memory_overallocate" type="number" min="-1" required value="<?= (int)$modalNode['memory_overallocate'] ?>">
                        <p class="fbg-admin-help-text">Use -1 for unlimited, 0 for no overallocation, or a percentage.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-disk">Disk Limit (MiB)</label>
                        <input id="node-disk" name="disk" type="number" min="1" required value="<?= (int)$modalNode['disk'] ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-disk-overallocate">Disk Overallocation</label>
                        <input id="node-disk-overallocate" name="disk_overallocate" type="number" min="-1" required value="<?= (int)$modalNode['disk_overallocate'] ?>">
                        <p class="fbg-admin-help-text">Use -1 for unlimited, 0 for no overallocation, or a percentage.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="node-upload-size">Upload Size Limit (MiB)</label>
                        <input id="node-upload-size" name="upload_size" type="number" min="1" max="1024" required value="<?= (int)$modalNode['upload_size'] ?>">
                    </div>
                </div>

                <h3>Options</h3>
                <div class="fbg-admin-option-list">
                    <label class="fbg-admin-checkbox">
                        <input type="checkbox" name="public" value="1" <?= (int)$modalNode['public'] === 1 ? 'checked' : '' ?>>
                        <span>
                            Public node
                            <small>Allow this node to be used automatically when placing servers.</small>
                        </span>
                    </label>

                    <label class="fbg-admin-checkbox">
                        <input type="checkbox" name="behind_proxy" value="1" <?= (int)$modalNode['behind_proxy'] === 1 ? 'checked' : '' ?>>
                        <span>
                            Behind proxy
                            <small>Use this when the daemon connection is proxied through another web server.</small>
                        </span>
                    </label>

                    <label class="fbg-admin-checkbox">
                        <input type="checkbox" name="maintenance_mode" value="1" <?= (int)$modalNode['maintenance_mode'] === 1 ? 'checked' : '' ?>>
                        <span>
                            Maintenance mode
                            <small>Prevent normal server activity while maintenance is underway.</small>
                        </span>
                    </label>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn btn-sm"><?= $isEditing ? 'Save Node' : 'Create Node' ?></button>
                    <a class="btn btn-sm fbg-neutral-button" href="./page.php?name=admin-nodes">Cancel</a>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($isEditing): ?>
                <?php if ($activeNodeTab === 'about'): ?>
                <hr>

                <div class="fbg-admin-node-summary-grid">
                    <div class="fbg-admin-node-summary-card">
                        <span>UUID</span>
                        <strong><?= htmlspecialchars((string)($modalNode['uuid'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>Memory Overallocation</span>
                        <strong><?= htmlspecialchars(fbgAdminNodesFormatOverallocate($modalNode['memory_overallocate']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>Disk Overallocation</span>
                        <strong><?= htmlspecialchars(fbgAdminNodesFormatOverallocate($modalNode['disk_overallocate']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="fbg-admin-node-summary-card">
                        <span>Updated</span>
                        <strong><?= htmlspecialchars(fbgAdminNodesSafeDate($modalNode['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>

                <?php
                $serverCount = (int)($modalNode['server_count'] ?? 0);
                $allocationCount = (int)($modalNode['allocation_count'] ?? 0);
                $deleteDisabled = $serverCount > 0 || $allocationCount > 0;
                $totalMemoryAllocated = 0;
                $totalDiskAllocated = 0;
                foreach ($editingServers as $server) {
                    $totalMemoryAllocated += max(0, (int)($server['memory'] ?? 0));
                    $totalDiskAllocated += max(0, (int)($server['disk'] ?? 0));
                }

                $nodeMemoryTotal = max(0, (int)($modalNode['memory'] ?? 0));
                $nodeDiskTotal = max(0, (int)($modalNode['disk'] ?? 0));
                $nodeMemoryLimit = fbgAdminNodesAllocationLimit($nodeMemoryTotal, $modalNode['memory_overallocate'] ?? 0);
                $nodeDiskLimit = fbgAdminNodesAllocationLimit($nodeDiskTotal, $modalNode['disk_overallocate'] ?? 0);
                $memoryAllocationPercent = $nodeMemoryLimit !== null && $nodeMemoryLimit > 0 ? min(100, ($totalMemoryAllocated / $nodeMemoryLimit) * 100) : 0;
                $diskAllocationPercent = $nodeDiskLimit !== null && $nodeDiskLimit > 0 ? min(100, ($totalDiskAllocated / $nodeDiskLimit) * 100) : 0;
                $memoryPhysicalPercent = $nodeMemoryLimit !== null && $nodeMemoryLimit > 0 ? min(100, ($nodeMemoryTotal / $nodeMemoryLimit) * 100) : 0;
                $diskPhysicalPercent = $nodeDiskLimit !== null && $nodeDiskLimit > 0 ? min(100, ($nodeDiskTotal / $nodeDiskLimit) * 100) : 0;
                $memoryOverageStart = min($memoryPhysicalPercent, $memoryAllocationPercent);
                $diskOverageStart = min($diskPhysicalPercent, $diskAllocationPercent);
                $memoryOveragePercent = max(0, $memoryAllocationPercent - $memoryPhysicalPercent);
                $diskOveragePercent = max(0, $diskAllocationPercent - $diskPhysicalPercent);
                ?>

                <div class="fbg-admin-node-allocation-summary-grid">
                    <article class="fbg-admin-node-allocation-card is-memory">
                        <div class="fbg-admin-node-allocation-card-header">
                            <span>Total Memory Allocated</span>
                            <strong><?= htmlspecialchars(fbgAdminNodesFormatCapacity($totalMemoryAllocated), ENT_QUOTES, 'UTF-8') ?> allocated</strong>
                            <small>
                                <?= htmlspecialchars(fbgAdminNodesFormatCapacity($nodeMemoryTotal), ENT_QUOTES, 'UTF-8') ?> physical &middot;
                                <?= $nodeMemoryLimit === null ? 'Unlimited' : htmlspecialchars(fbgAdminNodesFormatCapacity($nodeMemoryLimit), ENT_QUOTES, 'UTF-8') ?> allocation limit
                            </small>
                        </div>
                        <div class="fbg-admin-node-allocation-meter" aria-hidden="true">
                            <span class="fbg-admin-node-allocation-meter-fill" style="width: <?= htmlspecialchars(number_format($memoryAllocationPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"></span>
                            <?php if ($memoryOveragePercent > 0): ?>
                                <span class="fbg-admin-node-allocation-meter-overage" style="left: <?= htmlspecialchars(number_format($memoryOverageStart, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%; width: <?= htmlspecialchars(number_format($memoryOveragePercent, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"></span>
                            <?php endif; ?>
                        </div>
                        <p class="fbg-admin-node-allocation-percent">
                            <?= $nodeMemoryLimit === null ? 'Allocation limit is unlimited' : htmlspecialchars(fbgAdminNodesFormatPercent($memoryAllocationPercent), ENT_QUOTES, 'UTF-8') . ' of allocation limit' ?>
                        </p>
                    </article>

                    <article class="fbg-admin-node-allocation-card is-disk">
                        <div class="fbg-admin-node-allocation-card-header">
                            <span>Total Disk Allocated</span>
                            <strong><?= htmlspecialchars(fbgAdminNodesFormatCapacity($totalDiskAllocated), ENT_QUOTES, 'UTF-8') ?> allocated</strong>
                            <small>
                                <?= htmlspecialchars(fbgAdminNodesFormatCapacity($nodeDiskTotal), ENT_QUOTES, 'UTF-8') ?> physical &middot;
                                <?= $nodeDiskLimit === null ? 'Unlimited' : htmlspecialchars(fbgAdminNodesFormatCapacity($nodeDiskLimit), ENT_QUOTES, 'UTF-8') ?> allocation limit
                            </small>
                        </div>
                        <div class="fbg-admin-node-allocation-meter" aria-hidden="true">
                            <span class="fbg-admin-node-allocation-meter-fill" style="width: <?= htmlspecialchars(number_format($diskAllocationPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"></span>
                            <?php if ($diskOveragePercent > 0): ?>
                                <span class="fbg-admin-node-allocation-meter-overage" style="left: <?= htmlspecialchars(number_format($diskOverageStart, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%; width: <?= htmlspecialchars(number_format($diskOveragePercent, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"></span>
                            <?php endif; ?>
                        </div>
                        <p class="fbg-admin-node-allocation-percent">
                            <?= $nodeDiskLimit === null ? 'Allocation limit is unlimited' : htmlspecialchars(fbgAdminNodesFormatPercent($diskAllocationPercent), ENT_QUOTES, 'UTF-8') . ' of allocation limit' ?>
                        </p>
                    </article>
                </div>

                <section class="fbg-admin-node-danger-panel">
                    <div>
                        <h2>Delete Node</h2>
                        <p>Deleting a node is permanent. There must be no servers or allocations associated with this node before it can be removed.</p>
                        <?php if ($deleteDisabled): ?>
                            <p class="fbg-admin-help-text">
                                Remove <?= $serverCount ?> assigned server<?= $serverCount === 1 ? '' : 's' ?> and <?= $allocationCount ?> allocation<?= $allocationCount === 1 ? '' : 's' ?> before deleting.
                            </p>
                        <?php endif; ?>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm btn-delete fbg-admin-user-delete-button"
                        id="admin-node-delete-open"
                        <?= $deleteDisabled ? 'disabled' : '' ?>
                    >
                        Delete Node
                    </button>
                </section>
                <?php endif; ?>

                <?php if ($activeNodeTab === 'configuration'): ?>
                    <div class="fbg-admin-node-tab-panel">
                        <div class="fbg-admin-node-related-grid">
                            <section class="fbg-admin-node-config-panel">
                                <div class="fbg-admin-panel-header">
                                    <h2>Configuration File</h2>
                                </div>

                                <?php if ($editingConfigurationError !== ''): ?>
                                    <div class="fbg-admin-warning-box">
                                        <?= htmlspecialchars($editingConfigurationError, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php else: ?>
                                    <pre class="fbg-admin-node-config-code"><?= htmlspecialchars($editingConfiguration !== null ? fbgAdminNodesYaml($editingConfiguration) : '', ENT_QUOTES, 'UTF-8') ?></pre>
                                <?php endif; ?>

                                <p class="fbg-admin-help-text">
                                    Save this content on the node as <code>/etc/pterodactyl/config.yml</code>, then restart Wings so the daemon uses the updated configuration.
                                </p>
                            </section>

                            <section class="fbg-admin-node-deploy-panel">
                                <div class="fbg-admin-panel-header">
                                    <h2>Deployment Notes</h2>
                                </div>

                                <div class="fbg-admin-node-info-card">
                                    <strong>Install Wings on the target machine first.</strong>
                                    <span>After Wings is installed, copy the generated configuration into place and restart the Wings service.</span>
                                </div>

                                <div class="fbg-admin-node-info-card">
                                    <strong>Check daemon connectivity.</strong>
                                    <span>The panel will connect using <?= htmlspecialchars((string)$modalNode['scheme'], ENT_QUOTES, 'UTF-8') ?>://<?= htmlspecialchars((string)$modalNode['fqdn'], ENT_QUOTES, 'UTF-8') ?>:<?= (int)$modalNode['daemonListen'] ?>.</span>
                                </div>
                            </section>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeNodeTab === 'allocations'): ?>
                    <div class="fbg-admin-node-tab-panel fbg-admin-node-allocation-layout">
                        <section>
                            <div class="fbg-admin-panel-header fbg-admin-node-allocation-header">
                                <h2>Existing Allocations</h2>
                            </div>

                            <div class="fbg-admin-node-allocation-table-actions">
                                <button type="button" class="btn btn-sm btn-delete" id="admin-node-delete-selected-allocations"><i class="fas fa-trash"></i> Delete Selected</button>
                            </div>

                            <div class="fbg-admin-table-wrap">
                                <table class="fbg-admin-table fbg-admin-node-allocations-table">
                                    <thead>
                                        <tr>
                                            <th>
                                                <input type="checkbox" id="admin-node-select-all-allocations" aria-label="Select all free allocations">
                                            </th>
                                            <th>IP Address</th>
                                            <th>IP Alias</th>
                                            <th>Port</th>
                                            <th>Assigned To</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($editingAllocations)): ?>
                                            <tr>
                                                <td colspan="6">No allocations are assigned to this node.</td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($editingAllocations as $allocation): ?>
                                            <?php $allocationIsFree = (int)($allocation['server_id'] ?? 0) <= 0; ?>
                                            <tr>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        class="fbg-admin-node-allocation-checkbox"
                                                        value="<?= (int)$allocation['id'] ?>"
                                                        <?= $allocationIsFree ? '' : 'disabled' ?>
                                                        aria-label="Select allocation <?= (int)$allocation['port'] ?>"
                                                    >
                                                </td>
                                                <td><code><?= htmlspecialchars((string)$allocation['ip'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                                <td>
                                                    <?php if ($allocationIsFree): ?>
                                                        <form method="POST" class="fbg-admin-node-inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="update_allocation_alias">
                                                            <input type="hidden" name="node_id" value="<?= (int)$modalNode['id'] ?>">
                                                            <input type="hidden" name="allocation_id" value="<?= (int)$allocation['id'] ?>">
                                                            <input name="allocation_alias" type="text" maxlength="191" value="<?= htmlspecialchars((string)($allocation['ip_alias'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Optional alias">
                                                            <button type="submit" class="btn btn-sm">Save</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars((string)($allocation['ip_alias'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= (int)$allocation['port'] ?></td>
                                                <td>
                                                    <?php if ($allocationIsFree): ?>
                                                        <span class="fbg-admin-status-pill">Free</span>
                                                    <?php else: ?>
                                                        <a class="fbg-admin-branded-link" href="./page.php?name=admin-servers&edit=<?= (int)$allocation['server_id'] ?>">
                                                            <?= htmlspecialchars((string)($allocation['server_name'] ?? 'Assigned server'), ENT_QUOTES, 'UTF-8') ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($allocationIsFree): ?>
                                                        <button type="button" class="btn btn-sm btn-delete fbg-admin-node-delete-allocation" data-allocation-id="<?= (int)$allocation['id'] ?>"><i class="fas fa-trash"></i></button>
                                                    <?php else: ?>
                                                        <span class="fbg-admin-help-text">Locked</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="fbg-admin-node-create-allocation-panel">
                            <div class="fbg-admin-panel-header">
                                <h2>Assign New Allocations</h2>
                            </div>

                            <form method="POST" class="fbg-admin-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="create_allocations">
                                <input type="hidden" name="node_id" value="<?= (int)$modalNode['id'] ?>">

                                <div class="fbg-admin-field">
                                    <label for="allocation-ip">IP Address</label>
                                    <input id="allocation-ip" name="allocation_ip" type="text" required placeholder="0.0.0.0">
                                    <p class="fbg-admin-help-text">Enter an IP address to assign ports to this node.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="allocation-alias">IP Alias</label>
                                    <input id="allocation-alias" name="allocation_alias" type="text" maxlength="191" placeholder="node1.frostbyt3gaming.com">
                                    <p class="fbg-admin-help-text">Optional hostname shown to users instead of the raw IP address.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="allocation-ports">Ports</label>
                                    <textarea id="allocation-ports" name="allocation_ports" rows="5" required placeholder="25565, 25566-25575"></textarea>
                                    <p class="fbg-admin-help-text">Enter individual ports or port ranges separated by commas, spaces, or new lines.</p>
                                </div>

                                <div class="fbg-admin-form-actions">
                                    <button type="submit" class="btn btn-sm">Create Allocations</button>
                                </div>
                            </form>
                        </section>

                        <form method="POST" id="admin-node-delete-allocations-form" hidden>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete_allocations">
                            <input type="hidden" name="node_id" value="<?= (int)$modalNode['id'] ?>">
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($activeNodeTab === 'servers'): ?>
                    <div class="fbg-admin-node-tab-panel">
                        <div class="fbg-admin-panel-header">
                            <h2>Servers</h2>
                        </div>

                        <div class="fbg-admin-table-wrap">
                            <table class="fbg-admin-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Server Name</th>
                                        <th>Owner</th>
                                        <th>Egg</th>
                                        <th>Allocation</th>
                                        <th>Memory</th>
                                        <th>Disk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($editingServers)): ?>
                                        <tr>
                                            <td colspan="7">No servers are assigned to this node.</td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($editingServers as $server): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string)$server['uuidShort'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <a class="fbg-admin-branded-link" href="./page.php?name=admin-servers&edit=<?= (int)$server['id'] ?>">
                                                    <?= htmlspecialchars((string)$server['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars((string)($server['username'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)($server['egg_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><code><?= htmlspecialchars(trim((string)($server['ip'] ?? '') . ':' . (string)($server['port'] ?? ''), ':'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><?= fbgAdminNodesFormatMiB($server['memory']) ?></td>
                                            <td><?= fbgAdminNodesFormatMiB($server['disk']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeNodeTab === 'system'): ?>
                    <?php
                    $systemData = is_array($editingSystemInformation) ? $editingSystemInformation : [];
                    $systemOs = trim((string)($systemData['os'] ?? $systemData['system']['type'] ?? ''));
                    $systemArchitecture = trim((string)($systemData['architecture'] ?? $systemData['system']['arch'] ?? ''));
                    $systemKernel = trim((string)($systemData['kernel_version'] ?? $systemData['system']['release'] ?? ''));
                    $systemCpuCount = (int)($systemData['cpu_count'] ?? $systemData['system']['cpus'] ?? 0);
                    $daemonVersion = trim((string)($systemData['version'] ?? ''));
                    $systemConnectionUrl = (string)$modalNode['scheme'] . '://' . (string)$modalNode['fqdn'] . ':' . (int)$modalNode['daemonListen'];
                    ?>

                    <div class="fbg-admin-node-tab-panel">
                        <?php if ($editingSystemInformationError !== ''): ?>
                            <div class="fbg-admin-warning-box">
                                <?= htmlspecialchars($editingSystemInformationError, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <div class="fbg-admin-node-system-grid">
                            <article class="fbg-admin-node-system-card">
                                <span>Daemon Version</span>
                                <strong><?= htmlspecialchars($daemonVersion !== '' ? $daemonVersion : '-', ENT_QUOTES, 'UTF-8') ?></strong>
                            </article>

                            <article class="fbg-admin-node-system-card">
                                <span>Operating System</span>
                                <strong><?= htmlspecialchars($systemOs !== '' ? ucwords($systemOs) : '-', ENT_QUOTES, 'UTF-8') ?></strong>
                            </article>

                            <article class="fbg-admin-node-system-card">
                                <span>Architecture</span>
                                <strong><?= htmlspecialchars($systemArchitecture !== '' ? $systemArchitecture : '-', ENT_QUOTES, 'UTF-8') ?></strong>
                            </article>

                            <article class="fbg-admin-node-system-card">
                                <span>Kernel</span>
                                <strong><?= htmlspecialchars($systemKernel !== '' ? $systemKernel : '-', ENT_QUOTES, 'UTF-8') ?></strong>
                            </article>

                            <article class="fbg-admin-node-system-card">
                                <span>Total CPU Threads</span>
                                <strong><?= $systemCpuCount > 0 ? number_format($systemCpuCount) : '-' ?></strong>
                            </article>

                            <article class="fbg-admin-node-system-card">
                                <span>Connection Address</span>
                                <strong><?= htmlspecialchars($systemConnectionUrl, ENT_QUOTES, 'UTF-8') ?></strong>
                            </article>
                        </div>

                        <section class="fbg-admin-node-system-note">
                            <h2>Live Daemon Details</h2>
                            <p>This information is requested directly from Wings when the System Information tab is opened.</p>
                        </section>
                    </div>
                <?php endif; ?>

                <div class="fbg-modal-overlay fbg-admin-user-delete-confirm-overlay" id="admin-node-delete-confirm" hidden>
                    <div class="fbg-modal-card fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-node-delete-confirm-title">
                        <div class="fbg-modal-header">
                            <h3 id="admin-node-delete-confirm-title">Delete Node</h3>
                            <p>This is a destructive action and cannot be undone.</p>
                        </div>

                        <form method="POST" id="admin-node-delete-form" class="fbg-admin-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete_node">
                            <input type="hidden" name="node_id" value="<?= (int)$modalNode['id'] ?>">

                            <div class="fbg-admin-warning-box">
                                Deleting <?= htmlspecialchars((string)$modalNode['name'], ENT_QUOTES, 'UTF-8') ?> will remove this node from Pterodactyl. Servers and allocations must be removed before this action is available.
                            </div>

                            <div class="fbg-admin-field">
                                <label for="admin-node-delete-confirm-input">Type DELETE to confirm</label>
                                <input id="admin-node-delete-confirm-input" name="delete_confirmation" type="text" autocomplete="off" spellcheck="false">
                            </div>

                            <div class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                                <button type="button" class="btn btn-sm fbg-neutral-button" id="admin-node-delete-cancel">Cancel</button>
                                <button type="submit" class="btn btn-sm btn-delete fbg-admin-user-delete-confirm-submit" id="admin-node-delete-submit" disabled>Delete Node</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('admin-node-modal');
    if (!modal) return;

    document.body.classList.add('fbg-modal-open');

    const deleteOpen = document.getElementById('admin-node-delete-open');
    const deleteConfirm = document.getElementById('admin-node-delete-confirm');
    const deleteCancel = document.getElementById('admin-node-delete-cancel');
    const deleteInput = document.getElementById('admin-node-delete-confirm-input');
    const deleteSubmit = document.getElementById('admin-node-delete-submit');

    const closeDeleteConfirm = () => {
        if (!deleteConfirm) return;
        deleteConfirm.hidden = true;
        if (deleteInput) {
            deleteInput.value = '';
        }
        if (deleteSubmit) {
            deleteSubmit.disabled = true;
        }
    };

    if (deleteOpen && deleteConfirm) {
        deleteOpen.addEventListener('click', () => {
            deleteConfirm.hidden = false;
            if (deleteInput) {
                deleteInput.focus();
            }
        });
    }

    if (deleteCancel) {
        deleteCancel.addEventListener('click', closeDeleteConfirm);
    }

    if (deleteInput && deleteSubmit) {
        deleteInput.addEventListener('input', () => {
            deleteSubmit.disabled = deleteInput.value !== 'DELETE';
        });
    }

    const allocationDeleteForm = document.getElementById('admin-node-delete-allocations-form');
    const allocationCheckboxes = Array.from(document.querySelectorAll('.fbg-admin-node-allocation-checkbox:not(:disabled)'));
    const allocationSelectAll = document.getElementById('admin-node-select-all-allocations');
    const allocationDeleteSelected = document.getElementById('admin-node-delete-selected-allocations');
    const allocationSingleDeleteButtons = Array.from(document.querySelectorAll('.fbg-admin-node-delete-allocation'));

    const confirmNodeAction = async (title, description, confirmText = 'Delete', cancelText = 'Cancel') => {
        if (typeof window.FBGConfirm !== 'function') {
            return false;
        }

        return window.FBGConfirm(title, description, confirmText, cancelText, {
            danger: true,
        });
    };

    const submitAllocationDelete = async (allocationIds) => {
        if (!allocationDeleteForm || allocationIds.length === 0) {
            return;
        }

        const confirmed = await confirmNodeAction(
            allocationIds.length === 1 ? 'Delete Allocation' : 'Delete Allocations',
            allocationIds.length === 1
                ? 'This free allocation will be removed from the node.'
                : `These ${allocationIds.length} free allocations will be removed from the node.`,
            allocationIds.length === 1 ? 'Delete Allocation' : 'Delete Allocations'
        );

        if (!confirmed) {
            return;
        }

        allocationDeleteForm.querySelectorAll('input[name="allocation_ids[]"]').forEach((input) => input.remove());
        allocationIds.forEach((allocationId) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'allocation_ids[]';
            input.value = allocationId;
            allocationDeleteForm.appendChild(input);
        });
        allocationDeleteForm.submit();
    };

    if (allocationSelectAll) {
        allocationSelectAll.addEventListener('change', () => {
            allocationCheckboxes.forEach((checkbox) => {
                checkbox.checked = allocationSelectAll.checked;
            });
        });
    }

    if (allocationDeleteSelected) {
        allocationDeleteSelected.addEventListener('click', () => {
            const selectedIds = allocationCheckboxes
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value);

            submitAllocationDelete(selectedIds);
        });
    }

    allocationSingleDeleteButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const allocationId = button.dataset.allocationId || '';
            submitAllocationDelete(allocationId ? [allocationId] : []);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (deleteConfirm && !deleteConfirm.hidden) {
                closeDeleteConfirm();
                return;
            }

            window.location.href = './page.php?name=admin-nodes';
        }
    });
});
</script>
