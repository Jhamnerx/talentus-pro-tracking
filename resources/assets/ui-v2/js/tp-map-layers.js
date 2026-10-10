/* =====================================================================
 * tp-map-layers.js · Geocercas, POIs y rutas sobre VARIOS mapas
 * ---------------------------------------------------------------------
 * live-zones.js / live-pois.js / live-routes.js dibujan sobre UN mapa:
 * el de Live, que piden con window.tpLive.map(). Monitoreo de grupo tiene
 * hasta 32 mapas a la vez, asi que no sirven tal cual.
 *
 * Aqui los datos se piden UNA vez y se comparten; las capas de Leaflet no,
 * porque una capa solo puede colgar de un mapa: cada celda construye las
 * suyas a partir de los mismos datos.
 *
 * Tambien resuelve el limite de velocidad vigente en un punto, que es lo
 * que necesita el color del marcador por velocidad dentro de una geocerca.
 * La geometria esta repetida de live-zones.js a proposito: aquella es un
 * modulo cerrado sobre el mapa de Live y extraerla habria obligado a
 * reescribir una pantalla que ya funciona.
 *
 * Uso:
 *   tpMapLayers.cargar({zones: url, pois: url, routes: url})  -> Promise
 *   var capas = tpMapLayers.dibujar(map, {zones:true, pois:false, routes:true})
 *   capas.quitar()
 *   tpMapLayers.limitFor(lat, lng, vehicleTypeId, vehicleStateId)
 * ===================================================================== */
(function () {
  "use strict";

  var DATOS = { zones: [], pois: [], routes: [] };
  var CARGADO = false;
  var ENVUELO = null;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function normCoords(coords) {
    return (coords || [])
      .map(function (c) {
        if (Array.isArray(c)) return { lat: +c[0], lng: +c[1] };
        if (c && c.lat != null) return { lat: +c.lat, lng: +c.lng };
        if (c && c.y != null) return { lat: +c.y, lng: +c.x };
        return null;
      })
      .filter(Boolean);
  }

  function pide(url) {
    if (!url) return Promise.resolve([]);
    return fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (j) { return (j && (j.items || j.data)) || []; })
      .catch(function () { return []; });
  }

  /**
   * Pide los tres feeds una sola vez. Las llamadas que lleguen mientras
   * esta en vuelo se enganchan a la misma promesa.
   */
  function cargar(urls) {
    if (CARGADO) return Promise.resolve(DATOS);
    if (ENVUELO) return ENVUELO;

    ENVUELO = Promise.all([
      pide(urls && urls.zones),
      pide(urls && urls.pois),
      pide(urls && urls.routes),
    ]).then(function (res) {
      DATOS.zones = res[0] || [];
      DATOS.pois = res[1] || [];
      DATOS.routes = res[2] || [];
      CARGADO = true;
      ENVUELO = null;
      return DATOS;
    });

    return ENVUELO;
  }

  /* ---------------- Capas de Leaflet para UN mapa ---------------- */

  function capaDeZona(g) {
    var estilo = {
      color: g.polygon_color || "#1e88e5",
      weight: 2,
      opacity: 0.9,
      fillColor: g.polygon_color || "#1e88e5",
      fillOpacity: 0.12,
      interactive: false,
    };

    if (g.type === "circle") {
      var c = g.center;
      if (!c || c.lat == null) return null;
      return L.circle([+c.lat, +c.lng], Object.assign({ radius: Number(g.radius) || 0 }, estilo));
    }

    var pts = normCoords(g.coordinates);
    if (pts.length < 2) return null;
    var ll = pts.map(function (p) { return [p.lat, p.lng]; });

    // El corredor es una linea con anchura; en un mini-mapa basta la linea.
    if (g.type === "line") {
      return L.polyline(ll, { color: estilo.color, weight: 3, opacity: 0.9, interactive: false });
    }

    return L.polygon(ll, estilo);
  }

  function capaDePoi(p) {
    var lat = p.lat != null ? +p.lat : null;
    var lng = p.lng != null ? +p.lng : null;
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) return null;

    return L.marker([lat, lng], {
      interactive: false,
      keyboard: false,
      icon: L.divIcon({
        className: "tp-mon-poi",
        html: '<span style="background:' + esc(p.color || "#8b5cf6") + '"></span>',
        iconSize: [10, 10],
        iconAnchor: [5, 5],
      }),
    });
  }

  function capaDeRuta(r) {
    var pts = normCoords(r.coordinates);
    if (pts.length < 2) return null;
    return L.polyline(
      pts.map(function (p) { return [p.lat, p.lng]; }),
      { color: r.color || "#2563eb", weight: 3, opacity: 0.85, dashArray: "6 5", interactive: false }
    );
  }

  /**
   * Dibuja las capas pedidas en el mapa y devuelve un mando para quitarlas.
   * Las capas se crean nuevas por mapa: una capa de Leaflet no se puede
   * compartir entre dos mapas.
   */
  function dibujar(map, opts) {
    if (!map || !window.L) return { quitar: function () {} };
    opts = opts || {};

    var grupo = L.layerGroup();

    if (opts.zones) {
      DATOS.zones.forEach(function (g) {
        if (g.active === false) return;
        var c = capaDeZona(g);
        if (c) grupo.addLayer(c);
      });
    }
    if (opts.pois) {
      DATOS.pois.forEach(function (p) {
        if (p.active === false) return;
        var c = capaDePoi(p);
        if (c) grupo.addLayer(c);
      });
    }
    if (opts.routes) {
      DATOS.routes.forEach(function (r) {
        if (r.active === false) return;
        var c = capaDeRuta(r);
        if (c) grupo.addLayer(c);
      });
    }

    grupo.addTo(map);

    return {
      quitar: function () {
        try { map.removeLayer(grupo); } catch (e) {}
      },
    };
  }

  /* ---------------- Limite de velocidad en un punto ----------------
     Misma regla que live-zones.js: de las geocercas que contienen el punto
     gana la de MENOR area, y dentro de ella manda el limite del tipo y
     estado del vehiculo antes que el de la geocerca. */

  var TIERRA_M = 111320; // metros por grado de latitud

  function metrosEntre(aLat, aLng, bLat, bLng) {
    var dLat = (bLat - aLat) * TIERRA_M;
    var dLng = (bLng - aLng) * TIERRA_M * Math.cos((aLat * Math.PI) / 180);
    return Math.sqrt(dLat * dLat + dLng * dLng);
  }

  function metrosASegmento(pLat, pLng, aLat, aLng, bLat, bLng) {
    var cos = Math.cos((aLat * Math.PI) / 180);
    var px = pLng * cos, py = pLat;
    var ax = aLng * cos, ay = aLat;
    var bx = bLng * cos, by = bLat;

    var dx = bx - ax, dy = by - ay;
    var largo = dx * dx + dy * dy;

    var t = largo ? ((px - ax) * dx + (py - ay) * dy) / largo : 0;
    t = Math.max(0, Math.min(1, t));

    var cx = ax + t * dx, cy = ay + t * dy;

    return Math.sqrt((px - cx) * (px - cx) + (py - cy) * (py - cy)) * TIERRA_M;
  }

  function dentroDelPoligono(pLat, pLng, pts) {
    var dentro = false;
    for (var i = 0, j = pts.length - 1; i < pts.length; j = i++) {
      var yi = pts[i].lat, xi = pts[i].lng;
      var yj = pts[j].lat, xj = pts[j].lng;
      if ((yi > pLat) !== (yj > pLat) &&
          pLng < ((xj - xi) * (pLat - yi)) / (yj - yi) + xi) {
        dentro = !dentro;
      }
    }
    return dentro;
  }

  function areaAprox(g) {
    if (g.type === "circle") {
      var r = Number(g.radius) || 0;
      return Math.PI * r * r;
    }

    var pts = normCoords(g.coordinates);

    if (g.type === "line") {
      var largo = 0;
      for (var i = 1; i < pts.length; i++) {
        largo += metrosEntre(pts[i - 1].lat, pts[i - 1].lng, pts[i].lat, pts[i].lng);
      }
      return largo * 2 * (Number(g.radius) || 0);
    }

    if (pts.length < 3) return Infinity;

    var cos = Math.cos((pts[0].lat * Math.PI) / 180);
    var suma = 0;
    for (var k = 0, l = pts.length - 1; k < pts.length; l = k++) {
      suma += (pts[l].lng * cos) * pts[k].lat - (pts[k].lng * cos) * pts[l].lat;
    }
    return Math.abs(suma / 2) * TIERRA_M * TIERRA_M;
  }

  function contieneElPunto(g, lat, lng) {
    if (g.type === "circle") {
      var c = g.center;
      if (!c || c.lat == null) return false;
      return metrosEntre(+c.lat, +c.lng, lat, lng) <= (Number(g.radius) || 0);
    }

    var pts = normCoords(g.coordinates);
    if (pts.length < 2) return false;

    if (g.type === "line") {
      var ancho = Number(g.radius) || 0;
      for (var i = 1; i < pts.length; i++) {
        if (metrosASegmento(lat, lng, pts[i - 1].lat, pts[i - 1].lng, pts[i].lat, pts[i].lng) <= ancho) {
          return true;
        }
      }
      return false;
    }

    return pts.length >= 3 && dentroDelPoligono(lat, lng, pts);
  }

  function limiteEnPunto(lat, lng, tipoId, estadoId) {
    if (lat == null || lng == null || !DATOS.zones.length) return null;

    lat = +lat;
    lng = +lng;
    if (isNaN(lat) || isNaN(lng)) return null;

    var mejor = null;

    DATOS.zones.forEach(function (g) {
      if (!contieneElPunto(g, lat, lng)) return;

      var limite = null;
      var vsl = g.vehicle_speed_limits || {};

      if (tipoId && vsl[tipoId]) {
        // 0 es la clave del limite por defecto del tipo (estado nulo)
        var porTipo = vsl[tipoId];
        limite = (estadoId && porTipo[estadoId] != null) ? porTipo[estadoId] : porTipo[0];
      }

      if (limite == null) limite = g.speed_limit;
      if (limite == null) return;

      var area = areaAprox(g);
      if (!mejor || area < mejor.area) {
        mejor = { limit: +limite, zoneId: g.id, zoneName: g.name, area: area };
      }
    });

    if (!mejor) return null;

    return { limit: mejor.limit, zoneId: mejor.zoneId, zoneName: mejor.zoneName };
  }

  window.tpMapLayers = {
    cargar: cargar,
    dibujar: dibujar,
    limitFor: limiteEnPunto,
    datos: function () { return DATOS; },
    cargado: function () { return CARGADO; },
  };
})();
