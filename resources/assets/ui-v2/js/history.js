/* =====================================================
 * TalentusPro · History & Replay — motor LEAFLET (v2 integrado)
 * - Sidebar tipo Live: device searcheable + date-picker modal + avanzado
 *   (paradas / snap-to-road / coords no válidas) + acciones + timeline rico.
 * - Recorrido coloreado (simple/velocidad/viajes) + flechas + cursor device.
 * - Cards resumen flotantes sobre el mapa.
 * - Footer: Gráfico (canvas sincronizado + scrubber) / Registro de datos
 *   (tabla paginada, reusa el pipeline legalcy getMessages).
 * Reusa el pipeline legacy (HistoryModalHelper getApi/getMessages).
 * ===================================================== */
(function () {
  "use strict";
  var root = document.getElementById("tp-history");
  if (!root || !window.L) return;

  var CFG = {
    dataUrl: root.getAttribute("data-data-url"),
    messagesUrl: root.getAttribute("data-messages-url"),
    devicesUrl: root.getAttribute("data-devices-url"),
    geoUrl: root.getAttribute("data-geo-url"),
    addrOn: root.getAttribute("data-addr-on") === "1",
    zonesUrl: root.getAttribute("data-zones-url"),
    poisUrl: root.getAttribute("data-pois-url"),
    routesUrl: root.getAttribute("data-routes-url"),
    exportUrl: root.getAttribute("data-export-url"),
    positionUrl: root.getAttribute("data-position-url"),
  };
  var MAP_CFG = (function () {
    try { return JSON.parse(document.getElementById("tp-history-map-config").textContent || "{}"); }
    catch (e) { return {}; }
  })();

  // Traducciones (archivos de traduccion del backend, como el legacy)
  var I18N = (function () {
    try { return JSON.parse(document.getElementById("tp-hist-i18n").textContent || "{}"); }
    catch (e) { return {}; }
  })();
  function T(key, fallback) { return I18N[key] || fallback || key; }

  var S_DRIVE = 1, S_STOP = 2, S_START = 3, S_END = 4, S_EVENT = 5;
  var DRIVE_PALETTE = ["#2563eb", "#16a34a", "#0891b2", "#7c3aed", "#db2777", "#ea580c", "#ca8a04", "#0d9488", "#9333ea", "#dc2626"];

  var state = {
    devices: [], deviceId: null, deviceName: "", device: null,
    positions: [], timeline: [], segments: [], summary: null, sensors: [],
    range: null,
    colorMode: "trips", routeSingle: "#2563eb", routeRanges: null, sensor: "speed", sensorList: null,
    cursorIdx: 0, playing: false, rate: 8, rafId: null, lastTs: 0,
    view: { s: 0, e: 0 },
    map: null, baseLayers: {}, currentBase: null,
    routeGroup: null, decorator: null, stopGroup: null, eventGroup: null, endGroup: null,
    cursorMarker: null, cursorCourse: -999, pointPopup: null,
    overlays: { geofences: null, routes: null, poi: null },
    focusIdx: null, focusRange: null, focusLayer: null, focusPopup: null,
    display: { route: true, arrows: true, stops: true, events: false, geofences: false, poi: false, routes: false },
    dl: { page: 1, last: 1 },
  };
  var geoCache = {};

  function $(s) { return document.querySelector(s); }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function pad2(n) { return (n < 10 ? "0" : "") + n; }
  function toast(m, k) { if (window.tpModal && tpModal.toast) tpModal.toast(m, k || "info"); else if (k === "warning" || k === "error") alert(m); }
  function assetPath(p) { if (!p) return ""; if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p; return "/" + p; }
  function csrf() { return (window.Talentus && window.Talentus.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content || ""; }
  function fmtTime(t) { try { return new Date(String(t).replace(" ", "T")).toLocaleString(); } catch (e) { return t; } }
  function fmtClock(t) { try { var d = new Date(String(t).replace(" ", "T")); return pad2(d.getHours()) + ":" + pad2(d.getMinutes()); } catch (e) { return ""; } }
  function fmtDur(sec) { sec = Math.max(0, Math.floor(sec)); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60; if (h > 0) return h + "h " + pad2(m) + "m"; if (m > 0) return m + "m " + pad2(s) + "s"; return s + "s"; }

  // ── Colores del recorrido (PROPIEDAD del vehículo, estilo Wialon) ──
  // El modo/colores vienen del device (route_color_type/route_color/
  // route_speed_ranges) — el sidebar ya no tiene selector.
  var DEFAULT_RANGES = [
    { from: 0, to: 40, color: "#22d3ee" },
    { from: 40, to: 60, color: "#3b82f6" },
    { from: 60, to: 90, color: "#6366f1" },
    { from: 90, to: 120, color: "#a855f7" },
    { from: 120, to: null, color: "#ef4444" },
  ];
  function speedColor(spd) {
    spd = spd || 0;
    var R = state.routeRanges || DEFAULT_RANGES;
    for (var i = 0; i < R.length; i++) {
      var r = R[i];
      if (r.to == null || spd < r.to) return r.color || "#2563eb";
    }
    return R.length ? R[R.length - 1].color : "#2563eb";
  }
  function segColorAt(idx) {
    for (var i = 0; i < state.segments.length; i++) {
      var s = state.segments[i];
      if (idx >= s.s && idx <= s.e) return s.status === S_DRIVE ? DRIVE_PALETTE[s.colorIdx % DRIVE_PALETTE.length] : "#94a3b8";
    }
    return "#94a3b8";
  }
  function colorAt(idx) {
    if (state.colorMode === "single") return state.routeSingle || "#2563eb";
    if (state.colorMode === "trips") return segColorAt(idx);
    // speed (y sensor → fallback a speed hasta que los sensores definan rangos)
    return speedColor(state.positions[idx] ? state.positions[idx].spd : 0);
  }

  /* ===== Combobox de vehículos ===== */
  var combo = $("#tp-hist-device"), comboInput = $("#tp-hist-device-input"), comboMenu = $("#tp-hist-device-menu");
  function loadDevices() {
    return fetch(CFG.devicesUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (j) { state.devices = (j && j.items) || []; renderDeviceMenu(""); })
      .catch(function () { state.devices = []; });
  }
  function renderDeviceMenu(q) {
    q = (q || "").toLowerCase().trim();
    var list = state.devices.filter(function (d) { return !q || (d.name + " " + (d.plate || "")).toLowerCase().indexOf(q) !== -1; }).slice(0, 200);
    comboMenu.innerHTML = list.length ? list.map(function (d) {
      return '<button type="button" class="tp-combo__item' + (state.deviceId === d.id ? " is-active" : "") + '" data-id="' + d.id + '"><span class="tp-combo__item-name">' + esc(d.name) + "</span>" + (d.plate ? '<span class="tp-combo__item-plate">' + esc(d.plate) + "</span>" : "") + "</button>";
    }).join("") : '<div class="tp-combo__empty">' + esc(T("no_data", "Sin datos")) + '</div>';
  }
  function selDevice(id, name) { state.deviceId = id; state.deviceName = name; combo.setAttribute("data-value", id); comboInput.value = name; comboMenu.hidden = true; comboInput.setAttribute("aria-expanded", "false"); }
  if (comboInput) {
    comboInput.addEventListener("focus", function () { renderDeviceMenu(comboInput.value === state.deviceName ? "" : comboInput.value); comboMenu.hidden = false; });
    comboInput.addEventListener("input", function () { renderDeviceMenu(comboInput.value); comboMenu.hidden = false; });
    comboMenu.addEventListener("click", function (e) { var b = e.target.closest(".tp-combo__item"); if (!b) return; var id = parseInt(b.getAttribute("data-id"), 10); var d = state.devices.filter(function (x) { return x.id === id; })[0]; selDevice(id, d ? d.name : "#" + id); });
    document.addEventListener("click", function (e) { if (!combo.contains(e.target)) { comboMenu.hidden = true; } });
  }

  /* ===== DATE-RANGE PICKER ===== */
  var LOCALE = document.documentElement.lang || "es";
  // Meses/dias segun el idioma de la pagina (Intl, sin hardcodear)
  var MONTHS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { month: "long" }), a = [];
      for (var i = 0; i < 12; i++) { var n = f.format(new Date(2000, i, 1)); a.push(n.charAt(0).toUpperCase() + n.slice(1)); }
      return a;
    } catch (e) { return ["01", "02", "03", "04", "05", "06", "07", "08", "09", "10", "11", "12"]; }
  })();
  var DOWS = (function () {
    try {
      var f = new Intl.DateTimeFormat(LOCALE, { weekday: "short" }), a = [];
      // 2024-01-01 fue lunes (semana inicia en lunes, como el legacy)
      for (var d = 0; d < 7; d++) a.push(f.format(new Date(2024, 0, 1 + d)).slice(0, 2));
      return a;
    } catch (e) { return ["lu", "ma", "mi", "ju", "vi", "sa", "do"]; }
  })();
  var dp = { calY: 0, calM: 0, from: null, to: null }; // from/to = Date (day only)
  function ymd(d) { return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()); }
  function startToday() {
    var n = new Date();
    dp.from = new Date(n.getFullYear(), n.getMonth(), n.getDate());
    dp.to = new Date(n.getFullYear(), n.getMonth(), n.getDate());
    dp.calM = n.getMonth(); dp.calY = n.getFullYear();
  }
  function renderCal(el, y, m) {
    var first = new Date(y, m, 1), startDow = (first.getDay() + 6) % 7; // lunes=0
    var days = new Date(y, m + 1, 0).getDate();
    var html = '<div class="tp-datepick__cal-head"><button type="button" data-cal-prev>&lsaquo;</button>' +
      '<span>' + MONTHS[m] + " " + y + '</span><button type="button" data-cal-next>&rsaquo;</button></div>' +
      '<div class="tp-datepick__grid">' + DOWS.map(function (d) { return '<span class="tp-datepick__dow">' + esc(d) + '</span>'; }).join('');
    for (var i = 0; i < startDow; i++) html += "<span></span>";
    for (var d = 1; d <= days; d++) {
      var cur = new Date(y, m, d), cls = "tp-datepick__day", t = cur.getTime();
      if (dp.from && t === dp.from.getTime()) cls += " is-start";
      if (dp.to && t === dp.to.getTime()) cls += " is-end";
      if (dp.from && dp.to && t > dp.from.getTime() && t < dp.to.getTime()) cls += " is-in";
      html += '<button type="button" class="' + cls + '" data-day="' + ymd(cur) + '">' + d + "</button>";
    }
    html += "</div>";
    el.innerHTML = html;
  }
  function renderCals() {
    renderCal($("#tp-hist-dp-cal-a"), dp.calY, dp.calM);
    var nm = dp.calM + 1, ny = dp.calY; if (nm > 11) { nm = 0; ny++; }
    renderCal($("#tp-hist-dp-cal-b"), ny, nm);
    $("#tp-hist-dp-from-date").value = dp.from ? ymd(dp.from) : "";
    $("#tp-hist-dp-to-date").value = dp.to ? ymd(dp.to) : "";
  }
  function openDatePick() { $("#tp-hist-datepick").hidden = false; renderCals(); }
  function closeDatePick() { $("#tp-hist-datepick").hidden = true; }
  function applyQuickRange(key) {
    var n = new Date(), a = new Date(n.getFullYear(), n.getMonth(), n.getDate()), b = new Date(a);
    var ft = "00:00", tt = "23:59";
    if (key === "yesterday") { a.setDate(a.getDate() - 1); b.setDate(b.getDate() - 1); }
    else if (key === "2days") { a.setDate(a.getDate() - 2); }
    else if (key === "3days") { a.setDate(a.getDate() - 3); }
    else if (key === "week") { a.setDate(a.getDate() - ((a.getDay() + 6) % 7)); }
    else if (key === "lastweek") { a.setDate(a.getDate() - ((a.getDay() + 6) % 7) - 7); b = new Date(a); b.setDate(b.getDate() + 6); }
    else if (key === "month") { a = new Date(n.getFullYear(), n.getMonth(), 1); }
    else if (key === "lastmonth") { a = new Date(n.getFullYear(), n.getMonth() - 1, 1); b = new Date(n.getFullYear(), n.getMonth(), 0); }
    dp.from = a; dp.to = b; dp.calY = a.getFullYear(); dp.calM = a.getMonth();
    $("#tp-hist-dp-from-time").value = ft; $("#tp-hist-dp-to-time").value = tt;
    // Los selects de hora están mejorados por tp-select: sincronizar el botón
    [$("#tp-hist-dp-from-time"), $("#tp-hist-dp-to-time")].forEach(function (sel) {
      if (sel && sel._tpdd && window.tpSelect && tpSelect.refresh) {
        try { tpSelect.refresh(sel); } catch (e) {}
      }
    });
    renderCals();
  }
  function commitRange() {
    if (!dp.from) return;
    if (!dp.to || dp.to.getTime() < dp.from.getTime()) dp.to = new Date(dp.from);
    state.range = {
      fromDate: ymd(dp.from), fromTime: $("#tp-hist-dp-from-time").value || "00:00",
      toDate: ymd(dp.to), toTime: $("#tp-hist-dp-to-time").value || "23:59",
    };
    var lbl = state.range.fromDate + " " + state.range.fromTime + "  —  " + state.range.toDate + " " + state.range.toTime;
    $("#tp-hist-daterange-label").textContent = lbl;
  }
  function bindDatePicker() {
    $("#tp-hist-daterange").addEventListener("click", openDatePick);
    var clearBtn = $("#tp-hist-daterange-clear");
    if (clearBtn)
      clearBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        startToday();
        applyQuickRange("today");
        commitRange();
      });
    root.querySelectorAll("[data-datepick-close]").forEach(function (b) { b.addEventListener("click", closeDatePick); });
    $("#tp-hist-dp-quick").addEventListener("click", function (e) { var b = e.target.closest("[data-quick]"); if (b) applyQuickRange(b.getAttribute("data-quick")); });
    var cals = $("#tp-hist-datepick").querySelector(".tp-datepick__cals");
    cals.addEventListener("click", function (e) {
      var prev = e.target.closest("[data-cal-prev]"), next = e.target.closest("[data-cal-next]"), day = e.target.closest("[data-day]");
      if (prev) { dp.calM--; if (dp.calM < 0) { dp.calM = 11; dp.calY--; } renderCals(); return; }
      if (next) { dp.calM++; if (dp.calM > 11) { dp.calM = 0; dp.calY++; } renderCals(); return; }
      if (day) {
        var parts = day.getAttribute("data-day").split("-");
        var picked = new Date(+parts[0], +parts[1] - 1, +parts[2]);
        if (!dp.from || (dp.from && dp.to)) { dp.from = picked; dp.to = null; }
        else if (picked.getTime() < dp.from.getTime()) { dp.to = dp.from; dp.from = picked; }
        else { dp.to = picked; }
        renderCals();
      }
    });
    $("#tp-hist-dp-apply").addEventListener("click", function () { commitRange(); closeDatePick(); });
  }

  /* ===== Regla de medicion: estado + dibujo ===== */
  var rulerState = { on: false, pts: [], layer: null };
  function rulerRedraw() {
    if (rulerState.layer) { state.map.removeLayer(rulerState.layer); rulerState.layer = null; }
    if (!rulerState.pts.length) return;
    var g = L.featureGroup();
    if (rulerState.pts.length > 1)
      g.addLayer(L.polyline(rulerState.pts, { color: "#0f172a", weight: 2, dashArray: "5 6", opacity: 0.85 }));
    var acc = 0;
    rulerState.pts.forEach(function (pt, i) {
      g.addLayer(L.circleMarker(pt, { radius: 3.5, color: "#0f172a", fillColor: "#fff", fillOpacity: 1, weight: 2 }));
      if (i > 0) {
        acc += rulerState.pts[i - 1].distanceTo(pt);
        g.addLayer(L.marker(pt, {
          icon: L.divIcon({ className: "tp-hist-ldiv", html: '<span class="tp-ruler-chip">' + (acc / 1000).toFixed(2) + " Km</span>", iconSize: [0, 0], iconAnchor: [-10, 10] }),
          interactive: false,
        }));
      }
    });
    rulerState.layer = g;
    g.addTo(state.map);
  }

  /* ===== MAPA ===== */
  function initMap() {
    if (state.map) return;
    state.map = L.map("tp-history-map", { center: [-12.0464, -77.0428], zoom: 11, zoomControl: false, attributionControl: true });
    state.baseLayers = window.tpBasemaps && window.tpBasemaps.build ? window.tpBasemaps.build(MAP_CFG) : { "2": { name: "OSM", layer: L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png") } };
    var id = window.tpBasemaps && window.tpBasemaps.defaultId ? window.tpBasemaps.defaultId(state.baseLayers, MAP_CFG) : Object.keys(state.baseLayers)[0];
    if (id) setBaseLayer(id);
    state.map.on("click", function (e) {
      if (!rulerState.on) return;
      rulerState.pts.push(e.latlng);
      rulerRedraw();
    });
    buildProvidersMenu();
  }
  function setBaseLayer(id) {
    var e = state.baseLayers[id]; if (!e) return;
    if (state.currentBase && state.baseLayers[state.currentBase]) state.map.removeLayer(state.baseLayers[state.currentBase].layer);
    e.layer.addTo(state.map); if (e.layer.bringToBack) e.layer.bringToBack(); state.currentBase = id;
    var menu = $("#tp-hist-providers-menu"); if (menu) menu.querySelectorAll("[data-map-id]").forEach(function (b) { b.classList.toggle("is-active", b.getAttribute("data-map-id") === id); });
  }
  function buildProvidersMenu() {
    var menu = $("#tp-hist-providers-menu"); if (!menu) return;
    menu.innerHTML = Object.keys(state.baseLayers).map(function (id) { return '<button type="button" class="tp-menu__item" data-map-id="' + id + '">' + esc(state.baseLayers[id].name) + "</button>"; }).join("");
    menu.addEventListener("click", function (e) { var b = e.target.closest("[data-map-id]"); if (!b) return; var id = b.getAttribute("data-map-id"); setBaseLayer(id); persistMapId(id); menu.hidden = true; });
  }
  function persistMapId(id) { try { var fd = new FormData(); fd.append("key", "tp_map_id"); fd.append("value", String(id)); fetch("/ui/v2/api/preferences", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-CSRF-TOKEN": csrf() } }).catch(function () {}); } catch (e) {} }

  /* ===== RUTA ===== */
  function clearAll() {
    [state.routeGroup, state.decorator, state.stopGroup, state.eventGroup, state.endGroup, state.cursorMarker, state.focusLayer].forEach(function (g) { if (g && state.map && state.map.hasLayer(g)) state.map.removeLayer(g); });
    if (state.focusPopup && state.map) state.map.closePopup(state.focusPopup);
    state.routeGroup = state.decorator = state.stopGroup = state.eventGroup = state.endGroup = state.cursorMarker = null;
    state.focusIdx = null; state.focusRange = null; state.focusLayer = null; state.focusPopup = null;
    document.querySelectorAll(".tp-hist-tl.is-selected").forEach(function (x) { x.classList.remove("is-selected"); });
    state.cursorCourse = -999;
  }
  function buildRoute() {
    if (state.routeGroup) { [state.routeGroup, state.decorator].forEach(function (g) { if (g && state.map.hasLayer(g)) state.map.removeLayer(g); }); state.routeGroup = state.decorator = null; }
    var P = state.positions; if (P.length < 2) return;
    // Rango visible: todo el recorrido o SOLO el tramo enfocado (focus)
    var lo = 0, hi = P.length - 1;
    if (state.focusRange) {
      lo = Math.max(0, Math.min(state.focusRange.s, P.length - 1));
      hi = Math.max(lo, Math.min(state.focusRange.e, P.length - 1));
      // Tramo de UN solo punto (viaje de segundos): no hay polilinea que
      // dibujar, pero NO expandir al recorrido completo (bug: se veia todo)
      if (hi === lo) {
        state.routeGroup = L.featureGroup();
        if (state.display.route) state.routeGroup.addTo(state.map);
        return;
      }
    }
    var group = L.featureGroup(), polys = [];
    var seg = [[P[lo].lat, P[lo].lng]], color = colorAt(lo), s0 = lo;
    function flush() { if (seg.length >= 2) { var pl = L.polyline(seg, { color: color, weight: 4, opacity: 0.9 }); pl._idx = s0; pl.on("click", onRouteClick); group.addLayer(pl); polys.push(pl); } }
    for (var i = lo + 1; i <= hi; i++) { var c = colorAt(i); seg.push([P[i].lat, P[i].lng]); if (c !== color) { flush(); seg = [[P[i].lat, P[i].lng]]; color = c; s0 = i; } }
    flush();
    // Linea hit invisible y ANCHA sobre toda la ruta: facilita el click
    var hitPts = [];
    for (var hI = lo; hI <= hi; hI++) hitPts.push([P[hI].lat, P[hI].lng]);
    if (hitPts.length > 1) {
      var hit = L.polyline(hitPts, { color: '#000', opacity: 0, weight: 18, interactive: true });
      hit.on('click', onRouteClick);
      group.addLayer(hit);
    }
    state.routeGroup = group;
    if (state.display.route) group.addTo(state.map);
    if (window.L.polylineDecorator && polys.length) {
      // Flecha ROJA rellena sobre la ruta (estilo legacy)
      state.decorator = L.polylineDecorator(polys, { patterns: [{ offset: 25, repeat: 90, symbol: L.Symbol.arrowHead({ pixelSize: 8, polygon: true, pathOptions: { stroke: true, color: "#dc2626", weight: 1, fill: true, fillColor: "#dc2626", fillOpacity: 1 } }) }] });
      if (state.display.route && state.display.arrows) state.decorator.addTo(state.map);
    }
  }
  function onRouteClick(e) { if (rulerState.on) return; var idx = nearestIdxToLatLng(e.latlng); if (idx >= 0) openPointPopup(idx, e.latlng); }
  function nearestIdxToLatLng(ll) { var best = -1, bd = Infinity; for (var i = 0; i < state.positions.length; i++) { var p = state.positions[i], d = (p.lat - ll.lat) * (p.lat - ll.lat) + (p.lng - ll.lng) * (p.lng - ll.lng); if (d < bd) { bd = d; best = i; } } return best; }
  function ptRow(label, value) {
    return "<dt>" + label + "</dt><dd>" + (value == null || value === "" ? "-" : value) + "</dd>";
  }
  function openPointPopup(idx, ll) {
    var p = state.positions[idx]; if (!p) return;
    ll = ll || L.latLng(p.lat, p.lng);
    var sv = "http://maps.google.com/?q=&cbll=" + p.lat + "," + p.lng + "&cbp=12,20.09,,0,5&layer=c&hl=" + (LOCALE || "es");
    var html =
      '<div class="tp-hist-pt">' +
      '<div class="tp-hist-pt__bar"><span class="tp-hist-pt__baric"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span></div>' +
      '<dl class="tp-hist-pt__defs">' +
      (CFG.addrOn ? ptRow(T("address", "Dirección"), '<span data-addr>…</span>') : "") +
      ptRow(T("street_view", "Street view"), '<a href="' + sv + '" target="_blank" rel="noopener">' + T("preview", "Preview") + "</a>") +
      ptRow(T("latitude", "Latitud"), p.lat + "&deg;") +
      ptRow(T("longitude", "Longitud"), p.lng + "&deg;") +
      (p.alt != null ? ptRow(T("altitude", "Altitud"), Math.round(p.alt) + " m") : "") +
      ptRow(T("speed", "Velocidad"), Math.round(p.spd || 0) + " " + (gUnitSpeed() || "km/h")) +
      ptRow(T("time", "Hora"), esc(fmtTime(p.t))) +
      '<span data-pt-extra></span>' +
      "</dl>" +
      '<button type="button" class="tp-hist-pt__more" data-pt-more title="Ver todos los parámetros"><span></span><span></span><span></span></button>' +
      "</div>";
    if (state.pointPopup) state.map.closePopup(state.pointPopup);
    state.pointPopup = L.popup({ offset: [0, -6], maxWidth: 320, className: "tp-hist-pt-wrap" }).setLatLng(ll).setContent(html).openOn(state.map);
    if (CFG.addrOn) fetchAddr(p.lat, p.lng, function (a) {
      var el2 = state.pointPopup && state.pointPopup.getElement();
      var dd = el2 && el2.querySelector("[data-addr]");
      if (dd) dd.textContent = a || "-";
      if (state.pointPopup) state.pointPopup.update();
    });
    // Referencia vial "PE-1N · km 557" bajo la dirección (toggle hitos activo)
    if (state.display.hitos && window.tpHitos) {
      tpHitos.nearest(p.lat, p.lng).then(function (h) {
        if (!h || !state.pointPopup) return;
        var el3 = state.pointPopup.getElement();
        var dd2 = el3 && el3.querySelector("[data-addr]");
        if (dd2) {
          var ref = document.createElement("div");
          ref.className = "tp-hito-ref";
          ref.textContent = tpHitos.refText(h);
          dd2.parentNode.appendChild(ref);
          state.pointPopup.update();
        }
      });
    }
    var el = state.pointPopup.getElement();
    var more = el && el.querySelector("[data-pt-more]");
    if (more)
      more.addEventListener("click", function () {
        expandPointPopup(p, more);
      });
    moveCursor(idx);
  }
  // Modo COMPLETO: trae TODOS los parámetros del punto (XML other) con el
  // endpoint legacy history.position (como el legacy).
  function expandPointPopup(p, more) {
    function render(pos) {
      var el2 = state.pointPopup && state.pointPopup.getElement();
      var slot = el2 && el2.querySelector("[data-pt-extra]");
      if (!slot) return;
      var html = "";
      var arr = pos.other_arr || [];
      if (Object.prototype.toString.call(arr) === "[object Array]") {
        // parseXML legacy devuelve lineas "clave: valor"
        arr.forEach(function (line) {
          var t2 = String(line), ix = t2.indexOf(": ");
          if (ix > 0) html += ptRow(esc(t2.slice(0, ix)), esc(t2.slice(ix + 2)));
          else if (t2) html += ptRow("", esc(t2));
        });
      } else if (arr && typeof arr === "object") {
        Object.keys(arr).forEach(function (k) {
          var v = arr[k];
          if (v != null && typeof v !== "object") html += ptRow(esc(k), esc(String(v)));
        });
      }
      // Sensores marcados "mostrar en popup"
      var sv = pos.sensors_value || {};
      Object.keys(sv).forEach(function (k) {
        if (sv[k] != null && typeof sv[k] !== "object") html += ptRow(esc(k), esc(String(sv[k])));
      });
      slot.innerHTML = html;
      var m2 = el2.querySelector("[data-pt-more]");
      if (m2) m2.remove();
      if (state.pointPopup) state.pointPopup.update();
    }
    if (p._pos) { render(p._pos); return; }
    if (!CFG.positionUrl || !state.deviceId || !p.id) return;
    if (more) more.classList.add("is-loading");
    fetch(CFG.positionUrl + "?device_id=" + state.deviceId + "&position_id=" + p.id, {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var pos = (res && (res.position || res)) || {};
        p._pos = pos;
        render(pos);
      })
      .catch(function (e2) {
        if (more) more.classList.remove("is-loading");
        if (window.console) console.warn("history.position:", e2);
      });
  }
  // ===== FOCUS del timeline (drive / parking / trip start-end) =====
  function timelineRangeEnd(i) {
    for (var j = i + 1; j < state.timeline.length; j++) {
      var n = state.timeline[j];
      if (n.start_idx != null && (n.status === S_DRIVE || n.status === S_STOP || n.status === S_END))
        return Math.max(state.timeline[i].start_idx || 0, n.start_idx - 1);
    }
    return state.positions.length - 1;
  }
  function focusRows(it) {
    var sum = state.summary || {};
    var r = "";
    if (it.status === S_DRIVE) {
      r += ptRow(T("start", "Inicio"), esc(it.from)) + ptRow(T("end", "Fin"), esc(it.to));
      r += ptRow(T("driver", "Conductor"), esc(it.driver || "-"));
      if (it.top_speed != null) r += ptRow(T("top_speed", "Velocidad máxima"), it.top_speed + " " + (gUnitSpeed() || "km/h"));
      if (it.distance != null) r += ptRow(T("route_length", "Longitud de ruta"), it.distance + " km");
      r += ptRow(T("duration", "Duración"), esc(it.duration || "-"));
      if (it.fuel_consumption != null) r += ptRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", it.fuel_consumption + " L");
      if (it.engine_hours != null) r += ptRow(T("engine_hours", "Horas del motor"), fmtDur(it.engine_hours));
    } else if (it.status === S_STOP) {
      r += ptRow(T("start", "Inicio"), esc(it.from)) + ptRow(T("end", "Fin"), esc(it.to));
      r += ptRow(T("driver", "Conductor"), esc(it.driver || "-"));
      r += ptRow(T("duration", "Duración"), esc(it.duration || "-"));
      if (it.fuel_consumption != null) r += ptRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", it.fuel_consumption + " L");
      if (it.engine_hours != null) r += ptRow(T("engine_hours", "Horas del motor"), fmtDur(it.engine_hours));
    } else if (it.status === S_START || it.status === S_END) {
      r += ptRow(T("driver", "Conductor"), esc(tripDriver() || "-"));
      r += ptRow(T("route_length", "Longitud de ruta"), esc(sum.distance || "-"));
      r += ptRow(T("move_duration", "Duración de movimiento"), esc(sum.move_duration || "-"));
      r += ptRow(T("stop_duration", "Duración de estacionado"), esc(sum.stop_duration || "-"));
      r += ptRow(T("top_speed", "Velocidad máxima"), esc(sum.top_speed || "-"));
      if (sum.fuel) r += ptRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", esc(sum.fuel));
      if (sum.engine_hours) r += ptRow(T("engine_hours", "Horas del motor"), esc(sum.engine_hours));
    } else {
      r += ptRow(T("time", "Hora"), esc(it.from)) + ptRow(T("event", "Evento"), esc(it.message || "-"));
    }
    return r;
  }
  function openFocusPopup(ll, html) {
    if (state.focusPopup) state.map.closePopup(state.focusPopup);
    state.focusPopup = L.popup({ offset: [0, -10], maxWidth: 320, className: "tp-hist-pt-wrap" }).setLatLng(ll).setContent(html).openOn(state.map);
  }
  function clearFocus() {
    var hadRange = !!state.focusRange;
    state.focusIdx = null;
    state.focusRange = null;
    state.playStart = 0;
    state.playEnd = Math.max(0, state.positions.length - 1);
    document.querySelectorAll(".tp-hist-tl.is-selected").forEach(function (x) { x.classList.remove("is-selected"); });
    if (state.focusLayer) { state.map.removeLayer(state.focusLayer); state.focusLayer = null; }
    if (state.focusPopup) { state.map.closePopup(state.focusPopup); state.focusPopup = null; }
    if (hadRange) { buildRoute(); applyDisplay("route"); }
    // restaurar capas según los toggles
    if (state.routeGroup && state.display.route && !state.map.hasLayer(state.routeGroup)) state.routeGroup.addTo(state.map);
    if (state.decorator && state.display.route && state.display.arrows && !state.map.hasLayer(state.decorator)) state.decorator.addTo(state.map);
    if (state.stopGroup && state.display.stops && !state.map.hasLayer(state.stopGroup)) state.stopGroup.addTo(state.map);
    if (state.endGroup && !state.map.hasLayer(state.endGroup)) state.endGroup.addTo(state.map);
    if (state.eventGroup && state.display.events && !state.map.hasLayer(state.eventGroup)) state.eventGroup.addTo(state.map);
  }
  function focusTimeline(i) {
    clearFocus();
    var it = state.timeline[i];
    if (!it) return;
    state.focusIdx = i;
    var card = document.querySelector('.tp-hist-tl[data-idx="' + i + '"]');
    if (card) card.classList.add("is-selected");
    var m = statusMeta(it.status);
    var html = '<div class="tp-hist-pt"><div class="tp-hist-pt__title" style="color:' + m.color + '">' +
      esc(m.label + (it.index ? " " + it.index : "")) + '</div><dl class="tp-hist-pt__defs">' + focusRows(it) + "</dl></div>";

    if (it.status === S_DRIVE) {
      // SOLO ese tramo + los eventos ocurridos dentro
      var sIdx = it.start_idx || 0, eIdx = timelineRangeEnd(i);
      state.focusRange = { s: sIdx, e: eIdx };
      buildRoute(); applyDisplay("route");
      [state.stopGroup, state.endGroup, state.eventGroup].forEach(function (g) { if (g && state.map.hasLayer(g)) state.map.removeLayer(g); });
      var fg = L.featureGroup();
      state.timeline.forEach(function (ev) {
        if (ev.status !== S_EVENT || ev.lat == null || ev.start_idx == null) return;
        if (ev.start_idx < sIdx || ev.start_idx > eIdx) return;
        var mk = L.marker([ev.lat, ev.lng], { icon: eventIcon() });
        mk.bindPopup('<div class="tp-hist-pt"><div class="tp-hist-pt__title">' + esc(ev.message || "") + '</div><dl class="tp-hist-pt__defs">' + ptRow(T("time", "Hora"), esc(fmtTime(ev.from))) + "</dl></div>");
        fg.addLayer(mk);
      });
      state.focusLayer = fg; fg.addTo(state.map);
      var sp = state.positions[sIdx];
      if (state.routeGroup && state.routeGroup.getLayers().length) {
        state.map.fitBounds(state.routeGroup.getBounds(), { padding: [60, 60] });
      } else if (sp) {
        // viaje de un solo punto: centrar en el
        state.map.setView([sp.lat, sp.lng], Math.max(state.map.getZoom(), 16));
      }
      if (sp) openFocusPopup([sp.lat, sp.lng], html);
      moveCursor(sIdx);
      state.playStart = sIdx; state.playEnd = eIdx;
    } else if (it.status === S_STOP) {
      // SOLO ese punto
      [state.routeGroup, state.decorator, state.stopGroup, state.endGroup, state.eventGroup].forEach(function (g) { if (g && state.map.hasLayer(g)) state.map.removeLayer(g); });
      var fg2 = L.featureGroup();
      if (it.lat != null) fg2.addLayer(L.marker([it.lat, it.lng], { icon: badge("tp-hist-badge--stop", String(it.index || "P")) }));
      state.focusLayer = fg2; fg2.addTo(state.map);
      if (it.lat != null) {
        state.map.setView([it.lat, it.lng], Math.max(state.map.getZoom(), 15));
        openFocusPopup([it.lat, it.lng], html);
      }
      if (it.start_idx != null) moveCursor(it.start_idx);
    } else {
      // Trip start / Trip end / Evento: popup informativo en su punto
      var idx = it.start_idx != null ? it.start_idx : 0;
      var pp = it.lat != null ? { lat: it.lat, lng: it.lng } : state.positions[idx];
      if (pp) {
        state.map.setView([pp.lat, pp.lng], Math.max(state.map.getZoom(), 14));
        openFocusPopup([pp.lat, pp.lng], html);
      }
      moveCursor(idx);
    }
  }
  function fetchAddr(lat, lng, cb) {
    if (!CFG.geoUrl) return cb("");
    var key = lat.toFixed(5) + "," + lng.toFixed(5); if (geoCache[key] !== undefined) return cb(geoCache[key]);
    fetch(CFG.geoUrl + (CFG.geoUrl.indexOf("?") === -1 ? "?" : "&") + "lat=" + lat + "&lng=" + lng, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.text(); }).then(function (t) { var a = parseAddr(t); geoCache[key] = a; cb(a); }).catch(function () { cb(""); });
  }
  function parseAddr(t) { if (t == null) return ""; t = String(t).trim(); if (!t) return ""; try { var j = JSON.parse(t); if (typeof j === "string") return j; if (j && typeof j === "object") return j.address || j.name || ""; } catch (e) {} var lt = t.indexOf("<"); if (lt !== -1) t = t.slice(0, lt).trim(); return t; }

  function buildMarkers() {
    [state.stopGroup, state.eventGroup, state.endGroup].forEach(function (g) { if (g && state.map.hasLayer(g)) state.map.removeLayer(g); });
    var P = state.positions; if (!P.length) return;
    var endG = L.featureGroup();
    endG.addLayer(L.marker([P[0].lat, P[0].lng], { icon: badge("tp-hist-badge--start", "A") }).bindTooltip("Inicio · " + fmtTime(P[0].t)));
    endG.addLayer(L.marker([P[P.length - 1].lat, P[P.length - 1].lng], { icon: badge("tp-hist-badge--end", "B") }).bindTooltip("Fin · " + fmtTime(P[P.length - 1].t)));
    state.endGroup = endG; endG.addTo(state.map);
    var stopG = L.featureGroup(), sc = 0;
    state.timeline.forEach(function (it) {
      if (it.status !== S_STOP || it.lat == null) return; sc++;
      var m = L.marker([it.lat, it.lng], { icon: badge("tp-hist-badge--stop", String(it.index || sc)) });
      m.bindPopup(stopPopup(it, it.index || sc));
      m.on("click", function () { moveCursor(it.start_idx || 0); });
      stopG.addLayer(m);
    });
    state.stopGroup = stopG; if (state.display.stops) stopG.addTo(state.map);
    var evG = L.featureGroup();
    state.timeline.forEach(function (it) {
      if (it.status !== S_EVENT || it.lat == null) return;
      var m = L.marker([it.lat, it.lng], { icon: eventIcon() });
      m.bindPopup('<div class="tp-hist-pt"><div class="tp-hist-pt__title">' + esc(it.message || "Evento") + "</div><dl class=\"tp-hist-pt__defs\"><dt>Hora</dt><dd>" + esc(fmtTime(it.from)) + "</dd></dl></div>");
      m.on("click", function () { if (it.start_idx != null) moveCursor(it.start_idx); });
      evG.addLayer(m);
    });
    state.eventGroup = evG; if (state.display.events) evG.addTo(state.map);
  }
  function stopPopup(it, n) {
    return '<div class="tp-hist-pt"><div class="tp-hist-pt__title">Parada ' + n + "</div><dl class=\"tp-hist-pt__defs\">" +
      "<dt>Desde</dt><dd>" + esc(fmtTime(it.from)) + "</dd><dt>Hasta</dt><dd>" + esc(fmtTime(it.to)) + "</dd>" +
      (it.duration ? "<dt>Duración</dt><dd>" + esc(it.duration) + "</dd>" : "") + "</dl></div>";
  }
  function badge(cls, label) { return L.divIcon({ className: "tp-hist-ldiv", html: '<span class="tp-hist-badge ' + cls + '">' + esc(label) + "</span>", iconSize: [24, 24], iconAnchor: [12, 12] }); }
  function eventIcon() {
    // Triangulo de advertencia (eventos en el mapa)
    return L.divIcon({
      className: "tp-hist-ldiv",
      html: '<svg width="24" height="22" viewBox="0 0 24 22"><path d="M12 1.5 23 20.5H1z" fill="#f59e0b" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/><text x="12" y="17" text-anchor="middle" font-size="12" font-weight="800" fill="#fff">!</text></svg>',
      iconSize: [24, 22], iconAnchor: [12, 21],
    });
  }

  function cursorIcon(course) {
    var icon = (state.device && state.device.icon) || {}, type = icon.type || "arrow", src, w, h, rotate = false, bottom = false;
    if (type === "arrow") { src = "/images/device_icons/estados/" + ((state.device && state.device.icon_color) || "green") + ".png"; w = 25; h = 33; rotate = true; }
    else if (type === "rotating") { src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; rotate = true; }
    else { src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; bottom = true; }
    var rot = rotate ? "transform:rotate(" + (course || 0) + "deg);" : "";
    return L.divIcon({ className: "tp-hist-ldiv tp-hist-cursor", html: '<img src="' + src + '" width="' + w + '" height="' + h + '" style="display:block;' + rot + '">', iconSize: [w, h], iconAnchor: bottom ? [w / 2, h] : [w / 2, h / 2] });
  }

  /* ===== TIMELINE (sidebar) — tarjetas con modo colapsado/completo ===== */
  var TL_ICONS = {
    start: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>',
    end: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/></svg>',
    pause: '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>',
  };
  function statusMeta(st) {
    if (st === S_DRIVE) return { cls: "drive", sym: "D", color: "#16a34a", label: T("drive", "Conducción"), railed: true };
    if (st === S_STOP) return { cls: "stop", sym: TL_ICONS.pause, color: "#ef4444", label: T("stop", "Estacionado"), railed: true };
    if (st === S_START) return { cls: "start", sym: TL_ICONS.start, color: "#64748b", label: T("trip_start", "Inicio del viaje") };
    if (st === S_END) return { cls: "end", sym: TL_ICONS.end, color: "#64748b", label: T("trip_end", "Fin del viaje") };
    return { cls: "event", sym: "E", color: "#ef4444", label: T("event", "Evento") };
  }
  // Conductor a nivel de recorrido: el del primer viaje que lo tenga.
  function tripDriver() {
    for (var i = 0; i < state.timeline.length; i++) {
      var it = state.timeline[i];
      if (it.status === S_DRIVE && it.driver) return it.driver;
    }
    return null;
  }
  function tlRow(label, value, extra) {
    return (
      '<div class="tp-hist-tl__row' + (extra ? " tp-hist-tl__row--extra" : "") + '">' +
      "<span>" + label + ":</span><strong>" + esc(value == null || value === "" ? "-" : value) + "</strong></div>"
    );
  }
  function renderTimeline() {
    var host = $("#tp-hist-timeline");
    if (!state.timeline.length) { host.innerHTML = '<div class="tp-hist__side-empty"><span class="tp-text-muted tp-text-sm">' + esc(T("no_data", "Sin datos")) + "</span></div>"; return; }
    var sum = state.summary || {};
    // Fila de la CABECERA (fechas junto al riel de puntos)
    function hRow(label, value) {
      return '<span class="tp-hist-tl__hrow"><span>' + label + ':</span><strong>' + esc(value == null || value === "" ? "-" : value) + "</strong></span>";
    }
    host.innerHTML = state.timeline.map(function (it, i) {
      var m = statusMeta(it.status);
      var rows = "", headRows = "", hasExtra = true, sub = it.from || "";
      var startPos = it.start_idx != null ? state.positions[it.start_idx] : null;

      if (it.status === S_START || it.status === S_END) {
        // Totales del recorrido (como la referencia)
        rows += tlRow(T("driver", "Conductor"), tripDriver());
        rows += tlRow(T("route_length", "Longitud de ruta"), sum.distance);
        rows += tlRow(T("move_duration", "Duración de movimiento"), sum.move_duration, true);
        rows += tlRow(T("stop_duration", "Duración de estacionado"), sum.stop_duration, true);
        rows += tlRow(T("top_speed", "Velocidad máxima"), sum.top_speed, true);
        rows += tlRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", sum.fuel, true);
        rows += tlRow(T("engine_hours", "Horas del motor"), sum.engine_hours, true);
      } else if (it.status === S_STOP) {
        headRows = hRow(T("start", "Inicio"), it.from) + hRow(T("end", "Fin"), it.to);
        rows += tlRow(T("driver", "Conductor"), it.driver);
        rows += tlRow(T("speed", "Velocidad"), "0 " + (gUnitSpeed() || "km/h"));
        rows += tlRow(T("altitude", "Altitud"), startPos && startPos.alt != null ? Math.round(startPos.alt) + " m" : "0 m", true);
        rows += tlRow(T("duration", "Duración"), it.duration, true);
        if (it.fuel_consumption != null) rows += tlRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", it.fuel_consumption + " L", true);
        if (it.engine_hours != null) rows += tlRow(T("engine_hours", "Horas del motor"), fmtDur(it.engine_hours), true);
        sub = "";
      } else if (it.status === S_DRIVE) {
        headRows = hRow(T("drive_start", "Inicio del viaje"), it.from) + hRow(T("drive_end", "Fin de viaje"), it.to);
        rows += tlRow(T("driver", "Conductor"), it.driver);
        rows += tlRow(T("top_speed", "Velocidad máxima"), it.top_speed != null ? it.top_speed + " " + (gUnitSpeed() || "km/h") : null);
        rows += tlRow(T("route_length", "Longitud de ruta"), it.distance != null ? it.distance + " km" : null, true);
        rows += tlRow(T("duration", "Duración"), it.duration, true);
        if (it.fuel_consumption != null) rows += tlRow(T("fuel_consumption", "Consumo de combustible") + " (GPS)", it.fuel_consumption + " L", true);
        if (it.engine_hours != null) rows += tlRow(T("engine_hours", "Horas del motor"), fmtDur(it.engine_hours), true);
        sub = "";
      } else {
        rows += tlRow(T("time", "Hora"), it.from);
        rows += tlRow(T("event", "Evento"), it.message);
        hasExtra = false;
        sub = "";
      }

      // Columna izquierda: badge y, en tarjetas con riel (stop/drive),
      // dos puntos con el NÚMERO del tramo entre ellos.
      var left = m.railed
        ? '<span class="tp-hist-tl__rail" style="color:' + m.color + '">' +
          '<span class="tp-hist-tl__badge" style="background:' + m.color + '">' + m.sym + "</span>" +
          '<span class="tp-hist-tl__railline"><i class="tp-hist-tl__dot"></i>' +
          (it.index ? '<i class="tp-hist-tl__num">' + it.index + "</i>" : "") +
          '<i class="tp-hist-tl__dot"></i></span></span>'
        : '<span class="tp-hist-tl__badge" style="background:' + m.color + '">' + m.sym + "</span>";

      return (
        '<div class="tp-hist-tl tp-hist-tl--' + m.cls + '" data-idx="' + i + '">' +
        '<div class="tp-hist-tl__head' + (m.railed ? " tp-hist-tl__head--rail" : "") + '" role="button" tabindex="0">' +
        left +
        '<span class="tp-hist-tl__headtxt"><span class="tp-hist-tl__title"' +
        (m.railed ? ' style="color:' + m.color + '"' : "") + ">" + esc(m.label) + "</span>" +
        (sub ? '<span class="tp-hist-tl__time">' + esc(sub) + "</span>" : "") +
        headRows +
        "</span>" +
        (it.status === S_DRIVE ? '<button type="button" class="tp-hist-tl__play" title="Reproducir viaje">▶</button>' : "") +
        "</div>" +
        '<div class="tp-hist-tl__box">' + rows +
        (hasExtra ? '<button type="button" class="tp-hist-tl__more" title="Ver más información" aria-expanded="false"><span></span><span></span><span></span></button>' : "") +
        "</div></div>"
      );
    }).join("");
  }
  $("#tp-hist-timeline").addEventListener("click", function (e) {
    var more = e.target.closest(".tp-hist-tl__more");
    if (more) {
      var tl = more.closest(".tp-hist-tl");
      var open = tl.classList.toggle("is-open");
      more.setAttribute("aria-expanded", open ? "true" : "false");
      return;
    }
    var play = e.target.closest(".tp-hist-tl__play");
    var card = e.target.closest(".tp-hist-tl[data-idx]");
    if (!card) return;
    var it = state.timeline[parseInt(card.getAttribute("data-idx"), 10)];
    if (!it) return;
    if (play && it.status === S_DRIVE) { playSegment(it); return; }
    if (!e.target.closest(".tp-hist-tl__head")) return;
    var idx2 = parseInt(card.getAttribute("data-idx"), 10);
    if (state.focusIdx === idx2) clearFocus();
    else focusTimeline(idx2);
  });
  function playSegment(it) {
    var next = null;
    for (var i = 0; i < state.timeline.length; i++) if (state.timeline[i] === it) { next = state.timeline[i + 1]; break; }
    state.playStart = it.start_idx || 0;
    state.playEnd = next && next.start_idx != null ? Math.max(state.playStart, next.start_idx - 1) : state.positions.length - 1;
    moveCursor(state.playStart); play();
  }

  /* ===== RESUMEN (cards flotantes) ===== */
  function renderSummary() {
    var s = state.summary || {};
    $("#tp-hist-summary").hidden = !state.positions.length;
    var P = state.positions, dur = P.length ? (P[P.length - 1].ts - P[0].ts) : 0;
    var moving = 0, sum = 0; P.forEach(function (p) { if (p.spd > 0) { sum += p.spd; moving++; } });
    setSum("dist", s.distance != null ? s.distance : "—");
    setSum("dur", dur ? fmtDur(dur) : "—");
    setSum("vmax", s.top_speed != null ? s.top_speed : "—");
    setSum("vavg", moving ? Math.round(sum / moving) : "—");
    setSum("drives", s.drives != null ? s.drives : "—");
    setSum("stops", s.stops != null ? s.stops : "—");
    setSum("pts", s.points != null ? s.points : P.length);
  }
  function setSum(id, v) { var el = $("#tp-hist-sum-" + id); if (el) el.textContent = v; }

  /* ===== VISTA DEL GRÁFICO + PLAYBACK ===== */
  // Publica el alto real del footer flotante (--hist-fh) para que el sidebar
  // (modo sin pin) y la leyenda se acomoden al espacio restante.
  function updateFooterVar() {
    var f = $("#tp-hist-bottom");
    var h = !f || f.hidden ? 0 : f.offsetHeight;
    root.style.setProperty("--hist-fh", h + "px");
  }
  function setupGraphView() {
    var has = state.positions.length >= 2;
    $("#tp-hist-bottom").hidden = !has;
    if (!has) { updateFooterVar(); return; }
    state.playStart = 0; state.playEnd = state.positions.length - 1;
    state.view = { s: 0, e: state.positions.length - 1 };
    moveCursor(0);
    updateFooterVar();
  }
  // Zoom del gráfico por VENTANA de índices, centrado en el cursor
  // (equivalente al zoom/pan del Flot legacy).
  function zoomGraph(factor) {
    var len = state.positions.length;
    if (len < 2) return;
    var v = state.view, span = v.e - v.s + 1;
    var newSpan = Math.max(10, Math.min(len, Math.round(span * factor)));
    var center = state.cursorIdx >= v.s && state.cursorIdx <= v.e ? state.cursorIdx : Math.round((v.s + v.e) / 2);
    var s = Math.round(center - newSpan / 2);
    s = Math.max(0, Math.min(s, len - newSpan));
    state.view = { s: s, e: s + newSpan - 1 };
    drawGraph();
  }
  function resetGraphZoom() {
    state.view = { s: 0, e: Math.max(0, state.positions.length - 1) };
    drawGraph();
  }
  // Mantiene el cursor dentro de la ventana durante la reproducción
  function keepCursorInView() {
    var v = state.view, len = state.positions.length;
    var span = v.e - v.s;
    if (span >= len - 1) return;
    if (state.cursorIdx > v.e) {
      var s = Math.min(len - 1 - span, state.cursorIdx - Math.round(span * 0.2));
      state.view = { s: s, e: s + span };
    } else if (state.cursorIdx < v.s) {
      var s2 = Math.max(0, state.cursorIdx - Math.round(span * 0.2));
      state.view = { s: s2, e: s2 + span };
    }
  }
  function moveCursor(idx) {
    idx = Math.max(0, Math.min(state.positions.length - 1, idx | 0)); state.cursorIdx = idx;
    var p = state.positions[idx]; if (!p) return;
    var course = p.crs != null ? p.crs : 0;
    if (!state.cursorMarker) { state.cursorMarker = L.marker([p.lat, p.lng], { icon: cursorIcon(course), zIndexOffset: 1000, interactive: false }).addTo(state.map); state.cursorCourse = course; }
    else { state.cursorMarker.setLatLng([p.lat, p.lng]); if (Math.abs(course - state.cursorCourse) >= 4) { state.cursorMarker.setIcon(cursorIcon(course)); state.cursorCourse = course; } }
    $("#tp-hist-cursor-time").textContent = fmtTime(p.t);
    $("#tp-hist-cursor-speed").textContent = (p.spd != null ? Math.round(p.spd) : "—") + " " + (gUnitSpeed() || "km/h");
    updateHud();
    keepCursorInView();
    drawGraph();
  }
  // HUD de reproduccion: circulo de velocidad (color por estado) + brujula
  function updateHud() {
    var hud = $("#tp-hist-pb");
    if (!hud || hud.hidden) return;
    var p = state.positions[state.cursorIdx];
    if (!p) return;
    var spd = Math.round(p.spd || 0);
    var v = $("#tp-hist-pb-speedval"), u = $("#tp-hist-pb-speedunit"), c = $("#tp-hist-pb-speed");
    if (v) v.textContent = spd;
    if (u) u.textContent = gUnitSpeed() || "km/h";
    if (c) {
      c.classList.remove("is-moving", "is-stopped", "is-fast");
      c.classList.add(spd <= 0 ? "is-stopped" : spd >= 90 ? "is-fast" : "is-moving");
    }
    var n = $("#tp-hist-pb-needle");
    if (n) n.style.transform = "rotate(" + (p.crs != null ? p.crs : 0) + "deg)";
  }
  function gUnitSpeed() {
    var s = (state.sensorList || []).filter(function (x) { return x.id === "speed"; })[0];
    return s && s.sufix ? s.sufix : "";
  }
  function tick(now) {
    if (!state.playing) return;
    var dt = now - state.lastTs; state.lastTs = now;
    // x1 = 1 posicion/seg; x8 = 8 posiciones/seg... (esquema del legacy:
    // el player avanza PUNTOS, no tiempo simulado)
    state.playAcc = (state.playAcc || 0) + dt;
    var step = 1000 / Math.max(1, state.rate);
    var idx = state.cursorIdx, adv = false;
    while (state.playAcc >= step && idx < state.playEnd) { state.playAcc -= step; idx++; adv = true; }
    if (idx >= state.playEnd) { moveCursor(state.playEnd); pause(); return; }
    if (adv) {
      moveCursor(idx);
      var p = state.positions[idx];
      if (p && !state.map.getBounds().pad(-0.25).contains([p.lat, p.lng])) state.map.panTo([p.lat, p.lng], { animate: true });
    }
    state.rafId = requestAnimationFrame(tick);
  }
  function play() {
    if (state.positions.length < 2) return;
    if (state.cursorIdx >= state.playEnd) moveCursor(state.playStart);
    state.playing = true;
    state.playAcc = 0;
    var hud = $("#tp-hist-pb");
    if (hud) hud.hidden = false;
    updateHud();
    $("#tp-hist-play-icon").innerHTML = '<path d="M6 5h4v14H6zM14 5h4v14h-4z"/>';
    state.lastTs = performance.now();
    state.rafId = requestAnimationFrame(tick);
  }
  function pause() {
    state.playing = false;
    var hud = $("#tp-hist-pb");
    if (hud) hud.hidden = true;
    $("#tp-hist-play-icon").innerHTML = '<path d="M8 5v14l11-7z"/>';
    if (state.rafId) cancelAnimationFrame(state.rafId);
  }

  /* ===== GRÁFICA (canvas) ===== */
  var gc = $("#tp-hist-graph");
  // Tabs de sensores del gráfico: Velocidad/Altitud + sensores del device con
  // "add_to_graph" (los entrega el pipeline legacy en `sensors`).
  function renderSensorTabs() {
    var host = $("#tp-hist-sensor");
    if (!host) return;
    var list = state.sensors && state.sensors.length
      ? state.sensors
      : [{ id: "speed", name: "Velocidad", sufix: "km/h" }, { id: "altitude", name: "Altitud", sufix: "m" }];
    state.sensorList = list;
    var ok = list.some(function (s) { return s.id === state.sensor; });
    if (!ok) state.sensor = list[0].id;
    host.innerHTML = list.map(function (s) {
      return '<button class="tp-gtab' + (s.id === state.sensor ? " is-active" : "") + '" data-sensor="' + esc(s.id) + '" type="button">' + esc(s.name) + "</button>";
    }).join("");
  }
  function gVal(p) {
    if (state.sensor === "speed") return p.spd || 0;
    if (state.sensor === "altitude") return p.alt || 0;
    return p.sv && p.sv[state.sensor] != null ? +p.sv[state.sensor] || 0 : 0;
  }
  function gUnit() {
    var s = (state.sensorList || []).filter(function (x) { return x.id === state.sensor; })[0];
    return s && s.sufix ? s.sufix : "";
  }
  function drawGraph() {
    if (!gc || $("#tp-hist-bottom").hidden) return;
    var panel = document.querySelector('[data-bottom-panel="graph"]'); if (panel && panel.hidden) return;
    // Liberar el "ratchet": el canvas con width fijo impide que el contenedor
    // se ENCOJA (min-width del contenido) → la página se ensanchaba y los
    // controles del mapa quedaban recortados a la derecha.
    gc.style.width = "0px";
    gc.style.height = "0px";
    var wrap = gc.parentElement, W = wrap.clientWidth, H = wrap.clientHeight; if (!W || !H) return;
    var dpr = window.devicePixelRatio || 1; gc.width = W * dpr; gc.height = H * dpr; gc.style.width = W + "px"; gc.style.height = H + "px";
    var ctx = gc.getContext("2d"); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, W, H);
    var P = state.positions; if (P.length < 2) return;
    // Ventana visible (zoom/pan)
    var vs = Math.max(0, state.view.s || 0), ve = Math.min(P.length - 1, state.view.e || P.length - 1);
    if (ve - vs < 1) { vs = 0; ve = P.length - 1; }
    var padL = 44, padR = 10, padT = 8, padB = 22, gw = W - padL - padR, gh = H - padT - padB, vmax = 1;
    for (var i = vs; i <= ve; i++) vmax = Math.max(vmax, gVal(P[i])); vmax = Math.ceil(vmax / 10) * 10 || 10;
    var css = getComputedStyle(document.documentElement);
    var gridC = (css.getPropertyValue("--border-default") || "#e5e7eb").trim(), textC = (css.getPropertyValue("--fg-tertiary") || "#94a3b8").trim(), lineC = (css.getPropertyValue("--brand-500") || "#2563eb").trim();
    var unit = gUnit();
    ctx.strokeStyle = gridC; ctx.fillStyle = textC; ctx.font = "10px Inter, sans-serif"; ctx.lineWidth = 1;
    // Rejilla + eje Y (con unidad, ej. "80 kph")
    for (var g = 0; g <= 4; g++) {
      var yy = padT + gh * g / 4;
      ctx.globalAlpha = .5; ctx.beginPath(); ctx.moveTo(padL, yy); ctx.lineTo(W - padR, yy); ctx.stroke(); ctx.globalAlpha = 1;
      ctx.fillText(Math.round(vmax * (1 - g / 4)) + (unit ? " " + unit : ""), 4, yy + 3);
    }
    function xAt(i2) { return padL + gw * (i2 - vs) / (ve - vs); }
    function yAt(v) { return padT + gh * (1 - Math.min(v, vmax) / vmax); }
    // Eje X: horas (rejilla vertical + etiqueta), según la ventana visible
    var nTicks = Math.max(2, Math.floor(gw / 110));
    ctx.textAlign = "center";
    for (var t2 = 0; t2 <= nTicks; t2++) {
      var ti = Math.round(vs + (t2 * (ve - vs)) / nTicks);
      var tx = xAt(ti);
      ctx.globalAlpha = .35; ctx.beginPath(); ctx.moveTo(tx, padT); ctx.lineTo(tx, padT + gh); ctx.stroke(); ctx.globalAlpha = 1;
      ctx.fillText(fmtClock(P[ti].t), Math.min(Math.max(tx, padL + 14), W - padR - 14), H - 7);
    }
    ctx.textAlign = "left";
    // Área + línea
    ctx.beginPath(); ctx.moveTo(xAt(vs), yAt(gVal(P[vs])));
    for (var k = vs + 1; k <= ve; k++) ctx.lineTo(xAt(k), yAt(gVal(P[k])));
    ctx.lineTo(xAt(ve), padT + gh); ctx.lineTo(xAt(vs), padT + gh); ctx.closePath(); ctx.fillStyle = hexA(lineC, .15); ctx.fill();
    ctx.beginPath(); ctx.moveTo(xAt(vs), yAt(gVal(P[vs])));
    for (var m2 = vs + 1; m2 <= ve; m2++) ctx.lineTo(xAt(m2), yAt(gVal(P[m2])));
    ctx.strokeStyle = lineC; ctx.lineWidth = 1.5; ctx.stroke();
    // Crosshair del cursor (si está en la ventana)
    if (state.cursorIdx >= vs && state.cursorIdx <= ve) {
      var cx = xAt(state.cursorIdx);
      ctx.strokeStyle = lineC; ctx.globalAlpha = .8; ctx.setLineDash([4, 3]);
      ctx.beginPath(); ctx.moveTo(cx, padT); ctx.lineTo(cx, padT + gh); ctx.stroke(); ctx.setLineDash([]);
      ctx.globalAlpha = 1; ctx.fillStyle = lineC;
      ctx.beginPath(); ctx.arc(cx, yAt(gVal(P[state.cursorIdx])), 4, 0, Math.PI * 2); ctx.fill();
    }
  }
  // Convierte una X del canvas a índice de posición (según la ventana)
  function graphIdxAt(clientX) {
    var P = state.positions; if (P.length < 2) return -1;
    var r = gc.getBoundingClientRect();
    var vs = state.view.s, ve = state.view.e;
    var rel = (clientX - r.left - 44) / (r.width - 54);
    return Math.max(0, Math.min(P.length - 1, Math.round(vs + rel * (ve - vs))));
  }
  function hexA(hex, a) { hex = (hex || "").trim(); if (hex.charAt(0) !== "#" || (hex.length !== 7 && hex.length !== 4)) return "rgba(37,99,235," + a + ")"; if (hex.length === 4) hex = "#" + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3]; return "rgba(" + parseInt(hex.substr(1, 2), 16) + "," + parseInt(hex.substr(3, 2), 16) + "," + parseInt(hex.substr(5, 2), 16) + "," + a + ")"; }
  if (gc) {
    // Click = mover cursor · Arrastrar = PANEO horizontal · Rueda = zoom
    // (equivalente al pan/zoom interactivo del Flot legacy)
    var gDrag = null;
    gc.addEventListener("mousedown", function (e) {
      gDrag = { x: e.clientX, s: state.view.s, e: state.view.e, moved: false };
    });
    window.addEventListener("mousemove", function (e) {
      if (!gDrag) return;
      var dx = e.clientX - gDrag.x;
      if (Math.abs(dx) > 4) gDrag.moved = true;
      if (!gDrag.moved) return;
      var len = state.positions.length, span = gDrag.e - gDrag.s;
      if (span >= len - 1) return;
      var r = gc.getBoundingClientRect();
      var shift = Math.round((-dx / (r.width - 54)) * span);
      var s = Math.max(0, Math.min(len - 1 - span, gDrag.s + shift));
      state.view = { s: s, e: s + span };
      drawGraph();
    });
    window.addEventListener("mouseup", function () {
      if (!gDrag) return;
      var moved = gDrag.moved;
      gDrag = null;
      gcSuppressClick = moved;
    });
    var gcSuppressClick = false;
    gc.addEventListener("click", function (e) {
      if (gcSuppressClick) { gcSuppressClick = false; return; }
      var idx = graphIdxAt(e.clientX);
      if (idx < 0) return;
      pause();
      moveCursor(idx);
      var p = state.positions[idx];
      if (p) state.map.panTo([p.lat, p.lng]);
    });
    gc.addEventListener("wheel", function (e) {
      e.preventDefault();
      zoomGraph(e.deltaY < 0 ? 0.7 : 1.4);
    }, { passive: false });
    window.addEventListener("resize", function () { drawGraph(); updateFooterVar(); });
  }

  /* ===== OVERLAYS ===== */
  function buildGeofences(items) { var g = L.layerGroup(); (items || []).forEach(function (z) { var color = z.polygon_color || "#1e88e5", style = { color: color, weight: 2, fillColor: color, fillOpacity: .12 }, layer = null; if (z.type === "circle" && z.center && z.radius) { var c = z.center, lat = c.lat != null ? c.lat : c.y, lng = c.lng != null ? c.lng : c.x; if (typeof c === "string") { var pp = c.split(","); lat = parseFloat(pp[0]); lng = parseFloat(pp[1]); } layer = L.circle([lat, lng], Object.assign({ radius: Number(z.radius) }, style)); } else if (z.coordinates && z.coordinates.length >= 3) { layer = L.polygon(z.coordinates.map(function (pt) { return [pt.lat != null ? pt.lat : pt.y, pt.lng != null ? pt.lng : pt.x]; }), style); } if (layer) { if (z.name) layer.bindTooltip(z.name); g.addLayer(layer); } }); return g; }
  function buildRoutes(items) { var g = L.layerGroup(); (items || []).forEach(function (r) { var pts = (r.coordinates || []).map(function (c) { return [c[1], c[0]]; }); if (pts.length < 2) return; L.polyline(pts, { color: r.color || "#7c3aed", weight: 3, opacity: .8, dashArray: "6 6" }).bindTooltip(r.name || "Ruta").addTo(g); }); return g; }
  function buildPois(items) { var g = L.layerGroup(); (items || []).forEach(function (p) { if (p.lat == null || p.lng == null) return; var icon = p.icon && p.icon.path ? L.icon({ iconUrl: assetPath(p.icon.path), iconSize: [p.icon.width || 24, p.icon.height || 24] }) : L.divIcon({ className: "tp-hist-ldiv", html: '<span class="tp-hist-evpin" style="background:#7c3aed"></span>', iconSize: [14, 14], iconAnchor: [7, 7] }); L.marker([p.lat, p.lng], { icon: icon }).bindTooltip(p.name || "POI").addTo(g); }); return g; }
  function setOverlay(kind, on) {
    state.display[kind] = on; var ov = state.overlays[kind];
    if (on) { if (ov) ov.addTo(state.map); else { var url = kind === "geofences" ? CFG.zonesUrl : kind === "routes" ? CFG.routesUrl : CFG.poisUrl, b = kind === "geofences" ? buildGeofences : kind === "routes" ? buildRoutes : buildPois; fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } }).then(function (r) { return r.json(); }).then(function (j) { state.overlays[kind] = b(j && j.items); if (state.display[kind]) state.overlays[kind].addTo(state.map); }).catch(function () {}); } }
    else if (ov) state.map.removeLayer(ov);
  }
  function applyDisplay(key) {
    var on = state.display[key];
    if (key === "route") { if (state.routeGroup) on ? state.routeGroup.addTo(state.map) : state.map.removeLayer(state.routeGroup); if (state.decorator) (on && state.display.arrows) ? state.decorator.addTo(state.map) : state.map.removeLayer(state.decorator); }
    else if (key === "arrows") { if (state.decorator) (on && state.display.route) ? state.decorator.addTo(state.map) : state.map.removeLayer(state.decorator); }
    else if (key === "stops") { if (state.stopGroup) on ? state.stopGroup.addTo(state.map) : state.map.removeLayer(state.stopGroup); }
    else if (key === "events") { if (state.eventGroup) on ? state.eventGroup.addTo(state.map) : state.map.removeLayer(state.eventGroup); }
    else if (key === "hitos") {
      // Hitos kilométricos (capa compartida tp-hitos.js)
      if (window.tpHitos) {
        if (!state.hitosCtrl) state.hitosCtrl = tpHitos.create(state.map);
        state.hitosCtrl.setVisible(on);
      }
    }
    else setOverlay(key, on);
  }

  /* ===== REGISTRO DE DATOS ===== */
  function loadDataLog(page) {
    if (!state.range || !state.deviceId) return;
    state.dl.page = page || 1;
    var limitSel = $("#tp-hist-datalog-limit");
    var limit = limitSel ? parseInt(limitSel.value, 10) || 50 : 50;
    var qs = new URLSearchParams({ device: state.deviceId, from: apiFrom(), to: apiTo(), page: state.dl.page, limit: limit });
    fetch(CFG.messagesUrl + "?" + qs.toString(), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        state.dl.last = j.last || 1;
        var cols = j.columns || [];
        // Cabecera dinámica: base + TODOS los parámetros del XML `other`.
        $("#tp-hist-datalog-head").innerHTML = "<tr>" + cols.map(function (c) { return "<th>" + esc(c.label) + "</th>"; }).join("") + "</tr>";
        $("#tp-hist-datalog-body").innerHTML = (j.rows || []).map(function (m) {
          return "<tr>" + cols.map(function (c) {
            var v = m[c.key];
            if (c.key === "valid") v = v ? "sí" : "no";
            return "<td>" + esc(v == null || v === "" ? "—" : v) + "</td>";
          }).join("") + "</tr>";
        }).join("");
        $("#tp-hist-datalog-info").textContent = T("page", "Página") + " " + j.page + " / " + j.last + " · " + (j.total || 0);
        $("#tp-hist-datalog-prev").disabled = j.page <= 1;
        $("#tp-hist-datalog-next").disabled = j.page >= j.last;
        updateFooterVar();
      }).catch(function () {});
  }

  /* ===== EXPORT (endpoint legacy history.export: gsr|kml|gpx|csv) ===== */
  var exportMenu = null;
  function closeExportMenu() {
    if (exportMenu) { exportMenu.remove(); exportMenu = null; document.removeEventListener("click", closeExportMenu); }
  }
  function openExportMenu(btn) {
    if (exportMenu) { closeExportMenu(); return; }
    exportMenu = document.createElement("div");
    exportMenu.className = "tp-hist-export-menu";
    exportMenu.innerHTML = [["gsr", "GSR"], ["kml", "KML"], ["gpx", "GPX"], ["csv", "CSV"]]
      .map(function (f) { return '<button type="button" data-fmt="' + f[0] + '">' + esc(T("export", "Exportar")) + " " + f[1] + "</button>"; })
      .join("");
    document.body.appendChild(exportMenu);
    var r = btn.getBoundingClientRect();
    exportMenu.style.top = r.bottom + 6 + "px";
    exportMenu.style.left = Math.min(r.left, window.innerWidth - exportMenu.offsetWidth - 8) + "px";
    exportMenu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-fmt]");
      if (!b) return;
      exportHistory(b.getAttribute("data-fmt"));
      closeExportMenu();
    });
    setTimeout(function () { document.addEventListener("click", closeExportMenu); }, 0);
  }
  // Mismo contrato que el legacy (history.js _this.export): GET con los filtros
  // + format → {download} y navegamos a la URL de descarga.
  function exportHistory(format) {
    if (!state.deviceId || !state.range) return;
    var qs = new URLSearchParams({
      device_id: state.deviceId,
      from_date: state.range.fromDate,
      from_time: state.range.fromTime,
      to_date: state.range.toDate,
      to_time: state.range.toTime,
      snap_to_road: $("#tp-hist-snap").checked ? "true" : "false",
      stops: $("#tp-hist-stops").value || 0,
      show_invalid: $("#tp-hist-invalid").checked ? "true" : "false",
      format: format,
    });
    toast(T("generating_export", "Generando exportación") + " " + format.toUpperCase() + "…", "info");
    fetch(CFG.exportUrl + "?" + qs.toString(), {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.download) window.location.href = res.download;
        else if (res && res.error) toast(res.error, "warning");
        else toast(T("export_failed", "No se pudo generar la exportación"), "warning");
      })
      .catch(function () { toast(T("export_failed", "No se pudo generar la exportación"), "error"); });
  }

  /* ===== CARGA ===== */
  function apiFrom() { return state.range.fromDate + " " + state.range.fromTime + ":00"; }
  function apiTo() { return state.range.toDate + " " + state.range.toTime + ":59"; }
  function load() {
    var id = combo.getAttribute("data-value");
    if (!id) { toast(T("select_device", "Selecciona un vehículo"), "warning"); comboInput.focus(); return; }
    if (!state.range) { toast(T("select_date", "Selecciona una fecha"), "warning"); openDatePick(); return; }
    pause(); state.deviceId = parseInt(id, 10);
    $("#tp-hist-loading").hidden = false;
    initMap();
    var qs = new URLSearchParams({ device: id, from: apiFrom(), to: apiTo(), stops: $("#tp-hist-stops").value || 180, snap: $("#tp-hist-snap").checked ? 1 : 0, show_invalid: $("#tp-hist-invalid").checked ? 1 : 0 });
    fetch(CFG.dataUrl + "?" + qs.toString(), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (resp) {
        $("#tp-hist-loading").hidden = true;
        if (!resp.ok || !resp.body.ok) { toast("Error: " + ((resp.body && (resp.body.message || resp.body.error)) || "desconocido"), "warning"); return; }
        var b = resp.body;
        state.device = b.device || null; state.deviceName = (b.device && b.device.name) || state.deviceName;
        // Colores del recorrido según la config del VEHÍCULO
        var rc = (b.device && b.device.route) || {};
        state.colorMode = rc.type === "sensor" ? "speed" : (rc.type || "trips");
        state.routeSingle = rc.color || "#2563eb";
        state.routeRanges = rc.ranges && rc.ranges.length ? rc.ranges : null;
        state.positions = b.positions || []; state.timeline = b.timeline || []; state.summary = b.summary || {}; state.sensors = b.sensors || [];
        buildSegments();
        clearAll();
        if (!state.positions.length) { renderTimeline(); $("#tp-hist-summary").hidden = true; $("#tp-hist-bottom").hidden = true; toast(T("no_data", "Sin datos"), "info"); return; }
        $("#tp-hist-download").disabled = false; $("#tp-hist-clear").disabled = false;
        renderSensorTabs();
        buildRoute(); buildMarkers(); renderTimeline(); renderSummary(); setupGraphView(); updateLegend();
        ["geofences", "poi", "routes"].forEach(function (k) { if (state.display[k]) setOverlay(k, true); });
        if (state.routeGroup) state.map.fitBounds(state.routeGroup.getBounds(), { padding: [40, 40] });
        loadDataLog(1);
      })
      .catch(function (e) { $("#tp-hist-loading").hidden = true; toast("Error al cargar: " + (e.message || e), "error"); });
  }
  function buildSegments() {
    state.segments = []; var dIdx = 0;
    for (var i = 0; i < state.timeline.length; i++) {
      var it = state.timeline[i]; if (it.status !== S_DRIVE && it.status !== S_STOP) continue;
      var s = it.start_idx != null ? it.start_idx : 0, e = state.positions.length - 1;
      for (var j = i + 1; j < state.timeline.length; j++) { var n = state.timeline[j]; if (n.start_idx != null && (n.status === S_DRIVE || n.status === S_STOP)) { e = n.start_idx - 1; break; } }
      state.segments.push({ status: it.status, s: s, e: e, colorIdx: it.status === S_DRIVE ? dIdx++ : 0 });
    }
  }
  function clearHistory() {
    pause(); clearAll();
    state.positions = []; state.timeline = []; state.segments = []; state.summary = null;
    renderTimeline(); $("#tp-hist-summary").hidden = true; $("#tp-hist-bottom").hidden = true; updateFooterVar();
    $("#tp-hist-download").disabled = true; $("#tp-hist-clear").disabled = true;
  }
  function updateLegend() {
    var el = $("#tp-hist-legend");
    el.hidden = state.colorMode !== "speed" || state.positions.length < 2;
    if (el.hidden) return;
    // Barra + ticks dinámicos según los rangos del vehículo
    var R = state.routeRanges || DEFAULT_RANGES;
    var maxB = R.length > 1 ? R[R.length - 1].from : 100;
    var scale = maxB > 0 ? maxB : 100;
    var bar = el.querySelector(".tp-history__legend-bar");
    var ticks = el.querySelector(".tp-history__legend-ticks");
    if (bar)
      bar.innerHTML = R.map(function (r, i) {
        var last = i === R.length - 1;
        var w = last ? 18 : Math.max(6, ((r.to - r.from) / scale) * 82);
        return '<span style="flex:0 0 ' + w + "%;background:" + (r.color || "#2563eb") + '"></span>';
      }).join("");
    if (ticks)
      ticks.innerHTML = R.map(function (r) { return "<span>" + r.from + "</span>"; }).join("") + "<span>∞</span>";
  }

  /* ===== UI ===== */
  function setActive(sel, btn) { document.querySelector(sel).querySelectorAll(".tp-seg__btn").forEach(function (x) { x.classList.remove("is-active"); }); btn.classList.add("is-active"); }
  function bind() {
    bindDatePicker();
    $("#tp-hist-adv-toggle").addEventListener("click", function () { var a = $("#tp-hist-adv"); a.hidden = !a.hidden; this.setAttribute("aria-expanded", a.hidden ? "false" : "true"); this.classList.toggle("is-open", !a.hidden); });
    $("#tp-hist-load").addEventListener("click", load);
    $("#tp-hist-download").addEventListener("click", function (e) {
      e.stopPropagation();
      openExportMenu(this);
    });
    $("#tp-hist-clear").addEventListener("click", clearHistory);
    $("#tp-hist-side-collapse").addEventListener("click", function () { root.classList.add("tp-history--side-collapsed"); setTimeout(function () { state.map && state.map.invalidateSize(); }, 250); });
    var sideExpand = $("#tp-hist-side-expand");
    if (sideExpand) sideExpand.addEventListener("click", function () { root.classList.remove("tp-history--side-collapsed"); });

    // player (fila 2 del panel Gráfico)
    $("#tp-hist-play").addEventListener("click", function () { state.playing ? pause() : play(); });
    var stopBtn = $("#tp-hist-stop");
    if (stopBtn)
      stopBtn.addEventListener("click", function () {
        pause();
        state.playStart = 0; state.playEnd = state.positions.length - 1;
        moveCursor(0);
        var p = state.positions[0];
        if (p) state.map.panTo([p.lat, p.lng]);
      });
    var speedSel = $("#tp-hist-speed-sel");
    if (speedSel)
      speedSel.addEventListener("change", function () { state.rate = parseInt(this.value, 10) || 1; });
    // Zoom del gráfico
    var gzIn = $("#tp-hist-gzoom-in"), gzOut = $("#tp-hist-gzoom-out"), gzReset = $("#tp-hist-gzoom-reset");
    if (gzIn) gzIn.addEventListener("click", function () { zoomGraph(0.5); });
    if (gzOut) gzOut.addEventListener("click", function () { zoomGraph(2); });
    if (gzReset) gzReset.addEventListener("click", resetGraphZoom);
    // Tabs de sensores (dinámicas)
    $("#tp-hist-sensor").addEventListener("click", function (e) {
      var b = e.target.closest("[data-sensor]"); if (!b) return;
      $("#tp-hist-sensor").querySelectorAll("[data-sensor]").forEach(function (x) { x.classList.remove("is-active"); });
      b.classList.add("is-active");
      state.sensor = b.getAttribute("data-sensor");
      drawGraph();
    });

    // bottom tabs (Gráfico / Registro de datos)
    root.querySelector(".tp-history__bottom-tabs").addEventListener("click", function (e) {
      var b = e.target.closest("[data-bottom-tab]"); if (!b) return;
      root.querySelectorAll(".tp-history__bottom-tab").forEach(function (x) { x.classList.remove("is-active"); }); b.classList.add("is-active");
      var t = b.getAttribute("data-bottom-tab");
      root.querySelectorAll(".tp-history__bottom-panel").forEach(function (p) { p.hidden = p.getAttribute("data-bottom-panel") !== t; });
      if (t === "graph") drawGraph();
      updateFooterVar();
    });
    $("#tp-hist-bottom-collapse").addEventListener("click", function () {
      var collapsed = root.classList.toggle("tp-history--bottom-collapsed");
      this.setAttribute("aria-pressed", collapsed ? "true" : "false");
      setTimeout(function () { drawGraph(); updateFooterVar(); state.map && state.map.invalidateSize(); }, 220);
    });
    // Pin: footer al ancho que deja el sidebar (ON) o a todo el ancho (OFF,
    // el sidebar se adapta al alto restante).
    var pinBtn = $("#tp-hist-bottom-pin");
    if (pinBtn)
      pinBtn.addEventListener("click", function () {
        var on = root.classList.toggle("tp-history--pinned");
        pinBtn.setAttribute("aria-pressed", on ? "true" : "false");
        setTimeout(function () { drawGraph(); updateFooterVar(); state.map && state.map.invalidateSize(); }, 60);
      });
    $("#tp-hist-datalog-prev").addEventListener("click", function () { if (state.dl.page > 1) loadDataLog(state.dl.page - 1); });
    $("#tp-hist-datalog-next").addEventListener("click", function () { if (state.dl.page < state.dl.last) loadDataLog(state.dl.page + 1); });
    var dlLimit = $("#tp-hist-datalog-limit");
    if (dlLimit) dlLimit.addEventListener("change", function () { loadDataLog(1); });

    // map controls
    $("#tp-hist-zoom-in").addEventListener("click", function () { state.map && state.map.zoomIn(); });
    $("#tp-hist-zoom-out").addEventListener("click", function () { state.map && state.map.zoomOut(); });
    // Vista completa: despeja la UI (sidebar, resumen, footer) y deja solo el mapa
    $("#tp-hist-fullscreen").addEventListener("click", function () {
      var on = root.classList.toggle("tp-history--clean");
      this.setAttribute("aria-pressed", on ? "true" : "false");
      setTimeout(function () { state.map && state.map.invalidateSize(); updateFooterVar(); drawGraph(); }, 80);
    });

    // ===== Regla de medición (equivalente al leaflet.ruler del legacy) =====
    var rulerPanel = $("#tp-hist-ruler-panel");
    var rulerBtn = $("#tp-hist-ruler");
    function rulerSet(on) {
      rulerState.on = on;
      var bOn = $("#tp-hist-ruler-on"), bOff = $("#tp-hist-ruler-off");
      if (bOn) bOn.classList.toggle("is-active", on);
      if (bOff) bOff.classList.toggle("is-active", !on);
      if (rulerBtn) rulerBtn.setAttribute("aria-pressed", on ? "true" : "false");
      state.map.getContainer().style.cursor = on ? "crosshair" : "";
    }
    if (rulerBtn)
      rulerBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        rulerPanel.hidden = !rulerPanel.hidden;
      });
    var rClose = $("#tp-hist-ruler-close");
    if (rClose) rClose.addEventListener("click", function () { rulerPanel.hidden = true; });
    var rOn = $("#tp-hist-ruler-on"), rOff = $("#tp-hist-ruler-off"), rClear = $("#tp-hist-ruler-clear");
    if (rOn) rOn.addEventListener("click", function () { rulerSet(true); });
    if (rOff) rOff.addEventListener("click", function () { rulerSet(false); });
    if (rClear) rClear.addEventListener("click", function () { rulerState.pts = []; rulerRedraw(); });
    var styleBtn = $("#tp-hist-style"), menu = $("#tp-hist-providers-menu");
    styleBtn.addEventListener("click", function (e) { e.stopPropagation(); if (menu.hidden) { menu.style.position = "fixed"; menu.hidden = false; var r = styleBtn.getBoundingClientRect(), mw = menu.offsetWidth || 220, mh = menu.offsetHeight || 200; menu.style.left = Math.max(8, r.left - mw - 8) + "px"; menu.style.top = Math.min(Math.max(8, r.top), window.innerHeight - mh - 8) + "px"; } else menu.hidden = true; });
    document.addEventListener("click", function () { if (menu) menu.hidden = true; });
    root.querySelectorAll(".tp-hist__toggle[data-display]").forEach(function (btn) { var key = btn.getAttribute("data-display"); state.display[key] = btn.getAttribute("aria-pressed") === "true"; btn.addEventListener("click", function () { state.display[key] = !state.display[key]; btn.setAttribute("aria-pressed", state.display[key] ? "true" : "false"); applyDisplay(key); }); });
    // Hitos persistidos (m_hitos, seed del blade vía aria-pressed): aplicar al
    // cargar (diferido: tp-hitos.js es defer y el mapa debe existir).
    if (state.display.hitos) setTimeout(function () { applyDisplay("hitos"); }, 300);
    // Overlays persistidos (m_geofences/m_routes/m_poi, seed del blade): pintar
    // al abrir la página como en Live — no solo tras "Mostrar historial".
    setTimeout(function () {
      ["geofences", "routes", "poi"].forEach(function (k) { if (state.display[k]) setOverlay(k, true); });
    }, 300);

    new MutationObserver(drawGraph).observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
  }

  function init() {
    bind();
    startToday(); applyQuickRange("today"); commitRange();
    initMap();
    loadDevices().then(function () {
      var qs = new URLSearchParams(location.search), dev = qs.get("device");
      if (dev) { var d = state.devices.filter(function (x) { return x.id === parseInt(dev, 10); })[0]; if (d) selDevice(d.id, d.name); }
      var range = qs.get("range");
      if (range && ["today", "yesterday", "week", "month"].indexOf(range) !== -1) { applyQuickRange(range); commitRange(); }
      if (dev && combo.getAttribute("data-value")) load();
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
