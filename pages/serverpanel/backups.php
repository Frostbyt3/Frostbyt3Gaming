<?php
declare(strict_types=1);

$serverId = $serverIdentifier;
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

$canCreateBackup = $hasServerPermission('backup.create');
$canDownloadBackup = $hasServerPermission('backup.download');
$canRestoreBackup = $hasServerPermission('backup.restore');
$canDeleteBackup = $hasServerPermission('backup.delete');
?>

<div
    class="fbg-backups-panel"
    data-server-id="<?php echo htmlspecialchars($serverId); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
    data-can-create="<?php echo $canCreateBackup ? '1' : '0'; ?>"
    data-can-download="<?php echo $canDownloadBackup ? '1' : '0'; ?>"
    data-can-restore="<?php echo $canRestoreBackup ? '1' : '0'; ?>"
    data-can-delete="<?php echo $canDeleteBackup ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-box-archive"></i> Backups</h2>
            <p>Create, download, restore, and manage server backups.</p>
        </div>

        <div class="fbg-backups-header-actions">

            <?php if ($canCreateBackup): ?>
                <button type="button" class="btn fbg-primary-button" id="create-backup-button">
                    Create Backup
                </button>
            <?php endif; ?>

            <div class="fbg-backups-summary" id="backups-summary">
                Loading backups...
            </div>

        </div>
    </div>

    <div id="backups-message" class="fbg-dashboard-alert"></div>

    <div id="backups-list" class="fbg-backups-list">
        <div class="fbg-empty-state">Loading backups...</div>
    </div>
</div>

<?php if ($canCreateBackup): ?>
    <div class="fbg-modal-overlay" id="backup-create-modal" hidden>
        <div class="fbg-schedule-modal-card fbg-backup-modal-card">
            <div class="fbg-modal-header">
                <div>
                    <h3>Create server backup</h3>
                    <p>Create a new restore point for this server.</p>
                </div>

                <button type="button" class="fbg-modal-close" id="backup-create-close" aria-label="Close">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form id="backup-create-form">
                <div class="fbg-form-group">
                    <label for="backup-name">Backup Name</label>
                    <input type="text" id="backup-name" name="name" class="fbg-text-input" maxlength="191">
                    <small class="fbg-form-hint">Optional. Leave blank to let the panel generate a name.</small>
                </div>

                <div class="fbg-form-group">
                    <label for="backup-ignored">Ignored Files &amp; Directories</label>
                    <textarea id="backup-ignored" name="ignored" class="fbg-textarea" rows="7" placeholder="node_modules
cache/*
!cache/important.json"></textarea>
                    <small class="fbg-form-hint">
                        Enter one path per line. Wildcards are supported by Pterodactyl.
                    </small>
                </div>

                <label class="fbg-backup-lock-toggle">
                    <input type="checkbox" id="backup-is-locked" name="is_locked" value="1">
                    <span>
                        <strong>Locked</strong>
                        <small>Prevents this backup from being deleted until it is explicitly unlocked.</small>
                    </span>
                </label>

                <div class="fbg-modal-actions">
                    <button type="button" class="btn fbg-neutral-button" id="backup-create-cancel">
                        Cancel
                    </button>
                    <button type="submit" class="btn fbg-primary-button" id="backup-create-submit">
                        Start Backup
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script src="<?php echo asset('/backend/js/serverpanel/backups.js'); ?>"></script>