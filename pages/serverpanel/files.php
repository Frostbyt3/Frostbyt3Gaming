<?php
declare(strict_types=1);

$fileConfig = require __DIR__ . '/../../config/files.php';

$editableExtensions = array_values(array_filter(
    array_map(
        static fn ($ext): string => strtolower(trim((string)$ext)),
        (array)($fileConfig['editable_extensions'] ?? [])
    ),
    static fn (string $ext): bool => $ext !== ''
));

$maxEditorFileSize = (int)($fileConfig['max_editor_file_size'] ?? (1024 * 1024));

$requestedDir = trim((string)($_GET['dir'] ?? '/'));
if ($requestedDir === '') {
    $requestedDir = '/';
}

if ($requestedDir[0] !== '/') {
    $requestedDir = '/' . $requestedDir;
}

$requestedDir = preg_replace('#/+#', '/', $requestedDir) ?: '/';

function fbgBuildDirectoryBreadcrumbs(string $directory): array
{
    $directory = trim($directory);
    if ($directory === '' || $directory === '/') {
        return [
            ['label' => 'Home', 'path' => '/'],
        ];
    }

    $parts = array_values(array_filter(explode('/', trim($directory, '/')), 'strlen'));
    $breadcrumbs = [
        ['label' => 'Home', 'path' => '/'],
    ];

    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        $breadcrumbs[] = [
            'label' => $part,
            'path'  => $current,
        ];
    }

    return $breadcrumbs;
}

$breadcrumbs = fbgBuildDirectoryBreadcrumbs($requestedDir);
$serverIdentifier = (string)($selectedServer['identifier'] ?? '');
$canArchiveFiles = isset($hasServerPermission) && is_callable($hasServerPermission) && $hasServerPermission('file.archive');
$canCreateFiles = isset($hasServerPermission) && is_callable($hasServerPermission) && $hasServerPermission('file.create');
?>

<div class="fbg-files-panel" data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>" 
    data-directory="<?php echo htmlspecialchars($requestedDir); ?>"
    data-can-archive="<?php echo $canArchiveFiles ? '1' : '0'; ?>"
    data-can-create="<?php echo $canCreateFiles ? '1' : '0'; ?>"
    data-editable-extensions="<?php echo htmlspecialchars(json_encode($editableExtensions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>"
    data-max-editor-file-size="<?php echo (int)$maxEditorFileSize; ?>">
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-folder-open"></i> Files</h2>
            <p>Browse and manage your server files.</p>
        </div>
    </div>

    <div class="fbg-files-toolbar">
        <div class="fbg-files-breadcrumbs">
            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <?php if ($index > 0): ?>
                    <span class="fbg-files-breadcrumb-separator">/</span>
                <?php endif; ?>

                <a href="./page.php?name=serverpanel&id=<?php echo urlencode($serverIdentifier); ?>&tab=files&dir=<?php echo urlencode($crumb['path']); ?>" class="fbg-files-breadcrumb-link">
                    <?php echo htmlspecialchars($crumb['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="fbg-files-actions">
            <div class="fbg-files-per-page-inline">
                <label for="files-per-page" class="fbg-files-per-page-label">Rows per page:</label>
                <select id="files-per-page" class="fbg-files-pagination-select" title="Rows per page">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="250">250</option>
                </select>
            </div>

            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-refresh-button">
                <i class="fas fa-rotate-right"></i> Refresh
            </button>

            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-upload-button">
                <i class="fas fa-upload"></i> Upload
            </button>
            <input type="file" id="files-upload-input" multiple hidden>

            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-new-file-button">
                <i class="fas fa-file-circle-plus"></i> New File
            </button>

            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-new-folder-button">
                <i class="fas fa-folder-plus"></i> New Folder
            </button>
        </div>
    </div>

    <div class="fbg-files-search">
        <label for="files-search-input" class="fbg-meta-label">Search Files</label>
        <div class="fbg-files-search-input-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input
                type="text"
                id="files-search-input"
                class="fbg-files-text-input"
                placeholder="Search this folder..."
                autocomplete="off"
            >
            <button type="button" class="fbg-files-search-clear" id="files-search-clear" hidden aria-label="Clear file search">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- <div class="fbg-files-current-path">
        <span class="fbg-meta-label">Current Directory</span>
        <div class="fbg-meta-value" id="files-current-directory"><?php echo htmlspecialchars($requestedDir); ?></div>
    </div> -->

    <div class="fbg-dashboard-alert" id="files-message" style="display:none; margin-top: 16px;"></div>
    <div class="fbg-files-upload-queue" id="files-upload-queue" hidden></div>

    <div class="fbg-files-table-wrap">
        <div class="fbg-files-dropzone-hint" id="files-dropzone-hint" hidden>
            <i class="fas fa-cloud-arrow-up"></i>
            <span>Drop files here to upload to this folder</span>
        </div>
        <table class="fbg-files-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th style="width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody id="files-table-body">
                <tr class="fbg-files-loading-row">
                    <td colspan="4">Loading files...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="fbg-files-pagination" id="files-pagination">
        <div class="fbg-files-pagination-summary" id="files-pagination-summary">Showing 0-0 of 0 items</div>

        <div class="fbg-files-pagination-controls">
            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-page-prev">
                <i class="fas fa-chevron-left"></i> Prev
            </button>

            <div class="fbg-files-pagination-pages fbg-pagination-pages" id="files-pagination-pages" aria-label="Pagination pages"></div>

            <button type="button" class="btn fbg-neutral-button btn-sm" id="files-page-next">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

<div class="fbg-files-modal-backdrop" id="files-rename-modal" hidden>
    <div class="fbg-files-modal" role="dialog" aria-modal="true" aria-labelledby="files-rename-title">
        <button type="button" class="fbg-files-modal-close" id="files-rename-close" aria-label="Close rename dialog">
            <i class="fas fa-times"></i>
        </button>

        <div class="fbg-files-modal-body">
            <h3 class="fbg-files-modal-title" id="files-rename-title">Rename</h3>

            <form id="files-rename-form" class="fbg-files-rename-form">
                <input type="hidden" id="files-rename-path" value="">

                <div class="fbg-files-form-group">
                    <label for="files-rename-name">File Name</label>
                    <input type="text" id="files-rename-name" class="fbg-files-text-input" maxlength="255" autocomplete="off" required>
                </div>

                <div class="fbg-files-modal-actions">
                    <button type="button" class="btn fbg-neutral-button btn-sm" id="files-rename-cancel">
                        Cancel
                    </button>

                    <button type="submit" class="btn fbg-primary-button btn-sm" id="files-rename-submit">
                        Rename
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="fbg-files-modal-backdrop" id="files-newfolder-modal" hidden>
    <div class="fbg-files-modal">
        <button type="button" class="fbg-files-modal-close" id="files-newfolder-close">
            <i class="fas fa-times"></i>
        </button>

        <div class="fbg-files-modal-body">
            <h3 class="fbg-files-modal-title">New Folder</h3>

            <form id="files-newfolder-form" class="fbg-files-rename-form">
                <div class="fbg-files-form-group">
                    <label for="files-newfolder-name">Folder Name</label>
                    <input type="text" id="files-newfolder-name" class="fbg-files-text-input" maxlength="255" required>
                </div>

                <div class="fbg-files-modal-actions">
                    <button type="button" class="btn fbg-neutral-button btn-sm" id="files-newfolder-cancel">
                        Cancel
                    </button>

                    <button type="submit" class="btn fbg-primary-button btn-sm" id="files-newfolder-submit">
                        Create
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="fbg-files-modal-backdrop" id="files-editor-modal" hidden>
    <div class="fbg-files-modal fbg-files-editor-modal" role="dialog" aria-modal="true" aria-labelledby="files-editor-title">
        <button type="button" class="fbg-files-modal-close" id="files-editor-close" aria-label="Close editor">
            <i class="fas fa-times"></i>
        </button>

        <div class="fbg-files-modal-body">
            <div class="fbg-files-editor-header">
                <div>
                    <h3 class="fbg-files-modal-title" id="files-editor-title">Edit File</h3>
                    <div class="fbg-files-editor-path" id="files-editor-path">/</div>
                </div>

                <div class="fbg-files-editor-status" id="files-editor-status">Saved</div>
            </div>

            <div class="fbg-files-editor-notice" id="files-editor-notice" hidden></div>

            <div class="fbg-files-form-group fbg-files-editor-name-field" id="files-editor-name-field" hidden>
                <label for="files-editor-name">File Name</label>
                <input
                    type="text"
                    id="files-editor-name"
                    class="fbg-files-text-input"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="eula.txt"
                >
            </div>

            <div class="fbg-files-code-editor" id="files-code-editor" data-language="plain">
                <div class="fbg-files-code-editor-toolbar">
                    <span class="fbg-files-code-editor-language" id="files-editor-language">Plain Text</span>
                </div>

                <div class="fbg-files-code-editor-body">
                    <pre class="fbg-files-code-editor-lines" id="files-editor-line-numbers" aria-hidden="true">1</pre>
                    <div class="fbg-files-code-editor-input-wrap">
                        <pre class="fbg-files-code-editor-highlight" id="files-editor-highlight" aria-hidden="true"></pre>
                        <textarea
                            id="files-editor-textarea"
                            class="fbg-files-editor-textarea"
                            spellcheck="false"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div class="fbg-files-modal-actions">
                <button type="button" class="btn fbg-neutral-button btn-sm" id="files-editor-cancel">
                    Close
                </button>

                <button type="button" class="btn fbg-primary-button btn-sm" id="files-editor-save">
                    Save
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/files.js'); ?>"></script>
