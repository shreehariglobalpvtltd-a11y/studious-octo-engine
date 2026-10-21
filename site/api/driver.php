<?php
/**
 * POST /api/driver.php — the driver's phone (driver/index.php) talks to
 * the Live Fleet engine here. JSON in, JSON out.
 *
 *   { action:'login',  code:'D101', pin:'1234' }   → { token, kind:'driver', driver, buses, trip:null }
 *   { action:'pair',   code:'482913' }              → { token, kind:'vehicle', bus }   (bus phone, no PIN)
 *   { action:'me' }                                 → { kind, driver, bus, buses, trip, events, open, limit }
 *   { action:'start',  bus_id:3 }                   → { trip }          (a bus phone ignores bus_id)
 *   { action:'ping',   points:[{lat,lng,ts,spd,hdg,acc,alt}…], battery:87,
 *                      meta:{charging:true, net:'4g', visible:true} } → { accepted, … }
 *   { action:'event',  type:'sos'|'breakdown'|'puncture'|'police'|'meal' } → { id }
 *   { action:'clear',  id:12 }                      → the driver closes their own report
 *   { action:'end' }                                → { ended:true }
 *   { action:'logout' }                             → { }
 *
 * Every action except login carries the device token in the
 * X-Driver-Token header. The token is not a cookie, so another site
 * cannot ride on it (no CSRF form needed), and a phone the office
 * revokes in admin/live-setup.php is answered 401 on its next ping.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/livefleet.php';

/** @return array<string,mixed> */
function fleet_trip_out(?array $t): ?array
{
    if ($t === null) {
        return null;
    }
    return [
        'id' => (int) $t['id'], 'bus_id' => (int) $t['bus_id'],
        'bus' => trim((string) ($t['bus_name'] ?? '') . ' ' . (string) ($t['bus_number'] ?? '')),
        'number' => (string) ($t['bus_number'] ?? ''),
        'started' => (int) strtotime((string) $t['started_at']) * 1000,
        'km' => round((float) ($t['distance_km'] ?? 0), 1),
    ];
}

/** @return array<string,mixed> */
function fleet_me(array $device): array
{
    $vehicle = $device['kind'] === 'vehicle';
    $trip = LiveFleet::currentTrip($device);
    // Reports this trip still has open, so a reloaded phone shows its "✓ Solved" buttons again.
    $open = [];
    if ($trip !== null) {
        foreach (Database::fetchAll(
            'SELECT id, kind FROM fleet_alerts WHERE trip_id = :t AND ended_at IS NULL ORDER BY id', ['t' => (int) $trip['id']]
        ) as $a) {
            $ev = LiveFleet::DRIVER_EVENTS[(string) $a['kind']] ?? null;
            if ($ev !== null) {
                $open[] = ['id' => (int) $a['id'], 'type' => (string) $a['kind'], 'icon' => $ev[0], 'ne' => $ev[1]];
            }
        }
    }
    return [
        'kind'   => (string) $device['kind'],
        'driver' => ['id' => (int) ($device['driver_id'] ?? 0), 'name' => (string) ($device['driver_name'] ?? ''), 'code' => (string) ($device['login_code'] ?? '')],
        'bus'    => $vehicle ? ['id' => (int) $device['bus_id'], 'number' => (string) $device['bus_number'], 'name' => (string) $device['bus_name']] : null,
        'buses'  => LiveFleet::busesFor($device),
        'trip'   => fleet_trip_out($trip),
        'open'   => $open,
        'limit'  => max(30, Settings::getInt('fleet_speed_limit', 80)),
        'events' => array_map(static fn(array $e): array => ['type' => $e[0], 'icon' => $e[1][0], 'ne' => $e[1][1], 'en' => $e[1][2]],
            array_map(null, array_keys(LiveFleet::DRIVER_EVENTS), array_values(LiveFleet::DRIVER_EVENTS))),
    ];
}

try {
    Security::requirePost();
    header('Cache-Control: no-store');
    $action = Security::clean((string) Response::field('action', ''), 20);

    if ($action === 'login') {
        $ip   = Security::clientIp();
        $code = LiveFleet::normaliseCode((string) Response::field('code', ''));
        $pin  = Security::digits((string) Response::field('pin', ''), LiveFleet::PIN_MAX);
        Security::requireRateLimit('fleet_login_ip', $ip, 20, 600);
        if ($code !== '') {
            Security::requireRateLimit('fleet_login_code', $code, 10, 900);
        }
        $res = LiveFleet::login($code, $pin, Security::userAgent(), $ip);
        if (!$res['ok']) {
            Response::error((string) $res['error'], 401);
        }
        $device = LiveFleet::device((string) $res['token']);
        Response::success(['token' => $res['token']] + ($device !== null ? fleet_me($device) : ['kind' => 'driver', 'driver' => $res['driver'], 'buses' => [], 'trip' => null]));
    }

    if ($action === 'pair') {
        // A bus phone: the one-time code from Live Fleet Setup → that vehicle number.
        Security::requireRateLimit('fleet_pair_ip', Security::clientIp(), 10, 900);
        $res = LiveFleet::pairVehicle((string) Response::field('code', ''), Security::userAgent(), Security::clientIp());
        if (!$res['ok']) {
            Response::error((string) $res['error'], 401);
        }
        $device = LiveFleet::device((string) $res['token']);
        Response::success(['token' => $res['token']] + ($device !== null ? fleet_me($device) : ['kind' => 'vehicle', 'bus' => $res['bus']]));
    }

    $token  = (string) ($_SERVER['HTTP_X_DRIVER_TOKEN'] ?? Response::field('token', ''));
    $device = LiveFleet::device(strtolower(trim($token)));
    if ($device === null) {
        Response::unauthorized('Please sign in again with your Driver ID. · फेरि लगइन गर्नुहोस्।');
    }

    switch ($action) {
        case 'me':
            Response::success(fleet_me($device));

        case 'start':
            $busId = (int) Response::field('bus_id', 0);
            LiveFleet::startTrip($device, $busId);
            Response::success(['trip' => fleet_trip_out(LiveFleet::currentTrip($device))]);

        case 'ping':
            $points  = Response::field('points', []);
            $battery = Response::field('battery', null);
            $meta    = Response::field('meta', []);
            if (!is_array($points)) {
                Response::invalid(['points' => 'points must be a list.']);
            }
            try {
                $res = LiveFleet::ping($device, array_values($points), is_numeric($battery) ? (int) $battery : null, null, is_array($meta) ? $meta : []);
            } catch (DomainException $e) {
                Response::error('No trip running — tap Start. · यात्रा सुरु गर्नुहोस्।', 409);
            }
            Response::success($res + ['server' => (int) round(microtime(true) * 1000)]);

        case 'event':
            try {
                $id = LiveFleet::driverEvent($device, Security::clean((string) Response::field('type', ''), 20));
            } catch (DomainException $e) {
                Response::error('No trip running — tap Start. · यात्रा सुरु गर्नुहोस्।', 409);
            }
            Response::success(['id' => $id]);

        case 'clear':
            // Only a report of this phone's own running trip.
            $trip = LiveFleet::currentTrip($device);
            $id = (int) Response::field('id', 0);
            $mine = $trip !== null && Database::exists('SELECT 1 FROM fleet_alerts WHERE id = :id AND trip_id = :t', ['id' => $id, 't' => (int) $trip['id']]);
            Response::success(['cleared' => $mine && LiveFleet::resolveAlert($id)]);

        case 'end':
            Response::success(['ended' => LiveFleet::endTrip($device, 'driver')]);

        case 'logout':
            LiveFleet::logout($device);
            Response::success([]);
    }

    Response::error('Unknown action.', 400);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
