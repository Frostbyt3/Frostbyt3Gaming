<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../api/pterodactyl.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

$currentAdminPage = 'admin-servers';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_servers_message'] ?? '');
$messageType = (string)($_SESSION['admin_servers_message_type'] ?? 'success');
unset($_SESSION['admin_servers_message'], $_SESSION['admin_servers_message_type']);

function fbgAdminServersRedirect(string $message, string $type = 'success', ?int $editServerId = null, string $tab = 'details'): void
{
    $_SESSION['admin_servers_message'] = $message;
    $_SESSION['admin_servers_message_type'] = $type;

    $url = '/page.php?name=admin-servers';
    if ($editServerId !== null && $editServerId > 0) {
        $url .= '&edit=' . $editServerId . '&tab=' . urlencode($tab);
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminServersVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminServersRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminServersSafeDate(mixed $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function fbgAdminServersDatetimeLocalValue(mixed $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d\TH:i', $timestamp) : '';
}

function fbgAdminServersFormatExpirationForDb(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function fbgAdminServersOwnerOptionLabel(array $user): string
{
    $name = trim((string)($user['name_first'] ?? '') . ' ' . (string)($user['name_last'] ?? ''));
    $username = trim((string)($user['username'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));
    $label = $name !== '' ? $name : ($username !== '' ? $username : $email);

    if ($username !== '' && $label !== $username) {
        $label .= ' (' . $username . ')';
    }

    if ($email !== '') {
        $label .= ' - ' . $email;
    }

    return $label . ' [#' . (int)$user['id'] . ']';
}

function fbgAdminServersOwnerIdFromInput(string $value): int
{
    if (preg_match('/\[#(\d+)\]\s*$/', trim($value), $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function fbgAdminServersAllocationLabel(array $allocation): string
{
    $host = trim((string)($allocation['ip_alias'] ?? ''));
    if ($host === '') {
        $host = trim((string)($allocation['ip'] ?? ''));
    }

    $port = trim((string)($allocation['port'] ?? ''));
    $label = $host !== '' && $port !== '' ? $host . ':' . $port : 'Allocation #' . (int)($allocation['id'] ?? 0);
    $notes = trim((string)($allocation['notes'] ?? ''));

    if ($notes !== '') {
        $label .= ' - ' . $notes;
    }

    return $label;
}

function fbgAdminServersFormatMb(mixed $value): string
{
    $megabytes = (int)$value;

    if ($megabytes === 0) {
        return 'Unlimited';
    }

    if ($megabytes > 0 && $megabytes % 1024 === 0) {
        return number_format($megabytes / 1024) . ' GB';
    }

    return number_format($megabytes) . ' MB';
}

function fbgAdminServersFormatCpu(mixed $value): string
{
    $cpu = (int)$value;
    return $cpu === 0 ? 'Unlimited' : number_format($cpu) . '%';
}

function fbgAdminServersConnection(array $server): string
{
    $host = trim((string)($server['allocation_alias'] ?? ''));
    if ($host === '') {
        $host = trim((string)($server['allocation_ip'] ?? ''));
    }

    $port = trim((string)($server['allocation_port'] ?? ''));

    if ($host === '' || $port === '') {
        return '-';
    }

    return $host . ':' . $port;
}

function fbgAdminServersStatusLabel(mixed $status): string
{
    $status = strtolower(trim((string)$status));

    return match ($status) {
        'suspended' => 'Suspended',
        'installing' => 'Installing',
        'install_failed' => 'Install Failed',
        default => 'Active',
    };
}

function fbgAdminServersStatusClass(mixed $status): string
{
    $status = strtolower(trim((string)$status));

    return match ($status) {
        'suspended' => 'is-suspended',
        'installing' => 'is-installing',
        'install_failed' => 'is-error',
        default => 'is-active',
    };
}

function fbgAdminServersSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
    $query = $_GET;
    $query['name'] = 'admin-servers';
    $query['sort'] = $targetSort;
    $query['dir'] = $direction;
    $query['page_num'] = 1;

    return './page.php?' . http_build_query($query);
}

function fbgAdminServersFind(int $serverId): ?array
{
    if ($serverId <= 0) {
        return null;
    }

    $stmt = fbgPteroDb()->prepare("
        SELECT
            s.id,
            s.external_id,
            s.uuid,
            s.uuidShort AS identifier,
            s.node_id,
            s.name,
            s.description,
            s.status,
            s.owner_id,
            s.memory,
            s.swap,
            s.disk,
            s.io,
            s.cpu,
            s.threads,
            s.oom_disabled,
            s.allocation_limit,
            s.database_limit,
            s.backup_limit,
            s.product_id,
            s.allocation_id,
            s.nest_id,
            s.egg_id,
            s.startup,
            s.image,
            s.expired_at,
            s.created_at,
            s.updated_at,
            n.name AS node_name,
            n.fqdn AS node_fqdn,
            (
                SELECT aa.ip_alias
                FROM allocations aa
                WHERE aa.node_id = s.node_id
                  AND aa.ip_alias IS NOT NULL
                  AND aa.ip_alias != ''
                ORDER BY aa.id ASC
                LIMIT 1
            ) AS node_allocation_alias,
            e.name AS egg_name,
            u.username AS owner_username,
            u.name_first AS owner_first_name,
            u.name_last AS owner_last_name,
            u.email AS owner_email,
            a.ip AS allocation_ip,
            a.ip_alias AS allocation_alias,
            a.port AS allocation_port,
            g.name AS product_name,
            gc.title AS product_category_title
        FROM servers s
        LEFT JOIN nodes n ON n.id = s.node_id
        LEFT JOIN eggs e ON e.id = s.egg_id
        LEFT JOIN users u ON u.id = s.owner_id
        LEFT JOIN allocations a ON a.id = s.allocation_id
        LEFT JOIN games g ON g.id = s.product_id
        LEFT JOIN game_category gc ON gc.id = g.category_id
        WHERE s.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $serverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminServersVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));
    $serverId = (int)($_POST['server_id'] ?? 0);
    $server = fbgAdminServersFind($serverId);

    if (!$server) {
        fbgAdminServersRedirect('Server could not be found.', 'error');
    }

    if ($action === 'update_details') {
        $name = trim((string)($_POST['name'] ?? ''));
        $externalId = trim((string)($_POST['external_id'] ?? ''));
        $productId = max(0, (int)($_POST['product_id'] ?? 0));
        $description = trim((string)($_POST['description'] ?? ''));
        $ownerInput = trim((string)($_POST['owner_search'] ?? ''));
        $ownerId = fbgAdminServersOwnerIdFromInput($ownerInput);
        $expiredAt = fbgAdminServersFormatExpirationForDb((string)($_POST['expired_at'] ?? ''));

        if ($name === '') {
            fbgAdminServersRedirect('Server name is required.', 'error', $serverId);
        }

        if ($ownerId <= 0) {
            fbgAdminServersRedirect('Select a valid server owner from the owner search list.', 'error', $serverId);
        }

        $ownerStmt = fbgPteroDb()->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $ownerStmt->execute(['id' => $ownerId]);
        if ((int)($ownerStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected server owner could not be found.', 'error', $serverId);
        }

        if ($productId > 0) {
            $productStmt = fbgPteroDb()->prepare('SELECT id FROM games WHERE id = :id LIMIT 1');
            $productStmt->execute(['id' => $productId]);
            if ((int)($productStmt->fetchColumn() ?: 0) <= 0) {
                fbgAdminServersRedirect('Selected server plan could not be found.', 'error', $serverId);
            }
        }

        $detailsPayload = [
            'name' => $name,
            'user' => $ownerId,
            'description' => $description,
        ];

        $detailsPayload['external_id'] = $externalId !== '' ? $externalId : null;

        $result = pteroRequest('PATCH', "servers/{$serverId}/details", $detailsPayload);
        if (empty($result['ok'])) {
            fbgAdminServersRedirect((string)($result['error'] ?? 'Server details could not be updated.'), 'error', $serverId);
        }

        $expireStmt = fbgPteroDb()->prepare('
            UPDATE servers
            SET product_id = :product_id,
                expired_at = :expired_at,
                updated_at = NOW()
            WHERE id = :id
        ');
        $expireStmt->execute([
            'product_id' => $productId > 0 ? $productId : null,
            'expired_at' => $expiredAt,
            'id' => $serverId,
        ]);

        fbgAdminServersRedirect('Server details updated successfully.', 'success', $serverId);
    }

    if ($action === 'update_build') {
        $allocationId = max(0, (int)($_POST['allocation_id'] ?? 0));
        $memory = max(0, (int)($_POST['memory'] ?? 0));
        $swap = max(-1, (int)($_POST['swap'] ?? 0));
        $disk = max(0, (int)($_POST['disk'] ?? 0));
        $io = (int)($_POST['io'] ?? 500);
        $cpu = max(0, (int)($_POST['cpu'] ?? 0));
        $threads = trim((string)($_POST['threads'] ?? ''));
        $oomDisabled = isset($_POST['oom_disabled']);
        $databaseLimit = max(0, (int)($_POST['database_limit'] ?? 0));
        $allocationLimit = max(0, (int)($_POST['allocation_limit'] ?? 0));
        $backupLimit = max(0, (int)($_POST['backup_limit'] ?? 0));
        $addAllocations = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['add_allocations'] ?? [])))));
        $removeAllocations = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['remove_allocations'] ?? [])))));

        if ($allocationId <= 0) {
            fbgAdminServersRedirect('Select a valid default game port.', 'error', $serverId, 'build');
        }

        if ($io < 10 || $io > 1000) {
            fbgAdminServersRedirect('Block IO weight must be between 10 and 1000.', 'error', $serverId, 'build');
        }

        if (in_array($allocationId, $removeAllocations, true)) {
            fbgAdminServersRedirect('The default game port cannot also be removed.', 'error', $serverId, 'build');
        }

        if (in_array($allocationId, $addAllocations, true)) {
            fbgAdminServersRedirect('The default game port does not need to be assigned as an additional port.', 'error', $serverId, 'build');
        }

        $allocationStmt = fbgPteroDb()->prepare('
            SELECT id, server_id
            FROM allocations
            WHERE id = :id
              AND node_id = :node_id
              AND server_id = :server_id
            LIMIT 1
        ');
        $allocationStmt->execute([
            'id' => $allocationId,
            'node_id' => (int)$server['node_id'],
            'server_id' => $serverId,
        ]);

        if (!$allocationStmt->fetch(PDO::FETCH_ASSOC)) {
            fbgAdminServersRedirect('Selected default game port is not assigned to this server.', 'error', $serverId, 'build');
        }

        if (!empty($addAllocations)) {
            $placeholders = implode(',', array_fill(0, count($addAllocations), '?'));
            $addStmt = fbgPteroDb()->prepare("
                SELECT id
                FROM allocations
                WHERE id IN ({$placeholders})
                  AND node_id = ?
                  AND server_id IS NULL
            ");
            $addStmt->execute([...$addAllocations, (int)$server['node_id']]);
            $validAddIds = array_map('intval', $addStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if (count($validAddIds) !== count($addAllocations)) {
                fbgAdminServersRedirect('One or more selected ports are no longer available to assign.', 'error', $serverId, 'build');
            }
        }

        if (!empty($removeAllocations)) {
            $placeholders = implode(',', array_fill(0, count($removeAllocations), '?'));
            $removeStmt = fbgPteroDb()->prepare("
                SELECT id
                FROM allocations
                WHERE id IN ({$placeholders})
                  AND server_id = ?
            ");
            $removeStmt->execute([...$removeAllocations, $serverId]);
            $validRemoveIds = array_map('intval', $removeStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if (count($validRemoveIds) !== count($removeAllocations)) {
                fbgAdminServersRedirect('One or more selected ports are not assigned to this server.', 'error', $serverId, 'build');
            }
        }

        $buildPayload = [
            'allocation' => $allocationId,
            'memory' => $memory,
            'swap' => $swap,
            'disk' => $disk,
            'io' => $io,
            'cpu' => $cpu,
            'threads' => $threads !== '' ? $threads : null,
            'oom_disabled' => $oomDisabled,
            'feature_limits' => [
                'databases' => $databaseLimit,
                'allocations' => $allocationLimit,
                'backups' => $backupLimit,
            ],
        ];

        if (!empty($addAllocations)) {
            $buildPayload['add_allocations'] = $addAllocations;
        }

        if (!empty($removeAllocations)) {
            $buildPayload['remove_allocations'] = $removeAllocations;
        }

        $result = pteroRequest('PATCH', "servers/{$serverId}/build", $buildPayload);
        if (empty($result['ok'])) {
            fbgAdminServersRedirect((string)($result['error'] ?? 'Server build configuration could not be updated.'), 'error', $serverId, 'build');
        }

        fbgAdminServersRedirect('Server build configuration updated successfully.', 'success', $serverId, 'build');
    }

    fbgAdminServersRedirect('Unknown server action.', 'error', $serverId);
}

$editServerId = max(0, (int)($_GET['edit'] ?? 0));
$editingServer = $editServerId > 0 ? fbgAdminServersFind($editServerId) : null;
$activeServerTab = strtolower(trim((string)($_GET['tab'] ?? 'about')));

if (!in_array($activeServerTab, ['about', 'details', 'build', 'startup', 'database', 'mounts', 'manage', 'delete'], true)) {
    $activeServerTab = 'about';
}

$ownerOptions = [];
$planOptionsByCategory = [];
$defaultAllocationOptions = [];
$availableAllocationOptions = [];
$assignedAllocationOptions = [];
if ($editingServer) {
    $ownerStmt = fbgPteroDb()->query("
        SELECT id, username, email, name_first, name_last
        FROM users
        ORDER BY name_first ASC, name_last ASC, username ASC
    ");
    $ownerOptions = $ownerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $planStmt = fbgPteroDb()->query("
        SELECT
            g.id,
            g.name,
            g.price,
            g.hide,
            c.title AS category_title
        FROM games g
        LEFT JOIN game_category c ON c.id = g.category_id
        ORDER BY COALESCE(c.sort, 999999) ASC, c.title ASC, COALESCE(g.sort, 999999) ASC, g.name ASC
    ");

    foreach (($planStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $planOption) {
        $categoryTitle = trim((string)($planOption['category_title'] ?? 'Uncategorized'));
        if ($categoryTitle === '') {
            $categoryTitle = 'Uncategorized';
        }

        $planOptionsByCategory[$categoryTitle][] = $planOption;
    }

    $availableAllocationStmt = fbgPteroDb()->prepare('
        SELECT id, ip, ip_alias, port, server_id, notes
        FROM allocations
        WHERE node_id = :node_id
          AND server_id IS NULL
        ORDER BY COALESCE(ip_alias, ip) ASC, port ASC
    ');
    $availableAllocationStmt->execute(['node_id' => (int)$editingServer['node_id']]);
    $availableAllocationOptions = $availableAllocationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $assignedAllocationStmt = fbgPteroDb()->prepare('
        SELECT id, ip, ip_alias, port, server_id, notes
        FROM allocations
        WHERE server_id = :server_id
        ORDER BY CASE WHEN id = :allocation_id THEN 0 ELSE 1 END, COALESCE(ip_alias, ip) ASC, port ASC
    ');
    $assignedAllocationStmt->execute([
        'server_id' => (int)$editingServer['id'],
        'allocation_id' => (int)$editingServer['allocation_id'],
    ]);
    $assignedAllocationOptions = $assignedAllocationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $defaultAllocationOptions = $assignedAllocationOptions;
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$nodeFilter = max(0, (int)($_GET['node_id'] ?? 0));
$sort = strtolower(trim((string)($_GET['sort'] ?? 'name')));
$direction = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$pageNum = max(1, (int)($_GET['page_num'] ?? 1));
$perPage = 25;
$offset = ($pageNum - 1) * $perPage;

$sortMap = [
    'name' => 's.name',
    'uuid' => 's.uuid',
    'owner_username' => 'u.username',
    'owner_name' => 'u.name_last',
    'node' => 'n.name',
    'connection' => 'a.ip_alias',
    'status' => 's.status',
    'created' => 's.created_at',
];

if (!array_key_exists($sort, $sortMap)) {
    $sort = 'name';
}

if (!in_array($statusFilter, ['all', 'active', 'suspended', 'installing'], true)) {
    $statusFilter = 'all';
}

$nodesStmt = fbgPteroDb()->query('SELECT id, name FROM nodes ORDER BY name ASC');
$nodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$where = [];
$params = [];

if ($search !== '') {
    $searchColumns = [
        's.name',
        's.uuid',
        's.uuidShort',
        'u.username',
        'u.name_first',
        'u.name_last',
        'n.name',
        'a.ip_alias',
        'a.ip',
        'CAST(a.port AS CHAR)',
    ];
    $searchParts = [];

    foreach ($searchColumns as $index => $column) {
        $placeholder = 'search_' . $index;
        $searchParts[] = "{$column} LIKE :{$placeholder}";
        $params[$placeholder] = '%' . $search . '%';
    }

    $where[] = "(
        " . implode("\n        OR ", $searchParts) . "
    )";
}

if ($statusFilter === 'active') {
    $where[] = "(s.status IS NULL OR s.status = '')";
} elseif ($statusFilter !== 'all') {
    $where[] = 's.status = :status';
    $params['status'] = $statusFilter;
}

if ($nodeFilter > 0) {
    $where[] = 's.node_id = :node_id';
    $params['node_id'] = $nodeFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = fbgPteroDb()->prepare("
    SELECT COUNT(*)
    FROM servers s
    LEFT JOIN nodes n ON n.id = s.node_id
    LEFT JOIN users u ON u.id = s.owner_id
    LEFT JOIN allocations a ON a.id = s.allocation_id
    {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
    $offset = ($pageNum - 1) * $perPage;
}

$orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);
$serversStmt = fbgPteroDb()->prepare("
    SELECT
        s.id,
        s.uuid,
        s.uuidShort AS identifier,
        s.name,
        s.status,
        s.owner_id,
        n.name AS node_name,
        u.username AS owner_username,
        u.name_first AS owner_first_name,
        u.name_last AS owner_last_name,
        a.ip AS allocation_ip,
        a.ip_alias AS allocation_alias,
        a.port AS allocation_port
    FROM servers s
    LEFT JOIN nodes n ON n.id = s.node_id
    LEFT JOIN users u ON u.id = s.owner_id
    LEFT JOIN allocations a ON a.id = s.allocation_id
    {$whereSql}
    ORDER BY {$orderSql}, s.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$serversStmt->execute($params);
$servers = $serversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Servers</h1>
                <p class="fbg-admin-subtext">Review Pterodactyl servers, ownership, node placement, connection details, and lifecycle status.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Servers</h2>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-servers">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="server-search">Search</label>
                            <input id="server-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Server, UUID, owner, node, or port">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-status">Status</label>
                            <select id="server-status" name="status">
                                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="installing" <?= $statusFilter === 'installing' ? 'selected' : '' ?>>Installing</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-node">Node</label>
                            <select id="server-node" name="node_id">
                                <option value="0">All Nodes</option>
                                <?php foreach ($nodes as $node): ?>
                                    <option value="<?= (int)$node['id'] ?>" <?= $nodeFilter === (int)$node['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-sort">Sort</label>
                            <select id="server-sort" name="sort">
                                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Server Name</option>
                                <option value="uuid" <?= $sort === 'uuid' ? 'selected' : '' ?>>UUID</option>
                                <option value="owner_username" <?= $sort === 'owner_username' ? 'selected' : '' ?>>Owner Username</option>
                                <option value="owner_name" <?= $sort === 'owner_name' ? 'selected' : '' ?>>Owner Name</option>
                                <option value="node" <?= $sort === 'node' ? 'selected' : '' ?>>Node</option>
                                <option value="connection" <?= $sort === 'connection' ? 'selected' : '' ?>>Connection</option>
                                <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                                <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Created</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-dir">Direction</label>
                            <select id="server-dir" name="dir">
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
                    <table class="fbg-admin-table fbg-admin-servers-table">
                        <thead>
                            <tr>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('name', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Server Name</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('uuid', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">UUID</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('owner_username', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Owner Username</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('owner_name', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Owner Name</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('node', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Node</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('connection', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Connection</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminServersSortUrl('status', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Status</a></th>
                                <th>Panel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($servers)): ?>
                                <tr>
                                    <td colspan="8">No servers found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($servers as $server): ?>
                                <?php
                                $ownerName = trim((string)($server['owner_first_name'] ?? '') . ' ' . (string)($server['owner_last_name'] ?? ''));
                                $ownerUsername = trim((string)($server['owner_username'] ?? ''));
                                $identifier = trim((string)($server['identifier'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="./page.php?name=admin-servers&edit=<?= (int)$server['id'] ?>">
                                            <?= htmlspecialchars((string)$server['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><code><?= htmlspecialchars((string)$server['uuid'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td>
                                        <?php if ((int)$server['owner_id'] > 0): ?>
                                            <a class="fbg-admin-branded-link" href="./page.php?name=admin-users&edit=<?= (int)$server['owner_id'] ?>">
                                                <?= htmlspecialchars($ownerUsername !== '' ? $ownerUsername : 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$server['owner_id'] > 0): ?>
                                            <a class="fbg-admin-branded-link" href="./page.php?name=admin-users&edit=<?= (int)$server['owner_id'] ?>">
                                                <?= htmlspecialchars($ownerName !== '' ? $ownerName : 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($server['node_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminServersConnection($server), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="fbg-admin-status-pill <?= htmlspecialchars(fbgAdminServersStatusClass($server['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(fbgAdminServersStatusLabel($server['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($identifier !== ''): ?>
                                            <a class="btn btn-sm fbg-admin-icon-button" href="./page.php?name=serverpanel&id=<?= urlencode($identifier) ?>&tab=console" title="Open server console" aria-label="Open server console">
                                                <i class="fas fa-wrench" aria-hidden="true"></i>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="fbg-admin-form-actions">
                    <?php if ($pageNum > 1): ?>
                        <?php $prevQuery = array_merge($_GET, ['page_num' => $pageNum - 1]); ?>
                        <a class="btn fbg-neutral-button" href="./page.php?<?= htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                    <?php endif; ?>

                    <span><?= number_format($totalRows) ?> total server<?= $totalRows === 1 ? '' : 's' ?>, page <?= $pageNum ?> of <?= $totalPages ?></span>

                    <?php if ($pageNum < $totalPages): ?>
                        <?php $nextQuery = array_merge($_GET, ['page_num' => $pageNum + 1]); ?>
                        <a class="btn fbg-neutral-button" href="./page.php?<?= htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($editServerId > 0 && !$editingServer): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-empty-state">
                        <p>Server could not be found.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($editingServer): ?>
    <?php
    $editingOwnerName = trim((string)($editingServer['owner_first_name'] ?? '') . ' ' . (string)($editingServer['owner_last_name'] ?? ''));
    $editingOwnerUsername = trim((string)($editingServer['owner_username'] ?? ''));
    $editingIdentifier = trim((string)($editingServer['identifier'] ?? ''));
    $editingConnection = fbgAdminServersConnection($editingServer);
    ?>
    <div class="fbg-modal-overlay" id="admin-server-edit-modal">
        <div class="fbg-modal-card fbg-admin-server-modal" role="dialog" aria-modal="true" aria-labelledby="admin-server-edit-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-servers" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-server-edit-title"><?= htmlspecialchars((string)$editingServer['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p>Review server ownership, node placement, allocation, resources, and upcoming administrative controls.</p>
            </div>

            <div class="fbg-admin-server-tabs" role="tablist" aria-label="Server administration sections">
                <?php
                $tabs = [
                    'about' => 'About',
                    'details' => 'Details',
                    'build' => 'Build Configuration',
                    'startup' => 'Startup',
                    'database' => 'Database',
                    'mounts' => 'Mounts',
                    'manage' => 'Manage',
                    'delete' => 'Delete',
                ];
                ?>
                <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                    <button
                        type="button"
                        class="fbg-admin-server-tab<?= $tabKey === $activeServerTab ? ' is-active' : '' ?>"
                        data-admin-server-tab="<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
                        role="tab"
                        aria-selected="<?= $tabKey === $activeServerTab ? 'true' : 'false' ?>"
                    >
                        <?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>

                <?php if ($editingIdentifier !== ''): ?>
                    <a class="fbg-admin-server-tab fbg-admin-server-console-link" href="./page.php?name=serverpanel&id=<?= urlencode($editingIdentifier) ?>&tab=console">
                        <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'about' ? ' is-active' : '' ?>" data-admin-server-panel="about" <?= $activeServerTab === 'about' ? '' : 'hidden' ?>>
                <div class="fbg-admin-server-overview-grid">
                    <article class="fbg-admin-server-info-card">
                        <span class="fbg-admin-server-info-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
                        <div>
                            <span>Owner</span>
                            <strong>
                                <?php if ((int)$editingServer['owner_id'] > 0): ?>
                                    <a class="fbg-admin-branded-link" href="./page.php?name=admin-users&edit=<?= (int)$editingServer['owner_id'] ?>">
                                        <?= htmlspecialchars(($editingOwnerName !== '' ? $editingOwnerName : 'Unknown') . ($editingOwnerUsername !== '' ? ' (' . $editingOwnerUsername . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    Unknown
                                <?php endif; ?>
                            </strong>
                            <small>ID #<?= (int)$editingServer['owner_id'] ?></small>
                        </div>
                    </article>

                    <article class="fbg-admin-server-info-card">
                        <span class="fbg-admin-server-info-icon"><i class="fas fa-server" aria-hidden="true"></i></span>
                        <div>
                            <span>Node</span>
                            <strong><?= htmlspecialchars((string)($editingServer['node_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars((string)($editingServer['node_allocation_alias'] ?? $editingServer['node_fqdn'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>

                    <article class="fbg-admin-server-info-card">
                        <span class="fbg-admin-server-info-icon"><i class="fas fa-network-wired" aria-hidden="true"></i></span>
                        <div>
                            <span>Default Connection</span>
                            <strong><?= htmlspecialchars($editingConnection, ENT_QUOTES, 'UTF-8') ?></strong>
                            <small>Port: <?= htmlspecialchars(trim((string)($editingServer['allocation_port'] ?? '')) !== '' ? (string)$editingServer['allocation_port'] : '-', ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>

                    <article class="fbg-admin-server-info-card">
                        <span class="fbg-admin-server-info-icon"><i class="fas fa-info-circle" aria-hidden="true"></i></span>
                        <div>
                            <span>Status</span>
                            <strong><?= htmlspecialchars(fbgAdminServersStatusLabel($editingServer['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars(fbgAdminServersSafeDate($editingServer['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                </div>

                <div class="fbg-admin-server-about-grid">
                    <div class="fbg-admin-server-detail-list">
                        <h3>Identifiers</h3>
                        <dl>
                            <div><dt>Internal ID</dt><dd><?= (int)$editingServer['id'] ?></dd></div>
                            <div><dt>External ID</dt><dd><?= htmlspecialchars((string)($editingServer['external_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>UUID</dt><dd><code><?= htmlspecialchars((string)$editingServer['uuid'], ENT_QUOTES, 'UTF-8') ?></code></dd></div>
                            <div><dt>Short Identifier</dt><dd><code><?= htmlspecialchars($editingIdentifier !== '' ? $editingIdentifier : '-', ENT_QUOTES, 'UTF-8') ?></code></dd></div>
                            <div><dt>Current Egg</dt><dd><?= htmlspecialchars((string)($editingServer['egg_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        </dl>
                    </div>

                    <div class="fbg-admin-server-detail-list">
                        <h3>Resources</h3>
                        <dl>
                            <div><dt>CPU Limit</dt><dd><?= htmlspecialchars(fbgAdminServersFormatCpu($editingServer['cpu'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>CPU Pinning</dt><dd><?= htmlspecialchars(trim((string)($editingServer['threads'] ?? '')) !== '' ? (string)$editingServer['threads'] : '-', ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Memory</dt><dd><?= htmlspecialchars(fbgAdminServersFormatMb($editingServer['memory'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Disk Space</dt><dd><?= htmlspecialchars(fbgAdminServersFormatMb($editingServer['disk'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Block IO Weight</dt><dd><?= (int)($editingServer['io'] ?? 0) ?></dd></div>
                        </dl>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'details' ? ' is-active' : '' ?>" data-admin-server-panel="details" <?= $activeServerTab === 'details' ? '' : 'hidden' ?>>
                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_details">
                    <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="server-detail-name">Server Name</label>
                            <input id="server-detail-name" name="name" type="text" required value="<?= htmlspecialchars((string)$editingServer['name'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-detail-external-id">External Identifier</label>
                            <input id="server-detail-external-id" name="external_id" type="text" value="<?= htmlspecialchars((string)($editingServer['external_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-detail-product-id">Plan</label>
                            <select id="server-detail-product-id" name="product_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($planOptionsByCategory as $categoryTitle => $plansInCategory): ?>
                                    <optgroup label="<?= htmlspecialchars($categoryTitle, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php foreach ($plansInCategory as $planOption): ?>
                                            <?php
                                            $planLabel = (string)($planOption['name'] ?? 'Unnamed Plan');
                                            if (isset($planOption['price'])) {
                                                $planLabel .= ' - ' . fbgFormatCredit((float)$planOption['price']);
                                            }
                                            if ((int)($planOption['hide'] ?? 0) !== 0) {
                                                $planLabel .= ' (Hidden)';
                                            }
                                            ?>
                                            <option value="<?= (int)$planOption['id'] ?>" <?= (int)($editingServer['product_id'] ?? 0) === (int)$planOption['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">Sets the shop plan ID used by renewal pricing and expiration handling.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-detail-expiration">Expiration Date</label>
                            <input id="server-detail-expiration" name="expired_at" type="datetime-local" value="<?= htmlspecialchars(fbgAdminServersDatetimeLocalValue($editingServer['expired_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">Saved to the shop/plugin expiration field. Leave blank if the server does not expire.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-detail-owner">Server Owner</label>
                            <input
                                id="server-detail-owner"
                                name="owner_search"
                                type="text"
                                list="admin-server-owner-options"
                                autocomplete="off"
                                required
                                value="<?= htmlspecialchars(fbgAdminServersOwnerOptionLabel([
                                    'id' => (int)$editingServer['owner_id'],
                                    'username' => (string)($editingServer['owner_username'] ?? ''),
                                    'email' => (string)($editingServer['owner_email'] ?? ''),
                                    'name_first' => (string)($editingServer['owner_first_name'] ?? ''),
                                    'name_last' => (string)($editingServer['owner_last_name'] ?? ''),
                                ]), ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <datalist id="admin-server-owner-options"></datalist>
                            <script type="application/json" id="admin-server-owner-source">
                                <?= json_encode(array_map(static fn(array $owner): array => [
                                    'label' => fbgAdminServersOwnerOptionLabel($owner),
                                    'terms' => [
                                        strtolower((string)($owner['username'] ?? '')),
                                        strtolower((string)($owner['email'] ?? '')),
                                        strtolower((string)($owner['name_first'] ?? '')),
                                        strtolower((string)($owner['name_last'] ?? '')),
                                        strtolower(trim((string)($owner['name_first'] ?? '') . ' ' . (string)($owner['name_last'] ?? ''))),
                                    ],
                                ], $ownerOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                            </script>
                            <p class="fbg-admin-help-text">Start typing at least 2 characters, then select a user from the list.</p>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="server-detail-description">Server Description</label>
                            <textarea id="server-detail-description" name="description" rows="5"><?= htmlspecialchars((string)($editingServer['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Save Details</button>
                        <a class="btn fbg-neutral-button" href="./page.php?name=admin-servers&edit=<?= (int)$editingServer['id'] ?>">Cancel</a>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'build' ? ' is-active' : '' ?>" data-admin-server-panel="build" <?= $activeServerTab === 'build' ? '' : 'hidden' ?>>
                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_build">
                    <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">

                    <div class="fbg-admin-server-about-grid">
                        <div class="fbg-admin-server-detail-list">
                            <h3>Resource Management</h3>
                            <div class="fbg-admin-form-grid">
                                <div class="fbg-admin-field">
                                    <label for="server-build-cpu">CPU Limit (%)</label>
                                    <input id="server-build-cpu" name="cpu" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['cpu'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">Set to 0 for unlimited CPU time.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-threads">CPU Pinning</label>
                                    <input id="server-build-threads" name="threads" type="text" value="<?= htmlspecialchars((string)($editingServer['threads'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="0-3,8">
                                    <p class="fbg-admin-help-text"><string>Advanced</strong>: Enter the specific CPU cores that this process can run on, or leave blank to allow all cores. This can be a single number, or a comma seperated list. Example: 0, 0-1,3, or 0,1,3,4.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-memory">Allocated Memory (MiB)</label>
                                    <input id="server-build-memory" name="memory" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['memory'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">Set to 0 for unlimited memory.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-swap">Allocated Swap (MiB)</label>
                                    <input id="server-build-swap" name="swap" type="number" min="-1" required value="<?= htmlspecialchars((string)($editingServer['swap'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">0 disables swap. -1 allows unlimited swap.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-disk">Disk Space Limit (MiB)</label>
                                    <input id="server-build-disk" name="disk" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['disk'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">Set to 0 for unlimited disk usage.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-io">Block IO Weight</label>
                                    <input id="server-build-io" name="io" type="number" min="10" max="1000" required value="<?= htmlspecialchars((string)($editingServer['io'] ?? 500), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text"><strong>Advanced</strong>: The IO performance of this server relative to other running containers on the system. Value should be between 10 and 1000.</p>
                                </div>

                                <div class="fbg-admin-field fbg-admin-field-full">
                                    <label>
                                        <input type="checkbox" name="oom_disabled" value="1" <?= (int)($editingServer['oom_disabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                                        Disable OOM Killer
                                    </label>
                                    <p class="fbg-admin-help-text">When enabled, the server process is less likely to be killed automatically if it exceeds memory limits.</p>
                                </div>
                            </div>
                        </div>

                        <div class="fbg-admin-server-detail-list">
                            <h3>Application Feature Limits</h3>
                            <div class="fbg-admin-form-grid">
                                <div class="fbg-admin-field">
                                    <label for="server-build-database-limit">Database Limit</label>
                                    <input id="server-build-database-limit" name="database_limit" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['database_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">The total number of databases a user is allowed to create for this server.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-allocation-limit">Port Limit</label>
                                    <input id="server-build-allocation-limit" name="allocation_limit" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['allocation_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">The total number of allocations a user is allowed to create for this server.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-build-backup-limit">Backup Limit</label>
                                    <input id="server-build-backup-limit" name="backup_limit" type="number" min="0" required value="<?= htmlspecialchars((string)($editingServer['backup_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="fbg-admin-help-text">The total number of backups that can be created for this server.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                        <h3>Allocation Management</h3>
                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="server-build-allocation-id">Game Port</label>
                                <select id="server-build-allocation-id" name="allocation_id" required>
                                    <?php foreach ($defaultAllocationOptions as $allocationOption): ?>
                                        <option value="<?= (int)$allocationOption['id'] ?>" <?= (int)$editingServer['allocation_id'] === (int)$allocationOption['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(fbgAdminServersAllocationLabel($allocationOption), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="fbg-admin-help-text">Select the main connection from ports already assigned to this server.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="server-build-add-allocations">Assign Additional Ports</label>
                                <select id="server-build-add-allocations" name="add_allocations[]" multiple size="8">
                                    <?php foreach ($availableAllocationOptions as $allocationOption): ?>
                                        <?php if ((int)$allocationOption['id'] === (int)$editingServer['allocation_id']) continue; ?>
                                        <option value="<?= (int)$allocationOption['id'] ?>">
                                            <?= htmlspecialchars(fbgAdminServersAllocationLabel($allocationOption), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="fbg-admin-help-text">Hold Ctrl to select more than one available port.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="server-build-remove-allocations">Remove Additional Ports</label>
                                <select id="server-build-remove-allocations" name="remove_allocations[]" multiple size="8">
                                    <?php foreach ($assignedAllocationOptions as $allocationOption): ?>
                                        <?php if ((int)$allocationOption['id'] === (int)$editingServer['allocation_id']) continue; ?>
                                        <option value="<?= (int)$allocationOption['id'] ?>">
                                            <?= htmlspecialchars(fbgAdminServersAllocationLabel($allocationOption), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="fbg-admin-help-text">Shows ports currently assigned to this server. The current default game port cannot be removed here.</p>
                            </div>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Update Build Configuration</button>
                        <a class="btn fbg-neutral-button" href="./page.php?name=admin-servers&edit=<?= (int)$editingServer['id'] ?>&tab=build">Cancel</a>
                    </div>
                </form>
            </section>

            <?php foreach (array_diff(array_keys($tabs), ['about', 'details', 'build']) as $tabKey): ?>
                <section class="fbg-admin-server-tab-panel" data-admin-server-panel="<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>" hidden>
                    <div class="fbg-admin-empty-state">
                        <p><?= htmlspecialchars($tabs[$tabKey], ENT_QUOTES, 'UTF-8') ?> controls will be added in the next Admin Server Administration pass.</p>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('admin-server-edit-modal');
    if (!modal) return;

    document.body.classList.add('fbg-modal-open');

    const tabs = modal.querySelectorAll('[data-admin-server-tab]');
    const panels = modal.querySelectorAll('[data-admin-server-panel]');
    const ownerInput = document.getElementById('server-detail-owner');
    const ownerDatalist = document.getElementById('admin-server-owner-options');
    const ownerSource = document.getElementById('admin-server-owner-source');

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.adminServerTab;

            tabs.forEach((candidate) => {
                const isActive = candidate === tab;
                candidate.classList.toggle('is-active', isActive);
                candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                const isActive = panel.dataset.adminServerPanel === target;
                panel.hidden = !isActive;
                panel.classList.toggle('is-active', isActive);
            });
        });
    });

    if (ownerInput && ownerDatalist && ownerSource) {
        let owners = [];

        try {
            owners = JSON.parse(ownerSource.textContent || '[]');
        } catch (error) {
            owners = [];
        }

        ownerInput.addEventListener('input', () => {
            const query = ownerInput.value.trim().toLowerCase();
            ownerDatalist.replaceChildren();

            if (query.length < 2) {
                return;
            }

            owners
                .filter((owner) => Array.isArray(owner.terms) && owner.terms.some((term) => String(term).startsWith(query)))
                .slice(0, 30)
                .forEach((owner) => {
                    const option = document.createElement('option');
                    option.value = owner.label;
                    ownerDatalist.appendChild(option);
                });
        });
    }

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            window.location.href = './page.php?name=admin-servers';
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            window.location.href = './page.php?name=admin-servers';
        }
    });
});
</script>
