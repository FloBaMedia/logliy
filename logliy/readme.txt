=== Logliy - Login Protect (Passkey, Email Code) ===
Contributors: flobamedia
Tags: login, passkey, passwordless, otp, woocommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless WordPress login with Passkeys and Email OTP by FloBa Media. Complements Wordfence — does not replace it.

== Description ==

Logliy - Login Protect controls **how** users sign in: Passkeys (WebAuthn), Email one-time codes, and an optional password fallback.

It is **not** a security suite. Keep [Wordfence](https://www.wordfence.com/) (or similar) for WAF, brute-force lockouts, CAPTCHA, malware scanning, and classic TOTP 2FA.

= Features =

* Passkey login and registration (discoverable credentials, Conditional UI where available)
* Email OTP login via `wp_mail`
* Magic link (one-click email) login
* Password login **off by default**, re-enable site-wide and/or per role / per user
* Role-based login/logout redirects
* Optional custom login URL (auto-disabled if WPS Hide Login or similar is active)
* Session length, Remember-me duration, admin idle timeout, logout everywhere
* Users overview (Passkeys + last login)
* Optional custom login logo, brand, background, footer, CSS
* Modern login UI on `wp-login.php`
* WooCommerce classic + Blocks My Account/Checkout login forms
* Cloudflare Turnstile compatible (verifies tokens on Passkey / Email OTP / Magic Link REST login)
* REST API namespace `logliy/v1`
* Rate limits for OTP and Passkey auth
* Wordfence-friendly: fires `wp_login_failed` / `wp_login` and uses normal auth cookies
* Emergency override: `define( 'LOGLIY_ALLOW_PASSWORD', true );` in `wp-config.php`

= Wordfence compatibility =

* Failed Logliy attempts trigger `wp_login_failed` so Wordfence lockouts still apply
* Successful Logliy logins use `wp_set_auth_cookie` + `wp_login` like a normal `wp_signon`
* Wordfence Login CAPTCHA remains relevant on the password path; Passkey/OTP use Logliy's rate limits
* Logliy does **not** remove Wordfence hooks
* Wordfence TOTP 2FA continues to apply on the classic password path. Passkey / Email OTP are primary (password-replacement) factors in v1 and establish a session after success

= Cloudflare Turnstile =

When [Simple CAPTCHA with Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) (or equivalent) is enabled on the WordPress login form, Logliy requires a valid Turnstile token for Email OTP and Passkey REST authentication. The password path continues to use the Turnstile plugin's own `authenticate` check.

= WooCommerce =

* Classic My Account and Checkout login templates
* Guest checkout unchanged
* Does not block WooCommerce REST / Store API authentication
* Gutenberg/Blocks login parity is planned for a later release

= Requirements =

* PHP 8.1+
* WordPress 6.4+
* HTTPS for Passkeys (localhost allowed for development)
* Composer production dependencies are vendored in release builds (`vendor/`)

== Installation ==

1. Upload the `logliy` folder to `/wp-content/plugins/`
2. Activate **Logliy - Login Protect (Passkey, Email Code)**
3. Open **Settings → Logliy**
4. Register a Passkey on your profile and/or test Email OTP **before** relying on passwordless-only mode
5. Optionally set a custom login logo / brand name under General
6. Optionally enable password login again under General

== Frequently Asked Questions ==

= I locked myself out =

Add to `wp-config.php`:

`define( 'LOGLIY_ALLOW_PASSWORD', true );`

Then sign in with your password and adjust Logliy settings.

= Does this replace Wordfence? =

No. Logliy is the login **method** layer. Wordfence remains your WAF / lockout / scanner layer.

= Application Passwords / WP-CLI =

Those flows are not blocked by the password policy.

== Changelog ==

= 0.0.1 =
* Initial pre-release: Passkeys, Email OTP, password policy (default off), wp-login + WooCommerce classic
* Admin settings, profile Passkey management, Cloudflare Turnstile compatibility
* Optional login branding (logo / name / tagline); DE/EN i18n
* Auth hardening: no OTP enumeration, redirect validation, Turnstile token verify, rate limits
