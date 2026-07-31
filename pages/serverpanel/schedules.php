<?php
declare(strict_types=1);

$serverIdentifier  = (string)($selectedServer['identifier'] ?? '');
$currentScheduleId = (int)($_GET['schedule'] ?? 0);
$baseSchedulesUrl  = './page.php?name=serverpanel&id=' . urlencode($serverIdentifier) . '&tab=schedules';
$csrfToken         = (string)($_SESSION['csrf_token'] ?? '');
?>

<div
    class="fbg-schedules-panel"
    data-server-id="<?php echo htmlspecialchars($serverIdentifier); ?>"
    data-schedule-id="<?php echo $currentScheduleId; ?>"
    data-base-url="<?php echo htmlspecialchars($baseSchedulesUrl); ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken); ?>"
>
    <div class="fbg-server-card-header">
        <div class="fbg-server-heading">
            <h2><i class="fas fa-clock"></i> Schedules</h2>
            <p>View automated schedules, create new ones, and inspect their tasks.</p>
        </div>

        <div class="fbg-server-card-actions" id="schedule-header-actions">
            <button
                type="button"
                class="btn fbg-primary-button"
                id="new-schedule-button"
            >
                <i class="fas fa-plus"></i>
                New Schedule
            </button>
        </div>
    </div>

    <div
        class="fbg-dashboard-alert fbg-schedules-message"
        id="schedules-message"
        style="display:none;"
    ></div>

    <div id="fbg-schedules-content" class="fbg-schedules-content">
        <div class="fbg-schedules-loading">Loading schedules...</div>
    </div>
</div>

<div id="fbg-schedules-modal-root">
    <div class="fbg-modal-overlay" id="schedule-edit-modal" hidden>
        <div class="fbg-modal-card fbg-schedule-modal-card">
            <button
                type="button"
                class="fbg-modal-close"
                id="schedule-edit-close"
                aria-label="Close"
            >
                <i class="fas fa-times"></i>
            </button>

            <div class="fbg-modal-header">
                <h3 id="schedule-modal-title">Edit Schedule</h3>
                <p id="schedule-modal-description">
                    Update this schedule's name, cron timing, and status settings.
                </p>
            </div>

            <form id="schedule-edit-form">
                <input type="hidden" name="schedule_id" id="edit_schedule_id" value="">

                <div class="fbg-form-group">
                    <label class="fbg-meta-label" for="edit_name">Schedule Name</label>
                    <input
                        type="text"
                        class="fbg-files-text-input"
                        name="name"
                        id="edit_name"
                        required
                    >
                </div>

                <div class="fbg-schedule-cron-form-grid">
                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="edit_minute">Minute</label>
                        <input
                            type="text"
                            class="fbg-files-text-input"
                            name="minute"
                            id="edit_minute"
                            placeholder="*"
                        >
                    </div>

                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="edit_hour">Hour</label>
                        <input
                            type="text"
                            class="fbg-files-text-input"
                            name="hour"
                            id="edit_hour"
                            placeholder="*"
                        >
                    </div>

                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="edit_day_of_month">Day of Month</label>
                        <input
                            type="text"
                            class="fbg-files-text-input"
                            name="day_of_month"
                            id="edit_day_of_month"
                            placeholder="*"
                        >
                    </div>

                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="edit_month">Month</label>
                        <input
                            type="text"
                            class="fbg-files-text-input"
                            name="month"
                            id="edit_month"
                            placeholder="*"
                        >
                    </div>

                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="edit_day_of_week">Day of Week</label>
                        <input
                            type="text"
                            class="fbg-files-text-input"
                            name="day_of_week"
                            id="edit_day_of_week"
                            placeholder="*"
                        >
                    </div>
                </div>

                <div class="fbg-schedule-presets">
                    <span class="fbg-meta-label">Quick Presets</span>

                    <div class="fbg-schedule-preset-buttons">
                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="*/30"
                            data-hour="*"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            30 Minutes
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="*"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            1 Hour
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="*/4"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            4 Hours
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="*/8"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            8 Hours
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="*/12"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            12 Hours
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="0"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            Daily
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="0"
                            data-day-of-month="*"
                            data-month="*"
                            data-day-of-week="0"
                        >
                            Weekly
                        </button>

                        <button
                            type="button"
                            class="btn fbg-neutral-button btn-sm schedule-preset-button"
                            data-minute="0"
                            data-hour="0"
                            data-day-of-month="1"
                            data-month="*"
                            data-day-of-week="*"
                        >
                            Monthly
                        </button>
                    </div>
                </div>

                <div class="fbg-schedule-cheatsheet-toggle">
                    <label class="fbg-checkbox-row fbg-schedule-toggle-card" for="edit_show_cheatsheet">
                        <input type="checkbox" id="edit_show_cheatsheet">
                        <span class="fbg-schedule-toggle-copy">
                            <strong>Show Cheatsheet</strong>
                            <small>Show the cron cheatsheet for some examples.</small>
                        </span>
                    </label>
                </div>

                <div class="fbg-schedule-cheatsheet" id="schedule-cheatsheet" hidden>
                    <div class="fbg-schedule-cheatsheet-grid">
                        <div class="fbg-schedule-cheatsheet-column">
                            <h4>Examples</h4>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>*/5 * * * *</code>
                                <span>every 5 minutes</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>0 */1 * * *</code>
                                <span>every hour</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>0 8-12 * * *</code>
                                <span>hour range</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>0 0 * * *</code>
                                <span>once a day</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>0 0 * * MON</code>
                                <span>every Monday</span>
                            </div>
                        </div>

                        <div class="fbg-schedule-cheatsheet-column">
                            <h4>Special Characters</h4>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>*</code>
                                <span>any value</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>,</code>
                                <span>value list separator</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>-</code>
                                <span>range values</span>
                            </div>

                            <div class="fbg-schedule-cheatsheet-row">
                                <code>/</code>
                                <span>step values</span>
                            </div>
                        </div>
                    </div>
                </div>

                <label class="fbg-checkbox-row">
                    <input type="checkbox" name="only_when_online" id="edit_only_when_online" value="1">
                    <span>Only when server is online</span>
                </label>

                <label class="fbg-checkbox-row">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                    <span>Schedule enabled</span>
                </label>

                <div class="fbg-modal-actions">
                    <button
                        type="button"
                        class="btn fbg-neutral-button"
                        id="schedule-edit-cancel"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn fbg-primary-button"
                        id="schedule-edit-submit"
                    >
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="fbg-modal-overlay" id="task-edit-modal" hidden>
        <div class="fbg-modal-card fbg-schedule-modal-card">
            <button
                type="button"
                class="fbg-modal-close"
                id="task-edit-close"
                aria-label="Close"
            >
                <i class="fas fa-times"></i>
            </button>

            <div class="fbg-modal-header">
                <h3 id="task-modal-title">Edit Task</h3>
                <p id="task-modal-description">
                    Update the task action, payload, offset, and failure behavior.
                </p>
            </div>

            <form id="task-edit-form">
                <input type="hidden" name="schedule_id" id="task_edit_schedule_id" value="">
                <input type="hidden" name="task_id" id="task_edit_task_id" value="">

                <div class="fbg-schedule-task-form-grid">
                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="task_edit_action">Action</label>
                        <select
                            class="fbg-files-text-input"
                            name="action"
                            id="task_edit_action"
                            required
                        >
                            <option value="command">Send Command</option>
                            <option value="power">Send Power Action</option>
                        </select>
                    </div>

                    <div class="fbg-form-group">
                        <label class="fbg-meta-label" for="task_edit_time_offset">Time Offset (seconds)</label>
                        <input
                            type="number"
                            min="0"
                            class="fbg-files-text-input"
                            name="time_offset"
                            id="task_edit_time_offset"
                            value="0"
                            required
                        >
                    </div>
                </div>

                <div class="fbg-form-group" id="task-command-group">
                    <label class="fbg-meta-label" for="task_edit_command_payload">Command</label>
                    <textarea
                        class="fbg-files-text-input"
                        id="task_edit_command_payload"
                        rows="6"
                        placeholder="say Server restarting in 5 minutes"
                    ></textarea>
                </div>

                <div class="fbg-form-group" id="task-power-group" hidden>
                    <label class="fbg-meta-label" for="task_edit_power_payload">Power Action</label>
                    <select class="fbg-files-text-input" id="task_edit_power_payload">
                        <option value="start">Start the server</option>
                        <option value="restart">Restart the server</option>
                        <option value="stop">Stop the server</option>
                        <option value="kill">Terminate the server</option>
                    </select>
                </div>

                <label class="fbg-checkbox-row">
                    <input
                        type="checkbox"
                        name="continue_on_failure"
                        id="task_edit_continue_on_failure"
                        value="1"
                    >
                    <span>Continue on failure</span>
                </label>

                <div class="fbg-modal-actions">
                    <button
                        type="button"
                        class="btn fbg-neutral-button"
                        id="task-edit-cancel"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn fbg-primary-button"
                        id="task-edit-submit"
                    >
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/schedules.js'); ?>"></script>