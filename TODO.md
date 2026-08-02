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
- [ ] Add admin CRUD for shop categories and server plans.
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
- [ ] Extract shared pending-registration account creation service from `complete-registration.php`.
- [ ] Add safe, repeatable cleanup task/entrypoint for expired retained registrations.
- [ ] Add Registration Settings controls to the Admin Settings page.
- [ ] Add Pending Registrations admin management page with pagination/search/filter/sort.
- [ ] Add admin resend-verification action with new token, cooldown, counters, and delivery reporting.
- [ ] Add admin manual approval action with required reason and duplicate username/email checks.
- [ ] Add registration statistics to the admin dashboard.
- [ ] Add registration security logging without passwords, plaintext tokens, token hashes, or session data.
- [ ] Add or document coverage for legitimate registration, bot rejection paths, resend, manual approval, cleanup, and stats.
