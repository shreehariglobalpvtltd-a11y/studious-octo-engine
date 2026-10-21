<?php
/**
 * =====================================================================
 *  includes/livefleet.php — Live Fleet engine (Oct 2026).
 *
 *  Every bus on one map, each from its own driver's phone:
 *
 *    driver phone (driver/index.php)
 *        │  Driver ID + PIN  → device token (fleet_devices)
 *        │  Start trip       → fleet_trips row (status running)
 *        │  GPS fix ~1/s     → api/driver.php → LiveFleet::ping()
 *        ▼
 *    fleet_trips.last_*  (the live position — one row per bus)
 *    fleet_points        (the trail, thinned to one point per ~5 s)
 *        ▼
 *    api/live-fleet.php  → admin/live-fleet.php, admin/live-tv.php (1 s refresh)
 *
 *  What this replaces: before, the whole company shared ONE slot — the
 *  `livebus` key in kv_store — so a second driver's phone overwrote the
 *  first, and only a staff account could publish. That key is still
 *  written (mirrorLegacy) while a single bus is live, so the passenger
 *  Trip Companion, api/eta.php and the "bus is near" alerts keep working.
 *
 *  CCTV: fleet_cameras holds any number of feeds per bus. The browser
 *  plays them directly (HLS / MJPEG / snapshot / vendor embed); see
 *  docs/CCTV.md for turning a bus DVR's RTSP feed into one of those.
 * =====================================================================
 */

declare(strict_types=1);

final class LiveFleet
{
    /** A ping this recent → "live" (green). */
    public const LIVE_SECONDS = 20;
    /** Older than LIVE but this recent → "weak signal" (amber). Older → "offline". */
    public const WEAK_SECONDS = 180;
    /** Keep one trail point per this many seconds; the live position still moves every ping. */
    public const TRAIL_SECONDS = 5;
    /** Points accepted in one upload — five minutes of a phone that lost signal at 1 fix/s. */
    public const MAX_BATCH = 300;
    /** A jump faster than this between two fixes is GPS noise, not distance driven. */
    public const MAX_SPEED_KMH = 160;
    /** Fixes worse than this are kept as the live dot but never counted as distance. */
    public const GOOD_ACCURACY_M = 100;
    public const PIN_MIN = 4;
    public const PIN_MAX = 8;
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;
    /** A phone not seen for this long must sign in again. */
    public const DEVICE_IDLE_DAYS = 60;
    /** How often the legacy `livebus` key is refreshed. */
    public const MIRROR_SECONDS = 10;

    public const CAMERA_KINDS = [
        'hls'      => 'HLS video (.m3u8)',
        'mjpeg'    => 'MJPEG stream',
        'snapshot' => 'Snapshot JPEG (refreshed every second)',
        'embed'    => 'Web player page (DVR / cloud viewer)',
    ];

    /* =================================================================
     *  Driver logins (Driver ID + PIN)
     * ================================================================= */

    /** "d 101 " → "D101". Letters, digits and dashes only. */
    public static function normaliseCode(string $raw): string
    {
        return substr((string) preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($raw))), 0, 20);
    }

    /** The Driver ID offered when the office has not chosen one: D101, D102… */
    public static function suggestCode(int $driverId): string
    {
        return 'D' . (100 + $driverId);
    }

    /**
     * Give a driver (or change) their phone login. An empty $pin keeps the
     * current PIN. A NEW PIN signs every phone of that driver out — the
     * office changes the PIN precisely when a phone is lost.
     */
    public static function setLogin(int $driverId, string $code, string $pin, int $by = 0): void
    {
        $driver = Database::fetch('SELECT id, full_name FROM drivers WHERE id = :id', ['id' => $driverId]);
        if ($driver === null) {
            throw new RuntimeException('Driver not found.');
        }
        $code = self::normaliseCode($code);
        if (strlen($code) < 2) {
            throw new RuntimeException('Driver ID needs at least 2 letters or digits, e.g. D101.');
        }
        if ($pin !== '' && preg_match('/^\d{' . self::PIN_MIN . ',' . self::PIN_MAX . '}$/', $pin) !== 1) {
            throw new RuntimeException('PIN must be ' . self::PIN_MIN . ' to ' . self::PIN_MAX . ' digits.');
        }
        $taken = Database::fetch(
            'SELECT l.driver_id, d.full_name FROM fleet_driver_logins l JOIN drivers d ON d.id = l.driver_id
              WHERE l.login_code = :c AND l.driver_id <> :id',
            ['c' => $code, 'id' => $driverId]
        );
        if ($taken !== null) {
            throw new RuntimeException('Driver ID ' . $code . ' already belongs to ' . $taken['full_name'] . '.');
        }

        $now = date('Y-m-d H:i:s');
        $existing = Database::fetch('SELECT driver_id FROM fleet_driver_logins WHERE driver_id = :id', ['id' => $driverId]);
        if ($existing === null) {
            if ($pin === '') {
                throw new RuntimeException('Set a PIN for the first login.');
            }
            Database::insert('fleet_driver_logins', [
                'driver_id' => $driverId, 'login_code' => $code, 'pin_hash' => Security::hashPassword($pin),
                'failed_count' => 0, 'locked_until' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $data = ['login_code' => $code, 'updated_at' => $now];
            if ($pin !== '') {
                $data += ['pin_hash' => Security::hashPassword($pin), 'failed_count' => 0, 'locked_until' => null];
            }
            Database::update('fleet_driver_logins', $data, 'driver_id = :id', ['id' => $driverId]);
        }
        if ($pin !== '' && $existing !== null) {
            self::revokeDriverDevices($driverId);
        }
        // Never the PIN itself.
        Logger::audit('fleet.login.set', 'driver', (string) $driverId, null, ['code' => $code, 'pin_changed' => $pin !== '', 'by' => $by]);
    }

    /** Take the phone login away and sign every phone of this driver out. */
    public static function removeLogin(int $driverId, int $by = 0): void
    {
        Database::delete('fleet_driver_logins', 'driver_id = :id', ['id' => $driverId]);
        self::revokeDriverDevices($driverId);
        Logger::audit('fleet.login.remove', 'driver', (string) $driverId, null, ['by' => $by]);
    }

    /**
     * Driver ID (or the driver's mobile) + PIN → a new device token.
     *
     * @return array{ok:bool, error?:string, token?:string, driver?:array<string,mixed>}
     */
    public static function login(string $codeOrPhone, string $pin, string $userAgent = '', string $ip = ''): array
    {
        $wrong = ['ok' => false, 'error' => 'Driver ID or PIN is wrong. · ड्राइभर आईडी वा पिन मिलेन।'];
        $code  = self::normaliseCode($codeOrPhone);
        if ($code === '' || $pin === '') {
            return $wrong;
        }

        $row = Database::fetch(
            'SELECT l.*, d.full_name, d.phone, d.is_active
               FROM fleet_driver_logins l JOIN drivers d ON d.id = l.driver_id
              WHERE l.login_code = :c LIMIT 1',
            ['c' => $code]
        );
        // A mobile number works as the Driver ID too.
        if ($row === null && ctype_digit($code) && strlen($code) >= 10) {
            $phone = normalisePhone($code);
            $row = Database::fetch(
                'SELECT l.*, d.full_name, d.phone, d.is_active
                   FROM fleet_driver_logins l JOIN drivers d ON d.id = l.driver_id
                  WHERE d.phone IN (:p, :raw) LIMIT 1',
                ['p' => $phone, 'raw' => $code]
            );
        }
        if ($row === null) {
            // Same work as a real check, so a guess cannot be timed apart from a wrong PIN.
            Security::verifyPassword($pin, '$2y$11$yxH/L5KzAdA2MSRySFWXm.cTRmMPDI.Wv00cDztf8GUFivDHU67Ea');
            return $wrong;
        }
        if ((int) $row['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'This driver is switched off. Please call the office. · कार्यालयमा फोन गर्नुहोस्।'];
        }
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'Too many wrong PINs. Try again after ' . date('g:i A', (int) strtotime((string) $row['locked_until'])) . '.'];
        }
        if (!Security::verifyPassword($pin, (string) $row['pin_hash'])) {
            $fails = (int) $row['failed_count'] + 1;
            Database::update('fleet_driver_logins', [
                'failed_count' => $fails >= self::MAX_FAILS ? 0 : $fails,
                'locked_until' => $fails >= self::MAX_FAILS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null,
            ], 'driver_id = :id', ['id' => (int) $row['driver_id']]);
            return $wrong;
        }

        Database::update('fleet_driver_logins', ['failed_count' => 0, 'locked_until' => null], 'driver_id = :id', ['id' => (int) $row['driver_id']]);
        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');
        Database::insert('fleet_devices', [
            'driver_id' => (int) $row['driver_id'], 'token_hash' => hash('sha256', $token),
            'label' => self::deviceLabel($userAgent), 'last_ip' => $ip !== '' ? substr($ip, 0, 45) : null,
            'created_at' => $now, 'last_seen_at' => $now,
        ]);
        Logger::audit('fleet.device.login', 'driver', (string) $row['driver_id'], null, ['code' => $row['login_code'], 'ip' => $ip]);

        return ['ok' => true, 'token' => $token, 'driver' => [
            'id' => (int) $row['driver_id'], 'name' => (string) $row['full_name'], 'code' => (string) $row['login_code'],
        ]];
    }

    /**
     * The signed-in phone behind a token, or null (unknown, revoked, idle
     * too long, or the driver was switched off).
     *
     * @return array<string,mixed>|null
     */
    public static function device(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = Database::fetch(
            'SELECT v.*, d.full_name AS driver_name, d.phone AS driver_phone, d.is_active AS driver_active, l.login_code
               FROM fleet_devices v
               JOIN drivers d ON d.id = v.driver_id
               JOIN fleet_driver_logins l ON l.driver_id = v.driver_id
              WHERE v.token_hash = :h AND v.revoked_at IS NULL LIMIT 1',
            ['h' => hash('sha256', $token)]
        );
        if ($row === null || (int) $row['driver_active'] !== 1) {
            return null;
        }
        $seen = strtotime((string) ($row['last_seen_at'] ?? $row['created_at']));
        if ($seen < time() - self::DEVICE_IDLE_DAYS * 86400) {
            Database::update('fleet_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $row['id']]);
            return null;
        }
        // One write a minute, not one per ping.
        if ($seen < time() - 60) {
            Database::update('fleet_devices', ['last_seen_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $row['id']]);
        }
        return $row;
    }

    public static function logout(array $device): void
    {
        self::endTrip($device, 'logout');
        Database::update('fleet_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $device['id']]);
    }

    public static function revokeDevice(int $deviceId, int $by = 0): void
    {
        $dev = Database::fetch('SELECT * FROM fleet_devices WHERE id = :id', ['id' => $deviceId]);
        if ($dev === null) {
            return;
        }
        self::endTrip($dev, 'revoked');
        Database::update('fleet_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = :id AND revoked_at IS NULL', ['id' => $deviceId]);
        Logger::audit('fleet.device.revoke', 'driver', (string) $dev['driver_id'], null, ['device' => $deviceId, 'by' => $by]);
    }

    private static function revokeDriverDevices(int $driverId): void
    {
        foreach (Database::fetchAll('SELECT * FROM fleet_devices WHERE driver_id = :d AND revoked_at IS NULL', ['d' => $driverId]) as $dev) {
            self::endTrip($dev, 'revoked');
        }
        Database::update('fleet_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'driver_id = :d AND revoked_at IS NULL', ['d' => $driverId]);
    }

    /** "Mozilla/5.0 (Linux; Android 13; SM-A145F) … Chrome/129" → "Android 13 · SM-A145F · Chrome". */
    public static function deviceLabel(string $ua): string
    {
        if ($ua === '') {
            return 'Unknown phone';
        }
        $parts = [];
        if (preg_match('/Android ([\d.]+);\s*([^;)]+)/', $ua, $m) === 1) {
            $parts[] = 'Android ' . $m[1];
            if (trim($m[2]) !== 'K') {
                $parts[] = trim($m[2]);
            }
        } elseif (preg_match('/(iPhone|iPad).*OS ([\d_]+)/', $ua, $m) === 1) {
            $parts[] = $m[1] . ' iOS ' . str_replace('_', '.', $m[2]);
        } elseif (str_contains($ua, 'Windows')) {
            $parts[] = 'Windows';
        } elseif (str_contains($ua, 'Mac OS')) {
            $parts[] = 'Mac';
        } elseif (str_contains($ua, 'Linux')) {
            $parts[] = 'Linux';
        }
        foreach (['Traccar' => 'Traccar Client', 'SamsungBrowser' => 'Samsung Internet', 'Firefox' => 'Firefox', 'Edg/' => 'Edge', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $parts[] = $name;
                break;
            }
        }
        return substr($parts !== [] ? implode(' · ', $parts) : $ua, 0, 160);
    }

    /* =================================================================
     *  Trips
     * ================================================================= */

    /**
     * Buses the driver can pick on the phone. `suggested` = today's
     * schedule puts this driver on it (else the bus this phone drove last);
     * `busy` = another phone is already driving it.
     *
     * @return list<array<string,mixed>>
     */
    public static function busesFor(array $device): array
    {
        $driverId = (int) $device['driver_id'];
        $sched = Database::fetch(
            "SELECT bus_id FROM schedules
              WHERE driver_id = :d AND bus_id IS NOT NULL AND status <> 'cancelled' AND travel_date IN (:t, :y)
              ORDER BY travel_date DESC LIMIT 1",
            ['d' => $driverId, 't' => date('Y-m-d'), 'y' => date('Y-m-d', time() - 86400)]
        );
        $suggest = $sched !== null ? (int) $sched['bus_id'] : (int) ($device['bus_id'] ?? 0);

        $out = [];
        foreach (Database::fetchAll(
            "SELECT b.id, b.bus_number, b.bus_name, t.driver_id AS busy_driver, d.full_name AS busy_name
               FROM buses b
               LEFT JOIN fleet_trips t ON t.bus_id = b.id AND t.status = 'running'
               LEFT JOIN drivers d ON d.id = t.driver_id
              WHERE b.is_active = 1 ORDER BY b.id"
        ) as $b) {
            $busy = $b['busy_driver'] !== null && (int) $b['busy_driver'] !== $driverId;
            $out[(int) $b['id']] = [
                'id' => (int) $b['id'], 'number' => (string) $b['bus_number'], 'name' => (string) $b['bus_name'],
                'suggested' => (int) $b['id'] === $suggest,
                'busy' => $busy ? (string) ($b['busy_name'] ?? 'another driver') : null,
            ];
        }
        return array_values($out);
    }

    /**
     * Start driving $busId from this phone. Any other running trip on the
     * same bus, or of the same driver, is closed first — one bus, one dot.
     *
     * @return array<string,mixed> the new trip row
     */
    public static function startTrip(array $device, int $busId): array
    {
        $bus = Database::fetch('SELECT id, bus_number, bus_name FROM buses WHERE id = :id AND is_active = 1', ['id' => $busId]);
        if ($bus === null) {
            throw new RuntimeException('That bus is not in the active fleet.');
        }
        $driverId = (int) $device['driver_id'];
        $now = date('Y-m-d H:i:s');

        foreach (Database::fetchAll(
            "SELECT id, bus_id, driver_id, device_id FROM fleet_trips WHERE status = 'running' AND (bus_id = :b OR driver_id = :d OR device_id = :v)",
            ['b' => $busId, 'd' => $driverId, 'v' => (int) $device['id']]
        ) as $old) {
            Database::update('fleet_trips', ['status' => 'ended', 'ended_at' => $now, 'end_reason' => (int) $old['bus_id'] === $busId && (int) $old['driver_id'] !== $driverId ? 'taken_over' : 'restarted'],
                'id = :id', ['id' => (int) $old['id']]);
        }

        // Which timetable run is this? This driver's own schedule first, then
        // the bus's; today before yesterday (an overnight run started yesterday).
        $sched = Database::fetch(
            "SELECT id, route_id FROM schedules
              WHERE (driver_id = :d OR bus_id = :b) AND status IN ('scheduled','departed') AND travel_date IN (:t, :y)
              ORDER BY (driver_id = :d2) DESC, (bus_id = :b2) DESC, travel_date DESC LIMIT 1",
            ['d' => $driverId, 'b' => $busId, 't' => date('Y-m-d'), 'y' => date('Y-m-d', time() - 86400), 'd2' => $driverId, 'b2' => $busId]
        );
        $routeId = $sched !== null ? (int) $sched['route_id'] : (int) Database::scalar(
            'SELECT id FROM routes WHERE bus_id = :b AND is_active = 1 ORDER BY sort_order, id LIMIT 1', ['b' => $busId], 0
        );

        $id = Database::insert('fleet_trips', [
            'bus_id' => $busId, 'driver_id' => $driverId, 'device_id' => (int) $device['id'],
            'schedule_id' => $sched !== null ? (int) $sched['id'] : null,
            'route_id' => $routeId > 0 ? $routeId : null,
            'status' => 'running', 'started_at' => $now,
        ]);
        Database::update('fleet_devices', ['bus_id' => $busId], 'id = :id', ['id' => (int) $device['id']]);
        Logger::audit('fleet.trip.start', 'bus', (string) $busId, null, ['trip' => $id, 'driver' => $driverId]);

        return (array) Database::fetch('SELECT * FROM fleet_trips WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function currentTrip(array $device): ?array
    {
        return Database::fetch(
            "SELECT t.*, b.bus_number, b.bus_name FROM fleet_trips t JOIN buses b ON b.id = t.bus_id
              WHERE t.device_id = :v AND t.status = 'running' ORDER BY t.id DESC LIMIT 1",
            ['v' => (int) $device['id']]
        );
    }

    public static function endTrip(array $device, string $reason = 'driver'): bool
    {
        return Database::update('fleet_trips', [
            'status' => 'ended', 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => substr($reason, 0, 40),
        ], "device_id = :v AND status = 'running'", ['v' => (int) $device['id']]) > 0;
    }

    /**
     * Take a batch of GPS fixes from the phone.
     *
     * Each point: lat, lng, and optionally ts (ms since epoch, phone clock),
     * spd (m/s, as the Geolocation API reports), hdg (degrees), acc (m).
     * Bad points are dropped, not fatal: a phone that just regained signal
     * must never lose a whole batch to one nonsense fix.
     *
     * @param list<array<string,mixed>> $points
     * @return array{accepted:int, dropped:int, trip_id:int, distance_km:float}
     */
    public static function ping(array $device, array $points, ?int $battery = null, ?int $nowTs = null): array
    {
        $trip = self::currentTrip($device);
        if ($trip === null) {
            throw new DomainException('no_trip');
        }
        $nowTs   = $nowTs ?? time();
        $lastFix = $trip['last_fix_at'] !== null ? (int) strtotime((string) $trip['last_fix_at']) * 1000 : 0;

        $clean = [];
        foreach (array_slice($points, -self::MAX_BATCH) as $p) {
            $pt = self::cleanPoint($p, $nowTs);
            if ($pt !== null && $pt['ts'] > $lastFix) {
                $clean[(string) $pt['ts']] = $pt;      // a duplicate timestamp keeps the later copy
            }
        }
        ksort($clean, SORT_NUMERIC);
        $clean   = array_values($clean);
        $dropped = count($points) - count($clean);

        if ($clean === []) {
            // last_ping_at is "when the server last learned where the bus is" — the
            // whole live / weak / offline colour hangs on it — so a batch with no
            // new fix leaves it alone and only the battery is noted.
            if ($battery !== null) {
                Database::update('fleet_trips', ['last_battery' => max(0, min(100, $battery))], 'id = :id', ['id' => (int) $trip['id']]);
            }
            return ['accepted' => 0, 'dropped' => $dropped, 'trip_id' => (int) $trip['id'], 'distance_km' => (float) $trip['distance_km']];
        }

        $prev = $trip['last_lat'] !== null
            ? ['lat' => (float) $trip['last_lat'], 'lng' => (float) $trip['last_lng'], 'ts' => $lastFix, 'acc' => (float) ($trip['last_accuracy_m'] ?? 0)]
            : null;
        $lastTrail = $trip['last_trail_at'] !== null ? (int) strtotime((string) $trip['last_trail_at']) : 0;
        $addKm = 0.0;
        $trail = [];
        foreach ($clean as $i => $pt) {
            if ($prev !== null) {
                $km  = self::haversineKm($prev['lat'], $prev['lng'], $pt['lat'], $pt['lng']);
                $hrs = max(0.0, ($pt['ts'] - $prev['ts']) / 3600000);
                $kmh = $hrs > 0 ? $km / $hrs : null;
                if ($pt['kmh'] === null && $kmh !== null && $pt['acc'] <= self::GOOD_ACCURACY_M && $kmh <= self::MAX_SPEED_KMH) {
                    $clean[$i]['kmh'] = $pt['kmh'] = round($kmh, 1);
                }
                if ($pt['acc'] <= self::GOOD_ACCURACY_M && $prev['acc'] <= self::GOOD_ACCURACY_M && $kmh !== null && $kmh <= self::MAX_SPEED_KMH) {
                    $addKm += $km;
                }
            }
            $sec = intdiv($pt['ts'], 1000);
            if ($sec - $lastTrail >= self::TRAIL_SECONDS) {
                $trail[]   = $pt;
                $lastTrail = $sec;
            }
            $prev = $pt;
        }
        $last = end($clean);

        Database::transaction(static function () use ($trip, $trail, $last, $addKm, $clean, $battery, $nowTs, $lastTrail): void {
            if ($trail !== []) {
                $rows = [];
                $args = [];
                foreach ($trail as $n => $pt) {
                    $rows[] = "(:t$n, :la$n, :ln$n, :s$n, :h$n, :a$n, :r$n)";
                    $args += ["t$n" => (int) $trip['id'], "la$n" => $pt['lat'], "ln$n" => $pt['lng'], "s$n" => $pt['kmh'],
                              "h$n" => $pt['hdg'], "a$n" => $pt['acc'], "r$n" => date('Y-m-d H:i:s', intdiv($pt['ts'], 1000))];
                }
                Database::query('INSERT INTO fleet_points (trip_id, lat, lng, speed_kmh, heading, accuracy_m, recorded_at) VALUES ' . implode(',', $rows), $args);
            }
            Database::query(
                'UPDATE fleet_trips SET last_lat = :la, last_lng = :ln, last_speed_kmh = :s, last_heading = :h, last_accuracy_m = :a,
                        last_fix_at = :f, last_ping_at = :p, last_trail_at = :lt, distance_km = distance_km + :km, points = points + :n'
                . ($battery !== null ? ', last_battery = :bat' : '') . ' WHERE id = :id',
                ['la' => $last['lat'], 'ln' => $last['lng'], 's' => $last['kmh'], 'h' => $last['hdg'], 'a' => $last['acc'],
                 'f' => date('Y-m-d H:i:s', intdiv($last['ts'], 1000)), 'p' => date('Y-m-d H:i:s', $nowTs),
                 'lt' => $lastTrail > 0 ? date('Y-m-d H:i:s', $lastTrail) : null, 'km' => round($addKm, 3), 'n' => count($clean), 'id' => (int) $trip['id']]
                + ($battery !== null ? ['bat' => max(0, min(100, $battery))] : [])
            );
        });

        $prevPing = $trip['last_ping_at'] !== null ? (int) strtotime((string) $trip['last_ping_at']) : 0;
        if (intdiv($nowTs, self::MIRROR_SECONDS) !== intdiv($prevPing, self::MIRROR_SECONDS)) {
            self::mirrorLegacy($trip, $last, (string) ($device['driver_name'] ?? 'driver'), $nowTs);
        }

        return ['accepted' => count($clean), 'dropped' => $dropped, 'trip_id' => (int) $trip['id'],
                'distance_km' => round((float) $trip['distance_km'] + $addKm, 2)];
    }

    /**
     * One raw point → a clean one, or null when it cannot be a real fix.
     *
     * @return array{lat:float,lng:float,ts:int,kmh:?float,hdg:?int,acc:float}|null
     */
    public static function cleanPoint(mixed $p, int $nowTs): ?array
    {
        if (!is_array($p) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
            return null;
        }
        $lat = (float) $p['lat'];
        $lng = (float) $p['lng'];
        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
            return null;
        }
        $ts = is_numeric($p['ts'] ?? null) ? (int) $p['ts'] : $nowTs * 1000;
        // A phone clock a little ahead is common; a fix from tomorrow or last week is not a live position.
        if ($ts > ($nowTs + 120) * 1000 || $ts < ($nowTs - 86400) * 1000) {
            return null;
        }
        $acc = is_numeric($p['acc'] ?? null) ? max(0.0, (float) $p['acc']) : 50.0;
        if ($acc > 5000) {
            return null;    // a cell-tower guess kilometres wide would make the dot jump between towns
        }
        $kmh = null;
        if (is_numeric($p['spd'] ?? null) && (float) $p['spd'] >= 0) {
            $kmh = round(min((float) $p['spd'] * 3.6, 250.0), 1);
        }
        $hdg = is_numeric($p['hdg'] ?? null) && (float) $p['hdg'] >= 0 ? ((int) round((float) $p['hdg'])) % 360 : null;

        return ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'ts' => $ts, 'kmh' => $kmh, 'hdg' => $hdg, 'acc' => round($acc, 1)];
    }

    /**
     * Keep the old single-slot `livebus` key fed while exactly one bus is
     * live — the passenger Trip Companion, api/eta.php and the bus-is-near
     * alerts read it. With two buses live it cannot say which coach it
     * means, so it is left alone rather than flipping between them.
     */
    private static function mirrorLegacy(array $trip, array $pt, string $driverName, int $nowTs): void
    {
        try {
            $others = (int) Database::scalar(
                "SELECT COUNT(*) FROM fleet_trips WHERE status = 'running' AND id <> :id AND last_ping_at >= :since",
                ['id' => (int) $trip['id'], 'since' => date('Y-m-d H:i:s', $nowTs - 60)], 0
            );
            if ($others > 0) {
                return;
            }
            $stop = $trip['route_id'] !== null ? self::stopInfo((int) $trip['route_id'], $pt['lat'], $pt['lng']) : null;
            $value = json_encode([
                'gps' => true, 'lat' => $pt['lat'], 'lng' => $pt['lng'], 'bearing' => $pt['hdg'],
                'spd' => $pt['kmh'] !== null ? round($pt['kmh'] / 3.6, 2) : null, 'acc' => (int) round($pt['acc']),
                'stop' => $stop['near'] ?? '', 'next' => $stop['next'] ?? '', 'at' => $nowTs * 1000,
                'by' => $driverName, 'bus' => (string) ($trip['bus_number'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            Database::query(
                'INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :by)
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)',
                ['s' => 'global', 'k' => 'livebus', 'v' => (string) $value, 'by' => 'live-fleet']
            );
        } catch (Throwable $e) {
            Logger::warning('fleet mirror failed', ['err' => $e->getMessage()]);
        }
    }

    /* =================================================================
     *  Reading the fleet
     * ================================================================= */

    /** live | weak | offline | waiting (trip started, no fix yet) | parked (trip ended) | idle (never tracked). */
    public static function statusOf(?string $tripStatus, ?int $ageSeconds): string
    {
        if ($tripStatus === null) {
            return 'idle';
        }
        if ($tripStatus !== 'running') {
            return 'parked';
        }
        if ($ageSeconds === null) {
            return 'waiting';
        }
        return $ageSeconds <= self::LIVE_SECONDS ? 'live' : ($ageSeconds <= self::WEAK_SECONDS ? 'weak' : 'offline');
    }

    /**
     * Every active bus with its running trip — or, when parked, where its
     * last trip ended. One query; the 1-second refresh depends on it.
     *
     * @return array{now:int, buses:list<array<string,mixed>>, counts:array<string,int>}
     */
    public static function snapshot(bool $withCameras = true, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        $rows = Database::fetchAll(
            "SELECT b.id AS bus_id, b.bus_number, b.bus_name,
                    t.id AS trip_id, t.status AS trip_status, t.started_at, t.ended_at, t.route_id,
                    t.last_lat, t.last_lng, t.last_speed_kmh, t.last_heading, t.last_accuracy_m, t.last_battery,
                    t.last_fix_at, t.last_ping_at, t.distance_km,
                    d.full_name AS driver_name, d.phone AS driver_phone,
                    r.route_code, r.from_city, r.to_city
               FROM buses b
               LEFT JOIN fleet_trips t ON t.id = (
                    SELECT t2.id FROM fleet_trips t2 WHERE t2.bus_id = b.id
                     ORDER BY (t2.status = 'running') DESC, t2.id DESC LIMIT 1)
               LEFT JOIN drivers d ON d.id = t.driver_id
               LEFT JOIN routes r ON r.id = t.route_id
              WHERE b.is_active = 1
              ORDER BY b.id"
        );
        $cams   = $withCameras ? self::camerasByBus(true) : [];
        $counts = ['live' => 0, 'weak' => 0, 'offline' => 0, 'waiting' => 0, 'parked' => 0, 'idle' => 0];
        $buses  = [];
        foreach ($rows as $r) {
            $ping   = $r['last_ping_at'] !== null ? (int) strtotime((string) $r['last_ping_at']) : null;
            $age    = $ping !== null ? max(0, $nowTs - $ping) : null;
            $status = self::statusOf($r['trip_status'] !== null ? (string) $r['trip_status'] : null, $age);
            $counts[$status]++;
            $hasPos = $r['last_lat'] !== null;
            $stop   = $hasPos && $r['route_id'] !== null && $status !== 'parked'
                ? self::stopInfo((int) $r['route_id'], (float) $r['last_lat'], (float) $r['last_lng']) : null;
            $buses[] = [
                'id'      => (int) $r['bus_id'],
                'number'  => (string) $r['bus_number'],
                'name'    => (string) $r['bus_name'],
                'status'  => $status,
                'age_s'   => $age,
                'trip'    => $r['trip_id'] !== null ? [
                    'id' => (int) $r['trip_id'],
                    'started' => (int) strtotime((string) $r['started_at']) * 1000,
                    'ended' => $r['ended_at'] !== null ? (int) strtotime((string) $r['ended_at']) * 1000 : null,
                    'km' => round((float) $r['distance_km'], 1),
                ] : null,
                'driver'  => $r['driver_name'] !== null ? ['name' => (string) $r['driver_name'], 'phone' => (string) ($r['driver_phone'] ?? '')] : null,
                'route'   => $r['route_code'] !== null ? ['code' => (string) $r['route_code'], 'label' => $r['from_city'] . ' → ' . $r['to_city']] : null,
                'pos'     => $hasPos ? [
                    'lat' => (float) $r['last_lat'], 'lng' => (float) $r['last_lng'],
                    'kmh' => $r['last_speed_kmh'] !== null ? (float) $r['last_speed_kmh'] : null,
                    'hdg' => $r['last_heading'] !== null ? (int) $r['last_heading'] : null,
                    'acc' => $r['last_accuracy_m'] !== null ? (float) $r['last_accuracy_m'] : null,
                    'fix' => $r['last_fix_at'] !== null ? (int) strtotime((string) $r['last_fix_at']) * 1000 : null,
                ] : null,
                'battery' => $r['last_battery'] !== null && $status !== 'parked' ? (int) $r['last_battery'] : null,
                'stop'    => $stop,
                'cameras' => $cams[(int) $r['bus_id']] ?? [],
            ];
        }
        // Moving buses first, then the rest in fleet order.
        $rank = ['live' => 0, 'weak' => 1, 'waiting' => 2, 'offline' => 3, 'parked' => 4, 'idle' => 5];
        usort($buses, static fn(array $a, array $b): int => [$rank[$a['status']], $a['id']] <=> [$rank[$b['status']], $b['id']]);

        return ['now' => $nowTs * 1000, 'buses' => $buses, 'counts' => $counts];
    }

    /**
     * The recent trail of a trip as [lng, lat] pairs, oldest first.
     *
     * @return list<array{0:float,1:float}>
     */
    public static function trail(int $tripId, int $limit = 720): array
    {
        $rows = Database::fetchAll(
            'SELECT lat, lng FROM fleet_points WHERE trip_id = :t ORDER BY recorded_at DESC, id DESC LIMIT ' . max(1, min(5000, $limit)),
            ['t' => $tripId]
        );
        return array_reverse(array_map(static fn(array $r): array => [(float) $r['lng'], (float) $r['lat']], $rows));
    }

    /** @var array<int, list<array{name:string,lat:float,lng:float}>> */
    private static array $stopCache = [];

    /**
     * Nearest stop of the route and the one the bus is heading to.
     *
     * The route's stops (boarding then drop, in timetable order) form a
     * line. The bus is "at" a stop within 1 km of it; otherwise it is
     * between the nearest stop and a neighbour, and the next stop is the
     * one ahead — decided by which neighbour is closer to the bus than to
     * the nearest stop.
     *
     * @return array{near:string, near_km:float, at:bool, next:string, next_km:?float}|null
     */
    public static function stopInfo(int $routeId, float $lat, float $lng): ?array
    {
        if (!isset(self::$stopCache[$routeId])) {
            self::$stopCache[$routeId] = array_map(static fn(array $s): array => [
                'name' => (string) $s['stop_name'], 'lat' => (float) $s['latitude'], 'lng' => (float) $s['longitude'],
            ], Database::fetchAll(
                "SELECT stop_name, latitude, longitude FROM route_stops
                  WHERE route_id = :r AND latitude IS NOT NULL AND longitude IS NOT NULL
                  ORDER BY stop_type = 'drop', sort_order, id",
                ['r' => $routeId]
            ));
        }
        $stops = self::$stopCache[$routeId];
        if ($stops === []) {
            return null;
        }
        $best = 0;
        $bestKm = INF;
        foreach ($stops as $i => $s) {
            $km = self::haversineKm($lat, $lng, $s['lat'], $s['lng']);
            if ($km < $bestKm) {
                $best = $i;
                $bestKm = $km;
            }
        }
        $at = $bestKm <= 1.0;
        $nextIdx = null;
        if ($at) {
            $nextIdx = $best + 1;
        } elseif (isset($stops[$best + 1])) {
            $n = $stops[$best + 1];
            // Past the nearest stop when the following one is closer to the bus than the leg is long.
            $nextIdx = self::haversineKm($lat, $lng, $n['lat'], $n['lng']) < self::haversineKm($stops[$best]['lat'], $stops[$best]['lng'], $n['lat'], $n['lng'])
                ? $best + 1 : $best;
        } else {
            $nextIdx = $best;
        }
        $next = $stops[$nextIdx] ?? null;

        return [
            'near' => $stops[$best]['name'], 'near_km' => round($bestKm, 1), 'at' => $at,
            'next' => $next['name'] ?? '', 'next_km' => $next !== null ? round(self::haversineKm($lat, $lng, $next['lat'], $next['lng']), 1) : null,
        ];
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /* =================================================================
     *  Housekeeping (cron/fleet-cleanup.php)
     * ================================================================= */

    /** Close trips whose phone has said nothing for $hours (a dead battery, a forgotten tap). */
    public static function endSilent(int $hours = 6, ?int $nowTs = null): int
    {
        $cut = date('Y-m-d H:i:s', ($nowTs ?? time()) - $hours * 3600);
        return Database::update('fleet_trips', ['status' => 'ended', 'ended_at' => date('Y-m-d H:i:s', $nowTs ?? time()), 'end_reason' => 'silent'],
            "status = 'running' AND COALESCE(last_ping_at, started_at) < :cut", ['cut' => $cut]);
    }

    public static function purgePoints(int $days, ?int $nowTs = null): int
    {
        return Database::delete('fleet_points', 'recorded_at < :cut', ['cut' => date('Y-m-d H:i:s', ($nowTs ?? time()) - max(1, $days) * 86400)]);
    }

    /* =================================================================
     *  CCTV cameras
     * ================================================================= */

    /**
     * A camera URL the browser can load, or an exception saying why not.
     * Only http(s); no user:password@ (browsers refuse to send it from a
     * page, and it would show the DVR password to every viewer).
     */
    public static function cleanCameraUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 500 || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException('Camera link must start with https:// (or http://).');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Remove the user:password@ part — use the stream gateway\'s token link instead (see docs/CCTV.md).');
        }
        if (preg_match('/[\s"\'<>]/', $url) === 1) {
            throw new RuntimeException('Camera link contains spaces or quotes.');
        }
        return $url;
    }

    /** scheme://host[:port] of a URL — what the page's CSP must allow. */
    public static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    }

    /** @return array<int, list<array{id:int,name:string,kind:string,url:string}>> */
    public static function camerasByBus(bool $activeOnly = true): array
    {
        $out = [];
        foreach (Database::fetchAll(
            'SELECT id, bus_id, name, kind, url, is_active FROM fleet_cameras'
            . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY bus_id, sort_order, id'
        ) as $c) {
            $out[(int) $c['bus_id']][] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'kind' => (string) $c['kind'],
                'url' => (string) $c['url']] + ($activeOnly ? [] : ['active' => (int) $c['is_active'] === 1]);
        }
        return $out;
    }

    public static function saveCamera(int $id, int $busId, string $name, string $kind, string $url, bool $active, int $by = 0): int
    {
        if (!array_key_exists($kind, self::CAMERA_KINDS)) {
            throw new RuntimeException('Pick the camera type.');
        }
        if (!Database::exists('SELECT 1 FROM buses WHERE id = :id', ['id' => $busId])) {
            throw new RuntimeException('Pick the bus.');
        }
        $name = Security::clean($name, 60);
        $data = ['bus_id' => $busId, 'name' => $name !== '' ? $name : 'Camera', 'kind' => $kind,
                 'url' => self::cleanCameraUrl($url), 'is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($id > 0) {
            Database::update('fleet_cameras', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['sort_order'] = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM fleet_cameras WHERE bus_id = :b', ['b' => $busId], 1);
            $id = Database::insert('fleet_cameras', $data + ['created_at' => date('Y-m-d H:i:s')]);
        }
        Logger::audit('fleet.camera.save', 'bus', (string) $busId, null, ['camera' => $id, 'kind' => $kind, 'by' => $by]);
        return $id;
    }

    public static function deleteCamera(int $id, int $by = 0): void
    {
        Database::delete('fleet_cameras', 'id = :id', ['id' => $id]);
        Logger::audit('fleet.camera.delete', 'camera', (string) $id, null, ['by' => $by]);
    }

    /**
     * The page CSP from bootstrap, widened just enough to play this
     * fleet's camera feeds (frames, video, the HLS playlist fetches).
     * Pages without cameras keep the stock policy untouched.
     */
    public static function allowCameraOrigins(): void
    {
        $origins = [];
        foreach (Database::fetchAll('SELECT DISTINCT url FROM fleet_cameras WHERE is_active = 1') as $r) {
            $o = self::originOf((string) $r['url']);
            if ($o !== '') {
                $origins[$o] = true;
            }
        }
        $csp = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Security-Policy:') === 0) {
                $csp = trim(substr($h, strlen('Content-Security-Policy:')));
            }
        }
        if ($csp === '') {
            return;
        }
        $extra = implode(' ', array_keys($origins));
        $dirs = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $d) {
            $name = strtolower((string) strtok($d, ' '));
            $dirs[$name] = $d;
        }
        // hls.js plays through a blob: MediaSource and fetches the playlist from the camera host.
        $dirs['media-src']   = trim('media-src \'self\' blob: ' . $extra);
        $dirs['frame-src']   = trim('frame-src \'self\' ' . $extra);
        if ($extra !== '') {
            $dirs['connect-src'] = ($dirs['connect-src'] ?? "connect-src 'self'") . ' ' . $extra;
            $dirs['img-src']     = ($dirs['img-src'] ?? "img-src 'self'") . ' ' . $extra;
        }
        header('Content-Security-Policy: ' . implode('; ', $dirs), true);
    }

    /**
     * /assets/livefleet/<file>?v=<content hash>. sw.js serves /assets/*
     * cache-first by URL, so the stamp must change whenever the file does;
     * hashing the content does that without anyone remembering to bump it.
     */
    public static function asset(string $file): string
    {
        $path = ROOT_PATH . '/assets/livefleet/' . basename($file);
        return '/assets/livefleet/' . rawurlencode(basename($file)) . '?v=' . (is_file($path) ? substr((string) md5_file($path), 0, 10) : '0');
    }

    /* =================================================================
     *  TV wall key — lets a TV in the office open live-tv.php without
     *  a staff login. Empty = switched off (staff login required).
     * ================================================================= */

    public static function tvKey(): string
    {
        return Settings::getString('fleet_tv_key', '');
    }

    public static function rotateTvKey(int $by = 0): string
    {
        $key = bin2hex(random_bytes(16));
        Settings::set('fleet_tv_key', $key, 'string', 'liveops', false);
        Logger::audit('fleet.tvkey.rotate', 'settings', 'fleet_tv_key', null, ['by' => $by]);
        return $key;
    }

    public static function disableTvKey(int $by = 0): void
    {
        Settings::set('fleet_tv_key', '', 'string', 'liveops', false);
        Logger::audit('fleet.tvkey.disable', 'settings', 'fleet_tv_key', null, ['by' => $by]);
    }

    public static function tvKeyMatches(string $given): bool
    {
        $key = self::tvKey();
        return $key !== '' && $given !== '' && hash_equals($key, $given);
    }
}
