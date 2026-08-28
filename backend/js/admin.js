document.addEventListener('DOMContentLoaded', () => {
    const initSortableTable = (sortableBody, reorderForm, idAttribute, orderSelector) => {
        if (!sortableBody || !reorderForm) {
            return;
        }

        let draggedRow = null;

        const getRows = () => Array.from(sortableBody.querySelectorAll(`tr[${idAttribute}]`));

        const updateDisplayedOrderNumbers = () => {
            getRows().forEach((row, index) => {
                const orderValue = row.querySelector(orderSelector);
                if (orderValue) {
                    orderValue.textContent = String(index + 1);
                }
            });
        };

        const submitNewOrder = () => {
            reorderForm.querySelectorAll('input[name="ordered_ids[]"]').forEach(input => input.remove());

            getRows().forEach((row) => {
                const itemId = row.getAttribute(idAttribute);
                if (!itemId) {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ordered_ids[]';
                input.value = itemId;
                reorderForm.appendChild(input);
            });

            reorderForm.submit();
        };

        getRows().forEach((row) => {
            row.addEventListener('dragstart', (event) => {
                draggedRow = row;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', row.getAttribute(idAttribute) || '');
                row.classList.add('is-dragging');
            });

            row.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                getRows().forEach(r => r.classList.remove('is-drag-over'));
                draggedRow = null;
                updateDisplayedOrderNumbers();
            });

            row.addEventListener('dragover', (event) => {
                event.preventDefault();

                if (!draggedRow || draggedRow === row) {
                    return;
                }

                const rect = row.getBoundingClientRect();
                const offset = event.clientY - rect.top;
                const halfway = rect.height / 2;

                getRows().forEach(r => {
                    if (r !== row) {
                        r.classList.remove('is-drag-over');
                    }
                });

                row.classList.add('is-drag-over');

                if (offset < halfway) {
                    sortableBody.insertBefore(draggedRow, row);
                } else {
                    sortableBody.insertBefore(draggedRow, row.nextSibling);
                }

                updateDisplayedOrderNumbers();
            });

            row.addEventListener('drop', (event) => {
                event.preventDefault();
                row.classList.remove('is-drag-over');
                updateDisplayedOrderNumbers();
                submitNewOrder();
            });
        });

        updateDisplayedOrderNumbers();
    };

    initSortableTable(
        document.querySelector('#service-cards-sortable'),
        document.querySelector('#service-cards-reorder-form'),
        'data-card-id',
        '.fbg-service-card-order-value'
    );

    document.querySelectorAll('[data-fbg-sortable-body]').forEach((sortableBody) => {
        const formId = sortableBody.getAttribute('data-fbg-sort-form');
        const reorderForm = formId ? document.getElementById(formId) : null;

        initSortableTable(sortableBody, reorderForm, 'data-sort-id', '[data-fbg-sort-order-value]');
    });
});
