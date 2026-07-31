(() => {
    'use strict';

    const panel = document.querySelector('.fbg-activity-panel');

    if (!panel) {
        return;
    }

    const serverIdentifier = String(panel.dataset.serverId || '').trim();
    const refreshButton = document.getElementById('activity-refresh-button');
    const content = document.getElementById('fbg-activity-content');
    const messageBox = document.getElementById('activity-message');

    if (!serverIdentifier || !content) {
        return;
    }

    const EVENT_LABELS = {
        'server:power.start': 'Started server',
        'server:power.stop': 'Stopped server',
        'server:power.restart': 'Restarted server',
        'server:power.kill': 'Killed server',

        'server:console.command': 'Ran console command',
        'server:console.command-sent': 'Ran console command',

        'server:file.read': 'Viewed file',
        'server:file.write': 'Edited file',
        'server:file.rename': 'Renamed file',
        'server:file.copy': 'Copied file',
        'server:file.delete': 'Deleted file',
        'server:file.compress': 'Compressed files',
        'server:file.decompress': 'Decompressed archive',
        'server:file.download': 'Downloaded file',
        'server:file.pull': 'Pulled file from URL',
        'server:file.upload': 'Uploaded file',
        'server:file.mkdir': 'Created folder',

        'server:backup.start': 'Started backup',
        'server:backup.download': 'Downloaded backup',
        'server:backup.delete': 'Deleted backup',
        'server:backup.restore': 'Restored backup',
        'server:backup.lock': 'Locked backup',
        'server:backup.unlock': 'Unlocked backup',

        'server:schedule.create': 'Created schedule',
        'server:schedule.update': 'Updated schedule',
        'server:schedule.delete': 'Deleted schedule',
        'server:schedule.execute': 'Executed schedule',

        'server:startup.update': 'Updated startup settings',
        'server:docker.image': 'Changed Docker image',
        'server:reinstall': 'Reinstalled server',
        'server:build.update': 'Updated build configuration',

        'server:database.create': 'Created database',
        'server:database.delete': 'Deleted database',

        'server:allocation.create': 'Created allocation',
        'server:allocation.delete': 'Deleted allocation',
        'server:allocation.update': 'Updated allocation',

        'server:subuser.create': 'Added subuser',
        'server:subuser.update': 'Updated subuser',
        'server:subuser.delete': 'Removed subuser',

        'server:network.interface': 'Updated network settings',
        'server:sftp.login': 'Logged in via SFTP'
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case '\'': return '&#039;';
                default: return char;
            }
        });
    }

    function showMessage(type, text) {
        if (!messageBox) {
            return;
        }

        messageBox.className = 'fbg-dashboard-alert ' + (type || '');
        messageBox.textContent = text || '';
        messageBox.style.display = text ? 'block' : 'none';
    }

    function clearMessage() {
        showMessage('', '');
    }

    function setLoadingState() {
        content.innerHTML = '<div class="fbg-schedules-loading">Loading activity...</div>';
    }

    function formatTimestamp(timestamp) {
        if (!timestamp) {
            return 'Unknown time';
        }

        const parsed = new Date(timestamp);

        if (Number.isNaN(parsed.getTime())) {
            return escapeHtml(timestamp);
        }

        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit'
            }).format(parsed);
        } catch (error) {
            return parsed.toLocaleString();
        }
    }

    function formatRelativeTime(timestamp) {
        if (!timestamp) {
            return '';
        }

        const parsed = new Date(timestamp);

        if (Number.isNaN(parsed.getTime())) {
            return '';
        }

        const diffMs = parsed.getTime() - Date.now();
        const diffSeconds = Math.round(diffMs / 1000);
        const absSeconds = Math.abs(diffSeconds);

        const ranges = [
            { limit: 60, divisor: 1, unit: 'second' },
            { limit: 3600, divisor: 60, unit: 'minute' },
            { limit: 86400, divisor: 3600, unit: 'hour' },
            { limit: 604800, divisor: 86400, unit: 'day' },
            { limit: 2629800, divisor: 604800, unit: 'week' },
            { limit: 31557600, divisor: 2629800, unit: 'month' },
            { limit: Infinity, divisor: 31557600, unit: 'year' }
        ];

        const range = ranges.find(function (item) {
            return absSeconds < item.limit;
        }) || ranges[ranges.length - 1];

        const value = Math.round(diffSeconds / range.divisor);

        try {
            return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(value, range.unit);
        } catch (error) {
            return '';
        }
    }

    function getEventLabel(eventName) {
        const eventKey = String(eventName || '').trim();

        if (!eventKey) {
            return 'Unknown activity';
        }

        if (EVENT_LABELS[eventKey]) {
            return EVENT_LABELS[eventKey];
        }

        return eventKey
            .replace(/[:._-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, function (char) {
                return char.toUpperCase();
            });
    }

    function stringifyValue(value) {
        if (value === null || typeof value === 'undefined') {
            return '';
        }

        if (typeof value === 'string') {
            return value.trim();
        }

        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }

        if (Array.isArray(value)) {
            return value.map(stringifyValue).filter(Boolean).join(', ');
        }

        if (typeof value === 'object') {
            try {
                return JSON.stringify(value);
            } catch (error) {
                return '';
            }
        }

        return String(value);
    }

    function readProperty(properties, keys) {
        if (!properties || typeof properties !== 'object') {
            return '';
        }

        for (let i = 0; i < keys.length; i += 1) {
            const key = keys[i];

            if (Object.prototype.hasOwnProperty.call(properties, key)) {
                const value = stringifyValue(properties[key]);

                if (value !== '') {
                    return value;
                }
            }
        }

        return '';
    }

    function buildDetailList(item) {
        const properties = item && typeof item.properties === 'object' && item.properties !== null
            ? item.properties
            : {};

        const details = [];

        const command = readProperty(properties, [
            'command',
            'console',
            'console_command',
            'command_input'
        ]);

        const file = readProperty(properties, [
            'file',
            'filename',
            'path',
            'target',
            'source',
            'directory'
        ]);

        const from = readProperty(properties, [
            'from',
            'old',
            'old_name',
            'old_path'
        ]);

        const to = readProperty(properties, [
            'to',
            'new',
            'new_name',
            'new_path'
        ]);

        const image = readProperty(properties, [
            'docker_image',
            'image'
        ]);

        const schedule = readProperty(properties, [
            'schedule',
            'name',
            'schedule_name'
        ]);

        const backup = readProperty(properties, [
            'backup',
            'uuid',
            'backup_uuid'
        ]);

        if (command) {
            details.push({
                label: 'Command',
                value: command
            });
        }

        if (file) {
            details.push({
                label: 'Path',
                value: file
            });
        }

        if (from && to) {
            details.push({
                label: 'Rename',
                value: from + ' → ' + to
            });
        } else if (to && !file) {
            details.push({
                label: 'Target',
                value: to
            });
        }

        if (image) {
            details.push({
                label: 'Image',
                value: image
            });
        }

        if (schedule) {
            details.push({
                label: 'Schedule',
                value: schedule
            });
        }

        if (backup) {
            details.push({
                label: 'Backup',
                value: backup
            });
        }

        if (item && item.ip) {
            details.push({
                label: 'IP',
                value: item.ip
            });
        }

        return details;
    }

    function buildMeta(item) {
        const actorType = String(item.actor_type || '').trim();
        const actorUsername = String(item.actor_username || '').trim();
        const actorId = Number(item.actor_id || 0);
        const parts = [];

        if (actorUsername) {
            parts.push('By ' + actorUsername);
        } else if (actorType === 'user' && actorId > 0) {
            parts.push('By User #' + actorId);
        } else if (actorType === 'api_key') {
            parts.push('By API Key');
        } else if (actorType) {
            parts.push('By ' + actorType.replace(/_/g, ' '));
        } else {
            parts.push('By System');
        }

        const exactTime = formatTimestamp(item.timestamp);
        const relativeTime = formatRelativeTime(item.timestamp);

        if (relativeTime) {
            parts.push(relativeTime);
        } else if (exactTime) {
            parts.push(exactTime);
        }

        return {
            exactTime: exactTime,
            summary: parts.join(' • ')
        };
    }

    function renderEmptyState() {
        content.innerHTML = [
            '<div class="fbg-activity-empty">',
            '    <div class="fbg-empty-state-icon"><i class="fas fa-wave-square"></i></div>',
            '    <h3>No activity yet</h3>',
            '    <p>No activity logs were found for this server.</p>',
            '</div>'
        ].join('');
    }

    function renderItems(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmptyState();
            return;
        }

        const html = items.map(function (item) {
            const title = getEventLabel(item.event);
            const description = String(item.description || '').trim();
            const meta = buildMeta(item);
            const details = buildDetailList(item);

            const detailsHtml = details.length
                ? '<div class="fbg-activity-details">' + details.map(function (detail) {
                    return [
                        '<div class="fbg-activity-detail-row">',
                        '   <span class="fbg-activity-detail-label">' + escapeHtml(detail.label) + '</span>',
                        '   <code class="fbg-activity-detail-value">' + escapeHtml(detail.value) + '</code>',
                        '</div>'
                    ].join('');
                }).join('') + '</div>'
                : '';

            const descriptionHtml = description
                ? '<p class="fbg-activity-description">' + escapeHtml(description) + '</p>'
                : '';

            return [
                '<article class="fbg-server-card fbg-activity-item" data-event="' + escapeHtml(item.event || '') + '">',
                '    <div class="fbg-activity-item-header">',
                '        <div class="fbg-activity-item-heading">',
                '            <h3>' + escapeHtml(title) + '</h3>',
                '            <div class="fbg-activity-meta">',
                '                <span title="' + escapeHtml(meta.exactTime) + '">' + escapeHtml(meta.summary) + '</span>',
                '            </div>',
                '        </div>',
                '        <div class="fbg-activity-event-pill">' + escapeHtml(item.event || 'unknown') + '</div>',
                '    </div>',
                descriptionHtml,
                detailsHtml,
                '</article>'
            ].join('');
        }).join('');

        content.innerHTML = '<div class="fbg-activity-list">' + html + '</div>';
    }

    async function loadActivity() {
        if (!serverIdentifier) {
            showMessage('error is-visible', 'Missing server identifier.');
            return;
        }

        clearMessage();
        setLoadingState();

        if (refreshButton) {
            refreshButton.disabled = true;
        }

        try {
            const url = '/api/server/activity/view.php?id=' + encodeURIComponent(serverIdentifier) + '&limit=100';
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            let payload = null;

            try {
                payload = await response.json();
            } catch (error) {
                payload = null;
            }

            if (!response.ok || !payload || payload.ok !== true) {
                const errorMessage = payload && payload.error
                    ? payload.error
                    : 'Failed to load activity logs.';
                throw new Error(errorMessage);
            }

            renderItems(Array.isArray(payload?.data?.items) ? payload.data.items : []);
        } catch (error) {
            content.innerHTML = [
                '<div class="fbg-dashboard-alert error is-visible">',
                escapeHtml(error && error.message ? error.message : 'Failed to load activity logs.'),
                '</div>'
            ].join('');

            showMessage('error', 'Failed to load activity logs.');
        } finally {
            if (refreshButton) {
                refreshButton.disabled = false;
            }
        }
    }

    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            loadActivity();
        });
    }

    loadActivity();
})();