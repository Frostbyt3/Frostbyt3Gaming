document.addEventListener('click', function (e) {
    const el = e.target.closest('.fbg-copyable');
    if (!el) return;

    const text = el.dataset.copy || el.textContent;

    navigator.clipboard.writeText(text).then(() => {
        const original = el.textContent;
        el.textContent = 'Copied!';
        el.classList.add('copied');

        setTimeout(() => {
            el.classList.remove('copied');
            el.textContent = original;
        }, 1200);
    }).catch(err => {
        console.error('Copy failed:', err);
    });
});

(function () {
    const config = window.FBG_SERVER_PANEL || {};
    const identifier = config.identifier;
    const csrfToken = config.csrfToken;
    const currentTab = String(config.currentTab || '').toLowerCase();
    const isConsoleTab = currentTab === 'console';
    let isInstalling = !!config.isInstalling;
    let isSuspended = !!config.isSuspended;
    let canShowSuspendedRenewal = !!config.canShowSuspendedRenewal;
    const allowConsoleWhileInstalling = !!config.allowConsoleWhileInstalling;

    if (!identifier || !csrfToken) return;

    const STATUS_URL = '/api/server_status.php?id=' + encodeURIComponent(identifier);
    const POWER_URL = '/api/server/power.php';
    const UPDATE_DETAILS_URL = '/api/server/update-details.php';

    const POLL_FAST = isConsoleTab ? 1000 : 2000;
    const POLL_NORMAL = isConsoleTab ? 2000 : 3000;
    const POLL_SLOW = isConsoleTab ? 2500 : 4000;
    const INITIAL_POLL_DELAY = isConsoleTab ? 100 : 250;
    const POWER_BURST_DELAYS = [500, 1500, 3000, 5000];
    const POWER_ACTION_WINDOW_MS = 30000;

    const startBtn = document.querySelector('.btn-start');
    const stopBtn = document.querySelector('.btn-stop');
    const restartBtn = document.querySelector('.btn-restart');
    const powerMessage = document.getElementById('power-action-message');

    const statusBadge = document.querySelector('.server-status');
    const statusIcon = document.querySelector('.fbg-sidebar-stat-icon-status');
    const ramEl = document.querySelector('.stat-ram-usage');
    const diskEl = document.querySelector('.stat-disk-usage');
    const cpuEl = document.querySelector('.stat-cpu-usage');

    const addressEl = document.querySelector('.fbg-sidebar-stat .fbg-copyable');
    const railItems = Array.from(document.querySelectorAll('.fbg-server-rail-item[data-server]'));
    const railServerMap = new Map(
        railItems
            .map((item) => [String(item.dataset.server || '').trim(), item])
            .filter(([serverId]) => serverId !== '')
    );
    const railServerIds = Array.from(railServerMap.keys());
    const railStatusUrl = railServerIds.length > 1
        ? '/api/server_status.php?ids=' + encodeURIComponent(railServerIds.join(','))
        : '';

    const detailsMessage = document.getElementById('server-details-message');
    const serverNameText = document.getElementById('server-name-text');
    const serverDescriptionText = document.getElementById('server-description-text');
    const serverNameInput = document.getElementById('server-name-input');
    const serverDescriptionInput = document.getElementById('server-description-input');

    let powerMessageTimeout = null;
    let detailsMessageTimeout = null;

    let lastKnownStatus = null;
    let hasInitializedStatus = false;
    let pendingPowerAction = null;
    let pendingPowerUntil = 0;
    let loggedStatusesForAction = new Set();

    let refreshInFlight = false;
    let pageVisible = document.visibilityState === 'visible';
    let pollTimer = null;
    let currentPollDelay = POLL_NORMAL;
    let lastInstallState = isInstalling;
    let renderedSpecialMode = getSpecialMode(null, isInstalling, isSuspended, canShowSuspendedRenewal);
    let burstTimeouts = [];
    let activeRefreshController = null;
    let railRefreshInFlight = false;
    let railRefreshTimer = null;
    let railRefreshController = null;
    const RAIL_POLL_MS = 30000;
    const POWER_SETTLE_WINDOW_MS = 8000;
    let settledStatus = null;
    let settledStatusUntil = 0;

    function isPowerWindowActive() {
        return !!pendingPowerAction && pendingPowerUntil > Date.now();
    }

    async function confirmAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    function isSettledStatusActive() {
        return !!settledStatus && settledStatusUntil > Date.now();
    }

    function setSettledStatus(status) {
        settledStatus = status;
        settledStatusUntil = Date.now() + POWER_SETTLE_WINDOW_MS;
    }

    function clearSettledStatus() {
        settledStatus = null;
        settledStatusUntil = 0;
    }

    function updateAddress(address) {
        if (!addressEl) return;

        const value = String(address || '').trim() || 'Unavailable';
        addressEl.textContent = value;
        addressEl.dataset.copy = value;
    }

    function clearBurstRefreshes() {
        burstTimeouts.forEach(timeoutId => clearTimeout(timeoutId));
        burstTimeouts = [];
    }

    function abortRailRefresh() {
        if (railRefreshController) {
            railRefreshController.abort();
            railRefreshController = null;
        }
    }

    function abortActiveRefresh() {
        if (activeRefreshController) {
            activeRefreshController.abort();
            activeRefreshController = null;
        }
    }

    function queueBurstRefreshes() {
        clearBurstRefreshes();

        POWER_BURST_DELAYS.forEach(delay => {
            const timeoutId = setTimeout(() => {
                refresh({ force: true, immediate: true });
            }, delay);

            burstTimeouts.push(timeoutId);
        });
    }

    function scheduleRailRefresh(delay = RAIL_POLL_MS) {
        if (railRefreshTimer) {
            clearTimeout(railRefreshTimer);
            railRefreshTimer = null;
        }

        if (!pageVisible || !railStatusUrl) {
            return;
        }

        railRefreshTimer = setTimeout(() => {
            refreshRailStatuses();
        }, delay);
    }

    function scheduleNextPoll(delay = currentPollDelay) {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        if (!pageVisible) {
            return;
        }

        pollTimer = setTimeout(async () => {
            await refresh();
        }, delay);
    }

    function startPolling() {
        scheduleNextPoll(currentPollDelay);
        scheduleRailRefresh(RAIL_POLL_MS);
    }

    function statusToText(status) {
        switch (status) {
            case 'running': return 'Running';
            case 'offline': return 'Stopped';
            case 'starting': return 'Starting';
            case 'stopping': return 'Stopping';
            case 'suspended': return 'Suspended';
            case 'expired': return 'Expired';
            case 'installing': return 'Installing';
            default: return 'Unknown';
        }
    }

    function statusToClass(status) {
        return ['running', 'offline', 'starting', 'stopping', 'installing', 'suspended', 'expired'].includes(status)
            ? status
            : 'unknown';
    }

    function formatBytes(bytes) {
        const value = Number(bytes || 0);

        if (!Number.isFinite(value) || value <= 0) {
            return '0 Bytes';
        }

        const units = ['Bytes', 'KiB', 'MiB', 'GiB', 'TiB'];
        const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        const size = value / Math.pow(1024, power);

        return size.toFixed(power === 0 ? 0 : 2) + ' ' + units[power];
    }

    function consoleAppend(text) {
        if (
            window.FBG_SERVER_PANEL_CONSOLE &&
            typeof window.FBG_SERVER_PANEL_CONSOLE.appendConsoleText === 'function'
        ) {
            window.FBG_SERVER_PANEL_CONSOLE.appendConsoleText(text);
        }
    }

    function logStatusChange(status) {
        switch (status) {
            case 'running':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[92mRUNNING\x1b[36m.');
                break;
            case 'offline':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[91mSTOPPED\x1b[36m.');
                break;
            case 'starting':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[93mSTARTING\x1b[36m.');
                break;
            case 'stopping':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[91mSTOPPING\x1b[36m.');
                break;
            case 'installing':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[91mINSTALLING\x1b[36m.');
                break;
            case 'suspended':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[91mSUSPENDED\x1b[36m.');
                break;
            case 'expired':
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[94mEXPIRED\x1b[36m.');
                break;
            default:
                consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[36mServer marked as \x1b[91mUNKNOWN\x1b[36m.');
                break;
        }
    }

    function shouldLogStatusTransition(nextStatus) {
        if (!hasInitializedStatus) {
            hasInitializedStatus = true;
            lastKnownStatus = nextStatus;
            return false;
        }

        const inPowerWindow = isPowerWindowActive();

        if (!inPowerWindow) {
            if (nextStatus === lastKnownStatus) {
                return false;
            }

            lastKnownStatus = nextStatus;
            return true;
        }

        const allowedByAction = {
            start: ['starting', 'running'],
            stop: ['stopping', 'offline'],
            kill: ['offline'],
            restart: ['stopping', 'offline', 'starting', 'running']
        };

        const allowedStatuses = allowedByAction[pendingPowerAction] || [];

        if (!allowedStatuses.includes(nextStatus)) {
            return false;
        }

        if (loggedStatusesForAction.has(nextStatus)) {
            return false;
        }

        loggedStatusesForAction.add(nextStatus);
        lastKnownStatus = nextStatus;

        const actionCompleted =
            (pendingPowerAction === 'start' && nextStatus === 'running') ||
            (pendingPowerAction === 'stop' && nextStatus === 'offline') ||
            (pendingPowerAction === 'kill' && nextStatus === 'offline') ||
            (pendingPowerAction === 'restart' && nextStatus === 'running');

        if (actionCompleted) {
            setSettledStatus(nextStatus);
            pendingPowerAction = null;
            pendingPowerUntil = 0;
            loggedStatusesForAction.clear();
        }

        return true;
    }

    function normalizeServerStatus(status) {
        const value = String(status || '').trim().toLowerCase();

        if (value === 'off' || value === 'killed') {
            return 'offline';
        }

        if (value === 'suspended') {
            return 'suspended';
        }

        return value || 'unknown';
    }

    function getIncomingStatus(data) {
        if (data && data.status) {
            return normalizeServerStatus(data.status);
        }

        if (data && data.resource_status) {
            return normalizeServerStatus(data.resource_status);
        }

        return normalizeServerStatus(lastKnownStatus || getCurrentDisplayStatus());
    }

    function shouldPreservePowerStatus(incomingStatus, currentStatus) {
        if (!isPowerWindowActive()) {
            return false;
        }

        if (pendingPowerAction === 'start') {
            return currentStatus === 'starting' && ['offline', 'unknown'].includes(incomingStatus);
        }

        if (pendingPowerAction === 'restart') {
            if (currentStatus === 'stopping' && ['running', 'unknown'].includes(incomingStatus)) {
                return true;
            }

            if (currentStatus === 'starting' && ['offline', 'unknown'].includes(incomingStatus)) {
                return true;
            }

            return currentStatus === 'running' && ['offline', 'unknown', 'stopping'].includes(incomingStatus);
        }

        if (pendingPowerAction === 'stop') {
            if (currentStatus === 'stopping' && ['running', 'unknown'].includes(incomingStatus)) {
                return true;
            }

            return currentStatus === 'offline' && ['running', 'unknown', 'starting'].includes(incomingStatus);
        }

        if (pendingPowerAction === 'kill') {
            return currentStatus === 'offline' && ['running', 'unknown', 'starting', 'stopping'].includes(incomingStatus);
        }

        return false;
    }

    function shouldPreserveSettledStatus(incomingStatus, currentStatus) {
        if (!isSettledStatusActive() || currentStatus !== settledStatus) {
            return false;
        }

        if (settledStatus === 'offline') {
            return ['running', 'starting', 'stopping', 'unknown'].includes(incomingStatus);
        }

        if (settledStatus === 'running') {
            return ['offline', 'starting', 'stopping', 'unknown'].includes(incomingStatus);
        }

        return false;
    }

    function shouldPreserveStableRuntimeStatus(incomingStatus, currentStatus, nextInstalling, nextSuspended) {
        if (nextInstalling || nextSuspended) {
            return false;
        }

        if (currentStatus === 'running' && incomingStatus === 'starting') {
            return true;
        }

        if (currentStatus === 'offline' && incomingStatus === 'stopping') {
            return true;
        }

        return false;
    }

    function clearNamedTimeout(name) {
        if (name === 'power' && powerMessageTimeout) {
            clearTimeout(powerMessageTimeout);
            powerMessageTimeout = null;
        }

        if (name === 'details' && detailsMessageTimeout) {
            clearTimeout(detailsMessageTimeout);
            detailsMessageTimeout = null;
        }
    }

    function setNamedTimeout(name, timeoutId) {
        if (name === 'power') powerMessageTimeout = timeoutId;
        if (name === 'details') detailsMessageTimeout = timeoutId;
    }

    function showTimedMessage(element, message, isError, bucket) {
        if (!element) return;

        clearNamedTimeout(bucket);

        element.textContent = message;
        element.className = 'fbg-dashboard-alert is-visible ' + (isError ? 'error' : 'success');
        element.style.display = 'block';

        const timeoutId = setTimeout(() => {
            element.classList.remove('is-visible');
            element.style.display = 'none';
        }, isError ? 7000 : 4000);

        setNamedTimeout(bucket, timeoutId);
    }

    function showPowerMessage(message, isError) {
        showTimedMessage(powerMessage, message, isError, 'power');
    }

    function showDetailsMessage(message, isError) {
        showTimedMessage(detailsMessage, message, isError, 'details');
    }

    function setPowerButtonsDisabled(disabled) {
        [startBtn, stopBtn, restartBtn].forEach(btn => {
            if (btn) btn.disabled = disabled;
        });
    }

    function updateStatusIcon(status) {
        if (!statusIcon) return;

        statusIcon.classList.remove('running', 'offline', 'starting', 'stopping', 'installing', 'suspended', 'expired', 'unknown');
        statusIcon.classList.add(statusToClass(status));
    }

    function updateRailStatus(serverId, status) {
        const item = railServerMap.get(String(serverId || '').trim());
        if (!item) {
            return;
        }

        const dot = item.querySelector('.fbg-server-rail-status-dot');
        if (!dot) {
            return;
        }

        const normalized = statusToClass(normalizeServerStatus(status));
        dot.classList.remove('running', 'offline', 'starting', 'stopping', 'installing', 'suspended', 'expired', 'unknown');
        dot.classList.add(normalized);

        const label = statusToText(normalized);
        dot.title = label;
        dot.setAttribute('aria-label', label);
    }

    function updatePollDelayForStatus(status) {
        if (status === 'installing' || status === 'starting' || status === 'stopping') {
            currentPollDelay = POLL_FAST;
        } else if (status === 'running') {
            currentPollDelay = POLL_NORMAL;
        } else if (status === 'suspended' || status === 'expired') {
            currentPollDelay = POLL_SLOW;
        } else {
            currentPollDelay = POLL_SLOW;
        }
    }

    function getSpecialMode(status, nextInstalling, nextSuspended, nextCanShowSuspendedRenewal) {
        if (nextSuspended || status === 'suspended') {
            if (currentTab === 'settings' && nextCanShowSuspendedRenewal) {
                return null;
            }

            return 'suspended';
        }

        if (nextInstalling || status === 'installing') {
            if (isConsoleTab && allowConsoleWhileInstalling) {
                return null;
            }

            return 'installing';
        }

        return null;
    }

    async function reloadTabContent() {
        const container = document.getElementById('server-tab-content');
        if (!container) {
            return;
        }

        try {
            if (
                window.FBG_SERVER_PANEL_CONSOLE &&
                typeof window.FBG_SERVER_PANEL_CONSOLE.disconnect === 'function'
            ) {
                window.FBG_SERVER_PANEL_CONSOLE.disconnect();
            }

            const response = await fetch(
                '/page.php?name=serverpanel&id=' + encodeURIComponent(identifier) + '&tab=' + encodeURIComponent(currentTab) + '&partial=tab',
                {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                }
            );

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            const template = document.createElement('template');
            template.innerHTML = html;

            const scripts = Array.from(template.content.querySelectorAll('script'));
            scripts.forEach((script) => script.remove());

            container.innerHTML = '';
            container.appendChild(template.content.cloneNode(true));

            scripts.forEach((script) => {
                const nextScript = document.createElement('script');
                Array.from(script.attributes).forEach((attribute) => {
                    nextScript.setAttribute(attribute.name, attribute.value);
                });

                if (script.textContent) {
                    nextScript.textContent = script.textContent;
                }

                container.appendChild(nextScript);
            });
        } catch (error) {
            console.error('Failed to reload tab content:', error);
        }
    }

    function updateUI(data) {
        if (!isPowerWindowActive() && pendingPowerAction) {
            pendingPowerAction = null;
            pendingPowerUntil = 0;
            loggedStatusesForAction.clear();
        }

        if (!isSettledStatusActive() && settledStatus) {
            clearSettledStatus();
        }

        const incomingStatus = getIncomingStatus(data);
        const currentStatus = getCurrentDisplayStatus();
        const isOptimisticUpdate = !!(data && data.updating);
        const nextInstalling = data && Object.prototype.hasOwnProperty.call(data, 'is_installing')
            ? !!data.is_installing
            : isInstalling;
        const nextSuspended = data && Object.prototype.hasOwnProperty.call(data, 'is_suspended')
            ? !!data.is_suspended
            : (incomingStatus === 'suspended' ? true : isSuspended);
        const nextCanShowSuspendedRenewal = data && Object.prototype.hasOwnProperty.call(data, 'can_show_suspended_renewal')
            ? !!data.can_show_suspended_renewal
            : canShowSuspendedRenewal;
        const previousSpecialMode = renderedSpecialMode;
        const hasExplicitInstallState = data && Object.prototype.hasOwnProperty.call(data, 'is_installing');
        const hasExplicitSuspendState = data && Object.prototype.hasOwnProperty.call(data, 'is_suspended');
        let displayStatus = nextSuspended
            ? (nextCanShowSuspendedRenewal ? 'expired' : 'suspended')
            : (nextInstalling ? 'installing' : incomingStatus);

        if (hasExplicitInstallState && !nextInstalling && displayStatus === 'installing') {
            displayStatus = normalizeServerStatus(data && data.resource_status) === 'installing'
                ? 'unknown'
                : normalizeServerStatus(data && data.resource_status);
        }

        if (hasExplicitSuspendState && !nextSuspended && displayStatus === 'suspended') {
            displayStatus = normalizeServerStatus(data && data.resource_status) === 'suspended'
                ? 'unknown'
                : normalizeServerStatus(data && data.resource_status);
        }

        if (!isOptimisticUpdate && !nextInstalling && !nextSuspended && shouldPreservePowerStatus(displayStatus, currentStatus)) {
            displayStatus = currentStatus;
        }

        if (!isOptimisticUpdate && !nextInstalling && !nextSuspended && shouldPreserveSettledStatus(displayStatus, currentStatus)) {
            displayStatus = currentStatus;
        }

        if (!isOptimisticUpdate && shouldPreserveStableRuntimeStatus(displayStatus, currentStatus, nextInstalling, nextSuspended)) {
            displayStatus = currentStatus;
        }

        if (nextInstalling !== lastInstallState) {
            lastInstallState = nextInstalling;
            isInstalling = nextInstalling;

            consoleAppend(
                '\x1b[93m[FBG]:\x1b[0m ' +
                (nextInstalling
                    ? '\x1b[93mServer entering install mode.\x1b[0m'
                    : '\x1b[92mInstallation finished.\x1b[0m')
            );
        }

        lastInstallState = nextInstalling;
        isInstalling = nextInstalling;
        isSuspended = nextSuspended;
        canShowSuspendedRenewal = nextCanShowSuspendedRenewal;

        const nextSpecialMode = getSpecialMode(displayStatus, nextInstalling, nextSuspended, nextCanShowSuspendedRenewal);

        if (!nextInstalling && !nextSuspended) {
            const reachedFinalState =
                ((pendingPowerAction === 'start' || pendingPowerAction === 'restart') && displayStatus === 'running') ||
                ((pendingPowerAction === 'stop' || pendingPowerAction === 'kill') && displayStatus === 'offline');

            if (reachedFinalState) {
                setSettledStatus(displayStatus);
            } else if (displayStatus !== currentStatus) {
                clearSettledStatus();
            }
        } else {
            clearSettledStatus();
        }

        if (previousSpecialMode !== nextSpecialMode) {
            renderedSpecialMode = nextSpecialMode;
            reloadTabContent();
        } else {
            renderedSpecialMode = nextSpecialMode;
        }

        updatePollDelayForStatus(displayStatus);
        updateRailStatus(identifier, displayStatus);

        if (shouldLogStatusTransition(displayStatus)) {
            logStatusChange(displayStatus);
        }

        if (statusBadge) {
            statusBadge.className = 'fbg-status-badge ' + statusToClass(displayStatus) + ' server-status';
            statusBadge.textContent = statusToText(displayStatus);
            statusBadge.classList.toggle('is-updating', !!data.updating);
        }

        updateStatusIcon(displayStatus);

        if (ramEl && data.memory_bytes !== undefined && data.memory_bytes !== null) {
            ramEl.textContent = formatBytes(data.memory_bytes);
        }

        if (diskEl && data.disk_bytes !== undefined && data.disk_bytes !== null) {
            diskEl.textContent = formatBytes(data.disk_bytes);
        }

        if (cpuEl && data.cpu !== undefined && data.cpu !== null) {
            const cpuValue = Number(data.cpu);
            cpuEl.textContent = (Number.isFinite(cpuValue) ? cpuValue : 0).toFixed(2) + '%';
        }

        const powerLocked = nextInstalling || nextSuspended;

        if (startBtn) {
            startBtn.disabled = (
                displayStatus === 'running' ||
                displayStatus === 'starting' ||
                powerLocked
            );
        }

        if (stopBtn) {
            if (displayStatus === 'stopping') {
                stopBtn.dataset.action = 'kill';
                stopBtn.textContent = 'Kill';
                stopBtn.classList.add('btn-delete');
                stopBtn.classList.remove('danger-action');
                stopBtn.disabled = false;
            } else {
                stopBtn.dataset.action = 'stop';
                stopBtn.textContent = 'Stop';
                stopBtn.classList.remove('btn-delete');
                stopBtn.classList.add('danger-action');
                stopBtn.disabled = (displayStatus === 'offline' || powerLocked);
            }
        }

        if (restartBtn) {
            restartBtn.disabled = (displayStatus !== 'running' || powerLocked);
        }
    }

    async function refreshRailStatuses() {
        if (!pageVisible || railRefreshInFlight || !railStatusUrl) {
            return;
        }

        railRefreshInFlight = true;
        abortRailRefresh();
        railRefreshController = new AbortController();

        try {
            const response = await fetch(railStatusUrl, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                signal: railRefreshController.signal
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();
            const servers = data && typeof data === 'object' && data.servers && typeof data.servers === 'object'
                ? data.servers
                : {};

            Object.entries(servers).forEach(([serverId, serverData]) => {
                const nextStatus = serverData && serverData.is_suspended
                    ? (serverData.can_show_suspended_renewal ? 'expired' : 'suspended')
                    : (serverData && serverData.is_installing
                        ? 'installing'
                        : normalizeServerStatus(serverData && serverData.status));

                updateRailStatus(serverId, nextStatus);
            });
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }

            console.error('Rail status refresh failed:', err);
        } finally {
            railRefreshController = null;
            railRefreshInFlight = false;
            scheduleRailRefresh(RAIL_POLL_MS);
        }
    }

    function getCurrentDisplayStatus() {
        if (!statusBadge) {
            return 'unknown';
        }

        const states = ['running', 'offline', 'starting', 'stopping', 'installing', 'suspended', 'expired', 'unknown'];
        return states.find((state) => statusBadge.classList.contains(state)) || 'unknown';
    }

    async function refresh(options = {}) {
        const force = !!options.force;
        const immediate = !!options.immediate;

        if (!pageVisible && !force) {
            return;
        }

        if (refreshInFlight && !force) {
            return;
        }

        refreshInFlight = true;
        abortActiveRefresh();
        activeRefreshController = new AbortController();

        try {
            const response = await fetch(STATUS_URL, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                signal: activeRefreshController.signal
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();
            updateUI(data);
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }

            console.error('Panel refresh failed:', err);
            currentPollDelay = Math.max(currentPollDelay, POLL_SLOW);
        } finally {
            activeRefreshController = null;
            refreshInFlight = false;

            if (!immediate && pageVisible) {
                scheduleNextPoll(currentPollDelay);
            }
        }
    }

    async function sendPower(action) {
        if (isInstalling) {
            showPowerMessage('This server is still installing. Power actions are temporarily disabled.', true);
            return;
        }

        setPowerButtonsDisabled(true);
        pendingPowerAction = action;
        pendingPowerUntil = Date.now() + POWER_ACTION_WINDOW_MS;
        loggedStatusesForAction = new Set();

        showPowerMessage('Sending ' + action + ' command...', false);
        consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[93mSending ' + action + ' command...');

        if (action === 'start') {
            updateUI({ status: 'starting', is_installing: false, updating: true });
        } else if (action === 'stop') {
            updateUI({ status: 'stopping', is_installing: false, updating: true });
        } else if (action === 'restart') {
            updateUI({ status: 'stopping', is_installing: false, updating: true });
        } else if (action === 'kill') {
            updateUI({ status: 'offline', is_installing: false, updating: true });
        }

        currentPollDelay = POLL_FAST;
        scheduleNextPoll(POLL_FAST);

        try {
            const response = await fetch(POWER_URL, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: identifier,
                    action: action
                })
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Power action failed');
            }

            showPowerMessage(data.data?.message || 'Power action sent.', false);
            consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[92m' + (data.data?.message || 'Success'));

            refresh({ force: true, immediate: true });
            queueBurstRefreshes();
        } catch (err) {
            console.error('Power error:', err);
            showPowerMessage(err.message || 'Power action failed.', true);
            consoleAppend('\x1b[93m[FBG]:\x1b[0m \x1b[91m' + (err.message || 'Power action failed.'));
            pendingPowerAction = null;
            pendingPowerUntil = 0;
            loggedStatusesForAction.clear();
            refresh({ force: true, immediate: true });
        } finally {
            setPowerButtonsDisabled(false);
        }
    }

    function openEditor(field) {
        document.querySelectorAll('.fbg-editable-row').forEach(row => {
            const display = row.querySelector('.fbg-editable-display');
            const editor = row.querySelector('.fbg-editable-editor');

            if (display) display.style.display = '';
            if (editor) editor.style.display = 'none';
        });

        const row = document.querySelector('.fbg-editable-row[data-field="' + field + '"]');
        if (!row) return;

        const display = row.querySelector('.fbg-editable-display');
        const editor = row.querySelector('.fbg-editable-editor');

        if (display) display.style.display = 'none';
        if (editor) editor.style.display = 'block';

        if (field === 'name' && serverNameInput) {
            serverNameInput.focus();
            serverNameInput.select();
        }

        if (field === 'description' && serverDescriptionInput) {
            serverDescriptionInput.focus();
            serverDescriptionInput.select();
        }
    }

    function closeEditor(field) {
        const row = document.querySelector('.fbg-editable-row[data-field="' + field + '"]');
        if (!row) return;

        const display = row.querySelector('.fbg-editable-display');
        const editor = row.querySelector('.fbg-editable-editor');

        if (display) display.style.display = '';
        if (editor) editor.style.display = 'none';

        if (field === 'name' && serverNameInput && serverNameText) {
            serverNameInput.value = serverNameText.textContent.trim();
        }

        if (field === 'description' && serverDescriptionInput && serverDescriptionText) {
            const currentText = serverDescriptionText.textContent.trim();
            serverDescriptionInput.value = currentText === 'No description' ? '' : currentText;
        }
    }

    async function saveServerField(field) {
        let value = '';

        if (field === 'name' && serverNameInput) {
            value = serverNameInput.value.trim();
        }

        if (field === 'description' && serverDescriptionInput) {
            value = serverDescriptionInput.value.trim();
        }

        if (field === 'name' && !value) {
            showDetailsMessage('Server name cannot be empty.', true);
            return;
        }

        try {
            const response = await fetch(UPDATE_DETAILS_URL, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: identifier,
                    field: field,
                    value: value
                })
            });

            const rawText = await response.text();

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Non-JSON response from update-details endpoint:', rawText);
                throw new Error('Update endpoint returned invalid response. Check PHP error logs.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Failed to save changes.');
            }

            if (serverNameText && typeof data.name === 'string') {
                serverNameText.textContent = data.name;
            }

            if (serverDescriptionText && typeof data.description === 'string') {
                serverDescriptionText.textContent = data.description.trim() !== ''
                    ? data.description
                    : 'No description';
            }

            if (serverNameInput && typeof data.name === 'string') {
                serverNameInput.value = data.name;
            }

            if (serverDescriptionInput && typeof data.description === 'string') {
                serverDescriptionInput.value = data.description;
            }

            closeEditor(field);
            showDetailsMessage(data.message || 'Saved.', false);
        } catch (err) {
            console.error('Save details error:', err);
            showDetailsMessage(err.message || 'Failed to save changes.', true);
        }
    }

    if (startBtn) {
        startBtn.addEventListener('click', () => sendPower('start'));
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', async () => {
            const action = stopBtn.dataset.action || 'stop';

            if (action === 'kill') {
                const confirmed = await confirmAction(
                    'Force Kill Server?',
                    'This may cause data loss.',
                    'Force Kill',
                    'Cancel',
                    { variant: 'danger' }
                );

                if (!confirmed) {
                    return;
                }
            }

            sendPower(action);
        });
    }

    if (restartBtn) {
        restartBtn.addEventListener('click', () => sendPower('restart'));
    }

    document.addEventListener('click', function (e) {
        const editBtn = e.target.closest('.fbg-edit-toggle');
        if (editBtn) {
            openEditor(editBtn.dataset.field);
            return;
        }

        const cancelBtn = e.target.closest('.fbg-cancel-edit');
        if (cancelBtn) {
            closeEditor(cancelBtn.dataset.field);
            return;
        }

        const saveBtn = e.target.closest('.fbg-save-edit');
        if (saveBtn) {
            saveServerField(saveBtn.dataset.field);
        }
    });

    if (serverNameInput) {
        serverNameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveServerField('name');
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                closeEditor('name');
            }
        });
    }

    if (serverDescriptionInput) {
        serverDescriptionInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveServerField('description');
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                closeEditor('description');
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        pageVisible = document.visibilityState === 'visible';

        if (pageVisible) {
            refresh({ force: true, immediate: true }).finally(() => {
                scheduleNextPoll(INITIAL_POLL_DELAY);
                scheduleRailRefresh(250);
            });
        } else {
            abortRailRefresh();
            abortActiveRefresh();

            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (railRefreshTimer) {
                clearTimeout(railRefreshTimer);
                railRefreshTimer = null;
            }
        }
    });

    window.addEventListener('pagehide', function () {
        abortRailRefresh();
        abortActiveRefresh();
        clearBurstRefreshes();

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        if (railRefreshTimer) {
            clearTimeout(railRefreshTimer);
            railRefreshTimer = null;
        }
    });

    window.FBG_SERVER_PANEL_API = {
        updateUI,
        refresh,
        updateAddress
    };

    refresh({ force: true, immediate: true }).finally(() => {
        scheduleNextPoll(INITIAL_POLL_DELAY);
        scheduleRailRefresh(500);
    });
})();
