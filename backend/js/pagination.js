(() => {
    function clampPage(page, totalPages) {
        const safeTotalPages = Math.max(1, Number(totalPages) || 1);
        return Math.min(Math.max(Number(page) || 1, 1), safeTotalPages);
    }

    function pageItems(currentPage, totalPages, pageWindow = 2) {
        const safeTotalPages = Math.max(1, Number(totalPages) || 1);
        const safeCurrentPage = clampPage(currentPage, safeTotalPages);
        const pages = new Set([1, safeTotalPages]);
        const windowSize = Math.max(1, Number(pageWindow) || 2);

        for (let page = safeCurrentPage - windowSize; page <= safeCurrentPage + windowSize; page += 1) {
            if (page >= 1 && page <= safeTotalPages) {
                pages.add(page);
            }
        }

        const sortedPages = [...pages].sort((a, b) => a - b);
        const items = [];

        sortedPages.forEach((page, index) => {
            const previousPage = sortedPages[index - 1];

            if (index > 0 && page - previousPage > 1) {
                items.push({
                    type: 'ellipsis',
                    minPage: previousPage + 1,
                    maxPage: page - 1,
                });
            }

            items.push(page);
        });

        return items;
    }

    function openJumpInput(trigger, options = {}) {
        const totalPages = Math.max(1, Number(options.totalPages) || Number(trigger.dataset.totalPages) || 1);
        const minPage = clampPage(options.minPage || trigger.dataset.minPage || 1, totalPages);
        const maxPage = clampPage(options.maxPage || trigger.dataset.maxPage || totalPages, totalPages);
        const onPageChange = typeof options.onPageChange === 'function' ? options.onPageChange : null;
        const urlTemplate = options.urlTemplate || trigger.dataset.urlTemplate || '';
        const form = document.createElement('form');
        const input = document.createElement('input');

        form.className = 'fbg-pagination-jump-form';
        form.setAttribute('aria-label', `Jump to page between ${minPage} and ${maxPage}`);

        input.className = 'fbg-pagination-jump-input';
        input.type = 'number';
        input.min = String(minPage);
        input.max = String(maxPage);
        input.placeholder = `${minPage}-${maxPage}`;
        input.required = true;

        form.appendChild(input);
        trigger.replaceWith(form);
        input.focus();
        input.select();

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const requestedPage = clampPage(input.value, totalPages);
            const targetPage = Math.min(Math.max(requestedPage, minPage), maxPage);

            if (onPageChange) {
                onPageChange(targetPage);
                return;
            }

            if (urlTemplate) {
                window.location.href = urlTemplate.replace('__PAGE__', encodeURIComponent(String(targetPage)));
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                form.replaceWith(trigger);
                trigger.focus();
            }
        });

        input.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (document.activeElement !== input && form.isConnected) {
                    form.replaceWith(trigger);
                }
            }, 120);
        });
    }

    function renderPageNumbers(container, options = {}) {
        if (!container) return;

        const currentPage = clampPage(options.currentPage, options.totalPages);
        const totalPages = Math.max(1, Number(options.totalPages) || 1);
        const onPageChange = typeof options.onPageChange === 'function' ? options.onPageChange : () => {};
        const buttonClass = options.buttonClass || 'fbg-pagination-page';
        const pageWindow = options.pageWindow || 2;

        container.innerHTML = '';

        if (totalPages <= 1) {
            return;
        }

        pageItems(currentPage, totalPages, pageWindow).forEach((item) => {
            if (item?.type === 'ellipsis') {
                const ellipsis = document.createElement('button');
                ellipsis.type = 'button';
                ellipsis.className = 'fbg-pagination-ellipsis';
                ellipsis.setAttribute('aria-label', `Jump to page between ${item.minPage} and ${item.maxPage}`);
                ellipsis.textContent = '...';
                ellipsis.addEventListener('click', () => openJumpInput(ellipsis, {
                    minPage: item.minPage,
                    maxPage: item.maxPage,
                    totalPages,
                    onPageChange,
                }));
                container.appendChild(ellipsis);
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = buttonClass;
            button.textContent = String(item);
            button.setAttribute('aria-label', `Go to page ${item}`);

            if (item === currentPage) {
                button.classList.add('is-active');
                button.disabled = true;
                button.setAttribute('aria-current', 'page');
            } else {
                button.addEventListener('click', () => onPageChange(item));
            }

            container.appendChild(button);
        });
    }

    window.FBGPagination = {
        clampPage,
        openJumpInput,
        pageItems,
        renderPageNumbers,
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-fbg-pagination-jump]');
        if (!trigger) return;

        openJumpInput(trigger);
    });
})();
