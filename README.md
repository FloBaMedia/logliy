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

Vendor dependencies are included under `logliy/vendor/` (`web-auth/webauthn-lib` ^5).

## Languages

- **English** — source / default
- **German (`de_DE`)** — bundled in `logliy/languages/`

WordPress site (or user) language selects the translation.

## Build ZIP

Same approach as CookiePeak (`apps/wordpress-plugin/build.mjs`): Node builds a ZIP with
**forward slashes** (required on Linux hosts; PowerShell `Compress-Archive` breaks activation).

```bash
node build.mjs
# or: ./build.sh
```

Outputs (gitignored under `dist/` and repo root):

- `dist/logliy-0.0.2.zip` — versioned release (version from `logliy.php`)
- `dist/logliy-wordpress.zip` — stable alias (always latest build)
- `dist/logliy-wordpress.version` — sidecar with the version string

In WordPress: **Plugins → Install plugin → Upload** → `logliy-0.0.2.zip`.

## Develop

```bash
cd logliy
composer install --no-dev -o
```

## Emergency password unlock

```php
define( 'LOGLIY_ALLOW_PASSWORD', true );
```
