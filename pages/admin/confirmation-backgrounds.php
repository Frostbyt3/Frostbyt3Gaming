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

$currentAdminPage = 'admin-confirmation-backgrounds';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = (string)($_SESSION['admin_confirmation_backgrounds_message'] ?? '');
$messageType = (string)($_SESSION['admin_confirmation_backgrounds_message_type'] ?? 'success');
unset($_SESSION['admin_confirmation_backgrounds_message'], $_SESSION['admin_confirmation_backgrounds_message_type']);

function fbgAdminConfirmationBackgroundsRedirect(string $message, string $type = 'success'): void
{
    $_SESSION['admin_confirmation_backgrounds_message'] = $message;
    $_SESSION['admin_confirmation_backgrounds_message_type'] = $type;
    fbgRedirect('/page.php?name=admin-confirmation-backgrounds');
    exit;
}

function fbgAdminConfirmationBackgroundsVerifyCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        fbgAdminConfirmationBackgroundsRedirect('Security check failed. Please refresh and try again.', 'error');
    }
}

function fbgAdminConfirmationBackgroundsSlug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: 'confirmation';
    return trim($slug, '-') ?: 'confirmation';
}

function fbgAdminConfirmationBackgroundsHandleUpload(string $eggMatch): string
{
    $upload = $_FILES['confirmation_image_upload'] ?? null;

    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image upload failed. Please try again.');
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('The uploaded image could not be read.');
    }

    $originalName = (string)($upload['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Upload a JPG, PNG, or WEBP image.');
    }

    $uploadDir = dirname(__DIR__, 2) . '/backend/img/backgrounds';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('The background image folder could not be created.');
    }

    $filename = fbgAdminConfirmationBackgroundsSlug($eggMatch) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }

    return '/backend/img/backgrounds/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fbgAdminConfirmationBackgroundsVerifyCsrf();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_background') {
        $backgroundId = max(0, (int)($_POST['background_id'] ?? 0));
        $deleted = fbgDeleteConfirmationBackground($backgroundId);
        fbgAdminConfirmationBackgroundsRedirect(
            $deleted
                ? 'Confirmation image removed.'
                : 'Confirmation image could not be removed.',
            $deleted ? 'success' : 'error'
        );
    }

    if ($action === 'save_background') {
        try {
            $eggMatch = trim((string)($_POST['egg_match'] ?? ''));
            $imagePath = trim((string)($_POST['confirmation_image_url'] ?? ''));
            $uploadedPath = fbgAdminConfirmationBackgroundsHandleUpload($eggMatch);

            if ($uploadedPath !== '') {
                $imagePath = $uploadedPath;
            }

            $result = fbgSaveConfirmationBackground($eggMatch, $imagePath);
            fbgAdminConfirmationBackgroundsRedirect(
                !empty($result['ok']) ? 'Confirmation image saved.' : (string)($result['error'] ?? 'Confirmation image could not be saved.'),
                !empty($result['ok']) ? 'success' : 'error'
            );
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Confirmation image could not be saved.';
            fbgAdminConfirmationBackgroundsRedirect($message, 'error');
        }
    }
}

$backgrounds = fbgGetConfirmationBackgrounds();
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main fbg-admin-confirmation-backgrounds-page">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Shop Settings</p>
                <h1>Confirmation Images</h1>
                <p class="fbg-admin-subtext">Match server eggs to artwork used on order confirmation modals.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'Confirmation Backgrounds Manager',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Add Confirmation Image</h2>
                </div>

                <form method="POST" enctype="multipart/form-data" class="fbg-admin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="save_background">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="egg-match">Egg Name</label>
                            <input id="egg-match" name="egg_match" type="text" maxlength="120" placeholder="minecraft" required autocomplete="off">
                            <p class="fbg-admin-help-text">Partial matches are supported. Example: minecraft will match Minecraft Java Edition.</p>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="confirmation-image-url">Confirmation Image URL</label>
                            <input id="confirmation-image-url" name="confirmation_image_url" type="text" placeholder="/backend/img/backgrounds/minecraft.png">
                            <p class="fbg-admin-help-text">Use a hosted image URL, or upload an image below.</p>
                        </div>

                        <div class="fbg-admin-field fbg-admin-field-full">
                            <label for="confirmation-image-upload">Upload Image</label>
                            <input id="confirmation-image-upload" name="confirmation_image_upload" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <p class="fbg-admin-help-text">Uploaded images are stored in <code>/backend/img/backgrounds</code> and replace any URL entered above.</p>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn btn-sm">Save Confirmation Image</button>
                    </div>
                </form>
            </section>

            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <div>
                        <h2>Current Images</h2>
                        <p>Configured backgrounds are checked by partial egg name match.</p>
                    </div>
                </div>

                <?php if (empty($backgrounds)): ?>
                    <div class="fbg-admin-empty-state">
                        <p>No confirmation images have been configured yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fbg-admin-confirmation-background-grid">
                        <?php foreach ($backgrounds as $background): ?>
                            <?php
                            $imagePath = fbgNormalizeConfirmationBackgroundPath((string)($background['image_path'] ?? ''));
                            $eggMatch = (string)($background['egg_match'] ?? '');
                            ?>
                            <article class="fbg-admin-confirmation-background-card">
                                <button
                                    type="button"
                                    class="fbg-admin-confirmation-background-preview"
                                    data-egg-match="<?= htmlspecialchars($eggMatch, ENT_QUOTES, 'UTF-8') ?>"
                                    data-image-path="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="Preview <?= htmlspecialchars($eggMatch, ENT_QUOTES, 'UTF-8') ?> confirmation image"
                                >
                                    <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($eggMatch . ' confirmation background', ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                </button>
                                <div>
                                    <span>Egg Name</span>
                                    <strong><?= htmlspecialchars($eggMatch, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <code><?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?></code>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_background">
                                    <input type="hidden" name="background_id" value="<?= (int)$background['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-delete">Delete</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.fbg-admin-confirmation-background-preview').forEach((button) => {
        button.addEventListener('click', () => {
            if (typeof window.FBGPurchaseConfirmation !== 'function') {
                return;
            }

            const eggMatch = button.dataset.eggMatch || 'Game Server';
            const imagePath = button.dataset.imagePath || '';

            window.FBGPurchaseConfirmation({
                type: 'server_rental',
                title: 'Thanks for your order!',
                message: 'Your purchase was completed successfully.',
                label: 'Server Rental',
                backgroundImage: imagePath,
                eggName: eggMatch,
                gameName: eggMatch,
                planName: 'Test Plan',
                details: [
                    { label: 'Plan', value: 'Test Plan' },
                    { label: 'Game', value: eggMatch },
                    { label: 'Duration', value: '30 days' },
                    { label: 'Expiration Date', value: 'Oct 15, 2042' }
                ],
                totals: [
                    { label: 'Price', value: '5.99 USD' },
                    { label: 'Tax 7.25%', value: '0.43 USD' },
                    { label: 'Total', value: '6.42 USD', total: true }
                ],
                balance: {
                    label: 'Remaining Balance',
                    value: '22.82 USD'
                },
                note: 'Your server is being provisioned and installed and will appear in your dashboard shortly.',
                invoice: {
                    number: 'FBG-999999',
                    url: '#'
                },
                actions: [
                    {
                        label: 'Dashboard',
                        url: '#'
                    },
                    {
                        label: 'Server Panel',
                        url: '#',
                        primary: true
                    }
                ]
            });
        });
    });
});
</script>
