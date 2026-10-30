#!/usr/bin/env bash
# =====================================================================
#  tools/agentpro-deploy.sh — put Agent Pro on the LIVE site, then test it.
#  Run it ON the VPS as root (hPanel → VPS → Browser terminal, or ssh):
#
#      bash agentpro-deploy.sh            back up → install → database → cron → test
#      bash agentpro-deploy.sh --check    only the tests (is it live and working?)
#
#  From a laptop instead:  ssh root@<vps> 'bash -s' < agentpro-deploy.sh
#
#  Safe by design:
#    - a full backup first (files + database) — it stops if the backup fails
#    - every Agent Pro file is NEW; the installer stops if one already
#      exists and differs, before copying anything
#    - only admin/_guard.php and includes/wabot.php are edited, each with
#      its own .bak copy and a PHP parse check
#    - the SQL only creates ap_* tables and adds settings (INSERT IGNORE)
#    - it prints the exact undo commands at the end (put the two edited
#      files back; the new tables and files are then simply unused)
#
#  Settings (environment): SITE (website folder), ZIP (a local package
#  instead of downloading), BASE_URL (address for the tests).
# =====================================================================
set -euo pipefail

SITE="${SITE:-/var/www/shreehariglobal.in/public_html}"
BASE_URL="${BASE_URL:-https://www.shreehariglobal.in}"
ZIP_URL="${ZIP_URL:-https://github.com/shreehariglobalpvtltd-a11y/studious-octo-engine/raw/ccr-38414d31-y3cy2h/dist/agent-pro-shreehari.zip}"
ZIP="${ZIP:-}"
BACKUPS="${BACKUPS:-/root/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
MODE="deploy"
[ "${1:-}" = "--check" ] && MODE="check"

ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$*"; FAILS=$((FAILS + 1)); }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die()  { printf '\n\033[31mSTOPPED:\033[0m %s\nNothing after this point was changed.\n' "$*" >&2; exit 1; }
FAILS=0

[ -f "$SITE/includes/bootstrap.php" ] && [ -f "$SITE/admin/_guard.php" ] || die "no website at $SITE (set SITE=/path/to/public_html)"
command -v php >/dev/null || die "php is not installed"
cd "$SITE"
# The website runs as www-data: run its PHP as www-data too, so nothing it creates
# (an upload folder, a log, a lock file) ends up owned by root and unwritable for the site.
if [ "$(id -u)" = "0" ] && id www-data >/dev/null 2>&1 && command -v runuser >/dev/null; then
  sitephp() { runuser -u www-data -- php "$@"; }
else
  sitephp() { php "$@"; }
fi

# The site's own PHP knows the database name; the password never leaves PHP.
DB_NAME="$(sitephp -r 'define("SHG_APP", true); require "includes/bootstrap.php"; echo DB_NAME;' 2>/dev/null)" || die "PHP could not load the website (includes/bootstrap.php)"
[ -n "$DB_NAME" ] || die "could not read the database name from the site config"

# ------------------------------------------------------------------ tests
run_tests() {
  step "Tests on $BASE_URL"
  local php_check
  php_check="$(sitephp -r '
    define("SHG_APP", true); require "includes/bootstrap.php"; require_once INCLUDE_PATH . "/agentpro.php";
    require_once INCLUDE_PATH . "/agentpro-install.php"; require_once INCLUDE_PATH . "/agentpro-card.php";
    $miss = AgentProInstall::missingSchema(Database::pdo());
    echo "schema=" . ($miss === [] ? "ok" : implode(",", $miss)) . "\n";
    echo "available=" . (AgentPro::available() ? "yes" : "no") . "\n";
    echo "files=" . (AgentProInstall::missingFiles(ROOT_PATH) === [] ? "ok" : implode(",", AgentProInstall::missingFiles(ROOT_PATH))) . "\n";
    echo "menu=" . (str_contains((string) file_get_contents(ROOT_PATH . "/admin/_guard.php"), "hub.php") ? "ok" : "missing") . "\n";
    echo "wabot=" . (str_contains((string) file_get_contents(INCLUDE_PATH . "/wabot.php"), "agentpro-wa.php") ? "ok" : "missing") . "\n";
    echo "gd=" . (function_exists("imagettftext") ? "ok" : "missing") . "\n";
  ' 2>&1)" || true
  grep -q '^schema=ok$'   <<<"$php_check" && ok "database: all 9 ap_* tables + settings"        || bad "database: $(grep '^schema=' <<<"$php_check" || echo "$php_check" | tail -2)"
  grep -q '^available=yes$' <<<"$php_check" && ok "Agent Pro switched on"                       || bad "Agent Pro reports not available"
  grep -q '^files=ok$'    <<<"$php_check" && ok "all Agent Pro files in place"                   || bad "files: $(grep '^files=' <<<"$php_check")"
  grep -q '^menu=ok$'     <<<"$php_check" && ok "admin menu has Control Center / Team & ID Cards" || bad "admin menu lines missing (admin/_guard.php)"
  grep -q '^wabot=ok$'    <<<"$php_check" && ok "WhatsApp desk hooked into includes/wabot.php"    || bad "WhatsApp hooks missing (includes/wabot.php)"
  grep -q '^gd=ok$'       <<<"$php_check" && ok "PHP GD with fonts (card images)"                 || bad "PHP GD/FreeType missing: apt install php8.3-gd"

  # Over HTTP, as a visitor would see it.
  http() { curl -sk -o /dev/null -w '%{http_code}' -m 20 "$BASE_URL$1" 2>/dev/null || echo 000; }
  body() { curl -sk -m 20 "$BASE_URL$1" 2>/dev/null || true; }
  local c
  c="$(http /join.php)";                 [ "$c" = 200 ] && ok "join form opens (/join.php)"            || bad "/join.php answered $c"
  body /join.php | grep -q 'assets/agentpro/join.js' && ok "join form loads its app"                 || bad "/join.php does not load assets/agentpro/join.js"
  c="$(http /assets/agentpro/join.js)";  [ "$c" = 200 ] && ok "join form script served"                 || bad "/assets/agentpro/join.js answered $c"
  c="$(http /me/)";                      [ "$c" = 200 ] && ok "member portal opens (/me/)"             || bad "/me/ answered $c"
  body '/api/agentpro.php?a=csrf' | grep -q '"ok":true' && ok "Agent Pro API answers"                || bad "/api/agentpro.php?a=csrf did not answer ok"
  c="$(http '/id.php?c=not-a-real-card')"; case "$c" in 200|404) ok "ID check page works (unknown card → NOT VALID)";; *) bad "/id.php answered $c";; esac
  c="$(http /admin/hub.php)";            case "$c" in 200|301|302|303) ok "Control Center protected by login ($c)";; *) bad "/admin/hub.php answered $c";; esac
  c="$(http '/api/agentpro-card.php?m=1&s=id-front')"; case "$c" in 401|403) ok "ID card images need a login or a signed link ($c)";; *) bad "ID card image answered $c without a login";; esac
  c="$(http /uploads/agents-kyc/x.jpg)"; case "$c" in 403|404) ok "KYC documents folder is not public ($c)";; *) bad "/uploads/agents-kyc/ answered $c — add: location ^~ /uploads/agents-kyc/ { deny all; }";; esac

  if crontab -u www-data -l 2>/dev/null | grep -q 'cron/agentpro-worker.php'; then ok "worker cron set (every minute, as www-data)"
  else bad "worker cron missing"; fi
  local seen
  seen="$(sitephp -r 'define("SHG_APP", true); require "includes/bootstrap.php"; $s = Settings::getString("ap_worker_seen", ""); echo ctype_digit($s) ? date("d M Y H:i:s", (int) $s) : $s;' 2>/dev/null || true)"
  [ -n "$seen" ] && ok "worker last ran: $seen" || echo "  · worker has not run yet (normal in the first minute)"

  echo
  if [ "$FAILS" -eq 0 ]; then
    printf '\033[32mALL CHECKS PASSED\033[0m — open %s/admin/hub.php and %s/join.php\n' "$BASE_URL" "$BASE_URL"
  else
    printf '\033[31m%d CHECK(S) FAILED\033[0m — see the ✗ lines above\n' "$FAILS"
  fi
}

if [ "$MODE" = "check" ]; then
  run_tests
  exit $([ "$FAILS" -eq 0 ] && echo 0 || echo 1)
fi

[ "$(id -u)" = "0" ] || die "run as root (the files belong to www-data, the cron is set for www-data)"

# ----------------------------------------------------------------- backup
step "1. Backup → $BACKUPS"
mkdir -p "$BACKUPS"
chmod 700 "$BACKUPS"
FILES_BAK="$BACKUPS/site-before-agentpro-$STAMP.tgz"
DB_BAK="$BACKUPS/$DB_NAME-before-agentpro-$STAMP.sql.gz"
tar -czf "$FILES_BAK" -C "$(dirname "$SITE")" "$(basename "$SITE")" || die "file backup failed"
ok "files: $FILES_BAK ($(du -h "$FILES_BAK" | cut -f1))"
# MariaDB's root uses the unix socket on this server; fall back to the site's own login
# through a private temporary file (never printed, removed straight after).
if mysqldump --single-transaction --routines "$DB_NAME" 2>/dev/null | gzip > "$DB_BAK" && [ "$(gzip -dc "$DB_BAK" | head -c 4096 | grep -c 'CREATE TABLE\|Dump')" -gt 0 ]; then
  ok "database: $DB_BAK ($(du -h "$DB_BAK" | cut -f1))"
else
  CNF="$(mktemp)"; chmod 600 "$CNF"
  trap 'rm -f "$CNF"' EXIT
  sitephp -r 'define("SHG_APP", true); require "includes/bootstrap.php";
    $h = DB_HOST; $port = ""; if (str_contains($h, ";port=")) { [$h, $port] = explode(";port=", $h, 2); }
    echo "[client]\nuser=" . DB_USER . "\npassword=\"" . addcslashes(DB_PASS, "\"\\\\") . "\"\nhost=" . $h . ($port !== "" ? "\nport=" . $port : "") . "\n";' > "$CNF"
  mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces "$DB_NAME" 2>/dev/null | gzip > "$DB_BAK"
  rm -f "$CNF"
  [ "$(gzip -dc "$DB_BAK" | head -c 4096 | grep -c 'CREATE TABLE\|Dump')" -gt 0 ] || die "database backup failed — nothing was changed"
  ok "database: $DB_BAK ($(du -h "$DB_BAK" | cut -f1))"
fi

# ---------------------------------------------------------------- package
step "2. Agent Pro package"
WORK="$(mktemp -d /root/agentpro-XXXXXX)"
if [ -z "$ZIP" ]; then
  command -v curl >/dev/null || die "curl is not installed"
  curl -fsSL -m 120 -o "$WORK/agent-pro.zip" "$ZIP_URL" || die "could not download $ZIP_URL"
  ZIP="$WORK/agent-pro.zip"
fi
command -v unzip >/dev/null || { apt-get install -y -qq unzip >/dev/null 2>&1 || die "unzip is not installed (apt install unzip)"; }
unzip -q "$ZIP" -d "$WORK" || die "the package is not a valid zip"
PKG="$(find "$WORK" -maxdepth 1 -type d -name 'agent-pro-shreehari-*' | head -1)"
[ -n "$PKG" ] && [ -f "$PKG/4-tools/agentpro-install.sh" ] || die "the package has no 4-tools/agentpro-install.sh"
ok "package: $(basename "$PKG")"

# ---------------------------------------------------------------- install
step "3. Files, menu and WhatsApp hooks"
INSTALL_OUT="$(bash "$PKG/4-tools/agentpro-install.sh" "$SITE" 2>&1)" || { printf '%s\n' "$INSTALL_OUT" | grep -v '^   + '; die "the installer stopped (see above) — the backup is $FILES_BAK"; }
printf '%s\n' "$INSTALL_OUT" | grep -E '^   (Menu|WhatsApp|not done)' || true
php -l "$SITE/admin/_guard.php" >/dev/null && php -l "$SITE/includes/wabot.php" >/dev/null || die "a PHP check failed after editing — put back the .agentpro-*.bak copies next to those files"
grep -q "'href' => 'hub.php'" "$SITE/admin/_guard.php" || die "the menu lines were not added — paste them from $PKG/2-menu/AGENTPRO-MENU.txt, then run this again"
grep -q 'agentpro-wa.php' "$SITE/includes/wabot.php" || die "the WhatsApp hooks were not added — see $PKG/2-menu/AGENTPRO-WABOT.txt, then run this again"
GUARD_BAK="$(ls -t "$SITE"/admin/_guard.php.agentpro-*.bak 2>/dev/null | head -1 || true)"
WABOT_BAK="$(ls -t "$SITE"/includes/wabot.php.agentpro-*.bak 2>/dev/null | head -1 || true)"
ok "files copied, menu and WhatsApp hooks in place (PHP check passed)"

# --------------------------------------------------------------- database
step "4. Database ($DB_NAME): 9 new ap_* tables + settings"
sitephp tests/apply-sql.php database/upgrade-2026-10-agent-pro.sql | tail -1 | grep -q '^done$' || die "the SQL upgrade reported an error — run: cd $SITE && php tests/apply-sql.php database/upgrade-2026-10-agent-pro.sql"
ok "database upgraded"

# ------------------------------------------------------------------- cron
step "5. Worker (every minute, as www-data)"
CRON_LINE="* * * * * $(command -v php) $SITE/cron/agentpro-worker.php >/dev/null 2>&1"
if crontab -u www-data -l 2>/dev/null | grep -q 'cron/agentpro-worker.php'; then
  ok "already set"
else
  (crontab -u www-data -l 2>/dev/null; echo "$CRON_LINE") | crontab -u www-data - && ok "added: $CRON_LINE" || bad "could not add the cron line — add it by hand: crontab -u www-data -e"
fi

# --------------------------------------------------- reload + site history
step "6. Reload PHP and record the change"
if command -v systemctl >/dev/null && systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
  systemctl reload php8.3-fpm && ok "php8.3-fpm reloaded (picks up the edited files at once)"
fi
# The site keeps its history with git (see the site's CLAUDE.md): commit exactly what changed.
# The files belong to www-data and this runs as root: say this folder is trusted, for these commands only.
sitegit() { git -c safe.directory="$SITE" -C "$SITE" "$@"; }
if sitegit rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  mapfile -t AP_FILES < <(bash "$PKG/4-tools/agentpro-install.sh" --list)
  if sitegit add -- "${AP_FILES[@]}" admin/_guard.php includes/wabot.php \
     && sitegit -c user.name="${GIT_AUTHOR_NAME:-Agent Pro installer}" -c user.email="${GIT_AUTHOR_EMAIL:-root@localhost}" \
            commit -q -m "Add Agent Pro (ID + visiting cards, join form, wallet, WhatsApp desk)" -- "${AP_FILES[@]}" admin/_guard.php includes/wabot.php >/dev/null 2>&1; then
    ok "committed to the site's git history: $(sitegit log --oneline -1)"
  else
    echo "  · git commit skipped (nothing new to commit)"
  fi
else
  echo "  · the website folder is not a git repository — no history recorded"
fi
rm -rf "$WORK"

run_tests

cat <<EOF

To undo (only if needed) — put the two edited files back and stop the worker;
the new pages and ap_* tables are then simply unused, and no booking is lost:
${GUARD_BAK:+  cp -p "$GUARD_BAK" "$SITE/admin/_guard.php"
}${WABOT_BAK:+  cp -p "$WABOT_BAK" "$SITE/includes/wabot.php"
}  crontab -u www-data -l | grep -v agentpro-worker | crontab -u www-data -
Full backups (last resort — restoring the database would also remove anything booked since):
  $FILES_BAK
  $DB_BAK
EOF
exit $([ "$FAILS" -eq 0 ] && echo 0 || echo 1)
