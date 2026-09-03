(() => {
    const form = document.querySelector('[data-fbg-fbcode-form]');
    const preview = document.querySelector('[data-fbg-fbcode-preview]');
    const warning = document.querySelector('[data-fbg-fbcode-warning]');
    const resetButton = document.querySelector('[data-fbg-fbcode-reset]');
    const downloadForm = document.querySelector('[data-fbg-fbcode-download-form]');
    const downloadFields = document.querySelector('[data-fbg-fbcode-download-fields]');
    const logoOptions = document.querySelector('[data-fbg-fbcode-logo-options]');
    const logoImage = form?.querySelector('[name="logo_image"]');
    let previewTimer = null;
    let abortController = null;

    if (!form || !preview || !downloadForm || !downloadFields) {
        return;
    }

    function collectOptions(forceFormat = '') {
        const formData = new FormData(form);
        const options = {};

        formData.forEach((value, key) => {
            if (value instanceof File) {
                return;
            }

            options[key] = value;
        });

        options.logo_mode = String(form.elements.logo_mode?.value || 'frostbyt3');
        options.draw_light_modules = form.querySelector('[name="draw_light_modules"]')?.checked ? '1' : '0';
        options.connect_paths = form.querySelector('[name="connect_paths"]')?.checked ? '1' : '0';

        if (forceFormat) {
            options.format = forceFormat;
        }

        return options;
    }

    function collectFormData(forceFormat = '') {
        const formData = new FormData(form);
        const options = collectOptions(forceFormat);

        formData.set('logo_mode', options.logo_mode);
        formData.set('draw_light_modules', form.querySelector('[name="draw_light_modules"]')?.checked ? '1' : '0');
        formData.set('connect_paths', form.querySelector('[name="connect_paths"]')?.checked ? '1' : '0');
        if (options.logo_mode !== 'custom') {
            formData.delete('logo_image');
        }

        if (forceFormat) {
            formData.set('format', forceFormat);
        }

        return formData;
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
                    'Accept': 'application/json',
                },
                body: collectFormData('svg'),
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

            if (field instanceof RadioNodeList) {
                field.value = String(value);
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

    function syncLogoControls() {
        const logoMode = String(form.elements.logo_mode?.value || 'frostbyt3');

        if (logoOptions) {
            logoOptions.classList.toggle('is-logo-free', logoMode === 'none');
            logoOptions.classList.toggle('is-frostbyt3-logo', logoMode === 'frostbyt3');
        }

        if (logoImage) {
            logoImage.disabled = logoMode !== 'custom';
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    form.addEventListener('input', schedulePreview);
    form.addEventListener('change', () => {
        syncLogoControls();
        schedulePreview();
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshPreview();
    });

    downloadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncDownloadFields(collectOptions());

        try {
            const response = await fetch(downloadForm.action, {
                method: 'POST',
                body: collectFormData(),
            });
            const contentType = response.headers.get('Content-Type') || '';

            if (!response.ok) {
                throw new Error(await response.text() || 'The FBCode download could not be generated.');
            }

            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const filenameMatch = disposition.match(/filename="([^"]+)"/i);
            const filename = filenameMatch?.[1] || `fbcode.${contentType.includes('png') ? 'png' : 'svg'}`;
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');

            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch (error) {
            window.FBGToast?.({
                type: 'error',
                title: 'FBCode Generator',
                message: error.message || 'The FBCode download could not be generated.',
            });
        }
    });

    resetButton?.addEventListener('click', resetDefaults);

    syncLogoControls();
    syncDownloadFields(collectOptions());
    refreshPreview();
})();
