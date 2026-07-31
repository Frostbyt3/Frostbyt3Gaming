(function () {
    const config = window.FBG_SERVER_PANEL || {};
    const panelApi = window.FBG_SERVER_PANEL_API || {};

    const identifier = config.identifier;
    const csrfToken = config.csrfToken;

    if (!identifier || !csrfToken) return;

    const COMMAND_URL = '/api/server/command.php';
    const CONSOLE_URL = '/api/server/console.php?server_identifier=' + encodeURIComponent(identifier);

    const commandInput = document.getElementById('server-command-input');
    const commandButton = document.getElementById('send-command-button');
    const commandMessage = document.getElementById('command-message');

    const consoleOutput = document.getElementById('server-console-output');
    const consoleMessage = document.getElementById('console-message');
    const consoleClearButton = document.getElementById('console-clear-button');
    const consoleAutoscrollButton = document.getElementById('console-autoscroll-button');

    if (!consoleOutput) return;

    let consoleAutoscrollEnabled = true;
    let consoleSocket = null;
    let consoleReconnectTimer = null;
    let consoleHasAuthenticated = false;

    let commandHistory = [];
    let commandHistoryIndex = -1;

    let commandMessageTimeout = null;
    let consoleMessageTimeout = null;

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

    function showCommandMessage(message, isError) {
        if (!commandMessage) return;

        clearNamedTimeout('command');
        commandMessage.textContent = message;
        commandMessage.className = 'fbg-dashboard-alert is-visible ' + (isError ? 'error' : 'success');
        commandMessage.style.display = 'block';

        commandMessageTimeout = setTimeout(() => {
            commandMessage.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function showConsoleMessage(message, isError) {
        if (!consoleMessage) return;

        clearNamedTimeout('console');
        consoleMessage.textContent = message;
        consoleMessage.className = 'fbg-dashboard-alert is-visible ' + (isError ? 'error' : 'success');
        consoleMessage.style.display = 'block';

        consoleMessageTimeout = setTimeout(() => {
            consoleMessage.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function scrollConsoleToBottom() {
        if (!consoleOutput || !consoleAutoscrollEnabled) return;
        consoleOutput.scrollTop = consoleOutput.scrollHeight;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function highlightLogLine(line) {
        if (line.includes('[WARN]')) {
            return '<span class="log-warn">' + line + '</span>';
        }
        if (line.includes('[ERROR]')) {
            return '<span class="log-error">' + line + '</span>';
        }
        if (line.includes('[INFO]')) {
            return '<span class="log-info">' + line + '</span>';
        }
        return line;
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

        let html = escapeHtml(text);

        html = html.replace(/\x1b\[([0-9;]*)m/g, function (_, codes) {
            if (!codes || codes === '0') {
                return '</span>';
            }

            const parts = codes.split(';');
            let out = '';

            for (const code of parts) {
                if (code === '' || code === '0') {
                    out += '</span>';
                } else if (ansiMap[code]) {
                    out += ansiMap[code];
                }
            }

            return out;
        });

        return html;
    }

    function appendConsoleText(text) {
        if (!consoleOutput || !text) return;

        let normalized = String(text)
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n');

        if (consoleOutput.textContent === 'Connecting to console...') {
            consoleOutput.innerHTML = '';
        }

        if (normalized && !normalized.endsWith('\n')) {
            normalized += '\n';
        }

        const lines = normalized.split('\n').map(highlightLogLine).join('\n');
        consoleOutput.innerHTML += ansiToHtml(lines);

        const maxTextLength = 300000;
        const trimToTextLength = 150000;

        if (consoleOutput.textContent.length > maxTextLength) {
            const plain = consoleOutput.textContent.slice(-trimToTextLength);
            consoleOutput.textContent = plain;
        }

        scrollConsoleToBottom();
    }

    async function sendCommand() {
        if (!commandInput || !commandButton) return;

        const command = commandInput.value.trim();

        if (!command) {
            //showCommandMessage('Enter a command first.', true);
            return;
        }

        commandInput.value = '';
        commandInput.disabled = true;
        commandButton.disabled = true;

        //showCommandMessage('Sending command...', false);

        try {
            const response = await fetch(COMMAND_URL, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    id: identifier,
                    command: command
                })
            });

            const rawText = await response.text();

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Non-JSON response from command endpoint:', rawText);
                throw new Error('Command endpoint returned invalid response. Check PHP error logs.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Command failed');
            }

            //showCommandMessage(data.message || 'Command sent.', false);

            commandHistory.push(command);
            commandHistoryIndex = commandHistory.length;
        } catch (err) {
            console.error('Command error:', err);
            showCommandMessage(err.message || 'Failed to send command.', true);
            commandInput.value = command;
        } finally {
            commandInput.disabled = false;
            commandButton.disabled = false;
            commandInput.focus();
        }
    }

    function requestConsoleLogsAndStats() {
        if (!consoleSocket || consoleSocket.readyState !== WebSocket.OPEN) return;

        consoleSocket.send(JSON.stringify({
            event: 'send logs',
            args: [null]
        }));

        consoleSocket.send(JSON.stringify({
            event: 'send stats',
            args: [null]
        }));
    }

    function handleConsoleSocketMessage(message) {
        if (!message || typeof message !== 'object') return;

        console.log('Console socket message:', message);

        if (message.event === 'auth success') {
            consoleHasAuthenticated = true;
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[92mConsole connected!');
            requestConsoleLogsAndStats();
            return;
        }

        if (message.event === 'console output') {
            const chunk = Array.isArray(message.args) ? message.args.join('\n') : '';
            appendConsoleText(chunk);
            return;
        }

        if (message.event === 'status') {
            const status = Array.isArray(message.args) ? message.args[0] : 'unknown';

            if (typeof panelApi.updateUI === 'function') {
                panelApi.updateUI({ status: status });
            }

            return;
        }

        if (message.event === 'stats') {
            try {
                const rawStats = Array.isArray(message.args) ? message.args[0] : '{}';
                const stats = typeof rawStats === 'string' ? JSON.parse(rawStats) : rawStats;

                if (typeof panelApi.updateUI === 'function') {
                    panelApi.updateUI({
                        status: stats.state || undefined,
                        memory_bytes: stats.memory_bytes,
                        disk_bytes: stats.disk_bytes,
                        cpu: stats.cpu_absolute
                    });
                }
            } catch (err) {
                console.error('Failed to parse stats payload:', err, message);
            }

            return;
        }

        if (message.event === 'daemon message') {
            const text = Array.isArray(message.args) ? message.args.join(' ') : 'Daemon message received.';
            appendConsoleText('\n[daemon] ' + text + '\n');
            return;
        }

        if (message.event === 'jwt error') {
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mConsole session expired.\x1b[0m \x1b[93mReconnecting...');
            reconnectConsoleSoon();
            return;
        }

        if (message.event === 'token expiring') {
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mConsole token expiring.\x1b[0m \x1b[93mReconnecting...');
            reconnectConsoleSoon();
            return;
        }

        if (message.event === 'token expired') {
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mConsole token expired.\x1b[0m \x1b[93mReconnecting...');
            reconnectConsoleSoon();
        }
    }

    function reconnectConsoleSoon() {
        if (consoleReconnectTimer) {
            clearTimeout(consoleReconnectTimer);
        }

        if (consoleSocket) {
            try {
                consoleSocket.close();
            } catch (e) {
                console.error('Error closing console socket during reconnect:', e);
            }
            consoleSocket = null;
        }

        consoleReconnectTimer = setTimeout(() => {
            connectConsole();
        }, 2000);
    }

    async function connectConsole() {
        try {
            if (consoleReconnectTimer) {
                clearTimeout(consoleReconnectTimer);
                consoleReconnectTimer = null;
            }

            if (consoleSocket) {
                try {
                    consoleSocket.close();
                } catch (e) {
                    console.error('Error closing existing console socket:', e);
                }
                consoleSocket = null;
            }

            consoleHasAuthenticated = false;
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[92mConnecting to console...');

            const response = await fetch(CONSOLE_URL, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            const rawText = await response.text();

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Non-JSON response from console endpoint:', rawText);
                throw new Error('Console endpoint returned invalid response. Check PHP error logs.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Failed to get console connection details.');
            }

            const socketUrl = data.socket;
            const token = data.token;

            if (!socketUrl || !token) {
                throw new Error('Console connection details were incomplete.');
            }

            consoleSocket = new WebSocket(socketUrl);

            consoleSocket.addEventListener('open', function () {
                consoleSocket.send(JSON.stringify({
                    event: 'auth',
                    args: [token]
                }));
            });

            consoleSocket.addEventListener('message', function (event) {
                try {
                    const message = JSON.parse(event.data);
                    handleConsoleSocketMessage(message);
                } catch (err) {
                    console.error('Failed to parse console socket message:', err, event.data);
                }
            });

            consoleSocket.addEventListener('close', function (event) {
                console.warn('Console socket closed:', {
                    code: event.code,
                    reason: event.reason,
                    wasClean: event.wasClean,
                    authenticated: consoleHasAuthenticated
                });

                appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mConsole disconnected (code ' + event.code + ')\x1b[0m. \x1b[93mReconnecting...');
                reconnectConsoleSoon();
            });

            consoleSocket.addEventListener('error', function (event) {
                console.error('Console socket error:', event);
                appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mConsole connection error\x1b[0m. \x1b[93mReconnecting...');
                reconnectConsoleSoon();
            });
        } catch (err) {
            console.error('Console connection failed:', err);
            //showConsoleMessage(err.message || 'Failed to connect to console.', true);
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[91mFailed to connect to console.');
            reconnectConsoleSoon();
        }
    }

    window.FBG_SERVER_PANEL_CONSOLE = {
        appendConsoleText
    };

    if (consoleClearButton) {
        consoleClearButton.addEventListener('click', function () {
            consoleOutput.textContent = '';
            appendConsoleText('\x1b[93m[FBG]:\x1b[0m \x1b[93mConsole Cleared.');
        });
    }

    if (consoleAutoscrollButton) {
        consoleAutoscrollButton.addEventListener('click', function () {
            consoleAutoscrollEnabled = !consoleAutoscrollEnabled;
            consoleAutoscrollButton.dataset.enabled = consoleAutoscrollEnabled ? 'true' : 'false';
            consoleAutoscrollButton.textContent = 'Auto-scroll: ' + (consoleAutoscrollEnabled ? 'On' : 'Off');

            if (consoleAutoscrollEnabled) {
                scrollConsoleToBottom();
            }
        });
    }

    if (commandButton) {
        commandButton.addEventListener('click', sendCommand);
    }

    if (commandInput) {
        commandInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendCommand();
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (commandHistory.length > 0) {
                    commandHistoryIndex = Math.max(0, commandHistoryIndex - 1);
                    commandInput.value = commandHistory[commandHistoryIndex];
                }
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (commandHistory.length > 0) {
                    commandHistoryIndex = Math.min(commandHistory.length - 1, commandHistoryIndex + 1);
                    commandInput.value = commandHistory[commandHistoryIndex] || '';
                }
            }
        });
    }

    connectConsole();

    window.addEventListener('beforeunload', function () {
        if (consoleReconnectTimer) {
            clearTimeout(consoleReconnectTimer);
        }

        if (consoleSocket) {
            try {
                consoleSocket.close();
            } catch (e) {
                console.error('Error closing console socket on unload:', e);
            }
        }
    });
})();
