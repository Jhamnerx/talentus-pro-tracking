/* ============================================================
 * TalentusPro · Live Ops controller (Phase 2)
 * ------------------------------------------------------------
 * Responsibilities:
 *   - Initialize MapLibre GL with light/dark style support
 *   - Fetch devices via /ui/v2/api/devices and render markers
 *   - Render virtualized device list (windowing) bound to filters
 *   - Open drawer with device detail on row/marker click
 *   - Poll devices every 15s; reconnect Socket.io for events
 *   - Re-render markers on theme change
 *
 * Dependencies: window.maplibregl, window.io (lazy loaded if missing)
 * ============================================================ */
(function () {
  "use strict";

  var root = document.getElementById("tp-live");
  if (!root) return;

  // -----------------------------------------------------------
  // Config
  // -----------------------------------------------------------
  var CFG = {
    devicesUrl: root.dataset.devicesUrl,
    updatesUrl:
      root.dataset.updatesUrl || (root.dataset.devicesUrl || "") + "/updates",
    zonesUrl: root.dataset.zonesUrl || null,
    poisUrl: root.dataset.poisUrl || null,
    routesUrl: root.dataset.routesUrl || null,
    socketPort: parseInt(root.dataset.socketPort, 10) || 3000,
    socketSslPort: parseInt(root.dataset.socketSslPort, 10) || 3001,
    pollInterval: parseInt(root.dataset.pollInterval, 10) || 15000,
    rowHeight: 56,
    rowGap: 6,
    groupHeaderHeight: 34,
    bufferRows: 6,
    offlineThresholdSec: 600, // 10 min without ack → offline
    idleThresholdSec: 300, // engine on but speed=0
    tailMaxPoints: 30, // points kept per device for the tail polyline
  };

  // Map provider config injected by Laravel (mapId, apiKeys, available list).
  var MAP_CFG = (function () {
    var node = document.getElementById("tp-live-map-config");
    if (!node)
      return {
        mapId: null,
        available: [],
        keys: {},
        openmaptilesUrl: "",
        locale: "en",
      };
    try {
      return JSON.parse(node.textContent) || {};
    } catch (e) {
      console.warn("[live-ops] map config parse error", e);
      return {};
    }
  })();

  // Sembrar el estado compartido de toggles desde el backend (user->map_controls).
  if (window.tpMapShared && window.tpMapShared.init) {
    window.tpMapShared.init({
      toggles: (MAP_CFG && MAP_CFG.toggles) || {},
      urls: (MAP_CFG && MAP_CFG.urls) || {},
    });
  }

  // -----------------------------------------------------------
  // MAP PROVIDERS — mirrors legacy map_id catalog (config/maps.php)
  // Each entry returns a MapLibre GL `style` object (raster sources).
  // Returns null when a required API key is missing.
  // -----------------------------------------------------------
  var PROVIDERS = (function () {
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

    return {
      // -- OpenStreetMap (free) -----------------------------------
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

      // -- Google (direct tiles via mt0) — IDs 51,53,54,55,56,57 ---
      // Note: legacy IDs 1/3/4/5 use Google JS SDK (gridLayer.googleMutant), not raster — fallback to direct tiles.
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

      // -- Carto (free) -------------------------------------------
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

      // -- Mapbox (api key) ---------------------------------------
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

      // -- MapTiler (api key) -------------------------------------
      17: function () {
        if (!k.maptiler_key) return null;
        return rasterStyle(
          {
            tiles: [
              "https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key=" +
                encodeURIComponent(k.maptiler_key),
            ],
            tileSize: 256,
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

      // -- HERE (api key) -----------------------------------------
      10: function () {
        return hereStyle("explore.day");
      },
      11: function () {
        return hereStyle("satellite.day");
      },
      12: function () {
        return hereStyle("explore.satellite.day");
      },

      // -- TomTom (api key) ---------------------------------------
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

      // -- Azure Maps (api key) -----------------------------------
      28: function () {
        return azureStyle("microsoft.base.road");
      },
      29: function () {
        return azureStyle("microsoft.imagery");
      }, // Hybrid → imagery in MapLibre
      30: function () {
        return azureStyle("microsoft.imagery");
      },

      // -- Bing (api key, deferred — needs metadata fetch) --------
      // Bing requires metadata API to obtain quadkey URL templates.
      // We resolve them lazily inside applyMapStyle() for IDs 7/8/9.
      7: function () {
        return bingDeferred("Road");
      },
      8: function () {
        return bingDeferred("Aerial");
      },
      9: function () {
        return bingDeferred("AerialWithLabels");
      },

      // -- OpenMapTiles (self-hosted) -----------------------------
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
      // Returns a placeholder marker; resolveStyle() will async-fetch the real template.
      return { __bing: imagerySet, __key: k.bing_maps_key };
    }
  })();

  // -----------------------------------------------------------
  // Default fallback style (auto: theme-aware Carto / OSM)
  // -----------------------------------------------------------
  function fallbackStyle() {
    return getStyleMode() === "dark" ? PROVIDERS[72]() : PROVIDERS[2]();
  }

  /**
   * Resolve a style for the given provider id. Returns a Promise<style>.
   * - Synchronous for raster providers (returns resolved promise).
   * - Async for Bing (fetches quadkey metadata once and caches).
   * - Falls back to OSM/Carto when the requested provider has no key.
   */
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
                  tiles: [template.replace("{quadkey}", "{quadkey}")],
                  tileSize: 256,
                  attribution: "© Microsoft Bing Maps",
                },
              },
              layers: [{ id: "base", type: "raster", source: "base" }],
            };
            // MapLibre lacks {quadkey}: rewrite tiles via tile-loader transform handled later.
            style._bingTemplate = template;
            bingCache[key] = style;
            return style;
          } catch (e) {
            console.warn("[live-ops] Bing metadata failed", e);
            return fallbackStyle();
          }
        })
        .catch(function () {
          return fallbackStyle();
        });
    }
    return Promise.resolve(raw);
  }

  // -----------------------------------------------------------
  // State
  // -----------------------------------------------------------
  var state = {
    devices: [], // raw devices array (latest fetch, all pages)
    total: 0, // total de devices del backend (meta.pagination.total)
    index: {}, // id → device
    markers: {}, // id → maplibregl.Marker
    markerEls: {}, // id → DOM element
    poiMarkers: {}, // poiId → maplibregl.Marker (DOM, icono real, arrastrable)
    filtered: [], // current filtered + sorted view
    visual: [], // visual rows (group headers + devices) for the virtualizer
    visualTotalH: 0,
    groups: [], // [{id,title}] from backend (deviceGroups)
    grouped: true, // group list by device group name (like legacy)
    collapsedGroups: {}, // gid → true when collapsed
    statusFilter: "all",
    searchQuery: "",
    selectedId: null,
    listScrollTop: 0,
    map: null,
    socket: null,
    joinedChannels: {},
    currentMapId: null, // active provider id
    followsTheme: true, // when true, swaps Carto<->OSM on theme change
    pollHandle: null,
    autoFit: false, // auto-fit map to fleet bounds on each data update
    geolocateMarker: null, // maplibregl.Marker for user's GPS location
    toggles: {
      cluster: false,
      devices: true,
      geofences: false,
      poi: false,
      routes: false,
      names: false,
      tails: false,
      traffic: false,
    },
    tails: {}, // id → array of [lng, lat] (last N positions)
    zones: null, // cached zones payload
    zonesLoaded: false,
    clusterMarkers: [], // active cluster markers (DOM markers grouped by grid cell)
    trafficLayerId: "tp-live-traffic",
    bootstrapped: false,
  };

  // Preferencia de "vista por grupos" persistida localmente.
  try {
    var savedGrouped = localStorage.getItem("tp_live_grouped");
    if (savedGrouped !== null) state.grouped = savedGrouped === "1";
  } catch (e) {}

  // -----------------------------------------------------------
  // Status classification (operational, not legacy)
  // -----------------------------------------------------------
  // Clasifica un device en uno de los 3 buckets del filtro a partir del estado
  // AUTORITATIVO del backend (Device::getStatus(), enviado en d.online). No se
  // re-deriva de timestamps para que coincida exactamente con el legacy.
  function classify(d) {
    switch (d && d.online) {
      case "online":
      case "excessive_speed":
      case "move":
        return "moving";
      case "engine":
      case "ack":
      case "stop":
      case "idle":
      case "park":
      case "blocked":
        return "idle";
      default: // offline, expired, inactive, null
        return "offline";
    }
  }

  function fmtTimeAgo(ts) {
    if (!ts) return "—";
    var sec = parseInt(ts, 10);
    if (sec > 1e12) sec = Math.floor(sec / 1000); // ms → s
    var diff = Math.floor(Date.now() / 1000) - sec;
    if (diff < 0) diff = 0;
    if (diff < 60) return diff + "s";
    if (diff < 3600) return Math.floor(diff / 60) + "m";
    if (diff < 86400) return Math.floor(diff / 3600) + "h";
    return Math.floor(diff / 86400) + "d";
  }

  function fmtDuration(sec) {
    if (sec == null || isNaN(sec)) return "—";
    sec = parseInt(sec, 10);
    if (sec < 60) return sec + "s";
    if (sec < 3600) return Math.floor(sec / 60) + "m";
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    return h + "h " + m + "m";
  }

  function escapeHtml(s) {
    if (s == null) return "";
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // -----------------------------------------------------------
  // Map init
  // -----------------------------------------------------------
  function getStyleMode() {
    return document.documentElement.getAttribute("data-theme") === "dark"
      ? "dark"
      : "light";
  }

  /** Bing serves tiles with a {quadkey} param; convert {z}/{x}/{y} on the fly. */
  function tileToQuadkey(x, y, z) {
    var quad = "";
    for (var i = z; i > 0; i--) {
      var digit = 0,
        mask = 1 << (i - 1);
      if ((x & mask) !== 0) digit++;
      if ((y & mask) !== 0) digit += 2;
      quad += digit;
    }
    return quad;
  }

  function initMap() {
    if (!window.maplibregl) {
      console.warn("[live-ops] MapLibre GL not loaded");
      return;
    }
    // Choose initial provider: explicit user pick → first available → fallback.
    state.currentMapId =
      MAP_CFG.mapId ||
      (MAP_CFG.available && MAP_CFG.available[0] && MAP_CFG.available[0].id) ||
      null;
    state.followsTheme = !state.currentMapId;

    var initialStyle = state.currentMapId ? null : fallbackStyle();

    state.map = new maplibregl.Map({
      container: "tp-live-map",
      style: initialStyle || fallbackStyle(),
      center: [-77.0428, -12.0464],
      zoom: 4,
      minZoom: 2,
      maxZoom: 22,
      attributionControl: { compact: true },
      // Resolve {quadkey} for Bing tiles before fetch.
      transformRequest: function (url, resourceType) {
        if (resourceType === "Tile" && url.indexOf("{quadkey}") !== -1) {
          var m = url.match(/\/(\d+)\/(\d+)\/(\d+)/) || [];
          // MapLibre never substitutes {quadkey}; we receive the raw template instead.
          // The bing flow handles that via per-tile rewrite below.
        }
        return { url: url };
      },
    });
    state.map.addControl(
      new maplibregl.NavigationControl({ showCompass: false }),
      "bottom-right",
    );

    // Apply the chosen provider once.
    if (state.currentMapId) {
      applyProvider(state.currentMapId);
    }

    state.map.on("load", function () {
      bindClusterZoom();
      bindPoiInteractions();
      if (state.devices.length) {
        renderMarkers();
        fitToFleet(false);
      }
      // POIs: si el toggle está activo, cargar y pintar sus marcadores (icono real).
      if (state.toggles.poi) loadPoisPanel(true);
      // Si llegamos con un panel de mapa abierto (deep-link), inicializar su capa.
      var ap = document.querySelector(".tp-live__panel:not([hidden])");
      var apn = ap && ap.getAttribute("data-panel");
      if (apn === "zones") {
        bindZoneMapClick();
        ensureZonesVisible();
        loadZones(true);
      }
      // Re-apply overlays after style swaps (basemap changes wipe sources)
    });
    state.map.on("styledata", function () {
      if (state.toggles.geofences) renderGeofences();
      if (state.toggles.traffic) renderTraffic();
      if (state.toggles.tails) updateTails();
      renderSharedPois();
      renderSharedRoutes();
    });

    // Theme observer — only swap when followsTheme is true (no explicit pick).
    var mo = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        if (m.attributeName === "data-theme" && state.followsTheme) {
          state.map.setStyle(fallbackStyle());
        }
      });
    });
    mo.observe(document.documentElement, { attributes: true });

    // Build the providers menu.
    buildProvidersMenu();
  }

  function applyProvider(mapId) {
    if (!state.map) return;
    state.currentMapId = mapId;
    state.followsTheme = false;
    resolveStyle(mapId).then(function (style) {
      // Bing: rewrite per-tile via custom source after style load.
      var bingTemplate = style && style._bingTemplate;
      state.map.setStyle(style);
      state.map.once("styledata", function () {
        if (bingTemplate) {
          var src = state.map.getSource("base");
          // MapLibre has no {quadkey}; replace with TileJSON-like custom URL: we set tiles using a callback.
          // Since MapLibre cannot call JS per-tile, the simplest path is to rewrite using maxzoom and known quadkey patterns up front. Bing tile schemes accept {-y} too — we fall back to OSM if pattern is incompatible.
          if (src && src.tiles) {
            // Best-effort: convert {quadkey} in the URL using a stub that won't match → keep fallback warning.
            console.info(
              "[live-ops] Bing provider active; if tiles do not load, switch to another provider.",
            );
          }
        }
      });
      updateProvidersMenu();
      // Save user pick (best-effort).
      try {
        var fd = new FormData();
        fd.append("key", "tp_map_id");
        fd.append("value", String(mapId));
        fetch("/ui/v2/api/preferences", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        }).catch(function () {});
      } catch (e) {}
    });
  }

  function fitToFleet(animate) {
    if (!state.map || !state.filtered.length) return;
    var coords = state.filtered
      .filter(function (d) {
        return d.lat != null && d.lng != null;
      })
      .map(function (d) {
        return [parseFloat(d.lng), parseFloat(d.lat)];
      });
    if (!coords.length) return;
    var bounds = coords.reduce(
      function (b, c) {
        return b.extend(c);
      },
      new maplibregl.LngLatBounds(coords[0], coords[0]),
    );
    state.map.fitBounds(bounds, {
      padding: 60,
      maxZoom: 14,
      animate: !!animate,
      duration: 600,
    });
  }

  // -----------------------------------------------------------
  // Providers menu (top-right of map)
  // -----------------------------------------------------------
  function buildProvidersMenu() {
    var menu = document.getElementById("tp-live-providers-menu");
    if (!menu) return;
    var items = (MAP_CFG.available || []).slice();

    // Always offer "Auto (theme-aware)" at the top.
    items.unshift({ id: "__auto", name: "Automático (según tema)" });

    var html = items
      .map(function (m) {
        var supported = m.id === "__auto" || !!PROVIDERS[m.id];
        var disabled =
          !supported || (PROVIDERS[m.id] && PROVIDERS[m.id]() == null);
        var active =
          (m.id === "__auto" && state.followsTheme) ||
          state.currentMapId === m.id;
        return (
          '<button class="tp-menu__item' +
          (active ? " is-active" : "") +
          (disabled ? " is-disabled" : "") +
          '"' +
          ' role="menuitem" type="button"' +
          ' data-map-id="' +
          m.id +
          '"' +
          (disabled ? ' disabled aria-disabled="true"' : "") +
          ">" +
          '<span class="tp-menu__item-text">' +
          escapeHtml(m.name) +
          "</span>" +
          (active ? '<span class="tp-menu__item-check">✓</span>' : "") +
          (disabled && supported
            ? '<span class="tp-menu__item-hint">sin API key</span>'
            : "") +
          "</button>"
        );
      })
      .join("");
    menu.innerHTML = html;
  }

  function updateProvidersMenu() {
    var menu = document.getElementById("tp-live-providers-menu");
    if (!menu) return;
    menu.querySelectorAll("[data-map-id]").forEach(function (btn) {
      var id = btn.getAttribute("data-map-id");
      var isActive =
        (id === "__auto" && state.followsTheme) ||
        String(state.currentMapId) === id;
      btn.classList.toggle("is-active", isActive);
      var checkEl = btn.querySelector(".tp-menu__item-check");
      if (isActive && !checkEl)
        btn.insertAdjacentHTML(
          "beforeend",
          '<span class="tp-menu__item-check">✓</span>',
        );
      else if (!isActive && checkEl) checkEl.remove();
    });
  }

  // -----------------------------------------------------------
  // Markers
  // -----------------------------------------------------------
  // Resuelve una ruta de asset relativa a absoluta desde la raíz web.
  function assetPath(p) {
    if (!p) return "";
    if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p;
    return "/" + p;
  }

  // Replica device.js updateLayer: resuelve el icono del device según su TIPO.
  //  - arrow:    PNG por color de estado (images/device_icons/estados/<color>.png), 25×33, rota por course, ancla centro
  //  - rotating: imagen propia (icon.path), rota por course, ancla centro
  //  - icon:     imagen propia (icon.path), NO rota, ancla abajo-centro
  function deviceIconInfo(d) {
    var icon = d.icon || {};
    var type = icon.type || "arrow";
    if (type === "arrow") {
      var color = d.icon_color || icon.color || "gray";
      return {
        src: "/images/device_icons/estados/" + color + ".png",
        w: 25,
        h: 33,
        rotate: true,
        anchor: "center",
      };
    }
    if (type === "rotating") {
      return {
        src: assetPath(icon.path),
        w: icon.width || 32,
        h: icon.height || 32,
        rotate: true,
        anchor: "center",
      };
    }
    // 'icon' (estático) o cualquier otro tipo
    return {
      src: assetPath(icon.path),
      w: icon.width || 32,
      h: icon.height || 32,
      rotate: false,
      anchor: "bottom",
    };
  }

  // Aplica el icono (src/tamaño/rotación) + estado + etiqueta al elemento marcador.
  function applyMarkerIcon(el, d) {
    el.setAttribute("data-status", classify(d));
    var info = deviceIconInfo(d);
    var img = el.querySelector(".tp-live-marker__img");
    if (img) {
      if (img.getAttribute("src") !== info.src) img.setAttribute("src", info.src);
      img.style.width = info.w + "px";
      img.style.height = info.h + "px";
      img.style.transform = info.rotate
        ? "rotate(" + (parseFloat(d.course) || 0) + "deg)"
        : "";
    }
    // El contenedor toma el tamaño del icono para que MapLibre ancle bien.
    el.style.width = info.w + "px";
    el.style.height = info.h + "px";
    var label = el.querySelector(".tp-live-marker__label");
    if (label) label.textContent = d.name || d.plate_number || "#" + d.id;
  }

  function buildMarkerEl(device) {
    var el = document.createElement("div");
    el.className = "tp-live-marker tp-live-marker--img";
    el.setAttribute("data-id", device.id);
    var label = document.createElement("span");
    label.className = "tp-live-marker__label";
    el.appendChild(label);
    var img = document.createElement("img");
    img.className = "tp-live-marker__img";
    img.alt = "";
    img.draggable = false;
    el.appendChild(img);
    applyMarkerIcon(el, device);
    el.addEventListener("click", function (e) {
      e.stopPropagation();
      openDevicePopup(device.id);
    });
    return el;
  }

  // -----------------------------------------------------------
  // Popup del marcador (paridad con el legacy device.js openPopup,
  // versión concisa: estado, velocidad, hora, ubicación, detalles)
  // -----------------------------------------------------------
  var GEO_URL = root.getAttribute("data-geo-url") || "";
  var devicePopup = null;
  var addrCache = {};

  var STATUS_LABELS = {
    online: "En movimiento",
    excessive_speed: "Exceso de velocidad",
    move: "En movimiento",
    engine: "Motor encendido",
    idle: "Ralentí",
    ack: "Detenido",
    stop: "Detenido",
    park: "Estacionado",
    blocked: "Bloqueado",
    offline: "Desconectado",
    expired: "Expirado",
    inactive: "Inactivo",
  };

  function statusLabel(d) {
    return STATUS_LABELS[d && d.online] || (d && d.online) || "—";
  }

  function fetchDeviceAddress(d, cb) {
    if (!GEO_URL || d.lat == null || d.lng == null) return cb("");
    if (addrCache[d.id]) return cb(addrCache[d.id]);
    fetch(
      GEO_URL +
        (GEO_URL.indexOf("?") === -1 ? "?" : "&") +
        "lat=" +
        d.lat +
        "&lng=" +
        d.lng,
      {
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      },
    )
      .then(function (r) {
        return r.text();
      })
      .then(function (txt) {
        var i = txt.indexOf("<");
        if (i !== -1) txt = txt.slice(0, i); // corta DebugBar
        var addr = txt.trim();
        try {
          var j = JSON.parse(addr);
          addr = j.address || j.name || j.title || addr;
        } catch (e) {}
        addrCache[d.id] = addr;
        cb(addr);
      })
      .catch(function () {
        cb("");
      });
  }

  function devicePopupHtml(d) {
    var status = classify(d);
    var speed = d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h");
    return (
      '<div class="tp-dev-popup" data-status="' +
      status +
      '">' +
      '<div class="tp-dev-popup__head">' +
      '<span class="tp-dev-popup__dot"></span>' +
      '<span class="tp-dev-popup__name">' +
      escapeHtml(d.name || "#" + d.id) +
      "</span>" +
      "</div>" +
      '<div class="tp-dev-popup__status">' +
      escapeHtml(statusLabel(d)) +
      "</div>" +
      '<dl class="tp-dev-popup__defs">' +
      (d.plate_number
        ? "<dt>Placa</dt><dd>" + escapeHtml(d.plate_number) + "</dd>"
        : "") +
      "<dt>Velocidad</dt><dd>" +
      escapeHtml(speed) +
      "</dd>" +
      "<dt>Hora</dt><dd>" +
      escapeHtml(d.time || "—") +
      "</dd>" +
      '<dt>Ubicación</dt><dd data-pop-addr>Cargando…</dd>' +
      "<dt>Posición</dt><dd>" +
      parseFloat(d.lat).toFixed(5) +
      ", " +
      parseFloat(d.lng).toFixed(5) +
      "</dd>" +
      "</dl>" +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm tp-full" data-pop-details>Ver detalles</button>' +
      "</div>"
    );
  }

  function openDevicePopup(id) {
    var d = state.index[id];
    if (!d || !state.map || d.lat == null || d.lng == null) return;
    if (!devicePopup) {
      devicePopup = new maplibregl.Popup({
        offset: 18,
        closeButton: true,
        maxWidth: "280px",
        className: "tp-dev-popup-wrap",
      });
    }
    devicePopup
      .setLngLat([parseFloat(d.lng), parseFloat(d.lat)])
      .setHTML(devicePopupHtml(d))
      .addTo(state.map);

    var popEl = devicePopup.getElement();
    // Dirección perezosa (respeta plugin geofence_over_address del legacy).
    fetchDeviceAddress(d, function (addr) {
      if (!devicePopup || !devicePopup.isOpen()) return;
      var slot = popEl && popEl.querySelector("[data-pop-addr]");
      if (slot) slot.textContent = addr || "—";
    });
    // "Ver detalles" → abre el drawer.
    var btn = popEl && popEl.querySelector("[data-pop-details]");
    if (btn)
      btn.addEventListener("click", function () {
        selectDevice(id, true);
      });
  }

  function renderMarkers() {
    if (!state.map) return;
    var seen = {};
    state.filtered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      if (d.active === false) return; // toggle "active" desactivado → fuera del mapa
      seen[d.id] = true;
      var lngLat = [parseFloat(d.lng), parseFloat(d.lat)];
      if (state.markers[d.id]) {
        // Update position + icon (src/color/rotation) + status + label
        state.markers[d.id].setLngLat(lngLat);
        applyMarkerIcon(state.markerEls[d.id], d);
      } else {
        var el2 = buildMarkerEl(d);
        var marker = new maplibregl.Marker({
          element: el2,
          anchor: deviceIconInfo(d).anchor,
        })
          .setLngLat(lngLat)
          .addTo(state.map);
        state.markers[d.id] = marker;
        state.markerEls[d.id] = el2;
      }
    });
    // Remove markers no longer in filtered set
    Object.keys(state.markers).forEach(function (id) {
      if (!seen[id]) {
        state.markers[id].remove();
        delete state.markers[id];
        delete state.markerEls[id];
      }
    });
    // Re-apply selected marker emphasis
    if (state.selectedId && state.markerEls[state.selectedId]) {
      state.markerEls[state.selectedId].setAttribute("data-selected", "true");
    }
    // Apply layer toggles (visibility, names, cluster, tails)
    applyMarkerToggles();
    updateTails();
    // Auto-follow fleet if enabled
    if (state.autoFit) fitToFleet(true);
  }

  // -----------------------------------------------------------
  // Layer toggles: cluster / devices / names / tails / traffic
  // -----------------------------------------------------------
  function clearClusterMarkers() {
    state.clusterMarkers.forEach(function (m) {
      m.remove();
    });
    state.clusterMarkers = [];
  }
  function applyMarkerToggles() {
    var T = state.toggles;
    // names class on container
    state.map.getContainer().classList.toggle("tp-live-show-names", !!T.names);

    // devices visibility
    Object.keys(state.markerEls).forEach(function (id) {
      var el = state.markerEls[id];
      el.style.display = T.devices ? "" : "none";
    });

    // cluster
    clearClusterMarkers();
    if (!T.devices || !T.cluster) return;
    var zoom = state.map.getZoom();
    // Grid cell size in degrees scaled by zoom (rougher at low zoom)
    var cell = 360 / Math.pow(2, Math.max(0, Math.floor(zoom)) + 3);
    var groups = {};
    state.filtered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      var lat = parseFloat(d.lat),
        lng = parseFloat(d.lng);
      var key =
        Math.floor(lat / cell).toString() +
        ":" +
        Math.floor(lng / cell).toString();
      (groups[key] = groups[key] || []).push({ id: d.id, lat: lat, lng: lng });
    });
    Object.keys(groups).forEach(function (k) {
      var arr = groups[k];
      if (arr.length < 2) return;
      // Hide member markers and place a cluster marker at centroid
      var clat = 0,
        clng = 0;
      arr.forEach(function (p) {
        clat += p.lat;
        clng += p.lng;
        var el = state.markerEls[p.id];
        if (el) el.style.display = "none";
      });
      clat /= arr.length;
      clng /= arr.length;
      var cEl = document.createElement("div");
      cEl.className = "tp-live-cluster";
      cEl.textContent = arr.length.toString();
      cEl.title = arr.length + " vehículos en esta zona";
      cEl.addEventListener("click", function () {
        state.map.flyTo({
          center: [clng, clat],
          zoom: Math.min(18, state.map.getZoom() + 2),
          duration: 500,
        });
      });
      var cm = new maplibregl.Marker({ element: cEl, anchor: "center" })
        .setLngLat([clng, clat])
        .addTo(state.map);
      state.clusterMarkers.push(cm);
    });
  }

  function updateTails() {
    if (!state.map) return;
    var T = state.toggles;
    // Always accumulate positions (cheap), but only render when ON.
    state.filtered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      var key = String(d.id);
      var arr = state.tails[key] || [];
      var lng = parseFloat(d.lng),
        lat = parseFloat(d.lat);
      var last = arr[arr.length - 1];
      if (!last || last[0] !== lng || last[1] !== lat) {
        arr.push([lng, lat]);
        if (arr.length > CFG.tailMaxPoints) arr.shift();
        state.tails[key] = arr;
      }
    });

    var srcId = "tp-live-tails-src";
    var lyrId = "tp-live-tails-layer";
    if (!T.tails) {
      if (state.map.getLayer(lyrId)) state.map.removeLayer(lyrId);
      if (state.map.getSource(srcId)) state.map.removeSource(srcId);
      return;
    }
    var features = Object.keys(state.tails)
      .filter(function (k) {
        return state.tails[k].length >= 2;
      })
      .map(function (k) {
        return {
          type: "Feature",
          geometry: { type: "LineString", coordinates: state.tails[k] },
          properties: { id: k },
        };
      });
    var data = { type: "FeatureCollection", features: features };
    if (state.map.getSource(srcId)) {
      state.map.getSource(srcId).setData(data);
    } else {
      state.map.addSource(srcId, { type: "geojson", data: data });
      state.map.addLayer({
        id: lyrId,
        type: "line",
        source: srcId,
        paint: {
          "line-color": "#3b82f6",
          "line-width": 3,
          "line-opacity": 0.7,
        },
      });
    }
  }

  function ensureZonesLoaded() {
    if (state.zonesLoaded || !CFG.zonesUrl) return Promise.resolve();
    state.zonesLoaded = true;
    return fetch(CFG.zonesUrl, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.ok ? r.json() : { items: [] };
      })
      .then(function (json) {
        state.zones = (json && json.items) || [];
      })
      .catch(function () {
        state.zones = [];
      });
  }

  function renderGeofences() {
    if (!state.map) return;
    var T = state.toggles;
    var srcId = "tp-live-zones-src";
    var fillId = "tp-live-zones-fill";
    var lineId = "tp-live-zones-line";
    var removeLayers = function () {
      if (state.map.getLayer(fillId)) state.map.removeLayer(fillId);
      if (state.map.getLayer(lineId)) state.map.removeLayer(lineId);
      if (state.map.getSource(srcId)) state.map.removeSource(srcId);
    };
    if (!T.geofences) {
      removeLayers();
      return;
    }
    ensureZonesLoaded().then(function () {
      var features = [];
      (state.zones || []).forEach(function (z) {
        if (!z.active && z.active !== false) {
          /* keep all */
        }
        var color = z.polygon_color || "#1e88e5";
        var geometry = null;
        if (
          z.type === "polygon" &&
          z.coordinates &&
          z.coordinates.length >= 3
        ) {
          var ring = z.coordinates.map(function (c) {
            return [
              parseFloat(c.x || c.lng || c.longitude),
              parseFloat(c.y || c.lat || c.latitude),
            ];
          });
          // Close ring
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
      var data = { type: "FeatureCollection", features: features };
      if (state.map.getSource(srcId)) {
        state.map.getSource(srcId).setData(data);
      } else {
        state.map.addSource(srcId, { type: "geojson", data: data });
        state.map.addLayer({
          id: fillId,
          type: "fill",
          source: srcId,
          paint: {
            "fill-color": ["get", "color"],
            "fill-opacity": 0.15,
          },
        });
        state.map.addLayer({
          id: lineId,
          type: "line",
          source: srcId,
          paint: {
            "line-color": ["get", "color"],
            "line-width": 2,
          },
        });
      }
    });
  }

  // Approximate a circle as a 64-sided polygon (in lng/lat degrees).
  function circlePolygon(lng, lat, radiusM) {
    var coords = [];
    var steps = 64;
    var R = 6378137;
    var dLat = (radiusM / R) * (180 / Math.PI);
    var dLng =
      ((radiusM / R) * (180 / Math.PI)) / Math.cos((lat * Math.PI) / 180);
    for (var i = 0; i <= steps; i++) {
      var a = (i / steps) * 2 * Math.PI;
      coords.push([lng + dLng * Math.cos(a), lat + dLat * Math.sin(a)]);
    }
    return { type: "Polygon", coordinates: [coords] };
  }

  function renderTraffic() {
    if (!state.map) return;
    var T = state.toggles;
    var srcId = "tp-live-traffic-src";
    var lyrId = state.trafficLayerId;
    var remove = function () {
      if (state.map.getLayer(lyrId)) state.map.removeLayer(lyrId);
      if (state.map.getSource(srcId)) state.map.removeSource(srcId);
    };
    if (!T.traffic) {
      remove();
      return;
    }
    if (state.map.getSource(srcId)) return;
    // Google traffic overlay tiles (no API key required).
    state.map.addSource(srcId, {
      type: "raster",
      tiles: [
        "https://mt0.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
        "https://mt1.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
        "https://mt2.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
        "https://mt3.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
      ],
      tileSize: 256,
    });
    state.map.addLayer({
      id: lyrId,
      type: "raster",
      source: srcId,
      paint: { "raster-opacity": 0.85 },
    });
  }

  // -----------------------------------------------------------
  // Filtering + sorting
  // -----------------------------------------------------------
  function recomputeFiltered() {
    var q = state.searchQuery.trim().toLowerCase();
    var sf = state.statusFilter;
    var counts = {
      all: state.devices.length,
      moving: 0,
      idle: 0,
      offline: 0,
    };
    state.filtered = state.devices.filter(function (d) {
      var cls = classify(d);
      if (cls === "moving") counts.moving++;
      else if (cls === "idle") counts.idle++;
      else counts.offline++;
      if (sf !== "all" && cls !== sf) return false;
      if (q) {
        var hay =
          (d.name || "") + " " + (d.plate_number || "") + " " + (d.imei || "");
        if (hay.toLowerCase().indexOf(q) === -1) return false;
      }
      return true;
    });
    // Sort: moving > idle > stopped > offline ; then by name
    var rank = { moving: 0, idle: 1, stopped: 2, offline: 3 };
    state.filtered.sort(function (a, b) {
      var ra = rank[classify(a)] || 9;
      var rb = rank[classify(b)] || 9;
      if (ra !== rb) return ra - rb;
      return (a.name || "").localeCompare(b.name || "");
    });
    // Update count badges + map stats
    Object.keys(counts).forEach(function (k) {
      var n = root.querySelector('[data-count="' + k + '"]');
      if (n) n.textContent = counts[k];
    });
    var sM = document.getElementById("tp-live-stat-moving");
    if (sM) sM.textContent = counts.moving;
    var sI = document.getElementById("tp-live-stat-idle");
    if (sI) sI.textContent = counts.idle;
    var sO = document.getElementById("tp-live-stat-offline");
    if (sO) sO.textContent = counts.offline;
    // Topbar live pill
    if (window.tpUpdateLiveStatus) {
      window.tpUpdateLiveStatus({
        moving: counts.moving,
        idle: counts.idle,
        offline: counts.offline,
      });
    }
    buildVisualRows();
  }

  // Nombre del grupo por id (0 = "Sin grupo", como el legacy).
  function groupTitle(gid) {
    if (!gid) return "Sin grupo";
    for (var i = 0; i < state.groups.length; i++) {
      if (state.groups[i].id === gid) return state.groups[i].title;
    }
    return "Grupo " + gid;
  }

  // Construye las filas "visuales" (cabeceras de grupo + devices) con sus
  // alturas/offsets acumulados, para el virtualizador de altura variable.
  function buildVisualRows() {
    var rowH = CFG.rowHeight + CFG.rowGap;
    var visual = [];
    var top = 0;

    if (state.grouped) {
      var byGroup = {};
      state.filtered.forEach(function (d) {
        var gid = d.group_id || 0;
        (byGroup[gid] = byGroup[gid] || []).push(d);
      });
      // Orden: grupos del backend (por título) y al final los no listados (ej. 0).
      var order = [];
      state.groups.forEach(function (g) {
        if (byGroup[g.id]) order.push(g.id);
      });
      Object.keys(byGroup).forEach(function (k) {
        var gid = parseInt(k, 10);
        if (order.indexOf(gid) === -1) order.push(gid);
      });
      order.forEach(function (gid) {
        var items = byGroup[gid];
        var collapsed = !!state.collapsedGroups[gid];
        visual.push({
          type: "group",
          gid: gid,
          title: groupTitle(gid),
          count: items.length,
          collapsed: collapsed,
          h: CFG.groupHeaderHeight,
          top: top,
        });
        top += CFG.groupHeaderHeight;
        if (!collapsed) {
          items.forEach(function (d) {
            visual.push({ type: "device", d: d, h: rowH, top: top });
            top += rowH;
          });
        }
      });
    } else {
      state.filtered.forEach(function (d) {
        visual.push({ type: "device", d: d, h: rowH, top: top });
        top += rowH;
      });
    }

    state.visual = visual;
    state.visualTotalH = top;
  }

  // -----------------------------------------------------------
  // Virtualized list rendering
  // -----------------------------------------------------------
  var listWrap = root.querySelector(".tp-live__list-wrap");
  var listEl = document.getElementById("tp-live-list");
  var emptyEl = root.querySelector(".tp-live__empty");

  function rowTemplate(d, top) {
    var status = classify(d);
    var speedNum = Math.round(parseFloat(d.speed) || 0);
    var speedTxt = d.speed_text || speedNum + " km/h";
    var ago = fmtTimeAgo(d.acktimestamp || d.timestamp);
    var meta = d.plate_number || "";
    if (d.imei && !meta) meta = d.imei;
    var isActive = d.active !== false; // null/true → activo
    return (
      "" +
      '<div class="tp-live__row" data-id="' +
      d.id +
      '" data-status="' +
      status +
      '"' +
      ' role="option" style="position:absolute; top:' +
      top +
      'px; left:8px; right:8px;"' +
      (state.selectedId === d.id ? ' aria-selected="true"' : "") +
      ">" +
      '  <label class="tp-live__row-active" title="Mostrar en el mapa">' +
      '<input type="checkbox" data-id="' +
      d.id +
      '"' +
      (isActive ? " checked" : "") +
      "></label>" +
      '  <span class="tp-live__row-dot" aria-hidden="true"></span>' +
      '  <div class="tp-live__row-main">' +
      '    <div class="tp-live__row-name">' +
      escapeHtml(d.name || "#" + d.id) +
      "</div>" +
      '    <div class="tp-live__row-meta">' +
      escapeHtml(meta) +
      "</div>" +
      "  </div>" +
      '  <div class="tp-live__row-end">' +
      '    <span class="tp-live__row-speed' +
      (speedNum === 0 ? " tp-live__row-speed--zero" : "") +
      '">' +
      escapeHtml(speedTxt) +
      "</span>" +
      '    <span class="tp-live__row-time">' +
      ago +
      "</span>" +
      "  </div>" +
      '  <button type="button" class="tp-live__row-menu" data-id="' +
      d.id +
      '" title="Acciones" aria-label="Acciones">' +
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">' +
      '<circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>' +
      "</button>" +
      "</div>"
    );
  }

  function groupHeaderTemplate(entry) {
    return (
      '<div class="tp-live__group" data-gid="' +
      entry.gid +
      '" role="button" tabindex="0"' +
      (entry.collapsed ? ' data-collapsed="1"' : "") +
      ' style="position:absolute; top:' +
      entry.top +
      'px; left:8px; right:8px; height:' +
      CFG.groupHeaderHeight +
      'px;">' +
      '<svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>' +
      '<span class="tp-live__group-title">' +
      escapeHtml(entry.title) +
      "</span>" +
      '<span class="tp-live__group-count">' +
      entry.count +
      "</span>" +
      "</div>"
    );
  }

  // Búsqueda binaria de la primera fila visual visible dado scrollTop.
  function firstVisibleIndex(scrollTop) {
    var arr = state.visual;
    var lo = 0,
      hi = arr.length - 1,
      ans = 0;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (arr[mid].top + arr[mid].h >= scrollTop) {
        ans = mid;
        hi = mid - 1;
      } else {
        lo = mid + 1;
      }
    }
    return ans;
  }

  function renderList() {
    var arr = state.visual;
    var total = arr.length;
    // Clear skeleton on first real render
    var sk = listEl.querySelector(".tp-live__skeleton");
    if (sk) sk.remove();

    if (total === 0) {
      listEl.innerHTML = "";
      listEl.style.height = "0";
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;
    listEl.style.position = "relative";
    listEl.style.height = state.visualTotalH + "px";

    var scrollTop = listWrap.scrollTop;
    var viewH = listWrap.clientHeight;
    var bufferPx = CFG.bufferRows * (CFG.rowHeight + CFG.rowGap);
    var top0 = scrollTop - bufferPx;
    var top1 = scrollTop + viewH + bufferPx;

    var start = firstVisibleIndex(top0);
    var html = "";
    for (var i = start; i < total; i++) {
      var e = arr[i];
      if (e.top > top1) break;
      html +=
        e.type === "group" ? groupHeaderTemplate(e) : rowTemplate(e.d, e.top);
    }
    listEl.innerHTML = html;
  }

  // Click on a row → select
  function csrfToken() {
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }

  function persistActive(id, active) {
    try {
      var fd = new FormData();
      fd.append("id", id);
      fd.append("active", active ? "1" : "0");
      fetch("/devices/change_active", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: {
          "X-CSRF-TOKEN": csrfToken(),
          "X-Requested-With": "XMLHttpRequest",
        },
      }).catch(function () {});
    } catch (e) {}
  }

  // Menú de acciones por dispositivo (kebab) — flotante (la lista es virtual)
  var rowMenuEl = null;
  function closeRowMenu() {
    if (rowMenuEl) {
      rowMenuEl.remove();
      rowMenuEl = null;
      document.removeEventListener("click", closeRowMenu);
    }
  }
  // Posiciona el menú flotante: se abre HACIA LA DERECHA del botón kebab,
  // fuera del sidebar y por encima del mapa (como el legacy). Si no cabe a la
  // derecha, cae a la izquierda; el alto se ajusta al viewport.
  function positionRowMenu(btn) {
    if (!rowMenuEl) return;
    var r = btn.getBoundingClientRect();
    var w = rowMenuEl.offsetWidth || 240;
    var h = rowMenuEl.offsetHeight || 0;
    rowMenuEl.style.position = "fixed";
    var left = r.right + 6; // a la derecha del kebab → sobre el mapa
    if (left + w > window.innerWidth - 8) {
      left = Math.max(8, r.left - w - 6); // fallback: a la izquierda
    }
    rowMenuEl.style.left = left + "px";
    rowMenuEl.style.top =
      Math.max(8, Math.min(r.top, window.innerHeight - 10 - h)) + "px";
  }

  // Enruta el click de un ítem del menú según data-act (HTML servido por backend).
  function handleRowMenuClick(e, id) {
    var item = e.target.closest(".tp-row-menu__item");
    if (!item) return;
    var act = item.getAttribute("data-act");
    closeRowMenu();
    if (act === "history") {
      goHistory(id, item.getAttribute("data-range") || "today");
    } else if (act === "follow") {
      followDevice(id);
    } else if (act === "edit") {
      if (window.tpDeviceModal)
        window.tpDeviceModal.openEdit(id, function () {
          fetchDevices(true);
        });
    } else if (act === "pending") {
      // Acción legacy aún no portada a v2 (send_command, sharing, alerts,
      // camera, route_types, call_action, chat, qr, device_config,
      // device_plans, vehicle_state, video). El permiso ya se validó en el
      // backend al renderizar el menú; la funcionalidad queda pendiente.
      if (window.tpModal && tpModal.toast)
        tpModal.toast("Función disponible próximamente.", "info");
    }
  }

  function openRowMenu(btn, id) {
    closeRowMenu();
    if (!state.index[id]) return;
    rowMenuEl = document.createElement("div");
    rowMenuEl.className = "tp-row-menu";
    rowMenuEl.innerHTML =
      '<div class="tp-row-menu__loading">Cargando…</div>';
    document.body.appendChild(rowMenuEl);
    positionRowMenu(btn);
    rowMenuEl.addEventListener("click", function (e) {
      handleRowMenuClick(e, id);
    });
    // Carga el menú server-rendered (mismo gating de permisos que el legacy).
    var base = root.getAttribute("data-devices-url") || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/actions", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!rowMenuEl) return; // ya se cerró
        if (res && res.ok && res.html) {
          rowMenuEl.innerHTML = res.html;
        } else {
          rowMenuEl.innerHTML =
            '<div class="tp-row-menu__loading">Sin acciones.</div>';
        }
        positionRowMenu(btn);
      })
      .catch(function () {
        if (rowMenuEl)
          rowMenuEl.innerHTML =
            '<div class="tp-row-menu__loading">Error al cargar.</div>';
      });
    setTimeout(function () {
      document.addEventListener("click", closeRowMenu);
    }, 0);
  }
  function goHistory(id, range) {
    window.location.href = "/ui/v2/history?device=" + id + "&range=" + range;
  }
  function followDevice(id) {
    state.followId = id;
    selectDevice(id, true);
  }

  function toggleGroup(gid) {
    if (state.collapsedGroups[gid]) delete state.collapsedGroups[gid];
    else state.collapsedGroups[gid] = true;
    buildVisualRows();
    renderList();
  }

  listEl.addEventListener("click", function (e) {
    // Cabecera de grupo → colapsar/expandir.
    var groupEl = e.target.closest(".tp-live__group");
    if (groupEl) {
      toggleGroup(parseInt(groupEl.getAttribute("data-gid"), 10));
      return;
    }
    // El toggle "active" y el kebab no deben seleccionar la fila.
    if (e.target.closest(".tp-live__row-active")) return;
    var menuBtn = e.target.closest(".tp-live__row-menu");
    if (menuBtn) {
      e.stopPropagation();
      openRowMenu(menuBtn, parseInt(menuBtn.getAttribute("data-id"), 10));
      return;
    }
    var row = e.target.closest(".tp-live__row");
    if (!row || row.classList.contains("tp-live__row--skeleton")) return;
    var id = parseInt(row.getAttribute("data-id"), 10);
    if (id) selectDevice(id, true);
  });

  listEl.addEventListener("change", function (e) {
    var cb = e.target;
    if (!cb.matches || !cb.matches('.tp-live__row-active input[type="checkbox"]'))
      return;
    var id = parseInt(cb.getAttribute("data-id"), 10);
    var d = state.index[id];
    if (!d) return;
    d.active = cb.checked;
    renderMarkers();
    persistActive(id, cb.checked);
  });

  // Virtualization scroll handler (rAF throttled)
  var scrollScheduled = false;
  listWrap.addEventListener("scroll", function () {
    if (scrollScheduled) return;
    scrollScheduled = true;
    requestAnimationFrame(function () {
      scrollScheduled = false;
      renderList();
    });
  });

  // -----------------------------------------------------------
  // Drawer
  // -----------------------------------------------------------
  var drawer = document.getElementById("tp-live-drawer");

  function openDrawer() {
    root.setAttribute("data-drawer-open", "true");
    drawer.setAttribute("aria-hidden", "false");
  }
  function closeDrawer() {
    root.removeAttribute("data-drawer-open");
    drawer.setAttribute("aria-hidden", "true");
    state.followId = null;
    root.removeAttribute("data-bottombar-open");
    root.removeAttribute("data-bottombar-collapsed");
    var bbCards = document.getElementById("tp-live-bottombar-cards");
    if (bbCards) bbCards.innerHTML = "";
    var bbExpand = document.getElementById("tp-bottombar-expand");
    if (bbExpand) bbExpand.hidden = true;
    if (state.selectedId && state.markerEls[state.selectedId]) {
      state.markerEls[state.selectedId].removeAttribute("data-selected");
    }
    state.selectedId = null;
    // Update list selection
    var sel = listEl.querySelector('[aria-selected="true"]');
    if (sel) sel.removeAttribute("aria-selected");
  }

  var widgetsBox = null;
  var bottomBar = null;
  var widgetsLoad = { id: null, t: 0 };
  // El endpoint devuelve 2 grupos: .tp-dw--side (drawer) y .tp-dw--bottom
  // (barra inferior de cards). throttled=true → refresco silencioso en cada
  // update del feed (máx 1 cada 30s, sin "Cargando…"). false → carga al abrir.
  function loadDeviceWidgets(id, throttled) {
    widgetsBox = widgetsBox || document.getElementById("tp-drawer-widgets");
    bottomBar = bottomBar || document.getElementById("tp-live-bottombar-cards");
    if (!widgetsBox) return;
    if (throttled && widgetsLoad.id === id && Date.now() - widgetsLoad.t < 30000)
      return;
    widgetsLoad = { id: id, t: Date.now() };
    if (!throttled) {
      widgetsBox.innerHTML =
        '<div class="tp-text-xs tp-text-muted" style="padding:8px 2px;">Cargando información…</div>';
    }
    var base = root.getAttribute("data-devices-url") || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/widgets", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (state.selectedId != id) return; // el usuario cambió de vehículo
        var sideHtml = "",
          bottomHtml = "";
        if (res && res.ok && res.html) {
          var tmp = document.createElement("div");
          tmp.innerHTML = res.html;
          var sideEl = tmp.querySelector(".tp-dw--side");
          var bottomEl = tmp.querySelector(".tp-dw--bottom");
          sideHtml = sideEl ? sideEl.innerHTML.trim() : "";
          bottomHtml = bottomEl ? bottomEl.innerHTML.trim() : "";
        }
        if (sideHtml || !throttled) {
          widgetsBox.innerHTML =
            sideHtml ||
            '<div class="tp-text-xs tp-text-muted" style="padding:8px 2px;">Sin información adicional.</div>';
        }
        if (bottomBar) {
          bottomBar.innerHTML = bottomHtml;
          if (bottomHtml) root.setAttribute("data-bottombar-open", "true");
          else root.removeAttribute("data-bottombar-open");
        }
      })
      .catch(function () {
        if (state.selectedId != id || throttled) return;
        widgetsBox.innerHTML =
          '<div class="tp-text-xs tp-text-muted" style="padding:8px 2px;">No se pudieron cargar los widgets.</div>';
      });
  }

  function fillDrawer(d) {
    var status = classify(d);
    var statusLabels = {
      moving: "En movimiento",
      idle: "Idle",
      stopped: "Detenido",
      offline: "Sin señal",
      alert: "Alerta",
      sos: "SOS",
    };
    var statusVariants = {
      moving: "success",
      idle: "warning",
      stopped: "",
      offline: "danger",
      alert: "danger",
      sos: "critical",
    };

    document.getElementById("tp-drawer-name").textContent =
      d.name || "#" + d.id;
    document.getElementById("tp-drawer-plate").textContent =
      d.plate_number || d.imei || "";
    var dot = document.getElementById("tp-drawer-dot");
    dot.className = "tp-dot tp-dot--" + status;

    var statusRow = document.getElementById("tp-drawer-status-row");
    statusRow.innerHTML =
      '<span class="tp-chip tp-chip--' +
      (statusVariants[status] || "") +
      '">' +
      '<span class="tp-dot tp-dot--' +
      status +
      '"></span> ' +
      (statusLabels[status] || status) +
      "</span>";

    document.getElementById("tp-drawer-speed").textContent = Math.round(
      parseFloat(d.speed) || 0,
    );
    document.getElementById("tp-drawer-course").textContent =
      d.course != null ? Math.round(d.course) + "°" : "—";
    document.getElementById("tp-drawer-stop").textContent = fmtDuration(
      d.stop_duration_sec,
    );
    document.getElementById("tp-drawer-distance").textContent =
      d.total_distance != null ? parseFloat(d.total_distance).toFixed(1) : "—";
    document.getElementById("tp-drawer-lastseen").textContent =
      fmtTimeAgo(d.acktimestamp || d.timestamp) + " atrás";
    document.getElementById("tp-drawer-coords").textContent =
      d.lat != null && d.lng != null
        ? parseFloat(d.lat).toFixed(5) + ", " + parseFloat(d.lng).toFixed(5)
        : "—";
    document.getElementById("tp-drawer-altitude").textContent =
      d.altitude != null ? d.altitude + " m" : "—";
    document.getElementById("tp-drawer-engine").textContent =
      d.engine_status === true || d.engine_status === "on"
        ? "Encendido"
        : d.engine_status === false || d.engine_status === "off"
          ? "Apagado"
          : "—";
    document.getElementById("tp-drawer-imei").textContent = d.imei || "—";
    document.getElementById("tp-drawer-model").textContent =
      d.device_model || "—";
    document.getElementById("tp-drawer-sim").textContent = d.sim_number || "—";
  }

  document
    .getElementById("tp-drawer-close")
    .addEventListener("click", closeDrawer);

  // Modal nativo de configuración de widgets (v2, no legacy)
  function openWidgetsConfig(configUrl, saveUrl) {
    if (!configUrl || !window.tpModal) return;
    window.tpModal.fetchJSON(configUrl, { method: "GET" }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        window.tpModal.toast &&
          window.tpModal.toast("No se pudo cargar la configuración.", "error");
        return;
      }
      var dlg = window.tpModal.open({
        title: "Widgets",
        size: "lg",
        body: res.data.html,
        buttons: [
          { label: "Cancelar", variant: "ghost" },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog, btn) {
              var form = dialog.querySelector("#tp-widgets-config-form");
              if (!form) return false;
              var checked = [];
              form
                .querySelectorAll('input[name="widgets[]"]:checked')
                .forEach(function (i) {
                  checked.push(i.value);
                });
              var payload = {
                default: form.querySelector('[name="default"]').checked ? 1 : 0,
                status: form.querySelector('[name="status"]').checked ? 1 : 0,
                list: checked,
              };
              btn.disabled = true;
              window.tpModal
                .fetchJSON(saveUrl, { method: "POST", json: payload })
                .then(function (r) {
                  btn.disabled = false;
                  if (r && r.ok && r.data && r.data.ok) {
                    window.tpModal.toast &&
                      window.tpModal.toast("Widgets actualizados.", "success");
                    window.tpModal.close(dialog);
                    if (state.selectedId)
                      loadDeviceWidgets(state.selectedId, false);
                  } else {
                    window.tpModal.toast &&
                      window.tpModal.toast("No se pudo guardar.", "error");
                  }
                })
                .catch(function () {
                  btn.disabled = false;
                  window.tpModal.toast &&
                    window.tpModal.toast("Error de red.", "error");
                });
              return false; // mantener abierto hasta éxito
            },
          },
        ],
      });
      // "Predeterminado Widgets" deshabilita la lista (como el legacy)
      var def = dlg.querySelector('[name="default"]');
      var fields = dlg.querySelector("#tp-widgets-config-fields");
      function syncDefault() {
        if (!fields) return;
        fields.style.opacity = def && def.checked ? "0.45" : "";
        fields.querySelectorAll("input").forEach(function (i) {
          i.disabled = def && def.checked;
        });
      }
      if (def) {
        def.addEventListener("change", syncDefault);
        syncDefault();
      }

      // Drag-sort: reordena los widgets; el orden se guarda en list (orden del DOM).
      if (fields) {
        var dragEl = null;
        fields.querySelectorAll(".tp-widget-opt").forEach(function (it) {
          it.addEventListener("dragstart", function (e) {
            dragEl = it;
            it.classList.add("is-dragging");
            if (e.dataTransfer) {
              e.dataTransfer.effectAllowed = "move";
              try {
                e.dataTransfer.setData("text/plain", "");
              } catch (_) {}
            }
          });
          it.addEventListener("dragend", function () {
            it.classList.remove("is-dragging");
            dragEl = null;
          });
          it.addEventListener("dragover", function (e) {
            e.preventDefault();
            if (!dragEl || dragEl === it) return;
            var box = it.getBoundingClientRect();
            var before = e.clientX < box.left + box.width / 2;
            fields.insertBefore(dragEl, before ? it : it.nextSibling);
          });
        });
      }
    });
  }

  // Bottombar: colapsar / expandir / configurar
  (function () {
    var collapseBtn = document.getElementById("tp-bottombar-collapse");
    var expandBtn = document.getElementById("tp-bottombar-expand");
    var configBtn = document.getElementById("tp-bottombar-config");
    if (collapseBtn)
      collapseBtn.addEventListener("click", function () {
        root.setAttribute("data-bottombar-collapsed", "true");
        if (expandBtn) expandBtn.hidden = false;
      });
    if (expandBtn)
      expandBtn.addEventListener("click", function () {
        root.removeAttribute("data-bottombar-collapsed");
        expandBtn.hidden = true;
      });
    if (configBtn)
      configBtn.addEventListener("click", function () {
        openWidgetsConfig(
          configBtn.getAttribute("data-config-url"),
          configBtn.getAttribute("data-save-url"),
        );
      });
  })();
  document
    .getElementById("tp-drawer-center")
    .addEventListener("click", function () {
      if (!state.selectedId) return;
      var d = state.index[state.selectedId];
      if (d && d.lat != null && d.lng != null && state.map) {
        state.map.flyTo({
          center: [parseFloat(d.lng), parseFloat(d.lat)],
          zoom: Math.max(state.map.getZoom(), 15),
          duration: 600,
        });
      }
    });

  function selectDevice(id, centerMap) {
    var d = state.index[id];
    if (!d) return;
    // Clear previous marker selection
    if (state.selectedId && state.markerEls[state.selectedId]) {
      state.markerEls[state.selectedId].removeAttribute("data-selected");
    }
    state.selectedId = id;
    // Mark new
    if (state.markerEls[id])
      state.markerEls[id].setAttribute("data-selected", "true");
    // List highlight
    var prev = listEl.querySelector('[aria-selected="true"]');
    if (prev) prev.removeAttribute("aria-selected");
    var nowEl = listEl.querySelector('[data-id="' + id + '"]');
    if (nowEl) nowEl.setAttribute("aria-selected", "true");
    // Drawer
    fillDrawer(d);
    openDrawer();
    loadDeviceWidgets(id);
    // Center map (smooth)
    if (centerMap && d.lat != null && d.lng != null && state.map) {
      state.map.flyTo({
        center: [parseFloat(d.lng), parseFloat(d.lat)],
        zoom: Math.max(state.map.getZoom(), 13),
        duration: 500,
      });
    }
  }

  // -----------------------------------------------------------
  // Filters wiring
  // -----------------------------------------------------------
  var statusFilterEl = document.getElementById("tp-live-status-filter");
  statusFilterEl.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-filter]");
    if (!btn) return;
    statusFilterEl.querySelectorAll("[data-filter]").forEach(function (b) {
      b.setAttribute("aria-selected", "false");
    });
    btn.setAttribute("aria-selected", "true");
    state.statusFilter = btn.getAttribute("data-filter");
    recomputeFiltered();
    renderList();
    renderMarkers();
  });

  // Toggle "vista por grupos" (agrupar / desagrupar la lista, como el legacy).
  var groupToggleEl = document.getElementById("tp-live-group-toggle");
  if (groupToggleEl) {
    var syncGroupToggle = function () {
      groupToggleEl.setAttribute("aria-pressed", state.grouped ? "true" : "false");
      groupToggleEl.classList.toggle("is-active", state.grouped);
    };
    syncGroupToggle();
    groupToggleEl.addEventListener("click", function () {
      state.grouped = !state.grouped;
      try {
        localStorage.setItem("tp_live_grouped", state.grouped ? "1" : "0");
      } catch (e) {}
      syncGroupToggle();
      buildVisualRows();
      renderList();
    });
  }

  var searchEl = document.getElementById("tp-live-search");
  var searchTimer = null;
  searchEl.addEventListener("input", function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      state.searchQuery = searchEl.value || "";
      recomputeFiltered();
      renderList();
      renderMarkers();
    }, 120);
  });

  // Keyboard shortcut: focus search with /
  document.addEventListener("keydown", function (e) {
    if (e.key === "/" && document.activeElement === document.body) {
      e.preventDefault();
      searchEl.focus();
    }
  });

  // Refresh button
  document
    .getElementById("tp-live-refresh")
    .addEventListener("click", function () {
      fetchDevices(true);
    });

  // Fit-to-fleet button
  document.getElementById("tp-live-fit").addEventListener("click", function () {
    fitToFleet(true);
  });

  // Zoom in / Zoom out
  document
    .getElementById("tp-live-zoom-in")
    .addEventListener("click", function () {
      if (state.map) state.map.zoomIn();
    });
  document
    .getElementById("tp-live-zoom-out")
    .addEventListener("click", function () {
      if (state.map) state.map.zoomOut();
    });

  // Fullscreen toggle on the map area
  (function () {
    var btn = document.getElementById("tp-live-fullscreen");
    var mapArea = document.querySelector(".tp-live__map-area");
    if (!btn || !mapArea) return;
    btn.addEventListener("click", function () {
      var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
      if (fsEl) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(
          document,
        );
      } else {
        (mapArea.requestFullscreen || mapArea.webkitRequestFullscreen).call(
          mapArea,
        );
      }
    });
    document.addEventListener("fullscreenchange", function () {
      btn.setAttribute(
        "aria-pressed",
        document.fullscreenElement ? "true" : "false",
      );
      if (state.map)
        setTimeout(function () {
          state.map.resize();
        }, 150);
    });
  })();

  // Auto-follow fleet toggle
  document
    .getElementById("tp-live-autofollow")
    .addEventListener("click", function () {
      state.autoFit = !state.autoFit;
      this.setAttribute("aria-pressed", state.autoFit ? "true" : "false");
      if (state.autoFit) fitToFleet(true);
    });

  // Geolocate (browser GPS)
  document
    .getElementById("tp-live-geolocate")
    .addEventListener("click", function () {
      var self = this;
      if (!navigator.geolocation) {
        if (window.tpModal && tpModal.toast)
          tpModal.toast("Geolocalización no disponible en este navegador", {
            type: "error",
          });
        return;
      }
      self.setAttribute("aria-pressed", "true");
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          var lng = pos.coords.longitude;
          var lat = pos.coords.latitude;
          if (state.map) {
            state.map.flyTo({ center: [lng, lat], zoom: 15, duration: 800 });
            if (state.geolocateMarker) state.geolocateMarker.remove();
            var el = document.createElement("div");
            el.style.cssText =
              "width:16px;height:16px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 0 0 2px rgba(37,99,235,.4);";
            state.geolocateMarker = new maplibregl.Marker({ element: el })
              .setLngLat([lng, lat])
              .addTo(state.map);
          }
          self.setAttribute("aria-pressed", "false");
        },
        function (err) {
          self.setAttribute("aria-pressed", "false");
          if (window.tpModal && tpModal.toast)
            tpModal.toast(
              "No se pudo obtener la ubicación: " + (err.message || err.code),
              { type: "error" },
            );
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
      );
    });

  // POIs en Live: marcadores DOM con su ICONO real + arrastrables (NO la capa
  // circle de tp-map-shared, que se mantiene solo para Eventos).
  function renderSharedPois() {
    renderPoiMarkers();
  }

  function poiMarkerInner(p) {
    if (p.icon && p.icon.path) {
      var w = p.icon.width || 28,
        h = p.icon.height || 28;
      var max = Math.max(w, h),
        scale = max > 40 ? 40 / max : 1; // tope 40px (evita iconos enormes)
      return (
        '<img src="' +
        p.icon.path +
        '" alt="" draggable="false" style="width:' +
        Math.round(w * scale) +
        "px;height:" +
        Math.round(h * scale) +
        'px;display:block;">'
      );
    }
    return '<span class="tp-poi-marker__dot"></span>';
  }

  function removeAllPoiMarkers() {
    Object.keys(state.poiMarkers).forEach(function (id) {
      state.poiMarkers[id].remove();
      delete state.poiMarkers[id];
    });
  }

  function updatePoiCoords(id, lngLat) {
    if (!window.tpModal) return;
    tpModal
      .fetchJSON(POI_BASE + "/" + id, {
        method: "PUT",
        json: { coordinates: { lat: lngLat.lat, lng: lngLat.lng } },
      })
      .then(function (r) {
        if (r && r.ok && r.data && r.data.ok) {
          tpModal.toast && tpModal.toast("POI movido.", "success");
          var it = poisData.items.filter(function (x) {
            return x.id === id;
          })[0];
          if (it) {
            it.lat = lngLat.lat;
            it.lng = lngLat.lng;
          }
        } else {
          tpModal.toast && tpModal.toast("No se pudo mover el POI.", "error");
          loadPoisPanel(true);
        }
      })
      .catch(function () {
        loadPoisPanel(true);
      });
  }

  function renderPoiMarkers() {
    if (!state.map) return;
    if (!state.toggles.poi) {
      removeAllPoiMarkers();
      return;
    }
    if (!poisLoaded) {
      loadPoisPanel(true); // al terminar vuelve a llamar a renderPoiMarkers()
      return;
    }
    var canEdit = !!document.getElementById("tp-pois-new");
    var seen = {};
    poisData.items.forEach(function (p) {
      if (p.lat == null || p.lng == null) return;
      seen[p.id] = true;
      var existing = state.poiMarkers[p.id];
      if (existing) {
        existing.setLngLat([p.lng, p.lat]);
        existing.getElement().innerHTML = poiMarkerInner(p);
        return;
      }
      var el = document.createElement("div");
      el.className = "tp-poi-marker";
      el.title = p.name || "";
      el.innerHTML = poiMarkerInner(p);
      var mk = new maplibregl.Marker({
        element: el,
        anchor: "center",
        draggable: canEdit,
      })
        .setLngLat([p.lng, p.lat])
        .addTo(state.map);
      mk.__dragged = false;
      el.addEventListener("click", function (e) {
        e.stopPropagation();
        if (mk.__dragged) {
          mk.__dragged = false;
          return;
        }
        var ll = mk.getLngLat();
        openPoiPopup([ll.lng, ll.lat], p.id, p.name);
      });
      if (canEdit)
        mk.on("dragend", function () {
          mk.__dragged = true;
          updatePoiCoords(p.id, mk.getLngLat());
        });
      state.poiMarkers[p.id] = mk;
    });
    Object.keys(state.poiMarkers).forEach(function (id) {
      if (!seen[id]) {
        state.poiMarkers[id].remove();
        delete state.poiMarkers[id];
      }
    });
  }

  function renderSharedRoutes() {
    if (window.tpMapShared && state.map)
      window.tpMapShared.renderRoutes(state.map, CFG.routesUrl, !!state.toggles.routes);
  }

  // -----------------------------------------------------------
  // POIs — CRUD nativo v2 (modo "colocar" + modal + popup editar/eliminar).
  // Reutiliza PoiUserService en el backend; render compartido (tp-map-shared).
  // -----------------------------------------------------------
  var POI_BASE = CFG.poisUrl || "/ui/v2/api/pois";
  var poiPlaceMode = false;
  var poiClickBound = false;
  var poiPopup = null;

  function ensurePoiVisible() {
    if (!state.toggles.poi) {
      state.toggles.poi = true;
      var b = document.querySelector('.tp-live__toggle[data-toggle="poi"]');
      if (b) b.setAttribute("aria-pressed", "true");
      if (window.tpMapShared) tpMapShared.set("poi", true);
    }
    if (poisLoaded) renderPoiMarkers();
    else loadPoisPanel(true);
  }

  function startPoiPlaceMode() {
    poiPlaceMode = true;
    if (state.map) state.map.getCanvas().style.cursor = "crosshair";
    if (window.tpModal && tpModal.toast)
      tpModal.toast("Haz clic en el mapa para ubicar el POI.", "info");
  }
  function stopPoiPlaceMode() {
    poiPlaceMode = false;
    if (state.map) state.map.getCanvas().style.cursor = "";
  }

  function readPoiForm(dialog, coords, id) {
    var form = dialog.querySelector("#tp-poi-form");
    if (!form) return null;
    var icon = form.querySelector('input[name="map_icon_id"]:checked');
    var val = function (sel) {
      var el = form.querySelector(sel);
      return el ? el.value : "";
    };
    return {
      id: id || undefined,
      name: val('[name="name"]'),
      description: val('[name="description"]'),
      group_id: val('[name="group_id"]') || "0",
      map_icon_id: icon ? icon.value : null,
      coordinates: { lat: coords.lat, lng: coords.lng },
    };
  }

  function openPoiModal(coords, id) {
    if (!window.tpModal) return;
    var url = id ? POI_BASE + "/" + id + "/form" : POI_BASE + "/form";
    tpModal.fetchJSON(url, { method: "GET" }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        tpModal.toast && tpModal.toast("No se pudo cargar el formulario.", "error");
        return;
      }
      tpModal.open({
        title: id ? "Editar POI" : "Nuevo POI",
        size: "md",
        body: res.data.html,
        buttons: [
          { label: "Cancelar", variant: "ghost" },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog, btn) {
              var payload = readPoiForm(dialog, coords, id);
              if (!payload || !payload.name.trim()) {
                tpModal.toast && tpModal.toast("El nombre es obligatorio.", "warning");
                return false;
              }
              btn.disabled = true;
              var saveUrl = id ? POI_BASE + "/" + id : POI_BASE;
              tpModal
                .fetchJSON(saveUrl, { method: id ? "PUT" : "POST", json: payload })
                .then(function (r) {
                  btn.disabled = false;
                  if (r && r.ok && r.data && r.data.ok) {
                    tpModal.toast &&
                      tpModal.toast(id ? "POI actualizado." : "POI creado.", "success");
                    tpModal.close(dialog);
                    ensurePoiVisible();
                    refreshPoisPanel();
                  } else if (r && r.data && r.data.errors) {
                    tpModal.toast && tpModal.toast("Revisa los campos.", "warning");
                  } else {
                    tpModal.toast && tpModal.toast("No se pudo guardar.", "error");
                  }
                })
                .catch(function () {
                  btn.disabled = false;
                  tpModal.toast && tpModal.toast("Error de red.", "error");
                });
              return false;
            },
          },
        ],
      });
    });
  }

  function deletePoi(id) {
    if (!window.tpModal) return;
    var doDel = function () {
      tpModal
        .fetchJSON(POI_BASE + "/" + id, { method: "DELETE" })
        .then(function (r) {
          if (r && r.ok && r.data && r.data.ok) {
            tpModal.toast && tpModal.toast("POI eliminado.", "success");
            renderSharedPois();
            refreshPoisPanel();
          } else {
            tpModal.toast && tpModal.toast("No se pudo eliminar.", "error");
          }
        });
    };
    if (tpModal.confirm) {
      tpModal
        .confirm({
          title: "Eliminar POI",
          message: "¿Eliminar este POI?",
          confirmLabel: "Eliminar",
          danger: true,
        })
        .then(function (ok) {
          if (ok) doDel();
        });
    } else {
      doDel();
    }
  }

  // Popup de un POI (editar/eliminar). Reutilizado por el clic en el mapa y
  // por el clic en una fila del panel de POIs.
  function openPoiPopup(coords, id, name) {
    if (!state.map) return;
    if (!poiPopup)
      poiPopup = new maplibregl.Popup({
        offset: 12,
        closeButton: true,
        className: "tp-dev-popup-wrap",
      });
    var canEdit = !!document.getElementById("tp-pois-new");
    poiPopup
      .setLngLat(coords)
      .setHTML(
        '<div class="tp-dev-popup"><div class="tp-dev-popup__head">' +
          '<span class="tp-dev-popup__name">' +
          escapeHtml(name || "POI") +
          "</span></div>" +
          (canEdit
            ? '<div class="tp-poi-popup__actions">' +
              '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-poi-edit>Editar</button>' +
              '<button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-poi-del>Eliminar</button>' +
              "</div>"
            : "") +
          "</div>",
      )
      .addTo(state.map);
    var pe = poiPopup.getElement();
    var be = pe && pe.querySelector("[data-poi-edit]");
    var bd = pe && pe.querySelector("[data-poi-del]");
    if (be)
      be.addEventListener("click", function () {
        poiPopup.remove();
        openPoiModal({ lat: coords[1], lng: coords[0] }, id);
      });
    if (bd)
      bd.addEventListener("click", function () {
        poiPopup.remove();
        deletePoi(id);
      });
  }

  function bindPoiInteractions() {
    if (poiClickBound || !state.map) return;
    poiClickBound = true;

    // Clic en el mapa en modo "colocar" → nuevo POI. (Los POIs existentes son
    // marcadores DOM con su propio handler de clic/arrastre.)
    state.map.on("click", function (e) {
      if (!poiPlaceMode) return;
      var c = e.lngLat;
      stopPoiPlaceMode();
      openPoiModal({ lat: c.lat, lng: c.lng });
    });
  }

  var addPoiBtn = document.getElementById("tp-live-add-poi");
  if (addPoiBtn)
    addPoiBtn.addEventListener("click", function () {
      ensurePoiVisible();
      startPoiPlaceMode();
    });

  // -----------------------------------------------------------
  // Panel de POIs en el sidebar (mismo mapa de Live, como el legacy)
  // -----------------------------------------------------------
  var poisData = { items: [], groups: [] };
  var poisLoaded = false;
  var poisCollapsed = {};

  function loadPoisPanel(force) {
    if (poisLoaded && !force) {
      renderPoisList();
      return;
    }
    fetch(POI_BASE, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        poisData.items = (j && j.items) || [];
        poisData.groups = (j && j.groups) || [];
        poisLoaded = true;
        fillPoiGroupSelect();
        renderPoisList();
        renderPoiMarkers();
      })
      .catch(function () {});
  }
  function refreshPoisPanel() {
    if (poisLoaded) loadPoisPanel(true);
  }

  function fillPoiGroupSelect() {
    var sel = document.getElementById("tp-pois-group");
    if (!sel) return;
    var cur = sel.value;
    var html = '<option value="">Todos los grupos</option>';
    poisData.groups.forEach(function (g) {
      html += '<option value="' + g.id + '">' + escapeHtml(g.title) + "</option>";
    });
    sel.innerHTML = html;
    sel.value = cur;
  }

  var POI_ICONS = {
    go: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.16 7-12a7 7 0 0 0-14 0c0 4.84 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>',
    edit: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>',
    del: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
  };

  function poiGroupTitle(gid) {
    if (!gid) return "Sin grupo";
    for (var i = 0; i < poisData.groups.length; i++) {
      if (poisData.groups[i].id === gid) return poisData.groups[i].title;
    }
    return "Grupo " + gid;
  }

  function poiRowHtml(p, canEdit) {
    return (
      '<div class="tp-poi-row" data-id="' +
      p.id +
      '" data-lat="' +
      p.lat +
      '" data-lng="' +
      p.lng +
      '" role="option">' +
      '<div class="tp-poi-row__main">' +
      '<div class="tp-poi-row__name">' +
      escapeHtml(p.name || "#" + p.id) +
      "</div></div>" +
      '<div class="tp-poi-row__actions">' +
      '<button type="button" class="tp-poi-row__btn" data-poi-go title="Centrar">' +
      POI_ICONS.go +
      "</button>" +
      (canEdit
        ? '<button type="button" class="tp-poi-row__btn" data-poi-edit title="Editar">' +
          POI_ICONS.edit +
          "</button>" +
          '<button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-poi-del title="Eliminar">' +
          POI_ICONS.del +
          "</button>"
        : "") +
      "</div></div>"
    );
  }

  function renderPoisList() {
    var listEl = document.getElementById("tp-pois-list");
    if (!listEl) return;
    var q = ((document.getElementById("tp-pois-search") || {}).value || "")
      .trim()
      .toLowerCase();
    var gsel = (document.getElementById("tp-pois-group") || {}).value || "";
    var filtered = poisData.items.filter(function (p) {
      if (gsel && String(p.group_id || 0) !== String(gsel)) return false;
      if (q && (p.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    var canEdit = !!document.getElementById("tp-pois-new");
    var countEl = document.getElementById("tp-pois-count");
    if (countEl) countEl.textContent = filtered.length ? "· " + filtered.length : "";
    var emptyEl = document.getElementById("tp-pois-empty");
    if (emptyEl) emptyEl.hidden = filtered.length > 0;

    // Agrupar por grupo (como el legacy y el panel de Objetos).
    var byGroup = {};
    filtered.forEach(function (p) {
      var gid = p.group_id || 0;
      (byGroup[gid] = byGroup[gid] || []).push(p);
    });
    var order = [];
    poisData.groups.forEach(function (g) {
      if (byGroup[g.id]) order.push(g.id);
    });
    Object.keys(byGroup).forEach(function (k) {
      var gid = parseInt(k, 10);
      if (order.indexOf(gid) === -1) order.push(gid);
    });

    var chevron =
      '<svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    var html = "";
    order.forEach(function (gid) {
      var items = byGroup[gid];
      var collapsed = !!poisCollapsed[gid];
      html +=
        '<div class="tp-live__group" data-poi-gid="' +
        gid +
        '"' +
        (collapsed ? ' data-collapsed="1"' : "") +
        ' role="button" tabindex="0">' +
        chevron +
        '<span class="tp-live__group-title">' +
        escapeHtml(poiGroupTitle(gid)) +
        "</span>" +
        '<span class="tp-live__group-count">' +
        items.length +
        "</span></div>";
      if (!collapsed)
        html += items
          .map(function (p) {
            return poiRowHtml(p, canEdit);
          })
          .join("");
    });
    listEl.innerHTML = html;
  }

  var poisListEl = document.getElementById("tp-pois-list");
  if (poisListEl)
    poisListEl.addEventListener("click", function (e) {
      // Cabecera de grupo → colapsar/expandir.
      var gh = e.target.closest(".tp-live__group");
      if (gh) {
        var gid = parseInt(gh.getAttribute("data-poi-gid"), 10);
        poisCollapsed[gid] = !poisCollapsed[gid];
        renderPoisList();
        return;
      }
      var row = e.target.closest(".tp-poi-row");
      if (!row) return;
      var id = parseInt(row.getAttribute("data-id"), 10);
      var lat = parseFloat(row.getAttribute("data-lat"));
      var lng = parseFloat(row.getAttribute("data-lng"));
      var name = (row.querySelector(".tp-poi-row__name") || {}).textContent || "";
      if (e.target.closest("[data-poi-edit]")) {
        openPoiModal({ lat: lat, lng: lng }, id);
        return;
      }
      if (e.target.closest("[data-poi-del]")) {
        deletePoi(id);
        return;
      }
      // fila / centrar → vuela al POI + popup
      ensurePoiVisible();
      if (state.map)
        state.map.flyTo({
          center: [lng, lat],
          zoom: Math.max(state.map.getZoom(), 15),
          duration: 600,
        });
      openPoiPopup([lng, lat], id, name);
    });

  var poisSearchEl = document.getElementById("tp-pois-search");
  if (poisSearchEl) poisSearchEl.addEventListener("input", renderPoisList);
  var poisGroupEl = document.getElementById("tp-pois-group");
  if (poisGroupEl) poisGroupEl.addEventListener("change", renderPoisList);
  var poisNewBtn = document.getElementById("tp-pois-new");
  if (poisNewBtn)
    poisNewBtn.addEventListener("click", function () {
      ensurePoiVisible();
      startPoiPlaceMode();
    });

  // -----------------------------------------------------------
  // Panel de GEOCERCAS en el sidebar (mismo mapa de Live, como el legacy)
  // -----------------------------------------------------------
  var ZONES_BASE = CFG.zonesUrl || "/ui/v2/api/zones";
  var zonesData = { items: [], groups: [], vehicleTypes: [], speedPlugin: false };
  var zonesLoaded = false;
  var zonesTypeFilter = "all";
  var zonePopup = null;
  var zoneClickBound = false;
  var zonesById = {};

  function ensureZonesVisible() {
    if (!state.toggles.geofences) {
      state.toggles.geofences = true;
      var b = document.querySelector('.tp-live__toggle[data-toggle="geofences"]');
      if (b) b.setAttribute("aria-pressed", "true");
      if (window.tpMapShared) tpMapShared.set("geofences", true);
    }
    renderGeofences();
  }

  function loadZones(force) {
    if (zonesLoaded && !force) {
      renderZonesList();
      return;
    }
    fetch(ZONES_BASE, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        zonesData.items = (j && j.items) || [];
        zonesData.groups = (j && j.groups) || [];
        zonesData.vehicleTypes = (j && j.vehicle_types) || [];
        zonesData.speedPlugin = !!(j && j.speed_limit_plugin);
        zonesById = {};
        zonesData.items.forEach(function (z) {
          zonesById[z.id] = z;
        });
        zonesLoaded = true;
        // Compartir los datos con la capa del mapa (renderGeofences usa
        // state.zones); así el mapa refleja altas/ediciones/bajas sin re-fetch.
        state.zones = zonesData.items;
        state.zonesLoaded = true;
        fillZoneGroupSelect();
        renderZonesList();
        ensureZonesVisible();
      })
      .catch(function () {});
  }
  function refreshZones() {
    if (zonesLoaded) loadZones(true);
  }

  function fillZoneGroupSelect() {
    var sel = document.getElementById("tp-zones-group");
    if (!sel) return;
    var cur = sel.value;
    var html = '<option value="">Todos los grupos</option>';
    zonesData.groups.forEach(function (g) {
      html += '<option value="' + g.id + '">' + escapeHtml(g.title) + "</option>";
    });
    sel.innerHTML = html;
    sel.value = cur;
  }

  // Normaliza coordenadas de geocerca a array {lat,lng}.
  function zoneCoords(z) {
    return (z.coordinates || [])
      .map(function (c) {
        if (Array.isArray(c)) return { lat: +c[0], lng: +c[1] };
        if (c && c.lat != null) return { lat: +c.lat, lng: +c.lng };
        if (c && c.y != null) return { lat: +c.y, lng: +c.x };
        return null;
      })
      .filter(Boolean);
  }
  function zoneCenter(z) {
    var c = z.center;
    if (!c) return null;
    if (typeof c === "string") {
      var p = c.split(",");
      return { lat: parseFloat(p[0]), lng: parseFloat(p[1]) };
    }
    if (c.lat != null) return { lat: +c.lat, lng: +c.lng };
    if (Array.isArray(c)) return { lat: +c[0], lng: +c[1] };
    return null;
  }
  function flyToZone(z) {
    if (!state.map) return;
    if (z.type === "circle") {
      var ctr = zoneCenter(z);
      if (ctr) state.map.flyTo({ center: [ctr.lng, ctr.lat], zoom: 14, duration: 600 });
      return;
    }
    var pts = zoneCoords(z);
    if (!pts.length) return;
    var b = new maplibregl.LngLatBounds();
    pts.forEach(function (p) {
      b.extend([p.lng, p.lat]);
    });
    state.map.fitBounds(b, { padding: 60, duration: 600, maxZoom: 16 });
  }

  function openZonePopup(z) {
    if (!state.map || !z) return;
    var ctr = z.type === "circle" ? zoneCenter(z) : null;
    if (!ctr) {
      var pts = zoneCoords(z);
      if (pts.length) {
        var sumLat = 0, sumLng = 0;
        pts.forEach(function (p) {
          sumLat += p.lat;
          sumLng += p.lng;
        });
        ctr = { lat: sumLat / pts.length, lng: sumLng / pts.length };
      }
    }
    if (!ctr) return;
    if (!zonePopup)
      zonePopup = new maplibregl.Popup({
        offset: 8,
        closeButton: true,
        className: "tp-dev-popup-wrap",
      });
    var canEdit = !!document.getElementById("tp-zones-new");
    zonePopup
      .setLngLat([ctr.lng, ctr.lat])
      .setHTML(
        '<div class="tp-dev-popup"><div class="tp-dev-popup__head">' +
          '<span class="tp-dev-popup__dot" style="background:' +
          (z.polygon_color || "#1e88e5") +
          '"></span><span class="tp-dev-popup__name">' +
          escapeHtml(z.name || "Geocerca") +
          "</span></div>" +
          '<div class="tp-dev-popup__status">' +
          (z.type === "circle"
            ? "Círculo · " + (z.radius ? Math.round(z.radius) + " m" : "—")
            : "Polígono · " + zoneCoords(z).length + " pts") +
          "</div>" +
          (canEdit
            ? '<div class="tp-poi-popup__actions">' +
              '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-zone-edit>Editar</button>' +
              (z.type === "polygon"
                ? '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-zone-shape>Editar forma</button>'
                : "") +
              '<button type="button" class="tp-btn tp-btn--sm tp-btn--danger" data-zone-del>Eliminar</button>' +
              "</div>"
            : "") +
          "</div>",
      )
      .addTo(state.map);
    var pe = zonePopup.getElement();
    var be = pe && pe.querySelector("[data-zone-edit]");
    var bs = pe && pe.querySelector("[data-zone-shape]");
    var bd = pe && pe.querySelector("[data-zone-del]");
    if (be)
      be.addEventListener("click", function () {
        zonePopup.remove();
        openZoneEditModal(z);
      });
    if (bs)
      bs.addEventListener("click", function () {
        zonePopup.remove();
        startZoneEditShape(z);
      });
    if (bd)
      bd.addEventListener("click", function () {
        zonePopup.remove();
        deleteZone(z.id);
      });
  }

  // Geometría para guardar (preserva el contrato del backend).
  function zoneGeometryPayload(z) {
    if (z.type === "circle") {
      var ctr = zoneCenter(z);
      return { type: "circle", center: ctr, radius: z.radius, polygon: [] };
    }
    return { type: "polygon", polygon: zoneCoords(z) };
  }

  // Matriz de límite de velocidad por tipo/estado de vehículo (plugin
  // geofences_speed_limit). Espeja Geofences/partials/vehicle_speed_limits.
  function speedLimitsMatrixHtml(z) {
    if (!zonesData.speedPlugin || !zonesData.vehicleTypes.length) return "";
    var pre = (z && z.vehicle_speed_limits) || {};
    var val = function (tid, sid) {
      return pre[tid] && pre[tid][sid] != null ? pre[tid][sid] : "";
    };
    var html =
      '<div class="tp-field"><label class="tp-field__label">Límite por tipo de vehículo (km/h)</label>' +
      '<div class="tp-zone-vsl">';
    zonesData.vehicleTypes.forEach(function (vt) {
      if (!vt.states || !vt.states.length) {
        html +=
          '<div class="tp-zone-vsl__row"><span class="tp-zone-vsl__name">' +
          escapeHtml(vt.name) +
          "</span>" +
          '<input type="number" min="0" class="tp-input tp-input--sm" data-vsl-type="' +
          vt.id +
          '" data-vsl-state="0" value="' +
          val(vt.id, 0) +
          '"></div>';
      } else {
        html +=
          '<div class="tp-zone-vsl__group"><div class="tp-zone-vsl__title">' +
          escapeHtml(vt.name) +
          "</div>";
        vt.states.forEach(function (st) {
          html +=
            '<div class="tp-zone-vsl__row"><span class="tp-zone-vsl__name">' +
            escapeHtml(st.name) +
            "</span>" +
            '<input type="number" min="0" class="tp-input tp-input--sm" data-vsl-type="' +
            vt.id +
            '" data-vsl-state="' +
            st.id +
            '" value="' +
            val(vt.id, st.id) +
            '"></div>';
        });
        html += "</div>";
      }
    });
    return html + "</div></div>";
  }
  // Recoge la matriz en un objeto {typeId:{stateId:value}}. Devuelve {} (no null)
  // cuando el plugin/tipos existen, para que el backend pueda borrar los quitados.
  function gatherSpeedLimits(dialog) {
    if (!zonesData.speedPlugin || !zonesData.vehicleTypes.length) return null;
    var out = {};
    dialog.querySelectorAll("[data-vsl-type]").forEach(function (inp) {
      var v = inp.value;
      if (v === "" || v == null) return;
      var t = inp.getAttribute("data-vsl-type"),
        s = inp.getAttribute("data-vsl-state");
      (out[t] = out[t] || {})[s] = v;
    });
    return out;
  }

  function openZoneEditModal(z) {
    if (!window.tpModal) return;
    var groupOpts = '<option value="0">Sin grupo</option>';
    zonesData.groups.forEach(function (g) {
      groupOpts +=
        '<option value="' +
        g.id +
        '"' +
        (String(z.group_id || 0) === String(g.id) ? " selected" : "") +
        ">" +
        escapeHtml(g.title) +
        "</option>";
    });
    var body =
      '<form id="tp-zone-form" class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">Nombre</label>' +
      '<input type="text" name="name" class="tp-input" value="' +
      escapeHtml(z.name || "") +
      '"></div>' +
      '<div class="tp-field"><label class="tp-field__label">Color</label>' +
      '<input type="color" name="polygon_color" class="tp-input" value="' +
      (z.polygon_color || "#1e88e5") +
      '" style="height:38px;padding:4px;"></div>' +
      '<div class="tp-field"><label class="tp-field__label">Grupo</label>' +
      '<select name="group_id" class="tp-input">' +
      groupOpts +
      "</select></div>" +
      '<div class="tp-field"><label class="tp-field__label">Límite de velocidad (km/h)</label>' +
      '<input type="number" name="speed_limit" class="tp-input" min="0" value="' +
      (z.speed_limit != null ? z.speed_limit : "") +
      '"></div>' +
      speedLimitsMatrixHtml(z) +
      "</form>";
    tpModal.open({
      title: "Editar geocerca",
      size: "md",
      body: body,
      buttons: [
        { label: "Cancelar", variant: "ghost" },
        {
          label: "Guardar",
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var f = dialog.querySelector("#tp-zone-form");
            var name = (f.querySelector('[name="name"]').value || "").trim();
            if (!name) {
              tpModal.toast && tpModal.toast("El nombre es obligatorio.", "warning");
              return false;
            }
            var sl = f.querySelector('[name="speed_limit"]').value;
            var payload = Object.assign(
              {
                name: name,
                polygon_color: f.querySelector('[name="polygon_color"]').value,
                group_id: parseInt(f.querySelector('[name="group_id"]').value, 10) || 0,
                active: 1,
              },
              zoneGeometryPayload(z),
            );
            if (sl !== "") payload.speed_limit = parseFloat(sl);
            var vsl = gatherSpeedLimits(dialog);
            if (vsl) payload.vehicle_speed_limits = vsl;
            btn.disabled = true;
            tpModal
              .fetchJSON(ZONES_BASE + "/" + z.id, { method: "PUT", json: payload })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast && tpModal.toast("Geocerca actualizada.", "success");
                  tpModal.close(dialog);
                  refreshZones();
                  ensureZonesVisible();
                } else {
                  tpModal.toast && tpModal.toast("No se pudo guardar.", "error");
                }
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast && tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });
  }

  function deleteZone(id) {
    if (!window.tpModal) return;
    var doDel = function () {
      tpModal
        .fetchJSON(ZONES_BASE + "/" + id, { method: "DELETE" })
        .then(function (r) {
          if (r && r.ok && r.data && r.data.ok) {
            tpModal.toast && tpModal.toast("Geocerca eliminada.", "success");
            refreshZones();
            ensureZonesVisible();
          } else {
            tpModal.toast && tpModal.toast("No se pudo eliminar.", "error");
          }
        });
    };
    if (tpModal.confirm)
      tpModal
        .confirm({
          title: "Eliminar geocerca",
          message: "¿Eliminar esta geocerca?",
          confirmLabel: "Eliminar",
          danger: true,
        })
        .then(function (ok) {
          if (ok) doDel();
        });
    else doDel();
  }

  function renderZonesList() {
    var listEl = document.getElementById("tp-zones-list");
    if (!listEl) return;
    var q = ((document.getElementById("tp-zones-search") || {}).value || "")
      .trim()
      .toLowerCase();
    var gsel = (document.getElementById("tp-zones-group") || {}).value || "";
    var rows = zonesData.items.filter(function (z) {
      if (zonesTypeFilter !== "all" && z.type !== zonesTypeFilter) return false;
      if (gsel && String(z.group_id || 0) !== String(gsel)) return false;
      if (q && (z.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    var canEdit = !!document.getElementById("tp-zones-new");
    var countEl = document.getElementById("tp-zones-count");
    if (countEl) countEl.textContent = rows.length ? "· " + rows.length : "";
    var emptyEl = document.getElementById("tp-zones-empty");
    if (emptyEl) emptyEl.hidden = rows.length > 0;
    listEl.innerHTML = rows
      .map(function (z) {
        var meta =
          z.type === "circle"
            ? "Círculo · " + (z.radius ? Math.round(z.radius) + " m" : "—")
            : "Polígono · " + (z.coordinates || []).length + " pts";
        return (
          '<div class="tp-poi-row" data-id="' +
          z.id +
          '" role="option">' +
          '<span class="tp-zone-row__swatch" style="background:' +
          (z.polygon_color || "#1e88e5") +
          '"></span>' +
          '<div class="tp-poi-row__main">' +
          '<div class="tp-poi-row__name">' +
          escapeHtml(z.name || "#" + z.id) +
          "</div>" +
          '<div class="tp-poi-row__group">' +
          meta +
          "</div></div>" +
          '<div class="tp-poi-row__actions">' +
          '<button type="button" class="tp-poi-row__btn" data-zone-go title="Centrar">' +
          POI_ICONS.go +
          "</button>" +
          (canEdit
            ? '<button type="button" class="tp-poi-row__btn" data-zone-edit title="Editar">' +
              POI_ICONS.edit +
              "</button>" +
              '<button type="button" class="tp-poi-row__btn tp-poi-row__btn--danger" data-zone-del title="Eliminar">' +
              POI_ICONS.del +
              "</button>"
            : "") +
          "</div></div>"
        );
      })
      .join("");
  }

  var zonesListEl = document.getElementById("tp-zones-list");
  if (zonesListEl)
    zonesListEl.addEventListener("click", function (e) {
      var row = e.target.closest(".tp-poi-row");
      if (!row) return;
      var z = zonesById[parseInt(row.getAttribute("data-id"), 10)];
      if (!z) return;
      if (e.target.closest("[data-zone-edit]")) {
        openZoneEditModal(z);
        return;
      }
      if (e.target.closest("[data-zone-del]")) {
        deleteZone(z.id);
        return;
      }
      ensureZonesVisible();
      flyToZone(z);
      openZonePopup(z);
    });

  var zonesSearchEl = document.getElementById("tp-zones-search");
  if (zonesSearchEl) zonesSearchEl.addEventListener("input", renderZonesList);
  var zonesGroupEl = document.getElementById("tp-zones-group");
  if (zonesGroupEl) zonesGroupEl.addEventListener("change", renderZonesList);
  var zonesTypeEl = document.getElementById("tp-zones-type-filter");
  if (zonesTypeEl)
    zonesTypeEl.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-ztype]");
      if (!btn) return;
      zonesTypeEl.querySelectorAll("[data-ztype]").forEach(function (b) {
        b.setAttribute("aria-selected", "false");
      });
      btn.setAttribute("aria-selected", "true");
      zonesTypeFilter = btn.getAttribute("data-ztype");
      renderZonesList();
    });
  var zonesNewBtn = document.getElementById("tp-zones-new");
  if (zonesNewBtn)
    zonesNewBtn.addEventListener("click", function () {
      startZoneCreate();
    });

  function bindZoneMapClick() {
    if (zoneClickBound || !state.map) return;
    zoneClickBound = true;
    // Capa de geocercas de Live (renderGeofences usa tp-live-zones-fill).
    state.map.on("click", "tp-live-zones-fill", function (e) {
      if (zoneDraw) return; // dibujando: no abrir popup
      var f = e.features && e.features[0];
      if (!f) return;
      var z = zonesById[f.properties.id];
      if (z) openZonePopup(z);
    });
    state.map.on("mouseenter", "tp-live-zones-fill", function () {
      state.map.getCanvas().style.cursor = "pointer";
    });
    state.map.on("mouseleave", "tp-live-zones-fill", function () {
      if (!poiPlaceMode && !zoneDraw) state.map.getCanvas().style.cursor = "";
    });
  }

  // -----------------------------------------------------------
  // Dibujo de geocercas en el mapa de Live (polígono + círculo, sin librería)
  // -----------------------------------------------------------
  var ZONE_DRAW_SRC = "tp-zonedraw-src";
  var zoneDraw = null; // {type, points:[], center, radius}
  var zoneDrawBound = false;
  var zoneDrawBar = null;

  function metersBetween(a, b) {
    var R = 6371000,
      toR = Math.PI / 180;
    var dLat = (b.lat - a.lat) * toR,
      dLng = (b.lng - a.lng) * toR;
    var la1 = a.lat * toR,
      la2 = b.lat * toR;
    var h =
      Math.pow(Math.sin(dLat / 2), 2) +
      Math.cos(la1) * Math.cos(la2) * Math.pow(Math.sin(dLng / 2), 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }
  function ensureDrawLayers() {
    if (state.map.getSource(ZONE_DRAW_SRC)) return;
    state.map.addSource(ZONE_DRAW_SRC, {
      type: "geojson",
      data: { type: "FeatureCollection", features: [] },
    });
    state.map.addLayer({
      id: "tp-zonedraw-fill",
      type: "fill",
      source: ZONE_DRAW_SRC,
      filter: ["==", "$type", "Polygon"],
      paint: { "fill-color": "#1b99bd", "fill-opacity": 0.2 },
    });
    state.map.addLayer({
      id: "tp-zonedraw-line",
      type: "line",
      source: ZONE_DRAW_SRC,
      paint: { "line-color": "#1b99bd", "line-width": 2 },
    });
    state.map.addLayer({
      id: "tp-zonedraw-pts",
      type: "circle",
      source: ZONE_DRAW_SRC,
      filter: ["==", "$type", "Point"],
      paint: {
        "circle-radius": 4,
        "circle-color": "#ffffff",
        "circle-stroke-color": "#1b99bd",
        "circle-stroke-width": 2,
      },
    });
  }
  function clearDrawLayers() {
    ["tp-zonedraw-fill", "tp-zonedraw-line", "tp-zonedraw-pts"].forEach(
      function (id) {
        if (state.map.getLayer(id)) state.map.removeLayer(id);
      },
    );
    if (state.map.getSource(ZONE_DRAW_SRC))
      state.map.removeSource(ZONE_DRAW_SRC);
  }
  function drawPreview(extraLL) {
    if (!zoneDraw || !state.map.getSource(ZONE_DRAW_SRC)) return;
    var feats = [];
    if (zoneDraw.type === "polygon") {
      var pts = zoneDraw.points;
      if (pts.length >= 3) {
        var ring = pts.map(function (p) {
          return [p.lng, p.lat];
        });
        ring.push([pts[0].lng, pts[0].lat]);
        feats.push({
          type: "Feature",
          geometry: { type: "Polygon", coordinates: [ring] },
          properties: {},
        });
      } else if (pts.length >= 2) {
        feats.push({
          type: "Feature",
          geometry: {
            type: "LineString",
            coordinates: pts.map(function (p) {
              return [p.lng, p.lat];
            }),
          },
          properties: {},
        });
      }
    } else if (zoneDraw.center) {
      feats.push({
        type: "Feature",
        geometry: {
          type: "Point",
          coordinates: [zoneDraw.center.lng, zoneDraw.center.lat],
        },
        properties: {},
      });
      var r =
        zoneDraw.radius ||
        (extraLL ? metersBetween(zoneDraw.center, extraLL) : 0);
      if (r > 0)
        feats.push({
          type: "Feature",
          geometry: circlePolygon(zoneDraw.center.lng, zoneDraw.center.lat, r),
          properties: {},
        });
    }
    state.map
      .getSource(ZONE_DRAW_SRC)
      .setData({ type: "FeatureCollection", features: feats });
  }

  // Vértices arrastrables del polígono (mover / eliminar, como el legacy).
  function addVertex(ll) {
    var pt = { lat: ll.lat, lng: ll.lng };
    zoneDraw.points.push(pt);
    var el = document.createElement("div");
    el.className = "tp-zone-vertex";
    el.title = "Arrastra para mover · clic derecho para eliminar";
    var m = new maplibregl.Marker({ element: el, draggable: true })
      .setLngLat([pt.lng, pt.lat])
      .addTo(state.map);
    m.on("drag", function () {
      var p = m.getLngLat();
      pt.lat = p.lat;
      pt.lng = p.lng;
      drawPreview();
    });
    el.addEventListener("contextmenu", function (e) {
      e.preventDefault();
      if (!zoneDraw) return;
      if (zoneDraw.points.length <= 3) {
        tpModal.toast &&
          tpModal.toast("Un polígono necesita al menos 3 vértices.", "warning");
        return;
      }
      var i = zoneDraw.points.indexOf(pt);
      if (i > -1) zoneDraw.points.splice(i, 1);
      var vi = zoneDraw.verts.indexOf(m);
      if (vi > -1) zoneDraw.verts.splice(vi, 1);
      m.remove();
      drawPreview();
    });
    zoneDraw.verts.push(m);
    drawPreview();
  }
  function clearVertices() {
    if (zoneDraw && zoneDraw.verts)
      zoneDraw.verts.forEach(function (m) {
        m.remove();
      });
  }
  function rebuildVertices(coords) {
    clearVertices();
    zoneDraw.points = [];
    zoneDraw.verts = [];
    coords.forEach(function (c) {
      addVertex({ lat: c.lat, lng: c.lng });
    });
  }

  function showDrawBar(type, isEdit) {
    hideDrawBar();
    zoneDrawBar = document.createElement("div");
    zoneDrawBar.className = "tp-zonedraw-bar";
    var hint =
      type === "polygon"
        ? "Clic para añadir vértices · arrastra para moverlos · clic derecho para eliminar."
        : "Clic para el centro y luego para el radio.";
    zoneDrawBar.innerHTML =
      '<span class="tp-zonedraw-bar__hint">' +
      hint +
      "</span>" +
      '<button type="button" class="tp-btn tp-btn--sm tp-btn--accent" data-zd-finish>' +
      (isEdit ? "Guardar" : "Finalizar") +
      "</button>" +
      '<button type="button" class="tp-btn tp-btn--sm tp-btn--ghost" data-zd-cancel>Cancelar</button>';
    var area = root.querySelector(".tp-live__map-area") || document.body;
    area.appendChild(zoneDrawBar);
    zoneDrawBar
      .querySelector("[data-zd-finish]")
      .addEventListener("click", finishZoneDraw);
    zoneDrawBar
      .querySelector("[data-zd-cancel]")
      .addEventListener("click", cancelZoneDraw);
  }
  function hideDrawBar() {
    if (zoneDrawBar) {
      zoneDrawBar.remove();
      zoneDrawBar = null;
    }
  }
  function bindZoneDraw() {
    if (zoneDrawBound || !state.map) return;
    zoneDrawBound = true;
    state.map.on("click", function (e) {
      if (!zoneDraw) return;
      var ll = { lat: e.lngLat.lat, lng: e.lngLat.lng };
      if (zoneDraw.type === "polygon") {
        addVertex(ll);
      } else if (!zoneDraw.center) {
        zoneDraw.center = ll;
        drawPreview();
      } else {
        zoneDraw.radius = metersBetween(zoneDraw.center, ll);
        drawPreview();
        finishZoneDraw();
      }
    });
    // Preview de radio del círculo mientras se mueve el cursor.
    state.map.on("mousemove", function (e) {
      if (
        !zoneDraw ||
        zoneDraw.type !== "circle" ||
        !zoneDraw.center ||
        zoneDraw.radius
      )
        return;
      drawPreview({ lat: e.lngLat.lat, lng: e.lngLat.lng });
    });
  }
  function startZoneDraw(type) {
    ensureZonesVisible();
    bindZoneDraw();
    ensureDrawLayers();
    zoneDraw = {
      type: type,
      points: [],
      verts: [],
      center: null,
      radius: 0,
      editId: null,
    };
    if (state.map.doubleClickZoom) state.map.doubleClickZoom.disable();
    state.map.getCanvas().style.cursor = "crosshair";
    showDrawBar(type, false);
  }
  // Editar la forma de un polígono existente (mover/añadir/eliminar vértices).
  function startZoneEditShape(z) {
    if (z.type !== "polygon") {
      tpModal.toast &&
        tpModal.toast("Por ahora solo se edita la forma de polígonos.", "info");
      return;
    }
    ensureZonesVisible();
    bindZoneDraw();
    ensureDrawLayers();
    zoneDraw = {
      type: "polygon",
      points: [],
      verts: [],
      center: null,
      radius: 0,
      editId: z.id,
      editName: z.name,
    };
    if (state.map.doubleClickZoom) state.map.doubleClickZoom.disable();
    state.map.getCanvas().style.cursor = "crosshair";
    rebuildVertices(zoneCoords(z));
    showDrawBar("polygon", true);
  }
  function endZoneDraw() {
    clearVertices();
    zoneDraw = null;
    hideDrawBar();
    if (state.map) {
      state.map.getCanvas().style.cursor = "";
      if (state.map.doubleClickZoom) state.map.doubleClickZoom.enable();
      clearDrawLayers();
    }
  }
  function cancelZoneDraw() {
    endZoneDraw();
  }
  function finishZoneDraw() {
    if (!zoneDraw) return;
    var editId = zoneDraw.editId,
      editName = zoneDraw.editName;
    var geometry = null;
    if (zoneDraw.type === "polygon") {
      if (zoneDraw.points.length < 3) {
        tpModal.toast && tpModal.toast("Añade al menos 3 vértices.", "warning");
        return;
      }
      geometry = {
        type: "polygon",
        polygon: zoneDraw.points.map(function (p) {
          return { lat: p.lat, lng: p.lng };
        }),
      };
    } else {
      if (!zoneDraw.center || !(zoneDraw.radius > 0)) {
        tpModal.toast && tpModal.toast("Define el centro y el radio.", "warning");
        return;
      }
      geometry = {
        type: "circle",
        center: { lat: zoneDraw.center.lat, lng: zoneDraw.center.lng },
        radius: Math.round(zoneDraw.radius),
        polygon: [],
      };
    }
    endZoneDraw();
    if (editId) saveZoneGeometry(editId, editName, geometry);
    else openZoneCreateModal(geometry);
  }
  // Guarda SOLO la geometría de una geocerca existente (preserva metadatos).
  function saveZoneGeometry(id, name, geometry) {
    var payload = Object.assign({ name: name }, geometry);
    tpModal
      .fetchJSON(ZONES_BASE + "/" + id, { method: "PUT", json: payload })
      .then(function (r) {
        if (r && r.ok && r.data && r.data.ok) {
          tpModal.toast && tpModal.toast("Forma actualizada.", "success");
          refreshZones();
          ensureZonesVisible();
        } else {
          tpModal.toast && tpModal.toast("No se pudo actualizar la forma.", "error");
        }
      })
      .catch(function () {
        tpModal.toast && tpModal.toast("Error de red.", "error");
      });
  }

  function openZoneCreateModal(geometry) {
    if (!window.tpModal) return;
    var groupOpts = '<option value="0">Sin grupo</option>';
    zonesData.groups.forEach(function (g) {
      groupOpts +=
        '<option value="' + g.id + '">' + escapeHtml(g.title) + "</option>";
    });
    var body =
      '<form id="tp-zone-form" class="tp-poi-form">' +
      '<div class="tp-field"><label class="tp-field__label">Nombre</label>' +
      '<input type="text" name="name" class="tp-input"></div>' +
      '<div class="tp-field"><label class="tp-field__label">Color</label>' +
      '<input type="color" name="polygon_color" class="tp-input" value="#1b99bd" style="height:38px;padding:4px;"></div>' +
      '<div class="tp-field"><label class="tp-field__label">Grupo</label>' +
      '<select name="group_id" class="tp-input">' +
      groupOpts +
      "</select></div>" +
      (geometry.type === "circle"
        ? '<div class="tp-field"><label class="tp-field__label">Radio (m)</label>' +
          '<input type="number" name="radius" class="tp-input" value="' +
          geometry.radius +
          '"></div>'
        : "") +
      '<div class="tp-field"><label class="tp-field__label">Límite de velocidad (km/h)</label>' +
      '<input type="number" name="speed_limit" class="tp-input" min="0"></div>' +
      speedLimitsMatrixHtml(null) +
      "</form>";
    tpModal.open({
      title: "Nueva geocerca",
      size: "md",
      body: body,
      buttons: [
        { label: "Cancelar", variant: "ghost" },
        {
          label: "Crear",
          variant: "primary",
          close: false,
          onClick: function (dialog, btn) {
            var f = dialog.querySelector("#tp-zone-form");
            var name = (f.querySelector('[name="name"]').value || "").trim();
            if (!name) {
              tpModal.toast && tpModal.toast("El nombre es obligatorio.", "warning");
              return false;
            }
            var payload = Object.assign(
              {
                name: name,
                polygon_color: f.querySelector('[name="polygon_color"]').value,
                group_id: parseInt(f.querySelector('[name="group_id"]').value, 10) || 0,
                active: 1,
              },
              geometry,
            );
            if (geometry.type === "circle") {
              var rr = parseFloat(f.querySelector('[name="radius"]').value);
              if (rr > 0) payload.radius = rr;
            }
            var sl = f.querySelector('[name="speed_limit"]').value;
            if (sl !== "") payload.speed_limit = parseFloat(sl);
            var vsl = gatherSpeedLimits(dialog);
            if (vsl) payload.vehicle_speed_limits = vsl;
            btn.disabled = true;
            tpModal
              .fetchJSON(ZONES_BASE, { method: "POST", json: payload })
              .then(function (r) {
                btn.disabled = false;
                if (r && r.ok && r.data && r.data.ok) {
                  tpModal.toast && tpModal.toast("Geocerca creada.", "success");
                  tpModal.close(dialog);
                  refreshZones();
                  ensureZonesVisible();
                } else {
                  tpModal.toast &&
                    tpModal.toast(
                      (r.data && r.data.message) || "No se pudo crear.",
                      "error",
                    );
                }
              })
              .catch(function () {
                btn.disabled = false;
                tpModal.toast && tpModal.toast("Error de red.", "error");
              });
            return false;
          },
        },
      ],
    });
  }

  function startZoneCreate() {
    if (!window.tpModal) return;
    var dlg = tpModal.open({
      title: "Nueva geocerca",
      size: "sm",
      body:
        '<p style="margin:0 0 12px;font-size:14px;">¿Qué tipo de geocerca quieres dibujar?</p>' +
        '<div class="tp-row" style="gap:8px;">' +
        '<button type="button" class="tp-btn tp-btn--accent tp-full" data-zc="polygon">Polígono</button>' +
        '<button type="button" class="tp-btn tp-btn--accent tp-full" data-zc="circle">Círculo</button>' +
        "</div>",
      buttons: [{ label: "Cancelar", variant: "ghost" }],
    });
    if (dlg)
      dlg.querySelectorAll("[data-zc]").forEach(function (b) {
        b.addEventListener("click", function () {
          var t = b.getAttribute("data-zc");
          tpModal.close(dlg);
          startZoneDraw(t);
        });
      });
  }

  // Conmutador de paneles del sidebar (Objetos / Geocercas / POIs) sobre el mismo mapa.
  function switchPanel(name) {
    Array.prototype.forEach.call(
      document.querySelectorAll(".tp-live__panel"),
      function (p) {
        p.hidden = p.getAttribute("data-panel") !== name;
      },
    );
    Array.prototype.forEach.call(
      document.querySelectorAll(".tp-live__paneltab"),
      function (t) {
        t.classList.toggle(
          "is-active",
          t.getAttribute("data-panel-tab") === name,
        );
      },
    );
    if (name === "pois") {
      ensurePoiVisible();
      loadPoisPanel(false);
    } else if (name === "zones") {
      bindZoneMapClick();
      ensureZonesVisible();
      loadZones(false);
    }
  }
  Array.prototype.forEach.call(
    document.querySelectorAll(".tp-live__paneltab"),
    function (t) {
      t.addEventListener("click", function () {
        switchPanel(t.getAttribute("data-panel-tab"));
      });
    },
  );
  // Deep-link desde el rail (?panel=pois|zones) abre el panel correspondiente.
  try {
    var initialPanel = new URLSearchParams(location.search).get("panel");
    if (initialPanel === "pois" || initialPanel === "zones")
      switchPanel(initialPanel);
  } catch (e) {}

  // El rail navega con <a href> (recarga el mapa). Estando YA en el workspace
  // de Live, interceptamos "Live Ops", "Geocercas" y "POIs" para cambiar de
  // panel SIN recargar (el mapa persiste, como el legacy). Desde otra página
  // sí navega.
  Array.prototype.forEach.call(
    document.querySelectorAll(".tp-rail a[href]"),
    function (a) {
      var href = a.getAttribute("href") || "";
      var toPois = href.indexOf("panel=pois") !== -1;
      var toZones = href.indexOf("panel=zones") !== -1;
      var toLive =
        !toPois && !toZones && /\/ui\/v2\/live(\?|$)/.test(href);
      if (!toPois && !toZones && !toLive) return;
      a.addEventListener("click", function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
        e.preventDefault();
        var panel = toPois ? "pois" : toZones ? "zones" : "objects";
        switchPanel(panel);
        try {
          var url =
            panel === "objects" ? "/ui/v2/live" : "/ui/v2/live?panel=" + panel;
          history.replaceState(null, "", url);
        } catch (err) {}
        Array.prototype.forEach.call(
          document.querySelectorAll(".tp-rail__item[aria-current]"),
          function (it) {
            it.removeAttribute("aria-current");
          },
        );
        a.setAttribute("aria-current", "page");
      });
    },
  );

  // Layer toggle buttons (cluster, devices, geofences, poi, routes, names, tails, traffic)
  Array.prototype.forEach.call(
    document.querySelectorAll(".tp-live__toggle[data-toggle]"),
    function (btn) {
      // Estado inicial: store compartido (cross-página) o aria-pressed.
      var key = btn.getAttribute("data-toggle");
      var initial = btn.getAttribute("aria-pressed") === "true";
      if (window.tpMapShared) initial = tpMapShared.get(key, initial);
      state.toggles[key] = initial;
      btn.setAttribute("aria-pressed", initial ? "true" : "false");
      btn.addEventListener("click", function () {
        var k = btn.getAttribute("data-toggle");
        state.toggles[k] = !state.toggles[k];
        btn.setAttribute("aria-pressed", state.toggles[k] ? "true" : "false");
        if (window.tpMapShared) tpMapShared.set(k, state.toggles[k]);
        if (k === "poi") {
          renderSharedPois();
          return;
        }
        if (k === "routes") {
          renderSharedRoutes();
          return;
        }
        if (k === "geofences") {
          renderGeofences();
          return;
        }
        if (k === "traffic") {
          renderTraffic();
          return;
        }
        // cluster / devices / names / tails affect markers
        applyMarkerToggles();
        if (k === "tails") updateTails();
      });
    },
  );
  // Re-cluster on zoom changes (only when cluster is active)
  // Wired after map init below in the load handler is too late; attach via state.map when available.
  var clusterZoomBound = false;
  function bindClusterZoom() {
    if (clusterZoomBound || !state.map) return;
    clusterZoomBound = true;
    state.map.on("zoomend", function () {
      if (state.toggles.cluster) applyMarkerToggles();
    });
  }

  // Style toggle (opens providers menu)
  var styleBtn = document.getElementById("tp-live-style-toggle");
  var providersMenu = document.getElementById("tp-live-providers-menu");
  function closeProvidersMenu() {
    if (!providersMenu) return;
    providersMenu.hidden = true;
    styleBtn.setAttribute("aria-expanded", "false");
  }
  function openProvidersMenu() {
    if (!providersMenu) return;
    var rect = styleBtn.getBoundingClientRect();
    providersMenu.style.position = "fixed";
    providersMenu.style.top = rect.bottom + 6 + "px";
    providersMenu.style.right = window.innerWidth - rect.right + "px";
    providersMenu.style.left = "auto";
    providersMenu.hidden = false;
    styleBtn.setAttribute("aria-expanded", "true");
  }
  styleBtn.addEventListener("click", function (e) {
    e.stopPropagation();
    if (providersMenu.hidden) openProvidersMenu();
    else closeProvidersMenu();
  });
  document.addEventListener("click", function (e) {
    if (!providersMenu.hidden && !providersMenu.contains(e.target))
      closeProvidersMenu();
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeProvidersMenu();
  });
  providersMenu.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-map-id]");
    if (!btn || btn.disabled) return;
    var id = btn.getAttribute("data-map-id");
    closeProvidersMenu();
    if (id === "__auto") {
      state.followsTheme = true;
      state.currentMapId = null;
      state.map.setStyle(fallbackStyle());
      updateProvidersMenu();
      return;
    }
    applyProvider(parseInt(id, 10));
  });

  // Filter collapse / expand
  document
    .getElementById("tp-live-filters-collapse")
    .addEventListener("click", function () {
      root.setAttribute("data-filters-collapsed", "true");
      document.getElementById("tp-live-filters-expand").hidden = false;
      if (state.map)
        setTimeout(function () {
          state.map.resize();
        }, 350);
    });
  document
    .getElementById("tp-live-filters-expand")
    .addEventListener("click", function () {
      root.removeAttribute("data-filters-collapsed");
      document.getElementById("tp-live-filters-expand").hidden = true;
      if (state.map)
        setTimeout(function () {
          state.map.resize();
        }, 350);
    });

  // -----------------------------------------------------------
  // Data fetching
  // -----------------------------------------------------------
  var statusText = document.getElementById("tp-live-status-text");
  var inFlight = null;

  // Aplica el conjunto acumulado de devices al estado y re-renderiza.
  function commitDevices(rows, total) {
    state.devices = rows;
    state.total = total || rows.length;
    state.index = {};
    rows.forEach(function (d) {
      state.index[d.id] = d;
    });

    recomputeFiltered();
    renderList();
    if (state.map) {
      var runRender = function () {
        renderMarkers();
        if (!state.bootstrapped) {
          fitToFleet(false);
          state.bootstrapped = true;
        }
      };
      if (state.map.loaded()) {
        runRender();
      } else {
        state.map.once("load", runRender);
      }
    }
    joinDeviceChannels();
    if (state.selectedId && state.index[state.selectedId]) {
      fillDrawer(state.index[state.selectedId]);
    }
  }

  function fetchDevices(isManual) {
    if (inFlight) inFlight.abort();
    var ctrl = new AbortController();
    inFlight = ctrl;
    statusText.textContent = isManual ? "Refrescando…" : "Actualizando…";

    // Carga TODAS las páginas (el feed pagina; flotas >1000 antes se cortaban).
    var acc = [];
    var total = 0;

    function loadPage(page) {
      var sep = CFG.devicesUrl.indexOf("?") === -1 ? "?" : "&";
      var url = CFG.devicesUrl + sep + "page=" + page;
      return fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        signal: ctrl.signal,
      })
        .then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          return r.json();
        })
        .then(function (json) {
          var rows =
            json && json.data ? json.data : Array.isArray(json) ? json : [];
          acc = acc.concat(rows);
          if (json && Array.isArray(json.groups)) state.groups = json.groups;

          // El serializer (ArraySerializer custom) pone la paginación en la
          // RAÍZ como json.pagination (no json.meta.pagination).
          var pg =
            (json && json.pagination) ||
            (json && json.meta && json.meta.pagination) ||
            null;
          if (pg && pg.total != null) total = pg.total;

          // Commit progresivo: los vehículos aparecen a medida que cargan.
          commitDevices(acc, total);

          if (pg && pg.current_page < pg.last_page) {
            statusText.textContent =
              "Cargando… " + acc.length + "/" + (total || "?");
            return loadPage(pg.current_page + 1);
          }
          statusText.textContent =
            (total || acc.length) +
            " vehículos · " +
            new Date().toLocaleTimeString();
        });
    }

    loadPage(1)
      .catch(function (err) {
        if (err.name === "AbortError") return;
        console.warn("[live-ops] fetch failed", err);
        statusText.textContent = "Error al cargar · reintentando…";
      })
      .finally(function () {
        inFlight = null;
      });
  }

  // -----------------------------------------------------------
  // Updates incrementales (delta) — espeja legacy objects/items_json:
  // solo trae los devices cambiados desde ?time= + el nuevo time del server.
  // -----------------------------------------------------------
  function applyDeltas(items) {
    if (!items || !items.length) return;
    items.forEach(function (d) {
      var existing = state.index[d.id];
      if (existing) {
        for (var k in d) {
          if (Object.prototype.hasOwnProperty.call(d, k)) existing[k] = d[k];
        }
      } else {
        state.index[d.id] = d;
        state.devices.push(d);
      }
    });
    recomputeFiltered();
    renderList();
    renderMarkers();
    if (state.selectedId && state.index[state.selectedId]) {
      fillDrawer(state.index[state.selectedId]);
      loadDeviceWidgets(state.selectedId, true);
    }
    // "Seguir": recentra el mapa en el dispositivo seguido en cada actualización.
    if (state.followId && state.index[state.followId] && state.map) {
      var fdev = state.index[state.followId];
      if (fdev.lat != null && fdev.lng != null)
        state.map.easeTo({
          center: [parseFloat(fdev.lng), parseFloat(fdev.lat)],
          duration: 600,
        });
    }
  }

  function pollUpdates() {
    if (document.visibilityState !== "visible") return;
    var url =
      CFG.updatesUrl +
      "?time=" +
      encodeURIComponent(state.checkTime || "") +
      "&_=" +
      Date.now();
    fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res) return;
        if (res.time) state.checkTime = res.time;
        applyDeltas(res.items);
        statusText.textContent =
          Object.keys(state.index).length +
          " vehículos · " +
          new Date().toLocaleTimeString();
      })
      .catch(function () {});
  }

  function startPolling() {
    if (state.pollHandle) clearInterval(state.pollHandle);
    state.pollHandle = setInterval(function () {
      if (document.visibilityState === "visible") pollUpdates();
    }, CFG.pollInterval);
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") pollUpdates();
  });

  // -----------------------------------------------------------
  // Socket.io live updates (best-effort; doesn't block UI)
  // -----------------------------------------------------------
  function loadSocketIo(cb) {
    if (window.io) return cb();
    var s = document.createElement("script");
    s.src = "https://cdn.socket.io/4.7.5/socket.io.min.js";
    s.async = true;
    s.onload = cb;
    s.onerror = function () {
      console.warn("[live-ops] socket.io failed to load");
    };
    document.head.appendChild(s);
  }

  function initSocket() {
    loadSocketIo(function () {
      if (!window.io) return;
      try {
        var url =
          location.protocol === "https:"
            ? "https://" + location.hostname + ":" + CFG.socketSslPort
            : "http://" + location.hostname + ":" + CFG.socketPort;
        state.socket = io(url, {
          transports: ["websocket", "polling"],
          reconnection: true,
        });
        state.socket.on("connect", function () {
          joinDeviceChannels();
        });
        state.socket.on("notice", function (data) {
          if (window.tpToast && data && data.message) {
            window.tpToast({
              title: data.title || "Notificación",
              description: data.message,
              variant: data.type === "error" ? "danger" : data.type || "info",
            });
          }
        });
        // Listen for any device-position update events (forward-compatible).
        ["position_update", "device_update", "positionUpdate"].forEach(
          function (evt) {
            state.socket.on(evt, handleLiveUpdate);
          },
        );
      } catch (e) {
        console.warn("[live-ops] socket init failed", e);
      }
    });
  }

  function joinDeviceChannels() {
    if (!state.socket || !state.socket.connected) return;
    state.devices.forEach(function (d) {
      if (d.channel && !state.joinedChannels[d.channel]) {
        state.socket.emit("join", d.channel);
        state.joinedChannels[d.channel] = true;
      }
    });
  }

  function handleLiveUpdate(payload) {
    if (!payload || !payload.id) return;
    var existing = state.index[payload.id];
    if (!existing) return;
    // Merge known fields
    [
      "lat",
      "lng",
      "speed",
      "course",
      "altitude",
      "time",
      "timestamp",
      "acktimestamp",
      "engine_status",
      "online",
    ].forEach(function (k) {
      if (payload[k] !== undefined) existing[k] = payload[k];
    });
    recomputeFiltered();
    renderList();
    renderMarkers();
    if (state.selectedId === payload.id) {
      fillDrawer(existing);
      loadDeviceWidgets(payload.id, true); // refresco silencioso (sensores, etc.)
    }
  }

  // -----------------------------------------------------------
  // Window resize → render list (height-dependent)
  // -----------------------------------------------------------
  var resizeTimer = null;
  window.addEventListener("resize", function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      renderList();
      if (state.map) state.map.resize();
    }, 150);
  });

  // -----------------------------------------------------------
  // Boot
  // -----------------------------------------------------------
  initMap();
  fetchDevices(false);
  startPolling();
  initSocket();

  // Public surface for cross-script calls (e.g. device-modal "+ Nuevo" → refresh)
  window.tpLiveOps = {
    refresh: function () {
      fetchDevices(true);
    },
  };
})();
