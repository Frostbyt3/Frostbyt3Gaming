<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if (!canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fbgShopPlansVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function fbgShopPlansCleanShortUrl(string $shortUrl): string
{
    $shortUrl = trim($shortUrl);
    $shortUrl = ltrim($shortUrl, '/');

    return strtolower($shortUrl);
}

function fbgShopPlansFetchCategories(): array
{
    try {
        $stmt = fbgPteroDb()->query("
            SELECT id, title, short_url, hide, sort
            FROM game_category
            ORDER BY sort ASC, title ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Shop plan category fetch failed: ' . $e->getMessage());

        return [];
    }
}

function fbgShopPlansFetchNodes(): array
{
    try {
        $stmt = fbgPteroDb()->query("
            SELECT id, name, fqdn
            FROM nodes
            ORDER BY name ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Shop plan node fetch failed: ' . $e->getMessage());

        return [];
    }
}

function fbgShopPlansFetchEggs(): array
{
    try {
        $stmt = fbgPteroDb()->query("
            SELECT
                e.id,
                e.name,
                e.nest_id,
                n.name AS nest_name
            FROM eggs e
            LEFT JOIN nests n ON n.id = e.nest_id
            ORDER BY n.name ASC, e.name ASC, e.id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Shop plan egg fetch failed: ' . $e->getMessage());

        return [];
    }
}

function fbgShopPlansCategoryExists(int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $stmt = fbgPteroDb()->prepare("
        SELECT id
        FROM game_category
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $categoryId]);

    return (bool)$stmt->fetchColumn();
}

function fbgShopPlansEggExists(int $eggId): bool
{
    if ($eggId <= 0) {
        return false;
    }

    $stmt = fbgPteroDb()->prepare("
        SELECT id
        FROM eggs
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $eggId]);

    return (bool)$stmt->fetchColumn();
}

function fbgShopPlansValidNodeIds(array $nodeIds): array
{
    $nodeIds = array_values(array_unique(array_filter(array_map('intval', $nodeIds), static fn(int $id): bool => $id > 0)));

    if (empty($nodeIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
    $stmt = fbgPteroDb()->prepare("
        SELECT id
        FROM nodes
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($nodeIds);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return array_values(array_intersect($nodeIds, $validIds));
}

function fbgShopPlansNextSort(int $categoryId): int
{
    try {
        $stmt = fbgPteroDb()->prepare("
            SELECT COALESCE(MAX(sort), 0) + 1
            FROM games
            WHERE category_id = :category_id
        ");
        $stmt->execute(['category_id' => $categoryId]);

        return max(1, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('Shop plan next sort lookup failed: ' . $e->getMessage());

        return 1;
    }
}

function fbgShopPlansFetchAll(?int $categoryId = null): array
{
    try {
        $where = '';
        $params = [];

        if ($categoryId !== null && $categoryId > 0) {
            $where = 'WHERE g.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $stmt = fbgPteroDb()->prepare("
            SELECT
                g.*,
                c.title AS category_title,
                c.sort AS category_sort,
                (
                    SELECT COUNT(*)
                    FROM servers s
                    WHERE s.product_id = g.id
                ) AS server_count
            FROM games g
            LEFT JOIN game_category c ON c.id = g.category_id
            {$where}
            ORDER BY c.sort ASC, g.sort ASC, g.name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Shop plans fetch failed: ' . $e->getMessage());

        return [];
    }
}

function fbgShopPlansFetchOne(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = fbgPteroDb()->prepare("
        SELECT *
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fbgShopPlansNodeIdsFromCsv(string $nodeIds): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(string $id): int => (int)trim($id),
        explode(',', $nodeIds)
    ), static fn(int $id): bool => $id > 0)));
}

$message = '';
$messageType = 'success';
$editing = null;
$categories = fbgShopPlansFetchCategories();
$nodes = fbgShopPlansFetchNodes();
$eggs = fbgShopPlansFetchEggs();
$selectedCategoryId = (int)($_GET['category_id'] ?? 0);

if ($selectedCategoryId <= 0 && !empty($categories)) {
    $selectedCategoryId = (int)$categories[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgShopPlansVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $imageUrl = trim((string)($_POST['image_url'] ?? ''));
        $shortUrl = fbgShopPlansCleanShortUrl((string)($_POST['short_url'] ?? ''));
        $price = round((float)($_POST['price'] ?? 0), 2);
        $eggId = (int)($_POST['egg_id'] ?? 0);
        $nodeIds = fbgShopPlansValidNodeIds((array)($_POST['node_ids'] ?? []));
        $cpu = max(0, (int)($_POST['cpu'] ?? 0));
        $memory = max(0, (int)($_POST['memory'] ?? 0));
        $swap = max(0, (int)($_POST['swap'] ?? 0));
        $disk = max(0, (int)($_POST['disk'] ?? 0));
        $databaseLimit = max(0, (int)($_POST['database_limit'] ?? 0));
        $backupLimit = max(0, (int)($_POST['backup_limit'] ?? 0));
        $allocationLimit = max(0, (int)($_POST['allocation_limit'] ?? 0));
        $sort = max(1, (int)($_POST['sort'] ?? fbgShopPlansNextSort($categoryId)));
        $hide = isset($_POST['is_visible']) ? 0 : 1;

        try {
            if ($action === 'update' && $id <= 0) {
                throw new RuntimeException('Invalid plan update request.');
            }

            if (!fbgShopPlansCategoryExists($categoryId)) {
                throw new RuntimeException('A valid category is required.');
            }

            if ($name === '' || strlen($name) > 40) {
                throw new RuntimeException('Plan name is required and must be 40 characters or fewer.');
            }

            if ($imageUrl === '') {
                throw new RuntimeException('Image URL is required.');
            }

            if ($shortUrl === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $shortUrl)) {
                throw new RuntimeException('Short URL must use letters, numbers, hyphens, or underscores.');
            }

            if ($price <= 0) {
                throw new RuntimeException('Price must be greater than zero.');
            }

            if (!fbgShopPlansEggExists($eggId)) {
                throw new RuntimeException('A valid egg is required.');
            }

            if (empty($nodeIds)) {
                throw new RuntimeException('At least one valid node is required.');
            }

            $duplicateStmt = fbgPteroDb()->prepare("
                SELECT id
                FROM games
                WHERE category_id = :category_id
                AND short_url = :short_url
                AND id != :id
                LIMIT 1
            ");
            $duplicateStmt->execute([
                'category_id' => $categoryId,
                'short_url' => $shortUrl,
                'id' => $action === 'update' ? $id : 0,
            ]);

            if ($duplicateStmt->fetchColumn()) {
                throw new RuntimeException('That short URL is already used by another plan in this category.');
            }

            $payload = [
                'category_id' => $categoryId,
                'name' => $name,
                'image_url' => $imageUrl,
                'short_url' => $shortUrl,
                'egg_id' => $eggId,
                'cpu' => $cpu,
                'memory' => $memory,
                'swap' => $swap,
                'disk' => $disk,
                'database_limit' => $databaseLimit,
                'backup_limit' => $backupLimit,
                'allocation_limit' => $allocationLimit,
                'node_ids' => implode(',', $nodeIds),
                'price' => number_format($price, 2, '.', ''),
                'hide' => $hide,
                'sort' => $sort,
            ];

            if ($action === 'create') {
                $stmt = fbgPteroDb()->prepare("
                    INSERT INTO games (
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
                    ) VALUES (
                        :name,
                        :category_id,
                        :image_url,
                        :short_url,
                        :egg_id,
                        :cpu,
                        :memory,
                        :swap,
                        :disk,
                        :database_limit,
                        :backup_limit,
                        :allocation_limit,
                        :node_ids,
                        :price,
                        :hide,
                        :sort
                    )
                ");
                $stmt->execute($payload);

                $selectedCategoryId = $categoryId;
                $message = 'Plan created successfully.';
                $_POST = [];
            } else {
                $payload['id'] = $id;

                $stmt = fbgPteroDb()->prepare("
                    UPDATE games
                    SET
                        name = :name,
                        category_id = :category_id,
                        image_url = :image_url,
                        short_url = :short_url,
                        egg_id = :egg_id,
                        cpu = :cpu,
                        memory = :memory,
                        swap = :swap,
                        disk = :disk,
                        database_limit = :database_limit,
                        backup_limit = :backup_limit,
                        allocation_limit = :allocation_limit,
                        node_ids = :node_ids,
                        price = :price,
                        hide = :hide,
                        sort = :sort
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute($payload);

                $selectedCategoryId = $categoryId;
                $message = 'Plan updated successfully.';
            }
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Plan could not be saved.';
            $messageType = 'error';
        }
    }

    if ($action === 'toggle_visibility') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $message = 'Invalid visibility request.';
            $messageType = 'error';
        } else {
            $stmt = fbgPteroDb()->prepare("
                UPDATE games
                SET hide = CASE WHEN hide = 1 THEN 0 ELSE 1 END
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Plan visibility updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        try {
            if ($id <= 0) {
                throw new RuntimeException('Invalid delete request.');
            }

            $serverStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM servers WHERE product_id = :id");
            $serverStmt->execute(['id' => $id]);

            if ((int)$serverStmt->fetchColumn() > 0) {
                throw new RuntimeException('Plans attached to existing servers cannot be deleted.');
            }

            $stmt = fbgPteroDb()->prepare("
                DELETE FROM games
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Plan deleted successfully.';

            if (isset($_GET['edit']) && (int)$_GET['edit'] === $id) {
                fbgRedirect('/page.php?name=admin-shop-plans&category_id=' . $selectedCategoryId);
            }
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Plan could not be deleted.';
            $messageType = 'error';
        }
    }

    if ($action === 'reorder') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $orderedIds = $_POST['ordered_ids'] ?? [];

        if ($categoryId <= 0 || !is_array($orderedIds) || empty($orderedIds)) {
            $message = 'Invalid reorder request.';
            $messageType = 'error';
        } else {
            $orderedIds = array_values(array_filter(array_map('intval', $orderedIds), static fn(int $id): bool => $id > 0));

            if (empty($orderedIds)) {
                $message = 'Invalid reorder request.';
                $messageType = 'error';
            } else {
                $pdo = fbgPteroDb();
                $pdo->beginTransaction();

                try {
                    $stmt = $pdo->prepare("
                        UPDATE games
                        SET sort = :sort
                        WHERE id = :id
                        AND category_id = :category_id
                        LIMIT 1
                    ");

                    foreach ($orderedIds as $index => $id) {
                        $stmt->execute([
                            'sort' => $index + 1,
                            'id' => $id,
                            'category_id' => $categoryId,
                        ]);
                    }

                    $pdo->commit();
                    $selectedCategoryId = $categoryId;
                    $message = 'Plan order updated successfully.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = 'Failed to update plan order.';
                    $messageType = 'error';
                }
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $editing = fbgShopPlansFetchOne($editId);

        if ($editing) {
            $selectedCategoryId = (int)$editing['category_id'];
        }
    }
}

$plans = fbgShopPlansFetchAll($selectedCategoryId > 0 ? $selectedCategoryId : null);
$allPlans = fbgShopPlansFetchAll();
$visibleCount = count(array_filter($allPlans, static fn(array $plan): bool => (int)$plan['hide'] === 0));
$currency = fbgGetShopCurrency();
$currentAdminPage = 'admin-shop-plans';
$selectedNodeIds = fbgShopPlansNodeIdsFromCsv((string)($editing['node_ids'] ?? ''));
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Server Plans</h1>
                <p class="fbg-admin-subtext">Manage the purchasable server plans shown on the frontend server shop.</p>
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
                    <h2>Category View</h2>
                </div>

                <form method="GET" class="fbg-admin-form">
                    <input type="hidden" name="name" value="admin-shop-plans">

                    <div class="fbg-admin-compact-action-row">
                        <div class="fbg-admin-field">
                            <label for="shop-plan-filter-category">Category</label>
                            <select id="shop-plan-filter-category" name="category_id" onchange="this.form.submit()">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>" <?= (int)$category['id'] === $selectedCategoryId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$category['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- <div class="fbg-admin-field fbg-admin-compact-action">
                            <a href="./page.php?name=admin-shop-categories" class="btn fbg-neutral-button">Manage Categories</a>
                        </div> -->
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2><?= $editing ? 'Edit Plan' : 'Create Plan' ?></h2>
                </div>

                <?php if (empty($categories)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>Create a shop category before adding plans.</p>
                    </div>
                <?php else: ?>
                    <form method="POST" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">

                        <?php if ($editing): ?>
                            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <?php endif; ?>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="shop-plan-name">Plan Name</label>
                                <input
                                    id="shop-plan-name"
                                    type="text"
                                    name="name"
                                    maxlength="40"
                                    required
                                    value="<?= htmlspecialchars((string)($editing['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-price">Price (<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>)</label>
                                <input
                                    id="shop-plan-price"
                                    type="number"
                                    name="price"
                                    min="0.01"
                                    step="0.01"
                                    required
                                    value="<?= htmlspecialchars((string)($editing['price'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="shop-plan-category">Category</label>
                                <select id="shop-plan-category" name="category_id" required>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int)$category['id'] ?>" <?= (int)$category['id'] === (int)($editing['category_id'] ?? $selectedCategoryId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$category['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-short-url">Short URL</label>
                                <input
                                    id="shop-plan-short-url"
                                    type="text"
                                    name="short_url"
                                    maxlength="20"
                                    required
                                    placeholder="basic"
                                    value="<?= htmlspecialchars((string)($editing['short_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="shop-plan-image-url">Image URL</label>
                            <input
                                id="shop-plan-image-url"
                                type="url"
                                name="image_url"
                                required
                                placeholder="https://example.com/plan.png"
                                value="<?= htmlspecialchars((string)($editing['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="shop-plan-egg">Egg</label>
                                <select id="shop-plan-egg" name="egg_id" required>
                                    <option value="">Select Egg</option>
                                    <?php foreach ($eggs as $egg): ?>
                                        <option value="<?= (int)$egg['id'] ?>" <?= (int)$egg['id'] === (int)($editing['egg_id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(trim((string)($egg['nest_name'] ?? 'Unknown Nest') . ' / ' . (string)$egg['name']), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-sort">Sort Order</label>
                                <input
                                    id="shop-plan-sort"
                                    type="number"
                                    name="sort"
                                    min="1"
                                    value="<?= htmlspecialchars((string)($editing['sort'] ?? fbgShopPlansNextSort($selectedCategoryId)), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="shop-plan-memory">Memory (MB)</label>
                                <input id="shop-plan-memory" type="number" name="memory" min="0" required value="<?= htmlspecialchars((string)($editing['memory'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-disk">Disk (MB)</label>
                                <input id="shop-plan-disk" type="number" name="disk" min="0" required value="<?= htmlspecialchars((string)($editing['disk'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-cpu">CPU (%)</label>
                                <input id="shop-plan-cpu" type="number" name="cpu" min="0" required value="<?= htmlspecialchars((string)($editing['cpu'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-swap">Swap (MB)</label>
                                <input id="shop-plan-swap" type="number" name="swap" min="0" required value="<?= htmlspecialchars((string)($editing['swap'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="shop-plan-databases">Databases</label>
                                <input id="shop-plan-databases" type="number" name="database_limit" min="0" required value="<?= htmlspecialchars((string)($editing['database_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-backups">Backups</label>
                                <input id="shop-plan-backups" type="number" name="backup_limit" min="0" required value="<?= htmlspecialchars((string)($editing['backup_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="shop-plan-ports">Ports</label>
                                <input id="shop-plan-ports" type="number" name="allocation_limit" min="0" required value="<?= htmlspecialchars((string)($editing['allocation_limit'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="fbg-admin-field">
                            <label>Nodes</label>
                            <div class="fbg-admin-option-list">
                                <?php foreach ($nodes as $node): ?>
                                    <label class="fbg-admin-checkbox">
                                        <input
                                            type="checkbox"
                                            name="node_ids[]"
                                            value="<?= (int)$node['id'] ?>"
                                            <?= in_array((int)$node['id'], $selectedNodeIds, true) ? 'checked' : '' ?>
                                        >
                                        <span>
                                            <?= htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (trim((string)($node['fqdn'] ?? '')) !== ''): ?>
                                                <small><?= htmlspecialchars((string)$node['fqdn'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="fbg-admin-field">
                            <label class="fbg-admin-checkbox">
                                <input
                                    type="checkbox"
                                    name="is_visible"
                                    value="1"
                                    <?= !empty($editing) ? ((int)$editing['hide'] === 0 ? 'checked' : '') : 'checked' ?>
                                >
                                <span>Visible on server shop</span>
                            </label>
                        </div>

                        <div class="fbg-admin-form-actions">
                            <button type="submit" class="btn">
                                <?= $editing ? 'Update Plan' : 'Create Plan' ?>
                            </button>

                            <?php if ($editing): ?>
                                <a href="./page.php?name=admin-shop-plans&category_id=<?= $selectedCategoryId ?>" class="btn fbg-neutral-button">
                                    Cancel Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Total Plans</span>
                        <strong><?= count($allPlans) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Visible Plans</span>
                        <strong><?= $visibleCount ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Selected Category</span>
                        <strong><?= count($plans) ?></strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Existing Plans</h2>
                </div>

                <?php if (empty($plans)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No plans are configured for this category yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Preview</th>
                                    <th>Plan</th>
                                    <th>Resources</th>
                                    <th>Provisioning</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody
                                id="shop-plans-sortable"
                                data-fbg-sortable-body
                                data-fbg-sort-form="shop-plans-reorder-form"
                            >
                                <?php foreach ($plans as $plan): ?>
                                    <tr data-sort-id="<?= (int)$plan['id'] ?>" draggable="true">
                                        <td>
                                            <span class="fbg-drag-handle" title="Drag to reorder"><i class="fas fa-bars"></i></span>
                                            <span data-fbg-sort-order-value><?= (int)$plan['sort'] ?></span>
                                        </td>
                                        <td>
                                            <img
                                                class="fbg-admin-plan-preview"
                                                src="<?= htmlspecialchars((string)$plan['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                                alt="<?= htmlspecialchars((string)$plan['name'], ENT_QUOTES, 'UTF-8') ?> preview"
                                                loading="lazy"
                                            >
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$plan['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div style="margin-top: 6px; color: #9fb3c8;">
                                                <?= htmlspecialchars((string)$plan['category_title'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)$plan['short_url'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?= number_format((int)$plan['memory']) ?> MB RAM<br>
                                            <?= (int)$plan['disk'] === 0 ? 'Unlimited' : number_format((int)$plan['disk']) . ' MB' ?> Disk<br>
                                            <?= (int)$plan['cpu'] === 0 ? 'Unlimited' : number_format((int)$plan['cpu']) . '%' ?> CPU
                                        </td>
                                        <td>
                                            Egg #<?= (int)$plan['egg_id'] ?><br>
                                            Nodes: <?= htmlspecialchars((string)$plan['node_ids'], ENT_QUOTES, 'UTF-8') ?><br>
                                            <?= (int)$plan['database_limit'] ?> DB / <?= (int)$plan['backup_limit'] ?> Backups / <?= (int)$plan['allocation_limit'] ?> Ports
                                        </td>
                                        <td><?= htmlspecialchars(fbgFormatCredit((float)$plan['price'], $currency), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= (int)$plan['hide'] === 0 ? 'Visible' : 'Hidden' ?>
                                            <?php if ((int)$plan['server_count'] > 0): ?>
                                                <div style="margin-top: 6px; color: #9fb3c8;"><?= (int)$plan['server_count'] ?> linked server<?= (int)$plan['server_count'] === 1 ? '' : 's' ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <a href="./page.php?name=admin-shop-plans&category_id=<?= (int)$plan['category_id'] ?>&edit=<?= (int)$plan['id'] ?>" class="btn btn-sm">
                                                    Edit
                                                </a>

                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="toggle_visibility">
                                                    <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

                                                    <button type="submit" class="btn btn-sm fbg-neutral-button">
                                                        <?= (int)$plan['hide'] === 0 ? 'Hide' : 'Show' ?>
                                                    </button>
                                                </form>

                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this plan? Plans with linked servers cannot be deleted.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

                                                    <button type="submit" class="btn btn-sm btn-delete" <?= (int)$plan['server_count'] > 0 ? 'disabled' : '' ?>>
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <form method="POST" id="shop-plans-reorder-form" style="display: none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="reorder">
                            <input type="hidden" name="category_id" value="<?= $selectedCategoryId ?>">
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
