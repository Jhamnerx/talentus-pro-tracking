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
    zonesUrl: root.dataset.zonesUrl || null,
    socketPort: parseInt(root.dataset.socketPort, 10) || 3000,
    socketSslPort: parseInt(root.dataset.socketSslPort, 10) || 3001,
    pollInterval: parseInt(root.dataset.pollInterval, 10) || 15000,
    rowHeight: 56,
    rowGap: 2,
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
    devices: [], // raw devices array (latest fetch)
    index: {}, // id → device
    markers: {}, // id → maplibregl.Marker
    markerEls: {}, // id → DOM element
    filtered: [], // current filtered + sorted view
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

  // -----------------------------------------------------------
  // Status classification (operational, not legacy)
  // -----------------------------------------------------------
  function classify(d) {
    if (!d || d.lat == null || d.lng == null) return "offline";
    if (d.online === "offline" || d.online === "expired") return "offline";
    var nowSec = Math.floor(Date.now() / 1000);
    var lastSeen = parseInt(d.acktimestamp || d.timestamp || 0, 10);
    if (lastSeen && nowSec - lastSeen > CFG.offlineThresholdSec)
      return "offline";
    if ((d.speed || 0) > 1) return "moving";
    if (d.engine_status === true || d.engine_status === "on") return "idle";
    return "stopped";
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
      if (state.devices.length) {
        renderMarkers();
        fitToFleet(false);
      }
      // Re-apply overlays after style swaps (basemap changes wipe sources)
    });
    state.map.on("styledata", function () {
      if (state.toggles.geofences) renderGeofences();
      if (state.toggles.traffic) renderTraffic();
      if (state.toggles.tails) updateTails();
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
  function buildMarkerEl(device) {
    var tpl = document.getElementById("tp-live-marker-template");
    var el = tpl.content.firstElementChild.cloneNode(true);
    var status = classify(device);
    el.setAttribute("data-status", status);
    el.setAttribute("data-id", device.id);
    var hasHeading = (device.speed || 0) > 1 && device.course != null;
    if (!hasHeading) el.setAttribute("data-no-heading", "true");
    var arrow = el.querySelector(".tp-live-marker__arrow");
    if (arrow && hasHeading) {
      arrow.style.transform =
        "translateX(-50%) rotate(" + (parseFloat(device.course) || 0) + "deg)";
      arrow.style.transformOrigin = "50% 14px";
    }
    var label = el.querySelector(".tp-live-marker__label");
    if (label)
      label.textContent = device.plate_number || device.name || "#" + device.id;
    el.addEventListener("click", function (e) {
      e.stopPropagation();
      selectDevice(device.id, true);
    });
    return el;
  }

  function renderMarkers() {
    if (!state.map) return;
    var seen = {};
    state.filtered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      seen[d.id] = true;
      var lngLat = [parseFloat(d.lng), parseFloat(d.lat)];
      if (state.markers[d.id]) {
        // Update position + status + heading
        state.markers[d.id].setLngLat(lngLat);
        var el = state.markerEls[d.id];
        var status = classify(d);
        el.setAttribute("data-status", status);
        var hasHeading = (d.speed || 0) > 1 && d.course != null;
        el.toggleAttribute("data-no-heading", !hasHeading);
        var arrow = el.querySelector(".tp-live-marker__arrow");
        if (arrow && hasHeading) {
          arrow.style.transform =
            "translateX(-50%) rotate(" + (parseFloat(d.course) || 0) + "deg)";
        }
      } else {
        var el2 = buildMarkerEl(d);
        var marker = new maplibregl.Marker({ element: el2, anchor: "center" })
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
      stopped: 0,
    };
    state.filtered = state.devices.filter(function (d) {
      var cls = classify(d);
      if (cls === "moving") counts.moving++;
      else if (cls === "idle") counts.idle++;
      else if (cls === "offline") counts.offline++;
      else counts.stopped++;
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
  }

  // -----------------------------------------------------------
  // Virtualized list rendering
  // -----------------------------------------------------------
  var listWrap = root.querySelector(".tp-live__list-wrap");
  var listEl = document.getElementById("tp-live-list");
  var emptyEl = root.querySelector(".tp-live__empty");

  function rowTemplate(d, top) {
    var status = classify(d);
    var speed = Math.round(parseFloat(d.speed) || 0);
    var ago = fmtTimeAgo(d.acktimestamp || d.timestamp);
    var meta = d.plate_number || "";
    if (d.imei && !meta) meta = d.imei;
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
      (speed === 0 ? " tp-live__row-speed--zero" : "") +
      '">' +
      speed +
      " km/h</span>" +
      '    <span class="tp-live__row-time">' +
      ago +
      "</span>" +
      "  </div>" +
      "</div>"
    );
  }

  function renderList() {
    var rows = state.filtered;
    var rowH = CFG.rowHeight + CFG.rowGap;
    var total = rows.length;
    var totalH = total * rowH;
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
    listEl.style.height = totalH + "px";

    var scrollTop = listWrap.scrollTop;
    var viewH = listWrap.clientHeight;
    var first = Math.max(0, Math.floor(scrollTop / rowH) - CFG.bufferRows);
    var last = Math.min(
      total,
      Math.ceil((scrollTop + viewH) / rowH) + CFG.bufferRows,
    );

    var html = "";
    for (var i = first; i < last; i++) {
      html += rowTemplate(rows[i], i * rowH);
    }
    listEl.innerHTML = html;
  }

  // Click on a row → select
  listEl.addEventListener("click", function (e) {
    var row = e.target.closest(".tp-live__row");
    if (!row || row.classList.contains("tp-live__row--skeleton")) return;
    var id = parseInt(row.getAttribute("data-id"), 10);
    if (id) selectDevice(id, true);
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
    if (state.selectedId && state.markerEls[state.selectedId]) {
      state.markerEls[state.selectedId].removeAttribute("data-selected");
    }
    state.selectedId = null;
    // Update list selection
    var sel = listEl.querySelector('[aria-selected="true"]');
    if (sel) sel.removeAttribute("aria-selected");
  }

  var widgetsBox = null;
  function loadDeviceWidgets(id) {
    widgetsBox = widgetsBox || document.getElementById("tp-drawer-widgets");
    if (!widgetsBox) return;
    widgetsBox.innerHTML =
      '<div class="tp-text-xs tp-text-muted" style="padding:8px 2px;">Cargando información…</div>';
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
        widgetsBox.innerHTML =
          res && res.ok && res.html
            ? res.html
            : '<div class="tp-text-xs tp-text-muted" style="padding:8px 2px;">Sin widgets disponibles.</div>';
      })
      .catch(function () {
        if (state.selectedId != id) return;
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

  // Layer toggle buttons (cluster, devices, geofences, poi, names, tails, traffic)
  Array.prototype.forEach.call(
    document.querySelectorAll(".tp-live__toggle[data-toggle]"),
    function (btn) {
      // Sync initial state from aria-pressed
      var key = btn.getAttribute("data-toggle");
      state.toggles[key] = btn.getAttribute("aria-pressed") === "true";
      btn.addEventListener("click", function () {
        var k = btn.getAttribute("data-toggle");
        state.toggles[k] = !state.toggles[k];
        btn.setAttribute("aria-pressed", state.toggles[k] ? "true" : "false");
        if (k === "poi") {
          if (state.toggles[k] && window.tpModal && tpModal.toast) {
            tpModal.toast("POIs: configura tus puntos en la sección Admin.", {
              type: "info",
            });
          }
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

  function fetchDevices(isManual) {
    if (inFlight) inFlight.abort();
    var ctrl = new AbortController();
    inFlight = ctrl;
    statusText.textContent = isManual ? "Refrescando…" : "Actualizando…";

    fetch(CFG.devicesUrl, {
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
        state.devices = rows;
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

        statusText.textContent =
          rows.length + " vehículos · " + new Date().toLocaleTimeString();
        joinDeviceChannels();

        // If selected device exists in new data, refresh drawer.
        if (state.selectedId && state.index[state.selectedId]) {
          fillDrawer(state.index[state.selectedId]);
        }
      })
      .catch(function (err) {
        if (err.name === "AbortError") return;
        console.warn("[live-ops] fetch failed", err);
        statusText.textContent = "Error al cargar · reintentando…";
      })
      .finally(function () {
        inFlight = null;
      });
  }

  function startPolling() {
    if (state.pollHandle) clearInterval(state.pollHandle);
    state.pollHandle = setInterval(function () {
      if (document.visibilityState === "visible") fetchDevices(false);
    }, CFG.pollInterval);
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") fetchDevices(false);
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
    if (state.selectedId === payload.id) fillDrawer(existing);
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
