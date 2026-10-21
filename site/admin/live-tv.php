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
 *   ?layout=board   a railway-station departure board: every vehicle number,
 *                   where it is, next stop, km, expected time, on time / late
 *   ?bus=<id>       lock focus on one bus (no rotation)
 *   ?key=<tv key>   open without a staff login (admin/live-setup.php)
 *
 * Keys: ← → previous / next bus · Space pause rotation · 1 2 3 4 layout · F full screen
 *
 * A driver's SOS or breakdown report takes over the screen: a red banner
 * on every layout, a siren, and (one-bus layout) the map jumps to that bus.
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

$layout  = in_array($_GET['layout'] ?? '', ['focus', 'grid', 'all', 'board'], true) ? (string) $_GET['layout'] : 'focus';
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
.tv.no-ticker .ticker{display:none}
.bar{display:flex;align-items:center;gap:14px;padding:0 20px;background:linear-gradient(90deg,#12264E,#1C3B72);box-shadow:0 2px 14px rgba(0,0,0,.4);z-index:5}
.bar .brand{font-weight:800;font-size:19px;white-space:nowrap;display:flex;align-items:center;gap:10px}
.bar .brand .logo{height:48px;width:auto;background:#fff;border-radius:10px;padding:3px 9px;box-shadow:0 2px 8px rgba(0,0,0,.25)}
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
.info .np{font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
.up{position:absolute;right:20px;top:64px;z-index:3;width:min(380px,40%);background:rgba(11,18,32,.88);backdrop-filter:blur(6px);border:1px solid var(--line);border-radius:16px;padding:12px 14px;font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
.up h4{margin:0 0 6px;font-size:14px;color:#FFC08A;font-weight:800;letter-spacing:.02em}
.up .r{display:flex;gap:10px;align-items:baseline;padding:6px 0;border-top:1px solid var(--line);font-size:15px}
.up .r:first-of-type{border-top:0}
.up .r b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.up .r span{color:var(--mut);font-size:13px;white-space:nowrap;font-variant-numeric:tabular-nums}
.up .r.next b{color:#fff;font-size:17px}
.up .tm{margin-top:10px}
.ann{position:absolute;left:50%;top:14px;transform:translateX(-50%);z-index:4;max-width:min(900px,calc(100% - 40px));background:linear-gradient(90deg,#D96A10,#F07C1F);color:#fff;border-radius:14px;padding:10px 18px;
  font:700 19px/1.4 'Noto Sans Devanagari',Inter,system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.45);transition:opacity .5s,transform .5s}
.ann.off{opacity:0;transform:translate(-50%,-12px);pointer-events:none}
.vhint{position:fixed;right:22px;bottom:78px;z-index:9;background:#F07C1F;color:#fff;border:0;border-radius:16px;padding:14px 20px;font:800 17px/1.3 'Noto Sans Devanagari',Inter,system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.5);cursor:pointer}
.lf-poi{--lf-poi-bg:#1b2a45;--lf-poi-fg:#fff}
.lf-poi .lf-poi-i{background:#3b2610}
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
.info .top{display:flex;gap:14px;align-items:center}
.info .bimg{width:128px;height:72px;object-fit:cover;border-radius:12px;background:#fff;flex:0 0 auto;box-shadow:0 4px 14px rgba(0,0,0,.4)}
.info .hl{font:600 15px/1.5 'Noto Sans Devanagari',Inter,system-ui,sans-serif;color:#dbe4f3;margin-top:8px}
.info .lf-alert{font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
.alarm{position:fixed;left:0;right:0;top:64px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 16px;background:#b91c1c;color:#fff;font:800 18px/1.35 'Noto Sans Devanagari',Inter,system-ui,sans-serif;animation:tvAlarm 1s ease-in-out infinite;z-index:8;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.45)}
.alarm .lf-plate{transform:translateY(-1px)}
@keyframes tvAlarm{50%{background:#ef4444}}
/* the red bar sits over the top of the stage: move what lives there below it */
body.has-alarm .ann{top:62px}
body.has-alarm .up{top:112px}
body.has-alarm .idx,body.has-alarm .prog{top:48px}
body.has-alarm .board{padding-top:56px}
/* board — a railway departure board */
.board{position:absolute;inset:0;background:#05070d;padding:10px 16px;overflow:hidden;font-family:'Noto Sans Devanagari',Inter,system-ui,sans-serif}
.board table{width:100%;border-collapse:collapse;table-layout:fixed}
.board th{color:#FFC08A;font-size:14px;font-weight:800;text-align:left;padding:8px 10px;border-bottom:2px solid #5a3a12;white-space:nowrap}
.board td{padding:9px 10px;font-size:19px;font-weight:700;color:#ffd166;border-bottom:1px solid #1a2233;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums}
.board td.w{color:#fff}
.board td small{display:block;font-size:13px;color:#93a4be;font-weight:600;overflow:hidden;text-overflow:ellipsis}
.board td .chip{display:inline-block;padding:3px 10px;border-radius:999px;color:#fff;font-size:15px}
.board tr.red td{background:rgba(220,38,38,.18)}
.board .pg{position:absolute;right:18px;bottom:8px;color:#93a4be;font-size:13px}
.board .none{color:#93a4be;text-align:center;padding:60px 0;font-size:24px}
.ticker .tk .lf-plate{transform:translateY(-1px)}
@media(max-width:820px){.board td:nth-child(n+6),.board th:nth-child(n+6){display:none}.info .bimg{width:84px;height:48px}.up{display:none}.focus.has-cam{grid-template-columns:1fr;grid-template-rows:1fr 34%}.info{min-width:0;left:10px;right:10px;bottom:10px}.info .nm{font-size:20px}.info .speed{font-size:36px}.bar .counts,.bar .brand span{display:none}}
</style>
</head>
<body>
<div class="tv" id="tv">
  <header class="bar">
    <div class="brand"><img class="logo" src="<?= Security::e(LiveFleet::asset('shg-logo.svg')) ?>" alt="<?= Security::e($company) ?>"> <span>Live Fleet</span></div>
    <div class="counts"><span><i style="background:#16a34a"></i><b id="nLive">0</b></span><span><i style="background:#f59e0b"></i><b id="nWeak">0</b></span><span><i style="background:#dc2626"></i><b id="nOff">0</b></span></div>
    <div class="sp"></div>
    <div class="ctl">
      <button type="button" data-layout="focus">▶ One bus</button>
      <button type="button" data-layout="grid">▦ Grid</button>
      <button type="button" data-layout="all">🗺 All</button>
      <button type="button" data-layout="board">🚉 Board</button>
      <button type="button" id="bPause">⏸ Pause</button>
      <button type="button" id="bVoice">🔇 आवाज</button>
      <button type="button" id="bFull">⛶ Full screen</button>
    </div>
    <span class="conn" id="conn" title="connection"></span>
    <div class="clock" id="clock">--:--<small></small></div>
  </header>
  <div class="alarm" id="alarm" hidden></div>
  <main class="stage" id="stage"></main>
  <footer class="ticker" id="ticker"></footer>
</div>
<button type="button" class="vhint" id="vHint" hidden>🔊 घोषणा सुन्न यहाँ थिच्नुहोस्<br><small style="font-weight:600">Tap once to hear Nepali announcements</small></button>

<script src="<?= Security::e(LiveFleet::asset('livefleet.js')) ?>"></script>
<script>
(function () {
  var LF = window.LiveFleet, esc = LF.esc;
  var KEY = <?= Security::jsonForHtml($key) ?>;
  var ROTATE = <?= $rotate ?> * 1000;
  var LOCK = <?= $lockBus ?>;
  var layout = <?= Security::jsonForHtml($layout) ?>;
  var stage = document.getElementById('stage');
  var S = { buses: [], focusId: LOCK || null, paused: !!LOCK, rotT0: Date.now(), lastOk: 0, view: null, alarmUntil: 0 };
  var IMG_BUS = '/assets/img/bus-shg.webp';
  function redAlerts(b) { return (b.alerts || []).filter(function (a) { return a.level === 'red'; }); }

  function api() { return '/api/live-fleet.php' + (KEY ? '?key=' + encodeURIComponent(KEY) : ''); }
  function onRoad(b) { return b.pos && (b.status === 'live' || b.status === 'weak' || b.status === 'waiting' || b.status === 'offline'); }
  function shown() {
    // Buses worth a screen: on the road first; parked ones only when nothing is moving.
    var road = S.buses.filter(onRoad);
    return road.length ? road : S.buses.filter(function (b) { return b.pos; });
  }
  function byId(id) { for (var i = 0; i < S.buses.length; i++) if (S.buses[i].id === id) return S.buses[i]; return null; }
  /* 📍 where it is now · ➡️ the town ahead · 🚏 the next stop — in Nepali, km and time. */
  function stopLine(b) {
    var st = b.stop, out = [];
    // "Near X" next to "X ahead, 12 km" says the same thing twice — keep the line with the distance.
    if (b.place && (b.place.at || !b.ahead || b.ahead.name !== b.place.name)) out.push('📍 अहिले <b>' + esc(b.place.name) + '</b>' + (b.place.at ? '' : ' नजिक'));
    else if (st) out.push('📍 ' + (st.at ? '' : 'नजिक ') + '<b>' + esc(LF.nm(st.near_ne, st.near)) + '</b>');
    if (b.ahead) out.push('➡️ अगाडि <b>' + esc(b.ahead.name) + '</b> · ' + LF.neKm(b.ahead.km) + ' · ' + LF.neDur(b.ahead.min));
    if (st && st.next) out.push('🚏 अर्को स्टप <b>' + esc(LF.nm(st.next_ne, st.next)) + '</b> · ' + LF.neKm(st.next_km) + ' · करिब ' + LF.neDur(st.next_min));
    if (!out.length && b.route) out.push(esc(b.route.label));
    return out.join('<br>');
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
    root.innerHTML = '<div class="fmap"><div class="prog"><i></i></div><div class="idx" hidden></div><div class="up" hidden></div><div class="info" hidden></div><div class="splash" hidden></div></div><div class="fcam" hidden><div class="lf-cams"></div></div>';
    stage.appendChild(root);
    var mapEl = document.createElement('div');
    root.querySelector('.fmap').insertBefore(mapEl, root.querySelector('.prog'));
    var fm = new LF.FleetMap(mapEl, { look: 'night', controls: false, glideMs: 1000 });
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
        info.innerHTML = '<div class="top"><img class="bimg" src="' + IMG_BUS + '" alt="" onerror="this.remove()"><div class="nm">' + LF.plate(b.number, 'lg')
          + '<small>' + esc(b.name) + (b.route ? ' · ' + esc(b.route.label) : '') + '</small></div></div>'
          + LF.alertsHtml(b, false)
          + '<div class="row"><span class="speed">' + kmh(b) + ' <small>km/h</small></span>' + LF.pill(b.status, true)
          + (b.battery != null ? '<span style="color:var(--mut)">🔋 ' + b.battery + '%' + (b.phone && b.phone.charging ? ' ⚡' : '') + '</span>' : '') + '</div>'
          + '<div class="hl">' + LF.headline(b) + '</div>'
          + '<div class="stop np">' + stopLine(b) + '</div>'
          + '<div class="row" style="color:var(--mut);font-size:14px">' + (b.driver ? '👤 ' + esc(b.driver.name) + ' · ' : '') + 'GPS ' + esc(LF.ago(b.age_s))
          + (b.trip ? ' · ' + b.trip.km + ' km driven' : '') + '</div>';
        var up = root.querySelector('.up'), ups = b.stop && b.stop.upcoming ? b.stop.upcoming.slice(0, 4) : [];
        var towns = (b.towns || []).slice(0, ups.length ? 3 : 4);
        up.hidden = !ups.length && !towns.length && !(b.temples || []).length;
        var row = function (name, km, min, first) {
          return '<div class="r' + (first ? ' next' : '') + '"><b>' + esc(name) + '</b><span>' + LF.neKm(km) + ' · ' + LF.neDur(min)
            + '<br>' + LF.neClock(Date.now() + min * 60000) + '</span></div>';
        };
        up.innerHTML = (towns.length ? '<h4>➡️ आउने ठाउँहरू</h4>' + towns.map(function (t, i) { return row(t.name, t.km, t.min, i === 0); }).join('') : '')
          + (ups.length ? '<div class="tm"><h4>🚏 आउने स्टपहरू</h4>' + ups.map(function (u, i) { return row(LF.nm(u.ne, u.name), u.km, u.min, i === 0 && !towns.length); }).join('') + '</div>' : '')
          + ((b.temples || []).length ? '<div class="tm"><h4>🛕 नजिकैका मन्दिर</h4>' + b.temples.slice(0, 3).map(function (t) {
            return '<div class="r"><b>' + esc(t.name) + '</b><span>' + LF.neKm(t.km) + '</span></div>';
          }).join('') + '</div>' : '');
        fm.ready.then(function () { fm.setPois(LF.poisFor(b)); }).catch(function () {});
        var cams = b.cameras || [];
        root.classList.toggle('has-cam', cams.length > 0);
        root.querySelector('.fcam').hidden = !cams.length;
        LF.renderCams(root.querySelector('.fcam .lf-cams'), cams.slice(0, 3));
        setTimeout(function () { fm.resize(); }, 50);
      },
      tick: function () {
        var bar = root.querySelector('.prog i'), list = shown();
        if (S.paused || list.length < 2 || Date.now() < S.alarmUntil) { bar.style.width = '0'; return; }
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
            var fm = new LF.FleetMap(m, { look: 'night', controls: false, interactive: false, labels: false });
            t = tiles[b.id] = { el: el, fm: fm, placed: false };
            fm.ready.then(function () { fm.select(b.id); }).catch(function () {});
          }
          var s = LF.STATUS[b.status] || LF.STATUS.idle;
          t.el.style.borderColor = s.c;
          t.fm.ready.then(function () {
            t.fm.update([b]);
            if (!t.placed) { t.placed = true; t.fm.map.jumpTo({ center: [b.pos.lng, b.pos.lat], zoom: 13.5 }); t.fm.following = true; t.fm.selected = b.id; }
          }).catch(function () {});
          t.el.querySelector('.tl').innerHTML = '<div class="t1"><b>' + esc(b.name) + ' · ' + esc(b.number) + '</b><span>' + esc(s.np)
            + (b.place ? ' · 📍 ' + esc(b.place.name) : '')
            + (b.stop && b.stop.next ? ' · 🚏 ' + esc(LF.nm(b.stop.next_ne, b.stop.next)) + ' ' + LF.neKm(b.stop.next_km) + ', ' + LF.neDur(b.stop.next_min) : '')
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
    var fm = new LF.FleetMap(m, { look: 'night', controls: false });
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

  /* 🚉 Like a station departure board: one row per vehicle number. Buses with
     a red alert first, then the ones on the road by expected arrival, then
     the rest. More rows than fit → pages that turn every 12 s. */
  function boardView() {
    var root = document.createElement('div');
    root.className = 'board';
    stage.appendChild(root);
    var page = 0, pageT0 = Date.now(), pages = 1;
    function rank(b) { return redAlerts(b).length ? 0 : onRoad(b) ? 1 : b.status === 'parked' ? 3 : 2; }
    function eta(b) { return b.stop && b.stop.next_min != null ? b.stop.next_min : 1e9; }
    function draw() {
      var list = S.buses.slice().sort(function (a, b) { return rank(a) - rank(b) || eta(a) - eta(b) || (a.number < b.number ? -1 : 1); });
      var per = Math.max(3, Math.floor((root.clientHeight - 70) / 52));
      pages = Math.max(1, Math.ceil(list.length / per));
      if (page >= pages) page = 0;
      var rows = list.slice(page * per, page * per + per).map(function (b) {
        var st = b.stop || {}, s = LF.STATUS[b.status] || LF.STATUS.idle, red = redAlerts(b);
        var here = red.length ? red[0].icon + ' ' + red[0].ne : b.place ? (b.place.at ? '' : 'नजिक ') + b.place.name : st.near ? LF.nm(st.near_ne, st.near) : s.np;
        var late = b.status === 'parked' ? '' : LF.neLate(b.delay);
        return '<tr class="' + (red.length ? 'red' : '') + '"><td>' + LF.plate(b.number) + '<small>' + esc(b.name) + '</small></td>'
          + '<td class="w">' + esc(b.route ? b.route.label : '—') + '<small>' + (b.driver ? '👤 ' + esc(b.driver.name) : '') + '</small></td>'
          + '<td class="w">' + esc(here) + '<small>' + LF.pill(b.status, true) + '</small></td>'
          + '<td>' + (st.next ? esc(LF.nm(st.next_ne, st.next)) + '<small>' + LF.neKm(st.next_km) + '</small>' : '—') + '</td>'
          + '<td>' + (st.next_min != null && onRoad(b) ? LF.neClock(Date.now() + st.next_min * 60000) + '<small>' + LF.neDur(st.next_min) + '</small>' : '—') + '</td>'
          + '<td>' + (late ? '<span class="chip" style="background:' + LF.lateColor(b.delay) + '">' + esc(late) + '</span>' : '—') + '</td>'
          + '<td>' + (kmh(b) ? LF.ne(kmh(b)) + ' <small style="display:inline">किमी/घण्टा</small>' : '—') + '<small>' + esc(LF.ago(b.age_s)) + '</small></td></tr>';
      }).join('');
      root.innerHTML = '<table><colgroup><col style="width:15%"><col style="width:17%"><col style="width:19%"><col style="width:15%"><col style="width:14%"><col style="width:10%"><col style="width:10%"></colgroup>'
        + '<thead><tr><th>गाडी नं. · Vehicle</th><th>रुट · Route</th><th>अहिले · Now</th><th>अर्को स्टप · Next</th><th>पुग्ने समय · ETA</th><th>स्थिति</th><th>गति · Speed</th></tr></thead>'
        + '<tbody>' + (rows || '<tr><td colspan="7" class="none">कुनै गाडी छैन · No vehicles</td></tr>') + '</tbody></table>'
        + (pages > 1 ? '<div class="pg">पेज ' + LF.ne(page + 1) + ' / ' + LF.ne(pages) + '</div>' : '');
    }
    return {
      update: draw,
      tick: function () { if (pages > 1 && Date.now() - pageT0 > 12000) { page = (page + 1) % pages; pageT0 = Date.now(); draw(); } },
      destroy: function () {}
    };
  }

  function setLayout(l) {
    layout = l;
    destroyView();
    S.view = l === 'grid' ? gridView() : l === 'all' ? allView() : l === 'board' ? boardView() : focusView();
    document.getElementById('tv').classList.toggle('no-ticker', l === 'grid' || l === 'board');
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
      return '<span class="tk' + (layout === 'focus' && b.id === S.focusId ? ' cur' : '') + '" data-id="' + b.id + '"><i style="background:' + (redAlerts(b).length ? '#dc2626' : s.c) + '"></i>'
        + LF.plate(b.number || b.name, 'sm') + ' <small>' + (v ? v + ' km/h' : esc(s.np)) + (b.place && b.status !== 'parked' ? ' · ' + esc(b.place.name) : '') + '</small></span>';
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
    else if (e.key === '4') setLayout('board');
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
  /* 🆘 A red banner on every layout while any bus has an SOS / breakdown /
     overspeed alert; a tap opens that bus. */
  var alarmEl = document.getElementById('alarm');
  function renderAlarm() {
    var rows = [];
    S.buses.forEach(function (b) {
      redAlerts(b).forEach(function (a) {
        rows.push('<span data-id="' + b.id + '">' + esc(a.icon) + ' ' + LF.plate(b.number, 'sm') + ' ' + esc(a.ne) + (b.place ? ' — ' + esc(b.place.name) : '') + '</span>');
      });
    });
    alarmEl.hidden = !rows.length;
    document.body.classList.toggle('has-alarm', rows.length > 0);
    var html = rows.join('<span style="opacity:.6">·</span>');
    if (alarmEl._h !== html) { alarmEl.innerHTML = html; alarmEl._h = html; }
  }
  alarmEl.addEventListener('click', function (e) {
    var t = e.target.closest('[data-id]'), b = t && byId(+t.dataset.id);
    if (!b || !b.pos) return;
    if (layout !== 'focus') setLayout('focus');
    S.focusId = b.id; S.rotT0 = Date.now(); S.view.update(); renderTicker();
  });

  /* 📢 Nepali announcements: a banner on screen, spoken after one tap on 🔊. */
  var tracker = new LF.Tracker(), annT = null;
  var voice = new LF.Announcer(function (text, icon, level) {
    var el = stage.querySelector('.ann');
    if (!el) { el = document.createElement('div'); el.className = 'ann off'; stage.appendChild(el); }
    el.textContent = (icon || '📢') + ' ' + text;
    el.style.background = level === 'alarm' ? '#b91c1c' : level === 'warn' ? 'linear-gradient(90deg,#b45309,#d97706)' : '';
    el.classList.remove('off');
    clearTimeout(annT);
    annT = setTimeout(function () { el.classList.add('off'); }, 20000);
  }, function () {
    // The browser refused to speak (no tap since the page loaded): ask for one.
    bVoice.classList.remove('on'); bVoice.textContent = '🔇 आवाज'; vHint.hidden = false;
  });
  var bVoice = document.getElementById('bVoice'), vHint = document.getElementById('vHint');
  function setVoice(on) {
    on = voice.enable(on);
    bVoice.classList.toggle('on', on); bVoice.textContent = on ? '🔊 आवाज' : '🔇 आवाज';
    vHint.hidden = on || !voice.supported();
    try { localStorage.setItem('lf:tvVoice', on ? '1' : '0'); } catch (e) {}
  }
  bVoice.onclick = function () { setVoice(!voice.on); };
  vHint.onclick = function () { setVoice(true); };
  // Browsers only speak after a tap: offer the big button unless the TV was told to stay quiet.
  try { vHint.hidden = !voice.supported() || localStorage.getItem('lf:tvVoice') === '0'; } catch (e) { vHint.hidden = !voice.supported(); }

  LF.poll(api, 1000, function (d) {
    S.buses = d.buses;
    S.lastOk = Date.now();
    document.getElementById('nLive').textContent = d.counts.live;
    document.getElementById('nWeak').textContent = d.counts.weak;
    document.getElementById('nOff').textContent = d.counts.offline + d.counts.waiting;
    tracker.limit = d.limit || tracker.limit;
    var evs = tracker.events(d.buses);
    evs.forEach(function (ev) {
      // An SOS / breakdown: show that bus now and hold it for 2 minutes.
      if (ev.level === 'alarm' && layout === 'focus' && byId(ev.busId) && byId(ev.busId).pos) { S.focusId = ev.busId; S.alarmUntil = Date.now() + 120000; }
    });
    if (S.view) S.view.update();
    renderTicker();
    renderAlarm();
    evs.forEach(function (ev) { voice.say(ev.text, ev.icon, ev.speak, ev.level); });
    if (d.need_cells && d.need_cells.length) LF.warmCells(d.need_cells, KEY);
  }, function (e) {
    if (e && (e.status === 401 || e.status === 403)) {
      stage.innerHTML = '<div class="splash"><div class="big">Signed out</div><div class="sub">Open the TV link again from Live Fleet → Drivers &amp; CCTV.</div></div>';
    }
  });
})();
</script>
</body>
</html>
