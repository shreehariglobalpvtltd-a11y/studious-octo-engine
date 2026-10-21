#!/usr/bin/env bash
# =====================================================================
#  tools/test-local.sh — build a throw-away copy of the website with
#  Live Fleet installed, on a throw-away database, and run every test.
#
#      bash tools/test-local.sh [workdir]
#
#  Needs: php 8.3 (pdo_mysql, gd, curl), a MySQL/MariaDB server you can
#  reach as root, git. Uses the database `shari_lf_test` (dropped and
#  re-created) — never the live one. Set MYSQL="mysql -uroot -pSECRET"
#  if root has a password, SITE_REPO / SITE_REF to test another branch.
#
#  Used by .github/workflows/checks.yml as well.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="${1:-$HERE/.test-site}"
MYSQL="${MYSQL:-mysql -uroot}"
DB="shari_lf_test"
SITE_REPO="${SITE_REPO:-https://github.com/shreehariglobalpvtltd-a11y/shreehari.git}"
SITE_REF="${SITE_REF:-}"
PORT="${PORT:-8899}"
# The git repo has site/ + tools/; the download zip has 1-upload-to-public_html/ + 4-tools/.
TOOLS="$HERE/tools";  [ -d "$TOOLS" ] || TOOLS="$HERE/4-tools"
SRC="$HERE/site";     [ -d "$SRC" ]   || SRC="$HERE/1-upload-to-public_html"

echo "== website copy → $WORK"
rm -rf "$WORK"
git clone --quiet --depth 1 ${SITE_REF:+--branch "$SITE_REF"} "$SITE_REPO" "$WORK"

echo "== install Live Fleet"
bash "$TOOLS/install.sh" "$WORK" >/dev/null

echo "== test config + database $DB"
sed -e "s/'localhost'/'127.0.0.1'/; s/u000000000_shari'/$DB'/; s/u000000000_shariuser/lf/; s/CHANGE_ME_STRONG_PASSWORD/lftest/" \
    -e "s/define('APP_ENV',   'production')/define('APP_ENV',   'development')/" \
    -e "s/define('APP_DEBUG', APP_ENV !== 'production')/define('APP_DEBUG', true)/" \
    -e "s/CHANGE_ME_RANDOM_64_CHARS/lf-test-key-lf-test-key-lf-test-key-lf-test-key-lf-test-key-lf-tes/" \
    -e "s#https://www.shreehariglobal.in#http://127.0.0.1:$PORT#; s/define('FORCE_HTTPS', true)/define('FORCE_HTTPS', false)/" \
    "$WORK/config/config.sample.php" > "$WORK/config/config.local.php"
# 'lf'@'%': a database in Docker (GitHub Actions) sees PHP arrive from the
# container network, not from 127.0.0.1.
$MYSQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
           CREATE USER IF NOT EXISTS 'lf'@'%' IDENTIFIED BY 'lftest'; CREATE USER IF NOT EXISTS 'lf'@'localhost' IDENTIFIED BY 'lftest';
           GRANT ALL ON $DB.* TO 'lf'@'%'; GRANT ALL ON $DB.* TO 'lf'@'localhost'; FLUSH PRIVILEGES;"
$MYSQL "$DB" < "$WORK/database/schema.sql"
$MYSQL "$DB" < "$WORK/database/seed.sql"
# The site's upgrades are dated, not ordered by dependency: a second pass
# picks up the few that need a column a later-dated file adds.
for pass in 1 2; do
  for f in $(ls "$WORK"/database/upgrade-*.sql | sort); do $MYSQL -f "$DB" < "$f" >/dev/null 2>&1 || true; done
done
# The site's Database::pdo() prints a connection error and exits with status 0,
# so prove PHP can reach the database before trusting any test result.
(cd "$WORK" && php -r 'define("SHG_APP", true); require "includes/bootstrap.php"; echo Database::scalar("SELECT 1") == 1 ? "db-ok" : "db-bad";') | grep -qx db-ok \
  || { echo "PHP cannot reach the test database — check MYSQL / the lf user" >&2; exit 1; }
(cd "$WORK" && php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql | tail -1)

# A suite passes only if it says so: exit status 0 AND "FAILED: 0" in its output.
suite() {
  local out
  out="$(cd "$WORK" && php "$@" 2>&1)" || { echo "$out"; return 1; }
  echo "$out"
  grep -q "FAILED: 0" <<<"$out"
}

echo "== syntax"
find "$SRC" "$TOOLS" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
node --check "$SRC/assets/livefleet/livefleet.js"
node --check "$SRC/assets/livefleet/overlay.js"

echo "== engine tests"
suite tests/live-fleet-test.php
suite tests/live-fleet-vehicles-test.php

echo "== HTTP tests (php -S 127.0.0.1:$PORT)"
php -S "127.0.0.1:$PORT" -t "$WORK" >"$WORK/logs/devserver.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$PORT/driver/" && break; sleep 0.5; done
suite tests/live-fleet-http-test.php "http://127.0.0.1:$PORT"

echo "== simulator smoke run (3 buses, 6 s)"
php "$TOOLS/fleet-demo-seed.php" "$WORK" >/dev/null
php "$TOOLS/fleet-simulator.php" --base="http://127.0.0.1:$PORT" --driver=DEMO1:1234:1 --driver=DEMO2:1234:2 --driver=DEMO3:1234:3 --seconds=6 | tail -2
php "$TOOLS/fleet-demo-seed.php" "$WORK" --remove >/dev/null

echo
echo "ALL LIVE FLEET TESTS PASSED"
