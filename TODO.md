# TODO

## Frontend Shop

- [x] Build credit balance display and transaction history on the frontend.
- [x] Build the add-credit flow with Stripe and PayPal.
- [x] Replace the servers placeholder with a real category and plan catalog.
- [x] Add purchase-with-credit provisioning through the Pterodactyl API.
- [x] Add frontend payment settings management for Stripe, PayPal, and balance limits.
- [x] Add server purchase history to the Manage Balance page.
- [x] Record shop purchase metadata for renewals and 30-day expiration tracking.
- [x] Remove server-panel install-state hard reloads and stabilize polling.
- [x] Add admin CRUD for shop categories and server plans.
- [ ] Add order/invoice detail pages for completed balance uploads and server purchases.
- [ ] Add invoice support after the core shop loop is stable.

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

- [ ] Add an Admin Servers page and route.
- [ ] Add Admin Servers sidebar/navigation entry.
- [ ] Add server list table with search, filters, sorting, and pagination.
- [ ] Make server rows link to a dedicated admin server details page.
- [ ] Add quick button from admin server details to the existing frontend server panel/console page.
- [ ] Add tabbed admin server layout: About, Details, Build Configuration, Startup, Database, Mounts, Manage, and Delete.

### Admin Server About Tab

- [ ] Display server owner ID.
- [ ] Display owner username, first name, and last name.
- [ ] Display node name.
- [ ] Add small owner/node icons or visual identifiers.
- [ ] Display internal server ID.
- [ ] Display external identifier.
- [ ] Display UUID / Docker container ID.
- [ ] Display current egg.
- [ ] Display server name.
- [ ] Display CPU limit.
- [ ] Display CPU pinning.
- [ ] Display memory.
- [ ] Display disk space.
- [ ] Display block IO weight.
- [ ] Display default connection allocation.
- [ ] Display connection alias.

### Admin Server Details Tab

- [ ] Add editable server name field.
- [ ] Require server name before saving.
- [ ] Add editable external identifier field.
- [ ] Add editable expiration date field.
- [ ] Add editable server description field.
- [ ] Add searchable server owner selector.
- [ ] Search owner selector after two or more typed characters.
- [ ] Search users by matching database user fields that start with the typed value.
- [ ] Save selected user as the new server owner.
- [ ] Validate ownership changes carefully before saving.

### Admin Server Build Configuration Tab

- [ ] Add Resource Management controls.
- [ ] Add CPU limit field.
- [ ] Add CPU pinning field.
- [ ] Add allocated memory field.
- [ ] Add allocated swap field.
- [ ] Add disk space limit field.
- [ ] Add block IO weight field.
- [ ] Add OOM killer toggle.
- [ ] Add Application Feature Limits controls.
- [ ] Add database limit field.
- [ ] Add allocation/port limit field.
- [ ] Add backup limit field.
- [ ] Add Allocation Management controls.
- [ ] Add default game port selector.
- [ ] Add assign additional ports selector.
- [ ] Add remove additional ports selector.

### Admin Server Startup Tab

- [ ] Add startup command modification.
- [ ] Add Service Configuration controls for nest and egg.
- [ ] Add skip egg install script toggle.
- [ ] Add warning copy for destructive nest/egg changes.
- [ ] Add Docker image configuration.
- [ ] Display startup variable options similar to the frontend server panel startup tab.
- [ ] Allow startup variable editing where supported.
- [ ] Handle nest/egg changes through Pterodactyl-safe update/reinstall flow.

### Admin Server Database Tab

- [ ] Display active databases for the server.
- [ ] Add create database form.
- [ ] Add database host selector.
- [ ] Add database name field.
- [ ] Add connections-from field.
- [ ] Add concurrent connections field.
- [ ] Add database deletion/reset actions if supported.

### Admin Server Mounts Tab

- [ ] Display available mounts for the server.
- [ ] Only show mounts available for the server's egg and node.
- [ ] Display mount ID, name, source, target, and mounted status.
- [ ] Add mount/unmount actions if supported.

### Admin Server Manage Tab

- [ ] Add reinstall server action.
- [ ] Add install status toggle/action.
- [ ] Add suspend/unsuspend server action.
- [ ] Add transfer server action.
- [ ] Add warning copy for destructive or high-risk actions.
- [ ] Add confirmation modals for manage actions.

### Admin Server Delete Tab

- [ ] Add server delete action.
- [ ] Require strong confirmation before deleting.
- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server purchase/history records should remain for billing/audit history.
