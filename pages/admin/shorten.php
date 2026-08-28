<?php
    declare(strict_types=1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';

    requireLogin();

    if (!canAccess(4)) {
        http_response_code(403);
        fbgRedirect('/page.php?name=403');
        return;
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $currentAdminPage = 'admin-link-shortener';
    $rootDir = dirname(__DIR__, 2);
    $message = null;
    $messageType = 'success';
    $createdShortUrl = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
            $message = 'Security check failed. Mismatched token.';
            $messageType = 'error';
        } else {
            $action = (string)($_POST['action'] ?? 'create');

            if ($action === 'create') {
                $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
                $url  = trim((string)($_POST['url'] ?? ''));

                if ($slug === '' || $url === '') {
                    $message = 'Slug and destination URL are required.';
                    $messageType = 'error';
                } elseif (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    $message = 'Slug can only contain lowercase letters, numbers, and hyphens.';
                    $messageType = 'error';
                } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                    $message = 'Invalid destination URL.';
                    $messageType = 'error';
                } elseif (file_exists($rootDir . '/' . $slug)) {
                    $message = "'{$slug}' conflicts with an existing file or folder.";
                    $messageType = 'error';
                } else {
                    $checkStmt = db()->prepare('
                        SELECT id
                        FROM short_links
                        WHERE slug = :slug
                        LIMIT 1
                    ');
                    $checkStmt->execute(['slug' => $slug]);
                    $existing = $checkStmt->fetch();

                    if ($existing) {
                        $message = 'That short link already exists.';
                        $messageType = 'error';
                    } else {
                        $insertStmt = db()->prepare('
                            INSERT INTO short_links (slug, destination_url, is_active)
                            VALUES (:slug, :url, 1)
                        ');
                        $insertStmt->execute([
                            'slug' => $slug,
                            'url'  => $url,
                        ]);

                        $createdShortUrl = 'https://frostbyt3gaming.com/' . $slug;
                        $message = "Short link **'" . $slug . "'** created successfully.";
                        $messageType = 'success';
                    }
                }
            } elseif ($action === 'delete') {
                $slug = trim((string)($_POST['slug'] ?? ''));

                if ($slug === '') {
                    $message = 'Invalid delete request.';
                    $messageType = 'error';
                } else {
                    $deleteStmt = db()->prepare('
                        DELETE FROM short_links
                        WHERE slug = :slug
                        LIMIT 1
                    ');
                    $deleteStmt->execute(['slug' => $slug]);

                    $message = "Short link **'" . $slug . "'** deleted successfully.";
                    $messageType = 'warning';
                }
            }
        }
    }

    $stmt = db()->query('
        SELECT slug, destination_url
        FROM short_links
        WHERE is_active = 1
        ORDER BY slug ASC
    ');
    $links = $stmt->fetchAll();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Link Shortener</h1>
                <p class="fbg-admin-subtext">Create and manage short Frostbyt3 Gaming links for panels, projects, pages, and public resources.</p>
            </div>
        </header>

        <?php if ($message !== null): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Short Links Manager',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Create Short Link</h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="shortener-slug">Short Name</label>
                            <input id="shortener-slug" type="text" name="slug" placeholder="e.g. panel" required>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="shortener-url">Destination URL</label>
                            <input id="shortener-url" type="url" name="url" placeholder="https://panel.frostbyt3gaming.com/" required>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Create Short Link</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel">
                <div class="fbg-admin-panel-header">
                    <h2>Overview</h2>
                </div>

                <div class="fbg-admin-stat-list">
                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Active Links</span>
                        <strong><?= count($links) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Route Prefix</span>
                        <strong>frostbyt3gaming.com/slug</strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Status</span>
                        <strong>Active</strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Existing Short Links</h2>
                </div>

                <?php if (empty($links)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No short links created yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Slug</th>
                                    <th>Destination</th>
                                    <th>Short URL</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $link): ?>
                                    <?php
                                        $slug = (string)$link['slug'];
                                        $url = (string)$link['destination_url'];
                                        $shortUrl = 'https://frostbyt3gaming.com/' . $slug;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <button type="button" class="btn btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8') ?>', this)">
                                                    Copy
                                                </button>

                                                <form method="POST" class="fbg-admin-inline-form" onsubmit="event.preventDefault(); const form = this; window.FBGConfirm('Delete Short Link', 'Are you sure you want to delete this short link? This action cannot be undone.', 'Delete', 'Cancel', { variant: 'danger' }).then((confirmed) => { if (confirmed) form.submit(); }); return false;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
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
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<script>
    function copyToClipboard(text, element) {
        navigator.clipboard.writeText(text).then(function () {
            const original = element.innerHTML;
            element.innerHTML = 'Copied!';

            setTimeout(function () {
                element.innerHTML = original;
            }, 2000);
        }).catch(function (err) {
            console.error('Clipboard copy failed:', err);
        });
    }
</script>
