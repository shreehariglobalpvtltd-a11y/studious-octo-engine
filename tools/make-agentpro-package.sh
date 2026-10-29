#!/usr/bin/env bash
# =====================================================================
#  tools/make-agentpro-package.sh — build the ready-to-upload Agent Pro zip.
#
#      bash tools/make-agentpro-package.sh [output-dir]     → agent-pro-shreehari.zip
#
#  Layout inside the zip (see docs/AGENT-PRO-NEPALI.md):
#    1-upload-to-public_html/   only the Agent Pro files, same paths as the website
#    2-menu/                    menu lines + WhatsApp hooks, as text and as patches
#    3-database/                the SQL upgrade
#    4-tools/                   install, demo seed, mock AI server, browser test
#    docs/                      design, card philosophy, screenshots, sample cards
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$(mkdir -p "${1:-$HERE}" && cd "${1:-$HERE}" && pwd)"
NAME="agent-pro-shreehari-$(date +%Y%m%d)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/$NAME"

mkdir -p "$PKG/1-upload-to-public_html" "$PKG/2-menu" "$PKG/3-database" "$PKG/4-tools" "$PKG/docs"
while IFS= read -r rel; do
  mkdir -p "$(dirname "$PKG/1-upload-to-public_html/$rel")"
  cp "$HERE/site/$rel" "$PKG/1-upload-to-public_html/$rel"
done < <(bash "$HERE/tools/agentpro-install.sh" --list)

cp "$HERE/patches/agentpro-nav.patch" "$HERE/patches/agentpro-wabot.patch" "$HERE/patches/AGENTPRO-WABOT.txt" "$PKG/2-menu/"
# The menu lines, plain, for pasting by hand into admin/_guard.php.
{
  echo "Agent Pro — menu lines for admin/_guard.php (only if the installer could not add them)."
  echo "Paste the first five after the 'Staff & Approvals' line, the last one after the 'AI Activity' line."
  echo
  grep '^+' "$HERE/patches/agentpro-nav.patch" | grep -v '^+++' | sed 's/^+//; s/\r$//'
} > "$PKG/2-menu/AGENTPRO-MENU.txt"
cp "$HERE/site/database/upgrade-2026-10-agent-pro.sql" "$PKG/3-database/"
cp "$HERE/tools/agentpro-install.sh" "$HERE/tools/agentpro-demo-seed.php" "$HERE/tools/mock-ollama.php" \
   "$HERE/tools/agentpro-browser-test.cjs" "$HERE/tools/agentpro-browser-helper.php" "$PKG/4-tools/"
cp "$HERE/docs/AGENT-PRO-DESIGN.md" "$PKG/docs/"
cp -R "$HERE/docs/card-design" "$PKG/docs/"
mkdir -p "$PKG/docs/screenshots"
cp "$HERE"/docs/screenshots/ap-*.png "$PKG/docs/screenshots/"
cp "$HERE/docs/AGENT-PRO-NEPALI.md" "$PKG/PADHNUHOS-PAHILE-YO.md"

rm -f "$OUT/agent-pro-shreehari.zip"
(cd "$TMP" && zip -qr "$OUT/agent-pro-shreehari.zip" "$NAME")
echo "$OUT/agent-pro-shreehari.zip"
