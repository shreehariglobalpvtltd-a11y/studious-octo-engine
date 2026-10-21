<?php
/**
 * admin/live-fleet.php — Live Fleet (Oct 2026).
 *
 * Every active bus on one map by vehicle number, each from its own phone
 * (driver/ → api/driver.php), refreshed every second from
 * api/live-fleet.php. Built on what the best trackers do:
 *
 *   - the white SHG coach seen from above, turning with its heading (Uber)
 *   - click a bus → photo card with its number plate (Flightradar24)
 *   - stop-by-stop running status, timetable vs real, minutes late (NTES)
 *   - phone health and alerts: overspeed, long stop, no signal, SOS (Samsara)
 *   - replay a night's trip on the map (Flightradar24 / Traccar)
 *   - the passenger's own live link (redBus / Uber "share trip")
 *   - Nepali announcements, spoken after one tap on 🔊
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
    $routes[] = ['id' => (int) $r['id'], 'color' => $i % 2 === 0 ? '#2E5FA8' : '#0a6b3b',
        'stops' => array_map(static fn(array $s): array => ['name' => $s['name'], 'lat' => $s['lat'], 'lng' => $s['lng']], LiveFleet::routeStops((int) $r['id']))];
}

admin_header('Live Fleet', 'live-fleet');
?>
<link rel="stylesheet" href="<?= Security::e(LiveFleet::asset('livefleet.css')) ?>">
<style>
.lfa-top [hidden],.lfa-mapbox [hidden],.lfa-card [hidden]{display:none!important}
.lfa-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.lfa-top .spacer{flex:1}
.lfa-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;background:var(--card);border:1px solid var(--line);font-weight:700;font-size:13px}
.lfa-chip i{width:10px;height:10px;border-radius:50%}
.lfa-chip b{font-size:15px;font-variant-numeric:tabular-nums}
.lfa-chip.red{background:#FBE3E3;border-color:#f3b4b4;color:#8a1f1f}
.lfa-conn{font-size:12.5px;color:var(--mut);display:inline-flex;align-items:center;gap:6px}
.lfa-conn i{width:9px;height:9px;border-radius:50%;background:#16a34a;animation:lfBlink 1.2s ease-in-out infinite}
.lfa-conn.bad i{background:#dc2626;animation:none}
.lfa-btn.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.lfa-search{padding:8px 12px;border:1px solid var(--line-2);border-radius:10px;font:inherit;min-width:220px;background:var(--card);color:var(--ink)}
.lfa-wrap{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:14px;align-items:start}
.lfa-mapbox{position:relative}
#lfaMap{height:calc(100vh - 190px);min-height:440px;border-radius:14px;border:1px solid var(--line)}
.lfa-side{display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 190px);overflow:auto}
.lfa-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;flex:0 0 auto}
.lfa-card h3{margin:0;padding:11px 14px;font-size:14px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px}
.lfa-card h3 small{margin-left:auto;font-weight:500;color:var(--mut);font-size:11.5px;display:flex;gap:6px;align-items:center}
/* the selected bus: photo card like Flightradar24 */
.lfa-hero{position:relative;height:150px;background:linear-gradient(160deg,#eaf0f8 0%,#f8fafc 55%,#dfe7f2 100%);overflow:hidden}
.lfa-hero img.bus{position:absolute;right:-12px;bottom:-6px;height:150px;filter:drop-shadow(0 10px 14px rgba(18,38,78,.28))}
.lfa-hero img.crest{position:absolute;left:12px;top:10px;height:52px}
.lfa-hero .plate{position:absolute;left:12px;bottom:12px}
.lfa-hero .st{position:absolute;right:10px;top:10px}
:root[data-theme="dark"] .lfa-hero{background:linear-gradient(160deg,#1a2a46 0%,#111d33 60%,#0f1a2e 100%)}
.lfa-det{padding:12px 14px;display:flex;flex-direction:column;gap:10px;font-size:13.5px}
.lfa-det .nm{font-size:17px;font-weight:800;line-height:1.25}
.lfa-det .nm small{display:block;font-size:12.5px;font-weight:600;color:var(--mut)}
.lfa-head{font-size:13px;line-height:1.5;font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:8px 10px}
.lfa-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.lfa-stat{background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:7px 8px}
.lfa-stat .k{font-size:10.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;font-weight:700}
.lfa-stat .v{font-size:18px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.2}
.lfa-stat .v small{font-size:11px;font-weight:600;color:var(--mut)}
.lfa-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lfa-row .muted{font-size:12.5px}
.lfa-acts{display:flex;gap:6px;flex-wrap:wrap}
.lfa-acts .btn{padding:6px 10px;font-size:12.5px}
.lfa-det details{border-top:1px solid var(--line);padding-top:6px}
.lfa-det summary{cursor:pointer;font-weight:800;font-size:13.5px;padding:4px 0}
.lfa-list .lfa-item{display:flex;gap:10px;align-items:center;padding:9px 14px;border-bottom:1px solid var(--line);cursor:pointer;min-height:58px}
.lfa-list .lfa-item:last-child{border-bottom:0}
.lfa-list .lfa-item:hover{background:var(--hover)}
.lfa-list .lfa-item.sel{background:var(--blue-50);box-shadow:inset 3px 0 0 var(--blue)}
.lfa-item .dot{width:12px;height:12px;border-radius:50%;flex:0 0 auto;box-shadow:0 0 0 3px color-mix(in srgb,currentColor 18%,transparent)}
.lfa-item .tx{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:4px}
.lfa-item .tx span{display:block;font-size:12px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
.lfa-item .sp{font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
.lfa-item .sp small{display:block;font-size:11px;font-weight:600;color:var(--mut)}
.lfa-item .al{font-size:15px}
.lfa-cams{padding:12px 14px}
.lfa-empty{padding:14px;color:var(--mut);font-size:13px}
/* replay bar over the map */
.lfa-rp{position:absolute;left:12px;right:12px;bottom:12px;z-index:5;background:rgba(15,23,42,.92);color:#fff;border-radius:14px;padding:10px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 10px 30px rgba(0,0,0,.4)}
.lfa-rp button,.lfa-rp select{font:700 12.5px/1 inherit;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:#fff;border-radius:8px;padding:7px 10px;cursor:pointer}
.lfa-rp button.on{background:#F07C1F;border-color:#F07C1F}
.lfa-rp input[type=range]{flex:1 1 240px;accent-color:#F07C1F}
.lfa-rp .t{font-variant-numeric:tabular-nums;font-weight:800;min-width:150px;font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
@media(max-width:1080px){.lfa-wrap{grid-template-columns:1fr}#lfaMap{height:56vh;min-height:340px}.lfa-side{max-height:none}}
</style>

<div class="lfa-top">
  <span class="lfa-chip" title="Phone sent GPS in the last <?= LiveFleet::LIVE_SECONDS ?> s"><i style="background:#16a34a"></i>Live · लाइभ <b id="cLive">0</b></span>
  <span class="lfa-chip" title="Last GPS <?= LiveFleet::LIVE_SECONDS ?> s – <?= LiveFleet::WEAK_SECONDS / 60 ?> min ago"><i style="background:#f59e0b"></i>Weak <b id="cWeak">0</b></span>
  <span class="lfa-chip" title="Trip running but no GPS for more than <?= LiveFleet::WEAK_SECONDS / 60 ?> min"><i style="background:#dc2626"></i>Offline <b id="cOff">0</b></span>
  <span class="lfa-chip"><i style="background:#64748b"></i>Parked <b id="cPark">0</b></span>
  <span class="lfa-chip red" id="cAlert" hidden>⚠️ <b>0</b> alerts</span>
  <span class="lfa-conn" id="lfaConn"><i></i><span>connecting…</span></span>
  <span class="spacer"></span>
  <input class="lfa-search" id="lfaQ" type="search" placeholder="🔎 गाडी नम्बर / ड्राइभर · vehicle no." autocomplete="off">
  <button class="btn ghost lfa-btn" type="button" id="bFit">🗺️ All</button>
  <button class="btn ghost lfa-btn" type="button" id="bFollow">🎯 Follow</button>
  <button class="btn ghost lfa-btn on" type="button" id="bTrail">〰️ Trail</button>
  <a class="btn ghost" href="/admin/live-tv.php" target="_blank" rel="noopener">📺 TV</a>
  <?php if ($canSetup): ?><a class="btn ghost" href="/admin/live-setup.php" title="Vehicle phones, driver logins, CCTV, TV link">⚙️ Setup</a><?php endif; ?>
</div>

<div class="lfa-wrap">
  <div class="lfa-mapbox"><div id="lfaMap"></div><div class="lfa-rp" id="lfaRp" hidden></div></div>
  <aside class="lfa-side">
    <div class="lfa-card"><h3>🚌 Selected bus <small id="dAge"></small></h3>
      <div id="lfaSel"><div class="lfa-empty">Tap a bus on the map or in the list. · नक्सा वा सूचीमा बस छान्नुहोस्।</div></div>
    </div>
    <div class="lfa-card"><h3>📢 घोषणा · Announcements <small><button class="btn ghost sm" type="button" id="bVset" title="Voice settings">⚙️</button><button class="btn ghost sm" type="button" id="bVoice">🔇 आवाज बन्द</button></small></h3>
      <div id="lfaVp" hidden></div>
      <ul class="lf-log" id="lfaLog"><li class="muted">Arrivals, delays, next stop km / time, temples and alerts appear here in Nepali. Press 🔊 to hear them.</li></ul>
    </div>
    <div class="lfa-card"><h3>📹 Cameras · क्यामेरा <small id="camNote"></small></h3>
      <div class="lfa-cams"><div class="lf-cams" id="lfaCams"></div></div>
    </div>
    <div class="lfa-card"><h3>🚍 Vehicles · गाडीहरू <small id="lfaCount"></small></h3>
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
  var APP = <?= Security::jsonForHtml(rtrim(APP_URL, '/')) ?>;
  var IMG_BUS = '/assets/img/bus-shg.webp', IMG_CREST = <?= Security::jsonForHtml(LiveFleet::asset('shg-crest.svg')) ?>;
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var state = { buses: [], sel: null, trail: true, first: true, lastOk: 0, q: '', replay: null };
  try { state.sel = +localStorage.getItem('lf:sel') || null; state.trail = localStorage.getItem('lf:trail') !== '0'; } catch (e) {}
  // ?bus=<id> (from Live Fleet Setup → Vehicles) opens that vehicle.
  try { var qBus = +new URLSearchParams(location.search).get('bus'); if (qBus) state.sel = qBus; } catch (e) {}

  var fm = new LF.FleetMap(document.getElementById('lfaMap'), {
    look: 'day',
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

  function table(rows) {
    var now = Date.now();
    return '<table class="lf-up"><tbody>' + rows.map(function (u, i) {
      return '<tr' + (i === 0 ? ' class="next"' : '') + '><td><b>' + esc(u.name) + '</b>' + (u.sub ? '<small>' + esc(u.sub) + '</small>' : '') + '</td>'
        + '<td>' + LF.neKm(u.km) + '</td><td>' + LF.neDur(u.min) + '<br><small>' + LF.neClock(now + u.min * 60000) + '</small></td></tr>';
    }).join('') + '</tbody></table>';
  }

  function selHtml(b) {
    var p = b.pos || {}, moving = b.status === 'live' || b.status === 'weak';
    var tel = b.driver && b.driver.phone ? String(b.driver.phone).replace(/\D/g, '') : '';
    var started = b.trip && b.trip.started ? new Date(b.trip.started) : null;
    var towns = (b.towns || []).map(function (t) { return { name: t.name, sub: t.en && t.en !== t.name ? t.en : '', km: t.km, min: t.min }; });
    var ups = b.stop && b.stop.upcoming ? b.stop.upcoming.slice(0, 6).map(function (u) { return { name: LF.nm(u.ne, u.name), sub: u.ne ? u.name : '', km: u.km, min: u.min }; }) : [];
    var share = b.share ? APP + '/bus-live.php?t=' + b.share : '';
    return '<div class="lfa-hero"><img class="crest" src="' + IMG_CREST + '" alt="S Hari Global"><img class="bus" src="' + IMG_BUS + '" alt="">'
      + '<span class="plate">' + LF.plate(b.number, 'lg') + '</span><span class="st">' + LF.pill(b.status, true) + '</span></div>'
      + '<div class="lfa-det">'
      + '<div class="nm">' + esc(b.name) + '<small>' + (b.route ? esc(b.route.label) : 'No route on this trip') + '</small></div>'
      + (b.status !== 'idle' && b.status !== 'parked' ? '<div class="lfa-head">' + LF.headline(b) + '</div>' : '')
      + LF.alertsHtml(b, true)
      + '<div class="lfa-stats">'
      + '<div class="lfa-stat"><div class="k">Speed</div><div class="v">' + (moving && p.kmh != null ? Math.round(p.kmh) : '—') + ' <small>km/h</small></div></div>'
      + '<div class="lfa-stat"><div class="k">Avg</div><div class="v">' + (p.avg != null ? Math.round(p.avg) : '—') + ' <small>km/h</small></div></div>'
      + '<div class="lfa-stat"><div class="k">Max</div><div class="v">' + (b.trip && b.trip.max_kmh ? Math.round(b.trip.max_kmh) : '—') + ' <small>km/h</small></div></div>'
      + '<div class="lfa-stat"><div class="k">Driven</div><div class="v">' + (b.trip ? b.trip.km : '—') + ' <small>km</small></div></div>'
      + '</div>'
      + (LF.phoneHtml(b) ? '<div class="muted" style="font-size:12.5px">' + LF.phoneHtml(b) + '</div>' : '')
      + (b.place ? '<div>📍 अहिले: <b>' + esc(b.place.name) + '</b>' + (b.place.en && b.place.en !== b.place.name ? ' <span class="muted">' + esc(b.place.en) + '</span>' : '') + (b.place.at ? '' : ' <span class="muted">(' + LF.neKm(b.place.km) + ')</span>') + '</div>' : '')
      + (towns.length ? '<div><b>➡️ आउने ठाउँहरू</b>' + table(towns) + '</div>' : '')
      + (ups.length ? '<div><b>🚏 आउने स्टपहरू</b>' + table(ups) + '</div>' : '')
      + (b.temples && b.temples.length ? '<div><b>🛕 नजिकैका मन्दिर</b>' + b.temples.map(function (t) { return '<div>' + esc(t.name) + ' · ' + LF.neKm(t.km) + (t.ahead ? ' ⬆️' : '') + '</div>'; }).join('') + '</div>' : '')
      + (b.timeline && b.timeline.stops.length ? '<details' + (state.tlOpen ? ' open' : '') + ' data-tl><summary>🚆 चलिरहेको स्थिति · Running status</summary>' + LF.timelineHtml(b) + '</details>' : '')
      + (b.driver ? '<div class="lfa-row">👤 <b>' + esc(b.driver.name) + '</b>' + (started ? '<span class="muted">since ' + esc(started.toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })) + '</span>' : '') + '</div>' : '')
      + '<div class="lfa-acts">'
      + (tel ? '<a class="btn ghost" href="tel:+' + esc(tel) + '">📞 Call</a><a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/' + esc(tel) + '">💬 WhatsApp</a>' : '')
      + (b.pos ? '<a class="btn ghost" target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + p.lat + ',' + p.lng + '">🗺️ Google Maps</a>' : '')
      + (share ? '<button class="btn ghost" type="button" data-copy="' + esc(share) + '">🔗 Passenger link</button><a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text='
          + encodeURIComponent('🚌 ' + b.number + ' — लाइभ हेर्नुहोस् · Track your bus live: ' + share) + '">📲 Send link</a>' : '')
      + (b.trip ? '<button class="btn ghost" type="button" data-replay>⏪ Replay</button>' : '')
      + '<a class="btn ghost" target="_blank" rel="noopener" href="/admin/live-tv.php?bus=' + b.id + '">📺 TV</a>'
      + '</div></div>';
  }

  function render() {
    var b = state.sel != null ? bus(state.sel) : null;
    var box = document.getElementById('lfaSel');
    document.getElementById('dAge').textContent = b && b.age_s != null ? 'GPS ' + LF.ago(b.age_s) : '';
    var open = box.querySelector('details[data-tl]');
    if (open) state.tlOpen = open.open;
    if (!b) {
      box.innerHTML = '<div class="lfa-empty">Tap a bus on the map or in the list. · नक्सा वा सूचीमा बस छान्नुहोस्।</div>';
      LF.renderCams(document.getElementById('lfaCams'), [], '<div class="lf-cam-empty">Pick a bus to see its cameras.</div>');
    } else {
      var html = selHtml(b);
      if (box._h !== html) { box.innerHTML = html; box._h = html; }
      LF.renderCams(document.getElementById('lfaCams'), b.cameras,
        '<div class="lf-cam-empty">No camera on this bus yet. · यो बसमा क्यामेरा छैन।' + (CAN_SETUP ? '<br><a href="/admin/live-setup.php#cams">➕ Add a CCTV camera</a>' : '') + '</div>');
    }
    document.getElementById('camNote').textContent = b && b.cameras.length ? b.cameras.length + ' feed' + (b.cameras.length > 1 ? 's' : '') : '';

    var q = state.q.toLowerCase().replace(/[\s-]/g, '');
    var list = state.buses.filter(function (x) {
      return !q || (x.number + x.name + (x.driver ? x.driver.name : '')).toLowerCase().replace(/[\s-]/g, '').indexOf(q) !== -1;
    });
    var el = document.getElementById('lfaList');
    el.innerHTML = list.length ? list.map(function (x) {
      var s = LF.STATUS[x.status] || LF.STATUS.idle, moving = x.status === 'live' || x.status === 'weak';
      var where = x.status === 'parked' || x.status === 'idle' ? s.np : (x.place ? '📍 ' + x.place.name : s.np)
        + (x.stop && x.stop.next ? ' → ' + LF.nm(x.stop.next_ne, x.stop.next) + ' ' + LF.neKm(x.stop.next_km) : '');
      var red = (x.alerts || []).filter(function (a) { return a.level === 'red'; });
      return '<div class="lfa-item' + (x.id === state.sel ? ' sel' : '') + '" data-id="' + x.id + '">'
        + '<span class="dot" style="background:' + s.c + ';color:' + s.c + '"></span>'
        + '<span class="tx">' + LF.plate(x.number) + '<span>' + esc(x.driver ? x.driver.name + ' · ' : '') + esc(where) + '</span></span>'
        + (red.length ? '<span class="al" title="' + esc(red.map(function (a) { return a.en; }).join(', ')) + '">' + esc(red[0].icon) + '</span>' : '')
        + '<span class="sp">' + (moving && x.pos && x.pos.kmh != null ? Math.round(x.pos.kmh) + ' km/h' : '')
        + '<small style="color:' + LF.lateColor(x.delay) + '">' + esc(x.delay != null && moving ? LF.neLate(x.delay) : (x.age_s != null && x.status !== 'parked' ? LF.ago(x.age_s) : '')) + '</small></span>'
        + '</div>';
    }).join('') : '<div class="lfa-empty">No vehicle matches. · कुनै गाडी भेटिएन।</div>';
  }

  document.getElementById('lfaList').addEventListener('click', function (e) {
    var el = e.target.closest('.lfa-item');
    if (el) pick(+el.dataset.id, true);
  });
  document.getElementById('lfaQ').addEventListener('input', function () { state.q = this.value; render(); });
  document.getElementById('lfaSel').addEventListener('click', function (e) {
    var c = e.target.closest('[data-copy]');
    if (c) {
      var t = c.getAttribute('data-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { c.textContent = '✓ Copied'; })
        .catch(function () { prompt('Passenger link:', t); });
      return;
    }
    var r = e.target.closest('[data-resolve]');
    if (r) {
      r.disabled = true;
      fetch('/api/live-fleet.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'resolve', id: +r.getAttribute('data-resolve') }) }).then(function () { poller.now(); });
      return;
    }
    if (e.target.closest('[data-replay]')) openReplay(state.sel);
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

  /* 📢 Nepali announcements for every bus on the road; spoken after 🔊. */
  var tracker = new LF.Tracker();
  var logEl = document.getElementById('lfaLog');
  var voice = new LF.Announcer(function (text, icon, level) {
    if (logEl.querySelector('.muted')) logEl.innerHTML = '';
    var li = document.createElement('li');
    if (level === 'alarm') li.style.background = '#FBE3E3';
    li.innerHTML = '<span>' + esc(icon || '📢') + '</span><span>' + esc(text) + '</span><time>' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</time>';
    logEl.insertBefore(li, logEl.firstChild);
    while (logEl.children.length > 60) logEl.removeChild(logEl.lastChild);
  }, function () {
    // The browser refused to speak: one more click on 🔊 is needed.
    bVoice.classList.remove('on'); bVoice.textContent = '🔇 आवाज बन्द';
  });
  var bVoice = document.getElementById('bVoice');
  if (!voice.supported()) { bVoice.disabled = true; bVoice.title = 'This browser cannot speak'; }
  bVoice.onclick = function () {
    var on = voice.enable(!voice.on);
    bVoice.classList.toggle('on', on);
    bVoice.textContent = on ? '🔊 आवाज खुला' : '🔇 आवाज बन्द';
  };
  document.getElementById('bVset').onclick = function () {
    var vp = document.getElementById('lfaVp');
    vp.hidden = !vp.hidden;
    if (!vp.hidden && !vp.childNodes.length) LF.voicePanel(vp, voice);
  };

  /* ⏪ Replay: a whole trip played back on the map. */
  function openReplay(busId) {
    var bar = document.getElementById('lfaRp');
    bar.hidden = false;
    bar.innerHTML = '<span class="t">Loading trips…</span>';
    fetch('/api/live-fleet.php?trips=' + busId, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var trips = (j.data && j.data.trips) || [];
      if (!trips.length) { bar.innerHTML = '<span class="t">No recorded trip yet.</span><button type="button" data-x>✕</button>'; return; }
      bar.innerHTML = '<button type="button" data-play>▶</button>'
        + [10, 60, 300].map(function (x) { return '<button type="button" data-sp="' + x + '"' + (x === 60 ? ' class="on"' : '') + '>' + x + '×</button>'; }).join('')
        + '<input type="range" min="0" max="1000" value="0" data-seek><span class="t" data-t>—</span>'
        + '<select data-trip>' + trips.map(function (t) { return '<option value="' + t.id + '">' + new Date(t.started).toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) + ' · ' + t.km + ' km' + (t.running ? ' · live' : '') + '</option>'; }).join('') + '</select>'
        + '<button type="button" data-x>✕</button>';
      loadTrip(trips[0].id);
    });
  }
  function loadTrip(id) {
    fetch('/api/live-fleet.php?replay=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var d = j.data || {}, bar = document.getElementById('lfaRp');
      if (state.replay) state.replay.pause();
      if (!d.points || !d.points.length) { bar.querySelector('[data-t]').textContent = 'No points'; return; }
      fm.follow(false);
      var ghost = fm.ghost || (fm.ghost = new window.maplibregl.Marker({ element: (function () { var e = document.createElement('div'); e.className = 'lf-bus is-sel'; e.style.setProperty('--lf-c', '#F07C1F'); e.innerHTML = '<span class="lf-ring"></span><span class="lf-body">' + LF.BUS_SVG + '</span>'; return e; })() }).setLngLat([d.points[0][1], d.points[0][2]]).addTo(fm.map));
      var rp = state.replay = new LF.Replay(fm, d, function (p, t) {
        ghost.setLngLat([p[1], p[2]]);
        if (p[4] != null) ghost.getElement().querySelector('.lf-body').style.transform = 'translate(-50%,-50%) rotate(' + (p[4] - fm.map.getBearing()) + 'deg)';
        bar.querySelector('[data-t]').textContent = LF.neClock(t) + ' · ' + (p[3] != null ? Math.round(p[3]) + ' km/h' : '—');
        bar.querySelector('[data-seek]').value = Math.round((t - rp.t0) / Math.max(1, rp.t1 - rp.t0) * 1000);
      });
      rp.seek(rp.t0);
      var b = new window.maplibregl.LngLatBounds([d.points[0][1], d.points[0][2]], [d.points[0][1], d.points[0][2]]);
      d.points.forEach(function (q) { b.extend([q[1], q[2]]); });
      fm.map.fitBounds(b, { padding: 70, maxZoom: 13, duration: 800 });
    });
  }
  document.getElementById('lfaRp').addEventListener('click', function (e) {
    var rp = state.replay, t = e.target;
    if (t.hasAttribute('data-x')) {
      if (rp) rp.pause();
      state.replay = null; this.hidden = true; this.innerHTML = '';
      if (fm.ghost) { fm.ghost.remove(); fm.ghost = null; }
      fm.setTrail(null); poller.now(); return;
    }
    if (!rp) return;
    if (t.hasAttribute('data-play')) { if (rp.playing) { rp.pause(); t.textContent = '▶'; } else { if (rp.t >= rp.t1) rp.seek(rp.t0); rp.play(); t.textContent = '⏸'; } }
    if (t.hasAttribute('data-sp')) { rp.speed = +t.getAttribute('data-sp'); this.querySelectorAll('[data-sp]').forEach(function (x) { x.classList.toggle('on', x === t); }); }
  });
  document.getElementById('lfaRp').addEventListener('input', function (e) {
    var rp = state.replay;
    if (rp && e.target.hasAttribute('data-seek')) rp.seek(rp.t0 + (rp.t1 - rp.t0) * (+e.target.value / 1000));
  });
  document.getElementById('lfaRp').addEventListener('change', function (e) {
    if (e.target.hasAttribute('data-trip')) loadTrip(+e.target.value);
  });

  var conn = document.getElementById('lfaConn');
  setInterval(function () {
    if (!state.lastOk) return;
    var s = Math.round((Date.now() - state.lastOk) / 1000);
    conn.classList.toggle('bad', s > 6);
    conn.querySelector('span').textContent = s > 6 ? 'connection lost — retrying… · जडान टुट्यो' : 'live · updated ' + (s < 2 ? 'now' : s + ' s ago');
  }, 1000);

  var poller = LF.poll(function () {
    var t = state.trail && !state.replay && state.sel != null ? tripOf(state.sel) : 0;
    return '/api/live-fleet.php' + (t ? '?trail=' + t : '');
  }, EVERY, function (d) {
    state.buses = d.buses;
    state.lastOk = Date.now();
    var c = d.counts;
    document.getElementById('cLive').textContent = c.live;
    document.getElementById('cWeak').textContent = c.weak;
    document.getElementById('cOff').textContent = c.offline + c.waiting;
    document.getElementById('cPark').textContent = c.parked + c.idle;
    var nAlert = d.buses.reduce(function (n, x) { return n + (x.alerts || []).filter(function (a) { return a.level === 'red'; }).length; }, 0);
    var ca = document.getElementById('cAlert'); ca.hidden = !nAlert; ca.querySelector('b').textContent = nAlert;
    document.getElementById('lfaCount').textContent = d.buses.length + ' in fleet';
    fm.ready.then(function () {
      fm.update(d.buses);
      if (state.sel != null) fm.select(state.sel);
      if (!state.replay && d.trail && state.trail && d.trail.trip === tripOf(state.sel)) fm.setTrail(d.trail.points);
      fm.setPois(LF.poisFor(bus(state.sel)));
      if (state.first) {
        state.first = false;
        if (state.sel != null && bus(state.sel) && bus(state.sel).pos) fm.focus(state.sel, 12, 0); else fm.fitAll(60, 0);
      }
    }).catch(function () {});
    tracker.limit = d.limit || tracker.limit;
    tracker.events(d.buses).forEach(function (ev) { voice.say(ev.text, ev.icon, ev.speak, ev.level); });
    if (d.need_cells && d.need_cells.length) LF.warmCells(d.need_cells);
    render();
  }, function (e) {
    if (e && e.status === 401) { conn.classList.add('bad'); conn.querySelector('span').textContent = 'signed out — reload the page'; }
  });
})();
</script>
<?php admin_footer(); ?>
