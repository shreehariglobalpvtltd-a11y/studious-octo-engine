<?php
/**
 * admin/live-setup.php — Live Fleet setup.
 *
 *   Vehicles (by number plate): the driver assigned to each bus, its bus
 *   phone (📲 pair one with a 6-digit code / QR), who is driving it now.
 *   Driver IDs + PINs, every attached phone, CCTV cameras per bus, the TV
 *   wall link, and when the office is warned (speed limit, long stop).
 *
 * Driver logins / phones / assignments need drivers.edit; cameras, bus
 * phones, alerts and the TV link need liveops.edit. A manager and the
 * super-admin hold both.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/livefleet.php';
$admin = admin_boot('schedules.view');

$canDrivers = Auth::can('drivers.edit');
$canOps     = Auth::can('liveops.edit');
if (!$canDrivers && !$canOps) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
$by     = (int) ($admin['id'] ?? 0);
$flash  = null;
$paired = null;   // a pairing code just made: shown once, big, with its QR

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $driverActions = ['login_save', 'login_remove', 'device_revoke', 'assign'];
            $opsActions    = ['cam_save', 'cam_delete', 'tv_new', 'tv_off', 'pair_code', 'alerts_save'];
            if ((in_array($action, $driverActions, true) && !$canDrivers) || (in_array($action, $opsActions, true) && !$canOps)) {
                throw new RuntimeException('Your role does not allow that change.');
            }
            switch ($action) {
                case 'login_save':
                    $did = (int) ($_POST['driver_id'] ?? 0);
                    $pin = Security::digits((string) ($_POST['pin'] ?? ''), 12);
                    LiveFleet::setLogin($did, (string) ($_POST['code'] ?? ''), $pin, $by);
                    $flash = ['ok', $pin !== ''
                        ? 'Saved. Tell the driver: Driver ID ' . LiveFleet::normaliseCode((string) $_POST['code']) . ', PIN ' . $pin . ' — the PIN cannot be shown again.'
                        : 'Driver ID saved (PIN unchanged).'];
                    break;
                case 'login_remove':
                    LiveFleet::removeLogin((int) ($_POST['driver_id'] ?? 0), $by);
                    $flash = ['ok', 'Phone login removed and the driver\'s phones signed out.'];
                    break;
                case 'device_revoke':
                    LiveFleet::revokeDevice((int) ($_POST['device_id'] ?? 0), $by);
                    $flash = ['ok', 'That phone is signed out. A driver phone logs in again with Driver ID + PIN; a bus phone needs a new pairing code.'];
                    break;
                case 'assign':
                    $did = (int) ($_POST['driver_id'] ?? 0);
                    LiveFleet::assignDriver((int) ($_POST['bus_id'] ?? 0), $did > 0 ? $did : null, $by);
                    $flash = ['ok', $did > 0 ? 'Driver assigned to that vehicle. Its bus phone now shows this driver, and the driver\'s app ticks this bus.' : 'Vehicle has no assigned driver now.'];
                    break;
                case 'pair_code':
                    $pairBus = (int) ($_POST['bus_id'] ?? 0);
                    $paired  = LiveFleet::createPairCode($pairBus, $by) + ['bus_id' => $pairBus];
                    break;
                case 'alerts_save':
                    Settings::set('fleet_speed_limit', max(30, min(150, (int) ($_POST['speed_limit'] ?? 80))), 'int', 'liveops');
                    Settings::set('fleet_stop_alert_min', max(5, min(240, (int) ($_POST['stop_min'] ?? 20))), 'int', 'liveops');
                    $flash = ['ok', 'Alert settings saved.'];
                    break;
                case 'cam_save':
                    LiveFleet::saveCamera((int) ($_POST['cam_id'] ?? 0), (int) ($_POST['bus_id'] ?? 0), (string) ($_POST['name'] ?? ''),
                        (string) ($_POST['kind'] ?? ''), (string) ($_POST['url'] ?? ''), !empty($_POST['active']), $by);
                    $flash = ['ok', 'Camera saved. Open Live Fleet and pick the bus to see it.'];
                    break;
                case 'cam_delete':
                    LiveFleet::deleteCamera((int) ($_POST['cam_id'] ?? 0), $by);
                    $flash = ['ok', 'Camera removed.'];
                    break;
                case 'tv_new':
                    LiveFleet::rotateTvKey($by);
                    $flash = ['ok', 'New TV link made. Any older TV link has stopped working.'];
                    break;
                case 'tv_off':
                    LiveFleet::disableTvKey($by);
                    $flash = ['ok', 'TV link turned off. The TV wall now needs a staff login.'];
                    break;
                default:
                    throw new RuntimeException('Unknown action.');
            }
        } catch (RuntimeException $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$drivers = Database::fetchAll(
    "SELECT d.id, d.full_name, d.phone, d.role, d.is_active, l.login_code, l.locked_until,
            (SELECT COUNT(*) FROM fleet_devices v WHERE v.driver_id = d.id AND v.revoked_at IS NULL) AS phones
       FROM drivers d LEFT JOIN fleet_driver_logins l ON l.driver_id = d.id
      ORDER BY d.is_active DESC, (l.login_code IS NULL), d.full_name"
);
$devices = Database::fetchAll(
    "SELECT v.id, v.kind, v.label, v.created_at, v.last_seen_at, v.last_ip, d.full_name, l.login_code,
            t.id AS trip_id, b.bus_number, b.bus_name, vb.bus_number AS own_number, vb.bus_name AS own_name
       FROM fleet_devices v
       LEFT JOIN drivers d ON d.id = v.driver_id
       LEFT JOIN fleet_driver_logins l ON l.driver_id = v.driver_id
       LEFT JOIN fleet_trips t ON t.device_id = v.id AND t.status = 'running'
       LEFT JOIN buses b ON b.id = t.bus_id
       LEFT JOIN buses vb ON vb.id = v.bus_id AND v.kind = 'vehicle'
      WHERE v.revoked_at IS NULL
      ORDER BY v.kind DESC, v.last_seen_at DESC"
);
$buses   = Database::fetchAll('SELECT id, bus_number, bus_name FROM buses WHERE is_active = 1 ORDER BY id');
// Every vehicle by number: assigned driver, its bus phone, who is driving it now.
$vehicles = Database::fetchAll(
    "SELECT b.id, b.bus_number, b.bus_name, a.driver_id AS assigned_id, ad.full_name AS assigned_name,
            pv.id AS phone_id, pv.label AS phone_label, pv.last_seen_at AS phone_seen,
            t.id AS trip_id, t.last_ping_at, t.last_battery, td.full_name AS driving_name
       FROM buses b
       LEFT JOIN fleet_vehicle_assign a ON a.bus_id = b.id
       LEFT JOIN drivers ad ON ad.id = a.driver_id
       LEFT JOIN fleet_devices pv ON pv.id = (SELECT MAX(x.id) FROM fleet_devices x WHERE x.bus_id = b.id AND x.kind = 'vehicle' AND x.revoked_at IS NULL)
       LEFT JOIN fleet_trips t ON t.bus_id = b.id AND t.status = 'running'
       LEFT JOIN drivers td ON td.id = t.driver_id
      WHERE b.is_active = 1
      ORDER BY b.bus_number"
);
$activeDrivers = array_values(array_filter($drivers, static fn(array $d): bool => (int) $d['is_active'] === 1));
$speedLimit = max(30, Settings::getInt('fleet_speed_limit', 80));
$stopMin    = max(1, Settings::getInt('fleet_stop_alert_min', 20));
$cams    = Database::fetchAll(
    'SELECT c.*, b.bus_number, b.bus_name FROM fleet_cameras c JOIN buses b ON b.id = c.bus_id ORDER BY b.id, c.sort_order, c.id'
);
$editCam = null;
foreach ($cams as $c) {
    if ((int) $c['id'] === (int) ($_GET['cam'] ?? 0)) {
        $editCam = $c;
    }
}
$tvKey    = LiveFleet::tvKey();
$driverUrl = appUrl('/driver/');
$tvUrl    = appUrl('/admin/live-tv.php') . ($tvKey !== '' ? '?key=' . $tvKey : '');

admin_header('Live Fleet Setup', 'live-setup');
?>
<link rel="stylesheet" href="<?= Security::e(LiveFleet::asset('livefleet.css')) ?>">
<style>
.ls-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.ls-box{padding:14px 18px;font-size:13.5px;line-height:1.55}
.ls-qr{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.ls-qr img{width:150px;height:150px;border-radius:12px;border:1px solid var(--line);background:#fff;padding:6px}
.ls-url{font-family:var(--f-mono);font-size:12.5px;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:8px 10px;word-break:break-all;display:block;margin:6px 0}
.ls-steps{margin:8px 0 0;padding-left:20px}
.ls-steps li{margin:3px 0}
.ls-table td,.ls-table th{vertical-align:middle}
.ls-table input[type=text],.ls-table input[type=tel]{width:110px;padding:7px 9px;border:1px solid var(--line-2);border-radius:9px;font:inherit;background:var(--card);color:var(--ink)}
.ls-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ls-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:14px 18px}
.ls-form label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:700;color:var(--ink-2)}
.ls-form input,.ls-form select{padding:9px 10px;border:1px solid var(--line-2);border-radius:10px;font:inherit;font-weight:400;background:var(--card);color:var(--ink)}
.ls-form .wide{grid-column:1/-1}
.ls-tag{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:var(--ok-bg);color:var(--ok)}
.ls-tag.no{background:var(--soft);color:var(--mut)}
.ls-tag.bad{background:var(--bad-bg);color:var(--bad)}
.ls-tag.live{background:#E4F6EC;color:#178A50}
.ls-table select{padding:7px 9px;border:1px solid var(--line-2);border-radius:9px;font:inherit;background:var(--card);color:var(--ink);max-width:220px}
.ls-pair{display:flex;gap:20px;align-items:center;flex-wrap:wrap;padding:16px 18px;background:linear-gradient(90deg,#FFF7EC,#fff);border-bottom:1px solid var(--line)}
.ls-pair .code{font:800 44px/1 var(--f-mono,monospace);letter-spacing:.18em;color:#12264E}
.ls-pair img{width:170px;height:170px;border-radius:12px;border:1px solid var(--line);background:#fff;padding:6px}
.ls-small{font-size:12px;color:var(--mut)}
@media(max-width:960px){.ls-grid{grid-template-columns:1fr}}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<div class="panel" id="vehicles">
  <h2>🚌 Vehicles · गाडी नम्बर अनुसार</h2>
  <?php if ($paired !== null):
      $pairUrl = appUrl('/driver/') . '?pair=' . $paired['code'];
      $pairBus = null;
      foreach ($vehicles as $vv) { if ((int) $vv['id'] === (int) $paired['bus_id']) { $pairBus = $vv; } } ?>
    <div class="ls-pair">
      <img src="<?= Security::e(QrCode::dataUri($pairUrl, 6, 2)) ?>" alt="QR code to pair the bus phone">
      <div style="flex:1;min-width:240px">
        <div style="font-weight:800;font-size:15px">📲 Pair the phone for <?= LiveFleet::plateHtml((string) ($pairBus['bus_number'] ?? '')) ?></div>
        <div class="code"><?= Security::e($paired['code']) ?></div>
        <ol class="ls-steps">
          <li>On the phone that stays in this bus, scan the QR — or open <b><?= Security::e(appUrl('/driver/')) ?></b> → <b>Bus phone</b> → type the code.</li>
          <li>Allow <b>Location</b>. It starts by itself and is tied to this vehicle number.</li>
          <li>Keep it on the charger, screen on. A new code signs the old bus phone out.</li>
        </ol>
        <div class="ls-small">Works once, until <?= Security::e(date('g:i A', (int) ($paired['expires'] / 1000))) ?> (15 minutes). कोड एक पटक मात्र, १५ मिनेटसम्म चल्छ।</div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($vehicles === []): ?>
    <div class="ls-box">No active buses. Add them on the Buses page first.</div>
  <?php else: ?>
  <table class="ls-table">
    <thead><tr><th>Vehicle no. · गाडी नं.</th><th>Assigned driver · तोकिएको ड्राइभर</th><th>Bus phone · बस फोन</th><th>Now · अहिले</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $vv): ?>
      <tr>
        <td><?= LiveFleet::plateHtml((string) $vv['bus_number']) ?><div class="ls-small"><?= Security::e((string) $vv['bus_name']) ?></div></td>
        <td>
          <?php if ($canDrivers): ?>
          <form method="post" class="ls-inline">
            <?= Security::csrfField() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="bus_id" value="<?= (int) $vv['id'] ?>">
            <select name="driver_id" onchange="this.form.submit()" aria-label="Assigned driver">
              <option value="0">— none · छैन —</option>
              <?php foreach ($activeDrivers as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $vv['assigned_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= Security::e((string) $d['full_name'] . ($d['login_code'] !== null ? ' · ' . $d['login_code'] : '')) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn ghost sm" type="submit">Save</button></noscript>
          </form>
          <?php else: ?>
            <?= Security::e((string) ($vv['assigned_name'] ?? '—')) ?>
          <?php endif; ?>
        </td>
        <td><?php if ($vv['phone_id'] !== null): ?>
            <span class="ls-tag">✓ paired</span>
            <div class="ls-small"><?= Security::e((string) ($vv['phone_label'] ?? '')) ?> · <?= $vv['phone_seen'] !== null ? Security::e(timeAgo((string) $vv['phone_seen'])) : 'not seen yet' ?></div>
          <?php else: ?><span class="ls-tag no">none · छैन</span><?php endif; ?></td>
        <td><?php if ($vv['trip_id'] !== null): ?>
            <span class="ls-tag live">● on trip</span>
            <div class="ls-small"><?= Security::e((string) ($vv['driving_name'] ?? 'bus phone')) ?><?= $vv['last_ping_at'] !== null ? ' · GPS ' . Security::e(timeAgo((string) $vv['last_ping_at'])) : '' ?><?= $vv['last_battery'] !== null ? ' · 🔋' . (int) $vv['last_battery'] . '%' : '' ?></div>
          <?php else: ?><span class="muted">parked · पार्क</span><?php endif; ?></td>
        <td class="ls-inline">
          <?php if ($canOps): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="pair_code"><input type="hidden" name="bus_id" value="<?= (int) $vv['id'] ?>">
            <button class="btn ghost sm" type="submit" <?= $vv['phone_id'] !== null ? 'onclick="return confirm(\'Pair a new phone? The current bus phone is signed out when the new one connects.\')"' : '' ?>>📲 <?= $vv['phone_id'] !== null ? 'New phone' : 'Pair a phone' ?></button>
          </form>
          <?php endif; ?>
          <a class="btn ghost sm" href="/admin/live-fleet.php?bus=<?= (int) $vv['id'] ?>">🗺 Live</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="ls-box muted" style="font-size:12.5px">
    <b>Two ways to track a vehicle:</b> a <b>bus phone</b> (a phone that stays in the bus, paired once to its number — best: it tracks whoever drives) or the <b>driver's own phone</b> (Driver ID + PIN below; the driver picks the bus — the assigned one is ticked).
    गाडीमा सधैं रहने <b>बस फोन</b> (एक पटक जोडेपछि आफैं चल्छ) वा ड्राइभरको आफ्नै फोन (आईडी + पिन)।
  </div>
  <?php endif; ?>
</div>

<div class="ls-grid">
  <div class="panel">
    <h2>📱 Driver phone app · ड्राइभर एप</h2>
    <div class="ls-box ls-qr">
      <img src="<?= Security::e(QrCode::dataUri($driverUrl, 6, 2)) ?>" alt="QR code for the driver app">
      <div style="flex:1;min-width:220px">
        Scan with the driver's phone, or open:
        <a class="ls-url" href="<?= Security::e($driverUrl) ?>" target="_blank" rel="noopener"><?= Security::e($driverUrl) ?></a>
        <ol class="ls-steps">
          <li>Give the driver a <b>Driver ID + PIN</b> below — or pair a <b>bus phone</b> in Vehicles above.</li>
          <li>Driver opens the link → logs in → picks the bus (the assigned one is ticked) → <b>Start trip</b>.</li>
          <li>Phone on the charger, screen on. The bus appears on <a href="/admin/live-fleet.php">Live Fleet</a> within seconds.</li>
        </ol>
      </div>
    </div>
  </div>
  <div class="panel">
    <h2>📺 TV wall link</h2>
    <div class="ls-box">
      <?php if ($tvKey !== ''): ?>
        Open this on the office TV (no login needed — keep it private):
        <code class="ls-url" id="tvUrl"><?= Security::e($tvUrl) ?></code>
        <div class="ls-inline">
          <button class="btn ghost" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('tvUrl').textContent).then(function(){alert('Copied')})">📋 Copy</button>
          <a class="btn ghost" href="<?= Security::e($tvUrl) ?>" target="_blank" rel="noopener">▶ Open</a>
          <a class="btn ghost" href="<?= Security::e($tvUrl) ?>&amp;layout=grid" target="_blank" rel="noopener">▦ Grid</a>
          <?php if ($canOps): ?>
          <form method="post" style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="tv_new"><button class="btn ghost" type="submit" onclick="return confirm('Make a new link? The TV using the old link will stop.')">🔄 New link</button></form>
          <form method="post" style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="tv_off"><button class="btn ghost danger" type="submit">Turn off</button></form>
          <?php endif; ?>
        </div>
      <?php else: ?>
        Staff can open the TV wall after signing in: <a href="/admin/live-tv.php" target="_blank" rel="noopener">/admin/live-tv.php</a>.<br>
        For a TV that should not hold a staff login, make a private link:
        <?php if ($canOps): ?>
          <form method="post" style="margin-top:8px"><?= Security::csrfField() ?><input type="hidden" name="action" value="tv_new"><button class="btn primary" type="submit">➕ Make TV link</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <p class="muted" style="margin:10px 0 0;font-size:12.5px">Layouts: one bus at a time (rotates every 20 s), grid, the whole fleet on one map, or a station-style departure board (&amp;layout=board). Keys: ← → next bus · Space pause · 1–4 layout · F full screen.</p>
    </div>
    <h2 style="border-top:1px solid var(--line)">🚨 Alerts · सचेतना</h2>
    <?php if ($canOps): ?>
    <form method="post" class="ls-form">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="alerts_save">
      <label>Speed limit · गति सीमा (km/h)<input type="number" name="speed_limit" min="30" max="150" value="<?= $speedLimit ?>"></label>
      <label>Long stop after · रोकिएको (minutes)<input type="number" name="stop_min" min="5" max="240" value="<?= $stopMin ?>"></label>
      <div class="wide ls-inline"><button class="btn primary" type="submit">💾 Save</button>
        <span class="muted" style="font-size:12.5px">Over the limit for 15 s → red alert on Live Fleet + TV, spoken in Nepali; the driver's phone also says it. A running bus standing still this long → amber alert.</span></div>
    </form>
    <?php else: ?>
    <div class="ls-box">Speed limit <?= $speedLimit ?> km/h · long stop after <?= $stopMin ?> min.</div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h2>🪪 Driver logins · ड्राइभर लगइन</h2>
  <?php if ($drivers === []): ?>
    <div class="ls-box">No drivers yet. Add them on <a href="/admin/drivers.php">Drivers &amp; Crew</a> first.</div>
  <?php else: ?>
  <table class="ls-table no-card">
    <thead><tr><th>Driver</th><th>Phone login</th><th>Driver ID</th><th>New PIN (<?= LiveFleet::PIN_MIN ?>–<?= LiveFleet::PIN_MAX ?> digits)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $has = $d['login_code'] !== null; $locked = $has && !empty($d['locked_until']) && strtotime((string) $d['locked_until']) > time(); ?>
      <tr<?= (int) $d['is_active'] !== 1 ? ' style="opacity:.55"' : '' ?>>
        <td><b><?= Security::e((string) $d['full_name'] ?: '(no name)') ?></b><div class="muted" style="font-size:12px"><?= Security::e(ucfirst((string) $d['role'])) ?> · <?= Security::e((string) ($d['phone'] ?? '')) ?><?= (int) $d['is_active'] !== 1 ? ' · inactive' : '' ?></div></td>
        <td><?php if ($has): ?><span class="ls-tag">✓ <?= (int) $d['phones'] ?> phone<?= (int) $d['phones'] === 1 ? '' : 's' ?></span><?= $locked ? ' <span class="ls-tag bad">locked (wrong PINs)</span>' : '' ?><?php else: ?><span class="ls-tag no">not set</span><?php endif; ?></td>
        <?php if ($canDrivers): ?>
        <td colspan="2">
          <form method="post" class="ls-inline">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="login_save">
            <input type="hidden" name="driver_id" value="<?= (int) $d['id'] ?>">
            <input type="text" name="code" maxlength="20" required value="<?= Security::e((string) ($d['login_code'] ?? LiveFleet::suggestCode((int) $d['id']))) ?>" aria-label="Driver ID" style="text-transform:uppercase">
            <input type="tel" name="pin" inputmode="numeric" pattern="\d{<?= LiveFleet::PIN_MIN ?>,<?= LiveFleet::PIN_MAX ?>}" maxlength="<?= LiveFleet::PIN_MAX ?>" placeholder="<?= $has ? 'keep current' : 'e.g. 4821' ?>" <?= $has ? '' : 'required' ?> aria-label="PIN">
            <button class="btn ghost sm" type="button" onclick="this.previousElementSibling.value=String(Math.floor(1000+Math.random()*9000))">🎲</button>
            <button class="btn ok sm" type="submit"><?= $has ? '💾 Save' : '➕ Give login' ?></button>
          </form>
        </td>
        <td>
          <?php if ($has): ?>
          <form method="post" onsubmit="return confirm('Remove the phone login? Their phones are signed out.')">
            <?= Security::csrfField() ?><input type="hidden" name="action" value="login_remove"><input type="hidden" name="driver_id" value="<?= (int) $d['id'] ?>">
            <button class="btn ghost danger sm" type="submit">Remove</button>
          </form>
          <?php endif; ?>
        </td>
        <?php else: ?>
        <td colspan="3"><b><?= Security::e((string) ($d['login_code'] ?? '—')) ?></b></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="ls-box muted" style="font-size:12.5px">A new PIN signs out every phone of that driver — use it when a phone is lost. After <?= LiveFleet::MAX_FAILS ?> wrong PINs the login locks for <?= LiveFleet::LOCK_MINUTES ?> minutes. The driver may also type their mobile number instead of the Driver ID.</div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>📲 Attached phones · जोडिएका फोन</h2>
  <?php if ($devices === []): ?>
    <div class="ls-box muted">No phone is signed in yet.</div>
  <?php else: ?>
  <table class="ls-table">
    <thead><tr><th>Who · कसको</th><th>Phone</th><th>Signed in</th><th>Last seen</th><th>Now driving</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $v): ?>
      <tr>
        <td><?php if ($v['kind'] === 'vehicle'): ?>🚌 Bus phone · <?= LiveFleet::plateHtml((string) ($v['own_number'] ?? '')) ?>
            <?php else: ?>👤 <b><?= Security::e((string) ($v['full_name'] ?? '')) ?></b> <span class="muted"><?= Security::e((string) ($v['login_code'] ?? '')) ?></span><?php endif; ?></td>
        <td><?= Security::e((string) ($v['label'] ?? '')) ?></td>
        <td><?= Security::e(formatDate((string) $v['created_at'], 'j M, g:i A')) ?></td>
        <td><?= $v['last_seen_at'] !== null ? Security::e(timeAgo((string) $v['last_seen_at'])) : '—' ?></td>
        <td><?= $v['trip_id'] !== null ? '🚌 ' . Security::e((string) $v['bus_name'] . ' ' . (string) $v['bus_number']) : '<span class="muted">—</span>' ?></td>
        <td><?php if ($canDrivers): ?>
          <form method="post" onsubmit="return confirm('Sign this phone out? Its running trip ends. · यो फोन हटाउने?')">
            <?= Security::csrfField() ?><input type="hidden" name="action" value="device_revoke"><input type="hidden" name="device_id" value="<?= (int) $v['id'] ?>">
            <button class="btn ghost danger sm" type="submit">Sign out</button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel" id="cams">
  <h2>📹 CCTV cameras · सीसी क्यामेरा</h2>
  <?php if ($canOps): ?>
  <form method="post" class="ls-form">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="cam_save">
    <input type="hidden" name="cam_id" value="<?= (int) ($editCam['id'] ?? 0) ?>">
    <label>Bus
      <select name="bus_id" required>
        <?php foreach ($buses as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= (int) ($editCam['bus_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= Security::e($b['bus_name'] . ' · ' . $b['bus_number']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Camera name <input type="text" name="name" maxlength="60" placeholder="Front road / Driver / Cabin" value="<?= Security::e((string) ($editCam['name'] ?? '')) ?>"></label>
    <label>Type
      <select name="kind">
        <?php foreach (LiveFleet::CAMERA_KINDS as $k => $label): ?>
          <option value="<?= $k ?>" <?= ($editCam['kind'] ?? 'hls') === $k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="justify-content:flex-end"><span><input type="checkbox" name="active" value="1" <?= $editCam === null || (int) $editCam['is_active'] === 1 ? 'checked' : '' ?>> Show on Live Fleet &amp; TV</span></label>
    <label class="wide">Stream link (https://…)
      <input type="url" name="url" maxlength="500" required placeholder="https://cam.example.com/bus1-front/index.m3u8" value="<?= Security::e((string) ($editCam['url'] ?? '')) ?>">
    </label>
    <div class="wide ls-inline">
      <button class="btn primary" type="submit"><?= $editCam ? '💾 Save camera' : '➕ Add camera' ?></button>
      <?php if ($editCam): ?><a class="btn ghost" href="/admin/live-setup.php#cams">Cancel</a><?php endif; ?>
      <span class="muted" style="font-size:12.5px">Bus DVRs usually give RTSP (rtsp://…), which browsers cannot play — run it through a small gateway (MediaMTX or go2rtc) that serves HLS over https, then paste that link here. Step-by-step: docs/CCTV.md.</span>
    </div>
  </form>
  <?php endif; ?>
  <?php if ($cams === []): ?>
    <div class="ls-box muted">No cameras yet. The system is ready — add a camera link when the bus CCTV is installed.</div>
  <?php else: ?>
  <table class="ls-table">
    <thead><tr><th>Bus</th><th>Camera</th><th>Type</th><th>Link</th><th>Shown</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cams as $c): ?>
      <tr>
        <td><?= Security::e($c['bus_name'] . ' · ' . $c['bus_number']) ?></td>
        <td><b><?= Security::e((string) $c['name']) ?></b></td>
        <td><?= Security::e(strtoupper((string) $c['kind'])) ?></td>
        <td style="max-width:320px;word-break:break-all;font-family:var(--f-mono);font-size:12px"><?= Security::e((string) $c['url']) ?></td>
        <td><?= (int) $c['is_active'] === 1 ? '<span class="ls-tag">yes</span>' : '<span class="ls-tag no">hidden</span>' ?></td>
        <td class="ls-inline">
          <?php if ($canOps): ?>
          <a class="btn ghost sm" href="/admin/live-setup.php?cam=<?= (int) $c['id'] ?>#cams">Edit</a>
          <form method="post" onsubmit="return confirm('Remove this camera?')"><?= Security::csrfField() ?><input type="hidden" name="action" value="cam_delete"><input type="hidden" name="cam_id" value="<?= (int) $c['id'] ?>"><button class="btn ghost danger sm" type="submit">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
