document.addEventListener('DOMContentLoaded', function () {
    const cards = document.querySelectorAll('.fbg-server-card[data-href]');

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
});

(function () {
    const config = window.FBG_DASHBOARD || {};
    const csrfToken = config.csrfToken;

    const STATUS_URL = '/api/server_status.php';
    const POWER_URL = '/api/server/power.php';

    const POLL_FAST = 1250;
    const POLL_NORMAL = 2250;
    const POLL_SLOW = 4500;
    const MESSAGE_TIMEOUT = 4000;
    const MAX_STATUS_IDS_PER_REQUEST = 12;

    let pollInProgress = false;
    let pageVisible = document.visibilityState === 'visible';
    let pollTimer = null;
    let currentPollDelay = POLL_NORMAL;
    let burstTimeouts = [];
    let nextStatusOffset = 0;
    let activeStatusController = null;

    function getCards() {
        return Array.from(document.querySelectorAll('.fbg-server-card'));
    }

    function getServerIds(cards) {
        return cards
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

    function statusToText(status) {
        switch (status) {
            case 'installing': return 'Installing';
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
        const badge = card ? card.querySelector('.server-status') : null;

        if (!badge) {
            return 'unknown';
        }

        const knownStates = ['running', 'offline', 'starting', 'stopping', 'installing'];

        return knownStates.find(state => badge.classList.contains(state)) || 'unknown';
    }

    function clearMessageTimer(container) {
        if (!container) return;

        if (container._hideTimer) {
            clearTimeout(container._hideTimer);
            container._hideTimer = null;
        }
    }

    function showMessage(container, message, isError) {
        if (!container) return;

        clearMessageTimer(container);

        container.textContent = message;
        container.className = 'fbg-dashboard-alert power-msg ' + (isError ? 'error' : 'success');
        container.style.display = 'block';

        container._hideTimer = setTimeout(() => {
            container.style.display = 'none';
            container._hideTimer = null;
        }, MESSAGE_TIMEOUT);
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

    function updateCardResources(card, data) {
        const ram = card.querySelector('.stat-ram-usage');
        const disk = card.querySelector('.stat-disk-usage');
        const cpu = card.querySelector('.stat-cpu-usage');

        if (ram && data.memory_bytes !== undefined) {
            ram.textContent = formatBytes(data.memory_bytes);
        }

        if (disk && data.disk_bytes !== undefined) {
            disk.textContent = formatBytes(data.disk_bytes);
        }

        if (cpu && data.cpu !== undefined) {
            const cpuValue = Number(data.cpu);
            cpu.textContent = (Number.isFinite(cpuValue) ? cpuValue : 0).toFixed(2) + '%';
        }
    }

    function applyStatusToCard(card, status, options = {}) {
        const badge = card.querySelector('.server-status');
        const start = card.querySelector('.btn-start');
        const stop = card.querySelector('.btn-stop');
        const restart = card.querySelector('.btn-restart');

        if (!badge) {
            return;
        }

        badge.className = `fbg-status-badge ${statusToClass(status)} server-status`;
        badge.textContent = statusToText(status);
        badge.classList.toggle('is-updating', !!options.updating);

        if (status === 'installing') {
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

            if (status === 'stopping') {
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

        if (status === 'running') {
            if (start) start.disabled = true;
        } else if (status === 'offline') {
            if (stop) stop.disabled = true;
            if (restart) restart.disabled = true;
        } else if (status === 'starting') {
            if (start) start.disabled = true;
            if (stop) stop.disabled = true;
            if (restart) restart.disabled = true;
        } else if (status === 'stopping') {
            if (start) start.disabled = true;
            if (restart) restart.disabled = true;
        }
    }

    function setButtonsDisabled(container, disabled) {
        const buttons = container.querySelectorAll('button');

        buttons.forEach(function (btn) {
            btn.disabled = disabled;
        });
    }

    function calculatePollDelay(servers) {
        const values = Object.values(servers || {});

        let hasTransitional = false;
        let hasRunning = false;

        values.forEach(function (server) {
            const status = server && server.is_installing ? 'installing' : String(server?.status || 'unknown');

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

        const cards = getCards();
        const ids = getServerIds(cards);
        const statusIds = selectStatusIds(ids, options);

        if (!statusIds.length) {
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

            cards.forEach(function (card) {
                const id = String(card.dataset.server || '').trim();

                if (!id || !servers[id]) {
                    return;
                }

                const data = servers[id];
                const rawStatus = String(data.status || 'unknown');
                const displayStatus = data.is_installing ? 'installing' : rawStatus;

                applyStatusToCard(card, displayStatus);
                updateCardResources(card, data);
            });

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

        if (currentStatus === 'installing') {
            showMessage(msgBox, 'This server is still installing. Power actions are temporarily disabled.', true);
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

                    if (action === 'kill') {
                        if (!confirm('Force kill this server? This may cause data loss.')) {
                            return;
                        }
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

    bindPowerButtons();

    if (!csrfToken) {
        return;
    }

    refreshStatusesBatch({ immediate: true, force: true });
})();
