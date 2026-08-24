#!/usr/bin/env bash
# Publish Logliy to wordpress.org SVN (CookiePeak-style).
#
# Prerequisites:
#   - Working copy at WP_SVN_DIR (default: /root/projects/fbm/wp-svn/logliy)
#   - SVN password for WordPress.org user flobamedia
#
# Usage:
#   WP_SVN_PASSWORD='…' ./publish-to-wporg.sh
#   WP_SVN_PASSWORD='…' ./publish-to-wporg.sh 0.0.9   # must already match source
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$PLUGIN_DIR/logliy"
DEFAULT_WC="$(cd "$PLUGIN_DIR/.." && pwd)/wp-svn/logliy"
WC="${WP_SVN_DIR:-$DEFAULT_WC}"
USER="${WP_SVN_USER:-flobamedia}"
PASSWORD="${WP_SVN_PASSWORD:-}"

if [[ ! -d "$WC/.svn" ]]; then
  echo "SVN working copy missing at: $WC" >&2
  echo "Run: svn checkout https://plugins.svn.wordpress.org/logliy \"$WC\"" >&2
  exit 1
fi

if [[ -z "$PASSWORD" ]]; then
  echo "Set WP_SVN_PASSWORD to your WordPress.org SVN password." >&2
  echo "Create/reset it at: https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password" >&2
  exit 1
fi

VERSION="$(
  sed -n "s/.*define( 'LOGLIY_VERSION', '\\([^']*\\)'.*/\\1/p" "$SRC/logliy.php" | head -1
)"
if [[ -z "$VERSION" ]]; then
  VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SRC/logliy.php" | head -1 | tr -d '[:space:]')"
fi
if [[ -z "$VERSION" ]]; then
  echo "Could not read LOGLIY_VERSION from logliy.php" >&2
  exit 1
fi
if [[ $# -ge 1 && "$1" != "$VERSION" ]]; then
  echo "Argument version $1 != source version $VERSION" >&2
  exit 1
fi

STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$SRC/readme.txt" | head -1 | tr -d '[:space:]')"
if [[ "$STABLE" != "$VERSION" ]]; then
  echo "readme.txt Stable tag ($STABLE) != plugin version ($VERSION)" >&2
  exit 1
fi

echo "==> Syncing trunk from $SRC (version $VERSION)"
rsync -a --delete \
  --exclude '.DS_Store' \
  --exclude '*.po' \
  --exclude '*.mo' \
  --exclude '*.pot' \
  --exclude 'vendor/' \
  --exclude 'PHPUnit/' \
  --exclude 'Test/' \
  --exclude 'Tests/' \
  --exclude 'tests/' \
  "$SRC/" "$WC/trunk/"

cd "$WC"
svn add --force trunk assets >/dev/null
svn status | awk '/^\?/ {print $2}' | while read -r f; do
  [[ -e "$f" ]] && svn add --force "$f" >/dev/null || true
done

if [[ ! -d "tags/$VERSION" ]]; then
  echo "==> Creating tags/$VERSION from trunk"
  svn copy trunk "tags/$VERSION"
else
  echo "==> tags/$VERSION already exists (will update trunk only; tag left as-is)"
fi

echo "==> Pending changes:"
svn status

if [[ -d "tags/$VERSION/.svn" ]] || svn status "tags/$VERSION" 2>/dev/null | grep -q '^A'; then
  MSG="Release Logliy ${VERSION} - wordpress.org publish (trunk, tag, assets)"
else
  MSG="Release Logliy ${VERSION} - update trunk"
fi

echo "==> Committing as $USER..."
svn commit \
  --username "$USER" \
  --password "$PASSWORD" \
  --non-interactive \
  --no-auth-cache \
  -m "$MSG"

echo "Done. Public page: https://wordpress.org/plugins/logliy"
echo "SVN tag: https://plugins.svn.wordpress.org/logliy/tags/$VERSION"
