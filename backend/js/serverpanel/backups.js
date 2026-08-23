(() => {
    const panel = document.querySelector('.fbg-backups-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';

    const canCreate = panel.dataset.canCreate === '1';
    const canDownload = panel.dataset.canDownload === '1';
    const canRestore = panel.dataset.canRestore === '1';
    const canDelete = panel.dataset.canDelete === '1';

    const listEl = document.getElementById('backups-list');
    const messageEl = document.getElementById('backups-message');
    const summaryEl = document.getElementById('backups-summary');

    const createButton = document.getElementById('create-backup-button');
    const createModal = document.getElementById('backup-create-modal');
    const createForm = document.getElementById('backup-create-form');
    const createClose = document.getElementById('backup-create-close');
    const createCancel = document.getElementById('backup-create-cancel');
    const createSubmit = document.getElementById('backup-create-submit');
    const createNameInput = document.getElementById('backup-name');
    const createIgnoredInput = document.getElementById('backup-ignored');
    const createLockedInput = document.getElementById('backup-is-locked');

    if (createModal && createModal.parentElement !== document.body) {
        document.body.appendChild(createModal);
    }

    let activeMenu = null;
    let messageTimeout = null;
    let pollTimeout = null;
    let backupsPollTimer = null;

    const API_BASE = '/api/server/backups';

    function startBackupsPolling() {
        if (backupsPollTimer) return;

        backupsPollTimer = setInterval(() => {
            loadBackups();
        }, 15000); // 15 seconds
    }

    function stopBackupsPolling() {
        if (backupsPollTimer) {
            clearInterval(backupsPollTimer);
            backupsPollTimer = null;
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBytes(bytes) {
        const value = Number(bytes || 0);
        if (!Number.isFinite(value) || value <= 0) return '0 B';

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = value;
        let unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit += 1;
        }

        return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 2)} ${units[unit]}`;
    }

    function formatRelativeDate(value) {
        if (!value) return 'Unknown';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return 'Unknown';

        return date.toLocaleString();
    }

    function showMessage(message, isError = false) {
        if (!messageEl) return;

        window.clearTimeout(messageTimeout);

        messageEl.textContent = message;
        messageEl.className = `fbg-dashboard-alert is-visible ${isError ? 'error' : 'success'}`;
        messageEl.style.display = 'block';

        messageTimeout = window.setTimeout(() => {
            messageEl.classList.remove('is-visible');
            messageEl.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function showBackupsToast({ type = 'info', title = 'Backups', message = '', duration, persistent = false } = {}) {
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

        showMessage(cleanMessage.replace(/[#*_~-]/g, ''), type === 'error' || type === 'warning');
    }

    async function confirmAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    function hideMessage() {
        if (!messageEl) return;
        window.clearTimeout(messageTimeout);
        messageEl.classList.remove('is-visible');
        messageEl.style.display = 'none';
    }

    function closeActiveMenu() {
        if (activeMenu) {
            activeMenu.remove();
            activeMenu = null;
        }
    }

    function openCreateModal() {
        if (!createModal) return;
        createModal.hidden = false;
        document.body.classList.add('fbg-modal-open');
        createNameInput?.focus();
    }

    function closeCreateModal() {
        if (!createModal) return;
        createModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');

        if (createForm) {
            createForm.reset();
        }
    }

    function getIgnoredArray(rawValue) {
        return String(rawValue || '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);
    }

    function renderBackupRow(backup) {
        const name = backup.name && String(backup.name).trim() !== '' ? backup.name : 'Unnamed backup';
        const uuid = String(backup.uuid || '');
        const checksum = backup.checksum ? String(backup.checksum) : '';
        const createdAt = formatRelativeDate(backup.created_at);
        const size = formatBytes(backup.bytes);
        const isLocked = !!backup.is_locked;
        const isSuccessful = backup.is_successful !== false;

        const actions = [];

        if (canDownload) {
            actions.push(`<button type="button" class="fbg-backup-menu-item" data-action="download" data-uuid="${escapeHtml(uuid)}"><i class="fas fa-cloud-arrow-down"></i> Download</button>`);
        }

        if (canRestore) {
            actions.push(`<button type="button" class="fbg-backup-menu-item" data-action="restore" data-uuid="${escapeHtml(uuid)}"><i class="fas fa-box-open"></i> Restore</button>`);
        }

        if (canDelete) {
            actions.push(
                `<button type="button" class="fbg-backup-menu-item" data-action="${isLocked ? 'unlock' : 'lock'}" data-uuid="${escapeHtml(uuid)}">
                    <i class="fas ${isLocked ? 'fa-lock-open' : 'fa-lock'}"></i> ${isLocked ? 'Unlock' : 'Lock'}
                </button>`
            );

            actions.push(`<button type="button" class="fbg-backup-menu-item is-danger" data-action="delete" data-uuid="${escapeHtml(uuid)}"><i class="fas fa-trash"></i> Delete</button>`);
        }

        return `
            <div class="fbg-backup-row" data-backup-uuid="${escapeHtml(uuid)}" data-backup-locked="${isLocked ? '1' : '0'}">
                <div class="fbg-backup-left">
                    <div class="fbg-backup-icon">
                        <i class="fas fa-box-archive"></i>
                    </div>

                    <div class="fbg-backup-main">
                        <div class="fbg-backup-title-row">
                            <strong>${escapeHtml(name)}</strong>
                            ${isLocked ? '<span class="fbg-backup-badge">Locked</span>' : ''}
                            ${!isSuccessful ? '<span class="fbg-backup-badge is-warning">Processing</span>' : ''}
                        </div>

                        <div class="fbg-backup-meta">
                            <span>${escapeHtml(size)}</span>
                            ${checksum ? `<span>${escapeHtml(checksum)}</span>` : ''}
                        </div>
                    </div>
                </div>

                <div class="fbg-backup-right">
                    <div class="fbg-backup-created">
                        <strong>${escapeHtml(createdAt)}</strong>
                        <small>Created</small>
                    </div>

                    ${actions.length > 0 ? `
                        <div class="fbg-backup-menu-wrap">
                            <button type="button" class="btn btn-sm fbg-backup-menu-toggle" data-uuid="${escapeHtml(uuid)}" aria-label="Backup actions">
                                <i class="fas fa-ellipsis"></i>
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    function updateSummary(backups) {
        if (!summaryEl) return;

        const total = Array.isArray(backups) ? backups.length : 0;
        summaryEl.textContent = `${total} backup${total === 1 ? '' : 's'} created for this server`;
    }

    async function loadBackups(showLoading = true) {
        closeActiveMenu();

        if (showLoading && listEl) {
            listEl.innerHTML = '<div class="fbg-empty-state">Loading backups...</div>';
        }

        try {
            const response = await fetch(`${API_BASE}/list.php?id=${encodeURIComponent(serverId)}`, {
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Failed to load backups.');
            }

            const backups = Array.isArray(data?.data?.backups) ? data.data.backups : [];
            updateSummary(backups);

            if (backups.length === 0) {
                listEl.innerHTML = '<div class="fbg-empty-state">No backups found for this server yet.</div>';
                return;
            }

            listEl.innerHTML = backups.map(renderBackupRow).join('');
        } catch (error) {
            console.error(error);
            if (listEl) {
                listEl.innerHTML = `<div class="fbg-empty-state">${escapeHtml(error.message || 'Failed to load backups.')}</div>`;
            }
            updateSummary([]);
        }
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            cache: 'no-store',
            body: JSON.stringify(payload)
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Request failed.');
        }

        return data;
    }

    async function createBackup(event) {
        event.preventDefault();
        if (!createForm || !createSubmit) return;

        createSubmit.disabled = true;

        try {
            const payload = {
                id: serverId,
                csrf_token: csrfToken,
                name: createNameInput?.value.trim() || '',
                ignored: getIgnoredArray(createIgnoredInput?.value || ''),
                is_locked: !!createLockedInput?.checked
            };

            await postJson(`${API_BASE}/create.php`, payload);

            closeCreateModal();
            showBackupsToast({
                type: 'success',
                message: 'Backup creation has started.',
            });
            await loadBackups(false);
            queueRefreshBurst();
            startBackupsPolling();
        } catch (error) {
            showBackupsToast({
                type: 'error',
                message: "We couldn't start that backup.\nPlease try again in a moment.",
            });
        } finally {
            createSubmit.disabled = false;
        }
    }

    async function downloadBackup(uuid) {
        try {
            const response = await fetch(`${API_BASE}/download.php?id=${encodeURIComponent(serverId)}&backup=${encodeURIComponent(uuid)}`, {
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            const url = data?.data?.url || '';

            if (!response.ok || !data.ok || !url) {
                throw new Error(data.error || 'Failed to get download link.');
            }

            window.open(url, '_blank', 'noopener,noreferrer');
        } catch (error) {
            showBackupsToast({
                type: 'error',
                message: "We couldn't prepare that backup download.\nPlease try again in a moment.",
            });
        }
    }

    async function restoreBackup(uuid) {
        const confirmed = await confirmAction(
            'Restore Backup?',
            'This will overwrite the current server files.',
            'Restore',
            'Cancel',
            { variant: 'danger' }
        );
        if (!confirmed) return;

        try {
            await postJson(`${API_BASE}/restore.php`, {
                id: serverId,
                backup: uuid,
                csrf_token: csrfToken,
                truncate: true
            });

            showBackupsToast({
                type: 'success',
                message: 'Backup restore has been queued.',
            });
            await loadBackups(false);
            queueRefreshBurst();
        } catch (error) {
            showBackupsToast({
                type: 'error',
                message: "We couldn't queue that restore.\nPlease try again in a moment.",
            });
        }
    }

    async function toggleBackupLock(uuid, shouldLock) {
        try {
            const data = await postJson(`${API_BASE}/lock.php`, {
                id: serverId,
                backup: uuid,
                csrf_token: csrfToken,
                lock: shouldLock
            });

            showBackupsToast({
                type: 'success',
                message: shouldLock ? 'Backup locked.' : 'Backup unlocked.',
            });
            await loadBackups(false);
        } catch (error) {
            showBackupsToast({
                type: 'error',
                message: "We couldn't update that backup lock.\nPlease try again in a moment.",
            });
        }
    }

    async function deleteBackup(uuid) {
        const confirmed = await confirmAction(
            'Delete Backup?',
            'Delete this backup? This cannot be undone.',
            'Delete',
            'Cancel',
            { variant: 'danger' }
        );
        if (!confirmed) return;

        try {
            await postJson(`${API_BASE}/delete.php`, {
                id: serverId,
                backup: uuid,
                csrf_token: csrfToken
            });

            showBackupsToast({
                type: 'success',
                message: 'Backup deleted.',
            });
            await loadBackups(false);
        } catch (error) {
            showBackupsToast({
                type: 'error',
                message: "We couldn't delete that backup.\nPlease try again in a moment.",
            });
        }
    }

    function queueRefreshBurst() {
        window.clearTimeout(pollTimeout);

        const delays = [1500, 4000, 8000, 12000];
        delays.forEach((delay) => {
            window.setTimeout(() => {
                loadBackups(false);
            }, delay);
        });
    }

    function buildMenu(toggleButton, uuid) {
        closeActiveMenu();

        const row = toggleButton.closest('.fbg-backup-row');
        if (!row) return;

        const isLocked = row.dataset.backupLocked === '1';

        const menu = document.createElement('div');
        menu.className = 'fbg-backup-floating-menu';

        const items = [];

        if (canDownload) {
            items.push(`
                <button type="button" class="fbg-backup-menu-item" data-action="download" data-uuid="${escapeHtml(uuid)}">
                    <i class="fas fa-cloud-arrow-down"></i> Download
                </button>
            `);
        }

        if (canRestore) {
            items.push(`
                <button type="button" class="fbg-backup-menu-item" data-action="restore" data-uuid="${escapeHtml(uuid)}">
                    <i class="fas fa-box-open"></i> Restore
                </button>
            `);
        }

        if (canDelete) {
            items.push(`
                <button type="button" class="fbg-backup-menu-item" data-action="${isLocked ? 'unlock' : 'lock'}" data-uuid="${escapeHtml(uuid)}">
                    <i class="fas ${isLocked ? 'fa-lock-open' : 'fa-lock'}"></i> ${isLocked ? 'Unlock' : 'Lock'}
                </button>
            `);

            items.push(`
                <button type="button" class="fbg-backup-menu-item is-danger" data-action="delete" data-uuid="${escapeHtml(uuid)}">
                    <i class="fas fa-trash"></i> Delete
                </button>
            `);
        }

        if (items.length === 0) {
            return;
        }

        menu.innerHTML = items.join('');
        document.body.appendChild(menu);

        const rect = toggleButton.getBoundingClientRect();
        menu.style.top = `${window.scrollY + rect.bottom + 8}px`;
        menu.style.left = `${window.scrollX + rect.right - menu.offsetWidth}px`;

        activeMenu = menu;
    }

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('.fbg-backup-menu-toggle');
        if (toggle) {
            event.preventDefault();
            event.stopPropagation();

            const uuid = toggle.dataset.uuid || '';
            if (!uuid) return;

            if (activeMenu) {
                closeActiveMenu();
            }

            buildMenu(toggle, uuid);
            return;
        }

        const actionButton = event.target.closest('.fbg-backup-menu-item');
        if (actionButton) {
            event.preventDefault();
            event.stopPropagation();

            const action = actionButton.dataset.action || '';
            const uuid = actionButton.dataset.uuid || '';

            closeActiveMenu();

            if (!uuid || !action) return;

            if (action === 'download') {
                downloadBackup(uuid);
            } else if (action === 'restore') {
                restoreBackup(uuid);
            } else if (action === 'lock') {
                toggleBackupLock(uuid, true);
            } else if (action === 'unlock') {
                toggleBackupLock(uuid, false);
            } else if (action === 'delete') {
                deleteBackup(uuid);
            }

            return;
        }

        if (activeMenu && !event.target.closest('.fbg-backup-floating-menu')) {
            closeActiveMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeActiveMenu();
            closeCreateModal();
        }
    });

    createButton?.addEventListener('click', openCreateModal);
    createClose?.addEventListener('click', closeCreateModal);
    createCancel?.addEventListener('click', closeCreateModal);
    createModal?.addEventListener('click', (event) => {
        if (event.target === createModal) {
            closeCreateModal();
        }
    });
    createForm?.addEventListener('submit', createBackup);

    hideMessage();
    loadBackups(true);
    stopBackupsPolling();
})();
