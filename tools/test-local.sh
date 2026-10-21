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

echo "== website copy → $WORK"
rm -rf "$WORK"
git clone --quiet --depth 1 ${SITE_REF:+--branch "$SITE_REF"} "$SITE_REPO" "$WORK"

echo "== install Live Fleet"
bash "$HERE/tools/install.sh" "$WORK" >/dev/null

echo "== test config + database $DB"
sed -e "s/'localhost'/'127.0.0.1'/; s/u000000000_shari'/$DB'/; s/u000000000_shariuser/lf/; s/CHANGE_ME_STRONG_PASSWORD/lftest/" \
    -e "s/define('APP_ENV',   'production')/define('APP_ENV',   'development')/" \
    -e "s/define('APP_DEBUG', APP_ENV !== 'production')/define('APP_DEBUG', true)/" \
    -e "s/CHANGE_ME_RANDOM_64_CHARS/lf-test-key-lf-test-key-lf-test-key-lf-test-key-lf-test-key-lf-tes/" \
    -e "s#https://www.shreehariglobal.in#http://127.0.0.1:$PORT#; s/define('FORCE_HTTPS', true)/define('FORCE_HTTPS', false)/" \
    "$WORK/config/config.sample.php" > "$WORK/config/config.local.php"
$MYSQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
           CREATE USER IF NOT EXISTS 'lf'@'127.0.0.1' IDENTIFIED BY 'lftest'; CREATE USER IF NOT EXISTS 'lf'@'localhost' IDENTIFIED BY 'lftest';
           GRANT ALL ON $DB.* TO 'lf'@'127.0.0.1'; GRANT ALL ON $DB.* TO 'lf'@'localhost'; FLUSH PRIVILEGES;"
$MYSQL "$DB" < "$WORK/database/schema.sql"
$MYSQL "$DB" < "$WORK/database/seed.sql"
# The site's upgrades are dated, not ordered by dependency: a second pass
# picks up the few that need a column a later-dated file adds.
for pass in 1 2; do
  for f in $(ls "$WORK"/database/upgrade-*.sql | sort); do $MYSQL -f "$DB" < "$f" >/dev/null 2>&1 || true; done
done
(cd "$WORK" && php tests/apply-sql.php database/upgrade-2026-10-live-fleet.sql | tail -1)

echo "== syntax"
find "$HERE/site" "$HERE/tools" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
node --check "$HERE/site/assets/livefleet/livefleet.js"

echo "== engine tests"
(cd "$WORK" && php tests/live-fleet-test.php)

echo "== HTTP tests (php -S 127.0.0.1:$PORT)"
php -S "127.0.0.1:$PORT" -t "$WORK" >"$WORK/logs/devserver.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$PORT/driver/" && break; sleep 0.5; done
(cd "$WORK" && php tests/live-fleet-http-test.php "http://127.0.0.1:$PORT")

echo "== simulator smoke run (3 buses, 6 s)"
php "$HERE/tools/fleet-demo-seed.php" "$WORK" >/dev/null
php "$HERE/tools/fleet-simulator.php" --base="http://127.0.0.1:$PORT" --driver=DEMO1:1234:1 --driver=DEMO2:1234:2 --driver=DEMO3:1234:3 --seconds=6 | tail -2
php "$HERE/tools/fleet-demo-seed.php" "$WORK" --remove >/dev/null

echo
echo "ALL LIVE FLEET TESTS PASSED"
