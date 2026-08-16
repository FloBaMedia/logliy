# Changelog

All notable changes to Logliy – Login Protect are documented in this file.

## [0.0.8] — 2026-08-16

### Changed
- Removed the Custom CSS textarea; login branding stays form-based (logo, colors, footer). Extra styling belongs in Appearance → Customize → Additional CSS
- Remember-me auto-check runs from the enqueued `login.js` instead of a raw `<script>` tag
- Cloudflare Turnstile documented in readme as an external service, with Terms and Privacy links
- Release ZIP includes `composer.json` and excludes `.po`/`.mo` plus vendor `PHPUnit`/`Test` helpers
- WordPress.org translations: dropped `load_plugin_textdomain()` (requires WP 6.4+)
- Admin notices limited to Logliy settings and (for the Passkey nag) the profile screen

## [0.0.7] — 2026-08-11

### Fixed
- Orphaned `cfturnstile_*` options after uninstalling Simple Cloudflare Turnstile no longer force Captcha on passwordless login (only an active Turnstile plugin or explicit `CF_TURNSTILE_*` constants count)
- Duplicate “Settings saved” notice on the Logliy settings screen

### Added
- Plugin version shown in the settings page header

## [0.0.6] — 2026-08-10

### Fixed
- Turnstile/CAPTCHA is only enforced when the WP Login option is on **and** Turnstile is configured; sites without Captcha no longer see “Please complete the CAPTCHA…”
- Login JS no longer treats orphan Turnstile DOM as a Captcha requirement
- Advanced settings save broken by nested Import form; Save button also shown at top; active tab kept after save

## [0.0.5] — 2026-08-10

### Added
- Settings import / export (JSON) under Advanced — clone Logliy config to another site (Passkeys/users not included; media IDs and RP ID reset)
- Setting **Allow XML-RPC passwords** (default off): when password login is disabled, XML-RPC account-password auth stays blocked unless explicitly enabled (WordPress app / Jetpack / some backups)
- Atomic rate-limit table (`{prefix}logliy_rl`) for installs without an object cache (no option-lock spin)

### Changed
- Composer dependencies are namespace-prefixed with Strauss into `vendor-prefixed/` (`Logliy\…`) to avoid Symfony/class collisions with other plugins
- Release ZIP ships `vendor-prefixed/` only (excludes Composer `vendor/` / Strauss tooling / `composer.json`)
- Passwordless login (Passkey / OTP / Magic Link) skips Wordfence Login Security 2FA; Wordfence IP lockouts still apply via the core authenticate filter
- Settings import sanitizes against defaults without writing live options mid-import
- OTP HMAC is bound to the user ID; import confirm warns about overwriting custom CSS

## [0.0.4] — 2026-08-10

### Security
- Password policy no longer exempts all XML-RPC / REST auth; only Application Passwords, WP-CLI, and Cron
- Passwordless login (Passkey / OTP / Magic Link) runs `authenticate` and `wp_authenticate_user` before issuing cookies so Wordfence lockouts and similar filters can reject the session
- Account-level email cooldown / rate-limit responses use the same public shape as unknown accounts (less enumeration)
- OTP codes stored with HMAC-SHA256 instead of bcrypt (attempt-capped; DoS-friendlier)
- Rate-limit increments are atomic with object cache (`wp_cache_incr`) or a short option lock otherwise

### Changed
- Login UI uses a system font stack (no Google Fonts CDN)
- Client IP is filterable via `logliy_client_ip` for trusted proxies
- Passkey rename: name length capped; ownership check when update affects 0 rows
- Profile password override save uses `edit_user` capability
- Uninstall wipe also removes last-login / activity usermeta and `logliy_flush_rewrite`
- WebAuthn exception details only appended when `WP_DEBUG` is on

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
