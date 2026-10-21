<?php
/**
 * POST /api/fleet-places.php — fetch town / village / Hindu temple names for
 * map squares a bus has just driven into (OpenStreetMap via Overpass).
 *
 *   ?cells=118:361,118:362   (from api/live-fleet.php → need_cells)
 *   ?key=<tv key>            a TV wall without a staff login
 *
 * The live map never waits on this: it lists the squares it is missing and
 * the page asks here in the background. At most two squares per call, one
 * fetch per square however many screens ask (LiveFleet::fetchCell claims it).
 *
 * → { cells: { "118:361": "ok" | "pending" | "error" | "cached" } }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/livefleet.php';

try {
    Security::requirePost();
    if (!LiveFleet::tvKeyMatches((string) ($_GET['key'] ?? ''))) {
        Auth::requireAdmin('schedules.view');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    Security::requireRateLimit('fleet_places', Security::clientIp(), 30, 60);
    @set_time_limit(90);

    $asked = array_slice(array_filter(array_map('trim', explode(',', (string) Response::field('cells', '')))), 0, 2);
    $out = [];
    foreach ($asked as $cell) {
        if (preg_match('/^-?\d{1,4}:-?\d{1,4}$/', $cell) !== 1) {
            continue;
        }
        $have = LiveFleet::cells([$cell])[$cell] ?? null;
        $fresh = $have !== null && $have['status'] === 'ok' && $have['at'] > time() - LiveFleet::CELL_KEEP_DAYS * 86400;
        $out[$cell] = $fresh ? 'cached' : LiveFleet::fetchCell($cell);
    }
    Response::success(['cells' => $out]);
} catch (Throwable $e) {
    Response::serverError($e);
}
