<?php

require_once __DIR__ . '/../includes/error-handling.php';

include_once './backend/includes/functions.php';

// Sanitize page name
$page = isset($_GET['name']) ? strtolower($_GET['name']) : 'home';

// Map of allowed pages
$allowed = [
    'home'          =>  'home.php',
    'servers'       =>  'servers.php',
    'news'          =>  'news.php',
    'community'     =>  'community.php'
];

// Default to home if invalid
if (!array_key_exists($page, $allowed)) {
    $page = 'home';
}
?>
<?php include './backend/includes/header.php'; ?>

    <body>
        <main>
            <?php include './pages/' . $allowed[$page]; ?>
        </main>

        <footer class="fucking-footer">
            <?php include './backend/includes/footer.php'; ?>
        </footer>
        
    </body>
</html>
