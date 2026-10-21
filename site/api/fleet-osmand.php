<?php
/**
 * /api/fleet-osmand.php — background tracking through the free
 * "Traccar Client" app (Android / iPhone).
 *
 * A web page cannot read GPS once the phone screen is locked, so a
 * driver who cannot keep the screen on installs Traccar Client and sets:
 *
 *     Server URL         https://www.shreehariglobal.in/api/fleet-osmand.php
 *     Device identifier  the long code shown under "Background mode" on driver/
 *     Frequency          5 (seconds)   ·   Location accuracy: High
 *
 * Both of the app's formats are understood:
 *   - classic OsmAnd query string: ?id=&lat=&lon=&timestamp=(s)&speed=(knots)&bearing=&accuracy=&batt=
 *   - JSON body (Traccar Client 9+): { device_id, location:{ timestamp, coords:{latitude, longitude, speed(m/s), heading, accuracy}, battery:{level 0..1} } }
 *
 * The device identifier IS the phone's device token, so the same revoke
 * button in admin/live-setup.php cuts it off. With no trip running, the
 * first fix starts one on the bus that phone drove last.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/livefleet.php';

try {
    $json = [];
    if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $json = Response::input();
    }

    if (isset($json['location']) && is_array($json['location'])) {
        $loc   = $json['location'];
        $c     = is_array($loc['coords'] ?? null) ? $loc['coords'] : [];
        $id    = (string) ($json['device_id'] ?? '');
        $ts    = isset($loc['timestamp']) ? strtotime((string) $loc['timestamp']) : false;
        $point = ['lat' => $c['latitude'] ?? null, 'lng' => $c['longitude'] ?? null,
                  'ts' => $ts !== false ? $ts * 1000 : null,
                  'spd' => $c['speed'] ?? null, 'hdg' => $c['heading'] ?? null, 'acc' => $c['accuracy'] ?? null];
        $level = $loc['battery']['level'] ?? null;
        $batt  = is_numeric($level) ? (int) round((float) $level * 100) : null;
    } else {
        $q     = $_GET + $_POST;
        $id    = (string) ($q['id'] ?? $q['deviceid'] ?? '');
        $tsRaw = $q['timestamp'] ?? null;
        $ts    = is_numeric($tsRaw) ? (int) $tsRaw * ((int) $tsRaw > 100000000000 ? 1 : 1000) : (is_string($tsRaw) && strtotime($tsRaw) !== false ? strtotime($tsRaw) * 1000 : null);
        $point = ['lat' => $q['lat'] ?? null, 'lng' => $q['lon'] ?? $q['lng'] ?? null, 'ts' => $ts,
                  // OsmAnd speed is in knots.
                  'spd' => is_numeric($q['speed'] ?? null) ? (float) $q['speed'] / 1.943844 : null,
                  'hdg' => $q['bearing'] ?? $q['heading'] ?? null, 'acc' => $q['accuracy'] ?? $q['hdop'] ?? null];
        $batt  = is_numeric($q['batt'] ?? null) ? (int) round((float) $q['batt']) : null;
    }

    $device = LiveFleet::device(strtolower(trim($id)));
    if ($device === null) {
        http_response_code(401);
        exit;
    }
    if (LiveFleet::currentTrip($device) === null) {
        if (empty($device['bus_id'])) {
            // Nothing to attach the fix to: the driver has never picked a bus on driver/.
            http_response_code(409);
            exit;
        }
        LiveFleet::startTrip($device, (int) $device['bus_id']);
    }
    LiveFleet::ping($device, [$point], $batt);
    http_response_code(200);
} catch (Throwable $e) {
    Logger::warning('fleet osmand rejected', ['err' => $e->getMessage()]);
    http_response_code(400);
}
