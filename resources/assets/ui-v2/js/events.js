/*
 * UI v2 · Eventos (página dedicada con mapa)
 * - Lista con scroll infinito (paginación por offset, como el legacy).
 * - Geocodificación PEREZOSA: solo de las filas visibles (IntersectionObserver),
 *   reutilizando el endpoint legacy address.get (respeta el plugin
 *   geofence_over_address → nombre de geocerca en vez de dirección).
 * - Al seleccionar un evento: centra el mapa y muestra el marcador del device
 *   con un popup de información.
 * Deps: window.maplibregl, window.tpMapProviders (opcional).
 */
(function () {
  "use strict";

  var root = document.getElementById("tp-events");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
  var GEO_URL = root.getAttribute("data-geo-url");
  var ZONES_URL = root.getAttribute("data-zones-url");
  var POIS_URL = root.getAttribute("data-pois-url");
  var ROUTES_URL = root.getAttribute("data-routes-url");
  var listEl = document.getElementById("tp-events-list");
  var statusEl = document.getElementById("tp-events-status");
  var hoursSel = document.getElementById("tp-events-hours");
  var searchEl = document.getElementById("tp-events-search");
  var refreshBtn = document.getElementById("tp-events-refresh");
  var hintEl = document.getElementById("tp-events-hint");

  var MAP_CFG = (function () {
    try {
      return JSON.parse(
        document.getElementById("tp-events-map-config").textContent || "{}",
      );
    } catch (e) {
      return {};
    }
  })();
  var P =
    window.tpMapProviders && window.tpMapProviders.create
      ? window.tpMapProviders.create(MAP_CFG)
      : null;
  function csrf() {
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }

  // Sembrar el estado compartido de toggles desde el backend (user->map_controls).
  if (window.tpMapShared && window.tpMapShared.init) {
    window.tpMapShared.init({
      toggles: (MAP_CFG && MAP_CFG.toggles) || {},
      urls: (MAP_CFG && MAP_CFG.urls) || {},
    });
  }

  var state = {
    events: [],
    byId: {},
    offset: 0,
    hasMore: true,
    loading: false,
    map: null,
    marker: null,
    popup: null,
    selectedId: null,
    currentMapId: (MAP_CFG && MAP_CFG.mapId) || null,
  };
  var geoCache = {}; // eventId → texto (dirección o nombre de geocerca)

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function sevClass(sev) {
    return sev === "critical" ? "danger" : sev === "warning" ? "warning" : "info";
  }
  function sevColor(sev) {
    return sev === "critical"
      ? "#ef4444"
      : sev === "warning"
        ? "#f59e0b"
        : "#3b82f6";
  }

  // ── Mapa ───────────────────────────────────────────────────
  function osmStyle() {
    return {
      version: 8,
      sources: {
        osm: {
          type: "raster",
          tiles: [
            "https://a.tile.openstreetmap.org/{z}/{x}/{y}.png",
            "https://b.tile.openstreetmap.org/{z}/{x}/{y}.png",
            "https://c.tile.openstreetmap.org/{z}/{x}/{y}.png",
          ],
          tileSize: 256,
          attribution: "© OpenStreetMap",
        },
      },
      layers: [{ id: "osm", type: "raster", source: "osm" }],
    };
  }

  function createMap(style) {
    state.map = new maplibregl.Map({
      container: "tp-events-map",
      style: style || osmStyle(),
      center: [-77.0428, -12.0464],
      zoom: 5,
      attributionControl: { compact: true },
    });
    state.map.addControl(
      new maplibregl.NavigationControl({ showCompass: false }),
      "bottom-right",
    );
    state.map.on("load", function () {
      state.map.resize();
      renderSharedLayers();
    });
    state.map.on("styledata", function () {
      renderSharedLayers();
    });
  }

  function initMap() {
    if (!window.maplibregl) return;
    // Crear el mapa UNA vez con el estilo del proveedor del usuario (mismo tipo
    // que Live). Sin swap OSM→proveedor para no cancelar los tiles.
    if (P && MAP_CFG && MAP_CFG.mapId) {
      try {
        P.resolveStyle(MAP_CFG.mapId)
          .then(function (style) {
            createMap(style || osmStyle());
          })
          .catch(function () {
            createMap(osmStyle());
          });
        return;
      } catch (e) {}
    }
    createMap(osmStyle());
  }

  // ── Geocodificación perezosa (solo filas visibles) ─────────
  function parseGeo(text) {
    if (text == null) return "";
    text = String(text).trim();
    if (!text) return "";
    try {
      var j = JSON.parse(text);
      if (typeof j === "string") return j;
      if (j && typeof j === "object")
        return j.address || j.name || j.title || "";
    } catch (e) {}
    // Defensa: si el backend (DebugBar) inyectó HTML, corta en el primer "<".
    var lt = text.indexOf("<");
    if (lt !== -1) text = text.slice(0, lt).trim();
    return text;
  }

  function geocodeEvent(ev) {
    if (!GEO_URL || ev.lat == null || ev.lng == null) return;
    if (geoCache[ev.id] !== undefined) return; // ya pedido/cacheado
    geoCache[ev.id] = null; // marca en curso
    fetch(
      GEO_URL +
        (GEO_URL.indexOf("?") === -1 ? "?" : "&") +
        "lat=" +
        ev.lat +
        "&lng=" +
        ev.lng,
      {
        credentials: "same-origin",
        headers: { Accept: "*/*", "X-Requested-With": "XMLHttpRequest" },
      },
    )
      .then(function (r) {
        return r.text();
      })
      .then(function (text) {
        var addr = parseGeo(text);
        geoCache[ev.id] = addr;
        var span = listEl.querySelector('.tp-events__addr[data-id="' + ev.id + '"]');
        if (span) span.textContent = addr || "—";
        // Si el popup abierto es de este evento, refrescar su dirección.
        if (state.selectedId === ev.id && state.popup) updatePopupAddress(ev, addr);
      })
      .catch(function () {
        geoCache[ev.id] = "";
      });
  }

  var io =
    "IntersectionObserver" in window
      ? new IntersectionObserver(
          function (entries) {
            entries.forEach(function (en) {
              if (!en.isIntersecting) return;
              var id = parseInt(en.target.getAttribute("data-geo-id"), 10);
              var ev = state.byId[id];
              if (ev) geocodeEvent(ev);
              io.unobserve(en.target);
            });
          },
          { root: listEl, rootMargin: "100px" },
        )
      : null;

  // ── Lista ──────────────────────────────────────────────────
  function rowHtml(ev) {
    var hasGeo = ev.lat != null && ev.lng != null;
    var addr =
      geoCache[ev.id] != null
        ? esc(geoCache[ev.id])
        : hasGeo
          ? "…"
          : "sin ubicación";
    return (
      '<button type="button" class="tp-events__row" data-id="' +
      ev.id +
      '" data-geo-id="' +
      ev.id +
      '"' +
      (state.selectedId === ev.id ? ' aria-selected="true"' : "") +
      ">" +
      '<span class="tp-events__dot tp-events__dot--' +
      sevClass(ev.severity) +
      '"></span>' +
      '<span class="tp-events__row-main">' +
      '<span class="tp-events__row-title">' +
      esc(ev.message || ev.type) +
      "</span>" +
      '<span class="tp-events__row-sub">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      " · " +
      esc(ev.time) +
      "</span>" +
      '<span class="tp-events__addr" data-id="' +
      ev.id +
      '">' +
      addr +
      "</span>" +
      "</span></button>"
    );
  }

  function appendRows(events) {
    if (!events.length) return;
    var frag = document.createElement("div");
    frag.innerHTML = events.map(rowHtml).join("");
    while (frag.firstChild) {
      var node = frag.firstChild;
      listEl.appendChild(node);
      if (io && node.getAttribute) io.observe(node);
    }
  }

  // ── Selección / mapa ───────────────────────────────────────
  function updatePopupAddress(ev, addr) {
    if (!state.popup) return;
    var el = state.popup.getElement();
    if (!el) return;
    var dd = el.querySelector("[data-ev-addr]");
    if (dd) dd.textContent = addr || "—";
  }

  function popupHtml(ev) {
    var addr = geoCache[ev.id] != null ? geoCache[ev.id] : "…";
    return (
      '<div class="tp-ev-popup">' +
      '<div class="tp-ev-popup__title">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      (ev.plate ? " · " + esc(ev.plate) : "") +
      "</div>" +
      '<div class="tp-ev-popup__type tp-ev-popup__type--' +
      sevClass(ev.severity) +
      '">' +
      esc(ev.message || ev.type) +
      "</div>" +
      '<dl class="tp-ev-popup__defs">' +
      "<dt>Hora</dt><dd>" +
      esc(ev.time) +
      "</dd>" +
      "<dt>Ubicación</dt><dd data-ev-addr>" +
      esc(addr) +
      "</dd>" +
      (ev.speed != null
        ? "<dt>Velocidad</dt><dd>" + Math.round(ev.speed) + " km/h</dd>"
        : "") +
      "<dt>Coordenadas</dt><dd>" +
      parseFloat(ev.lat).toFixed(5) +
      ", " +
      parseFloat(ev.lng).toFixed(5) +
      "</dd></dl></div>"
    );
  }

  function selectEvent(id) {
    var ev = state.byId[id];
    if (!ev) return;
    state.selectedId = id;

    var prev = listEl.querySelector('[aria-selected="true"]');
    if (prev) prev.removeAttribute("aria-selected");
    var now = listEl.querySelector('.tp-events__row[data-id="' + id + '"]');
    if (now) now.setAttribute("aria-selected", "true");

    if (ev.lat == null || ev.lng == null || !state.map) {
      if (window.tpModal && window.tpModal.toast)
        window.tpModal.toast("Este evento no tiene ubicación.", "warning");
      return;
    }
    if (hintEl) hintEl.hidden = true;
    geocodeEvent(ev); // asegura dirección para el popup

    var coords = [parseFloat(ev.lng), parseFloat(ev.lat)];
    if (!state.marker) {
      var el = document.createElement("div");
      el.className = "tp-events__marker";
      state.marker = new maplibregl.Marker({ element: el, anchor: "bottom" });
    }
    state.marker.getElement().style.setProperty("--ev-color", sevColor(ev.severity));
    state.marker.setLngLat(coords).addTo(state.map);

    if (!state.popup)
      state.popup = new maplibregl.Popup({ offset: 26, closeButton: true });
    state.popup.setLngLat(coords).setHTML(popupHtml(ev)).addTo(state.map);

    state.map.flyTo({
      center: coords,
      zoom: Math.max(state.map.getZoom(), 15),
      duration: 600,
    });
  }

  // ── Carga (scroll infinito) ────────────────────────────────
  function load(reset) {
    if (state.loading) return;
    if (!reset && !state.hasMore) return;
    state.loading = true;
    if (reset) {
      state.offset = 0;
      state.hasMore = true;
      state.events = [];
      state.byId = {};
      state.selectedId = null;
      listEl.innerHTML = "";
    }
    if (statusEl)
      statusEl.textContent = reset ? "Cargando…" : "Cargando más…";

    var hours = hoursSel ? hoursSel.value : 24;
    var q = searchEl ? searchEl.value.trim() : "";
    fetch(
      DATA_URL +
        "?hours=" +
        encodeURIComponent(hours) +
        "&q=" +
        encodeURIComponent(q) +
        "&offset=" +
        state.offset +
        "&_=" +
        Date.now(),
      { credentials: "same-origin", headers: { Accept: "application/json" } },
    )
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        state.loading = false;
        var rows = (res && res.events) || [];
        rows.forEach(function (ev) {
          state.events.push(ev);
          state.byId[ev.id] = ev;
        });
        state.offset = res && res.offset != null ? res.offset : state.offset + rows.length;
        state.hasMore = !!(res && res.has_more);

        if (reset && !state.events.length) {
          listEl.innerHTML =
            '<div class="tp-text-xs tp-text-muted" style="padding:12px;">Sin eventos en el rango seleccionado.</div>';
        } else {
          appendRows(rows);
        }
        if (statusEl)
          statusEl.textContent =
            state.events.length + " eventos" + (state.hasMore ? " (+)" : "");
      })
      .catch(function () {
        state.loading = false;
        if (statusEl) statusEl.textContent = "Error al cargar";
      });
  }

  // ── Eventos UI ─────────────────────────────────────────────
  listEl.addEventListener("click", function (e) {
    var row = e.target.closest(".tp-events__row[data-id]");
    if (!row) return;
    selectEvent(parseInt(row.getAttribute("data-id"), 10));
  });
  listEl.addEventListener("scroll", function () {
    if (
      state.hasMore &&
      !state.loading &&
      listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 120
    ) {
      load(false);
    }
  });
  if (hoursSel) hoursSel.addEventListener("change", function () { load(true); });
  if (refreshBtn) refreshBtn.addEventListener("click", function () { load(true); });
  if (searchEl) {
    var t = null;
    searchEl.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(function () { load(true); }, 350);
    });
  }

  // Controles de mapa
  var fsBtn = document.getElementById("tp-ev-fullscreen");
  var ziBtn = document.getElementById("tp-ev-zoom-in");
  var zoBtn = document.getElementById("tp-ev-zoom-out");
  if (fsBtn)
    fsBtn.addEventListener("click", function () {
      var area = document.querySelector(".tp-events__map-area");
      if (!document.fullscreenElement && area && area.requestFullscreen) {
        area.requestFullscreen().then(function () {
          if (state.map) setTimeout(function () { state.map.resize(); }, 150);
        });
      } else if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    });
  if (ziBtn) ziBtn.addEventListener("click", function () { state.map && state.map.zoomIn(); });
  if (zoBtn) zoBtn.addEventListener("click", function () { state.map && state.map.zoomOut(); });

  // Selector de tipo de mapa (persiste en user->map_id → compartido con Live)
  var styleBtn = document.getElementById("tp-ev-style");
  var provMenu = document.getElementById("tp-ev-providers");
  function buildProviders() {
    if (!provMenu || !P) return;
    var items = P.availableSupported
      ? P.availableSupported()
      : MAP_CFG.available || [];
    provMenu.innerHTML =
      items
        .map(function (m) {
          return (
            '<button type="button" class="tp-events__provider' +
            (String(m.id) === String(state.currentMapId) ? " is-active" : "") +
            '" data-map-id="' +
            m.id +
            '">' +
            esc(m.name) +
            "</button>"
          );
        })
        .join("") ||
      '<div class="tp-text-xs tp-text-muted" style="padding:8px;">Sin proveedores.</div>';
  }
  if (styleBtn && provMenu) {
    styleBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      if (provMenu.hidden) {
        buildProviders();
        provMenu.hidden = false;
      } else {
        provMenu.hidden = true;
      }
    });
    provMenu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-map-id]");
      if (!b) return;
      var id = b.getAttribute("data-map-id");
      state.currentMapId = id;
      provMenu.hidden = true;
      if (P && state.map)
        P.applyMapStyle(state.map, id, function () {
          state.map.resize();
          renderSharedLayers();
        });
      // Persistir (mismo endpoint que Live → user->map_id)
      try {
        var fd = new FormData();
        fd.append("key", "tp_map_id");
        fd.append("value", String(id));
        fetch("/ui/v2/api/preferences", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { "X-CSRF-TOKEN": csrf() },
        }).catch(function () {});
      } catch (e2) {}
    });
    document.addEventListener("click", function () {
      if (!provMenu.hidden) provMenu.hidden = true;
    });
  }

  // Capas compartidas con Live (geocercas, rutas, POIs) — estado vía tpMapShared
  function layerVisible(key) {
    return !!(window.tpMapShared && window.tpMapShared.get(key, false));
  }
  function renderSharedLayers() {
    if (!window.tpMapShared || !state.map) return;
    window.tpMapShared.renderGeofences(state.map, ZONES_URL, layerVisible("geofences"));
    window.tpMapShared.renderRoutes(state.map, ROUTES_URL, layerVisible("routes"));
    window.tpMapShared.renderPois(state.map, POIS_URL, layerVisible("poi"));
  }
  [
    { id: "tp-ev-toggle-geofences", key: "geofences" },
    { id: "tp-ev-toggle-routes", key: "routes" },
    { id: "tp-ev-toggle-poi", key: "poi" },
  ].forEach(function (t) {
    var btn = document.getElementById(t.id);
    if (!btn) return;
    btn.setAttribute("aria-pressed", layerVisible(t.key) ? "true" : "false");
    btn.addEventListener("click", function () {
      var v = !layerVisible(t.key);
      if (window.tpMapShared) window.tpMapShared.set(t.key, v);
      btn.setAttribute("aria-pressed", v ? "true" : "false");
      renderSharedLayers();
    });
  });

  initMap();
  load(true);
})();
