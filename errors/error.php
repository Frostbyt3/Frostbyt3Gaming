<?php
    // Include the helper functions
    include("../redirect/functions.php");
    include("./functions.php");

    // Grab status code from Nginx, query string (for testing), or PHP fallback
    if (isset($_GET['code'])) {
        $code = (int)$_GET['code'];
    } elseif (isset($_SERVER['ERROR_STATUS'])) {
        $code = (int)$_SERVER['ERROR_STATUS'];
    } else {
        $code = http_response_code();
    }

    // If still 200 (normal execution, not an error), fallback to 500
    if ($code == 200) {
        $code = 404;
    }
    http_response_code($code);

    # Set defaults
    $imgcode = 0;
    $imgsize = "64px";

    // Map codes to descriptions (with some fun extras)
    $statusTexts = [
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        418 => "I'm a teapot ☕",
        420 => "Enhance Your Calm",
        451 => "Unavailable For Legal Reasons",
        500 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        520 => "Unknown Error (Cloudflare)",
        521 => "Web Server Is Down",
        522 => "Connection Timed Out",
        523 => "Origin Is Unreachable",
        525 => "SSL Handshake Failed",
    ];

    // Pick description if known
    $codeText = $statusTexts[$code] ?? "Unknown Error";

    // Dynamic images based on error code.
    switch ($code) {
        case 400:
        case 401:
        case 403:
            $imgcode = 403;
            break;
        case 404:
            $imgcode = 404;
            break;
        case 418:
            $imgcode = 418;
            $imgsize = "128px";
            break;
        case 420:
            $imgcode = 420;
            $imgsize = "128px";
            break;
        case 500:
        case 502:
        case 503:
        case 504:
            $imgcode = 500;
            break;
        default:
            $imgcode = 0;
            $imgsize = "auto";
            break;
    }

    // Fallback incase the error code isn't handled.
    if ($code < 400 || $code > 504) {
        $displayCode = "Undefined Error";
        $imgcode = 0;
        $imgsize = "auto";
        $message = getErrorMessage("defaulted");
    } else {
        $displayCode = $code;
        $message = getErrorMessage($code);
    }
?>
<!-- Now, let's get into the displayable portion of the code -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo $code; ?> - <?php echo $codeText; ?></title> <!-- Title Header -->
        <link rel="stylesheet" href="<?php echo asset('../redirect/backend/css/main.css'); ?>"> <!-- asset function prevents "stuck" css styling -->
    </head>
    <body>
        <div class="box" style="text-align: center; padding: 50px;"> <!-- This box is pretty cool. -->
            <p>
                <img src="<?php echo asset('../backend/img/' . $imgcode . '.png'); ?>" 
                    onerror="this.src='<?php echo asset('../backend/img/0.png'); ?>';"
                    alt="<?php echo $code; ?> - <?php echo $codeText; ?>"
                    style="width: <?php echo $imgsize;?>; height: auto;"> <!-- These 4 lines are what determines which image to use. -->
            </p>
            <h1><?php echo $displayCode; ?> - <?php echo $codeText; ?></h1> <!-- This is the displayed error code -->
            <p><?php echo $message; ?></p> <!-- This is the displayed error message -->
            <a href="/" class="cta-button">Back Home</a> <!-- Back home button. Self-explanitory. -->
        </div>
    </body>
</html>
