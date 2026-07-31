<div class="fbg-console-toolbar">
    <button type="button" class="btn fbg-neutral-button btn-sm" id="console-clear-button">Clear</button>
    <button type="button" class="btn fbg-neutral-button btn-sm" id="console-autoscroll-button" data-enabled="true">
        Auto-scroll: On
    </button>
</div>

<pre id="server-console-output" class="fbg-console-output">Connecting to console...</pre>

<div class="fbg-dashboard-alert" id="console-message" style="display:none; margin-top: 16px;"></div>
<div class="fbg-dashboard-alert" id="command-message" style="display:none; margin-top: 16px;"></div>

<!-- <label for="server-command-input" class="fbg-meta-label" style="margin-top: 12px;">Command</label> -->
<div class="fbg-command-row">
    <input type="text" id="server-command-input" class="fbg-text-input" placeholder="Type a command..." autocomplete="off">
    <button type="button" class="btn fbg-neutral-button btn-sm" id="send-command-button">Send</button>
</div>

<script src="<?php echo asset('/backend/js/serverpanel/console.js'); ?>"></script>