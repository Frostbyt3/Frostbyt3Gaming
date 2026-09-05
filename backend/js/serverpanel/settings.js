(() => {
    const panel = document.querySelector('.fbg-settings-panel');
    if (!panel) return;

    const serverIdentifier = panel.dataset.serverId || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const canRename = panel.dataset.canRename === '1';
    const canReinstall = panel.dataset.canReinstall === '1';
    const canRenew = panel.dataset.canRenew === '1';
    const canSteamUpdate = panel.dataset.canSteamUpdate === '1';
    const steamUpdateEasterEgg = panel.dataset.steamUpdateEasterEgg === '1';

    const DETAILS_URL = '/api/server/update-details.php';
    const REINSTALL_URL = '/api/server/settings/reinstall.php';
    const RENEW_URL = '/api/server/settings/renew.php';
    const STEAM_UPDATE_URL = '/api/server/settings/steam-update.php';

    const messageEl = document.getElementById('settings-message');

    const detailsForm = document.getElementById('settings-details-form');
    const saveButton = document.getElementById('settings-save-button');
    const nameInput = document.getElementById('settings-server-name');
    const descriptionInput = document.getElementById('settings-server-description');

    const reinstallButton = document.getElementById('settings-reinstall-button');
    const steamUpdateButton = document.getElementById('settings-steam-update-button');
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
    const steamEasterEggMessages = [
        'Half-Life 3 confirmed.',
        'Valve time is running exactly on schedule.',
        'GabeN has personally inspected this button.',
        'Steam says this update is in another library folder.',
        'Achievement unlocked: found a Steam manifest with commitment issues.',
        'The G-Man has declined your update request.',
        'Source 2 migration scheduled immediately after Half-Life 3.',
        'Your download region has been automatically changed to Antarctica.',
        'The Steam Summer Sale will begin before this update finishes.',
        'VAC was unable to verify your Minecraft installation.',
        'Please insert Disc 2.',
        'Task failed successfully.',
        'You made the file. You made the folder. Are you happy now?',
        'We checked. This is still not a Steam server.',
        'This is not how Steam works, but I admire the commitment.',
        'Congratulations! You have invented SteamCMD for Minecraft.',
        'Your manifest has been forwarded to GabeN for review.',
        'One file failed to validate. As is tradition.',
    ];

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

    function showSettingsToast({ type = 'info', title = 'Settings', message = '', duration, persistent = false } = {}) {
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

    function randomSteamEasterEggMessage() {
        return steamEasterEggMessages[Math.floor(Math.random() * steamEasterEggMessages.length)];
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

    function receiptPayload(receipt) {
        if (!receipt || !receipt.id || !receipt.receipt_number) {
            return null;
        }

        return {
            number: receipt.receipt_number,
            url: `/page.php?name=receipt&id=${encodeURIComponent(receipt.id)}`
        };
    }

    function showRenewalConfirmation(data) {
        if (typeof window.FBGPurchaseConfirmation !== 'function') {
            return false;
        }

        const currency = data.currency || renewButton?.dataset.currency || 'USD';
        const serverName = (headerNameText?.textContent || '').trim() || 'Game Server';

        window.FBGPurchaseConfirmation({
            type: 'renewal',
            title: 'Thanks for your order!',
            message: 'Your server renewal was completed successfully.',
            label: 'Server Renewal',
            backgroundImage: data.confirmation_background_image || '',
            backgroundKey: data.egg_name || data.game_name || serverName,
            eggName: data.egg_name || '',
            gameName: data.game_name || '',
            planName: serverName,
            currency,
            details: [
                { label: 'Plan', value: data.game_name || serverName },
                { label: 'Extended By', value: `${data.duration_days || 30} days` },
                { label: 'Previous Expiration', value: data.old_expired_at_display || 'Unknown' },
                { label: 'New Expiration', value: data.expired_date_display || data.expired_at_display || 'Unknown' }
            ],
            totals: [
                { label: 'Price', value: formatMoney(data.subtotal || 0, currency) },
                { label: `Tax ${Number(data.tax_rate || 0).toFixed(2)}%`, value: formatMoney(data.tax_amount || 0, currency) },
                { label: 'Total', value: formatMoney(data.total || 0, currency), total: true }
            ],
            balance: {
                label: 'Remaining Balance',
                value: formatMoney(data.balance || 0, currency)
            },
            note: 'Your server access has been extended. You can pick up right where you left off.',
            receipt: receiptPayload(data.receipt),
            actions: [
                {
                    label: 'Close',
                    close: true,
                    primary: true
                }
            ]
        });

        return true;
    }

    function applyRenewResponse(payload) {
        if (!renewButton) return;

        const data = payload?.data || {};
        const currency = data.currency || renewButton.dataset.currency || 'USD';
        renewButton.dataset.currency = currency;

        if (typeof data.total !== 'undefined') {
            renewButton.dataset.renewPrice = String(data.total);
        }

        if (typeof data.subtotal !== 'undefined') {
            renewButton.dataset.renewSubtotal = String(data.subtotal);
        }

        if (typeof data.tax_amount !== 'undefined') {
            renewButton.dataset.renewTax = String(data.tax_amount);
        }

        if (typeof data.tax_rate !== 'undefined') {
            renewButton.dataset.renewTaxRate = String(data.tax_rate);
        }

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
                showSettingsToast({
                    type: 'warning',
                    message: 'Please enter a server name before saving.',
                });
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

                showSettingsToast({
                    type: 'success',
                    message: 'Server details updated.',
                });
            } catch (error) {
                showSettingsToast({
                    type: 'error',
                    message: "We couldn't update those server details.\nPlease try again in a moment.",
                });
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
            const confirmed = await confirmAction(
                'Reinstall Server?',
                'This can overwrite files and re-run the install script.',
                'Reinstall',
                'Cancel',
                { variant: 'danger' }
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

                showSettingsToast({
                    type: 'success',
                    message: 'Server reinstall has started.',
                });
            } catch (error) {
                showSettingsToast({
                    type: 'error',
                    message: "We couldn't start the reinstall.\nPlease try again in a moment.",
                });
            } finally {
                reinstallButton.disabled = false;
                reinstallButton.textContent = originalText;
            }
        });
    }

    if (steamUpdateButton) {
        steamUpdateButton.addEventListener('click', async () => {
            if (steamUpdateEasterEgg && !canSteamUpdate) {
                await confirmAction(
                    'Steam Update',
                    randomSteamEasterEggMessage(),
                    'Close',
                    'Cancel'
                );
                return;
            }

            if (!canSteamUpdate) {
                showSettingsToast({
                    type: 'warning',
                    message: 'This server needs file delete and restart permissions before a Steam update can be started.',
                });
                return;
            }

            const confirmed = await confirmAction(
                'Update Server?',
                'This will remove the Steam app manifest and restart the server so Steam can verify the files and install any available updates.',
                'Update',
                'Cancel'
            );

            if (!confirmed) return;

            const originalText = steamUpdateButton.textContent;
            steamUpdateButton.disabled = true;
            steamUpdateButton.textContent = 'Updating...';

            try {
                const result = await postJson(STEAM_UPDATE_URL, {
                    csrf_token: csrfToken,
                    id: serverIdentifier
                });

                const appIds = Array.isArray(result?.data?.app_ids) && result.data.app_ids.length
                    ? `\nSteam App ID${result.data.app_ids.length === 1 ? '' : 's'}: ${result.data.app_ids.join(', ')}`
                    : '';

                showSettingsToast({
                    type: 'success',
                    message: `Steam update check has started.${appIds}`,
                });
                steamUpdateButton.textContent = 'Update Queued';
            } catch (error) {
                showSettingsToast({
                    type: 'error',
                    message: "We couldn't start the Steam update check.\nPlease try again in a moment.",
                });
                steamUpdateButton.disabled = false;
                steamUpdateButton.textContent = originalText;
            }
        });
    }

    if (renewButton && canRenew) {
        renewButton.addEventListener('click', async () => {
            const renewalTotal = formatMoney(renewButton.dataset.renewPrice || 0, renewButton.dataset.currency || 'USD');
            const renewalTax = formatMoney(renewButton.dataset.renewTax || 0, renewButton.dataset.currency || 'USD');
            const renewalTaxRate = Number(renewButton.dataset.renewTaxRate || 0).toFixed(2);
            const confirmed = await confirmAction(
                'Renew Server?',
                `Renew this server for one additional month and deduct ${renewalTotal} from your balance.\nIncludes ${renewalTax} tax (${renewalTaxRate}%).`,
                'Renew',
                'Cancel'
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

                if (!showRenewalConfirmation(renewData)) {
                    showSettingsToast({
                        type: 'success',
                        message: successMessage,
                    });
                }

                if (renewData.unsuspend_warning) {
                    showSettingsToast({
                        type: 'warning',
                        message: renewData.unsuspend_warning,
                    });
                }
            } catch (error) {
                showSettingsToast({
                    type: 'error',
                    message: "We couldn't renew this server.\nPlease check your balance and try again.",
                });
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
