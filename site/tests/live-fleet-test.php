<?php
/**
 * =====================================================================
 *  live-fleet-test.php — the Live Fleet engine (includes/livefleet.php).
 *
 *  Drives the engine directly against the TEST database with its own
 *  bus, two drivers and a synthetic clock, and pins:
 *
 *    - Driver ID + PIN login: wrong PIN, lock-out after 5, mobile number
 *      as the ID, inactive driver, revoking a phone, a new PIN signing
 *      every phone out
 *    - trips: one bus = one running trip (a second driver takes over),
 *      a phone restarting closes its old trip, no fix without a trip
 *    - pings: nonsense fixes dropped (not fatal), duplicates / old fixes
 *      ignored, trail thinned to one point per 5 s, distance only from
 *      believable movement, speed derived when the phone gives none
 *    - status: live → weak → offline by age, parked after End, idle
 *    - next-stop logic on a real route, CCTV link rules, TV key, cleanup
 *    - the legacy `livebus` key is fed while one bus is live
 *
 *  Everything it creates is deleted again (buses / drivers cascade).
 *
 *    php tests/live-fleet-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : get_class($e); }
}

if (!Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fleet_trips'")) {
    check('fleet_* tables exist (apply database/upgrade-2026-10-live-fleet.sql)', false);
    exit(1);
}

const LF_BUS = 'LF-TEST-0001';
const LF_BUS2 = 'LF-TEST-0002';
$cleanup = static function (): void {
    Database::run("DELETE FROM buses WHERE bus_number IN ('" . LF_BUS . "','" . LF_BUS2 . "')");
    Database::run("DELETE FROM drivers WHERE full_name LIKE 'LF Test %'");
};
$cleanup();
$legacy = Database::fetch("SELECT kvalue, updated_by FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
$tvBefore = LiveFleet::tvKey();

$busId  = Database::insert('buses', ['bus_number' => LF_BUS, 'bus_name' => 'LF Test Coach', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 1]);
$bus2   = Database::insert('buses', ['bus_number' => LF_BUS2, 'bus_name' => 'LF Test Parked', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 0]);
$drvA   = Database::insert('drivers', ['full_name' => 'LF Test Asha', 'phone' => '919800000101', 'role' => 'driver', 'is_active' => 1]);
$drvB   = Database::insert('drivers', ['full_name' => 'LF Test Bikram', 'phone' => '919800000102', 'role' => 'driver', 'is_active' => 1]);
$codeA  = 'LFT' . $drvA;
$codeB  = 'LFT' . $drvB;

try {
    echo "-- driver login --\n";
    check('a 1-letter Driver ID is refused', str_contains(throws(fn() => LiveFleet::setLogin($drvA, 'X', '1234')), 'at least 2'));
    check('a PIN with letters is refused', str_contains(throws(fn() => LiveFleet::setLogin($drvA, $codeA, '12a4')), 'digits'));
    check('a 3-digit PIN is refused', str_contains(throws(fn() => LiveFleet::setLogin($drvA, $codeA, '123')), 'digits'));
    check('the first login needs a PIN', str_contains(throws(fn() => LiveFleet::setLogin($drvA, $codeA, '')), 'PIN'));
    LiveFleet::setLogin($drvA, strtolower($codeA), '4821');
    check('Driver ID is stored upper-case', Database::scalar('SELECT login_code FROM fleet_driver_logins WHERE driver_id = :d', ['d' => $drvA]) === $codeA);
    check('the PIN is stored hashed, never plain', !str_contains((string) Database::scalar('SELECT pin_hash FROM fleet_driver_logins WHERE driver_id = :d', ['d' => $drvA]), '4821'));
    check('one Driver ID cannot belong to two drivers', str_contains(throws(fn() => LiveFleet::setLogin($drvB, $codeA, '1111')), 'already belongs'));
    LiveFleet::setLogin($drvB, $codeB, '7777');

    check('wrong PIN → refused', LiveFleet::login($codeA, '0000')['ok'] === false);
    check('unknown Driver ID → the same refusal text', LiveFleet::login('NOPE999', '4821')['error'] === LiveFleet::login($codeA, '0000')['error']);
    $r = LiveFleet::login(strtolower($codeA), '4821', 'Mozilla/5.0 (Linux; Android 13; SM-A145F) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36', '10.0.0.1');
    check('right PIN (any case) → a 64-hex token', $r['ok'] === true && preg_match('/^[a-f0-9]{64}$/', (string) $r['token']) === 1);
    $devA = LiveFleet::device((string) $r['token']);
    check('the token finds the phone and its driver', $devA !== null && (int) $devA['driver_id'] === $drvA);
    check('the phone gets a readable label', (string) $devA['label'] === 'Android 13 · SM-A145F · Chrome', (string) $devA['label']);
    check('only the token hash is stored', !Database::exists('SELECT 1 FROM fleet_devices WHERE token_hash = :t', ['t' => $r['token']]));
    check('a malformed token finds nothing', LiveFleet::device('abc') === null && LiveFleet::device(str_repeat('z', 64)) === null);
    check('the mobile number works as the Driver ID', LiveFleet::login('919800000101', '4821')['ok'] === true);

    for ($i = 0; $i < LiveFleet::MAX_FAILS; $i++) { LiveFleet::login($codeB, '0000'); }
    $locked = LiveFleet::login($codeB, '7777');
    check('after ' . LiveFleet::MAX_FAILS . ' wrong PINs even the right one waits', $locked['ok'] === false && str_contains((string) $locked['error'], 'Too many'));
    LiveFleet::setLogin($drvB, $codeB, '7778');
    check('the office setting a new PIN lifts the lock', LiveFleet::login($codeB, '7778')['ok'] === true);

    Database::update('drivers', ['is_active' => 0], 'id = :id', ['id' => $drvA]);
    check('a switched-off driver cannot log in', LiveFleet::login($codeA, '4821')['ok'] === false);
    check('...and their phone stops working', LiveFleet::device((string) $r['token']) === null);
    Database::update('drivers', ['is_active' => 1], 'id = :id', ['id' => $drvA]);
    check('switched back on, the phone works again', LiveFleet::device((string) $r['token']) !== null);

    $r2 = LiveFleet::login($codeA, '4821');
    LiveFleet::revokeDevice((int) LiveFleet::device((string) $r2['token'])['id']);
    check('a revoked phone is refused', LiveFleet::device((string) $r2['token']) === null);
    check('...other phones of the driver keep working', LiveFleet::device((string) $r['token']) !== null);

    echo "-- buses and trips --\n";
    $buses = LiveFleet::busesFor($devA);
    check('the phone is offered the active test bus', in_array($busId, array_column($buses, 'id'), true));
    check('an inactive bus is not offered', !in_array($bus2, array_column($buses, 'id'), true));
    check('starting an inactive bus is refused', str_contains(throws(fn() => LiveFleet::startTrip($devA, $bus2)), 'active fleet'));
    check('a fix with no trip is refused as no_trip', throws(fn() => LiveFleet::ping($devA, [['lat' => 22.3, 'lng' => 73.18]])) === 'no_trip');

    $tripA = LiveFleet::startTrip($devA, $busId);
    check('Start creates a running trip on that bus', $tripA['status'] === 'running' && (int) $tripA['bus_id'] === $busId);
    check('the phone remembers the bus for next time', (int) Database::scalar('SELECT bus_id FROM fleet_devices WHERE id = :id', ['id' => (int) $devA['id']]) === $busId);
    $devA = LiveFleet::device((string) $r['token']);
    check('next login suggests that bus', (bool) (array_values(array_filter(LiveFleet::busesFor($devA), fn($b) => $b['id'] === $busId))[0]['suggested'] ?? false));

    $devB = LiveFleet::device((string) LiveFleet::login($codeB, '7778')['token']);
    check('another driver sees the bus as busy', (string) (array_values(array_filter(LiveFleet::busesFor($devB), fn($b) => $b['id'] === $busId))[0]['busy'] ?? '') === 'LF Test Asha');
    $tripB = LiveFleet::startTrip($devB, $busId);
    check('a second driver taking the bus ends the first trip', Database::scalar('SELECT end_reason FROM fleet_trips WHERE id = :id', ['id' => (int) $tripA['id']]) === 'taken_over');
    check('one bus never has two running trips', (int) Database::scalar("SELECT COUNT(*) FROM fleet_trips WHERE bus_id = :b AND status = 'running'", ['b' => $busId]) === 1);
    check('the first phone now has no trip', LiveFleet::currentTrip($devA) === null);

    echo "-- pings --\n";
    $t0 = time() - 30;
    $pts = [];
    for ($i = 0; $i < 12; $i++) {   // ~11 m north per second ≈ 40 km/h, no speed from the phone
        $pts[] = ['lat' => 22.30 + $i * 0.0001, 'lng' => 73.18, 'ts' => ($t0 + $i) * 1000, 'acc' => 8, 'hdg' => 0];
    }
    $bad = [
        ['lat' => 999, 'lng' => 73.18, 'ts' => ($t0 + 3) * 1000],
        ['lat' => 0, 'lng' => 0, 'ts' => ($t0 + 4) * 1000],
        ['lat' => 'x', 'lng' => 73.18],
        ['lat' => 22.31, 'lng' => 73.18, 'ts' => (time() + 3600) * 1000],
        ['lat' => 22.31, 'lng' => 73.18, 'ts' => ($t0 + 5) * 1000, 'acc' => 9000],
        'not a point',
    ];
    $res = LiveFleet::ping($devB, array_merge($pts, $bad), 64, $t0 + 12);
    check('12 good fixes accepted, 6 nonsense ones dropped', $res['accepted'] === 12 && $res['dropped'] === 6, json_encode($res));
    $trip = Database::fetch('SELECT * FROM fleet_trips WHERE id = :id', ['id' => (int) $tripB['id']]);
    check('the live position is the newest fix', abs((float) $trip['last_lat'] - 22.3011) < 0.00001);
    check('speed is worked out when the phone sends none (~40 km/h)', (float) $trip['last_speed_kmh'] > 35 && (float) $trip['last_speed_kmh'] < 45, (string) $trip['last_speed_kmh']);
    check('battery is kept', (int) $trip['last_battery'] === 64);
    check('distance ≈ 0.12 km (11 steps of ~11 m)', abs((float) $trip['distance_km'] - 0.122) < 0.005, (string) $trip['distance_km']);
    $trail = (int) Database::scalar('SELECT COUNT(*) FROM fleet_points WHERE trip_id = :t', ['t' => (int) $tripB['id']]);
    check('the trail keeps one point per 5 s (3 of 12)', $trail === 3, (string) $trail);

    $again = LiveFleet::ping($devB, $pts, null, $t0 + 13);
    check('the same batch sent twice adds nothing', $again['accepted'] === 0);
    check('an empty batch does not refresh "last heard"', Database::scalar('SELECT last_ping_at FROM fleet_trips WHERE id = :id', ['id' => (int) $tripB['id']]) === $trip['last_ping_at']);

    $jump = LiveFleet::ping($devB, [['lat' => 23.30, 'lng' => 73.18, 'ts' => ($t0 + 14) * 1000, 'acc' => 8]], null, $t0 + 14);
    check('a 110 km GPS jump in 2 s moves the dot but adds no distance', $jump['accepted'] === 1 && abs($jump['distance_km'] - 0.122) < 0.005, (string) $jump['distance_km']);
    $out = LiveFleet::ping($devB, [['lat' => 22.30, 'lng' => 73.18, 'ts' => ($t0 + 1) * 1000]], null, $t0 + 15);
    check('a fix older than the last one is ignored', $out['accepted'] === 0);

    echo "-- status --\n";
    check('0 s → live', LiveFleet::statusOf('running', 0) === 'live');
    check(LiveFleet::LIVE_SECONDS . ' s → live', LiveFleet::statusOf('running', LiveFleet::LIVE_SECONDS) === 'live');
    check((LiveFleet::LIVE_SECONDS + 1) . ' s → weak', LiveFleet::statusOf('running', LiveFleet::LIVE_SECONDS + 1) === 'weak');
    check((LiveFleet::WEAK_SECONDS + 1) . ' s → offline', LiveFleet::statusOf('running', LiveFleet::WEAK_SECONDS + 1) === 'offline');
    check('started, no fix yet → waiting', LiveFleet::statusOf('running', null) === 'waiting');
    check('ended → parked, never tracked → idle', LiveFleet::statusOf('ended', 5) === 'parked' && LiveFleet::statusOf(null, null) === 'idle');

    $find = static function (array $snap) use ($busId): ?array {
        foreach ($snap['buses'] as $b) { if ($b['id'] === $busId) { return $b; } }
        return null;
    };
    $b = $find(LiveFleet::snapshot(true, $t0 + 15));
    check('the snapshot shows the bus live with driver and position', $b !== null && $b['status'] === 'live' && $b['driver']['name'] === 'LF Test Bikram' && $b['pos'] !== null, json_encode($b['status'] ?? null));
    check('...30 s later it is weak', $find(LiveFleet::snapshot(false, $t0 + 14 + 30))['status'] === 'weak');
    check('...5 min later it is offline', $find(LiveFleet::snapshot(false, $t0 + 14 + 300))['status'] === 'offline');
    check('an inactive bus is not on the map', !in_array($bus2, array_column(LiveFleet::snapshot(false)['buses'], 'id'), true));

    echo "-- stops --\n";
    $route = null;
    foreach (Database::fetchAll('SELECT id FROM routes WHERE is_active = 1 ORDER BY id') as $rt) {
        $st = Database::fetchAll("SELECT stop_name, latitude, longitude FROM route_stops WHERE route_id = :r AND latitude IS NOT NULL ORDER BY stop_type = 'drop', sort_order, id", ['r' => (int) $rt['id']]);
        if (count($st) >= 3) { $route = ['id' => (int) $rt['id'], 'stops' => $st]; break; }
    }
    if ($route === null) {
        echo "  \033[33mSKIP\033[0m  needs a route with three stops that have coordinates\n";
    } else {
        [$s0, $s1, $s2] = $route['stops'];
        $at = LiveFleet::stopInfo($route['id'], (float) $s1['latitude'], (float) $s1['longitude']);
        check('standing on a stop → "at" it, next is the following stop', $at['at'] && $at['near'] === $s1['stop_name'] && $at['next'] === $s2['stop_name'], json_encode($at, JSON_UNESCAPED_UNICODE));
        // 70 % of the way from stop 0 to stop 1: nearest is stop 1, and it is still ahead.
        $lat = (float) $s0['latitude'] + 0.7 * ((float) $s1['latitude'] - (float) $s0['latitude']);
        $lng = (float) $s0['longitude'] + 0.7 * ((float) $s1['longitude'] - (float) $s0['longitude']);
        $mid = LiveFleet::stopInfo($route['id'], $lat, $lng);
        check('between two stops → next is the one ahead', !$mid['at'] && $mid['next'] === $s1['stop_name'], json_encode($mid, JSON_UNESCAPED_UNICODE));
        $lat = (float) $s1['latitude'] + 0.3 * ((float) $s2['latitude'] - (float) $s1['latitude']);
        $lng = (float) $s1['longitude'] + 0.3 * ((float) $s2['longitude'] - (float) $s1['longitude']);
        $past = LiveFleet::stopInfo($route['id'], $lat, $lng);
        check('just past a stop → next is the stop after it', $past['next'] === $s2['stop_name'], json_encode($past, JSON_UNESCAPED_UNICODE));
    }

    echo "-- legacy livebus key --\n";
    $far = time() + 86400 * 400;   // a clock where no other trip in this database is recent
    $devA = LiveFleet::device((string) $r['token']);
    LiveFleet::startTrip($devA, $busId);
    LiveFleet::ping($devA, [['lat' => 21.17, 'lng' => 72.83, 'ts' => $far * 1000, 'acc' => 10, 'spd' => 10]], null, $far);
    $kv = Database::fetch("SELECT kvalue, updated_by FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
    $lb = json_decode((string) ($kv['kvalue'] ?? ''), true);
    check('with one bus live the old livebus key is fed', ($kv['updated_by'] ?? '') === 'live-fleet' && abs((float) ($lb['lat'] ?? 0) - 21.17) < 0.0001 && (float) $lb['spd'] === 10.0);

    echo "-- cameras --\n";
    foreach (['javascript:alert(1)', 'ftp://cam/x', 'https://admin:pass@cam.example.com/a.m3u8', 'https://cam.example.com/a b.m3u8', 'https://x.com/"onerror', ''] as $u) {
        check('refused camera link: ' . ($u === '' ? '(empty)' : $u), throws(fn() => LiveFleet::cleanCameraUrl($u)) !== '');
    }
    check('an https HLS link is accepted', LiveFleet::cleanCameraUrl(' https://cam.example.com:8443/bus1/index.m3u8 ') === 'https://cam.example.com:8443/bus1/index.m3u8');
    check('origin of a camera link', LiveFleet::originOf('https://Cam.Example.com:8443/x?y=1') === 'https://cam.example.com:8443');
    check('an unknown camera type is refused', throws(fn() => LiveFleet::saveCamera(0, $busId, 'X', 'rtsp', 'https://a.b/c', true)) !== '');
    $cam = LiveFleet::saveCamera(0, $busId, 'Front road', 'hls', 'https://cam.example.com/bus1/index.m3u8', true);
    LiveFleet::saveCamera(0, $busId, 'Hidden', 'snapshot', 'https://cam.example.com/snap.jpg', false);
    $b = $find(LiveFleet::snapshot(true));
    check('the snapshot carries the active camera only', count($b['cameras']) === 1 && $b['cameras'][0]['id'] === $cam && $b['cameras'][0]['kind'] === 'hls');

    echo "-- TV key --\n";
    LiveFleet::disableTvKey();
    check('no key set → nothing matches, not even empty', !LiveFleet::tvKeyMatches('') && !LiveFleet::tvKeyMatches('x'));
    $k = LiveFleet::rotateTvKey();
    check('a new key matches', strlen($k) === 32 && LiveFleet::tvKeyMatches($k));
    check('a wrong key does not', !LiveFleet::tvKeyMatches(strrev($k)));
    $k2 = LiveFleet::rotateTvKey();
    check('a newer key retires the old one', !LiveFleet::tvKeyMatches($k) && LiveFleet::tvKeyMatches($k2));

    echo "-- end, revoke, cleanup --\n";
    check('End closes the trip', LiveFleet::endTrip($devA) && LiveFleet::currentTrip($devA) === null);
    check('...and the bus shows as parked', $find(LiveFleet::snapshot(false))['status'] === 'parked');
    $devA2 = LiveFleet::device((string) LiveFleet::login($codeA, '4821')['token']);
    LiveFleet::startTrip($devA2, $busId);
    LiveFleet::setLogin($drvA, $codeA, '9999');
    check('a new PIN signs every phone out and ends its trip', LiveFleet::device((string) $r['token']) === null && (int) Database::scalar("SELECT COUNT(*) FROM fleet_trips WHERE driver_id = :d AND status = 'running'", ['d' => $drvA]) === 0);

    $devB = LiveFleet::device((string) LiveFleet::login($codeB, '7778')['token']);
    $tr = LiveFleet::startTrip($devB, $busId);
    Database::update('fleet_trips', ['last_ping_at' => date('Y-m-d H:i:s', time() - 7 * 3600)], 'id = :id', ['id' => (int) $tr['id']]);
    check('cron ends a trip silent for 7 h', LiveFleet::endSilent(6) >= 1 && Database::scalar('SELECT end_reason FROM fleet_trips WHERE id = :id', ['id' => (int) $tr['id']]) === 'silent');
    $before = (int) Database::scalar('SELECT COUNT(*) FROM fleet_points p JOIN fleet_trips t ON t.id = p.trip_id WHERE t.bus_id = :b', ['b' => $busId]);
    LiveFleet::purgePoints(30, time() + 86400 * 800);
    check('cron drops trail points past the keep window', $before > 0 && (int) Database::scalar('SELECT COUNT(*) FROM fleet_points p JOIN fleet_trips t ON t.id = p.trip_id WHERE t.bus_id = :b', ['b' => $busId]) === 0);
} catch (Throwable $e) {
    check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    Settings::set('fleet_tv_key', $tvBefore, 'string', 'liveops', false);
    if ($legacy !== null) {
        Database::query("UPDATE kv_store SET kvalue = :v, updated_by = :b WHERE kscope = 'global' AND kkey = 'livebus'", ['v' => $legacy['kvalue'], 'b' => $legacy['updated_by']]);
    } else {
        Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'live-fleet'");
    }
}

check('cleanup removed the test bus, drivers and their trips', !Database::exists("SELECT 1 FROM buses WHERE bus_number = '" . LF_BUS . "'")
    && !Database::exists("SELECT 1 FROM drivers WHERE full_name LIKE 'LF Test %'"));

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
