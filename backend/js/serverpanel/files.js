(() => {
    const filesPanel = document.querySelector('.fbg-files-panel');
    if (!filesPanel) return;

    const serverId = filesPanel.dataset.serverId || '';
    let currentDirectory = filesPanel.dataset.directory || '/';
    let activeFloatingMenu = null;
    let activeMenuButton = null;

    const tableBody = document.getElementById('files-table-body');
    const filesMessage = document.getElementById('files-message');
    const refreshButton = document.getElementById('files-refresh-button');
    const searchInput = document.getElementById('files-search-input');
    const searchClearButton = document.getElementById('files-search-clear');

    const renameModal = document.getElementById('files-rename-modal');
    const renameForm = document.getElementById('files-rename-form');
    const renamePathInput = document.getElementById('files-rename-path');
    const renameNameInput = document.getElementById('files-rename-name');
    const renameCloseButton = document.getElementById('files-rename-close');
    const renameCancelButton = document.getElementById('files-rename-cancel');
    const renameSubmitButton = document.getElementById('files-rename-submit');

    const newFolderModal = document.getElementById('files-newfolder-modal');
    const newFolderForm = document.getElementById('files-newfolder-form');
    const newFolderNameInput = document.getElementById('files-newfolder-name');
    const newFolderClose = document.getElementById('files-newfolder-close');
    const newFolderCancel = document.getElementById('files-newfolder-cancel');
    const newFolderButton = document.getElementById('files-new-folder-button');
    const newFolderSubmit = document.getElementById('files-newfolder-submit');
    const newFileButton = document.getElementById('files-new-file-button');

    const uploadButton = document.getElementById('files-upload-button');
    const uploadInput = document.getElementById('files-upload-input');
    const uploadQueue = document.getElementById('files-upload-queue');
    const tableWrap = document.querySelector('.fbg-files-table-wrap');
    const dropzoneHint = document.getElementById('files-dropzone-hint');
    const paginationWrap = document.getElementById('files-pagination');
    const paginationSummary = document.getElementById('files-pagination-summary');
    const paginationPages = document.getElementById('files-pagination-pages');
    const paginationPrevButton = document.getElementById('files-page-prev');
    const paginationNextButton = document.getElementById('files-page-next');
    const perPageSelect = document.getElementById('files-per-page');

    const editorModal = document.getElementById('files-editor-modal');
    const editorCloseButton = document.getElementById('files-editor-close');
    const editorCancelButton = document.getElementById('files-editor-cancel');
    const editorSaveButton = document.getElementById('files-editor-save');
    const editorTitle = document.getElementById('files-editor-title');
    const editorTextarea = document.getElementById('files-editor-textarea');
    const editorNameField = document.getElementById('files-editor-name-field');
    const editorNameInput = document.getElementById('files-editor-name');
    const editorPathLabel = document.getElementById('files-editor-path');
    const editorStatus = document.getElementById('files-editor-status');
    const editorNotice = document.getElementById('files-editor-notice');
    const editorShell = document.getElementById('files-code-editor');
    const editorHighlight = document.getElementById('files-editor-highlight');
    const editorLineNumbers = document.getElementById('files-editor-line-numbers');
    const editorLanguageLabel = document.getElementById('files-editor-language');

    let dragDepth = 0;
    let uploadInProgress = false;
    let currentPage = 1;
    let rowsPerPage = 50;

    let editorFilePath = '';
    let editorOriginalValue = '';
    let editorIsLoading = false;
    let editorIsSaving = false;
    let editorLanguage = 'plain';
    let editorMode = 'edit';
    let editorCreateDirectory = '/';

    let currentItems = [];
    let currentSearchTerm = '';

    let editableExtensions = [];
    try {
        editableExtensions = JSON.parse(filesPanel.dataset.editableExtensions || '[]');
    } catch (error) {
        console.error('Failed to parse editable extensions config:', error);
        editableExtensions = [];
    }

    const EDITABLE_EXTENSIONS = new Set(
        Array.isArray(editableExtensions)
            ? editableExtensions
                .map((ext) => String(ext || '').trim().toLowerCase())
                .filter(Boolean)
            : []
    );

    const MAX_EDITOR_FILE_SIZE = Number(filesPanel.dataset.maxEditorFileSize || 1048576);

    const savedRowsPerPage = Number(localStorage.getItem('fbg_files_rows_per_page'));
    if (Number.isFinite(savedRowsPerPage) && savedRowsPerPage > 0) {
        rowsPerPage = savedRowsPerPage;
    } else {
        rowsPerPage = Number(perPageSelect?.value || 50);
    }

    if (perPageSelect) {
        perPageSelect.value = String(rowsPerPage);
    }

    portalModalToBody(renameModal);
    portalModalToBody(newFolderModal);
    portalModalToBody(editorModal);

    function portalModalToBody(modal) {
        if (!modal) return;
        if (modal.parentElement === document.body) return;
        document.body.appendChild(modal);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeEditorHtml(value) {
        return escapeHtml(value)
            .replace(/\t/g, '    ')
            .replace(/ {2}/g, ' &nbsp;');
    }

    function normalizePath(path) {
        const value = String(path || '/').trim();
        if (!value) return '/';
        const normalized = value.replace(/\/+/g, '/');
        return normalized.startsWith('/') ? normalized : `/${normalized}`;
    }

    function getParentDirectory(path) {
        const normalized = normalizePath(path);
        if (normalized === '/') {
            return '/';
        }

        const parts = normalized.split('/').filter(Boolean);
        parts.pop();

        return parts.length ? `/${parts.join('/')}` : '/';
    }

    function getBaseName(path) {
        const normalized = normalizePath(path);
        if (normalized === '/') return '';
        const parts = normalized.split('/').filter(Boolean);
        return parts.length ? parts[parts.length - 1] : '';
    }

    function buildChildPath(directory, name) {
        const cleanDirectory = normalizePath(directory || '/');
        const cleanName = String(name || '').trim();

        return cleanDirectory === '/'
            ? `/${cleanName}`
            : `${cleanDirectory.replace(/\/+$/, '')}/${cleanName}`;
    }

    function isValidFileName(name) {
        const value = String(name || '').trim();
        return value !== '' && value !== '.' && value !== '..' && !/[\/\\]/.test(value);
    }

    function getExtension(name) {
        const value = String(name || '').trim().toLowerCase();
        const lastDot = value.lastIndexOf('.');
        return lastDot === -1 ? '' : value.slice(lastDot + 1);
    }

    function getEditorLanguageFromPath(path) {
        const ext = getExtension(getBaseName(path));

        if (ext === 'ini') {
            return 'ini';
        }

        if (ext === 'properties') {
            return 'properties';
        }

        return 'plain';
    }

    function updateCreateFilePathPreview() {
        if (editorMode !== 'create') return;

        const fileName = String(editorNameInput?.value || '').trim();
        const previewPath = fileName
            ? buildChildPath(editorCreateDirectory, fileName)
            : normalizePath(editorCreateDirectory);

        editorFilePath = fileName ? previewPath : '';
        setEditorLanguage(fileName ? getEditorLanguageFromPath(previewPath) : 'plain');

        if (editorPathLabel) {
            editorPathLabel.textContent = fileName
                ? previewPath
                : `Creating in ${normalizePath(editorCreateDirectory)}`;
        }
    }

    function getEditorLanguageLabel(language) {
        if (language === 'ini') return 'INI';
        if (language === 'properties') return 'Properties';
        return 'Plain Text';
    }

    function setEditorLanguage(language) {
        editorLanguage = ['ini', 'properties'].includes(language) ? language : 'plain';

        if (editorShell) {
            editorShell.dataset.language = editorLanguage;
        }

        if (editorLanguageLabel) {
            editorLanguageLabel.textContent = getEditorLanguageLabel(editorLanguage);
        }
    }

    function highlightIniLikeLine(line) {
        const escapedLine = escapeEditorHtml(line);
        const leadingMatch = line.match(/^\s*/);
        const leading = leadingMatch ? leadingMatch[0] : '';
        const trimmed = line.slice(leading.length);

        if (trimmed === '') {
            return escapedLine || '&nbsp;';
        }

        if (trimmed.startsWith(';') || trimmed.startsWith('#')) {
            return `<span class="fbg-code-comment">${escapedLine}</span>`;
        }

        if (/^\[[^\]]+\]\s*(?:[;#].*)?$/.test(trimmed)) {
            const commentIndex = trimmed.search(/\s[;#]/);
            const sectionPart = commentIndex >= 0 ? trimmed.slice(0, commentIndex) : trimmed;
            const commentPart = commentIndex >= 0 ? trimmed.slice(commentIndex) : '';

            return `${escapeEditorHtml(leading)}<span class="fbg-code-section">${escapeEditorHtml(sectionPart)}</span>${commentPart ? `<span class="fbg-code-comment">${escapeEditorHtml(commentPart)}</span>` : ''}`;
        }

        const separatorMatch = trimmed.match(/^([^:=\s][^:=]*?)(\s*[:=]\s*)(.*)$/);
        if (!separatorMatch) {
            return escapedLine;
        }

        const [, key, separator, rawValue] = separatorMatch;
        const inlineCommentMatch = rawValue.match(/^([^;#]*?)(\s[;#].*)$/);
        const value = inlineCommentMatch ? inlineCommentMatch[1] : rawValue;
        const comment = inlineCommentMatch ? inlineCommentMatch[2] : '';
        const valueClass = /^(true|false|null|yes|no|on|off)$/i.test(value.trim())
            ? 'fbg-code-boolean'
            : /^-?\d+(?:\.\d+)?$/.test(value.trim())
                ? 'fbg-code-number'
                : 'fbg-code-value';

        return [
            escapeEditorHtml(leading),
            `<span class="fbg-code-key">${escapeEditorHtml(key)}</span>`,
            `<span class="fbg-code-separator">${escapeEditorHtml(separator)}</span>`,
            `<span class="${valueClass}">${escapeEditorHtml(value)}</span>`,
            comment ? `<span class="fbg-code-comment">${escapeEditorHtml(comment)}</span>` : ''
        ].join('');
    }

    function highlightEditorContents(contents) {
        const normalized = String(contents ?? '');
        const lines = normalized.split('\n');

        if (editorLanguage === 'ini' || editorLanguage === 'properties') {
            return lines.map(highlightIniLikeLine).join('\n');
        }

        return escapeEditorHtml(normalized);
    }

    function updateEditorLineNumbers(contents) {
        if (!editorLineNumbers) return;

        const lineCount = Math.max(1, String(contents ?? '').split('\n').length);
        const lines = [];

        for (let index = 1; index <= lineCount; index++) {
            lines.push(String(index));
        }

        editorLineNumbers.textContent = lines.join('\n');
    }

    function syncEditorScroll() {
        if (!editorTextarea) return;

        if (editorHighlight) {
            editorHighlight.scrollTop = editorTextarea.scrollTop;
            editorHighlight.scrollLeft = editorTextarea.scrollLeft;
        }

        if (editorLineNumbers) {
            editorLineNumbers.scrollTop = editorTextarea.scrollTop;
        }
    }

    function renderCodeEditor() {
        if (!editorTextarea) return;

        const contents = editorTextarea.value || '';

        if (editorHighlight) {
            editorHighlight.innerHTML = highlightEditorContents(contents);
        }

        updateEditorLineNumbers(contents);
        syncEditorScroll();
    }

    function isEditableFile(entry) {
        if (!entry || !entry.is_file) return false;
        const ext = getExtension(entry.name || '');
        return EDITABLE_EXTENSIONS.has(ext);
    }

    function findEntryByPath(path) {
        const cleanPath = normalizePath(path);
        return (currentItems || []).find((entry) => normalizePath(entry.path || '/') === cleanPath) || null;
    }

    function showFilesMessage(message, isError = false) {
        if (!filesMessage) return;

        filesMessage.textContent = message;
        filesMessage.classList.remove('success', 'error');
        filesMessage.classList.add(isError ? 'error' : 'success');
        filesMessage.style.display = 'block';

        void filesMessage.offsetHeight;
        filesMessage.classList.add('is-visible');
    }

    function hideFilesMessage() {
        if (!filesMessage) return;

        filesMessage.classList.remove('is-visible');

        setTimeout(() => {
            filesMessage.style.display = 'none';
        }, 200);
    }

    function formatToastText(value) {
        return String(value || '')
            .replace(/[*_#-]/g, '')
            .trim();
    }

    function showFilesToast({ type = 'info', title = 'File Manager', message = '', duration, persistent = false } = {}) {
        const cleanMessage = String(message || '').trim();

        if (!cleanMessage) {
            return;
        }

        if (typeof window.FBGToast === 'function') {
            window.FBGToast({
                type,
                title,
                message: cleanMessage,
                duration,
                persistent
            });
            return;
        }

        showFilesMessage(cleanMessage.replace(/[#*_~-]/g, ''), type === 'error' || type === 'warning');
        window.clearTimeout(showFilesMessageTimed._timeoutId);
        showFilesMessageTimed._timeoutId = window.setTimeout(() => {
            hideFilesMessage();
        }, duration || (type === 'error' || type === 'warning' ? 9000 : 5000));
    }

    function showFilesMessageTimed(message, isError = false, duration = 3500) {
        showFilesToast({
            type: isError ? 'error' : 'success',
            message,
            duration
        });
    }

    function formatBytes(bytes) {
        const num = Number(bytes || 0);
        if (!Number.isFinite(num) || num <= 0) return '—';

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = num;
        let unitIndex = 0;

        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex++;
        }

        return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 2)} ${units[unitIndex]}`;
    }

    function formatDate(value) {
        if (!value) return '—';

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return escapeHtml(value);
        }

        return date.toLocaleString();
    }

    function getRowIcon(entry) {
        const name = String(entry.name || '').toLowerCase();

        if (!entry.is_file) {
            if (entry.is_symlink) return 'fas fa-link';
            return 'fas fa-folder';
        }

        if (name.endsWith('.txt') || name.endsWith('.log') || name.endsWith('.json') || name.endsWith('.cfg') || name.endsWith('.ini')) {
            return 'fas fa-file-lines';
        }

        if (name.endsWith('.zip') || name.endsWith('.rar') || name.endsWith('.7z') || name.endsWith('.tar') || name.endsWith('.gz')) {
            return 'fas fa-file-zipper';
        }

        if (name.endsWith('.exe') || name.endsWith('.dll') || name.endsWith('.so')) {
            return 'fas fa-gears';
        }

        if (name.endsWith('.png') || name.endsWith('.jpg') || name.endsWith('.jpeg') || name.endsWith('.webp') || name.endsWith('.gif')) {
            return 'fas fa-file-image';
        }

        return 'fas fa-file';
    }

    function buildFolderUrl(path) {
        return `./page.php?name=serverpanel&id=${encodeURIComponent(serverId)}&tab=files&dir=${encodeURIComponent(path)}`;
    }

    function buildDownloadUrl(path) {
        return `/api/server/files/download.php?id=${encodeURIComponent(serverId)}&file=${encodeURIComponent(path)}`;
    }

    async function parseJsonResponse(response, invalidJsonMessage) {
        const rawText = await response.text();

        let payload;
        try {
            payload = JSON.parse(rawText);
        } catch (error) {
            console.error(invalidJsonMessage, rawText);
            throw new Error('Endpoint returned invalid JSON. Check PHP logs.');
        }

        if (!response.ok || !payload?.ok) {
            throw new Error(payload?.error || 'Request failed.');
        }

        return payload;
    }

    async function fetchJson(url, options = {}, invalidJsonMessage = 'Invalid JSON response:') {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
            headers: {
                'Accept': 'application/json',
                ...(options.headers || {})
            }
        });

        return parseJsonResponse(response, invalidJsonMessage);
    }

    async function postJson(url, body, invalidJsonMessage = 'Invalid JSON response:') {
        return fetchJson(
            url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
            },
            invalidJsonMessage
        );
    }

    async function withButtonBusyState(buttonEl, busyText, callback) {
        if (!buttonEl) {
            return callback();
        }

        if (buttonEl.disabled) {
            return;
        }

        const originalText = buttonEl.textContent;

        try {
            buttonEl.disabled = true;
            buttonEl.textContent = busyText;
            return await callback();
        } finally {
            buttonEl.disabled = false;
            buttonEl.textContent = originalText;
        }
    }

    async function confirmFilesAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    function setEditorDirtyState() {
        if (!editorStatus || !editorTextarea) return;

        const dirty = editorMode === 'create'
            ? String(editorNameInput?.value || '').trim() !== '' || editorTextarea.value !== ''
            : editorTextarea.value !== editorOriginalValue;
        editorStatus.textContent = dirty ? 'Unsaved changes' : 'Saved';
        editorStatus.classList.toggle('is-dirty', dirty);
    }

    function setEditorNotice(message = '', isError = false) {
        if (!editorNotice) return;

        if (!message) {
            editorNotice.hidden = true;
            editorNotice.textContent = '';
            editorNotice.classList.remove('is-error');
            return;
        }

        editorNotice.hidden = false;
        editorNotice.textContent = message;
        editorNotice.classList.toggle('is-error', !!isError);
    }

    function openEditorModal() {
        if (!editorModal) return;
        editorModal.hidden = false;
        document.body.classList.add('fbg-modal-open');
    }

    async function closeEditorModal(force = false) {
        if (!editorModal) return;

        const isDirty = editorTextarea && (
            editorMode === 'create'
                ? String(editorNameInput?.value || '').trim() !== '' || editorTextarea.value !== ''
                : editorTextarea.value !== editorOriginalValue
        );
        if (!force && isDirty && !editorIsSaving) {
            const confirmed = await confirmFilesAction(
                'Discard changes?',
                'You have unsaved changes in the editor. Closing now will discard them.',
                'Discard',
                'Keep Editing',
                { variant: 'danger' }
            );
            if (!confirmed) {
                return;
            }
        }

        editorModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');

        editorFilePath = '';
        editorOriginalValue = '';
        editorIsLoading = false;
        editorIsSaving = false;
        editorMode = 'edit';
        editorCreateDirectory = '/';
        setEditorLanguage('plain');

        if (editorTitle) {
            editorTitle.textContent = 'Edit File';
        }

        if (editorNameField) {
            editorNameField.hidden = true;
        }

        if (editorNameInput) {
            editorNameInput.value = '';
        }

        if (editorTextarea) {
            editorTextarea.value = '';
            editorTextarea.readOnly = false;
        }
        renderCodeEditor();

        if (editorPathLabel) {
            editorPathLabel.textContent = '/';
        }

        if (editorStatus) {
            editorStatus.textContent = 'Saved';
            editorStatus.classList.remove('is-dirty');
        }

        setEditorNotice('');
    }

    function openCreateFileModal() {
        editorMode = 'create';
        editorCreateDirectory = normalizePath(currentDirectory);
        editorFilePath = '';
        editorOriginalValue = '';
        editorIsLoading = false;
        editorIsSaving = false;
        setEditorLanguage('plain');

        if (editorTitle) {
            editorTitle.textContent = 'Create File';
        }

        if (editorNameField) {
            editorNameField.hidden = false;
        }

        if (editorNameInput) {
            editorNameInput.value = '';
        }

        if (editorTextarea) {
            editorTextarea.value = '';
            editorTextarea.readOnly = false;
        }

        if (editorStatus) {
            editorStatus.textContent = 'Unsaved changes';
            editorStatus.classList.add('is-dirty');
        }

        setEditorNotice('');
        updateCreateFilePathPreview();
        renderCodeEditor();
        openEditorModal();

        setTimeout(() => {
            editorNameInput?.focus();
        }, 0);
    }

    async function loadEditorFile(entry) {
        if (!entry || !entry.is_file) return;

        const cleanPath = normalizePath(entry.path || '/');
        const fileSize = Number(entry.size || 0);

        editorFilePath = cleanPath;
        editorOriginalValue = '';
        editorIsLoading = true;
        editorMode = 'edit';
        editorCreateDirectory = '/';
        setEditorLanguage(getEditorLanguageFromPath(cleanPath));

        openEditorModal();

        if (editorTitle) {
            editorTitle.textContent = 'Edit File';
        }

        if (editorNameField) {
            editorNameField.hidden = true;
        }

        if (editorNameInput) {
            editorNameInput.value = '';
        }

        if (editorPathLabel) {
            editorPathLabel.textContent = cleanPath;
        }

        if (editorStatus) {
            editorStatus.textContent = 'Loading...';
            editorStatus.classList.remove('is-dirty');
        }

        setEditorNotice('');

        if (editorTextarea) {
            editorTextarea.value = '';
            editorTextarea.readOnly = true;
        }
        renderCodeEditor();

        if (fileSize > MAX_EDITOR_FILE_SIZE) {
            if (editorTextarea) {
                editorTextarea.value = '';
                editorTextarea.readOnly = true;
            }
            renderCodeEditor();

            setEditorNotice('This file is too large to edit in the browser right now.', true);

            if (editorStatus) {
                editorStatus.textContent = 'Read only';
            }

            editorIsLoading = false;
            return;
        }

        try {
            const payload = await fetchJson(
                `/api/server/files/read.php?id=${encodeURIComponent(serverId)}&file=${encodeURIComponent(cleanPath)}`,
                {},
                'Invalid JSON from file read endpoint:'
            );

            const contents = String(payload.contents ?? '');

            if (editorTextarea) {
                editorTextarea.value = contents;
                editorTextarea.readOnly = false;
                editorTextarea.focus();
            }
            renderCodeEditor();

            editorOriginalValue = contents;
            setEditorDirtyState();
        } catch (error) {
            console.error('Read file error:', error);

            if (editorTextarea) {
                editorTextarea.value = '';
                editorTextarea.readOnly = true;
            }
            renderCodeEditor();

            setEditorNotice(error.message || 'Failed to load file.', true);

            if (editorStatus) {
                editorStatus.textContent = 'Read only';
            }
        } finally {
            editorIsLoading = false;
        }
    }

    async function saveEditorFile() {
        if (!editorTextarea || editorIsLoading || editorIsSaving) {
            return;
        }

        if (editorMode === 'create') {
            const fileName = String(editorNameInput?.value || '').trim();

            if (!isValidFileName(fileName)) {
                setEditorNotice('Please enter a valid file name.', true);
                editorNameInput?.focus();
                return;
            }

            const fileExtension = getExtension(fileName);
            if (!fileExtension || !EDITABLE_EXTENSIONS.has(fileExtension)) {
                setEditorNotice('This file type is not editable in the browser.', true);
                editorNameInput?.focus();
                return;
            }

            editorFilePath = buildChildPath(editorCreateDirectory, fileName);

            if (findEntryByPath(editorFilePath)) {
                setEditorNotice('A file or folder with that name already exists.', true);
                editorNameInput?.focus();
                return;
            }
        }

        if (!editorFilePath) {
            return;
        }

        const contents = editorTextarea.value;

        if (editorMode !== 'create' && contents === editorOriginalValue) {
            closeEditorModal(true);
            return;
        }

        editorIsSaving = true;

        try {
            await withButtonBusyState(editorSaveButton, 'Saving...', async () => {
                if (editorMode === 'create') {
                    await postJson(
                        '/api/server/files/create-file.php',
                        {
                            id: serverId,
                            path: editorCreateDirectory,
                            name: getBaseName(editorFilePath),
                            contents: contents
                        },
                        'Invalid JSON from create-file endpoint:'
                    );
                } else {
                    await postJson(
                        '/api/server/files/write.php',
                        {
                            id: serverId,
                            path: editorFilePath,
                            contents: contents
                        },
                        'Invalid JSON from file write endpoint:'
                    );
                }

                editorOriginalValue = contents;
                setEditorDirtyState();
                const savedFileName = getBaseName(editorFilePath);
                const createdFile = editorMode === 'create';
                closeEditorModal(true);
                await loadFiles();

                if (typeof window.FBGToast === 'function') {
                    window.FBGToast({
                        type: 'success',
                        title: 'File Manager',
                        message: createdFile ? `# File created:\n### ${savedFileName}` : `# File saved:\n### ${savedFileName}`,
                    });
                } else {
                    showFilesMessageTimed(
                        createdFile ? `File "${savedFileName}" created.` : `Saved "${savedFileName}"`,
                        false
                    );
                }
                
            });
        } catch (error) {
            console.error('Save file error:', error);
            setEditorNotice(error.message || 'Failed to save file.', true);
            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: "We couldn't save that file.\nPlease check the file and try again.",
            });
        } finally {
            editorIsSaving = false;
        }
    }

    function attachRowHandlers() {
        const rows = tableBody.querySelectorAll('tr[data-row-path]');

        rows.forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, textarea, select')) {
                    return;
                }

                const path = row.dataset.rowPath || '/';
                const rowType = row.dataset.rowType || '';

                if (rowType === 'folder') {
                    window.location.href = buildFolderUrl(path);
                    return;
                }

                if (rowType === 'editable-file') {
                    const entry = findEntryByPath(path);
                    if (entry) {
                        loadEditorFile(entry);
                    }
                }
            });
        });
    }

    function closeAllActionMenus() {
        if (activeFloatingMenu) {
            activeFloatingMenu.remove();
            activeFloatingMenu = null;
        }

        if (activeMenuButton) {
            activeMenuButton.setAttribute('aria-expanded', 'false');
            activeMenuButton = null;
        }
    }

    function positionFloatingMenu(menu, button) {
        const buttonRect = button.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const viewportPadding = 10;

        let top = buttonRect.bottom + 8;
        let left = buttonRect.right - menuRect.width;

        if (left < viewportPadding) {
            left = viewportPadding;
        }

        if (left + menuRect.width > window.innerWidth - viewportPadding) {
            left = window.innerWidth - menuRect.width - viewportPadding;
        }

        if (top + menuRect.height > window.innerHeight - viewportPadding) {
            top = buttonRect.top - menuRect.height - 8;
        }

        if (top < viewportPadding) {
            top = viewportPadding;
        }

        menu.style.top = `${top}px`;
        menu.style.left = `${left}px`;
    }

    function openActionMenu(button) {
        const wrapper = button.closest('.fbg-files-row-actions');
        const originalMenu = wrapper?.querySelector('.fbg-files-action-menu');

        if (!wrapper || !originalMenu) return;

        const wasSameButton = activeMenuButton === button;
        closeAllActionMenus();

        if (wasSameButton) {
            return;
        }

        const floatingMenu = originalMenu.cloneNode(true);
        floatingMenu.classList.add('is-floating');
        floatingMenu.hidden = false;

        document.body.appendChild(floatingMenu);

        activeFloatingMenu = floatingMenu;
        activeMenuButton = button;
        activeMenuButton.setAttribute('aria-expanded', 'true');

        positionFloatingMenu(floatingMenu, button);
        attachFloatingActionItemHandlers(floatingMenu);
    }

    function openRenameModal(path, currentName) {
        if (!renameModal || !renamePathInput || !renameNameInput) return;

        renamePathInput.value = normalizePath(path);
        renameNameInput.value = currentName || '';
        renameModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        window.requestAnimationFrame(() => {
            renameNameInput.focus();
            renameNameInput.select();
        });
    }

    function closeRenameModal() {
        if (!renameModal) return;

        renameModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');

        if (renameForm) {
            renameForm.reset();
        }

        if (renamePathInput) {
            renamePathInput.value = '';
        }
    }

    async function submitRename(path, newName) {
        return postJson(
            '/api/server/files/rename.php',
            {
                id: serverId,
                path: path,
                name: newName
            },
            'Invalid JSON from file rename endpoint:'
        );
    }

    function openNewFolderModal() {
        if (!newFolderModal) return;

        newFolderModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        requestAnimationFrame(() => {
            newFolderNameInput?.focus();
        });
    }

    function closeNewFolderModal() {
        if (!newFolderModal) return;

        newFolderModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');
        newFolderForm?.reset();
    }

    async function handleActionClick(item) {
        const action = item.dataset.filesAction || '';
        const path = item.dataset.path || '/';
        const name = item.dataset.name || 'item';

        closeAllActionMenus();

        if (action === 'open') {
            window.location.href = buildFolderUrl(path);
            return;
        }

        if (action === 'download') {
            window.location.href = buildDownloadUrl(path);
            return;
        }

        if (action === 'rename') {
            openRenameModal(path, name);
            return;
        }

        if (action === 'delete') {
            const confirmed = await confirmFilesAction(
                'Delete item?',
                `Delete "${name}"? This cannot be undone.`,
                'Delete',
                'Cancel',
                { variant: 'danger' }
            );
            if (!confirmed) {
                return;
            }

            postJson(
                '/api/server/files/delete.php',
                {
                    id: serverId,
                    path: path
                },
                'Invalid JSON from file delete endpoint:'
            )
            .then(async () => {
                showFilesToast({
                    type: 'success',
                    title: 'File Manager',
                    message: `# Item deleted:\n### ${formatToastText(name)}`,
                });
                await loadFiles();
            })
            .catch((error) => {
                console.error('Delete error:', error);
                showFilesToast({
                    type: 'error',
                    title: 'File Manager',
                    message: "We couldn't delete that item.\nPlease try again in a moment.",
                });
            });

            return;
        }

        if (action === 'edit') {
            const entry = findEntryByPath(path);

            if (!entry || !isEditableFile(entry)) {
                showFilesToast({
                    type: 'warning',
                    title: 'File Manager',
                    message: "This file type can't be edited here yet.",
                });
                return;
            }

            loadEditorFile(entry);
        }
    }

    function attachFloatingActionItemHandlers(menu) {
        const actionItems = menu.querySelectorAll('[data-files-action]');

        actionItems.forEach((item) => {
            item.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                handleActionClick(item);
            });
        });
    }

    function attachActionMenuHandlers() {
        const actionButtons = tableBody.querySelectorAll('[data-files-action-toggle]');

        actionButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openActionMenu(button);
            });
        });
    }

    function buildActionsMenu(entry) {
        const name = entry.name || 'Unnamed';
        const cleanPath = normalizePath(entry.path || '/');
        const isFile = !!entry.is_file;

        const menuItems = [];

        if (isFile && isEditableFile(entry)) {
            menuItems.push(`
                <button
                    type="button"
                    class="fbg-files-action-item"
                    data-files-action="edit"
                    data-path="${escapeHtml(cleanPath)}"
                    data-name="${escapeHtml(name)}"
                    data-is-file="1"
                >
                    <i class="fas fa-file-pen"></i>
                    <span>Edit</span>
                </button>
            `);
        }

        if (isFile) {
            menuItems.push(`
                <button
                    type="button"
                    class="fbg-files-action-item"
                    data-files-action="download"
                    data-path="${escapeHtml(cleanPath)}"
                    data-name="${escapeHtml(name)}"
                    data-is-file="1"
                >
                    <i class="fas fa-download"></i>
                    <span>Download</span>
                </button>
            `);
        } else {
            menuItems.push(`
                <button
                    type="button"
                    class="fbg-files-action-item"
                    data-files-action="open"
                    data-path="${escapeHtml(cleanPath)}"
                    data-name="${escapeHtml(name)}"
                    data-is-file="0"
                >
                    <i class="fas fa-folder-open"></i>
                    <span>Open</span>
                </button>
            `);
        }

        menuItems.push(`
            <button
                type="button"
                class="fbg-files-action-item"
                data-files-action="rename"
                data-path="${escapeHtml(cleanPath)}"
                data-name="${escapeHtml(name)}"
                data-is-file="${isFile ? '1' : '0'}"
            >
                <i class="fas fa-pen"></i>
                <span>Rename</span>
            </button>
        `);

        menuItems.push(`
            <button
                type="button"
                class="fbg-files-action-item is-danger"
                data-files-action="delete"
                data-path="${escapeHtml(cleanPath)}"
                data-name="${escapeHtml(name)}"
                data-is-file="${isFile ? '1' : '0'}"
            >
                <i class="fas fa-trash"></i>
                <span>Delete</span>
            </button>
        `);

        return `
            <div class="fbg-files-row-actions">
                <button
                    type="button"
                    class="btn fbg-neutral-button btn-sm fbg-files-action-toggle"
                    data-files-action-toggle
                    aria-label="Open actions menu"
                    aria-haspopup="true"
                    aria-expanded="false"
                >
                    <i class="fas fa-ellipsis"></i>
                </button>

                <div class="fbg-files-action-menu" hidden>
                    ${menuItems.join('')}
                </div>
            </div>
        `;
    }

    function renderRows(items) {
        closeAllActionMenus();

        if (!Array.isArray(items) || items.length === 0) {
            tableBody.innerHTML = `
                <tr class="fbg-files-empty-row">
                    <td colspan="4">${currentSearchTerm.trim() ? 'No files match your search.' : 'This folder is empty.'}</td>
                </tr>
            `;
            return;
        }

        const rows = [];

        if (currentDirectory !== '/') {
            const parentDir = getParentDirectory(currentDirectory);
            rows.push(`
                <tr class="fbg-folder-row fbg-files-clickable-row" data-row-type="folder" data-row-path="${escapeHtml(parentDir)}">
                    <td>
                        <div class="fbg-files-name-cell">
                            <i class="fas fa-arrow-left"></i>
                            <span>..</span>
                        </div>
                    </td>
                    <td>Folder</td>
                    <td>—</td>
                    <td>
                        <a class="btn fbg-neutral-button btn-sm" href="${buildFolderUrl(parentDir)}">Up</a>
                    </td>
                </tr>
            `);
        }

        rows.push(...items.map((entry) => {
            const name = entry.name || 'Unnamed';
            const isFile = !!entry.is_file;
            const cleanPath = normalizePath(entry.path || '/');

            let rowClasses = [];
            let rowAttributes = `data-row-path="${escapeHtml(cleanPath)}"`;

            if (!isFile) {
                rowClasses.push('fbg-folder-row', 'fbg-files-clickable-row');
                rowAttributes += ' data-row-type="folder"';
            } else if (isEditableFile(entry)) {
                rowClasses.push('fbg-files-clickable-row', 'fbg-files-editable-row');
                rowAttributes += ' data-row-type="editable-file"';
            } else {
                rowAttributes += ' data-row-type="file"';
            }

            const rowClassAttr = rowClasses.length
                ? ` class="${rowClasses.join(' ')}"`
                : '';

            return `
                <tr${rowClassAttr} ${rowAttributes}>
                    <td>
                        <div class="fbg-files-name-cell">
                            <i class="${escapeHtml(getRowIcon(entry))}"></i>
                            <span>${escapeHtml(name)}</span>
                        </div>
                    </td>
                    <td>${isFile ? escapeHtml(formatBytes(entry.size ?? 0)) : 'Folder'}</td>
                    <td>${formatDate(entry.modified_at || entry.modifiedAt || '')}</td>
                    <td>${buildActionsMenu(entry)}</td>
                </tr>
            `;
        }));

        tableBody.innerHTML = rows.join('');
        attachRowHandlers();
        attachActionMenuHandlers();
    }

    function getFilteredItems() {
        const items = Array.isArray(currentItems) ? currentItems : [];
        const term = currentSearchTerm.trim().toLowerCase();

        if (!term) {
            return items;
        }

        return items.filter((entry) => {
            const name = String(entry?.name || '').toLowerCase();
            return name.includes(term);
        });
    }

    function updateSearchClearButton() {
        if (!searchClearButton) return;
        searchClearButton.hidden = currentSearchTerm.trim() === '';
    }

    function updatePaginationControls() {
        if (!paginationWrap || !paginationSummary || !paginationPages || !paginationPrevButton || !paginationNextButton) {
            return;
        }

        const filteredItems = getFilteredItems();
        const totalItems = filteredItems.length;

        if (totalItems === 0) {
            paginationSummary.textContent = currentSearchTerm.trim()
                ? 'Showing 0-0 of 0 matching items'
                : 'Showing 0-0 of 0 items';
            window.FBGPagination?.renderPageNumbers(paginationPages, {
                currentPage: 1,
                totalPages: 1,
            });
            paginationPrevButton.disabled = true;
            paginationNextButton.disabled = true;
            return;
        }

        const totalPages = Math.max(1, Math.ceil(totalItems / rowsPerPage));
        currentPage = Math.min(Math.max(currentPage, 1), totalPages);

        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = Math.min(startIndex + rowsPerPage, totalItems);

        paginationSummary.textContent = currentSearchTerm.trim()
            ? `Showing ${startIndex + 1}-${endIndex} of ${totalItems} matching items`
            : `Showing ${startIndex + 1}-${endIndex} of ${totalItems} items`;

        window.FBGPagination?.renderPageNumbers(paginationPages, {
            currentPage,
            totalPages,
            onPageChange: (nextPage) => {
                currentPage = nextPage;
                renderCurrentPage();
                scrollToFilesTop();
            },
        });
        paginationPrevButton.disabled = currentPage <= 1;
        paginationNextButton.disabled = currentPage >= totalPages;
    }

    function renderCurrentPage() {
        const filteredItems = getFilteredItems();
        const totalItems = filteredItems.length;

        if (totalItems === 0) {
            renderRows([]);
            updatePaginationControls();
            return;
        }

        const totalPages = Math.max(1, Math.ceil(totalItems / rowsPerPage));
        currentPage = Math.min(Math.max(currentPage, 1), totalPages);

        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = Math.min(startIndex + rowsPerPage, totalItems);
        const pageItems = filteredItems.slice(startIndex, endIndex);

        renderRows(pageItems);
        updatePaginationControls();
    }

    function setItemsAndRender(items, resetPage = false) {
        currentItems = Array.isArray(items) ? items : [];

        if (resetPage) {
            currentPage = 1;
        }

        updateSearchClearButton();
        renderCurrentPage();
    }

    function containsDirectory(items) {
        if (!items) return false;

        for (const item of items) {
            if (item.webkitGetAsEntry) {
                const entry = item.webkitGetAsEntry();
                if (entry && entry.isDirectory) {
                    return true;
                }
            }

            if (item.type === '' && item.size === 0) {
                return true;
            }
        }

        return false;
    }

    function createUploadItem(files) {
        if (!uploadQueue) return null;

        uploadQueue.hidden = false;

        const item = document.createElement('div');
        item.className = 'fbg-files-upload-item';
        item.innerHTML = `
            <div class="fbg-files-upload-top">
                <div class="fbg-files-upload-name">${escapeHtml(
                    files.length === 1 ? files[0].name : `${files.length} files`
                )}</div>
                <div class="fbg-files-upload-status">Waiting...</div>
            </div>
            <div class="fbg-files-upload-progress">
                <div class="fbg-files-upload-progress-bar"></div>
            </div>
        `;

        uploadQueue.prepend(item);
        return item;
    }

    function updateUploadItem(item, percent, statusText, isError = false) {
        if (!item) return;

        const status = item.querySelector('.fbg-files-upload-status');
        const bar = item.querySelector('.fbg-files-upload-progress-bar');

        if (status) {
            status.textContent = statusText;
            status.classList.toggle('is-error', !!isError);
        }

        if (bar) {
            bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
            bar.classList.toggle('is-error', !!isError);
        }
    }

    async function getUploadUrl() {
        const payload = await postJson(
            '/api/server/files/upload.php',
            {
                id: serverId,
                directory: currentDirectory
            },
            'Invalid JSON from file upload URL endpoint:'
        );

        if (!payload.upload_url) {
            throw new Error('Failed to get upload URL.');
        }

        return payload.upload_url;
    }

    function addDirectoryToUploadUrl(uploadUrl, directory) {
        const cleanDirectory = normalizePath(directory || '/');
        const separator = uploadUrl.includes('?') ? '&' : '?';

        return `${uploadUrl}${separator}directory=${encodeURIComponent(cleanDirectory)}`;
    }

    function uploadFilesToSignedUrl(uploadUrl, files, directory, progressCallback) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const formData = new FormData();

            for (const file of files) {
                formData.append('files', file, file.name);
            }

            xhr.open('POST', addDirectoryToUploadUrl(uploadUrl, directory), true);

            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                progressCallback(percent);
            });

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve();
                    return;
                }

                let message = 'Upload failed.';
                try {
                    const payload = JSON.parse(xhr.responseText);
                    message =
                        payload?.errors?.[0]?.detail ||
                        payload?.error ||
                        message;
                } catch (e) {
                    if (xhr.responseText) {
                        message = xhr.responseText;
                    }
                }

                reject(new Error(message));
            });

            xhr.addEventListener('error', () => {
                reject(new Error('Network error while uploading.'));
            });

            xhr.addEventListener('abort', () => {
                reject(new Error('Upload cancelled.'));
            });

            xhr.send(formData);
        });
    }

    async function startUpload(fileList) {
        const files = Array.from(fileList || []).filter((file) => file instanceof File);

        if (!files.length) {
            return;
        }

        if (uploadInProgress) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: 'An upload is already running.\nPlease wait for it to finish before starting another one.',
            });
            return;
        }

        uploadInProgress = true;
        hideFilesMessage();

        const uploadItem = createUploadItem(files);

        try {
            updateUploadItem(uploadItem, 0, 'Preparing upload...');
            const uploadUrl = await getUploadUrl();

            await uploadFilesToSignedUrl(uploadUrl, files, currentDirectory, (percent) => {
                updateUploadItem(
                    uploadItem,
                    percent,
                    percent >= 100 ? 'Finishing...' : `Uploading... ${percent}%`
                );
            });

            const bar = uploadItem?.querySelector('.fbg-files-upload-progress-bar');

            setTimeout(() => {
                updateUploadItem(uploadItem, 100, 'Upload complete');
                bar?.classList.add('is-success');
            }, 150);

            setTimeout(() => {
                if (!uploadItem) return;

                uploadItem.classList.add('is-completing');

                setTimeout(() => {
                    uploadItem.remove();

                    if (uploadQueue && uploadQueue.children.length === 0) {
                        uploadQueue.hidden = true;
                    }
                }, 250);
            }, 3500);

            if (uploadInput) {
                uploadInput.value = '';
            }

            await loadFiles();

            showFilesToast({
                type: 'success',
                title: 'File Manager',
                message: files.length === 1
                    ? `# Upload complete:\n### ${formatToastText(files[0].name)}`
                    : `# Upload complete:\n### ${files.length} files uploaded`,
            });
        } catch (error) {
            console.error('Upload error:', error);

            const message = error.message === 'Network error while uploading.'
                ? 'Connection lost during upload.'
                : error.message || 'Upload failed.';

            updateUploadItem(uploadItem, 100, message, true);

            const bar = uploadItem?.querySelector('.fbg-files-upload-progress-bar');
            bar?.classList.add('is-error');

            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: message === 'Connection lost during upload.'
                    ? 'The upload was interrupted.\nPlease check your connection and try again.'
                    : "We couldn't upload that file.\nPlease try again in a moment.",
            });

            setTimeout(() => {
                if (!uploadItem) return;

                uploadItem.classList.add('is-completing');

                setTimeout(() => {
                    uploadItem.remove();

                    if (uploadQueue && uploadQueue.children.length === 0) {
                        uploadQueue.hidden = true;
                    }
                }, 250);
            }, 3500);
        } finally {
            uploadInProgress = false;
        }
    }

    function showDropzone() {
        if (dropzoneHint) {
            dropzoneHint.hidden = false;
        }
        tableWrap?.classList.add('is-dragging');
    }

    function hideDropzone() {
        if (dropzoneHint) {
            dropzoneHint.hidden = true;
        }
        tableWrap?.classList.remove('is-dragging');
    }

    async function loadFiles() {
        closeAllActionMenus();

        if (!serverId) {
            tableBody.innerHTML = `
                <tr class="fbg-files-empty-row">
                    <td colspan="4">Missing server identifier.</td>
                </tr>
            `;
            currentItems = [];
            updatePaginationControls();
            return;
        }

        hideFilesMessage();

        tableBody.innerHTML = `
            <tr class="fbg-files-loading-row">
                <td colspan="4">Loading files...</td>
            </tr>
        `;

        try {
            const payload = await fetchJson(
                `/api/server/files.php?id=${encodeURIComponent(serverId)}&dir=${encodeURIComponent(currentDirectory)}`,
                {},
                'Invalid JSON from files list endpoint:'
            );

            currentDirectory = normalizePath(payload.directory || currentDirectory);

            currentSearchTerm = '';
            if (searchInput) {
                searchInput.value = '';
            }
            updateSearchClearButton();

            setItemsAndRender(payload.items || [], true);
        } catch (error) {
            console.error('Files list error:', error);
            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: "We couldn't open this folder.\nPlease refresh the file manager and try again.",
            });
            tableBody.innerHTML = `
                <tr class="fbg-files-empty-row">
                    <td colspan="4">Unable to load this directory.</td>
                </tr>
            `;
            currentItems = [];
            updatePaginationControls();
        }
    }

    renameCloseButton?.addEventListener('click', closeRenameModal);
    renameCancelButton?.addEventListener('click', closeRenameModal);

    renameModal?.addEventListener('click', (event) => {
        if (event.target === renameModal) {
            closeRenameModal();
        }
    });

    newFolderButton?.addEventListener('click', openNewFolderModal);
    newFolderClose?.addEventListener('click', closeNewFolderModal);
    newFolderCancel?.addEventListener('click', closeNewFolderModal);
    newFileButton?.addEventListener('click', openCreateFileModal);

    newFolderModal?.addEventListener('click', (event) => {
        if (event.target === newFolderModal) {
            closeNewFolderModal();
        }
    });

    renameForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const path = normalizePath(renamePathInput?.value || '');
        const newName = String(renameNameInput?.value || '').trim();
        const oldName = getBaseName(path);

        if (!path || path === '/') {
            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: "We couldn't find that file or folder.\nPlease refresh and try again.",
            });
            return;
        }

        if (!newName) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: 'Please enter a new name before saving.',
            });
            renameNameInput?.focus();
            return;
        }

        if (newName === '.' || newName === '..' || /[\/\\]/.test(newName)) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: "That name won't work here.\nUse a name without slashes.",
            });
            renameNameInput?.focus();
            return;
        }

        if (newName === oldName) {
            closeRenameModal();
            return;
        }

        try {
            await withButtonBusyState(renameSubmitButton, 'Renaming...', async () => {
                await submitRename(path, newName);
                closeRenameModal();
                await loadFiles();
                showFilesToast({
                    type: 'success',
                    title: 'File Manager',
                    message: `# Item renamed:\n**${formatToastText(oldName)}** is now **${formatToastText(newName)}**`,
                });
            });
        } catch (error) {
            console.error('Rename error:', error);
            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: "We couldn't rename that item.\nPlease try again in a moment.",
            });
        }
    });

    newFolderForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const name = String(newFolderNameInput?.value || '').trim();

        if (!name) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: 'Please enter a folder name before creating it.',
            });
            return;
        }

        if (/[\/\\]/.test(name) || name === '.' || name === '..') {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: "That folder name won't work here.\nUse a name without slashes.",
            });
            return;
        }

        try {
            await withButtonBusyState(newFolderSubmit, 'Creating...', async () => {
                await postJson(
                    '/api/server/files/create-folder.php',
                    {
                        id: serverId,
                        path: currentDirectory,
                        name: name
                    },
                    'Invalid JSON from create-folder endpoint:'
                );

                closeNewFolderModal();
                await loadFiles();
                showFilesToast({
                    type: 'success',
                    title: 'File Manager',
                    message: `# Folder created:\n### ${formatToastText(name)}`,
                });
            });
        } catch (error) {
            console.error(error);
            showFilesToast({
                type: 'error',
                title: 'File Manager',
                message: "We couldn't create that folder.\nPlease try again in a moment.",
            });
        }
    });

    function scrollToFilesTop() {
        tableWrap?.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    searchInput?.addEventListener('input', () => {
        currentSearchTerm = searchInput.value || '';
        currentPage = 1;
        updateSearchClearButton();
        renderCurrentPage();
    });

    searchClearButton?.addEventListener('click', () => {
        currentSearchTerm = '';
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        currentPage = 1;
        updateSearchClearButton();
        renderCurrentPage();
    });

    perPageSelect?.addEventListener('change', () => {
        const nextValue = Number(perPageSelect.value || 50);
        rowsPerPage = Number.isFinite(nextValue) && nextValue > 0 ? nextValue : 50;

        localStorage.setItem('fbg_files_rows_per_page', String(rowsPerPage));

        currentPage = 1;
        renderCurrentPage();
    });

    paginationPrevButton?.addEventListener('click', () => {
        if (currentPage <= 1) {
            return;
        }

        currentPage -= 1;
        renderCurrentPage();
        scrollToFilesTop();
    });

    paginationNextButton?.addEventListener('click', () => {
        const filteredItems = getFilteredItems();
        const totalPages = Math.max(1, Math.ceil(filteredItems.length / rowsPerPage));

        if (currentPage >= totalPages) {
            return;
        }

        currentPage += 1;
        renderCurrentPage();
        scrollToFilesTop();
    });

    refreshButton?.addEventListener('click', loadFiles);

    document.addEventListener('click', (event) => {
        if (
            !event.target.closest('.fbg-files-action-menu.is-floating') &&
            !event.target.closest('[data-files-action-toggle]')
        ) {
            closeAllActionMenus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (renameModal && !renameModal.hidden) {
                closeRenameModal();
                return;
            }

            if (newFolderModal && !newFolderModal.hidden) {
                closeNewFolderModal();
                return;
            }

            if (editorModal && !editorModal.hidden) {
                closeEditorModal();
                return;
            }

            closeAllActionMenus();
        }
    });

    window.addEventListener('resize', () => {
        if (activeFloatingMenu && activeMenuButton) {
            positionFloatingMenu(activeFloatingMenu, activeMenuButton);
        }
    });

    document.addEventListener('scroll', () => {
        if (activeFloatingMenu && activeMenuButton) {
            positionFloatingMenu(activeFloatingMenu, activeMenuButton);
        }
    }, true);

    uploadButton?.addEventListener('click', () => {
        if (uploadInProgress) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: 'An upload is already running.\nPlease wait for it to finish before starting another one.',
            });
            return;
        }

        uploadInput?.click();
    });

    uploadInput?.addEventListener('change', (event) => {
        const files = event.target.files;

        for (const file of files) {
            if (!file.name || (file.size === 0 && file.type === '')) {
                showFilesToast({
                    type: 'warning',
                    title: 'File Manager',
                    message: "Folder uploads aren't supported here yet.\nPlease upload individual files instead.",
                });
                return;
            }
        }

        startUpload(files);
    });

    tableWrap?.addEventListener('dragenter', (event) => {
        event.preventDefault();
        dragDepth++;
        showDropzone();
    });

    tableWrap?.addEventListener('dragover', (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });

    tableWrap?.addEventListener('dragleave', (event) => {
        event.preventDefault();
        dragDepth--;

        if (dragDepth <= 0) {
            dragDepth = 0;
            hideDropzone();
        }
    });

    tableWrap?.addEventListener('drop', (event) => {
        event.preventDefault();
        dragDepth = 0;
        hideDropzone();

        const items = event.dataTransfer?.items;

        if (containsDirectory(items)) {
            showFilesToast({
                type: 'warning',
                title: 'File Manager',
                message: "Folder uploads aren't supported here yet.\nPlease upload individual files instead.",
            });
            return;
        }

        const files = event.dataTransfer?.files;
        if (files && files.length) {
            startUpload(files);
        }
    });

    editorCloseButton?.addEventListener('click', () => closeEditorModal());
    editorCancelButton?.addEventListener('click', () => closeEditorModal());
    editorSaveButton?.addEventListener('click', saveEditorFile);
    editorNameInput?.addEventListener('input', () => {
        updateCreateFilePathPreview();
        setEditorDirtyState();
    });

    editorTextarea?.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
            event.preventDefault();

            const start = editorTextarea.selectionStart;
            const end = editorTextarea.selectionEnd;

            editorTextarea.value =
                editorTextarea.value.substring(0, start) +
                '    ' +
                editorTextarea.value.substring(end);

            editorTextarea.selectionStart = editorTextarea.selectionEnd = start + 4;
            renderCodeEditor();
            setEditorDirtyState();
        }
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            if (editorModal && !editorModal.hidden) {
                event.preventDefault();
                saveEditorFile();
            }
        }
    });

    editorModal?.addEventListener('click', (event) => {
        if (event.target === editorModal) {
            closeEditorModal();
        }
    });

    editorTextarea?.addEventListener('input', () => {
        renderCodeEditor();
        setEditorDirtyState();
    });

    editorTextarea?.addEventListener('scroll', syncEditorScroll);

    loadFiles();
})();
