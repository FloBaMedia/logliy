# Changelog

All notable changes to Logliy – Login Protect are documented in this file.

## [0.0.3] — 2026-08-10

### Fixed
- Fatal error with Divi when hiding `/wp-admin` for guests: custom login deny no longer loads the theme 404 template during early `init` (undefined `et_pb_is_pagebuilder_used()`)

## [0.0.2] — 2026-08-10

### Added
- Magic link (one-click email) login
- Role-based login / logout redirects
- Custom login URL (404-hides `wp-login.php` / guest `wp-admin`; auto-disabled if WPS Hide Login or similar is active)
- Session policy: cookie lifetime, Remember-me duration, admin idle timeout, logout everywhere
- Users overview (Passkeys + last login)
- WooCommerce Blocks checkout / account login panel
- Login branding extras: background color/image, footer HTML, custom CSS, hide back-to-site / lost-password links
- Auto Remember Me, login identifier mode (username / email / both), language switcher toggle
- Optional wipe-all-data on uninstall
- Shared cooldown for Email OTP and Magic Link requests (default 60s)
- EN source strings + bundled German (`de_DE`) translations
- Root `LICENSE` (GPLv2) and this changelog

### Fixed
- Passkey “A request is already pending.” (Conditional UI only on field focus; abort-and-wait before modal auth)
- Custom login URL redirected guests to `/cp` instead of hiding with 404
- Undefined `$user_login` / `$error` warnings when serving login via custom slug
- Onboarding banner stayed visible forever; now auto-dismisses when setup is complete and can be dismissed manually

### Changed
- Plugin version bumped to 0.0.2

## [0.0.1] — 2026-08-09

### Added
- Initial passwordless WordPress login: Passkeys (WebAuthn) + Email OTP
- Password login off by default (role / per-user / `LOGLIY_ALLOW_PASSWORD` emergency override)
- Login UI on `wp-login.php` and WooCommerce classic My Account / Checkout
- Cloudflare Turnstile verification on Passkey / OTP REST routes
- Rate limits, Wordfence-friendly `wp_login` / `wp_login_failed`
- Settings UI, profile Passkey management, ZIP build via `build.mjs`
