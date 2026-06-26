/* =====================================================
   TalentusPro · Shared map providers
   - Mirrors legacy map_id catalog (config/maps.php)
   - Returns MapLibre style objects per provider id
   - Usage:
       var P = window.tpMapProviders.create(MAP_CFG);
       P.fallbackStyle();
       P.resolveStyle(mapId).then(function(style){ ... });
       P.applyMapStyle(map, mapId);
   ===================================================== */
(function () {
  "use strict";

  function create(MAP_CFG) {
    MAP_CFG = MAP_CFG || {};
    var k = MAP_CFG.keys || {};
    var locale = MAP_CFG.locale || "en";

    function rasterStyle(source, attribution, maxZoom) {
      return {
        version: 8,
        sources: {
          base: {
            type: "raster",
            tiles: source.tiles,
            tileSize: source.tileSize || 256,
            attribution: attribution,
            maxzoom: maxZoom || 19,
          },
        },
        layers: [
          {
            id: "base",
            type: "raster",
            source: "base",
            minzoom: 0,
            maxzoom: maxZoom || 19,
          },
        ],
      };
    }
    function googleDirect(lyrs) {
      return rasterStyle(
        {
          tiles: [
            "https://mt0.google.com/vt/lyrs=" +
              lyrs +
              "&hl=" +
              locale +
              "&x={x}&y={y}&z={z}&s=Ga",
            "https://mt1.google.com/vt/lyrs=" +
              lyrs +
              "&hl=" +
              locale +
              "&x={x}&y={y}&z={z}&s=Ga",
          ],
        },
        "© Google",
        21,
      );
    }
    function hereStyle(style) {
      if (!k.here_api_key) return null;
      var pv = k.here_political_view
        ? "&pv=" + encodeURIComponent(k.here_political_view)
        : "";
      return rasterStyle(
        {
          tiles: [
            "https://maps.hereapi.com/v3/base/mc/{z}/{x}/{y}/png?apiKey=" +
              encodeURIComponent(k.here_api_key) +
              "&style=" +
              style +
              "&size=512" +
              pv,
          ],
          tileSize: 512,
        },
        "© HERE",
        20,
      );
    }
    function azureStyle(tilesetId) {
      if (!k.azure_maps_key) return null;
      return rasterStyle(
        {
          tiles: [
            "https://atlas.microsoft.com/map/tile?api-version=2024-04-01&tilesetId=" +
              tilesetId +
              "&zoom={z}&x={x}&y={y}&subscription-key=" +
              encodeURIComponent(k.azure_maps_key),
          ],
        },
        "© Microsoft Azure Maps",
        21,
      );
    }
    function bingDeferred(imagerySet) {
      return { __bing: imagerySet, __key: k.bing_maps_key };
    }

    var PROVIDERS = {
      2: function () {
        return rasterStyle(
          {
            tiles: [
              "https://a.tile.openstreetmap.org/{z}/{x}/{y}.png",
              "https://b.tile.openstreetmap.org/{z}/{x}/{y}.png",
              "https://c.tile.openstreetmap.org/{z}/{x}/{y}.png",
            ],
          },
          "© OpenStreetMap contributors",
          19,
        );
      },
      1: function () {
        return googleDirect("m");
      },
      51: function () {
        return googleDirect("m");
      },
      3: function () {
        return googleDirect("y");
      },
      53: function () {
        return googleDirect("y");
      },
      4: function () {
        return googleDirect("s");
      },
      54: function () {
        return googleDirect("s");
      },
      5: function () {
        return googleDirect("p");
      },
      55: function () {
        return googleDirect("p");
      },
      56: function () {
        return googleDirect("y,traffic");
      },
      57: function () {
        return googleDirect("m,traffic");
      },
      70: function () {
        return rasterStyle(
          {
            tiles: [
              "https://a.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}@2x.png",
              "https://b.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}@2x.png",
              "https://c.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}@2x.png",
            ],
          },
          "© CARTO © OpenStreetMap contributors",
          19,
        );
      },
      72: function () {
        return rasterStyle(
          {
            tiles: [
              "https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png",
              "https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png",
              "https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png",
            ],
          },
          "© CARTO © OpenStreetMap contributors",
          19,
        );
      },
      14: function () {
        if (!k.mapbox_access_token) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/512/{z}/{x}/{y}@2x?access_token=" +
                encodeURIComponent(k.mapbox_access_token),
            ],
            tileSize: 512,
          },
          "© Mapbox © OpenStreetMap",
          22,
        );
      },
      15: function () {
        if (!k.mapbox_access_token) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.mapbox.com/styles/v1/mapbox/satellite-v9/tiles/512/{z}/{x}/{y}@2x?access_token=" +
                encodeURIComponent(k.mapbox_access_token),
            ],
            tileSize: 512,
          },
          "© Mapbox",
          22,
        );
      },
      16: function () {
        if (!k.mapbox_access_token) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/512/{z}/{x}/{y}@2x?access_token=" +
                encodeURIComponent(k.mapbox_access_token),
            ],
            tileSize: 512,
          },
          "© Mapbox © OpenStreetMap",
          22,
        );
      },
      17: function () {
        if (!k.maptiler_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key=" +
                encodeURIComponent(k.maptiler_key),
            ],
          },
          "© MapTiler © OpenStreetMap",
          20,
        );
      },
      18: function () {
        if (!k.maptiler_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=" +
                encodeURIComponent(k.maptiler_key),
            ],
          },
          "© MapTiler © OpenStreetMap",
          20,
        );
      },
      19: function () {
        if (!k.maptiler_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key=" +
                encodeURIComponent(k.maptiler_key),
            ],
          },
          "© MapTiler © OpenStreetMap",
          20,
        );
      },
      10: function () {
        return hereStyle("explore.day");
      },
      11: function () {
        return hereStyle("satellite.day");
      },
      12: function () {
        return hereStyle("explore.satellite.day");
      },
      26: function () {
        if (!k.tomtom_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?key=" +
                encodeURIComponent(k.tomtom_key),
            ],
          },
          "© TomTom",
          22,
        );
      },
      27: function () {
        if (!k.tomtom_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.tomtom.com/map/1/tile/sat/main/{z}/{x}/{y}.jpg?key=" +
                encodeURIComponent(k.tomtom_key),
            ],
          },
          "© TomTom",
          19,
        );
      },
      28: function () {
        return azureStyle("microsoft.base.road");
      },
      29: function () {
        return azureStyle("microsoft.imagery");
      },
      30: function () {
        return azureStyle("microsoft.imagery");
      },
      7: function () {
        return bingDeferred("Road");
      },
      8: function () {
        return bingDeferred("Aerial");
      },
      9: function () {
        return bingDeferred("AerialWithLabels");
      },
      21: function () {
        if (!MAP_CFG.openmaptilesUrl) return null;
        return rasterStyle(
          {
            tiles: [
              MAP_CFG.openmaptilesUrl.replace(/\/$/, "") +
                "/styles/osm-bright/{z}/{x}/{y}.png",
            ],
          },
          "OpenMapTiles",
          20,
        );
      },
    };

    function getMode() {
      return document.documentElement.getAttribute("data-theme") === "dark"
        ? "dark"
        : "light";
    }
    function fallbackStyle() {
      return getMode() === "dark" ? PROVIDERS[72]() : PROVIDERS[2]();
    }

    var bingCache = {};
    function resolveStyle(mapId) {
      var fn = PROVIDERS[mapId];
      var raw = fn ? fn() : null;
      if (!raw) return Promise.resolve(fallbackStyle());
      if (raw.__bing) {
        var key = raw.__bing + ":" + (raw.__key || "");
        if (!raw.__key) return Promise.resolve(fallbackStyle());
        if (bingCache[key]) return Promise.resolve(bingCache[key]);
        var url =
          "https://dev.virtualearth.net/REST/V1/Imagery/Metadata/" +
          raw.__bing +
          "?output=json&include=ImageryProviders&key=" +
          encodeURIComponent(raw.__key);
        return fetch(url)
          .then(function (r) {
            return r.json();
          })
          .then(function (j) {
            try {
              var resource = j.resourceSets[0].resources[0];
              var template = resource.imageUrl
                .replace("{subdomain}", resource.imageUrlSubdomains[0])
                .replace("{culture}", "en-US");
              var style = {
                version: 8,
                sources: {
                  base: {
                    type: "raster",
                    scheme: "xyz",
                    tiles: [template],
                    tileSize: 256,
                    attribution: "© Microsoft Bing Maps",
                  },
                },
                layers: [{ id: "base", type: "raster", source: "base" }],
              };
              style._bingTemplate = template;
              bingCache[key] = style;
              return style;
            } catch (e) {
              return fallbackStyle();
            }
          })
          .catch(function () {
            return fallbackStyle();
          });
      }
      return Promise.resolve(raw);
    }

    /**
     * Apply a provider id to a MapLibre map, preserving custom sources/layers
     * after style swap (caller supplies a callback to re-add overlays).
     */
    function applyMapStyle(map, mapId, onAfter) {
      if (!map) return;
      resolveStyle(mapId).then(function (style) {
        map.setStyle(style);
        map.once("styledata", function () {
          if (typeof onAfter === "function") onAfter();
        });
      });
    }

    /** Filter MAP_CFG.available to entries that this client supports. */
    function availableSupported() {
      return (MAP_CFG.available || []).filter(function (m) {
        var fn = PROVIDERS[m.id];
        if (!fn) return false;
        var raw;
        try {
          raw = fn();
        } catch (e) {
          return false;
        }
        return raw != null;
      });
    }

    return {
      PROVIDERS: PROVIDERS,
      fallbackStyle: fallbackStyle,
      resolveStyle: resolveStyle,
      applyMapStyle: applyMapStyle,
      availableSupported: availableSupported,
      MAP_CFG: MAP_CFG,
    };
  }

  window.tpMapProviders = { create: create };
})();
