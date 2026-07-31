<?php
declare(strict_types=1);

$countdown = 3;

// Get slug safely
$slug = trim((string)($_GET['plan'] ?? ''));

// Basic safety (optional but recommended)
$slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);

// Build redirect URL
$baseUrl = 'https://panel.frostbyt3gaming.com/shop';
$redirectUrl = $slug !== '' ? $baseUrl . '/' . $slug : $baseUrl;
?>

<linksmain>
    <div class="subpagetitle">
        <h1>You are now being redirected...</h1>
        <p>
            You are being redirected to the panel's shop in <span id="countdown"><?= $countdown ?></span>...
        </p>
        <p>
            Click <a href="<?php htmlspecialchars($redirectUrl) ?>" rel="noopener noreferrer">here</a> if you are not automatically redirected.
        </p>
    </div>
</linksmain>

<noscript>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirectUrl) ?>">
</noscript>

<script>
    let timeLeft = <?= $countdown ?>;
    const countdownEl = document.getElementById('countdown');
    const redirectUrl = <?= json_encode($redirectUrl) ?>;

    const timer = setInterval(() => {
        timeLeft--;

        if (timeLeft <= 0) {
            clearInterval(timer);
            window.location.href = redirectUrl;
        } else {
            countdownEl.textContent = timeLeft;
        }
    }, 1000);
</script>