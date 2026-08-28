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

$currentAdminPage = 'admin-database-hosts';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_database_hosts_message'] ?? '');
$messageType = (string)($_SESSION['admin_database_hosts_message_type'] ?? 'success');
unset($_SESSION['admin_database_hosts_message'], $_SESSION['admin_database_hosts_message_type']);

function fbgAdminDatabaseHostsRedirect(string $message, string $type = 'success', ?int $editHostId = null, bool $openCreate = false): void
{
    $_SESSION['admin_database_hosts_message'] = $message;
    $_SESSION['admin_database_hosts_message_type'] = $type;

    $url = '/page.php?name=admin-database-hosts';
    if ($editHostId !== null && $editHostId > 0) {
        $url .= '&edit=' . $editHostId;
    } elseif ($openCreate) {
        $url .= '&create=1';
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminDatabaseHostsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminDatabaseHostsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminDatabaseHostsSafeDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function fbgAdminDatabaseHostsNodeLabel(?int $nodeId, array $nodesById): string
{
    if ($nodeId === null || $nodeId <= 0) {
        return 'None';
    }

    return (string)($nodesById[$nodeId]['name'] ?? ('Node #' . $nodeId));
}

function fbgAdminDatabaseHostsNormalizeHostInput(): array
{
    $name = trim((string)($_POST['host_name'] ?? ''));
    $host = trim((string)($_POST['host_address'] ?? ''));
    $port = (int)($_POST['host_port'] ?? 0);
    $username = trim((string)($_POST['host_username'] ?? ''));
    $password = (string)($_POST['host_password'] ?? '');
    $nodeIdRaw = (int)($_POST['node_id'] ?? 0);
    $nodeId = $nodeIdRaw > 0 ? $nodeIdRaw : null;

    return [
        'name' => $name,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'node_id' => $nodeId,
    ];
}

function fbgAdminDatabaseHostsValidateInput(array $input, bool $passwordRequired): ?string
{
    if ($input['name'] === '' || strlen((string)$input['name']) > 191) {
        return 'Database host name is required and must be 191 characters or fewer.';
    }

    if ($input['host'] === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', (string)$input['host'])) {
        return 'Host must be a valid IP address or FQDN.';
    }

    if ((int)$input['port'] < 1 || (int)$input['port'] > 65535) {
        return 'Port must be between 1 and 65535.';
    }

    if ($input['username'] === '' || strlen((string)$input['username']) > 32) {
        return 'Username is required and must be 32 characters or fewer.';
    }

    if ($passwordRequired && trim((string)$input['password']) === '') {
        return 'Password is required when creating a database host.';
    }

    return null;
}

function fbgAdminDatabaseHostsEncryptionKey(): ?string
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

function fbgAdminDatabaseHostsEncryptPassword(string $password): ?string
{
    $key = fbgAdminDatabaseHostsEncryptionKey();
    if ($key === null) {
        return null;
    }

    $iv = random_bytes(16);
    $encrypted = openssl_encrypt(serialize($password), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        return null;
    }

    $ivEncoded = base64_encode($iv);
    $valueEncoded = base64_encode($encrypted);
    $mac = hash_hmac('sha256', $ivEncoded . $valueEncoded, $key);
    $payload = json_encode([
        'iv' => $ivEncoded,
        'value' => $valueEncoded,
        'mac' => $mac,
        'tag' => '',
    ], JSON_UNESCAPED_SLASHES);

    return $payload === false ? null : base64_encode($payload);
}

function fbgAdminDatabaseHostsTestConnection(array $input, string $password): ?string
{
    $dsn = 'mysql:host=' . (string)$input['host'] . ';port=' . (int)$input['port'] . ';dbname=mysql;charset=utf8';

    try {
        $pdo = new PDO($dsn, (string)$input['username'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        return 'There was an error connecting to the database host with those credentials: ' . $e->getMessage();
    }

    return null;
}

function fbgAdminDatabaseHostsSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
    $query = $_GET;
    $query['name'] = 'admin-database-hosts';
    $query['sort'] = $targetSort;
    $query['dir'] = $direction;
    $query['page_num'] = 1;
    unset($query['edit'], $query['create']);

    return './page.php?' . http_build_query($query);
}

function fbgAdminDatabaseHostsBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-database-hosts';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

$nodes = [];
$nodesById = [];
$nodeStmt = fbgPteroDb()->query('SELECT id, name FROM nodes ORDER BY name ASC');
foreach (($nodeStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $node) {
    $node['id'] = (int)$node['id'];
    $nodes[] = $node;
    $nodesById[(int)$node['id']] = $node;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminDatabaseHostsVerifyCsrf();

    $action = (string)($_POST['action'] ?? '');
    $hostId = max(0, (int)($_POST['host_id'] ?? 0));

    if ($action === 'create_host') {
        $input = fbgAdminDatabaseHostsNormalizeHostInput();
        $error = fbgAdminDatabaseHostsValidateInput($input, true);
        if ($error !== null) {
            fbgAdminDatabaseHostsRedirect($error, 'error', null, true);
        }

        if ($input['node_id'] !== null && !isset($nodesById[(int)$input['node_id']])) {
            fbgAdminDatabaseHostsRedirect('Selected linked node could not be found.', 'error', null, true);
        }

        $encryptedPassword = fbgAdminDatabaseHostsEncryptPassword((string)$input['password']);
        if ($encryptedPassword === null) {
            fbgAdminDatabaseHostsRedirect('Database host password could not be encrypted. Add PTERO_APP_KEY to the site config using the Pterodactyl APP_KEY value, then try again.', 'error', null, true);
        }

        $connectionError = fbgAdminDatabaseHostsTestConnection($input, (string)$input['password']);
        if ($connectionError !== null) {
            fbgAdminDatabaseHostsRedirect($connectionError, 'error', null, true);
        }

        $stmt = fbgPteroDb()->prepare('
            INSERT INTO database_hosts (name, host, port, username, password, max_databases, node_id, created_at, updated_at)
            VALUES (:name, :host, :port, :username, :password, NULL, :node_id, NOW(), NOW())
        ');
        $stmt->execute([
            'name' => $input['name'],
            'host' => $input['host'],
            'port' => (int)$input['port'],
            'username' => $input['username'],
            'password' => $encryptedPassword,
            'node_id' => $input['node_id'],
        ]);

        fbgAdminDatabaseHostsRedirect('Database host created successfully.');
    }

    if ($action === 'update_host') {
        if ($hostId <= 0) {
            fbgAdminDatabaseHostsRedirect('Select a valid database host.', 'error');
        }

        $hostStmt = fbgPteroDb()->prepare('SELECT id FROM database_hosts WHERE id = :id LIMIT 1');
        $hostStmt->execute(['id' => $hostId]);
        if ((int)($hostStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminDatabaseHostsRedirect('Database host could not be found.', 'error');
        }

        $input = fbgAdminDatabaseHostsNormalizeHostInput();
        $error = fbgAdminDatabaseHostsValidateInput($input, false);
        if ($error !== null) {
            fbgAdminDatabaseHostsRedirect($error, 'error', $hostId);
        }

        if ($input['node_id'] !== null && !isset($nodesById[(int)$input['node_id']])) {
            fbgAdminDatabaseHostsRedirect('Selected linked node could not be found.', 'error', $hostId);
        }

        $params = [
            'id' => $hostId,
            'name' => $input['name'],
            'host' => $input['host'],
            'port' => (int)$input['port'],
            'username' => $input['username'],
            'node_id' => $input['node_id'],
        ];
        $passwordSql = '';
        if (trim((string)$input['password']) !== '') {
            $encryptedPassword = fbgAdminDatabaseHostsEncryptPassword((string)$input['password']);
            if ($encryptedPassword === null) {
                fbgAdminDatabaseHostsRedirect('Database host password could not be encrypted. Add PTERO_APP_KEY to the site config using the Pterodactyl APP_KEY value, then try again.', 'error', $hostId);
            }

            $connectionError = fbgAdminDatabaseHostsTestConnection($input, (string)$input['password']);
            if ($connectionError !== null) {
                fbgAdminDatabaseHostsRedirect($connectionError, 'error', $hostId);
            }

            $passwordSql = ', password = :password';
            $params['password'] = $encryptedPassword;
        }

        $stmt = fbgPteroDb()->prepare("
            UPDATE database_hosts
            SET name = :name,
                host = :host,
                port = :port,
                username = :username,
                node_id = :node_id,
                updated_at = NOW()
                {$passwordSql}
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute($params);

        fbgAdminDatabaseHostsRedirect('Database host updated successfully.', 'success', $hostId);
    }

    if ($action === 'delete_host') {
        if ($hostId <= 0) {
            fbgAdminDatabaseHostsRedirect('Select a valid database host.', 'error');
        }

        if ((string)($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            fbgAdminDatabaseHostsRedirect('Type DELETE to confirm database host deletion.', 'error', $hostId);
        }

        $countStmt = fbgPteroDb()->prepare('SELECT COUNT(*) FROM `databases` WHERE database_host_id = :id');
        $countStmt->execute(['id' => $hostId]);
        $databaseCount = (int)$countStmt->fetchColumn();

        if ($databaseCount > 0) {
            fbgAdminDatabaseHostsRedirect('Database host cannot be deleted while databases are assigned to it.', 'error', $hostId);
        }

        $stmt = fbgPteroDb()->prepare('DELETE FROM database_hosts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $hostId]);

        fbgAdminDatabaseHostsRedirect('Database host deleted successfully.');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'name');
$direction = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$pageNum = max(1, (int)($_GET['page_num'] ?? 1));
$perPage = 25;
$offset = ($pageNum - 1) * $perPage;
$editHostId = max(0, (int)($_GET['edit'] ?? 0));
$openCreate = isset($_GET['create']) && (string)$_GET['create'] === '1';

$sortMap = [
    'id' => 'dh.id',
    'name' => 'dh.name',
    'host' => 'dh.host',
    'port' => 'dh.port',
    'username' => 'dh.username',
    'databases' => 'database_count',
    'node' => 'node_name',
];
if (!isset($sortMap[$sort])) {
    $sort = 'name';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(dh.name LIKE :search_name OR dh.host LIKE :search_host OR dh.username LIKE :search_username OR CAST(dh.id AS CHAR) = :search_exact)';
    $searchLike = '%' . $search . '%';
    $params['search_name'] = $searchLike;
    $params['search_host'] = $searchLike;
    $params['search_username'] = $searchLike;
    $params['search_exact'] = $search;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM database_hosts dh {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
    $offset = ($pageNum - 1) * $perPage;
}

$orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);
$hostsStmt = fbgPteroDb()->prepare("
    SELECT
        dh.id,
        dh.name,
        dh.host,
        dh.port,
        dh.username,
        dh.node_id,
        dh.created_at,
        dh.updated_at,
        n.name AS node_name,
        COUNT(d.id) AS database_count
    FROM database_hosts dh
    LEFT JOIN nodes n ON n.id = dh.node_id
    LEFT JOIN `databases` d ON d.database_host_id = dh.id
    {$whereSql}
    GROUP BY dh.id, dh.name, dh.host, dh.port, dh.username, dh.node_id, dh.created_at, dh.updated_at, n.name
    ORDER BY {$orderSql}, dh.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$hostsStmt->execute($params);
$databaseHosts = $hostsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editingHost = null;
$editingDatabases = [];
if ($editHostId > 0) {
    $hostStmt = fbgPteroDb()->prepare('
        SELECT
            dh.id,
            dh.name,
            dh.host,
            dh.port,
            dh.username,
            dh.node_id,
            dh.created_at,
            dh.updated_at,
            n.name AS node_name,
            COUNT(d.id) AS database_count
        FROM database_hosts dh
        LEFT JOIN nodes n ON n.id = dh.node_id
        LEFT JOIN `databases` d ON d.database_host_id = dh.id
        WHERE dh.id = :id
        GROUP BY dh.id, dh.name, dh.host, dh.port, dh.username, dh.node_id, dh.created_at, dh.updated_at, n.name
        LIMIT 1
    ');
    $hostStmt->execute(['id' => $editHostId]);
    $editingHost = $hostStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingHost) {
        $databasesStmt = fbgPteroDb()->prepare('
            SELECT
                d.id,
                d.server_id,
                d.database,
                d.username,
                d.remote,
                d.max_connections,
                s.name AS server_name
            FROM `databases` d
            LEFT JOIN servers s ON s.id = d.server_id
            WHERE d.database_host_id = :host_id
            ORDER BY s.name ASC, d.database ASC
        ');
        $databasesStmt->execute(['host_id' => $editHostId]);
        $editingDatabases = $databasesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Database Hosts</h1>
                <p class="fbg-admin-subtext">Manage MySQL hosts that Pterodactyl can use when creating server databases.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Database Hosts Manager',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <h2>Hosts</h2>
                    <a class="btn" href="<?= htmlspecialchars(fbgAdminDatabaseHostsBaseQuery(['create' => 1, 'edit' => null]), ENT_QUOTES, 'UTF-8') ?>">Create Host</a>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-database-hosts">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="database-host-search">Search</label>
                            <input id="database-host-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, name, host, or username">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="database-host-sort">Sort</label>
                            <select id="database-host-sort" name="sort">
                                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID</option>
                                <option value="host" <?= $sort === 'host' ? 'selected' : '' ?>>Host</option>
                                <option value="port" <?= $sort === 'port' ? 'selected' : '' ?>>Port</option>
                                <option value="username" <?= $sort === 'username' ? 'selected' : '' ?>>Username</option>
                                <option value="databases" <?= $sort === 'databases' ? 'selected' : '' ?>>Databases</option>
                                <option value="node" <?= $sort === 'node' ? 'selected' : '' ?>>Linked Node</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="database-host-dir">Direction</label>
                            <select id="database-host-dir" name="dir">
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
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('id', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">ID</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('name', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Name</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('host', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Host</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('port', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Port</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('username', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Username</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('databases', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Databases</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminDatabaseHostsSortUrl('node', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Linked Node</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($databaseHosts)): ?>
                                <tr>
                                    <td colspan="7">No database hosts found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($databaseHosts as $host): ?>
                                <tr>
                                    <td><?= (int)$host['id'] ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminDatabaseHostsBaseQuery(['edit' => (int)$host['id'], 'create' => null]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$host['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><code><?= htmlspecialchars((string)$host['host'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= (int)$host['port'] ?></code></td>
                                    <td><?= htmlspecialchars((string)$host['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int)$host['database_count'] ?></td>
                                    <td><?= htmlspecialchars(fbgAdminDatabaseHostsNodeLabel($host['node_id'] !== null ? (int)$host['node_id'] : null, $nodesById), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="fbg-admin-form-actions">
                    <?php if ($pageNum > 1): ?>
                        <?php $prevQuery = array_merge($_GET, ['page_num' => $pageNum - 1]); unset($prevQuery['edit'], $prevQuery['create']); ?>
                        <a class="btn fbg-neutral-button" href="./page.php?<?= htmlspecialchars(http_build_query($prevQuery), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                    <?php endif; ?>

                    <span><?= number_format($totalRows) ?> total host<?= $totalRows === 1 ? '' : 's' ?>, page <?= $pageNum ?> of <?= $totalPages ?></span>

                    <?php if ($pageNum < $totalPages): ?>
                        <?php $nextQuery = array_merge($_GET, ['page_num' => $pageNum + 1]); unset($nextQuery['edit'], $nextQuery['create']); ?>
                        <a class="btn fbg-neutral-button" href="./page.php?<?= htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($editHostId > 0 && !$editingHost): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-empty-state">
                        <p>Database host could not be found.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($openCreate): ?>
    <div class="fbg-modal-overlay" id="admin-database-host-create-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-database-host-create-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-database-hosts" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-database-host-create-title">Create Database Host</h3>
                <p>Add a MySQL host that Pterodactyl can use when creating server databases.</p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_host">

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="create-host-name">Name</label>
                        <input id="create-host-name" name="host_name" type="text" required maxlength="191" autocomplete="off">
                        <p class="fbg-admin-help-text">A short label used to identify this database host.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-host-address">Host</label>
                        <input id="create-host-address" name="host_address" type="text" required autocomplete="off">
                        <p class="fbg-admin-help-text">The IP address or FQDN Pterodactyl should connect to.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-host-port">Port</label>
                        <input id="create-host-port" name="host_port" type="number" min="1" max="65535" value="3306" required>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-host-username">Username</label>
                        <input id="create-host-username" name="host_username" type="text" required maxlength="32" autocomplete="off">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="create-host-password">Password</label>
                        <input id="create-host-password" name="host_password" type="password" required autocomplete="new-password">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="create-host-node">Linked Node</label>
                        <select id="create-host-node" name="node_id">
                            <option value="0">None</option>
                            <?php foreach ($nodes as $node): ?>
                                <option value="<?= (int)$node['id'] ?>"><?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="fbg-admin-help-text">Defaults this database host when adding a database to a server on the selected node.</p>
                    </div>
                </div>

                <div class="fbg-admin-warning-box">
                    The account defined for this database host must have the <code>WITH GRANT OPTION</code> permission. Do not use the same MySQL account details that power this panel.
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Create Host</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-database-hosts">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($editingHost): ?>
    <?php $editingDatabaseCount = (int)($editingHost['database_count'] ?? 0); ?>
    <div class="fbg-modal-overlay" id="admin-database-host-edit-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-database-host-edit-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-database-hosts" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-database-host-edit-title">Edit <?= htmlspecialchars((string)$editingHost['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p>Update host details, user credentials, and review databases assigned to this host.</p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_host">
                <input type="hidden" name="host_id" value="<?= (int)$editingHost['id'] ?>">

                <h3>Host Details</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="edit-host-name">Name</label>
                        <input id="edit-host-name" name="host_name" type="text" required maxlength="191" value="<?= htmlspecialchars((string)$editingHost['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-host-address">Host</label>
                        <input id="edit-host-address" name="host_address" type="text" required value="<?= htmlspecialchars((string)$editingHost['host'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-host-port">Port</label>
                        <input id="edit-host-port" name="host_port" type="number" min="1" max="65535" required value="<?= (int)$editingHost['port'] ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-host-node">Linked Node</label>
                        <select id="edit-host-node" name="node_id">
                            <option value="0">None</option>
                            <?php foreach ($nodes as $node): ?>
                                <option value="<?= (int)$node['id'] ?>" <?= (int)($editingHost['node_id'] ?? 0) === (int)$node['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="fbg-admin-help-text">Defaults this host when adding a database to a server on the selected node.</p>
                    </div>
                </div>

                <h3>User Details</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field">
                        <label for="edit-host-username">Username</label>
                        <input id="edit-host-username" name="host_username" type="text" required maxlength="32" value="<?= htmlspecialchars((string)$editingHost['username'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field">
                        <label for="edit-host-password">Password</label>
                        <input id="edit-host-password" name="host_password" type="password" autocomplete="new-password" placeholder="Leave blank to keep the current password">
                    </div>
                </div>

                <div class="fbg-admin-warning-box">
                    The account defined for this database host must have the <code>WITH GRANT OPTION</code> permission. Password changes require `PTERO_APP_KEY` to be configured for Laravel-compatible encryption.
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Save Host</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-database-hosts">Cancel</a>

                    <span class="fbg-admin-user-delete-wrap" title="<?= $editingDatabaseCount > 0 ? htmlspecialchars('Remove assigned databases before deleting this host.', ENT_QUOTES, 'UTF-8') : '' ?>">
                        <button
                            type="button"
                            class="btn btn-delete fbg-admin-user-delete-button"
                            id="admin-database-host-delete-open"
                            <?= $editingDatabaseCount > 0 ? 'disabled' : '' ?>
                        >
                            Delete Host
                        </button>

                        <?php if ($editingDatabaseCount > 0): ?>
                            <span class="fbg-admin-help-text fbg-admin-user-delete-note">
                                This host has <?= $editingDatabaseCount ?> assigned database<?= $editingDatabaseCount === 1 ? '' : 's' ?>.
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </form>

            <hr>

            <div class="fbg-admin-panel-header">
                <h2>Associated Databases</h2>
            </div>

            <div class="fbg-admin-table-wrap">
                <table class="fbg-admin-table">
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th>Database Name</th>
                            <th>Username</th>
                            <th>Connections From</th>
                            <th>Max Connections</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($editingDatabases)): ?>
                            <tr>
                                <td colspan="5">No databases are assigned to this host.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($editingDatabases as $database): ?>
                            <tr>
                                <td>
                                    <?php if ((int)($database['server_id'] ?? 0) > 0): ?>
                                        <a class="fbg-admin-branded-link" href="./page.php?name=admin-servers&edit=<?= (int)$database['server_id'] ?>&tab=database">
                                            <?= htmlspecialchars((string)($database['server_name'] ?? ('Server #' . (int)$database['server_id'])), ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars((string)$database['database'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars((string)$database['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$database['remote'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $database['max_connections'] !== null ? (int)$database['max_connections'] : 'Unlimited' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="fbg-modal-overlay fbg-admin-user-delete-confirm-overlay" id="admin-database-host-delete-confirm" hidden>
                <div class="fbg-modal-card fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-database-host-delete-confirm-title">
                    <div class="fbg-modal-header">
                        <h3 id="admin-database-host-delete-confirm-title">Delete Database Host</h3>
                        <p>This is a destructive action and cannot be undone.</p>
                    </div>

                    <form method="POST" id="admin-database-host-delete-form" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_host">
                        <input type="hidden" name="host_id" value="<?= (int)$editingHost['id'] ?>">

                        <div class="fbg-admin-warning-box">
                            Deleting <?= htmlspecialchars((string)$editingHost['name'], ENT_QUOTES, 'UTF-8') ?> will remove this database host from Pterodactyl. Existing MySQL users or databases are not cleaned up by this action.
                        </div>

                        <div class="fbg-admin-field">
                            <label for="admin-database-host-delete-confirm-input">Type DELETE to confirm</label>
                            <input id="admin-database-host-delete-confirm-input" name="delete_confirmation" type="text" autocomplete="off" spellcheck="false">
                        </div>

                        <div class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                            <button type="button" class="btn fbg-neutral-button" id="admin-database-host-delete-cancel">Cancel</button>
                            <button type="submit" class="btn btn-delete fbg-admin-user-delete-confirm-submit" id="admin-database-host-delete-submit" disabled>Delete Host</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('admin-database-host-create-modal')
        || document.getElementById('admin-database-host-edit-modal');
    if (!modal) return;

    document.body.classList.add('fbg-modal-open');

    const deleteOpen = document.getElementById('admin-database-host-delete-open');
    const deleteConfirm = document.getElementById('admin-database-host-delete-confirm');
    const deleteCancel = document.getElementById('admin-database-host-delete-cancel');
    const deleteInput = document.getElementById('admin-database-host-delete-confirm-input');
    const deleteSubmit = document.getElementById('admin-database-host-delete-submit');

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

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (deleteConfirm && !deleteConfirm.hidden) {
                closeDeleteConfirm();
                return;
            }

            window.location.href = './page.php?name=admin-database-hosts';
        }
    });
});
</script>
