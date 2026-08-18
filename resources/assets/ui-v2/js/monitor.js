/* =====================================================
 * TalentusPro · Monitoreo de grupo
 * -----------------------------------------------------
 * Grilla 3×3 de mini-mapas; cada celda sigue en vivo a un
 * vehículo elegido con el selector agrupado (grupo → devices
 * con estado + icono). La selección persiste en localStorage.
 * Reusa el feed de Live (api.devices + api.devices.updates).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-monitor");
  if (!root) return;

  var CFG = {
    devicesUrl: root.getAttribute("data-devices-url"),
    updatesUrl: root.getAttribute("data-updates-url"),
  };
  var MAP_CFG = (function () {
    try {
      return JSON.parse(document.getElementById("tp-monitor-map-config").textContent) || {};
    } catch (e) {
      return {};
    }
  })();

  var SLOTS = 9;
  var LS_KEY = "tp_monitor_slots";

  var state = {
    index: {},
    devices: [],
    groups: [],
    slots: [],
    cells: [],
    checkTime: "",
  };
  try {
    state.slots = JSON.parse(localStorage.getItem(LS_KEY) || "[]") || [];
  } catch (e) {
    state.slots = [];
  }
  while (state.slots.length < SLOTS) state.slots.push(null);
  state.slots = state.slots.slice(0, SLOTS);

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function assetPath(p) {
    if (!p) return "";
    return p.charAt(0) === "/" ? p : "/" + p;
  }
  function save() {
    try { localStorage.setItem(LS_KEY, JSON.stringify(state.slots)); } catch (e) {}
  }

  /* ----- estado del device (mismas claves que Live) ----- */
  var STATUS_LABELS = {
    online: "En movimiento",
    excessive_speed: "Exceso de velocidad",
    engine: "Motor encendido",
    ack: "Detenido",
    stop: "Detenido",
    idle: "Ralentí",
    blocked: "Bloqueado",
    offline: "Desconectado",
    expired: "Expirado",
  };
  function statusTone(d) {
    var s = d.online;
    if (s === "online" || s === "excessive_speed") return "#10b981";
    if (s === "engine" || s === "idle") return "#f59e0b";
    if (s === "ack" || s === "stop") return "#3b82f6";
    if (s === "blocked") return "#ef4444";
    return "#94a3b8";
  }

  /* ----- marcador con el icono REAL del device (compacto de live.js) ----- */
  function deviceIcon(d) {
    var icon = d.icon || {};
    var type = icon.type || "arrow";
    var src, w, h, rotate = false, anchorBottom = false;
    if (type === "arrow") {
      src = "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
      w = 25; h = 33; rotate = true;
    } else if (type === "rotating") {
      src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; rotate = true;
    } else {
      src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; anchorBottom = true;
    }
    var rot = rotate ? "transform:rotate(" + (parseFloat(d.course) || 0) + "deg);" : "";
    return L.divIcon({
      html: '<img src="' + src + '" width="' + w + '" height="' + h + '" style="display:block;' + rot + '" draggable="false">',
      className: "tp-mon-marker",
      iconSize: [w, h],
      iconAnchor: anchorBottom ? [w / 2, h] : [w / 2, h / 2],
    });
  }

  // Icono en miniatura para el selector (mismo origen que el marcador)
  function thumbSrc(d) {
    var icon = d.icon || {};
    if ((icon.type || "arrow") === "arrow" || !icon.path)
      return "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
    return assetPath(icon.path);
  }

  /* ----- capa base fresca por celda (una TileLayer = un mapa) ----- */
  function baseLayer() {
    try {
      if (window.tpBasemaps && tpBasemaps.build) {
        var bms = tpBasemaps.build(MAP_CFG);
        var bid = tpBasemaps.defaultId ? tpBasemaps.defaultId(bms, MAP_CFG) : null;
        if (bid && bms[bid]) return bms[bid].layer;
      }
    } catch (e) {}
    return L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 });
  }

  /* ----- celdas ----- */
  var grid = document.getElementById("tp-mon-grid");
  for (var i = 0; i < SLOTS; i++) {
    var el = document.createElement("div");
    el.className = "tp-mon__cell tp-mon__cell--empty";
    grid.appendChild(el);
    state.cells.push({ el: el, map: null, marker: null });
  }

  var PLUS =
    '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';

  function renderCell(i) {
    var cell = state.cells[i];
    var id = state.slots[i];
    if (cell.map) {
      try { cell.map.remove(); } catch (e) {}
      cell.map = null;
      cell.marker = null;
    }
    if (!id || !state.index[id]) {
      cell.el.className = "tp-mon__cell tp-mon__cell--empty";
      cell.el.innerHTML =
        '<button type="button" class="tp-mon__add" data-slot="' + i + '" title="Agregar vehículo">' + PLUS + "</button>";
      return;
    }
    var d = state.index[id];
    cell.el.className = "tp-mon__cell";
    cell.el.innerHTML =
      '<div class="tp-mon__cellhead">' +
      '<span class="tp-mon__dot" data-c-dot></span>' +
      '<span class="tp-mon__name" title="' + esc(d.name) + '">' + esc(d.name || "#" + id) + "</span>" +
      '<span class="tp-mon__pill" data-c-pill>—</span>' +
      '<button type="button" class="tp-mon__clear" data-clear="' + i + '" title="Quitar vehículo">×</button>' +
      "</div>" +
      '<div class="tp-mon__map"></div>' +
      '<div class="tp-mon__cellfoot"><span data-c-speed>—</span><span data-c-time></span></div>';

    var mm = L.map(cell.el.querySelector(".tp-mon__map"), {
      zoomControl: false,
      attributionControl: false,
    });
    baseLayer().addTo(mm);
    cell.map = mm;
    cell.marker = null;
    setTimeout(function () {
      mm.invalidateSize();
      updateCell(i);
    }, 60);
  }

  function updateCell(i) {
    var cell = state.cells[i];
    var id = state.slots[i];
    if (!cell || !cell.map || !id) return;
    var d = state.index[id];
    if (!d) return;
    var dot = cell.el.querySelector("[data-c-dot]");
    if (dot) dot.style.background = statusTone(d);
    var pill = cell.el.querySelector("[data-c-pill]");
    if (pill) {
      pill.textContent = STATUS_LABELS[d.online] || d.online || "—";
      pill.style.color = statusTone(d);
    }
    var sp = cell.el.querySelector("[data-c-speed]");
    if (sp) sp.textContent = d.speed_text || "—";
    var tm = cell.el.querySelector("[data-c-time]");
    if (tm) tm.textContent = d.time || "";

    if (d.lat == null || d.lng == null) return;
    var ll = [parseFloat(d.lat), parseFloat(d.lng)];
    if (!cell.marker) {
      cell.marker = L.marker(ll, { icon: deviceIcon(d) }).addTo(cell.map);
      cell.map.setView(ll, 15);
    } else {
      cell.marker.setLatLng(ll);
      cell.marker.setIcon(deviceIcon(d));
      cell.map.panTo(ll);
    }
  }

  function renderAll() {
    for (var i = 0; i < SLOTS; i++) renderCell(i);
  }
  function updateAll() {
    for (var i = 0; i < SLOTS; i++) updateCell(i);
  }

  /* ----- selector de vehículo (agrupado, con estado + icono) ----- */
  function openPicker(slot) {
    var used = {};
    state.slots.forEach(function (id, idx) {
      if (id && idx !== slot) used[id] = 1;
    });
    var groupsMap = { 0: "Sin grupo" };
    (state.groups || []).forEach(function (g) {
      groupsMap[g.id] = g.title;
    });

    var dlg = tpModal.open({
      title: "Seleccionar vehículo",
      sub: "Celda " + (slot + 1) + " · agrupados por grupo de objetos",
      size: "md",
      body:
        '<input type="search" class="tp-input" data-mp-search placeholder="Buscar vehículo…" autocomplete="off" style="margin-bottom:10px;">' +
        '<div class="tp-mon__picklist" data-mp-list></div>',
      buttons: [{ label: "Cancelar", variant: "ghost", close: true }],
    });

    var listEl = dlg.querySelector("[data-mp-list]");
    var searchEl = dlg.querySelector("[data-mp-search]");

    function render() {
      var q = (searchEl.value || "").toLowerCase();
      var list = state.devices.filter(function (d) {
        return !q || (d.name || "").toLowerCase().indexOf(q) !== -1;
      });
      list.sort(function (a, b) {
        var ga = a.group_id || 0, gb = b.group_id || 0;
        if (ga !== gb) {
          if (ga === 0) return -1;
          if (gb === 0) return 1;
          return String(groupsMap[ga] || "").localeCompare(String(groupsMap[gb] || ""));
        }
        return String(a.name || "").localeCompare(String(b.name || ""));
      });
      var html = "", lastG = null, shown = 0;
      list.forEach(function (d) {
        var gid = d.group_id || 0;
        if (gid !== lastG) {
          lastG = gid;
          html += '<div class="tp-mon__pickgroup">' + esc(groupsMap[gid] || "Sin grupo") + "</div>";
        }
        var isUsed = !!used[d.id];
        html +=
          '<button type="button" class="tp-mon__pickitem" data-pick="' + d.id + '"' + (isUsed ? " disabled" : "") + ">" +
          '<span class="tp-mon__pickdot" style="background:' + statusTone(d) + '"></span>' +
          '<img class="tp-mon__pickicon" src="' + esc(thumbSrc(d)) + '" alt="">' +
          '<span class="tp-mon__pickname">' + esc(d.name || "#" + d.id) + "</span>" +
          '<span class="tp-mon__pickstate">' + (isUsed ? "En uso" : esc(STATUS_LABELS[d.online] || d.online || "")) + "</span>" +
          "</button>";
        shown++;
      });
      listEl.innerHTML = shown
        ? html
        : '<div class="tp-text-muted tp-text-sm" style="padding:14px;">Sin resultados.</div>';
    }
    render();

    var t;
    searchEl.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(render, 120);
    });
    listEl.addEventListener("click", function (e) {
      var it = e.target.closest("[data-pick]");
      if (!it || it.disabled) return;
      state.slots[slot] = parseInt(it.getAttribute("data-pick"), 10);
      save();
      renderCell(slot);
      tpModal.close(dlg);
    });
  }

  /* ----- interacción de la grilla ----- */
  grid.addEventListener("click", function (e) {
    var add = e.target.closest("[data-slot]");
    if (add) {
      openPicker(parseInt(add.getAttribute("data-slot"), 10));
      return;
    }
    var clr = e.target.closest("[data-clear]");
    if (clr) {
      var i = parseInt(clr.getAttribute("data-clear"), 10);
      state.slots[i] = null;
      save();
      renderCell(i);
    }
  });

  /* ----- datos: carga completa + updates parciales (como Live) ----- */
  function fetchDevices() {
    var acc = [];
    function page(p) {
      var sep = CFG.devicesUrl.indexOf("?") === -1 ? "?" : "&";
      return fetch(CFG.devicesUrl + sep + "page=" + p, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          acc = acc.concat((j && j.data) || []);
          if (j && Array.isArray(j.groups)) state.groups = j.groups;
          var pg = (j && j.pagination) || null;
          if (pg && pg.current_page < pg.last_page) return page(pg.current_page + 1);
        });
    }
    return page(1).then(function () {
      state.devices = acc;
      state.index = {};
      acc.forEach(function (d) { state.index[d.id] = d; });
      renderAll();
    });
  }

  function poll() {
    fetch(CFG.updatesUrl + "?time=" + encodeURIComponent(state.checkTime || "") + "&_=" + Date.now(), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.time) state.checkTime = res.time;
        var items = (res && res.items) || [];
        if (!items.length) return;
        items.forEach(function (d) {
          if (state.index[d.id]) {
            for (var k in d)
              if (Object.prototype.hasOwnProperty.call(d, k)) state.index[d.id][k] = d[k];
          }
        });
        updateAll();
      })
      .catch(function () {});
  }

  fetchDevices().then(function () {
    setInterval(poll, 15000);
  });
})();
