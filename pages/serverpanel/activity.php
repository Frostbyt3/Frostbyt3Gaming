<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
?>

<div
    class="fbg-activity-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-wave-square"></i> Activity</h2>
            <p>Review recent server actions recorded by the panel database.</p>
        </div>

        <div class="fbg-server-card-actions">
            <button type="button" class="btn fbg-neutral-button btn-sm" id="activity-refresh-button">
                <i class="fas fa-rotate-right"></i>
                Refresh
            </button>
        </div>
    </div>

    <div class="fbg-dashboard-alert" id="activity-message" style="display:none; margin-top: 16px;"></div>

    <div id="fbg-activity-content" class="fbg-activity-content">
        <div class="fbg-schedules-loading">Loading activity...</div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/activity.js'); ?>"></script>