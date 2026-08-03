<?php
require_once __DIR__ . '/../includes/db.php';

$articleSlug = trim((string)($_GET['article'] ?? ''));
$selectedArticle = null;

if ($articleSlug !== '') {
    $articleSlug = preg_replace('/[^a-z0-9\-]+/i', '', $articleSlug) ?? '';

    if ($articleSlug !== '') {
        $stmt = db()->prepare("
            SELECT id, slug, title, excerpt, content, image_url, published_at
            FROM articles
            WHERE slug = :slug
              AND is_published = 1
            LIMIT 1
        ");
        $stmt->execute(['slug' => $articleSlug]);
        $selectedArticle = $stmt->fetch();
    }
}

if ($articleSlug !== '' && empty($selectedArticle)) {
    http_response_code(404);
}

$stmt = db()->query("
    SELECT id, slug, title, excerpt, content, image_url, published_at
    FROM articles
    WHERE is_published = 1
    ORDER BY published_at DESC, id DESC
");

$articles = $stmt->fetchAll();
?>

<section class="news-hero">
    <div class="news-page">
        <?php if (!empty($selectedArticle)): ?>
            <h1><?= htmlspecialchars((string)$selectedArticle['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="subtitle">
                <?= !empty($selectedArticle['published_at']) ? date('F j, Y @ g:i A T', strtotime((string)$selectedArticle['published_at'])) : 'News & Updates' ?>
            </p>

            <div class="news-container">
                <article class="news-post">
                    <img
                        src="<?= htmlspecialchars((string)($selectedArticle['image_url'] ?? '/assets/images/news/default.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars((string)$selectedArticle['title'], ENT_QUOTES, 'UTF-8') ?>"
                        class="news-thumb"
                    >

                    <div class="news-content">
                        <div class="news-excerpt">
                            <?= $selectedArticle['content'] ?? '' ?>
                        </div>

                        <p style="margin-top: 24px;">
                            <a href="/page.php?name=news" class="btn fbg-neutral-button">Back to News</a>
                        </p>
                    </div>
                </article>
            </div>
        <?php else: ?>
            <h1>News & Updates</h1>
            <p class="subtitle">
                <?= $articleSlug !== '' ? 'That article could not be found.' : 'Stay up to date with the latest from Frostbyt3 Gaming.' ?>
            </p>

            <div class="news-container">
                <?php foreach ($articles as $index => $article): ?>
                    <article class="news-post">
                        <img
                            src="<?= htmlspecialchars($article['image_url'] ?? '/assets/images/news/default.jpg') ?>"
                            alt="<?= htmlspecialchars($article['title']) ?>"
                            class="news-thumb"
                        >

                        <div class="news-content">
                            <h2>
                                <a href="/page.php?name=news&amp;article=<?= htmlspecialchars((string)$article['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($article['title']) ?>
                                </a>
                                <?php if ($index === 0): ?>
                                    <span class="news-new">NEW</span>
                                <?php endif; ?>
                            </h2>

                            <p class="news-date">
                                <?= !empty($article['published_at']) ? date('F j, Y @ g:i A T', strtotime($article['published_at'])) : '' ?>
                            </p>

                            <div class="news-excerpt">
                                <?= $article['content'] ?? '' ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
