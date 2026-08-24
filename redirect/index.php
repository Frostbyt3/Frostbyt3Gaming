<?php
    include('./functions.php');

    $redirectUrl = getRedirectUrl("url", "https://panel.frostbyt3gaming.com/");

    // Countdown
    $countdown = 5;
    header("Refresh: $countdown; url=$redirectUrl");
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Redirecting...</title>
        <link rel="stylesheet" href="<?php echo asset('/backend/css/main.css'); ?>">
    </head>
    <body>
        <div class="box">
            <h1>Redirecting in <span id="countdown"><?php echo $countdown; ?></span>...</h1>
            <p>If you are having trouble, <a href="<?php echo htmlspecialchars($redirectUrl); ?>">click here</a> to redirect manually</p>
        </div>

        <script src="<?php echo asset('backend/js/main.js'); ?>"></script>
    </body>
</html>