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

$currentAdminPage = 'admin-locations';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_locations_message'] ?? '');
$messageType = (string)($_SESSION['admin_locations_message_type'] ?? 'success');
unset($_SESSION['admin_locations_message'], $_SESSION['admin_locations_message_type']);

function fbgAdminLocationsRedirect(string $message, string $type = 'success', ?int $editLocationId = null, bool $openCreate = false): void
{
    $_SESSION['admin_locations_message'] = $message;
    $_SESSION['admin_locations_message_type'] = $type;

    $url = '/page.php?name=admin-locations';
    if ($editLocationId !== null && $editLocationId > 0) {
        $url .= '&edit=' . $editLocationId;
    } elseif ($openCreate) {
        $url .= '&create=1';
    }

    fbgRedirect($url);
    exit;
}

function fbgAdminLocationsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminLocationsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminLocationsNormalizeInput(): array
{
    return [
        'short' => trim((string)($_POST['location_short'] ?? '')),
        'long' => trim((string)($_POST['location_long'] ?? '')),
    ];
}

function fbgAdminLocationsValidateInput(array $input): ?string
{
    if ($input['short'] === '' || strlen((string)$input['short']) > 60) {
        return 'Short code is required and must be 60 characters or fewer.';
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string)$input['short'])) {
        return 'Short code may only contain letters, numbers, dots, underscores, and hyphens.';
    }

    if (strlen((string)$input['long']) > 191) {
        return 'Description must be 191 characters or fewer.';
    }

    return null;
}

function fbgAdminLocationsShortExists(string $short, ?int $exceptLocationId = null): bool
{
    $sql = 'SELECT id FROM locations WHERE short = :short';
    $params = ['short' => $short];

    if ($exceptLocationId !== null && $exceptLocationId > 0) {
        $sql .= ' AND id <> :id';
        $params['id'] = $exceptLocationId;
    }

    $sql .= ' LIMIT 1';

    $stmt = fbgPteroDb()->prepare($sql);
    $stmt->execute($params);

    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function fbgAdminLocationsSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
{
    $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
    $query = $_GET;
    $query['name'] = 'admin-locations';
    $query['sort'] = $targetSort;
    $query['dir'] = $direction;
    $query['page_num'] = 1;
    unset($query['edit'], $query['create']);

    return './page.php?' . http_build_query($query);
}

function fbgAdminLocationsBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query['name'] = 'admin-locations';

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return './page.php?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminLocationsVerifyCsrf();

    $action = (string)($_POST['action'] ?? '');
    $locationId = max(0, (int)($_POST['location_id'] ?? 0));

    if ($action === 'create_location') {
        $input = fbgAdminLocationsNormalizeInput();
        $error = fbgAdminLocationsValidateInput($input);
        if ($error !== null) {
            fbgAdminLocationsRedirect($error, 'error', null, true);
        }

        if (fbgAdminLocationsShortExists((string)$input['short'])) {
            fbgAdminLocationsRedirect('A location with that short code already exists.', 'error', null, true);
        }

        $stmt = fbgPteroDb()->prepare('
            INSERT INTO locations (short, `long`, created_at, updated_at)
            VALUES (:short, :long, NOW(), NOW())
        ');
        $stmt->execute([
            'short' => $input['short'],
            'long' => $input['long'],
        ]);

        fbgAdminLocationsRedirect('Location created successfully.');
    }

    if ($action === 'update_location') {
        if ($locationId <= 0) {
            fbgAdminLocationsRedirect('Select a valid location.', 'error');
        }

        $locationStmt = fbgPteroDb()->prepare('SELECT id FROM locations WHERE id = :id LIMIT 1');
        $locationStmt->execute(['id' => $locationId]);
        if ((int)($locationStmt->fetchColumn() ?: 0) <= 0) {
            fbgAdminLocationsRedirect('Location could not be found.', 'error');
        }

        $input = fbgAdminLocationsNormalizeInput();
        $error = fbgAdminLocationsValidateInput($input);
        if ($error !== null) {
            fbgAdminLocationsRedirect($error, 'error', $locationId);
        }

        if (fbgAdminLocationsShortExists((string)$input['short'], $locationId)) {
            fbgAdminLocationsRedirect('A location with that short code already exists.', 'error', $locationId);
        }

        $stmt = fbgPteroDb()->prepare('
            UPDATE locations
            SET short = :short,
                `long` = :long,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $locationId,
            'short' => $input['short'],
            'long' => $input['long'],
        ]);

        fbgAdminLocationsRedirect('Location updated successfully.', 'success', $locationId);
    }

    if ($action === 'delete_location') {
        if ($locationId <= 0) {
            fbgAdminLocationsRedirect('Select a valid location.', 'error');
        }

        if ((string)($_POST['delete_confirmation'] ?? '') !== 'DELETE') {
            fbgAdminLocationsRedirect('Type DELETE to confirm location deletion.', 'error', $locationId);
        }

        $countStmt = fbgPteroDb()->prepare('SELECT COUNT(*) FROM nodes WHERE location_id = :id');
        $countStmt->execute(['id' => $locationId]);
        $nodeCount = (int)$countStmt->fetchColumn();

        if ($nodeCount > 0) {
            fbgAdminLocationsRedirect('Location cannot be deleted while nodes are assigned to it.', 'error', $locationId);
        }

        $stmt = fbgPteroDb()->prepare('DELETE FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $locationId]);

        fbgAdminLocationsRedirect('Location deleted successfully.');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'short');
$direction = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$perPage = 25;
$pageNum = fbgPaginationRequestedPage();
$offset = ($pageNum - 1) * $perPage;
$editLocationId = max(0, (int)($_GET['edit'] ?? 0));
$openCreate = isset($_GET['create']) && (string)$_GET['create'] === '1';

$sortMap = [
    'id' => 'l.id',
    'short' => 'l.short',
    'description' => 'l.long',
    'nodes' => 'node_count',
    'servers' => 'server_count',
];
if (!isset($sortMap[$sort])) {
    $sort = 'short';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(l.short LIKE :search_short OR l.long LIKE :search_long OR CAST(l.id AS CHAR) = :search_exact)';
    $searchLike = '%' . $search . '%';
    $params['search_short'] = $searchLike;
    $params['search_long'] = $searchLike;
    $params['search_exact'] = $search;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM locations l {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
$pageNum = $pagination['page_num'];
$totalPages = $pagination['total_pages'];
$offset = $pagination['offset'];

$orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);
$locationsStmt = fbgPteroDb()->prepare("
    SELECT
        l.id,
        l.short,
        l.long,
        l.created_at,
        l.updated_at,
        COUNT(DISTINCT n.id) AS node_count,
        COUNT(DISTINCT s.id) AS server_count
    FROM locations l
    LEFT JOIN nodes n ON n.location_id = l.id
    LEFT JOIN servers s ON s.node_id = n.id
    {$whereSql}
    GROUP BY l.id, l.short, l.long, l.created_at, l.updated_at
    ORDER BY {$orderSql}, l.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$locationsStmt->execute($params);
$locations = $locationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editingLocation = null;
$editingNodes = [];
if ($editLocationId > 0) {
    $locationStmt = fbgPteroDb()->prepare('
        SELECT
            l.id,
            l.short,
            l.long,
            l.created_at,
            l.updated_at,
            COUNT(DISTINCT n.id) AS node_count,
            COUNT(DISTINCT s.id) AS server_count
        FROM locations l
        LEFT JOIN nodes n ON n.location_id = l.id
        LEFT JOIN servers s ON s.node_id = n.id
        WHERE l.id = :id
        GROUP BY l.id, l.short, l.long, l.created_at, l.updated_at
        LIMIT 1
    ');
    $locationStmt->execute(['id' => $editLocationId]);
    $editingLocation = $locationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editingLocation) {
        $nodesStmt = fbgPteroDb()->prepare('
            SELECT
                n.id,
                n.name,
                n.fqdn,
                COUNT(s.id) AS server_count
            FROM nodes n
            LEFT JOIN servers s ON s.node_id = n.id
            WHERE n.location_id = :location_id
            GROUP BY n.id, n.name, n.fqdn
            ORDER BY n.name ASC
        ');
        $nodesStmt->execute(['location_id' => $editLocationId]);
        $editingNodes = $nodesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Locations</h1>
                <p class="fbg-admin-subtext">Manage the location labels used to organize Pterodactyl nodes.</p>
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
                    <h2>Location List</h2>
                    <a class="btn" href="<?= htmlspecialchars(fbgAdminLocationsBaseQuery(['create' => 1, 'edit' => null]), ENT_QUOTES, 'UTF-8') ?>">Create Location</a>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-locations">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="location-search">Search</label>
                            <input id="location-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, short code, or description">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="location-sort">Sort</label>
                            <select id="location-sort" name="sort">
                                <option value="short" <?= $sort === 'short' ? 'selected' : '' ?>>Short Code</option>
                                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID</option>
                                <option value="description" <?= $sort === 'description' ? 'selected' : '' ?>>Description</option>
                                <option value="nodes" <?= $sort === 'nodes' ? 'selected' : '' ?>>Nodes</option>
                                <option value="servers" <?= $sort === 'servers' ? 'selected' : '' ?>>Servers</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="location-dir">Direction</label>
                            <select id="location-dir" name="dir">
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
                                <th><a href="<?= htmlspecialchars(fbgAdminLocationsSortUrl('id', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">ID</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminLocationsSortUrl('short', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Short Code</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminLocationsSortUrl('description', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Description</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminLocationsSortUrl('nodes', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Nodes</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminLocationsSortUrl('servers', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Servers</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($locations)): ?>
                                <tr>
                                    <td colspan="5">No locations found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($locations as $location): ?>
                                <tr>
                                    <td><?= (int)$location['id'] ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="<?= htmlspecialchars(fbgAdminLocationsBaseQuery(['edit' => (int)$location['id'], 'create' => null]), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$location['short'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars((string)$location['long'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int)$location['node_count'] ?></td>
                                    <td><?= (int)$location['server_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'location', ['remove' => ['edit', 'create']]); ?>
            </section>

            <?php if ($editLocationId > 0 && !$editingLocation): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-empty-state">
                        <p>Location could not be found.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($openCreate): ?>
    <div class="fbg-modal-overlay" id="admin-location-create-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-location-create-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-locations" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-location-create-title">Create Location</h3>
                <p>Add a short location code and description for node organization.</p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_location">

                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="create-location-short">Short Code</label>
                        <input id="create-location-short" name="location_short" type="text" required maxlength="60" autocomplete="off" placeholder="us.ut.hooper">
                        <p class="fbg-admin-help-text">A short identifier used to distinguish this location from others. Must be between 1 and 60 characters.</p>
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="create-location-long">Description</label>
                        <textarea id="create-location-long" name="location_long" maxlength="191" rows="5" placeholder="Hooper, UT"></textarea>
                        <p class="fbg-admin-help-text">A longer description of this location. Must be less than 191 characters.</p>
                    </div>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Create Location</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-locations">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($editingLocation): ?>
    <?php $editingNodeCount = (int)($editingLocation['node_count'] ?? 0); ?>
    <div class="fbg-modal-overlay" id="admin-location-edit-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-location-edit-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-locations" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-location-edit-title">Edit <?= htmlspecialchars((string)$editingLocation['short'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars((string)$editingLocation['long'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <form method="POST" class="fbg-admin-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_location">
                <input type="hidden" name="location_id" value="<?= (int)$editingLocation['id'] ?>">

                <h3>Location Details</h3>
                <div class="fbg-admin-form-grid">
                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="edit-location-short">Short Code</label>
                        <input id="edit-location-short" name="location_short" type="text" required maxlength="60" value="<?= htmlspecialchars((string)$editingLocation['short'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="fbg-admin-field fbg-admin-field-full">
                        <label for="edit-location-long">Description</label>
                        <textarea id="edit-location-long" name="location_long" maxlength="191" rows="5"><?= htmlspecialchars((string)$editingLocation['long'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                    <button type="submit" class="btn">Save Location</button>
                    <a class="btn fbg-neutral-button" href="./page.php?name=admin-locations">Cancel</a>

                    <span class="fbg-admin-user-delete-wrap" title="<?= $editingNodeCount > 0 ? htmlspecialchars('Move assigned nodes before deleting this location.', ENT_QUOTES, 'UTF-8') : '' ?>">
                        <button
                            type="button"
                            class="btn btn-delete fbg-admin-user-delete-button"
                            id="admin-location-delete-open"
                            <?= $editingNodeCount > 0 ? 'disabled' : '' ?>
                        >
                            Delete Location
                        </button>

                        <?php if ($editingNodeCount > 0): ?>
                            <span class="fbg-admin-help-text fbg-admin-user-delete-note">
                                This location has <?= $editingNodeCount ?> assigned node<?= $editingNodeCount === 1 ? '' : 's' ?>.
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </form>

            <hr>

            <div class="fbg-admin-panel-header">
                <h2>Nodes</h2>
            </div>

            <div class="fbg-admin-table-wrap">
                <table class="fbg-admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>FQDN</th>
                            <th>Servers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($editingNodes)): ?>
                            <tr>
                                <td colspan="4">No nodes are assigned to this location.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($editingNodes as $node): ?>
                            <tr>
                                <td><?= (int)$node['id'] ?></td>
                                <td><?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><code><?= htmlspecialchars((string)$node['fqdn'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= (int)$node['server_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="fbg-modal-overlay fbg-admin-user-delete-confirm-overlay" id="admin-location-delete-confirm" hidden>
                <div class="fbg-modal-card fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-location-delete-confirm-title">
                    <div class="fbg-modal-header">
                        <h3 id="admin-location-delete-confirm-title">Delete Location</h3>
                        <p>This is a destructive action and cannot be undone.</p>
                    </div>

                    <form method="POST" id="admin-location-delete-form" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_location">
                        <input type="hidden" name="location_id" value="<?= (int)$editingLocation['id'] ?>">

                        <div class="fbg-admin-warning-box">
                            Deleting <?= htmlspecialchars((string)$editingLocation['short'], ENT_QUOTES, 'UTF-8') ?> will remove this location from Pterodactyl. Nodes must be moved before this action is available.
                        </div>

                        <div class="fbg-admin-field">
                            <label for="admin-location-delete-confirm-input">Type DELETE to confirm</label>
                            <input id="admin-location-delete-confirm-input" name="delete_confirmation" type="text" autocomplete="off" spellcheck="false">
                        </div>

                        <div class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                            <button type="button" class="btn fbg-neutral-button" id="admin-location-delete-cancel">Cancel</button>
                            <button type="submit" class="btn btn-delete fbg-admin-user-delete-confirm-submit" id="admin-location-delete-submit" disabled>Delete Location</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('admin-location-create-modal')
        || document.getElementById('admin-location-edit-modal');
    if (!modal) return;

    document.body.classList.add('fbg-modal-open');

    const deleteOpen = document.getElementById('admin-location-delete-open');
    const deleteConfirm = document.getElementById('admin-location-delete-confirm');
    const deleteCancel = document.getElementById('admin-location-delete-cancel');
    const deleteInput = document.getElementById('admin-location-delete-confirm-input');
    const deleteSubmit = document.getElementById('admin-location-delete-submit');

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

            window.location.href = './page.php?name=admin-locations';
        }
    });
});
</script>
