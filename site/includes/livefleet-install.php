<?php
/**
 * =====================================================================
 *  includes/livefleet-install.php — putting Live Fleet into the website
 *  (Oct 2026). Used by admin/live-install.php (a button, no terminal)
 *  and by tools/install.sh (a terminal):
 *
 *    1. the database upgrade   database/upgrade-2026-10-live-fleet.sql
 *    2. three menu items       admin/_guard.php, under "Map"
 *    3. fleet buses on the     admin/map.php — every bus from Live Fleet
 *       existing Live Map      on the old Live Map, in Google-like colours
 *
 *  Every edit of a PHP file: a backup copy is written first, then the new
 *  code is parsed (PhpToken, TOKEN_PARSE) — a file that would not compile
 *  is never saved. Each step checks first, so running it twice changes
 *  nothing. The file's own line endings (the site uses CRLF) are kept.
 *
 *  CLI (menu + Live Map only, never the database):
 *      php includes/livefleet-install.php /path/to/website
 * =====================================================================
 */
declare(strict_types=1);

final class LiveFleetInstall
{
    public const TABLES = [
        'fleet_driver_logins', 'fleet_devices', 'fleet_vehicle_assign', 'fleet_pair_codes', 'fleet_trips',
        'fleet_points', 'fleet_stop_events', 'fleet_alerts', 'fleet_cameras', 'fleet_places',
    ];

    /** Columns added after the first release — present only once the upgrade has run again. */
    public const COLUMNS = [
        'fleet_devices' => ['kind'],
        'fleet_trips'   => ['share_token', 'avg_speed_kmh', 'last_charging', 'stopped_since'],
    ];

    /** What must have been uploaded (relative to the website folder). */
    public const FILES = [
        'includes/livefleet.php', 'includes/livefleet-install.php',
        'api/driver.php', 'api/live-fleet.php', 'api/fleet-places.php', 'api/fleet-osmand.php',
        'admin/live-fleet.php', 'admin/live-tv.php', 'admin/live-setup.php', 'admin/live-install.php',
        'driver/index.php', 'driver/manifest.webmanifest', 'bus-live.php', 'cron/fleet-cleanup.php',
        'assets/livefleet/livefleet.js', 'assets/livefleet/livefleet.css', 'assets/livefleet/overlay.js',
        'assets/livefleet/shg-logo.svg', 'assets/livefleet/shg-crest.svg',
        'database/upgrade-2026-10-live-fleet.sql',
    ];

    public const SQL_FILE = 'database/upgrade-2026-10-live-fleet.sql';

    /** The menu lines, inserted right after the Live Map line of admin/_guard.php. */
    public const MENU_LINES = [
        "        // Live Fleet (Oct 2026): every bus by vehicle number from the phone in it, 1 s refresh,",
        "        // Nepali announcements, CCTV per bus and a full-screen TV wall. See includes/livefleet.php.",
        "        ['href' => 'live-fleet.php',   'icon' => 'map-pin',      'label' => 'Live Fleet',       'perm' => 'schedules.view', 'section' => 'Map'],",
        "        ['href' => 'live-tv.php',      'icon' => 'grid',         'label' => 'TV Wall',          'perm' => 'schedules.view', 'section' => 'Map'],",
        "        ['href' => 'live-setup.php',   'icon' => 'cog',          'label' => 'Vehicles & Drivers', 'perm' => 'drivers.edit', 'section' => 'Map'],",
    ];

    /** @return list<string> uploaded files that are missing */
    public static function missingFiles(string $root): array
    {
        return array_values(array_filter(self::FILES, static fn(string $f): bool => !is_file($root . '/' . $f)));
    }

    /** @return list<string> tables / "table.column" the database still lacks */
    public static function missingSchema(PDO $pdo): array
    {
        $have = [];
        foreach ($pdo->query("SHOW TABLES LIKE 'fleet\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $have[(string) $t] = true;
        }
        $out = [];
        foreach (self::TABLES as $t) {
            if (!isset($have[$t])) {
                $out[] = $t;
            }
        }
        foreach (self::COLUMNS as $t => $cols) {
            if (!isset($have[$t])) {
                continue;
            }
            $got = array_map('strval', $pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll(PDO::FETCH_COLUMN));
            foreach ($cols as $c) {
                if (!in_array($c, $got, true)) {
                    $out[] = $t . '.' . $c;
                }
            }
        }
        return $out;
    }

    /**
     * The statements of a .sql file, the way tests/apply-sql.php reads them:
     * "-- " comment lines dropped, split on ";".
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $s): bool => $s !== ''));
    }

    /**
     * Run the upgrade. It only creates / adds (IF NOT EXISTS, guarded ALTERs),
     * so a second run is harmless.
     *
     * @return array{ok:int, errors:list<string>}
     */
    public static function runSql(PDO $pdo, string $root): array
    {
        $file = $root . '/' . self::SQL_FILE;
        if (!is_file($file)) {
            return ['ok' => 0, 'errors' => ['Missing ' . self::SQL_FILE . ' — upload it first.']];
        }
        $ok = 0;
        $errors = [];
        foreach (self::statements((string) file_get_contents($file)) as $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (Throwable $e) {
                $errors[] = substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 70) . ' … → ' . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    public static function menuDone(string $root): bool
    {
        return is_file($root . '/admin/_guard.php') && str_contains((string) file_get_contents($root . '/admin/_guard.php'), "'live-fleet.php'");
    }

    public static function mapDone(string $root): bool
    {
        return !is_file($root . '/admin/map.php') || str_contains((string) file_get_contents($root . '/admin/map.php'), 'LiveFleetOverlay');
    }

    /** Add the three menu items under the Live Map line. @return string what happened */
    public static function patchMenu(string $root): string
    {
        $path = $root . '/admin/_guard.php';
        if (!is_file($path)) {
            throw new RuntimeException('admin/_guard.php not found.');
        }
        if (self::menuDone($root)) {
            return 'Menu items already there.';
        }
        $code = (string) file_get_contents($path);
        $nl = str_contains($code, "\r\n") ? "\r\n" : "\n";
        // The Live Map line: "['href' => 'map.php', … ]," — the new lines go right after it.
        if (preg_match("/^[ \\t]*\\['href'\\s*=>\\s*'map\\.php'.*\\],[ \\t]*\\r?\\n/m", $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('Could not find the Live Map menu line in admin/_guard.php — add the lines by hand (2-menu/MENU-5-LINES.txt).');
        }
        $at = (int) $m[0][1] + strlen((string) $m[0][0]);
        $new = substr($code, 0, $at) . implode($nl, self::MENU_LINES) . $nl . substr($code, $at);
        return 'Menu items added. Backup: ' . self::write($path, $new);
    }

    /** Show the fleet on admin/map.php. @return string what happened */
    public static function patchMap(string $root): string
    {
        $path = $root . '/admin/map.php';
        if (!is_file($path)) {
            return 'No admin/map.php in this website — nothing to do.';
        }
        if (self::mapDone($root)) {
            return 'Live Map already shows the fleet.';
        }
        $code = (string) file_get_contents($path);
        $nl = str_contains($code, "\r\n") ? "\r\n" : "\n";
        // 1) the two scripts, just before the page's own map script
        if (preg_match('/^<script>\r?\nwindow\.ADMAP\b/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('admin/map.php looks different from the expected version — the Live Map hook was not added (Live Fleet itself works without it).');
        }
        $scripts = implode($nl, [
            "<?php /* Live Fleet (Oct 2026): every fleet bus on this map, in Google-like colours. */ if (is_file(INCLUDE_PATH . '/livefleet.php')) { require_once INCLUDE_PATH . '/livefleet.php'; ?>",
            "<link rel=\"stylesheet\" href=\"<?= Security::e(LiveFleet::asset('livefleet.css')) ?>\">",
            "<script src=\"<?= Security::e(LiveFleet::asset('livefleet.js')) ?>\"></script>",
            "<script src=\"<?= Security::e(LiveFleet::asset('overlay.js')) ?>\"></script>",
            '<?php } ?>',
        ]) . $nl;
        $code = substr($code, 0, (int) $m[0][1]) . $scripts . substr($code, (int) $m[0][1]);
        // 2) one call once the map has drawn its routes
        if (preg_match('/^([ \t]*)draw\(\);[ \t]*\r?\n/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('Could not find draw(); in admin/map.php — the Live Map hook was not added.');
        }
        $at = (int) $m[0][1] + strlen((string) $m[0][0]);
        $code = substr($code, 0, $at) . $m[1][0] . 'if (window.LiveFleetOverlay) window.LiveFleetOverlay(map);' . $nl . substr($code, $at);
        return 'Live Map now shows every fleet bus. Backup: ' . self::write($path, $code);
    }

    /** Backup, prove the new code parses, then write. @return string the backup's file name */
    private static function write(string $path, string $code): string
    {
        try {
            PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException(basename($path) . ' would not compile after the change, so it was left as it was (' . $e->getMessage() . ').');
        }
        if (!is_writable($path)) {
            throw new RuntimeException(basename($path) . ' is not writable by PHP — make the change by hand (docs/INSTALL-NEPALI.md).');
        }
        // Ends in .bak: the site's .htaccess refuses to serve *.bak, so the old source never leaks.
        $backup = $path . '.livefleet-' . date('Ymd-His') . '.bak';
        if (!copy($path, $backup)) {
            throw new RuntimeException('Could not write a backup next to ' . basename($path) . ' — nothing was changed.');
        }
        if (file_put_contents($path, $code, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . basename($path) . ' — the backup ' . basename($backup) . ' is untouched.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return basename($backup);
    }
}

// php includes/livefleet-install.php /path/to/website — menu + Live Map (tools/install.sh)
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    $root = rtrim((string) ($argv[1] ?? ''), '/');
    if ($root === '' || !is_file($root . '/admin/_guard.php')) {
        fwrite(STDERR, "usage: php includes/livefleet-install.php /path/to/website\n");
        exit(2);
    }
    $fail = 0;
    foreach (['patchMenu', 'patchMap'] as $step) {
        try {
            echo '   ' . LiveFleetInstall::$step($root) . "\n";
        } catch (RuntimeException $e) {
            $fail++;
            fwrite(STDERR, '   ' . $e->getMessage() . "\n");
        }
    }
    exit($fail === 0 ? 0 : 1);
}
