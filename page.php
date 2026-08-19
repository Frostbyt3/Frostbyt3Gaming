<?php
require_once __DIR__ . '/includes/error-handling.php';

session_start();
include_once('./includes/functions.php');
require_once __DIR__ . '/includes/auth.php';

fbgAttemptRememberMeLogin();

$page = isset($_GET['name']) ? strtolower($_GET['name']) : 'home';

if ($page === 'logout') {
    fbgLogout();
    fbgRedirect('./page.php?name=home');
    exit;
}

$allowed = [
    'servers'               => 'servers.php',
    'news'                  => 'news.php',
    'community'             => 'community.php',
    'home'                  => 'home.php',
    'leave'                 => 'leave.php',
    'login'                 => 'login.php',
    'dashboard'             => 'dashboard.php',
    'account'               => 'account.php',
    'wallet'                => 'wallet.php',
    'credit'                => 'wallet.php',
    'verify-email-change'   => 'verify-email-change.php',
    'order'                 => 'order.php',
    'legal'                 => 'legal.php',
    'register'              => 'register.php',
    'serverpanel'           => 'serverpanel.php',
    'complete-registration' => 'complete-registration.php',
    'resend-verification'   => 'resend-verification.php',
    'verify'                => 'verify.php',
    'maintenance'           => 'maintenance.php',

    // Admin
    'admin-home'            => 'admin/home.php',
    'admin-articles'        => 'admin/articlemanager.php',
    'admin-users'           => 'admin/users.php',
    'admin-servers'         => 'admin/servers.php',
    'admin-image-upload'    => 'admin/image.php',
    'admin-file-upload'     => 'admin/fileupload.php',
    'admin-link-shortener'  => 'admin/shorten.php',
    'admin-service-manager' => 'admin/servicemanager.php',
    'admin-payments'        => 'admin/payments.php',
    'admin-shop-categories' => 'admin/categories.php',
    'admin-shop-plans'      => 'admin/plans.php',
    'admin-registrations'   => 'admin/registrations.php',
    'admin-settings'        => 'admin/settings.php',
    'admin-webp-png'        => 'admin/webp2png.php',

    // Error pages
    '403'                   => 'errors/403.php',
    '404'                   => 'errors/404.php',
];

if (!array_key_exists($page, $allowed)) {
    // $page = 'home';
    http_response_code(404);
    $page = '404';
}

$rawAction = trim((string)($_POST['action'] ?? ''));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $page === 'admin-servers'
    && in_array($rawAction, ['fetch_expiration_history'], true)
) {
    include('./pages/' . $allowed[$page]);
    exit;
}

include('./includes/header.php');

if (
    fbgIsMaintenanceMode()
    && !fbgCurrentUserCanBypassMaintenance()
    && $page !== 'maintenance'
) {
    $page = 'maintenance';
}

?>

<main>
    <?php include('./pages/' . $allowed[$page]); ?>
</main>

<footer>
<?php include('./includes/community.php'); ?>
</footer>

<!-- localStorage.removeItem("fbg_privacy_notice_dismissed"); -->
<div id="fbgPrivacyNotice" class="fbg-privacy-notice" hidden>
    <button type="button" class="fbg-privacy-notice__close" id="fbgPrivacyNoticeClose" aria-label="Close notice">
        &times;
    </button>

    <div class="fbg-privacy-notice__title">Privacy Notice</div>

    <div class="fbg-privacy-notice__text">
        Frostbyt3 Gaming uses essential session storage/cookies for login, security, and core site functionality.
        We do not use advertising or analytics trackers at this time.
        <a href="/page.php?name=legal">Learn more</a>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const notice = document.getElementById("fbgPrivacyNotice");
        const closeBtn = document.getElementById("fbgPrivacyNoticeClose");

        if (!notice || !closeBtn) return;

        const storageKey = "fbg_privacy_notice_dismissed";

        if (localStorage.getItem(storageKey) !== "1") {
            notice.hidden = false;
            requestAnimationFrame(() => {
                notice.classList.add("is-visible");
            });
        }

        closeBtn.addEventListener("click", function () {
            localStorage.setItem(storageKey, "1");
            notice.classList.remove("is-visible");

            setTimeout(() => {
                notice.hidden = true;
            }, 200);
        });
    });
</script>
</body>
</html>
