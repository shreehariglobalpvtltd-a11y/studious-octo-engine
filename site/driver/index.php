<?php
/**
 * driver/ — the phone in the bus for Live Fleet (Oct 2026).
 *
 * Two ways to set a phone up:
 *
 *   Driver phone — the driver logs in with Driver ID + PIN
 *                  (admin/live-setup.php), picks the bus (today's or the one
 *                  the office assigned is ticked) and taps Start.
 *   Bus phone    — a phone that stays in one bus. The office makes a 6-digit
 *                  code for that vehicle number (Live Fleet Setup → Vehicles →
 *                  📲 Pair a phone); the phone opens /driver/?pair=CODE (or
 *                  scans the QR) and is tied to that bus for good. It tracks
 *                  whoever drives — the driver the office assigned to the bus.
 *
 * While driving: leave the screen on, phone on the charger. A GPS fix goes
 * to the office every second (api/driver.php) with the phone's battery,
 * charger, network and screen state. With no signal the fixes wait on the
 * phone — even across a reload — and go in one batch when it returns.
 * Big buttons report an emergency, breakdown, puncture, police check or
 * meal halt to the office TV. Over the speed limit the phone says so aloud.
 *
 * Big buttons, English + Nepali, nothing else on the screen — it is used
 * by someone about to drive a bus.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

$company = Settings::getString('company_name', APP_NAME);
$osmand  = appUrl('/api/fleet-osmand.php');
$pair    = preg_replace('/\D/', '', (string) ($_GET['pair'] ?? ''));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#12264E">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Driver · <?= Security::e($company) ?></title>
<link rel="manifest" href="/driver/manifest.webmanifest">
<link rel="icon" type="image/svg+xml" href="<?= Security::e(LiveFleet::asset('shg-crest.svg')) ?>">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<style>
:root{--navy:#12264E;--ok:#16a34a;--warn:#f59e0b;--bad:#dc2626;--ink:#16233C;--mut:#6B7688;--line:#E4E9F1;--bg:#F4F6FB;--card:#fff}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;font-size:17px;line-height:1.45}
header{background:linear-gradient(90deg,#12264E,#1C3B72);color:#fff;padding:calc(10px + env(safe-area-inset-top)) 16px 10px;display:flex;align-items:center;gap:10px}
header img{width:40px;height:50px;background:#fff;border-radius:10px;padding:3px 5px}
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
input.code{font-size:34px;letter-spacing:.35em;text-align:center;font-variant-numeric:tabular-nums}
.btn{display:flex;width:100%;align-items:center;justify-content:center;gap:10px;min-height:62px;border:0;border-radius:16px;font:inherit;font-size:20px;font-weight:800;color:#fff;background:#2E5FA8;margin-top:16px;cursor:pointer}
.btn small{font-weight:600;font-size:15px;opacity:.9}
.btn.go{background:var(--ok)}
.btn.stop{background:var(--bad)}
.btn.ghost{background:#fff;color:var(--ink);border:2px solid var(--line);font-size:16px;min-height:52px}
.btn:disabled{opacity:.55}
.tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:#E8EDF5;border-radius:14px;padding:5px;margin-bottom:14px}
.tabs button{font:inherit;font-size:15px;font-weight:800;border:0;border-radius:10px;padding:11px 6px;background:none;color:var(--mut);cursor:pointer}
.tabs button.on{background:#fff;color:var(--ink);box-shadow:0 1px 4px rgba(18,38,78,.12)}
.err{display:none;margin-top:12px;padding:12px 14px;border-radius:12px;background:#FBE3E3;color:#8a1f1f;font-weight:600}
.err.show{display:block}
.note{margin-top:12px;padding:12px 14px;border-radius:12px;background:#FFF4D6;color:#7a5200;font-size:15px}
.bus{display:flex;gap:12px;align-items:center;padding:14px;border:2px solid var(--line);border-radius:14px;margin-top:10px;cursor:pointer;background:#fff}
.bus input{width:24px;height:24px;flex:0 0 auto;margin:0}
.bus b{display:block;font-size:17px}
.bus span{display:block;font-size:14px;color:var(--mut)}
.bus.sel{border-color:#2E5FA8;background:#F0F5FC}
.bus .tag{margin-left:auto;font-size:12px;font-weight:800;padding:4px 9px;border-radius:999px;background:#E4F6EC;color:#178A50;white-space:nowrap}
.bus .tag.mine{background:#E7EEFB;color:#1D4ED8}
.bus .tag.busy{background:#FFF4D6;color:#7a5200}
/* an Indian number plate */
.plate{display:inline-flex;align-items:stretch;background:#fff;border:2px solid #111;border-radius:6px;overflow:hidden;line-height:1;vertical-align:middle}
.plate i{font:800 9px/1 system-ui,sans-serif;font-style:normal;color:#fff;background:#1D4ED8;writing-mode:vertical-rl;transform:rotate(180deg);padding:3px 2px;display:flex;align-items:center}
.plate b{font:800 26px/1 'Roboto Condensed','Arial Narrow',Arial,sans-serif;color:#111;letter-spacing:.04em;padding:7px 11px}
.vehicle{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;margin-top:8px}
.vehicle img{width:100%;max-width:340px;border-radius:14px;background:#EEF2F8}
.count{font-size:15px;color:var(--mut)}
.live{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px}
.orb{width:150px;height:150px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:22px;position:relative;background:var(--c,#16a34a);margin:6px 0 4px}
.orb small{font-size:14px;font-weight:700;opacity:.95}
.orb::after{content:'';position:absolute;inset:-10px;border-radius:50%;border:5px solid var(--c,#16a34a);opacity:.35;animation:ring 1.6s ease-out infinite}
@keyframes ring{0%{transform:scale(.9);opacity:.5}100%{transform:scale(1.25);opacity:0}}
.speed{font-size:56px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.speed small{font-size:20px;color:var(--mut)}
.speed.over{color:var(--bad);animation:flash 1s ease-in-out infinite}
@keyframes flash{50%{opacity:.4}}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;margin-top:10px}
.stats div{background:var(--bg);border-radius:12px;padding:10px;text-align:left;font-size:13px;color:var(--mut)}
.stats b{display:block;font-size:18px;color:var(--ink);font-variant-numeric:tabular-nums}
/* report buttons */
.ev{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.ev button{font:inherit;border:2px solid var(--line);background:#fff;border-radius:16px;padding:14px 8px;font-weight:800;font-size:16px;color:var(--ink);cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;min-height:92px}
.ev button span{font-size:30px;line-height:1}
.ev button small{font-weight:600;font-size:12.5px;color:var(--mut)}
.ev button[data-type=sos]{grid-column:1/-1;background:var(--bad);border-color:var(--bad);color:#fff;font-size:22px;min-height:84px;flex-direction:row;gap:12px}
.ev button[data-type=sos] small{color:#fee2e2}
.open{margin-top:10px;padding:12px 14px;border-radius:14px;background:#FBE3E3;color:#8a1f1f;display:flex;gap:10px;align-items:center;font-weight:700}
.open button{margin-left:auto;font:inherit;font-size:14px;font-weight:800;border:0;border-radius:10px;padding:10px 12px;background:#fff;color:#8a1f1f;white-space:nowrap}
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
  <img src="<?= Security::e(LiveFleet::asset('shg-crest.svg')) ?>" alt="">
  <div><b><?= Security::e($company) ?></b><small id="role">Driver · ड्राइभर</small></div>
  <button class="out" id="bOut" type="button" hidden>Log out</button>
</header>
<main>

  <!-- 1. Sign in: a driver, or a phone that stays in one bus -->
  <section id="sLogin" hidden>
    <div class="tabs"><button type="button" data-tab="tDriver" class="on">👤 Driver · ड्राइभर</button><button type="button" data-tab="tPair">🚌 Bus phone · बस फोन</button></div>
    <div class="card" id="tDriver">
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
    <div class="card" id="tPair" hidden>
      <h1>Set up a bus phone</h1>
      <div class="np">यो फोन सधैं एउटै बसमा रहन्छ। कार्यालयको Live Fleet Setup → गाडी → 📲 बाट पाएको ६ अङ्कको कोड हाल्नुहोस्।</div>
      <form id="fPair" autocomplete="off">
        <label for="iPair">Pairing code · कोड</label>
        <input id="iPair" class="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="••••••" value="<?= Security::e($pair) ?>" required>
        <button class="btn" type="submit" id="bPair">Connect this phone · जोड्नुहोस्</button>
        <div class="err" id="ePair"></div>
      </form>
      <div class="note">The code works once, for 15 minutes. · कोड एक पटक, १५ मिनेटसम्म मात्र चल्छ।</div>
    </div>
  </section>

  <!-- 2. Pick the bus (a driver) / start (a bus phone) -->
  <section id="sBus" hidden>
    <div class="card">
      <h1 id="hello">Namaste</h1>
      <div class="np" id="busHint">Choose your bus · आफ्नो बस छान्नुहोस्</div>
      <div id="busList"></div>
      <button class="btn go" type="button" id="bStart">▶ Start trip <small>· यात्रा सुरु</small></button>
      <div class="count" id="autoStart" hidden></div>
      <div class="err" id="eBus"></div>
      <div class="note" id="nBus" hidden></div>
    </div>
  </section>

  <!-- 3. Driving -->
  <section id="sRun" hidden>
    <div class="card live">
      <div id="runBus">—</div>
      <div class="orb" id="orb"><span id="orbT">LIVE</span><small id="orbS">लाइभ</small></div>
      <div class="speed" id="spdBox"><span id="spd">0</span> <small>km/h</small></div>
      <div class="np" id="limitLine"></div>
      <div class="stats">
        <div>GPS accuracy<b id="stAcc">—</b></div>
        <div>Last sent · पठाइयो<b id="stLast">—</b></div>
        <div>Points sent<b id="stSent">0</b></div>
        <div>Waiting (no net)<b id="stQueue">0</b></div>
        <div>Battery · ब्याट्री<b id="stBat">—</b></div>
        <div>Network · नेटवर्क<b id="stNet">—</b></div>
      </div>
      <div class="note" id="nRun" hidden></div>
      <div class="err" id="eRun"></div>
    </div>

    <div class="card">
      <b>Tell the office · कार्यालयलाई खबर</b>
      <div class="np">One tap — it shows on the office TV at once. · एक पटक थिच्नुहोस्, कार्यालयको टिभीमा तुरुन्त देखिन्छ।</div>
      <div id="openEv"></div>
      <div class="ev" id="evBtns"></div>
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
  var KEY = 'lf_drv', QKEY = 'lf_drv_q';
  var S = { auth: null, me: null, buses: [], busId: null, trip: null, queue: [], sent: 0, lastSent: 0, lastFix: null, lastSpd: null,
            inflight: false, watch: null, wake: null, battery: null, charging: null, online: true, hiddenAt: 0,
            limit: 80, overSince: 0, warnedAt: 0, events: [], open: [] };
  try { S.auth = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function show(id) { ['sLogin', 'sBus', 'sRun'].forEach(function (s) { $(s).hidden = s !== id; }); $('bOut').hidden = id === 'sLogin'; }
  function err(id, msg) { var e = $(id); e.textContent = msg || ''; e.classList.toggle('show', !!msg); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function plate(n) { return '<span class="plate"><i>IND</i><b>' + esc(n || '—') + '</b></span>'; }
  function isBusPhone() { return !!(S.auth && S.auth.kind === 'vehicle'); }

  function api(action, body, keepalive) {
    var headers = { 'Content-Type': 'application/json' };
    if (S.auth && S.auth.token) headers['X-Driver-Token'] = S.auth.token;
    return fetch('/api/driver.php', { method: 'POST', headers: headers, credentials: 'same-origin', cache: 'no-store', keepalive: !!keepalive,
      body: JSON.stringify(Object.assign({ action: action }, body || {})) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || !j.ok) { var e = new Error(j.error || ('Server error ' + r.status)); e.status = r.status; throw e; }
          return j.data || {};
        });
      });
  }

  /* What the phone says about itself with every batch: charger, network, screen. */
  function meta() {
    var c = navigator.connection || {};
    return { charging: S.charging, net: c.type === 'wifi' ? 'wifi' : (c.effectiveType || null), visible: !document.hidden };
  }

  function signedOut(msg) {
    stopGps();
    S.auth = null; S.trip = null; S.queue = [];
    try { localStorage.removeItem(KEY); localStorage.removeItem(QKEY); } catch (e) {}
    show('sLogin');
    err('eLogin', msg || '');
  }
  function remember(d) {
    S.auth = { token: d.token, kind: d.kind || 'driver', name: d.driver ? d.driver.name : '', code: d.driver ? d.driver.code : '', bus: d.bus || null };
    try { localStorage.setItem(KEY, JSON.stringify(S.auth)); } catch (e) {}
  }
  function useMe(d) {
    S.me = d;
    S.events = d.events || S.events;
    if (d.open) S.open = d.open;
    if (d.limit) S.limit = d.limit;
    if (S.auth) {
      S.auth.kind = d.kind || S.auth.kind; S.auth.bus = d.bus || S.auth.bus;
      if (d.driver && d.driver.name) S.auth.name = d.driver.name;
      try { localStorage.setItem(KEY, JSON.stringify(S.auth)); } catch (e) {}
    }
    $('role').textContent = isBusPhone() ? 'Bus phone · बस फोन' : 'Driver · ड्राइभर';
  }

  /* ── 1. sign in ───────────────────────────────────────────────── */
  Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
    b.onclick = function () {
      Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (x) { x.classList.toggle('on', x === b); $(x.dataset.tab).hidden = x !== b; });
    };
  });
  $('fLogin').addEventListener('submit', function (e) {
    e.preventDefault();
    err('eLogin', '');
    $('bLogin').disabled = true;
    api('login', { code: $('iCode').value, pin: $('iPin').value }).then(function (d) {
      remember(d); useMe(d);
      $('iPin').value = '';
      busScreen(d.buses);
    }).catch(function (e2) {
      err('eLogin', e2.message || 'No internet. · इन्टरनेट छैन।');
    }).then(function () { $('bLogin').disabled = false; });
  });
  $('fPair').addEventListener('submit', function (e) {
    e.preventDefault();
    err('ePair', '');
    $('bPair').disabled = true;
    api('pair', { code: $('iPair').value }).then(function (d) {
      remember(d); useMe(d);
      $('iPair').value = '';
      try { history.replaceState(null, '', '/driver/'); } catch (e3) {}   // the code is used up; keep it out of the address bar
      // This tap is the moment to ask for location, then start straight away.
      busScreen(d.buses, 'Connected ✓ This phone now belongs to ' + (d.bus ? d.bus.number : 'this bus') + '. · यो फोन अब यो बसको भयो।');
      $('bStart').click();
    }).catch(function (e2) {
      err('ePair', e2.message || 'No internet. · इन्टरनेट छैन।');
    }).then(function () { $('bPair').disabled = false; });
  });
  $('bOut').onclick = function () {
    var q = isBusPhone() ? 'Disconnect this bus phone? The office must make a new code to connect it again. · यो फोन बसबाट हटाउने?'
                         : 'Log out? The trip ends. · लगआउट गर्दा यात्रा अन्त्य हुन्छ।';
    if ((S.trip || isBusPhone()) && !confirm(q)) return;
    api('logout').catch(function () {}).then(function () { signedOut(''); });
  };

  /* ── 2. bus ───────────────────────────────────────────────────── */
  var autoT = null;
  function busScreen(buses, note) {
    S.buses = buses || [];
    show('sBus');
    clearInterval(autoT); $('autoStart').hidden = true;
    var pick = S.buses.filter(function (b) { return b.suggested; })[0] || S.buses.filter(function (b) { return b.assigned; })[0] || S.buses[0];
    S.busId = pick ? pick.id : null;
    if (isBusPhone()) {
      var b = S.buses[0] || S.auth.bus || {};
      S.busId = b.id || null;
      $('hello').textContent = 'Bus phone · बस फोन';
      $('busHint').textContent = (S.auth.name ? 'Driver today · आजको ड्राइभर: ' + S.auth.name : 'No driver assigned — the office can set one. · ड्राइभर तोकिएको छैन।');
      $('busList').innerHTML = '<div class="vehicle"><img src="/assets/img/bus-shg.webp" alt="" onerror="this.remove()">' + plate(b.number) + '<div class="np">' + esc(b.name || '') + '</div></div>';
      autoStartIfAllowed();
    } else {
      $('hello').textContent = 'Namaste, ' + (S.auth ? S.auth.name : '') + ' 🙏';
      $('busHint').textContent = 'Choose your bus · आफ्नो बस छान्नुहोस्';
      var sorted = S.buses.slice().sort(function (a, b) { return (b.suggested ? 2 : b.assigned ? 1 : 0) - (a.suggested ? 2 : a.assigned ? 1 : 0); });
      $('busList').innerHTML = sorted.map(function (b) {
        return '<label class="bus' + (b.id === S.busId ? ' sel' : '') + '"><input type="radio" name="bus" value="' + b.id + '"' + (b.id === S.busId ? ' checked' : '') + '>'
          + '<span><b>' + esc(b.number) + '</b><span>' + esc(b.name) + '</span></span>'
          + (b.suggested ? '<span class="tag">Today · आज</span>' : b.assigned ? '<span class="tag mine">Yours · तपाईंको</span>' : '')
          + (b.busy ? '<span class="tag busy">' + esc(b.busy) + '</span>' : '') + '</label>';
      }).join('') || '<div class="err show">No bus is set up. Call the office.</div>';
    }
    var n = $('nBus'); n.hidden = !note; n.textContent = note || '';
  }
  /* A bus phone that already has location permission starts by itself — after
     a reboot or a lost charger nobody has to touch it. 10 s to cancel. */
  function autoStartIfAllowed() {
    if (!navigator.permissions || !navigator.permissions.query) return;
    navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
      if (p.state !== 'granted' || !$('sBus').offsetParent) return;
      var left = 10, el = $('autoStart');
      el.hidden = false;
      var tick = function () {
        el.innerHTML = '⏱️ Starting by itself in ' + left + ' s · ' + left + ' सेकेन्डमा आफैं सुरु हुन्छ — <a href="#" id="aCancel">cancel</a>';
        $('aCancel').onclick = function (ev) { ev.preventDefault(); clearInterval(autoT); el.hidden = true; };
        if (left-- <= 0) { clearInterval(autoT); el.hidden = true; $('bStart').click(); }
      };
      tick(); autoT = setInterval(tick, 1000);
    }).catch(function () {});
  }
  $('busList').addEventListener('change', function (e) {
    S.busId = +e.target.value;
    Array.prototype.forEach.call(document.querySelectorAll('.bus'), function (l) { l.classList.toggle('sel', l.querySelector('input').checked); });
  });
  $('bStart').onclick = function () {
    clearInterval(autoT); $('autoStart').hidden = true;
    var b = S.buses.filter(function (x) { return x.id === S.busId; })[0];
    if (!b && !isBusPhone()) { err('eBus', 'Choose a bus first. · पहिले बस छान्नुहोस्।'); return; }
    if (b && b.busy && !confirm(b.busy + ' is driving this bus now. Take over? · अर्को ड्राइभरले चलाइरहेको छ। तपाईं लिनुहुन्छ?')) return;
    err('eBus', '');
    $('bStart').disabled = true;
    // Ask for location first, inside the tap — some phones only show the permission prompt then.
    askGps().then(function () { return api('start', { bus_id: b ? b.id : 0 }); }).then(function (d) {
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
    // Fixes saved before a reload belong to this trip only.
    try {
      var saved = JSON.parse(localStorage.getItem(QKEY) || 'null');
      if (saved && saved.trip === trip.id && saved.points && saved.points.length && !S.queue.length) S.queue = saved.points;
    } catch (e) {}
    S.trip = trip;
    show('sRun');
    var num = (S.auth && S.auth.bus && S.auth.bus.number) || trip.number || '';
    $('runBus').innerHTML = (num ? plate(num) + ' ' : '🚌 ') + '<span class="np">' + esc(num ? trip.bus.replace(num, '').trim() : trip.bus) + '</span>';
    $('cId').textContent = S.auth ? S.auth.token : '—';
    $('limitLine').textContent = 'Speed limit · गति सीमा ' + S.limit + ' km/h';
    renderEvents();
    startGps();
    keepAwake();
    render();
  }

  function onFix(p) {
    var c = p.coords;
    var pt = { lat: c.latitude, lng: c.longitude, ts: Math.round(p.timestamp || Date.now()),
               spd: c.speed != null && !isNaN(c.speed) ? c.speed : null,
               hdg: c.heading != null && !isNaN(c.heading) ? c.heading : null,
               acc: c.accuracy != null ? Math.round(c.accuracy) : null,
               alt: c.altitude != null && !isNaN(c.altitude) ? Math.round(c.altitude) : null };
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
    checkSpeed();
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

  /* Over the limit for 15 s → buzz and say it (again after 2 min if still over).
     Speaking is allowed: the driver tapped Start on this page. */
  function checkSpeed() {
    var kmh = S.lastSpd != null ? S.lastSpd * 3.6 : 0, now = Date.now();
    if (kmh <= S.limit) { S.overSince = 0; return; }
    if (!S.overSince) S.overSince = now;
    if (now - S.overSince < 15000 || now - S.warnedAt < 120000) return;
    S.warnedAt = now;
    if (navigator.vibrate) navigator.vibrate([400, 200, 400]);
    if ('speechSynthesis' in window && window.SpeechSynthesisUtterance) {
      var u = new SpeechSynthesisUtterance('चालकज्यू, कृपया गति घटाउनुहोस्। गति सीमा ' + S.limit + ' किलोमिटर प्रतिघण्टा हो।');
      var v = (speechSynthesis.getVoices() || []).filter(function (x) { return /^(ne|hi)/i.test(x.lang); })[0];
      if (v) u.voice = v;
      u.lang = v ? v.lang : 'hi-IN';
      try { speechSynthesis.speak(u); } catch (e) {}
    }
  }

  // Sender: once a second, everything waiting, oldest first (at most 300 per request).
  var lastMetaSent = 0;
  setInterval(function () {
    if (!S.trip || S.inflight) return;
    // Nothing new to send still reports the phone's state every 30 s (charger pulled at a halt).
    if (!S.queue.length && Date.now() - lastMetaSent < 30000) return;
    var batch = S.queue.slice(0, 300);
    S.inflight = true;
    api('ping', { points: batch, battery: S.battery, meta: meta() }).then(function (d) {
      S.queue.splice(0, batch.length);
      S.sent += batch.length; S.lastSent = Date.now(); S.online = true; lastMetaSent = Date.now();
      if (d.distance_km != null) S.trip.km = d.distance_km;
    }).catch(function (e) {
      if (e.status === 401) return signedOut('The office signed this phone out. Log in again. · फेरि लगइन गर्नुहोस्।');
      if (e.status === 409) {
        stopGps(); S.trip = null; S.queue = [];
        return api('me').then(function (d) { useMe(d); busScreen(d.buses, 'Your trip was ended (another driver started this bus, or the office ended it).'); });
      }
      S.online = false;
    }).then(function () { S.inflight = false; });
  }, 1000);

  // Fixes not yet sent survive a reload or a crashed browser.
  setInterval(function () {
    if (!S.trip) return;
    try { localStorage.setItem(QKEY, JSON.stringify({ trip: S.trip.id, points: S.queue.slice(-3600) })); } catch (e) {}
  }, 5000);

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
    $('spdBox').classList.toggle('over', kmh > S.limit);
    $('stAcc').textContent = S.lastFix && S.lastFix.acc != null ? '±' + S.lastFix.acc + ' m' : '—';
    var ls = S.lastSent ? Math.round((Date.now() - S.lastSent) / 1000) : null;
    $('stLast').textContent = ls == null ? '—' : ls < 2 ? 'now · अहिले' : ls + ' s ago';
    $('stSent').textContent = S.sent;
    $('stQueue').textContent = S.queue.length;
    $('stBat').textContent = S.battery != null ? S.battery + '%' + (S.charging ? ' ⚡' : S.charging === false ? ' 🔌✗' : '') : '—';
    var m = meta();
    $('stNet').textContent = (m.net ? String(m.net).toUpperCase() : navigator.onLine ? 'online' : 'offline') + (S.wake ? ' · 🔆' : '');
  }
  setInterval(render, 1000);

  /* ── report buttons: SOS, breakdown, puncture, police, meal ───────── */
  var EV_SMALL = { sos: 'Emergency · आपतकालीन', breakdown: 'Bus broke down', puncture: 'Tyre puncture', police: 'Police / RTO check', meal: 'Meal / tea halt' };
  function renderEvents() {
    $('evBtns').innerHTML = (S.events || []).map(function (e) {
      return '<button type="button" data-type="' + esc(e.type) + '"><span>' + esc(e.icon) + '</span>' + esc(e.ne) + '<small>' + esc(EV_SMALL[e.type] || e.en) + '</small></button>';
    }).join('');
    $('openEv').innerHTML = S.open.map(function (o) {
      return '<div class="open"><span>' + esc(o.icon) + ' ' + esc(o.ne) + ' — sent · पठाइयो</span><button type="button" data-clear="' + o.id + '">✓ Solved · समाधान</button></div>';
    }).join('');
  }
  $('evBtns').addEventListener('click', function (e) {
    var b = e.target.closest('[data-type]');
    if (!b) return;
    var ev = (S.events || []).filter(function (x) { return x.type === b.dataset.type; })[0];
    if (!ev) return;
    if (!confirm(ev.icon + ' ' + ev.ne + ' — send to the office? · कार्यालयमा पठाउने?')) return;
    b.disabled = true;
    // Send the latest position first so the office sees where it happened.
    var flush = S.queue.length ? api('ping', { points: S.queue.slice(-300), battery: S.battery, meta: meta() }).then(function () { S.queue = []; }).catch(function () {}) : Promise.resolve();
    flush.then(function () { return api('event', { type: ev.type }); }).then(function (d) {
      S.open = S.open.filter(function (o) { return o.type !== ev.type; });
      S.open.push({ id: d.id, type: ev.type, icon: ev.icon, ne: ev.ne });
      renderEvents();
      if (navigator.vibrate) navigator.vibrate(200);
    }).catch(function (e2) { err('eRun', e2.message + ' — call the office. · कार्यालयमा फोन गर्नुहोस्।'); })
      .then(function () { b.disabled = false; });
  });
  $('openEv').addEventListener('click', function (e) {
    var b = e.target.closest('[data-clear]');
    if (!b) return;
    var id = +b.dataset.clear;
    api('clear', { id: id }).catch(function () {}).then(function () {
      S.open = S.open.filter(function (o) { return o.id !== id; });
      renderEvents();
    });
  });

  $('bEnd').onclick = function () {
    if (!confirm('End the trip? The bus disappears from the live map. · यात्रा अन्त्य गर्ने?')) return;
    $('bEnd').disabled = true;
    // Send what is still waiting first, so the trail reaches the office.
    var flush = S.queue.length ? api('ping', { points: S.queue.slice(-300), battery: S.battery, meta: meta() }).catch(function () {}) : Promise.resolve();
    flush.then(function () { return api('end'); }).then(function () {
      stopGps(); S.trip = null; S.queue = []; S.open = [];
      try { localStorage.removeItem(QKEY); } catch (e) {}
      return api('me').then(function (d) { useMe(d); busScreen(d.buses, 'Trip ended. Thank you! · धन्यवाद!'); clearInterval(autoT); $('autoStart').hidden = true; });
    }).catch(function (e) { err('eRun', e.message); }).then(function () { $('bEnd').disabled = false; });
  };

  /* ── keep the screen on, battery, going to / coming back from the background ── */
  function keepAwake() {
    if (!('wakeLock' in navigator) || S.wake) return;
    navigator.wakeLock.request('screen').then(function (l) {
      S.wake = l;
      l.addEventListener('release', function () { S.wake = null; });
    }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      S.hiddenAt = Date.now();
      // Tell the office now: GPS stops while the page is hidden.
      if (S.trip && S.auth) {
        try { api('ping', { points: S.queue.slice(-300), battery: S.battery, meta: meta(), token: S.auth.token }, true).then(function () { S.queue = []; }).catch(function () {}); } catch (e) {}
      }
      return;
    }
    if (S.trip) {
      keepAwake();
      var away = S.hiddenAt ? Math.round((Date.now() - S.hiddenAt) / 60000) : 0;
      var n = $('nRun');
      n.hidden = away < 1;
      n.textContent = 'The screen was off for ' + away + ' min — location was not sent then. Keep the screen on, or use Background mode below. · स्क्रिन बन्द हुँदा लोकेसन गएन।';
      lastMetaSent = 0;   // say "back on screen" straight away
    }
  });
  if (navigator.getBattery) {
    navigator.getBattery().then(function (b) {
      var upd = function () {
        var was = S.charging;
        S.battery = Math.round(b.level * 100); S.charging = !!b.charging;
        if (was === true && !S.charging) lastMetaSent = 0;   // charger pulled: tell the office now
      };
      upd(); b.addEventListener('levelchange', upd); b.addEventListener('chargingchange', upd);
    }).catch(function () {});
  }
  if (navigator.connection && navigator.connection.addEventListener) navigator.connection.addEventListener('change', function () { lastMetaSent = 0; });
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
    btn.onclick = function () {
      var txt = $(btn.dataset.copy).textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { btn.textContent = 'Copied ✓'; })
        .catch(function () { prompt('Copy this:', txt); });
    };
  });
  window.addEventListener('beforeunload', function (e) { if (S.trip) { e.preventDefault(); e.returnValue = ''; } });

  /* ── start ────────────────────────────────────────────────────── */
  var PAIR = <?= Security::jsonForHtml($pair) ?>;
  if (PAIR && (!S.auth || S.auth.kind !== 'vehicle')) {
    // Opened from the office's QR code: go straight to the bus-phone tab.
    show('sLogin');
    document.querySelector('[data-tab="tPair"]').click();
    return;
  }
  if (!S.auth) { show('sLogin'); return; }
  api('me').then(function (d) {
    useMe(d);
    if (d.trip) runScreen(d.trip); else busScreen(d.buses);
  }).catch(function (e) {
    if (e.status === 401) signedOut('');
    else { show('sLogin'); err('eLogin', 'No internet — try again. · इन्टरनेट छैन।'); }
  });
})();
</script>
</body>
</html>
