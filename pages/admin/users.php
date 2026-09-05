<?php
    declare(strict_types=1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/pagination.php';
    require_once __DIR__ . '/../../api/pterodactyl.php';
    require_once __DIR__ . '/../../includes/registration.php';

    requireLogin();

    if (!function_exists('canAccess') || !canAccess(4)) {
        http_response_code(403);
        fbgRedirect('/page.php?name=403');
        return;
    }

    $currentAdminPage = 'admin-users';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $message = (string)($_SESSION['admin_users_message'] ?? '');
    $messageType = (string)($_SESSION['admin_users_message_type'] ?? 'success');
    unset($_SESSION['admin_users_message'], $_SESSION['admin_users_message_type']);

    function fbgAdminUsersRedirect(string $message, string $type = 'success', ?int $editUserId = null): void
    {
        $_SESSION['admin_users_message'] = $message;
        $_SESSION['admin_users_message_type'] = $type;

        $url = '/page.php?name=admin-users';
        if ($editUserId !== null && $editUserId > 0) {
            $url .= '&edit=' . $editUserId;
        }

        fbgRedirect($url);
        exit;
    }

    function fbgAdminUsersVerifyCsrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
            fbgAdminUsersRedirect('Security check failed. Please refresh and try again.', 'error');
        }
    }

    function fbgAdminUsersPanelColumns(): array
    {
        static $columns = null;

        if (is_array($columns)) {
            return $columns;
        }

        try {
            $stmt = fbgPteroDb()->query('SHOW COLUMNS FROM users');
            $columns = array_flip(array_map(
                static fn(array $row): string => strtolower((string)$row['Field']),
                $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
            ));
        } catch (Throwable $e) {
            $columns = [];
        }

        return $columns;
    }

    function fbgAdminUsersColumnExists(string $column): bool
    {
        $columns = fbgAdminUsersPanelColumns();
        return array_key_exists(strtolower($column), $columns);
    }

    function fbgAdminUsersSafeDate(mixed $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
    }

    function fbgAdminUsersAccessLevels(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = db()->prepare("
            SELECT user_id, access_level, is_active
            FROM admin_access
            WHERE user_id IN ({$placeholders})
        ");
        $stmt->execute($userIds);

        $levels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $levels[(int)$row['user_id']] = !empty($row['is_active']) ? (int)$row['access_level'] : 0;
        }

        return $levels;
    }

    function fbgAdminUsersSetAccessLevel(int $userId, int $accessLevel): void
    {
        $accessLevel = max(0, min(4, $accessLevel));
        $isActive = $accessLevel > 0 ? 1 : 0;

        $stmt = db()->prepare('SELECT id FROM admin_access WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $update = db()->prepare("
                UPDATE admin_access
                SET access_level = :access_level,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $update->execute([
                'access_level' => $accessLevel,
                'is_active' => $isActive,
                'id' => $existingId,
            ]);
            return;
        }

        $insert = db()->prepare("
            INSERT INTO admin_access (user_id, access_level, is_active, created_at, updated_at)
            VALUES (:user_id, :access_level, :is_active, NOW(), NOW())
        ");
        $insert->execute([
            'user_id' => $userId,
            'access_level' => $accessLevel,
            'is_active' => $isActive,
        ]);
    }

    function fbgAdminUsersFind(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $columns = [
            'id',
            'email',
            'username',
            'name_first',
            'name_last',
            'language',
            'root_admin',
            'use_totp',
            'totp_secret',
            'credit',
            'created_at',
            'updated_at',
        ];

        foreach (['country', 'zip', 'zip_code', 'address'] as $optionalColumn) {
            if (fbgAdminUsersColumnExists($optionalColumn)) {
                $columns[] = $optionalColumn;
            }
        }

        $select = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', array_unique($columns)));
        $stmt = fbgPteroDb()->prepare("SELECT {$select} FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    function fbgAdminUsersOwnedServers(int $userId): array
    {
        $stmt = fbgPteroDb()->prepare("
            SELECT
                s.id,
                s.uuid,
                s.uuidShort,
                s.name,
                s.created_at,
                s.expired_at,
                n.name AS node_name
            FROM servers s
            LEFT JOIN nodes n ON n.id = s.node_id
            WHERE s.owner_id = :user_id
            ORDER BY s.created_at DESC, s.id DESC
        ");
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function fbgAdminUsersRootAdminLabel(mixed $value): string
    {
        return !empty($value) ? 'Administrator' : 'User';
    }

    function fbgAdminUsersAccessLabel(int $level): string
    {
        return match ($level) {
            4 => 'Administrator',
            3 => 'Manager',
            2 => 'Staff',
            1 => 'Limited',
            default => 'None',
        };
    }

    function fbgAdminUsersCountries(): array
    {
        return [
            'United States',
            'United Kingdom',
            'Afghanistan',
            'Albania',
            'Algeria',
            'Andorra',
            'Angola',
            'Antigua & Deps',
            'Argentina',
            'Armenia',
            'Australia',
            'Austria',
            'Azerbaijan',
            'Bahamas',
            'Bahrain',
            'Bangladesh',
            'Barbados',
            'Belarus',
            'Belgium',
            'Belize',
            'Benin',
            'Bhutan',
            'Bolivia',
            'Bosnia Herzegovina',
            'Botswana',
            'Brazil',
            'Brunei',
            'Bulgaria',
            'Burkina',
            'Burundi',
            'Cambodia',
            'Cameroon',
            'Canada',
            'Cape Verde',
            'Central African Rep',
            'Chad',
            'Chile',
            'China',
            'Colombia',
            'Comoros',
            'Congo',
            'Congo {Democratic Rep}',
            'Costa Rica',
            'Croatia',
            'Cuba',
            'Cyprus',
            'Czech Republic',
            'Denmark',
            'Djibouti',
            'Dominica',
            'Dominican Republic',
            'East Timor',
            'Ecuador',
            'Egypt',
            'El Salvador',
            'Equatorial Guinea',
            'Eritrea',
            'Estonia',
            'Ethiopia',
            'Fiji',
            'Finland',
            'France',
            'Gabon',
            'Gambia',
            'Georgia',
            'Germany',
            'Ghana',
            'Greece',
            'Grenada',
            'Guatemala',
            'Guinea',
            'Guinea-Bissau',
            'Guyana',
            'Haiti',
            'Honduras',
            'Hungary',
            'Iceland',
            'India',
            'Indonesia',
            'Iran',
            'Iraq',
            'Ireland {Republic}',
            'Israel',
            'Italy',
            'Ivory Coast',
            'Jamaica',
            'Japan',
            'Jordan',
            'Kazakhstan',
            'Kenya',
            'Kiribati',
            'Korea North',
            'Korea South',
            'Kosovo',
            'Kuwait',
            'Kyrgyzstan',
            'Laos',
            'Latvia',
            'Lebanon',
            'Lesotho',
            'Liberia',
            'Libya',
            'Liechtenstein',
            'Lithuania',
            'Luxembourg',
            'Macedonia',
            'Madagascar',
            'Malawi',
            'Malaysia',
            'Maldives',
            'Mali',
            'Malta',
            'Marshall Islands',
            'Mauritania',
            'Mauritius',
            'Mexico',
            'Micronesia',
            'Moldova',
            'Monaco',
            'Mongolia',
            'Montenegro',
            'Morocco',
            'Mozambique',
            'Myanmar, {Burma}',
            'Namibia',
            'Nauru',
            'Nepal',
            'Netherlands',
            'New Zealand',
            'Nicaragua',
            'Niger',
            'Nigeria',
            'Norway',
            'Oman',
            'Pakistan',
            'Palau',
            'Panama',
            'Papua New Guinea',
            'Paraguay',
            'Peru',
            'Philippines',
            'Poland',
            'Portugal',
            'Qatar',
            'Romania',
            'Russian Federation',
            'Rwanda',
            'St Kitts & Nevis',
            'St Lucia',
            'Saint Vincent & the Grenadines',
            'Samoa',
            'San Marino',
            'Sao Tome & Principe',
            'Saudi Arabia',
            'Senegal',
            'Serbia',
            'Seychelles',
            'Sierra Leone',
            'Singapore',
            'Slovakia',
            'Slovenia',
            'Solomon Islands',
            'Somalia',
            'South Africa',
            'South Sudan',
            'Spain',
            'Sri Lanka',
            'Sudan',
            'Suriname',
            'Swaziland',
            'Sweden',
            'Switzerland',
            'Syria',
            'Taiwan',
            'Tajikistan',
            'Tanzania',
            'Thailand',
            'Togo',
            'Tonga',
            'Trinidad & Tobago',
            'Tunisia',
            'Turkey',
            'Turkmenistan',
            'Tuvalu',
            'Uganda',
            'Ukraine',
            'United Arab Emirates',
            'Uruguay',
            'Uzbekistan',
            'Vanuatu',
            'Vatican City',
            'Venezuela',
            'Vietnam',
            'Yemen',
            'Zambia',
            'Zimbabwe',
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fbgAdminUsersVerifyCsrf();

        $action = trim((string)($_POST['action'] ?? ''));
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = fbgAdminUsersFind($userId);

        if (!$user) {
            fbgAdminUsersRedirect('User could not be found.', 'error');
        }

        if ($action === 'update_user') {
            $email = trim((string)($_POST['email'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $language = trim((string)($_POST['language'] ?? 'en'));
            $password = (string)($_POST['password'] ?? '');
            $rootAdmin = (int)($_POST['root_admin'] ?? 0) === 1 ? 1 : 0;
            $websiteAccessLevel = max(0, min(4, (int)($_POST['website_access_level'] ?? 0)));
            $credit = trim((string)($_POST['credit'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fbgAdminUsersRedirect('Enter a valid email address.', 'error', $userId);
            }

            if ($username === '' || $firstName === '' || $lastName === '') {
                fbgAdminUsersRedirect('Username, first name, and last name are required.', 'error', $userId);
            }

            if ($language !== 'en') {
                $language = 'en';
            }

            if ($password !== '' && strlen($password) < 8) {
                fbgAdminUsersRedirect('Password must be at least 8 characters when provided.', 'error', $userId);
            }

            if ($userId === (int)($_SESSION['user_id'] ?? 0) && $websiteAccessLevel < 4) {
                fbgAdminUsersRedirect('You cannot lower your own website admin access from this page.', 'error', $userId);
            }

            if ($credit !== '' && !is_numeric($credit)) {
                fbgAdminUsersRedirect('Account balance must be a valid number.', 'error', $userId);
            }

            $changes = [
                'email' => $email,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'language' => $language,
            ];

            if ($password !== '') {
                $changes['password'] = $password;
            }

            $result = pteroUpdatePanelUser($userId, $changes);
            if (empty($result['ok'])) {
                fbgAdminUsersRedirect((string)($result['error'] ?? 'Pterodactyl user profile could not be updated.'), 'error', $userId);
            }

            $pteroUpdates = ['root_admin = :root_admin'];
            $pteroParams = [
                'root_admin' => $rootAdmin,
                'id' => $userId,
            ];

            if (fbgAdminUsersColumnExists('credit')) {
                $pteroUpdates[] = 'credit = :credit';
                $pteroParams['credit'] = number_format((float)$credit, 2, '.', '');
            }

            $optionalFields = [
                'country' => 'country',
                'zip' => 'zip',
                'zip_code' => 'zip_code',
                'address' => 'address',
            ];

            foreach ($optionalFields as $postField => $columnName) {
                if (fbgAdminUsersColumnExists($columnName)) {
                    $pteroUpdates[] = "`{$columnName}` = :{$columnName}";
                    $pteroParams[$columnName] = trim((string)($_POST[$postField] ?? ''));
                }
            }

            $update = fbgPteroDb()->prepare("
                UPDATE users
                SET " . implode(', ', $pteroUpdates) . "
                WHERE id = :id
            ");
            $update->execute($pteroParams);

            fbgAdminUsersSetAccessLevel($userId, $websiteAccessLevel);

            fbgAdminUsersRedirect('User updated successfully.');
        }

        if ($action === 'delete_user') {
            $deleteConfirmation = trim((string)($_POST['delete_confirmation'] ?? ''));

            if ($deleteConfirmation !== 'DELETE') {
                fbgAdminUsersRedirect('Type DELETE to confirm user deletion.', 'error', $userId);
            }

            $servers = fbgAdminUsersOwnedServers($userId);

            if (!empty($servers)) {
                fbgAdminUsersRedirect('This user cannot be deleted while they own servers.', 'error', $userId);
            }

            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                fbgAdminUsersRedirect('You cannot delete your own account from this page.', 'error', $userId);
            }

            $result = pteroRequest('DELETE', 'users/' . $userId);
            if (empty($result['ok']) && (int)($result['status'] ?? 0) !== 204) {
                fbgAdminUsersRedirect((string)($result['error'] ?? 'User could not be deleted.'), 'error', $userId);
            }

            $deleteAccess = db()->prepare('DELETE FROM admin_access WHERE user_id = :user_id');
            $deleteAccess->execute(['user_id' => $userId]);

            fbgDeleteAllRememberedLoginsForUser($userId);
            fbgDeleteRegistrationById((string)$user['email'],(string)$user['username']);
            fbgAdminUsersRedirect('User deleted successfully.', 'warning');
        }

        fbgAdminUsersRedirect('Unknown user action.', 'error', $userId);
    }

    $editUserId = max(0, (int)($_GET['edit'] ?? 0));
    $editingUser = $editUserId > 0 ? fbgAdminUsersFind($editUserId) : null;
    $editingAccessLevel = $editingUser ? getUserAccessLevel($editUserId) : 0;
    $editingServers = $editingUser ? fbgAdminUsersOwnedServers($editUserId) : [];
    $countryOptions = fbgAdminUsersCountries();
    $currentAdminUserId = (int)($_SESSION['user_id'] ?? 0);
    $deleteDisabledReason = '';

    if ($editingUser) {
        if (!empty($editingServers)) {
            $deleteDisabledReason = 'This user owns ' . count($editingServers) . ' server' . (count($editingServers) === 1 ? '' : 's') . ' and cannot be deleted.';
        } elseif ((int)$editingUser['id'] === $currentAdminUserId) {
            $deleteDisabledReason = 'You cannot delete your own account from this page.';
        }
    }

    $search = trim((string)($_GET['q'] ?? ''));
    $sort = strtolower(trim((string)($_GET['sort'] ?? 'id')));
    $direction = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
    $perPage = 25;
    $pageNum = fbgPaginationRequestedPage();
    $offset = ($pageNum - 1) * $perPage;

    $sortMap = [
        'id' => 'u.id',
        'email' => 'u.email',
        'first' => 'u.name_first',
        'last' => 'u.name_last',
        'username' => 'u.username',
        'servers' => 'server_count',
        'panel' => 'u.root_admin',
    ];

    if (!array_key_exists($sort, $sortMap)) {
        $sort = 'id';
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(
            u.email LIKE :search_email
            OR u.username LIKE :search_username
            OR u.name_first LIKE :search_first
            OR u.name_last LIKE :search_last
            OR CAST(u.id AS CHAR) = :exact_search
        )';

        $searchLike = '%' . $search . '%';

        $params['search_email'] = $searchLike;
        $params['search_username'] = $searchLike;
        $params['search_first'] = $searchLike;
        $params['search_last'] = $searchLike;
        $params['exact_search'] = $search;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = fbgPteroDb()->prepare("SELECT COUNT(*) FROM users u {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $pagination = fbgNormalizePagination($totalRows, $pageNum, $perPage);
    $pageNum = $pagination['page_num'];
    $totalPages = $pagination['total_pages'];
    $offset = $pagination['offset'];

    $orderSql = $sortMap[$sort] . ' ' . strtoupper($direction);
    $usersStmt = fbgPteroDb()->prepare("
        SELECT
            u.id,
            u.email,
            u.username,
            u.name_first,
            u.name_last,
            u.root_admin,
            u.use_totp,
            u.totp_secret,
            u.credit,
            (
                SELECT COUNT(*)
                FROM servers s
                WHERE s.owner_id = u.id
            ) AS server_count
        FROM users u
        {$whereSql}
        ORDER BY {$orderSql}, u.id ASC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $usersStmt->execute($params);
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $accessLevels = fbgAdminUsersAccessLevels(array_column($users, 'id'));

    function fbgAdminUsersSortUrl(string $targetSort, string $currentSort, string $currentDirection): string
    {
        $direction = ($targetSort === $currentSort && $currentDirection === 'asc') ? 'desc' : 'asc';
        $query = $_GET;
        $query['name'] = 'admin-users';
        $query['sort'] = $targetSort;
        $query['dir'] = $direction;
        $query['page_num'] = 1;

        return './page.php?' . http_build_query($query);
    }
?>

<section class="fbg-admin-shell">
    <?php include __DIR__ . '/../../pages/admin/includes/admin-sidebar.php'; ?>

    <div class="fbg-admin-main">
        <header class="fbg-admin-header">
            <div class="fbg-admin-hero-card">
                <p class="fbg-admin-kicker">Administration</p>
                <h1>Users</h1>
                <p class="fbg-admin-subtext">Manage website access, Pterodactyl identity, account balance, and user-owned servers.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <script>
                window.FBGToast?.({
                    type: <?= json_encode($messageType) ?>,
                    title: 'User Manager',
                    message: <?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                });
            </script>
        <?php endif; ?>

        <div class="fbg-admin-grid">
            <section class="fbg-admin-panel fbg-admin-panel-full">
                <div class="fbg-admin-panel-header">
                    <h2>Users</h2>
                </div>

                <form method="GET" class="fbg-admin-form" action="./page.php">
                    <input type="hidden" name="name" value="admin-users">

                    <div class="fbg-admin-form-grid">
                        <div class="fbg-admin-field">
                            <label for="user-search">Search</label>
                            <input id="user-search" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, email, username, or name">
                        </div>

                        <div class="fbg-admin-field">
                            <label for="user-sort">Sort</label>
                            <select id="user-sort" name="sort">
                                <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>User ID</option>
                                <option value="email" <?= $sort === 'email' ? 'selected' : '' ?>>Email</option>
                                <option value="first" <?= $sort === 'first' ? 'selected' : '' ?>>First Name</option>
                                <option value="last" <?= $sort === 'last' ? 'selected' : '' ?>>Last Name</option>
                                <option value="username" <?= $sort === 'username' ? 'selected' : '' ?>>Username</option>
                                <option value="servers" <?= $sort === 'servers' ? 'selected' : '' ?>>Owned Servers</option>
                                <option value="panel" <?= $sort === 'panel' ? 'selected' : '' ?>>Panel Access</option>
                            </select>
                        </div>

                        <div class="fbg-admin-field">
                            <label for="user-dir">Direction</label>
                            <select id="user-dir" name="dir">
                                <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
                                <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                            </select>
                        </div>
                    </div>

                    <div class="fbg-admin-form-actions">
                        <button type="submit" class="btn">Apply Filters</button>
                    </div>
                </form>

                <div class="fbg-admin-table-wrap">
                    <table class="fbg-admin-table">
                        <thead>
                            <tr>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('id', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">ID</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('email', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Email</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('first', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">First</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('last', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Last</a></th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('username', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Username</a></th>
                                <th>2FA</th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('servers', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Servers</a></th>
                                <th>Website Access</th>
                                <th><a href="<?= htmlspecialchars(fbgAdminUsersSortUrl('panel', $sort, $direction), ENT_QUOTES, 'UTF-8') ?>">Panel Access</a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="9">No users found.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($users as $user): ?>
                                <?php
                                $userId = (int)$user['id'];
                                $hasTwoFactor = !empty($user['use_totp']) || trim((string)($user['totp_secret'] ?? '')) !== '';
                                $accessLevel = (int)($accessLevels[$userId] ?? 0);
                                ?>
                                <tr>
                                    <td><?= $userId ?></td>
                                    <td>
                                        <a class="fbg-admin-branded-link" href="./page.php?name=admin-users&edit=<?= $userId ?>">
                                            <?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars((string)$user['name_first'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)$user['name_last'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $hasTwoFactor ? 'Enabled' : 'Disabled' ?></td>
                                    <td><?= (int)$user['server_count'] ?></td>
                                    <td><?= htmlspecialchars(fbgAdminUsersAccessLabel($accessLevel), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fbgAdminUsersRootAdminLabel($user['root_admin']), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php fbgRenderPagination($pagination, 'user'); ?>
            </section>

            <?php if ($editUserId > 0 && !$editingUser): ?>
                <section class="fbg-admin-panel fbg-admin-panel-full">
                    <div class="fbg-admin-empty-state">
                        <p>User could not be found.</p>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($editingUser): ?>
    <div class="fbg-modal-overlay" id="admin-user-edit-modal">
        <div class="fbg-modal-card fbg-admin-user-modal" role="dialog" aria-modal="true" aria-labelledby="admin-user-edit-title">
            <a class="fbg-modal-close fbg-admin-user-modal-close" href="./page.php?name=admin-users" aria-label="Close">X</a>

            <div class="fbg-modal-header">
                <h3 id="admin-user-edit-title">Edit <?= htmlspecialchars((string)$editingUser['email'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p>Update identity, permissions, personal details, and account balance for this Pterodactyl user.</p>
            </div>

                    <form method="POST" class="fbg-admin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" value="<?= (int)$editingUser['id'] ?>">

                        <h3>Identity</h3>
                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="edit-email">Email</label>
                                <input id="edit-email" name="email" type="email" required value="<?= htmlspecialchars((string)$editingUser['email'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-username">Username</label>
                                <input id="edit-username" name="username" type="text" required value="<?= htmlspecialchars((string)$editingUser['username'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-first-name">First Name</label>
                                <input id="edit-first-name" name="first_name" type="text" required value="<?= htmlspecialchars((string)$editingUser['name_first'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-last-name">Last Name</label>
                                <input id="edit-last-name" name="last_name" type="text" required value="<?= htmlspecialchars((string)$editingUser['name_last'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-language">Default Language</label>
                                <select id="edit-language" name="language">
                                    <option value="en" selected>English</option>
                                </select>
                            </div>
                        </div>

                        <h3>Password</h3>
                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field fbg-admin-field-full">
                                <label for="edit-password">Password</label>
                                <input id="edit-password" name="password" type="password" autocomplete="new-password" placeholder="Leave blank to keep this user's current password">
                                <p class="fbg-admin-help-text">The user will not receive a notification if an admin changes this password.</p>
                            </div>
                        </div>

                        <h3>Permissions</h3>
                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="edit-root-admin">Backend Panel Administrator</label>
                                <select id="edit-root-admin" name="root_admin">
                                    <option value="0" <?= empty($editingUser['root_admin']) ? 'selected' : '' ?>>No</option>
                                    <option value="1" <?= !empty($editingUser['root_admin']) ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-website-access">Main Website Permissions</label>
                                <select id="edit-website-access" name="website_access_level">
                                    <option value="0" <?= $editingAccessLevel === 0 ? 'selected' : '' ?>>None</option>
                                    <option value="1" <?= $editingAccessLevel === 1 ? 'selected' : '' ?>>Limited</option>
                                    <option value="2" <?= $editingAccessLevel === 2 ? 'selected' : '' ?>>Staff</option>
                                    <option value="3" <?= $editingAccessLevel === 3 ? 'selected' : '' ?>>Manager</option>
                                    <option value="4" <?= $editingAccessLevel === 4 ? 'selected' : '' ?>>Administrator</option>
                                </select>
                            </div>
                        </div>

                        <h3>Personal Details</h3>
                        <div class="fbg-admin-form-grid">
                            <div class="fbg-admin-field">
                                <label for="edit-country">Country</label>
                                <input id="edit-country" name="country" type="text" list="admin-user-country-options" value="<?= htmlspecialchars((string)($editingUser['country'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= fbgAdminUsersColumnExists('country') ? '' : 'disabled' ?>>
                                <datalist id="admin-user-country-options">
                                    <?php foreach ($countryOptions as $country): ?>
                                        <option value="<?= htmlspecialchars($country, ENT_QUOTES, 'UTF-8') ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-zip">Zip Code</label>
                                <input id="edit-zip" name="<?= fbgAdminUsersColumnExists('zip') ? 'zip' : 'zip_code' ?>" type="text" value="<?= htmlspecialchars((string)($editingUser['zip'] ?? $editingUser['zip_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (fbgAdminUsersColumnExists('zip') || fbgAdminUsersColumnExists('zip_code')) ? '' : 'disabled' ?>>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-address">Address</label>
                                <input id="edit-address" name="address" type="text" value="<?= htmlspecialchars((string)($editingUser['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= fbgAdminUsersColumnExists('address') ? '' : 'disabled' ?>>
                            </div>

                            <div class="fbg-admin-field">
                                <label for="edit-credit">Wallet Balance</label>
                                <input id="edit-credit" name="credit" type="number" step="0.01" min="0" value="<?= htmlspecialchars(number_format((float)($editingUser['credit'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="fbg-admin-form-actions fbg-admin-user-modal-actions">
                            <button type="submit" class="btn">Save User</button>
                            <a class="btn fbg-neutral-button" href="./page.php?name=admin-users">Cancel</a>

                            <span class="fbg-admin-user-delete-wrap" title="<?= htmlspecialchars($deleteDisabledReason, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="btn btn-delete fbg-admin-user-delete-button" id="admin-user-delete-open" <?= $deleteDisabledReason !== '' ? 'disabled' : '' ?>>
                                    Delete User
                                </button>

                                <?php if ($deleteDisabledReason !== ''): ?>
                                    <span class="fbg-admin-help-text fbg-admin-user-delete-note">
                                        <?= htmlspecialchars($deleteDisabledReason, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </form>

                    <hr>

                    <div class="fbg-admin-panel-header">
                        <h2>Associated Servers</h2>
                    </div>

                    <div class="fbg-admin-table-wrap">
                        <table class="fbg-admin-table">
                            <thead>
                                <tr>
                                    <th>Server ID</th>
                                    <th>Server Name</th>
                                    <th>Node Name</th>
                                    <th>Creation Date</th>
                                    <th>Expiration Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($editingServers)): ?>
                                    <tr>
                                        <td colspan="5">No servers are associated with this account.</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($editingServers as $server): ?>
                                    <tr>
                                        <td><?= (int)$server['id'] ?></td>
                                        <td><?= htmlspecialchars((string)$server['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string)($server['node_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(fbgAdminUsersSafeDate($server['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(fbgAdminUsersSafeDate($server['expired_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="fbg-modal-overlay fbg-admin-user-delete-confirm-overlay" id="admin-user-delete-confirm" hidden>
                        <div class="fbg-modal-card fbg-admin-user-delete-confirm" role="dialog" aria-modal="true" aria-labelledby="admin-user-delete-confirm-title">
                            <div class="fbg-modal-header">
                                <h3 id="admin-user-delete-confirm-title">Delete User</h3>
                                <p>This is a destructive action and cannot be undone.</p>
                            </div>

                            <form method="POST" id="admin-user-delete-form" class="fbg-admin-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int)$editingUser['id'] ?>">

                                <div class="fbg-admin-warning-box">
                                    Deleting <?= htmlspecialchars((string)$editingUser['email'], ENT_QUOTES, 'UTF-8') ?> will permanently remove this Pterodactyl user and their website admin access records. This action cannot be undone.
                                </div>

                                <div class="fbg-admin-field">
                                    <label for="admin-user-delete-confirm-input">Type DELETE to confirm</label>
                                    <input id="admin-user-delete-confirm-input" name="delete_confirmation" type="text" autocomplete="off" spellcheck="false">
                                </div>

                                <div class="fbg-admin-form-actions fbg-admin-user-delete-confirm-actions">
                                    <button type="button" class="btn fbg-neutral-button" id="admin-user-delete-cancel">Cancel</button>
                                    <button type="submit" class="btn btn-delete fbg-admin-user-delete-confirm-submit" id="admin-user-delete-submit" disabled>Delete User</button>
                                </div>
                            </form>
                        </div>
                    </div>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('admin-user-edit-modal');
        if (!modal) return;
        const deleteOpen = document.getElementById('admin-user-delete-open');
        const deleteConfirm = document.getElementById('admin-user-delete-confirm');
        const deleteCancel = document.getElementById('admin-user-delete-cancel');
        const deleteInput = document.getElementById('admin-user-delete-confirm-input');
        const deleteSubmit = document.getElementById('admin-user-delete-submit');

        document.body.classList.add('fbg-modal-open');

        const closeDeleteConfirm = () => {
            if (!deleteConfirm) return;
            deleteConfirm.hidden = true;
            if (deleteInput) {
                deleteInput.value = '';
            }
            if (deleteSubmit) {
                deleteSubmit.disabled = true;
            }
        };

        if (deleteOpen && deleteConfirm) {
            deleteOpen.addEventListener('click', () => {
                deleteConfirm.hidden = false;
                if (deleteInput) {
                    deleteInput.focus();
                }
            });
        }

        if (deleteCancel) {
            deleteCancel.addEventListener('click', closeDeleteConfirm);
        }

        if (deleteInput && deleteSubmit) {
            deleteInput.addEventListener('input', () => {
                deleteSubmit.disabled = deleteInput.value !== 'DELETE';
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (deleteConfirm && !deleteConfirm.hidden) {
                    closeDeleteConfirm();
                    return;
                }

                window.location.href = './page.php?name=admin-users';
            }
        });
    });
</script>
