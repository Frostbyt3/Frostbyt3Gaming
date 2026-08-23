(() => {
    const DEFAULT_DURATIONS = {
        success: 5000,
        info: 5000,
        warning: 9000,
        error: 9000,
    };
    const EXIT_DURATION = 260;
    const VALID_TYPES = new Set(['success', 'warning', 'info', 'error']);
    let container = null;

    function getContainer() {
        if (container) return container;

        container = document.querySelector('[data-fbg-toast-container]');

        if (!container) {
            container = document.createElement('div');
            container.className = 'fbg-toast-container';
            container.setAttribute('data-fbg-toast-container', '');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-relevant', 'additions');
            document.body.appendChild(container);
        }

        return container;
    }

    function normalizeOptions(messageOrOptions, type, duration) {
        if (typeof messageOrOptions === 'object' && messageOrOptions !== null) {
            const requestedType = String(messageOrOptions.type || 'info').trim();
            const normalizedType = VALID_TYPES.has(requestedType) ? requestedType : 'info';
            const explicitDuration = messageOrOptions.duration;

            return {
                title: String(messageOrOptions.title || '').trim(),
                message: String(messageOrOptions.message || messageOrOptions.text || '').trim(),
                type: normalizedType,
                duration: explicitDuration === 0 ? 0 : Number(explicitDuration || DEFAULT_DURATIONS[normalizedType]),
                persistent: messageOrOptions.persistent === true || explicitDuration === 0,
            };
        }

        const requestedType = String(type || 'info').trim();
        const normalizedType = VALID_TYPES.has(requestedType) ? requestedType : 'info';
        const explicitDuration = duration;

        return {
            title: '',
            message: String(messageOrOptions || '').trim(),
            type: normalizedType,
            duration: explicitDuration === 0 ? 0 : Number(explicitDuration || DEFAULT_DURATIONS[normalizedType]),
            persistent: explicitDuration === 0,
        };
    }

    function dismissToast(toast) {
        if (!toast || toast.classList.contains('is-hiding')) return;

        if (toast._fbgTimer) {
            window.clearTimeout(toast._fbgTimer);
            toast._fbgTimer = null;
        }

        toast.classList.add('is-hiding');
        window.setTimeout(() => toast.remove(), EXIT_DURATION);
    }

    function startDismissTimer(toast) {
        if (toast._fbgPersistent || toast._fbgRemaining <= 0 || toast.classList.contains('is-hiding')) return;
        if (toast._fbgTimer) window.clearTimeout(toast._fbgTimer);

        toast._fbgStartedAt = Date.now();
        toast._fbgTimer = window.setTimeout(() => dismissToast(toast), toast._fbgRemaining);
    }

    function pauseDismissTimer(toast) {
        if (toast._fbgPersistent || !toast._fbgTimer) return;

        window.clearTimeout(toast._fbgTimer);
        toast._fbgTimer = null;
        toast._fbgRemaining = Math.max(1000, toast._fbgRemaining - (Date.now() - toast._fbgStartedAt));
    }

    /*
     * Toast markdown supports Discord-style emphasis:
     * *italic*
     * **bold**
     * ***bold italic***
     * __underline__
     * __*underline italic*__
     * __**underline bold**__,
     * __***underline bold italic***__
     * --strikethrough--,
     * # Big Heading
     * ## Smaller Heading
     * ### Even Smaller Heading
     * -# Subtext.
    */

    function appendMarkdownInline(parent, text, stopToken = '') {
        const tokens = [
            { marker: '***', tag: 'strong', className: 'fbg-toast-bold-italic' },
            { marker: '**', tag: 'strong' },
            { marker: '__', tag: 'span', className: 'fbg-toast-underline' },
            { marker: '--', tag: 's' },
            { marker: '*', tag: 'em' },
        ];
        let index = 0;

        while (index < text.length) {
            if (stopToken && text.startsWith(stopToken, index)) {
                return index + stopToken.length;
            }

            const token = tokens.find((candidate) => text.startsWith(candidate.marker, index));

            if (!token) {
                const nextMarkerIndex = tokens
                    .map((candidate) => text.indexOf(candidate.marker, index + 1))
                    .filter((markerIndex) => markerIndex !== -1)
                    .sort((a, b) => a - b)[0] ?? text.length;

                parent.appendChild(document.createTextNode(text.slice(index, nextMarkerIndex)));
                index = nextMarkerIndex;
                continue;
            }

            const closingIndex = text.indexOf(token.marker, index + token.marker.length);

            if (closingIndex === -1) {
                parent.appendChild(document.createTextNode(token.marker));
                index += token.marker.length;
                continue;
            }

            const formattedEl = document.createElement(token.tag);

            if (token.className) {
                formattedEl.className = token.className;
            }

            appendMarkdownInline(formattedEl, text.slice(index + token.marker.length, closingIndex));
            parent.appendChild(formattedEl);
            index = closingIndex + token.marker.length;
        }

        return index;
    }

    function appendMarkdownLine(parent, line) {
        const lineRules = [
            { pattern: /^###\s+(.+)$/, className: 'fbg-toast-heading fbg-toast-heading-3' },
            { pattern: /^##\s+(.+)$/, className: 'fbg-toast-heading fbg-toast-heading-2' },
            { pattern: /^#\s+(.+)$/, className: 'fbg-toast-heading fbg-toast-heading-1' },
            { pattern: /^-#\s+(.+)$/, className: 'fbg-toast-subtext' },
        ];
        const rule = lineRules.find((candidate) => candidate.pattern.test(line));
        const lineEl = document.createElement('span');
        const content = rule ? line.replace(rule.pattern, '$1') : line;

        lineEl.className = rule ? rule.className : 'fbg-toast-line';
        appendMarkdownInline(lineEl, content);
        parent.appendChild(lineEl);
    }

    function appendMarkdownMessage(parent, message) {
        String(message).replace(/\\n/g, '\n').split(/\r?\n/).forEach((line) => {
            appendMarkdownLine(parent, line);
        });
    }

    function showToast(messageOrOptions, type = 'info', duration) {
        const options = normalizeOptions(messageOrOptions, type, duration);

        if (!options.message && !options.title) return null;

        const toast = document.createElement('section');
        toast.className = `fbg-toast fbg-toast-${options.type}`;
        toast.setAttribute('role', options.type === 'error' ? 'alert' : 'status');

        const content = document.createElement('div');
        content.className = 'fbg-toast-content';

        if (options.title) {
            const titleEl = document.createElement('div');
            titleEl.className = 'fbg-toast-title';
            appendMarkdownMessage(titleEl, options.title);
            content.appendChild(titleEl);
        }

        if (options.message) {
            const messageEl = document.createElement('div');
            messageEl.className = 'fbg-toast-message';
            appendMarkdownMessage(messageEl, options.message);
            content.appendChild(messageEl);
        }

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'fbg-toast-close';
        closeButton.setAttribute('aria-label', 'Dismiss notification');
        closeButton.innerHTML = '&times;';
        closeButton.addEventListener('click', () => dismissToast(toast));

        toast._fbgPersistent = options.persistent;
        toast._fbgRemaining = Math.max(1000, options.duration);
        toast._fbgStartedAt = 0;
        toast._fbgTimer = null;

        toast.append(content, closeButton);
        getContainer().appendChild(toast);

        window.requestAnimationFrame(() => toast.classList.add('is-visible'));
        startDismissTimer(toast);

        toast.addEventListener('mouseenter', () => pauseDismissTimer(toast));
        toast.addEventListener('mouseleave', () => startDismissTimer(toast));

        return toast;
    }

    window.FBGToast = showToast;
})();
