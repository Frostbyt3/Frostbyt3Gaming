# Frostbyt3 Gaming Website

Official Frostbyt3 Gaming website and Pterodactyl-integrated customer/server portal.

This repository is the live website codebase. It powers public site pages, user registration and account management, the customer dashboard, server management tools, admin content tools, and Pterodactyl API integrations.

## Major Features

### Public Website

- Home page with configurable server/service cards
- Public server listing and ordering pages
- News/community/legal pages
- Short-link redirect handling from the root `index.php`
- External redirect interstitial support
- Custom 403 and 404 page fragments
- Privacy notice banner
- Maintenance mode with admin bypass support

### Authentication And Accounts

- Login using Pterodactyl user credentials
- Remember-me login cookies backed by server-side token records
- Public registration flow backed by local pending-registration records
- Email verification before Pterodactyl account creation
- Resend verification flow
- Shared customer-area sidebar used across dashboard, profile, wallet, and server pages
- User Profile page for logged-in users
- Pterodactyl-backed profile updates
- Password change with current-password verification and strength rules
- Email change flow with verification sent to the new email before applying the Pterodactyl update
- Remembered-login cleanup after password/email-sensitive account changes

### Pterodactyl Dashboard

- Dashboard display of accessible Pterodactyl servers with redesigned customer-area layout
- Admin Personal Servers / All Servers scope tabs
- Search filtering across visible dashboard server cards
- Persisted list-mode and card-mode dashboard layouts
- Left-rail navigation with support links and account shortcuts
- Shared sidebar active-state handling without requiring every page to define the same sidebar URLs
- Dashboard summary cards for total servers, running, stopped, starting, memory, and CPU usage
- Session/cache-aware server access syncing to reduce dashboard load time
- Faster server status/stat hydration for both personal and all-server admin views
- Server cards with status, allocation, CPU, memory, disk, access role, node, controls, and progress bars
- CSRF-protected server power controls
- Stale server metadata refresh support via `refresh_servers=1`
- Suspended/expired server display handling
- Server renewal integration

### Frontend Shop And Wallet

- Manage Wallet page with current account balance, balance uploads, transaction history, server purchase history, and shared customer-area sidebar
- Stripe and PayPal add-balance checkout flows using the existing Pterodactyl shop settings model
- Admin payment settings page for Stripe, PayPal, currency, and deposit limits
- Public game server catalog driven by Pterodactyl ShopSystem category and game tables
- Collapsible server plan categories with plan specs, pricing, and balance-aware purchase controls
- Terms-of-service-aware order confirmation modal before plan purchases
- Purchase-with-balance flow that provisions Pterodactyl servers through the Application API
- Shop purchase ledger for recording provisioned server plan name, date, amount, currency, and linked server/game IDs
- Provisioned shop servers receive `product_id` and 30-day `expired_at` metadata for renewal compatibility
- Server renewal deducts account balance, extends expiration by 30 days, and can unsuspend renewed servers

### Server Panel

The server panel includes Pterodactyl-backed tools for:

- Console output and command sending
- Power controls
- Real-time-ish status/stat polling tuned closer to the Pterodactyl experience
- Installing, admin-suspended, and expired-suspended state handling in the panel
- Automatic tab re-rendering when install/suspend status changes without a full page refresh
- Styled installing, suspended, and expired CTA states with Frostbyt3 image assets
- Expired-suspended servers display as `Expired` in the panel while still using suspended access locking
- Manual suspension tracking so admin suspensions do not show renewal content
- Left server rail with quick server switching and admin personal/all scope tabs
- Server details rename/description updates
- File manager browsing, search, row limits, and folder navigation
- Database manager for creating/managing server databases
- File read/write/edit support for allowed editable file types
- New-file creation in the current file-manager directory using the browser editor
- Basic language detection and syntax coloring for `.ini` and `.properties` files in the browser editor
- File upload, download, rename, delete, and folder creation
- Minecraft Modpacks tab with provider search, page sizing, pagination, version selection, and install flow
- Modpack install modal with optional delete-files install and recently installed modpack display
- Auto-hiding modpack install-started toast
- Backups: list, create, download, lock, restore, and delete
- Network allocations: list, create, update notes, set primary, and delete
- Schedules: list, view, create, update, delete, execute, and task management
- Startup settings and startup variable updates
- Subuser listing, creation, update, view, and deletion
- Activity viewing
- Settings tab for renewal, reinstall, and server diagnostics
- Install-state status polling without forced full-page reload loops
- Direct frontend-admin jump link from the server panel for admins
- Shared styled confirmation modal for destructive server-panel actions

### Admin Tools

- Admin dashboard
- Pending registration manager with search/filter/sort, resend, manual approval, manual delete, and set-password account completion actions
- Article/news manager
- Service card manager
- User manager with searchable table, modal editor, country selection, access-level controls, associated-server listing, and guarded delete flow
- Server manager with searchable/filterable server table and modal editor
- Server editor tabs for about, details, build configuration, startup, database, mounts, manage, and delete workflows
- Server creation flow with node/allocation, resource, nest/egg, docker image, and startup-variable controls
- Manual suspend/unsuspend handling that records whether a suspension was admin-triggered
- Site settings, including registration and maintenance settings
- Link shortener management
- File upload manager
- Image upload manager
- WebP-to-PNG conversion tool
- Payment settings manager for frontend shop checkout configuration
- TinyMCE-backed terms/settings editing support for admin payment/shop settings
- Admin sidebar/navigation
- Access-level based admin visibility

### API Layer

- Central Pterodactyl Application API and Client API helper in `api/pterodactyl.php`
- JSON-first server management endpoints under `api/server/`
- Server file endpoints for list, read, write, create file, create folder, upload, download, rename, and delete actions
- Minecraft modpack endpoints for provider search, version lookup, and install requests
- Shop checkout and provisioning endpoints under `api/shop/`
- Server status polling endpoint with install, suspend, manual-suspend, and expired-renewal metadata
- Admin conversion endpoints
- Protected file upload/download endpoint
- Permission checks tied to session-synced server access data

### Shared Frontend UX

- Site-wide styled confirmation helper exposed as `window.FBGConfirm(...)`
- Shared confirmation modal included globally through the header
- Font Awesome SVG/inline-icon CSS support for current Font Awesome rendering
- Dashboard and server-panel controls styled for consistent focus states, icon alignment, and responsive alignment

### Production Error Handling

- Shared production error configuration in `includes/error-handling.php`
- Errors are logged in production instead of displayed to users
- Local/dev requests can still display PHP errors
- PHPMailer SMTP debug output is disabled in production and enabled only for local/dev requests
- API endpoints that return JSON suppress browser error display

## Important Paths

- `page.php` - main page router
- `index.php` - short-link/root redirect entry point
- `includes/` - shared auth, DB, mail, registration, account, layout, and utility helpers
- `api/pterodactyl.php` - Pterodactyl API integration layer
- `api/server/` - server panel JSON endpoints
- `api/server/files/` - server file operation endpoints
- `api/server/modpacks/` - Minecraft modpack provider/version/install endpoints
- `pages/dashboard.php` - customer/admin server dashboard
- `pages/account.php` - user profile page
- `pages/wallet.php` - wallet/balance management page
- `pages/includes/sidebar.php` - shared customer-area sidebar include
- `pages/serverpanel.php` - server panel router
- `pages/serverpanel/` - server panel tab fragments
- `backend/js/confirm-modal.js` - shared confirmation modal helper
- `backend/js/serverpanel/` - server panel tab scripts
- `pages/admin/` - admin tools
- `backend/css/style.css` - primary stylesheet
- `config/secrets.php` - local secret/config values, intentionally ignored by Git

## Git Ignore Notes

The repository intentionally excludes sensitive or bulky live-data paths, including:

- `config/secrets.php`
- `downloads/`
- `backend/uplimg/`
- `backend/fivem/`
- `wow/`

Do not commit API keys, SMTP credentials, database credentials, generated downloads, uploaded images, or game-server payloads.

## Development Notes

- This repository currently doubles as the live production working directory
- Changes made in the live folder can immediately affect the public site
- Prefer small, focused commits so production changes are easy to revert
- Use `H:\xampp\php\php.exe -l <file>` for PHP syntax checks on this machine
- The local XAMPP PHP install may emit `Module "openssl" is already loaded`; that warning is from the PHP configuration, not necessarily from this site
- Most server-panel write endpoints should accept JSON request bodies
- Use Pterodactyl APIs for panel-facing user/server changes where possible

## Deployment

The live folder is deployed directly from the working tree. After testing a change:

```powershell
git -C "<directory>" status
git -C "<directory>" add .
# or:
git -C "<directory>" add <files>
git -C "<directory>" commit -m "Describe the change"
git -C "<directory>" push origin main
```

## Useful Git Commands

See commit history:

```powershell
git -C "<directory>" log --oneline --graph --decorate -50
```

See exactly what changed before committing:

```powershell
git -C "<directory>" diff
```

## Github Remote

```text
https://github.com/Frostbyt3/Frostbyt3Gaming.git
```
