(() => {
    const panel = document.querySelector('.fbg-palworld-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canUpdate = panel.dataset.canUpdate === '1';
    const contentEl = document.getElementById('fbg-palworld-content');

    if (!serverId || !contentEl) return;

    const endpoints = {
        view: '/api/server/palworld-settings/view.php?id=' + encodeURIComponent(serverId),
        save: '/api/server/palworld-settings/save.php',
        mergeDefaults: '/api/server/palworld-settings/merge-defaults.php'
    };

    let settings = [];
    let categories = ['Gameplay & Balance', 'Server & Network', 'Performance', 'Advanced', 'Other Settings'];
    let missingPromptShown = false;
    const missingPromptKey = 'fbg-palworld-missing-defaults-' + serverId;
    const collapsedCategoriesKey = 'fbg-palworld-collapsed-categories-' + serverId;
    let collapsedCategories = new Set(loadCollapsedCategories());

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast({ type = 'info', title = 'Palworld Settings', message = '', duration, persistent = false } = {}) {
        const cleanMessage = String(message || '').trim();
        if (!cleanMessage) return;

        if (typeof window.FBGToast === 'function') {
            window.FBGToast({ type, title, message: cleanMessage, duration, persistent });
        }
    }

    function loadCollapsedCategories() {
        try {
            const saved = JSON.parse(localStorage.getItem(collapsedCategoriesKey) || '[]');
            return Array.isArray(saved) ? saved.map(String) : [];
        } catch (error) {
            return [];
        }
    }

    function saveCollapsedCategories() {
        localStorage.setItem(collapsedCategoriesKey, JSON.stringify(Array.from(collapsedCategories)));
    }

    async function confirmAction(title, message, confirmLabel = 'Yes', cancelLabel = 'No') {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, message, confirmLabel, cancelLabel);
        }

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

    function postJson(url, payload) {
        return request(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    function categoryOrder(category) {
        const index = categories.indexOf(category);
        return index === -1 ? categories.length : index;
    }

    function sortedSettingsByCategory() {
        const groups = new Map();

        settings.forEach((setting) => {
            const category = setting.category || 'Other Settings';
            if (!groups.has(category)) {
                groups.set(category, []);
            }
            groups.get(category).push(setting);
        });

        return Array.from(groups.entries()).sort(([a], [b]) => {
            const byCategory = categoryOrder(a) - categoryOrder(b);
            return byCategory !== 0 ? byCategory : a.localeCompare(b);
        });
    }

    function isSensitive(setting) {
        return !!setting.sensitive || ['AdminPassword', 'ServerPassword'].includes(setting.key);
    }

    function renderInput(setting) {
        const disabled = canUpdate ? '' : 'disabled';
        const key = escapeHtml(setting.key);
        const value = setting.value;
        const commonAttrs = `data-setting-key="${key}" ${disabled}`;

        if (setting.type === 'boolean') {
            const checked = value === true || String(value).toLowerCase() === 'true' || String(value) === '1';
            return `
                <label class="fbg-toggle-row">
                    <span class="fbg-toggle-switch">
                        <input type="checkbox" class="palworld-setting-input" ${commonAttrs} ${checked ? 'checked' : ''}>
                        <span class="fbg-toggle-slider"></span>
                    </span>
                    <span class="fbg-toggle-label">${checked ? 'Enabled' : 'Disabled'}</span>
                </label>
            `;
        }

        if (setting.type === 'select' && Array.isArray(setting.options) && setting.options.length) {
            return `
                <select class="fbg-text-input palworld-setting-input" ${commonAttrs}>
                    ${setting.options.map((option) => `
                        <option value="${escapeHtml(option)}" ${String(option) === String(value) ? 'selected' : ''}>
                            ${escapeHtml(option)}
                        </option>
                    `).join('')}
                </select>
            `;
        }

        if (setting.type === 'multiselect' && Array.isArray(setting.options) && setting.options.length) {
            const selected = Array.isArray(value) ? value.map(String) : String(value || '').split(',').map((item) => item.trim()).filter(Boolean);

            return `
                <div class="fbg-palworld-multiselect-grid palworld-setting-multiselect" data-setting-key="${key}">
                    ${setting.options.map((option) => {
                        const optionValue = String(option);
                        const optionId = `palworld-${key}-${optionValue}`.replace(/[^A-Za-z0-9_-]/g, '-');
                        return `
                            <label class="fbg-palworld-option-chip" for="${escapeHtml(optionId)}">
                                <input
                                    type="checkbox"
                                    id="${escapeHtml(optionId)}"
                                    value="${escapeHtml(optionValue)}"
                                    ${selected.includes(optionValue) ? 'checked' : ''}
                                    ${disabled}
                                >
                                <span>${escapeHtml(optionValue)}</span>
                            </label>
                        `;
                    }).join('')}
                </div>
            `;
        }

        if (setting.type === 'textarea') {
            return `
                <textarea class="fbg-text-input fbg-palworld-textarea palworld-setting-input" ${commonAttrs}>${escapeHtml(value)}</textarea>
            `;
        }

        if (setting.type === 'list') {
            return `
                <input
                    type="text"
                    class="fbg-text-input palworld-setting-input"
                    value="${escapeHtml(Array.isArray(value) ? value.join(', ') : value)}"
                    placeholder="ExampleValue, AnotherValue"
                    ${commonAttrs}
                >
            `;
        }

        const isNumber = setting.type === 'integer' || setting.type === 'float';
        const numericAttrs = isNumber
            ? [
                setting.min !== null && setting.min !== undefined ? `min="${escapeHtml(setting.min)}"` : '',
                setting.max !== null && setting.max !== undefined ? `max="${escapeHtml(setting.max)}"` : '',
                setting.step !== null && setting.step !== undefined ? `step="${escapeHtml(setting.step)}"` : (setting.type === 'integer' ? 'step="1"' : 'step="0.1"')
            ].filter(Boolean).join(' ')
            : '';

        const input = `
            <input
                type="${isNumber ? 'number' : 'text'}"
                class="fbg-text-input palworld-setting-input${isSensitive(setting) ? ' is-sensitive' : ''}"
                value="${escapeHtml(value)}"
                ${numericAttrs}
                ${commonAttrs}
            >
        `;

        if (!isSensitive(setting)) {
            return input;
        }

        return `
            <div class="fbg-startup-sensitive-field fbg-palworld-sensitive-field is-concealed">
                ${input}
                <button type="button" class="fbg-startup-sensitive-toggle fbg-palworld-sensitive-toggle" aria-label="Show value" title="Show value">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                </button>
            </div>
        `;
    }

    function renderSetting(setting) {
        return `
            <article class="fbg-palworld-setting-card ${setting.known ? '' : 'is-unknown'}">
                <div class="fbg-palworld-setting-copy">
                    <div class="fbg-palworld-setting-title-row">
                        <h4>${escapeHtml(setting.label || setting.key)}</h4>
                        ${setting.known ? '' : '<span class="fbg-palworld-pill">Unrecognized</span>'}
                    </div>
                    <code>${escapeHtml(setting.key)}</code>
                    <p>${escapeHtml(setting.description || '')}</p>
                </div>
                <div class="fbg-palworld-setting-control">
                    ${renderInput(setting)}
                </div>
            </article>
        `;
    }

    function render() {
        const grouped = sortedSettingsByCategory();

        contentEl.innerHTML = `
            <div class="fbg-palworld-toolbar">
                <div>
                    <span class="fbg-meta-label">Config File</span>
                    <code>/Pal/Saved/Config/LinuxServer/PalWorldSettings.ini</code>
                </div>
                <button type="button" class="btn fbg-primary-button" id="palworld-save-button" ${canUpdate ? '' : 'disabled'}>
                    Save Palworld Settings
                </button>
            </div>

            ${!canUpdate ? `
                <div class="fbg-dashboard-alert warning is-visible fbg-palworld-readonly">
                    You can view these settings, but you do not have permission to save changes.
                </div>
            ` : ''}

            ${
                grouped.length
                    ? grouped.map(([category, items]) => `
                        <section class="fbg-palworld-section ${collapsedCategories.has(category) ? 'is-collapsed' : ''}" data-palworld-category="${escapeHtml(category)}">
                            <button type="button" class="fbg-palworld-section-header" aria-expanded="${collapsedCategories.has(category) ? 'false' : 'true'}">
                                <span class="fbg-palworld-section-title">
                                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                    <span>${escapeHtml(category)}</span>
                                </span>
                                <span>${items.length} setting${items.length === 1 ? '' : 's'}</span>
                            </button>
                            <div class="fbg-palworld-setting-list" ${collapsedCategories.has(category) ? 'hidden' : ''}>
                                ${items.map(renderSetting).join('')}
                            </div>
                        </section>
                    `).join('')
                    : '<div class="fbg-schedules-empty">No Palworld settings were found in this file.</div>'
            }
        `;

        bindActions();
    }

    function collectSettings() {
        const values = {};

        contentEl.querySelectorAll('.palworld-setting-input').forEach((input) => {
            const key = input.dataset.settingKey || '';
            if (!key) return;

            if (input.type === 'checkbox') {
                values[key] = input.checked;
            } else {
                values[key] = input.value;
            }
        });

        contentEl.querySelectorAll('.palworld-setting-multiselect').forEach((group) => {
            const key = group.dataset.settingKey || '';
            if (!key) return;

            values[key] = Array.from(group.querySelectorAll('input[type="checkbox"]:checked'))
                .map((input) => input.value);
        });

        return values;
    }

    function bindActions() {
        contentEl.querySelectorAll('.palworld-setting-input[type="checkbox"]').forEach((input) => {
            input.addEventListener('change', () => {
                const label = input.closest('.fbg-toggle-row')?.querySelector('.fbg-toggle-label');
                if (label) {
                    label.textContent = input.checked ? 'Enabled' : 'Disabled';
                }
            });
        });

        contentEl.querySelectorAll('.fbg-palworld-sensitive-toggle').forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                const wrap = button.closest('.fbg-palworld-sensitive-field');
                if (!wrap) return;

                const concealed = wrap.classList.toggle('is-concealed');
                button.setAttribute('aria-label', concealed ? 'Show value' : 'Hide value');
                button.setAttribute('title', concealed ? 'Show value' : 'Hide value');
                button.innerHTML = `<i class="fas ${concealed ? 'fa-eye' : 'fa-eye-slash'}" aria-hidden="true"></i>`;
            });
        });

        contentEl.querySelectorAll('.fbg-palworld-section-header').forEach((button) => {
            button.addEventListener('click', () => {
                const section = button.closest('.fbg-palworld-section');
                const category = section?.dataset.palworldCategory || '';
                if (!section || !category) return;

                const collapsed = section.classList.toggle('is-collapsed');
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

                const list = section.querySelector('.fbg-palworld-setting-list');
                if (list) {
                    list.hidden = collapsed;
                }

                if (collapsed) {
                    collapsedCategories.add(category);
                } else {
                    collapsedCategories.delete(category);
                }

                saveCollapsedCategories();
            });
        });

        const saveButton = document.getElementById('palworld-save-button');
        if (saveButton && canUpdate) {
            saveButton.addEventListener('click', saveSettings);
        }
    }

    async function saveSettings() {
        const button = document.getElementById('palworld-save-button');
        if (button) {
            button.disabled = true;
            button.textContent = 'Saving...';
        }

        try {
            const data = await postJson(endpoints.save, {
                id: serverId,
                csrf_token: csrfToken,
                settings: collectSettings()
            });

            settings = Array.isArray(data?.data?.settings) ? data.data.settings : settings;
            render();
            showToast({
                type: 'success',
                message: 'Your Palworld settings have been saved.'
            });
        } catch (error) {
            console.error('Palworld settings save failed:', error);
            showToast({
                type: 'error',
                message: "We couldn't save your Palworld settings.\n" + (error.message || 'Please try again in a moment.'),
                persistent: true
            });
        } finally {
            const freshButton = document.getElementById('palworld-save-button');
            if (freshButton) {
                freshButton.disabled = !canUpdate;
                freshButton.textContent = 'Save Palworld Settings';
            }
        }
    }

    async function maybePromptForMissingDefaults(missingDefaults) {
        if (!Array.isArray(missingDefaults) || !missingDefaults.length || missingPromptShown) {
            return;
        }

        if (sessionStorage.getItem(missingPromptKey) === '1') {
            return;
        }

        missingPromptShown = true;
        sessionStorage.setItem(missingPromptKey, '1');

        const missingList = missingDefaults
            .map((setting) => `- ${String(setting)}`)
            .join('\n');

        const confirmed = await confirmAction(
            'Missing Palworld Settings',
            "We've detected that your **PalWorldSettings.ini** file is missing some settings.\n\n" +
                missingList +
                "\n\nWould you like to add these missing settings back into the file?",
            'Yes',
            'No'
        );

        if (!confirmed) {
            return;
        }

        try {
            contentEl.innerHTML = '<div class="fbg-schedules-loading">Adding missing Palworld settings...</div>';
            const data = await postJson(endpoints.mergeDefaults, {
                id: serverId,
                csrf_token: csrfToken
            });

            settings = Array.isArray(data?.data?.settings) ? data.data.settings : settings;
            render();
            showToast({
                type: 'success',
                message: 'Missing Palworld settings were added without changing your existing values.'
            });
        } catch (error) {
            console.error('Palworld missing defaults merge failed:', error);
            showToast({
                type: 'error',
                message: "We couldn't add the missing Palworld settings.\nYour config file was left unchanged.",
                persistent: true
            });
            await loadSettings({ promptForMissing: false });
        }
    }

    async function loadSettings({ promptForMissing = true } = {}) {
        try {
            const data = await request(endpoints.view);
            const payload = data?.data || {};

            settings = Array.isArray(payload.settings) ? payload.settings : [];
            categories = Array.isArray(payload.categories) && payload.categories.length ? payload.categories : categories;

            render();

            if (payload.created) {
                showToast({
                    type: 'success',
                    message: 'PalWorldSettings.ini was created from the Frostbyt3 default configuration.'
                });
            } else if (promptForMissing) {
                await maybePromptForMissingDefaults(payload.missing_defaults);
            }
        } catch (error) {
            console.error('Palworld settings load failed:', error);
            contentEl.innerHTML = `
                <div class="fbg-dashboard-alert error is-visible fbg-palworld-load-error">
                    ${escapeHtml(error.message || 'Failed to load Palworld settings.')}
                </div>
            `;
            showToast({
                type: 'error',
                message: "We couldn't load your Palworld settings.\nYour config file was not changed.",
                persistent: true
            });
        }
    }

    loadSettings();
})();
