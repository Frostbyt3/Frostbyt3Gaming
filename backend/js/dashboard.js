document.addEventListener('DOMContentLoaded', function () {
    const cards = Array.from(document.querySelectorAll('.fbg-server-card[data-href]'));
    const shell = document.querySelector('.fbg-dashboard-shell');
    const searchInput = document.getElementById('fbg-dashboard-search');
    const viewButtons = Array.from(document.querySelectorAll('.fbg-dashboard-view-button[data-view-mode]'));
    const collection = document.querySelector('[data-dashboard-collection]');
    const summaryNodes = {
        totalServers: document.querySelector('[data-summary="total-servers"]'),
        running: document.querySelector('[data-summary="running"]'),
        stopped: document.querySelector('[data-summary="stopped"]'),
        starting: document.querySelector('[data-summary="starting"]'),
        memoryTotal: document.querySelector('[data-summary="memory-total"]'),
        cpuTotal: document.querySelector('[data-summary="cpu-total"]'),
        runningPercent: document.querySelector('[data-summary-percent="running"]'),
        stoppedPercent: document.querySelector('[data-summary-percent="stopped"]'),
        startingPercent: document.querySelector('[data-summary-percent="starting"]')
    };

    const config = window.FBG_DASHBOARD || {};
    const csrfToken = config.csrfToken;

    const STATUS_URL = '/api/server_status.php';
    const POWER_URL = '/api/server/power.php';

    const POLL_FAST = 1250;
    const POLL_NORMAL = 2250;
    const POLL_SLOW = 4500;
    const MESSAGE_TIMEOUT = 4000;
    const MAX_STATUS_IDS_PER_REQUEST = 24;
    const VIEW_MODE_KEY = 'fbg.dashboard.viewMode';

    let pollInProgress = false;
    let pageVisible = document.visibilityState === 'visible';
    let pollTimer = null;
    let currentPollDelay = POLL_NORMAL;
    let burstTimeouts = [];
    let nextStatusOffset = 0;
    let activeStatusController = null;

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

    function formatPercent(value) {
        const numeric = Number(value || 0);
        return (Number.isFinite(numeric) ? numeric : 0).toFixed(2) + '%';
    }

    function statusToText(status) {
        switch (status) {
            case 'installing': return 'Installing';
            case 'suspended': return 'Suspended';
            case 'running': return 'Running';
            case 'offline': return 'Stopped';
            case 'starting': return 'Starting';
            case 'stopping': return 'Stopping';
            default: return 'Unknown';
        }
    }

    function statusToClass(status) {
        switch (status) {
            case 'installing':
            case 'suspended':
            case 'running':
            case 'offline':
            case 'starting':
            case 'stopping':
                return status;
            default:
                return 'unknown';
        }
    }

    function getCardStatus(card) {
        if (!card) {
            return 'unknown';
        }

        return String(card.dataset.status || 'unknown');
    }

    function getCards() {
        return Array.from(document.querySelectorAll('.fbg-dashboard-item[data-server]'));
    }

    function getVisibleCards() {
        return getCards().filter(function (card) {
            return !card.hasAttribute('hidden') && card.style.display !== 'none';
        });
    }

    function getServerIds(cardsList) {
        return cardsList
            .map(card => String(card.dataset.server || '').trim())
            .filter(Boolean);
    }

    function selectStatusIds(ids, options = {}) {
        const explicitIds = Array.isArray(options.ids)
            ? options.ids.map(id => String(id || '').trim()).filter(Boolean)
            : [];

        if (explicitIds.length) {
            return Array.from(new Set(explicitIds));
        }

        if (ids.length <= MAX_STATUS_IDS_PER_REQUEST) {
            nextStatusOffset = 0;
            return ids;
        }

        const selected = [];

        for (let i = 0; i < MAX_STATUS_IDS_PER_REQUEST; i++) {
            selected.push(ids[(nextStatusOffset + i) % ids.length]);
        }

        nextStatusOffset = (nextStatusOffset + MAX_STATUS_IDS_PER_REQUEST) % ids.length;

        return selected;
    }

    function clearMessageTimer(container) {
        if (!container) {
            return;
        }

        if (container._hideTimer) {
            clearTimeout(container._hideTimer);
            container._hideTimer = null;
        }
    }

    function showMessage(container, message, isError) {
        if (!container) {
            return;
        }

        clearMessageTimer(container);
        container.textContent = message;
        container.className = 'fbg-dashboard-alert power-msg ' + (isError ? 'error' : 'success');
        container.style.display = 'block';
        container.classList.add('is-visible');

        container._hideTimer = setTimeout(function () {
            container.classList.remove('is-visible');
            container.style.display = 'none';
            container._hideTimer = null;
        }, MESSAGE_TIMEOUT);
    }

    function calculateBarWidth(usedValue, limitValue) {
        const limit = Number(limitValue || 0);
        const used = Number(usedValue || 0);

        if (!Number.isFinite(limit) || limit <= 0) {
            return '100%';
        }

        if (!Number.isFinite(used) || used <= 0) {
            return '0%';
        }

        return Math.max(0, Math.min((used / limit) * 100, 100)).toFixed(2) + '%';
    }

    function updateMetricBar(card, selector, width) {
        const fill = card.querySelector(selector);
        if (fill) {
            fill.style.width = width;
        }
    }

    function updateCardResources(card, data) {
        const ram = card.querySelector('.stat-ram-usage');
        const disk = card.querySelector('.stat-disk-usage');
        const cpu = card.querySelector('.stat-cpu-usage');

        const memoryBytes = Number(data.memory_bytes || 0);
        const diskBytes = Number(data.disk_bytes || 0);
        const cpuValue = Number(data.cpu || 0);

        if (ram) {
            ram.textContent = formatBytes(memoryBytes);
        }

        if (disk) {
            disk.textContent = formatBytes(diskBytes);
        }

        if (cpu) {
            cpu.textContent = formatPercent(cpuValue);
        }

        const memoryLimitMiB = Number(card.dataset.memoryLimitMib || 0);
        const diskLimitMiB = Number(card.dataset.diskLimitMib || 0);
        const cpuLimit = Number(card.dataset.cpuLimit || 0);

        updateMetricBar(card, '.stat-ram-fill', calculateBarWidth(memoryBytes, memoryLimitMiB * 1024 * 1024));
        updateMetricBar(card, '.stat-disk-fill', calculateBarWidth(diskBytes, diskLimitMiB * 1024 * 1024));
        updateMetricBar(card, '.stat-cpu-fill', calculateBarWidth(cpuValue, cpuLimit));

        card.dataset.memoryBytes = String(memoryBytes);
        card.dataset.diskBytes = String(diskBytes);
        card.dataset.cpuValue = String(Number.isFinite(cpuValue) ? cpuValue : 0);
    }

    function applyStatusToCard(card, status, options = {}) {
        const badge = card.querySelector('.server-status');
        const start = card.querySelector('.btn-start');
        const stop = card.querySelector('.btn-stop');
        const restart = card.querySelector('.btn-restart');
        const nextStatus = statusToClass(status);

        card.dataset.status = nextStatus;

        if (!badge) {
            return;
        }

        badge.className = 'fbg-status-badge server-status ' + nextStatus;
        badge.textContent = statusToText(status);
        badge.classList.toggle('is-updating', !!options.updating);

        if (nextStatus === 'installing' || nextStatus === 'suspended') {
            if (start) start.disabled = true;
            if (stop) stop.disabled = true;
            if (restart) restart.disabled = true;
            return;
        }

        if (options.preserveButtons) {
            return;
        }

        if (start) {
            start.disabled = false;
        }

        if (stop) {
            stop.disabled = false;

            if (nextStatus === 'stopping') {
                stop.dataset.action = 'kill';
                stop.textContent = 'Kill';
                stop.classList.remove('danger-action');
                stop.classList.add('btn-delete');
            } else {
                stop.dataset.action = 'stop';
                stop.textContent = 'Stop';
                stop.classList.remove('btn-delete');
                stop.classList.add('danger-action');
            }
        }

        if (restart) {
            restart.disabled = false;
        }

        if (nextStatus === 'running') {
            if (start) start.disabled = true;
        } else if (nextStatus === 'offline') {
            if (stop) stop.disabled = true;
            if (restart) restart.disabled = true;
        } else if (nextStatus === 'starting') {
            if (start) start.disabled = true;
            if (stop) stop.disabled = true;
            if (restart) restart.disabled = true;
        } else if (nextStatus === 'stopping') {
            if (start) start.disabled = true;
            if (restart) restart.disabled = true;
        }
    }

    function setButtonsDisabled(container, disabled) {
        container.querySelectorAll('button').forEach(function (btn) {
            btn.disabled = disabled;
        });
    }

    function updateSummary() {
        const visibleCards = getVisibleCards();
        const total = visibleCards.length;
        let running = 0;
        let stopped = 0;
        let starting = 0;
        let memoryBytes = 0;
        let cpuTotal = 0;

        visibleCards.forEach(function (card) {
            const status = getCardStatus(card);

            if (status === 'running') {
                running += 1;
            } else if (status === 'installing' || status === 'starting' || status === 'stopping') {
                starting += 1;
            } else {
                stopped += 1;
            }

            memoryBytes += Number(card.dataset.memoryBytes || 0);
            cpuTotal += Number(card.dataset.cpuValue || 0);
        });

        if (summaryNodes.totalServers) {
            summaryNodes.totalServers.textContent = String(total);
        }

        if (summaryNodes.running) {
            summaryNodes.running.textContent = String(running);
        }

        if (summaryNodes.stopped) {
            summaryNodes.stopped.textContent = String(stopped);
        }

        if (summaryNodes.starting) {
            summaryNodes.starting.textContent = String(starting);
        }

        if (summaryNodes.memoryTotal) {
            summaryNodes.memoryTotal.textContent = formatBytes(memoryBytes);
        }

        if (summaryNodes.cpuTotal) {
            summaryNodes.cpuTotal.textContent = formatPercent(cpuTotal);
        }

        const percent = function (value) {
            if (total <= 0) {
                return '0.0% of total';
            }

            return ((value / total) * 100).toFixed(1) + '% of total';
        };

        if (summaryNodes.runningPercent) {
            summaryNodes.runningPercent.textContent = percent(running);
        }

        if (summaryNodes.stoppedPercent) {
            summaryNodes.stoppedPercent.textContent = percent(stopped);
        }

        if (summaryNodes.startingPercent) {
            summaryNodes.startingPercent.textContent = percent(starting);
        }
    }

    function applySearchFilter() {
        const query = String(searchInput ? searchInput.value : '').trim().toLowerCase();

        getCards().forEach(function (card) {
            const haystack = String(card.dataset.search || '');
            const matches = query === '' || haystack.includes(query);
            card.hidden = !matches;
            card.style.display = matches ? '' : 'none';
            card.setAttribute('aria-hidden', matches ? 'false' : 'true');
        });

        if (collection) {
            collection.dataset.empty = getVisibleCards().length === 0 ? 'true' : 'false';
        }

        updateSummary();
    }

    function setViewMode(mode) {
        const normalized = mode === 'cards' ? 'cards' : 'list';

        if (shell) {
            shell.dataset.dashboardView = normalized;
        }

        viewButtons.forEach(function (button) {
            const isActive = button.dataset.viewMode === normalized;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        try {
            window.localStorage.setItem(VIEW_MODE_KEY, normalized);
        } catch (error) {
            console.warn('[FBG] Unable to persist dashboard view mode.', error);
        }
    }

    function restoreViewMode() {
        let savedMode = 'list';

        try {
            savedMode = window.localStorage.getItem(VIEW_MODE_KEY) || 'list';
        } catch (error) {
            savedMode = 'list';
        }

        setViewMode(savedMode);
    }

    function calculatePollDelay(servers) {
        const values = Object.values(servers || {});
        let hasTransitional = false;
        let hasRunning = false;

        values.forEach(function (server) {
            const resourceStatus = String(server?.status || 'unknown');
            const status = server?.is_suspended
                ? 'suspended'
                : (server?.is_installing ? 'installing' : resourceStatus);

            if (status === 'installing' || status === 'starting' || status === 'stopping') {
                hasTransitional = true;
            } else if (status === 'running') {
                hasRunning = true;
            }
        });

        if (hasTransitional) {
            return POLL_FAST;
        }

        if (hasRunning) {
            return POLL_NORMAL;
        }

        return POLL_SLOW;
    }

    function clearBurstRefreshes() {
        burstTimeouts.forEach(function (timeoutId) {
            clearTimeout(timeoutId);
        });

        burstTimeouts = [];
    }

    function abortActiveStatusRequest() {
        if (activeStatusController) {
            activeStatusController.abort();
            activeStatusController = null;
        }
    }

    function scheduleNextPoll(delay = currentPollDelay) {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        if (!pageVisible) {
            return;
        }

        pollTimer = setTimeout(function () {
            refreshStatusesBatch();
        }, delay);
    }

    function queueBurstRefreshes() {
        clearBurstRefreshes();

        [800, 2000, 4000].forEach(function (delay) {
            const timeoutId = setTimeout(function () {
                refreshStatusesBatch({ immediate: true, force: true });
            }, delay);

            burstTimeouts.push(timeoutId);
        });
    }

    async function refreshStatusesBatch(options = {}) {
        if (!pageVisible && !options.force) {
            return;
        }

        if (pollInProgress) {
            if (!options.immediate) {
                scheduleNextPoll(currentPollDelay);
            }
            return;
        }

        const cardsList = getCards();
        const ids = getServerIds(cardsList);
        const statusIds = selectStatusIds(ids, options);

        if (!statusIds.length) {
            updateSummary();
            return;
        }

        pollInProgress = true;
        abortActiveStatusRequest();
        activeStatusController = new AbortController();

        try {
            const response = await fetch(
                STATUS_URL + '?ids=' + encodeURIComponent(statusIds.join(',')),
                {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    signal: activeStatusController.signal
                }
            );

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const payload = await response.json();
            const servers = payload && payload.servers ? payload.servers : {};

            cardsList.forEach(function (card) {
                const id = String(card.dataset.server || '').trim();

                if (!id || !servers[id]) {
                    return;
                }

                const data = servers[id];
                const resourceStatus = String(data.status || 'unknown');
                const displayStatus = data.is_suspended
                    ? 'suspended'
                    : (data.is_installing ? 'installing' : resourceStatus);

                applyStatusToCard(card, displayStatus);
                updateCardResources(card, data);
            });

            updateSummary();
            currentPollDelay = calculatePollDelay(servers);

            if (ids.length > MAX_STATUS_IDS_PER_REQUEST && currentPollDelay > POLL_NORMAL) {
                currentPollDelay = POLL_NORMAL;
            }
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }

            console.error('[FBG] Batch status check failed:', err);
            currentPollDelay = Math.max(currentPollDelay, POLL_SLOW);
        } finally {
            activeStatusController = null;
            pollInProgress = false;

            if (pageVisible) {
                scheduleNextPoll(currentPollDelay);
            }
        }
    }

    async function sendPower(container, identifier, action) {
        const card = container.closest('.fbg-server-card');
        const msgBox = card ? card.querySelector('.power-msg') : null;
        const currentStatus = card ? getCardStatus(card) : 'unknown';

        if (currentStatus === 'installing' || currentStatus === 'suspended') {
            showMessage(msgBox, 'This server cannot accept power actions right now.', true);
            return;
        }

        setButtonsDisabled(container, true);
        showMessage(msgBox, 'Sending ' + action + '...', false);

        if (card) {
            const optimisticStatus =
                action === 'start' ? 'starting' :
                action === 'stop' ? 'stopping' :
                action === 'restart' ? 'starting' :
                'unknown';

            applyStatusToCard(card, optimisticStatus, {
                updating: true,
                preserveButtons: true
            });
            updateSummary();
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('server_identifier', identifier);
            formData.append('action', action);

            const res = await fetch(POWER_URL, {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' }
            });

            const data = await res.json();

            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Request failed');
            }

            showMessage(msgBox, data.message || 'Success', false);
            currentPollDelay = POLL_FAST;
            refreshStatusesBatch({ immediate: true, force: true, ids: [identifier] });
            queueBurstRefreshes();
        } catch (err) {
            console.error('[FBG] Power action failed:', err);
            showMessage(msgBox, err.message || 'Failed', true);
            refreshStatusesBatch({ immediate: true, force: true, ids: [identifier] });
        } finally {
            setButtonsDisabled(container, false);
        }
    }

    function bindCardNavigation() {
        cards.forEach(function (card) {
            card.addEventListener('click', function (event) {
                const interactive = event.target.closest('button, a, input, select, textarea, .fbg-server-actions');

                if (interactive) {
                    return;
                }

                const href = card.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            });
        });

        document.querySelectorAll('.fbg-server-actions .btn').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        });
    }

    function bindPowerButtons() {
        if (!csrfToken) {
            return;
        }

        document.querySelectorAll('.fbg-server-actions[data-server]').forEach(function (container) {
            if (container.dataset.fbgBound === '1') {
                return;
            }

            container.dataset.fbgBound = '1';

            const identifier = String(container.dataset.server || '').trim();
            const startBtn = container.querySelector('.btn-start');
            const stopBtn = container.querySelector('.btn-stop');
            const restartBtn = container.querySelector('.btn-restart');

            if (startBtn) {
                startBtn.addEventListener('click', function () {
                    sendPower(container, identifier, 'start');
                });
            }

            if (stopBtn) {
                stopBtn.addEventListener('click', function () {
                    const action = stopBtn.dataset.action || 'stop';

                    if (action === 'kill' && !window.confirm('Force kill this server? This may cause data loss.')) {
                        return;
                    }

                    sendPower(container, identifier, action);
                });
            }

            if (restartBtn) {
                restartBtn.addEventListener('click', function () {
                    sendPower(container, identifier, 'restart');
                });
            }
        });
    }

    function bindViewButtons() {
        viewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setViewMode(button.dataset.viewMode || 'list');
            });
        });
    }

    function bindSearch() {
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', applySearchFilter);
    }

    document.addEventListener('visibilitychange', function () {
        pageVisible = document.visibilityState === 'visible';

        if (pageVisible) {
            refreshStatusesBatch({ immediate: true, force: true });
        } else {
            abortActiveStatusRequest();

            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        }
    });

    window.addEventListener('pagehide', function () {
        abortActiveStatusRequest();
        clearBurstRefreshes();

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    });

    bindCardNavigation();
    bindPowerButtons();
    bindViewButtons();
    bindSearch();
    restoreViewMode();
    applySearchFilter();

    if (!csrfToken) {
        return;
    }

    refreshStatusesBatch({ immediate: true, force: true });
});
