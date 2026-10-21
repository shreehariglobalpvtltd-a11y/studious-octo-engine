/* =====================================================================
 *  Live Fleet — shared browser code for admin/live-fleet.php and
 *  admin/live-tv.php (Oct 2026).
 *
 *    LiveFleet.poll(url, ms, onData, onError)   1-second refresh loop
 *    new LiveFleet.FleetMap(el, opts)           MapLibre map + one marker per bus
 *    LiveFleet.renderCams(box, cameras)         CCTV tiles (HLS / MJPEG / snapshot / embed)
 *    new LiveFleet.Tracker() / .Announcer()     Nepali journey announcements, spoken aloud
 *
 *  Markers glide between fixes (a short tween each second) so a moving
 *  bus reads like video instead of a dot that hops.
 * ===================================================================== */
(function (w) {
  'use strict';
  var LF = w.LiveFleet = w.LiveFleet || {};

  LF.MAPLIBRE_JS  = 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js';
  LF.MAPLIBRE_CSS = 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css';
  LF.HLS_JS       = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
  LF.STYLE        = 'https://tiles.openfreemap.org/styles/liberty';
  LF.STYLE_DARK   = 'https://tiles.openfreemap.org/styles/dark';

  LF.STATUS = {
    live:    { c: '#16a34a', en: 'Live',            np: 'लाइभ' },
    weak:    { c: '#f59e0b', en: 'Weak signal',     np: 'कमजोर सिग्नल' },
    offline: { c: '#dc2626', en: 'Offline',         np: 'अफलाइन' },
    waiting: { c: '#3b82f6', en: 'Waiting for GPS', np: 'जीपीएस पर्खँदै' },
    parked:  { c: '#64748b', en: 'Parked',          np: 'पार्क' },
    idle:    { c: '#94a3b8', en: 'Not tracked',     np: 'ट्र्याक छैन' }
  };

  LF.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  LF.ago = function (sec) {
    if (sec == null) return '—';
    if (sec < 3) return 'just now';
    if (sec < 60) return sec + ' s ago';
    if (sec < 3600) return Math.round(sec / 60) + ' min ago';
    if (sec < 86400) return Math.round(sec / 3600) + ' h ago';
    return Math.round(sec / 86400) + ' d ago';
  };

  LF.pill = function (status, withNp) {
    var s = LF.STATUS[status] || LF.STATUS.idle;
    return '<span class="lf-pill' + (status === 'live' ? ' is-live' : '') + '" style="--lf-c:' + s.c + '"><i></i>'
      + LF.esc(s.en) + (withNp ? ' · ' + LF.esc(s.np) : '') + '</span>';
  };

  var loading = {};
  LF.loadScript = function (src) {
    if (!loading[src]) {
      loading[src] = new Promise(function (res, rej) {
        var s = document.createElement('script');
        s.src = src; s.async = true; s.onload = res;
        s.onerror = function () { delete loading[src]; rej(new Error('load ' + src)); };
        document.head.appendChild(s);
      });
    }
    return loading[src];
  };
  LF.loadCss = function (href) {
    if (document.querySelector('link[href="' + href + '"]')) return;
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
  };

  /* ── polling ────────────────────────────────────────────────────────
     A setTimeout chain, not setInterval: a slow answer never stacks a
     second request behind it. Errors back off to 10 s and recover by
     themselves (a TV on flaky office Wi-Fi must come back alone). */
  LF.poll = function (url, ms, onData, onError) {
    var stopped = false, timer = null, fails = 0;
    function tick() {
      if (stopped) return;
      var ctl = w.AbortController ? new AbortController() : null;
      var kill = setTimeout(function () { if (ctl) ctl.abort(); }, 8000);
      var u = typeof url === 'function' ? url() : url;
      fetch(u, { credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
        .then(function (r) {
          return r.json().then(function (j) { return { status: r.status, body: j }; });
        })
        .then(function (res) {
          clearTimeout(kill);
          if (!res.body || !res.body.ok) {
            var e = new Error((res.body && res.body.error) || ('HTTP ' + res.status)); e.status = res.status; throw e;
          }
          fails = 0;
          onData(res.body.data);
          timer = setTimeout(tick, ms);
        })
        .catch(function (e) {
          clearTimeout(kill);
          fails++;
          if (onError) onError(e, fails);
          if (e && (e.status === 401 || e.status === 403)) { stopped = true; return; }
          timer = setTimeout(tick, Math.min(10000, ms * Math.pow(2, Math.min(fails, 4))));
        });
    }
    tick();
    return {
      stop: function () { stopped = true; clearTimeout(timer); },
      now: function () { clearTimeout(timer); tick(); }
    };
  };

  /* ── the map ────────────────────────────────────────────────────── */
  LF.FleetMap = function (el, opts) {
    var self = this;
    opts = opts || {};
    this.el = el;
    this.opts = opts;
    this.items = {};          // bus id → { marker, el, from, to, t0, bus }
    this.selected = null;
    this.following = false;
    this.map = null;
    this.raf = null;
    el.classList.add('lf-map');
    var msg = document.createElement('div');
    msg.className = 'lf-map-msg'; msg.textContent = 'Loading map…';
    el.appendChild(msg);
    LF.loadCss(LF.MAPLIBRE_CSS);
    this.ready = LF.loadScript(LF.MAPLIBRE_JS).then(function () {
      return new Promise(function (res, rej) {
        try {
          self.map = new w.maplibregl.Map({
            container: el,
            style: opts.style || LF.STYLE,
            center: opts.center || [79.5, 25.0],
            zoom: opts.zoom || 4.6,
            interactive: opts.interactive !== false,
            attributionControl: { compact: true },
            fadeDuration: 200
          });
        } catch (e) {
          msg.textContent = 'This screen cannot draw the map (no WebGL).';
          rej(e); return;
        }
        if (opts.interactive !== false && opts.controls !== false) {
          self.map.addControl(new w.maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
        }
        self.map.on('dragstart', function () { if (self.following) self.follow(false); });
        self.map.on('rotate', function () { self._rotateArrows(); });
        self.map.on('load', function () {
          msg.remove();
          self.map.addSource('lf-trail', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
          self.map.addLayer({ id: 'lf-trail-glow', type: 'line', source: 'lf-trail', layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': '#F07C1F', 'line-width': 10, 'line-opacity': 0.22, 'line-blur': 3 } });
          self.map.addLayer({ id: 'lf-trail-line', type: 'line', source: 'lf-trail', layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': '#F07C1F', 'line-width': 4 } });
          res(self);
        });
        self.map.on('error', function (ev) {
          // A tile host hiccup must not blank the screen; just note it.
          if (w.console) console.warn('map error', ev && ev.error);
        });
      });
    }).catch(function (e) {
      msg.textContent = 'Map could not load — check the internet connection.';
      throw e;
    });
  };

  var FM = LF.FleetMap.prototype;

  FM._markerEl = function (bus) {
    var d = document.createElement('div');
    d.className = 'lf-bus';
    d.innerHTML = '<span class="lf-ring"></span><span class="lf-arrow"></span><span class="lf-dot">🚌</span>'
      + (this.opts.labels === false ? '' : '<span class="lf-tag"></span>');
    var self = this;
    d.addEventListener('click', function (e) {
      e.stopPropagation();
      if (self.opts.onSelect) self.opts.onSelect(bus.id);
    });
    return d;
  };

  FM._style = function (it) {
    var b = it.bus, s = LF.STATUS[b.status] || LF.STATUS.idle;
    it.el.style.setProperty('--lf-c', s.c);
    it.el.classList.toggle('is-live', b.status === 'live');
    it.el.classList.toggle('is-sel', this.selected === b.id);
    it.el.classList.toggle('is-dim', b.status === 'parked' || b.status === 'offline');
    var tag = it.el.querySelector('.lf-tag');
    if (tag) {
      var kmh = b.pos && b.pos.kmh != null && (b.status === 'live' || b.status === 'weak') ? ' · ' + Math.round(b.pos.kmh) + ' km/h' : '';
      tag.textContent = (b.number || b.name) + kmh;
    }
    var arrow = it.el.querySelector('.lf-arrow');
    arrow.style.display = b.pos && b.pos.hdg != null && b.status !== 'parked' ? '' : 'none';
    it.el.title = (b.name || '') + ' ' + (b.number || '') + ' — ' + s.en;
  };

  FM._rotateArrows = function () {
    var bearing = this.map ? this.map.getBearing() : 0;
    for (var id in this.items) {
      var it = this.items[id];
      if (it.bus.pos && it.bus.pos.hdg != null) {
        it.el.querySelector('.lf-arrow').style.transform = 'rotate(' + (it.bus.pos.hdg - bearing) + 'deg)';
      }
    }
  };

  /* Place / move every bus. Buses without a position are not drawn. */
  FM.update = function (buses) {
    if (!this.map) return;
    var seen = {}, now = performance.now(), self = this;
    buses.forEach(function (b) {
      if (!b.pos) return;
      seen[b.id] = true;
      var it = self.items[b.id];
      var to = { lng: b.pos.lng, lat: b.pos.lat };
      if (!it) {
        var el = self._markerEl(b);
        it = self.items[b.id] = {
          el: el, bus: b, from: to, to: to, cur: to, t0: now,
          marker: new w.maplibregl.Marker({ element: el, anchor: 'center' }).setLngLat([to.lng, to.lat]).addTo(self.map)
        };
      } else if (it.to.lng !== to.lng || it.to.lat !== to.lat) {
        // Teleport rather than glide across the country after a long gap.
        var far = Math.abs(it.cur.lng - to.lng) + Math.abs(it.cur.lat - to.lat) > 0.5;
        it.from = far ? to : it.cur; it.to = to; it.t0 = now;
      }
      it.bus = b;
      self._style(it);
    });
    for (var id in this.items) {
      if (!seen[id]) { this.items[id].marker.remove(); delete this.items[id]; }
    }
    this._rotateArrows();
    if (!this.raf) this.raf = requestAnimationFrame(this._tick.bind(this));
  };

  FM._tick = function () {
    this.raf = null;
    var now = performance.now(), moving = false, dur = this.opts.glideMs || 1000;
    for (var id in this.items) {
      var it = this.items[id];
      var k = Math.min(1, (now - it.t0) / dur);
      var e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;   // ease in-out
      it.cur = { lng: it.from.lng + (it.to.lng - it.from.lng) * e, lat: it.from.lat + (it.to.lat - it.from.lat) * e };
      it.marker.setLngLat([it.cur.lng, it.cur.lat]);
      if (k < 1) moving = true;
    }
    var sel = this.selected != null ? this.items[this.selected] : null;
    if (this.following && sel) {
      this.map.jumpTo({ center: [sel.cur.lng, sel.cur.lat] });
    }
    if (moving) this.raf = requestAnimationFrame(this._tick.bind(this));
  };

  FM.select = function (id) {
    this.selected = id;
    for (var k in this.items) this._style(this.items[k]);
  };

  FM.follow = function (on) {
    this.following = !!on;
    if (this.opts.onFollow) this.opts.onFollow(this.following);
    if (on) this.focus(this.selected);
  };

  FM.focus = function (id, zoom, ms) {
    var it = id != null ? this.items[id] : null;
    if (!it || !this.map) return false;
    this.map.easeTo({ center: [it.to.lng, it.to.lat], zoom: zoom || Math.max(this.map.getZoom(), 13), duration: ms == null ? 900 : ms });
    return true;
  };

  FM.fitAll = function (padding, ms) {
    if (!this.map) return;
    var pts = [];
    for (var id in this.items) pts.push([this.items[id].to.lng, this.items[id].to.lat]);
    (this.opts.extraPoints || []).forEach(function (p) { pts.push(p); });
    if (!pts.length) return;
    if (pts.length === 1) { this.map.easeTo({ center: pts[0], zoom: 11, duration: ms == null ? 900 : ms }); return; }
    var b = new w.maplibregl.LngLatBounds(pts[0], pts[0]);
    pts.forEach(function (p) { b.extend(p); });
    this.map.fitBounds(b, { padding: padding || 60, duration: ms == null ? 900 : ms, maxZoom: 12 });
  };

  FM.setTrail = function (coords) {
    if (!this.map || !this.map.getSource('lf-trail')) return;
    this.map.getSource('lf-trail').setData(coords && coords.length > 1
      ? { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } }
      : { type: 'FeatureCollection', features: [] });
  };

  /* Static lines (route stops) drawn under the buses. routes: [{ id, color, stops:[{lng,lat,name}] }] */
  FM.drawRoutes = function (routes) {
    var map = this.map;
    if (!map) return;
    routes.forEach(function (r) {
      var coords = r.stops.map(function (s) { return [s.lng, s.lat]; });
      if (coords.length < 2 || map.getSource('lf-r' + r.id)) return;
      map.addSource('lf-r' + r.id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } } });
      map.addLayer({ id: 'lf-r' + r.id, type: 'line', source: 'lf-r' + r.id, layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': r.color || '#2E5FA8', 'line-width': 2.5, 'line-opacity': 0.55, 'line-dasharray': [2, 1.5] } }, 'lf-trail-glow');
    });
  };

  /* Labelled points near the bus: 🛕 temples, 🚏 stops ahead. Drawn as HTML
     (not map text) so Devanagari names render correctly.
     pois: [{ key, icon, label, sub, lng, lat }] */
  FM.setPois = function (pois) {
    if (!this.map) return;
    this.poi = this.poi || {};
    var seen = {}, self = this;
    (pois || []).forEach(function (p) {
      seen[p.key] = true;
      var m = self.poi[p.key];
      var html = '<span class="lf-poi-i">' + LF.esc(p.icon) + '</span><span class="lf-poi-t">' + LF.esc(p.label)
        + (p.sub ? '<small>' + LF.esc(p.sub) + '</small>' : '') + '</span>';
      if (!m) {
        var el = document.createElement('div');
        el.className = 'lf-poi';
        m = self.poi[p.key] = { el: el, html: '', marker: new w.maplibregl.Marker({ element: el, anchor: 'bottom' }).setLngLat([p.lng, p.lat]).addTo(self.map) };
      }
      if (m.html !== html) { m.el.innerHTML = html; m.html = html; }
    });
    for (var k in this.poi) {
      if (!seen[k]) { this.poi[k].marker.remove(); delete this.poi[k]; }
    }
  };

  FM.resize = function () { if (this.map) this.map.resize(); };

  /* ── CCTV tiles ─────────────────────────────────────────────────── */
  function camTile(cam) {
    var box = document.createElement('div');
    box.className = 'lf-cam';
    box.innerHTML = '<span class="lf-cam-name"></span><span class="lf-cam-rec">● LIVE</span><div class="lf-cam-err" hidden></div>';
    box.querySelector('.lf-cam-name').textContent = '📹 ' + cam.name;
    var err = box.querySelector('.lf-cam-err');
    var cleanup = [];
    function fail(t) { err.hidden = false; err.textContent = t; }

    if (cam.kind === 'hls') {
      var v = document.createElement('video');
      v.muted = true; v.autoplay = true; v.playsInline = true; v.setAttribute('playsinline', '');
      box.appendChild(v);
      if (v.canPlayType('application/vnd.apple.mpegurl')) {
        v.src = cam.url;
        v.addEventListener('error', function () { fail('Camera offline · क्यामेरा अफलाइन'); });
      } else {
        LF.loadScript(LF.HLS_JS).then(function () {
          if (!w.Hls || !w.Hls.isSupported()) { fail('This browser cannot play HLS video.'); return; }
          var hls = new w.Hls({ liveSyncDurationCount: 2, lowLatencyMode: true });
          var retry = null;
          hls.on(w.Hls.Events.ERROR, function (_, data) {
            if (!data.fatal) return;
            fail('Camera offline — retrying… · क्यामेरा अफलाइन');
            clearTimeout(retry);
            retry = setTimeout(function () { err.hidden = true; hls.loadSource(cam.url); hls.startLoad(); }, 5000);
          });
          hls.on(w.Hls.Events.MANIFEST_PARSED, function () { err.hidden = true; v.play().catch(function () {}); });
          hls.loadSource(cam.url);
          hls.attachMedia(v);
          cleanup.push(function () { clearTimeout(retry); hls.destroy(); });
        }).catch(function () { fail('Video player could not load.'); });
      }
      cleanup.push(function () { v.removeAttribute('src'); v.load(); });
    } else if (cam.kind === 'mjpeg' || cam.kind === 'snapshot') {
      var img = document.createElement('img');
      img.alt = cam.name;
      img.onerror = function () { fail('Camera offline · क्यामेरा अफलाइन'); };
      img.onload = function () { err.hidden = true; };
      box.appendChild(img);
      if (cam.kind === 'mjpeg') {
        img.src = cam.url;
        cleanup.push(function () { img.src = ''; });
      } else {
        var sep = cam.url.indexOf('?') === -1 ? '?' : '&';
        var refresh = function () { img.src = cam.url + sep + '_t=' + Date.now(); };
        refresh();
        var iv = setInterval(refresh, 1000);
        cleanup.push(function () { clearInterval(iv); });
      }
    } else {
      var f = document.createElement('iframe');
      f.src = cam.url;
      f.setAttribute('allow', 'autoplay; fullscreen');
      f.setAttribute('referrerpolicy', 'no-referrer');
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-presentation');
      box.appendChild(f);
    }
    box._lfCleanup = function () { cleanup.forEach(function (fn) { try { fn(); } catch (e) {} }); };
    return box;
  }

  /* Draw the camera tiles for one bus. Called every refresh, so it only
     rebuilds when the camera list really changed — otherwise a playing
     video would restart every second. */
  LF.renderCams = function (box, cams, emptyHtml) {
    var sig = (cams || []).map(function (c) { return c.id + '|' + c.kind + '|' + c.url; }).join(',');
    if (box._lfSig === sig) return;
    box._lfSig = sig;
    Array.prototype.forEach.call(box.querySelectorAll('.lf-cam'), function (t) { if (t._lfCleanup) t._lfCleanup(); });
    box.innerHTML = '';
    if (!cams || !cams.length) {
      box.innerHTML = emptyHtml || '';
      return;
    }
    cams.forEach(function (c) { box.appendChild(camTile(c)); });
  };

  /* ── Nepali words, numbers and times ────────────────────────────── */
  var NE_DIGITS = '०१२३४५६७८९';
  LF.ne = function (v) { return String(v).replace(/[0-9]/g, function (d) { return NE_DIGITS[+d]; }); };
  LF.neKm = function (km) { return km == null ? '' : LF.ne(km >= 10 ? Math.round(km) : Math.round(km * 10) / 10) + ' किमी'; };
  /** 135 → "२ घण्टा १५ मिनेट" (rounded to 5 min past the first hour). */
  LF.neDur = function (min) {
    if (min == null) return '';
    if (min < 60) return LF.ne(Math.max(1, Math.round(min))) + ' मिनेट';
    var h = Math.floor(min / 60), m = Math.round((min % 60) / 5) * 5;
    if (m === 60) { h++; m = 0; }
    return LF.ne(h) + ' घण्टा' + (m ? ' ' + LF.ne(m) + ' मिनेट' : '');
  };
  /** ms → "बेलुका ७:३०", "भोलि बिहान ६:१०" — the day is named when it is not today. */
  LF.neClock = function (ms) {
    var d = new Date(ms), h = d.getHours(), m = d.getMinutes();
    var part = h < 4 ? 'राति' : h < 12 ? 'बिहान' : h < 16 ? 'दिउँसो' : h < 19 ? 'बेलुका' : 'राति';
    var t0 = new Date(); t0.setHours(0, 0, 0, 0);
    var days = Math.floor((new Date(d).setHours(0, 0, 0, 0) - t0) / 86400000);
    var day = days === 1 ? 'भोलि ' : days === 2 ? 'पर्सि ' : days > 2 ? LF.ne(d.getDate()) + ' गते ' : '';
    return day + part + ' ' + LF.ne(h % 12 || 12) + ':' + LF.ne(m < 10 ? '0' + m : m);
  };
  LF.neSpeed = function (kmh) { return LF.ne(Math.round(kmh || 0)) + ' किमी/घण्टा'; };
  /** A stop or place in Devanagari when known, else as written. */
  LF.nm = function (ne, en) { return ne || en || ''; };

  /* A greeting that fits the temple's deity — said after its name. */
  var GREET = [
    [/shiv|mahadev|mahakal|nath\b|somnath|neelkanth|शिव|महादेव|महाकाल|नाथ/i, 'हर हर महादेव'],
    [/\bram\b|rama|raghu|hanuman|bajrang|maruti|राम|हनुमान|बजरंग/i, 'जय श्री राम'],
    [/krishna|krishn|govind|gopal|radha|shyam|swaminarayan|iskcon|dwarka|kanha|thakor|कृष्ण|गोविन्द|गोपाल|राधा|श्याम|स्वामिनारायण|स्वामीनारायण|द्वारका|ठाकोर/i, 'जय श्री कृष्ण'],
    [/durga|ambe|amba|mata|devi|kali|bhavani|chamunda|vaishno|sherawali|bahuchar|दुर्गा|अम्बे|अम्बा|माता|देवी|काली|भवानी|चामुण्डा/i, 'जय माता दी'],
    [/ganesh|ganpati|vinayak|गणेश|गणपति|विनायक/i, 'गणपति बाप्पा मोरया'],
    [/jagannath|जगन्नाथ/i, 'जय जगन्नाथ'],
    [/sai\b|साईं|साई/i, 'ॐ साईं राम'],
    [/vishnu|narayan|hari\b|balaji|venkatesh|विष्णु|नारायण|हरि|बालाजी/i, 'ॐ नमो नारायणाय']
  ];
  LF.greet = function (name, en) {
    var t = (name || '') + ' ' + (en || '');
    for (var i = 0; i < GREET.length; i++) if (GREET[i][0].test(t)) return GREET[i][1];
    return 'जय श्री हरि';
  };

  /* ── what changed since the last refresh → Nepali sentences ────────
     One Tracker per page. events(buses) returns [{ busId, icon, text }]:
       🚏 arrived at a stop / left it, with the next stop's km and time
       📍 reached a town or village (at most once a minute per bus)
       🛕 a Hindu temple within 1 km (once per temple per bus)
       🕒 every 15 min: speed, and km + time to the last stop */
  LF.Tracker = function () { this.prev = {}; this.placeAt = {}; this.lastPlace = {}; this.temples = {}; this.etaAt = {}; };
  LF.Tracker.prototype.events = function (buses, nowMs) {
    var out = [], self = this, now = nowMs || Date.now();
    buses.forEach(function (b) {
      var p = self.prev[b.id];
      var moving = b.status === 'live' || b.status === 'weak';
      self.prev[b.id] = { status: b.status, stop: b.stop, place: b.place };
      if (!moving) return;
      var bus = b.name || b.number, st = b.stop, said = false;
      var nextTxt = function () {
        return st && st.next ? ' अर्को स्टप ' + LF.nm(st.next_ne, st.next) + ' — ' + LF.neKm(st.next_km) + ', करिब ' + LF.neDur(st.next_min) + '।' : '';
      };
      if (!p) {
        if (b.place) {
          out.push({ busId: b.id, icon: '📍', text: bus + ' अहिले ' + b.place.name + (b.place.at ? 'मा' : ' नजिक') + ' छ।' + nextTxt() });
          self.lastPlace[b.id] = b.place.name; self.placeAt[b.id] = now;
        }
        self.etaAt[b.id] = now;
        return;
      }
      if (st && st.at && (!p.stop || !p.stop.at || p.stop.near !== st.near)) {
        out.push({ busId: b.id, icon: '🚏', text: bus + ' ' + LF.nm(st.near_ne, st.near) + ' आइपुग्यो।' + nextTxt() });
        said = true;
      } else if (p.stop && p.stop.at && st && !st.at) {
        out.push({ busId: b.id, icon: '🚌', text: bus + ' ' + LF.nm(p.stop.near_ne, p.stop.near) + 'बाट हिँड्यो।' + nextTxt() });
        said = true;
      }
      if (b.place && b.place.at && b.place.name !== self.lastPlace[b.id] && now - (self.placeAt[b.id] || 0) >= 60000) {
        self.lastPlace[b.id] = b.place.name; self.placeAt[b.id] = now;
        if (!said) {
          var a = b.ahead;
          out.push({ busId: b.id, icon: '📍', text: bus + ' ' + b.place.name + ' आइपुग्यो।'
            + (a ? ' अगाडि ' + a.name + ' — ' + LF.neKm(a.km) + ', करिब ' + LF.neDur(a.min) + '।' : '') });
          said = true;
        }
      }
      (b.temples || []).some(function (t) {
        var k = b.id + ':' + t.id;
        if (t.km > 1.0 || self.temples[k]) return false;
        self.temples[k] = now;
        out.push({ busId: b.id, icon: '🛕', text: bus + ' नजिकै ' + t.name + ' — ' + LF.neKm(t.km) + '। ' + LF.greet(t.name, t.en) + '!' });
        return true;
      });
      if (now - (self.etaAt[b.id] || 0) >= 15 * 60000 && st && st.upcoming && st.upcoming.length) {
        self.etaAt[b.id] = now;
        var d = st.upcoming[st.upcoming.length - 1];
        out.push({ busId: b.id, icon: '🕒', text: bus + ': गति ' + LF.neSpeed(b.pos && b.pos.kmh) + '। ' + LF.nm(d.ne, d.name) + ' सम्म '
          + LF.neKm(d.km) + ', करिब ' + LF.neDur(d.min) + ' — ' + LF.neClock(now + d.min * 60000) + ' सम्म पुग्ने अनुमान।' });
      }
    });
    return out;
  };

  /* ── speaking (Web Speech API) ────────────────────────────────────
     Browsers only allow speech after a click, so the page shows a 🔊 button
     that calls enable(true). Uses a Nepali voice if the device has one,
     otherwise Hindi (same script, understood by Nepali listeners). */
  LF.Announcer = function (onText) { this.on = false; this.q = []; this.busy = false; this.onText = onText || null; this.voice = null; };
  var AN = LF.Announcer.prototype;
  AN.supported = function () { return 'speechSynthesis' in w && typeof w.SpeechSynthesisUtterance === 'function'; };
  AN.pickVoice = function () {
    if (!this.supported()) return null;
    var v = w.speechSynthesis.getVoices() || [];
    var by = function (re) { for (var i = 0; i < v.length; i++) if (re.test(v[i].lang)) return v[i]; return null; };
    return by(/^ne/i) || by(/^hi/i) || by(/^mr/i) || null;
  };
  AN.enable = function (on) {
    this.on = !!on && this.supported();
    if (!this.supported()) return false;
    w.speechSynthesis.cancel();
    this.q = []; this.busy = false;
    if (this.on) { this.voice = this.pickVoice(); this._speak('घोषणा सुरु भयो। जय श्री हरि।'); }
    return this.on;
  };
  AN.say = function (text, icon) {
    if (this.onText) this.onText(text, icon);
    if (!this.on) return;
    this.q.push(text);
    if (this.q.length > 4) this.q.splice(0, this.q.length - 4);   // never fall minutes behind the buses
    this._next();
  };
  AN._next = function () {
    if (this.busy || !this.q.length) return;
    this._speak(this.q.shift());
  };
  AN._speak = function (text) {
    var self = this;
    var u = new w.SpeechSynthesisUtterance(String(text).replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '').replace(/—/g, ','));
    if (!this.voice) this.voice = this.pickVoice();
    if (this.voice) u.voice = this.voice;
    u.lang = this.voice ? this.voice.lang : 'hi-IN';
    u.rate = 0.92;
    this.busy = true;
    u.onend = u.onerror = function () { self.busy = false; setTimeout(function () { self._next(); }, 400); };
    w.speechSynthesis.speak(u);
  };
  if ('speechSynthesis' in w && w.speechSynthesis.addEventListener) {
    w.speechSynthesis.addEventListener('voiceschanged', function () {});   // loads the voice list early on Chrome
  }

  /* ── ask the server to fetch town / temple names for new map squares ── */
  var warmBusy = false, warmTried = {};
  LF.warmCells = function (cells, key) {
    if (warmBusy || !cells || !cells.length) return;
    var now = Date.now();
    var todo = cells.filter(function (c) { return !warmTried[c] || now - warmTried[c] > 120000; }).slice(0, 2);
    if (!todo.length) return;
    todo.forEach(function (c) { warmTried[c] = now; });
    warmBusy = true;
    fetch('/api/fleet-places.php?cells=' + encodeURIComponent(todo.join(',')) + (key ? '&key=' + encodeURIComponent(key) : ''),
      { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
      .catch(function () {}).then(function () { warmBusy = false; });
  };

  /* Temples near a bus + its next stops, as map labels. */
  LF.poisFor = function (b) {
    var out = [];
    if (!b) return out;
    (b.temples || []).forEach(function (t) {
      out.push({ key: 't' + t.id, icon: '🛕', label: t.name, sub: LF.neKm(t.km), lng: t.lng, lat: t.lat });
    });
    return out;
  };
})(window);
