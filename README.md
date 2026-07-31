# Frostbyt3 Gaming Website

Official Frostbyt3 Gaming website and Pterodactyl-integrated customer/server portal.

This repository is the live website codebase. It powers public site pages, user registration and account management, the customer dashboard, server management tools, admin content tools, and Pterodactyl API integrations.

## Major Features

### Public Website

- Home page with configurable server/service cards.
- Public server listing and ordering pages.
- News/community/legal pages.
- Short-link redirect handling from the root `index.php`.
- External redirect interstitial support.
- Custom 403 and 404 page fragments.
- Privacy notice banner.
- Maintenance mode with admin bypass support.

### Authentication And Accounts

- Login using Pterodactyl user credentials.
- Remember-me login cookies backed by server-side token records.
- Public registration flow backed by local pending-registration records.
- Email verification before Pterodactyl account creation.
- Resend verification flow.
- Account management page for logged-in users.
- Pterodactyl-backed profile updates.
- Password change with current-password verification and strength rules.
- Email change flow with verification sent to the new email before applying the Pterodactyl update.
- Remembered-login cleanup after password/email-sensitive account changes.

### Pterodactyl Dashboard

- Dashboard display of accessible Pterodactyl servers.
- Admin toggle for showing all Pterodactyl servers.
- Session/cache-aware server access syncing to reduce dashboard load time.
- Server cards with status, allocation, CPU, memory, disk, access role, node, and controls.
- Stale server metadata refresh support via `refresh_servers=1`.
- Suspended/expired server display handling.
- Server renewal integration.

### Server Panel

The server panel includes Pterodactyl-backed tools for:

- Console output and command sending.
- Power controls.
- Server details rename/description updates.
- File manager browsing.
- File read/write/edit support for allowed editable file types.
- File upload, download, rename, delete, and folder creation.
- Backups: list, create, download, lock, restore, and delete.
- Network allocations: list, create, update notes, set primary, and delete.
- Schedules: list, view, create, update, delete, execute, and task management.
- Startup settings and startup variable updates.
- Subuser listing, creation, update, view, and deletion.
- Activity viewing.
- Settings tab for renewal, reinstall, and server diagnostics.

### Admin Tools

- Admin dashboard.
- Article/news manager.
- Service card manager.
- Site settings, including registration and maintenance settings.
- Link shortener management.
- File upload manager.
- Image upload manager.
- WebP-to-PNG conversion tool.
- Admin sidebar/navigation.
- Access-level based admin visibility.

### API Layer

- Central Pterodactyl Application API and Client API helper in `api/pterodactyl.php`.
- JSON-first server management endpoints under `api/server/`.
- Server status polling endpoint.
- Admin conversion endpoints.
- Protected file upload/download endpoint.
- Permission checks tied to session-synced server access data.

### Production Error Handling

- Shared production error configuration in `includes/error-handling.php`.
- Errors are logged in production instead of displayed to users.
- Local/dev requests can still display PHP errors.
- PHPMailer SMTP debug output is disabled in production and enabled only for local/dev requests.
- API endpoints that return JSON suppress browser error display.

## Important Paths

- `page.php` - main page router.
- `index.php` - short-link/root redirect entry point.
- `includes/` - shared auth, DB, mail, registration, account, layout, and utility helpers.
- `api/pterodactyl.php` - Pterodactyl API integration layer.
- `api/server/` - server panel JSON endpoints.
- `pages/dashboard.php` - customer/admin server dashboard.
- `pages/serverpanel.php` - server panel router.
- `pages/serverpanel/` - server panel tab fragments.
- `pages/admin/` - admin tools.
- `backend/css/style.css` - primary stylesheet.
- `config/secrets.php` - local secret/config values, intentionally ignored by Git.

## Git Ignore Notes

The repository intentionally excludes sensitive or bulky live-data paths, including:

- `config/secrets.php`
- `downloads/`
- `backend/uplimg/`
- `backend/fivem/`
- `wow/`

Do not commit API keys, SMTP credentials, database credentials, generated downloads, uploaded images, or game-server payloads.

## Development Notes

- This repository currently doubles as the live production working directory.
- Changes made in the live folder can immediately affect the public site.
- Prefer small, focused commits so production changes are easy to revert.
- Use `H:\xampp\php\php.exe -l <file>` for PHP syntax checks on this machine.
- The local XAMPP PHP install may emit `Module "openssl" is already loaded`; that warning is from the PHP configuration, not necessarily from this site.
- Most server-panel write endpoints should accept JSON request bodies.
- Use Pterodactyl APIs for panel-facing user/server changes where possible.

## Deployment

The live folder is deployed directly from the working tree. After testing a change:

```powershell
git status
git add <files>
git commit -m "Describe the change"
git push origin main
```

The GitHub remote is:

```text
https://github.com/Frostbyt3/Frostbyt3Gaming.git
```
