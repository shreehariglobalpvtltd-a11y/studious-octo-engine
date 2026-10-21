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
 *  Traccar formats, logout, and the pages answering.
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
$cleanup = static function (): void {
    Database::run("DELETE FROM buses WHERE bus_number = '" . LFH_BUS . "'");
    Database::run("DELETE FROM drivers WHERE full_name = 'LF Http Driver'");
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('fleet_login_ip', 'fleet_login_code')");
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

    echo "-- end and logout --\n";
    [$c, $j] = drv('end', [], $token);
    check('End → ended', $c === 200 && ($j['data']['ended'] ?? false) === true);
    [, $j] = http('GET', $BASE . '/api/live-fleet.php?key=' . $key);
    check('...the bus shows as parked', ($findBus($j)['status'] ?? '') === 'parked');
    [$c] = drv('logout', [], $token);
    [$c2] = drv('me', [], $token);
    check('after logout the token is dead', $c === 200 && $c2 === 401);
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
