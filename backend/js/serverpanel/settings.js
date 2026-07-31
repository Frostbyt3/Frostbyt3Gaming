(() => {
    const panel = document.querySelector('.fbg-settings-panel');
    if (!panel) return;

    const serverIdentifier = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canRename = panel.dataset.canRename === '1';
    const canReinstall = panel.dataset.canReinstall === '1';
    const canRenew = panel.dataset.canRenew === '1';

    const DETAILS_URL = '/api/server/update-details.php';
    const REINSTALL_URL = '/api/server/settings/reinstall.php';
    const RENEW_URL = '/api/server/settings/renew.php';

    const messageEl = document.getElementById('settings-message');

    const detailsForm = document.getElementById('settings-details-form');
    const saveButton = document.getElementById('settings-save-button');
    const nameInput = document.getElementById('settings-server-name');
    const descriptionInput = document.getElementById('settings-server-description');

    const reinstallButton = document.getElementById('settings-reinstall-button');
    const renewButton = document.getElementById('settings-renew-button');

    const headerNameText = document.getElementById('server-name-text');
    const headerDescriptionText = document.getElementById('server-description-text');
    const headerNameInput = document.getElementById('server-name-input');
    const headerDescriptionInput = document.getElementById('server-description-input');

    const balanceValue = document.getElementById('settings-balance-value');
    const expirationRow = document.getElementById('settings-expiration-row');
    const expirationValue = document.getElementById('settings-expiration-value');
    const renewWarning = document.getElementById('settings-renew-warning');

    let messageTimeout = null;

    function showMessage(text, isError) {
        if (!messageEl) return;

        if (messageTimeout) {
            clearTimeout(messageTimeout);
            messageTimeout = null;
        }

        messageEl.textContent = text;
        messageEl.className = 'fbg-dashboard-alert is-visible ' + (isError ? 'error' : 'success');
        messageEl.style.display = 'block';

        messageTimeout = setTimeout(() => {
            messageEl.classList.remove('is-visible');
            messageEl.style.display = 'none';
        }, isError ? 7000 : 5000);
    }

    async function parseJsonResponse(response) {
        const data = await response.json().catch(() => ({
            ok: false,
            error: 'Invalid server response.',
            data: null
        }));

        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Request failed.');
        }

        return data;
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        return parseJsonResponse(response);
    }

    async function updateField(field, value) {
        return postJson(DETAILS_URL, {
            csrf_token: csrfToken,
            id: serverIdentifier,
            field,
            value
        });
    }

    function formatMoney(amount, currency) {
        const numeric = Number(amount || 0);
        return `${numeric.toFixed(2)} ${currency}`;
    }

    function updateRenewButtonLabel() {
        if (!renewButton) return;

        const renewPrice = renewButton.dataset.renewPrice || '0.00';
        const currency = renewButton.dataset.currency || 'USD';
        renewButton.textContent = `Renew Server - ${formatMoney(renewPrice, currency)}`;
    }

    function applyRenewResponse(payload) {
        if (!renewButton) return;

        const data = payload?.data || {};
        const currency = data.currency || renewButton.dataset.currency || 'USD';
        renewButton.dataset.currency = currency;

        if (typeof data.balance !== 'undefined' && balanceValue) {
            balanceValue.textContent = formatMoney(data.balance, currency);
        }

        if (data.expired_at_display && expirationValue && expirationRow) {
            expirationValue.textContent = data.expired_at_display;
            expirationRow.style.display = '';
        }

        if (typeof data.can_renew !== 'undefined') {
            renewButton.disabled = !data.can_renew;
        }

        if (renewWarning) {
            if (data.renew_warning) {
                renewWarning.textContent = data.renew_warning;
                renewWarning.style.display = '';
            } else {
                renewWarning.textContent = '';
                renewWarning.style.display = 'none';
            }
        }

        updateRenewButtonLabel();

        return data;
    }

    if (detailsForm && canRename) {
        detailsForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const nameValue = (nameInput?.value || '').trim();
            const descriptionValue = (descriptionInput?.value || '').trim();

            if (!nameValue) {
                showMessage('Server name cannot be empty.', true);
                if (nameInput) nameInput.focus();
                return;
            }

            const originalText = saveButton ? saveButton.textContent : '';

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.textContent = 'Saving...';
            }

            try {
                await updateField('name', nameValue);
                await updateField('description', descriptionValue);

                if (headerNameText) headerNameText.textContent = nameValue;
                if (headerDescriptionText) {
                    headerDescriptionText.textContent = descriptionValue || 'No description';
                }
                if (headerNameInput) headerNameInput.value = nameValue;
                if (headerDescriptionInput) headerDescriptionInput.value = descriptionValue;

                showMessage('Server details updated successfully.', false);
            } catch (error) {
                showMessage(error.message || 'Failed to update server details.', true);
            } finally {
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.textContent = originalText || 'Save';
                }
            }
        });
    }

    if (reinstallButton && canReinstall) {
        reinstallButton.addEventListener('click', async () => {
            const confirmed = window.confirm(
                'Are you sure you want to reinstall this server?\n\nThis can overwrite files and re-run the install script.'
            );

            if (!confirmed) return;

            const originalText = reinstallButton.textContent;
            reinstallButton.disabled = true;
            reinstallButton.textContent = 'Reinstalling...';

            try {
                const result = await postJson(REINSTALL_URL, {
                    csrf_token: csrfToken,
                    id: serverIdentifier
                });

                showMessage(result?.data?.message || result?.message || 'Server reinstall initiated.', false);
            } catch (error) {
                showMessage(error.message || 'Failed to reinstall server.', true);
            } finally {
                reinstallButton.disabled = false;
                reinstallButton.textContent = originalText;
            }
        });
    }

    if (renewButton && canRenew) {
        renewButton.addEventListener('click', async () => {
            const confirmed = window.confirm(
                'Renew this server for one additional month and deduct the cost from your balance?'
            );

            if (!confirmed) return;

            const originalText = renewButton.textContent;
            renewButton.disabled = true;
            renewButton.textContent = 'Renewing...';

            try {
                const result = await postJson(RENEW_URL, {
                    csrf_token: csrfToken,
                    id: serverIdentifier
                });

                const renewData = applyRenewResponse(result);
                const successMessage = renewData.unsuspend_warning
                    ? `Server renewed successfully. ${renewData.unsuspend_warning}`
                    : (renewData.message || result?.message || 'Server renewed for an additional month.');

                showMessage(successMessage, false);

                if (renewData.unsuspend_warning) {
                    showMessage(renewData.unsuspend_warning, false);
                }
            } catch (error) {
                showMessage(error.message || 'Failed to renew server.', true);
                renewButton.disabled = false;
                renewButton.textContent = originalText;
            } finally {
                if (renewButton.textContent === 'Renewing...') {
                    updateRenewButtonLabel();
                }
            }
        });
    }
})();