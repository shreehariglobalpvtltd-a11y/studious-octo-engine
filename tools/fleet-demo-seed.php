<?php
/**
 * =====================================================================
 *  tools/fleet-demo-seed.php — three demo drivers for trying Live Fleet
 *  on a TEST copy of the site.
 *
 *    php tools/fleet-demo-seed.php /path/to/site            add DEMO1-3, PIN 1234
 *    php tools/fleet-demo-seed.php /path/to/site --remove   take them away again
 *
 *  Also fills the town / temple cache along NH48 (Surat → Mehsana) with
 *  SAMPLE names and approximate positions, so the Nepali announcements can
 *  be tried where OpenStreetMap (Overpass) cannot be reached. On a server
 *  that can reach it, real names are fetched by themselves — skip this
 *  with --no-places.
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

// [kind, lat, lng, devanagari, english, id] — sample data, positions approximate.
$sample = [
    ['city', 21.1702, 72.8311, 'सुरत', 'Surat'], ['town', 21.2730, 72.9556, 'कामरेज', 'Kamrej'],
    ['town', 21.4630, 72.9590, 'कोसम्बा', 'Kosamba'], ['town', 21.6264, 73.0152, 'अङ्कलेश्वर', 'Ankleshwar'],
    ['city', 21.7051, 72.9959, 'भरुच', 'Bharuch'], ['village', 21.9230, 73.0700, 'पालेज', 'Palej'],
    ['town', 22.0530, 73.1230, 'करजण', 'Karjan'], ['city', 22.3072, 73.1812, 'वडोदरा', 'Vadodara'],
    ['village', 22.4530, 73.0670, 'वासद', 'Vasad'], ['city', 22.5645, 72.9289, 'आनन्द', 'Anand'],
    ['city', 22.6916, 72.8634, 'नडियाद', 'Nadiad'], ['town', 22.8240, 72.7560, 'महेमदावाद', 'Mahemdavad'],
    ['city', 23.0225, 72.5714, 'अहमदाबाद', 'Ahmedabad'], ['city', 23.2156, 72.6369, 'गान्धीनगर', 'Gandhinagar'],
    ['town', 23.2464, 72.4950, 'कलोल', 'Kalol'], ['city', 23.6009, 72.3807, 'मेहसाणा', 'Mehsana'],
    ['temple', 21.2765, 72.9600, 'श्री स्वामिनारायण मन्दिर', 'Shree Swaminarayan Mandir'],
    ['temple', 21.7080, 72.9990, 'श्री अम्बे माता मन्दिर', 'Shree Ambe Mata Mandir'],
    ['temple', 22.0560, 73.1200, 'श्री महादेव मन्दिर', 'Shree Mahadev Mandir'],
    ['temple', 22.3080, 73.1830, 'श्री कृष्ण मन्दिर', 'Shree Krishna Mandir'],
    ['temple', 22.5650, 72.9300, 'श्री हनुमान मन्दिर', 'Shree Hanuman Mandir'],
    ['temple', 22.6935, 72.8619, 'सन्तराम मन्दिर', 'Santram Mandir'],
    ['temple', 23.0297, 72.5075, 'इस्कोन मन्दिर', 'ISKCON Temple'],
    ['temple', 23.2295, 72.6744, 'अक्षरधाम', 'Akshardham'],
    ['temple', 23.2470, 72.4960, 'श्री गणेश मन्दिर', 'Shree Ganesh Mandir'],
];
foreach ($sample as $i => &$it) { $it[] = 'demo-' . $i; }
unset($it);
$cells = [];
foreach ($sample as $it) { $cells[LiveFleet::cellOf($it[1], $it[2])] = true; }
// Every square the simulator's road (tools/fleet-simulator.php) passes, so the bus is never in an empty one.
$road = [[21.1702, 72.8311], [21.2730, 72.9556], [21.4650, 72.9500], [21.6264, 73.0152], [21.7051, 72.9959], [22.0530, 73.1230],
         [22.3072, 73.1812], [22.5645, 72.9289], [22.6916, 72.8634], [23.0225, 72.5714], [23.2156, 72.6369], [23.2464, 72.4950], [23.6009, 72.3807]];
for ($i = 1; $i < count($road); $i++) {
    for ($k = 0; $k <= 30; $k++) {
        $cells[LiveFleet::cellOf($road[$i - 1][0] + ($road[$i][0] - $road[$i - 1][0]) * $k / 30, $road[$i - 1][1] + ($road[$i][1] - $road[$i - 1][1]) * $k / 30)] = true;
    }
}

if (in_array('--remove', $argv, true)) {
    $n = Database::run("DELETE FROM drivers WHERE full_name LIKE 'Demo Driver %'");
    $c = 0;
    foreach (array_keys($cells) as $cell) { $c += Database::delete('fleet_places', 'cell = :c', ['c' => $cell]); }
    echo "Removed $n demo drivers (their logins, phones and trips went with them) and $c sample place squares.\n";
    exit(0);
}

if (!in_array('--no-places', $argv, true)) {
    foreach (array_keys($cells) as $cell) {
        [$s, $w, $n, $e] = LiveFleet::cellBox($cell);
        LiveFleet::storeCell($cell, array_values(array_filter($sample, fn($it) => $it[1] >= $s && $it[1] <= $n && $it[2] >= $w && $it[2] <= $e)));
    }
    echo 'Sample towns + temples cached in ' . count($cells) . " map squares along NH48 (demo data).\n";
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
