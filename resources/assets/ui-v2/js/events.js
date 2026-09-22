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
  var MAP_URL = root.getAttribute("data-map-url");
  var GEO_URL = root.getAttribute("data-geo-url");
  // Sección de direcciones gateada por el plugin (paridad Events/index.blade legacy).
  var ADDR_ON = root.getAttribute("data-addr-on") === "1";
  var ZONES_URL = root.getAttribute("data-zones-url");
  var POIS_URL = root.getAttribute("data-pois-url");
  var ROUTES_URL = root.getAttribute("data-routes-url");
  // "Atender" = registrar acción de llamada sobre el evento (perm call_actions+events).
  // El tilde del estado "Atendido", que pintan la lista y el popup.
  var ATENDIDO_ICONO =
    '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
  var CA_ON = root.getAttribute("data-ca-on") === "1";
  var CA_STORE_URL = root.getAttribute("data-ca-store-url");
  // Tipos de respuesta REALES (mismas etiquetas que la tabla de call actions).
  var RESP_TYPES = (function () {
    try {
      return JSON.parse(document.getElementById("tp-events-resp-types").textContent) || [];
    } catch (e) {
      return [];
    }
  })();
  // Catálogo COMPLETO de tipos de evento (etiquetas legacy) para el filtro Tipo.
  var EVENT_TYPES = (function () {
    try {
      return JSON.parse(document.getElementById("tp-events-types").textContent) || [];
    } catch (e) {
      return [];
    }
  })();
  var listEl = document.getElementById("tp-events-list");
  var statusEl = document.getElementById("tp-events-status");
  var hoursSel = document.getElementById("tp-events-hours");
  var searchEl = document.getElementById("tp-events-search");
  var refreshBtn = document.getElementById("tp-events-refresh");
  var hintEl = document.getElementById("tp-events-hint");
  var typeSel = document.getElementById("tp-events-type");
  var deviceSel = document.getElementById("tp-events-device");
  var exportBtn = document.getElementById("tp-events-export");
  var riskBtn = document.getElementById("tp-ev-toggle-risk");
  var legendEl = document.getElementById("tp-ev-legend");
  var legendNote = document.getElementById("tp-ev-legend-note");

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

  var CAN_REMOVE = root.getAttribute("data-can-remove") === "1";
  var DESTROY_URL = root.getAttribute("data-destroy-url") || "/ui/v2/api/events";

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
    riskLayer: null, // capa del mapa de riesgo (todos los eventos)
    riskOn: false,
    knownTypes: {}, // acumulado de tipos vistos → filtro por tipo
    maxId: 0, // mayor id cargado → detectar eventos nuevos (tiempo real)
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

  // Texto del evento: con el plugin event_section_alert activo el backend manda
  // el nombre de la alerta en `title` (paridad Event::getTitleAttribute()).
  function evTitle(ev) {
    return (ev && (ev.title || ev.message || ev.type)) || "";
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
    if (!ADDR_ON || !GEO_URL || ev.lat == null || ev.lng == null) return;
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
      '"></span><span class="tp-events__row-main"><span class="tp-events__row-titleline"><span class="tp-events__row-title">' +
      esc(evTitle(ev)) +
      "</span>" +
      (ev.attended
        ? '<span class="tp-events__attended">' + ATENDIDO_ICONO + 'Atendido</span>'
        : "") +
      '</span><span class="tp-events__row-sub">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      " · " +
      esc(ev.time) +
      "</span>" +
      (ADDR_ON
        ? '<span class="tp-events__addr" data-id="' + ev.id + '">' + addr + "</span>"
        : "") +
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
  function popupHtml(ev) {
    var addr = geoCache[ev.id] != null ? geoCache[ev.id] : "…";
    return (
      '<div class="tp-ev-popup"><div class="tp-ev-popup__title">' +
      esc(ev.device_name || "#" + (ev.device_id || "")) +
      (ev.plate ? " · " + esc(ev.plate) : "") +
      '</div><div class="tp-ev-popup__type tp-ev-popup__type--' +
      sevClass(ev.severity) +
      '">' +
      esc(evTitle(ev)) +
      '</div><dl class="tp-ev-popup__defs"><dt>Hora</dt><dd>' +
      esc(ev.time) +
      "</dd>" +
      (ADDR_ON ? "<dt>Ubicación</dt><dd data-ev-addr>" + esc(addr) + "</dd>" : "") +
      (ev.speed != null ? "<dt>Velocidad</dt><dd>" + Math.round(ev.speed) + " km/h</dd>" : "") +
      "<dt>Coordenadas</dt><dd>" +
      parseFloat(ev.lat).toFixed(5) +
      ", " +
      parseFloat(ev.lng).toFixed(5) +
      "</dd></dl>" +
      '<div style="display:flex;gap:6px;margin-top:8px;">' +
      // "Historial": abre la reproducción del vehículo alrededor del evento.
      (ev.device_id
        ? '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ev-history="' + ev.id + '" style="flex:1;">Historial</button>'
        : "") +
      // "Atender": solo con permiso, si el evento tiene alerta (el backend
      // exige alert_id) y si NO se atendió ya. La lista sí miraba `attended`
      // y el popup no, así que sobre un evento ya atendido seguía ofreciendo
      // el botón y se registraban dos acciones de llamada para el mismo
      // evento. Atendido: se muestra el estado, no un botón.
      (CA_ON && ev.alert_id
        ? (ev.attended
            ? '<span class="tp-events__attended" style="flex:1;justify-content:center;">' + ATENDIDO_ICONO + 'Atendido</span>'
            : '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm" data-ca-attend="' + ev.id + '" style="flex:1;">Atender</button>')
        : "") +
      // "Eliminar": paridad legacy events.destroy (perm events.clean → remove).
      (CAN_REMOVE
        ? '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-ev-del="' + ev.id + '" style="flex:0 0 auto;color:#ef4444;">Eliminar</button>'
        : "") +
      "</div></div>"
    );
  }

  /**
   * Repinta el popup abierto si es el de este evento. Se usa tras atender:
   * el estado vive en `ev`, pero el popup ya está en el DOM con el HTML de
   * antes, así que seguiría mostrando el botón "Atender".
   */
  function repintarPopup(ev) {
    if (!state.marker || state.selectedId !== ev.id) return;

    var popup = state.marker.getPopup();
    if (!popup) return;

    popup.setContent(popupHtml(ev));
  }

  // Componente fecha+hora: date custom (tp-datepicker) + 2 tp-select (HH:MM).
  function pad2(n) { return ("0" + n).slice(-2); }
  function nowParts() {
    var d = new Date();
    return { date: d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()), h: pad2(d.getHours()), m: pad2(d.getMinutes()) };
  }
  function hourOptions(sel) {
    var o = "";
    for (var i = 0; i < 24; i++) { var v = pad2(i); o += '<option value="' + v + '"' + (v === sel ? " selected" : "") + ">" + v + "</option>"; }
    return o;
  }
  function minOptions(sel) {
    var o = "";
    for (var i = 0; i < 60; i++) { var v = pad2(i); o += '<option value="' + v + '"' + (v === sel ? " selected" : "") + ">" + v + "</option>"; }
    return o;
  }

  // Modal "Atender evento" → POST a call-actions/store (deriva alert_id/device_id).
  function openAttendModal(ev) {
    if (!window.tpModal) return;
    var np = nowParts();
    var body =
      '<div class="tp-scope tp-form"><div class="tp-form__grid">' +
      '<div class="tp-form__field"><span class="tp-form__label">Evento</span>' +
      '<input class="tp-input" value="' + esc((evTitle(ev)) + " · " + (ev.device_name || "")) + '" readonly></div>' +
      '<div class="tp-form__field tp-form__field--required"><span class="tp-form__label">Tipo de respuesta</span>' +
      '<select class="tp-input" data-ca-resp>' +
      RESP_TYPES.map(function (t) { return '<option value="' + esc(t.type) + '">' + esc(t.title) + "</option>"; }).join("") +
      "</select></div>" +
      '<div class="tp-form__field tp-form__field--required"><span class="tp-form__label">Fecha de llamada</span>' +
      '<div style="display:flex;gap:8px;align-items:center;">' +
      '<input type="date" class="tp-input" data-ca-date value="' + np.date + '" style="flex:1;min-width:0;">' +
      '<select class="tp-input" data-ca-h style="width:74px;">' + hourOptions(np.h) + "</select>" +
      '<span style="font-weight:600;">:</span>' +
      '<select class="tp-input" data-ca-m style="width:74px;">' + minOptions(np.m) + "</select>" +
      "</div></div>" +
      '<label class="tp-form__field tp-form__field--required"><span class="tp-form__label">Notas</span>' +
      '<textarea data-ca-notes rows="2" data-tp-field></textarea></label>' +
      '</div><div data-ca-err style="display:none;color:#ef4444;font-size:13px;margin-top:8px;"></div></div>';
    tpModal.open({
      title: "Atender evento",
      size: "md",
      body: body,
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        { label: "Registrar", variant: "primary", close: false, onClick: function (d) { submitAttend(d, ev); return false; } },
      ],
    });
  }

  function submitAttend(d, ev) {
    var err = d.querySelector("[data-ca-err]");
    function showErr(m) { if (err) { err.textContent = m; err.style.display = "block"; } }
    if (err) err.style.display = "none";
    var dateV = (window.tpDate ? tpDate.value(d.querySelector("[data-ca-date]"))
                                : d.querySelector("[data-ca-date]").value || "").trim();
    var hV = d.querySelector("[data-ca-h]").value || "00";
    var mV = d.querySelector("[data-ca-m]").value || "00";
    var payload = {
      event_id: ev.id,
      response_type: d.querySelector("[data-ca-resp]").value,
      called_at: dateV ? dateV + " " + hV + ":" + mV : "",
      remarks: d.querySelector("[data-ca-notes]").value.trim(),
    };
    if (!dateV) return showErr("La fecha de llamada es obligatoria.");
    if (!payload.remarks) return showErr("Las notas son obligatorias.");
    tpModal.setBusy(d, true);
    tpModal
      .fetchJSON(CA_STORE_URL, { method: "POST", json: payload })
      .then(function (res) {
        tpModal.setBusy(d, false);
        if (res.status === 422) {
          var errors = (res.data && res.data.errors) || {};
          var first = Object.keys(errors)[0];
          return showErr(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "Revisa los campos.");
        }
        if (!res.ok || !res.data || !res.data.ok) return showErr("No se pudo registrar.");
        // Update optimista: marca el evento como atendido y re-pinta su fila,
        // así el badge "Atendido" aparece al instante (sin recargar).
        ev.attended = true;
        var rowEl = listEl.querySelector('.tp-events__row[data-id="' + ev.id + '"]');
        if (rowEl) rowEl.outerHTML = rowHtml(ev);
        // Y el popup del mapa, si es el de este evento: si no, se queda con
        // el botón "Atender" invitando a registrar la acción otra vez.
        repintarPopup(ev);
        tpModal.close(d);
        tpModal.toast("Evento atendido.", "success");
      })
      .catch(function () { tpModal.setBusy(d, false); showErr("Error de red."); });
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
      // .off antes de remover para que el cambio de evento NO dispare el
      // limpiado de abajo (evita la carrera al alternar entre eventos).
      state.marker.off("popupclose");
      state.map.removeLayer(state.marker);
      state.marker = null;
    }
    var m = L.marker(ll, { icon: eventIcon(ev) }).addTo(state.map);
    state.marker = m;
    m.bindPopup(popupHtml(ev), { offset: [0, -10] }).openPopup();
    // Al CERRAR el popup, quitar también el marcador (mapa limpio) y
    // deseleccionar la fila. Solo si sigue siendo el marcador activo.
    m.on("popupclose", function () {
      if (state.marker !== m) return;
      state.map.removeLayer(m);
      state.marker = null;
      state.selectedId = null;
      var sel = listEl.querySelector('[aria-selected="true"]');
      if (sel) sel.removeAttribute("aria-selected");
      if (hintEl) hintEl.hidden = false;
    });
    state.map.flyTo(ll, Math.max(state.map.getZoom(), 15), { duration: 0.6 });
  }

  // ── Carga (scroll infinito) ────────────────────────────────
  /** Rango absoluto del panel (tiene prioridad sobre la ventana relativa). */
  function rangeQS() {
    var f = document.getElementById("tp-events-from");
    var t = document.getElementById("tp-events-to");
    var qs = "";
    if (f && f.value) qs += "&date_from=" + encodeURIComponent(f.value + " 00:00:00");
    if (t && t.value) qs += "&date_to=" + encodeURIComponent(t.value + " 23:59:59");
    return qs;
  }

  /** Borra un evento (id) o TODOS los del usuario (id null). */
  function deleteEvents(id) {
    if (!CAN_REMOVE) return;
    tpModal
      .confirm({
        title: id ? "Eliminar evento" : "Borrar todos los eventos",
        message: id
          ? "¿Eliminar este evento? No se puede deshacer."
          : "Se borrarán TODOS tus eventos. Esta acción no se puede deshacer.",
        danger: true,
        confirmLabel: "Eliminar",
      })
      .then(function (ok) {
        if (!ok) return;
        tpModal
          .fetchJSON(DESTROY_URL + "/" + (id || "all"), { method: "DELETE" })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              tpModal.toast("No se pudo eliminar.", "error");
              return;
            }
            tpModal.toast(id ? "Evento eliminado." : "Eventos eliminados.", "success");
            if (state.map && state.marker) {
              try { state.map.closePopup(); } catch (e) {}
            }
            load(true);
          })
          .catch(function () { tpModal.toast("Error de red.", "error"); });
      });
  }

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
    var hours = hoursSel ? hoursSel.value : 0;
    var type = typeSel ? typeSel.value : "";
    var deviceId = deviceSel ? deviceSel.value : "";
    var q = searchEl ? searchEl.value.trim() : "";
    fetch(
      DATA_URL + "?hours=" + encodeURIComponent(hours) + "&type=" + encodeURIComponent(type) + "&device_id=" + encodeURIComponent(deviceId) + "&q=" + encodeURIComponent(q) + rangeQS() + "&offset=" + state.offset + "&_=" + Date.now(),
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
          if (ev.id > state.maxId) state.maxId = ev.id;
        });
        state.offset = res && res.offset != null ? res.offset : state.offset + rows.length;
        state.hasMore = !!(res && res.has_more);
        if (reset && !state.events.length) {
          listEl.innerHTML = '<div class="tp-text-xs tp-text-muted" style="padding:12px;">Sin eventos en el rango seleccionado.</div>';
        } else {
          appendRows(rows);
        }
        if (statusEl) statusEl.textContent = state.events.length + " eventos" + (state.hasMore ? " (+)" : "");
        // Si el mapa de riesgo está activo, refréscalo con el mismo filtro.
        if (reset && state.riskOn) loadRisk();
      })
      .catch(function () {
        state.loading = false;
        if (statusEl) statusEl.textContent = "Error al cargar";
      });
  }

  /* ── Filtro por tipo: poblado UNA vez desde el catálogo completo (legacy) ── */
  function populateTypeOptions() {
    if (!typeSel || !EVENT_TYPES.length) return;
    var cur = typeSel.value;
    typeSel.innerHTML =
      '<option value="">Todos los tipos</option>' +
      EVENT_TYPES.map(function (t) {
        return '<option value="' + esc(t.type) + '"' + (t.type === cur ? " selected" : "") + ">" + esc(t.title) + "</option>";
      }).join("");
    if (window.tpSelect && tpSelect.refresh) try { tpSelect.refresh(typeSel); } catch (e) {}
  }

  /* ── #5 "Ver en historial": rango derivado de la antigüedad del evento ── */
  function historyRange(timeStr) {
    var t = Date.parse(String(timeStr || "").replace(" ", "T"));
    if (isNaN(t)) return "today";
    var days = (Date.now() - t) / 86400000;
    if (days < 1) return "today";
    if (days < 2) return "yesterday";
    if (days < 7) return "week";
    return "month";
  }

  /* ── #4 Tiempo real: beep (WebAudio, sin assets) para eventos críticos ── */
  function beep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = beep._ctx || (beep._ctx = new Ctx());
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = "sine"; o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
      g.gain.value = 0.05; o.start(); o.stop(ctx.currentTime + 0.15);
    } catch (e) {}
  }
  function pollLive() {
    if (state.loading) return;
    var hours = hoursSel ? hoursSel.value : 0;
    var type = typeSel ? typeSel.value : "";
    var deviceId = deviceSel ? deviceSel.value : "";
    var q = searchEl ? searchEl.value.trim() : "";
    fetch(
      DATA_URL + "?hours=" + encodeURIComponent(hours) + "&type=" + encodeURIComponent(type) + "&device_id=" + encodeURIComponent(deviceId) + "&q=" + encodeURIComponent(q) + rangeQS() + "&offset=0&_=" + Date.now(),
      { credentials: "same-origin", headers: { Accept: "application/json" } },
    )
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var rows = (res && res.events) || [];
        var fresh = rows.filter(function (ev) { return ev.id > state.maxId; });
        if (!fresh.length) return;
        fresh.forEach(function (ev) {
          if (!state.byId[ev.id]) { state.byId[ev.id] = ev; state.events.unshift(ev); }
          if (ev.id > state.maxId) state.maxId = ev.id;
        });
        var crit = fresh.some(function (ev) { return ev.severity === "critical"; });
        if (crit) beep();
        // Solo insertar en la lista si el usuario está arriba (no molestar lectura).
        if (listEl.scrollTop < 40) {
          var frag = document.createElement("div");
          frag.innerHTML = fresh.map(rowHtml).join("");
          var ref = listEl.firstChild;
          while (frag.firstChild) { var n = frag.firstChild; listEl.insertBefore(n, ref); if (io && n.getAttribute) io.observe(n); }
        }
        if (statusEl) statusEl.textContent = state.events.length + " eventos" + (state.hasMore ? " (+)" : "");
        if (state.riskOn) loadRisk();
        if (window.tpModal && window.tpModal.toast) window.tpModal.toast(fresh.length + " evento(s) nuevo(s)", crit ? "warning" : "info");
      })
      .catch(function () {});
  }

  /* ── #6 Exportar CSV (eventos cargados) ── */
  function csvCell(v) { v = String(v == null ? "" : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
  function exportCsv() {
    if (!state.events.length) { if (window.tpModal) tpModal.toast("No hay eventos para exportar.", "warning"); return; }
    var head = ["Evento", "Vehiculo", "Fecha", "Latitud", "Longitud", "Atendido"];
    var lines = [head.join(",")];
    state.events.forEach(function (ev) {
      lines.push([evTitle(ev), ev.device_name || "", ev.time || "", ev.lat != null ? ev.lat : "", ev.lng != null ? ev.lng : "", ev.attended ? "Si" : "No"].map(csvCell).join(","));
    });
    var blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8;" });
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "eventos.csv";
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }

  /* ── #7 Mapa de riesgo: todos los eventos por severidad + leyenda ── */
  function loadRisk() {
    if (!MAP_URL || !state.map) return;
    var hours = hoursSel ? hoursSel.value : 0;
    var type = typeSel ? typeSel.value : "";
    var deviceId = deviceSel ? deviceSel.value : "";
    var q = searchEl ? searchEl.value.trim() : "";
    if (legendNote) legendNote.textContent = "Cargando…";
    fetch(
      MAP_URL + "?hours=" + encodeURIComponent(hours) + "&type=" + encodeURIComponent(type) + "&device_id=" + encodeURIComponent(deviceId) + "&q=" + encodeURIComponent(q) + rangeQS() + "&_=" + Date.now(),
      { credentials: "same-origin", headers: { Accept: "application/json" } },
    )
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (state.riskLayer) { state.map.removeLayer(state.riskLayer); state.riskLayer = null; }
        var pts = (res && res.points) || [];
        var g = L.layerGroup();
        pts.forEach(function (p) {
          L.circleMarker([p.lat, p.lng], { radius: 5, color: "#fff", weight: 1, fillColor: sevColor(p.severity), fillOpacity: 0.9 })
            .bindTooltip(esc(evTitle(p) + " · " + (p.device_name || "")))
            .bindPopup(
              '<div class="tp-ev-popup"><div class="tp-ev-popup__title">' + esc(p.device_name || "") + "</div>" +
              '<div class="tp-ev-popup__type tp-ev-popup__type--' + sevClass(p.severity) + '">' + esc(evTitle(p)) + "</div>" +
              '<dl class="tp-ev-popup__defs"><dt>Hora</dt><dd>' + esc(p.time) + "</dd><dt>Coordenadas</dt><dd>" + p.lat.toFixed(5) + ", " + p.lng.toFixed(5) + "</dd></dl></div>",
            )
            .addTo(g);
        });
        state.riskLayer = g;
        if (state.riskOn) g.addTo(state.map);
        if (legendNote) legendNote.textContent = pts.length + " eventos" + (res && res.capped ? " (máx " + res.cap + ")" : "");
      })
      .catch(function () { if (legendNote) legendNote.textContent = "Error al cargar"; });
  }
  function toggleRisk() {
    state.riskOn = !state.riskOn;
    if (riskBtn) riskBtn.setAttribute("aria-pressed", state.riskOn ? "true" : "false");
    if (legendEl) legendEl.hidden = !state.riskOn;
    if (state.riskOn) loadRisk();
    else if (state.riskLayer) state.map.removeLayer(state.riskLayer);
  }

  // ── UI ─────────────────────────────────────────────────────
  listEl.addEventListener("click", function (e) {
    var row = e.target.closest(".tp-events__row[data-id]");
    if (!row) return;
    selectEvent(parseInt(row.getAttribute("data-id"), 10));
  });
  // Botón "Atender" dentro del popup del mapa (delegado: el popup se inyecta al DOM).
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-ca-attend]");
    if (!btn) return;
    var ev = state.byId[btn.getAttribute("data-ca-attend")];
    if (ev) openAttendModal(ev);
  });
  // "Eliminar" del popup (delegado: el popup se inyecta al DOM).
  document.addEventListener("click", function (e) {
    var b = e.target.closest && e.target.closest("[data-ev-del]");
    if (!b) return;
    deleteEvents(parseInt(b.getAttribute("data-ev-del"), 10));
  });
  var clearBtn = document.getElementById("tp-events-clear");
  if (clearBtn) clearBtn.addEventListener("click", function () { deleteEvents(null); });
  ["tp-events-from", "tp-events-to"].forEach(function (idc) {
    var el = document.getElementById(idc);
    if (el) el.addEventListener("change", function () { load(true); });
  });
  var rangeClear = document.getElementById("tp-events-range-clear");
  if (rangeClear)
    rangeClear.addEventListener("click", function () {
      ["tp-events-from", "tp-events-to"].forEach(function (idc) {
        var el = document.getElementById(idc);
        if (el) el.value = "";
      });
      load(true);
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
  if (typeSel) typeSel.addEventListener("change", function () { load(true); });
  if (deviceSel) deviceSel.addEventListener("change", function () { load(true); });
  if (exportBtn) exportBtn.addEventListener("click", exportCsv);
  if (riskBtn) riskBtn.addEventListener("click", toggleRisk);
  // #5 "Ver en historial" desde el popup del evento (delegado en document).
  document.addEventListener("click", function (e) {
    var hb = e.target.closest && e.target.closest("[data-ev-history]");
    if (!hb) return;
    var ev = state.byId[hb.getAttribute("data-ev-history")];
    if (ev && ev.device_id) window.location.href = "/ui/v2/history?device=" + ev.device_id + "&range=" + historyRange(ev.time);
  });
  // #4 Tiempo real: sondeo de eventos nuevos cada 30 s.
  setInterval(pollLive, 30000);

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
      L.polyline(pts, { color: r.color || "#7c3aed", weight: 5, opacity: 0.85, dashArray: "6 6" }).bindTooltip(r.name || "Ruta").addTo(g);
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

  populateTypeOptions();
  initMap();
  load(true);
})();
