<?php
/**
 * =====================================================================
 *  tools/fleet-demo-seed.php — three demo drivers for trying Live Fleet
 *  on a TEST copy of the site.
 *
 *    php tools/fleet-demo-seed.php /path/to/site            add DEMO1-3, PIN 1234
 *    php tools/fleet-demo-seed.php /path/to/site --remove   take them away again
 *
 *  Refuses a production config (APP_ENV = production) — demo drivers on
 *  the live site would show up in the real Drivers & Crew list.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_file($root . '/includes/bootstrap.php')) {
    fwrite(STDERR, "usage: php tools/fleet-demo-seed.php /path/to/site [--remove]\n");
    exit(2);
}
require_once $root . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

if (APP_ENV === 'production') {
    fwrite(STDERR, "This site runs with APP_ENV = production. Demo drivers are for a test copy only.\n");
    exit(1);
}

$demo = [['Demo Driver One', '910000000001', 'DEMO1'], ['Demo Driver Two', '910000000002', 'DEMO2'], ['Demo Driver Three', '910000000003', 'DEMO3']];

if (in_array('--remove', $argv, true)) {
    $n = Database::run("DELETE FROM drivers WHERE full_name LIKE 'Demo Driver %'");
    echo "Removed $n demo drivers (their logins, phones and trips went with them).\n";
    exit(0);
}

foreach ($demo as [$name, $phone, $code]) {
    $id = (int) Database::scalar('SELECT id FROM drivers WHERE phone = :p', ['p' => $phone], 0);
    if ($id === 0) {
        $id = Database::insert('drivers', ['full_name' => $name, 'phone' => $phone, 'role' => 'driver', 'is_active' => 1]);
    }
    LiveFleet::setLogin($id, $code, '1234');
    echo "Driver ID $code  PIN 1234  ($name)\n";
}
$buses = Database::fetchAll('SELECT id, bus_number, bus_name FROM buses WHERE is_active = 1 ORDER BY id LIMIT 3');
echo "\nActive buses: " . implode(', ', array_map(fn($b) => '#' . $b['id'] . ' ' . $b['bus_name'], $buses)) . "\n";
$args = [];
foreach ($buses as $i => $b) { $args[] = '--driver=' . $demo[$i][2] . ':1234:' . $b['id']; }
echo "\nNow run:\n  php tools/fleet-simulator.php --base=" . rtrim(APP_URL, '/') . ' ' . implode(' ', $args) . " --speedup=10\n";
