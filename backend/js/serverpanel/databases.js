(function () {
    const panel = document.querySelector('.fbg-databases-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const databaseLimit = Number(panel.dataset.databaseLimit || 0);
    const canCreate = panel.dataset.canCreate === '1';
    const canUpdate = panel.dataset.canUpdate === '1';
    const canDelete = panel.dataset.canDelete === '1';
    const canViewPassword = panel.dataset.canViewPassword === '1';

    const modalRoot = document.getElementById('fbg-databases-modal-root');
    const contentEl = document.getElementById('fbg-databases-content');
    const messageEl = document.getElementById('databases-message');
    const introEl = panel.querySelector('.fbg-databases-intro');
    const headerActions = document.getElementById('database-header-actions');

    const createModal = document.getElementById('database-create-modal');
    const createForm = document.getElementById('database-create-form');
    const createClose = document.getElementById('database-create-close');
    const createCancel = document.getElementById('database-create-cancel');
    const createSubmit = document.getElementById('database-create-submit');
    const createButtons = [
        document.getElementById('new-database-button'),
        document.getElementById('database-empty-create-button')
    ].filter(Boolean);
    const createName = document.getElementById('database_create_name');
    const createRemote = document.getElementById('database_create_remote');

    const detailsModal = document.getElementById('database-details-modal');
    const detailsClose = document.getElementById('database-details-close');
    const detailsCancel = document.getElementById('database-details-cancel');
    const rotateButton = document.getElementById('database-rotate-password-button');
    const detailFields = {
        endpoint: document.getElementById('database_detail_endpoint'),
        remote: document.getElementById('database_detail_remote'),
        username: document.getElementById('database_detail_username'),
        password: document.getElementById('database_detail_password'),
        jdbc: document.getElementById('database_detail_jdbc')
    };

    const endpoints = {
        list: '/api/server/databases/list.php?id=' + encodeURIComponent(serverId),
        view: '/api/server/databases/view.php?id=' + encodeURIComponent(serverId) + '&database_id=',
        create: '/api/server/databases/create.php',
        rotate: '/api/server/databases/rotate-password.php',
        delete: '/api/server/databases/delete.php'
    };

    let databaseMap = {};
    let currentDatabaseId = '';

    function mountModalsToBody() {
        if (!modalRoot) return;
        if (modalRoot.parentElement !== document.body) {
            document.body.appendChild(modalRoot);
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

    function showMessage(message, isError = false) {
        if (!messageEl) return;

        const fallback = isError ? 'Database action failed.' : 'Database action completed.';
        const normalizedMessage = typeof message === 'string' && message.trim() !== ''
            ? message.trim()
            : fallback;

        messageEl.textContent = normalizedMessage;
        messageEl.className = 'fbg-dashboard-alert ' + (isError ? 'error' : 'success') + ' is-visible';
        messageEl.style.display = 'block';

        clearTimeout(showMessage._timer);
        showMessage._timer = setTimeout(() => {
            messageEl.classList.remove('is-visible');
            messageEl.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function showDatabaseToast({ type = 'info', title = 'Databases', message = '', duration, persistent = false } = {}) {
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

    async function readJsonResponse(response, fallbackMessage) {
        const text = await response.text();
        let data = null;

        try {
            data = text ? JSON.parse(text) : {};
        } catch (error) {
            console.error('Invalid JSON from database endpoint:', text);
            throw new Error('The server returned an error page instead of JSON.');
        }

        if (!response.ok || !data.ok) {
            throw new Error(errorMessageFromData(data, fallbackMessage));
        }

        return data;
    }

    function errorMessageFromData(data, fallbackMessage) {
        const candidates = [
            data?.error,
            data?.message,
            data?.data?.error,
            data?.data?.message,
            data?.errors?.[0]?.detail,
            data?.errors?.[0]?.message,
            data?.data?.errors?.[0]?.detail,
            data?.data?.errors?.[0]?.message
        ];

        for (const candidate of candidates) {
            if (typeof candidate === 'string' && candidate.trim() !== '') {
                return candidate.trim();
            }
        }

        const objectCandidate = candidates.find((candidate) => candidate && typeof candidate === 'object');
        if (objectCandidate) {
            try {
                const encoded = JSON.stringify(objectCandidate);
                if (encoded && encoded !== '{}') {
                    return encoded;
                }
            } catch (error) {
                console.error('Failed to encode database endpoint error:', objectCandidate);
            }
        }

        return fallbackMessage;
    }

    function getAttr(item) {
        return item?.attributes || item || {};
    }

    function getDatabaseId(item) {
        return String(getAttr(item).id || '');
    }

    function endpointFromAttr(attr) {
        const address = attr?.host?.address || '';
        const port = attr?.host?.port || '';
        return [address, port].filter(Boolean).join(':');
    }

    function passwordFromItem(item) {
        const password = item?.attributes?.password
            || item?.relationships?.password?.data?.attributes?.password
            || item?.relationships?.password?.attributes?.password
            || item?.attributes?.relationships?.password?.data?.attributes?.password
            || item?.attributes?.relationships?.password?.attributes?.password
            || '';

        return String(password || '');
    }

    function jdbcString(attr, password) {
        const endpoint = endpointFromAttr(attr);
        const username = String(attr.username || '');
        const database = String(attr.name || '');

        if (!endpoint || !username || !database || !password) {
            return '';
        }

        return `jdbc:mysql://${encodeURIComponent(username)}:${encodeURIComponent(password)}@${endpoint}/${database}`;
    }

    function setCreateAvailability(count) {
        const limitReached = databaseLimit > 0 && count >= databaseLimit;

        if (headerActions) {
            headerActions.hidden = limitReached || !canCreate;
        }

        createButtons.forEach((button) => {
            button.disabled = limitReached || !canCreate;
            button.title = limitReached
                ? 'This server has used all allocated database slots.'
                : 'Create a database';
        });
    }

    function renderEmpty() {
        if (introEl) {
            introEl.hidden = false;
        }

        contentEl.innerHTML = `
            <div class="fbg-schedules-empty fbg-databases-empty">
                <strong>No databases have been created yet.</strong>
                <span>Your server is ready whenever you need one. Create a database to store your world's data, plugins, or application information.</span>
            </div>
        `;
    }

    function renderDatabaseRow(item) {
        const attr = getAttr(item);
        const id = getDatabaseId(item);
        const endpoint = endpointFromAttr(attr);
        const maxConnections = Number(attr.max_connections || 0);

        return `
            <article class="fbg-database-row" data-database-id="${escapeHtml(id)}">
                <div class="fbg-database-row-name">
                    <span class="fbg-database-icon"><i class="fas fa-database"></i></span>
                    <strong>${escapeHtml(attr.name || 'Unknown Database')}</strong>
                </div>

                <div class="fbg-database-row-meta">
                    <div>
                        <strong>${escapeHtml(endpoint || 'Unavailable')}</strong>
                        <span>Database Host</span>
                    </div>
                    <div>
                        <strong>${escapeHtml(attr.connections_from || '%')}</strong>
                        <span>Connections From</span>
                    </div>
                    <div>
                        <strong>${escapeHtml(attr.username || 'Unavailable')}</strong>
                        <span>Username</span>
                    </div>
                    <div>
                        <strong>${maxConnections > 0 ? escapeHtml(maxConnections) : 'Unlimited'}</strong>
                        <span>Max Connections</span>
                    </div>
                </div>

                <div class="fbg-database-row-actions">
                    ${canViewPassword ? `
                        <button type="button" class="btn fbg-neutral-button btn-sm database-view-button" data-database-id="${escapeHtml(id)}" aria-label="View database details">
                            <i class="fas fa-eye"></i>
                        </button>
                    ` : ''}

                    ${canDelete ? `
                        <button type="button" class="btn btn-delete btn-sm database-delete-button" data-database-id="${escapeHtml(id)}" aria-label="Delete database">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    ` : ''}
                </div>
            </article>
        `;
    }

    function renderList(items) {
        databaseMap = {};

        items.forEach((item) => {
            const id = getDatabaseId(item);
            if (id) {
                databaseMap[id] = item;
            }
        });

        setCreateAvailability(items.length);

        if (!items.length) {
            renderEmpty();
            return;
        }

        if (introEl) {
            introEl.hidden = true;
        }

        contentEl.innerHTML = `
            <div class="fbg-database-list">
                ${items.map(renderDatabaseRow).join('')}
            </div>
            <div class="fbg-database-allocation-note">
                ${items.length} of ${databaseLimit || items.length} databases have been allocated to this server.
            </div>
        `;

        bindRowActions();
    }

    function bindRowActions() {
        contentEl.querySelectorAll('.database-view-button').forEach((button) => {
            button.addEventListener('click', async () => {
                await openDetailsModal(button.dataset.databaseId || '', button);
            });
        });

        contentEl.querySelectorAll('.database-delete-button').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteDatabase(button.dataset.databaseId || '', button);
            });
        });
    }

    async function loadDatabases() {
        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading databases...</div>';

        try {
            const response = await fetch(endpoints.list, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await readJsonResponse(response, 'Failed to load databases.');

            renderList(Array.isArray(data.items) ? data.items : []);
        } catch (error) {
            contentEl.innerHTML = `
                <div class="fbg-schedules-empty">
                    ${escapeHtml(error.message || 'Failed to load databases.')}
                </div>
            `;
            showDatabaseToast({
                type: 'error',
                message: "We couldn't load databases for this server.\nPlease refresh and try again.",
            });
        }
    }

    function openCreateModal() {
        if (!createModal || !createForm || !canCreate) return;

        createForm.reset();
        if (createRemote) createRemote.value = '%';

        createModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        setTimeout(() => {
            createName?.focus();
        }, 0);
    }

    function closeCreateModal() {
        if (!createModal) return;
        createModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');
    }

    async function submitCreate(event) {
        event.preventDefault();

        if (!createForm || !createSubmit) return;

        const formData = new FormData(createForm);
        const originalText = createSubmit.textContent;

        createSubmit.disabled = true;
        createSubmit.textContent = 'Creating...';

        try {
            const response = await fetch(endpoints.create, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: serverId,
                    database: String(formData.get('database') || '').trim(),
                    remote: String(formData.get('remote') || '%').trim() || '%'
                })
            });

            const data = await readJsonResponse(response, 'Failed to create database.');
            closeCreateModal();
            showDatabaseToast({
                type: 'success',
                message: 'Database created.',
            });
            await loadDatabases();
        } catch (error) {
            showDatabaseToast({
                type: 'error',
                message: "We couldn't create that database.\nPlease try again in a moment.",
            });
        } finally {
            createSubmit.disabled = false;
            createSubmit.textContent = originalText;
        }
    }

    function closeDetailsModal() {
        if (!detailsModal) return;
        detailsModal.hidden = true;
        currentDatabaseId = '';
        document.body.classList.remove('fbg-modal-open');
    }

    function fillDetails(item) {
        const attr = getAttr(item);
        const endpoint = endpointFromAttr(attr);
        const password = passwordFromItem(item);

        detailFields.endpoint.value = endpoint;
        detailFields.remote.value = attr.connections_from || '%';
        detailFields.username.value = attr.username || '';
        detailFields.password.value = password || 'Unavailable';
        detailFields.jdbc.value = jdbcString(attr, password) || 'Unavailable';
    }

    async function openDetailsModal(databaseId, button) {
        if (!databaseId || !detailsModal || !canViewPassword) return;

        const originalHtml = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        try {
            const response = await fetch(endpoints.view + encodeURIComponent(databaseId), {
                headers: { 'Accept': 'application/json' }
            });
            const data = await readJsonResponse(response, 'Failed to load database details.');

            currentDatabaseId = databaseId;
            fillDetails(data.data || {});

            detailsModal.hidden = false;
            document.body.classList.add('fbg-modal-open');
        } catch (error) {
            showDatabaseToast({
                type: 'error',
                message: "We couldn't load that database's details.\nPlease try again in a moment.",
            });
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        }
    }

    async function rotatePassword() {
        if (!currentDatabaseId || !rotateButton || !canUpdate) return;

        const confirmed = await confirmAction(
            'Rotate Password?',
            'Existing connections using the old password will stop working.',
            'Rotate Password',
            'Cancel',
            { variant: 'danger' }
        );
        if (!confirmed) return;

        const originalText = rotateButton.textContent;
        rotateButton.disabled = true;
        rotateButton.textContent = 'Rotating...';

        try {
            const response = await fetch(endpoints.rotate, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: serverId,
                    database_id: currentDatabaseId
                })
            });

            await readJsonResponse(response, 'Failed to rotate database password.');
            showDatabaseToast({
                type: 'success',
                message: 'Database password rotated.',
            });

            const detailResponse = await fetch(endpoints.view + encodeURIComponent(currentDatabaseId), {
                headers: { 'Accept': 'application/json' }
            });
            const detailData = await readJsonResponse(detailResponse, 'Failed to reload database details.');
            fillDetails(detailData.data || {});
            await loadDatabases();
        } catch (error) {
            showDatabaseToast({
                type: 'error',
                message: "We couldn't rotate that database password.\nPlease try again in a moment.",
            });
        } finally {
            rotateButton.disabled = false;
            rotateButton.textContent = originalText;
        }
    }

    async function deleteDatabase(databaseId, button) {
        if (!databaseId || !canDelete) return;

        const item = databaseMap[databaseId] || {};
        const attr = getAttr(item);
        const confirmed = await confirmAction(
            'Delete Database?',
            `Delete database ${attr.name || databaseId}? This cannot be undone.`,
            'Delete',
            'Cancel',
            { variant: 'danger' }
        );

        if (!confirmed) return;

        const originalHtml = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        try {
            const response = await fetch(endpoints.delete, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: serverId,
                    database_id: databaseId
                })
            });

            const data = await readJsonResponse(response, 'Failed to delete database.');
            showDatabaseToast({
                type: 'success',
                message: 'Database deleted.',
            });
            await loadDatabases();
        } catch (error) {
            showDatabaseToast({
                type: 'error',
                message: "We couldn't delete that database.\nPlease try again in a moment.",
            });
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        }
    }

    mountModalsToBody();

    createButtons.forEach((button) => {
        button.addEventListener('click', openCreateModal);
    });

    createForm?.addEventListener('submit', submitCreate);
    createClose?.addEventListener('click', closeCreateModal);
    createCancel?.addEventListener('click', closeCreateModal);
    detailsClose?.addEventListener('click', closeDetailsModal);
    detailsCancel?.addEventListener('click', closeDetailsModal);
    rotateButton?.addEventListener('click', rotatePassword);

    /* [createModal, detailsModal].forEach((modal) => {
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) {
                if (modal === createModal) closeCreateModal();
                if (modal === detailsModal) closeDetailsModal();
            }
        });
    }); */

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (createModal && !createModal.hidden) closeCreateModal();
        if (detailsModal && !detailsModal.hidden) closeDetailsModal();
    });

    loadDatabases();
})();
