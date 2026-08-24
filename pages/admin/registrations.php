<?php
    declare(strict_types=1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/pagination.php';
    require_once __DIR__ . '/../../includes/registration.php';
    require_once __DIR__ . '/../../includes/mailer.php';

    requireLogin();

    if (!canAccess(4)) {
        http_response_code(403);
        fbgRedirect('/page.php?name=403');
        return;
    }

    $currentAdminPage = 'admin-registrations';

    fbgEnsurePendingRegistrationSecuritySchema();
    fbgDeleteExpiredPendingRegistrations();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $message = (string)($_SESSION['admin_registration_message'] ?? '');
    $messageType = (string)($_SESSION['admin_registration_message_type'] ?? 'success');
    unset($_SESSION['admin_registration_message'], $_SESSION['admin_registration_message_type']);

    function fbgAdminRegistrationRedirect(string $message, string $type = 'success'): void
    {
        $_SESSION['admin_registration_message'] = $message;
        $_SESSION['admin_registration_message_type'] = $type;
        fbgRedirect('/page.php?name=admin-registrations');
        exit;
    }

    function fbgAdminRegistrationVerifyCsrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
            fbgAdminRegistrationRedirect('Security check failed. Please refresh and try again.', 'error');
        }
    }

    function fbgAdminRegistrationVerificationUrl(string $selector, string $token): string
    {
        return fbgRegistrationBaseUrl()
            . '/page.php?name=verify'
            . '&selector=' . urlencode($selector)
            . '&token=' . urlencode($token);
    }

    function fbgAdminRegistrationSendVerification(array $pendingRegistration, string $selector, string $token): bool
    {
        return fbgSendVerificationEmail([
            'to_email' => (string)$pendingRegistration['email'],
            'first_name' => (string)($pendingRegistration['first_name'] ?? ''),
            'verification_url' => fbgAdminRegistrationVerificationUrl($selector, $token),
        ]);
    }

    function fbgAdminRegistrationSendCompletion(array $pendingRegistration, string $selector, string $token): bool
    {
        return fbgSendRegistrationCompletionEmail([
            'to_email' => (string)$pendingRegistration['email'],
            'first_name' => (string)($pendingRegistration['first_name'] ?? ''),
            'completion_url' => fbgAdminRegistrationVerificationUrl($selector, $token),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fbgAdminRegistrationVerifyCsrf();

        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'cleanup_registrations') {
            $marked = fbgMarkExpiredPendingRegistrations();
            $deleted = fbgCleanupExpiredPendingRegistrations();

            fbgAdminRegistrationRedirect(
                'Registration cleanup complete. Marked ' . $marked . ' expired and deleted ' . $deleted . ' retained row(s).'
            );
        }

        $registrationId = (int)($_POST['registration_id'] ?? 0);
        $pending = fbgFindPendingRegistrationById($registrationId);

        if (!$pending) {
            fbgAdminRegistrationRedirect('Registration could not be found.', 'error');
        }

        if (!empty($pending['consumed_at'])) {
            fbgAdminRegistrationRedirect('That registration has already been completed.', 'error');
        }

        if ($action === 'delete_registration') {
            $deleted = fbgDeletePendingRegistration((int)$pending['id']);

            fbgAdminRegistrationRedirect(
                $deleted ? 'Pending registration deleted.' : 'Pending registration could not be deleted.',
                $deleted ? 'success' : 'error'
            );
        }

        if (!empty($pending['rejected_at'])) {
            fbgAdminRegistrationRedirect('Rejected or expired registrations cannot be modified here.', 'error');
        }

        if ($action === 'resend_verification') {
            $refresh = fbgRefreshPendingRegistrationVerificationToken((int)$pending['id']);

            if (empty($refresh['ok'])) {
                fbgAdminRegistrationRedirect($refresh['error'] ?? 'Verification email could not be prepared.', 'error');
            }

            try {
                $sent = fbgAdminRegistrationSendVerification($pending, (string)$refresh['selector'], (string)$refresh['token']);
            } catch (Throwable $e) {
                $sent = false;
            }

            fbgAdminRegistrationRedirect(
                $sent ? 'Verification email sent.' : 'Verification token was refreshed, but the email could not be sent.',
                $sent ? 'success' : 'error'
            );
        }

        if (in_array($action, ['manual_approval', 'manual_approval_email', 'manual_approval_set_password'], true)) {
            $reason = trim((string)($_POST['manual_approval_reason'] ?? ''));

            if ($reason === '') {
                fbgAdminRegistrationRedirect('Manual approval requires a reason.', 'error');
            }

            if (pteroFindUserByEmail((string)$pending['email'])) {
                fbgAdminRegistrationRedirect('A Pterodactyl account already exists with that email.', 'error');
            }

            if (function_exists('pteroFindUserByUsername') && pteroFindUserByUsername((string)$pending['username'])) {
                fbgAdminRegistrationRedirect('A Pterodactyl account already exists with that username.', 'error');
            }

            if ($action === 'manual_approval_set_password') {
                $password = (string)($_POST['manual_approval_password'] ?? '');
                $confirmPassword = (string)($_POST['manual_approval_confirm_password'] ?? '');
                $passwordErrors = fbgValidatePassword($password, $confirmPassword);

                if (!empty($passwordErrors)) {
                    fbgAdminRegistrationRedirect(implode(' ', $passwordErrors), 'error');
                }

                $approved = fbgMarkPendingRegistrationManuallyApproved(
                    (int)$pending['id'],
                    (int)($_SESSION['user_id'] ?? 0),
                    $reason
                );

                if (!$approved) {
                    fbgAdminRegistrationRedirect('Registration could not be manually approved.', 'error');
                }

                $approvedPending = fbgFindPendingRegistrationById((int)$pending['id']);
                if (!$approvedPending) {
                    fbgAdminRegistrationRedirect('Approved registration could not be reloaded.', 'error');
                }

                $created = fbgCreatePterodactylUserFromPendingRegistration($approvedPending, $password);

                if (empty($created['ok'])) {
                    fbgAdminRegistrationRedirect($created['error'] ?? 'Account could not be created.', 'error');
                }

                fbgAdminRegistrationRedirect('Registration approved and account created.');
            }

            $refresh = fbgRefreshPendingRegistrationVerificationToken((int)$pending['id']);
            if (empty($refresh['ok'])) {
                fbgAdminRegistrationRedirect($refresh['error'] ?? 'Completion email could not be prepared.', 'error');
            }

            $approved = fbgMarkPendingRegistrationManuallyApproved(
                (int)$pending['id'],
                (int)($_SESSION['user_id'] ?? 0),
                $reason
            );

            if (!$approved) {
                fbgAdminRegistrationRedirect('Registration could not be manually approved.', 'error');
            }

            try {
                $sent = fbgAdminRegistrationSendCompletion($pending, (string)$refresh['selector'], (string)$refresh['token']);
            } catch (Throwable $e) {
                $sent = false;
            }

            fbgAdminRegistrationRedirect(
                $sent ? 'Registration approved and completion email sent.' : 'Registration approved, but the completion email could not be sent.',
                $sent ? 'success' : 'error'
            );
        }

        if ($action === 'set_password') {
            if (empty($pending['email_verified_at'])) {
                fbgAdminRegistrationRedirect('Registration must be verified or manually approved before setting a password.', 'error');
            }

            $password = (string)($_POST['set_password'] ?? '');
            $confirmPassword = (string)($_POST['set_confirm_password'] ?? '');
            $passwordErrors = fbgValidatePassword($password, $confirmPassword);

            if (!empty($passwordErrors)) {
                fbgAdminRegistrationRedirect(implode(' ', $passwordErrors), 'error');
            }

            $created = fbgCreatePterodactylUserFromPendingRegistration($pending, $password);

            if (empty($created['ok'])) {
                fbgAdminRegistrationRedirect($created['error'] ?? 'Account could not be created.', 'error');
            }

            fbgAdminRegistrationRedirect('Password set and account created.');
        }

        fbgAdminRegistrationRedirect('Unknown registration action.', 'error');
    }

    function fbgAdminRegistrationStatus(array $row): string
    {
        if (!empty($row['consumed_at'])) {
            return 'Completed';
        }

        if (!empty($row['rejected_at'])) {
            return (string)($row['rejection_reason'] ?? '') === FbgRegistrationRejectionReason::VERIFICATION_EXPIRED
                ? 'Expired'
                : 'Rejected';
        }

        if (!empty($row['email_verified_at'])) {
            return 'Verified';
        }

        return 'Pending';
    }

    function fbgAdminRegistrationDate(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($value))->format('M j, Y g:i A');
        } catch (Throwable $e) {
            return $value;
        }
    }

    function fbgAdminRegistrationSortUrl(string $sort, string $currentSort, string $currentDirection): string
    {
        $direction = ($sort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
        $query = $_GET;
        $query['sort'] = $sort;
        $query['dir'] = $direction;
        $query['page_num'] = 1;

        return './page.php?' . http_build_query($query);
    }

    $search = trim((string)($_GET['q'] ?? ''));
    $filter = strtolower(trim((string)($_GET['status'] ?? 'active')));
    $sort = strtolower(trim((string)($_GET['sort'] ?? 'created')));
    $direction = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
    $perPage = 25;
    $pageNum = fbgPaginationRequestedPage();
    $offset = ($pageNum - 1) * $perPage;

    $allowedFilters = ['all', 'active', 'pending', 'verified', 'expired', 'rejected', 'completed'];
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'active';
    }

    $createdSortColumn = fbgPendingRegistrationColumnExists('created_at') ? 'created_at' : 'id';
    $sortMap = [
        'created' => $createdSortColumn,
        'username' => 'username',
        'email' => 'email',
        'expires' => 'verification_expires_at',
        'status' => "CASE
            WHEN consumed_at IS NOT NULL THEN 5
            WHEN rejected_at IS NOT NULL THEN 4
            WHEN email_verified_at IS NOT NULL THEN 3
            WHEN verification_expires_at < UTC_TIMESTAMP() THEN 2
            ELSE 1
        END",
    ];

    if (!array_key_exists($sort, $sortMap)) {
        $sort = 'created';
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(username LIKE :search OR email LIKE :search OR first_name LIKE :search OR last_name LIKE :search OR ip_address LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($filter === 'active') {
        $where[] = 'consumed_at IS NULL AND rejected_at IS NULL';
    } elseif ($filter === 'pending') {
        $where[] = 'consumed_at IS NULL AND rejected_at IS NULL AND email_verified_at IS NULL AND verification_expires_at >= UTC_TIMESTAMP()';
    } elseif ($filter === 'verified') {
        $where[] = 'consumed_at IS NULL AND rejected_at IS NULL AND email_verified_at IS NOT NULL';
    } elseif ($filter === 'expired') {
        $where[] = "consumed_at IS NULL AND ((rejected_at IS NOT NULL AND rejection_reason = :expired_reason) OR (email_verified_at IS NULL AND verification_expires_at < UTC_TIMESTAMP()))";
        $params['expired_reason'] = FbgRegistrationRejectionReason::VERIFICATION_EXPIRED;
    } elseif ($filter === 'rejected') {
        $where[] = 'rejected_at IS NOT NULL AND (rejection_reason IS NULL OR rejection_reason <> :expired_reason)';
        $params['expired_reason'] = FbgRegistrationRejectionReason::VERIFICATION_EXPIRED;
    } elseif ($filter === 'completed') {
        $where[] = 'consumed_at IS NOT NULL';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);
    $pdo = db();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pending_registrations {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
    $pageNum = $pagination['page_num'];
    $totalPages = $pagination['total_pages'];
    $offset = $pagination['offset'];

    $listStmt = $pdo->prepare("
        SELECT *
        FROM pending_registrations
        {$whereSql}
        ORDER BY {$orderSql}, id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $registrations = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Registrations</h1>
                <p class="fbg-admin-subtext">Review pending, verified, rejected, expired, and completed account registrations.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="fbg-dashboard-alert <?= $messageType === 'error' ? 'error' : 'success' ?> is-visible" style="margin-bottom: 20px;">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Pending Registrations</h2>
                    <form method="POST" class="fbg-admin-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="cleanup_registrations">
                        <button type="submit" class="btn btn-sm">Run Cleanup</button>
                    </form>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-registrations">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="registration-search">Search</label>
                            <input id="registration-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Username, email, name, or IP">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration-status">Status</label>
                            <select id="registration-status" name="status">
                                <?php foreach ($allowedFilters as $option): ?>
                                    <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $filter === $option ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($option), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration-sort">Sort</label>
                            <select id="registration-sort" name="sort">
                                <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Created</option>
                                <option value="username" <?= $sort === 'username' ? 'selected' : '' ?>>Username</option>
                                <option value="email" <?= $sort === 'email' ? 'selected' : '' ?>>Email</option>
                                <option value="expires" <?= $sort === 'expires' ? 'selected' : '' ?>>Expires</option>
                                <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="registration-dir">Direction</label>
                            <select id="registration-dir" name="dir">
                                <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                                <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            </select>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap fbg-registration-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th><a href="<?= htmlspecialchars(fbgAdminRegistrationSortUrl('created', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Created</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminRegistrationSortUrl('username', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Username</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminRegistrationSortUrl('email', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Email</a></th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>IP</th>
                                <th>Verified</th>
                                <th>Expires</th>
                                <th>Resends</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registrations)): ?>
                                <tr>
                                    <td colspan="10">No registrations found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($registrations as $registration): ?>
                                <?php
                                $canDelete = empty($registration['consumed_at']);
                                $canModify = $canDelete && empty($registration['rejected_at']);
                                $canManualApprove = $canModify && empty($registration['email_verified_at']);
                                $canSetPassword = $canModify && !empty($registration['email_verified_at']);
                                $registrationLabel = trim((string)($registration['username'] ?? ''));
                                if ($registrationLabel === '') {
                                    $registrationLabel = (string)($registration['email'] ?? 'registration');
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(fbgAdminRegistrationDate($registration['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($registration['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($registration['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminRegistrationStatus($registration), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($registration['rejection_reason'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($registration['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminRegistrationDate($registration['email_verified_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminRegistrationDate($registration['verification_expires_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int)($registration['verification_resend_count'] ?? 0) ?></td>
                                    <td>
                                        <?php if ($canModify || $canDelete): ?>
                                            <div class="fbg-registration-actions-menu" data-registration-actions>
                                                <button
                                                    type="button"
                                                    class="fbg-registration-actions-trigger"
                                                    data-registration-actions-trigger
                                                    aria-label="Open registration actions"
                                                    aria-expanded="false">
                                                    <i class="fas fa-ellipsis" aria-hidden="true"></i>
                                                </button>

                                                <div class="fbg-registration-actions-dropdown" data-registration-actions-dropdown>
                                                    <?php if ($canModify): ?>
                                                        <form method="POST" class="fbg-registration-actions-form">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="resend_verification">
                                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                                            <button type="submit">Resend Verification</button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($canManualApprove): ?>
                                                        <button
                                                            type="button"
                                                            data-registration-approve-trigger
                                                            data-registration-id="<?= (int)$registration['id'] ?>"
                                                            data-registration-label="<?= htmlspecialchars($registrationLabel, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-registration-email="<?= htmlspecialchars((string)($registration['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            Manual Approval
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($canSetPassword): ?>
                                                        <button
                                                            type="button"
                                                            data-registration-password-trigger
                                                            data-registration-id="<?= (int)$registration['id'] ?>"
                                                            data-registration-label="<?= htmlspecialchars($registrationLabel, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-registration-email="<?= htmlspecialchars((string)($registration['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            Set Password
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($canDelete): ?>
                                                        <form
                                                            method="POST"
                                                            class="fbg-registration-actions-form"
                                                            onsubmit="return confirm('Delete this pending registration? This cannot be undone.');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="delete_registration">
                                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                                            <button type="submit" class="fbg-registration-action-danger">Delete Registration</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'registration'); ?>
            </section>
        </div>
    </div>
</section>

<div class="fbg-modal-overlay" id="registration-approval-modal" hidden>
    <div class="fbg-modal-card fbg-registration-approval-modal" role="dialog" aria-modal="true" aria-labelledby="registration-approval-title">
        <button type="button" class="fbg-modal-close" id="registration-approval-close" aria-label="Close">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>

        <div class="fbg-modal-header">
            <h3 id="registration-approval-title">Manual Approval</h3>
            <p id="registration-approval-description">Approve this registration and choose how the account should be completed.</p>
        </div>

        <form method="POST" class="fbg-admin-form" id="registration-approval-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="registration_id" id="registration-approval-id" value="">

            <div class="fbg-admin-field">
                <label>Registration</label>
                <input type="text" id="registration-approval-label" value="" disabled>
            </div>

            <div class="fbg-admin-field">
                <label for="registration-approval-reason">Approval Reason</label>
                <textarea id="registration-approval-reason" name="manual_approval_reason" rows="4" maxlength="500" required></textarea>
            </div>

            <div class="fbg-registration-approval-passwords" id="registration-approval-password-fields">
                <div class="fbg-admin-field">
                    <label for="registration-approval-password">Password</label>
                    <input
                        id="registration-approval-password"
                        type="password"
                        name="manual_approval_password"
                        autocomplete="new-password">
                </div>

                <div class="fbg-admin-field">
                    <label for="registration-approval-confirm-password">Confirm Password</label>
                    <input
                        id="registration-approval-confirm-password"
                        type="password"
                        name="manual_approval_confirm_password"
                        autocomplete="new-password">
                </div>
            </div>

            <div class="fbg-modal-actions">
                <button type="button" class="btn fbg-neutral-button" id="registration-approval-cancel">Cancel</button>
                <button type="submit" class="btn" name="action" value="manual_approval_email" data-approval-email-submit>Approve and Send Email</button>
                <button type="submit" class="btn" name="action" value="manual_approval_set_password" data-approval-password-submit>Approve and Set Password</button>
            </div>
        </form>
    </div>
</div>

<div class="fbg-modal-overlay" id="registration-password-modal" hidden>
    <div class="fbg-modal-card fbg-registration-approval-modal" role="dialog" aria-modal="true" aria-labelledby="registration-password-title">
        <button type="button" class="fbg-modal-close" id="registration-password-close" aria-label="Close">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>

        <div class="fbg-modal-header">
            <h3 id="registration-password-title">Set Registration Password</h3>
            <p id="registration-password-description">Set a password and finish creating this user's account.</p>
        </div>

        <form method="POST" class="fbg-admin-form" id="registration-password-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="set_password">
            <input type="hidden" name="registration_id" id="registration-password-id" value="">

            <div class="fbg-admin-field">
                <label>Registration</label>
                <input type="text" id="registration-password-label" value="" disabled>
            </div>

            <div class="fbg-registration-approval-passwords">
                <div class="fbg-admin-field">
                    <label for="registration-password">Password</label>
                    <input
                        id="registration-password"
                        type="password"
                        name="set_password"
                        autocomplete="new-password"
                        required>
                </div>

                <div class="fbg-admin-field">
                    <label for="registration-confirm-password">Confirm Password</label>
                    <input
                        id="registration-confirm-password"
                        type="password"
                        name="set_confirm_password"
                        autocomplete="new-password"
                        required>
                </div>
            </div>

            <div class="fbg-modal-actions">
                <button type="button" class="btn fbg-neutral-button" id="registration-password-cancel">Cancel</button>
                <button type="submit" class="btn">Set Password and Create Account</button>
            </div>
        </form>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', () => {
        const menus = Array.from(document.querySelectorAll('[data-registration-actions]'));
        const modal = document.getElementById('registration-approval-modal');
        const modalClose = document.getElementById('registration-approval-close');
        const modalCancel = document.getElementById('registration-approval-cancel');
        const modalId = document.getElementById('registration-approval-id');
        const modalLabel = document.getElementById('registration-approval-label');
        const modalReason = document.getElementById('registration-approval-reason');
        const modalDescription = document.getElementById('registration-approval-description');
        const modalPassword = document.getElementById('registration-approval-password');
        const modalConfirmPassword = document.getElementById('registration-approval-confirm-password');
        const emailSubmit = document.querySelector('[data-approval-email-submit]');
        const passwordSubmit = document.querySelector('[data-approval-password-submit]');
        const setPasswordModal = document.getElementById('registration-password-modal');
        const setPasswordClose = document.getElementById('registration-password-close');
        const setPasswordCancel = document.getElementById('registration-password-cancel');
        const setPasswordId = document.getElementById('registration-password-id');
        const setPasswordLabel = document.getElementById('registration-password-label');
        const setPasswordDescription = document.getElementById('registration-password-description');
        const setPasswordInput = document.getElementById('registration-password');
        const setConfirmPasswordInput = document.getElementById('registration-confirm-password');

        const setPasswordRequired = (isRequired) => {
            if (modalPassword) {
                modalPassword.required = isRequired;
            }

            if (modalConfirmPassword) {
                modalConfirmPassword.required = isRequired;
            }
        };

        const closeMenus = () => {
            menus.forEach((menu) => {
                menu.classList.remove('is-open');
                const trigger = menu.querySelector('[data-registration-actions-trigger]');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        };

        menus.forEach((menu) => {
            const trigger = menu.querySelector('[data-registration-actions-trigger]');
            if (!trigger) return;

            trigger.addEventListener('click', (event) => {
                event.stopPropagation();
                const shouldOpen = !menu.classList.contains('is-open');
                closeMenus();
                menu.classList.toggle('is-open', shouldOpen);
                trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
        });

        const openApprovalModal = (button) => {
            if (!modal || !modalId || !modalLabel || !modalReason) return;

            const label = button.dataset.registrationLabel || 'registration';
            const email = button.dataset.registrationEmail || '';

            modalId.value = button.dataset.registrationId || '';
            modalLabel.value = email !== '' ? `${label} (${email})` : label;
            if (modalDescription) {
                modalDescription.textContent = `Approve ${label}, then either email a password setup link or set the password now.`;
            }
            modalReason.value = '';
            if (modalPassword) modalPassword.value = '';
            if (modalConfirmPassword) modalConfirmPassword.value = '';
            setPasswordRequired(false);
            modal.hidden = false;
            document.body.classList.add('fbg-modal-open');
            closeMenus();
            modalReason.focus();
        };

        const closeApprovalModal = () => {
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('fbg-modal-open');
        };

        const openSetPasswordModal = (button) => {
            if (!setPasswordModal || !setPasswordId || !setPasswordLabel) return;

            const label = button.dataset.registrationLabel || 'registration';
            const email = button.dataset.registrationEmail || '';

            setPasswordId.value = button.dataset.registrationId || '';
            setPasswordLabel.value = email !== '' ? `${label} (${email})` : label;
            if (setPasswordDescription) {
                setPasswordDescription.textContent = `Set a password for ${label} and finish creating this user's account.`;
            }
            if (setPasswordInput) setPasswordInput.value = '';
            if (setConfirmPasswordInput) setConfirmPasswordInput.value = '';
            setPasswordModal.hidden = false;
            document.body.classList.add('fbg-modal-open');
            closeMenus();
            if (setPasswordInput) setPasswordInput.focus();
        };

        const closeSetPasswordModal = () => {
            if (!setPasswordModal) return;
            setPasswordModal.hidden = true;
            document.body.classList.remove('fbg-modal-open');
        };

        document.querySelectorAll('[data-registration-approve-trigger]').forEach((button) => {
            button.addEventListener('click', () => openApprovalModal(button));
        });

        document.querySelectorAll('[data-registration-password-trigger]').forEach((button) => {
            button.addEventListener('click', () => openSetPasswordModal(button));
        });

        if (emailSubmit) {
            emailSubmit.addEventListener('click', () => setPasswordRequired(false));
        }

        if (passwordSubmit) {
            passwordSubmit.addEventListener('click', () => setPasswordRequired(true));
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-registration-actions]')) {
                closeMenus();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenus();
                closeApprovalModal();
                closeSetPasswordModal();
            }
        });

        if (modalClose) modalClose.addEventListener('click', closeApprovalModal);
        if (modalCancel) modalCancel.addEventListener('click', closeApprovalModal);
        if (setPasswordClose) setPasswordClose.addEventListener('click', closeSetPasswordModal);
        if (setPasswordCancel) setPasswordCancel.addEventListener('click', closeSetPasswordModal);
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeApprovalModal();
                }
            });
        }
        if (setPasswordModal) {
            setPasswordModal.addEventListener('click', (event) => {
                if (event.target === setPasswordModal) {
                    closeSetPasswordModal();
                }
            });
        }
    });
</script>
