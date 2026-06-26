/*
 * UI v2 · Estado y capas de mapa COMPARTIDOS entre páginas (Live, Eventos…)
 * window.tpMapShared
 *  - get(key, fallback) / set(key, value): toggles persistidos en localStorage
 *    (tp_map_settings) → el estado se comparte entre páginas (ej. geocercas
 *    activas en Live se ven en Eventos).
 *  - renderGeofences(map, zonesUrl, visible): pinta/quita las geocercas en un
 *    mapa MapLibre (mismo formato que el endpoint de zonas de la v2).
 */
(function () {
  "use strict";

  var KEY = "tp_map_settings";
  var defaults = {
    mapId: null,
    cluster: false,
    devices: true,
    geofences: false,
    poi: false,
    names: false,
    tails: false,
    traffic: false,
  };

  function read() {
    try {
      return Object.assign({}, defaults, JSON.parse(localStorage.getItem(KEY) || "{}"));
    } catch (e) {
      return Object.assign({}, defaults);
    }
  }
  function write(s) {
    try {
      localStorage.setItem(KEY, JSON.stringify(s));
    } catch (e) {}
  }
  function get(k, fallback) {
    var s = read();
    if (k in s && s[k] !== undefined && s[k] !== null) return s[k];
    return fallback !== undefined ? fallback : defaults[k];
  }
  function set(k, v) {
    var s = read();
    s[k] = v;
    write(s);
  }

  function circlePolygon(lng, lat, radiusMeters, points) {
    points = points || 48;
    var coords = [];
    var earth = 6378137;
    var dLat = (radiusMeters / earth) * (180 / Math.PI);
    var dLng =
      (radiusMeters / (earth * Math.cos((Math.PI * lat) / 180))) *
      (180 / Math.PI);
    for (var i = 0; i <= points; i++) {
      var t = (2 * Math.PI * i) / points;
      coords.push([lng + dLng * Math.cos(t), lat + dLat * Math.sin(t)]);
    }
    return { type: "Polygon", coordinates: [coords] };
  }

  function zonesToFeatures(zones) {
    var features = [];
    (zones || []).forEach(function (z) {
      var color = z.polygon_color || "#1e88e5";
      var geometry = null;
      if (z.type === "polygon" && z.coordinates && z.coordinates.length >= 3) {
        var ring = z.coordinates.map(function (c) {
          return [
            parseFloat(c.x || c.lng || c.longitude),
            parseFloat(c.y || c.lat || c.latitude),
          ];
        });
        if (
          ring.length &&
          (ring[0][0] !== ring[ring.length - 1][0] ||
            ring[0][1] !== ring[ring.length - 1][1])
        ) {
          ring.push([ring[0][0], ring[0][1]]);
        }
        geometry = { type: "Polygon", coordinates: [ring] };
      } else if (z.type === "circle" && z.center && z.radius) {
        var cx = parseFloat(z.center.x || z.center.lng || z.center.longitude);
        var cy = parseFloat(z.center.y || z.center.lat || z.center.latitude);
        geometry = circlePolygon(cx, cy, parseFloat(z.radius));
      }
      if (!geometry) return;
      features.push({
        type: "Feature",
        geometry: geometry,
        properties: { id: z.id, name: z.name, color: color },
      });
    });
    return { type: "FeatureCollection", features: features };
  }

  var zonesCache = null;
  var zonesPromise = null;
  function loadZones(url) {
    if (!url) return Promise.resolve([]);
    if (zonesCache) return Promise.resolve(zonesCache);
    if (zonesPromise) return zonesPromise;
    zonesPromise = fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.ok ? r.json() : { items: [] };
      })
      .then(function (j) {
        zonesCache = (j && j.items) || [];
        return zonesCache;
      })
      .catch(function () {
        zonesCache = [];
        return zonesCache;
      });
    return zonesPromise;
  }

  function renderGeofences(map, zonesUrl, visible) {
    if (!map) return;
    var srcId = "tp-shared-zones-src";
    var fillId = "tp-shared-zones-fill";
    var lineId = "tp-shared-zones-line";
    function removeLayers() {
      if (map.getLayer(fillId)) map.removeLayer(fillId);
      if (map.getLayer(lineId)) map.removeLayer(lineId);
      if (map.getSource(srcId)) map.removeSource(srcId);
    }
    if (!visible) {
      try {
        removeLayers();
      } catch (e) {}
      return;
    }
    loadZones(zonesUrl).then(function (zones) {
      if (!map || !map.getStyle) return;
      var data = zonesToFeatures(zones);
      try {
        if (map.getSource(srcId)) {
          map.getSource(srcId).setData(data);
          return;
        }
        if (!map.isStyleLoaded()) {
          map.once("idle", function () {
            renderGeofences(map, zonesUrl, visible);
          });
          return;
        }
        map.addSource(srcId, { type: "geojson", data: data });
        map.addLayer({
          id: fillId,
          type: "fill",
          source: srcId,
          paint: { "fill-color": ["get", "color"], "fill-opacity": 0.15 },
        });
        map.addLayer({
          id: lineId,
          type: "line",
          source: srcId,
          paint: { "line-color": ["get", "color"], "line-width": 2 },
        });
      } catch (e) {}
    });
  }

  window.tpMapShared = {
    get: get,
    set: set,
    read: read,
    loadZones: loadZones,
    renderGeofences: renderGeofences,
  };
})();
