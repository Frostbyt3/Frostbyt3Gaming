<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$canUpdatePalworldSettings = $hasServerPermission('file.update');
?>

<div
    class="fbg-palworld-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
    data-can-update="<?php echo $canUpdatePalworldSettings ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-sliders"></i> Palworld Settings</h2>
            <p>Review and update the settings currently present in your PalWorldSettings.ini file.</p>
        </div>
    </div>

    <div id="fbg-palworld-content" class="fbg-palworld-content">
        <div class="fbg-schedules-loading">Loading Palworld settings...</div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/palworld-settings.js'); ?>"></script>
