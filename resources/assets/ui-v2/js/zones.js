/* =====================================================
   TalentusPro · Zones & Routes (Leaflet edition)
   - Leaflet 1.9 + Leaflet.draw 1.0 + Leaflet.GeometryUtil
   - Renders saved geofences as polygons/circles
   - Inline editor uses L.Draw for create + layer.editing for edit
   - Sidebar list with search / type / group filters
   ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-zones");
  if (!root) return;

  /* ----- Root config ----- */
  var DATA_URL = root.getAttribute("data-data-url");
  var META_URL = root.getAttribute("data-meta-url");
  var STORE_URL = root.getAttribute("data-store-url");
  var BASE_URL = root.getAttribute("data-base-url") || "/talentus/api/zones";
  var CAN_EDIT = root.getAttribute("data-can-edit") === "1";
  var CAN_CREATE = root.getAttribute("data-can-create") === "1";
  var IMPORT_URL = root.getAttribute("data-import-url") || "";
  var EXPORT_ITEMS_URL = root.getAttribute("data-export-items-url") || "";
  var EXPORT_URL = root.getAttribute("data-export-url") || "";

  var EXPORT_TYPES = (function () {
    try {
      return (
        JSON.parse(
          document.getElementById("tp-zones-export-types").textContent,
        ) || {}
      );
    } catch (e) {
      return {};
    }
  })();

  var META_CACHE = null;

  var state = {
    items: [],
    groups: [],
    filtered: [],
    q: "",
    type: "",
    groupId: "",
    activeId: null,
    map: null,
    zonesLayer: null, // L.featureGroup of saved zones
    activeBasemap: "osm",
    basemapLayer: null,
    editor: null, // {id, isEdit, type, drawer, draftLayer, draft}
  };

  /* ----- Helpers ----- */
  function $(s, c) {
    return (c || document).querySelector(s);
  }
  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  /* ----- Parse center: accepts "lat,lng" string, JSON object, or plain object ----- */
  function parseLatLng(s) {
    if (!s) return null;
    if (typeof s === "object") {
      var lat = s.lat != null ? Number(s.lat) : null;
      var lng =
        s.lng != null ? Number(s.lng) : s.lon != null ? Number(s.lon) : null;
      if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) return null;
      return { lat: lat, lng: lng };
    }
    var str = String(s).trim();
    if (str.charAt(0) === "{") {
      try {
        var o = JSON.parse(str);
        if (o && o.lat != null && (o.lng != null || o.lon != null)) {
          return {
            lat: Number(o.lat),
            lng: Number(o.lng != null ? o.lng : o.lon),
          };
        }
      } catch (e) {}
    }
    var parts = str
      .split(/[,\s]+/)
      .map(Number)
      .filter(function (n) {
        return !isNaN(n);
      });
    if (parts.length < 2) return null;
    return { lat: parts[0], lng: parts[1] };
  }

  /* Normalize one item's coordinates to array of {lat,lng} */
  function normCoords(coords) {
    if (!Array.isArray(coords)) return [];
    return coords
      .map(function (p) {
        if (Array.isArray(p)) return { lat: Number(p[0]), lng: Number(p[1]) };
        if (p && p.lat != null && p.lng != null)
          return { lat: Number(p.lat), lng: Number(p.lng) };
        if (p && p.lat != null && p.lon != null)
          return { lat: Number(p.lat), lng: Number(p.lon) };
        return null;
      })
      .filter(Boolean);
  }

  /* ----- Map provider config from server (mirrors app.settings) ----- */
  var MAP_CONFIG = (function () {
    try {
      var el = document.getElementById("tp-zones-map-config");
      if (!el) return null;
      return JSON.parse(el.textContent) || null;
    } catch (e) {
      return null;
    }
  })();
  var KEYS = (MAP_CONFIG && MAP_CONFIG.keys) || {};
  var LOCALE = (MAP_CONFIG && MAP_CONFIG.locale) || "en";
  var LANG_TAG = String(LOCALE).split(/[_-]/)[0] || "en";
  var OPEN_MAPTILES_URL = (MAP_CONFIG && MAP_CONFIG.openmaptilesUrl) || "";

  /* ----- Legacy map registry (key = id from config/maps.php) ------------
     Each entry: { name, factory: () => L.Layer|false }
     factory returns false when the required key/config is missing.
     Heavy variants (Google JS API mutant, Bing plugin, Yandex plugin)
     are intentionally omitted; their no-key Google "≠" tiles (51-57)
     and other plain-tile providers are supported.
     ---------------------------------------------------------------------- */
  var LEGACY_TILES = {
    2: {
      name: "OpenStreetMap",
      factory: function () {
        return L.tileLayer(
          "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
          {
            maxZoom: 19,
            attribution:
              '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
          },
        );
      },
    },
    10: {
      name: "Here Normal",
      factory: function () {
        if (!KEYS.here_api_key) return false;
        var url =
          "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
        if (KEYS.here_political_view)
          url += "&pview=" + KEYS.here_political_view;
        return L.tileLayer(url, {
          resource: "base",
          projection: "mc",
          apiKey: KEYS.here_api_key,
          style: "explore.day",
          size: "256",
          maxZoom: 18,
          lang: LANG_TAG,
          format: "png8",
          attribution:
            'Map &copy; <a href="http://developer.here.com">HERE</a>',
        });
      },
    },
    11: {
      name: "Here Satellite",
      factory: function () {
        if (!KEYS.here_api_key) return false;
        var url =
          "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
        if (KEYS.here_political_view)
          url += "&pview=" + KEYS.here_political_view;
        return L.tileLayer(url, {
          resource: "base",
          projection: "mc",
          apiKey: KEYS.here_api_key,
          style: "satellite.day",
          size: "256",
          maxZoom: 18,
          lang: LANG_TAG,
          format: "png8",
          attribution:
            'Map &copy; <a href="http://developer.here.com">HERE</a>',
        });
      },
    },
    12: {
      name: "Here Hybrid",
      factory: function () {
        if (!KEYS.here_api_key) return false;
        var url =
          "https://maps.hereapi.com/v3/{resource}/{projection}/{z}/{x}/{y}/{format}?apiKey={apiKey}&style={style}&size={size}&lang={lang}";
        if (KEYS.here_political_view)
          url += "&pview=" + KEYS.here_political_view;
        return L.tileLayer(url, {
          resource: "base",
          projection: "mc",
          apiKey: KEYS.here_api_key,
          style: "explore.satellite.day",
          size: "256",
          maxZoom: 18,
          lang: LANG_TAG,
          format: "png8",
          attribution:
            'Map &copy; <a href="http://developer.here.com">HERE</a>',
        });
      },
    },
    14: {
      name: "MapBox Normal",
      factory: function () {
        if (!KEYS.mapbox_access_token) return false;
        return L.tileLayer(
          "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
          {
            id: "streets-v11",
            accessToken: KEYS.mapbox_access_token,
            tileSize: 512,
            zoomOffset: -1,
            maxZoom: 18,
            attribution: "© Mapbox",
          },
        );
      },
    },
    15: {
      name: "MapBox Satellite",
      factory: function () {
        if (!KEYS.mapbox_access_token) return false;
        return L.tileLayer(
          "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
          {
            id: "satellite-v9",
            accessToken: KEYS.mapbox_access_token,
            tileSize: 512,
            zoomOffset: -1,
            maxZoom: 18,
            attribution: "© Mapbox",
          },
        );
      },
    },
    16: {
      name: "MapBox Hybrid",
      factory: function () {
        if (!KEYS.mapbox_access_token) return false;
        return L.tileLayer(
          "https://api.mapbox.com/styles/v1/mapbox/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}",
          {
            id: "satellite-streets-v11",
            accessToken: KEYS.mapbox_access_token,
            tileSize: 512,
            zoomOffset: -1,
            maxZoom: 18,
            attribution: "© Mapbox",
          },
        );
      },
    },
    17: {
      name: "MapTiler Basic",
      factory: function () {
        if (!KEYS.maptiler_key) return false;
        return L.tileLayer(
          "https://api.maptiler.com/maps/basic/{z}/{x}/{y}.png?key=" +
            KEYS.maptiler_key,
          {
            tileSize: 512,
            zoomOffset: -1,
            minZoom: 1,
            maxZoom: 20,
            crossOrigin: true,
            attribution: "© MapTiler © OSM",
          },
        );
      },
    },
    18: {
      name: "MapTiler Streets",
      factory: function () {
        if (!KEYS.maptiler_key) return false;
        return L.tileLayer(
          "https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=" +
            KEYS.maptiler_key,
          {
            tileSize: 512,
            zoomOffset: -1,
            minZoom: 1,
            maxZoom: 20,
            crossOrigin: true,
            attribution: "© MapTiler © OSM",
          },
        );
      },
    },
    19: {
      name: "MapTiler Satellite",
      factory: function () {
        if (!KEYS.maptiler_key) return false;
        return L.tileLayer(
          "https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key=" +
            KEYS.maptiler_key,
          {
            tileSize: 512,
            zoomOffset: -1,
            minZoom: 1,
            maxZoom: 20,
            crossOrigin: true,
            attribution: "© MapTiler © OSM",
          },
        );
      },
    },
    21: {
      name: "OpenMapTiles OSM",
      factory: function () {
        if (!OPEN_MAPTILES_URL) return false;
        return L.tileLayer(
          OPEN_MAPTILES_URL + "/styles/osm-bright/{z}/{x}/{y}.png",
          { minZoom: 1, maxZoom: 20, crossOrigin: true },
        );
      },
    },
    26: {
      name: "TomTom Basic",
      factory: function () {
        if (!KEYS.tomtom_key) return false;
        return L.tileLayer(
          "https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?key=" +
            KEYS.tomtom_key,
          { tileSize: 256, minZoom: 1, maxZoom: 20, attribution: "© TomTom" },
        );
      },
    },
    27: {
      name: "TomTom Satellite",
      factory: function () {
        if (!KEYS.tomtom_key) return false;
        return L.tileLayer(
          "https://api.tomtom.com/map/1/tile/sat/main/{z}/{x}/{y}.jpg?key=" +
            KEYS.tomtom_key,
          { tileSize: 256, minZoom: 1, maxZoom: 19, attribution: "© TomTom" },
        );
      },
    },
    28: {
      name: "Azure Normal",
      factory: function () {
        if (!KEYS.azure_maps_key) return false;
        return L.tileLayer(
          "https://atlas.microsoft.com/map/tile?subscription-key=" +
            KEYS.azure_maps_key +
            "&api-version=2.0&tilesetId=microsoft.base.road&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
            String(LOCALE).replace("_", "-") +
            "&view=Auto",
          {
            tileSize: 256,
            minZoom: 1,
            maxZoom: 21,
            attribution: "© Microsoft, TomTom",
          },
        );
      },
    },
    29: {
      name: "Azure Hybrid",
      factory: function () {
        if (!KEYS.azure_maps_key) return false;
        return L.tileLayer(
          "https://atlas.microsoft.com/map/tile?subscription-key=" +
            KEYS.azure_maps_key +
            "&api-version=2.0&tilesetId=microsoft.base.hybrid.road&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
            String(LOCALE).replace("_", "-") +
            "&view=Auto",
          {
            tileSize: 256,
            minZoom: 1,
            maxZoom: 19,
            attribution: "© Microsoft, TomTom",
          },
        );
      },
    },
    30: {
      name: "Azure Satellite",
      factory: function () {
        if (!KEYS.azure_maps_key) return false;
        return L.tileLayer(
          "https://atlas.microsoft.com/map/tile?subscription-key=" +
            KEYS.azure_maps_key +
            "&api-version=2.0&tilesetId=microsoft.imagery&zoom={z}&x={x}&y={y}&tileSize=256&language=" +
            String(LOCALE).replace("_", "-") +
            "&view=Auto",
          {
            tileSize: 256,
            minZoom: 1,
            maxZoom: 19,
            attribution: "© Microsoft, TomTom",
          },
        );
      },
    },
    51: {
      name: "Google Map - Normal ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=m&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    53: {
      name: "Google Map - Híbrido ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=y&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    54: {
      name: "Google Map - Satelital ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=s&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    55: {
      name: "Google Map - Terreno ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=p&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    56: {
      name: "Google Map - Tráfico Híbrido ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=y,traffic&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    57: {
      name: "Google Map - Tráfico ≠",
      factory: function () {
        return L.tileLayer(
          "https://mt{s}.google.com/vt/lyrs=m,traffic&hl=" +
            LANG_TAG +
            "&x={x}&y={y}&z={z}&s=Ga",
          {
            maxZoom: 21,
            minZoom: 0,
            subdomains: ["0", "1", "2", "3"],
            attribution: "© Google Maps",
          },
        );
      },
    },
    70: {
      name: "Carto",
      factory: function () {
        return L.tileLayer(
          "https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}@2x.png",
          {
            maxZoom: 18,
            minZoom: 2,
            subdomains: "abcd",
            attribution: "© CARTO © OSM",
          },
        );
      },
    },
    71: {
      name: "LocationIQ Streets",
      factory: function () {
        return L.tileLayer(
          "https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key=pk.0f147952a41c555a5b70614039fd148b",
          {
            maxZoom: 18,
            minZoom: 2,
            subdomains: ["a", "b", "c"],
            attribution: "© LocationIQ © OSM",
          },
        );
      },
    },
    72: {
      name: "Carto Dark",
      factory: function () {
        return L.tileLayer(
          "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png",
          {
            maxZoom: 18,
            minZoom: 2,
            subdomains: "abcd",
            attribution: "© CARTO © OSM",
          },
        );
      },
    },
  };

  /* Build the active basemap menu by filtering server-side availability
     and the registry above. Returns { id -> { name, layer } } */
  var BASEMAPS = (function buildBasemaps() {
    var out = {};
    var available =
      MAP_CONFIG && Array.isArray(MAP_CONFIG.available)
        ? MAP_CONFIG.available
        : [];

    if (available.length) {
      available.forEach(function (m) {
        var id = parseInt(m.id, 10);
        var def = LEGACY_TILES[id];
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
    }

    // Always-on fallbacks (no keys required) if nothing came from server
    if (!Object.keys(out).length) {
      ["2", "70", "72"].forEach(function (id) {
        var def = LEGACY_TILES[parseInt(id, 10)];
        if (!def) return;
        var layer = def.factory();
        if (layer) out[id] = { name: def.name, layer: layer };
      });
    }
    return out;
  })();

  function defaultBasemapId() {
    // 1) server-stored user preference
    if (MAP_CONFIG && MAP_CONFIG.mapId && BASEMAPS[String(MAP_CONFIG.mapId)]) {
      return String(MAP_CONFIG.mapId);
    }
    // 2) light/dark auto pick from available
    var dark = document.documentElement.getAttribute("data-theme") === "dark";
    if (dark && BASEMAPS["72"]) return "72";
    if (!dark && BASEMAPS["2"]) return "2";
    // 3) first available
    var keys = Object.keys(BASEMAPS);
    return keys.length ? keys[0] : null;
  }

  function setBasemap(id) {
    if (!state.map) return;
    var sid = String(id);
    var def = BASEMAPS[sid];
    if (!def) return;
    if (state.basemapLayer) state.map.removeLayer(state.basemapLayer);
    state.basemapLayer = def.layer;
    state.basemapLayer.addTo(state.map);
    state.activeBasemap = sid;
    // mark active item in menu
    var menu = document.getElementById("tp-zones-providers-menu");
    if (menu) {
      menu.querySelectorAll("[data-basemap]").forEach(function (b) {
        b.classList.toggle("is-active", b.getAttribute("data-basemap") === sid);
      });
    }
  }

  function renderBasemapMenu() {
    var menu = document.getElementById("tp-zones-providers-menu");
    if (!menu) return;
    var html = "";
    Object.keys(BASEMAPS).forEach(function (id) {
      html +=
        '<button class="tp-menu__item" type="button" role="menuitem" data-basemap="' +
        id +
        '">' +
        escapeHtml(BASEMAPS[id].name) +
        "</button>";
    });
    if (!html) {
      html =
        '<div class="tp-text-muted" style="padding:8px 10px;">Sin mapas disponibles</div>';
    }
    menu.innerHTML = html;
  }

  /* ----- Map setup ----- */
  function initMap() {
    if (typeof L === "undefined") {
      console.warn("[zones] Leaflet not available");
      return;
    }
    state.map = L.map("tp-zones-map", {
      center: [-12.04, -77.04],
      zoom: 5,
      zoomControl: true,
      preferCanvas: true,
    });
    setBasemap(defaultBasemapId());
    state.zonesLayer = L.featureGroup().addTo(state.map);
    renderBasemapMenu();
    // re-mark active after menu render
    setBasemap(state.activeBasemap);

    setTimeout(function () {
      try {
        state.map.invalidateSize();
      } catch (e) {}
    }, 0);
    setTimeout(function () {
      try {
        state.map.invalidateSize();
      } catch (e) {}
    }, 250);
    window.addEventListener("resize", function () {
      if (state.map) state.map.invalidateSize();
    });
  }

  /* ----- Build a Leaflet layer for a saved geofence ----- */
  function buildLayerFor(g) {
    var style = {
      color: g.polygon_color || "#1e88e5",
      weight: g.id === state.activeId ? 3 : 2,
      opacity: g.active ? 1 : 0.55,
      fill: true,
      fillColor: g.polygon_color || "#1e88e5",
      fillOpacity: g.id === state.activeId ? 0.4 : 0.18,
    };
    if (g.type === "circle") {
      var c = parseLatLng(g.center);
      if (!c || !g.radius) return null;
      return L.circle(
        [c.lat, c.lng],
        Object.assign({ radius: Number(g.radius) }, style),
      );
    }
    var coords = normCoords(g.coordinates);
    if (coords.length < 3) return null;
    return L.polygon(
      coords.map(function (p) {
        return [p.lat, p.lng];
      }),
      style,
    );
  }

  function updateZonesLayer() {
    if (!state.zonesLayer) return;
    state.zonesLayer.clearLayers();
    state.filtered.forEach(function (g) {
      var layer = buildLayerFor(g);
      if (!layer) return;
      layer._zoneId = g.id;
      layer.on("click", function () {
        selectZone(g.id);
      });
      state.zonesLayer.addLayer(layer);
    });
  }

  function fitToAll() {
    if (!state.map || !state.zonesLayer) return;
    var b = state.zonesLayer.getBounds();
    if (b && b.isValid())
      state.map.fitBounds(b, { padding: [40, 40], maxZoom: 16 });
  }

  /* ----- Sidebar render ----- */
  function applyFilters() {
    var q = state.q.toLowerCase();
    state.filtered = state.items.filter(function (g) {
      if (state.type && g.type !== state.type) return false;
      if (state.groupId && String(g.group_id) !== String(state.groupId))
        return false;
      if (q && (g.name || "").toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    renderList();
    updateZonesLayer();
  }

  function renderList() {
    var list = document.getElementById("tp-zones-list");
    var count = document.getElementById("tp-zones-count");
    count.textContent = state.filtered.length + " / " + state.items.length;
    if (!state.filtered.length) {
      list.innerHTML =
        '<div class="tp-text-muted tp-text-center" style="padding:20px;">Sin resultados.</div>';
      return;
    }
    var html = "";
    for (var i = 0; i < state.filtered.length; i++) {
      var g = state.filtered[i];
      var cls =
        "tp-zones__item" +
        (g.id === state.activeId ? " is-active" : "") +
        (g.active ? "" : " tp-zones__item-inactive");
      html +=
        '<div class="' +
        cls +
        '" role="option" data-zone-id="' +
        g.id +
        '" tabindex="0">' +
        '<span class="tp-zones__item-swatch" style="background:' +
        escapeHtml(g.polygon_color) +
        '"></span>' +
        '<div class="tp-zones__item-body">' +
        '<div class="tp-zones__item-name" title="' +
        escapeHtml(g.name) +
        '">' +
        escapeHtml(g.name) +
        "</div>" +
        '<div class="tp-zones__item-meta">' +
        (g.type === "circle"
          ? "Círculo · " + (g.radius ? Math.round(g.radius) + " m" : "—")
          : "Polígono · " + (g.coordinates || []).length + " pts") +
        "</div></div></div>";
    }
    list.innerHTML = html;
  }

  function renderGroups() {
    var sel = document.getElementById("tp-zones-group");
    var html = '<option value="">Todos los grupos</option>';
    state.groups.forEach(function (g) {
      html +=
        '<option value="' + g.id + '">' + escapeHtml(g.title) + "</option>";
    });
    sel.innerHTML = html;
  }

  /* ----- Selection / detail panel ----- */
  function selectZone(id) {
    id = parseInt(id, 10);
    state.activeId = id;
    var g = state.items.find(function (x) {
      return x.id === id;
    });
    renderList();
    updateZonesLayer();
    if (!g || !state.map) return;
    showDetail(g);
    // Fit to that layer
    var target = null;
    state.zonesLayer.eachLayer(function (l) {
      if (l._zoneId === id) target = l;
    });
    if (target) {
      var b = target.getBounds ? target.getBounds() : null;
      if (b && b.isValid())
        state.map.fitBounds(b, { padding: [60, 60], maxZoom: 16 });
      else if (target.getLatLng) state.map.setView(target.getLatLng(), 14);
    }
  }

  function showDetail(g) {
    var det = document.getElementById("tp-zones-detail");
    $("#tp-zones-detail-name").textContent = g.name || "—";
    $("#tp-zones-detail-swatch").style.background =
      g.polygon_color || "#1e88e5";
    $("#tp-zones-detail-type").textContent =
      g.type === "circle" ? "Círculo" : "Polígono";
    $("#tp-zones-detail-active").innerHTML = g.active
      ? '<span class="tp-zones__dot tp-zones__dot--on"></span>Activa'
      : '<span class="tp-zones__dot tp-zones__dot--off"></span>Inactiva';
    var grp = state.groups.find(function (x) {
      return x.id === g.group_id;
    });
    $("#tp-zones-detail-group").textContent = grp ? grp.title : "—";
    $("#tp-zones-detail-size").textContent =
      g.type === "circle"
        ? g.radius
          ? Math.round(g.radius) + " m"
          : "—"
        : (g.coordinates || []).length + " vértices";
    $("#tp-zones-detail-speed").textContent = g.speed_limit
      ? g.speed_limit + " km/h"
      : "—";
    var ed = $("#tp-zones-detail-edit");
    if (ed)
      ed.onclick = function () {
        openZoneEditor(g.id);
      };
    var del = $("#tp-zones-detail-del");
    if (del)
      del.onclick = function () {
        deleteZone(g.id);
      };
    det.hidden = false;
  }

  /* ----- Load list ----- */
  function load() {
    return fetch(DATA_URL, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (p) {
        if (!p || !p.ok) return;
        state.items = p.items || [];
        state.groups = p.groups || [];
        renderGroups();
        applyFilters();
        if (state.activeId == null) fitToAll();
      })
      .catch(function (err) {
        console.warn("[zones] load failed", err);
      });
  }

  /* ----- Main UI wiring (top bar / filters) ----- */
  var searchTimer;
  document
    .getElementById("tp-zones-search")
    .addEventListener("input", function (e) {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.q = e.target.value.trim();
        applyFilters();
      }, 200);
    });

  var typeBtns = root.querySelectorAll(".tp-zones__filters .tp-seg__btn");
  typeBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      typeBtns.forEach(function (b) {
        b.classList.remove("is-active");
      });
      btn.classList.add("is-active");
      state.type = btn.getAttribute("data-type") || "";
      applyFilters();
    });
  });

  document
    .getElementById("tp-zones-group")
    .addEventListener("change", function (e) {
      state.groupId = e.target.value;
      applyFilters();
    });

  document
    .getElementById("tp-zones-list")
    .addEventListener("click", function (e) {
      var it = e.target.closest("[data-zone-id]");
      if (!it) return;
      selectZone(it.getAttribute("data-zone-id"));
    });

  document.getElementById("tp-zones-fit").addEventListener("click", fitToAll);
  document
    .getElementById("tp-zones-detail-close")
    .addEventListener("click", function () {
      document.getElementById("tp-zones-detail").hidden = true;
      state.activeId = null;
      renderList();
      updateZonesLayer();
    });

  /* Basemap menu */
  var provBtn = document.getElementById("tp-zones-providers-btn");
  var provMenu = document.getElementById("tp-zones-providers-menu");
  if (provBtn && provMenu) {
    provBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      var open = !provMenu.hidden;
      if (open) {
        provMenu.hidden = true;
        provBtn.setAttribute("aria-expanded", "false");
      } else {
        provMenu.hidden = false;
        provBtn.setAttribute("aria-expanded", "true");
      }
    });
    document.addEventListener("click", function (e) {
      if (provMenu.hidden) return;
      if (provMenu.contains(e.target) || provBtn.contains(e.target)) return;
      provMenu.hidden = true;
      provBtn.setAttribute("aria-expanded", "false");
    });
    provMenu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-basemap]");
      if (!b) return;
      setBasemap(b.getAttribute("data-basemap"));
      provMenu.hidden = true;
      provBtn.setAttribute("aria-expanded", "false");
    });
  }

  /* Theme reactivity */
  new MutationObserver(function () {
    if (!state.map) return;
    // only auto-switch when user has not picked one explicitly
    if (!MAP_CONFIG || !MAP_CONFIG.mapId) {
      var dark = document.documentElement.getAttribute("data-theme") === "dark";
      var target = dark ? "72" : "2";
      if (BASEMAPS[target]) setBasemap(target);
    }
  }).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });

  /* ============================================================
     Native zone create/edit editor (inline panel + L.Draw on main map)
     ============================================================ */
  function loadMeta() {
    if (META_CACHE) return Promise.resolve(META_CACHE);
    return tpModal.fetchJSON(META_URL, { method: "GET" }).then(function (res) {
      if (!res.ok || !res.data || !res.data.ok) throw new Error("meta_failed");
      META_CACHE = res.data;
      return META_CACHE;
    });
  }

  function getCheckedRadio(nodeList) {
    for (var i = 0; i < nodeList.length; i++)
      if (nodeList[i].checked) return nodeList[i].value;
    return "";
  }

  function zoneGeneralPanel(meta, item) {
    var groups = meta.groups || [];
    var devices = meta.devices || [];
    var color = (item && item.polygon_color) || meta.default_color || "#d000df";
    var name = (item && item.name) || "";
    var groupId = item && item.group_id ? item.group_id : "";
    var devId = item && item.device_id ? item.device_id : "";
    var speed = item && item.speed_limit != null ? item.speed_limit : "";
    var active = !item || item.active;
    var grpOpts =
      '<option value="">— Sin grupo —</option>' +
      groups
        .map(function (g) {
          var sel = String(g.id) === String(groupId) ? " selected" : "";
          return (
            '<option value="' +
            g.id +
            '"' +
            sel +
            ">" +
            escapeHtml(g.title) +
            "</option>"
          );
        })
        .join("");
    var deviceField = "";
    if (meta.plugins && meta.plugins.moving_geofence && devices.length) {
      var devOpts =
        '<option value="">— Geocerca fija —</option>' +
        devices
          .map(function (d) {
            var sel = String(d.id) === String(devId) ? " selected" : "";
            return (
              '<option value="' +
              d.id +
              '"' +
              sel +
              ">" +
              escapeHtml(d.name) +
              "</option>"
            );
          })
          .join("");
      deviceField =
        '<div class="tp-field"><label class="tp-field__label">Vehículo (móvil)</label>' +
        '<select class="tp-select" name="device_id">' +
        devOpts +
        "</select>" +
        '<div class="tp-field__hint">Si se asigna, la geocerca se moverá con el vehículo.</div></div>';
    }
    var speedField = "";
    if (meta.plugins && meta.plugins.speed_limit) {
      speedField =
        '<div class="tp-field"><label class="tp-field__label">Límite de velocidad (km/h)</label>' +
        '<input class="tp-input" type="number" min="0" name="speed_limit" value="' +
        escapeHtml(speed) +
        '"></div>';
    }
    return (
      '<div class="tp-field"><label class="tp-field__label">Nombre *</label>' +
      '<input class="tp-input" type="text" name="name" maxlength="80" required value="' +
      escapeHtml(name) +
      '" placeholder="Ej. Patio principal"></div>' +
      '<div class="tp-grid-2">' +
      '<div class="tp-field"><label class="tp-field__label">Grupo</label>' +
      '<select class="tp-select" name="group_id">' +
      grpOpts +
      "</select></div>" +
      '<div class="tp-field"><label class="tp-field__label">Color</label>' +
      '<input class="tp-input" type="color" name="polygon_color" value="' +
      escapeHtml(color) +
      '" style="height:36px; padding:2px;"></div></div>' +
      '<div class="tp-grid-2">' +
      '<div class="tp-field"><label class="tp-field__label">Estado</label>' +
      '<label class="tp-checkbox"><input type="checkbox" name="active"' +
      (active ? " checked" : "") +
      "> <span>Activa</span></label></div>" +
      speedField +
      "</div>" +
      deviceField
    );
  }

  function zoneGeometryPanel(meta, item) {
    var t = (item && item.type) || "polygon";
    var radius = item && item.radius ? Math.round(item.radius) : 100;
    return (
      '<div class="tp-field"><label class="tp-field__label">Tipo *</label>' +
      '<div class="tp-row" style="gap:14px;">' +
      '<label class="tp-checkbox"><input type="radio" name="type" value="polygon"' +
      (t === "polygon" ? " checked" : "") +
      "> <span>Polígono</span></label>" +
      '<label class="tp-checkbox"><input type="radio" name="type" value="circle"' +
      (t === "circle" ? " checked" : "") +
      "> <span>Círculo</span></label>" +
      "</div></div>" +
      '<div class="tp-field" id="tp-zone-circle-row" hidden>' +
      '<label class="tp-field__label">Radio (m)</label>' +
      '<input class="tp-input" type="number" min="1" step="1" name="radius" value="' +
      radius +
      '" style="max-width:160px;">' +
      '<div class="tp-field__hint">Dibuja el círculo en el mapa o arrastra el borde/centro para ajustarlo.</div></div>' +
      '<div class="tp-field" id="tp-zone-poly-row">' +
      '<label class="tp-field__label">Vértices</label>' +
      '<div class="tp-row" style="gap:6px; align-items:center;">' +
      '<span class="tp-text-sm tp-text-muted" id="tp-zone-poly-count">0 puntos</span>' +
      '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" id="tp-zone-poly-redraw">Redibujar</button>' +
      "</div>" +
      '<div class="tp-field__hint">Clic en el mapa para añadir vértices; doble clic para terminar. Arrastra un vértice para moverlo; clic en el punto medio para insertar uno nuevo.</div></div>'
    );
  }

  /* ----- Draw helpers ----- */
  function disposeEditor() {
    if (!state.editor) return;
    if (state.editor.drawer) {
      try {
        state.editor.drawer.disable();
      } catch (e) {}
    }
    if (state.editor.draftLayer && state.map) {
      try {
        if (
          state.editor.draftLayer.editing &&
          state.editor.draftLayer.editing.disable
        )
          state.editor.draftLayer.editing.disable();
      } catch (e) {}
      try {
        state.map.removeLayer(state.editor.draftLayer);
      } catch (e) {}
    }
  }

  function syncDraftFromLayer() {
    if (!state.editor || !state.editor.draftLayer) return;
    var l = state.editor.draftLayer;
    if (l instanceof L.Circle) {
      var c = l.getLatLng();
      state.editor.draft.type = "circle";
      state.editor.draft.center = { lat: c.lat, lng: c.lng };
      state.editor.draft.radius = l.getRadius();
      // sync radius input
      var rEl = document.querySelector(
        '#tp-zones-editor-form input[name="radius"]',
      );
      if (rEl) rEl.value = Math.round(state.editor.draft.radius);
    } else if (l instanceof L.Polygon) {
      var rings = l.getLatLngs();
      var pts = rings && rings.length ? rings[0] : [];
      if (Array.isArray(pts[0])) pts = pts[0]; // handle nested array
      state.editor.draft.type = "polygon";
      state.editor.draft.coordinates = pts.map(function (p) {
        return { lat: p.lat, lng: p.lng };
      });
      var cnt = document.getElementById("tp-zone-poly-count");
      if (cnt)
        cnt.textContent = state.editor.draft.coordinates.length + " puntos";
    }
  }

  function attachEditedListeners(layer) {
    layer.on("edit", syncDraftFromLayer);
    // Leaflet.draw's Edit toolbar fires 'edit' on vertex move; we listen
    // directly on the layer's editing handlers for circle/poly.
    if (layer instanceof L.Circle) {
      // Plug into L.Edit.Circle: it fires editstart but vertex movement is
      // handled internally; the simplest approach is to poll on map mouseup.
      var map = state.map;
      var onUp = function () {
        if (!state.editor || state.editor.draftLayer !== layer) return;
        // Only sync if the radius/center actually changed
        syncDraftFromLayer();
      };
      map.on("mouseup touchend", onUp);
      layer._tpOnUp = onUp;
    }
  }

  function startDrawer(type) {
    if (!state.map) return;
    // Dispose previous draft if any
    if (state.editor.drawer) {
      try {
        state.editor.drawer.disable();
      } catch (e) {}
      state.editor.drawer = null;
    }
    if (state.editor.draftLayer) {
      try {
        state.map.removeLayer(state.editor.draftLayer);
      } catch (e) {}
      state.editor.draftLayer = null;
    }
    var color = document.querySelector(
      '#tp-zones-editor-form input[name="polygon_color"]',
    );
    var shapeOptions = {
      color: color ? color.value : "#d000df",
      weight: 3,
      opacity: 1,
      fill: true,
      fillOpacity: 0.3,
      fillColor: color ? color.value : "#d000df",
    };
    if (type === "circle") {
      state.editor.drawer = new L.Draw.Circle(state.map, {
        shapeOptions: shapeOptions,
        metric: true,
      });
    } else {
      state.editor.drawer = new L.Draw.Polygon(state.map, {
        shapeOptions: shapeOptions,
        allowIntersection: false,
        showArea: false,
      });
    }
    state.editor.drawer.enable();
  }

  function makeLayerEditable(layer) {
    if (!layer || !layer.editing) return;
    try {
      layer.editing.enable();
    } catch (e) {}
  }

  function bindDrawCreated() {
    if (!state.map) return;
    state.map.on(L.Draw.Event.CREATED, function (e) {
      if (!state.editor) return;
      var type = e.layerType;
      var layer = e.layer;
      if (type !== "polygon" && type !== "circle") return;
      // Remove any old draft
      if (state.editor.draftLayer)
        state.map.removeLayer(state.editor.draftLayer);
      state.editor.draftLayer = layer;
      state.map.addLayer(layer);
      makeLayerEditable(layer);
      attachEditedListeners(layer);
      syncDraftFromLayer();
    });
  }

  /* ----- Editor body render (form + drawer wiring) ----- */
  function renderEditorBody(meta, item) {
    var body = document.getElementById("tp-zones-editor-body");
    body.innerHTML =
      '<section style="margin-bottom:10px;">' +
      zoneGeneralPanel(meta, item) +
      "</section>" +
      '<hr style="border:0; border-top:1px solid var(--border-default); margin:6px 0 14px;">' +
      "<section>" +
      zoneGeometryPanel(meta, item) +
      "</section>";

    var form = document.getElementById("tp-zones-editor-form");
    var typeInputs = form.querySelectorAll('input[name="type"]');
    var circleRow = form.querySelector("#tp-zone-circle-row");
    var polyRow = form.querySelector("#tp-zone-poly-row");
    var radiusInput = form.querySelector('input[name="radius"]');
    var redrawBtn = form.querySelector("#tp-zone-poly-redraw");
    var colorInput = form.querySelector('input[name="polygon_color"]');

    function syncTypeUI() {
      var t = getCheckedRadio(typeInputs);
      state.editor.draft.type = t;
      circleRow.hidden = t !== "circle";
      polyRow.hidden = t !== "polygon";
      // When user changes type with no draft yet, restart drawer
      if (!state.editor.draftLayer || !sameShape(state.editor.draftLayer, t))
        startDrawer(t);
    }
    function sameShape(layer, t) {
      if (t === "circle") return layer instanceof L.Circle;
      return layer instanceof L.Polygon;
    }
    typeInputs.forEach(function (i) {
      i.addEventListener("change", syncTypeUI);
    });

    if (radiusInput) {
      radiusInput.addEventListener("input", function () {
        var r = parseFloat(radiusInput.value);
        if (!(r > 0)) return;
        state.editor.draft.radius = r;
        if (state.editor.draftLayer instanceof L.Circle)
          state.editor.draftLayer.setRadius(r);
      });
    }
    if (redrawBtn) {
      redrawBtn.addEventListener("click", function (e) {
        e.preventDefault();
        startDrawer(state.editor.draft.type);
      });
    }
    if (colorInput) {
      colorInput.addEventListener("input", function () {
        if (!state.editor.draftLayer) return;
        var c = colorInput.value;
        try {
          state.editor.draftLayer.setStyle({ color: c, fillColor: c });
        } catch (e) {}
      });
    }

    // Don't immediately startDrawer here for edit mode (we'll load existing
    // layer in openZoneEditor). For create mode the caller starts it.
    circleRow.hidden = state.editor.draft.type !== "circle";
    polyRow.hidden = state.editor.draft.type !== "polygon";
  }

  /* ----- Read form into payload (preserves contract) ----- */
  function readZoneForm() {
    var form = document.getElementById("tp-zones-editor-form");
    if (!form) return null;
    var name = (form.name.value || "").trim();
    if (!name) {
      tpModal.toast("El nombre es obligatorio.", "warning");
      return null;
    }
    var type = getCheckedRadio(form.querySelectorAll('input[name="type"]'));
    // Force-sync from layer before reading
    syncDraftFromLayer();
    var draft = state.editor.draft;
    var data = {
      name: name,
      type: type,
      active: form.active && form.active.checked ? 1 : 0,
      polygon_color: form.polygon_color ? form.polygon_color.value : "#d000df",
      group_id:
        form.group_id && form.group_id.value
          ? parseInt(form.group_id.value, 10)
          : 0,
    };
    if (form.speed_limit && form.speed_limit.value !== "")
      data.speed_limit = parseFloat(form.speed_limit.value);
    if (form.device_id)
      data.device_id = form.device_id.value
        ? parseInt(form.device_id.value, 10)
        : null;

    if (type === "circle") {
      if (!draft.center) {
        tpModal.toast("Dibuja el círculo en el mapa.", "warning");
        return null;
      }
      var radius = form.radius && parseFloat(form.radius.value);
      if (!(radius > 0)) {
        tpModal.toast("Introduce un radio válido en metros.", "warning");
        return null;
      }
      data.center = { lat: draft.center.lat, lng: draft.center.lng };
      data.radius = radius;
      data.polygon = [];
    } else {
      if (!draft.coordinates || draft.coordinates.length < 3) {
        tpModal.toast(
          "Dibuja el polígono (mín. 3 vértices) en el mapa.",
          "warning",
        );
        return null;
      }
      data.polygon = draft.coordinates.map(function (p) {
        return { lat: p.lat, lng: p.lng };
      });
    }
    return data;
  }

  /* ----- Delete ----- */
  function deleteZone(id) {
    if (!CAN_EDIT) return;
    tpModal
      .confirm({
        title: "Eliminar geocerca",
        message: "Esta acción no se puede deshacer. ¿Eliminar la geocerca?",
        danger: true,
        confirmLabel: "Eliminar",
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal
          .fetchJSON(BASE_URL + "/" + encodeURIComponent(id), {
            method: "DELETE",
          })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              tpModal.toast("No se pudo eliminar.", "error");
              return;
            }
            tpModal.toast("Geocerca eliminada.", "success");
            document.getElementById("tp-zones-detail").hidden = true;
            state.activeId = null;
            load();
          })
          .catch(function () {
            tpModal.toast("Error de red.", "error");
          });
      });
  }

  /* ----- Hint banner ----- */
  function updateDrawHint() {
    var existing = document.getElementById("tp-zones-draw-hint");
    if (existing) existing.remove();
    if (!state.editor) return;
    var hint = document.createElement("div");
    hint.id = "tp-zones-draw-hint";
    hint.className = "tp-zones__draw-hint";
    hint.textContent =
      state.editor.draft.type === "circle"
        ? "Clic + arrastrar para dibujar el círculo. Arrastra borde/centro para ajustar."
        : "Clic para añadir vértices · doble clic para terminar · arrastra para mover.";
    var area = root.querySelector(".tp-zones__map-area");
    if (area) area.appendChild(hint);
  }

  /* ----- Open editor (create or edit) ----- */
  function openZoneEditor(id) {
    if (!id && !CAN_CREATE) return;
    if (id && !CAN_EDIT) return;
    var isEdit = !!id;

    state.editor = {
      id: id || null,
      isEdit: isEdit,
      drawer: null,
      draftLayer: null,
      draft: { type: "polygon", center: null, coordinates: [], radius: 100 },
    };

    var panel = document.getElementById("tp-zones-editor");
    var titleEl = document.getElementById("tp-zones-editor-title");
    var saveBtn = document.getElementById("tp-zones-editor-save");
    var body = document.getElementById("tp-zones-editor-body");

    titleEl.textContent = isEdit ? "Editar geocerca" : "Nueva geocerca";
    saveBtn.textContent = isEdit ? "Guardar cambios" : "Crear geocerca";
    body.innerHTML =
      '<div class="tp-text-center tp-text-muted" style="padding:24px;">Cargando…</div>';
    panel.hidden = false;

    var det = document.getElementById("tp-zones-detail");
    if (det) det.hidden = true;

    if (state.map) {
      setTimeout(function () {
        try {
          state.map.invalidateSize();
        } catch (e) {}
      }, 0);
      setTimeout(function () {
        try {
          state.map.invalidateSize();
        } catch (e) {}
      }, 220);
    }

    var loader = isEdit
      ? Promise.all([
          loadMeta(),
          tpModal
            .fetchJSON(BASE_URL + "/" + encodeURIComponent(id) + "/edit", {
              method: "GET",
            })
            .then(function (r) {
              if (!r.ok || !r.data || !r.data.ok)
                throw new Error("edit_failed");
              return r.data.item;
            }),
        ]).then(function (arr) {
          return { meta: arr[0], item: arr[1] };
        })
      : loadMeta().then(function (m) {
          return { meta: m, item: null };
        });

    loader
      .then(function (ctx) {
        // Pre-set draft from item before rendering body
        if (ctx.item) {
          state.editor.draft.type = ctx.item.type || "polygon";
          state.editor.draft.center = ctx.item.center
            ? parseLatLng(ctx.item.center)
            : null;
          state.editor.draft.coordinates = normCoords(ctx.item.coordinates);
          state.editor.draft.radius =
            ctx.item.radius != null ? Number(ctx.item.radius) : 100;
        }

        renderEditorBody(ctx.meta, ctx.item);

        if (ctx.item) {
          // Build layer for existing geofence and enable editing
          var layer = buildLayerFor(ctx.item);
          if (layer) {
            state.editor.draftLayer = layer;
            state.map.addLayer(layer);
            makeLayerEditable(layer);
            attachEditedListeners(layer);
            // Hide the corresponding rendered zone in zonesLayer to avoid overlap
            state.zonesLayer.eachLayer(function (l) {
              if (l._zoneId === ctx.item.id) state.zonesLayer.removeLayer(l);
            });
            // Fly to it
            var b = layer.getBounds && layer.getBounds();
            if (b && b.isValid)
              state.map.fitBounds(b, { padding: [60, 60], maxZoom: 16 });
            else if (layer.getLatLng) state.map.setView(layer.getLatLng(), 14);
          }
        } else {
          // Create mode: start drawer immediately for selected type
          startDrawer(state.editor.draft.type);
        }
        updateDrawHint();
      })
      .catch(function (err) {
        console.error("[zones] openZoneEditor failed:", err);
        body.innerHTML =
          '<div class="tp-text-center" style="padding:24px; color:var(--status-alert,#ef4444);">No se pudo cargar el editor.' +
          (err && err.message
            ? '<div class="tp-text-sm tp-text-muted" style="margin-top:6px;">' +
              escapeHtml(err.message) +
              "</div>"
            : "") +
          "</div>";
      });
  }

  function closeZoneEditor() {
    disposeEditor();
    state.editor = null;
    document.getElementById("tp-zones-editor").hidden = true;
    var hint = document.getElementById("tp-zones-draw-hint");
    if (hint) hint.remove();
    // Re-render to restore any hidden zone layer
    updateZonesLayer();
  }

  function saveEditor() {
    if (!state.editor) return;
    var payload = readZoneForm();
    if (!payload) return;
    var isEdit = state.editor.isEdit;
    var id = state.editor.id;
    var url = isEdit ? BASE_URL + "/" + encodeURIComponent(id) : STORE_URL;
    var method = isEdit ? "PUT" : "POST";
    var saveBtn = document.getElementById("tp-zones-editor-save");
    saveBtn.disabled = true;
    saveBtn.textContent = "Guardando…";

    tpModal
      .fetchJSON(url, { method: method, json: payload })
      .then(function (res) {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? "Guardar cambios" : "Crear geocerca";
        if (res.status === 422) {
          tpModal.toast("Revisa los campos marcados.", "warning");
          return;
        }
        if (!res.ok || !res.data || !res.data.ok) {
          tpModal.toast(
            (res.data && res.data.message) ||
              (isEdit
                ? "No se pudo guardar."
                : "No se pudo crear la geocerca."),
            "error",
          );
          return;
        }
        tpModal.toast(
          isEdit ? "Cambios guardados." : "Geocerca creada.",
          "success",
        );
        closeZoneEditor();
        load();
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? "Guardar cambios" : "Crear geocerca";
        tpModal.toast("Error de red.", "error");
      });
  }

  /* ----- Wire editor UI ----- */
  var newBtn = document.getElementById("tp-zones-new");
  if (newBtn)
    newBtn.addEventListener("click", function () {
      openZoneEditor();
    });

  var editorClose = document.getElementById("tp-zones-editor-close");
  if (editorClose) editorClose.addEventListener("click", closeZoneEditor);
  var editorCancel = document.getElementById("tp-zones-editor-cancel");
  if (editorCancel) editorCancel.addEventListener("click", closeZoneEditor);
  var editorSave = document.getElementById("tp-zones-editor-save");
  if (editorSave) editorSave.addEventListener("click", saveEditor);

  /* ============================================================
     Import / Export native modals
     ============================================================ */
  function openImportModal() {
    if (!IMPORT_URL) return;
    var bodyHtml =
      '<form id="tp-zones-import-form" class="tp-form" enctype="multipart/form-data">' +
      '<div class="tp-field">' +
      '<label class="tp-label" for="tp-zones-import-file">Archivo (.gexp / .csv / .xls)</label>' +
      '<input type="file" id="tp-zones-import-file" name="file" accept=".gexp,.csv,.xls,.xlsx,.txt" required />' +
      '<small class="tp-help">Selecciona un archivo de geocercas para importar.</small>' +
      "</div></form>";

    return window.tpModal.open({
      title: "Importar geocercas",
      size: "sm",
      body: bodyHtml,
      buttons: [
        { label: "Cancelar", variant: "ghost", value: "cancel" },
        {
          label: "Importar",
          variant: "primary",
          onClick: function (d, btn) {
            var input = d.querySelector("#tp-zones-import-file");
            if (!input || !input.files || !input.files.length) {
              window.tpModal.toast("Selecciona un archivo", "error");
              return false;
            }
            btn.disabled = true;
            btn.textContent = "Importando...";
            var fd = new FormData();
            fd.append("file", input.files[0]);
            fd.append("_token", window.tpModal.csrf());
            fetch(IMPORT_URL, {
              method: "POST",
              credentials: "same-origin",
              headers: { "X-Requested-With": "XMLHttpRequest" },
              body: fd,
            })
              .then(function (r) {
                return r
                  .json()
                  .then(function (j) {
                    return { ok: r.ok, body: j };
                  })
                  .catch(function () {
                    return { ok: r.ok, body: null };
                  });
              })
              .then(function (res) {
                if (!res.ok || !res.body || res.body.ok !== true) {
                  var msg =
                    (res.body && (res.body.message || res.body.error)) ||
                    "Error al importar";
                  window.tpModal.toast(msg, "error");
                  btn.disabled = false;
                  btn.textContent = "Importar";
                  return;
                }
                window.tpModal.toast(
                  res.body.message || "Importación correcta",
                  "success",
                );
                window.tpModal.close(d, "ok");
                load();
              })
              .catch(function (err) {
                window.tpModal.toast(err.message || "Error", "error");
                btn.disabled = false;
                btn.textContent = "Importar";
              });
            return false;
          },
        },
      ],
    });
  }

  function openExportModal() {
    if (!EXPORT_URL) return;
    var types = EXPORT_TYPES || {};
    var typeKeys = Object.keys(types);
    if (!typeKeys.length) {
      typeKeys = [
        "export_single",
        "export_groups",
        "export_active",
        "export_inactive",
      ];
      types = {
        export_single: "Geocercas específicas",
        export_groups: "Grupos",
        export_active: "Activas",
        export_inactive: "Inactivas",
      };
    }

    var radiosHtml = typeKeys
      .map(function (k, i) {
        return (
          '<label class="tp-radio"><input type="radio" name="export_type" value="' +
          window.tpModal.escapeHtml(k) +
          '"' +
          (i === 0 ? " checked" : "") +
          " /> " +
          window.tpModal.escapeHtml(types[k]) +
          "</label>"
        );
      })
      .join("");

    var bodyHtml =
      '<form id="tp-zones-export-form" class="tp-form">' +
      '<div class="tp-field"><label class="tp-label">Tipo de exportación</label>' +
      '<div class="tp-row" style="flex-direction:column;gap:6px;">' +
      radiosHtml +
      "</div></div>" +
      '<div class="tp-field" id="tp-zones-export-items-wrap">' +
      '<label class="tp-label" for="tp-zones-export-items">Elementos</label>' +
      '<select id="tp-zones-export-items" name="items[]" multiple size="8" class="tp-input" style="height:auto;"></select>' +
      '<small class="tp-help">Mantén Ctrl/Cmd para seleccionar varios. Vacío = todos.</small>' +
      "</div></form>";

    return window.tpModal.open({
      title: "Exportar geocercas",
      size: "sm",
      body: bodyHtml,
      buttons: [
        { label: "Cancelar", variant: "ghost", value: "cancel" },
        {
          label: "Exportar",
          variant: "primary",
          onClick: function (d) {
            var selType = d.querySelector('input[name="export_type"]:checked');
            if (!selType) return false;
            var sel = d.querySelector("#tp-zones-export-items");
            var ids = [];
            if (sel) {
              for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].selected) ids.push(sel.options[i].value);
              }
            }
            var form = document.createElement("form");
            form.method = "POST";
            form.action = EXPORT_URL;
            form.style.display = "none";
            var add = function (name, value) {
              var inp = document.createElement("input");
              inp.type = "hidden";
              inp.name = name;
              inp.value = value;
              form.appendChild(inp);
            };
            add("_token", window.tpModal.csrf());
            add("export_type", selType.value);
            ids.forEach(function (v) {
              add("items[]", v);
            });
            document.body.appendChild(form);
            form.submit();
            setTimeout(function () {
              if (form.parentNode) form.parentNode.removeChild(form);
            }, 1500);
            return true;
          },
        },
      ],
      onMount: function (d) {
        var radios = d.querySelectorAll('input[name="export_type"]');
        var sel = d.querySelector("#tp-zones-export-items");
        var wrap = d.querySelector("#tp-zones-export-items-wrap");
        function loadItems(type) {
          if (!sel) return;
          sel.innerHTML = "<option disabled selected>Cargando...</option>";
          if (!EXPORT_ITEMS_URL) {
            sel.innerHTML = "";
            if (wrap) wrap.style.display = "none";
            return;
          }
          fetch(EXPORT_ITEMS_URL + "?type=" + encodeURIComponent(type), {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
          })
            .then(function (r) {
              return r.json();
            })
            .then(function (j) {
              sel.innerHTML = "";
              if (!j || !j.ok || !Array.isArray(j.items)) {
                if (wrap) wrap.style.display = "none";
                return;
              }
              if (!j.items.length) {
                if (wrap) wrap.style.display = "none";
                return;
              }
              if (wrap) wrap.style.display = "";
              var selected = {};
              (j.selected || []).forEach(function (s) {
                selected[String(s)] = true;
              });
              j.items.forEach(function (it) {
                var opt = document.createElement("option");
                opt.value = String(it.id);
                opt.textContent = it.label;
                if (selected[String(it.id)]) opt.selected = true;
                sel.appendChild(opt);
              });
            })
            .catch(function () {
              if (wrap) wrap.style.display = "none";
            });
        }
        radios.forEach(function (r) {
          r.addEventListener("change", function () {
            if (r.checked) loadItems(r.value);
          });
        });
        var initial = d.querySelector('input[name="export_type"]:checked');
        if (initial) loadItems(initial.value);
      },
    });
  }

  var importBtn = document.getElementById("tp-zones-import");
  if (importBtn) importBtn.addEventListener("click", openImportModal);
  var exportBtn = document.getElementById("tp-zones-export");
  if (exportBtn) exportBtn.addEventListener("click", openExportModal);

  /* ----- Boot ----- */
  initMap();
  if (state.map) bindDrawCreated();
  load();
})();
