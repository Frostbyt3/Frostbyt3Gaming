document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.fbg-dashboard-alert.is-visible');

    alerts.forEach(alert => {
        setTimeout(() => {
            alert.classList.remove('is-visible');
            alert.classList.add('is-hiding');

            setTimeout(() => {
                alert.remove();
            }, 400);
        }, 3000);
    });

    const sortableBody = document.querySelector('#service-cards-sortable');
    const reorderForm = document.querySelector('#service-cards-reorder-form');

    if (!sortableBody || !reorderForm) {
        return;
    }

    let draggedRow = null;

    const getRows = () => Array.from(sortableBody.querySelectorAll('tr[data-card-id]'));

    const updateDisplayedOrderNumbers = () => {
        getRows().forEach((row, index) => {
            const orderValue = row.querySelector('.fbg-service-card-order-value');
            if (orderValue) {
                orderValue.textContent = String(index + 1);
            }
        });
    };

    const submitNewOrder = () => {
        reorderForm.querySelectorAll('input[name="ordered_ids[]"]').forEach(input => input.remove());

        getRows().forEach((row) => {
            const cardId = row.getAttribute('data-card-id');
            if (!cardId) {
                return;
            }

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ordered_ids[]';
            input.value = cardId;
            reorderForm.appendChild(input);
        });

        reorderForm.submit();
    };

    getRows().forEach((row) => {
        row.addEventListener('dragstart', () => {
            draggedRow = row;
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
});