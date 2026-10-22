=== Logliy - Login Protect (Passkey, Email, SSO) ===
Contributors: flobamedia
Tags: login, passkey, passwordless, sso, otp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless login with Passkeys, Email Code, Magic Link and SSO. Email access rules, shared rate limits, security log and alerts.

== Description ==

Logliy - Login Protect controls **how** users sign in: Passkeys (WebAuthn) first, with Email one-time codes and Magic Links as fallback, plus optional SSO (OpenID Connect) and an optional password path.

It is **not** a security suite and **not** a generic OTP plugin. Keep [Wordfence](https://www.wordfence.com/) (or similar) for WAF, brute-force lockouts, CAPTCHA, malware scanning, and classic TOTP 2FA. Logliy is the login-method layer on top.

= Features =

* Passkey login and registration (discoverable credentials, Conditional UI where available)
* Email OTP login via `wp_mail`
* Optional email address/domain allowlists with local-part wildcards for Email Code / Magic Link
* Shared account, IP and site email limits; atomic single-use codes and links
* Optional SSO email and signed group/role restrictions
* Security log with configurable retention and up to 10000 events
* Opt-in notifications for account, plugin, user and Logliy settings changes
* Magic link (one-click email) login
* Optional SSO via OpenID Connect (shown on the login form only when fully configured)
* Password login **off by default**, re-enable site-wide and/or per role / per user
* Role-based login/logout redirects
* Optional custom login URL (auto-disabled if WPS Hide Login or similar is active)
* Session length, Remember-me duration, admin idle timeout, session list and individual/all-session revocation
* Passkey count and last login in the standard WordPress Users list
* Optional custom login logo, brand, background, and footer
* Modern login UI on `wp-login.php`
* WooCommerce classic + Blocks My Account/Checkout login forms
* Cloudflare Turnstile compatible (verifies tokens on Passkey / Email OTP / Magic Link / SSO login)
* REST API namespace `logliy/v1`
* Rate limits for OTP, Passkey, and SSO auth
* Wordfence-friendly: fires `wp_login_failed` / `wp_login` and uses normal auth cookies
* Emergency override: `define( 'LOGLIY_ALLOW_PASSWORD', true );` in `wp-config.php`

= Wordfence compatibility =

* Failed Logliy attempts trigger `wp_login_failed` so Wordfence lockouts still apply
* Successful Logliy logins use `wp_set_auth_cookie` + `wp_login` like a normal `wp_signon`
* Wordfence IP lockouts still run during passwordless login; Wordfence Login Security 2FA is skipped for Passkey / Email OTP / Magic Link / SSO (those methods already replace the password)
* Wordfence TOTP 2FA continues to apply on the classic password path
* Logliy does **not** remove Wordfence hooks globally — only suspends LS 2FA for the passwordless completion step

= Cloudflare Turnstile =

When [Simple CAPTCHA with Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) (or equivalent) is enabled on the WordPress login form, Logliy requires a valid Turnstile token for Email OTP, Passkey, Magic Link, and SSO login. The password path continues to use the Turnstile plugin's own `authenticate` check.

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

When a visitor completes passwordless login (Passkey, Email OTP, Magic Link, or SSO) while Turnstile is required, Logliy sends the Turnstile response token and the visitor IP to Cloudflare’s siteverify API so the challenge can be validated. No other personal data is sent to Cloudflare by Logliy.

This service is provided by Cloudflare: [Terms of Use](https://www.cloudflare.com/website-terms/) and [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

When SSO (OpenID Connect) is enabled, Logliy contacts the OpenID Provider you configure (Issuer / discovery, token, and JWKS URLs) so visitors can sign in with that identity provider. Logliy does not send login data to FloBa Media. The identity provider receives standard OpenID Connect authentication data (such as the authorization request and, after sign-in, token exchange). Which personal data that provider stores is defined by that provider.

You must register this site’s Redirect URI at the provider and accept that provider’s own terms of service and privacy policy before enabling SSO.

== Installation ==

1. Upload the `logliy` folder to `/wp-content/plugins/`
2. Activate **Logliy - Login Protect (Passkey, Email, SSO)**
3. Open **Settings → Logliy**
4. Register a Passkey on your profile and/or test Email OTP **before** relying on passwordless-only mode
5. Optionally enable SSO under the SSO tab (login form shows it only when fully configured)
6. Optionally set a custom login logo / brand name under General
7. Optionally enable password login again under General

== Frequently Asked Questions ==

= How do email access rules work? =

On Email, under General email settings, enable the allowlist and enter one rule per line: `@example.org`, `staff-*@example.net` or `person@example.org`. Rules are case-insensitive. A domain rule does not include subdomains; wildcards are allowed only before `@`. An enabled empty list denies all email login. Rules apply to the account email even when the user enters a username. Both sending and redemption check the current rules. Passkeys and SSO have separate policies. Public request responses do not reveal whether an account is eligible.

= Are login codes and links single-use? =

Yes. Email challenges are stored in a dedicated database table and consumed atomically. A new request replaces the previous challenge of the same method. Codes allow at most five attempts without extending their original expiry. Changes to the account email invalidate outstanding challenges. Upgrading to 0.2.0 invalidates pending codes/links from older versions; request a new one.

= How can I restrict SSO? =

On SSO, configure a separate email allowlist and/or permitted values from a signed ID-token claim such as `groups`, `roles` or `realm_access.roles`. At least one group must match exactly. When both restrictions are enabled, both must pass, including for existing linked accounts. Rules run before account linking or creation and never assign WordPress roles. Verified email is required by default in 0.2.0. Your provider must return boolean `email_verified: true`; an administrator can disable this requirement for a trusted provider that does not support the claim.

= What does the security log store? =

Event type, UTC time, actor/account IDs and plugin file names only. No raw IP addresses, email addresses, codes, links, user agents or tokens are recorded. IDs can still identify users; include the log in your privacy assessment. The log is enabled by default, capped at 10000 events, and retained for 30 days (configurable 1–365). Expired entries are hidden immediately; hourly WordPress cron deletes them. Administrators can inspect it on Security log. It is a local operational log, not a tamper-proof audit service.

= Which security emails are available? =

All categories are off by default. Enable account security, plugin changes, user changes and/or Logliy settings changes on Security. Plugin installations, updates (including automatic updates), activations, deactivations and deletions each have their own notification switch. Existing all-plugin notification choices are preserved. User notifications cover creation, deletion and role changes. Account security notices cover Passkeys, SSO links, session revocation and email/password changes. Administration notices go to the configured recipient or the WordPress administration email; account notices also go to the affected account. At most 20 messages per recipient per hour are sent; failures and suppressed messages are logged. WordPress may send its own notices independently. Delivery uses `wp_mail` and depends on your site's mail setup.

= Can I revoke individual sessions? =

Profiles show active sessions with individual and all-session revoke buttons. Users can manage their own sessions; administrators need permission to edit the target user. With custom session storage, only all-session revocation is supported here. Revoking the current session signs you out.


= How do I enable SSO? =

Open **Settings → Logliy → SSO**. Enable SSO and enter your OpenID Provider Issuer URL, Client ID, and Client Secret. Register the shown Redirect URI at the provider. The SSO tab appears on the login form only when those three fields are set. ID tokens must be signed with RS256. New WordPress users are not created unless you turn on **Create users**.

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

= 0.2.0 =
* Always-on Auto-save with a top-right Save settings action, debounced edits, sequential requests and visible failure status.
* Content-based admin asset versions prevent stale CSS/JS during repeated ZIP installs; sidebar stays alongside settings on desktop.
* Wider responsive settings layout with clearly labelled ServerMetry and CookiePeak promotions using local assets.
* Neutral example.com addresses in email rule help.
* Plugin notifications can be selected separately by event; shared email rules and limits are grouped above method-specific settings.
* Passkey count and last login appear in the WordPress Users list instead of a duplicate users tab.
* Added optional address/domain/wildcard rules for Email Code and Magic Link, checked at request and redemption.
* Shared email request limits across methods, plus a site-wide limit and atomic cooldowns.
* Hardened single-use challenges against concurrent redemption; fixed expiry and attempt counting; new links replace older links.
* Added SSO verified-email, address and signed group/role policies before linking or provisioning.
* Added bounded security log, configurable retention and opt-in account/plugin/user/settings notifications.
* Added active session list and individual/all-session revocation with capability and nonce checks.
* Pending codes and links from earlier versions must be requested again. SSO now requires verified email by default; review provider settings before upgrading.

= 0.1.1 =
* Fix: SSO no longer fails with “denied” when Cloudflare Turnstile is enabled on the login form

= 0.1.0 =
* Optional SSO login via OpenID Connect (authorization code + PKCE). Shown on the login form only when fully configured.
* Plugin name updated to include SSO

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
