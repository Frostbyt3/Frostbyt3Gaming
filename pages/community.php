<?php include_once("./includes/communityevents.php"); ?>
<head>
    <link rel="stylesheet" href="<?php echo asset('./backend/css/news-css.css'); ?>">
</head>
<section class="news-page">
    <h1>Community & Events</h1>
    <p class="subtitle">Join the discussion, connect with players, and be part of Frostbyt3 Gaming</p>

    <div class="news-container">
        <?php foreach ($communityposts as $post): ?>
            <article class="news-post">
                <img src="<?= $post['img'] ?>" alt="<?= $post['title'] ?>" class="news-thumb">
                <div class="news-content">
                    <h2><?= $post['title'] ?></h2>
                    <p class="news-date"><?= $post['date'] ?></p>
                    <p class="news-excerpt"><?= $post['excerpt'] ?></p>
                    <?php if (!empty($post['btnlink'])): ?>
                        <p style="text-align: center; align-items: center;"><a href="<?= $post['btnlink'] ?>" class="cta-button">Test Button</a></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>