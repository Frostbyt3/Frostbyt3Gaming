(function () {
    const config = window.FBG_SERVER_PANEL || {};

    const identifier = String(config.identifier || '').trim();
    const csrfToken = String(config.csrfToken || '').trim();
    const isValheimServer = !!config.isValheim;

    if (!identifier || !csrfToken) return;

    const CONSOLE_URL = '/api/server/console.php?server_identifier=' + encodeURIComponent(identifier);
    const TOKEN_REFRESH_FALLBACK_MS = 12 * 60 * 1000;
    const RECONNECT_MIN_MS = 1000;
    const RECONNECT_MAX_MS = 15000;
    const MAX_TEXT_LENGTH = 300000;
    const TRIM_TO_TEXT_LENGTH = 150000;
    const COMMAND_HISTORY_LIMIT = 50;

    const commandInput = document.getElementById('server-command-input');
    const commandButton = document.getElementById('send-command-button');
    const commandMessage = document.getElementById('command-message');
    const consoleOutput = document.getElementById('server-console-output');
    const consoleMessage = document.getElementById('console-message');
    const consoleClearButton = document.getElementById('console-clear-button');
    const consoleAutoscrollButton = document.getElementById('console-autoscroll-button');

    if (!consoleOutput) return;

    const TerminalCtor = typeof window.Terminal === 'function' ? window.Terminal : null;
    let socket = null;
    let socketToken = '';
    let socketUrl = '';
    let terminal = null;
    let terminalResizeTimer = null;
    let authenticated = false;
    let connecting = false;
    let manuallyClosed = false;
    let ignoredCloseSocket = null;
    let reconnectTimer = null;
    let tokenTimer = null;
    let reconnectDelay = RECONNECT_MIN_MS;
    let activeConnectionId = 0;
    let consoleAutoscrollEnabled = true;
    let commandHistory = [];
    let commandHistoryIndex = -1;
    let commandMessageTimeout = null;
    let consoleMessageTimeout = null;
    let pendingConsoleHtml = '';
    let consoleFlushFrame = null;

    function clearNamedTimeout(name) {
        if (name === 'command' && commandMessageTimeout) {
            clearTimeout(commandMessageTimeout);
            commandMessageTimeout = null;
        }

        if (name === 'console' && consoleMessageTimeout) {
            clearTimeout(consoleMessageTimeout);
            consoleMessageTimeout = null;
        }
    }

    function showTimedMessage(element, message, isError, name) {
        if (!element) return;

        clearNamedTimeout(name);

        element.textContent = message;
        element.className = 'fbg-dashboard-alert fbg-console-toolbar-message is-visible ' + (isError ? 'error' : 'success');
        element.style.display = 'block';

        const timeoutId = setTimeout(() => {
            element.classList.remove('is-visible', 'error', 'success');
            element.style.display = 'none';
        }, isError ? 7000 : 4000);

        if (name === 'command') commandMessageTimeout = timeoutId;
        if (name === 'console') consoleMessageTimeout = timeoutId;
    }

    function showCommandMessage(message, isError) {
        showTimedMessage(commandMessage, message, isError, 'command');
    }

    function showConsoleMessage(message, isError) {
        showTimedMessage(consoleMessage, message, isError, 'console');
    }

    function plainToastText(value) {
        return String(value || '')
            .replace(/\\n/g, '\n')
            .replace(/^#{1,3}\s+/gm, '')
            .replace(/^-#\s+/gm, '')
            .replace(/[*_~-]/g, '')
            .trim();
    }

    function showConsoleToast({ type = 'info', title = 'Server Console', message = '', duration, persistent = false, fallback = 'console' } = {}) {
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

        const fallbackMessage = plainToastText(cleanMessage);
        const isError = type === 'error' || type === 'warning';

        if (fallback === 'command') {
            showCommandMessage(fallbackMessage, isError);
            return;
        }

        showConsoleMessage(fallbackMessage, isError);
    }

    function setCommandEnabled(enabled) {
        if (commandInput) commandInput.disabled = !enabled;
        if (commandButton) commandButton.disabled = !enabled;
    }

    function scrollConsoleToBottom() {
        if (!consoleAutoscrollEnabled) return;

        if (terminal && typeof terminal.scrollToBottom === 'function') {
            terminal.scrollToBottom();
            return;
        }

        if (!consoleOutput) return;

        consoleOutput.scrollTop = consoleOutput.scrollHeight;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function ansiToHtml(text) {
        const ansiMap = {
            '1': '<span class="ansi-bold">',
            '30': '<span class="ansi-fg-black">',
            '31': '<span class="ansi-fg-red">',
            '32': '<span class="ansi-fg-green">',
            '33': '<span class="ansi-fg-yellow">',
            '34': '<span class="ansi-fg-blue">',
            '35': '<span class="ansi-fg-magenta">',
            '36': '<span class="ansi-fg-cyan">',
            '37': '<span class="ansi-fg-white">',
            '90': '<span class="ansi-fg-bright-black">',
            '91': '<span class="ansi-fg-bright-red">',
            '92': '<span class="ansi-fg-bright-green">',
            '93': '<span class="ansi-fg-bright-yellow">',
            '94': '<span class="ansi-fg-bright-blue">',
            '95': '<span class="ansi-fg-bright-magenta">',
            '96': '<span class="ansi-fg-bright-cyan">',
            '97': '<span class="ansi-fg-bright-white">'
        };

        return escapeHtml(text).replace(/\x1b\[([0-9;]*)m/g, function (_, codes) {
            if (!codes || codes === '0') return '</span>';

            return codes.split(';').map((code) => {
                if (!code || code === '0') return '</span>';
                return ansiMap[code] || '';
            }).join('');
        });
    }

    function formatLogLine(line) {
        const html = ansiToHtml(line);

        if (line.includes('[WARN]')) return '<span class="log-warn">' + html + '</span>';
        if (line.includes('[ERROR]')) return '<span class="log-error">' + html + '</span>';
        if (line.includes('[INFO]')) return '<span class="log-info">' + html + '</span>';

        return html;
    }

    function flushConsoleBuffer() {
        consoleFlushFrame = null;

        if (!consoleOutput || !pendingConsoleHtml) return;

        const html = pendingConsoleHtml;
        pendingConsoleHtml = '';

        consoleOutput.insertAdjacentHTML('beforeend', html);

        if (consoleOutput.textContent.length > MAX_TEXT_LENGTH) {
            consoleOutput.textContent = consoleOutput.textContent.slice(-TRIM_TO_TEXT_LENGTH);
        }

        scrollConsoleToBottom();
    }

    function queueConsoleHtml(html) {
        if (!html) return;

        pendingConsoleHtml += html;

        if (consoleFlushFrame !== null) return;

        consoleFlushFrame = window.requestAnimationFrame(flushConsoleBuffer);
    }

    function normalizeTerminalText(text) {
        let normalized = String(text)
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n');

        if (normalized && !normalized.endsWith('\n')) {
            normalized += '\n';
        }

        return normalized
            .replace(/\n/g, '\r\n');
    }

    function resizeTerminal() {
        if (!terminal || !consoleOutput || typeof terminal.resize !== 'function') return;

        const styles = window.getComputedStyle(consoleOutput);
        const fontSize = parseFloat(styles.fontSize) || 12;
        const lineHeight = parseFloat(styles.lineHeight) || Math.ceil(fontSize * 1.35);
        const paddingX = (parseFloat(styles.paddingLeft) || 0) + (parseFloat(styles.paddingRight) || 0);
        const paddingY = (parseFloat(styles.paddingTop) || 0) + (parseFloat(styles.paddingBottom) || 0);
        const characterWidth = fontSize * 0.62;
        const cols = Math.max(40, Math.floor((consoleOutput.clientWidth - paddingX) / characterWidth));
        const rows = Math.max(12, Math.floor((consoleOutput.clientHeight - paddingY) / lineHeight));

        terminal.resize(cols, rows);
    }

    function scheduleTerminalResize() {
        if (terminalResizeTimer) {
            clearTimeout(terminalResizeTimer);
        }

        terminalResizeTimer = setTimeout(() => {
            terminalResizeTimer = null;
            resizeTerminal();
        }, 100);
    }

    function initializeTerminal() {
        if (!TerminalCtor || terminal) return !!terminal;

        consoleOutput.textContent = '';
        consoleOutput.classList.add('is-xterm');

        const rootStyles = window.getComputedStyle(document.documentElement);
        const terminalBackground = rootStyles.getPropertyValue('--fbg-body-bg').trim() || '#111111';

        terminal = new TerminalCtor({
            allowTransparency: true,
            convertEol: true,
            cursorBlink: false,
            cursorStyle: 'underline',
            disableStdin: true,
            fontFamily: '"JetBrains Mono", "Fira Code", Consolas, monospace',
            fontSize: 12,
            lineHeight: 1.35,
            rows: 32,
            scrollback: 8000,
            theme: {
                background: terminalBackground,
                foreground: '#f5f5f5',
                black: '#000000',
                red: '#e06c75',
                green: '#98c379',
                yellow: '#e5c07b',
                blue: '#61afef',
                magenta: '#c678dd',
                cyan: '#56b6c2',
                white: '#dcdfe4',
                brightBlack: '#7f848e',
                brightRed: '#ff7b72',
                brightGreen: '#7ee787',
                brightYellow: '#f2cc60',
                brightBlue: '#79c0ff',
                brightMagenta: '#d2a8ff',
                brightCyan: '#a5f3fc',
                brightWhite: '#ffffff'
            }
        });

        terminal.open(consoleOutput);
        resizeTerminal();

        window.addEventListener('resize', scheduleTerminalResize);

        return true;
    }

    function appendConsoleText(text) {
        if (!consoleOutput || !text) return;

        if (terminal || initializeTerminal()) {
            terminal.write(normalizeTerminalText(text));
            scrollConsoleToBottom();
            return;
        }

        let normalized = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n');

        if (consoleOutput.textContent === 'Connecting to console...') {
            consoleOutput.innerHTML = '';
            pendingConsoleHtml = '';
        }

        if (normalized && !normalized.endsWith('\n')) {
            normalized += '\n';
        }

        queueConsoleHtml(normalized.split('\n').map(formatLogLine).join('\n'));
    }

    function sendSocketEvent(event, args = [null]) {
        if (!socket || socket.readyState !== WebSocket.OPEN || !authenticated) {
            return false;
        }

        socket.send(JSON.stringify({
            event,
            args: Array.isArray(args) ? args : [args]
        }));

        return true;
    }

    function requestLogs() {
        sendSocketEvent('send logs');
    }

    function requestStats() {
        sendSocketEvent('send stats');
    }

    function clearReconnectTimer() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    }

    function clearTokenTimer() {
        if (tokenTimer) {
            clearTimeout(tokenTimer);
            tokenTimer = null;
        }
    }

    function scheduleTokenRefresh() {
        clearTokenTimer();

        tokenTimer = setTimeout(() => {
            refreshToken();
        }, TOKEN_REFRESH_FALLBACK_MS);
    }

    function closeSocket(ignoreCloseEvent = false) {
        authenticated = false;

        if (!socket) return;

        if (ignoreCloseEvent) {
            ignoredCloseSocket = socket;
        }

        try {
            socket.close();
        } catch (error) {
            console.error('Error closing console socket:', error);
        }

        socket = null;
    }

    function scheduleReconnect(reason) {
        if (manuallyClosed) return;

        closeSocket(true);
        clearReconnectTimer();
        setCommandEnabled(false);

        if (reason) {
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91m' + reason + '\x1b[0m \x1b[93mReconnecting...\x1b[0m');
        }

        const delay = reconnectDelay;
        reconnectDelay = Math.min(reconnectDelay + 1500, RECONNECT_MAX_MS);

        reconnectTimer = setTimeout(() => {
            connectConsole();
        }, delay);
    }

    async function getConnectionDetails() {
        const response = await fetch(CONSOLE_URL, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });

        const rawText = await response.text();
        let data;

        try {
            data = JSON.parse(rawText);
        } catch (error) {
            console.error('Non-JSON response from console endpoint:', rawText);
            throw new Error('Console endpoint returned invalid response. Check PHP error logs.');
        }

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Failed to get console connection details.');
        }

        if (!data.socket || !data.token) {
            throw new Error('Console connection details were incomplete.');
        }

        return {
            socket: String(data.socket),
            token: String(data.token)
        };
    }

    async function refreshToken() {
        if (manuallyClosed) return;

        try {
            const details = await getConnectionDetails();
            socketToken = details.token;

            if (details.socket && details.socket !== socketUrl) {
                socketUrl = details.socket;
                scheduleReconnect('Console endpoint changed.');
                return;
            }

            if (socket && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({
                    event: 'auth',
                    args: [socketToken]
                }));
            }

            scheduleTokenRefresh();
        } catch (error) {
            console.error('Console token refresh failed:', error);
            scheduleReconnect('Console token refresh failed.');
        }
    }

    function normalizeStatus(status) {
        const value = String(status || '').trim().toLowerCase();
        if (value === 'off') return 'offline';
        if (value === 'killed') return 'offline';
        if (value === 'suspended') return 'suspended';
        if (value === 'installing') return 'installing';
        return value || undefined;
    }

    function getPanelApi() {
        return window.FBG_SERVER_PANEL_API || {};
    }

    function updateValheimJoinCodeFromConsole(text) {
        if (!isValheimServer || !text) return;

        const match = String(text).match(/\bjoin code\s+([0-9]{4,10})\b/i);
        if (!match) return;

        const panelApi = getPanelApi();
        if (typeof panelApi.updateValheimJoinCode !== 'function') return;

        panelApi.updateValheimJoinCode(match[1]);
    }

    function updatePanelStatus(status) {
        const panelApi = getPanelApi();

        if (typeof panelApi.updateUI !== 'function') return;

        const normalized = normalizeStatus(status);
        if (!normalized) return;

        panelApi.updateUI({ status: normalized, status_source: 'socket_status' });
    }

    function updatePanelStats(rawStats) {
        const panelApi = getPanelApi();

        if (typeof panelApi.updateUI !== 'function') return;

        let stats = rawStats;

        if (typeof rawStats === 'string') {
            try {
                stats = JSON.parse(rawStats);
            } catch (error) {
                console.error('Failed to parse stats payload:', error, rawStats);
                return;
            }
        }

        if (!stats || typeof stats !== 'object') return;

        const resources = stats.resources && typeof stats.resources === 'object'
            ? stats.resources
            : stats;

        panelApi.updateUI({
            status: normalizeStatus(stats.state || stats.current_state),
            memory_bytes: resources.memory_bytes,
            disk_bytes: resources.disk_bytes,
            cpu: resources.cpu_absolute,
            uptime: resources.uptime
        });
    }

    function handleSocketMessage(message) {
        if (!message || typeof message !== 'object') return;

        const args = Array.isArray(message.args) ? message.args : [];

        switch (message.event) {
            case 'auth success':
                authenticated = true;
                reconnectDelay = RECONNECT_MIN_MS;
                setCommandEnabled(true);
                appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[92mConsole connected!\x1b[0m');
                requestLogs();
                requestStats();
                scheduleTokenRefresh();
                return;

            case 'console output':
            case 'install output':
            case 'transfer logs':
                updateValheimJoinCodeFromConsole(args.join('\n'));
                appendConsoleText(args.join('\n'));
                return;

            case 'status':
                updatePanelStatus(args[0]);
                return;

            case 'stats':
                updatePanelStats(args[0] || {});
                return;

            case 'daemon message':
                appendConsoleText('\n[daemon] ' + args.join(' ') + '\n');
                return;

            case 'daemon error':
                appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91m' + args.join(' ') + '\x1b[0m');
                return;

            case 'token expiring':
            case 'token expired':
                refreshToken();
                return;

            case 'jwt error':
                console.warn('Console JWT error:', args[0] || '');
                refreshToken();
                return;

            case 'transfer status':
                if (args[0] && args[0] !== 'starting' && args[0] !== 'success') {
                    scheduleReconnect('Server transfer state changed.');
                }
                return;

            default:
                return;
        }
    }

    async function connectConsole() {
        if (connecting || manuallyClosed) return;

        connecting = true;
        clearReconnectTimer();
        clearTokenTimer();
        closeSocket(true);
        setCommandEnabled(false);
        appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[92mConnecting to console...\x1b[0m');

        /* showConsoleToast({
            type: 'success', // success, warning, info, error
            title: 'Frostbyt3 Gaming',
            message: '# Header 1\n## Header 2\n### Header 3\nNormal Text\n-# Sub-text\n*italic*\n**bold**\n***bold italic***\n__underline__\n__*underline italic*__\n__**underline bold**__\n__***underline bold italic***__\n--strikethrough--',
            persistent: true,
        }) */

        const connectionId = ++activeConnectionId;

        try {
            const details = await getConnectionDetails();

            if (connectionId !== activeConnectionId || manuallyClosed) {
                return;
            }

            socketUrl = details.socket;
            socketToken = details.token;
            socket = new WebSocket(socketUrl);
            const activeSocket = socket;

            activeSocket.addEventListener('open', () => {
                activeSocket.send(JSON.stringify({
                    event: 'auth',
                    args: [socketToken]
                }));
            });

            activeSocket.addEventListener('message', (event) => {
                try {
                    handleSocketMessage(JSON.parse(event.data));
                } catch (error) {
                    console.warn('Failed to parse console socket message:', error, event.data);
                }
            });

            activeSocket.addEventListener('close', (event) => {
                if (manuallyClosed) return;
                if (ignoredCloseSocket === activeSocket) {
                    ignoredCloseSocket = null;
                    return;
                }

                console.warn('Console socket closed:', {
                    code: event.code,
                    reason: event.reason,
                    wasClean: event.wasClean,
                    authenticated
                });

                scheduleReconnect('Console disconnected.');
            });

            activeSocket.addEventListener('error', (event) => {
                console.error('Console socket error:', event);
                scheduleReconnect('Console connection error.');
            });
        } catch (error) {
            console.error('Console connection failed:', error);
            showConsoleToast({
                type: 'error',
                message: "We couldn't connect to the console.\nTrying again shortly.",
            });
            scheduleReconnect('Failed to connect to console.');
        } finally {
            connecting = false;
        }
    }

    function sendCommand() {
        if (!commandInput || !commandButton) return;

        const command = commandInput.value.trim();

        if (!command) {
            return;
        }

        if (!sendSocketEvent('send command', command)) {
            showConsoleToast({
                type: 'warning',
                message: 'The console is still connecting.\nTry again in a moment.',
                fallback: 'command',
            });

            commandInput.focus();
            return;
        }

        commandHistory.unshift(command);
        commandHistory = commandHistory.slice(0, COMMAND_HISTORY_LIMIT);
        commandHistoryIndex = -1;
        commandInput.value = '';
        commandInput.focus();

        showConsoleToast({
            type: 'success',
            message: 'Your command has been sent to the server.',
            fallback: 'command',
        });
    }

    window.FBG_SERVER_PANEL_CONSOLE = {
        appendConsoleText,
        requestStats,
        reconnect: () => scheduleReconnect('Manual reconnect requested.'),
        disconnect: () => {
            manuallyClosed = true;
            clearReconnectTimer();
            clearTokenTimer();
            closeSocket(true);
        }
    };

    if (consoleClearButton) {
        consoleClearButton.addEventListener('click', function () {
            if (terminal) {
                terminal.clear();
            } else {
                consoleOutput.textContent = '';
            }

            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[93mConsole cleared.\x1b[0m');
            showConsoleToast({
                type: 'info',
                message: 'Console cleared.',
            });
        });
    }

    if (consoleAutoscrollButton) {
        consoleAutoscrollButton.addEventListener('click', function () {
            consoleAutoscrollEnabled = !consoleAutoscrollEnabled;
            consoleAutoscrollButton.dataset.enabled = consoleAutoscrollEnabled ? 'true' : 'false';
            consoleAutoscrollButton.textContent = 'Auto-scroll: ' + (consoleAutoscrollEnabled ? 'On' : 'Off');

            showConsoleToast({
                type: (consoleAutoscrollEnabled ? 'info' : 'warning'),
                message: 'Auto-scroll ' + (consoleAutoscrollEnabled ? 'enabled' : 'disabled'),
            })

            if (consoleAutoscrollEnabled) {
                scrollConsoleToBottom();
            }
        });
    }

    if (commandButton) {
        commandButton.addEventListener('click', sendCommand);
    }

    if (commandInput) {
        commandInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                sendCommand();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();

                if (commandHistory.length > 0) {
                    commandHistoryIndex = Math.min(commandHistoryIndex + 1, commandHistory.length - 1);
                    commandInput.value = commandHistory[commandHistoryIndex] || '';
                }

                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();

                if (commandHistory.length > 0) {
                    commandHistoryIndex = Math.max(commandHistoryIndex - 1, -1);
                    commandInput.value = commandHistoryIndex >= 0 ? commandHistory[commandHistoryIndex] : '';
                }
            }
        });
    }

    window.addEventListener('beforeunload', function () {
        manuallyClosed = true;
        clearReconnectTimer();
        clearTokenTimer();
        closeSocket();
    });

    initializeTerminal();
    connectConsole();
})();
