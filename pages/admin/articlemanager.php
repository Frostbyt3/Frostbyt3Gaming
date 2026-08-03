<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if (!function_exists('canAccess') || !canAccess(4)) {
    http_response_code(403);
    fbgRedirect('/page.php?name=403');
    return;
}

// --------------------------------------------------
// CSRF
// --------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------
function makeSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim((string)$slug, '-');

    return $slug !== '' ? $slug : 'article-' . time();
}

function uniqueArticleSlug(PDO $pdo, string $baseSlug, ?int $excludeId = null): string
{
    $slug = $baseSlug;
    $i = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = ?');
            $stmt->execute([$slug]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $i;
        $i++;
    }
}

$message = null;
$messageType = 'success';
$editing = null;

// --------------------------------------------------
// Handle POST actions
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $title        = trim((string)($_POST['title'] ?? ''));
        $slugInput    = trim((string)($_POST['slug'] ?? ''));
        $excerpt      = trim((string)($_POST['excerpt'] ?? ''));
        $content      = (string)($_POST['content'] ?? '');
        $imageUrl     = trim((string)($_POST['image_url'] ?? ''));
        $isPublished  = isset($_POST['is_published']) ? 1 : 0;
        $publishedAt  = trim((string)($_POST['published_at'] ?? ''));

        if ($title === '') {
            $message = 'Title is required.';
            $messageType = 'error';
        } else {
            $baseSlug = $slugInput !== '' ? makeSlug($slugInput) : makeSlug($title);
            $slug = uniqueArticleSlug(db(), $baseSlug);

            $publishedAtValue = $publishedAt !== '' ? $publishedAt : ($isPublished ? date('Y-m-d H:i:s') : null);

            $stmt = db()->prepare('
                INSERT INTO articles
                    (slug, title, excerpt, content, image_url, is_published, published_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $slug,
                $title,
                $excerpt,
                $content,
                $imageUrl !== '' ? $imageUrl : null,
                $isPublished,
                $publishedAtValue,
            ]);

            $message = 'Article created successfully.';
            $messageType = 'success';
            $_POST = [];
        }
    }

    if ($action === 'update') {
        $id           = (int)($_POST['id'] ?? 0);
        $title        = trim((string)($_POST['title'] ?? ''));
        $slugInput    = trim((string)($_POST['slug'] ?? ''));
        $excerpt      = trim((string)($_POST['excerpt'] ?? ''));
        $content      = (string)($_POST['content'] ?? '');
        $imageUrl     = trim((string)($_POST['image_url'] ?? ''));
        $isPublished  = isset($_POST['is_published']) ? 1 : 0;
        $publishedAt  = trim((string)($_POST['published_at'] ?? ''));

        if ($id <= 0 || $title === '') {
            $message = 'Invalid article update request.';
            $messageType = 'error';
        } else {
            $baseSlug = $slugInput !== '' ? makeSlug($slugInput) : makeSlug($title);
            $slug = uniqueArticleSlug(db(), $baseSlug, $id);

            $publishedAtValue = $publishedAt !== '' ? $publishedAt : ($isPublished ? date('Y-m-d H:i:s') : null);

            $stmt = db()->prepare('
                UPDATE articles
                SET
                    slug = ?,
                    title = ?,
                    excerpt = ?,
                    content = ?,
                    image_url = ?,
                    is_published = ?,
                    published_at = ?
                WHERE id = ?
            ');

            $stmt->execute([
                $slug,
                $title,
                $excerpt,
                $content,
                $imageUrl !== '' ? $imageUrl : null,
                $isPublished,
                $publishedAtValue,
                $id,
            ]);

            $message = 'Article updated successfully.';
            $messageType = 'success';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM articles WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Article deleted successfully.';
            $messageType = 'success';
        } else {
            $message = 'Invalid delete request.';
            $messageType = 'error';
        }
    }
}

// --------------------------------------------------
// Load edit target if requested
// --------------------------------------------------
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = db()->prepare('SELECT * FROM articles WHERE id = ?');
        $stmt->execute([$editId]);
        $editing = $stmt->fetch();
    }
}

// --------------------------------------------------
// Load articles list
// --------------------------------------------------
$stmt = db()->query('
    SELECT id, slug, title, image_url, is_published, published_at
    FROM articles
    ORDER BY
        COALESCE(published_at, "1970-01-01 00:00:00") DESC,
        id DESC
');

$articles = $stmt->fetchAll();
$articleBaseUrl = 'https://frostbyt3gaming.com/page.php?name=news&article=';
?>

<?php $currentAdminPage = 'admin-articles'; ?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Article Manager</h1>
                <p class="fbg-admin-subtext">Manage news articles, drafts, published posts, and article metadata for the website.</p>
            </div>
        </header>

        <?php if ($message !== null): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-wide">
                <div class="fbg-admin-panel-header">
                    <h2><?= $editing ? 'Edit Article' : 'Create Article' ?></h2>
                </div>

                <form method="POST" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">

                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="article-title">Title</label>
                            <input
                                id="article-title"
                                type="text"
                                name="title"
                                required
                                value="<?= htmlspecialchars((string)($editing['title'] ?? ($_POST['title'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field">
                            <label for="article-slug">Slug</label>
                            <input
                                id="article-slug"
                                type="text"
                                name="slug"
                                placeholder="Leave blank to auto-generate from title"
                                value="<?= htmlspecialchars((string)($editing['slug'] ?? ($_POST['slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field">
                            <label for="article-image-url">Image URL</label>
                            <input
                                id="article-image-url"
                                type="text"
                                name="image_url"
                                placeholder="/assets/images/news/default.jpg"
                                value="<?= htmlspecialchars((string)($editing['image_url'] ?? ($_POST['image_url'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="article-published-at">Published At</label>
                            <input
                                id="article-published-at"
                                type="text"
                                name="published_at"
                                placeholder="YYYY-MM-DD HH:MM:SS"
                                value="<?= htmlspecialchars((string)($editing['published_at'] ?? ($_POST['published_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="article-excerpt">Excerpt</label>
                            <textarea
                                id="article-excerpt"
                                name="excerpt"
                                rows="5"
                            ><?= htmlspecialchars((string)($editing['excerpt'] ?? ($_POST['excerpt'] ?? '')), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="article-content">Full Content</label>
                            <textarea
                                id="article-content"
                                name="content"
                                rows="16"
                            ><?= htmlspecialchars((string)($editing['content'] ?? ($_POST['content'] ?? '')), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label class="fbg-admin-checkbox">
                                <input
                                    type="checkbox"
                                    name="is_published"
                                    value="1"
                                    <?= !empty($editing['is_published']) || isset($_POST['is_published']) ? 'checked' : '' ?>
                                >
                                <span>Published</span>
                            </label>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">
                            <?= $editing ? 'Update Article' : 'Create Article' ?>
                        </button>

                        <?php if ($editing): ?>
                            <a href="./page.php?name=admin-articles" class="btn fbg-neutral-button">
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
                        <span class="fbg-admin-stat-label">Total Articles</span>
                        <strong><?= count($articles) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Published</span>
                        <strong><?= count(array_filter($articles, static fn(array $article): bool => (int)$article['is_published'] === 1)) ?></strong>
                    </div>

                    <div class="fbg-admin-stat">
                        <span class="fbg-admin-stat-label">Mode</span>
                        <strong><?= $editing ? 'Editing' : 'Creating' ?></strong>
                    </div>
                </div>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Existing Articles</h2>
                </div>

                <?php if (empty($articles)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No articles created yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Slug</th>
                                    <th>Published</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($articles as $article): ?>
                                    <?php
                                    $articleUrl = $articleBaseUrl . rawurlencode((string)$article['slug']);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$article['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <span><?= htmlspecialchars((string)$article['slug'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm"
                                                    onclick="copyArticleLink('<?= htmlspecialchars($articleUrl, ENT_QUOTES, 'UTF-8') ?>', this)"
                                                >
                                                    Copy Link
                                                </button>
                                            </div>
                                        </td>
                                        <td><?= (int)$article['is_published'] === 1 ? 'Yes' : 'No' ?></td>
                                        <td><?= !empty($article['published_at']) ? htmlspecialchars((string)$article['published_at'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                        <td>
                                            <div class="fbg-admin-table-actions">
                                                <a href="./page.php?name=admin-articles&edit=<?= (int)$article['id'] ?>" class="btn btn-sm">
                                                    Edit
                                                </a>

                                                <form method="POST" class="fbg-admin-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-delete"
                                                        onclick="return confirm('Delete this article?')"
                                                    >
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
function copyArticleLink(text, element) {
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
