<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$databaseLimit = max(0, (int)($selectedServer['feature_databases'] ?? 0));

$canCreateDatabase = $hasServerPermission('database.create');
$canUpdateDatabase = $hasServerPermission('database.update');
$canDeleteDatabase = $hasServerPermission('database.delete');
$canViewDatabasePassword = $hasServerPermission('database.view_password');
?>

<div
    class="fbg-databases-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier, ENT_QUOTES, 'UTF-8'); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-database-limit="<?php echo $databaseLimit; ?>"
    data-can-create="<?php echo $canCreateDatabase ? '1' : '0'; ?>"
    data-can-update="<?php echo $canUpdateDatabase ? '1' : '0'; ?>"
    data-can-delete="<?php echo $canDeleteDatabase ? '1' : '0'; ?>"
    data-can-view-password="<?php echo $canViewDatabasePassword ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-database"></i> Databases</h2>
            <p>Create and manage database credentials for this server.</p>
        </div>

        <?php if ($canCreateDatabase): ?>
            <div class="fbg-server-card-actions" id="database-header-actions">
                <button type="button" class="btn fbg-primary-button" id="new-database-button">
                    <i class="fas fa-plus"></i>
                    Create Database
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div
        class="fbg-dashboard-alert fbg-databases-message"
        id="databases-message"
        style="display:none;"
    ></div>

    <div id="fbg-databases-content" class="fbg-databases-content">
        <div class="fbg-schedules-loading">Loading databases...</div>
    </div>
</div>

<div id="fbg-databases-modal-root">
    <div class="fbg-modal-overlay" id="database-create-modal" hidden>
        <div class="fbg-modal-card fbg-schedule-modal-card fbg-database-modal-card">
            <button type="button" class="fbg-modal-close" id="database-create-close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="fbg-modal-header">
                <h3>Create Database</h3>
                <p>Create a new database for this server. A username and password will be generated automatically.</p>
            </div>

            <form id="database-create-form">
                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_create_name">Database Name</label>
                    <input
                        type="text"
                        class="fbg-files-text-input"
                        name="database"
                        id="database_create_name"
                        required
                        minlength="3"
                        maxlength="48"
                        pattern="[A-Za-z0-9_-]+"
                    >
                    <small>A descriptive name for your database instance. Letters, numbers, dashes, and underscores only.</small>
                </div>

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_create_remote">Connections From</label>
                    <input
                        type="text"
                        class="fbg-files-text-input"
                        name="remote"
                        id="database_create_remote"
                        value="%"
                        placeholder="%"
                        required
                    >
                    <small>Where connections should be allowed from. Use <code>%</code> to allow connections from anywhere.</small>
                </div>

                <div class="fbg-modal-actions">
                    <button type="button" class="btn fbg-neutral-button" id="database-create-cancel">Cancel</button>
                    <button type="submit" class="btn fbg-primary-button" id="database-create-submit">Create Database</button>
                </div>
            </form>
        </div>
    </div>

    <div class="fbg-modal-overlay" id="database-details-modal" hidden>
        <div class="fbg-modal-card fbg-schedule-modal-card fbg-database-modal-card">
            <button type="button" class="fbg-modal-close" id="database-details-close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="fbg-modal-header">
                <h3>Database Connection Details</h3>
                <p>Use these credentials to connect your application or plugin to this database.</p>
            </div>

            <div class="fbg-database-detail-fields">
                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_detail_endpoint">Endpoint</label>
                    <input id="database_detail_endpoint" class="fbg-files-text-input" type="text" readonly>
                </div>

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_detail_remote">Connections From</label>
                    <input id="database_detail_remote" class="fbg-files-text-input" type="text" readonly>
                </div>

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_detail_username">Username</label>
                    <input id="database_detail_username" class="fbg-files-text-input" type="text" readonly>
                </div>

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_detail_password">Password</label>
                    <input id="database_detail_password" class="fbg-files-text-input" type="text" readonly>
                </div>

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="database_detail_jdbc">JDBC Connection String</label>
                    <input id="database_detail_jdbc" class="fbg-files-text-input" type="text" readonly>
                </div>
            </div>

            <div class="fbg-modal-actions">
                <?php if ($canUpdateDatabase): ?>
                    <button type="button" class="btn fbg-neutral-button" id="database-rotate-password-button">Rotate Password</button>
                <?php endif; ?>
                <button type="button" class="btn fbg-neutral-button" id="database-details-cancel">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/databases.js'); ?>"></script>
