#!/usr/bin/env bash
# =====================================================================
#  tools/install.sh — copy Live Fleet into a copy of the website.
#
#      bash tools/install.sh /path/to/website
#
#  What it does (and nothing else):
#    1. copies every file under site/ to the same path in the website
#       (all are NEW files — it stops if one already exists and differs)
#    2. adds the three menu items to admin/_guard.php (patches/admin-nav.patch)
#    3. prints the two commands still to run: the database upgrade and
#       the hourly cleanup cron
#
#  It does NOT touch the database. Try it on a test copy first.
# =====================================================================
set -euo pipefail

SITE="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -z "$SITE" ] || [ ! -f "$SITE/includes/bootstrap.php" ] || [ ! -f "$SITE/admin/_guard.php" ]; then
  echo "usage: bash tools/install.sh /path/to/website   (the folder that has includes/ and admin/)" >&2
  exit 2
fi

echo "== 1. Copying files into $SITE"
cd "$HERE/site"
COPIED=()
while IFS= read -r -d '' f; do
  rel="${f#./}"
  dest="$SITE/$rel"
  if [ -e "$dest" ] && ! cmp -s "$f" "$dest"; then
    echo "   STOP: $rel already exists in the website and is different. Nothing more was changed." >&2
    exit 1
  fi
  mkdir -p "$(dirname "$dest")"
  cp "$f" "$dest"
  COPIED+=("$dest")
  echo "   + $rel"
done < <(find . -type f -print0 | sort -z)

echo "== 2. Adding the menu items (admin/_guard.php)"
if grep -q "live-fleet.php" "$SITE/admin/_guard.php"; then
  echo "   already there"
elif (cd "$SITE" && patch -p1 --dry-run --silent < "$HERE/patches/admin-nav.patch" >/dev/null 2>&1); then
  (cd "$SITE" && patch -p1 --silent < "$HERE/patches/admin-nav.patch")
  echo "   added: Live Fleet · TV Wall · Driver Login & CCTV"
else
  echo "   could not patch automatically — add the 5 lines from patches/admin-nav.patch by hand" >&2
fi

# On the server the web files belong to www-data (see the site's CLAUDE.md).
if [ "$(id -u)" = "0" ] && id www-data >/dev/null 2>&1; then
  chown www-data:www-data "${COPIED[@]}" "$SITE/driver" "$SITE/assets/livefleet"
fi

cat <<EOF

== 3. Still to do
   a) Database (adds 5 new tables, changes nothing that exists):
        cd "$SITE" && php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql

   b) Hourly cleanup (crontab -e):
        17 * * * * php $SITE/cron/fleet-cleanup.php >/dev/null 2>&1

   c) Check it:
        cd "$SITE" && php -l includes/livefleet.php && php tests/live-fleet-test.php   (TEST database only)

   Then: Admin → Map → Driver Login & CCTV → give a driver a Driver ID + PIN.
EOF
