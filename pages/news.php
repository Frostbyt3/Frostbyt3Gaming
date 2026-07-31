<?php
require_once __DIR__ . '/../includes/db.php';

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
        <h1>News & Updates</h1>
        <p class="subtitle">Stay up to date with the latest from Frostbyt3 Gaming.</p>

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
                            <?= htmlspecialchars($article['title']) ?>
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
    </div>
</section>