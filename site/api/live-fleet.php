<?php
/**
 * GET /api/live-fleet.php — every active bus, where it is right now.
 * Polled once a second by admin/live-fleet.php and admin/live-tv.php.
 *
 *   ?trail=<trip id>   also return that trip's recent trail ([lng, lat] pairs)
 *   ?key=<tv key>      a TV wall without a staff login (admin/live-setup.php
 *                      issues the key; driver phone numbers are left out)
 *
 * Otherwise a staff session holding schedules.view is required — the same
 * permission as the Live Map.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/livefleet.php';

try {
    $tv = LiveFleet::tvKeyMatches((string) ($_GET['key'] ?? ''));
    if (!$tv) {
        Auth::requireAdmin('schedules.view');
    }
    // Nothing below writes the session; release its lock so a page polling
    // every second never queues the staff member's other tabs behind it.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $snap = LiveFleet::snapshot(true);
    if ($tv) {
        foreach ($snap['buses'] as &$b) {
            if ($b['driver'] !== null) {
                $b['driver']['phone'] = '';
            }
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
