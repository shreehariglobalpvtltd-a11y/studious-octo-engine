<?php
/**
 * admin/live-tv.php — Live Fleet TV wall (Oct 2026).
 *
 * Made for the office TV, a spare monitor or a phone turned sideways:
 * full screen, dark, no menus, refreshes every second, and never needs
 * a click once it is open.
 *
 *   ?layout=focus   (default) one bus at a time, filling the screen like a
 *                   TV channel: the map follows it, its CCTV plays beside
 *                   it, and it moves on to the next bus every ?rotate=20 s
 *   ?layout=grid    every bus in its own tile (map + camera), up to 9
 *   ?layout=all     the whole fleet on one map
 *   ?bus=<id>       lock focus on one bus (no rotation)
 *   ?key=<tv key>   open without a staff login (admin/live-setup.php)
 *
 * Keys: ← → previous / next bus · Space pause rotation · 1 2 3 layout · F full screen
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/livefleet.php';

$key = (string) ($_GET['key'] ?? '');
if (!LiveFleet::tvKeyMatches($key)) {
    if ($key !== '') {
        http_response_code(403);
        exit('This TV link is no longer valid. Ask the office for the new link (Live Fleet → Drivers & CCTV → TV wall).');
    }
    admin_boot('schedules.view');
    $key = '';
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
LiveFleet::allowCameraOrigins();

$layout  = in_array($_GET['layout'] ?? '', ['focus', 'grid', 'all'], true) ? (string) $_GET['layout'] : 'focus';
$rotate  = max(5, min(600, (int) ($_GET['rotate'] ?? 20)));
$lockBus = max(0, (int) ($_GET['bus'] ?? 0));
$company = Settings::getString('company_name', APP_NAME);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0b1220">
<title>Live Fleet TV · <?= Security::e($company) ?></title>
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= Security::e(LiveFleet::asset('livefleet.css')) ?>">
<style>
:root{--bg:#0b1220;--card:#111d33;--line:#22314a;--ink:#e8f0fb;--mut:#93a4be;--acc:#F07C1F}
*{box-sizing:border-box}
[hidden]{display:none!important}
html,body{margin:0;height:100%;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;overflow:hidden}
body.idle{cursor:none}
.tv{display:grid;grid-template-rows:64px 1fr 58px;height:100vh}
.tv.no-ticker{grid-template-rows:64px 1fr}
.bar{display:flex;align-items:center;gap:14px;padding:0 20px;background:linear-gradient(90deg,#12264E,#1C3B72);box-shadow:0 2px 14px rgba(0,0,0,.4);z-index:5}
.bar .brand{font-weight:800;font-size:19px;white-space:nowrap;display:flex;align-items:center;gap:10px}
.bar .brand img{width:34px;height:34px;background:#fff;border-radius:9px;padding:3px}
.bar .brand span{color:#FFC08A;font-size:12px;letter-spacing:.12em;text-transform:uppercase}
.bar .counts{display:flex;gap:8px;margin-left:8px}
.bar .counts span{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:14px;background:rgba(255,255,255,.08);padding:5px 11px;border-radius:999px}
.bar .counts i{width:10px;height:10px;border-radius:50%}
.bar .sp{flex:1}
.bar .ctl{display:flex;gap:6px;transition:opacity .3s}
body.idle .bar .ctl{opacity:0}
.bar .ctl button{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:7px 12px;font:600 13px/1 inherit;cursor:pointer}
.bar .ctl button.on{background:var(--acc);border-color:var(--acc)}
.bar .clock{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:.02em;white-space:nowrap}
.bar .clock small{display:block;font-size:11.5px;font-weight:600;color:#cdd6e6;text-align:right;letter-spacing:0}
.conn{width:12px;height:12px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.25)}
.conn.bad{background:#dc2626;box-shadow:0 0 0 4px rgba(220,38,38,.3)}
.stage{position:relative;min-height:0}
/* focus */
.focus{position:absolute;inset:0;display:grid;grid-template-columns:1fr}
.focus.has-cam{grid-template-columns:minmax(0,1fr) minmax(320px,36%)}
.focus .fmap{position:relative;min-height:0}
.focus .fmap .lf-map{position:absolute;inset:0}
.focus .fcam{background:#000;padding:6px;min-height:0;overflow:hidden;display:flex;align-items:center}
.focus .fcam .lf-cams{grid-template-columns:1fr;gap:6px;width:100%}
.info{position:absolute;left:20px;bottom:20px;z-index:3;background:rgba(11,18,32,.88);backdrop-filter:blur(6px);border:1px solid var(--line);border-radius:18px;padding:16px 20px;min-width:360px;max-width:min(560px,calc(100% - 40px));box-shadow:0 14px 40px rgba(0,0,0,.5)}
.info .nm{font-size:28px;font-weight:800;line-height:1.15}
.info .nm small{display:block;font-size:15px;color:var(--mut);font-weight:600;margin-top:2px}
.info .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;font-size:16px}
.info .speed{font-size:54px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.info .speed small{font-size:18px;color:var(--mut);font-weight:700}
.info .stop{font-size:17px;margin-top:8px;line-height:1.45}
.info .stop b{color:#fff}
.info .lf-pill{font-size:14px;padding:5px 12px}
.prog{position:absolute;left:0;right:0;top:0;height:4px;z-index:4;background:rgba(255,255,255,.08)}
.prog i{display:block;height:100%;width:0;background:var(--acc)}
.idx{position:absolute;right:20px;top:16px;z-index:3;background:rgba(11,18,32,.8);border:1px solid var(--line);border-radius:999px;padding:6px 14px;font-weight:800;font-size:15px}
.splash{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;z-index:2;background:radial-gradient(circle at 50% 40%,#16284a,#0b1220 70%)}
.splash .big{font-size:40px;font-weight:800}
.splash .sub{font-size:20px;color:var(--mut)}
/* grid */
.grid{position:absolute;inset:0;display:grid;gap:6px;padding:6px}
.tile{position:relative;background:var(--card);border-radius:14px;overflow:hidden;min-height:0;border:2px solid var(--line)}
.tile .lf-map{position:absolute;inset:0}
.tile .tcam{position:absolute;right:8px;top:8px;width:42%;z-index:3}
.tile .tcam .lf-cams{grid-template-columns:1fr}
.tile .tcam .lf-cam:not(:first-child){display:none}
.tile .tl{position:absolute;left:0;right:0;bottom:0;z-index:3;background:linear-gradient(0deg,rgba(11,18,32,.95),rgba(11,18,32,.6) 70%,transparent);padding:22px 14px 10px;display:flex;align-items:flex-end;gap:12px}
.tile .tl .t1{flex:1;min-width:0}
.tile .tl b{display:block;font-size:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile .tl span{display:block;font-size:13.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile .tl .kmh{font-size:30px;font-weight:900;font-variant-numeric:tabular-nums;white-space:nowrap}
.tile .tl .kmh small{font-size:13px;color:var(--mut)}
/* all */
.all{position:absolute;inset:0}
.all .lf-map{position:absolute;inset:0}
/* ticker */
.ticker{display:flex;gap:8px;align-items:center;padding:0 12px;background:#0e1830;border-top:1px solid var(--line);overflow:hidden;white-space:nowrap}
.ticker .tk{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border-radius:12px;background:var(--card);border:1px solid var(--line);font-size:14.5px;font-weight:700;cursor:pointer;flex:0 0 auto}
.ticker .tk.cur{border-color:var(--acc);box-shadow:0 0 0 2px var(--acc) inset}
.ticker .tk i{width:10px;height:10px;border-radius:50%}
.ticker .tk small{color:var(--mut);font-weight:600}
@media(max-width:820px){.focus.has-cam{grid-template-columns:1fr;grid-template-rows:1fr 34%}.info{min-width:0;left:10px;right:10px;bottom:10px}.info .nm{font-size:20px}.info .speed{font-size:36px}.bar .counts,.bar .brand span{display:none}}
</style>
</head>
<body>
<div class="tv" id="tv">
  <header class="bar">
    <div class="brand"><img src="/assets/img/logo.png" alt=""><?= Security::e($company) ?> <span>Live Fleet</span></div>
    <div class="counts"><span><i style="background:#16a34a"></i><b id="nLive">0</b></span><span><i style="background:#f59e0b"></i><b id="nWeak">0</b></span><span><i style="background:#dc2626"></i><b id="nOff">0</b></span></div>
    <div class="sp"></div>
    <div class="ctl">
      <button type="button" data-layout="focus">▶ One bus</button>
      <button type="button" data-layout="grid">▦ Grid</button>
      <button type="button" data-layout="all">🗺 All</button>
      <button type="button" id="bPause">⏸ Pause</button>
      <button type="button" id="bFull">⛶ Full screen</button>
    </div>
    <span class="conn" id="conn" title="connection"></span>
    <div class="clock" id="clock">--:--<small></small></div>
  </header>
  <main class="stage" id="stage"></main>
  <footer class="ticker" id="ticker"></footer>
</div>

<script src="<?= Security::e(LiveFleet::asset('livefleet.js')) ?>"></script>
<script>
(function () {
  var LF = window.LiveFleet, esc = LF.esc;
  var KEY = <?= Security::jsonForHtml($key) ?>;
  var ROTATE = <?= $rotate ?> * 1000;
  var LOCK = <?= $lockBus ?>;
  var layout = <?= Security::jsonForHtml($layout) ?>;
  var stage = document.getElementById('stage');
  var S = { buses: [], focusId: LOCK || null, paused: !!LOCK, rotT0: Date.now(), lastOk: 0, view: null };

  function api() { return '/api/live-fleet.php' + (KEY ? '?key=' + encodeURIComponent(KEY) : ''); }
  function onRoad(b) { return b.pos && (b.status === 'live' || b.status === 'weak' || b.status === 'waiting' || b.status === 'offline'); }
  function shown() {
    // Buses worth a screen: on the road first; parked ones only when nothing is moving.
    var road = S.buses.filter(onRoad);
    return road.length ? road : S.buses.filter(function (b) { return b.pos; });
  }
  function byId(id) { for (var i = 0; i < S.buses.length; i++) if (S.buses[i].id === id) return S.buses[i]; return null; }
  function stopLine(b) {
    if (!b.stop) return b.route ? esc(b.route.label) : '';
    return (b.stop.at ? '📍 At <b>' + esc(b.stop.near) + '</b>' : '📍 Near <b>' + esc(b.stop.near) + '</b>')
      + (b.stop.next ? ' &nbsp;➡️ Next <b>' + esc(b.stop.next) + '</b>' + (b.stop.next_km != null ? ' · ' + b.stop.next_km + ' km' : '') : '');
  }
  function kmh(b) { return (b.status === 'live' || b.status === 'weak') && b.pos && b.pos.kmh != null ? Math.round(b.pos.kmh) : 0; }

  /* ── layouts ─────────────────────────────────────────────────────── */
  function destroyView() {
    if (S.view && S.view.destroy) S.view.destroy();
    Array.prototype.forEach.call(stage.querySelectorAll('.lf-cam'), function (t) { if (t._lfCleanup) t._lfCleanup(); });
    stage.innerHTML = '';
    S.view = null;
  }

  function focusView() {
    var root = document.createElement('div');
    root.className = 'focus';
    root.innerHTML = '<div class="fmap"><div class="prog"><i></i></div><div class="idx" hidden></div><div class="info" hidden></div><div class="splash" hidden></div></div><div class="fcam" hidden><div class="lf-cams"></div></div>';
    stage.appendChild(root);
    var mapEl = document.createElement('div');
    root.querySelector('.fmap').insertBefore(mapEl, root.querySelector('.prog'));
    var fm = new LF.FleetMap(mapEl, { style: LF.STYLE_DARK, controls: false, glideMs: 1000 });
    var current = null;
    fm.ready.then(function () { fm.map.setPitch(45); }).catch(function () {});
    return {
      update: function () {
        var list = shown();
        var splash = root.querySelector('.splash'), info = root.querySelector('.info'), idx = root.querySelector('.idx');
        fm.ready.then(function () { fm.update(S.buses); }).catch(function () {});
        if (!list.length) {
          splash.hidden = false; info.hidden = true; idx.hidden = true;
          splash.innerHTML = '<div class="big">No bus is live right now</div><div class="sub">अहिले कुनै बस लाइभ छैन · the screen wakes up when a driver starts a trip</div>';
          root.classList.remove('has-cam'); root.querySelector('.fcam').hidden = true;
          current = null; return;
        }
        splash.hidden = true;
        if (S.focusId == null || !list.some(function (b) { return b.id === S.focusId; })) {
          if (LOCK && byId(LOCK) && byId(LOCK).pos) S.focusId = LOCK; else { S.focusId = list[0].id; S.rotT0 = Date.now(); }
        }
        var b = byId(S.focusId);
        if (current !== b.id) {
          current = b.id;
          fm.ready.then(function () {
            fm.select(b.id);
            fm.following = false;
            fm.map.flyTo({ center: [b.pos.lng, b.pos.lat], zoom: 14.5, pitch: 45, duration: 2200, essential: true });
            fm.map.once('moveend', function () { if (current === b.id) fm.follow(true); });
          }).catch(function () {});
        }
        var n = list.indexOf(b) + 1;
        idx.hidden = list.length < 2; idx.textContent = n + ' / ' + list.length + (S.paused ? ' ⏸' : '');
        info.hidden = false;
        info.innerHTML = '<div class="nm">' + esc(b.name) + '<small>' + esc(b.number) + (b.route ? ' · ' + esc(b.route.label) : '') + '</small></div>'
          + '<div class="row"><span class="speed">' + kmh(b) + ' <small>km/h</small></span>' + LF.pill(b.status, true)
          + (b.battery != null ? '<span style="color:var(--mut)">🔋 ' + b.battery + '%</span>' : '') + '</div>'
          + '<div class="stop">' + stopLine(b) + '</div>'
          + '<div class="row" style="color:var(--mut);font-size:14px">' + (b.driver ? '👤 ' + esc(b.driver.name) + ' · ' : '') + 'GPS ' + esc(LF.ago(b.age_s))
          + (b.trip ? ' · ' + b.trip.km + ' km driven' : '') + '</div>';
        var cams = b.cameras || [];
        root.classList.toggle('has-cam', cams.length > 0);
        root.querySelector('.fcam').hidden = !cams.length;
        LF.renderCams(root.querySelector('.fcam .lf-cams'), cams.slice(0, 3));
        setTimeout(function () { fm.resize(); }, 50);
      },
      tick: function () {
        var bar = root.querySelector('.prog i'), list = shown();
        if (S.paused || list.length < 2) { bar.style.width = '0'; return; }
        var k = (Date.now() - S.rotT0) / ROTATE;
        bar.style.width = Math.min(100, k * 100) + '%';
        if (k >= 1) step(1);
      },
      destroy: function () { if (fm.map) fm.map.remove(); }
    };
  }

  function gridView() {
    var root = document.createElement('div');
    root.className = 'grid';
    stage.appendChild(root);
    var tiles = {};
    function cols(n) { return n <= 1 ? 1 : n <= 4 ? 2 : 3; }
    return {
      update: function () {
        var list = shown().slice(0, 9).sort(function (a, b) { return a.id - b.id; });
        var keep = {};
        list.forEach(function (b) { keep[b.id] = true; });
        Object.keys(tiles).forEach(function (id) {
          if (!keep[id]) {
            var t = tiles[id];
            Array.prototype.forEach.call(t.el.querySelectorAll('.lf-cam'), function (c) { if (c._lfCleanup) c._lfCleanup(); });
            if (t.fm.map) t.fm.map.remove();
            t.el.remove(); delete tiles[id];
          }
        });
        var c = cols(list.length);
        root.style.gridTemplateColumns = 'repeat(' + c + ',1fr)';
        root.style.gridTemplateRows = 'repeat(' + Math.max(1, Math.ceil(list.length / c)) + ',1fr)';
        if (!list.length) {
          root.innerHTML = '<div class="splash"><div class="big">No bus is live right now</div><div class="sub">अहिले कुनै बस लाइभ छैन</div></div>';
          return;
        }
        var sp = root.querySelector('.splash'); if (sp) sp.remove();
        list.forEach(function (b) {
          var t = tiles[b.id];
          if (!t) {
            var el = document.createElement('div');
            el.className = 'tile';
            el.innerHTML = '<div class="tcam"><div class="lf-cams"></div></div><div class="tl"></div>';
            var m = document.createElement('div');
            el.insertBefore(m, el.firstChild);
            root.appendChild(el);
            var fm = new LF.FleetMap(m, { style: LF.STYLE_DARK, controls: false, interactive: false, labels: false });
            t = tiles[b.id] = { el: el, fm: fm, placed: false };
            fm.ready.then(function () { fm.select(b.id); }).catch(function () {});
          }
          var s = LF.STATUS[b.status] || LF.STATUS.idle;
          t.el.style.borderColor = s.c;
          t.fm.ready.then(function () {
            t.fm.update([b]);
            if (!t.placed) { t.placed = true; t.fm.map.jumpTo({ center: [b.pos.lng, b.pos.lat], zoom: 13.5 }); t.fm.following = true; t.fm.selected = b.id; }
          }).catch(function () {});
          t.el.querySelector('.tl').innerHTML = '<div class="t1"><b>' + esc(b.name) + ' · ' + esc(b.number) + '</b><span>' + esc(s.en)
            + (b.stop ? ' · ' + esc(b.stop.at ? 'At ' + b.stop.near : 'Near ' + b.stop.near) + (b.stop.next ? ' → ' + esc(b.stop.next) : '') : '')
            + ' · GPS ' + esc(LF.ago(b.age_s)) + '</span></div><div class="kmh">' + kmh(b) + ' <small>km/h</small></div>';
          LF.renderCams(t.el.querySelector('.tcam .lf-cams'), (b.cameras || []).slice(0, 1));
        });
        setTimeout(function () { Object.keys(tiles).forEach(function (id) { tiles[id].fm.resize(); }); }, 50);
      },
      tick: function () {},
      destroy: function () { Object.keys(tiles).forEach(function (id) { if (tiles[id].fm.map) tiles[id].fm.map.remove(); }); }
    };
  }

  function allView() {
    var root = document.createElement('div');
    root.className = 'all';
    stage.appendChild(root);
    var m = document.createElement('div');
    root.appendChild(m);
    var fm = new LF.FleetMap(m, { style: LF.STYLE_DARK, controls: false });
    var lastFit = 0;
    return {
      update: function () {
        fm.ready.then(function () {
          fm.update(S.buses);
          if (Date.now() - lastFit > 30000) { lastFit = Date.now(); fm.fitAll(90, lastFit ? 1500 : 0); }
        }).catch(function () {});
      },
      tick: function () {},
      destroy: function () { if (fm.map) fm.map.remove(); }
    };
  }

  function setLayout(l) {
    layout = l;
    destroyView();
    S.view = l === 'grid' ? gridView() : l === 'all' ? allView() : focusView();
    document.getElementById('tv').classList.toggle('no-ticker', l === 'grid');
    Array.prototype.forEach.call(document.querySelectorAll('[data-layout]'), function (b) { b.classList.toggle('on', b.dataset.layout === l); });
    try { var u = new URL(location.href); u.searchParams.set('layout', l); history.replaceState(null, '', u); } catch (e) {}
    if (S.buses.length) S.view.update();
  }

  function step(dir) {
    var list = shown();
    if (!list.length) return;
    var i = 0;
    for (var k = 0; k < list.length; k++) if (list[k].id === S.focusId) i = k;
    S.focusId = list[(i + dir + list.length) % list.length].id;
    S.rotT0 = Date.now();
    if (S.view) S.view.update();
    renderTicker();
  }

  function renderTicker() {
    var tk = document.getElementById('ticker');
    tk.innerHTML = S.buses.map(function (b) {
      var s = LF.STATUS[b.status] || LF.STATUS.idle, v = kmh(b);
      return '<span class="tk' + (layout === 'focus' && b.id === S.focusId ? ' cur' : '') + '" data-id="' + b.id + '"><i style="background:' + s.c + '"></i>'
        + esc(b.number || b.name) + ' <small>' + (v ? v + ' km/h' : esc(s.en)) + (b.stop && b.status !== 'parked' ? ' · ' + esc(b.stop.next || b.stop.near) : '') + '</small></span>';
    }).join('');
  }
  document.getElementById('ticker').addEventListener('click', function (e) {
    var t = e.target.closest('.tk');
    if (!t) return;
    var b = byId(+t.dataset.id);
    if (!b || !b.pos) return;
    if (layout !== 'focus') setLayout('focus');
    S.focusId = b.id; S.rotT0 = Date.now(); S.view.update(); renderTicker();
  });

  /* ── controls ────────────────────────────────────────────────────── */
  Array.prototype.forEach.call(document.querySelectorAll('[data-layout]'), function (b) { b.onclick = function () { setLayout(b.dataset.layout); }; });
  var bPause = document.getElementById('bPause');
  function setPaused(p) { S.paused = p; S.rotT0 = Date.now(); bPause.classList.toggle('on', p); bPause.textContent = p ? '▶ Resume' : '⏸ Pause'; if (S.view) S.view.update(); }
  bPause.onclick = function () { setPaused(!S.paused); };
  document.getElementById('bFull').onclick = function () {
    if (!document.fullscreenElement) { (document.documentElement.requestFullscreen || function () {}).call(document.documentElement); }
    else if (document.exitFullscreen) document.exitFullscreen();
  };
  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === ' ') { e.preventDefault(); setPaused(!S.paused); }
    else if (e.key === '1') setLayout('focus');
    else if (e.key === '2') setLayout('grid');
    else if (e.key === '3') setLayout('all');
    else if (e.key === 'f' || e.key === 'F') document.getElementById('bFull').click();
  });
  var idleT = null;
  function wake() { document.body.classList.remove('idle'); clearTimeout(idleT); idleT = setTimeout(function () { document.body.classList.add('idle'); }, 4000); }
  document.addEventListener('mousemove', wake); document.addEventListener('touchstart', wake); wake();

  var clock = document.getElementById('clock');
  setInterval(function () {
    var d = new Date();
    clock.innerHTML = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
      + '<small>' + d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }) + '</small>';
    document.getElementById('conn').classList.toggle('bad', !S.lastOk || Date.now() - S.lastOk > 6000);
    if (S.view) S.view.tick();
  }, 250);

  // A screen that runs for days: reload twice a day to pick up new code and shed memory.
  setTimeout(function () { location.reload(); }, 12 * 3600 * 1000);

  setLayout(layout);
  if (LOCK) setPaused(true);
  LF.poll(api, 1000, function (d) {
    S.buses = d.buses;
    S.lastOk = Date.now();
    document.getElementById('nLive').textContent = d.counts.live;
    document.getElementById('nWeak').textContent = d.counts.weak;
    document.getElementById('nOff').textContent = d.counts.offline + d.counts.waiting;
    if (S.view) S.view.update();
    renderTicker();
  }, function (e) {
    if (e && (e.status === 401 || e.status === 403)) {
      stage.innerHTML = '<div class="splash"><div class="big">Signed out</div><div class="sub">Open the TV link again from Live Fleet → Drivers &amp; CCTV.</div></div>';
    }
  });
})();
</script>
</body>
</html>
