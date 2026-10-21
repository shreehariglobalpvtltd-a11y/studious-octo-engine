<?php
/**
 * =====================================================================
 *  tools/fleet-simulator.php — fake buses for testing Live Fleet.
 *
 *  Logs in exactly like a driver's phone (api/driver.php), starts a trip
 *  and sends one GPS fix per second while "driving" along NH48
 *  Surat → Vadodara → Ahmedabad → Mehsana (and back). Several drivers can
 *  run at once, spread along the road. Stops for ~20 s every few minutes
 *  like a real bus, and can fake a lost signal to show "weak" → catch-up.
 *
 *    php tools/fleet-simulator.php --base=http://127.0.0.1:8899 \
 *        --driver=DEMO1:1234:1 --driver=DEMO2:1234:2 --driver=DEMO3:1234:3
 *
 *  --driver=ID:PIN:BUS   one fake phone (repeat for more buses)
 *  --kmh=60              cruising speed
 *  --speedup=1           move N× faster than real time (10 = easy to watch)
 *  --seconds=0           stop after this many seconds (0 = until Ctrl+C)
 *  --signal-loss=0       every N seconds one bus loses signal for 25 s
 *  --path=file.json      [[lat,lng], …] instead of the built-in road
 *
 *  It only talks HTTP, so it works against any copy of the site. Never
 *  point it at the live site during real trips: it moves real buses' dots.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$opt = ['base' => 'http://127.0.0.1:8899', 'kmh' => '60', 'speedup' => '1', 'seconds' => '0', 'signal-loss' => '0', 'path' => ''];
$drivers = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) !== 1) {
        fwrite(STDERR, "unknown argument: $a\n"); exit(2);
    }
    if ($m[1] === 'driver') { $drivers[] = $m[2]; } else { $opt[$m[1]] = $m[2]; }
}
if ($drivers === []) {
    fwrite(STDERR, "usage: php tools/fleet-simulator.php --base=http://127.0.0.1:8899 --driver=DEMO1:1234:1 [--driver=…] [--speedup=10]\n");
    exit(2);
}
$BASE = rtrim($opt['base'], '/');

// NH48, Surat → Mehsana, roughly along the highway.
$path = [
    [21.1702, 72.8311], [21.2730, 72.9556], [21.4650, 72.9500], [21.6264, 73.0152], [21.7051, 72.9959],
    [22.0530, 73.1230], [22.3072, 73.1812], [22.5645, 72.9289], [22.6916, 72.8634], [23.0225, 72.5714],
    [23.2156, 72.6369], [23.2464, 72.4950], [23.6009, 72.3807],
];
if ($opt['path'] !== '') {
    $path = json_decode((string) file_get_contents($opt['path']), true);
    if (!is_array($path) || count($path) < 2) { fwrite(STDERR, "--path must be a JSON list of [lat,lng]\n"); exit(2); }
}

function hav(array $a, array $b): float {
    $r = 6371000.0;
    $dLat = deg2rad($b[0] - $a[0]); $dLng = deg2rad($b[1] - $a[1]);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}
function bearing(array $a, array $b): float {
    $y = sin(deg2rad($b[1] - $a[1])) * cos(deg2rad($b[0]));
    $x = cos(deg2rad($a[0])) * sin(deg2rad($b[0])) - sin(deg2rad($a[0])) * cos(deg2rad($b[0])) * cos(deg2rad($b[1] - $a[1]));
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}
// Cumulative metres along the path.
$cum = [0.0];
for ($i = 1; $i < count($path); $i++) { $cum[] = $cum[$i - 1] + hav($path[$i - 1], $path[$i]); }
$total = end($cum);
function at(float $d): array {
    global $path, $cum;
    for ($i = 1; $i < count($path); $i++) {
        if ($d <= $cum[$i]) {
            $k = ($d - $cum[$i - 1]) / max(1.0, $cum[$i] - $cum[$i - 1]);
            return [[$path[$i - 1][0] + ($path[$i][0] - $path[$i - 1][0]) * $k, $path[$i - 1][1] + ($path[$i][1] - $path[$i - 1][1]) * $k], bearing($path[$i - 1], $path[$i])];
        }
    }
    $n = count($path);
    return [$path[$n - 1], bearing($path[$n - 2], $path[$n - 1])];
}

function call(string $action, array $body = [], string $token = ''): array {
    global $BASE;
    $ch = curl_init($BASE . '/api/driver.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $token !== '' ? ['X-Driver-Token: ' . $token] : []),
        CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $body)]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string) $raw, true);
    return [$code, is_array($j) ? $j : ['ok' => false, 'error' => $raw === false ? 'no connection' : 'bad answer']];
}

$sims = [];
foreach ($drivers as $n => $spec) {
    [$code, $pin, $bus] = array_pad(explode(':', $spec), 3, '');
    [$c, $j] = call('login', ['code' => $code, 'pin' => $pin]);
    if ($c !== 200 || empty($j['ok'])) { fwrite(STDERR, "$code: login failed — " . ($j['error'] ?? $c) . "\n"); exit(1); }
    $token = (string) $j['data']['token'];
    [$c, $j] = call('start', ['bus_id' => (int) $bus], $token);
    if ($c !== 200 || empty($j['ok'])) { fwrite(STDERR, "$code: start failed — " . ($j['error'] ?? $c) . "\n"); exit(1); }
    $sims[] = [
        'code' => $code, 'token' => $token, 'bus' => (string) $j['data']['trip']['bus'],
        // Spread the buses along the road; odd ones drive the other way.
        'd' => $total * ($n + 0.5) / count($drivers), 'dir' => $n % 2 === 0 ? 1 : -1,
        'stopUntil' => 0, 'nextStop' => time() + random_int(60, 150), 'queue' => [], 'offlineUntil' => 0, 'sent' => 0,
    ];
    echo "✓ {$code} logged in, driving {$sims[$n]['bus']}\n";
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
}

$kmh = (float) $opt['kmh'];
$speedup = max(0.1, (float) $opt['speedup']);
$loss = (int) $opt['signal-loss'];
$t0 = time();
echo "Sending a fix per second per bus. Ctrl+C to stop (trips are ended on exit).\n";
while ($running && ((int) $opt['seconds'] === 0 || time() - $t0 < (int) $opt['seconds'])) {
    $tick = microtime(true);
    $now = time();
    if ($loss > 0 && ($now - $t0) > 0 && ($now - $t0) % $loss === 0) {
        $sims[array_rand($sims)]['offlineUntil'] = $now + 25;
    }
    $line = [];
    foreach ($sims as &$s) {
        // A halt every 1-3 minutes, like a pickup point.
        if ($now >= $s['nextStop'] && $s['stopUntil'] < $now) { $s['stopUntil'] = $now + 20; $s['nextStop'] = $now + random_int(60, 180); }
        $v = $now < $s['stopUntil'] ? 0.0 : max(0.0, $kmh * (0.85 + mt_rand() / mt_getrandmax() * 0.3)) / 3.6;   // m/s
        $s['d'] += $s['dir'] * $v * $speedup;
        if ($s['d'] >= $total) { $s['d'] = $total; $s['dir'] = -1; }
        if ($s['d'] <= 0) { $s['d'] = 0; $s['dir'] = 1; }
        [$pos, $hdg] = at($s['d']);
        if ($s['dir'] < 0) { $hdg = fmod($hdg + 180, 360); }
        $noise = 0.00003;
        $s['queue'][] = ['lat' => round($pos[0] + (mt_rand() / mt_getrandmax() - 0.5) * $noise, 7), 'lng' => round($pos[1] + (mt_rand() / mt_getrandmax() - 0.5) * $noise, 7),
            'ts' => (int) round(microtime(true) * 1000), 'spd' => round($v, 2), 'hdg' => (int) round($hdg), 'acc' => random_int(4, 12)];
        $state = '';
        if ($now < $s['offlineUntil']) {
            $state = 'no signal (' . count($s['queue']) . ' waiting)';
        } else {
            [$c, $j] = call('ping', ['points' => array_slice($s['queue'], -300), 'battery' => max(5, 100 - intdiv($now - $t0, 120))], $s['token']);
            if ($c === 200) { $s['sent'] += count($s['queue']); $s['queue'] = []; $state = 'sent ✓'; }
            else { $state = 'error ' . $c . ' ' . ($j['error'] ?? ''); if ($c === 401 || $c === 409) { $running = false; } }
        }
        $line[] = sprintf('%s %5.1f km/h %s', $s['code'], $v * 3.6, $state);
    }
    unset($s);
    echo date('H:i:s') . '  ' . implode('  |  ', $line) . "\n";
    usleep(max(0, (int) ((1.0 - (microtime(true) - $tick)) * 1e6)));
}

foreach ($sims as $s) {
    call('end', [], $s['token']);
    call('logout', [], $s['token']);
}
echo "Trips ended.\n";
