<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php'; // adjust as needed

function getVisibleGameCategories(): array
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, title, image_url, short_url
        FROM panel.game_category
        WHERE hide = 0
        ORDER BY sort ASC, title ASC
    ");

    $stmt->execute();

    return $stmt->fetchAll();
}

$categories = getVisibleGameCategories();
?>

<section class="server-title">
    <h1>Our Servers</h1>
    <p>Select a game below to start your order.</p>
</section>

<section class="server-cards">
    <div class="cards">
        <?php if (empty($categories)): ?>
            <p>No server categories are available right now.</p>
        <?php else: ?>
            <?php foreach ($categories as $category): ?>
                <div class="servercard">
                    <h2><?= htmlspecialchars((string)$category['title']) ?></h2>

                    <img src="<?= htmlspecialchars((string)$category['image_url']) ?>" alt="<?= htmlspecialchars((string)$category['title']) ?>">

                    <?php
                        $slug = urlencode((string)$category['short_url']);
                    ?>

                    <a href="./page.php?name=order&plan=<?= $slug ?>" class="btn">
                        Open Panel Shop
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>