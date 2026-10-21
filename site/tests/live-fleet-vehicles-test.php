<?php
/**
 * =====================================================================
 *  live-fleet-vehicles-test.php — Live Fleet by vehicle number (Oct 2026).
 *
 *  Pins what the third round added on top of live-fleet-test.php:
 *
 *    - a regular driver per vehicle number; a bus phone paired to one
 *      vehicle by a 6-digit code (single use, 15 min, hashed, a newer code
 *      retires the older, a new phone signs the old one out); a bus phone
 *      only ever tracks its own bus and carries the assigned driver
 *    - phone health from the phone: charger, network, screen, altitude →
 *      battery / unplugged / app-in-background alerts
 *    - overspeed after 15 s over the limit, closed when it slows down;
 *      a long stop; a parked bus's GPS jitter adds no km
 *    - driver reports (SOS, puncture…): one open per kind, closed by the
 *      office, a puncture closes itself once the bus rolls again
 *    - railway-style running status: arrival / departure per stop from the
 *      fixes, lateness against the timetable, replay of the whole trip
 *    - the passenger link: one bus, nothing private, dies after the trip
 *    - the installer: menu + Live Map edits keep CRLF, back up to *.bak,
 *      run twice = no change, refuse a file that would not compile
 *
 *  Everything it creates is deleted again (buses / drivers cascade).
 *
 *    php tests/live-fleet-vehicles-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';
require_once INCLUDE_PATH . '/livefleet-install.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : get_class($e); }
}

if (!Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fleet_pair_codes'")) {
    check('fleet_* tables exist (apply database/upgrade-2026-10-live-fleet.sql)', false);
    exit(1);
}

const LV_BUSES = ['LV-TEST-0001', 'LV-TEST-0002', 'LV-TEST-0003'];
const LV_ROUTE = 'LVT9';
$cleanup = static function (): void {
    Database::run("DELETE FROM buses WHERE bus_number IN ('" . implode("','", LV_BUSES) . "')");
    Database::run("DELETE FROM drivers WHERE full_name LIKE 'LV Test %'");
    Database::run("DELETE FROM schedules WHERE route_id IN (SELECT id FROM routes WHERE route_code = '" . LV_ROUTE . "')");
    Database::run("DELETE FROM routes WHERE route_code = '" . LV_ROUTE . "'");
};
$cleanup();
$legacy  = Database::fetch("SELECT kvalue, updated_by FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
$limitWas = Settings::getInt('fleet_speed_limit', 80);
$stopWas  = Settings::getInt('fleet_stop_alert_min', 20);
Settings::set('fleet_speed_limit', 80, 'int', 'liveops');
Settings::set('fleet_stop_alert_min', 20, 'int', 'liveops');

$busA = Database::insert('buses', ['bus_number' => LV_BUSES[0], 'bus_name' => 'LV Test Coach A', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 1]);
$busB = Database::insert('buses', ['bus_number' => LV_BUSES[1], 'bus_name' => 'LV Test Retired', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 0]);
$busC = Database::insert('buses', ['bus_number' => LV_BUSES[2], 'bus_name' => 'LV Test Coach C', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 1]);
$drvA = Database::insert('drivers', ['full_name' => 'LV Test Asha Rai', 'phone' => '919800000201', 'role' => 'driver', 'is_active' => 1]);
$drvB = Database::insert('drivers', ['full_name' => 'LV Test Bikram', 'phone' => '919800000202', 'role' => 'driver', 'is_active' => 1]);
$find = static function (array $snap, int $id): ?array { foreach ($snap['buses'] as $b) { if ($b['id'] === $id) { return $b; } } return null; };
$keys = static fn(?array $b): array => array_map(static fn(array $a): string => $a['key'], $b['alerts'] ?? []);
$tmp  = sys_get_temp_dir() . '/lv-install-' . bin2hex(random_bytes(4));

try {
    echo "-- a regular driver per vehicle number --\n";
    check('assigning to an unknown bus is refused', throws(fn() => LiveFleet::assignDriver(999999999, $drvA)) !== '');
    check('assigning an unknown driver is refused', throws(fn() => LiveFleet::assignDriver($busA, 999999999)) !== '');
    LiveFleet::assignDriver($busA, $drvA);
    check('the vehicle now has its driver', (LiveFleet::driverForBus($busA)['id'] ?? 0) === $drvA);
    LiveFleet::assignDriver($busA, $drvB);
    check('assigning again replaces, never duplicates', (LiveFleet::driverForBus($busA)['id'] ?? 0) === $drvB
        && (int) Database::scalar('SELECT COUNT(*) FROM fleet_vehicle_assign WHERE bus_id = :b', ['b' => $busA]) === 1);
    LiveFleet::assignDriver($busA, null);
    check('null clears it', LiveFleet::driverForBus($busA) === null);
    LiveFleet::assignDriver($busA, $drvA);

    echo "-- pairing a bus phone to a vehicle number --\n";
    check('no code for a bus that is not in service', str_contains(throws(fn() => LiveFleet::createPairCode($busB)), 'active'));
    $pc1 = LiveFleet::createPairCode($busA);
    check('the code is 6 digits, valid ~15 minutes', preg_match('/^\d{6}$/', $pc1['code']) === 1 && abs($pc1['expires'] / 1000 - (time() + 900)) < 5);
    check('only its hash is stored', !Database::exists('SELECT 1 FROM fleet_pair_codes WHERE code_hash = :c', ['c' => $pc1['code']])
        && Database::exists('SELECT 1 FROM fleet_pair_codes WHERE code_hash = :h', ['h' => hash('sha256', $pc1['code'])]));
    $pc2 = LiveFleet::createPairCode($busA);
    check('a newer code retires the older one', !LiveFleet::pairVehicle($pc1['code'])['ok']);
    check('a wrong code is refused', !LiveFleet::pairVehicle($pc2['code'] === '000000' ? '111111' : '000000')['ok']);
    check('letters are not a code', !LiveFleet::pairVehicle('abcdef')['ok']);
    $p = LiveFleet::pairVehicle(' ' . $pc2['code'] . ' ', 'Test phone', '10.0.0.9');
    check('the right code pairs the phone', $p['ok'] && ($p['bus']['id'] ?? 0) === $busA, json_encode($p));
    check('...only once', !LiveFleet::pairVehicle($pc2['code'])['ok']);
    $vPhone = LiveFleet::device((string) $p['token']);
    check('it is a vehicle phone of that bus with the assigned driver', $vPhone !== null && $vPhone['kind'] === 'vehicle'
        && (int) $vPhone['bus_id'] === $busA && ($vPhone['driver_name'] ?? '') === 'LV Test Asha Rai', json_encode([$vPhone['kind'] ?? null, $vPhone['driver_name'] ?? null]));
    $pc3 = LiveFleet::createPairCode($busC);
    Database::run("UPDATE fleet_pair_codes SET expires_at = '2001-01-01 00:00:00' WHERE code_hash = '" . hash('sha256', $pc3['code']) . "'");
    check('an expired code is refused', !LiveFleet::pairVehicle($pc3['code'])['ok']);

    $buses = LiveFleet::busesFor($vPhone);
    check('a bus phone is offered only its own bus', count($buses) === 1 && $buses[0]['id'] === $busA && $buses[0]['suggested']);
    LiveFleet::setLogin($drvA, 'LVA' . $drvA, '5150');
    $dPhone = LiveFleet::device((string) LiveFleet::login('LVA' . $drvA, '5150')['token']);
    $mine = array_values(array_filter(LiveFleet::busesFor($dPhone), static fn(array $b): bool => $b['id'] === $busA));
    check('the driver\'s own phone ticks the assigned vehicle', $mine !== [] && $mine[0]['assigned'] && $mine[0]['suggested']);

    $trip = LiveFleet::startTrip($vPhone, $busC);
    check('a bus phone tracks its own bus whatever it asks for', (int) $trip['bus_id'] === $busA);
    check('...with the assigned driver on the trip', (int) $trip['driver_id'] === $drvA);
    check('...and a passenger link token', preg_match('/^[a-f0-9]{24}$/', (string) $trip['share_token']) === 1);

    echo "-- phone health --\n";
    $t = time();
    LiveFleet::ping($vPhone, [['lat' => 21.30, 'lng' => 72.95, 'ts' => $t * 1000, 'spd' => 10, 'acc' => 6, 'alt' => 14.4]], 15, $t,
        ['charging' => false, 'net' => '4G!', 'visible' => false]);
    $b = $find(LiveFleet::snapshot(false), $busA);
    check('the office sees the phone: bus phone, unplugged, 4g, off screen, altitude', ($b['phone'] ?? null) === ['kind' => 'vehicle', 'charging' => false, 'net' => '4g', 'visible' => false, 'alt' => 14.4],
        json_encode($b['phone'] ?? null));
    check('battery 15% and not charging → battery alert', in_array('battery', $keys($b), true));
    check('driver page not on screen → alert', in_array('background', $keys($b), true));
    LiveFleet::ping($vPhone, [], 64, $t, ['charging' => false, 'visible' => true]);
    $b = $find(LiveFleet::snapshot(false), $busA);
    check('a bus phone off its charger → unplugged alert', in_array('unplugged', $keys($b), true) && !in_array('background', $keys($b), true), implode(',', $keys($b)));
    LiveFleet::ping($vPhone, [], 64, $t, ['charging' => true]);
    check('...gone once it is plugged in', !in_array('unplugged', $keys($find(LiveFleet::snapshot(false), $busA)), true));
    check('a state-only batch keeps the last fix time', (int) Database::scalar("SELECT points FROM fleet_trips WHERE id = :id", ['id' => (int) $trip['id']]) === 1);

    echo "-- overspeed, long stop, parked jitter --\n";
    $lat = 21.30; $pts = [];
    for ($i = 1; $i <= 20; $i++) { $lat += 0.00025; $pts[] = ['lat' => $lat, 'lng' => 72.95, 'ts' => ($t + $i) * 1000, 'spd' => 27.8, 'acc' => 5, 'hdg' => 0]; }
    LiveFleet::ping($vPhone, array_slice($pts, 0, 10), 80, $t + 10);
    check('10 s over the limit is not yet an episode', !Database::exists("SELECT 1 FROM fleet_alerts WHERE trip_id = :t AND kind = 'overspeed'", ['t' => (int) $trip['id']]));
    LiveFleet::ping($vPhone, array_slice($pts, 10), 80, $t + 20);
    $os = Database::fetch("SELECT peak, ended_at FROM fleet_alerts WHERE trip_id = :t AND kind = 'overspeed'", ['t' => (int) $trip['id']]);
    check('15 s+ at 100 km/h → overspeed episode with its peak', $os !== null && abs((float) $os['peak'] - 100.1) < 0.2 && $os['ended_at'] === null, json_encode($os));
    $b = $find(LiveFleet::snapshot(false, $t + 20), $busA);
    $ov = array_values(array_filter($b['alerts'], static fn(array $a): bool => $a['key'] === 'overspeed'));
    check('...shown red, in Nepali and English', $ov !== [] && $ov[0]['level'] === 'red' && str_contains($ov[0]['ne'], 'सीमा') && str_contains($ov[0]['en'], 'limit 80'));
    $lat += 0.0002;
    LiveFleet::ping($vPhone, [['lat' => $lat, 'lng' => 72.95, 'ts' => ($t + 21) * 1000, 'spd' => 15, 'acc' => 5]], 80, $t + 21);
    check('slowing down closes the episode', Database::scalar("SELECT ended_at FROM fleet_alerts WHERE trip_id = :t AND kind = 'overspeed'", ['t' => (int) $trip['id']]) !== null);
    check('the trip keeps its top speed', abs((float) Database::scalar('SELECT max_speed_kmh FROM fleet_trips WHERE id = :id', ['id' => (int) $trip['id']]) - 100.1) < 0.2);

    $km0 = (float) Database::scalar('SELECT distance_km FROM fleet_trips WHERE id = :id', ['id' => (int) $trip['id']]);
    $stand = [];
    for ($i = 1; $i <= 22; $i++) {   // 21 minutes standing, the fix wobbling a few metres
        $stand[] = ['lat' => $lat + (($i % 3) - 1) * 0.00008, 'lng' => 72.95 + (($i % 2) * 0.00006), 'ts' => ($t + 21 + $i * 60) * 1000, 'spd' => 0.4, 'acc' => 9];
    }
    LiveFleet::ping($vPhone, $stand, 80, $t + 21 + 22 * 60);
    $km1 = (float) Database::scalar('SELECT distance_km FROM fleet_trips WHERE id = :id', ['id' => (int) $trip['id']]);
    check('a parked bus\'s GPS wobble adds no km', abs($km1 - $km0) < 0.001, $km0 . ' → ' . $km1);
    $st = Database::fetch("SELECT peak, ended_at FROM fleet_alerts WHERE trip_id = :t AND kind = 'stopped'", ['t' => (int) $trip['id']]);
    check('standing 20+ minutes → long-stop episode', $st !== null && (int) $st['peak'] >= 20 && $st['ended_at'] === null, json_encode($st));
    check('...shown amber with the minutes', in_array('stopped', $keys($find(LiveFleet::snapshot(false, $t + 21 + 22 * 60), $busA)), true));
    LiveFleet::ping($vPhone, [['lat' => $lat + 0.001, 'lng' => 72.95, 'ts' => ($t + 21 + 23 * 60) * 1000, 'spd' => 12, 'acc' => 5]], 80, $t + 21 + 23 * 60);
    check('moving again closes it', Database::scalar("SELECT ended_at FROM fleet_alerts WHERE trip_id = :t AND kind = 'stopped'", ['t' => (int) $trip['id']]) !== null);

    echo "-- driver reports --\n";
    check('an unknown report is refused', throws(fn() => LiveFleet::driverEvent($vPhone, 'party')) !== '');
    $sos = LiveFleet::driverEvent($vPhone, 'sos');
    check('SOS twice = one open report', LiveFleet::driverEvent($vPhone, 'sos') === $sos);
    $b = $find(LiveFleet::snapshot(false), $busA);
    $sa = array_values(array_filter($b['alerts'], static fn(array $a): bool => $a['key'] === 'sos:' . $sos));
    check('SOS is red, first in the list, with its id to resolve', $sa !== [] && $sa[0]['level'] === 'red' && $sa[0]['id'] === $sos && $b['alerts'][0]['key'] === 'sos:' . $sos);
    check('the office resolves it once', LiveFleet::resolveAlert($sos) && !LiveFleet::resolveAlert($sos));
    $pun = LiveFleet::driverEvent($vPhone, 'puncture');
    $now2 = $t + 21 + 24 * 60;
    LiveFleet::ping($vPhone, [['lat' => $lat + 0.002, 'lng' => 72.95, 'ts' => $now2 * 1000, 'spd' => 2, 'acc' => 5]], 80, $now2);   // 7 km/h
    check('a puncture stays open while the bus crawls', Database::scalar('SELECT ended_at FROM fleet_alerts WHERE id = :id', ['id' => $pun]) === null);
    LiveFleet::ping($vPhone, [['lat' => $lat + 0.004, 'lng' => 72.95, 'ts' => ($now2 + 10) * 1000, 'spd' => 11, 'acc' => 5]], 80, $now2 + 10);
    check('...and closes itself once it rolls at 40 km/h', Database::scalar('SELECT ended_at FROM fleet_alerts WHERE id = :id', ['id' => $pun]) !== null);

    echo "-- a new bus phone signs the old one out --\n";
    $pc4 = LiveFleet::createPairCode($busA);
    $p2 = LiveFleet::pairVehicle($pc4['code'], 'New phone');
    check('the new phone pairs', $p2['ok']);
    check('the old bus phone is signed out and its trip ended', LiveFleet::device((string) $p['token']) === null
        && Database::scalar('SELECT status FROM fleet_trips WHERE id = :id', ['id' => (int) $trip['id']]) === 'ended');
    check('one vehicle, one bus phone', (int) Database::scalar("SELECT COUNT(*) FROM fleet_devices WHERE bus_id = :b AND kind = 'vehicle' AND revoked_at IS NULL", ['b' => $busA]) === 1);

    echo "-- running status, stop by stop --\n";
    $T0 = time() - 3600;                  // reached Surat an hour ago, 10 minutes late
    $sched0 = $T0 - 600;
    $rid = Database::insert('routes', ['route_code' => LV_ROUTE, 'from_city' => 'Surat', 'to_city' => 'Ahmedabad', 'dep_time' => date('H:i:s', $sched0), 'arr_time' => '23:00:00', 'is_active' => 0]);
    foreach ([['boarding', 'Surat', 21.1702, 72.8311, 0], ['boarding', 'Vadodara', 22.3072, 73.1812, 4 * 3600], ['drop', 'Ahmedabad', 23.0225, 72.5714, 6 * 3600]] as $i => [$type, $name, $la, $ln, $off]) {
        Database::insert('route_stops', ['route_id' => $rid, 'stop_type' => $type, 'stop_name' => $name, 'latitude' => $la, 'longitude' => $ln,
            'stop_time' => date('H:i:s', $sched0 + $off), 'sort_order' => $i + 1]);
    }
    Database::insert('schedules', ['route_id' => $rid, 'travel_date' => date('Y-m-d', $sched0), 'bus_id' => $busC, 'driver_id' => $drvB, 'total_seats' => 30, 'status' => 'scheduled']);
    LiveFleet::setLogin($drvB, 'LVB' . $drvB, '6262');
    $phB = LiveFleet::device((string) LiveFleet::login('LVB' . $drvB, '6262')['token']);
    $tc = LiveFleet::startTrip($phB, $busC);
    check('the trip finds its timetable run and route', (int) $tc['route_id'] === $rid && (int) $tc['schedule_id'] > 0);
    $sch = LiveFleet::schedTimes($tc);
    check('timetable times per stop, in order', count($sch) === 3 && strtotime($sch[0]) === $sched0 && strtotime($sch[2]) - strtotime($sch[1]) === 2 * 3600, json_encode($sch));
    LiveFleet::ping($phB, [['lat' => 21.1705, 'lng' => 72.8315, 'ts' => $T0 * 1000, 'spd' => 2, 'acc' => 8]], 90, $T0);
    LiveFleet::ping($phB, [['lat' => 21.1902, 'lng' => 72.8411, 'ts' => ($T0 + 300) * 1000, 'spd' => 12, 'acc' => 8]], 90, $T0 + 300);
    LiveFleet::ping($phB, [['lat' => 21.70, 'lng' => 73.00, 'ts' => ($T0 + 3000) * 1000, 'spd' => 18, 'acc' => 8, 'hdg' => 20]], 90, $T0 + 3000);
    $ev = Database::fetch('SELECT arrived_at, departed_at, sched_at FROM fleet_stop_events WHERE trip_id = :t AND stop_idx = 0', ['t' => (int) $tc['id']]);
    check('arrival at Surat from the first fix within 1 km', $ev !== null && strtotime((string) $ev['arrived_at']) === $T0, json_encode($ev));
    check('departure from the first fix 1.5 km+ away', $ev !== null && strtotime((string) $ev['departed_at']) === $T0 + 300);
    $bc = $find(LiveFleet::snapshot(false), $busC);
    $tl = $bc['timeline'] ?? null;
    check('the running status lists every stop', $tl !== null && count($tl['stops']) === 3);
    check('Surat: passed, reached 10 min late, left 5 min later', ($tl['stops'][0]['state'] ?? '') === 'done' && ($tl['stops'][0]['late'] ?? null) === 10
        && ($tl['stops'][0]['dep'] ?? 0) === ($T0 + 300) * 1000, json_encode($tl['stops'][0] ?? null));
    check('Vadodara: next, with an expected time and the Nepali name', ($tl['stops'][1]['state'] ?? '') === 'next' && ($tl['stops'][1]['eta'] ?? null) !== null && ($tl['stops'][1]['ne'] ?? '') === 'वडोदरा');
    check('the headline knows the last departure', ($tl['last_dep']['name'] ?? '') === 'Surat');
    $rp = LiveFleet::replay((int) $tc['id']);
    check('replay: every point with time, place, speed', count($rp['points']) === 3 && $rp['points'][0][0] === $T0 * 1000 && count($rp['points'][0]) === 5);
    check('replay: the stops it reached', ($rp['stops'][0]['name'] ?? '') === 'Surat' && $rp['stops'][0]['arr'] === $T0 * 1000);
    $rt = LiveFleet::recentTrips($busC);
    check('recent trips of the bus, newest first', ($rt[0]['id'] ?? 0) === (int) $tc['id'] && $rt[0]['running'] && $rt[0]['driver'] === 'LV Test Bikram');

    echo "-- the passenger link --\n";
    $tok = (string) $bc['share'];
    $sv = LiveFleet::shareView($tok);
    check('the link shows that one bus, running', $sv['state'] === 'running' && $sv['bus']['id'] === $busC);
    check('nothing private: no phone, battery, alerts, cameras', !array_key_exists('phone', $sv['bus']) && !array_key_exists('battery', $sv['bus'])
        && !array_key_exists('alerts', $sv['bus']) && !array_key_exists('cameras', $sv['bus']) && !array_key_exists('share', $sv['bus']));
    check('driver: first name only, no number', $sv['bus']['driver'] === ['name' => 'LV', 'phone' => ''], json_encode($sv['bus']['driver']));
    check('a made-up or malformed token shows nothing', LiveFleet::shareView(str_repeat('ab', 12))['state'] === 'unknown'
        && LiveFleet::shareView("x' OR 1=1 --")['state'] === 'unknown' && !isset(LiveFleet::shareView('')['bus']));
    LiveFleet::endTrip($phB);
    check('just after the trip: shown as ended', LiveFleet::shareView($tok)['state'] === 'ended');
    check('2 hours later: expired', LiveFleet::shareView($tok, time() + 7300)['state'] === 'expired');

    echo "-- the installer --\n";
    $stm = LiveFleetInstall::statements("-- a comment\nCREATE TABLE a (x INT);\n\n-- another\nDO 0;\n");
    check('SQL: comment lines dropped, split on ;', $stm === ['CREATE TABLE a (x INT)', 'DO 0']);
    check('every Live Fleet file is uploaded here', LiveFleetInstall::missingFiles(ROOT_PATH) === [], implode(',', LiveFleetInstall::missingFiles(ROOT_PATH)));
    check('the database has every table and column', LiveFleetInstall::missingSchema(Database::pdo()) === [], implode(',', LiveFleetInstall::missingSchema(Database::pdo())));
    mkdir($tmp . '/admin', 0700, true);
    $guard = "<?php\r\nfunction admin_nav(): array\r\n{\r\n    \$all = [\r\n        ['href' => 'index.php', 'label' => 'Dashboard'],\r\n"
           . "        ['href' => 'map.php',          'icon' => 'map-pin',      'label' => 'Live Map',         'perm' => 'schedules.view', 'section' => 'Map'],\r\n"
           . "    ];\r\n    return \$all;\r\n}\r\n";
    $map = "<?php admin_header('Live Map', 'map'); ?>\r\n<div id=\"lmMap\"></div>\r\n<script>\r\nwindow.ADMAP = { routes: [] };\r\n(function () {\r\n  var map;\r\n"
         . "  function draw(){ }\r\n  map.on('load', function(){\r\n      draw();\r\n      poll();\r\n  });\r\n})();\r\n</script>\r\n<?php admin_footer(); ?>\r\n";
    file_put_contents($tmp . '/admin/_guard.php', $guard);
    file_put_contents($tmp . '/admin/map.php', $map);
    check('menu: not there yet', !LiveFleetInstall::menuDone($tmp) && !LiveFleetInstall::mapDone($tmp));
    $msg = LiveFleetInstall::patchMenu($tmp);
    $g = (string) file_get_contents($tmp . '/admin/_guard.php');
    check('menu: three items added under Live Map', LiveFleetInstall::menuDone($tmp) && substr_count($g, "'section' => 'Map'") === 4
        && strpos($g, "'live-fleet.php'") > strpos($g, "'map.php'"), $msg);
    check('menu: the file keeps its CRLF line endings', substr_count($g, "\n") === substr_count($g, "\r\n"));
    $baks = glob($tmp . '/admin/_guard.php.livefleet-*.bak') ?: [];
    check('menu: the old file is kept as *.bak (the site never serves .bak)', count($baks) === 1 && file_get_contents($baks[0]) === $guard);
    check('menu: a second run changes nothing', str_contains(LiveFleetInstall::patchMenu($tmp), 'already') && file_get_contents($tmp . '/admin/_guard.php') === $g);
    LiveFleetInstall::patchMap($tmp);
    $m = (string) file_get_contents($tmp . '/admin/map.php');
    check('Live Map: scripts before the map script, the call right after draw()', LiveFleetInstall::mapDone($tmp)
        && strpos($m, "asset('overlay.js')") < strpos($m, 'window.ADMAP') && preg_match("/draw\\(\\);\r\n\\s+if \\(window\\.LiveFleetOverlay\\) window\\.LiveFleetOverlay\\(map\\);\r\n\\s+poll\\(\\);/", $m) === 1);
    check('Live Map: CRLF kept, still valid PHP', substr_count($m, "\n") === substr_count($m, "\r\n") && throws(fn() => PhpToken::tokenize($m, TOKEN_PARSE)) === '');
    check('Live Map: a second run changes nothing', str_contains(LiveFleetInstall::patchMap($tmp), 'already') && file_get_contents($tmp . '/admin/map.php') === $m);
    file_put_contents($tmp . '/admin/_guard.php', "<?php\n\$all = [];\n");
    check('a menu without the Live Map line is left alone', throws(fn() => LiveFleetInstall::patchMenu($tmp)) !== '' && file_get_contents($tmp . '/admin/_guard.php') === "<?php\n\$all = [];\n");
    $w = new ReflectionMethod(LiveFleetInstall::class, 'write');
    $w->setAccessible(true);
    check('code that would not compile is never saved', str_contains(throws(fn() => $w->invoke(null, $tmp . '/admin/_guard.php', "<?php\n\$all = [;\n")), 'compile')
        && file_get_contents($tmp . '/admin/_guard.php') === "<?php\n\$all = [];\n");
    check('a number plate is escaped', LiveFleet::plateHtml('<b>X</b>') === '<span class="lf-plate"><i>IND</i><b>&lt;b&gt;X&lt;/b&gt;</b></span>');
} catch (Throwable $e) {
    check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    Settings::set('fleet_speed_limit', $limitWas, 'int', 'liveops');
    Settings::set('fleet_stop_alert_min', $stopWas, 'int', 'liveops');
    if ($legacy !== null) {
        Database::query("UPDATE kv_store SET kvalue = :v, updated_by = :b WHERE kscope = 'global' AND kkey = 'livebus'", ['v' => $legacy['kvalue'], 'b' => $legacy['updated_by']]);
    } else {
        Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'live-fleet'");
    }
    foreach (array_merge(glob($tmp . '/admin/*') ?: [], [$tmp . '/admin', $tmp]) as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
}

check('cleanup removed the test buses, drivers, route and their trips', !Database::exists("SELECT 1 FROM buses WHERE bus_number LIKE 'LV-TEST-%'")
    && !Database::exists("SELECT 1 FROM drivers WHERE full_name LIKE 'LV Test %'") && !Database::exists("SELECT 1 FROM routes WHERE route_code = '" . LV_ROUTE . "'"));

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
