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

function fbgShopCategoriesVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function fbgShopCategoriesFetchAll(): array
{
    try {
        $stmt = fbgPteroDb()->query("
            SELECT
                c.id,
                c.title,
                c.short_url,
                c.image_url,
                c.hide,
                c.sort,
                (
                    SELECT COUNT(*)
                    FROM games g
                    WHERE g.category_id = c.id
                ) AS plan_count
            FROM game_category c
            ORDER BY c.sort ASC, c.title ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('Shop categories fetch failed: ' . $e->getMessage());

        return [];
    }
}

function fbgShopCategoriesNextSort(): int
{
    try {
        $stmt = fbgPteroDb()->query("SELECT COALESCE(MAX(sort), 0) + 1 FROM game_category");

        return max(1, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('Shop category next sort lookup failed: ' . $e->getMessage());

        return 1;
    }
}

function fbgShopCategoriesCleanShortUrl(string $shortUrl): string
{
    $shortUrl = trim($shortUrl);
    $shortUrl = ltrim($shortUrl, '/');

    return strtolower($shortUrl);
}

$message = '';
$messageType = 'success';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgShopCategoriesVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim(strip_tags((string)($_POST['title'] ?? '')));
        $shortUrl = fbgShopCategoriesCleanShortUrl((string)($_POST['short_url'] ?? ''));
        $imageUrl = trim((string)($_POST['image_url'] ?? ''));
        $sort = max(1, (int)($_POST['sort'] ?? fbgShopCategoriesNextSort()));
        $hide = isset($_POST['is_visible']) ? 0 : 1;

        try {
            if ($title === '') {
                throw new RuntimeException('Category title is required.');
            }

            if ($shortUrl === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $shortUrl)) {
                throw new RuntimeException('Short URL must use letters, numbers, hyphens, or underscores.');
            }

            if ($imageUrl === '') {
                throw new RuntimeException('Image URL is required.');
            }

            $duplicateStmt = fbgPteroDb()->prepare("
                SELECT id
                FROM game_category
                WHERE short_url = :short_url
                AND id != :id
                LIMIT 1
            ");
            $duplicateStmt->execute([
                'short_url' => $shortUrl,
                'id' => $action === 'update' ? $id : 0,
            ]);

            if ($duplicateStmt->fetchColumn()) {
                throw new RuntimeException('That short URL is already used by another category.');
            }

            if ($action === 'create') {
                $stmt = fbgPteroDb()->prepare("
                    INSERT INTO game_category
                        (title, short_url, image_url, hide, sort)
                    VALUES
                        (:title, :short_url, :image_url, :hide, :sort)
                ");
                $stmt->execute([
                    'title' => $title,
                    'short_url' => $shortUrl,
                    'image_url' => $imageUrl,
                    'hide' => $hide,
                    'sort' => $sort,
                ]);

                $message = 'Category created successfully.';
                $_POST = [];
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('Invalid category update request.');
                }

                $stmt = fbgPteroDb()->prepare("
                    UPDATE game_category
                    SET
                        title = :title,
                        short_url = :short_url,
                        image_url = :image_url,
                        hide = :hide,
                        sort = :sort
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    'id' => $id,
                    'title' => $title,
                    'short_url' => $shortUrl,
                    'image_url' => $imageUrl,
                    'hide' => $hide,
                    'sort' => $sort,
                ]);

                $message = 'Category updated successfully.';
            }
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Category could not be saved.';
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
                UPDATE game_category
                SET hide = CASE WHEN hide = 1 THEN 0 ELSE 1 END
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Category visibility updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        try {
            if ($id <= 0) {
                throw new RuntimeException('Invalid delete request.');
            }

            $planStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM games WHERE category_id = :id");
            $planStmt->execute(['id' => $id]);

            if ((int)$planStmt->fetchColumn() > 0) {
                throw new RuntimeException('Categories with existing plans cannot be deleted.');
            }

            $stmt = fbgPteroDb()->prepare("
                DELETE FROM game_category
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Category deleted successfully.';

            if (isset($_GET['edit']) && (int)$_GET['edit'] === $id) {
                fbgRedirect('/page.php?name=admin-shop-categories');
            }
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Category could not be deleted.';
            $messageType = 'error';
        }
    }

    if ($action === 'reorder') {
        $orderedIds = $_POST['ordered_ids'] ?? [];

        if (!is_array($orderedIds) || empty($orderedIds)) {
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
                        UPDATE game_category
                        SET sort = :sort
                        WHERE id = :id
                        LIMIT 1
                    ");

                    foreach ($orderedIds as $index => $id) {
                        $stmt->execute([
                            'sort' => $index + 1,
                            'id' => $id,
                        ]);
                    }

                    $pdo->commit();

                    $message = 'Category order updated successfully.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = 'Failed to update category order.';
                    $messageType = 'error';
                }
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = fbgPteroDb()->prepare("
            SELECT id, title, short_url, image_url, hide, sort
            FROM game_category
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $editId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $editing = $row;
        }
    }
}

$categories = fbgShopCategoriesFetchAll();
$visibleCount = count(array_filter($categories, static fn(array $category): bool => (int)$category['hide'] === 0));
$currentAdminPage = 'admin-shop-categories';
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop</p>
                <h1>Server Categories</h1>
                <p class="fbg-admin-subtext">Manage the category groups shown on the frontend server shop.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2><?= $editing ? 'Edit Category' : 'Create Category' ?></h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">

                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="shop-category-title">Title</label>
                            <input
                                id="shop-category-title"
                                type="text"
                                name="title"
                                maxlength="191"
                                required
                                value="<?= htmlspecialchars((string)($editing['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field">
                            <label for="shop-category-sort">Sort Order</label>
                            <input
                                id="shop-category-sort"
                                type="number"
                                name="sort"
                                min="1"
                                value="<?= htmlspecialchars((string)($editing['sort'] ?? fbgShopCategoriesNextSort()), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="shop-category-short-url">Short URL</label>
                        <input
                            id="shop-category-short-url"
                            type="text"
                            name="short_url"
                            maxlength="20"
                            required
                            placeholder="minecraft"
                            value="<?= htmlspecialchars((string)($editing['short_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <p class="fbg-admin-help-text">Used by the shop plugin as the category slug. Keep this stable once plans exist.</p>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="shop-category-image-url">Image URL</label>
                        <input
                            id="shop-category-image-url"
                            type="url"
                            name="image_url"
                            required
                            placeholder="https://example.com/category.png"
                            value="<?= htmlspecialchars((string)($editing['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >
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
                            <?= $editing ? 'Update Category' : 'Create Category' ?>
                        </button>

                        <?php if ($editing): ?>
                            <a href="./page.php?name=admin-shop-categories" class="btn fbg-neutral-button">
                                Cancel Edit
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Total Categories</span>
                        <strong><?= count($categories) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Visible Categories</span>
                        <strong><?= $visibleCount ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Ordering Column</span>
                        <strong>sort</strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Existing Categories</h2>
                </div>

                <?php if (empty($categories)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No shop categories created yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Category</th>
                                    <th>Short URL</th>
                                    <th>Plans</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody
                                id="shop-categories-sortable"
                                data-fbg-sortable-body
                                data-fbg-sort-form="shop-categories-reorder-form"
                            >
                                <?php foreach ($categories as $category): ?>
                                    <tr
                                        data-sort-id="<?= (int)$category['id'] ?>"
                                        draggable="true"
                                    >
                                        <td>
                                            <span class="fbg-drag-handle" title="Drag to reorder"><i class="fas fa-bars"></i></span>
                                            <span data-fbg-sort-order-value><?= (int)$category['sort'] ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$category['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div style="margin-top: 6px; color: #9fb3c8; word-break: break-all;">
                                                <?= htmlspecialchars((string)$category['image_url'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>
                                        <td><code>/<?= htmlspecialchars((string)$category['short_url'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><?= (int)$category['plan_count'] ?></td>
                                        <td><?= (int)$category['hide'] === 0 ? 'Visible' : 'Hidden' ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <a href="./page.php?name=admin-shop-categories&edit=<?= (int)$category['id'] ?>" class="btn btn-sm">
                                                    Edit
                                                </a>

                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="toggle_visibility">
                                                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">

                                                    <button type="submit" class="btn btn-sm fbg-neutral-button">
                                                        <?= (int)$category['hide'] === 0 ? 'Hide' : 'Show' ?>
                                                    </button>
                                                </form>

                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category? Categories with plans cannot be deleted.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">

                                                    <button type="submit" class="btn btn-sm btn-delete" <?= (int)$category['plan_count'] > 0 ? 'disabled' : '' ?>>
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <form method="POST" id="shop-categories-reorder-form" style="display: none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="reorder">
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>