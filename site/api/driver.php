<?php
/**
 * POST /api/driver.php — the driver's phone (driver/index.php) talks to
 * the Live Fleet engine here. JSON in, JSON out.
 *
 *   { action:'login',  code:'D101', pin:'1234' }   → { token, driver, buses, trip:null }
 *   { action:'me' }                                 → { driver, buses, trip }
 *   { action:'start',  bus_id:3 }                   → { trip }
 *   { action:'ping',   points:[{lat,lng,ts,spd,hdg,acc}…], battery:87 } → { accepted, … }
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
        'started' => (int) strtotime((string) $t['started_at']) * 1000,
        'km' => round((float) ($t['distance_km'] ?? 0), 1),
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
        Response::success([
            'token'  => $res['token'],
            'driver' => $res['driver'],
            'buses'  => $device !== null ? LiveFleet::busesFor($device) : [],
            'trip'   => null,
        ]);
    }

    $token  = (string) ($_SERVER['HTTP_X_DRIVER_TOKEN'] ?? Response::field('token', ''));
    $device = LiveFleet::device(strtolower(trim($token)));
    if ($device === null) {
        Response::unauthorized('Please sign in again with your Driver ID. · फेरि लगइन गर्नुहोस्।');
    }

    switch ($action) {
        case 'me':
            Response::success([
                'driver' => ['id' => (int) $device['driver_id'], 'name' => (string) $device['driver_name'], 'code' => (string) $device['login_code']],
                'buses'  => LiveFleet::busesFor($device),
                'trip'   => fleet_trip_out(LiveFleet::currentTrip($device)),
            ]);

        case 'start':
            $busId = (int) Response::field('bus_id', 0);
            LiveFleet::startTrip($device, $busId);
            Response::success(['trip' => fleet_trip_out(LiveFleet::currentTrip($device))]);

        case 'ping':
            $points  = Response::field('points', []);
            $battery = Response::field('battery', null);
            if (!is_array($points)) {
                Response::invalid(['points' => 'points must be a list.']);
            }
            try {
                $res = LiveFleet::ping($device, array_values($points), is_numeric($battery) ? (int) $battery : null);
            } catch (DomainException $e) {
                Response::error('No trip running — tap Start. · यात्रा सुरु गर्नुहोस्।', 409);
            }
            Response::success($res + ['server' => (int) round(microtime(true) * 1000)]);

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
