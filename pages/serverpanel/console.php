<div class="fbg-console-toolbar">
    <div class="fbg-console-toolbar-actions">
        <button type="button" class="btn fbg-neutral-button btn-sm" id="console-clear-button">Clear</button>
        <button type="button" class="btn fbg-neutral-button btn-sm" id="console-autoscroll-button" data-enabled="true">
            Auto-scroll: On
        </button>
    </div>

    <div class="fbg-console-toolbar-toast-wrap" aria-live="polite">
        <div class="fbg-dashboard-alert fbg-console-toolbar-message" id="console-message" style="display:none;"></div>
        <div class="fbg-dashboard-alert fbg-console-toolbar-message" id="command-message" style="display:none;"></div>
    </div>
</div>

<div id="server-console-output" class="fbg-console-output">Connecting to console...</div>

<!-- <label for="server-command-input" class="fbg-meta-label" style="margin-top: 12px;">Command</label> -->
<div class="fbg-command-row">
    <input type="text" id="server-command-input" class="fbg-text-input" placeholder="Type a command..." autocomplete="off">
    <button type="button" class="btn fbg-neutral-button btn-sm" id="send-command-button">Send</button>
</div>

<link rel="stylesheet" href="<?php echo asset('/backend/vendor/xterm/xterm.css'); ?>">
<script src="<?php echo asset('/backend/vendor/xterm/xterm.js'); ?>"></script>
<script src="<?php echo asset('/backend/js/serverpanel/console.js'); ?>"></script>
