(() => {
    const panel = document.querySelector('.fbg-network-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canCreate = panel.dataset.canCreate === '1';
    const canUpdate = panel.dataset.canUpdate === '1';
    const canDelete = panel.dataset.canDelete === '1';
    const allocationLimit = parseInt(panel.dataset.allocationLimit || '0', 10) || 0;

    const contentEl = document.getElementById('fbg-network-content');
    const messageEl = document.getElementById('network-message');
    const footerMetaEl = document.getElementById('network-footer-meta');
    const createButton = document.getElementById('network-create-allocation-button');

    const endpoints = {
        list: '/api/server/network/list.php?id=' + encodeURIComponent(serverId),
        create: '/api/server/network/create.php',
        update: '/api/server/network/update.php',
        primary: '/api/server/network/primary.php',
        delete: '/api/server/network/delete.php'
    };

    let allocations = [];
    let savingNotes = new Set();

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
        messageEl.className = 'fbg-dashboard-alert is-visible ' + (isError ? 'error' : 'success');
        messageEl.style.display = 'block';

        clearTimeout(showMessage._timer);
        showMessage._timer = setTimeout(() => {
            messageEl.classList.remove('is-visible');
            messageEl.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    async function confirmAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
            headers: {
                'Accept': 'application/json',
                ...(options.headers || {})
            }
        });

        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {};
        }

        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Request failed.');
        }

        return data;
    }

    async function postJson(url, payload) {
        return request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
    }

    function normalizeAllocation(item) {
        const attrs = item && item.attributes ? item.attributes : (item || {});

        return {
            id: Number(attrs.id || 0),
            ip: attrs.ip || '',
            alias: attrs.alias || attrs.ip_alias || '',
            port: attrs.port || '',
            notes: attrs.notes || '',
            isDefault: !!(attrs.is_default || attrs.default),
            assigned: attrs.assigned !== false
        };
    }

    function getHostnameLabel(allocation) {
        return allocation.alias || allocation.ip || 'Unknown';
    }

    function updateFooter() {
        if (!footerMetaEl) return;

        const used = allocations.length;

        if (allocationLimit > 0) {
            footerMetaEl.textContent = `You are currently using ${used} of ${allocationLimit} allowed allocations for this server.`;
        } else {
            footerMetaEl.textContent = `This server is currently using ${used} allocation${used === 1 ? '' : 's'}.`;
        }

        footerMetaEl.style.display = 'block';

        if (createButton) {
            if (!canCreate) {
                createButton.style.display = 'none';
            } else if (allocationLimit > 0 && used >= allocationLimit) {
                createButton.style.display = '';
                createButton.disabled = true;
                createButton.classList.add('fbg-network-limit');
                createButton.innerHTML = '<i class="fas fa-lock"></i> Limit reached';
                createButton.title = 'You have reached the maximum number of allocations.';
            } else {
                createButton.style.display = '';
                createButton.disabled = false;
                createButton.classList.remove('fbg-network-limit');
                createButton.innerHTML = '<i class="fas fa-plus"></i> Create Allocation';
                createButton.title = '';
            }
        }
    }

    function renderAllocations() {
        if (!contentEl) return;

        if (!allocations.length) {
            contentEl.innerHTML = `
                <div class="fbg-schedules-empty">
                    No allocations found for this server.
                </div>
            `;
            updateFooter();
            return;
        }

        contentEl.innerHTML = `
            <div class="fbg-network-allocation-list">
                ${allocations.map((allocation) => {
                    const canDeleteThis = canDelete && !allocation.isDefault && allocations.length > 1;
                    const notesDisabled = canUpdate ? '' : ' disabled';
                    const primaryDisabled = (!canUpdate || allocation.isDefault) ? ' disabled' : '';
                    const deleteDisabled = canDeleteThis ? '' : ' disabled';

                    return `
                        <div class="fbg-network-allocation-card" data-allocation-id="${allocation.id}">
                            <div class="fbg-network-allocation-icon">
                                <i class="fas fa-network-wired"></i>
                            </div>

                            <div class="fbg-network-allocation-main">
                                <div class="fbg-network-allocation-meta">
                                    <div class="fbg-network-badge-wrap">
                                        <div class="fbg-network-badge">${escapeHtml(getHostnameLabel(allocation))}</div>
                                        <div class="fbg-network-badge">${escapeHtml(allocation.port)}</div>
                                    </div>

                                    <div class="fbg-network-meta-labels">
                                        <span class="fbg-meta-label">HOSTNAME</span>
                                        <span class="fbg-meta-label">PORT</span>
                                    </div>
                                </div>

                                <div class="fbg-network-allocation-notes">
                                    <input
                                        type="text"
                                        class="fbg-text-input network-notes-input"
                                        data-allocation-id="${allocation.id}"
                                        value="${escapeHtml(allocation.notes)}"
                                        placeholder="Notes"${notesDisabled}
                                    >
                                </div>
                            </div>

                            <div class="fbg-network-allocation-actions">
                                ${allocation.isDefault ? `
                                    <button type="button" class="btn fbg-primary-button btn-sm" disabled>
                                        Primary
                                    </button>
                                ` : `
                                    <button
                                        type="button"
                                        class="btn fbg-neutral-button btn-sm network-primary-button"
                                        data-allocation-id="${allocation.id}"${primaryDisabled}
                                    >
                                        Make Primary
                                    </button>
                                `}

                                ${canDelete ? `
                                    <button
                                        type="button"
                                        class="btn btn-delete btn-sm network-delete-button"
                                        data-allocation-id="${allocation.id}"
                                        title="Delete allocation"${deleteDisabled}
                                    >
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        updateFooter();
    }

    async function loadAllocations() {
        if (!contentEl) return;

        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading allocations...</div>';

        try {
            const data = await request(endpoints.list);
            const items = Array.isArray(data?.data?.items) ? data.data.items : [];
            allocations = items.map(normalizeAllocation);
            renderAllocations();
        } catch (error) {
            contentEl.innerHTML = `
                <div class="fbg-dashboard-alert error" style="display:block;">
                    ${escapeHtml(error.message || 'Failed to load allocations.')}
                </div>
            `;
        }
    }

    async function createAllocation() {
        if (!canCreate || !createButton) return;

        createButton.disabled = true;

        try {
            const data = await postJson(endpoints.create, {
                id: serverId,
                csrf_token: csrfToken
            });

            showMessage(data?.message || 'Allocation created successfully.');
            await loadAllocations();
        } catch (error) {
            showMessage(error.message || 'Failed to create allocation.', true);
        } finally {
            updateFooter();
        }
    }

    async function setPrimary(allocationId, button) {
        if (!canUpdate || !allocationId) return;

        if (button) button.disabled = true;

        try {
            const data = await postJson(endpoints.primary, {
                id: serverId,
                allocation_id: allocationId,
                csrf_token: csrfToken
            });

            showMessage(data?.message || 'Primary allocation updated successfully.');
            await loadAllocations();

            const primaryAllocation = allocations.find((item) => item.isDefault);
            if (primaryAllocation && window.FBG_SERVER_PANEL_API && typeof window.FBG_SERVER_PANEL_API.updateAddress === 'function') {
                const host = primaryAllocation.alias || primaryAllocation.ip || '';
                const port = primaryAllocation.port || '';
                const address = host && port ? `${host}:${port}` : 'Unavailable';
                window.FBG_SERVER_PANEL_API.updateAddress(address);
            }
        } catch (error) {
            showMessage(error.message || 'Failed to set primary allocation.', true);
            if (button) button.disabled = false;
        }
    }

    async function saveNotes(input) {
        if (!canUpdate || !input) return;

        const allocationId = parseInt(input.dataset.allocationId || '0', 10);
        if (!allocationId || savingNotes.has(allocationId)) return;

        savingNotes.add(allocationId);
        input.disabled = true;

        try {
            const data = await postJson(endpoints.update, {
                id: serverId,
                allocation_id: allocationId,
                notes: input.value.trim(),
                csrf_token: csrfToken
            });

            const allocation = allocations.find((item) => item.id === allocationId);
            if (allocation) {
                allocation.notes = input.value.trim();
            }

            showMessage(data?.message || 'Allocation updated successfully.');
        } catch (error) {
            showMessage(error.message || 'Failed to update allocation notes.', true);
        } finally {
            savingNotes.delete(allocationId);
            input.disabled = !canUpdate;
        }
    }

    async function deleteAllocation(allocationId, button) {
        if (!canDelete || !allocationId) return;

        const allocation = allocations.find((item) => item.id === allocationId);
        if (!allocation) return;

        if (allocation.isDefault) {
            showMessage('The primary allocation cannot be deleted.', true);
            return;
        }

        if (allocations.length <= 1) {
            showMessage('You cannot delete the only allocation on this server.', true);
            return;
        }

        const confirmed = await confirmAction(
            'Delete Allocation?',
            `Delete allocation ${getHostnameLabel(allocation)}:${allocation.port}? This cannot be undone.`,
            'Delete',
            'Cancel',
            { variant: 'danger' }
        );

        if (!confirmed) return;

        if (button) button.disabled = true;

        try {
            const data = await postJson(endpoints.delete, {
                id: serverId,
                allocation_id: allocationId,
                csrf_token: csrfToken
            });

            showMessage(data?.message || 'Allocation deleted successfully.');
            await loadAllocations();
        } catch (error) {
            showMessage(error.message || 'Failed to delete allocation.', true);
            if (button) button.disabled = false;
        }
    }

    if (createButton) {
        createButton.addEventListener('click', createAllocation);
    }

    contentEl?.addEventListener('click', async (event) => {
        const primaryButton = event.target.closest('.network-primary-button');
        if (primaryButton) {
            const allocationId = parseInt(primaryButton.dataset.allocationId || '0', 10);
            await setPrimary(allocationId, primaryButton);
            return;
        }

        const deleteButton = event.target.closest('.network-delete-button');
        if (deleteButton) {
            const allocationId = parseInt(deleteButton.dataset.allocationId || '0', 10);
            await deleteAllocation(allocationId, deleteButton);
        }
    });

    contentEl?.addEventListener('keydown', (event) => {
        const input = event.target.closest('.network-notes-input');
        if (!input) return;

        if (event.key === 'Enter') {
            event.preventDefault();
            saveNotes(input);
        }
    });

    contentEl?.addEventListener('blur', (event) => {
        const input = event.target.closest('.network-notes-input');
        if (!input) return;
        saveNotes(input);
    }, true);

    loadAllocations();
})();
