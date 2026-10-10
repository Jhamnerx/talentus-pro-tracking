/* =====================================================
 * TalentusPro · Monitoreo de grupo
 * -----------------------------------------------------
 * Cuadricula de mini-mapas a elegir (de 2 a 32 celdas); cada
 * celda sigue en vivo a un vehículo elegido con el selector
 * agrupado (grupo → devices con estado + icono). El tamaño y
 * la selección persisten en localStorage.
 * Reusa el feed de Live (api.devices + api.devices.updates).
 * ===================================================== */
(function () {
  "use strict";

  var root = document.getElementById("tp-monitor");
  if (!root) return;

  var CFG = {
    zonesUrl: root.getAttribute("data-zones-url"),
    poisUrl: root.getAttribute("data-pois-url"),
    routesUrl: root.getAttribute("data-routes-url"),
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

  /* Disposiciones ofrecidas. Es la unica fuente de verdad: los botones del
     selector se pintan desde aqui, asi no hay forma de que el HTML y el JS
     discrepen sobre que tamaños existen. `cols` es la forma deseada; si la
     pantalla no da, se recorta al vuelo (ver aplicaColumnas). */
  var LAYOUTS = [
    { n: 2, cols: 2 },
    { n: 4, cols: 2 },
    { n: 6, cols: 3 },
    { n: 9, cols: 3 },
    { n: 12, cols: 4 },
    { n: 16, cols: 4 },
    { n: 24, cols: 6 },
    { n: 32, cols: 8 }
  ];
  var MAX_SLOTS = 32;
  var TAM_POR_DEFECTO = 9;
  var ANCHO_MIN_CELDA = 230; // por debajo de esto el mini-mapa no dice nada

  var LS_KEY = "tp_monitor_slots";
  var LS_TAM = "tp_monitor_size";
  var LS_CAPAS = "tp_monitor_layers";

  function layoutDe(n) {
    for (var i = 0; i < LAYOUTS.length; i++) if (LAYOUTS[i].n === n) return LAYOUTS[i];
    return null;
  }

  var state = {
    index: {},
    devices: [],
    groups: [],
    slots: [],
    cells: [],
    size: TAM_POR_DEFECTO,
    checkTime: "",
    // Capas del mapa, apagadas por defecto: en celdas pequeñas estorban mas
    // que ayudan, y quien las quiera las deja encendidas (se recuerdan).
    capas: { zones: false, pois: false, routes: false },
  };
  try {
    var capasGuardadas = JSON.parse(localStorage.getItem(LS_CAPAS) || "null");
    if (capasGuardadas && typeof capasGuardadas === "object") {
      state.capas.zones = !!capasGuardadas.zones;
      state.capas.pois = !!capasGuardadas.pois;
      state.capas.routes = !!capasGuardadas.routes;
    }
  } catch (e) {}
  try {
    state.slots = JSON.parse(localStorage.getItem(LS_KEY) || "[]") || [];
  } catch (e) {
    state.slots = [];
  }
  // El array se guarda SIEMPRE con 32 huecos aunque se vean menos: al
  // encoger, los vehiculos de las celdas que se ocultan no se pierden y
  // vuelven tal cual si se agranda otra vez.
  while (state.slots.length < MAX_SLOTS) state.slots.push(null);
  state.slots = state.slots.slice(0, MAX_SLOTS);

  try {
    var tamGuardado = parseInt(localStorage.getItem(LS_TAM), 10);
    if (layoutDe(tamGuardado)) state.size = tamGuardado;
  } catch (e) {}

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
    online: tpT('front.move'),
    excessive_speed: tpT('front.overspeed'),
    engine: tpT('ui.engine_on'),
    ack: tpT('front.stopped'),
    stop: tpT('front.stopped'),
    idle: tpT('front.idle'),
    blocked: tpT('front.blocked'),
    offline: tpT('front.offline'),
    expired: tpT('front.expired'),
  };
  // Mismo criterio que live.js: getStatusColor() devuelve el color por NOMBRE
  // y aqui se traduce al token del tema. "ink" es el tono para texto.
  // Se repite en cada fichero porque shell.js va con defer y se ejecuta
  // despues que estos.
  var SC_NOMBRES = {
    green: 1, yellow: 1, orange: 1, red: 1,
    blue: 1, cyan: 1, gray: 1, black: 1, white: 1,
  };
  function statusVar(c, variante) {
    if (!c) return "";
    c = String(c).toLowerCase();
    if (SC_NOMBRES[c]) return "var(--sc-" + c + (variante ? "-" + variante : "") + ")";
    return c.charAt(0) === "#" ? c : "";
  }

  // El color del vehiculo (icon_colors del legacy). Antes eran cinco
  // hexadecimales fijos que ademas no cuadraban con el legacy: ack salia azul
  // y blocked rojo, cuando alli son gris y negro.
  function statusTone(d, variante) {
    return statusVar(d && d.icon_color, variante) || "var(--status-offline)";
  }

  /* ----- marcador con el icono REAL del device (compacto de live.js) ----- */
  /* ── Color del marcador por velocidad (igual que en Live) ───────────
     El rango manda salvo dentro de una geocerca con limite, donde pintan
     los colores de "proximo" y "excedido". Sin transmitir no se aplica:
     ese 0 kph es el ultimo dato conocido, no una medida. */
  function colorPorVelocidad(d) {
    var cfg = d.marker_colors;
    if (!cfg) return null;
    if (clasifica(d) === "offline") return null;

    var v = parseFloat(d.speed);
    if (isNaN(v)) return null;

    var def = (MAP_CFG && MAP_CFG.markerColors) || {};
    var defGeo = def.geofence || {};

    var gf = window.tpMapLayers && tpMapLayers.limitFor
      ? tpMapLayers.limitFor(d.lat, d.lng, d.vehicle_type_id, d.vehicle_state_id)
      : null;

    if (gf && gf.limit) {
      var margen = cfg.margin != null ? +cfg.margin : (+defGeo.margin || 0);
      if (v >= gf.limit) return cfg.over || defGeo.over || null;
      if (margen > 0 && v >= gf.limit - margen) return cfg.near || defGeo.near || null;
    }

    var rangos = cfg.ranges || def.ranges || [];
    for (var i = 0; i < rangos.length; i++) {
      var r = rangos[i];
      var desde = r.from == null ? -Infinity : +r.from;
      var hasta = r.to == null ? Infinity : +r.to;
      if (v >= desde && v < hasta) return r.color || null;
    }
    return rangos.length ? rangos[rangos.length - 1].color || null : null;
  }

  // Los mismos cubos que Live, para saber si el equipo transmite.
  function clasifica(d) {
    switch (d && d.online) {
      case "online": case "excessive_speed": case "move": return "moving";
      case "engine": case "idle": return "idle";
      case "ack": case "stop": case "park": case "blocked": return "stopped";
      default: return "offline";
    }
  }

  function deviceIcon(d) {
    var icon = d.icon || {};
    var type = icon.type || "arrow";
    var src, w, h, rotate = false, anchorBottom = false;
    if (type === "arrow") {
      src = "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
      // 50x50: es el tamaño real de esos PNG (ver live.js)
      w = 50; h = 50; rotate = true;
    } else if (type === "rotating") {
      src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; rotate = true;
    } else {
      src = assetPath(icon.path); w = icon.width || 32; h = icon.height || 32; anchorBottom = true;
    }
    var rot = rotate ? "transform:rotate(" + (parseFloat(d.course) || 0) + "deg);" : "";

    // Resplandor: disco tenue detras y brillo pegado a la silueta, el mismo
    // tratamiento que en Live. El color sale de la velocidad; si el vehiculo
    // no la usa, del estado (icon_color -> getStatusColor del legacy).
    var col = colorPorVelocidad(d) || statusVar(d.icon_color);
    var haloPx = Math.round(Math.max(w, h) * 1.15) + 6;
    var haloMid = -Math.round(haloPx / 2);
    var halo = col
      ? '<span class="tp-mon-halo" style="background:' + col +
        ";width:" + haloPx + "px;height:" + haloPx + "px;margin:" + haloMid + "px 0 0 " +
        haloMid + 'px;"></span>'
      : "";

    return L.divIcon({
      html: halo + '<img src="' + src + '" width="' + w + '" height="' + h +
        '" style="display:block;' + (col ? "--tp-halo:" + col + ";" : "") + rot + '" draggable="false">',
      className: "tp-mon-marker" + (col ? " tp-mon-marker--colored" : ""),
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

  /* Columnas: la forma del layout, recortada a lo que quepa. Un 8x4 en una
     pantalla estrecha daria celdas de 90px, que no son un mapa. */
  function aplicaColumnas() {
    var lay = layoutDe(state.size) || { cols: 3 };
    var ancho = grid.clientWidth || window.innerWidth || 1200;
    var caben = Math.max(1, Math.floor(ancho / ANCHO_MIN_CELDA));

    grid.style.setProperty("--mon-cols", Math.min(lay.cols, caben));
  }

  function tiraMapas() {
    state.cells.forEach(function (c) {
      if (c.map) {
        try { c.map.remove(); } catch (e) {}
      }
    });
    state.cells = [];
  }

  function construyeCeldas() {
    tiraMapas();
    grid.innerHTML = "";

    for (var i = 0; i < state.size; i++) {
      var el = document.createElement("div");
      el.className = "tp-mon__cell tp-mon__cell--empty";
      grid.appendChild(el);
      state.cells.push({ el: el, map: null, marker: null });
    }

    renderAll();
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
      '<button type="button" class="tp-mon__clear" data-clear="' + i + '" title="' + tpT('ui.remove_vehicle') + '">×</button>' +
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
      pill.style.color = statusTone(d, "ink");
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
      pintaCapas(cell);
    } else {
      // Primero el icono -rumbo y color- y luego la posicion: setIcon rehace
      // el elemento y cortaria el deslizamiento (igual que en Live).
      cell.marker.setIcon(deviceIcon(d));
      mueve(cell, ll);
      cell.map.panTo(ll);
    }
  }

  /**
   * Lleva el marcador a su nueva posicion, deslizandolo si el plugin
   * "Animacion de movimiento del dispositivo" esta activo. La duracion es el
   * intervalo de refresco, como el checkFrequency del legacy.
   */
  function mueve(cell, ll) {
    var m = cell.marker;
    var anima =
      MAP_CFG.animateMove &&
      typeof m.slideTo === "function" &&
      cell.map && cell.map.hasLayer(m);

    if (!anima) { m.setLatLng(ll); return; }

    var destino = L.latLng(ll[0], ll[1]);
    if (m.getLatLng().equals(destino)) return;

    setTimeout(function () {
      if (!cell.map || !cell.map.hasLayer(m)) return;
      try { m.slideTo(destino, { duration: 15000 }); } catch (e) { m.setLatLng(destino); }
    }, 150);
  }

  /* ── Capas (geocercas, POIs, rutas) ─────────────────────────────────
     Los datos se piden una vez para toda la pantalla; las capas de Leaflet
     se construyen por celda, porque una capa solo cuelga de un mapa. */
  function pintaCapas(cell) {
    if (!cell || !cell.map || !window.tpMapLayers) return;

    if (cell.capas) { cell.capas.quitar(); cell.capas = null; }

    if (!state.capas.zones && !state.capas.pois && !state.capas.routes) return;

    tpMapLayers
      .cargar({ zones: CFG.zonesUrl, pois: CFG.poisUrl, routes: CFG.routesUrl })
      .then(function () {
        if (!cell.map) return;
        cell.capas = tpMapLayers.dibujar(cell.map, state.capas);
      });
  }

  function pintaCapasTodas() {
    state.cells.forEach(function (c) { if (c && c.map) pintaCapas(c); });
  }

  /* ── Interruptores de capa de la cabecera ───────────────────────────── */
  function montaInterruptores() {
    var btns = document.querySelectorAll("[data-mon-layer]");
    Array.prototype.forEach.call(btns, function (b) {
      var k = b.getAttribute("data-mon-layer");
      function pinta() {
        var on = !!state.capas[k];
        b.classList.toggle("is-on", on);
        b.setAttribute("aria-pressed", on ? "true" : "false");
      }
      pinta();
      b.addEventListener("click", function () {
        state.capas[k] = !state.capas[k];
        pinta();
        guardaCapas();
        // El color por velocidad dentro de una geocerca necesita las zonas
        // cargadas, asi que se repintan tambien los marcadores.
        pintaCapasTodas();
        updateAll();
      });
    });
  }

  function guardaCapas() {
    try { localStorage.setItem(LS_CAPAS, JSON.stringify(state.capas)); } catch (e) {}
  }

  function renderAll() {
    for (var i = 0; i < state.cells.length; i++) renderCell(i);
  }
  function updateAll() {
    for (var i = 0; i < state.cells.length; i++) updateCell(i);
  }

  /* ----- selector de tamaño de cuadricula ----- */
  var cajaTam = document.getElementById("tp-mon-sizes");
  var pista = document.getElementById("tp-mon-hint");

  function pintaSelectorTam() {
    if (cajaTam) {
      cajaTam.innerHTML = LAYOUTS.map(function (l) {
        return '<button type="button" class="tp-segmented__item" data-size="' + l.n + '"' +
          (l.n === state.size ? ' aria-selected="true"' : "") +
          ' title="' + l.n + tpT('ui.vehicles_up_to') + l.cols + ' columnas">' + l.n + "</button>";
      }).join("");
    }

    if (pista) {
      pista.textContent = tpT('ui.pick_up_to') + state.size +
        tpT('ui.monitor_grid_note');
    }
  }

  function cambiaTam(n) {
    if (!layoutDe(n) || n === state.size) return;

    state.size = n;
    try { localStorage.setItem(LS_TAM, String(n)); } catch (e) {}

    pintaSelectorTam();
    aplicaColumnas();
    construyeCeldas();
  }

  if (cajaTam) {
    cajaTam.addEventListener("click", function (e) {
      var b = e.target.closest("[data-size]");
      if (b) cambiaTam(parseInt(b.getAttribute("data-size"), 10));
    });
  }

  /* Al cambiar el ancho puede caber otra columna: se recalcula y se avisa a
     Leaflet, que si no se queda con el tamaño viejo y pinta tiles cortadas. */
  var tRedim;
  window.addEventListener("resize", function () {
    clearTimeout(tRedim);
    tRedim = setTimeout(function () {
      aplicaColumnas();
      state.cells.forEach(function (c) {
        if (c.map) {
          try { c.map.invalidateSize(); } catch (e) {}
        }
      });
    }, 180);
  });

  /* ----- selector de vehículo (agrupado, con estado + icono) ----- */
  function openPicker(slot) {
    // Solo cuentan las celdas a la vista: marcar "En uso" por un vehiculo
    // que vive en una celda oculta seria incomprensible.
    var used = {};
    state.slots.forEach(function (id, idx) {
      if (id && idx !== slot && idx < state.size) used[id] = 1;
    });
    var groupsMap = { 0: tpT('front.ungrouped') };
    (state.groups || []).forEach(function (g) {
      groupsMap[g.id] = g.title;
    });

    var dlg = tpModal.open({
      title: tpT('ui.select_vehicle'),
      sub: "Celda " + (slot + 1) + tpT('ui.grouped_by_object_group'),
      size: "md",
      body:
        '<input type="search" class="tp-input" data-mp-search placeholder="' + tpT('ui.search_vehicle') + '" autocomplete="off" style="margin-bottom:10px;">' +
        '<div class="tp-mon__picklist" data-mp-list></div>',
      buttons: [{ label: tpT('global.cancel'), variant: "ghost", close: true }],
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
          html += '<div class="tp-mon__pickgroup">' + esc(groupsMap[gid] || tpT('front.ungrouped')) + "</div>";
        }
        var isUsed = !!used[d.id];
        html +=
          '<button type="button" class="tp-mon__pickitem" data-pick="' + d.id + '"' + (isUsed ? " disabled" : "") + ">" +
          '<span class="tp-mon__pickdot" style="background:' + statusTone(d) + '"></span>' +
          '<img class="tp-mon__pickicon" src="' + esc(thumbSrc(d)) + '" alt="">' +
          '<span class="tp-mon__pickname">' + esc(d.name || "#" + d.id) + "</span>" +
          '<span class="tp-mon__pickstate">' + (isUsed ? tpT('ui.in_use') : esc(STATUS_LABELS[d.online] || d.online || "")) + "</span>" +
          "</button>";
        shown++;
      });
      listEl.innerHTML = shown
        ? html
        : '<div class="tp-text-muted tp-text-sm" style="padding:14px;">' + tpT('ui.no_results') + '</div>';
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

      var elegido = parseInt(it.getAttribute("data-pick"), 10);

      // Fuera de cualquier otra celda, incluidas las ocultas: si no, al
      // agrandar la cuadricula saldria el mismo vehiculo dos veces.
      var liberadas = [];
      state.slots.forEach(function (v, idx) {
        if (v === elegido && idx !== slot) {
          state.slots[idx] = null;
          liberadas.push(idx);
        }
      });

      state.slots[slot] = elegido;
      save();

      renderCell(slot);
      liberadas.forEach(function (idx) {
        if (idx < state.cells.length) renderCell(idx);
      });

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

  pintaSelectorTam();
  montaInterruptores();
  aplicaColumnas();
  construyeCeldas();

  fetchDevices().then(function () {
    setInterval(poll, 15000);
  });
})();
