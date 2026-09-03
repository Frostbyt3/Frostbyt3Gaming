<?php
#users.php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
?>

<div
    class="fbg-users-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-user-shield"></i> Users</h2>
            <p>Manage server subusers, invite new users, and edit their permissions.</p>
        </div>

        <div class="fbg-server-card-actions">
            <button type="button" class="btn fbg-primary-button" id="new-subuser-button">
                <i class="fas fa-plus"></i>
                New User
            </button>
        </div>
    </div>

    <div class="fbg-dashboard-alert fbg-users-message" id="users-message"></div>

    <div id="fbg-users-content" class="fbg-users-content">
        <div class="fbg-schedules-loading">Loading users...</div>
    </div>
</div>

<div id="fbg-users-modal-root">
    <div class="fbg-modal-overlay" id="subuser-modal" hidden>
        <div class="fbg-modal-card fbg-subuser-modal-card">
            <button
                type="button"
                class="fbg-modal-close"
                id="subuser-modal-close"
                aria-label="Close"
            >
                <i class="fas fa-times"></i>
            </button>

            <div class="fbg-modal-header">
                <h3 id="subuser-modal-title">New User</h3>
                <p id="subuser-modal-description">
                    Invite a panel user by email and choose their permissions.
                </p>
            </div>

            <form id="subuser-form">
                <input type="hidden" name="subuser_uuid" id="subuser_uuid" value="">

                <div class="fbg-form-group" id="subuser-email-group">
                    <label class="fbg-meta-label" for="subuser_email">Email Address</label>
                    <input
                        type="email"
                        class="fbg-files-text-input"
                        name="email"
                        id="subuser_email"
                        required
                    >
                </div>

                <div class="fbg-subuser-templates">
                    <div class="fbg-subuser-template-row">
                        <div class="fbg-subuser-template-group">
                            <span class="fbg-meta-label">Quick Templates</span>
                            <div class="fbg-schedule-preset-buttons" id="subuser-template-buttons">
                                <button type="button" class="btn fbg-neutral-button btn-sm subuser-template-button" data-template="readonly">
                                    Read Only
                                </button>

                                <button type="button" class="btn fbg-neutral-button btn-sm subuser-template-button" data-template="moderator">
                                    Moderator
                                </button>

                                <button type="button" class="btn fbg-neutral-button btn-sm subuser-template-button" data-template="developer">
                                    Developer
                                </button>

                                <button type="button" class="btn fbg-neutral-button btn-sm subuser-template-button" data-template="admin">
                                    Administrator
                                </button>

                                <button type="button" class="btn fbg-neutral-button btn-sm" id="subuser-clear-permissions">
                                    Clear
                                </button>
                            </div>
                        </div>

                        <div class="fbg-subuser-code-group">
                            <label class="fbg-meta-label" for="subuser-permission-code">Permission Code</label>
                            <div class="fbg-subuser-code-controls">
                                <input type="text" class="fbg-files-text-input" id="subuser-permission-code" inputmode="numeric" autocomplete="off" pattern="[0-9]*" spellcheck="false">
                                <button type="button" class="btn fbg-neutral-button btn-sm" id="subuser-permission-code-copy">Copy</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="subuser-permission-groups"></div>

                <div class="fbg-modal-actions">
                    <button type="button" class="btn fbg-neutral-button" id="subuser-cancel">
                        Cancel
                    </button>

                    <button type="submit" class="btn fbg-primary-button" id="subuser-submit">
                        Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/users.js'); ?>"></script>
