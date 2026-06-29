/* ============================================================
 * UI v2 · Catálogo de capas base Leaflet (todos los proveedores)
 * ------------------------------------------------------------
 * Espeja resources/assets/js/model/MapTiles.js (config/maps.php): OSM, Here,
 * Mapbox, MapTiler, OpenMapTiles, TomTom, Azure, Google (tiles ≠), Carto,
 * LocationIQ. Construye solo las capas DISPONIBLES para el usuario (cfg.available)
 * con sus API keys (cfg.keys). Compartido por live.js y (a futuro) zones.js.
 *
 *   window.tpBasemaps.build(cfg)  -> { "<id>": { name, layer } }
 *   window.tpBasemaps.defaultId(basemaps, cfg) -> "<id>"
 * ============================================================ */
(function () {
  "use strict";
  if (!window.L) return;

  function registry(KEYS, LOCALE, LANG_TAG, OPEN_MAPTILES_URL) {
    return {
      2: {
        name: "OpenStreetMap",
        factory: function () {
          return L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap",
          });
        },
      },
      10: {
        name: "Here Normal",
        factory: function () {
          if (!KEYS.here_api_key) return false;
          var url =
            "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
          if (KEYS.here_political_view) url += "&pview=" + KEYS.here_political_view;
          return L.tileLayer(url, {
            resource: "base", projection: "mc", apiKey: KEYS.here_api_key,
            style: "explore.day", size: "256", maxZoom: 18, lang: LANG_TAG,
            format: "png8", attribution: "Map &copy; HERE",
          });
        },
      },
      11: {
        name: "Here Satellite",
        factory: function () {
          if (!KEYS.here_api_key) return false;
          var url =
            "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
          if (KEYS.here_political_view) url += "&pview=" + KEYS.here_political_view;
          return L.tileLayer(url, {
            resource: "base", projection: "mc", apiKey: KEYS.here_api_key,
            style: "satellite.day", size: "256", maxZoom: 18, lang: LANG_TAG,
            format: "png8", attribution: "Map &copy; HERE",
          });
        },
      },
      12: {
        name: "Here Hybrid",
        factory: function () {
          if (!KEYS.here_api_key) return false;
          var url =
            "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
          if (KEYS.here_political_view) url += "&pview=" + KEYS.here_political_view;
          return L.tileLayer(url, {
            resource: "base", projection: "mc", apiKey: KEYS.here_api_key,
            style: "explore.satellite.day", size: "256", maxZoom: 18, lang: LANG_TAG,
            format: "png8", attribution: "Map &copy; HERE",
          });
        },
      },
      14: {
        name: "MapBox Normal",
        factory: function () {
          if (!KEYS.mapbox_access_token) return false;
          return L.tileLayer(
            "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
            { id: "streets-v11", accessToken: KEYS.mapbox_access_token, tileSize: 512, zoomOffset: -1, maxZoom: 18, attribution: "© Mapbox" },
          );
        },
      },
      15: {
        name: "MapBox Satellite",
        factory: function () {
          if (!KEYS.mapbox_access_token) return false;
          return L.tileLayer(
            "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
            { id: "satellite-v9", accessToken: KEYS.mapbox_access_token, tileSize: 512, zoomOffset: -1, maxZoom: 18, attribution: "© Mapbox" },
          );
        },
      },
      16: {
        name: "MapBox Hybrid",
        factory: function () {
          if (!KEYS.mapbox_access_token) return false;
          return L.tileLayer(
            "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
            { id: "satellite-streets-v11", accessToken: KEYS.mapbox_access_token, tileSize: 512, zoomOffset: -1, maxZoom: 18, attribution: "© Mapbox" },
          );
        },
      },
      17: {
        name: "MapTiler Basic",
        factory: function () {
          if (!KEYS.maptiler_key) return false;
          return L.tileLayer("https://api.maptiler.com/maps/basic/{z}/{x}/{y}.png?key=" + KEYS.maptiler_key,
            { tileSize: 512, zoomOffset: -1, minZoom: 1, maxZoom: 20, crossOrigin: true, attribution: "© MapTiler © OSM" });
        },
      },
      18: {
        name: "MapTiler Streets",
        factory: function () {
          if (!KEYS.maptiler_key) return false;
          return L.tileLayer("https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=" + KEYS.maptiler_key,
            { tileSize: 512, zoomOffset: -1, minZoom: 1, maxZoom: 20, crossOrigin: true, attribution: "© MapTiler © OSM" });
        },
      },
      19: {
        name: "MapTiler Satellite",
        factory: function () {
          if (!KEYS.maptiler_key) return false;
          return L.tileLayer("https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key=" + KEYS.maptiler_key,
            { tileSize: 512, zoomOffset: -1, minZoom: 1, maxZoom: 20, crossOrigin: true, attribution: "© MapTiler © OSM" });
        },
      },
      21: {
        name: "OpenMapTiles OSM",
        factory: function () {
          if (!OPEN_MAPTILES_URL) return false;
          return L.tileLayer(OPEN_MAPTILES_URL + "/styles/osm-bright/{z}/{x}/{y}.png", { minZoom: 1, maxZoom: 20, crossOrigin: true });
        },
      },
      26: {
        name: "TomTom Basic",
        factory: function () {
          if (!KEYS.tomtom_key) return false;
          return L.tileLayer("https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?key=" + KEYS.tomtom_key,
            { tileSize: 256, minZoom: 1, maxZoom: 20, attribution: "© TomTom" });
        },
      },
      27: {
        name: "TomTom Satellite",
        factory: function () {
          if (!KEYS.tomtom_key) return false;
          return L.tileLayer("https://api.tomtom.com/map/1/tile/sat/main/{z}/{x}/{y}.jpg?key=" + KEYS.tomtom_key,
            { tileSize: 256, minZoom: 1, maxZoom: 19, attribution: "© TomTom" });
        },
      },
      28: {
        name: "Azure Normal",
        factory: function () {
          if (!KEYS.azure_maps_key) return false;
          return L.tileLayer(
            "https://atlas.microsoft.com/map/tile?subscription-key=" + KEYS.azure_maps_key +
              "&api-version=2.0&tilesetId=microsoft.base.road&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
              String(LOCALE).replace("_", "-") + "&view=Auto",
            { tileSize: 256, minZoom: 1, maxZoom: 21, attribution: "© Microsoft, TomTom" });
        },
      },
      29: {
        name: "Azure Hybrid",
        factory: function () {
          if (!KEYS.azure_maps_key) return false;
          return L.tileLayer(
            "https://atlas.microsoft.com/map/tile?subscription-key=" + KEYS.azure_maps_key +
              "&api-version=2.0&tilesetId=microsoft.base.hybrid.road&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
              String(LOCALE).replace("_", "-") + "&view=Auto",
            { tileSize: 256, minZoom: 1, maxZoom: 19, attribution: "© Microsoft, TomTom" });
        },
      },
      30: {
        name: "Azure Satellite",
        factory: function () {
          if (!KEYS.azure_maps_key) return false;
          return L.tileLayer(
            "https://atlas.microsoft.com/map/tile?subscription-key=" + KEYS.azure_maps_key +
              "&api-version=2.0&tilesetId=microsoft.imagery&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
              String(LOCALE).replace("_", "-") + "&view=Auto",
            { tileSize: 256, minZoom: 1, maxZoom: 19, attribution: "© Microsoft, TomTom" });
        },
      },
      51: { name: "Google Normal", factory: g("m", LANG_TAG) },
      53: { name: "Google Híbrido", factory: g("y", LANG_TAG) },
      54: { name: "Google Satélite", factory: g("s", LANG_TAG) },
      55: { name: "Google Terreno", factory: g("p", LANG_TAG) },
      56: { name: "Google Tráfico Híbrido", factory: g("y,traffic", LANG_TAG) },
      57: { name: "Google Tráfico", factory: g("m,traffic", LANG_TAG) },
      70: {
        name: "Carto",
        factory: function () {
          return L.tileLayer("https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}@2x.png",
            { maxZoom: 18, minZoom: 2, subdomains: "abcd", attribution: "© CARTO © OSM" });
        },
      },
      71: {
        name: "LocationIQ Streets",
        factory: function () {
          return L.tileLayer("https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key=pk.0f147952a41c555a5b70614039fd148b",
            { maxZoom: 18, minZoom: 2, subdomains: ["a", "b", "c"], attribution: "© LocationIQ © OSM" });
        },
      },
      72: {
        name: "Carto Dark",
        factory: function () {
          return L.tileLayer("https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png",
            { maxZoom: 18, minZoom: 2, subdomains: "abcd", attribution: "© CARTO © OSM" });
        },
      },
    };
    function g(lyrs, lang) {
      return function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=" + lyrs + "&hl=" + lang + "&x={x}&y={y}&z={z}&s=Ga",
          { maxZoom: 21, minZoom: 0, subdomains: ["0", "1", "2", "3"], attribution: "© Google Maps" },
        );
      };
    }
  }

  window.tpBasemaps = {
    build: function (cfg) {
      cfg = cfg || {};
      var KEYS = cfg.keys || {};
      var LOCALE = cfg.locale || "en";
      var LANG_TAG = String(LOCALE).split(/[_-]/)[0] || "en";
      var OPEN_MAPTILES_URL = cfg.openmaptilesUrl || "";
      var REG = registry(KEYS, LOCALE, LANG_TAG, OPEN_MAPTILES_URL);
      var out = {};
      var available = Array.isArray(cfg.available) ? cfg.available : [];
      available.forEach(function (m) {
        var id = parseInt(m.id, 10);
        var def = REG[id];
        if (!def) return;
        var layer = null;
        try {
          layer = def.factory();
        } catch (e) {
          layer = null;
        }
        if (!layer) return;
        out[String(id)] = { name: m.name || def.name, layer: layer };
      });
      // Respaldos sin key si el servidor no entregó disponibles.
      if (!Object.keys(out).length) {
        ["2", "51", "70", "72"].forEach(function (id) {
          var def = REG[parseInt(id, 10)];
          if (!def) return;
          var layer = def.factory();
          if (layer) out[id] = { name: def.name, layer: layer };
        });
      }
      return out;
    },
    defaultId: function (basemaps, cfg) {
      cfg = cfg || {};
      if (cfg.mapId && basemaps[String(cfg.mapId)]) return String(cfg.mapId);
      var dark = document.documentElement.getAttribute("data-theme") === "dark";
      if (dark && basemaps["72"]) return "72";
      if (basemaps["2"]) return "2";
      var keys = Object.keys(basemaps);
      return keys.length ? keys[0] : null;
    },
  };
})();
