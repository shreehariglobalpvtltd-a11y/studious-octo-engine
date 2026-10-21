<?php
/**
 * =====================================================================
 *  live-fleet-http-test.php — Live Fleet over real HTTP, the way a
 *  driver's phone, the Traccar Client app and the TV wall talk to it.
 *
 *  Needs the dev server on the TEST database:
 *      php -S 127.0.0.1:8899 -t .        (another terminal)
 *      php tests/live-fleet-http-test.php [http://127.0.0.1:8899]
 *
 *  Pins: login refusals, the token as the only key, 409 before Start,
 *  the live answer reaching the map API, the map API shut without a
 *  staff session or TV key, the TV key hiding driver phones, both
 *  Traccar formats, logout, and the pages answering. Then: a driver's
 *  SOS reaching the office and cleared by the phone, the passenger link
 *  (one bus, nothing private, noindex), replay, and a bus phone paired
 *  by code that only ever tracks its own vehicle number.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

$BASE = rtrim($argv[1] ?? (getenv('LF_BASE') ?: 'http://127.0.0.1:8899'), '/');
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** The response headers of a GET, lower-cased names. @return array<string,string> */
function headersOf(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $len = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $out = [];
    foreach (explode("\n", substr($raw, 0, $len)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $out[strtolower(trim($k))] = trim($v); }
    }
    return $out;
}
/** @return array{0:int,1:mixed,2:string} status, decoded JSON, raw body */
function http(string $method, string $url, mixed $json = null, array $headers = []): array {
    $ch = curl_init($url);
    $h = $headers;
    if ($json !== null) { $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true), $body];
}
function drv(string $action, array $body = [], string $token = ''): array {
    global $BASE;
    return http('POST', $BASE . '/api/driver.php', ['action' => $action] + $body, $token !== '' ? ['X-Driver-Token: ' . $token] : []);
}

[$c] = http('GET', $BASE . '/driver/');
if ($c === 0) {
    echo "  \033[33mSKIP\033[0m  no server at $BASE — start one: php -S 127.0.0.1:8899 -t .\n";
    exit(0);
}

const LFH_BUS = 'LF-HTTP-0001';
const LFH_BUS2 = 'LF-HTTP-0002';
$cleanup = static function (): void {
    Database::run("DELETE FROM buses WHERE bus_number IN ('" . LFH_BUS . "', '" . LFH_BUS2 . "')");
    Database::run("DELETE FROM drivers WHERE full_name = 'LF Http Driver'");
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('fleet_login_ip', 'fleet_login_code', 'fleet_places', 'fleet_pair_ip', 'fleet_share')");
    Database::run("DELETE FROM fleet_places WHERE cell = '52:52'");
};
$cleanup();
$tvBefore = LiveFleet::tvKey();
$busId = Database::insert('buses', ['bus_number' => LFH_BUS, 'bus_name' => 'LF Http Coach', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 1]);
$drvId = Database::insert('drivers', ['full_name' => 'LF Http Driver', 'phone' => '919800000201', 'role' => 'driver', 'is_active' => 1]);
$code  = 'LFH' . $drvId;
LiveFleet::setLogin($drvId, $code, '2468');
$findBus = static function (?array $d) use ($busId): ?array {
    foreach (($d['data']['buses'] ?? []) as $b) { if ($b['id'] === $busId) { return $b; } }
    return null;
};

try {
    echo "-- driver API --\n";
    [$c, $j] = drv('login', ['code' => $code, 'pin' => '0000']);
    check('wrong PIN → 401 with a message', $c === 401 && ($j['ok'] ?? true) === false && ($j['error'] ?? '') !== '', (string) $c);
    [$c, $j] = http('GET', $BASE . '/api/driver.php?action=login&code=' . $code . '&pin=2468');
    check('login over GET is refused (POST only)', $c === 405 || $c === 400, (string) $c);
    [$c, $j] = drv('login', ['code' => strtolower($code), 'pin' => '2468']);
    $token = (string) ($j['data']['token'] ?? '');
    check('right PIN → token + driver + bus list', $c === 200 && strlen($token) === 64 && ($j['data']['driver']['name'] ?? '') === 'LF Http Driver'
        && in_array($busId, array_column($j['data']['buses'] ?? [], 'id'), true));
    [$c] = drv('me', [], str_repeat('a', 64));
    check('an unknown token → 401', $c === 401);
    [$c] = drv('me');
    check('no token → 401', $c === 401);
    [$c, $j] = drv('ping', ['points' => [['lat' => 22.3, 'lng' => 73.18]]], $token);
    check('a fix before Start → 409', $c === 409, (string) $c);
    [$c, $j] = drv('start', ['bus_id' => $busId], $token);
    check('Start → the running trip', $c === 200 && ($j['data']['trip']['bus_id'] ?? 0) === $busId);
    $now = time();
    $pts = [];
    for ($i = 0; $i < 3; $i++) { $pts[] = ['lat' => 22.3072 + $i * 0.0002, 'lng' => 73.1812, 'ts' => ($now - 3 + $i) * 1000, 'spd' => 12.5, 'hdg' => 10, 'acc' => 6]; }
    [$c, $j] = drv('ping', ['points' => $pts, 'battery' => 81], $token);
    check('3 fixes → accepted 3', $c === 200 && ($j['data']['accepted'] ?? 0) === 3, json_encode($j['data'] ?? $j));
    [$c, $j] = drv('ping', ['points' => 'nope'], $token);
    check('points that are not a list → 422', $c === 422);
    [$c, $j] = drv('me', [], $token);
    check('me → the trip is still running', $c === 200 && ($j['data']['trip']['bus_id'] ?? 0) === $busId);

    echo "-- map API --\n";
    [$c] = http('GET', $BASE . '/api/live-fleet.php');
    check('no staff session, no key → 401', $c === 401);
    LiveFleet::disableTvKey();
    [$c] = http('GET', $BASE . '/api/live-fleet.php?key=');
    check('TV key switched off → empty key does not open it', $c === 401);
    $key = LiveFleet::rotateTvKey();
    [$c] = http('GET', $BASE . '/api/live-fleet.php?key=' . strrev($key));
    check('a wrong TV key → 401', $c === 401);
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    $b = $findBus($j);
    check('the TV key → every bus, ours live at 45 km/h', $c === 200 && $b !== null && $b['status'] === 'live' && abs(($b['pos']['kmh'] ?? 0) - 45) < 0.1, json_encode($b['pos'] ?? null));
    check('...battery and driver name are there', ($b['battery'] ?? null) === 81 && ($b['driver']['name'] ?? '') === 'LF Http Driver');
    check('...but not the driver\'s phone number', ($b['driver']['phone'] ?? 'x') === '');
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key . '&trail=' . (int) ($b['trip']['id'] ?? 0));
    check('?trail= returns the trip\'s breadcrumbs', $c === 200 && ($j['data']['trail']['trip'] ?? 0) === ($b['trip']['id'] ?? -1) && count($j['data']['trail']['points'] ?? []) >= 1);
    [, , $raw] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    check('the answer is small enough for a 1 s refresh', strlen($raw) < 20000, strlen($raw) . ' bytes');

    check('the answer lists the map squares still to fetch', is_array($j['data']['need_cells'] ?? null));

    echo "-- town / temple names endpoint --\n";
    [$c] = http('GET', $BASE . '/api/fleet-places.php?cells=52:52&key=' . $key);
    check('GET is refused (POST only)', $c === 405, (string) $c);
    [$c] = http('POST', $BASE . '/api/fleet-places.php?cells=52:52', []);
    check('no staff session, no key → 401', $c === 401, (string) $c);
    LiveFleet::storeCell('52:52', [['temple', 10.45, 10.45, 'शिव मन्दिर', 'Shiv Mandir', 'n9']]);
    [$c, $j] = http('POST', $BASE . '/api/fleet-places.php?cells=' . rawurlencode('52:52,drop table') . '&key=' . $key, []);
    check('a cached square is answered "cached" without asking OpenStreetMap; junk ignored', $c === 200 && ($j['data']['cells'] ?? null) === ['52:52' => 'cached'], json_encode($j));

    echo "-- Traccar Client (background mode) --\n";
    $t = time();
    [$c] = http('GET', $BASE . '/api/fleet-osmand.php?id=' . $token . '&lat=22.3100&lon=73.1900&timestamp=' . $t . '&speed=27&bearing=90&accuracy=5&batt=55');
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    $b = $findBus($j);
    check('OsmAnd query string → the bus moves', $c === 200 && abs(($b['pos']['lat'] ?? 0) - 22.31) < 0.00001 && ($b['battery'] ?? 0) === 55, (string) $c);
    check('...knots turned into km/h (27 kn ≈ 50 km/h)', abs(($b['pos']['kmh'] ?? 0) - 50.0) < 0.2, (string) ($b['pos']['kmh'] ?? ''));
    [$c] = http('POST', $BASE . '/api/fleet-osmand.php', ['device_id' => $token, 'location' => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $t + 2), 'coords' => ['latitude' => 22.3200, 'longitude' => 73.2000, 'speed' => 10, 'heading' => 45, 'accuracy' => 7],
        'battery' => ['level' => 0.42, 'is_charging' => true]]]);
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    $b = $findBus($j);
    check('Traccar JSON body → the bus moves', $c === 200 && abs(($b['pos']['lat'] ?? 0) - 22.32) < 0.00001 && ($b['battery'] ?? 0) === 42, (string) $c);
    [$c] = http('GET', $BASE . '/api/fleet-osmand.php?id=' . str_repeat('b', 64) . '&lat=22.3&lon=73.1');
    check('an unknown device id → 401', $c === 401);

    echo "-- pages --\n";
    [$c, , $raw] = http('GET', $BASE . '/driver/');
    check('driver/ answers with the login form', $c === 200 && str_contains($raw, 'Driver login'));
    [$c] = http('GET', $BASE . '/admin/live-fleet.php');
    check('admin/live-fleet.php sends a guest to the login page', $c === 302);
    [$c, , $raw] = http('GET', $BASE . '/admin/live-tv.php?key=' . $key);
    check('admin/live-tv.php opens with the TV key', $c === 200 && str_contains($raw, 'Live Fleet'));
    [$c] = http('GET', $BASE . '/admin/live-tv.php?key=' . strrev($key));
    check('...and refuses a stale key', $c === 403);

    echo "-- driver reports reach the office --\n";
    [$c, $j] = drv('event', ['type' => 'sos'], $token);
    $sos = (int) ($j['data']['id'] ?? 0);
    check('SOS from the phone → a report id', $c === 200 && $sos > 0, json_encode($j));
    [$c, $j] = drv('event', ['type' => 'party'], $token);
    check('an unknown report → 400', $c === 400, (string) $c);
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    $b = $findBus($j);
    check('the TV sees the SOS, red, first', ($b['alerts'][0]['key'] ?? '') === 'sos:' . $sos && ($b['alerts'][0]['level'] ?? '') === 'red');
    [$c, $j] = drv('me', [], $token);
    check('me → the open report (for the ✓ button after a reload) and the speed limit', ($j['data']['open'][0]['id'] ?? 0) === $sos && is_int($j['data']['limit'] ?? null)
        && count($j['data']['events'] ?? []) === 5);
    [$c] = http('POST', $BASE . '/api/live-fleet.php?key=' . $key, ['action' => 'resolve', 'id' => $sos]);
    check('the TV link cannot resolve a report (read-only)', $c === 403, (string) $c);
    [$c] = http('POST', $BASE . '/api/live-fleet.php', ['action' => 'resolve', 'id' => $sos]);
    check('a guest cannot either', $c === 401, (string) $c);
    [$c, $j] = drv('clear', ['id' => 999999999], $token);
    check('a phone cannot clear a report of another trip', $c === 200 && ($j['data']['cleared'] ?? true) === false);
    [$c, $j] = drv('clear', ['id' => $sos], $token);
    check('the phone clears its own report', $c === 200 && ($j['data']['cleared'] ?? false) === true);

    echo "-- the passenger link --\n";
    $share = (string) Database::scalar("SELECT share_token FROM fleet_trips WHERE bus_id = :b AND status = 'running'", ['b' => $busId]);
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?share=' . $share);
    check('no login: the passenger sees that bus, running', $c === 200 && ($j['data']['state'] ?? '') === 'running' && ($j['data']['bus']['id'] ?? 0) === $busId);
    check('...without the phone, battery, alerts or the driver\'s number', !array_key_exists('phone', $j['data']['bus'] ?? ['phone' => 1])
        && !array_key_exists('battery', $j['data']['bus']) && !array_key_exists('alerts', $j['data']['bus']) && ($j['data']['bus']['driver']['phone'] ?? 'x') === '');
    check('...and the API says noindex', str_contains(headersOf($BASE . '/api/live-fleet.php?share=' . $share)['x-robots-tag'] ?? '', 'noindex'));
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?share=' . str_repeat('0', 24));
    check('a made-up token → nothing', $c === 200 && ($j['data']['state'] ?? '') === 'unknown' && !isset($j['data']['bus']));
    [$c, , $raw] = http('GET', $BASE . '/bus-live.php?t=' . $share);
    check('bus-live.php opens, noindex', $c === 200 && str_contains($raw, 'noindex') && str_contains($raw, 'livefleet.js'));
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key . '&trips=' . $busId);
    $tripId = (int) ($j['data']['trips'][0]['id'] ?? 0);
    check('?trips= lists the bus\'s trips', $c === 200 && $tripId > 0);
    [$c, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key . '&replay=' . $tripId);
    check('?replay= gives every point [ms, lng, lat, km/h, heading]', $c === 200 && count($j['data']['points'][0] ?? []) === 5 && ($j['data']['trip']['id'] ?? 0) === $tripId);

    echo "-- end and logout --\n";
    [$c, $j] = drv('end', [], $token);
    check('End → ended', $c === 200 && ($j['data']['ended'] ?? false) === true);
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    check('...the bus shows as parked', ($findBus($j)['status'] ?? '') === 'parked');
    [$c] = drv('logout', [], $token);
    [$c2] = drv('me', [], $token);
    check('after logout the token is dead', $c === 200 && $c2 === 401);

    echo "-- a bus phone, paired by code --\n";
    $bus2 = Database::insert('buses', ['bus_number' => LFH_BUS2, 'bus_name' => 'LF Http Coach 2', 'coach_type' => 'sleeper', 'total_seats' => 30, 'is_active' => 1]);
    LiveFleet::assignDriver($bus2, $drvId);
    [$c] = drv('pair', ['code' => '000000']);
    check('a wrong code → 401', $c === 401, (string) $c);
    $pc = LiveFleet::createPairCode($bus2);
    [$c, $j] = drv('pair', ['code' => $pc['code']]);
    $vt = (string) ($j['data']['token'] ?? '');
    check('the right code → a bus-phone token for that vehicle', $c === 200 && strlen($vt) === 64 && ($j['data']['kind'] ?? '') === 'vehicle'
        && ($j['data']['bus']['number'] ?? '') === LFH_BUS2 && ($j['data']['driver']['name'] ?? '') === 'LF Http Driver', json_encode([$j['data']['kind'] ?? null, $j['data']['bus'] ?? null]));
    [$c] = drv('pair', ['code' => $pc['code']]);
    check('the code works once', $c === 401);
    [$c, $j] = drv('start', ['bus_id' => $busId], $vt);
    check('a bus phone starts its own bus, whatever it asks', $c === 200 && ($j['data']['trip']['bus_id'] ?? 0) === $bus2);
    [$c, $j] = drv('ping', ['points' => [['lat' => 22.4, 'lng' => 73.1, 'spd' => 5, 'acc' => 6, 'alt' => 33]], 'battery' => 90,
        'meta' => ['charging' => true, 'net' => 'wifi', 'visible' => true]], $vt);
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    $b2 = null;
    foreach ($j['data']['buses'] ?? [] as $x) { if ($x['id'] === $bus2) { $b2 = $x; } }
    check('the office sees it by vehicle number with the phone\'s state', ($b2['number'] ?? '') === LFH_BUS2 && ($b2['phone']['kind'] ?? '') === 'vehicle'
        && ($b2['phone']['net'] ?? '') === 'wifi' && ($b2['phone']['charging'] ?? null) === true, json_encode($b2['phone'] ?? null));
    [$c, , $raw] = http('GET', $BASE . '/driver/?pair=123456');
    check('driver/?pair= opens on the bus-phone tab with the code filled in', $c === 200 && str_contains($raw, 'value="123456"') && str_contains($raw, 'Bus phone'));
    drv('end', [], $vt);
    [$c] = http('GET', $BASE . '/admin/live-install.php');
    check('the web installer sends a guest to the login page', $c === 302, (string) $c);
    [$c] = http('GET', $BASE . '/admin/live-setup.php');
    check('so does Vehicles & Drivers', $c === 302, (string) $c);
} catch (Throwable $e) {
    check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
    Settings::set('fleet_tv_key', $tvBefore, 'string', 'liveops', false);
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'live-fleet'");
}

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
