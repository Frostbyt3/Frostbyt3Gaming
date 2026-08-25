# TODO

## Frontend Shop

- [x] Build credit balance display and transaction history on the frontend.
- [x] Build the add-credit flow with Stripe and PayPal.
- [x] Replace the servers placeholder with a real category and plan catalog.
- [x] Add rental-with-credit provisioning through the Pterodactyl API.
- [x] Add frontend payment settings management for Stripe, PayPal, and balance limits.
- [x] Add server rental history to the Manage Wallet page.
- [x] Record shop rental metadata for renewals and 30-day expiration tracking.
- [x] Remove server-panel install-state hard reloads and stabilize polling.
- [x] Add admin CRUD for shop categories and server plans.
- [ ] Add order detail pages for completed balance uploads, server rentals, and renewals.

## Frontend Invoice System

- [x] Add frontend-owned invoice database tables for invoices, invoice line items, and invoice events.
- [x] Add immutable invoice snapshots for company details, customer details, line items, tax, totals, currency, and payment metadata.
- [x] Add invoice numbering settings with configurable prefix and starting/next invoice number.
- [x] Add invoice company settings for business name, address, phone, email, and tax label.
- [x] Add invoice email delivery enable/disable setting.
- [ ] Add invoice reply-to/from behavior and future PDF attachment behavior.
- [x] Move invoice settings and manual invoice recovery to a standalone admin Invoice Settings page.
- [x] Generate invoices when wallet top-ups complete successfully.
- [x] Generate invoices when server rentals complete successfully.
- [x] Generate invoices when server renewals complete successfully.
- [x] Add admin action to manually generate a missing invoice for a completed wallet top-up.
- [x] Send invoice email notifications through the existing PHPMailer setup after invoice creation.
- [x] Add customer-facing invoice list/detail pages with secure ownership checks.
- [ ] Add PDF invoice rendering/download support.
- [x] Add admin invoice list/detail pages with search, filters, pagination, and resend.
- [ ] Add invoice void and refund-aware status handling.
- [ ] Add invoice event logging for generated, emailed, resent, failed-email, voided, refunded, and downloaded events.
- [x] Change invoices displayed on Wallet page to new invoice system.

## Shared Frontend Utilities

- [x] Create proper pagination function/convert current per-page functions to global.
- [x] Create toast notification function/convert current per-page functions to global.

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

### Admin Server Delete Tab

- [x] Add server delete action.
- [x] Require strong confirmation before deleting.
- [ ] Prevent accidental deletion with clear server name confirmation.
- [ ] Decide whether deleted server rental/history records should remain for billing/audit history.
