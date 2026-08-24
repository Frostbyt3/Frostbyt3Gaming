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

function serviceCardsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function serviceCardsTableName(): string
{
    return 'server_cards';
}

function serviceCardsFetchAll(): array
{
    $table = serviceCardsTableName();

    $stmt = db()->query("
        SELECT
            id,
            title,
            body,
            btnlink,
            buttontext,
            sort_order,
            is_active,
            created_at,
            updated_at
        FROM {$table}
        ORDER BY sort_order ASC, id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

$message = '';
$messageType = 'success';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    serviceCardsVerifyCsrf();

    $action = trim((string)($_POST['action'] ?? ''));
    $table  = serviceCardsTableName();

    if ($action === 'create') {
        $title      = trim((string)($_POST['title'] ?? ''));
        $body       = trim((string)($_POST['body'] ?? ''));
        $btnlink    = trim((string)($_POST['btnlink'] ?? ''));
        $buttontext = trim((string)($_POST['buttontext'] ?? ''));
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $message = 'Title is required.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("
                INSERT INTO {$table}
                    (title, body, btnlink, buttontext, sort_order, is_active)
                VALUES
                    (:title, :body, :btnlink, :buttontext, :sort_order, :is_active)
            ");

            $stmt->execute([
                'title'      => $title,
                'body'       => $body !== '' ? $body : null,
                'btnlink'    => $btnlink !== '' ? $btnlink : null,
                'buttontext' => $buttontext !== '' ? $buttontext : null,
                'sort_order' => $sortOrder,
                'is_active'  => $isActive,
            ]);

            $message = 'Service card created successfully.';
            $messageType = 'success';
            $_POST = [];
        }
    }

    if ($action === 'update') {
        $id         = (int)($_POST['id'] ?? 0);
        $title      = trim((string)($_POST['title'] ?? ''));
        $body       = trim((string)($_POST['body'] ?? ''));
        $btnlink    = trim((string)($_POST['btnlink'] ?? ''));
        $buttontext = trim((string)($_POST['buttontext'] ?? ''));
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $title === '') {
            $message = 'Invalid service card update request.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("
                UPDATE {$table}
                SET
                    title = :title,
                    body = :body,
                    btnlink = :btnlink,
                    buttontext = :buttontext,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                'id'         => $id,
                'title'      => $title,
                'body'       => $body !== '' ? $body : null,
                'btnlink'    => $btnlink !== '' ? $btnlink : null,
                'buttontext' => $buttontext !== '' ? $buttontext : null,
                'sort_order' => $sortOrder,
                'is_active'  => $isActive,
            ]);

            $message = 'Service card updated successfully.';
            $messageType = 'success';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $message = 'Invalid delete request.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("
                DELETE FROM {$table}
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Service card deleted successfully.';
            $messageType = 'success';

            if (isset($_GET['edit']) && (int)$_GET['edit'] === $id) {
                fbgRedirect('/page.php?name=admin-service-manager');
            }
        }
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $message = 'Invalid toggle request.';
            $messageType = 'error';
        } else {
            $stmt = db()->prepare("
                UPDATE {$table}
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $id]);

            $message = 'Service card status updated.';
            $messageType = 'success';
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
                $pdo = db();
                $pdo->beginTransaction();

                try {
                    $stmt = $pdo->prepare("
                        UPDATE {$table}
                        SET sort_order = :sort_order
                        WHERE id = :id
                        LIMIT 1
                    ");

                    foreach ($orderedIds as $index => $id) {
                        $stmt->execute([
                            'sort_order' => $index + 1,
                            'id'         => $id,
                        ]);
                    }

                    $pdo->commit();

                    $message = 'Service card order updated successfully.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = 'Failed to update service card order.';
                    $messageType = 'error';
                }
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $table = serviceCardsTableName();

        $stmt = db()->prepare("
            SELECT
                id,
                title,
                body,
                btnlink,
                buttontext,
                sort_order,
                is_active
            FROM {$table}
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

$cards = serviceCardsFetchAll();
$currentAdminPage = 'admin-service-manager';
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Service Cards</h1>
                <p class="fbg-admin-subtext">Manage the cards shown in the “Our Services” section on the homepage.</p>
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
                    <h2><?= $editing ? 'Edit Service Card' : 'Create Service Card' ?></h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">

                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="service-card-title">Title</label>
                            <input
                                id="service-card-title"
                                type="text"
                                name="title"
                                maxlength="150"
                                required
                                value="<?= htmlspecialchars((string)($editing['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field">
                            <label for="service-card-sort-order">Sort Order</label>
                            <input
                                id="service-card-sort-order"
                                type="number"
                                name="sort_order"
                                value="<?= htmlspecialchars((string)($editing['sort_order'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                    </div>

                    <div class="fbg-admin-field">
                        <label for="service-card-body">Body</label>
                        <textarea
                            id="service-card-body"
                            name="body"
                            rows="6"
                        ><?= htmlspecialchars((string)($editing['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="service-card-btnlink">Button Link</label>
                            <input
                                id="service-card-btnlink"
                                type="text"
                                name="btnlink"
                                maxlength="255"
                                placeholder="https://example.com/ or /page.php?name=servers"
                                value="<?= htmlspecialchars((string)($editing['btnlink'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field">
                            <label for="service-card-buttontext">Button Text</label>
                            <input
                                id="service-card-buttontext"
                                type="text"
                                name="buttontext"
                                maxlength="100"
                                placeholder="See More"
                                value="<?= htmlspecialchars((string)($editing['buttontext'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                    </div>

                    <div class="fbg-admin-field">
                        <label class="fbg-admin-checkbox">
                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                <?= !empty($editing) ? ((int)$editing['is_active'] === 1 ? 'checked' : '') : 'checked' ?>
                            >
                            <span>Active</span>
                        </label>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">
                            <?= $editing ? 'Update Service Card' : 'Create Service Card' ?>
                        </button>

                        <?php if ($editing): ?>
                            <a href="./page.php?name=admin-service-manager" class="btn fbg-neutral-button">
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
                        <span class="fbg-admin-stat-label">Total Cards</span>
                        <strong><?= count($cards) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Active Cards</span>
                        <strong><?= count(array_filter($cards, static fn(array $card): bool => (int)$card['is_active'] === 1)) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Homepage Section</span>
                        <strong>Our Services</strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Existing Service Cards</h2>
                </div>

                <?php if (empty($cards)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No service cards created yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Title</th>
                                    <th>Button</th>
                                    <th>Link</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="service-cards-sortable">
                                <?php foreach ($cards as $card): ?>
                                    <tr
                                        data-card-id="<?= (int)$card['id'] ?>"
                                        draggable="true"
                                    >
                                        <td>
                                            <span class="fbg-drag-handle" title="Drag to reorder"><i class="fas fa-bars"></i></span>
                                            <span class="fbg-service-card-order-value"><?= (int)$card['sort_order'] ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$card['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if (!empty($card['body'])): ?>
                                                <div style="margin-top: 6px; color: #9fb3c8;">
                                                    <?= nl2br(htmlspecialchars((string)$card['body'], ENT_QUOTES, 'UTF-8')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($card['buttontext'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string)($card['btnlink'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= (int)$card['is_active'] === 1 ? 'Active' : 'Hidden' ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($card['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <a href="./page.php?name=admin-service-manager&edit=<?= (int)$card['id'] ?>" class="btn btn-sm">
                                                    Edit
                                                </a>

                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">

                                                    <button type="submit" class="btn btn-sm fbg-neutral-button">
                                                        <?= (int)$card['is_active'] === 1 ? 'Hide' : 'Show' ?>
                                                    </button>
                                                </form>

                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this service card?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">

                                                    <button type="submit" class="btn btn-sm btn-delete">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <form method="POST" id="service-cards-reorder-form" style="display: none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="reorder">
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>