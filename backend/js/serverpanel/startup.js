(() => {
    const panel = document.querySelector('.fbg-startup-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canUpdate = panel.dataset.canUpdate === '1';
    const canUpdateDockerImage = panel.dataset.canUpdateDockerImage === '1';

    const contentEl = document.getElementById('fbg-startup-content');
    const messageEl = document.getElementById('startup-message');

    const endpoints = {
        view: '/api/server/startup/view.php?id=' + encodeURIComponent(serverId),
        update: '/api/server/startup/update.php'
    };

    let startupMeta = {};
    let startupVariables = [];
    const pendingSaves = new Map();

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
        }, isError ? 7000 : 3000);
    }

    function showStartupToast({ type = 'info', title = 'Startup', message = '', duration, persistent = false } = {}) {
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

    function firstNonEmpty(...values) {
        for (const value of values) {
            if (value !== null && value !== undefined && String(value) !== '') {
                return value;
            }
        }
        return '';
    }

    function normalizeVariable(item) {
        const attrs = item && item.attributes ? item.attributes : (item || {});

        const currentValue = firstNonEmpty(
            attrs.server_value,
            attrs.value,
            attrs.default_value
        );

        return {
            name: attrs.name || attrs.env_variable || 'Variable',
            description: attrs.description || '',
            envVariable: attrs.env_variable || attrs.envVariable || attrs.key || '',
            value: String(currentValue ?? ''),
            defaultValue: String(attrs.default_value ?? ''),
            rules: String(attrs.rules ?? ''),
            userViewable: attrs.is_viewable !== false && attrs.user_viewable !== false,
            userEditable: attrs.is_editable !== false && attrs.user_editable !== false
        };
    }

    function isBooleanLike(variable) {
        const value = String(variable.value).trim().toLowerCase();
        const defaultValue = String(variable.defaultValue).trim().toLowerCase();
        const set = new Set(['0', '1', 'true', 'false']);
        return set.has(value) || set.has(defaultValue);
    }

    function isSensitiveVariable(variable) {
        const haystack = [
            variable.name,
            variable.envVariable
        ].join(' ').toLowerCase();

        return /\b(token|key)\b/.test(haystack) || haystack.includes('token') || haystack.includes('key');
    }

    function toCheckboxChecked(value) {
        const normalized = String(value).trim().toLowerCase();
        return normalized === '1' || normalized === 'true';
    }

    function getVariableByKey(variableKey) {
        return startupVariables.find((item) => item.envVariable === variableKey) || null;
    }

    function setCardState(variableKey, stateText = '', isError = false) {
        const card = contentEl?.querySelector(`.fbg-startup-variable-card[data-variable-key="${CSS.escape(variableKey)}"]`);
        if (!card) return;

        const statusEl = card.querySelector('.fbg-startup-variable-status');
        if (!statusEl) return;

        statusEl.textContent = stateText;
        statusEl.classList.toggle('is-error', !!isError);
        statusEl.classList.toggle('is-visible', stateText !== '');
    }

    function markInputSavedValue(input, value) {
        input.dataset.lastSavedValue = String(value);
    }

    function getInputCurrentValue(input) {
        if (input.type === 'checkbox') {
            return input.checked ? '1' : '0';
        }

        return String(input.value ?? '');
    }

    function setInputBusy(input, busy) {
        const card = input.closest('.fbg-startup-variable-card');
        if (card) {
            card.classList.toggle('is-saving', !!busy);
        }

        if (input.type === 'checkbox') {
            input.disabled = !!busy;
        } else {
            input.readOnly = !!busy;
        }
    }

    async function saveVariable(input, { silentSuccess = true } = {}) {
        if (!input) return;

        const variableKey = input.dataset.variableKey || '';
        if (!variableKey) return;

        const variable = getVariableByKey(variableKey);
        if (!variable || !canUpdate || !variable.userEditable) return;

        const newValue = getInputCurrentValue(input);
        const lastSavedValue = String(input.dataset.lastSavedValue ?? '');

        if (newValue === lastSavedValue) {
            setCardState(variableKey, '');
            return;
        }

        if (pendingSaves.has(variableKey)) {
            return pendingSaves.get(variableKey);
        }

        setInputBusy(input, true);
        setCardState(variableKey, 'Saving...');

        const savePromise = (async () => {
            try {
                const data = await postJson(endpoints.update, {
                    id: serverId,
                    csrf_token: csrfToken,
                    variable_key: variableKey,
                    value: newValue
                });

                const index = startupVariables.findIndex((item) => item.envVariable === variableKey);
                if (index !== -1) {
                    startupVariables[index].value = newValue;
                }

                markInputSavedValue(input, newValue);
                setCardState(variableKey, 'Saved');

                clearTimeout(input._savedTimer);
                input._savedTimer = setTimeout(() => {
                    setCardState(variableKey, '');
                }, 1800);

                if (!silentSuccess) {
                    showStartupToast({
                        type: 'success',
                        message: 'Startup variable saved.',
                    });
                }
            } catch (error) {
                const fallbackValue = String(input.dataset.lastSavedValue ?? '');

                if (input.type === 'checkbox') {
                    input.checked = fallbackValue === '1';
                } else {
                    input.value = fallbackValue;
                }

                setCardState(variableKey, error.message || 'Failed to save.', true);
                showStartupToast({
                    type: 'error',
                    message: "We couldn't save that startup variable.\nPlease try again in a moment.",
                });
            } finally {
                setInputBusy(input, false);
                pendingSaves.delete(variableKey);
            }
        })();

        pendingSaves.set(variableKey, savePromise);
        return savePromise;
    }

    function render() {
        const startupCommand = firstNonEmpty(
            startupMeta.startup_command,
            startupMeta.startupCommand,
            startupMeta.raw_startup_command,
            startupMeta.invocation,
            ''
        );

        const dockerImage = firstNonEmpty(
            startupMeta.docker_image,
            startupMeta.dockerImage,
            ''
        );

        const dockerImages = startupMeta.docker_images && typeof startupMeta.docker_images === 'object'
            ? startupMeta.docker_images
            : {};

        const dockerImageEntries = Object.entries(dockerImages);
        const currentDockerLabel = dockerImages[dockerImage] || dockerImage || '';

        if (!Array.isArray(startupVariables)) {
            startupVariables = [];
        }

        const visibleVariables = startupVariables.filter((variable) => variable.userViewable !== false);

        contentEl.innerHTML = `
            <div class="fbg-startup-grid">
                <div class="fbg-startup-readonly-card">
                    <span class="fbg-meta-label">STARTUP COMMAND</span>
                    <pre class="fbg-startup-code">${escapeHtml(startupCommand || 'Unavailable')}</pre>
                    <span class="fbg-meta-label fbg-meta-label-sm">
                        Need changes to this? Reach out to us on <a href="https://frostbyt3gaming.com/discord" target="_blank" rel="noopener noreferrer">Discord</a>.
                    </span>
                </div>

                <div class="fbg-startup-readonly-card">
                    <span class="fbg-meta-label">DOCKER IMAGE</span>
                    ${
                        dockerImageEntries.length
                            ? `
                                <select
                                    class="fbg-text-input startup-variable-input"
                                    id="startup-docker-image"
                                    ${canUpdateDockerImage ? '' : 'disabled'}
                                >
                                    ${dockerImageEntries.map(([image, label]) => `
                                        <option
                                            value="${escapeHtml(image)}"
                                            ${image === dockerImage ? 'selected' : ''}
                                        >
                                            ${escapeHtml(label)}
                                        </option>
                                    `).join('')}
                                </select>
                                <span class="fbg-meta-label fbg-meta-label-sm">
                                    This is an advanced feature allowing you to select a Docker image to use when running this server instance.
                                </span>
                            `
                            : `
                                <div class="fbg-startup-readonly-value">
                                    ${escapeHtml(currentDockerLabel || 'Unavailable')}
                                </div>
                            `
                    }
                </div>
            </div>

            <div class="fbg-startup-variables-wrap">
                <div class="fbg-server-card-header" style="padding: 0; margin-bottom: 16px;">
                    <div class="fbg-server-heading">
                        <h3 style="margin: 0;"><i class="fas fa-sliders"></i> Variables</h3>
                        <p style="margin: 6px 0 0;">Edit the startup environment values for this server.</p>
                    </div>
                </div>

                ${
                    !visibleVariables.length
                        ? `<div class="fbg-schedules-empty">No startup variables were returned for this server.</div>`
                        : `<div class="fbg-startup-variable-list">
                            ${visibleVariables.map((variable) => {
                                const editable = canUpdate && variable.userEditable && variable.envVariable !== '';
                                const isBoolean = isBooleanLike(variable);
                                const currentValue = String(variable.value ?? '');
                                const sensitive = !isBoolean && isSensitiveVariable(variable);
                                const inputMarkup = currentValue.length > 40
                                    ? `
                                        <textarea
                                            class="fbg-text-input startup-variable-input${sensitive ? ' is-sensitive' : ''}"
                                            data-variable-key="${escapeHtml(variable.envVariable)}"
                                            data-last-saved-value="${escapeHtml(currentValue)}"
                                            ${editable ? '' : 'disabled'}
                                        >${escapeHtml(currentValue)}</textarea>
                                    `
                                    : `
                                        <input
                                            type="text"
                                            class="fbg-text-input startup-variable-input${sensitive ? ' is-sensitive' : ''}"
                                            data-variable-key="${escapeHtml(variable.envVariable)}"
                                            data-last-saved-value="${escapeHtml(currentValue)}"
                                            value="${escapeHtml(currentValue)}"
                                            ${editable ? '' : 'disabled'}
                                        >
                                    `;

                                return `
                                    <div class="fbg-startup-variable-card" data-variable-key="${escapeHtml(variable.envVariable)}">
                                        <div class="fbg-startup-variable-header">
                                            <div>
                                                <h4>${escapeHtml(variable.name)}</h4>
                                                <div class="fbg-startup-env-key">${escapeHtml(variable.envVariable)}</div>
                                            </div>
                                        </div>

                                        ${
                                            variable.description
                                                ? `<p class="fbg-startup-variable-description">${escapeHtml(variable.description)}</p>`
                                                : ''
                                        }

                                        <div class="fbg-startup-variable-input-wrap">
                                            ${
                                                isBoolean
                                                    ? `
                                                        <label class="fbg-toggle-row">
                                                            <span class="fbg-toggle-switch">
                                                                <input
                                                                    type="checkbox"
                                                                    class="startup-variable-input"
                                                                    data-variable-key="${escapeHtml(variable.envVariable)}"
                                                                    data-last-saved-value="${escapeHtml(currentValue)}"
                                                                    ${toCheckboxChecked(currentValue) ? 'checked' : ''}
                                                                    ${editable ? '' : 'disabled'}
                                                                >
                                                                <span class="fbg-toggle-slider"></span>
                                                            </span>
                                                            <span class="fbg-toggle-label">${toCheckboxChecked(currentValue) ? 'Enabled' : 'Disabled'}</span>
                                                        </label>
                                                    `  
                                                    : `
                                                        ${
                                                            sensitive
                                                                ? `
                                                                    <div class="fbg-startup-sensitive-field is-concealed">
                                                                        ${inputMarkup}
                                                                        <button
                                                                            type="button"
                                                                            class="fbg-startup-sensitive-toggle"
                                                                            aria-label="Show value"
                                                                            title="Show value"
                                                                        >
                                                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                                                        </button>
                                                                    </div>
                                                                `
                                                                : inputMarkup
                                                        }
                                                    `
                                            }
                                        </div>

                                        <div class="fbg-startup-variable-footer">
                                            <div class="fbg-startup-variable-meta">
                                                ${
                                                    variable.defaultValue !== ''
                                                        ? `<span class="fbg-meta-label">Default: ${escapeHtml(variable.defaultValue)}</span>`
                                                        : `<span class="fbg-meta-label">Default: —</span>`
                                                }
                                                ${
                                                    editable
                                                        ? `<span class="fbg-startup-variable-status" aria-live="polite"></span>`
                                                        : `<span class="fbg-meta-label">Read Only</span>`
                                                }
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>`
                }
            </div>
        `;

        bindActions();
    }

    function bindActions() {
        const dockerSelect = document.getElementById('startup-docker-image');

        if (dockerSelect && canUpdateDockerImage) {
            dockerSelect.addEventListener('change', async () => {
                const previousValue = String(startupMeta.docker_image || '');

                if (dockerSelect.value === previousValue) {
                    return;
                }

                dockerSelect.disabled = true;

                try {
                    const data = await postJson(endpoints.update, {
                        id: serverId,
                        csrf_token: csrfToken,
                        docker_image: dockerSelect.value
                    });

                    startupMeta.docker_image = dockerSelect.value;

                    if (data?.data?.docker_image_label) {
                        const option = dockerSelect.querySelector(`option[value="${CSS.escape(dockerSelect.value)}"]`);
                        if (option) {
                            option.textContent = data.data.docker_image_label;
                        }
                    }

                    showStartupToast({
                        type: 'success',
                        message: 'Docker image updated.',
                    });
                } catch (error) {
                    dockerSelect.value = previousValue;
                    console.error('Docker image update failed:', error);
                    showStartupToast({
                        type: 'error',
                        message: "We couldn't update the Docker image.\nThis image may not be available for this server, or your account may not have permission to change it.",
                    });
                } finally {
                    dockerSelect.disabled = false;
                }
            });
        }

        contentEl.querySelectorAll('.startup-variable-input').forEach((input) => {
            const variableKey = input.dataset.variableKey || '';
            const variable = getVariableByKey(variableKey);

            if (!variable || !canUpdate || !variable.userEditable) {
                return;
            }

            if (input.type === 'checkbox') {
                input.addEventListener('change', () => {
                    const row = input.closest('.fbg-toggle-row');
                    const label = row ? row.querySelector('.fbg-toggle-label') : null;

                    if (label) {
                        label.textContent = input.checked ? 'Enabled' : 'Disabled';
                    }

                    saveVariable(input, { silentSuccess: true });
                });
            } else {
                input.addEventListener('focus', () => {
                    setCardState(variableKey, '');
                });

                input.addEventListener('blur', () => {
                    saveVariable(input, { silentSuccess: true });
                });

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                        event.preventDefault();
                        input.blur();
                    }
                });
            }
        });

        contentEl.querySelectorAll('.fbg-startup-sensitive-toggle').forEach((button) => {
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });

            button.addEventListener('click', () => {
                const wrap = button.closest('.fbg-startup-sensitive-field');
                if (!wrap) return;

                const concealed = wrap.classList.toggle('is-concealed');
                button.setAttribute('aria-label', concealed ? 'Show value' : 'Hide value');
                button.setAttribute('title', concealed ? 'Show value' : 'Hide value');
                button.innerHTML = `<i class="fas ${concealed ? 'fa-eye' : 'fa-eye-slash'}" aria-hidden="true"></i>`;
            });
        });
    }

    async function loadStartup() {
        try {
            const data = await request(endpoints.view);

            startupMeta = data?.data?.meta || {};
            startupVariables = Array.isArray(data?.data?.variables)
                ? data.data.variables.map(normalizeVariable)
                : [];

            render();
        } catch (error) {
            contentEl.innerHTML = `
                <div class="fbg-dashboard-alert error is-visible" style="display:block;">
                    ${escapeHtml(error.message || 'Failed to load startup configuration.')}
                </div>
            `;
        }
    }

    loadStartup();
})();
