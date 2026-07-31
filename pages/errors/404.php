<?php
http_response_code(404);

function getRandom404() {
    $messages = [
        "We can't seem to find that page. Are you sure you have the right URL?",
        "That page doesn't seem to exist.",
        "Hey! Nothing to see here...",
        "This page got lost in the void.",
        "Looks like this page rage quit.",
        "404: Page not found. It probably went AFK.",
        "You found... absolutely nothing.",
        "This page doesn't exist. It never did.",
        "Oops. This page slipped into another dimension.",
        "We looked everywhere! Even under the couch.",
        "This page has been yeeted into oblivion.",
        "Well... This is awkward.",
        "You weren't supposed to see this. Or anything, really.",
        "The page you're looking for is on vacation",
        "Error 404: The dev probably broke something.",
        "This page is still in beta. Forever.",
        "You hit a dead end. Try turning around.",
        "Nothing here but cold air and broken links.",
        "This page got lost somewhere between deploys.",
        "If this page existed, you'd be seeing it.",
        "We asked the server. It shrugged.",
        "404: Not found. Just like my motivation on days that end in 'Y'.",
        "This page has been sacrificed to the code gods.",
        "You've reached the edge of the internet. Congrats.",
        "There's supposed to be something here... but there isn't.",
        "This page was here a second ago... We swear.",
        "Even our servers can't find this one.",
        "You broke it. (Okay, maybe not, but still.)",
        "This page took an unexpected detour",
        "The link worked yesterday. Probably.",
        "404: Page not found. Try again after coffee.",
        "These aren't the droids you're looking for.",
    ];

    // Pick one at random
    return $messages[array_rand($messages)];
}

?>

<section class="error-page">
    <div class="error-container">
        <div class="warning-box" style="text-align: center;">
            <h1 style="color: #e11d48;">404</h1>
            <h1>Page not found</h1>

            <h3>
                <i><?php echo getRandom404(); ?></i>
            </h3>
        </div>
    </div>
</section>
