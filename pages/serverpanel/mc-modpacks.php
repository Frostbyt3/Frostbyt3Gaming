<?php
declare(strict_types=1);

$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$canDeleteFiles = $hasServerPermission('file.delete');
?>

<div
    class="fbg-modpacks-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier, ENT_QUOTES, 'UTF-8'); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-can-delete-files="<?php echo $canDeleteFiles ? '1' : '0'; ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-cubes"></i> Minecraft Modpacks</h2>
            <p>Search supported providers, choose a version, and start a modpack install for this server.</p>
        </div>
    </div>

    <div class="fbg-dashboard-alert fbg-modpacks-message" id="modpacks-message" style="display:none; margin-top: 16px;"></div>

    <section class="fbg-modpacks-current" id="modpacks-current" hidden></section>

    <section class="fbg-modpacks-toolbar">
        <div class="fbg-modpacks-field">
            <label class="fbg-meta-label" for="modpacks-provider">Provider</label>
            <select id="modpacks-provider" class="fbg-files-text-input">
                <option value="modrinth">Modrinth</option>
                <option value="curseforge">CurseForge</option>
                <option value="feedthebeast">Feed The Beast</option>
                <option value="atlauncher">ATLauncher</option>
                <option value="technic">Technic</option>
                <option value="voidswrath">Voids Wrath</option>
            </select>
        </div>

        <div class="fbg-modpacks-field">
            <label class="fbg-meta-label" for="modpacks-page-size">Page Size</label>
            <select id="modpacks-page-size" class="fbg-files-text-input">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
            </select>
        </div>

        <div class="fbg-modpacks-field fbg-modpacks-search-field">
            <label class="fbg-meta-label" for="modpacks-search">Search Query</label>
            <input id="modpacks-search" type="search" class="fbg-files-text-input" placeholder="Search modpacks..." autocomplete="off">
        </div>

        <button type="button" class="btn fbg-neutral-button" id="modpacks-refresh-button">
            <i class="fas fa-rotate"></i>
            <span>Refresh</span>
        </button>
    </section>

    <div id="modpacks-content" class="fbg-modpacks-content">
        <div class="fbg-schedules-loading">Loading modpacks...</div>
    </div>

    <div class="fbg-modpacks-pagination" id="modpacks-pagination" hidden>
        <button type="button" class="btn fbg-neutral-button btn-sm" id="modpacks-prev-button">Previous</button>
        <span class="fbg-modpacks-pagination-pages fbg-pagination-pages" id="modpacks-pagination-pages" aria-label="Pagination pages"></span>
        <button type="button" class="btn fbg-neutral-button btn-sm" id="modpacks-next-button">Next</button>
    </div>
</div>

<div class="fbg-files-modal-backdrop" id="modpacks-install-modal" hidden>
    <div class="fbg-files-modal fbg-modpacks-install-modal" role="dialog" aria-modal="true" aria-labelledby="modpacks-install-title">
        <button type="button" class="fbg-files-modal-close" id="modpacks-install-close" aria-label="Close install dialog">
            <i class="fas fa-times"></i>
        </button>

        <div class="fbg-files-modal-body">
            <h3 class="fbg-files-modal-title" id="modpacks-install-title">Install Modpack</h3>
            <p class="fbg-settings-note" id="modpacks-install-description"></p>

            <div class="fbg-modpacks-install-preview" id="modpacks-install-preview"></div>

            <form id="modpacks-install-form" class="fbg-files-rename-form">
                <div class="fbg-files-form-group">
                    <label for="modpacks-version">Modpack Version</label>
                    <select id="modpacks-version" class="fbg-files-text-input" required>
                        <option value="">Loading versions...</option>
                    </select>
                </div>

                <label class="fbg-toggle-row fbg-modpacks-delete-toggle">
                    <span class="fbg-toggle-switch">
                        <input type="checkbox" id="modpacks-delete-files">
                        <span class="fbg-toggle-slider"></span>
                    </span>
                    <span class="fbg-toggle-label">
                        <strong>Delete Files</strong>
                        <small>Delete all server files before installing this modpack. This is irreversible.</small>
                    </span>
                </label>

                <div class="fbg-files-modal-actions">
                    <button type="button" class="btn fbg-neutral-button btn-sm" id="modpacks-install-cancel">Cancel</button>
                    <button type="submit" class="btn danger-action btn-sm" id="modpacks-install-submit">Install Modpack</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/mc-modpacks.js'); ?>"></script>