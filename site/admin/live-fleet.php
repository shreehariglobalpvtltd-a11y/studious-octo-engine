<?php
/**
 * admin/live-fleet.php — Live Fleet (Oct 2026).
 *
 * Every active bus on one map, each from its own driver's phone
 * (driver/ → api/driver.php), refreshed every second from
 * api/live-fleet.php. Click a bus for its driver, speed, next stop,
 * trail and CCTV cameras. Read-only — nothing here writes.
 *
 * The older Live Map (map.php) still works; it shows the single shared
 * `livebus` position, which the new engine keeps feeding while one bus
 * is live.
 *
 * Permission: schedules.view (same as the Live Map).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/livefleet.php';
$admin = admin_boot('schedules.view');
LiveFleet::allowCameraOrigins();

$canSetup = Auth::can('drivers.edit') || Auth::can('liveops.edit');
$every    = max(1, min(10, (int) ($_GET['every'] ?? 1)));

// Route lines under the buses — the same stops the Live Map draws.
$routes = [];
foreach (Database::fetchAll('SELECT id, route_code FROM routes WHERE is_active = 1 ORDER BY sort_order, id') as $i => $r) {
    $stops = Database::fetchAll(
        "SELECT stop_name, latitude, longitude FROM route_stops
          WHERE route_id = :r AND latitude IS NOT NULL AND longitude IS NOT NULL
          ORDER BY stop_type = 'drop', sort_order, id",
        ['r' => (int) $r['id']]
    );
    $routes[] = ['id' => (int) $r['id'], 'color' => $i % 2 === 0 ? '#2E5FA8' : '#0a6b3b',
        'stops' => array_map(static fn(array $s): array => ['name' => (string) $s['stop_name'], 'lat' => (float) $s['latitude'], 'lng' => (float) $s['longitude']], $stops)];
}

admin_header('Live Fleet', 'live-fleet');
?>
<link rel="stylesheet" href="<?= Security::e(LiveFleet::asset('livefleet.css')) ?>">
<style>
.lfa-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.lfa-top .spacer{flex:1}
.lfa-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;background:var(--card);border:1px solid var(--line);font-weight:700;font-size:13px}
.lfa-chip i{width:10px;height:10px;border-radius:50%}
.lfa-chip b{font-size:15px;font-variant-numeric:tabular-nums}
.lfa-conn{font-size:12.5px;color:var(--mut);display:inline-flex;align-items:center;gap:6px}
.lfa-conn i{width:9px;height:9px;border-radius:50%;background:#16a34a;animation:lfBlink 1.2s ease-in-out infinite}
.lfa-conn.bad i{background:#dc2626;animation:none}
.lfa-btn.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.lfa-wrap{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px;align-items:start}
#lfaMap{height:calc(100vh - 190px);min-height:440px;border-radius:14px;border:1px solid var(--line)}
.lfa-side{display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 190px);overflow:auto}
.lfa-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;flex:0 0 auto}
.lfa-card h3{margin:0;padding:11px 14px;font-size:14px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px}
.lfa-card h3 small{margin-left:auto;font-weight:500;color:var(--mut);font-size:11.5px}
.lfa-det{padding:14px;display:flex;flex-direction:column;gap:10px;font-size:13.5px}
.lfa-det .nm{font-size:18px;font-weight:800;line-height:1.2}
.lfa-det .nm small{display:block;font-size:12.5px;font-weight:600;color:var(--mut)}
.lfa-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.lfa-stat{background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:9px 10px}
.lfa-stat .k{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.05em;font-weight:700}
.lfa-stat .v{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.2}
.lfa-stat .v small{font-size:11.5px;font-weight:600;color:var(--mut)}
.lfa-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lfa-row .muted{font-size:12.5px}
.lfa-acts{display:flex;gap:6px;flex-wrap:wrap}
.lfa-acts .btn{padding:6px 10px;font-size:12.5px}
.lfa-list .lfa-item{display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);cursor:pointer;min-height:56px}
.lfa-list .lfa-item:last-child{border-bottom:0}
.lfa-list .lfa-item:hover{background:var(--hover)}
.lfa-list .lfa-item.sel{background:var(--blue-50);box-shadow:inset 3px 0 0 var(--blue)}
.lfa-item .ic{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:19px;background:var(--soft);flex:0 0 auto;position:relative}
.lfa-item .ic i{position:absolute;right:-2px;bottom:-2px;width:12px;height:12px;border-radius:50%;border:2px solid var(--card)}
.lfa-item .tx{flex:1 1 auto;min-width:0}
.lfa-item .tx b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lfa-item .tx span{display:block;font-size:12px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lfa-item .sp{font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
.lfa-item .sp small{display:block;font-size:11px;font-weight:600;color:var(--mut)}
.lfa-cams{padding:12px 14px}
.lfa-empty{padding:14px;color:var(--mut);font-size:13px}
@media(max-width:1080px){.lfa-wrap{grid-template-columns:1fr}#lfaMap{height:56vh;min-height:340px}.lfa-side{max-height:none}}
</style>

<div class="lfa-top">
  <span class="lfa-chip" title="Phone sent GPS in the last <?= LiveFleet::LIVE_SECONDS ?> s"><i style="background:#16a34a"></i>Live · लाइभ <b id="cLive">0</b></span>
  <span class="lfa-chip" title="Last GPS <?= LiveFleet::LIVE_SECONDS ?> s – <?= LiveFleet::WEAK_SECONDS / 60 ?> min ago"><i style="background:#f59e0b"></i>Weak <b id="cWeak">0</b></span>
  <span class="lfa-chip" title="Trip running but no GPS for more than <?= LiveFleet::WEAK_SECONDS / 60 ?> min"><i style="background:#dc2626"></i>Offline <b id="cOff">0</b></span>
  <span class="lfa-chip"><i style="background:#64748b"></i>Parked <b id="cPark">0</b></span>
  <span class="lfa-conn" id="lfaConn"><i></i><span>connecting…</span></span>
  <span class="spacer"></span>
  <button class="btn ghost lfa-btn" type="button" id="bFit">🗺️ All buses</button>
  <button class="btn ghost lfa-btn" type="button" id="bFollow">🎯 Follow</button>
  <button class="btn ghost lfa-btn on" type="button" id="bTrail">〰️ Trail</button>
  <a class="btn ghost" href="/admin/live-tv.php" target="_blank" rel="noopener">📺 TV wall</a>
  <?php if ($canSetup): ?><a class="btn ghost" href="/admin/live-setup.php" title="Driver logins, phones, CCTV cameras, TV link">⚙️ Setup</a><?php endif; ?>
</div>

<div class="lfa-wrap">
  <div id="lfaMap"></div>
  <aside class="lfa-side">
    <div class="lfa-card"><h3>🚌 Selected bus <small id="dAge"></small></h3>
      <div id="lfaDet" class="lfa-empty">Tap a bus on the map or in the list. · नक्सा वा सूचीमा बस छान्नुहोस्।</div>
    </div>
    <div class="lfa-card"><h3>📢 घोषणा · Announcements <small><button class="btn ghost sm" type="button" id="bVoice">🔇 आवाज बन्द</button></small></h3>
      <ul class="lf-log" id="lfaLog"><li class="muted">Arrivals, next stop km / time and temples on the way appear here (Nepali). Press the button to hear them.</li></ul>
    </div>
    <div class="lfa-card"><h3>📹 Cameras · क्यामेरा <small id="camNote"></small></h3>
      <div class="lfa-cams"><div class="lf-cams" id="lfaCams"></div></div>
    </div>
    <div class="lfa-card"><h3>🚍 All buses · सबै बस <small id="lfaCount"></small></h3>
      <div class="lfa-list" id="lfaList"><div class="lfa-empty">Loading…</div></div>
    </div>
  </aside>
</div>

<script src="<?= Security::e(LiveFleet::asset('livefleet.js')) ?>"></script>
<script>
(function () {
  var LF = window.LiveFleet, esc = LF.esc;
  var ROUTES = <?= Security::jsonForHtml($routes) ?>;
  var EVERY = <?= $every * 1000 ?>;
  var CAN_SETUP = <?= $canSetup ? 'true' : 'false' ?>;
  var state = { buses: [], sel: null, trail: true, first: true, lastOk: 0 };
  try { state.sel = +localStorage.getItem('lf:sel') || null; state.trail = localStorage.getItem('lf:trail') !== '0'; } catch (e) {}

  var fm = new LF.FleetMap(document.getElementById('lfaMap'), {
    onSelect: function (id) { pick(id, true); },
    onFollow: function (on) { document.getElementById('bFollow').classList.toggle('on', on); }
  });
  fm.ready.then(function () { fm.drawRoutes(ROUTES); }).catch(function () {});

  function bus(id) { for (var i = 0; i < state.buses.length; i++) if (state.buses[i].id === id) return state.buses[i]; return null; }
  function tripOf(id) { var b = bus(id); return b && b.trip && b.status !== 'idle' ? b.trip.id : 0; }

  function pick(id, zoom) {
    state.sel = id;
    try { localStorage.setItem('lf:sel', String(id)); } catch (e) {}
    fm.select(id);
    if (zoom) fm.focus(id, 13);
    if (!state.trail || !tripOf(id)) fm.setTrail(null);
    render();
    poller.now();
  }

  /* The stops still ahead: km on the road and time at the bus's average speed. */
  function upcomingHtml(b) {
    var st = b.stop;
    if (!st || !st.upcoming || !st.upcoming.length) return '';
    var now = Date.now();
    return '<div><b>🚏 आउने स्टपहरू</b>' + (st.at ? ' <span class="muted">· अहिले ' + esc(LF.nm(st.near_ne, st.near)) + 'मा</span>' : '')
      + '<table class="lf-up"><tbody>' + st.upcoming.slice(0, 6).map(function (u, i) {
        return '<tr' + (i === 0 ? ' class="next"' : '') + '><td><b>' + esc(LF.nm(u.ne, u.name)) + '</b>' + (u.ne ? '<small>' + esc(u.name) + '</small>' : '') + '</td>'
          + '<td>' + LF.neKm(u.km) + '</td><td>' + LF.neDur(u.min) + '<br><small>' + LF.neClock(now + u.min * 60000) + '</small></td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  function townsHtml(b) {
    if (!b.towns || !b.towns.length) return '';
    var now = Date.now();
    return '<div><b>➡️ आउने ठाउँहरू</b><table class="lf-up"><tbody>' + b.towns.map(function (t, i) {
      return '<tr' + (i === 0 ? ' class="next"' : '') + '><td><b>' + esc(t.name) + '</b>' + (t.en && t.en !== t.name ? '<small>' + esc(t.en) + '</small>' : '') + '</td>'
        + '<td>' + LF.neKm(t.km) + '</td><td>' + LF.neDur(t.min) + '<br><small>' + LF.neClock(now + t.min * 60000) + '</small></td></tr>';
    }).join('') + '</tbody></table></div>';
  }
  function templesHtml(b) {
    if (!b.temples || !b.temples.length) return '';
    return '<div><b>🛕 नजिकैका मन्दिर</b>' + b.temples.map(function (t) {
      return '<div>' + esc(t.name) + (t.en && t.en !== t.name ? ' <span class="muted">' + esc(t.en) + '</span>' : '') + ' · ' + LF.neKm(t.km) + (t.ahead ? ' ⬆️' : '') + '</div>';
    }).join('') + '</div>';
  }

  /* 📢 Nepali announcements for every bus on the road; spoken after 🔊. */
  var tracker = new LF.Tracker();
  var logEl = document.getElementById('lfaLog');
  var voice = new LF.Announcer(function (text, icon) {
    if (logEl.querySelector('.muted')) logEl.innerHTML = '';
    var li = document.createElement('li');
    li.innerHTML = '<span>' + esc(icon || '📢') + '</span><span>' + esc(text) + '</span><time>' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</time>';
    logEl.insertBefore(li, logEl.firstChild);
    while (logEl.children.length > 40) logEl.removeChild(logEl.lastChild);
  });
  var bVoice = document.getElementById('bVoice');
  if (!voice.supported()) { bVoice.disabled = true; bVoice.title = 'This browser cannot speak'; }
  bVoice.onclick = function () {
    var on = voice.enable(!voice.on);
    bVoice.classList.toggle('on', on);
    bVoice.textContent = on ? '🔊 आवाज खुला' : '🔇 आवाज बन्द';
  };

  function render() {
    var b = state.sel != null ? bus(state.sel) : null;
    var det = document.getElementById('lfaDet');
    document.getElementById('dAge').textContent = b && b.age_s != null ? 'GPS ' + LF.ago(b.age_s) : '';
    if (!b) {
      det.className = 'lfa-empty';
      det.innerHTML = 'Tap a bus on the map or in the list. · नक्सा वा सूचीमा बस छान्नुहोस्।';
      LF.renderCams(document.getElementById('lfaCams'), [], '<div class="lf-cam-empty">Pick a bus to see its cameras.</div>');
    } else {
      det.className = 'lfa-det';
      var p = b.pos || {}, moving = b.status === 'live' || b.status === 'weak';
      var tel = b.driver && b.driver.phone ? String(b.driver.phone).replace(/\D/g, '') : '';
      var started = b.trip && b.trip.started ? new Date(b.trip.started) : null;
      det.innerHTML =
        '<div class="lfa-row"><div class="nm">' + esc(b.name) + '<small>' + esc(b.number) + (b.route ? ' · ' + esc(b.route.label) : '') + '</small></div></div>'
        + '<div class="lfa-row">' + LF.pill(b.status, true) + (b.battery != null ? '<span class="muted">🔋 ' + b.battery + '%</span>' : '') + (p.acc != null && moving ? '<span class="muted">±' + Math.round(p.acc) + ' m</span>' : '') + '</div>'
        + '<div class="lfa-stats">'
        + '<div class="lfa-stat"><div class="k">Speed</div><div class="v">' + (moving && p.kmh != null ? Math.round(p.kmh) : '—') + ' <small>km/h</small></div></div>'
        + '<div class="lfa-stat"><div class="k">Driven</div><div class="v">' + (b.trip ? b.trip.km : '—') + ' <small>km</small></div></div>'
        + '<div class="lfa-stat"><div class="k">' + (b.status === 'parked' ? 'Ended' : 'Started') + '</div><div class="v" style="font-size:15px">' + (b.trip ? new Date(b.status === 'parked' && b.trip.ended ? b.trip.ended : b.trip.started).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—') + '</div></div>'
        + '</div>'
        + (b.place ? '<div>📍 अहिले: <b>' + esc(b.place.name) + '</b>' + (b.place.en && b.place.en !== b.place.name ? ' <span class="muted">' + esc(b.place.en) + '</span>' : '')
            + (b.place.at ? '' : ' <span class="muted">(' + LF.neKm(b.place.km) + ')</span>') + '</div>' : '')
        + townsHtml(b)
        + upcomingHtml(b)
        + templesHtml(b)
        + (b.driver ? '<div class="lfa-row">👤 <b>' + esc(b.driver.name) + '</b>' + (started ? '<span class="muted">since ' + esc(started.toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })) + '</span>' : '') + '</div>' : '')
        + '<div class="lfa-acts">'
        + (tel ? '<a class="btn ghost" href="tel:+' + esc(tel) + '">📞 Call driver</a><a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/' + esc(tel) + '">💬 WhatsApp</a>' : '')
        + (b.pos ? '<a class="btn ghost" target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + p.lat + ',' + p.lng + '">🗺️ Google Maps</a>' : '')
        + '<a class="btn ghost" target="_blank" rel="noopener" href="/admin/live-tv.php?bus=' + b.id + '">📺 This bus on TV</a>'
        + '</div>';
      LF.renderCams(document.getElementById('lfaCams'), b.cameras,
        '<div class="lf-cam-empty">No camera on this bus yet. · यो बसमा क्यामेरा छैन।' + (CAN_SETUP ? '<br><a href="/admin/live-setup.php#cams">➕ Add a CCTV camera</a>' : '') + '</div>');
    }
    document.getElementById('camNote').textContent = b && b.cameras.length ? b.cameras.length + ' feed' + (b.cameras.length > 1 ? 's' : '') : '';

    var list = document.getElementById('lfaList');
    if (!state.buses.length) { list.innerHTML = '<div class="lfa-empty">No active buses in the fleet.</div>'; return; }
    list.innerHTML = state.buses.map(function (x) {
      var s = LF.STATUS[x.status] || LF.STATUS.idle, moving = x.status === 'live' || x.status === 'weak';
      var where = x.status === 'parked' ? '' : (x.place ? '📍 ' + x.place.name : '')
        + (x.stop && x.stop.next ? ' → ' + LF.nm(x.stop.next_ne, x.stop.next) + ' ' + LF.neKm(x.stop.next_km) : '');
      if (!where && x.route) where = x.route.label;
      return '<div class="lfa-item' + (x.id === state.sel ? ' sel' : '') + '" data-id="' + x.id + '">'
        + '<span class="ic">🚌<i style="background:' + s.c + '"></i></span>'
        + '<span class="tx"><b>' + esc(x.name) + ' · ' + esc(x.number) + '</b><span>' + esc(s.en) + (x.driver ? ' · ' + esc(x.driver.name) : '') + (where ? ' · ' + esc(where) : '') + '</span></span>'
        + '<span class="sp">' + (moving && x.pos && x.pos.kmh != null ? Math.round(x.pos.kmh) + ' km/h' : '') + '<small>' + (x.age_s != null && x.status !== 'parked' ? esc(LF.ago(x.age_s)) : '') + '</small></span>'
        + '</div>';
    }).join('');
  }

  document.getElementById('lfaList').addEventListener('click', function (e) {
    var el = e.target.closest('.lfa-item');
    if (el) pick(+el.dataset.id, true);
  });
  document.getElementById('bFit').onclick = function () { fm.follow(false); fm.fitAll(60); };
  document.getElementById('bFollow').onclick = function () {
    if (state.sel == null) { alert('Pick a bus first.'); return; }
    fm.follow(!fm.following);
  };
  var bTrail = document.getElementById('bTrail');
  bTrail.classList.toggle('on', state.trail);
  bTrail.onclick = function () {
    state.trail = !state.trail;
    bTrail.classList.toggle('on', state.trail);
    try { localStorage.setItem('lf:trail', state.trail ? '1' : '0'); } catch (e) {}
    if (!state.trail) fm.setTrail(null);
    poller.now();
  };

  var conn = document.getElementById('lfaConn');
  setInterval(function () {
    if (!state.lastOk) return;
    var s = Math.round((Date.now() - state.lastOk) / 1000);
    conn.classList.toggle('bad', s > 6);
    conn.querySelector('span').textContent = s > 6 ? 'connection lost — retrying… · जडान टुट्यो' : 'live · updated ' + (s < 2 ? 'now' : s + ' s ago');
  }, 1000);

  var poller = LF.poll(function () {
    var t = state.trail && state.sel != null ? tripOf(state.sel) : 0;
    return '/api/live-fleet.php' + (t ? '?trail=' + t : '');
  }, EVERY, function (d) {
    state.buses = d.buses;
    state.lastOk = Date.now();
    var c = d.counts;
    document.getElementById('cLive').textContent = c.live;
    document.getElementById('cWeak').textContent = c.weak;
    document.getElementById('cOff').textContent = c.offline + c.waiting;
    document.getElementById('cPark').textContent = c.parked + c.idle;
    document.getElementById('lfaCount').textContent = d.buses.length + ' in fleet';
    fm.ready.then(function () {
      fm.update(d.buses);
      if (state.sel != null) fm.select(state.sel);
      if (d.trail && state.trail && d.trail.trip === tripOf(state.sel)) fm.setTrail(d.trail.points);
      fm.setPois(LF.poisFor(bus(state.sel)));
      if (state.first) {
        state.first = false;
        if (state.sel != null && bus(state.sel) && bus(state.sel).pos) fm.focus(state.sel, 12, 0); else fm.fitAll(60, 0);
      }
    }).catch(function () {});
    tracker.events(d.buses).forEach(function (ev) { voice.say(ev.text, ev.icon); });
    if (d.need_cells && d.need_cells.length) LF.warmCells(d.need_cells);
    render();
  }, function (e) {
    if (e && e.status === 401) { conn.classList.add('bad'); conn.querySelector('span').textContent = 'signed out — reload the page'; }
  });
})();
</script>
<?php admin_footer(); ?>
