(() => {
    const form = document.querySelector('[data-fbg-fbcode-form]');
    const preview = document.querySelector('[data-fbg-fbcode-preview]');
    const warning = document.querySelector('[data-fbg-fbcode-warning]');
    const resetButton = document.querySelector('[data-fbg-fbcode-reset]');
    const downloadForm = document.querySelector('[data-fbg-fbcode-download-form]');
    const downloadFields = document.querySelector('[data-fbg-fbcode-download-fields]');
    let previewTimer = null;
    let abortController = null;

    if (!form || !preview || !downloadForm || !downloadFields) {
        return;
    }

    function collectOptions(forceFormat = '') {
        const formData = new FormData(form);
        const options = {};

        formData.forEach((value, key) => {
            options[key] = value;
        });

        options.logo_enabled = form.querySelector('[name="logo_enabled"]')?.checked ? '1' : '0';
        options.draw_light_modules = form.querySelector('[name="draw_light_modules"]')?.checked ? '1' : '0';
        options.connect_paths = form.querySelector('[name="connect_paths"]')?.checked ? '1' : '0';

        if (forceFormat) {
            options.format = forceFormat;
        }

        return options;
    }

    function setPreviewLoading() {
        preview.classList.add('is-loading');
    }

    function showPreviewError(message) {
        preview.classList.remove('is-loading');
        preview.innerHTML = `<div class="fbg-fbcode-preview-placeholder">${escapeHtml(message)}</div>`;
    }

    function syncDownloadFields(options) {
        downloadFields.innerHTML = '';

        Object.entries(options).forEach(([key, value]) => {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = key;
            field.value = String(value);
            downloadFields.appendChild(field);
        });
    }

    async function refreshPreview() {
        const options = collectOptions('svg');
        syncDownloadFields(collectOptions());

        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();
        setPreviewLoading();

        try {
            const response = await fetch('/api/admin/fbcode-preview.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(options),
                signal: abortController.signal,
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'The FBCode preview could not be generated.');
            }

            preview.classList.remove('is-loading');
            preview.innerHTML = data.svg;

            if (warning) {
                const warnings = Array.isArray(data.warnings) ? data.warnings : [];
                warning.hidden = warnings.length === 0;
                warning.textContent = warnings.join(' ');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            showPreviewError(error.message || 'The FBCode preview could not be generated.');
            window.FBGToast?.({
                type: 'error',
                title: 'FBCode Generator',
                message: error.message || 'The FBCode preview could not be generated.',
            });
        }
    }

    function schedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 350);
    }

    function resetDefaults() {
        const defaults = window.FBGCodeDefaults || {};

        Object.entries(defaults).forEach(([key, value]) => {
            const field = form.elements[key];

            if (!field) {
                return;
            }

            if (field.type === 'checkbox') {
                field.checked = Boolean(value);
                return;
            }

            field.value = String(value);
        });

        schedulePreview();
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    form.addEventListener('input', schedulePreview);
    form.addEventListener('change', schedulePreview);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshPreview();
    });

    downloadForm.addEventListener('submit', () => {
        syncDownloadFields(collectOptions());
    });

    resetButton?.addEventListener('click', resetDefaults);

    syncDownloadFields(collectOptions());
    refreshPreview();
})();
