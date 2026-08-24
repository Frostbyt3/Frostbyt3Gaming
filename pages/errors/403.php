<?php
http_response_code(403);

function getRandom403() {
    $messages = [
        "Even our servers think this is a bad idea.",
        "The firewall has spoken.",
        "YOU SHALL NOT PASS!",
        "This isn't your base.",
        "You don't have the right DLC for this area.",
        "You need a higher level to enter this zone.",
        "NOPE!",
        "We checked twice. Still no.",
        "You tried. We respect that.",
        "This page saw you coming and locked the door.",
        "Nice attempt though.",
        "Error: Skill issue.",
        "Try turning your permissions off and on again.",
        "Your privileges have left the chat.",
        "Have you tried being an admin?",
        "Complete more quests to unlock this region.",
        "You need the 'Admin Keycard' for this door.",
        "Guards have been alerted. (Not really... but still no.)",
        "This isn't your skill tree.",
        "You are not in this party.",
        "Bold of you to assume you had access.",
        "Confidence ≠ permission",
        "Shoot your shot... just not here.",
        "We admire the ambition.",
        "This is where you'd see something cool... If you had access.",
        "The content is shy and only shows itself to authorized users.",
        "There's definitely something here. Just not for you.",
        "I used to be an adventurer like you... Until I found a forbidden page.",
        "Forbidden! You don't have the quest item required to enter.",
        "Access denied - This dungeon requires a guild membership.",
        "Welcome to 403: Forbidden Resource. The server understood the request but refuses to authorize it.",
    ];

    // Pick one at random
    return $messages[array_rand($messages)];
}

?>

<section class="error-page">
    <div class="error-container">
        <div class="warning-box" style="text-align: center;">
            <h1 style="color: #e11d48;">403</h1>
            <h1>Access denied</h1>

            <h3>
                <i><?php echo getRandom403(); ?></i>
            </h3>
        </div>
    </div>
</section>