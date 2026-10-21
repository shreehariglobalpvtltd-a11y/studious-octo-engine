<?php
/**
 * admin/live-install.php — install Live Fleet from the browser (Oct 2026).
 *
 * For hosting without a terminal: upload the files (File Manager / FTP),
 * open https://<site>/admin/live-install.php as the super-admin and press
 * the buttons. Each step says whether it is done; doing a step twice
 * changes nothing. The PHP files it edits (admin/_guard.php for the menu,
 * admin/map.php for the Live Map) get a backup copy first and are parsed
 * before they are saved (includes/livefleet-install.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/livefleet-install.php';
$admin = admin_boot('');
if (!Auth::isSuperadmin()) {
    http_response_code(403);
    exit('Only the super-admin can install Live Fleet.');
}

$root = ROOT_PATH;
$log  = [];   // [ok|bad, text]
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $log[] = ['bad', 'Session expired — please try again.'];
    } else {
        $step = (string) ($_POST['step'] ?? '');
        $steps = $step === 'all' ? ['db', 'menu', 'map'] : [$step];
        foreach ($steps as $s) {
            try {
                if ($s === 'db') {
                    $r = LiveFleetInstall::runSql(Database::pdo(), $root);
                    $log[] = [$r['errors'] === [] ? 'ok' : 'bad', 'Database: ' . $r['ok'] . ' statements done' . ($r['errors'] !== [] ? ', ' . count($r['errors']) . ' failed:' : '.')];
                    foreach ($r['errors'] as $e) {
                        $log[] = ['bad', $e];
                    }
                    Logger::audit('fleet.install.db', 'system', 'livefleet', null, ['ok' => $r['ok'], 'errors' => count($r['errors'])]);
                } elseif ($s === 'menu') {
                    $log[] = ['ok', LiveFleetInstall::patchMenu($root)];
                } elseif ($s === 'map') {
                    $log[] = ['ok', LiveFleetInstall::patchMap($root)];
                }
            } catch (Throwable $e) {
                $log[] = ['bad', $e->getMessage()];
            }
        }
    }
}

$missingFiles  = LiveFleetInstall::missingFiles($root);
$missingSchema = [];
try {
    $missingSchema = LiveFleetInstall::missingSchema(Database::pdo());
} catch (Throwable $e) {
    $missingSchema = ['(could not read the database: ' . $e->getMessage() . ')'];
}
$menuDone = LiveFleetInstall::menuDone($root);
$mapDone  = LiveFleetInstall::mapDone($root);
$allDone  = $missingFiles === [] && $missingSchema === [] && $menuDone && $mapDone;
$cronLine = '17 * * * * php ' . $root . '/cron/fleet-cleanup.php >/dev/null 2>&1';

admin_header('Install Live Fleet', 'live-setup');
?>
<style>
.li-step{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 18px;border-top:1px solid var(--line)}
.li-step:first-of-type{border-top:0}
.li-step .n{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:var(--soft);color:var(--mut)}
.li-step.done .n{background:var(--ok-bg);color:var(--ok)}
.li-step b{display:block}
.li-step small{color:var(--mut);font-size:12.5px;display:block;margin-top:2px}
.li-log{padding:12px 18px;font-size:13px;line-height:1.6}
.li-log div.ok::before{content:'✓ ';color:var(--ok);font-weight:800}
.li-log div.bad{color:var(--bad)}
.li-log div.bad::before{content:'✗ ';font-weight:800}
.li-code{font-family:var(--f-mono);font-size:12.5px;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:8px 10px;word-break:break-all;display:block;margin:6px 0}
</style>

<?php if ($log !== []): ?>
<div class="panel"><h2>What happened</h2><div class="li-log">
  <?php foreach ($log as $l): ?><div class="<?= $l[0] ?>"><?= Security::e($l[1]) ?></div><?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="panel">
  <h2>🚌 Install Live Fleet · लाइभ फ्लीट जडान</h2>
  <div class="li-step <?= $missingFiles === [] ? 'done' : '' ?>">
    <span class="n"><?= $missingFiles === [] ? '✓' : '1' ?></span>
    <div><b>Files uploaded · फाइल अपलोड</b>
      <small><?= $missingFiles === [] ? 'All ' . count(LiveFleetInstall::FILES) . ' files are in place.' : 'Still missing: ' . Security::e(implode(', ', $missingFiles)) . ' — upload the folder 1-upload-to-public_html again.' ?></small></div>
    <span></span>
  </div>
  <div class="li-step <?= $missingSchema === [] ? 'done' : '' ?>">
    <span class="n"><?= $missingSchema === [] ? '✓' : '2' ?></span>
    <div><b>Database · डाटाबेस</b>
      <small><?= $missingSchema === [] ? 'All 10 Live Fleet tables are there.' : 'To add: ' . Security::e(implode(', ', $missingSchema)) . '. Only new tables / columns — nothing existing changes.' ?></small></div>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="db"><button class="btn <?= $missingSchema === [] ? 'ghost' : 'primary' ?>" type="submit"><?= $missingSchema === [] ? 'Run again' : 'Run' ?></button></form>
  </div>
  <div class="li-step <?= $menuDone ? 'done' : '' ?>">
    <span class="n"><?= $menuDone ? '✓' : '3' ?></span>
    <div><b>Menu · मेनु</b>
      <small><?= $menuDone ? 'Map → Live Fleet · TV Wall · Vehicles &amp; Drivers are in the menu.' : 'Adds Live Fleet · TV Wall · Vehicles &amp; Drivers under Map in admin/_guard.php (a backup is kept).' ?></small></div>
    <?php if (!$menuDone): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="menu"><button class="btn primary" type="submit">Add</button></form><?php else: ?><span></span><?php endif; ?>
  </div>
  <div class="li-step <?= $mapDone ? 'done' : '' ?>">
    <span class="n"><?= $mapDone ? '✓' : '4' ?></span>
    <div><b>Live Map shows the fleet · पुरानो नक्सामा पनि</b>
      <small><?= $mapDone ? 'The old Live Map (admin/map.php) shows every fleet bus in Google-like colours.' : 'Optional: every fleet bus on the old Live Map too (admin/map.php, a backup is kept).' ?></small></div>
    <?php if (!$mapDone): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="map"><button class="btn primary" type="submit">Add</button></form><?php else: ?><span></span><?php endif; ?>
  </div>
  <?php if (!$allDone && $missingFiles === []): ?>
  <div class="li-step"><span class="n">⚡</span><div><b>Everything at once · सबै एकै पटक</b><small>Steps 2, 3 and 4 in one go.</small></div>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="all"><button class="btn ok" type="submit">Install all</button></form></div>
  <?php endif; ?>
  <div class="li-step">
    <span class="n">5</span>
    <div><b>Hourly cleanup (hPanel → Advanced → Cron Jobs)</b>
      <small>Ends trips whose phone went silent, deletes GPS points older than <?= max(1, Settings::getInt('fleet_keep_days', 30)) ?> days. Add this once:</small>
      <code class="li-code"><?= Security::e($cronLine) ?></code></div>
    <span></span>
  </div>
</div>

<?php if ($allDone): ?>
<div class="panel"><h2>✅ Ready · तयार</h2><div class="li-log">
  Next: <a href="/admin/live-setup.php"><b>Vehicles &amp; Drivers</b></a> → give a driver a Driver ID + PIN, or 📲 pair a bus phone to a vehicle number →
  open <a href="/admin/live-fleet.php"><b>Live Fleet</b></a> and the <a href="/admin/live-tv.php" target="_blank" rel="noopener"><b>TV Wall</b></a>.
</div></div>
<?php endif; ?>
<?php admin_footer(); ?>
