# TODO

## Frontend Shop

- [x] Build credit balance display and transaction history on the frontend.
- [x] Build the add-credit flow with Stripe and PayPal.
- [x] Replace the servers placeholder with a real category and plan catalog.
- [x] Add purchase-with-credit provisioning through the Pterodactyl API.
- [x] Add frontend payment settings management for Stripe, PayPal, and balance limits.
- [x] Add server purchase history to the Manage Wallet page.
- [x] Record shop purchase metadata for renewals and 30-day expiration tracking.
- [x] Remove server-panel install-state hard reloads and stabilize polling.
- [x] Add admin CRUD for shop categories and server plans.
- [ ] Add order/invoice detail pages for completed balance uploads and server purchases.
- [ ] Add invoice support after the core shop loop is stable.

## Shared Frontend Utilities

- [ ] Create proper pagination function/convert current per-page functions to global.
- [ ] Create toast notification function/convert current per-page functions to global.

## Registration Security

- [x] Add guarded `pending_registrations` schema upgrades for rejection, IP, resend, and manual approval metadata.
- [x] Add centralized registration rejection reason constants.
- [x] Add registration security settings defaults through the existing `site_settings` architecture.
- [x] Add trusted-client-IP detection for Cloudflare/reverse-proxy-safe rate limiting.
- [x] Replace immediate expired-registration deletion with mark-expired plus retention cleanup.
- [x] Add invisible randomized honeypot protection to the registration form.
- [x] Add server-side registration timing protection.
- [x] Add configurable IP rate limiting for registration attempts.
- [x] Extract shared pending-registration account creation service from `complete-registration.php`.
- [x] Add safe, repeatable cleanup task/entrypoint for expired retained registrations.
- [x] Add Registration Settings controls to the Admin Settings page.
- [x] Add Pending Registrations admin management page with pagination/search/filter/sort.
- [x] Add admin resend-verification action with new token, cooldown, counters, and delivery reporting.
- [x] Add admin manual approval action with required reason and duplicate username/email checks.
- [x] Add admin manual delete action for pending registrations.
- [x] Add admin set-password completion flow for pending registrations that need account creation finalized.
- [x] Add registration statistics to the admin dashboard.
- [ ] Add registration security logging without passwords, plaintext tokens, token hashes, or session data.
- [ ] Add or document coverage for legitimate registration, bot rejection paths, resend, manual approval, cleanup, and stats.

## Admin User Management

- [x] Add an Admin Users page and route.
- [x] Add Admin Users sidebar/navigation entry.
- [x] Build a users table similar to the Admin Registrations table.
- [x] Display user ID, email, first name, last name, username, 2FA status, owned server count, website access level, and Pterodactyl panel access level.
- [x] Make the user's email address link to a dedicated edit/details page.
- [x] Add search, filter, sort, and pagination controls for users.
- [x] Load user list data from the Pterodactyl users table plus the `mainsite.admin_access` table.
- [x] Add a user profile edit page.
- [x] Add Identity controls for email, username, first name, last name, and default language.
- [x] Limit default language options to English for now.
- [x] Add optional admin password reset field that keeps the current password when left blank.
- [x] Add clear admin copy explaining that users are not automatically notified when an admin changes their password.
- [x] Add Permissions controls for Pterodactyl backend administrator access.
- [x] Add Permissions controls for main website access level using `mainsite.admin_access.user_id`, `access_level`, and `is_active`.
- [x] Add Personal Details controls for country, zip code, address, and account balance/credit.
- [x] Decide whether account balance edits should be direct, ledger-backed, or both.
- [x] Add Associated Servers table with server ID, server name, node name, creation date, and expiration date.
- [x] Add user deletion action.
- [x] Block user deletion when any servers are associated with the account.
- [x] Add confirmation modal for user deletion.
- [x] Add CSRF protection and audit-safe error handling to all user admin write actions.
- [x] Use Pterodactyl API updates where possible for panel user changes.
- [x] Keep direct database writes limited to website/admin-access and shop balance data where appropriate.

## Admin Server Administration

- [x] Add an Admin Servers page and route.
- [x] Add Admin Servers sidebar/navigation entry.
- [x] Add server list table with search, filters, sorting, and pagination.
- [x] Make server rows link to a dedicated admin server details page.
- [x] Add quick button from admin server details to the existing frontend server panel/console page.
- [x] Add tabbed admin server layout: About, Details, Build Configuration, Startup, Database, Mounts, Manage, and Delete.

### Admin Server About Tab

- [x] Display server owner ID.
- [x] Display owner username, first name, and last name.
- [x] Display node name.
- [x] Add small owner/node icons or visual identifiers.
- [x] Display internal server ID.
- [x] Display external identifier.
- [x] Display UUID / Docker container ID.
- [x] Display current egg.
- [x] Display server name.
- [x] Display CPU limit.
- [x] Display CPU pinning.
- [x] Display memory.
- [x] Display disk space.
- [x] Display block IO weight.
- [x] Display default connection allocation.
- [x] Display connection alias.

### Admin Server Details Tab

- [x] Add editable server name field.
- [x] Require server name before saving.
- [x] Add editable external identifier field.
- [x] Add grouped shop plan picker.
- [x] Save selected shop plan ID for renewal handling.
- [x] Add editable expiration date field.
- [x] Add editable server description field.
- [x] Add searchable server owner selector.
- [x] Search owner selector after two or more typed characters.
- [x] Search users by matching database user fields that start with the typed value.
- [x] Save selected user as the new server owner.
- [x] Validate ownership changes carefully before saving.

### Admin Server Build Configuration Tab

- [x] Add Resource Management controls.
- [x] Add CPU limit field.
- [x] Add CPU pinning field.
- [x] Add allocated memory field.
- [x] Add allocated swap field.
- [x] Add disk space limit field.
- [x] Add block IO weight field.
- [x] Add OOM killer toggle.
- [x] Add Application Feature Limits controls.
- [x] Add database limit field.
- [x] Add allocation/port limit field.
- [x] Add backup limit field.
- [x] Add Allocation Management controls.
- [x] Add default game port selector.
- [x] Add assign additional ports selector.
- [x] Add remove additional ports selector.

### Admin Server Startup Tab

- [x] Add startup command modification.
- [x] Add Service Configuration controls for nest and egg.
- [x] Add skip egg install script toggle.
- [x] Add warning copy for destructive nest/egg changes.
- [x] Add Docker image configuration.
- [x] Display startup variable options similar to the frontend server panel startup tab.
- [x] Allow startup variable editing where supported.
- [x] Handle nest/egg changes through Pterodactyl-safe update/reinstall flow.

### Admin Server Database Tab

- [x] Display active databases for the server.
- [x] Add create database form.
- [x] Add database host selector.
- [x] Add database name field.
- [x] Add connections-from field.
- [x] Add concurrent connections field.
- [x] Add database deletion/reset actions.

### Admin Server Mounts Tab

- [x] Display available mounts for the server.
- [x] Only show mounts available for the server's egg and node.
- [x] Display mount ID, name, source, target, and mounted status.
- [x] Add mount/unmount actions.

### Admin Server Manage Tab

- [x] Add reinstall server action.
- [x] Add install status toggle/action.
- [x] Add suspend/unsuspend server action.
- [x] Add transfer server action.
- [x] Add warning copy for destructive or high-risk actions.
- [x] Add confirmation modals for manage actions.

### Admin Server Delete Tab

- [x] Add server delete action.
- [x] Require strong confirmation before deleting.
- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server purchase/history records should remain for billing/audit history.

## Admin Database Host Management

- [x] Add backend Database Hosts admin page and route.
- [x] Add Database Hosts sidebar/navigation entry.
- [x] Add database host list table with ID, name, host, port, username, database count, and linked node.
- [x] Add Create New Database Host modal.
- [x] Add create form fields for name, host, port, username, password, and linked node.
- [x] Add clear `WITH GRANT OPTION` warning copy for database host credentials.
- [x] Validate database host create/update inputs before saving.
- [x] Allow clicking an existing database host to open a details/edit modal.
- [x] Show editable host details: name, host, port, and linked node.
- [x] Show editable user details: username and optional password replacement.
- [x] Show associated databases for the selected database host.
- [x] Add save/update action for database host details.
- [x] Add delete action for database hosts.
- [x] Guard database host deletion when associated databases exist.
- [x] Add confirmation modal for database host deletion.
