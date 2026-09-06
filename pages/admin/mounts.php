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

$currentAdminPage = 'admin-mounts';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_mounts_message'] ?? '');
$messageType = (string)($_SESSION['admin_mounts_message_type'] ?? 'success');
unset($_SESSION['admin_mounts_message'], $_SESSION['admin_mounts_message_type']);

function fbgAdminMountsH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fbgAdminMountsRedirect(string $message, string $type = 'success', ?int $editMountId = null, bool $openCreate = false): void
{
    $_SESSION['admin_mounts_message'] = $message;
    $_SESSION['admin_mounts_message_type'] = $type;

    $url = '/page.php?name=admin-mounts';
    if ($editMountId !== null && $editMountId > 0) {
        $url .= '&edit=' . $editMountId;
    } elseif ($openCreate) {
        $url .= '&create=1';
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminMountsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminMountsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminMountsUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function fbgAdminMountsBool(string $key): int
{
    return in_array((string)($_POST[$key] ?? '0'), ['1', 'on', 'true'], true) ? 1 : 0;
}

function fbgAdminMountsNormalizeInput(): array
{
    return [
        'name' => trim((string)($_POST['mount_name'] ?? '')),
        'description' => trim((string)($_POST['mount_description'] ?? '')),
        'source' => trim((string)($_POST['mount_source'] ?? '')),
        'target' => trim((string)($_POST['mount_target'] ?? '')),
        'read_only' => fbgAdminMountsBool('mount_read_only'),
        'user_mountable' => fbgAdminMountsBool('mount_user_mountable'),
    ];
}

function fbgAdminMountsValidateInput(array $input): ?string
{
    $nameLength = strlen((string)$input['name']);
    if ($nameLength < 2 || $nameLength > 64) {
        return 'Mount name must be between 2 and 64 characters.';
    }

    if (strlen((string)$input['description']) > 191) {
        return 'Description must be 191 characters or fewer.';
    }

    if ((string)$input['source'] === '' || (string)$input['target'] === '') {
        return 'Source and target paths are required.';
    }

    $invalidSources = ['/etc/pterodactyl', '/var/lib/pterodactyl/volumes', '/srv/daemon-data'];
    $invalidTargets = ['/home/container'];

    if (in_array(rtrim((string)$input['source'], '/'), $invalidSources, true)) {
        return 'That source path is reserved by Pterodactyl and cannot be used as a mount source.';
    }

    if (in_array(rtrim((string)$input['target'], '/'), $invalidTargets, true)) {
        return 'That target path is reserved by Pterodactyl and cannot be used as a mount target.';
    }

    return null;
}

function fbgAdminMountsNameExists(string $name, ?int $exceptMountId = null): bool
{
    $sql = 'SELECT id FROM mounts WHERE name = :name';
    $params = ['name' => $name];

    if ($exceptMountId !== null && $exceptMountId > 0) {
        $sql .= ' AND id != :id';
        $params['id'] = $exceptMountId;
    }

    $sql .= ' LIMIT 1';
    $stmt = fbgPteroDb()->prepare($sql);
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

function fbgAdminMountsBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-mounts';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

function fbgAdminMountsSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';

    return fbgAdminMountsBaseQuery([
        'sort' => $targetSort,
        'dir' => $direction,
        'page_num' => null,
        'edit' => null,
        'create' => null,
    ]);
}

function fbgAdminMountsIntList(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        $values = [$values];
    }

    return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminMountsVerifyCsrf();

    $action = (string)($_POST['action'] ?? '');
    $mountId = max(0, (int)($_POST['mount_id'] ?? 0));

    if ($action === 'create_mount') {
        $input = fbgAdminMountsNormalizeInput();
        $error = fbgAdminMountsValidateInput($input);
        if ($error !== null) {
            fbgAdminMountsRedirect($error, 'error', null, true);
        }

        if (fbgAdminMountsNameExists((string)$input['name'])) {
            fbgAdminMountsRedirect('A mount with that name already exists.', 'error', null, true);
        }

        $stmt = fbgPteroDb()->prepare('
            INSERT INTO mounts (uuid, name, description, source, target, read_only, user_mountable)
            VALUES (:uuid, :name, :description, :source, :target, :read_only, :user_mountable)
        ');
        $stmt->execute([
            'uuid' => fbgAdminMountsUuid(),
            'name' => $input['name'],
            'description' => $input['description'] !== '' ? $input['description'] : null,
            'source' => $input['source'],
            'target' => $input['target'],
            'read_only' => $input['read_only'],
            'user_mountable' => $input['user_mountable'],
        ]);

        fbgAdminMountsRedirect('Mount created successfully.', 'success');
    }

    if ($mountId <= 0 && in_array($action, ['update_mount', 'assign_eggs', 'detach_egg', 'assign_nodes', 'detach_node', 'delete_mount'], true)) {
        fbgAdminMountsRedirect('Select a valid mount.', 'error');
    }

    if ($action === 'update_mount') {
        $existsStmt = fbgPteroDb()->prepare('SELECT id FROM mounts WHERE id = :id LIMIT 1');
        $existsStmt->execute(['id' => $mountId]);
        if (!$existsStmt->fetchColumn()) {
            fbgAdminMountsRedirect('Mount could not be found.', 'error');
        }

        $input = fbgAdminMountsNormalizeInput();
        $error = fbgAdminMountsValidateInput($input);
        if ($error !== null) {
            fbgAdminMountsRedirect($error, 'error', $mountId);
        }

        if (fbgAdminMountsNameExists((string)$input['name'], $mountId)) {
            fbgAdminMountsRedirect('A mount with that name already exists.', 'error', $mountId);
        }

        $stmt = fbgPteroDb()->prepare('
            UPDATE mounts
            SET name = :name,
                description = :description,
                source = :source,
                target = :target,
                read_only = :read_only,
                user_mountable = :user_mountable
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $mountId,
            'name' => $input['name'],
            'description' => $input['description'] !== '' ? $input['description'] : null,
            'source' => $input['source'],
            'target' => $input['target'],
            'read_only' => $input['read_only'],
            'user_mountable' => $input['user_mountable'],
        ]);

        fbgAdminMountsRedirect('Mount updated successfully.', 'success', $mountId);
    }

    if ($action === 'assign_eggs') {
        $eggIds = fbgAdminMountsIntList('egg_ids');
        if (empty($eggIds)) {
            fbgAdminMountsRedirect('Select at least one egg to assign.', 'error', $mountId);
        }

        $stmt = fbgPteroDb()->prepare('INSERT IGNORE INTO egg_mount (egg_id, mount_id) VALUES (:egg_id, :mount_id)');
        foreach ($eggIds as $eggId) {
            $stmt->execute(['egg_id' => $eggId, 'mount_id' => $mountId]);
        }

        fbgAdminMountsRedirect('Egg availability updated.', 'success', $mountId);
    }

    if ($action === 'detach_egg') {
        $eggId = max(0, (int)($_POST['egg_id'] ?? 0));
        if ($eggId <= 0) {
            fbgAdminMountsRedirect('Select a valid egg to remove.', 'error', $mountId);
        }

        $stmt = fbgPteroDb()->prepare('DELETE FROM egg_mount WHERE egg_id = :egg_id AND mount_id = :mount_id');
        $stmt->execute(['egg_id' => $eggId, 'mount_id' => $mountId]);

        fbgAdminMountsRedirect('Egg removed from this mount.', 'warning', $mountId);
    }

    if ($action === 'assign_nodes') {
        $nodeIds = fbgAdminMountsIntList('node_ids');
        if (empty($nodeIds)) {
            fbgAdminMountsRedirect('Select at least one node to assign.', 'error', $mountId);
        }

        $stmt = fbgPteroDb()->prepare('INSERT IGNORE INTO mount_node (node_id, mount_id) VALUES (:node_id, :mount_id)');
        foreach ($nodeIds as $nodeId) {
            $stmt->execute(['node_id' => $nodeId, 'mount_id' => $mountId]);
        }

        fbgAdminMountsRedirect('Node availability updated.', 'success', $mountId);
    }

    if ($action === 'detach_node') {
        $nodeId = max(0, (int)($_POST['node_id'] ?? 0));
        if ($nodeId <= 0) {
            fbgAdminMountsRedirect('Select a valid node to remove.', 'error', $mountId);
        }

        $stmt = fbgPteroDb()->prepare('DELETE FROM mount_node WHERE node_id = :node_id AND mount_id = :mount_id');
        $stmt->execute(['node_id' => $nodeId, 'mount_id' => $mountId]);

        fbgAdminMountsRedirect('Node removed from this mount.', 'warning', $mountId);
    }

    if ($action === 'delete_mount') {
        $countStmt = fbgPteroDb()->prepare('SELECT COUNT(*) FROM mount_server WHERE mount_id = :mount_id');
        $countStmt->execute(['mount_id' => $mountId]);
        $serverCount = (int)$countStmt->fetchColumn();

        if ($serverCount > 0) {
            fbgAdminMountsRedirect('Mount cannot be deleted while servers are using it.', 'error', $mountId);
        }

        $pdo = fbgPteroDb();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM egg_mount WHERE mount_id = :mount_id')->execute(['mount_id' => $mountId]);
            $pdo->prepare('DELETE FROM mount_node WHERE mount_id = :mount_id')->execute(['mount_id' => $mountId]);
            $pdo->prepare('DELETE FROM mounts WHERE id = :mount_id LIMIT 1')->execute(['mount_id' => $mountId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            fbgAdminMountsRedirect('Mount could not be deleted. Please try again.', 'error', $mountId);
        }

        fbgAdminMountsRedirect('Mount deleted successfully.', 'success');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$sort = strtolower((string)($_GET['sort'] ?? 'id'));
$direction = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$perPage = 25;
$pageNum = fbgPaginationRequestedPage();
$editMountId = max(0, (int)($_GET['edit'] ?? 0));
$openCreate = isset($_GET['create']);

$sortMap = [
    'id' => 'm.id',
    'name' => 'm.name',
    'source' => 'm.source',
    'target' => 'm.target',
    'eggs' => 'egg_count',
    'nodes' => 'node_count',
    'servers' => 'server_count',
];

if (!array_key_exists($sort, $sortMap)) {
    $sort = 'id';
}

$whereSql = '';
$params = [];

if ($search !== '') {
    $whereSql = 'WHERE m.id LIKE :search OR m.name LIKE :search OR m.description LIKE :search OR m.source LIKE :search OR m.target LIKE :search';
    $params['search'] = '%' . $search . '%';
}

$countStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM mounts m {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$offset = $pagination['offset'];

$orderSql = $sortMap[$sort] . ' ' . strtoupper($direction) . ', m.id ASC';
$mountsStmt = fbgPteroDb()->prepare("
    SELECT
        m.id,
        m.uuid,
        m.name,
        m.description,
        m.source,
        m.target,
        m.read_only,
        m.user_mountable,
        (SELECT COUNT(*) FROM egg_mount em WHERE em.mount_id = m.id) AS egg_count,
        (SELECT COUNT(*) FROM mount_node mn WHERE mn.mount_id = m.id) AS node_count,
        (SELECT COUNT(*) FROM mount_server ms WHERE ms.mount_id = m.id) AS server_count
    FROM mounts m
    {$whereSql}
    ORDER BY {$orderSql}
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $mountsStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$mountsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$mountsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
$mountsStmt->execute();
$mounts = $mountsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editingMount = null;
$editingEggs = [];
$editingNodes = [];
$editingServers = [];
$availableEggsByNest = [];
$availableNodesByLocation = [];

if ($editMountId > 0) {
    $mountStmt = fbgPteroDb()->prepare('
        SELECT
            m.*,
            (SELECT COUNT(*) FROM egg_mount em WHERE em.mount_id = m.id) AS egg_count,
            (SELECT COUNT(*) FROM mount_node mn WHERE mn.mount_id = m.id) AS node_count,
            (SELECT COUNT(*) FROM mount_server ms WHERE ms.mount_id = m.id) AS server_count
        FROM mounts m
        WHERE m.id = :id
        LIMIT 1
    ');
    $mountStmt->execute(['id' => $editMountId]);
    $editingMount = $mountStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingMount) {
        $eggsStmt = fbgPteroDb()->prepare('
            SELECT
                e.id,
                e.name,
                e.description,
                COALESCE(n.name, "Unassigned Nest") AS nest_name,
                COUNT(s.id) AS server_count
            FROM egg_mount em
            INNER JOIN eggs e ON e.id = em.egg_id
            LEFT JOIN nests n ON n.id = e.nest_id
            LEFT JOIN servers s ON s.egg_id = e.id
            WHERE em.mount_id = :mount_id
            GROUP BY e.id, e.name, e.description, n.name
            ORDER BY n.name ASC, e.name ASC
        ');
        $eggsStmt->execute(['mount_id' => $editMountId]);
        $editingEggs = $eggsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $nodesStmt = fbgPteroDb()->prepare('
            SELECT
                n.id,
                n.name,
                n.fqdn,
                COALESCE(l.short, "Unassigned") AS location_short,
                COALESCE(l.`long`, "") AS location_long,
                COUNT(s.id) AS server_count
            FROM mount_node mn
            INNER JOIN nodes n ON n.id = mn.node_id
            LEFT JOIN locations l ON l.id = n.location_id
            LEFT JOIN servers s ON s.node_id = n.id
            WHERE mn.mount_id = :mount_id
            GROUP BY n.id, n.name, n.fqdn, l.short, l.`long`
            ORDER BY l.short ASC, n.name ASC
        ');
        $nodesStmt->execute(['mount_id' => $editMountId]);
        $editingNodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $serversStmt = fbgPteroDb()->prepare('
            SELECT
                s.id,
                s.uuidShort AS identifier,
                s.name,
                COALESCE(u.username, "Unknown") AS owner_username,
                COALESCE(n.name, "Unknown Node") AS node_name,
                COALESCE(e.name, "Unknown Egg") AS egg_name
            FROM mount_server ms
            INNER JOIN servers s ON s.id = ms.server_id
            LEFT JOIN users u ON u.id = s.owner_id
            LEFT JOIN nodes n ON n.id = s.node_id
            LEFT JOIN eggs e ON e.id = s.egg_id
            WHERE ms.mount_id = :mount_id
            ORDER BY s.name ASC, s.id ASC
        ');
        $serversStmt->execute(['mount_id' => $editMountId]);
        $editingServers = $serversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $availableEggsStmt = fbgPteroDb()->prepare('
            SELECT e.id, e.name, COALESCE(n.name, "Unassigned Nest") AS nest_name
            FROM eggs e
            LEFT JOIN nests n ON n.id = e.nest_id
            WHERE NOT EXISTS (
                SELECT 1 FROM egg_mount em WHERE em.egg_id = e.id AND em.mount_id = :mount_id
            )
            ORDER BY n.name ASC, e.name ASC
        ');
        $availableEggsStmt->execute(['mount_id' => $editMountId]);
        foreach (($availableEggsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $egg) {
            $availableEggsByNest[(string)$egg['nest_name']][] = $egg;
        }

        $availableNodesStmt = fbgPteroDb()->prepare('
            SELECT n.id, n.name, COALESCE(l.short, "Unassigned") AS location_short
            FROM nodes n
            LEFT JOIN locations l ON l.id = n.location_id
            WHERE NOT EXISTS (
                SELECT 1 FROM mount_node mn WHERE mn.node_id = n.id AND mn.mount_id = :mount_id
            )
            ORDER BY l.short ASC, n.name ASC
        ');
        $availableNodesStmt->execute(['mount_id' => $editMountId]);
        foreach (($availableNodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $node) {
            $availableNodesByLocation[(string)$node['location_short']][] = $node;
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
                <h1>Mounts</h1>
                <p class="fbg-admin-subtext">Manage extra filesystem mount points available to Pterodactyl servers.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Mounts',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <h2>Mount List</h2>
                    <a class="btn" href="<?= fbgAdminMountsH(fbgAdminMountsBaseQuery(['create' => 1, 'edit' => null])) ?>">Create Mount</a>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-mounts">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="mount-search">Search</label>
                            <input id="mount-search" type="search" name="q" value="<?= fbgAdminMountsH($search) ?>" placeholder="ID, name, source, target, or description">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="mount-sort">Sort</label>
                            <select id="mount-sort" name="sort">
                                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID</option>
                                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                                <option value="source" <?= $sort === 'source' ? 'selected' : '' ?>>Source</option>
                                <option value="target" <?= $sort === 'target' ? 'selected' : '' ?>>Target</option>
                                <option value="eggs" <?= $sort === 'eggs' ? 'selected' : '' ?>>Eggs</option>
                                <option value="nodes" <?= $sort === 'nodes' ? 'selected' : '' ?>>Nodes</option>
                                <option value="servers" <?= $sort === 'servers' ? 'selected' : '' ?>>Servers</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="mount-dir">Direction</label>
                            <select id="mount-dir" name="dir">
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
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('id', $sort, $direction)) ?>">ID</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('name', $sort, $direction)) ?>">Name</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('source', $sort, $direction)) ?>">Source</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('target', $sort, $direction)) ?>">Target</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('eggs', $sort, $direction)) ?>">Eggs</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('nodes', $sort, $direction)) ?>">Nodes</a></th>
                                <th><a href="<?= fbgAdminMountsH(fbgAdminMountsSortUrl('servers', $sort, $direction)) ?>">Servers</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mounts)): ?>
                                <tr>
                                    <td colspan="7">No mounts found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($mounts as $mount): ?>
                                <tr>
                                    <td><code><?= (int)$mount['id'] ?></code></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= fbgAdminMountsH(fbgAdminMountsBaseQuery(['edit' => (int)$mount['id'], 'create' => null])) ?>">
                                            <?= fbgAdminMountsH($mount['name'] ?? '') ?>
                                        </a>
                                        <?php if ((int)($mount['read_only'] ?? 0) === 1): ?>
                                            <br><small>Read only</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= fbgAdminMountsH($mount['source'] ?? '') ?></code></td>
                                    <td><code><?= fbgAdminMountsH($mount['target'] ?? '') ?></code></td>
                                    <td><?= (int)($mount['egg_count'] ?? 0) ?></td>
                                    <td><?= (int)($mount['node_count'] ?? 0) ?></td>
                                    <td><?= (int)($mount['server_count'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'mount', ['remove' => ['edit', 'create']]); ?>
            </section>
        </div>
    </div>
</section>

<?php if ($openCreate): ?>
    <div class="fbg-modal-overlay" id="admin-mount-create-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-mount-create-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-mounts" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-mount-create-title">Create Mount</h3>
                <p>Create a reusable filesystem mount point for selected eggs and nodes.</p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_mount">

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="create-mount-name">Name</label>
                        <input id="create-mount-name" name="mount_name" type="text" required maxlength="64" autocomplete="off">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-mount-source">Source Path</label>
                        <input id="create-mount-source" name="mount_source" type="text" required placeholder="/mnt/shared">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-mount-target">Target Path</label>
                        <input id="create-mount-target" name="mount_target" type="text" required placeholder="/home/container/shared">
                    </div>

                    <div class="fbg-admin-field">
                        <label>Options</label>
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="mount_read_only" value="1">
                            <span>Read only</span>
                        </label>
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="mount_user_mountable" value="1">
                            <span>User mountable</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="create-mount-description">Description</label>
                        <textarea id="create-mount-description" name="mount_description" maxlength="191" rows="4"></textarea>
                    </div>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Create Mount</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-mounts">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($editMountId > 0 && !$editingMount): ?>
    <div class="fbg-modal-overlay" id="admin-mount-missing-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-mount-missing-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-mounts" aria-label="Close">X</a>
            <div class="fbg-modal-header">
                <h3 id="admin-mount-missing-title">Mount Not Found</h3>
                <p>That mount could not be found.</p>
            </div>
            <div class="fbg-admin-form-actions">
                <a class="btn" href="./page.php?name=admin-mounts">Back to Mounts</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($editingMount): ?>
    <?php $editingServerCount = (int)($editingMount['server_count'] ?? 0); ?>
    <div class="fbg-modal-overlay" id="admin-mount-edit-modal">
        <div class="fbg-modal-card fbg-admin-user-modal fbg-admin-mount-modal" role="dialog" aria-modal="true" aria-labelledby="admin-mount-edit-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-mounts" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-mount-edit-title">Edit <?= fbgAdminMountsH($editingMount['name'] ?? '') ?></h3>
                <p>Update mount paths, availability, and review servers currently using this mount.</p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_mount">
                <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">

                <h3>Mount Details</h3>

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="edit-mount-name">Name</label>
                        <input id="edit-mount-name" name="mount_name" type="text" required maxlength="64" value="<?= fbgAdminMountsH($editingMount['name'] ?? '') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-mount-uuid">UUID</label>
                        <input id="edit-mount-uuid" type="text" readonly value="<?= fbgAdminMountsH($editingMount['uuid'] ?? '') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-mount-source">Source Path</label>
                        <input id="edit-mount-source" name="mount_source" type="text" required value="<?= fbgAdminMountsH($editingMount['source'] ?? '') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-mount-target">Target Path</label>
                        <input id="edit-mount-target" name="mount_target" type="text" required value="<?= fbgAdminMountsH($editingMount['target'] ?? '') ?>">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="edit-mount-description">Description</label>
                        <textarea id="edit-mount-description" name="mount_description" maxlength="191" rows="4"><?= fbgAdminMountsH($editingMount['description'] ?? '') ?></textarea>
                    </div>

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="mount_read_only" value="1" <?= (int)($editingMount['read_only'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span>Read only</span>
                        </label>
                    </div>

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input type="checkbox" name="mount_user_mountable" value="1" <?= (int)($editingMount['user_mountable'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span>User mountable</span>
                        </label>
                    </div>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Save Mount</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-mounts">Cancel</a>
                </div>
            </form>

            <hr>

            <div class="fbg-admin-grid">
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header">
                        <h2>Assign Eggs</h2>
                    </div>

                    <form method="POST" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="assign_eggs">
                        <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">

                        <div class="fbg-admin-field">
                            <label for="mount-egg-ids">Available Eggs</label>
                            <select id="mount-egg-ids" name="egg_ids[]" multiple size="8">
                                <?php foreach ($availableEggsByNest as $nestName => $eggs): ?>
                                    <optgroup label="<?= fbgAdminMountsH($nestName) ?>">
                                        <?php foreach ($eggs as $egg): ?>
                                            <option value="<?= (int)$egg['id'] ?>">#<?= (int)$egg['id'] ?> <?= fbgAdminMountsH($egg['name'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">Hold Ctrl or Shift to select multiple eggs.</p>
                        </div>

                        <div class="fbg-admin-form-actions">
                            <button type="submit" class="btn btn-sm">Assign Selected Eggs</button>
                        </div>
                    </form>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header">
                        <h2>Assigned Eggs</h2>
                    </div>

                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Egg</th>
                                    <th>Nest</th>
                                    <th>Servers</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($editingEggs)): ?>
                                    <tr><td colspan="5">No eggs are assigned to this mount.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($editingEggs as $egg): ?>
                                    <tr>
                                        <td><code><?= (int)$egg['id'] ?></code></td>
                                        <td><?= fbgAdminMountsH($egg['name'] ?? '') ?></td>
                                        <td><?= fbgAdminMountsH($egg['nest_name'] ?? '') ?></td>
                                        <td><?= (int)($egg['server_count'] ?? 0) ?></td>
                                        <td>
                                            <form method="POST" data-fbg-confirm-title="Remove egg?" data-fbg-confirm-message="This mount will no longer be available to servers using **<?= fbgAdminMountsH($egg['name'] ?? '') ?>**." data-fbg-confirm-button="Remove" data-fbg-confirm-variant="danger">
                                                <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="detach_egg">
                                                <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">
                                                <input type="hidden" name="egg_id" value="<?= (int)$egg['id'] ?>">
                                                <button type="submit" class="btn btn-delete btn-sm">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header">
                        <h2>Assign Nodes</h2>
                    </div>

                    <form method="POST" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="assign_nodes">
                        <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">

                        <div class="fbg-admin-field">
                            <label for="mount-node-ids">Available Nodes</label>
                            <select id="mount-node-ids" name="node_ids[]" multiple size="8">
                                <?php foreach ($availableNodesByLocation as $locationName => $nodes): ?>
                                    <optgroup label="<?= fbgAdminMountsH($locationName) ?>">
                                        <?php foreach ($nodes as $node): ?>
                                            <option value="<?= (int)$node['id'] ?>">#<?= (int)$node['id'] ?> <?= fbgAdminMountsH($node['name'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="fbg-admin-help-text">Hold Ctrl or Shift to select multiple nodes.</p>
                        </div>

                        <div class="fbg-admin-form-actions">
                            <button type="submit" class="btn btn-sm">Assign Selected Nodes</button>
                        </div>
                    </form>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header">
                        <h2>Assigned Nodes</h2>
                    </div>

                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Node</th>
                                    <th>Location</th>
                                    <th>FQDN</th>
                                    <th>Servers</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($editingNodes)): ?>
                                    <tr><td colspan="6">No nodes are assigned to this mount.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($editingNodes as $node): ?>
                                    <tr>
                                        <td><code><?= (int)$node['id'] ?></code></td>
                                        <td><?= fbgAdminMountsH($node['name'] ?? '') ?></td>
                                        <td><?= fbgAdminMountsH($node['location_short'] ?? '') ?></td>
                                        <td><code><?= fbgAdminMountsH($node['fqdn'] ?? '') ?></code></td>
                                        <td><?= (int)($node['server_count'] ?? 0) ?></td>
                                        <td>
                                            <form method="POST" data-fbg-confirm-title="Remove node?" data-fbg-confirm-message="This mount will no longer be available on **<?= fbgAdminMountsH($node['name'] ?? '') ?>**." data-fbg-confirm-button="Remove" data-fbg-confirm-variant="danger">
                                                <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="detach_node">
                                                <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">
                                                <input type="hidden" name="node_id" value="<?= (int)$node['id'] ?>">
                                                <button type="submit" class="btn btn-delete btn-sm">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-panel-header">
                        <h2>Mounted Servers</h2>
                    </div>

                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Identifier</th>
                                    <th>Server</th>
                                    <th>Owner</th>
                                    <th>Node</th>
                                    <th>Egg</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($editingServers)): ?>
                                    <tr><td colspan="6">No servers are currently using this mount.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($editingServers as $server): ?>
                                    <tr>
                                        <td><code><?= (int)$server['id'] ?></code></td>
                                        <td><code><?= fbgAdminMountsH($server['identifier'] ?? '') ?></code></td>
                                        <td><?= fbgAdminMountsH($server['name'] ?? '') ?></td>
                                        <td><?= fbgAdminMountsH($server['owner_username'] ?? '') ?></td>
                                        <td><?= fbgAdminMountsH($server['node_name'] ?? '') ?></td>
                                        <td><?= fbgAdminMountsH($server['egg_name'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="fbg-admin-panel fbg-admin-panel-full" style="border-color: rgba(255, 64, 64, 0.44);">
                    <div class="fbg-admin-panel-header">
                        <h2>Delete Mount</h2>
                    </div>
                    <p>Deleting a mount is permanent. Remove server assignments before deleting.</p>
                    <?php if ($editingServerCount > 0): ?>
                        <p class="fbg-admin-help-text">This mount is used by <?= $editingServerCount ?> server<?= $editingServerCount === 1 ? '' : 's' ?> and cannot be deleted yet.</p>
                    <?php endif; ?>

                    <form method="POST" data-fbg-confirm-title="Delete mount?" data-fbg-confirm-message="This permanently deletes **<?= fbgAdminMountsH($editingMount['name'] ?? '') ?>** from Pterodactyl." data-fbg-confirm-button="Delete Mount" data-fbg-confirm-variant="danger">
                        <input type="hidden" name="csrf_token" value="<?= fbgAdminMountsH($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete_mount">
                        <input type="hidden" name="mount_id" value="<?= (int)$editingMount['id'] ?>">
                        <button type="submit" class="btn btn-delete btn-sm" <?= $editingServerCount > 0 ? 'disabled' : '' ?>>Delete Mount</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('admin-mount-create-modal')
        || document.getElementById('admin-mount-edit-modal')
        || document.getElementById('admin-mount-missing-modal');

    if (modal) {
        document.body.classList.add('fbg-modal-open');
    }

    document.querySelectorAll('form[data-fbg-confirm-title]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.fbgConfirmSubmitted === '1') {
                return;
            }

            event.preventDefault();

            const confirmed = await window.FBGConfirm?.({
                title: form.dataset.fbgConfirmTitle || 'Are you sure?',
                message: form.dataset.fbgConfirmMessage || 'Please confirm this action.',
                confirmLabel: form.dataset.fbgConfirmButton || 'Confirm',
                cancelLabel: 'Cancel',
                variant: form.dataset.fbgConfirmVariant || 'default',
            });

            if (!confirmed) {
                return;
            }

            form.dataset.fbgConfirmSubmitted = '1';
            form.submit();
        });
    });
});
</script>
