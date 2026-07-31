<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

$canCreateAllocation = $hasServerPermission('allocation.create');
$canUpdateAllocation = $hasServerPermission('allocation.update');
$canDeleteAllocation = $hasServerPermission('allocation.delete');
$allocationLimit = (int)($selectedServer['feature_allocations'] ?? 0);
?>

<div
    class="fbg-network-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
    data-can-create="<?php echo $canCreateAllocation ? '1' : '0'; ?>"
    data-can-update="<?php echo $canUpdateAllocation ? '1' : '0'; ?>"
    data-can-delete="<?php echo $canDeleteAllocation ? '1' : '0'; ?>"
    data-allocation-limit="<?php echo (int)$allocationLimit; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-network-wired"></i> Network</h2>
            <p>Manage this server's IP/port allocations, primary port, and allocation notes.</p>
        </div>

        <?php if ($canCreateAllocation): ?>
            <div class="fbg-server-card-actions">
                <button type="button" class="btn fbg-primary-button" id="network-create-allocation-button">
                    <i class="fas fa-plus"></i> Create Allocation
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="fbg-dashboard-alert" id="network-message" style="display:none; margin-top: 16px;"></div>

    <div id="fbg-network-content" class="fbg-network-content">
        <div class="fbg-schedules-loading">Loading allocations...</div>
    </div>

    <div class="fbg-network-footer-meta" id="network-footer-meta" style="display:none;"></div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/network.js'); ?>"></script>