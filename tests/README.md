# Security regression checks

Run these checks only on a disposable WordPress installation with Logliy active,
PHP 8.1+ and MySQL/MariaDB. They create and delete their own named test accounts
and change Logliy settings. Do not run them on a production site.

```sh
wp eval-file /path/to/tests/admin-save.php
wp eval-file /path/to/tests/notification-preferences.php
wp eval-file /path/to/tests/security-integration.php
wp eval-file /path/to/tests/security-lifecycle.php
```

The lifecycle script checks upgrade preservation and both keep-data / opt-in
uninstall paths, including user-meta cleanup, then reactivates Logliy.

The integration script intercepts all mail with `pre_wp_mail`. No email is sent.
It checks allowlist boundaries, generic request responses, shared account/IP/site
limits, code expiration/attempts/replay, address changes, SSO restrictions,
session authorization and revocation, notification limits, and log retention.

For actual database concurrency, run on the same disposable installation:

```sh
wp eval-file /path/to/tests/security-concurrency.php setup
for mode in consume attempt rate; do
  seq 1 20 | xargs -P 8 -I '{}' wp eval-file /path/to/tests/security-concurrency.php "$mode"
done
wp eval-file /path/to/tests/security-concurrency.php verify
```

The check requires exactly one successful consume, five attempts and five
allowed rate-limit hits, across twenty independent PHP processes per operation.

For manual verification, install the ZIP on staging:

1. In Settings → Logliy → Email → General email settings, enable the allowlist and enter `@example.org`
   and `staff-*@example.net`. Confirm only matching existing accounts get mail.
2. Request a code or link, change the list to exclude the account, then try to
   sign in. It must fail. Restore the list and confirm a successful code or link
   cannot be reused. A replacement link invalidates the previous link.
3. Use the SSO tab to enable email and signed group restrictions. Try an allowed
   and a denied identity, including an already linked identity. Neither a denied
   group nor a missing claim may create or link an account.
4. In Security, select notification categories and a test recipient. Install a
   harmless plugin and create a test user. Inspect the notifications and Security
   log. Account notifications also go to the affected account.
5. Sign in on two browsers. In Profile → Active sessions revoke one session and
   confirm only that browser loses access; then revoke all sessions.

No web server is required for the CLI checks. Browser checks need a configured
Jean run environment; CLI tests do not prove browser layout or real mail delivery.

`admin-save.php` checks tab-scoped writes, malformed input, whole-write rejection,
unchanged saves, denied storage, secret handling, and the actual AJAX handler's
nonce/capability checks. Mail is intercepted.

`admin-autosave-browser.js` is a browser-evaluated async function for a disposable
rendered Email settings page. It replaces fetch only during the test and checks
always-on behavior, checkbox saves, debouncing, request ordering, validation,
network failure, and manual retry. It does not send real save requests.
