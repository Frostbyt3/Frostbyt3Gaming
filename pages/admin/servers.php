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

$currentAdminPage = 'admin-servers';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_servers_message'] ?? '');
$messageType = (string)($_SESSION['admin_servers_message_type'] ?? 'success');
unset($_SESSION['admin_servers_message'], $_SESSION['admin_servers_message_type']);

function fbgAdminServersRedirect(string $message, string $type = 'success', ?int $editServerId = null, string $tab = 'details', bool $openCreate = false): void
{
    $_SESSION['admin_servers_message'] = $message;
    $_SESSION['admin_servers_message_type'] = $type;

    $url = '/page.php?name=admin-servers';
    if ($editServerId !== null && $editServerId > 0) {
        $url .= '&edit=' . $editServerId . '&tab=' . urlencode($tab);
    } elseif ($openCreate) {
        $url .= '&create=1';
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminServersJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function fbgAdminServersDockerImagesMap(mixed $value): array
{
    $raw = trim((string)$value);

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    $images = [];

    if (is_array($decoded)) {
        foreach ($decoded as $label => $image) {
            if (!is_string($image) || trim($image) === '') {
                continue;
            }

            $image = trim($image);
            $label = is_string($label) && trim($label) !== '' ? trim($label) : $image;
            $images[$image] = $label;
        }

        return $images;
    }

    return [$raw => $raw];
}

function fbgAdminServersDatabaseHostLabel(array $host): string
{
    $name = trim((string)($host['name'] ?? ''));
    $address = trim((string)($host['host'] ?? ''));
    $port = trim((string)($host['port'] ?? ''));
    $label = $name !== '' ? $name : 'Database Host #' . (int)($host['id'] ?? 0);

    if ($address !== '') {
        $label .= ' (' . $address . ($port !== '' ? ':' . $port : '') . ')';
    }

    return $label;
}

function fbgAdminServersDatabaseApiError(array $result, string $fallback): string
{
    if ((int)($result['status'] ?? 0) === 403) {
        return 'Pterodactyl denied this database action. Check that PTERO_DB_MANAGEMENT_API_KEY has Server Databases read/write permission, then try again.';
    }

    return (string)($result['error'] ?? $fallback);
}

function fbgAdminServersServerApiError(array $result, string $fallback): string
{
    if ((int)($result['status'] ?? 0) === 403) {
        return 'Pterodactyl denied this server action. Check that PTERO_API_KEY has the required server permissions, then try again.';
    }

    return (string)($result['error'] ?? $fallback);
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

function fbgAdminServersMountStatusClass(bool $isMounted): string
{
    return $isMounted ? 'is-active' : 'is-installing';
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

function fbgAdminServersBaseQuery(array $overrides = []): string
{
    $query = $_GET;
    $query['name'] = 'admin-servers';

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
            continue;
        }

        $query[$key] = $value;
    }

    return './page.php?' . http_build_query($query);
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

    $hasSuspendManualColumn = function_exists('fbgEnsurePteroServersSuspendManualColumn')
        ? fbgEnsurePteroServersSuspendManualColumn()
        : false;
    $suspendManualSelect = $hasSuspendManualColumn ? 's.suspend_manual,' : '0 AS suspend_manual,';

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
            {$suspendManualSelect}
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
            e.startup AS egg_startup,
            e.docker_images AS egg_docker_images,
            ns.name AS nest_name,
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
        LEFT JOIN nests ns ON ns.id = s.nest_id
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

    if ($action === 'create_server') {
        $name = trim((string)($_POST['create_name'] ?? ''));
        $description = trim((string)($_POST['create_description'] ?? ''));
        $ownerInput = trim((string)($_POST['create_owner_search'] ?? ''));
        $ownerId = fbgAdminServersOwnerIdFromInput($ownerInput);
        $nodeId = max(0, (int)($_POST['create_node_id'] ?? 0));
        $allocationId = max(0, (int)($_POST['create_allocation_id'] ?? 0));
        $additionalAllocations = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['create_allocation_additional'] ?? [])))));
        $databaseLimit = max(0, (int)($_POST['create_database_limit'] ?? 0));
        $allocationLimit = max(0, (int)($_POST['create_allocation_limit'] ?? 0));
        $backupLimit = max(0, (int)($_POST['create_backup_limit'] ?? 0));
        $cpu = max(0, (int)($_POST['create_cpu'] ?? 0));
        $threads = trim((string)($_POST['create_threads'] ?? ''));
        $memory = max(0, (int)($_POST['create_memory'] ?? 0));
        $swap = max(-1, (int)($_POST['create_swap'] ?? 0));
        $disk = max(0, (int)($_POST['create_disk'] ?? 0));
        $io = (int)($_POST['create_io'] ?? 500);
        $oomDisabled = !isset($_POST['create_enable_oom_killer']);
        $nestId = max(0, (int)($_POST['create_nest_id'] ?? 0));
        $eggId = max(0, (int)($_POST['create_egg_id'] ?? 0));
        $skipScripts = isset($_POST['create_skip_scripts']);
        $dockerImage = trim((string)($_POST['create_docker_image'] ?? ''));
        $customImage = trim((string)($_POST['create_custom_image'] ?? ''));
        $startup = trim((string)($_POST['create_startup'] ?? ''));
        $startOnCompletion = isset($_POST['create_start_on_completion']);
        $environment = $_POST['create_environment'] ?? [];

        if ($name === '') {
            fbgAdminServersRedirect('Server name is required.', 'error', null, 'details', true);
        }

        if ($ownerId <= 0) {
            fbgAdminServersRedirect('Select a valid server owner from the owner search list.', 'error', null, 'details', true);
        }

        if ($nodeId <= 0) {
            fbgAdminServersRedirect('Select a valid node.', 'error', null, 'details', true);
        }

        if ($allocationId <= 0) {
            fbgAdminServersRedirect('Select a valid default port.', 'error', null, 'details', true);
        }

        if ($nestId <= 0 || $eggId <= 0) {
            fbgAdminServersRedirect('Select a valid nest and egg.', 'error', null, 'details', true);
        }

        if ($startup === '') {
            fbgAdminServersRedirect('Startup command is required.', 'error', null, 'details', true);
        }

        if ($io < 10 || $io > 1000) {
            fbgAdminServersRedirect('Block IO weight must be between 10 and 1000.', 'error', null, 'details', true);
        }

        $image = $customImage !== '' ? $customImage : $dockerImage;
        if ($image === '') {
            fbgAdminServersRedirect('Select a docker image or provide a custom one.', 'error', null, 'details', true);
        }

        if (in_array($allocationId, $additionalAllocations, true)) {
            fbgAdminServersRedirect('The default port does not need to be assigned again as an additional port.', 'error', null, 'details', true);
        }

        $ownerStmt = fbgPteroDb()->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $ownerStmt->execute(['id' => $ownerId]);
        if ((int)($ownerStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected server owner could not be found.', 'error', null, 'details', true);
        }

        $nodeStmt = fbgPteroDb()->prepare('SELECT id FROM nodes WHERE id = :id LIMIT 1');
        $nodeStmt->execute(['id' => $nodeId]);
        if ((int)($nodeStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected node could not be found.', 'error', null, 'details', true);
        }

        $eggStmt = fbgPteroDb()->prepare('
            SELECT id
            FROM eggs
            WHERE id = :egg_id
              AND nest_id = :nest_id
            LIMIT 1
        ');
        $eggStmt->execute([
            'egg_id' => $eggId,
            'nest_id' => $nestId,
        ]);
        if ((int)($eggStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected egg does not belong to the selected nest.', 'error', null, 'details', true);
        }

        $allocationStmt = fbgPteroDb()->prepare('
            SELECT id
            FROM allocations
            WHERE id = :id
              AND node_id = :node_id
              AND server_id IS NULL
            LIMIT 1
        ');
        $allocationStmt->execute([
            'id' => $allocationId,
            'node_id' => $nodeId,
        ]);
        if ((int)($allocationStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected default port is not available on the chosen node.', 'error', null, 'details', true);
        }

        if (!empty($additionalAllocations)) {
            $placeholders = implode(',', array_fill(0, count($additionalAllocations), '?'));
            $additionalStmt = fbgPteroDb()->prepare("
                SELECT id
                FROM allocations
                WHERE id IN ({$placeholders})
                  AND node_id = ?
                  AND server_id IS NULL
            ");
            $additionalStmt->execute([...$additionalAllocations, $nodeId]);
            $validAdditionalIds = array_map('intval', $additionalStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if (count($validAdditionalIds) !== count($additionalAllocations)) {
                fbgAdminServersRedirect('One or more additional ports are no longer available on the selected node.', 'error', null, 'details', true);
            }
        }

        if (!is_array($environment)) {
            $environment = [];
        }

        $environment = array_map(static fn($value) => is_scalar($value) ? trim((string)$value) : '', $environment);

        $payload = [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'user' => $ownerId,
            'egg' => $eggId,
            'docker_image' => $image,
            'startup' => $startup,
            'environment' => $environment,
            'skip_scripts' => $skipScripts,
            'oom_disabled' => $oomDisabled,
            'limits' => [
                'memory' => $memory,
                'swap' => $swap,
                'disk' => $disk,
                'io' => $io,
                'threads' => $threads !== '' ? $threads : null,
                'cpu' => $cpu,
            ],
            'feature_limits' => [
                'databases' => $databaseLimit,
                'allocations' => $allocationLimit,
                'backups' => $backupLimit,
            ],
            'allocation' => [
                'default' => $allocationId,
                'additional' => $additionalAllocations,
            ],
            'start_on_completion' => $startOnCompletion,
        ];

        $result = pteroRequest('POST', 'servers', $payload);
        if (empty($result['ok'])) {
            fbgAdminServersRedirect(
                fbgAdminServersServerApiError($result, 'Server could not be created.'),
                'error',
                null,
                'details',
                true
            );
        }

        $createdServerId = (int)($result['data']['attributes']['id'] ?? 0);
        if ($createdServerId <= 0) {
            fbgAdminServersRedirect('Server was created, but the new server ID was not returned by the API.', 'success');
        }

        fbgAdminServersRedirect('Server created successfully.', 'success', $createdServerId, 'about');
    }

    $serverId = (int)($_POST['server_id'] ?? 0);
    $server = fbgAdminServersFind($serverId);

    if (!$server) {
        fbgAdminServersRedirect('Server could not be found.', 'error');
    }

    if ($action === 'fetch_expiration_history') {
        $entries = array_map(static function (array $entry): array {
            return [
                'created_at' => fbgAdminServersSafeDate($entry['created_at'] ?? ''),
                'action' => fbgServerExpirationActionLabel((string)($entry['action'] ?? '')),
                'previous_expiration' => !empty($entry['old_expired_at']) ? fbgAdminServersSafeDate($entry['old_expired_at']) : 'None',
                'new_expiration' => !empty($entry['new_expired_at']) ? fbgAdminServersSafeDate($entry['new_expired_at']) : 'None',
                'source' => fbgServerExpirationSourceLabel((string)($entry['source'] ?? '')),
                'changed_by' => trim((string)($entry['changed_by_label'] ?? '')) !== '' ? (string)$entry['changed_by_label'] : 'System',
            ];
        }, fbgGetServerExpirationHistoryEntries($serverId));

        fbgAdminServersJsonResponse([
            'ok' => true,
            'entries' => $entries,
        ]);
    }

    if ($action === 'restore_expiration') {
        $currentExpiredAt = fbgNormalizeExpirationHistoryValue((string)($server['expired_at'] ?? ''));
        $lastKnownExpiration = fbgGetServerLastKnownExpiration($serverId, $currentExpiredAt);

        if ($lastKnownExpiration === null) {
            fbgAdminServersRedirect('No prior expiration date is available to restore.', 'error', $serverId, 'details');
        }

        $restoreStmt = fbgPteroDb()->prepare('
            UPDATE servers
            SET expired_at = :expired_at,
                updated_at = NOW()
            WHERE id = :id
        ');
        $restoreStmt->execute([
            'expired_at' => $lastKnownExpiration,
            'id' => $serverId,
        ]);

        $actor = fbgCurrentExpirationHistoryActor();
        fbgTryRecordServerExpirationHistory(
            $serverId,
            'admin_restore',
            'admin_restore_control',
            $currentExpiredAt,
            $lastKnownExpiration,
            $actor['user_id'] ?? null,
            $actor['label'] ?? null
        );

        fbgAdminServersRedirect('Expiration date restored from history.', 'success', $serverId, 'details');
    }

    if ($action === 'update_details') {
        $name = trim((string)($_POST['name'] ?? ''));
        $externalId = trim((string)($_POST['external_id'] ?? ''));
        $productId = max(0, (int)($_POST['product_id'] ?? 0));
        $description = trim((string)($_POST['description'] ?? ''));
        $ownerInput = trim((string)($_POST['owner_search'] ?? ''));
        $ownerId = fbgAdminServersOwnerIdFromInput($ownerInput);
        $expiredAt = fbgAdminServersFormatExpirationForDb((string)($_POST['expired_at'] ?? ''));
        $overrideConfirmed = max(0, (int)($_POST['expiration_override_confirmed'] ?? 0)) === 1;
        $oldExpiredAt = fbgNormalizeExpirationHistoryValue((string)($server['expired_at'] ?? ''));
        $oldProductId = max(0, (int)($server['product_id'] ?? 0));

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

        if ($productId > 0 && $expiredAt === null && !$overrideConfirmed) {
            $warningMessage = $oldExpiredAt !== null
                ? 'You are about to remove the expiration date from a shop-linked server.'
                : 'This shop-linked server is currently missing an expiration date.';

            fbgAdminServersRedirect($warningMessage . ' Confirm the override to save without an expiration.', 'error', $serverId, 'details');
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

        $actor = fbgCurrentExpirationHistoryActor();
        $shouldLogExpirationChange =
            $oldExpiredAt !== $expiredAt
            || ($productId > 0 && $expiredAt === null && $overrideConfirmed)
            || ($oldProductId > 0 && $productId === 0 && $oldExpiredAt !== $expiredAt);

        if ($shouldLogExpirationChange) {
            $historyAction = ($oldExpiredAt !== null && $expiredAt === null)
                ? 'admin_clear'
                : 'admin_edit';

            fbgTryRecordServerExpirationHistory(
                $serverId,
                $historyAction,
                'admin_server_editor',
                $oldExpiredAt,
                $expiredAt,
                $actor['user_id'] ?? null,
                $actor['label'] ?? null
            );
        }

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

    if ($action === 'update_startup') {
        $startupCommand = trim((string)($_POST['startup'] ?? ''));
        $nestId = max(0, (int)($_POST['nest_id'] ?? 0));
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        $dockerImageSelect = trim((string)($_POST['docker_image'] ?? ''));
        $dockerImageCustom = trim((string)($_POST['docker_image_custom'] ?? ''));
        $dockerImage = $dockerImageCustom !== '' ? $dockerImageCustom : $dockerImageSelect;
        $skipScripts = isset($_POST['skip_scripts']);

        if ($startupCommand === '') {
            fbgAdminServersRedirect('Startup command is required.', 'error', $serverId, 'startup');
        }

        if ($nestId <= 0 || $eggId <= 0) {
            fbgAdminServersRedirect('Select a valid nest and egg.', 'error', $serverId, 'startup');
        }

        $eggStmt = fbgPteroDb()->prepare('
            SELECT id, nest_id, name, startup, docker_images
            FROM eggs
            WHERE id = :egg_id
              AND nest_id = :nest_id
            LIMIT 1
        ');
        $eggStmt->execute([
            'egg_id' => $eggId,
            'nest_id' => $nestId,
        ]);
        $egg = $eggStmt->fetch(PDO::FETCH_ASSOC);

        if (!$egg) {
            fbgAdminServersRedirect('Selected egg does not belong to the selected nest.', 'error', $serverId, 'startup');
        }

        if ($dockerImage === '') {
            $dockerImages = fbgAdminServersDockerImagesMap($egg['docker_images'] ?? '');
            $dockerImage = (string)array_key_first($dockerImages);
        }

        if ($dockerImage === '') {
            fbgAdminServersRedirect('Docker image is required.', 'error', $serverId, 'startup');
        }

        $variablesStmt = fbgPteroDb()->prepare('
            SELECT env_variable, default_value
            FROM egg_variables
            WHERE egg_id = :egg_id
            ORDER BY name ASC
        ');
        $variablesStmt->execute(['egg_id' => $eggId]);
        $postedEnvironment = is_array($_POST['environment'] ?? null) ? $_POST['environment'] : [];
        $environment = [];

        foreach (($variablesStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $variable) {
            $key = trim((string)($variable['env_variable'] ?? ''));
            if ($key === '') {
                continue;
            }

            $environment[$key] = array_key_exists($key, $postedEnvironment)
                ? (string)$postedEnvironment[$key]
                : (string)($variable['default_value'] ?? '');
        }

        $result = pteroRequest('PATCH', "servers/{$serverId}/startup", [
            'startup' => $startupCommand,
            'environment' => $environment,
            'egg' => $eggId,
            'image' => $dockerImage,
            'skip_scripts' => $skipScripts,
        ]);

        if (empty($result['ok'])) {
            fbgAdminServersRedirect((string)($result['error'] ?? 'Server startup configuration could not be updated.'), 'error', $serverId, 'startup');
        }

        fbgAdminServersRedirect('Server startup configuration updated successfully.', 'success', $serverId, 'startup');
    }

    if ($action === 'create_database') {
        $databaseHostId = max(0, (int)($_POST['database_host_id'] ?? 0));
        $databaseName = trim((string)($_POST['database_name'] ?? ''));
        $remote = trim((string)($_POST['remote'] ?? '%'));

        if ($databaseHostId <= 0) {
            fbgAdminServersRedirect('Select a valid database host.', 'error', $serverId, 'database');
        }

        $serverDatabaseLimit = $server['database_limit'] !== null ? (int)$server['database_limit'] : null;
        if ($serverDatabaseLimit !== null) {
            $databaseCountStmt = fbgPteroDb()->prepare('SELECT COUNT(*) FROM `databases` WHERE server_id = :server_id');
            $databaseCountStmt->execute(['server_id' => $serverId]);
            $serverDatabaseCount = (int)$databaseCountStmt->fetchColumn();

            if ($serverDatabaseCount >= $serverDatabaseLimit) {
                $limitLabel = $serverDatabaseLimit === 1 ? '1 database' : $serverDatabaseLimit . ' databases';
                fbgAdminServersRedirect("This server is already at its database limit of {$limitLabel}. Increase the Database Limit on the Build Configuration tab first.", 'error', $serverId, 'database');
            }
        }

        if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $databaseName)) {
            fbgAdminServersRedirect('Database name may only contain letters, numbers, dashes, and underscores.', 'error', $serverId, 'database');
        }

        $prefix = 's' . $serverId . '_';
        if (strlen($prefix . $databaseName) > 48) {
            fbgAdminServersRedirect('Database name is too long for this server prefix.', 'error', $serverId, 'database');
        }

        if ($remote === '') {
            $remote = '%';
        }

        if (!preg_match('/^[0-9%.]{1,15}$/', $remote)) {
            fbgAdminServersRedirect('Connections From must be a valid IP address pattern or % wildcard.', 'error', $serverId, 'database');
        }

        $hostStmt = fbgPteroDb()->prepare('SELECT id FROM database_hosts WHERE id = :id LIMIT 1');
        $hostStmt->execute(['id' => $databaseHostId]);
        if ((int)($hostStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminServersRedirect('Selected database host could not be found.', 'error', $serverId, 'database');
        }

        $result = pteroDatabaseManagementRequest('POST', "servers/{$serverId}/databases", [
            'database' => $databaseName,
            'remote' => $remote,
            'host' => $databaseHostId,
        ]);

        if (empty($result['ok'])) {
            fbgAdminServersRedirect(fbgAdminServersDatabaseApiError($result, 'Database could not be created.'), 'error', $serverId, 'database');
        }

        fbgAdminServersRedirect('Database created successfully.', 'success', $serverId, 'database');
    }

    if ($action === 'reset_database_password' || $action === 'delete_database') {
        $databaseId = max(0, (int)($_POST['database_id'] ?? 0));

        if ($databaseId <= 0) {
            fbgAdminServersRedirect('Select a valid database.', 'error', $serverId, 'database');
        }

        $databaseStmt = fbgPteroDb()->prepare('
            SELECT id, database
            FROM `databases`
            WHERE id = :id
              AND server_id = :server_id
            LIMIT 1
        ');
        $databaseStmt->execute([
            'id' => $databaseId,
            'server_id' => $serverId,
        ]);
        $database = $databaseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$database) {
            fbgAdminServersRedirect('Selected database could not be found for this server.', 'error', $serverId, 'database');
        }

        if ($action === 'reset_database_password') {
            $result = pteroDatabaseManagementRequest('POST', "servers/{$serverId}/databases/{$databaseId}/reset-password");

            if (empty($result['ok'])) {
                fbgAdminServersRedirect(fbgAdminServersDatabaseApiError($result, 'Database password could not be reset.'), 'error', $serverId, 'database');
            }

            fbgAdminServersRedirect('Database password reset successfully.', 'success', $serverId, 'database');
        }

        $result = pteroDatabaseManagementRequest('DELETE', "servers/{$serverId}/databases/{$databaseId}");

        if (empty($result['ok'])) {
            fbgAdminServersRedirect(fbgAdminServersDatabaseApiError($result, 'Database could not be deleted.'), 'error', $serverId, 'database');
        }

        fbgAdminServersRedirect('Database deleted successfully.', 'success', $serverId, 'database');
    }

    if ($action === 'attach_mount' || $action === 'detach_mount') {
        $mountId = max(0, (int)($_POST['mount_id'] ?? 0));

        if ($mountId <= 0) {
            fbgAdminServersRedirect('Select a valid mount.', 'error', $serverId, 'mounts');
        }

        $mountStmt = fbgPteroDb()->prepare('
            SELECT m.id, m.name
            FROM mounts m
            INNER JOIN egg_mount em ON em.mount_id = m.id AND em.egg_id = :egg_id
            INNER JOIN mount_node mn ON mn.mount_id = m.id AND mn.node_id = :node_id
            WHERE m.id = :mount_id
            LIMIT 1
        ');
        $mountStmt->execute([
            'egg_id' => (int)$server['egg_id'],
            'node_id' => (int)$server['node_id'],
            'mount_id' => $mountId,
        ]);
        $mount = $mountStmt->fetch(PDO::FETCH_ASSOC);

        if (!$mount) {
            fbgAdminServersRedirect('Selected mount is not available for this server.', 'error', $serverId, 'mounts');
        }

        $mountedStmt = fbgPteroDb()->prepare('
            SELECT 1
            FROM mount_server
            WHERE server_id = :server_id
              AND mount_id = :mount_id
            LIMIT 1
        ');
        $mountedStmt->execute([
            'server_id' => $serverId,
            'mount_id' => $mountId,
        ]);
        $isMounted = (bool)$mountedStmt->fetchColumn();

        if ($action === 'attach_mount') {
            if ($isMounted) {
                fbgAdminServersRedirect('This mount is already attached to the server.', 'error', $serverId, 'mounts');
            }

            $attachStmt = fbgPteroDb()->prepare('
                INSERT INTO mount_server (mount_id, server_id)
                VALUES (:mount_id, :server_id)
            ');
            $attachStmt->execute([
                'mount_id' => $mountId,
                'server_id' => $serverId,
            ]);

            fbgAdminServersRedirect('Mount attached successfully.', 'success', $serverId, 'mounts');
        }

        if (!$isMounted) {
            fbgAdminServersRedirect('This mount is not currently attached to the server.', 'error', $serverId, 'mounts');
        }

        $detachStmt = fbgPteroDb()->prepare('
            DELETE FROM mount_server
            WHERE mount_id = :mount_id
              AND server_id = :server_id
        ');
        $detachStmt->execute([
            'mount_id' => $mountId,
            'server_id' => $serverId,
        ]);

        fbgAdminServersRedirect('Mount removed successfully.', 'success', $serverId, 'mounts');
    }

    if ($action === 'reinstall_server') {
        $result = pteroReinstallServer($serverId);
        if (empty($result['ok'])) {
            fbgAdminServersRedirect((string)($result['error'] ?? 'Server reinstall could not be started.'), 'error', $serverId, 'manage');
        }

        fbgAdminServersRedirect('Server reinstall started successfully.', 'success', $serverId, 'manage');
    }

    if ($action === 'toggle_install_status') {
        $currentStatus = strtolower(trim((string)($server['status'] ?? '')));

        if ($currentStatus === 'install_failed') {
            fbgAdminServersRedirect('Install status cannot be toggled while the server is marked as install failed.', 'error', $serverId, 'manage');
        }

        $nextStatus = $currentStatus === 'installing' ? null : 'installing';
        $toggleInstallStmt = fbgPteroDb()->prepare('
            UPDATE servers
            SET status = :status,
                updated_at = NOW()
            WHERE id = :id
        ');
        $toggleInstallStmt->bindValue(':status', $nextStatus, $nextStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $toggleInstallStmt->bindValue(':id', $serverId, PDO::PARAM_INT);
        $toggleInstallStmt->execute();

        fbgAdminServersRedirect(
            $nextStatus === 'installing'
                ? 'Server marked as installing.'
                : 'Server marked as installed.',
            'success',
            $serverId,
            'manage'
        );
    }

    if ($action === 'toggle_suspend_server') {
        $currentStatus = strtolower(trim((string)($server['status'] ?? '')));
        $isSuspended = $currentStatus === 'suspended';
        $result = $isSuspended
            ? pteroUnsuspendServer($serverId)
            : pteroRequest('POST', "servers/{$serverId}/suspend");

        if (empty($result['ok'])) {
            fbgAdminServersRedirect((string)($result['error'] ?? 'Suspension state could not be changed.'), 'error', $serverId, 'manage');
        }

        if (function_exists('fbgEnsurePteroServersSuspendManualColumn') && fbgEnsurePteroServersSuspendManualColumn()) {
            $manualSuspendStmt = fbgPteroDb()->prepare('
                UPDATE servers
                SET suspend_manual = :suspend_manual
                WHERE id = :id
            ');
            $manualSuspendStmt->execute([
                ':suspend_manual' => $isSuspended ? 0 : 1,
                ':id' => $serverId,
            ]);
        }

        fbgAdminServersRedirect(
            $isSuspended ? 'Server unsuspended successfully.' : 'Server suspended successfully.',
            'success',
            $serverId,
            'manage'
        );
    }

    if ($action === 'transfer_server') {
        fbgAdminServersRedirect('Transfer execution has not been reconnected yet. The transfer modal UI is available, but the backend workflow still needs to be wired back in.', 'error', $serverId, 'manage');
    }

    if ($action === 'delete_server') {
        $result = pteroRequest('DELETE', "servers/{$serverId}");
        if (empty($result['ok'])) {
            fbgAdminServersRedirect(
                fbgAdminServersServerApiError($result, 'The server could not be safely deleted.'),
                'error',
                $serverId,
                'delete'
            );
        }

        fbgAdminServersRedirect('Server deleted successfully.', 'success');
    }

    if ($action === 'force_delete_server') {
        $result = pteroRequest('DELETE', "servers/{$serverId}/force");
        if (empty($result['ok'])) {
            fbgAdminServersRedirect(
                fbgAdminServersServerApiError($result, 'The server could not be forcibly deleted.'),
                'error',
                $serverId,
                'delete'
            );
        }

        fbgAdminServersRedirect('Server force deleted successfully.', 'success');
    }

    fbgAdminServersRedirect('Unknown server action.', 'error', $serverId);
}

$editServerId = max(0, (int)($_GET['edit'] ?? 0));
$createMode = max(0, (int)($_GET['create'] ?? 0)) === 1;
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
$nestOptions = [];
$eggOptions = [];
$startupEggData = [];
$databaseHosts = [];
$serverDatabases = [];
$serverMounts = [];
$transferNodes = [];
$transferAllocationMap = [];
$expirationHistoryCount = 0;
$lastKnownExpiration = null;
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

    $nestStmt = fbgPteroDb()->query('
        SELECT id, name
        FROM nests
        ORDER BY name ASC
    ');
    $nestOptions = $nestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $serverVariableStmt = fbgPteroDb()->prepare('
        SELECT variable_id, variable_value
        FROM server_variables
        WHERE server_id = :server_id
    ');
    $serverVariableStmt->execute(['server_id' => (int)$editingServer['id']]);
    $serverVariableValues = [];
    foreach (($serverVariableStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $serverVariable) {
        $serverVariableValues[(int)$serverVariable['variable_id']] = (string)($serverVariable['variable_value'] ?? '');
    }

    $eggStmt = fbgPteroDb()->query('
        SELECT
            e.id,
            e.nest_id,
            e.name,
            e.startup,
            e.docker_images,
            n.name AS nest_name
        FROM eggs e
        LEFT JOIN nests n ON n.id = e.nest_id
        ORDER BY n.name ASC, e.name ASC
    ');

    foreach (($eggStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $eggOption) {
        $eggId = (int)$eggOption['id'];
        $eggOptions[] = $eggOption;
        $startupEggData[$eggId] = [
            'id' => $eggId,
            'nest_id' => (int)$eggOption['nest_id'],
            'name' => (string)($eggOption['name'] ?? ''),
            'startup' => (string)($eggOption['startup'] ?? ''),
            'docker_images' => fbgAdminServersDockerImagesMap($eggOption['docker_images'] ?? ''),
            'variables' => [],
        ];
    }

    $eggVariableStmt = fbgPteroDb()->query('
        SELECT id, egg_id, name, description, env_variable, default_value, user_viewable, user_editable, rules
        FROM egg_variables
        ORDER BY name ASC
    ');

    foreach (($eggVariableStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $variable) {
        $eggId = (int)($variable['egg_id'] ?? 0);
        if (!isset($startupEggData[$eggId])) {
            continue;
        }

        $variableId = (int)$variable['id'];
        $value = (int)$editingServer['egg_id'] === $eggId && array_key_exists($variableId, $serverVariableValues)
            ? $serverVariableValues[$variableId]
            : (string)($variable['default_value'] ?? '');

        $startupEggData[$eggId]['variables'][] = [
            'id' => $variableId,
            'name' => (string)($variable['name'] ?? ''),
            'description' => (string)($variable['description'] ?? ''),
            'env_variable' => (string)($variable['env_variable'] ?? ''),
            'default_value' => (string)($variable['default_value'] ?? ''),
            'value' => $value,
            'user_viewable' => (int)($variable['user_viewable'] ?? 0) === 1,
            'user_editable' => (int)($variable['user_editable'] ?? 0) === 1,
            'rules' => (string)($variable['rules'] ?? ''),
        ];
    }

    $databaseHostStmt = fbgPteroDb()->query('
        SELECT
            dh.id,
            dh.name,
            dh.host,
            dh.port,
            dh.max_databases,
            COUNT(d.id) AS database_count
        FROM database_hosts dh
        LEFT JOIN `databases` d ON d.database_host_id = dh.id
        GROUP BY dh.id, dh.name, dh.host, dh.port, dh.max_databases
        ORDER BY dh.name ASC, dh.host ASC
    ');
    $databaseHosts = $databaseHostStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $serverDatabasesStmt = fbgPteroDb()->prepare('
        SELECT
            d.id,
            d.database,
            d.username,
            d.remote,
            d.max_connections,
            d.created_at,
            dh.name AS host_name,
            dh.host AS host_address,
            dh.port AS host_port
        FROM `databases` d
        LEFT JOIN database_hosts dh ON dh.id = d.database_host_id
        WHERE d.server_id = :server_id
        ORDER BY d.database ASC
    ');
    $serverDatabasesStmt->execute(['server_id' => (int)$editingServer['id']]);
    $serverDatabases = $serverDatabasesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $mountStmt = fbgPteroDb()->prepare('
        SELECT
            m.id,
            m.name,
            m.source,
            m.target,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM mount_server ms
                    WHERE ms.server_id = :server_id
                      AND ms.mount_id = m.id
                ) THEN 1
                ELSE 0
            END AS is_mounted
        FROM mounts m
        INNER JOIN egg_mount em ON em.mount_id = m.id AND em.egg_id = :egg_id
        INNER JOIN mount_node mn ON mn.mount_id = m.id AND mn.node_id = :node_id
        ORDER BY m.name ASC, m.id ASC
    ');
    $mountStmt->execute([
        'server_id' => (int)$editingServer['id'],
        'egg_id' => (int)$editingServer['egg_id'],
        'node_id' => (int)$editingServer['node_id'],
    ]);
    $serverMounts = $mountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $transferNodesStmt = fbgPteroDb()->prepare('
        SELECT id, name
        FROM nodes
        WHERE id != :node_id
        ORDER BY name ASC
    ');
    $transferNodesStmt->execute(['node_id' => (int)$editingServer['node_id']]);
    $transferNodes = $transferNodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!empty($transferNodes)) {
        $transferAllocationStmt = fbgPteroDb()->prepare('
            SELECT id, node_id, ip, ip_alias, port, notes
            FROM allocations
            WHERE server_id IS NULL
              AND node_id = :node_id
            ORDER BY COALESCE(ip_alias, ip) ASC, port ASC
        ');

        foreach ($transferNodes as $transferNode) {
            $nodeId = (int)($transferNode['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $transferAllocationStmt->execute(['node_id' => $nodeId]);
            $transferAllocationMap[$nodeId] = $transferAllocationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $expirationHistoryCount = fbgGetServerExpirationHistoryCount((int)$editingServer['id']);
    $currentExpirationValue = fbgNormalizeExpirationHistoryValue((string)($editingServer['expired_at'] ?? ''));
    $lastKnownExpiration = fbgGetServerLastKnownExpiration((int)$editingServer['id'], $currentExpirationValue);
}

$createOwnerOptions = [];
$createNestOptions = [];
$createEggOptions = [];
$createStartupEggData = [];
$createNodeOptions = [];
$createAllocationMap = [];

$createOwnerStmt = fbgPteroDb()->query("
    SELECT id, username, email, name_first, name_last
    FROM users
    ORDER BY name_first ASC, name_last ASC, username ASC
");
$createOwnerOptions = $createOwnerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$createNestStmt = fbgPteroDb()->query('
    SELECT id, name
    FROM nests
    ORDER BY name ASC
');
$createNestOptions = $createNestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$createEggStmt = fbgPteroDb()->query('
    SELECT
        e.id,
        e.nest_id,
        e.name,
        e.startup,
        e.docker_images
    FROM eggs e
    ORDER BY e.nest_id ASC, e.name ASC
');

foreach (($createEggStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $eggOption) {
    $eggId = (int)$eggOption['id'];
    $createEggOptions[] = $eggOption;
    $createStartupEggData[$eggId] = [
        'id' => $eggId,
        'nest_id' => (int)$eggOption['nest_id'],
        'name' => (string)($eggOption['name'] ?? ''),
        'startup' => (string)($eggOption['startup'] ?? ''),
        'docker_images' => fbgAdminServersDockerImagesMap($eggOption['docker_images'] ?? ''),
        'variables' => [],
    ];
}

$createEggVariableStmt = fbgPteroDb()->query('
    SELECT id, egg_id, name, description, env_variable, default_value, user_viewable, user_editable, rules
    FROM egg_variables
    ORDER BY egg_id ASC, name ASC
');

foreach (($createEggVariableStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $variable) {
    $eggId = (int)($variable['egg_id'] ?? 0);
    if (!isset($createStartupEggData[$eggId])) {
        continue;
    }

    $createStartupEggData[$eggId]['variables'][] = [
        'id' => (int)$variable['id'],
        'name' => (string)($variable['name'] ?? ''),
        'description' => (string)($variable['description'] ?? ''),
        'env_variable' => (string)($variable['env_variable'] ?? ''),
        'default_value' => (string)($variable['default_value'] ?? ''),
        'value' => (string)($variable['default_value'] ?? ''),
        'user_viewable' => (int)($variable['user_viewable'] ?? 0) === 1,
        'user_editable' => (int)($variable['user_editable'] ?? 0) === 1,
        'rules' => (string)($variable['rules'] ?? ''),
    ];
}

$createNodeStmt = fbgPteroDb()->query("
    SELECT
        n.id,
        n.name,
        COUNT(a.id) AS allocation_count
    FROM nodes n
    LEFT JOIN allocations a
        ON a.node_id = n.id
       AND a.server_id IS NULL
    GROUP BY n.id, n.name
    HAVING allocation_count > 0
    ORDER BY n.name ASC
");
$createNodeOptions = $createNodeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!empty($createNodeOptions)) {
    $createAllocationStmt = fbgPteroDb()->prepare('
        SELECT id, node_id, ip, ip_alias, port, notes
        FROM allocations
        WHERE node_id = :node_id
          AND server_id IS NULL
        ORDER BY COALESCE(ip_alias, ip) ASC, port ASC
    ');

    foreach ($createNodeOptions as $createNode) {
        $createNodeId = (int)($createNode['id'] ?? 0);
        if ($createNodeId <= 0) {
            continue;
        }

        $createAllocationStmt->execute(['node_id' => $createNodeId]);
        $createAllocationMap[$createNodeId] = $createAllocationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$nodeFilter = max(0, (int)($_GET['node_id'] ?? 0));
$sort = strtolower(trim((string)($_GET['sort'] ?? 'name')));
$direction = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$perPage = 25;
$pageNum = fbgPaginationRequestedPage();
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
$pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$totalPages = $pagination['total_pages'];
$offset = $pagination['offset'];

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
                <div class="fbg-admin-panel-header" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <h2>Servers</h2>
                    <a class="btn" href="<?= htmlspecialchars(fbgAdminServersBaseQuery(['create' => 1, 'edit' => null, 'tab' => null]), ENT_QUOTES, 'UTF-8') ?>">Create Server</a>
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

                <?php fbgRenderPagination($pagination, 'server'); ?>
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

<?php if ($createMode): ?>
    <div class="fbg-modal-overlay" id="admin-server-create-modal">
        <div class="fbg-modal-card fbg-admin-server-modal" role="dialog" aria-modal="true" aria-labelledby="admin-server-create-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="<?= htmlspecialchars(fbgAdminServersBaseQuery(['create' => null, 'edit' => null, 'tab' => null]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-server-create-title">Create Server</h3>
                <p>Add a new server to the panel with Pterodactyl-managed resources, startup settings, and service variables.</p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 18px;">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="fbg-admin-form" id="admin-server-create-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_server">

                <div class="fbg-admin-server-detail-list">
                    <h3>Core Details</h3>
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="create-server-name">Server Name</label>
                            <input id="create-server-name" name="create_name" type="text" required maxlength="191" placeholder="Server Name">
                            <p class="fbg-admin-help-text">Character limits are handled by Pterodactyl. Keep the name clear and user-friendly.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-description">Server Description</label>
                            <textarea id="create-server-description" name="create_description" rows="4" placeholder="A short description of this server."></textarea>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-owner">Server Owner</label>
                            <input id="create-server-owner" name="create_owner_search" type="text" list="admin-server-create-owner-options" autocomplete="off" required placeholder="Start typing a name, username, or email">
                            <datalist id="admin-server-create-owner-options"></datalist>
                            <script type="application/json" id="admin-server-create-owner-source">
                                <?= json_encode(array_map(static fn(array $owner): array => [
                                    'label' => fbgAdminServersOwnerOptionLabel($owner),
                                    'terms' => [
                                        strtolower((string)($owner['username'] ?? '')),
                                        strtolower((string)($owner['email'] ?? '')),
                                        strtolower((string)($owner['name_first'] ?? '')),
                                        strtolower((string)($owner['name_last'] ?? '')),
                                        strtolower(trim((string)($owner['name_first'] ?? '') . ' ' . (string)($owner['name_last'] ?? ''))),
                                    ],
                                ], $createOwnerOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                            </script>
                            <p class="fbg-admin-help-text">Start typing at least 2 characters, then pick the owner from the list.</p>
                        </div>

                        <div class="fbg-admin-field" style="justify-content: flex-end;">
                            <label class="fbg-admin-checkbox" style="margin-top: 24px;">
                                <input type="checkbox" name="create_start_on_completion" value="1" checked>
                                <span>Start Server when Installed</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                    <h3>Allocation Management</h3>
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="create-server-node">Node</label>
                            <select id="create-server-node" name="create_node_id" required>
                                <option value="">Select a node</option>
                                <?php foreach ($createNodeOptions as $nodeOption): ?>
                                    <option value="<?= (int)$nodeOption['id'] ?>">
                                        <?= htmlspecialchars((string)$nodeOption['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">Only nodes with at least one unassigned port are listed here.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-allocation">Default Port</label>
                            <select id="create-server-allocation" name="create_allocation_id" required disabled>
                                <option value="">Select a default port</option>
                            </select>
                            <p class="fbg-admin-help-text">This is the main allocation that will be assigned to the server.</p>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="create-server-additional-allocations">Additional Port(s)</label>
                            <select id="create-server-additional-allocations" name="create_allocation_additional[]" multiple size="8" disabled></select>
                            <p class="fbg-admin-help-text">Optional additional ports to assign during creation. Hold Ctrl to select more than one.</p>
                        </div>
                    </div>

                    <script type="application/json" id="admin-server-create-allocation-map">
                        <?= json_encode($createAllocationMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                    </script>
                </div>

                <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                    <h3>Application Feature Limits</h3>
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="create-server-database-limit">Database Limit</label>
                            <input id="create-server-database-limit" name="create_database_limit" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">The total number of databases a user is allowed to create for this server.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-allocation-limit">Port Limit</label>
                            <input id="create-server-allocation-limit" name="create_allocation_limit" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">The total number of allocations a user is allowed to create for this server.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-backup-limit">Backup Limit</label>
                            <input id="create-server-backup-limit" name="create_backup_limit" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">The total number of backups that can be created for this server.</p>
                        </div>
                    </div>
                </div>

                <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                    <h3>Resource Management</h3>
                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="create-server-cpu">CPU Limit (%)</label>
                            <input id="create-server-cpu" name="create_cpu" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">Set to 0 for unlimited CPU time.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-threads">CPU Pinning</label>
                            <input id="create-server-threads" name="create_threads" type="text" placeholder="0-3,8">
                            <p class="fbg-admin-help-text">Leave blank to allow all cores, or enter a comma-separated list / range.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-memory">Memory (MiB)</label>
                            <input id="create-server-memory" name="create_memory" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">Set to 0 for unlimited memory.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-swap">Swap (MiB)</label>
                            <input id="create-server-swap" name="create_swap" type="number" min="-1" required value="0">
                            <p class="fbg-admin-help-text">0 disables swap. -1 allows unlimited swap.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-disk">Disk Space (MiB)</label>
                            <input id="create-server-disk" name="create_disk" type="number" min="0" required value="0">
                            <p class="fbg-admin-help-text">Set to 0 for unlimited disk usage.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-io">Block IO Weight</label>
                            <input id="create-server-io" name="create_io" type="number" min="10" max="1000" required value="500">
                            <p class="fbg-admin-help-text">Advanced. Value should be between 10 and 1000.</p>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label class="fbg-admin-checkbox fbg-admin-checkbox-block">
                                <input type="checkbox" name="create_enable_oom_killer" value="1" checked>
                                <span>Enable OOM Killer</span>
                            </label>
                            <p class="fbg-admin-help-text">When enabled, the server may be stopped automatically if it exceeds its memory limit.</p>
                        </div>
                    </div>
                </div>

                <div class="fbg-admin-server-about-grid" style="margin-top: 16px;">
                    <div class="fbg-admin-server-detail-list">
                        <h3>Nest Configuration</h3>
                        <div class="fbg-admin-field">
                            <label for="create-server-nest">Nest</label>
                            <select id="create-server-nest" name="create_nest_id" required>
                                <option value="">Select a nest</option>
                                <?php foreach ($createNestOptions as $nestOption): ?>
                                    <option value="<?= (int)$nestOption['id'] ?>"><?= htmlspecialchars((string)$nestOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">Select the Nest that this server will be grouped under.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-egg">Egg</label>
                            <select id="create-server-egg" name="create_egg_id" required disabled>
                                <option value="">Select an egg</option>
                            </select>
                            <p class="fbg-admin-help-text">Select the Egg that defines how this server should operate.</p>
                        </div>

                        <label class="fbg-admin-checkbox fbg-admin-checkbox-block" style="margin-top: 18px;">
                            <input type="checkbox" name="create_skip_scripts" value="1">
                            <span>Skip Egg Install Script</span>
                        </label>
                        <p class="fbg-admin-help-text">If selected, the install script attached to the egg will not run during installation.</p>
                    </div>

                    <div class="fbg-admin-server-detail-list">
                        <h3>Docker Configuration</h3>
                        <div class="fbg-admin-field">
                            <label for="create-server-docker-image">Docker Image</label>
                            <select id="create-server-docker-image" name="create_docker_image" disabled>
                                <option value="">Select a docker image</option>
                            </select>
                            <p class="fbg-admin-help-text">This is the default docker image used to run the server.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="create-server-custom-image">Custom Docker Image</label>
                            <input id="create-server-custom-image" name="create_custom_image" type="text" placeholder="Or enter a custom image...">
                            <p class="fbg-admin-help-text">If provided, this overrides the selected docker image.</p>
                        </div>
                    </div>
                </div>

                <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                    <h3>Startup Configuration</h3>
                    <div class="fbg-admin-field">
                        <label for="create-server-startup">Startup Command</label>
                        <input id="create-server-startup" name="create_startup" type="text" required>
                        <p class="fbg-admin-help-text">The selected egg will populate this automatically. You can adjust it before creation if needed.</p>
                    </div>

                    <div id="admin-server-create-startup-variables" class="fbg-admin-form-grid" style="margin-top: 18px;"></div>
                    <script type="application/json" id="admin-server-create-egg-options">
                        <?= json_encode($createEggOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                    </script>
                    <script type="application/json" id="admin-server-create-egg-data">
                        <?= json_encode($createStartupEggData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                    </script>
                </div>

                <div class="fbg-admin-form-actions" style="margin-top: 24px;">
                    <a class="btn fbg-neutral-button" href="<?= htmlspecialchars(fbgAdminServersBaseQuery(['create' => null, 'edit' => null, 'tab' => null]), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
                    <button type="submit" class="btn">Create Server</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

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

            <?php if ($message !== ''): ?>
                <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 18px;">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

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
                    <input type="hidden" name="expiration_override_confirmed" id="server-detail-expiration-override" value="0">

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
                            <input
                                id="server-detail-expiration"
                                name="expired_at"
                                type="datetime-local"
                                data-initial-expiration="<?= htmlspecialchars(fbgAdminServersDatetimeLocalValue($editingServer['expired_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                value="<?= htmlspecialchars(fbgAdminServersDatetimeLocalValue($editingServer['expired_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <p class="fbg-admin-help-text">Saved to the shop/plugin expiration field. Leave blank if the server does not expire.</p>
                            <?php if ($lastKnownExpiration !== null): ?>
                                <div class="fbg-admin-help-text" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 8px;">
                                    <span>Last known expiration: <strong><?= htmlspecialchars(fbgAdminServersSafeDate($lastKnownExpiration), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                    <button type="submit" class="btn fbg-neutral-button" name="action" value="restore_expiration">Restore Last Known Expiration</button>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top: 12px;">
                                <button
                                    type="button"
                                    class="btn fbg-neutral-button"
                                    id="server-expiration-log-toggle"
                                    data-server-id="<?= (int)$editingServer['id'] ?>"
                                    aria-expanded="false"
                                    aria-controls="server-expiration-log-panel"
                                >
                                    Expiration Log (<?= (int)$expirationHistoryCount ?>)
                                </button>
                            </div>
                            <div id="server-expiration-log-panel" hidden data-loaded="0" class="fbg-admin-server-detail-list fbg-admin-server-expiration-log-panel" style="margin-top: 16px;">
                                <div class="fbg-admin-empty-state">
                                    <p>Loading expiration history...</p>
                                </div>
                            </div>
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
                                    <label class="fbg-admin-checkbox fbg-admin-checkbox-block">
                                        <input type="checkbox" name="oom_disabled" value="1" <?= (int)($editingServer['oom_disabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                                        <span>Disable OOM Killer</span>
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

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'startup' ? ' is-active' : '' ?>" data-admin-server-panel="startup" <?= $activeServerTab === 'startup' ? '' : 'hidden' ?>>
                <form method="POST" class="fbg-admin-form" id="admin-server-startup-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_startup">
                    <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">

                    <div class="fbg-admin-server-detail-list">
                        <h3>Startup Command Modification</h3>
                        <div class="fbg-admin-field">
                            <label for="server-startup-command">Startup Command</label>
                            <input id="server-startup-command" name="startup" type="text" required value="<?= htmlspecialchars((string)($editingServer['startup'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-last-default="<?= htmlspecialchars((string)($editingServer['egg_startup'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <p class="fbg-admin-help-text">Edit this server's startup command. Common variables include <code>{{SERVER_MEMORY}}</code>, <code>{{SERVER_IP}}</code>, and <code>{{SERVER_PORT}}</code>.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="server-startup-default-command">Default Service Start Command</label>
                            <input id="server-startup-default-command" type="text" value="<?= htmlspecialchars((string)($editingServer['egg_startup'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                    </div>

                    <div class="fbg-admin-server-about-grid" style="margin-top: 16px;">
                        <div>
                            <div class="fbg-admin-server-detail-list">
                                <h3>Service Configuration</h3>
                                <p class="fbg-admin-help-text" style="color: #ff7777;">Changing the nest or egg can trigger a reinstall workflow and may overwrite server files. Use Skip Egg Install Script only when you know the service scripts should not run.</p>

                                <div class="fbg-admin-field">
                                    <label for="server-startup-nest">Nest</label>
                                    <select id="server-startup-nest" name="nest_id" required>
                                        <?php foreach ($nestOptions as $nestOption): ?>
                                            <option value="<?= (int)$nestOption['id'] ?>" <?= (int)$editingServer['nest_id'] === (int)$nestOption['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$nestOption['name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="fbg-admin-help-text">Select the Nest this server will be grouped into.</p>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-startup-egg">Egg</label>
                                    <select id="server-startup-egg" name="egg_id" required>
                                        <?php foreach ($eggOptions as $eggOption): ?>
                                            <option value="<?= (int)$eggOption['id'] ?>" data-nest-id="<?= (int)$eggOption['nest_id'] ?>" <?= (int)$editingServer['egg_id'] === (int)$eggOption['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)($eggOption['name'] ?? 'Unknown Egg'), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="fbg-admin-help-text">Select the Egg that provides startup variables and processing data.</p>
                                </div>

                                <label class="fbg-admin-checkbox fbg-admin-checkbox-block" style="margin-top: 18px;">
                                    <input type="checkbox" name="skip_scripts" value="1">
                                    <span>Skip Egg Install Script</span>
                                </label>
                                <p class="fbg-admin-help-text">If selected, the install script attached to the egg will not run.</p>
                            </div>

                            <div class="fbg-admin-server-detail-list" style="margin-top: 16px;">
                                <h3>Docker Image Configuration</h3>
                                <div class="fbg-admin-field">
                                    <label for="server-startup-docker-image">Image</label>
                                    <select id="server-startup-docker-image" name="docker_image"></select>
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="server-startup-docker-custom">Custom Image</label>
                                    <input id="server-startup-docker-custom" name="docker_image_custom" type="text" value="">
                                    <p class="fbg-admin-help-text">Leave blank to use the selected Docker image above.</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div id="admin-server-startup-variables"></div>
                        </div>
                    </div>

                    <script type="application/json" id="admin-server-startup-egg-data">
                        <?= json_encode($startupEggData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                    </script>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Save Startup Configuration</button>
                        <a class="btn fbg-neutral-button" href="./page.php?name=admin-servers&edit=<?= (int)$editingServer['id'] ?>&tab=startup">Cancel</a>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'database' ? ' is-active' : '' ?>" data-admin-server-panel="database" <?= $activeServerTab === 'database' ? '' : 'hidden' ?>>
                <div class="fbg-admin-server-about-grid">
                    <div>
                        <div class="fbg-dashboard-alert is-visible" style="margin-bottom: 18px;">
                            Database passwords can be viewed when visiting this server on the frontend panel.
                        </div>

                        <div class="fbg-admin-server-detail-list">
                            <h3>Active Databases</h3>
                            <div class="fbg-admin-table-wrap fbg-admin-database-table-wrap">
                                <table class="fbg-admin-table">
                                    <thead>
                                        <tr>
                                            <th>Database</th>
                                            <th>Username</th>
                                            <th>Connections From</th>
                                            <th>Host</th>
                                            <th>Max Connections</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($serverDatabases)): ?>
                                            <tr>
                                                <td colspan="6">No databases are assigned to this server.</td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($serverDatabases as $database): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$database['database'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$database['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$database['remote'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <code><?= htmlspecialchars(trim((string)($database['host_address'] ?? '') . ':' . (string)($database['host_port'] ?? ''), ':'), ENT_QUOTES, 'UTF-8') ?></code>
                                                </td>
                                                <td>
                                                    <?php $maxConnections = (int)($database['max_connections'] ?? 0); ?>
                                                    <?= $maxConnections > 0 ? number_format($maxConnections) : 'Unlimited' ?>
                                                </td>
                                                <td>
                                                    <details class="fbg-admin-row-menu">
                                                        <summary aria-label="Database actions">
                                                            <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                                        </summary>
                                                        <div class="fbg-admin-row-menu-dropdown">
                                                            <form method="POST">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="action" value="reset_database_password">
                                                                <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                                                                <input type="hidden" name="database_id" value="<?= (int)$database['id'] ?>">
                                                                <button type="submit">
                                                                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                                                                    Reset Password
                                                                </button>
                                                            </form>

                                                            <form method="POST" onsubmit="return confirm('Delete database <?= htmlspecialchars((string)$database['database'], ENT_QUOTES, 'UTF-8') ?>? This cannot be undone.');">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="action" value="delete_database">
                                                                <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                                                                <input type="hidden" name="database_id" value="<?= (int)$database['id'] ?>">
                                                                <button type="submit" class="danger">
                                                                    <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                                                    Delete Database
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </details>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div>
                        <form method="POST" class="fbg-admin-server-detail-list">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="create_database">
                            <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">

                            <h3>Create New Database</h3>

                            <?php
                            $serverDatabaseLimit = $editingServer['database_limit'] !== null ? (int)$editingServer['database_limit'] : null;
                            $serverDatabaseCount = count($serverDatabases);
                            $databaseLimitReached = $serverDatabaseLimit !== null && $serverDatabaseCount >= $serverDatabaseLimit;
                            $hasAvailableDatabaseHost = false;
                            foreach ($databaseHosts as $databaseHostCheck) {
                                $maxDatabasesCheck = $databaseHostCheck['max_databases'] !== null ? (int)$databaseHostCheck['max_databases'] : null;
                                $databaseCountCheck = (int)($databaseHostCheck['database_count'] ?? 0);
                                if ($maxDatabasesCheck === null || $databaseCountCheck < $maxDatabasesCheck) {
                                    $hasAvailableDatabaseHost = true;
                                    break;
                                }
                            }
                            $canCreateDatabase = !$databaseLimitReached && $hasAvailableDatabaseHost;
                            ?>

                            <?php if ($databaseLimitReached): ?>
                                <div class="fbg-dashboard-alert error is-visible" style="margin-bottom: 18px;">
                                    This server is at its database limit. Increase the Database Limit on the Build Configuration tab before creating another database.
                                </div>
                            <?php elseif (!$hasAvailableDatabaseHost): ?>
                                <div class="fbg-dashboard-alert error is-visible" style="margin-bottom: 18px;">
                                    No database hosts are currently available.
                                </div>
                            <?php endif; ?>

                            <div class="fbg-admin-field">
                                <label for="server-database-host">Database Host</label>
                                <select id="server-database-host" name="database_host_id" required>
                                    <?php foreach ($databaseHosts as $databaseHost): ?>
                                        <?php
                                        $maxDatabases = $databaseHost['max_databases'] !== null ? (int)$databaseHost['max_databases'] : null;
                                        $databaseCount = (int)($databaseHost['database_count'] ?? 0);
                                        $hostFull = $maxDatabases !== null && $databaseCount >= $maxDatabases;
                                        ?>
                                        <option value="<?= (int)$databaseHost['id'] ?>" <?= $hostFull ? 'disabled' : '' ?>>
                                            <?= htmlspecialchars(fbgAdminServersDatabaseHostLabel($databaseHost), ENT_QUOTES, 'UTF-8') ?><?= $hostFull ? ' (Full)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="fbg-admin-help-text">Select the database server this database should be created on.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="server-database-name">Database</label>
                                <div style="display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px;">
                                    <input type="text" value="s<?= (int)$editingServer['id'] ?>_" readonly aria-label="Database prefix">
                                    <input id="server-database-name" name="database_name" type="text" required placeholder="database" maxlength="<?= max(1, 48 - strlen('s' . (int)$editingServer['id'] . '_')) ?>">
                                </div>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="server-database-remote">Connections</label>
                                <input id="server-database-remote" name="remote" type="text" value="%" required>
                                <p class="fbg-admin-help-text">IP address pattern connections are allowed from. Use <code>%</code> to allow any host.</p>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="server-database-max-connections">Concurrent Connections</label>
                                <input id="server-database-max-connections" type="text" value="Unlimited" disabled>
                                <p class="fbg-admin-help-text">The Application API creates databases with unlimited concurrent connections. Custom limits can be added later with a dedicated database helper.</p>
                            </div>

                            <p class="fbg-admin-help-text">A username and password will be randomly generated after form submission.</p>

                            <div class="fbg-admin-form-actions">
                                <button type="submit" class="btn" <?= $canCreateDatabase ? '' : 'disabled' ?>>Create Database</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'mounts' ? ' is-active' : '' ?>" data-admin-server-panel="mounts" <?= $activeServerTab === 'mounts' ? '' : 'hidden' ?>>
                <div class="fbg-admin-server-detail-list">
                    <h3>Available Mounts</h3>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Source</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($serverMounts)): ?>
                                    <tr>
                                        <td colspan="6">No mounts are available for this server's current egg and node.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($serverMounts as $mount): ?>
                                    <?php $isMounted = (int)($mount['is_mounted'] ?? 0) === 1; ?>
                                    <tr>
                                        <td><code><?= (int)$mount['id'] ?></code></td>
                                        <td><?= htmlspecialchars((string)($mount['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><code><?= htmlspecialchars((string)($mount['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code><?= htmlspecialchars((string)($mount['target'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td>
                                            <span class="fbg-admin-status-pill <?= fbgAdminServersMountStatusClass($isMounted) ?>">
                                                <?= $isMounted ? 'Mounted' : 'Unmounted' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" class="fbg-admin-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                                                <input type="hidden" name="mount_id" value="<?= (int)$mount['id'] ?>">
                                                <input type="hidden" name="action" value="<?= $isMounted ? 'detach_mount' : 'attach_mount' ?>">
                                                <button
                                                    type="submit"
                                                    class="fbg-admin-mount-button <?= $isMounted ? 'is-detach' : 'is-attach' ?>"
                                                    aria-label="<?= $isMounted ? 'Unmount ' : 'Mount ' ?><?= htmlspecialchars((string)($mount['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    title="<?= $isMounted ? 'Unmount' : 'Mount' ?>"
                                                >
                                                    <?= $isMounted ? 'Unmount' : 'Mount' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'manage' ? ' is-active' : '' ?>" data-admin-server-panel="manage" <?= $activeServerTab === 'manage' ? '' : 'hidden' ?>>
                <?php
                $manageStatus = strtolower(trim((string)($editingServer['status'] ?? '')));
                $manageIsSuspended = $manageStatus === 'suspended';
                $manageIsInstalling = $manageStatus === 'installing';
                ?>
                <div class="fbg-admin-server-about-grid">
                    <div class="fbg-admin-server-detail-list" style="border-top: 2px solid #ef4444;">
                        <h3>Reinstall Server</h3>
                        <p>This will reinstall the server with the assigned service scripts. Danger! This could overwrite server data.</p>
                        <div class="fbg-admin-form-actions" style="justify-content: flex-start;">
                            <button type="button" class="btn danger-action" id="admin-server-open-reinstall-modal">Reinstall Server</button>
                        </div>
                    </div>

                    <div class="fbg-admin-server-detail-list" style="border-top: 2px solid #1e88ff;">
                        <h3>Install Status</h3>
                        <p>If you need to change the install status from uninstalled to installed, or vice versa, you may do so with the button below.</p>
                        <form method="POST" class="fbg-admin-form-actions" style="justify-content: flex-start;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="toggle_install_status">
                            <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                            <button type="submit" class="btn">
                                <?= $manageIsInstalling ? 'Mark as Installed' : 'Mark as Installing' ?>
                            </button>
                        </form>
                    </div>

                    <div class="fbg-admin-server-detail-list" style="border-top: 2px solid #f59e0b;">
                        <h3><?= $manageIsSuspended ? 'Unsuspend Server' : 'Suspend Server' ?></h3>
                        <p>This will <?= $manageIsSuspended ? 'restore access to' : 'suspend' ?> the server<?= $manageIsSuspended ? ' and re-enable panel/API access.' : ', stop any running processes, and immediately block the user from managing it through the panel or API.' ?></p>
                        <form method="POST" class="fbg-admin-form-actions" style="justify-content: flex-start;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="toggle_suspend_server">
                            <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                            <button type="submit" class="btn warn-action">
                                <?= $manageIsSuspended ? 'Unsuspend Server' : 'Suspend Server' ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="fbg-admin-server-detail-list" style="margin-top: 16px; border-top: 2px solid #22c55e;">
                    <h3>Transfer Server</h3>
                    <p>Transfer this server to another node connected to this panel. Warning! This feature has not been fully tested and may have bugs.</p>
                    <div class="fbg-admin-form-actions" style="justify-content: flex-start;">
                        <button type="button" class="btn" id="admin-server-open-transfer-modal" style="background:#16a34a;">Transfer Server</button>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-server-tab-panel<?= $activeServerTab === 'delete' ? ' is-active' : '' ?>" data-admin-server-panel="delete" <?= $activeServerTab === 'delete' ? '' : 'hidden' ?>>
                <div class="fbg-admin-server-about-grid">
                    <div class="fbg-admin-server-detail-list" style="border-top: 2px solid #ef4444;">
                        <h3>Safely Delete Server</h3>
                        <p>This action will attempt to delete the server from both the panel and daemon. If either one reports an error the action will be cancelled.</p>
                        <div class="fbg-dashboard-alert error is-visible" style="margin: 14px 0 18px;">
                            Deleting a server is an irreversible action. All server data, including files and users, will be removed from the system.
                        </div>
                        <div class="fbg-admin-form-actions" style="justify-content: flex-start;">
                            <button type="button" class="btn danger-action" id="admin-server-open-safe-delete-modal">Safely Delete This Server</button>
                        </div>
                    </div>

                    <div class="fbg-admin-server-detail-list" style="border-top: 2px solid #ef4444;">
                        <h3>Force Delete Server</h3>
                        <p>This action will attempt to delete the server from both the panel and daemon. If the daemon does not respond, or reports an error, the deletion will continue.</p>
                        <div class="fbg-dashboard-alert error is-visible" style="margin: 14px 0 18px;">
                            Deleting a server is an irreversible action. All server data, including files and users, will be removed from the system. This method may leave dangling files on your daemon if it reports an error.
                        </div>
                        <div class="fbg-admin-form-actions" style="justify-content: flex-start;">
                            <button type="button" class="btn danger-action" id="admin-server-open-force-delete-modal">Forcibly Delete This Server</button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="fbg-modal-overlay" id="admin-server-reinstall-modal" hidden>
                <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-server-reinstall-title">
                    <button type="button" class="fbg-modal-close fbg-admin-user-modal-close" data-close-admin-server-modal="reinstall" aria-label="Close">X</button>
                    <div class="fbg-modal-header">
                        <h3 id="admin-server-reinstall-title">Confirm Reinstall</h3>
                        <p>This will stop the server and re-run its installation script. Files may be deleted or modified during this process.</p>
                    </div>
                    <div class="fbg-dashboard-alert error is-visible" style="margin-bottom: 18px;">
                        This is a destructive action. Make sure any needed files are backed up first.
                    </div>
                    <form method="POST" class="fbg-admin-form-actions" style="justify-content: flex-end;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reinstall_server">
                        <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                        <button type="button" class="btn fbg-neutral-button" data-close-admin-server-modal="reinstall">Cancel</button>
                        <button type="submit" class="btn danger-action">Yes, Reinstall Server</button>
                    </form>
                </div>
            </div>

            <div class="fbg-modal-overlay" id="admin-server-safe-delete-modal" hidden>
                <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-server-safe-delete-title">
                    <button type="button" class="fbg-modal-close fbg-admin-user-modal-close" data-close-admin-server-modal="safe-delete" aria-label="Close">X</button>
                    <div class="fbg-modal-header">
                        <h3 id="admin-server-safe-delete-title">Safely Delete Server</h3>
                        <p>This will try to remove the server from both the panel and daemon. If either one fails, the deletion will be cancelled.</p>
                    </div>
                    <div class="fbg-admin-warning-box">
                        This is irreversible. All server files, users, and related data will be removed immediately if the delete succeeds.
                    </div>
                    <form method="POST" class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_server">
                        <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                        <button type="button" class="btn fbg-neutral-button" data-close-admin-server-modal="safe-delete">Cancel</button>
                        <button type="submit" class="btn danger-action">Delete Server</button>
                    </form>
                </div>
            </div>

            <div class="fbg-modal-overlay" id="admin-server-force-delete-modal" hidden>
                <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-server-force-delete-title">
                    <button type="button" class="fbg-modal-close fbg-admin-user-modal-close" data-close-admin-server-modal="force-delete" aria-label="Close">X</button>
                    <div class="fbg-modal-header">
                        <h3 id="admin-server-force-delete-title">Force Delete Server</h3>
                        <p>This will continue deleting the server even if the daemon errors or does not respond.</p>
                    </div>
                    <div class="fbg-admin-warning-box">
                        This is irreversible. All server files, users, and related data will be removed immediately. Force delete may leave dangling files behind on the daemon if it reports an error.
                    </div>
                    <form method="POST" class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="force_delete_server">
                        <input type="hidden" name="server_id" value="<?= (int)$editingServer['id'] ?>">
                        <button type="button" class="btn fbg-neutral-button" data-close-admin-server-modal="force-delete">Cancel</button>
                        <button type="submit" class="btn danger-action">Force Delete Server</button>
                    </form>
                </div>
            </div>

            <div class="fbg-modal-overlay" id="admin-server-transfer-modal" hidden>
                <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-server-transfer-title">
                    <button type="button" class="fbg-modal-close fbg-admin-user-modal-close" data-close-admin-server-modal="transfer" aria-label="Close">X</button>
                    <div class="fbg-modal-header">
                        <h3 id="admin-server-transfer-title">Transfer Server</h3>
                        <p>Select the destination node and ports for this transfer.</p>
                    </div>

                    <div class="fbg-admin-form">
                        <div class="fbg-admin-field">
                            <label for="admin-server-transfer-node">Node</label>
                            <select id="admin-server-transfer-node">
                                <option value="">Select a node</option>
                                <?php foreach ($transferNodes as $transferNode): ?>
                                    <option value="<?= (int)$transferNode['id'] ?>"><?= htmlspecialchars((string)$transferNode['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">The node which this server will be transferred to.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="admin-server-transfer-allocation">Default Port</label>
                            <select id="admin-server-transfer-allocation" disabled>
                                <option value="">Select a default port</option>
                            </select>
                            <p class="fbg-admin-help-text">The main port that will be assigned to this server.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="admin-server-transfer-additional-allocations">Additional Port(s)</label>
                            <select id="admin-server-transfer-additional-allocations" multiple size="6" disabled></select>
                            <p class="fbg-admin-help-text">Additional ports to assign to this server on transfer.</p>
                        </div>

                        <div class="fbg-dashboard-alert" style="display:block; margin-top: 16px;">
                            Transfer execution has not been reconnected yet. This restores the modal UI so we can finish wiring the backend workflow cleanly next.
                        </div>

                        <script type="application/json" id="admin-server-transfer-allocations">
                            <?= json_encode($transferAllocationMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                        </script>

                        <div class="fbg-admin-form-actions" style="justify-content: flex-end; margin-top: 18px;">
                            <button type="button" class="btn fbg-neutral-button" data-close-admin-server-modal="transfer">Cancel</button>
                            <button type="button" class="btn" disabled>Confirm</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fbg-modal-overlay" id="admin-server-expiration-confirm-modal" hidden>
                <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-server-expiration-confirm-title">
                    <button type="button" class="fbg-modal-close fbg-admin-user-modal-close" data-close-admin-server-modal="expiration-confirm" aria-label="Close">X</button>
                    <div class="fbg-modal-header">
                        <h3 id="admin-server-expiration-confirm-title">Confirm Missing Expiration</h3>
                        <p id="admin-server-expiration-confirm-message">This shop-linked server is currently missing an expiration date.</p>
                    </div>
                    <div class="fbg-admin-warning-box">
                        Saving a shop-linked server without an expiration date can leave renewal handling in an unsafe state. Cancel if you want to keep or restore a valid expiration.
                    </div>
                    <div class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                        <button type="button" class="btn fbg-neutral-button" data-close-admin-server-modal="expiration-confirm">Cancel</button>
                        <button type="button" class="btn danger-action" id="admin-server-expiration-confirm-save">Save Without Expiration</button>
                    </div>
                </div>
            </div>

            <?php foreach (array_diff(array_keys($tabs), ['about', 'details', 'build', 'startup', 'database', 'mounts', 'manage', 'delete']) as $tabKey): ?>
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
    const detailsForm = modal.querySelector('section[data-admin-server-panel="details"] form');
    const productSelect = document.getElementById('server-detail-product-id');
    const expirationInput = document.getElementById('server-detail-expiration');
    const expirationOverrideInput = document.getElementById('server-detail-expiration-override');
    const expirationLogToggle = document.getElementById('server-expiration-log-toggle');
    const expirationLogPanel = document.getElementById('server-expiration-log-panel');
    const startupEggSource = document.getElementById('admin-server-startup-egg-data');
    const startupNestSelect = document.getElementById('server-startup-nest');
    const startupEggSelect = document.getElementById('server-startup-egg');
    const startupCommandInput = document.getElementById('server-startup-command');
    const startupDefaultInput = document.getElementById('server-startup-default-command');
    const startupDockerSelect = document.getElementById('server-startup-docker-image');
    const startupDockerCustom = document.getElementById('server-startup-docker-custom');
    const startupVariablesWrap = document.getElementById('admin-server-startup-variables');
    const reinstallModal = document.getElementById('admin-server-reinstall-modal');
    const safeDeleteModal = document.getElementById('admin-server-safe-delete-modal');
    const forceDeleteModal = document.getElementById('admin-server-force-delete-modal');
    const transferModal = document.getElementById('admin-server-transfer-modal');
    const openReinstallModalButton = document.getElementById('admin-server-open-reinstall-modal');
    const openSafeDeleteModalButton = document.getElementById('admin-server-open-safe-delete-modal');
    const openForceDeleteModalButton = document.getElementById('admin-server-open-force-delete-modal');
    const openTransferModalButton = document.getElementById('admin-server-open-transfer-modal');
    const transferNodeSelect = document.getElementById('admin-server-transfer-node');
    const transferAllocationSelect = document.getElementById('admin-server-transfer-allocation');
    const transferAdditionalSelect = document.getElementById('admin-server-transfer-additional-allocations');
    const transferAllocationsSource = document.getElementById('admin-server-transfer-allocations');
    const expirationConfirmModal = document.getElementById('admin-server-expiration-confirm-modal');
    const expirationConfirmMessage = document.getElementById('admin-server-expiration-confirm-message');
    const expirationConfirmButton = document.getElementById('admin-server-expiration-confirm-save');
    const csrfToken = '<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>';

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

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

            if (target === 'details' && expirationLogPanel && expirationLogPanel.dataset.prefetched !== '1') {
                expirationLogPanel.dataset.prefetched = '1';
            }
        });
    });

    const overlays = [reinstallModal, safeDeleteModal, forceDeleteModal, transferModal, expirationConfirmModal].filter(Boolean);
    const syncModalOpenState = () => {
        const hasOpenOverlay = overlays.some((overlay) => !overlay.hidden);
        document.body.classList.toggle('fbg-modal-open', hasOpenOverlay || !modal.hidden);
    };

    const openOverlay = (overlay) => {
        if (!overlay) return;
        overlay.hidden = false;
        syncModalOpenState();
    };

    const closeOverlay = (overlay) => {
        if (!overlay) return;
        overlay.hidden = true;
        if (overlay === expirationConfirmModal && expirationOverrideInput) {
            expirationOverrideInput.value = '0';
        }
        syncModalOpenState();
    };

    if (openReinstallModalButton && reinstallModal) {
        openReinstallModalButton.addEventListener('click', () => openOverlay(reinstallModal));
    }

    if (openSafeDeleteModalButton && safeDeleteModal) {
        openSafeDeleteModalButton.addEventListener('click', () => openOverlay(safeDeleteModal));
    }

    if (openForceDeleteModalButton && forceDeleteModal) {
        openForceDeleteModalButton.addEventListener('click', () => openOverlay(forceDeleteModal));
    }

    if (openTransferModalButton && transferModal) {
        openTransferModalButton.addEventListener('click', () => openOverlay(transferModal));
    }

    modal.querySelectorAll('[data-close-admin-server-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-close-admin-server-modal');
            if (target === 'reinstall') closeOverlay(reinstallModal);
            if (target === 'safe-delete') closeOverlay(safeDeleteModal);
            if (target === 'force-delete') closeOverlay(forceDeleteModal);
            if (target === 'transfer') closeOverlay(transferModal);
            if (target === 'expiration-confirm') closeOverlay(expirationConfirmModal);
        });
    });

    overlays.forEach((overlay) => {
        if (!overlay) return;
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                closeOverlay(overlay);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const openOverlay = overlays.find((overlay) => !overlay.hidden);
        if (openOverlay) {
            event.preventDefault();
            closeOverlay(openOverlay);
            return;
        }

        window.location.href = './page.php?name=admin-servers';
    });

    const loadExpirationHistory = async () => {
        if (!expirationLogToggle || !expirationLogPanel) {
            return;
        }

        const serverId = expirationLogToggle.dataset.serverId;
        if (!serverId) {
            return;
        }

        if (expirationLogPanel.dataset.loaded === '1') {
            return;
        }

        expirationLogPanel.innerHTML = '<div class="fbg-admin-empty-state"><p>Loading expiration history...</p></div>';

        try {
            const body = new URLSearchParams();
            body.set('csrf_token', csrfToken);
            body.set('action', 'fetch_expiration_history');
            body.set('server_id', serverId);

            const response = await fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: body.toString(),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Expiration history could not be loaded.');
            }

            const entries = Array.isArray(data.entries) ? data.entries : [];
            if (!entries.length) {
                expirationLogPanel.innerHTML = '<div class="fbg-admin-empty-state"><p>No expiration history recorded.</p></div>';
                expirationLogPanel.dataset.loaded = '1';
                return;
            }

            expirationLogPanel.innerHTML = `
                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Action</th>
                                <th>Previous Expiration</th>
                                <th>New Expiration</th>
                                <th>Source</th>
                                <th>Changed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${entries.map((entry) => `
                                <tr>
                                    <td>${escapeHtml(entry.created_at || '-')}</td>
                                    <td>${escapeHtml(entry.action || '-')}</td>
                                    <td>${escapeHtml(entry.previous_expiration || 'None')}</td>
                                    <td>${escapeHtml(entry.new_expiration || 'None')}</td>
                                    <td>${escapeHtml(entry.source || '-')}</td>
                                    <td>${escapeHtml(entry.changed_by || 'System')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            expirationLogPanel.dataset.loaded = '1';
        } catch (error) {
            const errorMessage = error && typeof error.message === 'string'
                ? error.message
                : 'Expiration history could not be loaded.';
            expirationLogPanel.innerHTML = `<div class="fbg-admin-empty-state"><p>${escapeHtml(errorMessage)}</p></div>`;
        }
    };

    if (expirationLogToggle && expirationLogPanel) {
        expirationLogToggle.addEventListener('click', async () => {
            const isExpanded = expirationLogToggle.getAttribute('aria-expanded') === 'true';
            expirationLogToggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
            expirationLogPanel.hidden = isExpanded;

            if (!isExpanded) {
                await loadExpirationHistory();
            }
        });
    }

    if (detailsForm && productSelect && expirationInput && expirationOverrideInput && expirationConfirmModal && expirationConfirmMessage) {
        detailsForm.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (submitter && submitter.name === 'action' && submitter.value === 'restore_expiration') {
                return;
            }

            const productId = parseInt(productSelect.value || '0', 10);
            const expirationValue = String(expirationInput.value || '').trim();
            const overrideValue = expirationOverrideInput.value === '1';

            if (productId > 0 && expirationValue === '' && !overrideValue) {
                event.preventDefault();
                const hadInitialExpiration = String(expirationInput.dataset.initialExpiration || '').trim() !== '';
                expirationConfirmMessage.textContent = hadInitialExpiration
                    ? 'You are about to remove the expiration date from a shop-linked server.'
                    : 'This shop-linked server is currently missing an expiration date.';
                openOverlay(expirationConfirmModal);
            }
        });
    }

    if (expirationConfirmButton && detailsForm && expirationOverrideInput) {
        expirationConfirmButton.addEventListener('click', () => {
            expirationOverrideInput.value = '1';
            if (expirationConfirmModal) {
                expirationConfirmModal.hidden = true;
                syncModalOpenState();
            }
            if (typeof detailsForm.requestSubmit === 'function') {
                detailsForm.requestSubmit();
                return;
            }
            detailsForm.submit();
        });
    }

    if (transferNodeSelect && transferAllocationSelect && transferAdditionalSelect && transferAllocationsSource) {
        let transferAllocationsByNode = {};

        try {
            transferAllocationsByNode = JSON.parse(transferAllocationsSource.textContent || '{}');
        } catch (error) {
            transferAllocationsByNode = {};
        }

        const renderTransferAllocations = () => {
            const nodeId = transferNodeSelect.value;
            const allocations = Array.isArray(transferAllocationsByNode[nodeId]) ? transferAllocationsByNode[nodeId] : [];

            transferAllocationSelect.innerHTML = '<option value="">Select a default port</option>';
            transferAdditionalSelect.innerHTML = '';

            if (!nodeId || allocations.length === 0) {
                transferAllocationSelect.disabled = true;
                transferAdditionalSelect.disabled = true;
                return;
            }

            allocations.forEach((allocation, index) => {
                const host = String(allocation.ip_alias || allocation.ip || '').trim();
                const port = String(allocation.port || '').trim();
                const notes = String(allocation.notes || '').trim();
                const label = `${host}:${port}${notes ? ' - ' + notes : ''}`;

                const defaultOption = document.createElement('option');
                defaultOption.value = String(allocation.id || '');
                defaultOption.textContent = label;
                if (index === 0) {
                    defaultOption.selected = true;
                }
                transferAllocationSelect.appendChild(defaultOption);
            });

            allocations.forEach((allocation) => {
                const host = String(allocation.ip_alias || allocation.ip || '').trim();
                const port = String(allocation.port || '').trim();
                const notes = String(allocation.notes || '').trim();
                const label = `${host}:${port}${notes ? ' - ' + notes : ''}`;

                const option = document.createElement('option');
                option.value = String(allocation.id || '');
                option.textContent = label;
                transferAdditionalSelect.appendChild(option);
            });

            transferAllocationSelect.disabled = false;
            transferAdditionalSelect.disabled = false;
        };

        transferNodeSelect.addEventListener('change', renderTransferAllocations);
        transferAllocationSelect.addEventListener('change', () => {
            const selectedDefault = transferAllocationSelect.value;
            Array.from(transferAdditionalSelect.options).forEach((option) => {
                option.hidden = option.value === selectedDefault;
                if (option.value === selectedDefault) {
                    option.selected = false;
                }
            });
        });
    }

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

    if (startupEggSource && startupNestSelect && startupEggSelect && startupVariablesWrap) {
        let eggData = {};

        try {
            eggData = JSON.parse(startupEggSource.textContent || '{}');
        } catch (error) {
            eggData = {};
        }

        const isBooleanVariable = (variable) => {
            const rules = String(variable.rules || '').toLowerCase();
            return rules.includes('boolean') || rules.includes('bool');
        };

        const isCheckedValue = (value) => ['1', 'true', 'yes', 'on'].includes(String(value ?? '').toLowerCase());

        const renderStartupVariables = (egg) => {
            const variables = Array.isArray(egg?.variables) ? egg.variables : [];

            if (!variables.length) {
                startupVariablesWrap.innerHTML = `
                    <div class="fbg-admin-server-detail-list">
                        <h3>Startup Parameters</h3>
                        <div class="fbg-admin-empty-state">
                            <p>No startup variables are configured for this egg.</p>
                        </div>
                    </div>
                `;
                return;
            }

            startupVariablesWrap.innerHTML = `
                <div class="fbg-admin-server-detail-list">
                    <h3>Startup Parameters</h3>
                    ${variables.map((variable) => {
                        const key = String(variable.env_variable || '');
                        const name = String(variable.name || key || 'Startup Variable');
                        const value = String(variable.value ?? variable.default_value ?? '');
                        const description = String(variable.description || '');
                        const rules = String(variable.rules || '');
                        const inputName = `environment[${escapeHtml(key)}]`;

                        return `
                            <div class="fbg-admin-field" style="margin-bottom: 18px;">
                                <label>${escapeHtml(name)}</label>
                                ${
                                    isBooleanVariable(variable)
                                        ? `
                                            <label style="display: flex; align-items: center; gap: 10px;">
                                                <input type="hidden" name="${inputName}" value="0">
                                                <input type="checkbox" name="${inputName}" value="1" ${isCheckedValue(value) ? 'checked' : ''}>
                                                <span>${isCheckedValue(value) ? 'Enabled' : 'Disabled'}</span>
                                            </label>
                                        `
                                        : `<input type="text" name="${inputName}" value="${escapeHtml(value)}">`
                                }
                                ${description ? `<p class="fbg-admin-help-text">${escapeHtml(description)}</p>` : ''}
                                <p class="fbg-admin-help-text">Startup Command Variable: <code>${escapeHtml(key)}</code></p>
                                ${rules ? `<p class="fbg-admin-help-text">Input Rules: <code>${escapeHtml(rules)}</code></p>` : ''}
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        };

        const syncEggOptions = () => {
            const nestId = startupNestSelect.value;
            let selectedVisible = false;
            let firstVisible = null;

            Array.from(startupEggSelect.options).forEach((option) => {
                const visible = option.dataset.nestId === nestId;
                option.hidden = !visible;
                option.disabled = !visible;

                if (visible && firstVisible === null) {
                    firstVisible = option;
                }

                if (visible && option.selected) {
                    selectedVisible = true;
                }
            });

            if (!selectedVisible && firstVisible) {
                firstVisible.selected = true;
            }
        };

        const applyEggData = (replaceStartupCommand = false, preferCurrentImage = false) => {
            const egg = eggData[startupEggSelect.value] || {};
            const defaultStartup = String(egg.startup || '');

            if (startupDefaultInput) {
                startupDefaultInput.value = defaultStartup;
            }

            if (startupCommandInput) {
                const previousDefault = startupCommandInput.dataset.lastDefault || '';
                if (replaceStartupCommand || startupCommandInput.value === '' || startupCommandInput.value === previousDefault) {
                    startupCommandInput.value = defaultStartup;
                }
                startupCommandInput.dataset.lastDefault = defaultStartup;
            }

            if (startupDockerSelect) {
                const currentValue = startupDockerSelect.value;
                startupDockerSelect.replaceChildren();

                const dockerImages = egg.docker_images && typeof egg.docker_images === 'object' ? egg.docker_images : {};
                Object.entries(dockerImages).forEach(([image, label]) => {
                    const option = document.createElement('option');
                    option.value = image;
                    option.textContent = label || image;
                    startupDockerSelect.appendChild(option);
                });

                if (currentValue && Array.from(startupDockerSelect.options).some((option) => option.value === currentValue)) {
                    startupDockerSelect.value = currentValue;
                }
            }

            if (startupDockerCustom && startupDockerSelect && preferCurrentImage) {
                const hasSelectedCurrentImage = Array.from(startupDockerSelect.options).some((option) => option.value === <?= json_encode((string)($editingServer['image'] ?? '')) ?>);
                if (hasSelectedCurrentImage) {
                    startupDockerSelect.value = <?= json_encode((string)($editingServer['image'] ?? '')) ?>;
                    startupDockerCustom.value = '';
                } else if (startupDockerCustom.value === '') {
                    startupDockerCustom.value = <?= json_encode((string)($editingServer['image'] ?? '')) ?>;
                }
            } else if (startupDockerCustom) {
                startupDockerCustom.value = '';
            }

            renderStartupVariables(egg);
        };

        startupNestSelect.addEventListener('change', () => {
            syncEggOptions();
            applyEggData(true, false);
        });

        startupEggSelect.addEventListener('change', () => {
            applyEggData(true, false);
        });

        syncEggOptions();
        applyEggData(false, true);
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const createModal = document.getElementById('admin-server-create-modal');
    if (!createModal) return;

    document.body.classList.add('fbg-modal-open');

    const ownerInput = document.getElementById('create-server-owner');
    const ownerDatalist = document.getElementById('admin-server-create-owner-options');
    const ownerSource = document.getElementById('admin-server-create-owner-source');
    const nodeSelect = document.getElementById('create-server-node');
    const allocationSelect = document.getElementById('create-server-allocation');
    const additionalAllocationsSelect = document.getElementById('create-server-additional-allocations');
    const allocationMapSource = document.getElementById('admin-server-create-allocation-map');
    const nestSelect = document.getElementById('create-server-nest');
    const eggSelect = document.getElementById('create-server-egg');
    const eggOptionsSource = document.getElementById('admin-server-create-egg-options');
    const eggDataSource = document.getElementById('admin-server-create-egg-data');
    const startupInput = document.getElementById('create-server-startup');
    const dockerSelect = document.getElementById('create-server-docker-image');
    const dockerCustomInput = document.getElementById('create-server-custom-image');
    const variablesWrap = document.getElementById('admin-server-create-startup-variables');

    let owners = [];
    let allocationMap = {};
    let eggOptions = [];
    let eggData = {};

    try {
        owners = JSON.parse(ownerSource?.textContent || '[]');
    } catch (error) {
        owners = [];
    }

    try {
        allocationMap = JSON.parse(allocationMapSource?.textContent || '{}');
    } catch (error) {
        allocationMap = {};
    }

    try {
        eggOptions = JSON.parse(eggOptionsSource?.textContent || '[]');
    } catch (error) {
        eggOptions = [];
    }

    try {
        eggData = JSON.parse(eggDataSource?.textContent || '{}');
    } catch (error) {
        eggData = {};
    }

    if (ownerInput && ownerDatalist) {
        const renderOwnerOptions = (query = '') => {
            const normalized = query.trim().toLowerCase();
            const matches = normalized.length < 2
                ? []
                : owners.filter((owner) => Array.isArray(owner.terms) && owner.terms.some((term) => term.includes(normalized))).slice(0, 25);

            ownerDatalist.innerHTML = '';
            matches.forEach((owner) => {
                const option = document.createElement('option');
                option.value = owner.label || '';
                ownerDatalist.appendChild(option);
            });
        };

        ownerInput.addEventListener('input', () => renderOwnerOptions(ownerInput.value));
        ownerInput.addEventListener('focus', () => renderOwnerOptions(ownerInput.value));
    }

    const syncAdditionalAllocationVisibility = () => {
        if (!allocationSelect || !additionalAllocationsSelect) return;

        const selectedDefault = allocationSelect.value;
        Array.from(additionalAllocationsSelect.options).forEach((option) => {
            const isDefault = option.value === selectedDefault;
            option.hidden = isDefault;
            if (isDefault) {
                option.selected = false;
            }
        });
    };

    const syncAllocations = () => {
        if (!nodeSelect || !allocationSelect || !additionalAllocationsSelect) return;

        const nodeId = nodeSelect.value;
        const allocations = Array.isArray(allocationMap[nodeId]) ? allocationMap[nodeId] : [];

        allocationSelect.innerHTML = '<option value="">Select a default port</option>';
        additionalAllocationsSelect.innerHTML = '';

        if (!nodeId || allocations.length === 0) {
            allocationSelect.disabled = true;
            additionalAllocationsSelect.disabled = true;
            return;
        }

        allocations.forEach((allocation, index) => {
            const host = String(allocation.ip_alias || allocation.ip || '').trim();
            const port = String(allocation.port || '').trim();
            const notes = String(allocation.notes || '').trim();
            const label = `${host}:${port}${notes ? ' - ' + notes : ''}`;

            const defaultOption = document.createElement('option');
            defaultOption.value = String(allocation.id || '');
            defaultOption.textContent = label;
            if (index === 0) {
                defaultOption.selected = true;
            }
            allocationSelect.appendChild(defaultOption);

            const additionalOption = document.createElement('option');
            additionalOption.value = String(allocation.id || '');
            additionalOption.textContent = label;
            additionalAllocationsSelect.appendChild(additionalOption);
        });

        allocationSelect.disabled = false;
        additionalAllocationsSelect.disabled = false;
        syncAdditionalAllocationVisibility();
    };

    const renderCreateVariables = (egg) => {
        if (!variablesWrap) return;
        variablesWrap.innerHTML = '';

        const variables = Array.isArray(egg?.variables) ? egg.variables : [];
        if (variables.length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'fbg-admin-empty-state';
            emptyState.innerHTML = '<p>This egg does not expose any editable service variables.</p>';
            variablesWrap.appendChild(emptyState);
            return;
        }

        variables.forEach((variable) => {
            const field = document.createElement('div');
            field.className = 'fbg-admin-field';

            const label = document.createElement('label');
            label.textContent = variable.name || variable.env_variable || 'Service Variable';

            const input = document.createElement('input');
            input.type = 'text';
            input.name = `create_environment[${variable.env_variable || ''}]`;
            input.value = variable.value ?? variable.default_value ?? '';

            const help = document.createElement('p');
            help.className = 'fbg-admin-help-text';

            const details = [];
            if (variable.description) {
                details.push(variable.description);
            }
            if (variable.env_variable) {
                details.push(`Access in Startup: {{${variable.env_variable}}}`);
            }
            if (variable.rules) {
                details.push(`Validation Rules: ${variable.rules}`);
            }
            help.textContent = details.join(' ');

            field.appendChild(label);
            field.appendChild(input);
            field.appendChild(help);
            variablesWrap.appendChild(field);
        });
    };

    const applyEggData = (resetCustomImage = false) => {
        if (!eggSelect || !startupInput || !dockerSelect || !variablesWrap) return;

        const eggId = eggSelect.value;
        const egg = eggData[eggId];

        dockerSelect.innerHTML = '<option value="">Select a docker image</option>';

        if (!egg) {
            startupInput.value = '';
            dockerSelect.disabled = true;
            if (dockerCustomInput) {
                dockerCustomInput.value = '';
            }
            renderCreateVariables(null);
            return;
        }

        startupInput.value = egg.startup || '';

        const dockerImages = egg.docker_images && typeof egg.docker_images === 'object' ? egg.docker_images : {};
        Object.entries(dockerImages).forEach(([image, label], index) => {
            const option = document.createElement('option');
            option.value = image;
            option.textContent = label || image;
            if (index === 0) {
                option.selected = true;
            }
            dockerSelect.appendChild(option);
        });

        dockerSelect.disabled = Object.keys(dockerImages).length === 0;
        if (dockerCustomInput && resetCustomImage) {
            dockerCustomInput.value = '';
        }

        renderCreateVariables(egg);
    };

    const syncEggOptions = () => {
        if (!nestSelect || !eggSelect) return;

        const selectedNestId = nestSelect.value;
        eggSelect.innerHTML = '<option value="">Select an egg</option>';

        const matchingEggs = eggOptions.filter((egg) => String(egg.nest_id || '') === selectedNestId);
        matchingEggs.forEach((egg, index) => {
            const option = document.createElement('option');
            option.value = String(egg.id || '');
            option.textContent = String(egg.name || 'Unknown Egg');
            if (index === 0) {
                option.selected = true;
            }
            eggSelect.appendChild(option);
        });

        eggSelect.disabled = matchingEggs.length === 0;
        applyEggData();
    };

    if (nodeSelect) {
        if (!nodeSelect.value && nodeSelect.options.length > 1) {
            nodeSelect.selectedIndex = 1;
        }
        nodeSelect.addEventListener('change', syncAllocations);
        syncAllocations();
    }

    if (allocationSelect) {
        allocationSelect.addEventListener('change', syncAdditionalAllocationVisibility);
    }

    if (nestSelect) {
        if (!nestSelect.value && nestSelect.options.length > 1) {
            nestSelect.selectedIndex = 1;
        }
        nestSelect.addEventListener('change', syncEggOptions);
        syncEggOptions();
    }

    if (eggSelect) {
        eggSelect.addEventListener('change', () => applyEggData(true));
    }

    if (createModal) {
        createModal.addEventListener('click', (event) => {
            if (event.target === createModal) {
                window.location.href = <?= json_encode(fbgAdminServersBaseQuery(['create' => null, 'edit' => null, 'tab' => null])) ?>;
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            window.location.href = <?= json_encode(fbgAdminServersBaseQuery(['create' => null, 'edit' => null, 'tab' => null])) ?>;
        }
    });
});
</script>
