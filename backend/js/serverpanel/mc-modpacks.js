(() => {
    const panel = document.querySelector('.fbg-modpacks-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canDeleteFiles = panel.dataset.canDeleteFiles === '1';

    const providerEl = document.getElementById('modpacks-provider');
    const pageSizeEl = document.getElementById('modpacks-page-size');
    const searchEl = document.getElementById('modpacks-search');
    const refreshEl = document.getElementById('modpacks-refresh-button');
    const contentEl = document.getElementById('modpacks-content');
    const messageEl = document.getElementById('modpacks-message');
    const currentEl = document.getElementById('modpacks-current');
    const paginationEl = document.getElementById('modpacks-pagination');
    const prevEl = document.getElementById('modpacks-prev-button');
    const nextEl = document.getElementById('modpacks-next-button');
    const pageLabelEl = document.getElementById('modpacks-page-label');

    const modalEl = document.getElementById('modpacks-install-modal');
    const modalCloseEl = document.getElementById('modpacks-install-close');
    const modalCancelEl = document.getElementById('modpacks-install-cancel');
    const modalFormEl = document.getElementById('modpacks-install-form');
    const modalTitleEl = document.getElementById('modpacks-install-title');
    const modalDescriptionEl = document.getElementById('modpacks-install-description');
    const modalPreviewEl = document.getElementById('modpacks-install-preview');
    const versionEl = document.getElementById('modpacks-version');
    const deleteFilesEl = document.getElementById('modpacks-delete-files');
    const installSubmitEl = document.getElementById('modpacks-install-submit');

    const providerNames = {
        atlauncher: 'ATLauncher',
        curseforge: 'CurseForge',
        feedthebeast: 'Feed The Beast',
        modrinth: 'Modrinth',
        technic: 'Technic',
        voidswrath: 'Voids Wrath'
    };

    let page = 1;
    let totalPages = 1;
    let searchTimer = null;
    let messageTimer = null;
    let activeModpack = null;
    let activeProvider = 'modrinth';

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

        clearTimeout(messageTimer);
        messageEl.textContent = message;
        messageEl.className = 'fbg-dashboard-alert fbg-modpacks-message ' + (isError ? 'error' : 'success');
        messageEl.style.display = 'block';
        messageEl.classList.add('is-visible');

        if (!isError) {
            messageTimer = setTimeout(() => {
                messageEl.classList.add('is-hiding');

                setTimeout(() => {
                    if (!messageEl.classList.contains('is-hiding')) return;
                    clearMessage();
                }, 220);
            }, 3500);
        }
    }

    function clearMessage() {
        if (!messageEl) return;

        clearTimeout(messageTimer);
        messageEl.textContent = '';
        messageEl.style.display = 'none';
        messageEl.classList.remove('is-visible', 'is-hiding', 'error', 'success');
    }

    async function readJson(response, fallbackMessage) {
        const text = await response.text();
        let payload = null;

        try {
            payload = text ? JSON.parse(text) : null;
        } catch (error) {
            throw new Error(fallbackMessage);
        }

        if (!response.ok || payload?.ok === false) {
            throw new Error(payload?.error || fallbackMessage);
        }

        return payload;
    }

    function normalizeModpack(item, provider) {
        const raw = item || {};

        return {
            id: String(raw.id ?? ''),
            provider: String(raw.provider ?? provider),
            name: String(raw.name ?? 'Unknown Modpack'),
            description: String(raw.description ?? ''),
            url: String(raw.url ?? ''),
            iconUrl: String(raw.icon_url ?? raw.iconUrl ?? raw.image_url ?? raw.imageUrl ?? '')
        };
    }

    function normalizeList(payload, provider) {
        const body = payload?.data || {};
        const items = Array.isArray(body.data) ? body.data : [];
        const pagination = body.meta?.pagination || {};
        const installed = body.meta?.installed_modpack || null;

        return {
            items: items.map((item) => normalizeModpack(item, provider)).filter((item) => item.id !== ''),
            installed: installed ? normalizeModpack(installed, installed.provider || provider) : null,
            pagination: {
                total: Number(pagination.total || items.length || 0),
                count: Number(pagination.count || items.length || 0),
                perPage: Number(pagination.per_page || pageSizeEl?.value || 25),
                currentPage: Number(pagination.current_page || page),
                totalPages: Math.max(1, Number(pagination.total_pages || 1))
            }
        };
    }

    function modpackImage(modpack) {
        const src = modpack.iconUrl || '/backend/img/icons/minecraftmodpack.png';
        return `<img src="${escapeHtml(src)}" alt="" loading="lazy" onerror="this.src='/backend/img/icons/minecraftmodpack.png'">`;
    }

    function providerLabel(provider) {
        return providerNames[provider] || provider;
    }

    function modpackMarkup(modpack, options = {}) {
        const provider = modpack.provider || providerEl?.value || 'modrinth';
        const externalLink = modpack.url
            ? `<a href="${escapeHtml(modpack.url)}" target="_blank" rel="noopener noreferrer" class="fbg-modpacks-external" title="Open provider page"><i class="fas fa-arrow-up-right-from-square"></i></a>`
            : '';

        return `
            <article class="fbg-modpack-row ${options.current ? 'is-current' : ''}" data-modpack-id="${escapeHtml(modpack.id)}" data-provider="${escapeHtml(provider)}">
                <div class="fbg-modpack-icon">${modpackImage(modpack)}</div>
                <div class="fbg-modpack-main">
                    <div class="fbg-modpack-title-row">
                        <h3>${escapeHtml(modpack.name)}</h3>
                        ${externalLink}
                    </div>
                    <p>${escapeHtml(modpack.description || 'No description provided.')}</p>
                    <span>${escapeHtml(providerLabel(provider))}</span>
                </div>
                <button type="button" class="fbg-modpack-install-button" data-modpack-install title="Install ${escapeHtml(modpack.name)}">
                    <i class="fas fa-download"></i>
                </button>
            </article>
        `;
    }

    function renderCurrent(modpack) {
        if (!currentEl) return;

        if (!modpack) {
            currentEl.hidden = true;
            currentEl.innerHTML = '';
            return;
        }

        currentEl.hidden = false;
        currentEl.innerHTML = `
            <div class="fbg-modpacks-section-label">Most Recently Installed Modpack</div>
            ${modpackMarkup(modpack, { current: true })}
        `;
    }

    function bindInstallButtons() {
        contentEl?.querySelectorAll('[data-modpack-install]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('.fbg-modpack-row');
                if (!row) return;

                const modpack = {
                    id: row.dataset.modpackId || '',
                    provider: row.dataset.provider || providerEl?.value || 'modrinth',
                    name: row.querySelector('h3')?.textContent || 'Modpack',
                    description: row.querySelector('p')?.textContent || '',
                    url: row.querySelector('.fbg-modpacks-external')?.getAttribute('href') || '',
                    iconUrl: row.querySelector('img')?.getAttribute('src') || ''
                };

                openInstallModal(modpack);
            });
        });

        currentEl?.querySelectorAll('[data-modpack-install]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('.fbg-modpack-row');
                if (!row) return;

                openInstallModal({
                    id: row.dataset.modpackId || '',
                    provider: row.dataset.provider || providerEl?.value || 'modrinth',
                    name: row.querySelector('h3')?.textContent || 'Modpack',
                    description: row.querySelector('p')?.textContent || '',
                    url: row.querySelector('.fbg-modpacks-external')?.getAttribute('href') || '',
                    iconUrl: row.querySelector('img')?.getAttribute('src') || ''
                });
            });
        });
    }

    function renderList(state) {
        renderCurrent(state.installed);

        if (!contentEl) return;

        if (!state.items.length) {
            contentEl.innerHTML = '<div class="fbg-schedules-empty">There are no modpacks to display for this query.</div>';
        } else {
            contentEl.innerHTML = `<div class="fbg-modpacks-grid">${state.items.map((item) => modpackMarkup(item)).join('')}</div>`;
        }

        page = state.pagination.currentPage;
        totalPages = state.pagination.totalPages;

        if (paginationEl && pageLabelEl && prevEl && nextEl) {
            paginationEl.hidden = totalPages <= 1;
            pageLabelEl.textContent = `Page ${page} of ${totalPages}`;
            prevEl.disabled = page <= 1;
            nextEl.disabled = page >= totalPages;
        }

        bindInstallButtons();
    }

    async function loadModpacks() {
        if (!serverId || !contentEl) return;

        clearMessage();
        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading modpacks...</div>';

        const provider = providerEl?.value || 'modrinth';
        const params = new URLSearchParams({
            id: serverId,
            provider,
            search_query: searchEl?.value || '',
            page_size: pageSizeEl?.value || '25',
            page: String(page)
        });

        try {
            const response = await fetch('/api/server/modpacks/list.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const payload = await readJson(response, 'Failed to load modpacks.');
            renderList(normalizeList(payload, provider));
        } catch (error) {
            contentEl.innerHTML = `<div class="fbg-schedules-empty">${escapeHtml(error.message || 'Failed to load modpacks.')}</div>`;
            showMessage(error.message || 'Failed to load modpacks.', true);
        }
    }

    async function loadVersions(modpack) {
        if (!versionEl) return;

        versionEl.disabled = true;
        versionEl.innerHTML = '<option value="">Loading versions...</option>';

        const params = new URLSearchParams({
            id: serverId,
            provider: modpack.provider,
            modpack_id: modpack.id
        });

        const response = await fetch('/api/server/modpacks/versions.php?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        const payload = await readJson(response, 'Failed to load modpack versions.');
        const versions = Array.isArray(payload?.data) ? payload.data : [];

        if (!versions.length) {
            versionEl.innerHTML = '<option value="">No versions found</option>';
            return;
        }

        versionEl.innerHTML = versions.map((version) => {
            const id = String(version.id ?? '');
            const name = String(version.name ?? id);
            return `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`;
        }).join('');
        versionEl.disabled = false;
    }

    async function openInstallModal(modpack) {
        if (!modalEl || !modalTitleEl || !modalDescriptionEl || !modalPreviewEl) return;

        activeModpack = modpack;
        activeProvider = modpack.provider || providerEl?.value || 'modrinth';

        modalTitleEl.textContent = 'Install Modpack';
        modalDescriptionEl.textContent = `You requested the installation of "${modpack.name}" from the ${providerLabel(activeProvider)} provider. Select the desired version below.`;
        modalPreviewEl.innerHTML = modpackMarkup({ ...modpack, provider: activeProvider }, { current: true });
        modalPreviewEl.querySelector('[data-modpack-install]')?.remove();

        if (deleteFilesEl) {
            deleteFilesEl.checked = false;
            deleteFilesEl.disabled = !canDeleteFiles;
        }

        modalEl.hidden = false;
        document.body.classList.add('fbg-modal-open');

        try {
            await loadVersions({ ...modpack, provider: activeProvider });
        } catch (error) {
            closeInstallModal();
            showMessage(error.message || 'Failed to load modpack versions.', true);
        }
    }

    function closeInstallModal() {
        if (!modalEl) return;

        modalEl.hidden = true;
        document.body.classList.remove('fbg-modal-open');
        activeModpack = null;
    }

    async function installActiveModpack() {
        if (!activeModpack || !versionEl || !installSubmitEl) return;

        const versionId = versionEl.value;
        if (!versionId) {
            showMessage('Select a modpack version before installing.', true);
            return;
        }

        const shouldDeleteFiles = !!deleteFilesEl?.checked;
        if (shouldDeleteFiles) {
            const confirmed = typeof window.FBGConfirm === 'function'
                ? await window.FBGConfirm(
                    'Delete Server Files?',
                    'This will delete all server files before installing the selected modpack. This cannot be undone.',
                    'Delete Files & Install',
                    'Cancel',
                    { variant: 'danger' }
                )
                : window.confirm('Delete all server files before installing this modpack? This cannot be undone.');

            if (!confirmed) {
                return;
            }
        }

        installSubmitEl.disabled = true;
        installSubmitEl.textContent = 'Installing...';

        try {
            const response = await fetch('/api/server/modpacks/install.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: serverId,
                    provider: activeProvider,
                    modpack_id: activeModpack.id,
                    modpack_version_id: versionId,
                    delete_server_files: shouldDeleteFiles
                })
            });
            const payload = await readJson(response, 'Failed to start modpack installation.');

            closeInstallModal();
            showMessage(payload?.message || payload?.data?.message || 'Modpack installation has started.');
            window.FBG_SERVER_PANEL_API?.refresh?.({ force: true, immediate: true });
        } catch (error) {
            showMessage(error.message || 'Failed to start modpack installation.', true);
        } finally {
            installSubmitEl.disabled = false;
            installSubmitEl.textContent = 'Install Modpack';
        }
    }

    providerEl?.addEventListener('change', () => {
        page = 1;
        loadModpacks();
    });

    pageSizeEl?.addEventListener('change', () => {
        page = 1;
        loadModpacks();
    });

    searchEl?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            page = 1;
            loadModpacks();
        }, 350);
    });

    refreshEl?.addEventListener('click', () => loadModpacks());
    prevEl?.addEventListener('click', () => {
        if (page <= 1) return;
        page -= 1;
        loadModpacks();
    });
    nextEl?.addEventListener('click', () => {
        if (page >= totalPages) return;
        page += 1;
        loadModpacks();
    });

    modalCloseEl?.addEventListener('click', closeInstallModal);
    modalCancelEl?.addEventListener('click', closeInstallModal);
    modalEl?.addEventListener('click', (event) => {
        if (event.target === modalEl) {
            closeInstallModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modalEl && !modalEl.hidden) {
            closeInstallModal();
        }
    });
    modalFormEl?.addEventListener('submit', (event) => {
        event.preventDefault();
        installActiveModpack();
    });

    if (deleteFilesEl && !canDeleteFiles) {
        deleteFilesEl.disabled = true;
        const note = deleteFilesEl.closest('.fbg-toggle-row')?.querySelector('small');
        if (note) {
            note.textContent = 'You need file delete permission to clear server files before installing.';
        }
    }

    loadModpacks();
})();
