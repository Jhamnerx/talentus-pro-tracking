/*
 * UI v2 · Eventos (página dedicada con mapa)
 * - Lista de eventos (filtros: rango horas + búsqueda).
 * - Al seleccionar un evento: centra el mapa en su ubicación y muestra el
 *   marcador del dispositivo con un popup de información junto al device.
 * Deps: window.maplibregl, window.tpMapProviders (opcional, para el tile del
 * proveedor del usuario; si no, OSM).
 */
(function () {
  "use strict";

  var root = document.getElementById("tp-events");
  if (!root) return;

  var DATA_URL = root.getAttribute("data-data-url");
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

  var state = {
    events: [],
    map: null,
    marker: null,
    popup: null,
    selectedId: null,
  };

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

  // -----------------------------------------------------------
  // Mapa
  // -----------------------------------------------------------
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

  function initMap() {
    if (!window.maplibregl) return;
    state.map = new maplibregl.Map({
      container: "tp-events-map",
      style: osmStyle(),
      center: [-77.0428, -12.0464],
      zoom: 4,
      minZoom: 2,
      maxZoom: 22,
      attributionControl: { compact: true },
    });
    state.map.addControl(
      new maplibregl.NavigationControl({ showCompass: false }),
      "bottom-right",
    );
    // Tile del proveedor del usuario (Google/Here/…) si está configurado.
    try {
      if (window.tpMapProviders && MAP_CFG && MAP_CFG.mapId) {
        var P = window.tpMapProviders.create(MAP_CFG);
        P.resolveStyle(MAP_CFG.mapId)
          .then(function (style) {
            if (style) state.map.setStyle(style);
          })
          .catch(function () {});
      }
    } catch (e) {}
  }

  // -----------------------------------------------------------
  // Lista
  // -----------------------------------------------------------
  function renderList() {
    if (!state.events.length) {
      listEl.innerHTML =
        '<div class="tp-text-xs tp-text-muted" style="padding:12px;">Sin eventos en el rango seleccionado.</div>';
      return;
    }
    var html = "";
    state.events.forEach(function (ev) {
      var hasGeo = ev.lat != null && ev.lng != null;
      html +=
        '<button type="button" class="tp-events__row" data-id="' +
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
        (hasGeo ? "" : " · sin ubicación") +
        "</span></span></button>";
    });
    listEl.innerHTML = html;
  }

  function selectEvent(id) {
    var ev = null;
    for (var i = 0; i < state.events.length; i++) {
      if (state.events[i].id === id) {
        ev = state.events[i];
        break;
      }
    }
    if (!ev) return;
    state.selectedId = id;

    var prev = listEl.querySelector('[aria-selected="true"]');
    if (prev) prev.removeAttribute("aria-selected");
    var now = listEl.querySelector('[data-id="' + id + '"]');
    if (now) now.setAttribute("aria-selected", "true");

    if (ev.lat == null || ev.lng == null || !state.map) {
      if (window.tpModal && window.tpModal.toast)
        window.tpModal.toast("Este evento no tiene ubicación.", "warning");
      return;
    }
    if (hintEl) hintEl.hidden = true;

    var coords = [parseFloat(ev.lng), parseFloat(ev.lat)];

    if (!state.marker) {
      var el = document.createElement("div");
      el.className = "tp-events__marker";
      state.marker = new maplibregl.Marker({ element: el, anchor: "bottom" });
    }
    state.marker.getElement().style.setProperty("--ev-color", sevColor(ev.severity));
    state.marker.setLngLat(coords).addTo(state.map);

    var popupHtml =
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
      (ev.speed != null
        ? "<dt>Velocidad</dt><dd>" + Math.round(ev.speed) + " km/h</dd>"
        : "") +
      "<dt>Coordenadas</dt><dd>" +
      parseFloat(ev.lat).toFixed(5) +
      ", " +
      parseFloat(ev.lng).toFixed(5) +
      "</dd></dl></div>";

    if (!state.popup)
      state.popup = new maplibregl.Popup({ offset: 26, closeButton: true });
    state.popup.setLngLat(coords).setHTML(popupHtml).addTo(state.map);

    state.map.flyTo({
      center: coords,
      zoom: Math.max(state.map.getZoom(), 15),
      duration: 600,
    });
  }

  // -----------------------------------------------------------
  // Carga
  // -----------------------------------------------------------
  function loadEvents() {
    var hours = hoursSel ? hoursSel.value : 24;
    var q = searchEl ? searchEl.value.trim() : "";
    if (statusEl) statusEl.textContent = "Cargando…";
    fetch(
      DATA_URL +
        "?hours=" +
        encodeURIComponent(hours) +
        "&q=" +
        encodeURIComponent(q) +
        "&_=" +
        Date.now(),
      { credentials: "same-origin", headers: { Accept: "application/json" } },
    )
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        state.events = (res && res.events) || [];
        state.selectedId = null;
        renderList();
        if (statusEl) statusEl.textContent = state.events.length + " eventos";
      })
      .catch(function () {
        if (statusEl) statusEl.textContent = "Error al cargar";
        listEl.innerHTML =
          '<div class="tp-text-xs tp-text-muted" style="padding:12px;">No se pudieron cargar los eventos.</div>';
      });
  }

  // -----------------------------------------------------------
  // Eventos UI
  // -----------------------------------------------------------
  listEl.addEventListener("click", function (e) {
    var row = e.target.closest("[data-id]");
    if (!row) return;
    selectEvent(parseInt(row.getAttribute("data-id"), 10));
  });
  if (hoursSel) hoursSel.addEventListener("change", loadEvents);
  if (refreshBtn) refreshBtn.addEventListener("click", loadEvents);
  if (searchEl) {
    var t = null;
    searchEl.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(loadEvents, 350);
    });
  }

  initMap();
  loadEvents();
})();
