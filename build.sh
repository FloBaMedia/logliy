#!/usr/bin/env bash
# Creates logliy-{version}.zip for WordPress plugin upload (CookiePeak-style).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
node "$SCRIPT_DIR/build.mjs"
