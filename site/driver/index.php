<?php
/**
 * driver/ — the driver's phone app for Live Fleet (Oct 2026).
 *
 *   1. Log in with Driver ID + PIN (given in admin/live-setup.php)
 *   2. Pick the bus → Start trip
 *   3. Leave the screen on, phone on the charger: a GPS fix goes to the
 *      office every second (api/driver.php). With no signal the fixes are
 *      kept on the phone and sent in one go when the signal returns.
 *
 * Big buttons, English + Nepali, nothing else on the screen — it is used
 * by someone about to drive a bus.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

$company = Settings::getString('company_name', APP_NAME);
$osmand  = appUrl('/api/fleet-osmand.php');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#12264E">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Driver · <?= Security::e($company) ?></title>
<link rel="manifest" href="/driver/manifest.webmanifest">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<style>
:root{--navy:#12264E;--ok:#16a34a;--warn:#f59e0b;--bad:#dc2626;--ink:#16233C;--mut:#6B7688;--line:#E4E9F1;--bg:#F4F6FB;--card:#fff}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;font-size:17px;line-height:1.45}
header{background:linear-gradient(90deg,#12264E,#1C3B72);color:#fff;padding:calc(12px + env(safe-area-inset-top)) 16px 12px;display:flex;align-items:center;gap:10px}
header img{width:36px;height:36px;background:#fff;border-radius:10px;padding:3px}
header b{font-size:17px}
header small{display:block;color:#FFC08A;font-size:11.5px;letter-spacing:.1em;text-transform:uppercase}
header .out{margin-left:auto;color:#cdd6e6;font-size:14px;background:none;border:0;padding:8px}
main{max-width:520px;margin:0 auto;padding:16px 16px calc(24px + env(safe-area-inset-bottom))}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 1px 6px rgba(18,38,78,.06)}
h1{font-size:23px;margin:0 0 4px}
.np{color:var(--mut);font-size:15px}
label{display:block;font-weight:700;margin:14px 0 6px}
input{width:100%;font:inherit;font-size:22px;padding:14px;border:2px solid var(--line);border-radius:14px;background:#fff;color:var(--ink)}
input:focus{outline:none;border-color:#2E5FA8}
.btn{display:flex;width:100%;align-items:center;justify-content:center;gap:10px;min-height:62px;border:0;border-radius:16px;font:inherit;font-size:20px;font-weight:800;color:#fff;background:#2E5FA8;margin-top:16px;cursor:pointer}
.btn small{font-weight:600;font-size:15px;opacity:.9}
.btn.go{background:var(--ok)}
.btn.stop{background:var(--bad)}
.btn.ghost{background:#fff;color:var(--ink);border:2px solid var(--line);font-size:16px;min-height:52px}
.btn:disabled{opacity:.55}
.err{display:none;margin-top:12px;padding:12px 14px;border-radius:12px;background:#FBE3E3;color:#8a1f1f;font-weight:600}
.err.show{display:block}
.note{margin-top:12px;padding:12px 14px;border-radius:12px;background:#FFF4D6;color:#7a5200;font-size:15px}
.bus{display:flex;gap:12px;align-items:center;padding:14px;border:2px solid var(--line);border-radius:14px;margin-top:10px;cursor:pointer;background:#fff}
.bus input{width:24px;height:24px;flex:0 0 auto;margin:0}
.bus b{display:block;font-size:17px}
.bus span{display:block;font-size:14px;color:var(--mut)}
.bus.sel{border-color:#2E5FA8;background:#F0F5FC}
.bus .tag{margin-left:auto;font-size:12px;font-weight:800;padding:4px 9px;border-radius:999px;background:#E4F6EC;color:#178A50;white-space:nowrap}
.bus .tag.busy{background:#FFF4D6;color:#7a5200}
.live{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px}
.orb{width:150px;height:150px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:22px;position:relative;background:var(--c,#16a34a);margin:6px 0 4px}
.orb small{font-size:14px;font-weight:700;opacity:.95}
.orb::after{content:'';position:absolute;inset:-10px;border-radius:50%;border:5px solid var(--c,#16a34a);opacity:.35;animation:ring 1.6s ease-out infinite}
@keyframes ring{0%{transform:scale(.9);opacity:.5}100%{transform:scale(1.25);opacity:0}}
.speed{font-size:56px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.speed small{font-size:20px;color:var(--mut)}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;margin-top:10px}
.stats div{background:var(--bg);border-radius:12px;padding:10px;text-align:left;font-size:13px;color:var(--mut)}
.stats b{display:block;font-size:18px;color:var(--ink);font-variant-numeric:tabular-nums}
details{margin-top:6px}
summary{font-weight:700;cursor:pointer;padding:6px 0}
.copy{display:flex;gap:8px;align-items:center;margin:8px 0}
.copy code{flex:1;font-size:12.5px;word-break:break-all;background:var(--bg);padding:8px 10px;border-radius:10px}
.copy button{font:inherit;font-size:14px;padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:#fff}
[hidden]{display:none!important}
</style>
</head>
<body>
<header>
  <img src="/assets/img/logo.png" alt="">
  <div><b><?= Security::e($company) ?></b><small>Driver · ड्राइभर</small></div>
  <button class="out" id="bOut" type="button" hidden>Log out</button>
</header>
<main>

  <!-- 1. Login -->
  <section id="sLogin" hidden>
    <div class="card">
      <h1>Driver login</h1>
      <div class="np">ड्राइभर लगइन — कार्यालयबाट पाएको आईडी र पिन हाल्नुहोस्</div>
      <form id="fLogin" autocomplete="off">
        <label for="iCode">Driver ID · ड्राइभर आईडी</label>
        <input id="iCode" name="code" autocapitalize="characters" autocorrect="off" spellcheck="false" placeholder="D101" required>
        <label for="iPin">PIN · पिन</label>
        <input id="iPin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="••••" required>
        <button class="btn" type="submit" id="bLogin">Login · लगइन</button>
        <div class="err" id="eLogin"></div>
      </form>
    </div>
  </section>

  <!-- 2. Pick the bus -->
  <section id="sBus" hidden>
    <div class="card">
      <h1 id="hello">Namaste</h1>
      <div class="np">Choose your bus · आफ्नो बस छान्नुहोस्</div>
      <div id="busList"></div>
      <button class="btn go" type="button" id="bStart">▶ Start trip <small>· यात्रा सुरु</small></button>
      <div class="err" id="eBus"></div>
      <div class="note" id="nBus" hidden></div>
    </div>
  </section>

  <!-- 3. Driving -->
  <section id="sRun" hidden>
    <div class="card live">
      <div class="np" id="runBus">—</div>
      <div class="orb" id="orb"><span id="orbT">LIVE</span><small id="orbS">लाइभ</small></div>
      <div class="speed"><span id="spd">0</span> <small>km/h</small></div>
      <div class="stats">
        <div>GPS accuracy<b id="stAcc">—</b></div>
        <div>Last sent · पठाइयो<b id="stLast">—</b></div>
        <div>Points sent<b id="stSent">0</b></div>
        <div>Waiting (no net)<b id="stQueue">0</b></div>
        <div>Battery<b id="stBat">—</b></div>
        <div>Screen awake<b id="stWake">—</b></div>
      </div>
      <div class="note" id="nRun" hidden></div>
      <div class="err" id="eRun"></div>
    </div>
    <div class="card">
      <b>Keep this screen open, phone on the charger.</b>
      <div class="np">यो स्क्रिन खुला राख्नुहोस् र फोन चार्जमा राख्नुहोस्। स्क्रिन बन्द भए लोकेसन जाँदैन।</div>
      <button class="btn stop" type="button" id="bEnd">■ End trip <small>· यात्रा अन्त्य</small></button>
      <details>
        <summary>Background mode (screen off) · स्क्रिन बन्द गर्दा पनि</summary>
        <div class="np" style="font-size:14px">Install the free <b>Traccar Client</b> app, then set: Server URL and Device identifier below, Frequency <b>5</b>, Accuracy <b>High</b>, and switch Service status ON. It keeps sending even with the screen off.</div>
        <div class="copy"><code id="cUrl"><?= Security::e($osmand) ?></code><button type="button" data-copy="cUrl">Copy</button></div>
        <div class="copy"><code id="cId">—</code><button type="button" data-copy="cId">Copy</button></div>
        <div class="np" style="font-size:13px">Keep the Device identifier secret — it works like your PIN.</div>
      </details>
    </div>
  </section>

</main>
<script>
(function () {
  'use strict';
  var KEY = 'lf_drv';
  var S = { auth: null, buses: [], busId: null, trip: null, queue: [], sent: 0, lastSent: 0, lastFix: null, lastSpd: null,
            inflight: false, watch: null, wake: null, battery: null, online: true, hiddenAt: 0 };
  try { S.auth = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function show(id) { ['sLogin', 'sBus', 'sRun'].forEach(function (s) { $(s).hidden = s !== id; }); $('bOut').hidden = id === 'sLogin'; }
  function err(id, msg) { var e = $(id); e.textContent = msg || ''; e.classList.toggle('show', !!msg); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function api(action, body) {
    var headers = { 'Content-Type': 'application/json' };
    if (S.auth && S.auth.token) headers['X-Driver-Token'] = S.auth.token;
    return fetch('/api/driver.php', { method: 'POST', headers: headers, credentials: 'same-origin', cache: 'no-store',
      body: JSON.stringify(Object.assign({ action: action }, body || {})) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || !j.ok) { var e = new Error(j.error || ('Server error ' + r.status)); e.status = r.status; throw e; }
          return j.data || {};
        });
      });
  }

  function signedOut(msg) {
    stopGps();
    S.auth = null; S.trip = null; S.queue = [];
    try { localStorage.removeItem(KEY); } catch (e) {}
    show('sLogin');
    err('eLogin', msg || '');
  }

  /* ── 1. login ─────────────────────────────────────────────────── */
  $('fLogin').addEventListener('submit', function (e) {
    e.preventDefault();
    err('eLogin', '');
    $('bLogin').disabled = true;
    api('login', { code: $('iCode').value, pin: $('iPin').value }).then(function (d) {
      S.auth = { token: d.token, name: d.driver.name, code: d.driver.code };
      try { localStorage.setItem(KEY, JSON.stringify(S.auth)); } catch (e2) {}
      $('iPin').value = '';
      busScreen(d.buses);
    }).catch(function (e2) {
      err('eLogin', e2.message || 'No internet. · इन्टरनेट छैन।');
    }).then(function () { $('bLogin').disabled = false; });
  });
  $('bOut').onclick = function () {
    if (S.trip && !confirm('Log out? The trip ends. · लगआउट गर्दा यात्रा अन्त्य हुन्छ।')) return;
    api('logout').catch(function () {}).then(function () { signedOut(''); });
  };

  /* ── 2. bus ───────────────────────────────────────────────────── */
  function busScreen(buses, note) {
    S.buses = buses || [];
    show('sBus');
    $('hello').textContent = 'Namaste, ' + (S.auth ? S.auth.name : '') + ' 🙏';
    var pick = S.buses.filter(function (b) { return b.suggested; })[0] || S.buses[0];
    S.busId = pick ? pick.id : null;
    var sorted = S.buses.slice().sort(function (a, b) { return (b.suggested ? 1 : 0) - (a.suggested ? 1 : 0); });
    $('busList').innerHTML = sorted.map(function (b) {
      return '<label class="bus' + (b.id === S.busId ? ' sel' : '') + '"><input type="radio" name="bus" value="' + b.id + '"' + (b.id === S.busId ? ' checked' : '') + '>'
        + '<span><b>' + esc(b.name) + '</b><span>' + esc(b.number) + '</span></span>'
        + (b.suggested ? '<span class="tag">Today · आज</span>' : '') + (b.busy ? '<span class="tag busy">' + esc(b.busy) + '</span>' : '') + '</label>';
    }).join('') || '<div class="err show">No bus is set up. Call the office.</div>';
    var n = $('nBus'); n.hidden = !note; n.textContent = note || '';
  }
  $('busList').addEventListener('change', function (e) {
    S.busId = +e.target.value;
    Array.prototype.forEach.call(document.querySelectorAll('.bus'), function (l) { l.classList.toggle('sel', l.querySelector('input').checked); });
  });
  $('bStart').onclick = function () {
    var b = S.buses.filter(function (x) { return x.id === S.busId; })[0];
    if (!b) { err('eBus', 'Choose a bus first. · पहिले बस छान्नुहोस्।'); return; }
    if (b.busy && !confirm(b.busy + ' is driving this bus now. Take over? · अर्को ड्राइभरले चलाइरहेको छ। तपाईं लिनुहुन्छ?')) return;
    err('eBus', '');
    $('bStart').disabled = true;
    // Ask for location first, inside the tap — some phones only show the permission prompt then.
    askGps().then(function () { return api('start', { bus_id: b.id }); }).then(function (d) {
      runScreen(d.trip);
    }).catch(function (e) {
      if (e && e.status === 401) return signedOut('Please log in again.');
      err('eBus', e.message);
    }).then(function () { $('bStart').disabled = false; });
  };

  /* ── 3. driving ───────────────────────────────────────────────── */
  function askGps() {
    return new Promise(function (res, rej) {
      if (!navigator.geolocation) { rej(new Error('This phone has no GPS in the browser. Use Chrome.')); return; }
      if (!window.isSecureContext) { rej(new Error('Open this page with https:// for GPS to work.')); return; }
      navigator.geolocation.getCurrentPosition(function () { res(); }, function (e) {
        if (e.code === 1) rej(new Error('Location is blocked. Allow "Location" for this site in browser settings, then try again. · लोकेसन अनुमति दिनुहोस्।'));
        else res();   // no fix yet is fine — the watch keeps trying
      }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    });
  }

  function runScreen(trip) {
    S.trip = trip;
    show('sRun');
    $('runBus').textContent = '🚌 ' + trip.bus;
    $('cId').textContent = S.auth ? S.auth.token : '—';
    startGps();
    keepAwake();
    render();
  }

  function onFix(p) {
    var c = p.coords;
    var pt = { lat: c.latitude, lng: c.longitude, ts: Math.round(p.timestamp || Date.now()),
               spd: c.speed != null && !isNaN(c.speed) ? c.speed : null,
               hdg: c.heading != null && !isNaN(c.heading) ? c.heading : null,
               acc: c.accuracy != null ? Math.round(c.accuracy) : null };
    // Phones may fire several fixes a second; one a second is plenty.
    if (S.lastFix && pt.ts - S.lastFix.ts < 900) return;
    // Some phones never fill in speed; work it out from the last fix (the server does the same).
    if (pt.spd == null && S.lastFix && pt.acc != null && pt.acc <= 100) {
      var dLat = (pt.lat - S.lastFix.lat) * Math.PI / 180, dLng = (pt.lng - S.lastFix.lng) * Math.PI / 180;
      var h = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(pt.lat * Math.PI / 180) * Math.cos(S.lastFix.lat * Math.PI / 180) * Math.pow(Math.sin(dLng / 2), 2);
      var mps = 12742000 * Math.asin(Math.min(1, Math.sqrt(h))) / ((pt.ts - S.lastFix.ts) / 1000);
      if (mps < 45) S.lastSpd = mps;
    } else {
      S.lastSpd = pt.spd;
    }
    S.lastFix = pt;
    S.queue.push(pt);
    if (S.queue.length > 3600) S.queue.splice(0, S.queue.length - 3600);   // keep the last hour
    err('eRun', '');
  }
  function onGpsErr(e) {
    if (e.code === 1) err('eRun', 'Location is blocked. Allow Location for this site in settings. · लोकेसन अनुमति दिनुहोस्।');
  }
  function startGps() {
    if (S.watch != null || !navigator.geolocation) return;
    S.watch = navigator.geolocation.watchPosition(onFix, onGpsErr, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
  }
  function stopGps() {
    if (S.watch != null) navigator.geolocation.clearWatch(S.watch);
    S.watch = null; S.lastFix = null; S.lastSpd = null;
    if (S.wake) { try { S.wake.release(); } catch (e) {} S.wake = null; }
  }

  // Sender: once a second, everything waiting, oldest first (at most 300 per request).
  setInterval(function () {
    if (!S.trip || S.inflight || !S.queue.length) return;
    var batch = S.queue.slice(0, 300);
    S.inflight = true;
    api('ping', { points: batch, battery: S.battery }).then(function (d) {
      S.queue.splice(0, batch.length);
      S.sent += batch.length; S.lastSent = Date.now(); S.online = true;
      if (d.distance_km != null) S.trip.km = d.distance_km;
    }).catch(function (e) {
      if (e.status === 401) return signedOut('The office signed this phone out. Log in again. · फेरि लगइन गर्नुहोस्।');
      if (e.status === 409) {
        stopGps(); S.trip = null; S.queue = [];
        return api('me').then(function (d) { busScreen(d.buses, 'Your trip was ended (another driver started this bus, or the office ended it).'); });
      }
      S.online = false;
    }).then(function () { S.inflight = false; });
  }, 1000);

  function render() {
    if (!S.trip) return;
    var fixAge = S.lastFix ? (Date.now() - S.lastFix.ts) / 1000 : 999;
    var c, t, s;
    if (!S.lastFix || fixAge > 20) { c = '#3b82f6'; t = 'GPS…'; s = 'जीपीएस खोज्दै'; }
    else if (!S.online) { c = '#dc2626'; t = 'NO NET'; s = 'इन्टरनेट छैन'; }
    else if (S.queue.length > 5) { c = '#f59e0b'; t = 'SENDING'; s = 'पठाउँदै'; }
    else { c = '#16a34a'; t = 'LIVE'; s = 'लाइभ'; }
    $('orb').style.setProperty('--c', c); $('orbT').textContent = t; $('orbS').textContent = s;
    var kmh = S.lastSpd != null && fixAge < 20 ? Math.round(S.lastSpd * 3.6) : 0;
    $('spd').textContent = kmh;
    $('stAcc').textContent = S.lastFix && S.lastFix.acc != null ? '±' + S.lastFix.acc + ' m' : '—';
    var ls = S.lastSent ? Math.round((Date.now() - S.lastSent) / 1000) : null;
    $('stLast').textContent = ls == null ? '—' : ls < 2 ? 'now · अहिले' : ls + ' s ago';
    $('stSent').textContent = S.sent;
    $('stQueue').textContent = S.queue.length;
    $('stBat').textContent = S.battery != null ? S.battery + '%' : '—';
    $('stWake').textContent = S.wake ? '✓ yes' : 'keep screen on';
  }
  setInterval(render, 1000);

  $('bEnd').onclick = function () {
    if (!confirm('End the trip? The bus disappears from the live map. · यात्रा अन्त्य गर्ने?')) return;
    $('bEnd').disabled = true;
    // Send what is still waiting first, so the trail reaches the office.
    var flush = S.queue.length ? api('ping', { points: S.queue.slice(-300), battery: S.battery }).catch(function () {}) : Promise.resolve();
    flush.then(function () { return api('end'); }).then(function () {
      stopGps(); S.trip = null; S.queue = [];
      return api('me').then(function (d) { busScreen(d.buses, 'Trip ended. Thank you! · धन्यवाद!'); });
    }).catch(function (e) { err('eRun', e.message); }).then(function () { $('bEnd').disabled = false; });
  };

  /* ── keep the screen on, battery, coming back from a locked screen ── */
  function keepAwake() {
    if (!('wakeLock' in navigator) || S.wake) return;
    navigator.wakeLock.request('screen').then(function (l) {
      S.wake = l;
      l.addEventListener('release', function () { S.wake = null; });
    }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { S.hiddenAt = Date.now(); return; }
    if (S.trip) {
      keepAwake();
      var away = S.hiddenAt ? Math.round((Date.now() - S.hiddenAt) / 60000) : 0;
      var n = $('nRun');
      n.hidden = away < 1;
      n.textContent = 'The screen was off for ' + away + ' min — location was not sent then. Keep the screen on, or use Background mode below. · स्क्रिन बन्द हुँदा लोकेसन गएन।';
    }
  });
  if (navigator.getBattery) {
    navigator.getBattery().then(function (b) {
      var upd = function () { S.battery = Math.round(b.level * 100); };
      upd(); b.addEventListener('levelchange', upd);
    }).catch(function () {});
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
    btn.onclick = function () {
      var txt = $(btn.dataset.copy).textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { btn.textContent = 'Copied ✓'; })
        .catch(function () { prompt('Copy this:', txt); });
    };
  });
  window.addEventListener('beforeunload', function (e) { if (S.trip) { e.preventDefault(); e.returnValue = ''; } });

  /* ── start ────────────────────────────────────────────────────── */
  if (!S.auth) { show('sLogin'); return; }
  api('me').then(function (d) {
    if (d.trip) runScreen(d.trip); else busScreen(d.buses);
  }).catch(function (e) {
    if (e.status === 401) signedOut('');
    else { show('sLogin'); err('eLogin', 'No internet — try again. · इन्टरनेट छैन।'); }
  });
})();
</script>
</body>
</html>
