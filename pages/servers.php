<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$catalog = fbgGetShopCatalog();
$currency = fbgGetShopCurrency();
$shopTaxRate = fbgGetShopTaxRate();
$userId = (int)($_SESSION['user_id'] ?? 0);
$isLoggedIn = $userId > 0;
$balance = $isLoggedIn ? fbgGetUserCreditBalance($userId) : 0.0;
$tosUrl = trim((string)fbgGetShopSetting('settings::shop::tos_url', ''));
$tosLink = $tosUrl !== '' ? $tosUrl : './page.php?name=legal&doc=terms';
$tosCanFrame = $tosUrl !== '';

function fbgShopFormatMemory(int $megabytes): string
{
    if ($megabytes > 0 && $megabytes % 1024 === 0) {
        return number_format($megabytes / 1024) . ' GB';
    }

    return number_format($megabytes) . ' MB';
}

function fbgShopFormatDisk(int $megabytes): string
{
    if ($megabytes === 0) {
        return '&infin;';
    }

    return fbgShopFormatMemory($megabytes);
}

function fbgShopFormatCpu(int $cpu): string
{
    if ($cpu === 0) {
        return '&infin;';
    }

    return number_format($cpu) . '%';
}

function fbgShopPluralize(int $count, string $singular, string $plural): string
{
    return number_format($count) . ' ' . ($count === 1 ? $singular : $plural);
}
?>

<section class="fbg-account-page fbg-shop-page">
    <div class="fbg-dashboard-shell">
        <div class="fbg-dashboard-layout">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div class="fbg-dashboard-main">
                <div class="fbg-shop-shell">
                    <div class="fbg-shop-header">
                        <div>
                            <h1>Game Servers</h1>
                            <p>Choose a configured server plan and deploy it automatically with your account balance.</p>
                        </div>

                        <div class="fbg-shop-balance">
                            <span>Account Balance</span>
                            <strong>$<?php echo $isLoggedIn ? htmlspecialchars(fbgFormatCredit($balance, $currency)) : 'Login Required'; ?></strong>
                            <a href="./page.php?name=wallet">Manage Wallet</a>
                        </div>
                    </div>

                    <div id="fbg-shop-message" class="fbg-dashboard-alert" style="display:none; margin-bottom: 18px;"></div>

                    <?php if (empty($catalog)): ?>
                        <section class="fbg-account-section">
                            <div class="fbg-empty-state">
                                No server plans are available right now.
                            </div>
                        </section>
                    <?php else: ?>
                        <div class="fbg-shop-category-list">
            <?php foreach ($catalog as $category): ?>
                <?php
                $categoryPanelId = 'shop-category-' . (int)$category['id'];
                ?>
                <section class="fbg-shop-category-card">
                    <button
                        type="button"
                        class="fbg-shop-category-trigger"
                        aria-expanded="false"
                        aria-controls="<?php echo htmlspecialchars($categoryPanelId); ?>"
                    >
                        <span class="fbg-shop-category-summary">
                            <img
                                src="<?php echo htmlspecialchars((string)$category['image_url']); ?>"
                                alt=""
                                aria-hidden="true"
                            >
                            <span>
                                <strong><?php echo htmlspecialchars((string)$category['title']); ?></strong>
                                <small><?php echo count($category['games']); ?> available plan<?php echo count($category['games']) === 1 ? '' : 's'; ?></small>
                            </span>
                        </span>
                        <span class="fbg-shop-category-caret" aria-hidden="true">▾</span>
                    </button>

                    <div
                        id="<?php echo htmlspecialchars($categoryPanelId); ?>"
                        class="fbg-shop-category-panel"
                        hidden
                    >
                        <?php if (empty($category['games'])): ?>
                            <div class="fbg-empty-state">
                                No visible plans are configured for this category yet.
                            </div>
                        <?php else: ?>
                            <div class="fbg-shop-plan-grid">
                            <?php foreach ($category['games'] as $game): ?>
                                <?php
                                $price = (float)($game['price'] ?? 0);
                                $tax = fbgCalculateShopTax($price);
                                $totalPrice = (float)$tax['total'];
                                $canAfford = $isLoggedIn && $balance >= $totalPrice;
                                $balanceAfterOrder = max(0, $balance - $totalPrice);
                                ?>
                                <article class="fbg-shop-plan-card">
                                    <div class="fbg-shop-plan-media">
                                        <img
                                            src="<?php echo htmlspecialchars((string)$game['image_url']); ?>"
                                            alt="<?php echo htmlspecialchars((string)$game['name']); ?>"
                                        >
                                    </div>

                                    <div class="fbg-shop-plan-body">
                                        <div class="fbg-shop-plan-title-row">
                                            <h3><?php echo htmlspecialchars((string)$game['name']); ?></h3>
                                            <strong><?php echo htmlspecialchars(fbgFormatCredit($totalPrice, $currency)); ?></strong>
                                        </div>
                                        <?php if ($shopTaxRate > 0): ?>
                                            <p class="fbg-shop-plan-tax-note">
                                                Includes <?php echo htmlspecialchars(fbgFormatCredit((float)$tax['tax_amount'], $currency)); ?> tax.
                                            </p>
                                        <?php endif; ?>

                                        <div class="fbg-shop-plan-specs">
                                            <span><?php echo htmlspecialchars(fbgShopFormatMemory((int)$game['memory'])); ?> RAM</span>
                                            <span><?php echo fbgShopFormatDisk((int)$game['disk']); ?> Disk</span>
                                            <span><?php echo fbgShopFormatCpu((int)$game['cpu']); ?> CPU</span>
                                            <?php if ((int)$game['database_limit'] > 0): ?>
                                                <span><?php echo fbgShopPluralize((int)$game['database_limit'], 'Database', 'Databases'); ?></span>
                                            <?php endif; ?>
                                            <span><?php echo (int)$game['backup_limit']; ?> Backups</span>
                                            <span><?php echo fbgShopPluralize((int)$game['allocation_limit'], 'Port', 'Ports'); ?></span>
                                        </div>
                                    </div>

                                    <div class="fbg-shop-plan-actions">
                                        <?php if (!$isLoggedIn): ?>
                                            <a class="btn fbg-primary-button" href="./page.php?name=login">
                                                Login to Rent
                                            </a>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="btn fbg-primary-button fbg-shop-purchase-button"
                                                data-game-id="<?php echo (int)$game['id']; ?>"
                                                data-game-name="<?php echo htmlspecialchars((string)$category['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-plan-name="<?php echo htmlspecialchars((string)$game['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-subtotal="<?php echo htmlspecialchars(fbgFormatCredit($price, $currency), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-tax-rate="<?php echo htmlspecialchars(number_format((float)$tax['tax_rate'], 2), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-tax-amount="<?php echo htmlspecialchars(fbgFormatCredit((float)$tax['tax_amount'], $currency), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-total="<?php echo htmlspecialchars(fbgFormatCredit($totalPrice, $currency), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-current-balance="<?php echo htmlspecialchars(fbgFormatCredit($balance, $currency), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-balance-after="<?php echo htmlspecialchars(fbgFormatCredit($balanceAfterOrder, $currency), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-default-text="Rent Server"
                                                <?php echo $canAfford ? '' : 'disabled'; ?>
                                            >
                                                <?php echo $canAfford ? 'Rent Server' : 'Insufficient Balance'; ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($isLoggedIn): ?>
    <div class="fbg-modal-overlay" id="fbg-shop-order-modal" hidden>
        <div class="fbg-modal-card fbg-shop-order-modal" role="dialog" aria-modal="true" aria-labelledby="fbg-shop-order-title">
            <button type="button" class="fbg-modal-close fbg-shop-order-close" id="fbg-shop-order-close" aria-label="Close">X</button>

            <div class="fbg-modal-header">
                <h3 id="fbg-shop-order-title">Your adventure is about to begin!</h3>
                <p>You're one click away from deploying your new server.</p>
            </div>

            <div class="fbg-shop-order-copy">
                <p>By selecting Confirm Rental, you acknowledge that you have read and agree to the Terms of Service.</p>
                <p>Your new world will begin taking shape immediately after your rental is confirmed. You'll be exploring it in no time!</p>
            </div>

            <div class="fbg-shop-order-tos">
                <div class="fbg-shop-order-tos-header">
                    <strong>Terms of Service</strong>
                    <a id="fbg-shop-order-tos-link" href="<?php echo htmlspecialchars($tosLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        Open Terms
                    </a>
                </div>

                <?php if ($tosCanFrame): ?>
                    <iframe
                        id="fbg-shop-order-tos-frame"
                        src="<?php echo htmlspecialchars($tosLink, ENT_QUOTES, 'UTF-8'); ?>"
                        title="Terms of Service"
                    ></iframe>
                <?php else: ?>
                    <div class="fbg-shop-order-tos-fallback">
                        Terms of Service are available using the link above.
                    </div>
                <?php endif; ?>
            </div>

            <dl class="fbg-shop-order-summary">
                <div>
                    <dt>Game</dt>
                    <dd id="fbg-shop-order-game">-</dd>
                </div>
                <div>
                    <dt>Plan</dt>
                    <dd id="fbg-shop-order-plan">-</dd>
                </div>
                <div>
                    <dt>Subtotal</dt>
                    <dd id="fbg-shop-order-subtotal">-</dd>
                </div>
                <div>
                    <dt>Tax</dt>
                    <dd id="fbg-shop-order-tax">-</dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd id="fbg-shop-order-total">-</dd>
                </div>
                <div>
                    <dt>Current Balance</dt>
                    <dd id="fbg-shop-order-balance">-</dd>
                </div>
                <div>
                    <dt>Balance After Rental</dt>
                    <dd id="fbg-shop-order-after">-</dd>
                </div>
            </dl>

            <label class="fbg-shop-order-agree">
                <input type="checkbox" id="fbg-shop-order-agree">
                <span>
                    I have read and agree to the
                    <a href="<?php echo htmlspecialchars($tosLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Terms of Service</a>.
                </span>
            </label>

            <div class="fbg-modal-actions fbg-shop-order-actions">
                <button type="button" class="btn fbg-neutral-button" id="fbg-shop-order-cancel">Cancel</button>
                <button type="button" class="btn fbg-primary-button" id="fbg-shop-order-confirm" disabled>Confirm Rental</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const message = document.getElementById("fbg-shop-message");
    const buttons = document.querySelectorAll(".fbg-shop-purchase-button");
    const categoryCards = document.querySelectorAll(".fbg-shop-category-card");
    const csrfToken = <?php echo json_encode((string)$_SESSION['csrf_token']); ?>;
    const orderModal = document.getElementById("fbg-shop-order-modal");
    const orderClose = document.getElementById("fbg-shop-order-close");
    const orderCancel = document.getElementById("fbg-shop-order-cancel");
    const orderAgree = document.getElementById("fbg-shop-order-agree");
    const orderConfirm = document.getElementById("fbg-shop-order-confirm");
    const orderFields = {
        game: document.getElementById("fbg-shop-order-game"),
        plan: document.getElementById("fbg-shop-order-plan"),
        subtotal: document.getElementById("fbg-shop-order-subtotal"),
        tax: document.getElementById("fbg-shop-order-tax"),
        total: document.getElementById("fbg-shop-order-total"),
        balance: document.getElementById("fbg-shop-order-balance"),
        after: document.getElementById("fbg-shop-order-after")
    };
    let activePurchaseButton = null;

    const readJsonResponse = async (response) => {
        const contentType = response.headers.get("content-type") || "";

        if (!contentType.toLowerCase().includes("application/json")) {
            throw new Error(response.ok
                ? "The server returned an unexpected response."
                : "The server returned an error page instead of JSON.");
        }

        try {
            return await response.json();
        } catch (error) {
            throw new Error("The server returned invalid JSON.");
        }
    };

    const showMessage = (text, type) => {
        if (!message) return;
        message.textContent = text;
        message.className = "fbg-dashboard-alert is-visible" + (type === "error" ? " error" : " success");
        message.style.display = "block";
        message.scrollIntoView({ behavior: "smooth", block: "nearest" });
    };

    const openOrderModal = (button) => {
        if (!orderModal || !orderAgree || !orderConfirm) return false;

        activePurchaseButton = button;
        orderFields.game.textContent = button.dataset.gameName || "-";
        orderFields.plan.textContent = button.dataset.planName || "-";
        orderFields.subtotal.textContent = button.dataset.subtotal || "-";
        orderFields.tax.textContent = `${button.dataset.taxAmount || "-"} (${button.dataset.taxRate || "0.00"}%)`;
        orderFields.total.textContent = button.dataset.total || "-";
        orderFields.balance.textContent = button.dataset.currentBalance || "-";
        orderFields.after.textContent = button.dataset.balanceAfter || "-";
        orderAgree.checked = false;
        orderConfirm.disabled = true;
        orderModal.hidden = false;
        document.body.classList.add("fbg-modal-open");
        orderAgree.focus();

        return true;
    };

    const closeOrderModal = () => {
        if (!orderModal || !orderAgree || !orderConfirm) return;

        orderModal.hidden = true;
        orderAgree.checked = false;
        orderConfirm.disabled = true;
        activePurchaseButton = null;
        document.body.classList.remove("fbg-modal-open");
    };

    categoryCards.forEach((card) => {
        const trigger = card.querySelector(".fbg-shop-category-trigger");
        const panel = card.querySelector(".fbg-shop-category-panel");

        if (!trigger || !panel) return;

        trigger.addEventListener("click", () => {
            const isOpen = trigger.getAttribute("aria-expanded") === "true";

            trigger.setAttribute("aria-expanded", isOpen ? "false" : "true");
            panel.hidden = isOpen;
            card.classList.toggle("is-open", !isOpen);
        });
    });

    const purchaseServer = async (button) => {
            const gameId = button.dataset.gameId;
            const originalText = button.dataset.defaultText || button.textContent;
            let provisioningStep = 0;
            let provisioningTimer = null;

            const stopProvisioningAnimation = () => {
                if (provisioningTimer) {
                    clearInterval(provisioningTimer);
                    provisioningTimer = null;
                }
            };

            button.disabled = true;
            button.textContent = "Provisioning.";
            provisioningTimer = setInterval(() => {
                provisioningStep = (provisioningStep + 1) % 3;
                button.textContent = "Provisioning" + ".".repeat(provisioningStep + 1);
            }, 500);

            try {
                const response = await fetch("/api/shop/purchase-server.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "Accept": "application/json"
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        game_id: gameId
                    })
                });

                const payload = await readJsonResponse(response);

                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || "Could not start server rental.");
                }

                stopProvisioningAnimation();
                button.textContent = "Provisioned";
                showMessage(payload.data?.message || "Server rental started and provisioning has begun.", "success");

                if (payload.data?.identifier) {
                    setTimeout(() => {
                        window.location.href = "/page.php?name=dashboard";
                    }, 1500);
                }
            } catch (error) {
                stopProvisioningAnimation();
                showMessage(error.message || "Could not start server rental.", "error");
                button.disabled = false;
                button.textContent = originalText;
            }
    };

    if (orderAgree && orderConfirm) {
        orderAgree.addEventListener("change", () => {
            orderConfirm.disabled = !orderAgree.checked;
        });
    }

    if (orderConfirm) {
        orderConfirm.addEventListener("click", () => {
            if (!activePurchaseButton || !orderAgree?.checked) return;

            const button = activePurchaseButton;
            closeOrderModal();
            purchaseServer(button);
        });
    }

    [orderClose, orderCancel].forEach((control) => {
        if (control) {
            control.addEventListener("click", closeOrderModal);
        }
    });

    if (orderModal) {
        orderModal.addEventListener("click", (event) => {
            if (event.target === orderModal) {
                closeOrderModal();
            }
        });
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && orderModal && !orderModal.hidden) {
            closeOrderModal();
        }
    });

    buttons.forEach((button) => {
        button.addEventListener("click", () => {
            if (!openOrderModal(button)) {
                purchaseServer(button);
            }
        });
    });
});
</script>
