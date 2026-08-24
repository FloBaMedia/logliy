---
description: wordpress.org SVN publish for Logliy (shared with CookiePeak)
alwaysApply: true
---

# wordpress.org Plugin-Release

Nur fertige Releases, nicht jeder Git-Commit. User `flobamedia`.

**Secret (nicht in Git):** `~/.config/wordpress-org/svn.env` (`chmod 600`). Wird von `publish-to-wporg.sh` geladen. Override: `WP_SVN_PASSWORD`. Reset: https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password

**Working copy (außerhalb Git):** `/root/projects/fbm/wp-svn/logliy`

## Schritte

1. Version bump: `LOGLIY_VERSION`, `readme.txt` Stable tag + Changelog, `Tested up to`
2. Directory-Assets in `wporg-assets/` (icon-128/256, banner-772x250 / 1544x500)
3. `node build.mjs`
4. `svn update /root/projects/fbm/wp-svn/logliy`
5. `./publish-to-wporg.sh`
6. Prüfen: https://wordpress.org/plugins/logliy

## SVN-Regeln (sonst Timeout)

- Nie `svn copy trunk tags/x` auf **uncommitted** Trunk — das lädt alles doppelt und bricht mit `svn: E175012 Connection timed out`.
- Erst `trunk` + `assets/` committen, dann Tag als Server-Kopie (`svn copy` nach dem Trunk-Commit).
- `http-timeout` hoch setzen (Skript: 6000). Erster Trunk-Commit kann 10+ Minuten brauchen.
- Checkout: `svn checkout https://plugins.svn.wordpress.org/logliy /root/projects/fbm/wp-svn/logliy`
