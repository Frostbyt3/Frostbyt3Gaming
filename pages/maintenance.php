<?php
declare(strict_types=1);

$message = fbgGetMaintenanceMessage();
?>

<section class="fbg-maintenance-page">
    <div class="fbg-maintenance-container">
        <div class="warning-box" style="text-align: center;">
            <div style="font-size: 5rem;color: #ffe600;text-align: center;"><i class="fas fa-triangle-exclamation"></i></div>
            <h1>We're performing some maintenance. Please check back in a bit.</h1>
        
            <h3>
                <?= strip_tags($message, '<b><strong><i><em><br><feature><highlight><ul><li>') ?>
            </h3>
        </div>
    </div>
</section>