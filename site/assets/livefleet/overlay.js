/* =====================================================================
 *  Live Fleet on the site's existing Live Map (admin/map.php), Oct 2026.
 *
 *  admin/map.php calls LiveFleetOverlay(map) once its routes are drawn
 *  (the line is added by admin/live-install.php). It then:
 *    - recolours the map like Google Maps (LiveFleet.applyLook)
 *    - draws every running bus — white coach, number plate, speed — moving
 *      smoothly, refreshed every 2 s from /api/live-fleet.php
 *    - a click on a bus: where it is in Nepali, next stop, open in Live Fleet
 *  A staff member without Live Fleet access just sees nothing extra.
 * ===================================================================== */
(function (w) {
  'use strict';
  w.LiveFleetOverlay = function (map) {
    var LF = w.LiveFleet;
    if (!LF || !map || map._lfOverlay) return;
    map._lfOverlay = true;
    LF.applyLook(map, 'day');
    var fm = LF.FleetMap.attach(map, { onSelect: function (id) { open(id); } });
    var buses = [], popup = null, popId = null;

    var chip = document.createElement('a');
    chip.className = 'lf-ov-chip';
    chip.href = '/admin/live-fleet.php';
    chip.style.cssText = 'position:absolute;left:10px;top:10px;z-index:3;background:#12264E;color:#fff;border-radius:999px;padding:7px 13px;'
      + "font:700 13px/1.2 'Noto Sans Devanagari',Inter,system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.25)";
    chip.textContent = '🚌 Live Fleet…';
    map.getContainer().appendChild(chip);

    function byId(id) { for (var i = 0; i < buses.length; i++) if (buses[i].id === id) return buses[i]; return null; }
    function html(b) {
      var st = b.stop;
      return '<div style="font:13px/1.5 \'Noto Sans Devanagari\',Inter,system-ui,sans-serif;min-width:220px">'
        + LF.plate(b.number) + ' ' + LF.pill(b.status, true)
        + '<div style="margin-top:6px"><b>' + LF.esc(b.name) + '</b>' + (b.route ? ' · ' + LF.esc(b.route.label) : '') + '</div>'
        + '<div>' + LF.headline(b) + '</div>'
        + (b.place ? '<div>📍 ' + LF.esc(b.place.name) + (b.place.at ? '' : ' नजिक') + '</div>' : '')
        + (st && st.next ? '<div>🚏 ' + LF.esc(LF.nm(st.next_ne, st.next)) + ' · ' + LF.neKm(st.next_km) + ' · ' + LF.neDur(st.next_min) + '</div>' : '')
        + '<div style="margin-top:6px"><a href="/admin/live-fleet.php?bus=' + b.id + '">🗺 Open in Live Fleet →</a></div></div>';
    }
    function open(id) {
      var b = byId(id);
      if (!b || !b.pos) return;
      popId = id;
      if (popup) popup.remove();
      popup = new w.maplibregl.Popup({ offset: 26, closeButton: true, maxWidth: '320px' }).setLngLat([b.pos.lng, b.pos.lat]).setHTML(html(b)).addTo(map);
      popup.on('close', function () { popId = null; });
      fm.select(id);
    }

    LF.poll('/api/live-fleet.php', 2000, function (d) {
      buses = d.buses || [];
      fm.update(buses);
      chip.textContent = '🚌 Live Fleet · ' + d.counts.live + ' लाइभ' + (d.counts.weak ? ' · ' + d.counts.weak + ' कमजोर' : '') + ' →';
      var b = popId != null ? byId(popId) : null;
      if (popup && b && b.pos) popup.setLngLat([b.pos.lng, b.pos.lat]).setHTML(html(b));
    }, function (e) {
      if (e && (e.status === 401 || e.status === 403)) chip.remove();
    });
  };
})(window);
