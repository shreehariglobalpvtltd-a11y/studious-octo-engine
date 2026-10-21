<?php
/**
 * cron/fleet-cleanup.php — Live Fleet housekeeping. Recommended: hourly.
 *
 *   - ends trips whose phone has sent nothing for 6 hours (dead battery,
 *     a driver who forgot to tap End), so the map stops showing them as
 *     "offline" forever
 *   - drops trail points older than Settings → fleet_keep_days (30)
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/livefleet.php';

$ended  = LiveFleet::endSilent(6);
$purged = LiveFleet::purgePoints(max(1, Settings::getInt('fleet_keep_days', 30)));

cron_done(['trips_ended' => $ended, 'points_purged' => $purged]);
