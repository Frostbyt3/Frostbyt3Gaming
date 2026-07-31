<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

$canReadStartup = $hasServerPermission('startup.read');
$canUpdateStartup = $hasServerPermission('startup.update');

/* Adjust this to match your admin logic */
$canEditStartupCommand = !empty($_SESSION['is_admin']);
?>

<div
    class="fbg-startup-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
    data-can-update="<?php echo $canUpdateStartup ? '1' : '0'; ?>"
    data-can-edit-startup-command="<?php echo $canEditStartupCommand ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-sliders-h"></i> Startup</h2>
            <p>View this server's startup command, Docker image, and startup variables.</p>
        </div>
    </div>

    <div class="fbg-dashboard-alert" id="startup-message" style="display:none; margin-top: 16px;"></div>

    <div id="fbg-startup-content" class="fbg-startup-content">
        <div class="fbg-schedules-loading">Loading startup configuration...</div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/startup.js'); ?>"></script>