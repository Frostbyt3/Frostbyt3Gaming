(function () {
    const panel = document.querySelector('.fbg-users-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';

    const contentEl = document.getElementById('fbg-users-content');
    const messageEl = document.getElementById('users-message');
    const newButton = document.getElementById('new-subuser-button');

    const modalRoot = document.getElementById('fbg-users-modal-root');
    const modal = document.getElementById('subuser-modal');
    const modalClose = document.getElementById('subuser-modal-close');
    const modalCancel = document.getElementById('subuser-cancel');
    const modalTitle = document.getElementById('subuser-modal-title');
    const modalDescription = document.getElementById('subuser-modal-description');
    const form = document.getElementById('subuser-form');
    const submitButton = document.getElementById('subuser-submit');

    const uuidField = document.getElementById('subuser_uuid');
    const emailGroup = document.getElementById('subuser-email-group');
    const emailField = document.getElementById('subuser_email');
    const permissionGroupsEl = document.getElementById('subuser-permission-groups');

    const endpoints = {
        list: '/api/server/users/list.php?id=' + encodeURIComponent(serverId),
        view: '/api/server/users/view.php?id=' + encodeURIComponent(serverId) + '&subuser_uuid=',
        create: '/api/server/users/create.php',
        update: '/api/server/users/update.php',
        delete: '/api/server/users/delete.php'
    };

    let mode = 'create';
    let permissionCatalog = {};
    let templates = {};
    let usersByUuid = {};

    function mountModalsToBody() {
        if (modalRoot && modalRoot.parentElement !== document.body) {
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

        messageEl.textContent = message;
        messageEl.className = 'fbg-dashboard-alert ' + (isError ? 'error' : 'success');
        messageEl.style.display = 'block';

        clearTimeout(showMessage._timer);
        showMessage._timer = setTimeout(() => {
            messageEl.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function showUsersToast({
        type = 'info',
        title = 'Users',
        message = '',
        duration,
        persistent = false
    } = {}) {
        if (typeof window.FBGToast === 'function') {
            window.FBGToast({
                type,
                title,
                message,
                duration,
                persistent
            });
            return;
        }

        const cleanMessage = String(message || title || '');
        showMessage(cleanMessage.replace(/[#*_~-]/g, ''), type === 'error' || type === 'warning');
    }

    async function confirmAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    async function parseJsonResponse(response, invalidJsonMessage) {
        const rawText = await response.text();

        let data;
        try {
            data = JSON.parse(rawText);
        } catch (error) {
            console.error(invalidJsonMessage, rawText);
            throw new Error('Endpoint returned invalid JSON. Check PHP logs.');
        }

        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Request failed.');
        }

        return data;
    }

    async function request(url, options = {}, invalidJsonMessage = 'Invalid JSON response:') {
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

    function getCheckedPermissions() {
        return Array.from(
            permissionGroupsEl.querySelectorAll('input[type="checkbox"][data-permission]:checked')
        ).map((input) => input.value);
    }

    function setCheckedPermissions(permissions) {
        const selected = new Set(Array.isArray(permissions) ? permissions : []);

        permissionGroupsEl
            .querySelectorAll('input[type="checkbox"][data-permission]')
            .forEach((input) => {
                input.checked = selected.has(input.value);
            });

        syncGroupCheckboxes();
    }

    function syncGroupCheckboxes() {
        permissionGroupsEl.querySelectorAll('.fbg-user-permission-group').forEach((groupEl) => {
            const groupCheckbox = groupEl.querySelector('.fbg-user-group-checkbox');
            const permissionCheckboxes = Array.from(
                groupEl.querySelectorAll('input[type="checkbox"][data-permission]')
            );

            if (!groupCheckbox || !permissionCheckboxes.length) {
                return;
            }

            const checkedCount = permissionCheckboxes.filter((input) => input.checked).length;

            if (checkedCount === 0) {
                groupCheckbox.checked = false;
                groupCheckbox.indeterminate = false;
            } else if (checkedCount === permissionCheckboxes.length) {
                groupCheckbox.checked = true;
                groupCheckbox.indeterminate = false;
            } else {
                groupCheckbox.checked = false;
                groupCheckbox.indeterminate = true;
            }
        });
    }

    function bindPermissionGroupToggles() {
        permissionGroupsEl.querySelectorAll('.fbg-user-group-checkbox').forEach((groupCheckbox) => {
            groupCheckbox.addEventListener('change', () => {
                const rawPermissions = groupCheckbox.dataset.groupPermissions || '';
                const permissions = rawPermissions.split('|').filter(Boolean);

                permissions.forEach((permissionKey) => {
                    const input = permissionGroupsEl.querySelector(
                        `input[data-permission="1"][value="${CSS.escape(permissionKey)}"]`
                    );

                    if (input) {
                        input.checked = groupCheckbox.checked;
                    }
                });

                syncGroupCheckboxes();
            });
        });

        permissionGroupsEl
            .querySelectorAll('input[type="checkbox"][data-permission]')
            .forEach((input) => {
                input.addEventListener('change', () => {
                    syncGroupCheckboxes();
                });
            });
    }

    function renderPermissionGroups() {
        const groups = Object.entries(permissionCatalog || {});

        if (!groups.length) {
            permissionGroupsEl.innerHTML =
                '<div class="fbg-dashboard-alert error" style="display:block;">No permission catalog available.</div>';
            return;
        }

        permissionGroupsEl.innerHTML = groups.map(([groupName, items], groupIndex) => {
            const permissionEntries = Object.entries(items || {});
            const permissionKeys = permissionEntries.map(([permissionKey]) => permissionKey);

            return `
                <div class="fbg-user-permission-group" data-group-index="${groupIndex}">
                    <div class="fbg-user-permission-group-header">
                        <h4>${escapeHtml(groupName)}</h4>

                        <label class="fbg-user-group-toggle">
                            <span>Select All</span>
                            <input
                                type="checkbox"
                                class="fbg-user-group-checkbox"
                                data-group-toggle="1"
                                data-group-permissions="${escapeHtml(permissionKeys.join('|'))}"
                            >
                        </label>
                    </div>

                    <div class="fbg-user-permission-grid">
                        ${permissionEntries.map(([permissionKey, description]) => `
                            <label class="fbg-checkbox-row fbg-user-permission-row">
                                <input
                                    type="checkbox"
                                    value="${escapeHtml(permissionKey)}"
                                    data-permission="1"
                                    data-group-name="${escapeHtml(groupName)}"
                                >
                                <span>
                                    <strong>${escapeHtml(permissionKey)}</strong><br>
                                    <small>${escapeHtml(description)}</small>
                                </span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            `;
        }).join('');

        bindPermissionGroupToggles();
        syncGroupCheckboxes();
    }

    function openCreateModal() {
        mode = 'create';
        form.reset();
        uuidField.value = '';
        emailGroup.style.display = '';
        emailField.disabled = false;
        emailField.required = true;

        modalTitle.textContent = 'New User';
        modalDescription.textContent = 'Invite a panel user by email and choose their permissions.';
        submitButton.textContent = 'Create User';

        renderPermissionGroups();
        setCheckedPermissions([]);
        modal.hidden = false;
    }

    function openEditModal(user) {
        mode = 'edit';
        form.reset();
        uuidField.value = user.uuid || '';
        emailField.value = user.email || '';
        emailGroup.style.display = '';
        emailField.disabled = true;
        emailField.required = false;

        modalTitle.textContent = 'Edit User Permissions';
        modalDescription.textContent = 'Update this user’s permissions. This replaces their existing permission set.';
        submitButton.textContent = 'Save Changes';

        renderPermissionGroups();
        setCheckedPermissions(user.permissions || []);
        modal.hidden = false;
    }

    function closeModal() {
        modal.hidden = true;
    }

    function formatDate(value) {
        if (!value) return 'Unknown';

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);

        return date.toLocaleString();
    }

    function renderUsers(items) {
        usersByUuid = {};

        if (!Array.isArray(items) || !items.length) {
            contentEl.innerHTML = `
                <div class="fbg-schedules-empty">
                    No subusers have been added to this server yet.
                </div>
            `;
            return;
        }

        items.forEach((item) => {
            const attr = item.attributes || {};
            if (attr.uuid) {
                usersByUuid[attr.uuid] = attr;
            }
        });

        contentEl.innerHTML = `
            <div class="fbg-user-list">
                ${items.map((item) => {
                    const attr = item.attributes || {};
                    const permissions = Array.isArray(attr.permissions) ? attr.permissions : [];
                    const preview = permissions.slice(0, 6);

                    return `
                        <article class="fbg-schedule-list-card fbg-user-list-card">
                            <div class="fbg-schedule-list-top">
                                <div class="fbg-schedule-title-wrap">
                                    <h3 class="fbg-schedule-list-heading">${escapeHtml(attr.username || 'Unknown User')}</h3>

                                    <div class="fbg-schedule-submeta">
                                        <span><strong>Email:</strong> ${escapeHtml(attr.email || 'Unknown')}</span>
                                        <span><strong>2FA:</strong> ${attr['2fa_enabled'] ? 'Enabled' : 'Disabled'}</span>
                                        <span><strong>Added:</strong> ${escapeHtml(formatDate(attr.created_at || ''))}</span>
                                    </div>
                                </div>

                                <div class="fbg-schedule-list-actions">
                                    <button
                                        type="button"
                                        class="btn fbg-neutral-button btn-sm user-edit-button"
                                        data-subuser-uuid="${escapeHtml(attr.uuid || '')}"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-delete btn-sm user-delete-button"
                                        data-subuser-uuid="${escapeHtml(attr.uuid || '')}"
                                        data-subuser-name="${escapeHtml(attr.username || 'this user')}"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <div class="fbg-user-list-body">
                                <div class="fbg-user-permission-pill-wrap">
                                    ${preview.map((permission) => `
                                        <span class="fbg-user-permission-pill">${escapeHtml(permission)}</span>
                                    `).join('')}
                                    ${permissions.length > 6 ? `<span class="fbg-user-permission-pill">+${permissions.length - 6} more</span>` : ''}
                                </div>
                            </div>
                        </article>
                    `;
                }).join('')}
            </div>
        `;

        bindUserActions();
    }

    function bindUserActions() {
        contentEl.querySelectorAll('.user-edit-button').forEach((button) => {
            button.addEventListener('click', async () => {
                const subuserUuid = button.dataset.subuserUuid || '';
                if (!subuserUuid) return;

                try {
                    await withButtonBusyState(button, 'Loading...', async () => {
                        const result = await request(
                            endpoints.view + encodeURIComponent(subuserUuid),
                            {},
                            'Invalid JSON from subuser view endpoint:'
                        );

                        openEditModal(result.item || {});
                    });
                } catch (error) {
                    showUsersToast({
                        type: 'error',
                        message: "We couldn't load that user's permissions.\nPlease try again in a moment."
                    });
                }
            });
        });

        contentEl.querySelectorAll('.user-delete-button').forEach((button) => {
            button.addEventListener('click', async () => {
                const subuserUuid = button.dataset.subuserUuid || '';
                const subuserName = button.dataset.subuserName || 'this user';

                if (!subuserUuid) return;

                const confirmed = await confirmAction(
                    'Remove User?',
                    `Remove ${subuserName} from this server?`,
                    'Remove',
                    'Cancel',
                    { variant: 'danger' }
                );

                if (!confirmed) {
                    return;
                }

                try {
                    await withButtonBusyState(button, 'Deleting...', async () => {
                        const result = await request(
                            endpoints.delete,
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    csrf_token: csrfToken,
                                    id: serverId,
                                    subuser_uuid: subuserUuid
                                })
                            },
                            'Invalid JSON from subuser delete endpoint:'
                        );

                        showUsersToast({
                            type: 'warning',
                            message: 'User removed from this server.'
                        });
                        await loadUsers();
                    });
                } catch (error) {
                    showUsersToast({
                        type: 'error',
                        message: "We couldn't remove that user.\nPlease try again in a moment."
                    });
                }
            });
        });
    }

    async function loadUsers() {

        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading users...</div>';

        try {
            const result = await request(
                endpoints.list,
                {},
                'Invalid JSON from users list endpoint:'
            );

            permissionCatalog = result.permission_catalog || {};
            templates = result.templates || {};
            renderUsers(result.items || []);
        } catch (error) {
            contentEl.innerHTML = `
                <div class="fbg-dashboard-alert error" style="display:block;">
                    ${escapeHtml(error.message || 'Failed to load users.')}
                </div>
            `;
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const permissions = getCheckedPermissions();
        const payload = {
            csrf_token: csrfToken,
            id: serverId,
            permissions: permissions
        };

        let endpoint = endpoints.create;
        let busyText = 'Creating...';

        if (mode === 'create') {
            payload.email = emailField.value.trim();
        } else {
            endpoint = endpoints.update;
            busyText = 'Saving...';
            payload.subuser_uuid = uuidField.value.trim();
        }

        try {
            await withButtonBusyState(submitButton, busyText, async () => {
                const result = await request(
                    endpoint,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    },
                    'Invalid JSON from subuser save endpoint:'
                );

                closeModal();
                showUsersToast({
                    type: 'success',
                    message: mode === 'create'
                        ? 'User added to this server.'
                        : 'User permissions updated.'
                });

                await loadUsers();
            });
        } catch (error) {
            showUsersToast({
                type: 'error',
                message: "We couldn't save that user.\nPlease check the details and try again."
            });
        }
    });

    document.addEventListener('click', (event) => {
        const templateButton = event.target.closest('.subuser-template-button');
        if (templateButton) {
            const templateKey = templateButton.dataset.template || '';
            const template = templates[templateKey] || null;

            if (template && Array.isArray(template.permissions)) {
                setCheckedPermissions(template.permissions);
            }
        }

        if (event.target.closest('#subuser-clear-permissions')) {
            setCheckedPermissions([]);
        }

    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    if (newButton) newButton.addEventListener('click', openCreateModal);
    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalCancel) modalCancel.addEventListener('click', closeModal);

    mountModalsToBody();
    loadUsers();
})();
