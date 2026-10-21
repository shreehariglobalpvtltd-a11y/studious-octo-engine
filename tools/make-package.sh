#!/usr/bin/env bash
# =====================================================================
#  tools/make-package.sh — build the ready-to-upload zip.
#
#      bash tools/make-package.sh [output-dir]     → live-fleet-shreehari-<date>.zip
#
#  Layout inside the zip (see docs/INSTALL-NEPALI.md):
#    1-upload-to-public_html/   every file under site/, same paths as the website
#    2-menu/                    the 5 menu lines + patches/admin-nav.patch, admin-map.patch
#    3-database/                the SQL upgrade
#    4-tools/                   install.sh, test-local.sh, simulator, demo seed
#    docs/                      CCTV, research, screenshots
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$HERE}"
NAME="live-fleet-shreehari-$(date +%Y%m%d)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/$NAME"

mkdir -p "$PKG/1-upload-to-public_html" "$PKG/2-menu" "$PKG/3-database" "$PKG/4-tools" "$PKG/docs"
cp -R "$HERE/site/." "$PKG/1-upload-to-public_html/"
cp "$HERE/patches/admin-nav.patch" "$HERE/patches/admin-map.patch" "$PKG/2-menu/"
# The five added lines, plain, for pasting by hand into admin/_guard.php.
grep '^+' "$HERE/patches/admin-nav.patch" | grep -v '^+++' | sed 's/^+//; s/\r$//' > "$PKG/2-menu/MENU-5-LINES.txt"
cp "$HERE/site/database/upgrade-2026-10-live-fleet.sql" "$PKG/3-database/"
cp "$HERE/tools/install.sh" "$HERE/tools/test-local.sh" "$HERE/tools/fleet-simulator.php" "$HERE/tools/fleet-demo-seed.php" "$PKG/4-tools/"
cp "$HERE/docs/CCTV.md" "$HERE/docs/RESEARCH.md" "$PKG/docs/"
cp -R "$HERE/docs/screenshots" "$PKG/docs/"
cp "$HERE/README.md" "$PKG/README.md"
cp "$HERE/docs/INSTALL-NEPALI.md" "$PKG/PADHNUHOS-PAHILE-YO.md"

mkdir -p "$OUT"
(cd "$TMP" && zip -qr "$OUT/$NAME.zip" "$NAME")
echo "$OUT/$NAME.zip"
