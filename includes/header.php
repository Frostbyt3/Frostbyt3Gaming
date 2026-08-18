<?php include_once('./includes/functions.php'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="Frostbyt3 Gaming">
    <meta property="og:description" content="Powering the games you love">
    <meta property="og:image" content="https://frostbyt3gaming.com/backend/img/Snowflake.png">
    <meta property="og:url" content="https://frostbyt3gaming.com/">
    <meta property="og:type" content="website">
    <title>Frostbyt3 Gaming - <?php echo getRandomTitle(); ?></title>
    <link rel="stylesheet" href="<?php echo asset('/backend/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/backend/css/mobile.css'); ?>">
    <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css"> -->
    <script src="https://kit.fontawesome.com/8cbb51ac38.js" crossorigin="anonymous"></script>
    <link rel="icon" type="image/png" href="<?php echo asset('./backend/img/favicon.png'); ?>" sizes="16x16">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        function toggleMenu() {
            document.querySelector('#navMenu ul').classList.toggle('show');
        }
    </script>
    <script src="<?php echo asset('./backend/js/external-redirect.js'); ?>"></script>
</head>
<body>
    <header>
        <div class="logo">
            <a href="/page.php?name=home">
                <img src="./backend/img/logo.png" alt="Frostbyt3 Gaming Logo">
            </a>
            <h2 class="splashText"><?php echo getRandomTitle(); ?></h2>
        </div>
        <div class="hamburger" onclick="toggleMenu()">
            <i class="fas fa-bars"></i>
        </div>
        <nav id="navMenu">
            <ul>
                <li><a href="/page.php?name=home">Home</a></li>
                <li><a href="/page.php?name=servers">Servers</a></li>
                <li><a href="/page.php?name=news">News</a></li>
                
                <?php if (canAccess(4)): ?>
                    <li><a href="/page.php?name=admin-home">Admin</a></li>
                <?php endif; ?>

                <?php if (isset($_SESSION['user_id'])): ?>
                <?php
                $displayName = $_SESSION['username'];
                $email = $_SESSION['email'] ?? '';
                $avatarUrl = getGravatarUrl($email, 24);
                $creditBalance = fbgGetUserCreditBalance((int)($_SESSION['user_id'] ?? 0));
                $shopCurrency = fbgGetShopCurrency();

                if (!empty($_SESSION['name'])) {
                    $displayName = explode(' ', $_SESSION['name'])[0];
                }
                ?>

                <li class="nav-user-menu">
                    <button class="nav-user-trigger" type="button">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar">
                        <?php echo htmlspecialchars($displayName); ?>
                        <span class="nav-caret">▾</span>
                    </button>

                    <div class="nav-user-dropdown">
                        <div class="nav-user-summary">
                            <div class="nav-user-name"><?php echo htmlspecialchars($displayName); ?></div>
                            <div class="nav-user-email"><?php echo htmlspecialchars($email); ?></div>
                            <div class="nav-user-credit">
                                <span>Account Balance</span>
                                <a href="/page.php?name=credit"><strong>$<?php echo htmlspecialchars(fbgFormatCredit($creditBalance, $shopCurrency)); ?></strong></a>
                            </div>
                        </div>

                        <hr>

                        <a href="/page.php?name=dashboard"><i class="fas fa-house"></i> Dashboard</a>

                        <!-- <?php if (canAccess(4)): ?>
                            <a href="https://panel.frostbyt3gaming.com/"><i class="fas fa-suitcase"></i> Backend Panel</a>
                        <?php endif; ?> -->

                        <hr>
                        <a href="/page.php?name=account"><i class="fas fa-user"></i> Manage Profile</a>
                        <a href="/page.php?name=wallet"><i class="fas fa-credit-card"></i> Manage Wallet</a>
                        <a href="/page.php?name=logout" class="danger"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
                    </div>
                </li>

                <?php else: ?>
                    <li><a href="/page.php?name=register">Register</a></li>
                    <li><a href="/page.php?name=login">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const menus = document.querySelectorAll(".nav-user-menu");

            if (!menus.length) return;

            menus.forEach((menu) => {
                const trigger = menu.querySelector(".nav-user-trigger");
                if (!trigger) return;

                trigger.addEventListener("click", (e) => {
                    e.stopPropagation();

                    menus.forEach((otherMenu) => {
                        if (otherMenu !== menu) {
                            otherMenu.classList.remove("open");
                        }
                    });

                    menu.classList.toggle("open");
                });
            });

            document.addEventListener("click", (e) => {
                menus.forEach((menu) => {
                    if (!menu.contains(e.target)) {
                        menu.classList.remove("open");
                    }
                });
            });

            document.addEventListener("keydown", (e) => {
                if (e.key === "Escape") {
                    menus.forEach((menu) => menu.classList.remove("open"));
                }
            });
        });
    </script>
