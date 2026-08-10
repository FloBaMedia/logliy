# Logliy – LoginProtect

WordPress plugin for passwordless login (Passkeys, Email OTP, Magic Link) by FloBa Media.

License: [GPLv2 or later](LICENSE) · Changelog: [CHANGELOG.md](CHANGELOG.md)

## Layout

```
logliy/          Plugin root (upload this folder to wp-content/plugins/)
build.mjs        Builds WordPress-ready ZIP (forward-slash entries)
dist/            Output: logliy-{version}.zip + logliy-wordpress.zip
```

## Requirements

- PHP 8.1+
- WordPress 6.4+
- HTTPS for Passkeys

Vendor dependencies are namespace-prefixed with [Strauss](https://github.com/BrianHenryIE/strauss) into `logliy/vendor-prefixed/` (`Logliy\…`) so Symfony / WebAuthn classes do not collide with other plugins.

## Languages

- **English** — source / default
- **German (`de_DE`)** — bundled in `logliy/languages/`

WordPress site (or user) language selects the translation.

## Build ZIP

Same approach as CookiePeak (`apps/wordpress-plugin/build.mjs`): Node builds a ZIP with
**forward slashes** (required on Linux hosts; PowerShell `Compress-Archive` breaks activation). The release ZIP includes `vendor-prefixed/` and excludes Composer `vendor/` (Strauss / require-dev).

```bash
node build.mjs
# or: ./build.sh
```

Outputs (gitignored under `dist/` and repo root):

- `dist/logliy-0.0.5.zip` — versioned release (version from `logliy.php`)
- `dist/logliy-wordpress.zip` — stable alias (always latest build)
- `dist/logliy-wordpress.version` — sidecar with the version string

In WordPress: **Plugins → Install plugin → Upload** → `logliy-0.0.5.zip`.

## Develop

```bash
cd logliy
composer install
# post-install runs Strauss → vendor-prefixed/
```

## Emergency password unlock

```php
define( 'LOGLIY_ALLOW_PASSWORD', true );
```
