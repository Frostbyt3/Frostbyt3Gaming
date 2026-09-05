(() => {
    let backdrop = null;
    let modal = null;
    let previousFocus = null;

    const money = (value, currency = 'USD') => {
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }

        const numeric = Number(value || 0);
        return `${numeric.toFixed(2)} ${currency}`;
    };

    const cleanText = (value, fallback = '') => {
        const text = String(value ?? '').trim();
        return text !== '' ? text : fallback;
    };

    const backgroundMap = {
        minecraft: '/backend/img/backgrounds/minecraft.png',
    };

    const safeBackgroundUrl = (url) => {
        const path = cleanText(url).replace(/["\\]/g, '');

        if (path === '') {
            return '';
        }

        if (path.startsWith('/backend/img/backgrounds/')) {
            return path;
        }

        try {
            const parsed = new URL(path, window.location.origin);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return parsed.href;
            }
        } catch (error) {
            return '';
        }

        return '';
    };

    const resolveBackground = (options) => {
        const explicitUrl = safeBackgroundUrl(options.backgroundImage);

        if (explicitUrl !== '') {
            return explicitUrl;
        }

        const haystack = [
            options.backgroundKey,
            options.eggName,
            options.gameName,
            options.planName,
            ...(Array.isArray(options.details) ? options.details.map((row) => row?.value) : []),
        ]
            .map((value) => cleanText(value).toLowerCase())
            .join(' ');

        if (haystack.includes('minecraft')) {
            return backgroundMap.minecraft;
        }

        return '';
    };

    const clearChildren = (element) => {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    };

    const makeElement = (tag, className = '', text = '') => {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== '') {
            element.textContent = text;
        }

        return element;
    };

    const makeRow = ({ label = '', value = '', total = false } = {}) => {
        const row = makeElement('div', 'fbg-purchase-confirmation-row' + (total ? ' is-total' : ''));
        row.appendChild(makeElement('span', '', cleanText(label, '-')));
        row.appendChild(makeElement('strong', '', cleanText(value, '-')));

        return row;
    };

    const appendRows = (container, rows = []) => {
        clearChildren(container);

        rows
            .filter((row) => row && cleanText(row.label) !== '')
            .forEach((row) => {
                container.appendChild(makeRow(row));
            });
    };

    const closeModal = () => {
        if (!backdrop) return;

        backdrop.hidden = true;
        document.body.classList.remove('fbg-purchase-confirmation-open');

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    };

    const createAction = (action) => {
        if (action.close) {
            const button = makeElement('button', 'btn btn-sm ' + (action.primary ? 'fbg-primary-button' : 'fbg-secondary-button'), cleanText(action.label, 'Close'));
            button.type = 'button';
            button.addEventListener('click', closeModal);
            return button;
        }

        const link = makeElement('a', 'btn btn-sm ' + (action.primary ? 'fbg-primary-button' : 'fbg-secondary-button'), cleanText(action.label, 'Continue'));
        link.href = cleanText(action.url, '#');
        return link;
    };

    const ensureModal = () => {
        if (backdrop && modal) {
            return;
        }

        backdrop = makeElement('div', 'fbg-purchase-confirmation-backdrop');
        backdrop.hidden = true;
        backdrop.setAttribute('role', 'presentation');

        modal = makeElement('section', 'fbg-purchase-confirmation-modal');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'fbg-purchase-confirmation-title');

        const closeButton = makeElement('button', 'fbg-purchase-confirmation-close', 'X');
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', 'Close confirmation');
        closeButton.addEventListener('click', closeModal);

        const body = makeElement('div', 'fbg-purchase-confirmation-body');
        body.innerHTML = `
            <div class="fbg-purchase-confirmation-kicker"></div>
            <h2 id="fbg-purchase-confirmation-title"></h2>
            <p class="fbg-purchase-confirmation-message"></p>
            <div class="fbg-purchase-confirmation-section-label"></div>
            <div class="fbg-purchase-confirmation-details"></div>
            <div class="fbg-purchase-confirmation-totals"></div>
            <div class="fbg-purchase-confirmation-balance"></div>
            <p class="fbg-purchase-confirmation-note"></p>
            <p class="fbg-purchase-confirmation-receipt"></p>
            <div class="fbg-purchase-confirmation-actions"></div>
        `;

        modal.appendChild(closeButton);
        modal.appendChild(body);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && backdrop && !backdrop.hidden) {
                closeModal();
            }
        });
    };

    window.FBGPurchaseConfirmation = (options = {}) => {
        ensureModal();

        previousFocus = document.activeElement;

        const currency = cleanText(options.currency, 'USD');
        const title = cleanText(options.title, 'Thanks for your order!');
        const message = cleanText(options.message, 'Your purchase was completed successfully.');
        const label = cleanText(options.label, 'Order Complete');
        const details = Array.isArray(options.details) ? options.details : [];
        const totals = Array.isArray(options.totals) ? options.totals : [];
        const actions = Array.isArray(options.actions) ? options.actions : [];

        modal.dataset.type = cleanText(options.type, 'purchase');
        const backgroundUrl = resolveBackground(options);
        modal.classList.toggle('has-background', backgroundUrl !== '');

        if (backgroundUrl !== '') {
            modal.style.setProperty('--fbg-purchase-confirmation-background', `url("${backgroundUrl}")`);
        } else {
            modal.style.removeProperty('--fbg-purchase-confirmation-background');
        }

        modal.querySelector('.fbg-purchase-confirmation-kicker').textContent = label;
        modal.querySelector('#fbg-purchase-confirmation-title').textContent = title;
        modal.querySelector('.fbg-purchase-confirmation-message').textContent = message;
        modal.querySelector('.fbg-purchase-confirmation-section-label').textContent = label;

        appendRows(modal.querySelector('.fbg-purchase-confirmation-details'), details);
        appendRows(modal.querySelector('.fbg-purchase-confirmation-totals'), totals.map((row) => ({
            ...row,
            value: typeof row.value === 'undefined' ? money(row.amount, currency) : row.value,
        })));

        const balanceEl = modal.querySelector('.fbg-purchase-confirmation-balance');
        clearChildren(balanceEl);
        if (options.balance && cleanText(options.balance.label) !== '') {
            balanceEl.appendChild(makeRow(options.balance));
        }

        const noteEl = modal.querySelector('.fbg-purchase-confirmation-note');
        noteEl.textContent = cleanText(options.note);
        noteEl.hidden = noteEl.textContent === '';

        const receiptEl = modal.querySelector('.fbg-purchase-confirmation-receipt');
        clearChildren(receiptEl);

        if (options.receipt && cleanText(options.receipt.number) !== '') {
            receiptEl.appendChild(document.createTextNode(`Receipt ${cleanText(options.receipt.number)}`));

            if (cleanText(options.receipt.url) !== '') {
                receiptEl.appendChild(document.createTextNode(' · '));
                const receiptLink = makeElement('a', '', 'View Receipt');
                receiptLink.href = cleanText(options.receipt.url);
                receiptEl.appendChild(receiptLink);
            }

            receiptEl.hidden = false;
        } else {
            receiptEl.hidden = true;
        }

        const actionsEl = modal.querySelector('.fbg-purchase-confirmation-actions');
        clearChildren(actionsEl);

        const normalizedActions = actions.length > 0
            ? actions
            : [{ label: 'Close', close: true, primary: true }];

        normalizedActions.forEach((action) => {
            actionsEl.appendChild(createAction(action));
        });

        backdrop.hidden = false;
        document.body.classList.add('fbg-purchase-confirmation-open');

        const focusTarget = actionsEl.querySelector('a, button') || modal.querySelector('.fbg-purchase-confirmation-close');
        if (focusTarget) {
            focusTarget.focus();
        }

        return {
            close: closeModal,
        };
    };
})();
