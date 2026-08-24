=== Logliy - Login Protect (Passkey, Email Code) ===
Contributors: flobamedia
Tags: login, passkey, passwordless, otp, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless WordPress login with Passkeys and Email OTP by FloBa Media. Complements Wordfence — does not replace it.

== Description ==

Logliy - Login Protect controls **how** users sign in: Passkeys (WebAuthn) first, with Email one-time codes and Magic Links as fallback, plus an optional password path.

It is **not** a security suite and **not** a generic OTP plugin. Keep [Wordfence](https://www.wordfence.com/) (or similar) for WAF, brute-force lockouts, CAPTCHA, malware scanning, and classic TOTP 2FA. Logliy is the login-method layer on top.

= Features =

* Passkey login and registration (discoverable credentials, Conditional UI where available)
* Email OTP login via `wp_mail`
* Magic link (one-click email) login
* Password login **off by default**, re-enable site-wide and/or per role / per user
* Role-based login/logout redirects
* Optional custom login URL (auto-disabled if WPS Hide Login or similar is active)
* Session length, Remember-me duration, admin idle timeout, logout everywhere
* Users overview (Passkeys + last login)
* Optional custom login logo, brand, background, and footer
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
* Wordfence IP lockouts still run during passwordless login; Wordfence Login Security 2FA is skipped for Passkey / Email OTP / Magic Link (those methods already replace the password)
* Wordfence TOTP 2FA continues to apply on the classic password path
* Logliy does **not** remove Wordfence hooks globally — only suspends LS 2FA for the passwordless completion step

= Cloudflare Turnstile =

When [Simple CAPTCHA with Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) (or equivalent) is enabled on the WordPress login form, Logliy requires a valid Turnstile token for Email OTP and Passkey REST authentication. The password path continues to use the Turnstile plugin's own `authenticate` check.

= WooCommerce =

* Classic My Account and Checkout login templates
* Guest checkout unchanged
* Does not block WooCommerce REST / Store API authentication
* Optional panel above Checkout and Customer Account blocks for guests

= Requirements =

* PHP 8.1+
* WordPress 6.4+
* HTTPS for Passkeys (localhost allowed for development)
* Composer production dependencies are vendored in release builds (`vendor-prefixed/`)

== External services ==

This plugin can contact Cloudflare Turnstile only when a compatible Turnstile plugin is active and configured for the WordPress login form. Logliy does not load Turnstile by itself.

When a visitor completes passwordless login (Passkey, Email OTP, or Magic Link) while Turnstile is required, Logliy sends the Turnstile response token and the visitor IP to Cloudflare’s siteverify API so the challenge can be validated. No other personal data is sent to Cloudflare by Logliy.

This service is provided by Cloudflare: [Terms of Use](https://www.cloudflare.com/website-terms/) and [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

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

= Application Passwords / WP-CLI / XML-RPC =

Application Passwords and WP-CLI are not blocked by the password policy.
With password login off, XML-RPC authentication with the **account password** is blocked by default (affects the WordPress mobile app, Jetpack, and some backup tools). Enable **Allow XML-RPC passwords** under Logliy → General if a tool still requires it. Prefer Application Passwords when the client supports them.

== Changelog ==

= 0.0.9 =
* Tested up to WordPress 7.1

= 0.0.8 =
* Remove arbitrary custom CSS from settings (use Additional CSS instead)
* Enqueue remember-me check via login.js; drop inline script tag
* Document Cloudflare Turnstile as an external service (terms + privacy)
* Stop bundling .po/.mo and vendor PHPUnit helpers; include composer.json
* WordPress.org loads translations automatically (no load_plugin_textdomain)
* Limit admin notices to Logliy settings / profile screens

= 0.0.7 =
* Ignore leftover Turnstile DB options when the Captcha plugin is not active (fixes false CAPTCHA block)
* Show plugin version on settings page; remove duplicate “settings saved” notice

= 0.0.6 =
* Captcha only required when Cloudflare Turnstile is enabled and configured; sites without Captcha are not blocked
* Fix Advanced settings save (nested import form) and add Save button at top

= 0.0.5 =
* Settings import/export (JSON) for cloning config between sites
* Vendor namespaces prefixed with Strauss (Logliy\) to avoid Symfony conflicts
* Passwordless login skips Wordfence 2FA (lockouts still apply); Wordfence 2FA remains on password path
* Optional “Allow XML-RPC passwords” (off by default) when password login is disabled
* Atomic rate-limit DB table (no option-lock); OTP HMAC bound to user; safer settings import

= 0.0.4 =
* Security: password policy applies to XML-RPC; REST exemption limited to Application Passwords
* Security: passwordless login runs authenticate / wp_authenticate_user before cookies (Wordfence lockouts)
* Privacy: system font stack instead of Google Fonts CDN
* Hardening: account cooldown/rate-limit responses no longer enumerate users; atomic rate-limit buckets; OTP HMAC

= 0.0.3 =
* Fix: Divi fatal when hiding wp-admin (no theme 404 template during early init)

= 0.0.2 =
* Magic link, custom login URL, redirects, sessions, users overview, WC blocks, branding extras

= 0.0.1 =
* Initial pre-release: Passkeys, Email OTP, password policy (default off), wp-login + WooCommerce classic
* Admin settings, profile Passkey management, Cloudflare Turnstile compatibility
* Optional login branding (logo / name / tagline); DE/EN i18n
* Auth hardening: no OTP enumeration, redirect validation, Turnstile token verify, rate limits
