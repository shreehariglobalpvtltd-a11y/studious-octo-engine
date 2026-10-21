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
            // A Google-like look recolours liberty, so it always starts from liberty.
            style: opts.look ? LF.STYLE : (opts.style || LF.STYLE),
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
        self.map.on('zoom', function () { self._rotateArrows(); });
        self.map.on('load', function () {
          msg.remove();
          if (opts.look) LF.applyLook(self.map, opts.look);
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

  /* ── Google-Maps-like colours ───────────────────────────────────────
     Google's own tiles may not be shown inside another map library, so
     the free OpenFreeMap "liberty" tiles are recoloured with colours
     measured from today's Google Maps tiles over India: warm-grey land,
     cyan water, mint parks, slate-blue national highways, pale-blue
     streets, grey labels. "night" is the same measured from Google's
     dark mode. Each rule: [layer-id pattern, { paint property: value }];
     a layer may match several rules. */
  var LOOKS = {
    day: [
      [/^background$/, { 'background-color': ['interpolate',['linear'],['zoom'],10,'#f5f0e5',13,'#f5f3f3',17,'#f8f7f7'] }],
      [/^natural_earth$/, { 'raster-opacity': 0.15, 'raster-saturation': -0.4 }],
      [/^landuse_residential$/, { 'fill-color': '#f7f7f7' }],
      [/^park$/, { 'fill-color': '#c3f1d5', 'fill-opacity': 1, 'fill-outline-color': '#c3f1d5' }],
      [/^park_outline$/, { 'line-opacity': 0 }],
      [/^landcover_wood$/, { 'fill-color': '#c3f1d5', 'fill-opacity': 1 }],
      [/^landcover_grass$/, { 'fill-color': '#d3f8e2', 'fill-opacity': 1 }],
      [/^landcover_ice$/, { 'fill-color': '#ffffff' }],
      [/^landcover_sand$/, { 'fill-color': '#f8f4ec' }],
      [/^landuse_(pitch|track)$/, { 'fill-color': '#a9eac2' }],
      [/^landuse_cemetery$/, { 'fill-color': '#d3f8e2' }],
      [/^landuse_hospital$/, { 'fill-color': '#fce8e6' }],
      [/^landuse_school$/, { 'fill-color': '#efecec' }],
      [/^water$/, { 'fill-color': ['interpolate',['linear'],['zoom'],4,'#6cd2e7',5,'#72d4e8',7,'#7ed6ea',8,'#84d7eb',9,'#90daee'] }],
      [/^waterway_(tunnel|river|other)$/, { 'line-color': '#90daee' }],
      [/^aeroway_fill$/, { 'fill-color': '#e6edf7', 'fill-opacity': 1 }],
      [/^aeroway_(runway|taxiway)$/, { 'line-color': '#f3f6ff' }],
      [/^(road|tunnel|bridge)_motorway(_link)?$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,'#abbcd6',13,'#9bb0cb',14,'#8ba5c1'] }],
      [/^(road|tunnel|bridge)_motorway(_link)?_casing$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,'#899cc0',14,'#7796b6'] }],
      [/^(road|tunnel|bridge)_trunk_primary$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,['match',['get','class'],'trunk','#abbcd6','#d6dee6'],13,['match',['get','class'],'trunk','#9bb0cb','#d3dbe3'],14,['match',['get','class'],'trunk','#8ba5c1','#ccd7e0'],18,['match',['get','class'],'trunk','#8ba5c1','#bac9d7']] }],
      [/^(road|tunnel|bridge)_trunk_primary_casing$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,['match',['get','class'],'trunk','#899cc0','#c9d2dc'],14,['match',['get','class'],'trunk','#7796b6','#c4ced6']] }],
      [/^(road|tunnel|bridge)_(secondary_tertiary|link)$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,'#d6dee6',14,'#cfd9e3',18,'#bac9d7'] }],
      [/^(road|tunnel|bridge)_(secondary_tertiary|link)_casing$/, { 'line-color': '#c4ced6' }],
      [/^(road_minor|tunnel_minor|bridge_street)$/, { 'line-color': ['interpolate',['linear'],['zoom'],13,'#e3e7ec',15,'#d8e0e7',17,'#d8e0e7',18,'#c1ccd8'] }],
      [/^(road_minor|tunnel_street|bridge_street)_casing$/, { 'line-color': '#c8d0d7' }],
      [/^(road|tunnel|bridge)_service_track$/, { 'line-color': '#e3e7ec' }],
      [/^(road|tunnel|bridge)_service_track_casing$/, { 'line-color': '#d5dce3' }],
      [/^(road|tunnel|bridge)_path_pedestrian$/, { 'line-color': '#ffffff' }],
      [/^bridge_path_pedestrian_casing$/, { 'line-color': '#d8e0e7' }],
      [/^(road|tunnel|bridge)_(major|transit)_rail(_hatching)?$/, { 'line-color': '#cdd0d4' }],
      [/^building$/, { 'fill-color': '#e9eaef', 'fill-outline-color': '#e0e0e8' }],
      [/^building-3d$/, { 'fill-extrusion-color': '#e9eaef' }],
      [/^boundary_2$/, { 'line-color': '#282a2d' }],
      [/^boundary_3$/, { 'line-color': '#8a8a8b' }],
      [/^boundary_disputed$/, { 'line-color': '#8a8a8b' }],
      [/^label_(country_[123]|city|city_capital)$/, { 'text-color': '#2f3034' }],
      [/^label_(town|village)$/, { 'text-color': '#3c4043' }],
      [/^label_(state|other)$/, { 'text-color': '#636366' }],
      [/^label_/, { 'text-halo-color': '#ffffff' }],
      [/^(water_name_(point|line)_label|waterway_line_label)$/, { 'text-color': '#0c7a92' }],
      [/^highway-name-(major|minor|path)$/, { 'text-color': '#5c6f87', 'text-halo-color': '#ffffff' }],
      [/^poi_r(1|7|20)$/, { 'text-color': '#70757a' }],
      [/^poi_transit$/, { 'text-color': '#354557' }],
      [/^(poi_|airport$)/, { 'text-halo-color': '#ffffff' }],
      [/^airport$/, { 'text-color': '#70757a' }]
    ],
    night: [
      [/^background$/, { 'background-color': ['interpolate',['linear'],['zoom'],10,'#2c3246',13,'#1c2a40'] }],
      [/^natural_earth$/, { 'raster-opacity': 0 }],
      [/^landuse_residential$/, { 'fill-color': '#1c2a40' }],
      [/^park$/, { 'fill-color': '#144a57', 'fill-opacity': 1, 'fill-outline-color': '#144a57' }],
      [/^park_outline$/, { 'line-opacity': 0 }],
      [/^landcover_wood$/, { 'fill-color': '#144a57', 'fill-opacity': 1 }],
      [/^landcover_grass$/, { 'fill-color': '#133949', 'fill-opacity': 1 }],
      [/^landcover_ice$/, { 'fill-color': '#485876' }],
      [/^landcover_sand$/, { 'fill-color': '#2f364c' }],
      [/^landuse_(pitch|track)$/, { 'fill-color': '#175a69' }],
      [/^landuse_cemetery$/, { 'fill-color': '#133949' }],
      [/^landuse_hospital$/, { 'fill-color': '#3d3d57' }],
      [/^landuse_school$/, { 'fill-color': '#21344d' }],
      [/^water$/, { 'fill-color': '#00102e' }],
      [/^waterway_(tunnel|river|other)$/, { 'line-color': '#00102e' }],
      [/^aeroway_fill$/, { 'fill-color': '#2e476d', 'fill-opacity': 1 }],
      [/^aeroway_(runway|taxiway)$/, { 'line-color': '#273f62' }],
      [/^(road|tunnel|bridge)_motorway(_link)?$/, { 'line-color': '#47688a' }],
      [/^(road|tunnel|bridge)_motorway(_link)?_casing$/, { 'line-color': '#3e5a77' }],
      [/^(road|tunnel|bridge)_trunk_primary$/, { 'line-color': ['match',['get','class'],'trunk','#47688a','#3e5a77'] }],
      [/^(road|tunnel|bridge)_trunk_primary_casing$/, { 'line-color': ['match',['get','class'],'trunk','#3e5a77','#111926'] }],
      [/^(road|tunnel|bridge)_(secondary_tertiary|link)$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,'#3c4d62',14,'#3e5a77'] }],
      [/^(road|tunnel|bridge)_(secondary_tertiary|link)_casing$/, { 'line-color': '#111926' }],
      [/^(road_minor|tunnel_minor|bridge_street)$/, { 'line-color': ['interpolate',['linear'],['zoom'],12,'#28394c',15,'#3e5a77'] }],
      [/^(road_minor|tunnel_street|bridge_street)_casing$/, { 'line-color': '#111926' }],
      [/^(road|tunnel|bridge)_service_track$/, { 'line-color': '#34455a' }],
      [/^(road|tunnel|bridge)_service_track_casing$/, { 'line-color': '#111926' }],
      [/^(road|tunnel|bridge)_path_pedestrian$/, { 'line-color': '#2d425c' }],
      [/^bridge_path_pedestrian_casing$/, { 'line-color': '#111926' }],
      [/^(road|tunnel|bridge)_(major|transit)_rail(_hatching)?$/, { 'line-color': '#485477' }],
      [/^building$/, { 'fill-color': '#213d69', 'fill-outline-color': '#2e3e56' }],
      [/^building-3d$/, { 'fill-extrusion-color': '#213d69' }],
      [/^boundary_2$/, { 'line-color': '#bbbec1' }],
      [/^boundary_3$/, { 'line-color': '#858b8e' }],
      [/^boundary_disputed$/, { 'line-color': '#858b8e' }],
      [/^label_(country_[123]|city|city_capital)$/, { 'text-color': '#f0f2f5' }],
      [/^label_(town|village)$/, { 'text-color': '#c4ccd7' }],
      [/^label_(state|other)$/, { 'text-color': '#8e97a3' }],
      [/^label_/, { 'text-halo-color': '#191d23' }],
      [/^(water_name_(point|line)_label|waterway_line_label)$/, { 'text-color': '#88b3f7' }],
      [/^highway-name-(major|minor|path)$/, { 'text-color': '#c4ccd7', 'text-halo-color': '#191d23' }],
      [/^poi_r(1|7|20)$/, { 'text-color': '#9aa0a9' }],
      [/^poi_transit$/, { 'text-color': '#b5bfcd' }],
      [/^(poi_|airport$)/, { 'text-halo-color': '#191d23' }],
      [/^airport$/, { 'text-color': '#9aa0a9' }]
    ]
  };
  LF.LOOKS = LOOKS;

  /* Recolour a loaded liberty map. Unknown layers (a style update by the
     tile host) are simply left as they are. Returns how many were changed. */
  LF.applyLook = function (map, look) {
    var rules = LOOKS[look];
    if (!rules || !map || !map.getStyle) return 0;
    var changed = 0;
    (map.getStyle().layers || []).forEach(function (layer) {
      if (layer.id.indexOf('lf-') === 0) return;               // our own trail / routes
      // Paint properties start with the layer type: "fill-…", "line-…"; a label's are "text-…".
      var prefix = layer.type === 'symbol' ? 'text-' : layer.type + '-';
      rules.forEach(function (r) {
        if (!r[0].test(layer.id)) return;
        for (var prop in r[1]) {
          // A property that does not belong to this layer type is skipped quietly.
          if (prop.indexOf(prefix) !== 0 || (layer.type === 'fill' && prop.indexOf('fill-extrusion-') === 0)) continue;
          try { map.setPaintProperty(layer.id, prop, r[1][prop]); changed++; } catch (e) { /* keep the original */ }
        }
      });
    });
    return changed;
  };

  var FM = LF.FleetMap.prototype;

  /* The company coach seen from above: white body, navy windscreen at the
     front (pointing up = north before rotation), the SHG gold + navy band. */
  LF.BUS_SVG = '<svg viewBox="0 0 26 60" width="22" height="50" aria-hidden="true">'
    + '<rect x="1.2" y="1.2" width="23.6" height="57.6" rx="5.5" fill="#fff" stroke="#1C2F6B" stroke-width="1.6"/>'
    + '<path d="M3.6 9.5 Q13 3.6 22.4 9.5 L22.4 4.6 Q13 0.8 3.6 4.6 Z" fill="#1C2F6B"/>'
    + '<rect x="6.2" y="15" width="13.6" height="17" rx="2.2" fill="#EEF2F8" stroke="#B9C3D6" stroke-width=".7"/>'
    + '<circle cx="13" cy="23.5" r="3.3" fill="#fff" stroke="#D9A441" stroke-width="1.1"/>'
    + '<path d="M2 41.5 L24 35.5" stroke="#D9A441" stroke-width="2.4"/>'
    + '<path d="M2 45.6 L24 39.6" stroke="#1C2F6B" stroke-width="2.4"/>'
    + '<rect x="4.2" y="53" width="17.6" height="3.8" rx="1.6" fill="#1C2F6B" opacity=".85"/>'
    + '</svg>';

  /* An Indian number plate: white, black letters, the blue IND strip. */
  LF.plate = function (number, cls) {
    return '<span class="lf-plate' + (cls ? ' ' + cls : '') + '"><i>IND</i><b>' + LF.esc(number || '—') + '</b></span>';
  };

  FM._markerEl = function (bus) {
    var d = document.createElement('div');
    d.className = 'lf-bus';
    d.innerHTML = '<span class="lf-halo"></span><span class="lf-ring"></span><span class="lf-body">' + LF.BUS_SVG + '</span>'
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
    var alert = (b.alerts || []).some(function (a) { return a.level === 'red'; });
    it.el.style.setProperty('--lf-c', alert ? '#dc2626' : s.c);
    it.el.classList.toggle('is-live', b.status === 'live');
    it.el.classList.toggle('is-alert', alert);
    it.el.classList.toggle('is-sel', this.selected === b.id);
    it.el.classList.toggle('is-dim', b.status === 'parked' || b.status === 'offline');
    var tag = it.el.querySelector('.lf-tag');
    if (tag) {
      var kmh = b.pos && b.pos.kmh != null && (b.status === 'live' || b.status === 'weak') ? Math.round(b.pos.kmh) + ' km/h' : '';
      var html = LF.plate(b.number, 'sm') + (kmh ? '<small>' + kmh + '</small>' : '');
      if (tag._h !== html) { tag.innerHTML = html; tag._h = html; }
    }
    // Keep the last good heading: a parked bus must not spin to "north".
    var p = b.pos || {};
    if (p.hdg != null && (p.kmh == null || p.kmh >= 3)) it.hdg = p.hdg;
    it.el.title = (b.name || '') + ' ' + (b.number || '') + ' — ' + s.en;
  };

  /* metres per screen pixel at this latitude and zoom (Web Mercator, 512 px tiles) */
  function mPerPx(lat, zoom) { return 40075016.686 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom + 9); }

  FM._rotateArrows = function () {
    var bearing = this.map ? this.map.getBearing() : 0, zoom = this.map ? this.map.getZoom() : 10;
    for (var id in this.items) {
      var it = this.items[id], p = it.bus.pos || {};
      it.el.querySelector('.lf-body').style.transform = 'translate(-50%,-50%) rotate(' + ((it.hdg || 0) - bearing) + 'deg)';
      // A faint circle the size of the GPS error, only when the fix is poor.
      var halo = it.el.querySelector('.lf-halo');
      if (p.acc != null && p.acc > 50 && it.bus.status !== 'parked') {
        var px = Math.min(420, 2 * p.acc / mPerPx(p.lat, zoom));
        halo.style.display = px > 44 ? '' : 'none';
        halo.style.width = halo.style.height = px + 'px';
      } else {
        halo.style.display = 'none';
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

  /* The bus markers on a map someone else made (the site's Live Map):
     no trail, no controls — just update(), select(), focus(). */
  LF.FleetMap.attach = function (map, opts) {
    var fm = Object.create(FM);
    fm.opts = opts || {}; fm.items = {}; fm.selected = null; fm.following = false; fm.raf = null;
    fm.map = map; fm.el = map.getContainer();
    fm.ready = Promise.resolve(fm);
    map.on('rotate', function () { fm._rotateArrows(); });
    map.on('zoom', function () { fm._rotateArrows(); });
    return fm;
  };

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

  /* A greeting that fits the temple's deity — said after its name.
     Checked in this order, first match wins: "Swaminarayan" must not be
     caught by "narayan", "Rameshwar" not by "ram", "Sai Ram" not by "ram".
     Latin and Devanagari words both match whole words ("राम" is not
     found in "रामपुर"); a trailing "जी" is allowed: "हनुमानजी". */
  var GREET = [
    ['जय पशुपतिनाथ', 'pashupati, pashupatinath', 'पशुपति'],
    ['जय स्वामिनारायण', 'swaminarayan, swami narayan, baps, akshardham, nilkanth varni, sahajanand', 'स्वामिनारायण स्वामीनारायण अक्षरधाम'],
    ['जय जगन्नाथ', 'jagannath, jagannatha', 'जगन्नाथ'],
    ['ॐ साईं राम', 'sai, saibaba, sai baba, shirdi', 'साईं साई शिर्डी'],
    ['जय सूर्यदेव', 'surya, sun temple, suryanarayan, modhera', 'सूर्य मोढेरा'],
    ['ॐ नमो नारायणाय', 'tirupati, venkateshwara, venkateswara, venkatesh, vishnu, narayan, narayana, laxminarayan, lakshmi narayan, badrinath, muktinath, budhanilkantha, vitthal', 'विष्णु नारायण वेंकटेश्वर तिरुपति बद्रीनाथ मुक्तिनाथ बूढानीलकण्ठ विट्ठल'],
    ['जय श्री श्याम', 'khatu, khatushyam, khatu shyam', 'खाटू'],
    ['जय द्वारकाधीश', 'dwarka, dwarkadhish, ranchhod, ranchhodrai, dakor', 'द्वारका रणछोड डाकोर'],
    ['राधे राधे', 'radha, radhe, banke bihari, vrindavan, iskcon', 'राधा राधे वृन्दावन इस्कोन'],
    ['जय श्रीकृष्ण', 'krishna, krishn, gopal, govind, kanha, shrinathji, nathdwara', 'कृष्ण श्रीकृष्ण गोपाल गोविन्द कान्हा श्रीनाथ'],
    ['जय बजरंगबली', 'hanuman, hanumanji, bajrang, bajrangbali, maruti, balaji, salasar, mehandipur, kashtabhanjan, sankat mochan', 'हनुमान बजरंग बजरङ्ग मारुति बालाजी सालासर कष्टभंजन संकटमोचन'],
    ['जय बाबा रामदेव', 'ramdev, ramdevra, ramdev pir', 'रामदेव'],
    ['जय जलाराम बापा', 'jalaram, jalaram bapa, virpur', 'जलाराम वीरपुर'],
    ['जय श्रीराम', 'ram, shri ram, shriram, raghunath, sitaram, siyaram, ayodhya', 'राम श्रीराम रघुनाथ सीताराम सियाराम अयोध्या'],
    ['जय श्री गणेश', 'ganesh, ganesha, ganpati, ganapati, vinayak, siddhivinayak, gajanan', 'गणेश गणपति विनायक गजानन'],
    ['जय बहुचर माँ', 'bahuchar, bahucharaji, bahuchara, becharaji', 'बहुचर'],
    ['जय अम्बे माँ', 'ambaji, amba, ambe, ambika', 'अम्बाजी अम्बा अम्बे अम्बिका'],
    ['जय माँ काली', 'kali, mahakali, kalika, dakshinkali, pavagadh', 'काली कालिका पावागढ'],
    ['जय माता दी', 'mata, mataji, maa, maiya, mai, devi, durga, chamunda, bhavani, vaishno, vaishnodevi, khodiyar, momai, ashapura, harsiddhi, santoshi, sherawali, jagdamba, devipatan, manakamana, guhyeshwari', 'माता माँ मैया माई देवी दुर्गा चामुण्डा भवानी वैष्णो खोडियार आशापुरा हरसिद्धि सन्तोषी जगदम्बा मनकामना'],
    ['जय शनिदेव', 'shani, shanidev, shani dev', 'शनि'],
    ['जय गुरु गोरखनाथ', 'gorakhnath, gorakh', 'गोरखनाथ'],
    ['जय गुरुदेव दत्त', 'datta, dattatreya, datt', 'दत्त'],
    ['हर हर महादेव', 'shiv, shiva, mahadev, mahadeo, shankar, bholenath, bhole, mahakal, mahakaleshwar, somnath, kedarnath, vishwanath, omkareshwar, nageshwar, rameshwar, rameshwaram, neelkanth, nilkanth, jyotirling, shivalaya', 'शिव महादेव शंकर शङ्कर भोलेनाथ महाकाल सोमनाथ केदारनाथ विश्वनाथ ओंकारेश्वर नागेश्वर रामेश्वर नीलकण्ठ ज्योतिर्लिङ्ग'],
    ['नमो बुद्धाय', 'buddha, bouddha, buddhist, vihar, vihara, stupa, gumba, gompa, lumbini', 'बुद्ध बौद्ध विहार स्तूप गुम्बा लुम्बिनी'],
    ['जय जिनेन्द्र', 'jain, derasar, jinalaya, mahavir, mahaveer, parshvanath, adinath, palitana, taranga', 'जैन देरासर जिनालय महावीर पार्श्वनाथ आदिनाथ'],
    ['सत श्री अकाल', 'gurudwara, gurdwara', 'गुरुद्वारा']
  ].map(function (g) {
    return {
      say: g[0],
      re: new RegExp('(^|[^a-z])(' + g[1].split(/,\s*/).join('|') + ')(?![a-z])', 'i'),   // whole words only
      dev: g[2].split(' ')
    };
  });
  LF.greet = function (name, en) {
    var t = (name || '') + ' ' + (en || '');
    var words = t.split(/[\s,.()\/\-–]+/);
    for (var i = 0; i < GREET.length; i++) {
      var g = GREET[i];
      if (g.re.test(t) || g.dev.some(function (d) { return words.indexOf(d) !== -1 || words.indexOf(d + 'जी') !== -1; })) return g.say;
    }
    return 'हरि ॐ';
  };

  /* ── vehicle numbers, read aloud the railway way: letter by letter ── */
  var NE_LETTER = { A: 'ए', B: 'बी', C: 'सी', D: 'डी', E: 'ई', F: 'एफ', G: 'जी', H: 'एच', I: 'आई', J: 'जे', K: 'के', L: 'एल', M: 'एम',
    N: 'एन', O: 'ओ', P: 'पी', Q: 'क्यू', R: 'आर', S: 'एस', T: 'टी', U: 'यू', V: 'भी', W: 'डब्लु', X: 'एक्स', Y: 'वाई', Z: 'जेड' };
  var NE_DIGIT_WORD = ['शून्य', 'एक', 'दुई', 'तीन', 'चार', 'पाँच', 'छ', 'सात', 'आठ', 'नौ'];
  /** "GJ-02-T-4127" → "जी जे, शून्य दुई, टी, चार एक दुई सात" */
  LF.spellPlate = function (number) {
    return String(number || '').toUpperCase().split(/[-\s]+/).filter(Boolean).map(function (part) {
      return part.split('').map(function (c) {
        if (/[0-9]/.test(c)) return NE_DIGIT_WORD[+c];
        return NE_LETTER[c] || '';
      }).filter(Boolean).join(' ');
    }).join(', ');
  };

  /** "२५ मिनेट ढिलो" / "समयमै" / "१० मिनेट छिटो" — or '' without a timetable. */
  LF.neLate = function (min) {
    if (min == null) return '';
    if (min >= 5) return LF.neDur(min) + ' ढिलो';
    if (min <= -5) return LF.neDur(-min) + ' छिटो';
    return 'समयमै';
  };
  LF.lateColor = function (min) { return min == null ? '#64748b' : min >= 30 ? '#dc2626' : min >= 5 ? '#d97706' : '#16a34a'; };

  /* ── what changed since the last refresh → Nepali sentences ────────
     One Tracker per page. events(buses) returns [{ busId, icon, text, speak, level }].
     The wording follows railway / airport announcements:
       🚌 a trip starts: "… बाट … का लागि प्रस्थान गरेको छ"
       🚏 arrived at a stop / left it / nearly there — with lateness and the next stop's km + time
       🏁 reached the last stop: "… आइपुगेर आजको यात्रा सकुशल सम्पन्न भयो"
       📍 reached a town or village (at most once a minute per bus)
       🛕 a temple within 1 km (once per temple per bus), with its deity's greeting
       ⏱️ running late: when the delay first passes 15, 30, 60, 120 minutes
       🆘 / 🛠️ / ⚠️ / 📵 / ⏸️ a new alert (SOS and breakdown are 'alarm': siren first)
       🕒 every 15 min: where it is, km + time to the last stop
     `text` shows the vehicle number; `speak` reads it letter by letter and
     says "किलोमिटर" for "किमी". Set tracker.limit to the speed limit. */
  LF.Tracker = function () {
    this.prev = {}; this.placeAt = {}; this.lastPlace = {}; this.temples = {}; this.etaAt = {};
    this.lateBand = {}; this.alerts = {}; this.nearing = {}; this.limit = 80;
  };
  function forSpeech(t) {
    return t.replace(/किमी\/घण्टा/g, 'किलोमिटर प्रतिघण्टा').replace(/किमी/g, 'किलोमिटर').replace(/~/g, 'करिब ');
  }
  function at(b) { return b.place ? b.place.name + (b.place.at ? 'मा' : ' नजिक') : ''; }
  LF.Tracker.prototype.events = function (buses, nowMs) {
    var out = [], self = this, now = nowMs || Date.now();
    buses.forEach(function (b) {
      var p = self.prev[b.id];
      var moving = b.status === 'live' || b.status === 'weak';
      var onRoad = moving || b.status === 'offline' || b.status === 'waiting';
      var tripId = b.trip ? b.trip.id : null;
      self.prev[b.id] = { status: b.status, stop: b.stop, place: b.place, trip: tripId };
      var plate = b.number || b.name, spoken = LF.spellPlate(b.number) || b.name;
      // "बस GJ-02-T-4127 वडोदरा आइपुग्यो।" — pre goes before "बस" ("सावधान! बस …").
      var push = function (icon, body, level, pre) {
        var glue = /^(बाट|को|मा)(\s|$)/.test(body) ? ' ' : ', ';
        out.push({ busId: b.id, icon: icon, level: level || 'info',
          text: (pre || '') + 'बस ' + plate + ' ' + body,
          speak: forSpeech((pre || '') + 'बस ' + spoken + glue + body) });
      };

      // Alerts first — they matter most, and they also arrive while the bus is offline.
      var seen = self.alerts[b.id] || {}, now2 = {};
      (onRoad ? b.alerts || [] : []).forEach(function (a) {
        now2[a.key] = true;
        if (seen[a.key] || !p) return;      // only NEW alerts, and not the ones already there when the page opened
        var where = at(b) ? ' — ' + at(b) : '';
        var kmh = Math.round((b.pos && b.pos.kmh) || 0);
        if (a.key.indexOf('sos') === 0) push('🆘', 'बाट आपतकालीन सन्देश आएको छ! मद्दत चाहियो' + where + '। कृपया तुरुन्त चालकसँग सम्पर्क गर्नुहोला।', 'alarm');
        else if (a.key.indexOf('breakdown') === 0) push('🛠️', 'बिग्रिएको सूचना आएको छ' + where + '। कृपया चालकसँग सम्पर्क गर्नुहोला।', 'alarm');
        else if (a.key === 'overspeed') push('⚠️', 'तोकिएको सीमाभन्दा बढी गतिमा चलिरहेको छ। हालको गति ' + LF.neSpeed(kmh) + ' छ, सीमा ' + LF.neSpeed(self.limit) + ' हो।', 'warn', 'सावधान! ');
        else if (a.key === 'nodata') push('📵', 'बाट जीपीएस संकेत आउन बन्द भएको छ।' + (b.age_s ? ' अन्तिम पटक ' + LF.neDur(Math.round(b.age_s / 60)) + 'अघि' + (b.place ? ' ' + b.place.name + ' नजिक' : '') + ' देखिएको थियो।' : ''), 'warn', 'ध्यान दिनुहोला! ');
        else if (a.key === 'stopped') push('⏸️', (b.place ? b.place.name + ' नजिक ' : '') + LF.ne(a.ne) + ' छ। कृपया चालकसँग सम्पर्क गर्नुहोला।', 'warn', 'ध्यान दिनुहोला! ');
        else if (a.level === 'red' || a.key.indexOf('puncture') === 0 || a.key.indexOf('police') === 0) push(a.icon || '⚠️', '— ' + a.ne + where + '।', 'warn');
      });
      self.alerts[b.id] = now2;
      if (!moving) return;

      var st = b.stop, said = false, tl = b.timeline, stops = tl && tl.stops ? tl.stops : [];
      var nextTxt = function () {
        return st && st.next ? ' अर्को स्टप ' + LF.nm(st.next_ne, st.next) + ' हो, जुन यहाँबाट करिब ' + LF.neKm(st.next_km)
          + ' टाढा छ। त्यहाँ पुग्न करिब ' + LF.neDur(st.next_min) + ' लाग्नेछ।' : '';
      };
      var firstStop = stops.length ? LF.nm(stops[0].ne, stops[0].name) : '';
      var lastStop = stops.length ? stops[stops.length - 1] : null;
      var lastName = lastStop ? LF.nm(lastStop.ne, lastStop.name) : '';

      // A trip that starts while the page is open.
      if (p && tripId && p.trip !== tripId && b.trip && now - b.trip.started < 10 * 60000) {
        push('🚌', (firstStop ? firstStop + 'बाट ' : '') + (lastName ? lastName + 'का लागि ' : '') + 'प्रस्थान गरेको छ। सम्पूर्ण यात्रुहरूको यात्रा सुखद र सुरक्षित रहोस्।', 'info', 'एस हरि ग्लोबलको ');
        said = true;
      }
      if (!p) {
        if (b.place) {
          push('📍', 'अहिले ' + at(b) + ' छ।' + nextTxt());
          self.lastPlace[b.id] = b.place.name; self.placeAt[b.id] = now;
        }
        self.etaAt[b.id] = now;
        self.lateBand[b.id] = lateBand(b.delay);
        return;
      }
      if (st && st.at && (!p.stop || !p.stop.at || p.stop.near !== st.near)) {
        var name = LF.nm(st.near_ne, st.near), late = LF.neLate(b.delay);
        if (lastStop && st.near === lastStop.name && !st.next) {
          push('🏁', name + ' आइपुगेर आजको यात्रा सकुशल सम्पन्न भयो।' + (late && late !== 'समयमै' ? ' (' + late + ')' : ''));
          self.lateBand[b.id] = 9;                  // the trip is over: no "running late" after this
        } else {
          push('🚏', name + ' आइपुग्यो' + (late ? ', ' + late : '') + '।' + nextTxt());
        }
        said = true;
      } else if (p.stop && p.stop.at && st && !st.at) {
        push('🚌', LF.nm(p.stop.near_ne, p.stop.near) + 'बाट प्रस्थान गरेको छ।' + (st.next ? ' अब यो ' + LF.nm(st.next_ne, st.next) + 'तर्फ जाँदै छ। त्यहाँ पुग्न करिब ' + LF.neDur(st.next_min) + ' लाग्नेछ।' : ''));
        said = true;
      } else if (st && !st.at && st.next && st.next_km != null && st.next_km <= 5 && st.next_min != null && st.next_min <= 10
                 && self.nearing[b.id] !== st.next) {
        // Nearly there: people waiting at that stop get ready.
        self.nearing[b.id] = st.next;
        push('🔔', LF.nm(st.next_ne, st.next) + ' नजिक आइपुगेको छ र करिब ' + LF.neDur(st.next_min) + 'मा त्यहाँ पुग्नेछ। त्यहाँबाट चढ्ने यात्रुहरू कृपया तयार रहनुहोला।');
        said = true;
      }
      if (b.place && b.place.at && b.place.name !== self.lastPlace[b.id] && now - (self.placeAt[b.id] || 0) >= 60000) {
        self.lastPlace[b.id] = b.place.name; self.placeAt[b.id] = now;
        if (!said) {
          var a = b.ahead;
          push('📍', b.place.name + ' आइपुग्यो।' + (a ? ' अगाडि ' + a.name + ' — ' + LF.neKm(a.km) + ', करिब ' + LF.neDur(a.min) + '।' : ''));
          said = true;
        }
      }
      (b.temples || []).some(function (t) {
        var k = b.id + ':' + t.id;
        if (t.km > 1.0 || self.temples[k]) return false;
        self.temples[k] = now;
        push('🛕', 'अहिले ' + t.name + ' नजिकबाट जाँदै छ। ' + LF.greet(t.name, t.en) + '!');
        return true;
      });
      var band = lateBand(b.delay);
      if (band > (self.lateBand[b.id] || 0)) {
        push('⏱️', 'आफ्नो निर्धारित समयभन्दा करिब ' + LF.neDur(b.delay) + ' ढिला चलिरहेको छ।'
          + (st && st.next && st.next_min != null ? ' ' + LF.nm(st.next_ne, st.next) + ' पुग्ने अनुमानित समय ' + LF.neClock(now + st.next_min * 60000) + ' बजे हो।' : '')
          + ' असुविधाका लागि हामी क्षमाप्रार्थी छौँ।', 'warn');
      }
      self.lateBand[b.id] = band;
      if (now - (self.etaAt[b.id] || 0) >= 15 * 60000 && st && st.upcoming && st.upcoming.length) {
        self.etaAt[b.id] = now;
        var d = st.upcoming[st.upcoming.length - 1];
        push('🕒', 'अहिले ' + (at(b) || 'बाटोमा') + ' छ, गति ' + LF.neSpeed(b.pos && b.pos.kmh) + '। ' + LF.nm(d.ne, d.name) + ' पुग्न अझै ' + LF.neKm(d.km)
          + ' बाँकी छ र करिब ' + LF.neDur(d.min) + ' लाग्नेछ — ' + LF.neClock(now + d.min * 60000) + ' बजेसम्म पुग्ने अनुमान।');
      }
    });
    return out;
  };
  function lateBand(min) { return min == null ? 0 : min >= 120 ? 4 : min >= 60 ? 3 : min >= 30 ? 2 : min >= 15 ? 1 : 0; }

  /* ── sound: a station chime before each announcement, a siren for SOS ── */
  var audioCtx = null;
  function ctx() {
    var A = w.AudioContext || w.webkitAudioContext;
    if (!A) return null;
    if (!audioCtx) audioCtx = new A();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    return audioCtx;
  }
  /* One bell note, the way a station PA chime sounds: sine partials at 1×,
     4× and 10× the pitch (the tuning of a vibraphone bar), the overtones
     dying first so it rings "ding" rather than beeps. */
  function bell(c, out, f, t0) {
    [[1, 1.0, 1.8], [4, 0.25, 0.5], [10, 0.06, 0.15]].forEach(function (p) {
      var o = c.createOscillator(), g = c.createGain();
      o.type = 'sine'; o.frequency.value = f * p[0];
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(p[1], t0 + 0.008);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + p[2]);
      o.connect(g).connect(out);
      o.start(t0); o.stop(t0 + p[2] + 0.05);
    });
  }
  function bells(notes, gap) {
    var c = ctx();
    if (!c) return 0;
    var out = c.createGain(), t = c.currentTime + 0.05;
    out.gain.value = 0.35;
    out.connect(c.destination);
    notes.forEach(function (f, i) { bell(c, out, f, t + i * gap); });
    return Math.round(((notes.length - 1) * gap + 1.1) * 1000) + 300;   // a short silence before the voice
  }
  /** Railway / airport "ding-dong-ding" before an announcement (G4 E5 C5). ~2.2 s */
  LF.chime = function () { return bells([392.00, 659.25, 523.25], 0.40); };
  /** Two-note "ding-dong" before a warning (E5 C5). ~2 s */
  LF.dingDong = function () { return bells([659.25, 523.25], 0.55); };
  /** A rising-falling siren for SOS / breakdown. ~2.4 s */
  LF.siren = function () {
    var c = ctx();
    if (!c) return 0;
    var o = c.createOscillator(), g = c.createGain(), t = c.currentTime + 0.05;
    o.type = 'sawtooth';
    for (var i = 0; i < 4; i++) {
      o.frequency.setValueAtTime(650, t + i * 0.6);
      o.frequency.linearRampToValueAtTime(1150, t + i * 0.6 + 0.3);
      o.frequency.linearRampToValueAtTime(650, t + i * 0.6 + 0.6);
    }
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.18, t + 0.05);
    g.gain.setValueAtTime(0.18, t + 2.3); g.gain.exponentialRampToValueAtTime(0.0001, t + 2.45);
    o.connect(g).connect(c.destination); o.start(t); o.stop(t + 2.5);
    return 2500;
  };

  /* ── speaking (Web Speech API) ────────────────────────────────────
     Browsers only allow sound after a click, so the page shows a 🔊 button
     that calls enable(true); if the browser still refuses ("not-allowed")
     onBlocked() is called so the page can show the button again.
     Voice, best first: the one picked in the settings; a Nepali voice —
     Edge on Windows has "Microsoft Hemkala / Sagar Online (Natural)",
     Android has Google's Nepali voice; else Hindi (same script, clearly
     understood). A voice that fails (an "Online" voice with the internet
     down) is skipped from then on. Each sentence is its own utterance:
     Chrome cuts long ones off after ~15 s. Fully Kiosk (Android TV boxes)
     has no Web Speech, so its own fully.textToSpeech() is used there. */
  var VKEY = 'lf:voice';
  LF.INTRO = 'आदरणीय यात्रुहरू, कृपया ध्यान दिनुहोला।';
  LF.Announcer = function (onText, onBlocked) {
    this.on = false; this.q = []; this.busy = false; this.voice = null; this.bad = {};
    this.onText = onText || null; this.onBlocked = onBlocked || null;
    this.cfg = { voice: '', rate: 0.92, chime: true, intro: false };
    try { var c = JSON.parse(localStorage.getItem(VKEY) || 'null'); if (c) for (var k in c) this.cfg[k] = c[k]; } catch (e) {}
  };
  var AN = LF.Announcer.prototype;
  function webSpeech() { return 'speechSynthesis' in w && typeof w.SpeechSynthesisUtterance === 'function'; }
  function fullyTts() { return !webSpeech() && w.fully && typeof w.fully.textToSpeech === 'function'; }
  AN.supported = function () { return webSpeech() || fullyTts(); };
  AN.voices = function () {
    if (!webSpeech()) return [];
    var rank = function (v) {
      var l = v.lang.replace('_', '-');
      var base = /^ne/i.test(l) ? 0 : /^hi/i.test(l) ? 2 : /^(mr|gu|bn)/i.test(l) ? 4 : /^en-IN/i.test(l) ? 6 : 8;
      return base + (/natural|online|neural|google/i.test(v.name) ? 0 : 1);     // the natural-sounding ones first
    };
    return (w.speechSynthesis.getVoices() || []).filter(function (v) { return v && v.name && v.lang; })   // Edge sometimes lists blank voices
      .sort(function (a, b) { return rank(a) - rank(b) || (a.name < b.name ? -1 : 1); });
  };
  AN.pickVoice = function () {
    var v = this.voices(), want = this.cfg.voice, bad = this.bad, i;
    for (i = 0; i < v.length; i++) if (want && v[i].voiceURI === want && !bad[v[i].voiceURI]) return v[i];
    for (i = 0; i < v.length; i++) if (!bad[v[i].voiceURI] && /^(ne|hi|mr)/i.test(v[i].lang)) return v[i];
    return null;
  };
  AN.save = function () { try { localStorage.setItem(VKEY, JSON.stringify(this.cfg)); } catch (e) {} this.voice = this.pickVoice(); };
  AN.enable = function (on) {
    this.on = !!on && this.supported();
    if (webSpeech()) w.speechSynthesis.cancel();
    this.q = []; this.busy = false;
    if (this.on) { ctx(); this.voice = this.pickVoice(); this._play({ speak: 'नमस्कार। एस हरि ग्लोबलको लाइभ बस घोषणा सुरु भयो।', level: 'info' }); }
    return this.on;
  };
  /** Show it now; speak it when its turn comes. level: info | warn | alarm (alarm jumps the queue). */
  AN.say = function (text, icon, speak, level) {
    if (this.onText) this.onText(text, icon, level || 'info');
    if (!this.on) return;
    var item = { speak: speak || text, level: level || 'info' };
    if (item.level === 'alarm') this.q.unshift(item); else this.q.push(item);
    if (this.q.length > 5) this.q.splice(5);       // never fall minutes behind the buses
    this._next();
  };
  AN.test = function () {
    this._play({ speak: 'बस जी जे, शून्य दुई, टी, चार एक दुई सात, भरुच आइपुग्यो। अर्को स्टप वडोदरा हो, जुन यहाँबाट करिब सत्तरी किलोमिटर टाढा छ। त्यहाँ पुग्न करिब एक घण्टा लाग्नेछ।', level: 'info' });
  };
  AN._next = function () {
    if (this.busy || !this.q.length) return;
    this._play(this.q.shift());
  };
  AN._play = function (item) {
    var self = this;
    this.busy = true;
    var lead = item.level === 'alarm' ? LF.siren() : !this.cfg.chime ? 0 : item.level === 'warn' ? LF.dingDong() : LF.chime();
    setTimeout(function () { self._speak((self.cfg.intro && item.level === 'info' ? LF.INTRO + ' ' : '') + item.speak); }, lead);
  };
  AN._speak = function (text) {
    var self = this;
    var clean = String(text).replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '').replace(/—/g, ',').replace(/\s+/g, ' ').trim();
    var done = false, fin = function () { if (done) return; done = true; self.busy = false; setTimeout(function () { self._next(); }, 500); };
    setTimeout(fin, 20000 + clean.length * 120);     // some TV browsers never fire onend
    if (fullyTts()) {
      try { w.fully.textToSpeech(clean, 'ne_NP'); } catch (e) {}
      setTimeout(fin, 1500 + clean.length * 90);
      return;
    }
    var parts = clean.replace(/([।!?])\s*/g, '$1\n').split('\n').filter(function (x) { return x.trim(); });
    var i = 0, retried = false;
    var one = function () {
      if (i >= parts.length || done) { fin(); return; }
      var u = new w.SpeechSynthesisUtterance(parts[i]);
      if (!self.voice) self.voice = self.pickVoice();
      if (self.voice) u.voice = self.voice;
      u.lang = self.voice ? self.voice.lang.replace('_', '-') : 'hi-IN';    // Android often follows lang, not voice
      u.rate = +self.cfg.rate || 0.92;
      u.onend = function () { i++; one(); };
      u.onerror = function (e) {
        var why = e && e.error;
        if (why === 'not-allowed') {                // no click yet on this page
          self.on = false; self.q = [];
          if (self.onBlocked) self.onBlocked();
          fin(); return;
        }
        if (why === 'interrupted' || why === 'canceled') { fin(); return; }
        if (self.voice && !retried) {               // this voice is broken here — try the next one once
          self.bad[self.voice.voiceURI] = true; self.voice = self.pickVoice(); retried = true;
          one(); return;
        }
        i++; one();
      };
      w.speechSynthesis.speak(u);
    };
    one();
  };
  if (webSpeech() && w.speechSynthesis.addEventListener) {
    w.speechSynthesis.addEventListener('voiceschanged', function () {});   // loads the voice list early on Chrome
  }

  /** A small settings box: voice, speed, chime, railway intro, test. */
  LF.voicePanel = function (box, an) {
    function render() {
      var v = an.voices();
      box.innerHTML = '<div class="lf-vp"><label>आवाज · Voice <select data-k="voice"><option value="">स्वतः (नेपाली → हिन्दी)</option>'
        + v.map(function (x) { return '<option value="' + LF.esc(x.voiceURI) + '"' + (x.voiceURI === an.cfg.voice ? ' selected' : '') + '>' + LF.esc(x.name + ' · ' + x.lang) + '</option>'; }).join('')
        + '</select></label><label>गति · Speed <input type="range" min="0.7" max="1.2" step="0.02" data-k="rate" value="' + an.cfg.rate + '"></label>'
        + '<label class="ck"><input type="checkbox" data-k="chime"' + (an.cfg.chime ? ' checked' : '') + '> 🔔 घण्टी</label>'
        + '<label class="ck"><input type="checkbox" data-k="intro"' + (an.cfg.intro ? ' checked' : '') + '> "' + LF.INTRO + '"</label>'
        + '<button type="button" data-test>▶ परीक्षण</button>'
        + (v.some(function (x) { return /^ne/i.test(x.lang); }) ? '' : '<small>यो यन्त्रमा नेपाली आवाज छैन — हिन्दी आवाजले पढ्छ। Windows: Microsoft Edge मा खोल्नुहोस् (Hemkala / Sagar नेपाली आवाज)। Android: Settings → Text-to-speech → Speech Services by Google → Install voice data → नेपाली।</small>')
        + '</div>';
    }
    render();
    if (webSpeech() && w.speechSynthesis.addEventListener) w.speechSynthesis.addEventListener('voiceschanged', render);
    box.addEventListener('change', function (e) {
      var k = e.target.getAttribute('data-k');
      if (!k) return;
      an.cfg[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.type === 'range' ? +e.target.value : e.target.value;
      an.save();
    });
    box.addEventListener('click', function (e) { if (e.target.hasAttribute('data-test')) { ctx(); an.test(); } });
  };

  /* ── what every bus card shows ─────────────────────────────────── */

  /** NTES-style one line: "वडोदराबाट बिहान ११:२० मा छुट्यो · अर्को आनन्द ३२ किमी · २५ मिनेट ढिलो · ५ सेकेन्ड अघि" */
  LF.headline = function (b) {
    var parts = [], tl = b.timeline, st = b.stop;
    if (tl && tl.last_dep && !(st && st.at)) parts.push(LF.nm(tl.last_dep.ne, tl.last_dep.name) + 'बाट ' + LF.neClock(tl.last_dep.at) + 'मा छुट्यो');
    if (st && st.at) parts.push(LF.nm(st.near_ne, st.near) + 'मा छ');
    if (st && st.next) parts.push('अर्को ' + LF.nm(st.next_ne, st.next) + ' ' + LF.neKm(st.next_km));
    var late = LF.neLate(b.delay);
    if (late) parts.push('<b style="color:' + LF.lateColor(b.delay) + '">' + late + '</b>');
    if (b.age_s != null && b.status !== 'parked') {
      var a = b.age_s;
      parts.push('<span style="color:' + (a > 120 ? '#dc2626' : a > 30 ? '#d97706' : 'inherit') + '">' + (a > 180 ? 'सिग्नल छैन · ' : '')
        + (a < 3 ? 'अहिले' : a < 60 ? LF.ne(a) + ' सेकेन्ड अघि' : LF.neDur(Math.round(a / 60)) + ' अघि') + '</span>');
    }
    return parts.map(function (x) { return x.charAt(0) === '<' ? x : LF.esc(x); }).join(' · ');
  };

  /** Railway running status: every stop with timetable, actual / expected time and lateness. */
  LF.timelineHtml = function (b) {
    var tl = b.timeline;
    if (!tl || !tl.stops || !tl.stops.length) return '';
    var t = function (ms) { return ms ? LF.neClock(ms) : '—'; };
    return '<ol class="lf-tl">' + tl.stops.map(function (s) {
      var real = s.arr ? t(s.arr) + (s.dep ? ' → ' + t(s.dep) : '') : s.eta ? '~' + t(s.eta) : (s.state === 'done' ? 'पार भयो' : '—');
      var late = s.late != null && (s.arr || s.eta) ? '<em style="background:' + LF.lateColor(s.late) + '">' + LF.esc(LF.neLate(s.late)) + '</em>' : '';
      return '<li class="is-' + s.state + '"><i></i><div><b>' + LF.esc(LF.nm(s.ne, s.name)) + '</b>'
        + (s.ne ? '<small>' + LF.esc(s.name) + '</small>' : '') + '</div><div class="tm"><span>' + (s.sched ? 'तालिका ' + t(s.sched) : '')
        + '</span><span>' + real + '</span>' + late + '</div></li>';
    }).join('') + '</ol>';
  };

  LF.alertsHtml = function (b, canResolve) {
    return (b.alerts || []).map(function (a) {
      return '<div class="lf-alert is-' + a.level + '"><span>' + LF.esc(a.icon) + '</span><b>' + LF.esc(a.ne) + '</b><small>' + LF.esc(a.en) + '</small>'
        + (canResolve && a.id ? '<button type="button" data-resolve="' + a.id + '">✓ समाधान</button>' : '') + '</div>';
    }).join('');
  };

  /** Phone health line: 🔋 76% ⚡ · 📶 4G · ±6 m · 🏔 120 m · bus phone */
  LF.phoneHtml = function (b) {
    var ph = b.phone || {}, p = b.pos || {}, out = [];
    if (b.battery != null) out.push('🔋 ' + b.battery + '%' + (ph.charging ? ' ⚡' : ''));
    if (ph.net) out.push('📶 ' + LF.esc(String(ph.net).toUpperCase()));
    if (p.acc != null) out.push('±' + Math.round(p.acc) + ' m');
    if (ph.alt != null) out.push('🏔 ' + Math.round(ph.alt) + ' m');
    if (ph.kind === 'vehicle') out.push('📱 बस फोन');
    if (ph.visible === false) out.push('📴 एप पछाडि');
    return out.join(' · ');
  };

  /* ── trip replay: play a night's trip back on the map ──────────── */
  LF.Replay = function (fm, data, onFrame) {
    this.fm = fm; this.d = data; this.onFrame = onFrame; this.speed = 60; this.playing = false; this.raf = null;
    var pts = data.points || [];
    this.t0 = pts.length ? pts[0][0] : 0; this.t1 = pts.length ? pts[pts.length - 1][0] : 0; this.t = this.t0;
    fm.setTrail(pts.map(function (p) { return [p[1], p[2]]; }));
  };
  var RP = LF.Replay.prototype;
  RP.at = function (t) {
    var pts = this.d.points, lo = 0, hi = pts.length - 1;
    if (!pts.length) return null;
    if (t <= pts[0][0]) return pts[0];
    if (t >= pts[hi][0]) return pts[hi];
    while (hi - lo > 1) { var mid = (lo + hi) >> 1; if (pts[mid][0] <= t) lo = mid; else hi = mid; }
    var a = pts[lo], b = pts[hi], k = (t - a[0]) / Math.max(1, b[0] - a[0]);
    return [t, a[1] + (b[1] - a[1]) * k, a[2] + (b[2] - a[2]) * k, a[3], b[4] != null ? b[4] : a[4]];
  };
  RP.seek = function (t) {
    this.t = Math.max(this.t0, Math.min(this.t1, t));
    var p = this.at(this.t);
    if (p && this.onFrame) this.onFrame(p, this.t);
  };
  RP.play = function () {
    var self = this, last = performance.now();
    this.playing = true;
    var step = function (now) {
      if (!self.playing) return;
      self.seek(self.t + (now - last) * self.speed);
      last = now;
      if (self.t >= self.t1) { self.playing = false; return; }
      self.raf = requestAnimationFrame(step);
    };
    this.raf = requestAnimationFrame(step);
  };
  RP.pause = function () { this.playing = false; if (this.raf) cancelAnimationFrame(this.raf); };

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
