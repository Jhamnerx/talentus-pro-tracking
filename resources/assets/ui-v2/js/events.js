/*
 * UI v2 · Eventos (página dedicada con mapa) — motor LEAFLET
 * - Lista con scroll infinito + geocodificación perezosa (filas visibles).
 * - Al seleccionar un evento: centra el mapa y muestra el marcador del device
 *   (icono real) con popup. Capas base vía tp-basemaps; overlays geocercas/
 *   rutas/POIs en Leaflet.
 */
(function () {
  "use strict";
  var root = document.getElementById("tp-events");
  if (!root || !window.L) return;

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
      return JSON.parse(document.getElementById("tp-events-map-config").textContent || "{}");
    } catch (e) {
      return {};
    }
  })();
  function csrf() {
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }

  var state = {
    events: [],
    byId: {},
    offset: 0,
    hasMore: true,
    loading: false,
    map: null,
    baseLayers: {},
    currentBase: null,
    marker: null,
    selectedId: null,
    overlays: { geofences: null, routes: null, poi: null },
    toggles: (MAP_CFG && MAP_CFG.toggles) || {},
  };
  var geoCache = {};

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function sevClass(sev) {
    return sev === "critical" ? "danger" : sev === "warning" ? "warning" : "info";
  }
  function sevColor(sev) {
    return sev === "critical" ? "#ef4444" : sev === "warning" ? "#f59e0b" : "#3b82f6";
  }
  function assetPath(p) {
    if (!p) return "";
    if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p;
    return "/" + p;
  }

  // ── Icono Leaflet del evento (icono real del device) ───────
  function eventIcon(ev) {
    var icon = ev.icon || null;
    if (!icon) {
      return L.divIcon({
        className: "tp-ev-ldot",
        html:
          '<span style="display:block;width:16px;height:16px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:' +
          sevColor(ev.severity) +
          ';border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)"></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 16],
      });
    }
    var type = icon.type || "arrow";
    var src, w, h, rotate = false, bottom = false;
    if (type === "arrow") {
      src = "/images/device_icons/estados/" + (ev.icon_color || icon.color || "gray") + ".png";
      w = 25;
      h = 33;
      rotate = true;
    } else if (type === "rotating") {
      src = assetPath(icon.path);
      w = icon.width || 32;
      h = icon.height || 32;
      rotate = true;
    } else {
      src = assetPath(icon.path);
      w = icon.width || 32;
      h = icon.height || 32;
      bottom = true;
    }
    var rot = rotate ? "transform:rotate(" + (parseFloat(ev.course) || 0) + "deg);" : "";
    return L.divIcon({
      className: "tp-ev-ldot",
      html: '<img src="' + src + '" width="' + w + '" height="' + h + '" style="display:block;' + rot + '">',
      iconSize: [w, h],
      iconAnchor: bottom ? [w / 2, h] : [w / 2, h / 2],
    });
  }

  // ── Mapa Leaflet + capas base ──────────────────────────────
  function initMap() {
    state.map = L.map("tp-events-map", {
      center: [-12.0464, -77.0428],
      zoom: 5,
      zoomControl: false,
      attributionControl: true,
    });
    state.baseLayers =
      window.tpBasemaps && window.tpBasemaps.build
        ? window.tpBasemaps.build(MAP_CFG)
        : { "2": { name: "OSM", layer: L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png") } };
    var id =
      window.tpBasemaps && window.tpBasemaps.defaultId
        ? window.tpBasemaps.defaultId(state.baseLayers, MAP_CFG)
        : Object.keys(state.baseLayers)[0];
    if (id) setBaseLayer(id);
    renderOverlays();
  }
  function setBaseLayer(id) {
    var entry = state.baseLayers[id];
    if (!entry) return;
    if (state.currentBase && state.baseLayers[state.currentBase])
      state.map.removeLayer(state.baseLayers[state.currentBase].layer);
    entry.layer.addTo(state.map);
    if (entry.layer.bringToBack) entry.layer.bringToBack();
    state.currentBase = id;
  }

  // ── Geocodificación perezosa ───────────────────────────────
  function parseGeo(text) {
    if (text == null) return "";
    text = String(text).trim();
    if (!text) return "";
    try {
      var j = JSON.parse(text);
      if (typeof j === "string") return j;
      if (j && typeof j === "object") return j.address || j.name || j.title || "";
    } catch (e) {}
    var lt = text.indexOf("<");
    if (lt !== -1) text = text.slice(0, lt).trim();
    return text;
  }
  function geocodeEvent(ev) {
    if (!GEO_URL || ev.lat == null || ev.lng == null) return;
    if (geoCache[ev.id] !== undefined) return;
    geoCache[ev.id] = null;
    fetch(
      GEO_URL + (GEO_URL.indexOf("?") === -1 ? "?" : "&") + "lat=" + ev.lat + "&lng=" + ev.lng,
      { credentials: "same-origin", headers: { Accept: "*/*", "X-Requested-With": "XMLHttpRequest" } },
    )
      .then(function (r) {
        return r.text();
      })
      .then(function (text) {
        var addr = parseGeo(text);
        geoCache[ev.id] = addr;
        var span = listEl.querySelector('.tp-events__addr[data-id="' + ev.id + '"]');
        if (span) span.textContent = addr || "—";
        if (state.selectedId === ev.id && state.marker && state.marker.getPopup()) {
          var el = state.marker.getPopup().getElement();
          var dd = el && el.querySelector("[data-ev-addr]");
          if (dd) dd.textContent = addr || "—";
        }
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
    var addr = geoCache[ev.id] != null ? esc(geoCache[ev.id]) : hasGeo ? "…" : "sin ubicación";
    return (
      '<button type="button" class="tp-events__row" data-id="' +
      ev.id +
      '" data-geo-id="' +
      ev.id +
      '"' +
      (state.selectedId === ev.id ? ' aria-selected="true"' : "") +
      '><span class="tp-events__dot tp-events__dot--' +
      sevClass(ev.severity) +
      '"></span><span class="tp-events__row-main"><span class="tp-events__row-title">' +
      esc(ev.message || ev.type) +
      '</span><span class="tp-events__row-sub">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      " · " +
      esc(ev.time) +
      '</span><span class="tp-events__addr" data-id="' +
      ev.id +
      '">' +
      addr +
      "</span></span></button>"
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
  function popupHtml(ev) {
    var addr = geoCache[ev.id] != null ? geoCache[ev.id] : "…";
    return (
      '<div class="tp-ev-popup"><div class="tp-ev-popup__title">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      (ev.plate ? " · " + esc(ev.plate) : "") +
      '</div><div class="tp-ev-popup__type tp-ev-popup__type--' +
      sevClass(ev.severity) +
      '">' +
      esc(ev.message || ev.type) +
      '</div><dl class="tp-ev-popup__defs"><dt>Hora</dt><dd>' +
      esc(ev.time) +
      "</dd><dt>Ubicación</dt><dd data-ev-addr>" +
      esc(addr) +
      "</dd>" +
      (ev.speed != null ? "<dt>Velocidad</dt><dd>" + Math.round(ev.speed) + " km/h</dd>" : "") +
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
      if (window.tpModal && window.tpModal.toast) window.tpModal.toast("Este evento no tiene ubicación.", "warning");
      return;
    }
    if (hintEl) hintEl.hidden = true;
    geocodeEvent(ev);
    var ll = [parseFloat(ev.lat), parseFloat(ev.lng)];
    if (state.marker) {
      state.map.removeLayer(state.marker);
      state.marker = null;
    }
    state.marker = L.marker(ll, { icon: eventIcon(ev) }).addTo(state.map);
    state.marker.bindPopup(popupHtml(ev), { offset: [0, -10] }).openPopup();
    state.map.flyTo(ll, Math.max(state.map.getZoom(), 15), { duration: 0.6 });
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
    if (statusEl) statusEl.textContent = reset ? "Cargando…" : "Cargando más…";
    var hours = hoursSel ? hoursSel.value : 24;
    var q = searchEl ? searchEl.value.trim() : "";
    fetch(
      DATA_URL + "?hours=" + encodeURIComponent(hours) + "&q=" + encodeURIComponent(q) + "&offset=" + state.offset + "&_=" + Date.now(),
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
          listEl.innerHTML = '<div class="tp-text-xs tp-text-muted" style="padding:12px;">Sin eventos en el rango seleccionado.</div>';
        } else {
          appendRows(rows);
        }
        if (statusEl) statusEl.textContent = state.events.length + " eventos" + (state.hasMore ? " (+)" : "");
      })
      .catch(function () {
        state.loading = false;
        if (statusEl) statusEl.textContent = "Error al cargar";
      });
  }

  // ── UI ─────────────────────────────────────────────────────
  listEl.addEventListener("click", function (e) {
    var row = e.target.closest(".tp-events__row[data-id]");
    if (!row) return;
    selectEvent(parseInt(row.getAttribute("data-id"), 10));
  });
  listEl.addEventListener("scroll", function () {
    if (state.hasMore && !state.loading && listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 120) load(false);
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
          if (state.map) setTimeout(function () { state.map.invalidateSize(); }, 150);
        });
      } else if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    });
  if (ziBtn) ziBtn.addEventListener("click", function () { state.map && state.map.zoomIn(); });
  if (zoBtn) zoBtn.addEventListener("click", function () { state.map && state.map.zoomOut(); });

  // Selector de capa base (persiste en user->map_id → compartido con Live)
  var styleBtn = document.getElementById("tp-ev-style");
  var provMenu = document.getElementById("tp-ev-providers");
  if (styleBtn && provMenu) {
    styleBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      if (provMenu.hidden) {
        provMenu.innerHTML = Object.keys(state.baseLayers)
          .map(function (id) {
            return (
              '<button type="button" class="tp-events__provider' +
              (id === state.currentBase ? " is-active" : "") +
              '" data-map-id="' +
              id +
              '">' +
              esc(state.baseLayers[id].name) +
              "</button>"
            );
          })
          .join("");
        provMenu.hidden = false;
      } else {
        provMenu.hidden = true;
      }
    });
    provMenu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-map-id]");
      if (!b) return;
      var id = b.getAttribute("data-map-id");
      setBaseLayer(id);
      provMenu.hidden = true;
      try {
        var fd = new FormData();
        fd.append("key", "tp_map_id");
        fd.append("value", String(id));
        fetch("/ui/v2/api/preferences", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-CSRF-TOKEN": csrf() } }).catch(function () {});
      } catch (e2) {}
    });
    document.addEventListener("click", function () {
      if (!provMenu.hidden) provMenu.hidden = true;
    });
  }

  // ── Overlays Leaflet: geocercas / rutas / POIs ─────────────
  function circlePoly(lat, lng, radius) {
    return L.circle([lat, lng], { radius: radius });
  }
  function buildGeofences(items) {
    var g = L.layerGroup();
    (items || []).forEach(function (z) {
      var color = z.polygon_color || "#1e88e5";
      var style = { color: color, weight: 2, fillColor: color, fillOpacity: 0.15 };
      var layer = null;
      if (z.type === "circle" && z.center && z.radius) {
        var c = z.center;
        var lat = c.lat != null ? c.lat : c.y,
          lng = c.lng != null ? c.lng : c.x;
        if (typeof c === "string") {
          var p = c.split(",");
          lat = parseFloat(p[0]);
          lng = parseFloat(p[1]);
        }
        layer = L.circle([lat, lng], Object.assign({ radius: Number(z.radius) }, style));
      } else if (z.coordinates && z.coordinates.length >= 3) {
        layer = L.polygon(
          z.coordinates.map(function (pt) {
            return [pt.lat != null ? pt.lat : pt.y, pt.lng != null ? pt.lng : pt.x];
          }),
          style,
        );
      }
      if (layer) {
        if (z.name) layer.bindTooltip(z.name);
        g.addLayer(layer);
      }
    });
    return g;
  }
  function buildRoutes(items) {
    var g = L.layerGroup();
    (items || []).forEach(function (r) {
      var pts = (r.coordinates || []).map(function (c) {
        return [c[1], c[0]];
      });
      if (pts.length < 2) return;
      L.polyline(pts, { color: r.color || "#7c3aed", weight: 3, opacity: 0.85 }).bindTooltip(r.name || "Ruta").addTo(g);
    });
    return g;
  }
  function buildPois(items) {
    var g = L.layerGroup();
    (items || []).forEach(function (p) {
      if (p.lat == null || p.lng == null) return;
      var icon =
        p.icon && p.icon.path
          ? L.icon({ iconUrl: assetPath(p.icon.path), iconSize: [p.icon.width || 24, p.icon.height || 24] })
          : L.divIcon({ className: "tp-ev-ldot", html: '<span style="display:block;width:12px;height:12px;border-radius:50%;background:#7c3aed;border:2px solid #fff"></span>', iconSize: [12, 12], iconAnchor: [6, 6] });
      L.marker([p.lat, p.lng], { icon: icon }).bindTooltip(p.name || "POI").addTo(g);
    });
    return g;
  }
  function loadOverlay(kind, url, builder) {
    fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        state.overlays[kind] = builder(j && j.items);
        if (state.toggles[kind]) state.overlays[kind].addTo(state.map);
      })
      .catch(function () {});
  }
  function setOverlay(kind, on) {
    state.toggles[kind] = on;
    if (kind === "hitos") {
      // Hitos kilométricos (capa compartida tp-hitos.js, recarga por viewport)
      if (window.tpHitos) {
        if (!state.hitosCtrl) state.hitosCtrl = tpHitos.create(state.map);
        state.hitosCtrl.setVisible(on);
      }
      return;
    }
    var ov = state.overlays[kind];
    if (on) {
      if (ov) ov.addTo(state.map);
      else if (kind === "geofences") loadOverlay("geofences", ZONES_URL, buildGeofences);
      else if (kind === "routes") loadOverlay("routes", ROUTES_URL, buildRoutes);
      else if (kind === "poi") loadOverlay("poi", POIS_URL, buildPois);
    } else if (ov) {
      state.map.removeLayer(ov);
    }
  }
  function renderOverlays() {
    ["geofences", "routes", "poi"].forEach(function (k) {
      if (state.toggles[k]) setOverlay(k, true);
    });
  }
  [
    { id: "tp-ev-toggle-geofences", key: "geofences" },
    { id: "tp-ev-toggle-routes", key: "routes" },
    { id: "tp-ev-toggle-poi", key: "poi" },
    { id: "tp-ev-toggle-hitos", key: "hitos" },
  ].forEach(function (t) {
    var btn = document.getElementById(t.id);
    if (!btn) return;
    btn.setAttribute("aria-pressed", state.toggles[t.key] ? "true" : "false");
    btn.addEventListener("click", function () {
      var v = !state.toggles[t.key];
      btn.setAttribute("aria-pressed", v ? "true" : "false");
      setOverlay(t.key, v);
    });
  });

  initMap();
  load(true);
})();
