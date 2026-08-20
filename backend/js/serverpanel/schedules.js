(function () {
    const panel = document.querySelector('.fbg-schedules-panel');
    if (!panel) return;

    const serverId = panel.dataset.serverId || '';
    const baseUrl = panel.dataset.baseUrl || '';
    const csrfToken = panel.dataset.csrfToken || '';

    const modalRoot = document.getElementById('fbg-schedules-modal-root');

    const contentEl = document.getElementById('fbg-schedules-content');
    const messageEl = document.getElementById('schedules-message');

    const editModal = document.getElementById('schedule-edit-modal');
    const editForm = document.getElementById('schedule-edit-form');
    const editClose = document.getElementById('schedule-edit-close');
    const editCancel = document.getElementById('schedule-edit-cancel');
    const editSubmit = document.getElementById('schedule-edit-submit');
    const newScheduleButton = document.getElementById('new-schedule-button');
    const scheduleModalTitle = document.getElementById('schedule-modal-title');
    const scheduleModalDescription = document.getElementById('schedule-modal-description');
    const scheduleHeaderActions = document.getElementById('schedule-header-actions');

    const schedulePresetButtons = document.querySelectorAll('.schedule-preset-button');

    const scheduleCheatsheetToggle = document.getElementById('edit_show_cheatsheet');
    const scheduleCheatsheet = document.getElementById('schedule-cheatsheet');

    const taskEditModal = document.getElementById('task-edit-modal');
    const taskEditForm = document.getElementById('task-edit-form');
    const taskEditClose = document.getElementById('task-edit-close');
    const taskEditCancel = document.getElementById('task-edit-cancel');
    const taskEditSubmit = document.getElementById('task-edit-submit');
    const taskModalTitle = document.getElementById('task-modal-title');
    const taskModalDescription = document.getElementById('task-modal-description');

    const editFields = {
        scheduleId: document.getElementById('edit_schedule_id'),
        name: document.getElementById('edit_name'),
        minute: document.getElementById('edit_minute'),
        hour: document.getElementById('edit_hour'),
        dayOfMonth: document.getElementById('edit_day_of_month'),
        month: document.getElementById('edit_month'),
        dayOfWeek: document.getElementById('edit_day_of_week'),
        onlyWhenOnline: document.getElementById('edit_only_when_online'),
        isActive: document.getElementById('edit_is_active')
    };

    const taskEditFields = {
        scheduleId: document.getElementById('task_edit_schedule_id'),
        taskId: document.getElementById('task_edit_task_id'),
        action: document.getElementById('task_edit_action'),
        commandGroup: document.getElementById('task-command-group'),
        powerGroup: document.getElementById('task-power-group'),
        commandPayload: document.getElementById('task_edit_command_payload'),
        powerPayload: document.getElementById('task_edit_power_payload'),
        timeOffset: document.getElementById('task_edit_time_offset'),
        continueOnFailure: document.getElementById('task_edit_continue_on_failure')
    };

    const endpoints = {
        list: '/api/server/schedules/list.php?id=' + encodeURIComponent(serverId),
        view: '/api/server/schedules/view.php?id=' + encodeURIComponent(serverId) + '&schedule_id=',
        create: '/api/server/schedules/create.php',
        update: '/api/server/schedules/update.php',
        delete: '/api/server/schedules/delete.php',
        execute: '/api/server/schedules/execute.php',
        taskCreate: '/api/server/schedules/task-create.php',
        taskUpdate: '/api/server/schedules/task-update.php',
        taskDelete: '/api/server/schedules/task-delete.php'
    };

    let currentScheduleAttributes = null;
    let currentTasksById = {};
    let taskModalMode = 'edit';
    let scheduleModalMode = 'edit';
    let scheduleId = Number(panel.dataset.scheduleId || 0);

    function mountModalsToBody() {
        if (!modalRoot) return;
        if (modalRoot.parentElement !== document.body) {
            document.body.appendChild(modalRoot);
        }
    }

    async function confirmAction(title, description, confirmText = 'Confirm', cancelText = 'Cancel', options = {}) {
        if (typeof window.FBGConfirm === 'function') {
            return window.FBGConfirm(title, description, confirmText, cancelText, options);
        }

        console.warn('FBGConfirm is not available.');
        return false;
    }

    function syncScheduleHeaderActions() {
        if (!scheduleHeaderActions) return;
        scheduleHeaderActions.hidden = scheduleId > 0;
    }

    function applySchedulePreset(button) {
        if (!button) return;

        editFields.minute.value = button.dataset.minute || '*';
        editFields.hour.value = button.dataset.hour || '*';
        editFields.dayOfMonth.value = button.dataset.dayOfMonth || '*';
        editFields.month.value = button.dataset.month || '*';
        editFields.dayOfWeek.value = button.dataset.dayOfWeek || '*';
    }

    function syncScheduleCheatsheet() {
        if (!scheduleCheatsheet || !scheduleCheatsheetToggle) return;
        scheduleCheatsheet.hidden = !scheduleCheatsheetToggle.checked;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showMessage(message, isError = false) {
        if (!messageEl) return;

        messageEl.textContent = message;
        messageEl.className = 'fbg-dashboard-alert ' + (isError ? 'error' : 'success');
        messageEl.style.display = 'block';

        clearTimeout(showMessage._timer);
        showMessage._timer = setTimeout(() => {
            messageEl.style.display = 'none';
        }, isError ? 7000 : 4000);
    }

    function formatDate(value) {
        if (!value) return 'Never';

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString();
    }

    function cronValues(attr) {
        return [
            attr?.cron?.minute ?? '*',
            attr?.cron?.hour ?? '*',
            attr?.cron?.day_of_month ?? '*',
            attr?.cron?.month ?? '*',
            attr?.cron?.day_of_week ?? '*'
        ];
    }

    function normalizeTaskAction(action) {
        const value = String(action || '').toLowerCase();
        if (value === 'command') return 'COMMAND';
        if (value === 'power') return 'POWER';
        return value ? value.toUpperCase() : 'TASK';
    }

    function normalizeTaskActionValue(action) {
        const value = String(action || '').toLowerCase();
        return value === 'power' ? 'power' : 'command';
    }

    function getTaskId(task) {
        const topLevelId = Number(task?.id ?? 0);
        if (topLevelId > 0) return topLevelId;

        const attributeId = Number(task?.attributes?.id ?? 0);
        if (attributeId > 0) return attributeId;

        return 0;
    }

    function buildTaskMap(tasks) {
        const map = {};

        tasks.forEach((task) => {
            const id = getTaskId(task);
            if (id > 0) {
                map[id] = task;
            }
        });

        currentTasksById = map;
    }

    function renderStatusBadge(attr) {
        const isProcessing = !!attr.is_processing;
        const isActive = !!attr.is_active;

        if (isProcessing) {
            return `
                <span class="fbg-status-badge processing">
                    Processing...
                </span>
            `;
        }

        return `
            <span class="fbg-status-badge ${isActive ? 'running' : 'offline'}">
                ${isActive ? 'Active' : 'Inactive'}
            </span>
        `;
    }

    function renderCronGrid(attr) {
        const cron = cronValues(attr);

        return `
            <div class="fbg-schedule-cron-grid">
                <div class="fbg-schedule-cron-box">
                    <span class="fbg-meta-label">Minute</span>
                    <div class="fbg-meta-value">${escapeHtml(cron[0])}</div>
                </div>
                <div class="fbg-schedule-cron-box">
                    <span class="fbg-meta-label">Hour</span>
                    <div class="fbg-meta-value">${escapeHtml(cron[1])}</div>
                </div>
                <div class="fbg-schedule-cron-box">
                    <span class="fbg-meta-label">Day (Month)</span>
                    <div class="fbg-meta-value">${escapeHtml(cron[2])}</div>
                </div>
                <div class="fbg-schedule-cron-box">
                    <span class="fbg-meta-label">Month</span>
                    <div class="fbg-meta-value">${escapeHtml(cron[3])}</div>
                </div>
                <div class="fbg-schedule-cron-box">
                    <span class="fbg-meta-label">Day (Week)</span>
                    <div class="fbg-meta-value">${escapeHtml(cron[4])}</div>
                </div>
            </div>
        `;
    }

    function renderScheduleList(items) {
        if (!Array.isArray(items) || !items.length) {
            contentEl.innerHTML = `
                <div class="fbg-schedules-empty">
                    No schedules have been created yet.
                </div>
            `;
            return;
        }

        contentEl.innerHTML = `
            <div class="fbg-schedule-list">
                ${items.map((item) => {
                    const attr = item.attributes || {};
                    const scheduleListId = Number(attr.id || item.id || 0);
                    const href = baseUrl + '&schedule=' + encodeURIComponent(String(scheduleListId));

                    return `
                        <article class="fbg-schedule-list-card" data-schedule-id="${scheduleListId}">
                            <div class="fbg-schedule-list-top">
                                <div class="fbg-schedule-title-wrap">
                                    <h3 class="fbg-schedule-list-heading">
                                        <a class="fbg-schedule-title-link" href="${href}">
                                            ${escapeHtml(attr.name || 'Unnamed Schedule')}
                                        </a>
                                    </h3>

                                    <div class="fbg-schedule-submeta">
                                        ${renderStatusBadge(attr)}
                                        <span><strong>Last run:</strong> ${escapeHtml(formatDate(attr.last_run_at || ''))}</span>
                                        <span><strong>Next run:</strong> ${escapeHtml(formatDate(attr.next_run_at || ''))}</span>
                                    </div>
                                </div>

                                <div class="fbg-schedule-list-actions">
                                    <button
                                        type="button"
                                        class="btn fbg-neutral-button btn-sm schedule-list-edit-button"
                                        data-schedule-id="${scheduleListId}"
                                    >
                                        Edit
                                    </button>
                                </div>
                            </div>

                            <a class="fbg-schedule-body-link" href="${href}">
                                ${renderCronGrid(attr)}

                                <div class="fbg-schedule-footer">
                                    <span><strong>Only when online:</strong> ${attr.only_when_online ? 'Yes' : 'No'}</span>
                                    <span><strong>Processing:</strong> ${attr.is_processing ? 'Yes' : 'No'}</span>
                                </div>
                            </a>
                        </article>
                    `;
                }).join('')}
            </div>
        `;

        bindScheduleListActions(items);
    }

    function bindScheduleListActions(items) {
        const scheduleMap = {};

        items.forEach((item) => {
            const attr = item.attributes || {};
            const id = Number(attr.id || item.id || 0);

            if (id > 0) {
                scheduleMap[id] = attr;
            }
        });

        const editButtons = contentEl.querySelectorAll('.schedule-list-edit-button');

        editButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const id = Number(button.dataset.scheduleId || 0);
                const attr = scheduleMap[id] || null;

                if (!attr) {
                    showMessage('Could not load that schedule for editing.', true);
                    return;
                }

                openEditModal(attr);
            });
        });
    }

    function renderTask(task) {
        const attr = task.attributes || {};
        const payload = String(attr.payload || '').trim();
        const taskId = getTaskId(task);

        return `
            <article class="fbg-schedule-task-card">
                <div class="fbg-schedule-task-inner">
                    <div class="fbg-schedule-task-top">
                        <div>
                            <div class="fbg-schedule-task-action">${escapeHtml(normalizeTaskAction(attr.action || 'task'))}</div>
                            ${payload !== '' ? `<code class="fbg-schedule-task-payload">${escapeHtml(payload)}</code>` : ''}
                        </div>

                        <div class="fbg-task-pills">
                            <span class="fbg-task-pill">Offset: ${Number(attr.time_offset || 0)}s</span>
                            ${attr.continue_on_failure ? '<span class="fbg-task-pill">Continues on Failure</span>' : ''}
                            ${attr.sequence_id ? `<span class="fbg-task-pill">Task #${Number(attr.sequence_id)}</span>` : ''}
                        </div>
                    </div>

                    <div class="fbg-schedule-task-actions">
                        <button
                            type="button"
                            class="btn btn-sm task-edit-button"
                            data-task-id="${taskId}"
                        >
                            Edit
                        </button>

                        <button
                            type="button"
                            class="btn btn-delete btn-sm task-delete-button"
                            data-task-id="${taskId}"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </article>
        `;
    }

    function bindScheduleDetailActions(attr) {
        const scheduleEditButton = document.getElementById('edit-schedule-button');
        if (scheduleEditButton) {
            scheduleEditButton.addEventListener('click', () => {
                openEditModal(attr);
            });
        }

        const newTaskButton = document.getElementById('new-task-button');
        if (newTaskButton) {
            newTaskButton.addEventListener('click', () => {
                openCreateTaskModal();
            });
        }

        const runScheduleButton = document.getElementById('run-schedule-button');
        if (runScheduleButton) {
            runScheduleButton.addEventListener('click', async () => {
                await runSchedule(runScheduleButton);
            });
        }

        const deleteScheduleButton = document.getElementById('delete-schedule-button');
        if (deleteScheduleButton) {
            deleteScheduleButton.addEventListener('click', async () => {
                const confirmed = await confirmAction(
                    'Delete Schedule?',
                    'Any tasks inside this schedule will also be removed.',
                    'Delete',
                    'Cancel',
                    { variant: 'danger' }
                );

                if (!confirmed) {
                    return;
                }

                await deleteSchedule(deleteScheduleButton);
            });
        }

        const taskEditButtons = contentEl.querySelectorAll('.task-edit-button');
        taskEditButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const taskId = Number(button.dataset.taskId || 0);

                if (taskId <= 0) {
                    showMessage('Could not determine that task ID.', true);
                    return;
                }

                openEditTaskModal(taskId);
            });
        });

        const taskDeleteButtons = contentEl.querySelectorAll('.task-delete-button');
        taskDeleteButtons.forEach((button) => {
            button.addEventListener('click', async () => {
                const taskId = Number(button.dataset.taskId || 0);

                if (taskId <= 0) {
                    showMessage('Could not determine that task ID.', true);
                    return;
                }

                const confirmed = await confirmAction(
                    'Delete Task?',
                    'Delete this task? This cannot be undone.',
                    'Delete',
                    'Cancel',
                    { variant: 'danger' }
                );
                if (!confirmed) {
                    return;
                }

                await deleteTask(taskId, button);
            });
        });
    }

    function renderScheduleDetail(attr) {
        const tasks = Array.isArray(attr?.relationships?.tasks?.data)
            ? attr.relationships.tasks.data
            : [];

        buildTaskMap(tasks);

        contentEl.innerHTML = `
            <a class="btn fbg-neutral-button fbg-schedule-back-link" href="${baseUrl}">
                <i class="fas fa-arrow-left"></i>
                Back to Schedules
            </a>

            <article class="fbg-schedule-detail-card">
                <div class="fbg-schedule-detail-inner">
                    <div class="fbg-schedule-detail-top">
                        <div class="fbg-schedule-detail-title">
                            <h3>${escapeHtml(attr.name || 'Unnamed Schedule')}</h3>
                            <div class="fbg-schedule-submeta">
                                ${renderStatusBadge(attr)}
                                <span><strong>Last run:</strong> ${escapeHtml(formatDate(attr.last_run_at || ''))}</span>
                                <span><strong>Next run:</strong> ${escapeHtml(formatDate(attr.next_run_at || ''))}</span>
                            </div>
                        </div>

                        <div class="fbg-schedule-detail-actions">
                            <button
                                type="button"
                                class="btn fbg-neutral-button btn-sm"
                                id="edit-schedule-button"
                            >
                                Edit
                            </button>

                            <button
                                type="button"
                                class="btn fbg-primary-button btn-sm"
                                id="new-task-button"
                            >
                                New Task
                            </button>

                            <button
                                type="button"
                                class="btn fbg-neutral-button btn-sm"
                                id="run-schedule-button"
                                ${attr.is_processing ? 'disabled' : ''}
                                title="${attr.is_processing ? 'This schedule is currently processing.' : 'Run this schedule now.'}"
                            >
                                ${attr.is_processing ? 'Running...' : 'Run Now'}
                            </button>

                            <button
                                type="button"
                                class="btn btn-delete btn-sm"
                                id="delete-schedule-button"
                            >
                                Delete
                            </button>
                        </div>
                    </div>

                    ${renderCronGrid(attr)}

                    <div class="fbg-schedule-footer">
                        <span><strong>Only when online:</strong> ${attr.only_when_online ? 'Yes' : 'No'}</span>
                        <span><strong>Enabled:</strong> ${attr.is_active ? 'Yes' : 'No'}</span>
                        <span><strong>Processing:</strong> ${attr.is_processing ? 'Yes' : 'No'}</span>
                    </div>
                </div>
            </article>

            <div class="fbg-schedule-tasks-wrap">
                ${
                    tasks.length
                        ? tasks.map(renderTask).join('')
                        : '<div class="fbg-schedule-empty-tasks">This schedule does not have any tasks yet.</div>'
                }
            </div>
        `;

        bindScheduleDetailActions(attr);
    }

    function openEditModal(attr) {
        currentScheduleAttributes = attr || null;

        if (!editModal || !editForm || !currentScheduleAttributes) {
            return;
        }

        scheduleModalMode = 'edit';

        editFields.scheduleId.value = String(currentScheduleAttributes.id || '');
        editFields.name.value = currentScheduleAttributes.name || '';
        editFields.minute.value = currentScheduleAttributes?.cron?.minute ?? '*';
        editFields.hour.value = currentScheduleAttributes?.cron?.hour ?? '*';
        editFields.dayOfMonth.value = currentScheduleAttributes?.cron?.day_of_month ?? '*';
        editFields.month.value = currentScheduleAttributes?.cron?.month ?? '*';
        editFields.dayOfWeek.value = currentScheduleAttributes?.cron?.day_of_week ?? '*';
        editFields.onlyWhenOnline.checked = !!currentScheduleAttributes.only_when_online;
        editFields.isActive.checked = !!currentScheduleAttributes.is_active;

        if (scheduleModalTitle) {
            scheduleModalTitle.textContent = 'Edit Schedule';
        }

        if (scheduleModalDescription) {
            scheduleModalDescription.textContent = 'Update this schedule\'s name, cron timing, and status settings.';
        }

        if (editSubmit) {
            editSubmit.textContent = 'Save Changes';
        }

        if (scheduleCheatsheetToggle) {
            scheduleCheatsheetToggle.checked = false;
        }

        syncScheduleCheatsheet();

        editModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        setTimeout(() => {
            editFields.name.focus();
            editFields.name.select();
        }, 0);
    }

    function openCreateScheduleModal() {
        if (!editModal || !editForm) {
            return;
        }

        currentScheduleAttributes = null;
        scheduleModalMode = 'create';

        editFields.scheduleId.value = '';
        editFields.name.value = '';
        editFields.minute.value = '*';
        editFields.hour.value = '*';
        editFields.dayOfMonth.value = '*';
        editFields.month.value = '*';
        editFields.dayOfWeek.value = '*';
        editFields.onlyWhenOnline.checked = false;
        editFields.isActive.checked = true;

        if (scheduleModalTitle) {
            scheduleModalTitle.textContent = 'Create Schedule';
        }

        if (scheduleModalDescription) {
            scheduleModalDescription.textContent = 'Create a new schedule with cron timing and server availability settings.';
        }

        if (editSubmit) {
            editSubmit.textContent = 'Create Schedule';
        }

        if (scheduleCheatsheetToggle) {
            scheduleCheatsheetToggle.checked = false;
        }

        syncScheduleCheatsheet();

        editModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        setTimeout(() => {
            editFields.name.focus();
        }, 0);
    }

    function closeEditModal() {
        if (!editModal) return;
        editModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');
    }

    function syncTaskPayloadUi(action, payload = '') {
        const normalizedAction = normalizeTaskActionValue(action);
        const normalizedPayload = String(payload || '').trim().toLowerCase();

        if (normalizedAction === 'power') {
            taskEditFields.commandGroup.hidden = true;
            taskEditFields.powerGroup.hidden = false;
            taskEditFields.commandPayload.value = '';

            const allowedPowerValues = ['start', 'restart', 'stop', 'kill'];
            taskEditFields.powerPayload.value = allowedPowerValues.includes(normalizedPayload)
                ? normalizedPayload
                : 'restart';
        } else {
            taskEditFields.commandGroup.hidden = false;
            taskEditFields.powerGroup.hidden = true;
            taskEditFields.commandPayload.value = String(payload || '');
        }
    }

    function getTaskPayloadForSubmit() {
        const action = normalizeTaskActionValue(taskEditFields.action.value);

        if (action === 'power') {
            return String(taskEditFields.powerPayload.value || 'restart').trim();
        }

        return String(taskEditFields.commandPayload.value || '').trim();
    }

    function openCreateTaskModal() {
        taskModalMode = 'create';

        taskEditFields.scheduleId.value = String(scheduleId);
        taskEditFields.taskId.value = '';
        taskEditFields.action.value = 'command';
        taskEditFields.timeOffset.value = '0';
        taskEditFields.continueOnFailure.checked = false;
        taskEditFields.commandPayload.value = '';
        taskEditFields.powerPayload.value = 'restart';

        if (taskModalTitle) {
            taskModalTitle.textContent = 'Create Task';
        }

        if (taskModalDescription) {
            taskModalDescription.textContent = 'Add a new task to this schedule.';
        }

        if (taskEditSubmit) {
            taskEditSubmit.textContent = 'Create Task';
        }

        syncTaskPayloadUi('command', '');

        taskEditModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        setTimeout(() => {
            taskEditFields.commandPayload.focus();
        }, 0);
    }

    function openEditTaskModal(taskId) {
        const task = currentTasksById[taskId];
        const attr = task?.attributes || null;

        if (!taskEditModal || !attr) {
            showMessage('Could not load that task from the current schedule data.', true);
            return;
        }

        taskModalMode = 'edit';

        taskEditFields.scheduleId.value = String(scheduleId);
        taskEditFields.taskId.value = String(taskId);
        taskEditFields.action.value = normalizeTaskActionValue(attr.action || 'command');
        taskEditFields.timeOffset.value = String(Number(attr.time_offset || 0));
        taskEditFields.continueOnFailure.checked = !!attr.continue_on_failure;

        if (taskModalTitle) {
            taskModalTitle.textContent = 'Edit Task';
        }

        if (taskModalDescription) {
            taskModalDescription.textContent = 'Update the task action, payload, offset, and failure behavior.';
        }

        if (taskEditSubmit) {
            taskEditSubmit.textContent = 'Save Changes';
        }

        syncTaskPayloadUi(attr.action || 'command', attr.payload || '');

        taskEditModal.hidden = false;
        document.body.classList.add('fbg-modal-open');

        setTimeout(() => {
            if (normalizeTaskActionValue(taskEditFields.action.value) === 'power') {
                taskEditFields.powerPayload.focus();
            } else {
                taskEditFields.commandPayload.focus();
            }
        }, 0);
    }

    function closeTaskEditModal() {
        if (!taskEditModal) return;
        taskEditModal.hidden = true;
        document.body.classList.remove('fbg-modal-open');
    }

    async function parseJsonResponse(response, invalidJsonMessage) {
        const rawText = await response.text();

        let data;
        try {
            data = JSON.parse(rawText);
        } catch (error) {
            console.error(invalidJsonMessage, rawText);
            throw new Error('Endpoint returned invalid JSON. Check PHP logs.');
        }

        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Request failed.');
        }

        return data;
    }

    async function fetchJson(url, options = {}, invalidJsonMessage = 'Invalid JSON response:') {
        const response = await fetch(url, {
            cache: 'no-store',
            ...options,
            headers: {
                'Accept': 'application/json',
                ...(options.headers || {})
            }
        });

        return parseJsonResponse(response, invalidJsonMessage);
    }

    async function withButtonBusyState(buttonEl, busyText, callback) {
        if (!buttonEl) {
            return callback();
        }

        if (buttonEl.disabled) {
            return;
        }

        const originalText = buttonEl.textContent;

        try {
            buttonEl.disabled = true;
            buttonEl.textContent = busyText;
            return await callback();
        } finally {
            buttonEl.disabled = false;
            buttonEl.textContent = originalText;
        }
    }

    async function submitEditForm(event) {
        event.preventDefault();

        const isCreateMode = scheduleModalMode === 'create';
        const endpoint = isCreateMode ? endpoints.create : endpoints.update;

        const payload = {
            csrf_token: csrfToken,
            id: serverId,
            name: editFields.name.value.trim(),
            minute: editFields.minute.value.trim() || '*',
            hour: editFields.hour.value.trim() || '*',
            day_of_month: editFields.dayOfMonth.value.trim() || '*',
            month: editFields.month.value.trim() || '*',
            day_of_week: editFields.dayOfWeek.value.trim() || '*',
            only_when_online: editFields.onlyWhenOnline.checked,
            is_active: editFields.isActive.checked
        };

        if (!isCreateMode) {
            payload.schedule_id = editFields.scheduleId.value;
        }

        try {
            await withButtonBusyState(
                editSubmit,
                isCreateMode ? 'Creating...' : 'Saving...',
                async () => {
                    const data = await fetchJson(
                        endpoint,
                        {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        },
                        'Invalid JSON from schedule endpoint:'
                    );

                    closeEditModal();
                    showMessage(data.message || (isCreateMode ? 'Schedule created successfully.' : 'Schedule updated successfully.'));

                    if (isCreateMode) {
                        const createdScheduleId = Number(data?.item?.id || 0);

                        if (createdScheduleId > 0) {
                            window.location.href = baseUrl + '&schedule=' + encodeURIComponent(String(createdScheduleId));
                            return;
                        }
                    }

                    if (scheduleId > 0) {
                        await loadScheduleDetail(scheduleId);
                    } else {
                        await loadScheduleList();
                    }
                }
            );
        } catch (error) {
            console.error(error);
            showMessage(error.message || (isCreateMode ? 'Failed to create schedule.' : 'Failed to update schedule.'), true);
        }
    }

    async function submitTaskEditForm(event) {
        event.preventDefault();

        const payload = getTaskPayloadForSubmit();
        if (payload === '') {
            showMessage('Please enter a command or select a power action.', true);
            return;
        }

        const isCreateMode = taskModalMode === 'create';
        const endpoint = isCreateMode ? endpoints.taskCreate : endpoints.taskUpdate;

        const taskPayload = {
            csrf_token: csrfToken,
            id: serverId,
            schedule_id: taskEditFields.scheduleId.value,
            action: normalizeTaskActionValue(taskEditFields.action.value),
            payload: payload,
            time_offset: taskEditFields.timeOffset.value || '0',
            continue_on_failure: taskEditFields.continueOnFailure.checked
        };

        if (!isCreateMode) {
            taskPayload.task_id = taskEditFields.taskId.value;
        }

        try {
            await withButtonBusyState(
                taskEditSubmit,
                isCreateMode ? 'Creating...' : 'Saving...',
                async () => {
                    const data = await fetchJson(
                        endpoint,
                        {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(taskPayload)
                        },
                        'Invalid JSON from task endpoint:'
                    );

                    closeTaskEditModal();
                    showMessage(data.message || (isCreateMode ? 'Task created successfully.' : 'Task updated successfully.'));

                    if (scheduleId > 0) {
                        await loadScheduleDetail(scheduleId);
                    }
                }
            );
        } catch (error) {
            console.error(error);
            showMessage(error.message || (isCreateMode ? 'Failed to create task.' : 'Failed to update task.'), true);
        }
    }

    async function runSchedule(buttonEl = null) {
        if (scheduleId <= 0) {
            showMessage('Could not determine that schedule ID.', true);
            return;
        }

        const payload = {
            csrf_token: csrfToken,
            id: serverId,
            schedule_id: String(scheduleId)
        };

        try {
            await withButtonBusyState(buttonEl, 'Running...', async () => {
                const data = await fetchJson(
                    endpoints.execute,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    },
                    'Invalid JSON from schedule execute endpoint:'
                );

                showMessage(data.message || 'Schedule started successfully.');

                if (scheduleId > 0) {
                    await loadScheduleDetail(scheduleId);
                }
            });
        } catch (error) {
            console.error(error);
            showMessage(error.message || 'Failed to run schedule.', true);
        }
    }

    async function deleteSchedule(buttonEl = null) {
        if (scheduleId <= 0) {
            showMessage('Could not determine that schedule ID.', true);
            return;
        }

        const payload = {
            csrf_token: csrfToken,
            id: serverId,
            schedule_id: String(scheduleId)
        };

        try {
            await withButtonBusyState(buttonEl, 'Deleting...', async () => {
                const data = await fetchJson(
                    endpoints.delete,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    },
                    'Invalid JSON from schedule delete endpoint:'
                );

                showMessage(data.message || 'Schedule deleted successfully.');
                window.location.href = baseUrl;
            });
        } catch (error) {
            console.error(error);
            showMessage(error.message || 'Failed to delete schedule.', true);
        }
    }

    async function deleteTask(taskId, buttonEl = null) {
        const payload = {
            csrf_token: csrfToken,
            id: serverId,
            schedule_id: String(scheduleId),
            task_id: String(taskId)
        };

        try {
            await withButtonBusyState(buttonEl, 'Deleting...', async () => {
                const data = await fetchJson(
                    endpoints.taskDelete,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    },
                    'Invalid JSON from task delete endpoint:'
                );

                showMessage(data.message || 'Task deleted successfully.');

                if (scheduleId > 0) {
                    await loadScheduleDetail(scheduleId);
                } else {
                    await loadScheduleList();
                }
            });
        } catch (error) {
            console.error(error);
            showMessage(error.message || 'Failed to delete task.', true);
        }
    }

    async function loadScheduleList() {
        scheduleId = 0;
        syncScheduleHeaderActions();

        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading schedules...</div>';

        const data = await fetchJson(
            endpoints.list,
            {},
            'Invalid JSON from schedule list endpoint:'
        );

        renderScheduleList(Array.isArray(data.items) ? data.items : []);
    }

    async function loadScheduleDetail(id) {
        scheduleId = Number(id || 0);
        syncScheduleHeaderActions();

        contentEl.innerHTML = '<div class="fbg-schedules-loading">Loading schedule...</div>';

        const data = await fetchJson(
            endpoints.view + encodeURIComponent(String(id)),
            {},
            'Invalid JSON from schedule view endpoint:'
        );

        if (!data.item) {
            throw new Error('Failed to load schedule.');
        }

        renderScheduleDetail(data.item);
    }

    function bindGlobalModalEvents() {
        if (newScheduleButton) {
            newScheduleButton.addEventListener('click', () => {
                openCreateScheduleModal();
            });
        }

        if (schedulePresetButtons.length) {
            schedulePresetButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    applySchedulePreset(button);
                });
            });
        }

        if (scheduleCheatsheetToggle) {
            scheduleCheatsheetToggle.addEventListener('change', syncScheduleCheatsheet);
        }

        if (editClose) {
            editClose.addEventListener('click', closeEditModal);
        }

        if (editCancel) {
            editCancel.addEventListener('click', closeEditModal);
        }

        if (editModal) {
            editModal.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    closeEditModal();
                }
            });
        }

        if (editForm) {
            editForm.addEventListener('submit', submitEditForm);
        }

        if (taskEditClose) {
            taskEditClose.addEventListener('click', closeTaskEditModal);
        }

        if (taskEditCancel) {
            taskEditCancel.addEventListener('click', closeTaskEditModal);
        }

        if (taskEditModal) {
            taskEditModal.addEventListener('click', (event) => {
                if (event.target === taskEditModal) {
                    closeTaskEditModal();
                }
            });
        }

        if (taskEditForm) {
            taskEditForm.addEventListener('submit', submitTaskEditForm);
        }

        if (taskEditFields.action) {
            taskEditFields.action.addEventListener('change', () => {
                syncTaskPayloadUi(taskEditFields.action.value, '');
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;

            if (taskEditModal && !taskEditModal.hidden) {
                closeTaskEditModal();
                return;
            }

            if (editModal && !editModal.hidden) {
                closeEditModal();
            }
        });
    }

    async function init() {
        mountModalsToBody();
        bindGlobalModalEvents();
        syncScheduleHeaderActions();

        try {
            if (scheduleId > 0) {
                await loadScheduleDetail(scheduleId);
            } else {
                await loadScheduleList();
            }
        } catch (error) {
            console.error(error);
            contentEl.innerHTML = `
                <div class="fbg-schedules-empty">
                    ${escapeHtml(error.message || 'Failed to load schedules.')}
                </div>
            `;
            showMessage(error.message || 'Failed to load schedules.', true);
        }
    }

    init();
})();
