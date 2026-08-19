(() => {
    let modal = null;
    let titleEl = null;
    let messageEl = null;
    let iconEl = null;
    let confirmButton = null;
    let cancelButton = null;
    let closeButton = null;
    let activeResolve = null;
    let previousFocus = null;

    function ensureModal() {
        if (modal) return;

        modal = document.createElement('div');
        modal.className = 'fbg-confirm-backdrop';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="fbg-files-modal fbg-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="fbg-confirm-title">
                <button type="button" class="fbg-files-modal-close" data-fbg-confirm-close aria-label="Close confirmation dialog">
                    <i class="fas fa-times"></i>
                </button>

                <div class="fbg-files-modal-body">
                    <div class="fbg-confirm-icon" data-fbg-confirm-icon aria-hidden="true">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>

                    <h3 class="fbg-files-modal-title" id="fbg-confirm-title" data-fbg-confirm-title></h3>
                    <p class="fbg-confirm-message" data-fbg-confirm-message></p>

                    <div class="fbg-files-modal-actions">
                        <button type="button" class="btn fbg-neutral-button btn-sm" data-fbg-confirm-cancel>Cancel</button>
                        <button type="button" class="btn danger-action btn-sm" data-fbg-confirm-submit>Confirm</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        titleEl = modal.querySelector('[data-fbg-confirm-title]');
        messageEl = modal.querySelector('[data-fbg-confirm-message]');
        iconEl = modal.querySelector('[data-fbg-confirm-icon]');
        confirmButton = modal.querySelector('[data-fbg-confirm-submit]');
        cancelButton = modal.querySelector('[data-fbg-confirm-cancel]');
        closeButton = modal.querySelector('[data-fbg-confirm-close]');

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                resolveConfirm(false);
            }
        });

        confirmButton.addEventListener('click', () => resolveConfirm(true));
        cancelButton.addEventListener('click', () => resolveConfirm(false));
        closeButton.addEventListener('click', () => resolveConfirm(false));

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal && !modal.hidden) {
                resolveConfirm(false);
            }
        });
    }

    function resolveConfirm(value) {
        if (!modal || modal.hidden) return;

        modal.hidden = true;
        document.body.classList.remove('fbg-confirm-open');

        const resolver = activeResolve;
        activeResolve = null;

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        previousFocus = null;

        if (resolver) {
            resolver(value);
        }
    }

    function normalizeOptions(titleOrOptions, message, confirmLabel, cancelLabel, extraOptions = {}) {
        if (typeof titleOrOptions === 'object' && titleOrOptions !== null) {
            return titleOrOptions;
        }

        return {
            ...extraOptions,
            title: titleOrOptions,
            message,
            confirmLabel,
            cancelLabel
        };
    }

    window.FBGConfirm = function FBGConfirm(titleOrOptions = {}, message = '', confirmLabel = '', cancelLabel = '', extraOptions = {}) {
        ensureModal();

        if (activeResolve) {
            resolveConfirm(false);
        }

        const options = normalizeOptions(titleOrOptions, message, confirmLabel, cancelLabel, extraOptions);
        const variant = options.variant === 'danger' ? 'danger' : 'default';
        const iconClass = options.icon || (variant === 'danger' ? 'fas fa-triangle-exclamation' : 'fas fa-circle-question');

        titleEl.textContent = options.title || 'Are you sure?';
        messageEl.textContent = options.message || 'Please confirm this action.';
        iconEl.className = `fbg-confirm-icon is-${variant}`;
        iconEl.innerHTML = `<i class="${iconClass}"></i>`;
        confirmButton.textContent = options.confirmLabel || 'Confirm';
        cancelButton.textContent = options.cancelLabel || 'Cancel';
        confirmButton.className = `btn btn-sm ${variant === 'danger' ? 'danger-action' : 'fbg-primary-button'}`;

        previousFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('fbg-confirm-open');
        confirmButton.focus();

        return new Promise((resolve) => {
            activeResolve = resolve;
        });
    };
})();
