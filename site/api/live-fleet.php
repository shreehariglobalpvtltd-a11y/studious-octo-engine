<?php
/**
 * GET /api/live-fleet.php — every active bus, where it is right now.
 * Polled once a second by admin/live-fleet.php and admin/live-tv.php.
 *
 *   ?trail=<trip id>   also return that trip's recent trail ([lng, lat] pairs)
 *   ?key=<tv key>      a TV wall without a staff login (admin/live-setup.php
 *                      issues the key; driver phone numbers are left out)
 *   ?share=<token>     a passenger's live link (bus-live.php): that one bus only
 *   ?replay=<trip id>  every point of a trip, for the playback slider
 *   ?trips=<bus id>    that bus's recent trips (to pick one to replay)
 *   POST {action:'resolve', id}   mark a driver's SOS / breakdown report dealt with
 *
 * Otherwise a staff session holding schedules.view is required — the same
 * permission as the Live Map.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/livefleet.php';

try {
    /* A passenger's link: only that bus, no login, nothing private. */
    $share = (string) ($_GET['share'] ?? '');
    if ($share !== '') {
        header('X-Robots-Tag: noindex, nofollow');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Security::requireRateLimit('fleet_share', Security::clientIp(), 150, 60);
        Response::success(LiveFleet::shareView(strtolower($share)));
    }

    $tv = LiveFleet::tvKeyMatches((string) ($_GET['key'] ?? ''));
    if (!$tv) {
        $admin = Auth::requireAdmin('schedules.view');
    }

    /* POST { action:'resolve', id } — the office marks a driver's report as dealt with. */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ($tv) {
            Response::forbidden('The TV link is read-only.');
        }
        Security::requireCsrf();
        if ((string) Response::field('action', '') !== 'resolve') {
            Response::error('Unknown action.', 400);
        }
        Response::success(['resolved' => LiveFleet::resolveAlert((int) Response::field('id', 0), (int) ($admin['id'] ?? 0))]);
    }

    // Nothing below writes the session; release its lock so a page polling
    // every second never queues the staff member's other tabs behind it.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    /* ?replay=<trip id> — the whole trip for playback: [ms, lng, lat, km/h, heading] per point. */
    $replay = (int) ($_GET['replay'] ?? 0);
    if ($replay > 0) {
        Response::success(LiveFleet::replay($replay));
    }
    /* ?trips=<bus id> — that bus's recent trips, to choose one to replay. */
    $tripsOf = (int) ($_GET['trips'] ?? 0);
    if ($tripsOf > 0) {
        Response::success(['trips' => LiveFleet::recentTrips($tripsOf)]);
    }

    $snap = LiveFleet::snapshot(true);
    if ($tv) {
        foreach ($snap['buses'] as &$b) {
            if ($b['driver'] !== null) {
                $b['driver']['phone'] = '';
            }
            $b['share'] = null;
        }
        unset($b);
    }

    $tripId = (int) ($_GET['trail'] ?? 0);
    if ($tripId > 0) {
        $snap['trail'] = ['trip' => $tripId, 'points' => LiveFleet::trail($tripId)];
    }

    Response::success($snap);
} catch (Throwable $e) {
    Response::serverError($e);
}
