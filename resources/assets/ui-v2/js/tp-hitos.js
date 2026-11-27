/* =====================================================
 * TalentusPro · tp-hitos · Hitos kilométricos del Perú
 * Capa Leaflet compartida por TODAS las páginas con mapa
 * (Live, Historial, Eventos). Ver docs/implementacion_hitos_km.md.
 *
 *   var ctrl = tpHitos.create(map);   // una vez por mapa
 *   ctrl.setVisible(true|false);      // toggle de la capa
 *   tpHitos.nearest(lat, lng)         // → Promise<{ruta,km,distance}|null>
 *                                     //   (para "PE-1N · km 557" en popups)
 *
 * Reglas: zoom <9 apagado · 9–11 cada 10 km · ≥12 cada 1 km (la densidad
 * la decide el backend por ?zoom=). Recarga con debounce en moveend/zoomend.
 * Config JSON en #tp-hitos-config (urls). Dataset semestral → el endpoint
 * manda Cache-Control fuerte y el navegador cachea las respuestas.
 * ===================================================== */
(function () {
  "use strict";

  var CFG = null;
  function cfg() {
    if (CFG) return CFG;
    var el = document.getElementById("tp-hitos-config");
    if (!el) return null;
    try {
      CFG = JSON.parse(el.textContent);
    } catch (e) {
      CFG = null;
    }
    return CFG;
  }

  /* ---------- Hito más cercano (popups de vehículo/punto) ---------- */
  var nearestCache = {};
  function nearest(lat, lng) {
    var c = cfg();
    lat = parseFloat(lat);
    lng = parseFloat(lng);
    if (!c || !c.nearest || isNaN(lat) || isNaN(lng)) return Promise.resolve(null);
    var key = lat.toFixed(4) + "," + lng.toFixed(4);
    if (Object.prototype.hasOwnProperty.call(nearestCache, key)) return Promise.resolve(nearestCache[key]);
    return fetch(c.nearest + "?lat=" + lat + "&lng=" + lng, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        var v = j && j.ok ? { ruta: j.ruta, km: j.km, distance: j.distance } : null;
        nearestCache[key] = v;
        return v;
      })
      .catch(function () {
        return null;
      });
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /**
   * Texto para popups: nombre legible + código oficial (el código sigue en
   * los hitos físicos, comunicados SUTRAN y partes policiales).
   * "Panamericana Norte · km 557 (PE-1N)" — o solo el código si no hay nombre.
   */
  function refText(h) {
    if (!h) return null;
    var via = h.via && h.via !== h.ruta ? h.via : null;
    return via ? via + " · km " + h.km + " (" + h.ruta + ")" : h.ruta + " · km " + h.km;
  }

  /** Tooltip de dos niveles: nombre legible grande + código pequeño debajo. */
  function tipHtml(h) {
    var via = h.via && h.via !== h.ruta ? h.via : null;
    return (
      '<div class="tp-hito-tip"><b>' +
      esc((via || h.ruta) + " · km " + h.km) +
      "</b>" +
      (via ? "<span>" + esc(h.ruta) + "</span>" : "") +
      "</div>"
    );
  }

  /* ---------- Capa por mapa ---------- */
  function create(map) {
    var layer = L.layerGroup();
    var on = false;
    var timer = null;
    var seq = 0;

    function reload() {
      if (!on || !cfg()) return;
      if (map.getZoom() < 9) {
        layer.clearLayers();
        return;
      }
      var b = map.getBounds();
      var bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]
        .map(function (v) {
          return v.toFixed(4);
        })
        .join(",");
      var my = ++seq;
      fetch(cfg().url + "?bbox=" + bbox + "&zoom=" + map.getZoom(), {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (j) {
          if (my !== seq || !on) return; // respuesta obsoleta o toggle apagado
          layer.clearLayers();
          ((j && j.items) || []).forEach(function (h) {
            L.marker([h.lat, h.lon], {
              icon: L.divIcon({
                className: "tp-hito",
                html: '<div class="tp-hito-pin">' + h.km + "</div>",
                iconSize: [34, 18],
                iconAnchor: [17, 9],
              }),
              interactive: true,
            })
              .bindTooltip(tipHtml(h), { direction: "top" })
              .addTo(layer);
          });
        })
        .catch(function () {});
    }

    function sched() {
      clearTimeout(timer);
      timer = setTimeout(reload, 300);
    }
    map.on("moveend zoomend", sched);

    return {
      setVisible: function (v) {
        on = !!v;
        if (on) {
          if (!map.hasLayer(layer)) layer.addTo(map);
          reload();
        } else {
          map.removeLayer(layer);
          layer.clearLayers();
        }
      },
      isVisible: function () {
        return on;
      },
      refresh: reload,
    };
  }

  window.tpHitos = { create: create, nearest: nearest, refText: refText };
})();
