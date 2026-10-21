<?php
/**
 * bus-live.php?t=<token> — a passenger's live link to ONE bus (Oct 2026).
 *
 * Like redBus "Track my bus" / Uber "share trip": the office sends the link
 * (Live Fleet → 🔗 Passenger link / 📲 Send link) and the family at home
 * watches the bus move, where it is in Nepali, the next stop with km and
 * expected time, and the railway-style running status of every stop.
 *
 * No login. The link only works for that one trip and stops 2 hours after
 * it ends. Nothing private is shown: no driver phone, only the first name,
 * no cameras, no phone battery (api/live-fleet.php?share= strips them).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/livefleet.php';

header('X-Robots-Tag: noindex, nofollow');
$token   = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) ($_GET['t'] ?? '')) ?? '');
$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::officePhone();
$wa      = Settings::officeWhatsApp();
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#12264E">
<title>बस लाइभ · Live bus · <?= Security::e($company) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= Security::e(LiveFleet::asset('shg-crest.svg')) ?>">
<link rel="stylesheet" href="<?= Security::e(LiveFleet::asset('livefleet.css')) ?>">
<style>
:root{--ink:#16233C;--mut:#6B7688;--line:#E4E9F1;--bg:#F4F6FB;--card:#fff;--acc:#F07C1F}
*{box-sizing:border-box}
[hidden]{display:none!important}
html,body{margin:0;background:var(--bg);color:var(--ink);font-family:'Noto Sans Devanagari',Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}
header{display:flex;align-items:center;gap:10px;padding:calc(8px + env(safe-area-inset-top)) 14px 8px;background:#fff;border-bottom:1px solid var(--line)}
header img{height:42px}
header b{font-size:15px}
header small{display:block;color:var(--acc);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.wrap{max-width:980px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr);gap:0}
#map{height:52vh;min-height:300px;position:relative}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;margin:12px;padding:14px 16px;box-shadow:0 1px 6px rgba(18,38,78,.06)}
.hero{display:flex;gap:12px;align-items:center}
.hero img{width:120px;height:68px;object-fit:cover;border-radius:12px;background:#EEF2F8;flex:0 0 auto}
.hero .t{min-width:0}
.hero .t div{font-size:13px;color:var(--mut);margin-top:4px}
.hl{margin-top:10px;font-size:15px;line-height:1.55}
.big{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.big div{background:var(--bg);border-radius:12px;padding:10px;font-size:12px;color:var(--mut)}
.big b{display:block;font-size:20px;color:var(--ink);font-variant-numeric:tabular-nums}
.next{margin-top:12px;padding:12px 14px;border-radius:14px;background:#FFF4E8;font-size:15px;line-height:1.5}
.next b{font-size:18px}
.btns{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:12px}
.btns a,.btns button{display:flex;align-items:center;justify-content:center;gap:6px;min-height:48px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--ink);font:700 15px/1.2 inherit;text-decoration:none;cursor:pointer}
.btns .pri{background:#12264E;color:#fff;border-color:#12264E}
.btns .on{background:var(--acc);border-color:var(--acc);color:#fff}
.msg{text-align:center;padding:40px 20px;font-size:18px;line-height:1.6}
.msg small{display:block;color:var(--mut);font-size:14px;margin-top:6px}
.ann{position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:9;background:linear-gradient(90deg,#D96A10,#F07C1F);color:#fff;border-radius:14px;padding:10px 14px;font-weight:700;font-size:15px;box-shadow:0 10px 30px rgba(0,0,0,.3);transition:opacity .4s}
.ann.off{opacity:0;pointer-events:none}
h3{margin:0 0 4px;font-size:15px}
.foot{text-align:center;color:var(--mut);font-size:12px;padding:6px 0 24px}
@media(min-width:900px){.wrap{grid-template-columns:minmax(0,1fr) 400px;align-items:start}#map{height:calc(100vh - 59px);position:sticky;top:0}.side{max-height:calc(100vh - 59px);overflow:auto}}
</style>
</head>
<body>
<header>
  <img src="<?= Security::e(LiveFleet::asset('shg-logo.svg')) ?>" alt="<?= Security::e($company) ?>">
  <div style="margin-left:auto;text-align:right"><small>Live bus · लाइभ बस</small><b id="clock">—</b></div>
</header>
<div class="wrap">
  <div id="map"></div>
  <div class="side">
    <div class="card" id="state" hidden></div>
    <div class="card" id="main" hidden>
      <div class="hero">
        <img src="/assets/img/bus-shg.webp" alt="" onerror="this.remove()">
        <div class="t"><span id="plate"></span><div id="sub"></div><div id="pill"></div></div>
      </div>
      <div class="hl" id="hl"></div>
      <div class="big">
        <div>गति · Speed<b id="spd">—</b></div>
        <div>अहिले · Now<b id="here" style="font-size:15px">—</b></div>
        <div>अपडेट · Updated<b id="age" style="font-size:15px">—</b></div>
      </div>
      <div class="next" id="next" hidden></div>
      <div class="btns">
        <a class="pri" href="tel:+<?= Security::e($wa) ?>">📞 कार्यालय · Call</a>
        <a href="https://wa.me/<?= Security::e($wa) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
        <button type="button" id="bShare">📤 Share · पठाउनुहोस्</button>
        <button type="button" id="bVoice">🔇 आवाज · Voice</button>
      </div>
    </div>
    <div class="card" id="tlCard" hidden>
      <h3>🚆 चलिरहेको स्थिति · Running status</h3>
      <div id="tl"></div>
    </div>
    <div class="foot"><?= Security::e($company) ?> · <?= Security::e($phone) ?></div>
  </div>
</div>
<script src="<?= Security::e(LiveFleet::asset('livefleet.js')) ?>"></script>
<script>
(function () {
  var LF = window.LiveFleet, esc = LF.esc;
  var TOKEN = <?= Security::jsonForHtml($token) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var fm = new LF.FleetMap($('map'), { look: 'day', zoom: 6, center: [80.5, 25.5] });
  var first = true, last = null;
  var tracker = new LF.Tracker(), annT = null;
  var voice = new LF.Announcer(function (text, icon) {
    var el = document.querySelector('.ann');
    if (!el) { el = document.createElement('div'); el.className = 'ann off'; document.body.appendChild(el); }
    el.textContent = (icon || '📢') + ' ' + text; el.classList.remove('off');
    clearTimeout(annT); annT = setTimeout(function () { el.classList.add('off'); }, 15000);
  }, function () { $('bVoice').classList.remove('on'); $('bVoice').textContent = '🔇 आवाज · Voice'; });

  function stateMsg(big, small) {
    $('state').hidden = false; $('state').innerHTML = '<div class="msg">' + big + '<small>' + small + '</small></div>';
  }
  function draw(d) {
    if (d.state === 'unknown') { $('main').hidden = true; $('tlCard').hidden = true; stateMsg('🔗 यो लिङ्क मिलेन', 'This link is not valid. Please ask the office for a new one.'); return false; }
    if (d.state === 'expired') { $('main').hidden = true; $('tlCard').hidden = true; stateMsg('🏁 यो यात्रा सकियो', 'This trip has ended. Thank you for travelling with us.'); return false; }
    var b = d.bus; last = b;
    $('state').hidden = d.state !== 'ended';
    if (d.state === 'ended') stateMsg('🏁 यात्रा सकियो · Trip ended', 'The last place the bus was seen is shown on the map.');
    $('main').hidden = false;
    $('plate').innerHTML = LF.plate(b.number, 'lg');
    $('sub').textContent = b.name + (b.route ? ' · ' + b.route.label : '') + (b.driver ? ' · 👤 ' + b.driver.name : '');
    $('pill').innerHTML = LF.pill(b.status, true);
    $('hl').innerHTML = LF.headline(b);
    var moving = b.status === 'live' || b.status === 'weak';
    $('spd').textContent = moving && b.pos && b.pos.kmh != null ? LF.ne(Math.round(b.pos.kmh)) + ' किमी/घण्टा' : '—';
    $('here').textContent = b.place ? b.place.name + (b.place.at ? '' : ' नजिक') : b.stop && b.stop.near ? LF.nm(b.stop.near_ne, b.stop.near) : '—';
    $('age').textContent = b.age_s == null ? '—' : b.age_s < 3 ? 'अहिले' : b.age_s < 60 ? LF.ne(b.age_s) + ' सेकेन्ड अघि' : LF.neDur(Math.round(b.age_s / 60)) + ' अघि';
    var st = b.stop;
    $('next').hidden = !(st && st.next && d.state === 'running');
    if (st && st.next) {
      $('next').innerHTML = '🚏 अर्को स्टप <b>' + esc(LF.nm(st.next_ne, st.next)) + '</b><br>' + LF.neKm(st.next_km) + ' बाँकी · करिब ' + LF.neDur(st.next_min)
        + ' · ' + LF.neClock(Date.now() + st.next_min * 60000) + ' बजेतिर पुग्ने'
        + (b.ahead && b.ahead.name !== st.next ? '<br>➡️ अगाडि ' + esc(b.ahead.name) + ' · ' + LF.neKm(b.ahead.km) : '');
    }
    var tl = LF.timelineHtml(b);
    $('tlCard').hidden = !tl; $('tl').innerHTML = tl;
    fm.ready.then(function () {
      fm.update([b]);
      fm.select(b.id);
      fm.setPois(LF.poisFor(b));
      if (first && b.pos) { first = false; fm.focus(b.id, 13, 0); if (d.state === 'running') fm.follow(true); }
    }).catch(function () {});
    if (d.state === 'running') tracker.events([b]).forEach(function (ev) { voice.say(ev.text, ev.icon, ev.speak, ev.level); });
    return d.state === 'running';
  }

  if (!TOKEN) { stateMsg('🔗 यो लिङ्क मिलेन', 'This link is not valid.'); return; }
  var poller = LF.poll(function () { return '/api/live-fleet.php?share=' + encodeURIComponent(TOKEN); }, 1000, function (d) {
    if (!draw(d)) poller.stop();     // nothing more will change
  }, function (e) {
    if (e && e.status === 429) stateMsg('⏳ धेरै पटक खोलियो', 'Too many requests — the page retries by itself.');
  });

  setInterval(function () { $('clock').textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }, 1000);
  $('bShare').onclick = function () {
    var data = { title: 'Live bus', text: '🚌 ' + (last ? last.number : '') + ' — लाइभ हेर्नुहोस् · Track the bus live', url: location.href };
    if (navigator.share) navigator.share(data).catch(function () {});
    else if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () { $('bShare').textContent = '✓ Copied'; });
    else prompt('Link:', location.href);
  };
  $('bVoice').onclick = function () {
    var on = voice.enable(!voice.on);
    this.classList.toggle('on', on); this.textContent = on ? '🔊 आवाज खुला' : '🔇 आवाज · Voice';
  };
  // Map controls stop "follow" when the person pans; a tap on the bus brings it back.
  fm.opts.onSelect = function () { fm.follow(true); };
})();
</script>
</body>
</html>
