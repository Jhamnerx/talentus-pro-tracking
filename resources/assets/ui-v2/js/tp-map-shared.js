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

  // Estado compartido con el LEGACY: vive en el backend (user->map_controls).
  // init() lo siembra desde el mapConfig inyectado (buildMapConfig) y set()
  // persiste vía el endpoint legacy changeMapSettings (keys m_*).
  var S = { toggles: {}, urls: {} };
  var KEYMAP = {
    geofences: "m_geofences",
    poi: "m_poi",
    routes: "m_routes",
    devices: "m_objects",
    names: "m_show_names",
    tails: "m_show_tails",
    traffic: "m_traffic",
    cluster: "m_objects_cluster",
  };

  function init(cfg) {
    cfg = cfg || {};
    if (cfg.toggles) S.toggles = Object.assign({}, cfg.toggles);
    if (cfg.urls) S.urls = cfg.urls || {};
  }
  function get(k, fallback) {
    if (S.toggles && k in S.toggles) return !!S.toggles[k];
    return fallback !== undefined ? fallback : false;
  }
  function set(k, v) {
    v = !!v;
    S.toggles[k] = v;
    var param = KEYMAP[k];
    var url = S.urls && S.urls.changeMapSettings;
    if (param && url) {
      var full =
        url +
        (url.indexOf("?") === -1 ? "?" : "&") +
        "param=" +
        encodeURIComponent(param) +
        "&value=" +
        (v ? "true" : "false");
      fetch(full, {
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      }).catch(function () {});
    }
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

  var jsonCache = {}; // url → Promise<items[]>
  function loadItems(url) {
    if (!url) return Promise.resolve([]);
    if (jsonCache[url]) return jsonCache[url];
    jsonCache[url] = fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.ok ? r.json() : { items: [] };
      })
      .then(function (j) {
        return (j && j.items) || [];
      })
      .catch(function () {
        return [];
      });
    return jsonCache[url];
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
    loadItems(zonesUrl).then(function (zones) {
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

  function renderPois(map, url, visible) {
    if (!map) return;
    var srcId = "tp-shared-pois-src";
    var cId = "tp-shared-pois-circle";
    function rm() {
      if (map.getLayer(cId)) map.removeLayer(cId);
      if (map.getSource(srcId)) map.removeSource(srcId);
    }
    if (!visible) {
      try {
        rm();
      } catch (e) {}
      return;
    }
    loadItems(url).then(function (items) {
      if (!map || !map.getStyle) return;
      var features = (items || [])
        .map(function (p) {
          return {
            type: "Feature",
            geometry: {
              type: "Point",
              coordinates: [parseFloat(p.lng), parseFloat(p.lat)],
            },
            properties: { id: p.id, name: p.name || "" },
          };
        })
        .filter(function (f) {
          return (
            !isNaN(f.geometry.coordinates[0]) &&
            !isNaN(f.geometry.coordinates[1])
          );
        });
      var data = { type: "FeatureCollection", features: features };
      try {
        if (map.getSource(srcId)) {
          map.getSource(srcId).setData(data);
          return;
        }
        if (!map.isStyleLoaded()) {
          map.once("idle", function () {
            renderPois(map, url, visible);
          });
          return;
        }
        map.addSource(srcId, { type: "geojson", data: data });
        map.addLayer({
          id: cId,
          type: "circle",
          source: srcId,
          paint: {
            "circle-radius": 6,
            "circle-color": "#7c3aed",
            "circle-stroke-color": "#ffffff",
            "circle-stroke-width": 2,
          },
        });
      } catch (e) {}
    });
  }

  function renderRoutes(map, url, visible) {
    if (!map) return;
    var srcId = "tp-shared-routes-src";
    var lId = "tp-shared-routes-line";
    function rm() {
      if (map.getLayer(lId)) map.removeLayer(lId);
      if (map.getSource(srcId)) map.removeSource(srcId);
    }
    if (!visible) {
      try {
        rm();
      } catch (e) {}
      return;
    }
    loadItems(url).then(function (items) {
      if (!map || !map.getStyle) return;
      var features = (items || [])
        .filter(function (r) {
          return r.coordinates && r.coordinates.length >= 2;
        })
        .map(function (r) {
          return {
            type: "Feature",
            geometry: { type: "LineString", coordinates: r.coordinates },
            properties: { id: r.id, name: r.name || "", color: r.color || "#7c3aed" },
          };
        });
      var data = { type: "FeatureCollection", features: features };
      try {
        if (map.getSource(srcId)) {
          map.getSource(srcId).setData(data);
          return;
        }
        if (!map.isStyleLoaded()) {
          map.once("idle", function () {
            renderRoutes(map, url, visible);
          });
          return;
        }
        map.addSource(srcId, { type: "geojson", data: data });
        map.addLayer({
          id: lId,
          type: "line",
          source: srcId,
          layout: { "line-join": "round", "line-cap": "round" },
          paint: { "line-color": ["get", "color"], "line-width": 3 },
        });
      } catch (e) {}
    });
  }

  window.tpMapShared = {
    init: init,
    get: get,
    set: set,
    loadItems: loadItems,
    renderGeofences: renderGeofences,
    renderPois: renderPois,
    renderRoutes: renderRoutes,
  };
})();
