<?php
declare(strict_types=1);

// Default fallback
$defaultUrl = 'https://frostbyt3gaming.com';

// Get raw URL from query string
$rawTarget = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
$target = $defaultUrl;

// Validate URL
if ($rawTarget !== '' && filter_var($rawTarget, FILTER_VALIDATE_URL)) {
    $scheme = (string)parse_url($rawTarget, PHP_URL_SCHEME);

    if (in_array(strtolower($scheme), ['http', 'https'], true)) {
        $target = $rawTarget;
    }
}

// Host checks
$targetHost = strtolower((string)(parse_url($target, PHP_URL_HOST) ?? ''));
$currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

// Normalize current host if it includes a port
if (strpos($currentHost, ':') !== false) {
    $currentHost = explode(':', $currentHost)[0];
}

// Treat these as internal/safe hosts that should bypass the leave page
$isInternal =
    $targetHost === '' ||
    $targetHost === $currentHost ||
    $targetHost === 'localhost' ||
    $targetHost === '127.0.0.1' ||
    $targetHost === 'frostbyt3gaming.com' ||
    str_ends_with($targetHost, '.frostbyt3gaming.com');

// If somehow an internal URL reaches this page, just redirect directly
if ($isInternal) {
    header('Location: ' . $target);
    exit;
}

// Assets
$triangleImage = '/backend/img/triangle.png';

// Warning lines
$warnings = [
    'Warning: Loot drop rates drastically decrease beyond this point.',
    'You\'re straying outside the safe zone, adventurer.',
    'Achievement Unlocked: Leaving Frostbyt3 Gaming (0 Gamerscore).',
    'Respawn points are not guaranteed out there.',
    'The party won\'t follow you beyond this portal.'
];

$line = $warnings[array_rand($warnings)];
?>

<section class="leave-page">
    <div class="leave-container">
        <div class="warning-box" style="text-align: center;">
            <img
                src="<?php echo htmlspecialchars($triangleImage, ENT_QUOTES, 'UTF-8'); ?>"
                alt="Warning"
                style="width: 72px; height: auto;"
            >

            <h1 style="color: #e11d48;">You are about to leave Frostbyt3 Gaming!</h1>

            <p><i><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></i></p>

            <h4>
                <i>Frostbyt3 Gaming takes no responsibility for damage caused to your gear.</i>
            </h4>

            <p style="margin-top: 1rem; opacity: 0.8; word-break: break-word;">
                Destination:<br>
                <span style="font-size: 0.95rem;">
                    <?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </p>

            <div class="buttons" style="margin-top: 1.5rem;">
                <button type="button" class="btn" onclick="window.history.back();">
                    Go back!
                </button>

                <a href="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>" class="btn danger-action btn-stop" style="margin-left: 10px;" rel="noopener noreferrer" data-bypass-leave="1">
                    Continue on!
                </a>
            </div>
        </div>
    </div>
</section>