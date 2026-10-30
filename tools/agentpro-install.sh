#!/usr/bin/env bash
# =====================================================================
#  tools/agentpro-install.sh — copy Agent Pro into a copy of the website.
#
#      bash tools/agentpro-install.sh /path/to/website
#      bash tools/agentpro-install.sh --list          (the files, one per line)
#
#  What it does (and nothing else):
#    1. copies the Agent Pro files to the same paths in the website
#       (all are NEW files — it stops if one already exists and differs)
#    2. adds the four menu items to admin/_guard.php and the two WhatsApp
#       hooks to includes/wabot.php — includes/agentpro-install.php, the
#       same code as the web installer: backup first, parse check, CRLF kept
#       (the same changes as patches/agentpro-nav.patch + agentpro-wabot.patch)
#    3. prints what is still to run: the database upgrade and the cron line
#
#  It does NOT touch the database. Try it on a test copy first.
#  No terminal on the server? Upload the files and open
#  /admin/agentpro-install.php as the super-admin instead.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Works from the git repo (site/) and from the download zip (1-upload-to-public_html/).
SRC="$HERE/site"; [ -d "$SRC" ] || SRC="$HERE/1-upload-to-public_html"

# Every Agent Pro file, relative to the website root. Nothing here exists on the live site today.
agentpro_files() {
  (cd "$SRC" && find \
      includes/agentpro.php includes/agentpro-*.php \
      api/agentpro.php api/agentpro-card.php \
      admin/hub.php admin/team.php admin/team-member.php admin/team-print.php admin/team-doc.php \
      admin/ai-local.php admin/agentpro-install.php \
      join.php id.php c.php me cron/agentpro-worker.php \
      assets/agentpro database/upgrade-2026-10-agent-pro.sql \
      tests/agent-pro-test.php tests/agent-pro-wa-test.php tests/agent-pro-http-test.php \
      -type f | sort)
}

if [ "${1:-}" = "--list" ]; then
  agentpro_files
  exit 0
fi

SITE="${1:-}"
if [ -z "$SITE" ] || [ ! -f "$SITE/includes/bootstrap.php" ] || [ ! -f "$SITE/admin/_guard.php" ]; then
  echo "usage: bash tools/agentpro-install.sh /path/to/website   (the folder that has includes/ and admin/)" >&2
  exit 2
fi

echo "== 1. Copying Agent Pro files into $SITE"
FILES=()
while IFS= read -r rel; do FILES+=("$rel"); done < <(agentpro_files)
# Check everything before copying anything: a half-copied add-on is worse than none.
for rel in "${FILES[@]}"; do
  if [ -e "$SITE/$rel" ] && ! cmp -s "$SRC/$rel" "$SITE/$rel"; then
    echo "   STOP: $rel already exists in the website and is different. Nothing was changed." >&2
    exit 1
  fi
done
COPIED=()
for rel in "${FILES[@]}"; do
  mkdir -p "$(dirname "$SITE/$rel")"
  cp "$SRC/$rel" "$SITE/$rel"
  COPIED+=("$SITE/$rel")
  echo "   + $rel"
done

echo "== 2. Menu (admin/_guard.php) + WhatsApp desk (includes/wabot.php)"
php "$SITE/includes/agentpro-install.php" "$SITE" \
  || echo "   not done automatically — paste from 2-menu/AGENTPRO-MENU.txt and AGENTPRO-WABOT.txt by hand (AGENT-PRO-NEPALI.md, step 2)" >&2

# On the server the web files belong to www-data (see the site's CLAUDE.md).
if [ "$(id -u)" = "0" ] && id www-data >/dev/null 2>&1; then
  chown www-data:www-data "${COPIED[@]}"
  chown -R www-data:www-data "$SITE/me" "$SITE/assets/agentpro"
  for f in "$SITE/admin/_guard.php" "$SITE/includes/wabot.php" "$SITE"/admin/*.agentpro-*.bak "$SITE"/includes/*.agentpro-*.bak; do
    [ -e "$f" ] && chown www-data:www-data "$f"
  done
fi

cat <<EOF

== 3. Still to do
   a) Database (adds 9 new tables + settings, changes nothing that exists):
        cd "$SITE" && php tests/apply-sql.php database/upgrade-2026-10-agent-pro.sql

   b) The worker — reads documents, answers slow AI messages, tidies up (crontab -e):
        * * * * * php $SITE/cron/agentpro-worker.php >/dev/null 2>&1

   c) Optional, for reading ID photos without AI:
        apt install tesseract-ocr tesseract-ocr-hin tesseract-ocr-nep

   d) Optional, the free AI on this VPS (see AGENT-PRO-NEPALI.md, step 5):
        curl -fsSL https://ollama.com/install.sh | sh && ollama pull qwen3.5:4b

   e) Check it:
        cd "$SITE" && php tests/agent-pro-test.php      (TEST database only)

   Then: Admin → Control Center. Share https://<your site>/join.php with new agents.
EOF
