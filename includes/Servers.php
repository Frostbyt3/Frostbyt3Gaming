<?php
    $servers = [
        [
            "type" => "minecraft",
            "name" => "All the Mods 10",
            "host" => "node1.frostbyt3gaming.com",
            "port" => "10065"
        ],
        [
            "type" => "steam",
            "name" => "Ark: Survival Evolved",
            "host" => "209.147.107.81",
            "gamePort" => "10002",
            "queryPort" => "10005",
            "appid" => "346110"
        ],
        [
            "type" => "steam",
            "name" => "Icarus",
            "host" => "209.147.107.81",
            "gamePort" => "10000",
            "queryPort" => "10001",
            "appid" => "1149460"
        ]
    ];
?>

<?php foreach ($servers as $server): ?>
    <div class="card">
        <h1><?= $server['name'] ?></h1>
        <div class="server-info">
            <?php
            if ($server['type'] === 'minecraft') {
                $status = getMinecraftStatus($server['host'], $server['port']);
            } else {
                $status = getSteamServerStatus($server['host'], $server['queryPort']);
            }

            if ($status['online']) {
                echo "<p class='status online'>Online</p>";
                if ($server['type'] === 'minecraft') {
                    echo "<p class='motd'>MOTD:</p>";
                    echo "<p class='motdtext'>{$status['motd']}</p>";
                } else {
                    echo "<p class='motd'>Server Name: </p>";
                    echo "<p class='motdtext'>{$status['name']}</p>";
                }
                if ($server['type'] === 'steam') {
                    echo "<p class='motd'>Map:</p>";
                    echo "<p class='motdtext'>{$status['map']}</p>";
                }
                echo "<p class='motd'>Players:</p>";
                echo "<p class='motdtext'>{$status['players']} / {$status['max']}</p>";
                $directIp = ($server['type'] === 'steam')
                    ? "{$server['host']}:{$server['gamePort']}"
                    : "{$server['host']}:{$server['port']}";

                echo "<p class='motd'>Direct IP:</p>";
                echo "<span class='copy-status' data-tooltip='Copy to Clipboard' onclick=\"copyToClipboard('$directIp', this)\"><p class='motdtext'>$directIp</p></span>";
                echo "<p class='copy-hint'>(Click to Copy)</p>";
                if ($server['type'] === 'steam') {
                    $joinUrl = "steam://connect/{$server['host']}:{$server['gamePort']}?appid={$server['appid']}";
                    echo "<p><a href='$joinUrl' class='join-button'>Join Server</a></p>";
                }
            } else {
                echo "<p class='status offline'>Offline</p>";
            }
            ?>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        // Create tooltip element
        const tooltip = document.createElement("div");
        tooltip.id = "tooltip";
        document.body.appendChild(tooltip);

        // Attach hover listeners to all .copy-status elements
        document.querySelectorAll(".copy-status").forEach(el => {
            el.addEventListener("mouseenter", () => {
                tooltip.textContent = "Copy to Clipboard";
                tooltip.classList.add("show");
            });
            el.addEventListener("mouseleave", () => {
                tooltip.classList.remove("show");
            });
            el.addEventListener("mousemove", (e) => {
                let x = e.clientX + 15;
                let y = e.clientY + 15;

                if (x + tooltip.offsetWidth > window.innerWidth) {
                    x = e.clientX - tooltip.offsetWidth - 15;
                }
                if (y + tooltip.offsetHeight > window.innerHeight) {
                    y = e.clientY - tooltip.offsetHeight - 15;
                }

                tooltip.style.left = x + "px";
                tooltip.style.top = y + "px";
            });
        });

        // Expose tooltip reference for the copy function
        window.updateTooltip = (msg) => {
            tooltip.textContent = msg;
        };
    });

    function copyToClipboard(text, el) {
        navigator.clipboard.writeText(text).then(function () {
            updateTooltip("Copied!");
            setTimeout(() => {
                updateTooltip("Copy to Clipboard");
            }, 2000);
        }).catch(function (err) {
            console.error("Clipboard copy failed:", err);
        });
    }
</script>