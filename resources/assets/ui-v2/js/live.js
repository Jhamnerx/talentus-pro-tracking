/* ============================================================
 * UI v2 · Live workspace — LEAFLET engine (migración desde MapLibre)
 * ------------------------------------------------------------
 * Un solo mapa Leaflet (mismo motor que el legacy) para el workspace:
 * Objetos (devices) + paneles Geocercas/POIs sobre el MISMO mapa.
 * Paso 1: mapa + capas base + marcadores de device + lista + controles.
 * Zonas (leaflet-draw) y POIs se conectan en los commits siguientes.
 * ============================================================ */
(function () {
  "use strict";
  var root = document.getElementById("tp-live");
  if (!root || !window.L) return;

  var CFG = {
    devicesUrl: root.getAttribute("data-devices-url"),
    updatesUrl:
      root.getAttribute("data-updates-url") ||
      (root.getAttribute("data-devices-url") || "") + "/updates",
    zonesUrl: root.getAttribute("data-zones-url"),
    poisUrl: root.getAttribute("data-pois-url"),
    geoUrl: root.getAttribute("data-geo-url") || "",
    fullAddress: root.getAttribute("data-widget-full-address") === "1",
    pollInterval: parseInt(root.getAttribute("data-poll-interval"), 10) || 15000,
    // Segundos sin datos para pasar a «sin conexion» (legacy: app.offlineTimeout).
    offlineTimeout: parseInt(root.getAttribute("data-offline-timeout"), 10) || 0,
    groupToggleUrl: root.getAttribute("data-group-toggle-url") || "",
    deviceFullUrl: root.getAttribute("data-device-full-url") || "",
    // 'standart' (975h 30min 44s) o 'hh:mm:ss' (975:30:44), de la cuenta.
    durationFormat: root.getAttribute("data-duration-format") || "standart",
    // Atajos del popup/ficha (paridad popup legacy): perm history.view y
    // reporte de rutas (tipo 43) con perm reports.view + tipo habilitado.
    canHistory: root.getAttribute("data-can-history") === "1",
    routeReportType: root.getAttribute("data-route-report-type") || "",
    reportsUpdateUrl: root.getAttribute("data-reports-update-url") || "/reports/update",
    today: root.getAttribute("data-today") || "",
  };

  // Reenvíos disponibles para el modal de grupos (inyectados por el blade;
  // gated por addon.forwards + perm forwards.view en el servidor).
  var FWD_CFG = (function () {
    try {
      return JSON.parse(document.getElementById("tp-live-forwards").textContent);
    } catch (e) {
      return { can: false, items: [] };
    }
  })();

  var MAP_CFG = (function () {
    try {
      return JSON.parse(
        (document.getElementById("tp-live-map-config") || {}).textContent ||
          "{}",
      );
    } catch (e) {
      return {};
    }
  })();
  var KEYS = MAP_CFG.keys || {};

  // -----------------------------------------------------------
  // Capas base (Leaflet). OSM + Google (tiles ≠) + proveedores con key.
  // -----------------------------------------------------------
  // Capas base: catálogo completo (todos los proveedores) vía tp-basemaps.js,
  // construido desde las capas disponibles del usuario (MAP_CFG.available).
  function buildBaseLayers() {
    if (window.tpBasemaps && window.tpBasemaps.build) {
      var bm = window.tpBasemaps.build(MAP_CFG);
      if (bm && Object.keys(bm).length) return bm;
    }
    // Respaldo mínimo si no cargó el módulo.
    return {
      "2": {
        name: "OpenStreetMap",
        layer: L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
          maxZoom: 19,
          attribution: "&copy; OpenStreetMap",
        }),
      },
    };
  }

  var state = {
    map: null,
    baseLayers: {},
    currentBase: null,
    devices: [],
    total: 0,
    index: {},
    markers: {}, // id → L.marker
    deviceLayer: null, // L.layerGroup
    filtered: [], // lista (search + estado + filtro avanzado si sidebar)
    mapFiltered: [], // marcadores (search + estado + filtro avanzado si map)
    // Filtro avanzado (addon.object_filter): ids resueltos por backend + toggles.
    advFilter: { ids: null, sidebar: true, map: true, statuses: [], online: null, offline: null },
    visual: [],
    groups: [],
    grouped: true,
    collapsedGroups: {},
    statusFilter: "all",
    searchQuery: "",
    selectedId: null,
    checkTime: null,
    pollHandle: null,
    bootstrapped: false,
    popup: null,
    popupId: null,
    toggles: {},
    autoFit: false,
    geoMarker: null,
    trafficLayer: null,
    clusterGroup: null,
    routesLayer: null,
    tailLayer: null,
  };
  try {
    var sg = localStorage.getItem("tp_live_grouped");
    if (sg !== null) state.grouped = sg === "1";
  } catch (e) {}

  function esc(s) {
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

  // -----------------------------------------------------------
  // Estado operativo del device (autoritativo: d.online)
  // -----------------------------------------------------------
  // Los mismos cubos que el legacy en Device::getStatusColor(): el naranja
  // es SOLO para el motor encendido sin moverse. Un vehiculo parado con el
  // motor apagado (ack) es "detenido" y va en gris; juntarlos hacia que la
  // v2 dijera ralenti de cualquier vehiculo quieto.
  /**
   * Paso a «sin conexion» en el navegador. Port de Device.checkOffline del
   * legacy (model/device.js): el sondeo solo trae los equipos que cambiaron,
   * asi que uno que deja de transmitir no vuelve a llegar y hay que vencerlo
   * aqui con el mismo tiempo limite.
   * `now` = hora del servidor del ultimo sondeo (segundos).
   */
  function checkOffline(d, now, timeout) {
    if (!timeout || !d || d.online === "offline" || d.online === "expired") return false;
    var online = d.online,
      diff = now - (parseInt(d.timestamp, 10) || 0),
      ackDiff = now - (parseInt(d.acktimestamp, 10) || 0);
    if (diff >= timeout && ackDiff < timeout) online = "ack";
    if (diff >= timeout && ackDiff >= timeout) online = "offline";
    if (online === d.online) return false;
    d.online = online;
    d.speed = 0;
    // Legacy: al pasar a «sin conexion» pone en falso los sensores de
    // encendido (acc, ignition, engine). Aqui viven en tres sitios: el estado
    // del motor del feed, los testigos de la ficha y los sensores del popup.
    if (online === "offline") {
      d.engine_status = false;
      var dash = typeof dashData !== "undefined" && dashData[d.id];
      if (dash && dash.telltales) {
        dash.telltales.engine = false;
        dash.telltales.ignition = false;
      }
      var fc = fullCache[d.id] && fullCache[d.id].data;
      if (fc && fc.sensors)
        fc.sensors.forEach(function (sn) {
          if (sn.type === "acc" || sn.type === "ignition" || sn.type === "engine") sn.val = false;
        });
    }
    if (d.speed_text) d.speed_text = String(d.speed_text).replace(/^[\d.,\s]+/, "0 ");
    return true;
  }
  function checkOfflineAll(now) {
    var changed = false;
    state.devices.forEach(function (d) {
      if (checkOffline(d, now, CFG.offlineTimeout)) changed = true;
    });
    return changed;
  }

  /**
   * Tiempo detenido que sigue corriendo entre sondeos. Port de
   * Device.calcStopDuration del legacy: suma lo transcurrido desde que llego
   * el dato (`_recibido`). Devuelve segundos, o un texto ya hecho.
   */
  function stopDuration(d) {
    if (!d) return null;
    if (d.online === "online") return 0;
    if (!parseInt(d.timestamp, 10)) return "-";
    if (!parseInt(d.moved_timestamp, 10)) return "12+h";
    var offset = d._recibido ? Math.floor((Date.now() - d._recibido) / 1000) : 0;
    var stop = (parseInt(d.stop_duration_sec, 10) || 0) + offset;
    return stop < 60 ? 0 : stop;
  }
  function stopDurationText(d) {
    var v = stopDuration(d);
    if (typeof v !== "number") return v;
    // Mas de un dia detenido: en dias y horas ("52d 1h"). Con horas a secas
    // salia "1249h 57min 51s", que no cabe en la tarjeta ni se lee de un
    // vistazo. Por debajo de un dia, el formato de la cuenta.
    if (v >= 86400) {
      var dias = Math.floor(v / 86400),
        horas = Math.floor((v % 86400) / 3600);
      return dias + "d" + (horas ? " " + horas + tpT("front.hour_short") : "");
    }
    return fmtDuration(v);
  }

  function classify(d) {
    switch (d && d.online) {
      case "online":
      case "excessive_speed":
      case "move":
        return "moving";
      case "engine":
      case "idle":
        return "idle";
      case "ack":
      case "stop":
      case "park":
      // El legacy le da color propio (negro). Aqui no hay cubo para eso, y
      // un vehiculo con el motor bloqueado esta parado: va con los detenidos.
      case "blocked":
        return "stopped";
      default:
        return "offline";
    }
  }
  // getStatusColor() del legacy devuelve el color por NOMBRE, de un catalogo
  // de nueve. Se traduce al token del tema para que claro y oscuro usen el
  // tono que toca; si alguien guardo un hex propio, se respeta tal cual.
  var SC_NOMBRES = {
    green: 1, yellow: 1, orange: 1, red: 1,
    blue: 1, cyan: 1, gray: 1, black: 1, white: 1,
  };
  // variante: "ink" = el tono oscurecido, para texto suelto. "on" = el color
  // del texto que va encima del relleno. Sin variante, el relleno. Un hex
  // propio no se puede derivar aqui: se devuelve igual (y de texto encima,
  // blanco, que es lo que habia antes).
  function statusVar(c, variante) {
    if (!c) return "";
    c = String(c).toLowerCase();
    if (SC_NOMBRES[c]) {
      if (variante === "on") return "var(--sc-" + c + "-on)";
      return "var(--sc-" + c + (variante ? "-ink" : "") + ")";
    }
    if (c.charAt(0) !== "#") return "";
    return variante === "on" ? "#fff" : c;
  }

  function fmtTimeAgo(ts) {
    if (!ts) return "—";
    var sec = parseInt(ts, 10);
    if (sec > 1e12) sec = Math.floor(sec / 1000);
    var diff = Math.floor(Date.now() / 1000) - sec;
    if (diff < 0) diff = 0;
    if (diff < 60) return diff + "s";
    if (diff < 3600) return Math.floor(diff / 60) + "m";
    if (diff < 86400) return Math.floor(diff / 3600) + "h";
    return Math.floor(diff / 86400) + "d";
  }

  // -----------------------------------------------------------
  // Mapa Leaflet + capas base
  // -----------------------------------------------------------
  function initMap() {
    state.map = L.map("tp-live-map", {
      center: [-12.0464, -77.0428],
      zoom: 5,
      zoomControl: false,
      attributionControl: true,
    });
    state.baseLayers = buildBaseLayers();
    var firstId =
      window.tpBasemaps && window.tpBasemaps.defaultId
        ? window.tpBasemaps.defaultId(state.baseLayers, MAP_CFG)
        : Object.keys(state.baseLayers)[0];
    if (firstId) setBaseLayer(firstId);
    state.deviceLayer = L.layerGroup().addTo(state.map);
    // El mapa cambia de tamaño sin que la ventana cambie (al abrir la ficha en
    // pantalla ancha, plegar la lista…). Leaflet guarda el tamaño y, sin
    // avisarle, centraba con el ancho viejo: el vehiculo seguido quedaba
    // ~190 px corrido. Se le avisa cada vez que el contenedor cambia.
    if (typeof ResizeObserver === "function") {
      new ResizeObserver(function () {
        if (!state.map) return;
        state.map.invalidateSize({ pan: false });
        // La ficha se abre con transicion y el mapa termina de encogerse
        // DESPUES de centrar: se vuelve a centrar el vehiculo seguido.
        // Mientras dura el acercamiento inicial se pide el mismo zoom (getZoom
        // aun da el de antes y lo dejaria a medio camino).
        if (state.followSelected && state.selectedId != null) centerOnSelected(state.centerZoom || undefined);
      }).observe(state.map.getContainer());
    }
    // Arrastrar el mapa deja de seguir al vehiculo (legacy: dragstart →
    // disableFitBounds). Volver a elegirlo reanuda el seguimiento.
    state.map.on("dragstart", function () {
      state.followSelected = false;
      setAutoFit(false);
    });
    // Preferencias de marcador (Ajustes de cuenta → Mapa)
    var mt = MAP_CFG.toggles || {};
    state.map.getContainer().classList.toggle("tp-show-speed", !!mt.showSpeed);
    state.map.getContainer().classList.toggle("tp-status-color", !!mt.statusColor);
    buildBaseMenu();
  }
  function setBaseLayer(id) {
    var entry = state.baseLayers[id];
    if (!entry) return;
    if (state.currentBase && state.baseLayers[state.currentBase])
      state.map.removeLayer(state.baseLayers[state.currentBase].layer);
    entry.layer.addTo(state.map);
    if (entry.layer.bringToBack) entry.layer.bringToBack();
    state.currentBase = id;
    var menu = document.getElementById("tp-live-providers-menu");
    if (menu)
      menu.querySelectorAll("[data-map-id]").forEach(function (b) {
        b.setAttribute(
          "aria-selected",
          b.getAttribute("data-map-id") === id ? "true" : "false",
        );
      });
  }
  function buildBaseMenu() {
    var menu = document.getElementById("tp-live-providers-menu");
    if (!menu) return;
    menu.innerHTML = Object.keys(state.baseLayers)
      .map(function (id) {
        return (
          '<button type="button" class="tp-menu__item" data-map-id="' +
          id +
          '">' +
          esc(state.baseLayers[id].name) +
          "</button>"
        );
      })
      .join("");
    menu.addEventListener("click", function (e) {
      var b = e.target.closest("[data-map-id]");
      if (!b) return;
      var id = b.getAttribute("data-map-id");
      setBaseLayer(id);
      persistMapId(id);
      menu.hidden = true;
    });
  }
  // Persiste el tipo de mapa en user->map_id (mismo endpoint que Eventos).
  function persistMapId(id) {
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
    } catch (e) {}
  }

  // -----------------------------------------------------------
  // Marcador de device (ícono real, como el legacy device.js)
  // -----------------------------------------------------------
  function assetPath(p) {
    if (!p) return "";
    if (/^https?:\/\//.test(p) || p.charAt(0) === "/") return p;
    return "/" + p;
  }
  /* ============================================================
     Color del marcador segun la velocidad
     ------------------------------------------------------------
     Se activa por unidad (modal del vehiculo -> Cola). Con la unidad dentro
     de una geocerca CON limite mandan los dos colores de geocerca; fuera, o
     si la geocerca no define limite, mandan los rangos de velocidad.

     Precedencia:
       1. en geocerca y por encima del limite   -> color "excedido"
       2. en geocerca y a <= margen del limite  -> color "proximo"
       3. resto                                 -> rango de velocidad
     ============================================================ */

  function markerDefaults() {
    var d = (MAP_CFG && MAP_CFG.markerColors) || {};
    return {
      ranges: d.ranges || [],
      geofence: d.geofence || {},
    };
  }

  function colorDeRango(rangos, v) {
    if (!rangos.length) return null;

    var minimo = Infinity;
    for (var i = 0; i < rangos.length; i++) {
      var r = rangos[i];
      var desde = r.from == null ? -Infinity : +r.from;
      var hasta = r.to == null ? Infinity : +r.to;
      if (desde < minimo) minimo = desde;
      // Tramo abierto por arriba: [desde, hasta)
      if (v >= desde && v < hasta) return r.color || null;
    }

    // Por DEBAJO del primer tramo no hay color: los tramos empiezan en 1 y el
    // 0 kph queda fuera a proposito, para no pintar de verde a un parado.
    if (v < minimo) return null;

    // Por encima del ultimo tramo cerrado, el ultimo color definido.
    return rangos[rangos.length - 1].color || null;
  }

  function markerColor(d) {
    var cfg = d.marker_colors;
    if (!cfg) return null;

    // Solo con el vehiculo EN MOVIMIENTO. El primer tramo (0-40) es verde,
    // asi que uno parado salia del mismo verde que uno circulando despacio;
    // y si ademas no transmite, ese 0 kph ni siquiera es una medida, es el
    // ultimo dato conocido. Parado o en ralenti manda el color del estado.
    if (classify(d) !== "moving") return null;

    // d.speed sale del accessor de Device, que es Formatter::speed()->format()
    // sobre getSpeed(): ya viene en la unidad del usuario y ya aplica el
    // sensor de velocidad ECM cuando el vehiculo lo tiene configurado. El
    // limite de la geocerca llega en esa misma unidad, asi que se comparan
    // entre si sin convertir nada.
    var v = parseFloat(d.speed);
    if (isNaN(v)) return null;

    var def = markerDefaults();

    var gf = window.tpLiveZones && tpLiveZones.limitFor
      ? tpLiveZones.limitFor(d.lat, d.lng, d.vehicle_type_id, d.vehicle_state_id)
      : null;

    if (gf && gf.limit) {
      var margen = cfg.margin != null ? +cfg.margin : (+def.geofence.margin || 0);

      if (v >= gf.limit) {
        return cfg.over || def.geofence.over || null;
      }
      if (margen > 0 && v >= gf.limit - margen) {
        return cfg.near || def.geofence.near || null;
      }
    }

    return colorDeRango(cfg.ranges || def.ranges, v);
  }

  function deviceIcon(d) {
    var icon = d.icon || {};
    var type = icon.type || "arrow";
    var src,
      w,
      h,
      rotate = false,
      anchorBottom = false;
    if (type === "arrow") {
      src = "/images/device_icons/estados/" + (d.icon_color || "gray") + ".png";
      // Los PNG de estados/ son cuadrados de 50x50. Pintarlos a 25x33 -las
      // medidas que la tabla guarda para la flecha- los dejaba a la mitad y
      // estirados a lo alto.
      w = 50;
      h = 50;
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
      anchorBottom = true;
    }
    // Tamaño real como el legacy (device.js updateLayer): arrow 25×33,
    // rotating/icon usan icon.width/height SIN escalar.
    var rot = rotate ? "transform:rotate(" + (parseFloat(d.course) || 0) + "deg);" : "";
    var selected = state.selectedId === d.id;
    // Preferencias de marcador (Ajustes de cuenta → Mapa): la etiqueta lleva
    // clase de estado (color condicionado por .tp-status-color en el contenedor)
    // y un badge de velocidad (visible con .tp-show-speed).
    var grp = classify(d);
    var spd = d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h");

    // Color por velocidad: tiñe la etiqueta y pone un halo detras del icono.
    // El halo va como hermano del <img> y no lo tapa (z-index en el CSS).
    var col = markerColor(d);
    var estiloNombre = col
      ? ' style="background:' + col + ';border-color:' + col + ';color:#fff;"'
      : "";
    // El disco se dimensiona con el icono: van de 25x33 (flecha) a 48x48, y
    // con una medida fija quedaba desproporcionado en los extremos. La
    // proporcion es la del seleccionado (38 px para un icono de ~32).
    var haloPx = Math.round(Math.max(w, h) * 1.15) + 6;
    var haloMid = -Math.round(haloPx / 2);
    var halo = col
      ? '<span class="tp-lmarker__halo" style="background:' + col +
        ";width:" + haloPx + "px;height:" + haloPx + "px;margin:" + haloMid + "px 0 0 " +
        haloMid + 'px;"></span>'
      : "";
    // El color por velocidad es del VEHICULO; el de estado es un ajuste de la
    // CUENTA para todos. Manda el del vehiculo: si lo tiene, no se pone la
    // clase de estado. Antes se ponian las dos y ganaba el color en linea
    // solo por la cascada del CSS.
    var claseNombre = "tp-lmarker__name" + (col ? "" : " tp-lmarker__name--" + grp);
    // Con "colorear etiqueta segun el estado" activo, el tono sale del color
    // del vehiculo (.tp-status-color lo recoge en el CSS).
    // La etiqueta usa el relleno -el color tal cual del legacy- y el color de
    // texto que le toca por contraste: blanco sobre azul, oscuro sobre
    // amarillo. No se puede fijar uno solo: texto blanco sobre amarillo da
    // 1.07:1 y texto oscuro sobre azul, 1.71:1.
    var cEtq = col ? "" : statusVar(d.icon_color);
    var cEtqOn = col ? "" : statusVar(d.icon_color, "on");
    if (cEtq) {
      estiloNombre = ' style="--tp-status:' + cEtq + ";--tp-status-on:" + cEtqOn + ';"';
    }

    var html =
      '<span class="' + claseNombre + '"' + estiloNombre + ">" +
      // Nombre y estado van en su propio span: el toggle "nombres" los oculta
      // sin tirar la etiqueta entera, que puede seguir mostrando la velocidad.
      '<span class="tp-lmarker__title">' +
      esc(d.name || "") +
      vehicleStateBadge(d.vehicle_state, "vertical-align:middle;margin-left:4px;") +
      "</span>" +
      '<span class="tp-lmarker__speed">' + esc(spd) + "</span>" +
      "</span>" +
      halo +
      '<img src="' +
      src +
      '" width="' +
      w +
      '" height="' +
      h +
      '" style="display:block;' +
      // El drop-shadow del resplandor lee este --tp-halo (ver live-ops.css).
      (col ? "--tp-halo:" + col + ";" : "") +
      rot +
      '" draggable="false">';
    return L.divIcon({
      html: html,
      className: "tp-lmarker" + (selected ? " tp-lmarker--selected" : "") +
        (col ? " tp-lmarker--colored" : ""),
      iconSize: [w, h],
      iconAnchor: anchorBottom ? [w / 2, h] : [w / 2, h / 2],
    });
  }

  // Circulo de imprecision del GPS (addon.inaccuracy): el servidor solo manda
  // `inaccuracy` (metros) con el addon activo. Mismo estilo que el legacy.
  var inaccuracies = {}; // id → L.circle
  function syncInaccuracy(d) {
    var r = parseInt(d.inaccuracy, 10) || 0;
    var m = state.markers[d.id];
    var visible = r > 0 && m && state.toggles.devices !== false && d.active !== false;
    var c = inaccuracies[d.id];
    if (!visible) {
      if (c) {
        c.remove();
        delete inaccuracies[d.id];
      }
      return;
    }
    if (!c) {
      inaccuracies[d.id] = L.circle(m.getLatLng(), {
        radius: r,
        color: "#98c4ec",
        weight: 3,
        opacity: 1,
        fill: true,
        fillOpacity: 0.3,
        fillColor: "#98c4ec",
        interactive: false,
      }).addTo(state.map);
    } else {
      c.setRadius(r);
      c.setLatLng(m.getLatLng());
    }
  }

  function renderMarkers() {
    if (!state.map) return;
    var seen = {};
    state.mapFiltered.forEach(function (d) {
      if (d.lat == null || d.lng == null) return;
      if (d.active === false) return;
      seen[d.id] = true;
      var ll = [parseFloat(d.lat), parseFloat(d.lng)];
      var cont = markerContainer();
      var m = state.markers[d.id];
      if (m) {
        // Primero el icono -rumbo y color- y luego la posicion, como el
        // legacy: setIcon rehace el elemento y cortaria el deslizamiento.
        m.setIcon(deviceIcon(d));
        moverMarcador(m, ll);
      } else {
        m = L.marker(ll, { icon: deviceIcon(d) });
        m.options.deviceId = d.id;
        // La cola sigue al marcador mientras se desliza (legacy moveTail).
        m.on("move", function () {
          moveTail(d.id);
          var c = inaccuracies[d.id];
          if (c) c.setLatLng(m.getLatLng());
        });
        m.on("click", function () {
          openDevicePopup(d.id);
          // Legacy (onLayerClick): ademas del popup, el bottombar pasa a este
          // equipo aunque no se seleccione.
          if (state.selectedId == null) loadBottomWidgets(d.id);
        });
        cont.addLayer(m);
        state.markers[d.id] = m;
      }
    });
    var cont2 = markerContainer();
    Object.keys(state.markers).forEach(function (id) {
      if (!seen[id]) {
        cont2.removeLayer(state.markers[id]);
        delete state.markers[id];
      }
    });
    Object.keys(inaccuracies).forEach(function (id) {
      if (!seen[id]) {
        inaccuracies[id].remove();
        delete inaccuracies[id];
      }
    });
    state.mapFiltered.forEach(function (d) {
      if (seen[d.id]) syncInaccuracy(d);
    });
    renderTails();
  }

  // Contenedor activo de marcadores: cluster o layerGroup normal.
  /**
   * Mueve el marcador a su nueva posicion, deslizandolo si el plugin
   * "Animacion de movimiento del dispositivo" esta activo.
   *
   * Las dos condiciones son las del legacy (model/device.js): el plugin
   * encendido y el marcador colgando del mapa. Con los objetos agrupados el
   * marcador vive dentro del cluster, y deslizarlo ahi pelea con el
   * reagrupado, asi que en ese caso salta, igual que en el legacy.
   */
  function moverMarcador(m, ll) {
    var anima =
      MAP_CFG.animateMove &&
      typeof m.slideTo === "function" &&
      state.map &&
      state.map.hasLayer(m);

    if (!anima) {
      m.setLatLng(ll);
      return;
    }

    var destino = L.latLng(ll[0], ll[1]);

    if (m.getLatLng().equals(destino)) return;

    // El mismo respiro de 150 ms del legacy: deja que el icono recien puesto
    // se asiente antes de empezar a mover.
    setTimeout(function () {
      if (!state.map || !state.map.hasLayer(m)) return;
      try {
        m.slideTo(destino, { duration: CFG.pollInterval });
      } catch (e) {
        m.setLatLng(destino);
      }
    }, 150);
  }

  function markerContainer() {
    return state.toggles.cluster && state.clusterGroup ? state.clusterGroup : state.deviceLayer;
  }
  function ensureCluster() {
    if (!window.L.markerClusterGroup) return null;
    if (!state.clusterGroup)
      state.clusterGroup = L.markerClusterGroup({
        chunkedLoading: true,
        maxClusterRadius: 50,
        showCoverageOnHover: false,
        // Legacy: sin "araña"; en el zoom maximo se abre la lista de equipos.
        spiderfyOnMaxZoom: false,
        iconCreateFunction: function (cluster) {
          var n = cluster.getChildCount();
          var tier = n < 10 ? "sm" : n < 100 ? "md" : "lg";
          var size = n < 10 ? 36 : n < 100 ? 44 : 52;
          return L.divIcon({
            html: '<div class="tp-live-cluster tp-live-cluster--' + tier + '">' + n + "</div>",
            className: "tp-live-cluster-wrap",
            iconSize: [size, size],
          });
        },
      });
    if (!state.clusterWired) {
      state.clusterWired = true;
      // Legacy (devices.js clusterclick): pulsar un grupo deja de seguir y, en
      // el zoom maximo, abre la lista de sus equipos.
      state.clusterGroup.on("clusterclick", function (e) {
        state.followSelected = false;
        setAutoFit(false);
        if (state.map.getZoom() >= state.map.getMaxZoom()) clusterPopup(e.layer);
      });
    }
    return state.clusterGroup;
  }
  /** Lista de equipos de un cluster (legacy clusterPopup): nombre, velocidad y estado. */
  function clusterPopup(cluster) {
    var html = '<ul class="tp-cluster-list">';
    cluster.getAllChildMarkers().forEach(function (mk) {
      var d = state.index[mk.options.deviceId];
      if (!d) return;
      html +=
        '<li><button type="button" data-cl-dev="' + d.id + '">' +
        '<span class="tp-cluster-list__name">' + esc(d.name || "#" + d.id) + "</span>" +
        '<span class="tp-cluster-list__meta">' +
        esc(d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h")) + " · " +
        esc(STATUS_LABELS[d.online] || d.online || "") + "</span></button></li>";
    });
    html += "</ul>";
    var pop = L.popup({ maxWidth: 280, className: "tp-dev-popup-wrap" })
      .setLatLng(cluster.getLatLng())
      .setContent(html)
      .openOn(state.map);
    var el = pop.getElement();
    if (el)
      el.addEventListener("click", function (ev) {
        var b = ev.target.closest("[data-cl-dev]");
        if (b) openDevicePopup(parseInt(b.getAttribute("data-cl-dev"), 10));
      });
  }
  function setClusterMode(on) {
    var m = state.map;
    // sacar todos los marcadores de ambos contenedores
    Object.keys(state.markers).forEach(function (id) {
      try {
        state.deviceLayer.removeLayer(state.markers[id]);
      } catch (e) {}
      if (state.clusterGroup) {
        try {
          state.clusterGroup.removeLayer(state.markers[id]);
        } catch (e) {}
      }
    });
    state.markers = {};
    if (on) {
      var cg = ensureCluster();
      if (!cg) {
        state.toggles.cluster = false;
        window.tpModal && tpModal.toast && tpModal.toast(tpT('ui.clustering_unavailable'), "warning");
        return;
      }
      m.removeLayer(state.deviceLayer);
      cg.addTo(m);
    } else {
      if (state.clusterGroup) m.removeLayer(state.clusterGroup);
      state.deviceLayer.addTo(m);
    }
    renderMarkers();
  }

  /** Enciende o apaga «Auto-seguir flota» y su boton. */
  function setAutoFit(on) {
    state.autoFit = !!on;
    var af = document.getElementById("tp-live-autofollow");
    if (af) af.setAttribute("aria-pressed", state.autoFit ? "true" : "false");
  }

  function fitToFleet() {
    var pts = state.mapFiltered
      .filter(function (d) {
        return d.lat != null && d.lng != null && d.active !== false;
      })
      .map(function (d) {
        return [parseFloat(d.lat), parseFloat(d.lng)];
      });
    if (pts.length) state.map.fitBounds(L.latLngBounds(pts), { padding: [50, 50], maxZoom: 16 });
  }

  // Aplica un toggle de capa del control flotante.
  // Mapeo toggle UI → clave de map_controls (igual que el legacy app.js saveSetting)
  var TOGGLE_PARAM = {
    devices: "m_objects",
    cluster: "m_objects_cluster",
    geofences: "m_geofences",
    routes: "m_routes",
    poi: "m_poi",
    names: "m_show_names",
    tails: "m_show_tails",
    traffic: "m_traffic",
    hitos: "m_hitos",
    aircraft: "m_aircraft",
  };
  // Persiste el toggle en user->map_controls (estado compartido con la UI legacy).
  function persistToggle(key, val) {
    var param = TOGGLE_PARAM[key];
    var url = MAP_CFG.urls && MAP_CFG.urls.changeMapSettings;
    if (!param || !url) return;
    var q = url + (url.indexOf("?") === -1 ? "?" : "&") + "param=" + encodeURIComponent(param) + "&value=" + (val ? "true" : "false");
    fetch(q, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } }).catch(function () {});
  }

  function applyToggle(key) {
    var on = !!state.toggles[key];
    var m = state.map;
    if (key === "devices") {
      var cont = markerContainer();
      if (on) cont.addTo(m);
      else m.removeLayer(cont);
      // Legacy (addTail): sin objetos no se pintan colas.
      renderTails();
    } else if (key === "cluster") {
      setClusterMode(on);
    } else if (key === "geofences") {
      if (window.tpLiveZones && tpLiveZones.setVisible) tpLiveZones.setVisible(on);
    } else if (key === "poi") {
      if (window.tpLivePois && tpLivePois.setVisible) tpLivePois.setVisible(on);
    } else if (key === "routes") {
      // El panel de Rutas (live-routes.js) es dueño de la capa (clic → popup,
      // CRUD). Delegamos como con geocercas/POIs; si aún no cargó, fallback
      // de solo lectura.
      if (window.tpLiveRoutes && tpLiveRoutes.setVisible) tpLiveRoutes.setVisible(on);
      else setRoutesVisible(on);
    } else if (key === "traffic") {
      if (on) {
        if (!state.trafficLayer)
          state.trafficLayer = L.tileLayer(
            "https://mt{s}.google.com/vt/lyrs=h,traffic&x={x}&y={y}&z={z}",
            { subdomains: ["0", "1", "2", "3"], opacity: 0.7 },
          );
        state.trafficLayer.addTo(m);
      } else if (state.trafficLayer) {
        m.removeLayer(state.trafficLayer);
      }
    } else if (key === "names") {
      m.getContainer().classList.toggle("tp-show-names", on);
    } else if (key === "tails") {
      renderTails();
    } else if (key === "aircraft") {
      aircraftSetVisible(on);
    } else if (key === "hitos") {
      // Hitos kilométricos (capa compartida tp-hitos.js)
      if (window.tpHitos) {
        if (!state.hitosCtrl) state.hitosCtrl = tpHitos.create(m);
        state.hitosCtrl.setVisible(on);
      }
    }
  }

  // Aviones en vivo. Port de model/aircraft.js del legacy: cada 5 s pide a
  // /flights los aviones en 250 millas alrededor del centro del mapa (el
  // servidor los saca de opendata.adsb.fi y los guarda 60 s) y pinta los que
  // caen dentro de la vista, girados segun su rumbo. Con zoom 6 o menos no
  // pide nada: el radio del servicio no cubre la vista.
  var aircraft = { layer: null, timer: null };
  function aircraftFetch() {
    var m = state.map;
    if (!m || !aircraft.layer) return;
    if (m.getZoom() <= 6) {
      aircraft.layer.clearLayers();
      return;
    }
    var c = m.getCenter();
    fetch("/flights?lat=" + c.lat.toFixed(5) + "&lon=" + c.lng.toFixed(5), {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!aircraft.timer) return;
        aircraft.layer.clearLayers();
        var bounds = m.getBounds();
        ((data && data.aircraft) || []).forEach(function (ac) {
          if (!ac.lat || !ac.lon || !bounds.contains([ac.lat, ac.lon])) return;
          var icon = L.divIcon({
            className: "tp-aircraft",
            html: '<div style="transform:rotate(' + (ac.track || 0) + 'deg)">✈</div>',
            iconSize: [20, 20],
            iconAnchor: [10, 10],
          });
          L.marker([ac.lat, ac.lon], { icon: icon })
            .bindPopup(
              '<dl class="tp-dev-popup__defs" style="margin:0">' +
                "<dt>Vuelo</dt><dd>" + esc((ac.flight || "").trim() || "N/A") + "</dd>" +
                "<dt>Aeronave</dt><dd>" + esc(ac.desc || ac.t || "N/A") + "</dd>" +
                (ac.alt_baro != null ? "<dt>Altitud</dt><dd>" + esc(ac.alt_baro) + " ft</dd>" : "") +
                "</dl>",
              { className: "tp-dev-popup-wrap" },
            )
            .addTo(aircraft.layer);
        });
      })
      .catch(function () {});
  }
  function aircraftSetVisible(on) {
    if (!state.map) return;
    if (!aircraft.layer) aircraft.layer = L.layerGroup().addTo(state.map);
    if (on) {
      if (aircraft.timer) return;
      aircraft.timer = setInterval(aircraftFetch, 5000);
      aircraftFetch();
    } else {
      clearInterval(aircraft.timer);
      aircraft.timer = null;
      aircraft.layer.clearLayers();
    }
  }

  // Cola de recorrido (tail): polilínea de las últimas posiciones de cada device.
  // Port de getTailPoints/addTail/moveTail del legacy (model/device.js): el
  // ultimo punto es la posicion ACTUAL del marcador, y la cola se mueve con el
  // en cada paso del deslizamiento, para que no quede despegada del icono.
  var tails = {}; // id → L.polyline
  function tailPoints(d) {
    var tail = d.tail;
    if (typeof tail === "string") {
      try {
        tail = JSON.parse(tail);
      } catch (e) {
        tail = null;
      }
    }
    if (!tail || !tail.length) return null;
    var pts = tail
      .map(function (p) {
        return [parseFloat(p.lat), parseFloat(p.lng)];
      })
      .filter(function (x) {
        return !isNaN(x[0]) && !isNaN(x[1]);
      });
    pts.pop();
    var m = state.markers[d.id];
    var ll = m ? m.getLatLng() : d.lat != null ? L.latLng(parseFloat(d.lat), parseFloat(d.lng)) : null;
    if (ll) pts.push([ll.lat, ll.lng]);
    return pts.length >= 2 ? pts : null;
  }
  function moveTail(id) {
    var t = tails[id],
      d = state.index[id];
    if (!t || !d) return;
    var pts = tailPoints(d);
    if (pts) t.setLatLngs(pts);
  }
  function renderTails() {
    var m = state.map;
    if (!state.tailLayer) state.tailLayer = L.layerGroup();
    // Legacy: sin "Objetos" o sin "Cola" no hay colas.
    if (!state.toggles.tails || state.toggles.devices === false) {
      if (m.hasLayer(state.tailLayer)) m.removeLayer(state.tailLayer);
      state.tailLayer.clearLayers();
      tails = {};
      return;
    }
    if (!m.hasLayer(state.tailLayer)) state.tailLayer.addTo(m);
    var vivos = {};
    state.mapFiltered.forEach(function (d) {
      if (d.active === false) return;
      var pts = tailPoints(d);
      if (!pts) return;
      vivos[d.id] = true;
      var color = d.tail_color || "#1e88e5";
      if (tails[d.id]) {
        tails[d.id].setLatLngs(pts);
        tails[d.id].setStyle({ color: color });
      } else {
        tails[d.id] = L.polyline(pts, { color: color, weight: 2, opacity: 0.8 }).addTo(state.tailLayer);
      }
    });
    Object.keys(tails).forEach(function (id) {
      if (!vivos[id]) {
        state.tailLayer.removeLayer(tails[id]);
        delete tails[id];
      }
    });
  }

  // Rutas: capa de polilíneas desde el endpoint de rutas (lectura).
  function setRoutesVisible(on) {
    var m = state.map;
    if (!on) {
      if (state.routesLayer) m.removeLayer(state.routesLayer);
      return;
    }
    if (state.routesLayer) {
      state.routesLayer.addTo(m);
      return;
    }
    fetch(CFG.routesUrl, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        state.routesLayer = L.layerGroup();
        ((j && j.items) || []).forEach(function (rt) {
          var pts = (rt.coordinates || []).map(function (c) {
            return [c[1], c[0]]; // [lng,lat] → [lat,lng]
          });
          if (pts.length < 2) return;
          L.polyline(pts, { color: rt.color || "#7c3aed", weight: 3, opacity: 0.85 })
            .bindTooltip(rt.name || tpT('front.route'))
            .addTo(state.routesLayer);
        });
        if (state.toggles.routes) state.routesLayer.addTo(m);
      })
      .catch(function () {});
  }

  // Modal de configuración de widgets (reusa el endpoint nativo v2).
  function openWidgetsConfig(configUrl, saveUrl) {
    if (!configUrl || !window.tpModal) return;
    tpModal.fetchJSON(configUrl, { method: "GET" }).then(function (res) {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        tpModal.toast && tpModal.toast(tpT('ui.config_load_failed'), "error");
        return;
      }
      var dlg = tpModal.open({
        title: tpT('widgets'),
        size: "lg",
        body: res.data.html,
        buttons: [
          { label: tpT('global.cancel'), variant: "ghost" },
          {
            label: tpT('global.save'),
            variant: "primary",
            close: false,
            onClick: function (dialog, btn) {
              var form = dialog.querySelector("#tp-widgets-config-form");
              if (!form) return false;
              var checked = [];
              form.querySelectorAll('input[name="widgets[]"]:checked').forEach(function (i) {
                checked.push(i.value);
              });
              var payload = {
                default: form.querySelector('[name="default"]').checked ? 1 : 0,
                status: form.querySelector('[name="status"]').checked ? 1 : 0,
                list: checked,
              };
              btn.disabled = true;
              tpModal
                .fetchJSON(saveUrl, { method: "POST", json: payload })
                .then(function (r) {
                  btn.disabled = false;
                  if (r && r.ok && r.data && r.data.ok) {
                    tpModal.toast && tpModal.toast(tpT('widgets_updated'), "success");
                    tpModal.close(dialog);
                    if (state.selectedId) loadDrawerWidgets(state.selectedId);
                  } else tpModal.toast && tpModal.toast(tpT('ui.save_failed'), "error");
                })
                .catch(function () {
                  btn.disabled = false;
                  tpModal.toast && tpModal.toast(tpT('ui.network_error'), "error");
                });
              return false;
            },
          },
        ],
      });
      var def = dlg.querySelector('[name="default"]');
      var fields = dlg.querySelector("#tp-widgets-config-fields");
      function syncDef() {
        if (!fields) return;
        fields.style.opacity = def && def.checked ? "0.45" : "";
        fields.querySelectorAll("input").forEach(function (i) {
          i.disabled = def && def.checked;
        });
      }
      if (def) {
        def.addEventListener("change", syncDef);
        syncDef();
      }
    });
  }

  // -----------------------------------------------------------
  // Popup de device (conciso, con dirección perezosa)
  // -----------------------------------------------------------
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
  var addrCache = {};
  function fetchAddr(d, cb) {
    if (!CFG.geoUrl || d.lat == null) return cb("");
    var clave = d.id + "@" + d.lat + "," + d.lng;
    if (addrCache[clave] !== undefined) return cb(addrCache[clave]);
    fetch(
      CFG.geoUrl +
        (CFG.geoUrl.indexOf("?") === -1 ? "?" : "&") +
        "lat=" +
        d.lat +
        "&lng=" +
        d.lng,
      { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } },
    )
      .then(function (r) {
        return r.text();
      })
      .then(function (t) {
        var i = t.indexOf("<");
        if (i !== -1) t = t.slice(0, i);
        t = t.trim();
        try {
          var j = JSON.parse(t);
          t = j.address || j.name || t;
        } catch (e) {}
        addrCache[clave] = t;
        cb(t);
      })
      .catch(function () {
        cb("");
      });
  }
  // Datos completos del equipo para el popup (sensores, servicios, protocolo,
  // vencimiento): el mismo endpoint y transformer que usa el legacy al abrir
  // el popup o los widgets (objects/items?id=&full=1 → DeviceMapFullTransformer).
  var fullCache = {};
  function loadFull(id, cb) {
    var c = fullCache[id];
    if (c && Date.now() - c.t < 15000) return cb(c.data);
    if (!CFG.deviceFullUrl) return cb(c ? c.data : null);
    fetch(CFG.deviceFullUrl + "?id=" + encodeURIComponent(id) + "&full=1", {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var row = j && j.data && j.data.length ? j.data[0] : null;
        fullCache[id] = { t: Date.now(), data: row };
        cb(row);
      })
      .catch(function () { cb(c ? c.data : null); });
  }

  function popRow(dt, dd, attrs) {
    return "<dt>" + esc(dt) + "</dt><dd" + (attrs || "") + ">" + dd + "</dd>";
  }

  // Iconos del popup (trazo, heredan el color)
  var POP_IC = {
    follow: '<circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>',
    send_command: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
    edit: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
    device_media: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/>',
    device_alerts: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    video: '<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    street: '<circle cx="12" cy="5" r="2"/><path d="M10 22v-6l-2-1V9h8v6l-2 1v6"/>',
    details: '<path d="M4 6h16M4 12h16M4 18h10"/>',
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    ext: '<path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>',
    speed: '<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4-5"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
    sat: '<path d="M12 20a8 8 0 0 0-8-8"/><path d="M12 20a12 12 0 0 0-12-12"/><circle cx="19" cy="17" r="2"/>',
    alt: '<path d="m3 20 6-10 4 6 3-4 5 8z"/>',
  };
  function popIcon(k) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (POP_IC[k] || "") + "</svg>";
  }
  // Tipos logicos (ON/OFF) y los que en ON son alerta: mismo reparto que el
  // mosaico de Sensores de la ficha.
  var SENS_LOGICOS = { acc: 1, ignition: 1, engine: 1, blocked: 1, door: 1, seatbelt: 1, plugged: 1, anonymizer: 1,
    logical: 1, drive_business: 1, drive_private: 1, harsh_acceleration: 1, harsh_breaking: 1, harsh_turning: 1 };
  var SENS_ALERTA = { blocked: 1, harsh_acceleration: 1, harsh_breaking: 1, harsh_turning: 1 };

  /**
   * Popup del marcador. Organizacion de la referencia, en el estilo de la
   * ficha: cabecera con estado y botones (video, street view, detalles,
   * cerrar), fila de atajos con las MISMAS acciones y permisos del menu del
   * vehiculo, hora relativa, direccion con copiar y abrir en el mapa, tres
   * cifras (velocidad, detenido, satelites o altitud), sensores en dos
   * columnas con su valor coloreado, el bloque desplegable del legacy y los
   * atajos de historial. Datos del popup del legacy (objects/items?full=1).
   * `refresco` = repintado desde el sondeo (legacy updatePopup).
   */
  function openDevicePopup(id, refresco) {
    var d = state.index[id];
    if (!d || d.lat == null) return;
    var abierto = false;
    if (refresco && state.popup && state.popup.getElement()) {
      var det = state.popup.getElement().querySelector("details[data-pop-more]");
      abierto = !!(det && det.open);
    }
    var f = (fullCache[id] && fullCache[id].data) || {};
    var status = classify(d);
    var speed = d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h");
    var lat = parseFloat(d.lat),
      lng = parseFloat(d.lng);
    var coords = lat.toFixed(6) + ", " + lng.toFixed(6);
    var cEst = statusVar(d.icon_color);
    var acc = accionesDisponibles(id);
    var canales = d.camera_channels && d.camera_channels.length ? d.camera_channels : f.camera_channels;

    // Atajos: los del menu del vehiculo que la cuenta tiene, con su texto.
    var atajos = "";
    ["follow", "send_command", "edit", "share", "device_media", "device_alerts"].forEach(function (k) {
      if (!acc[k]) return;
      atajos += '<button type="button" class="tp-pop__act" data-pop-act="' + k + '" title="' + esc(acc[k]) + '" aria-label="' + esc(acc[k]) + '">' + popIcon(k) + "</button>";
    });

    // Satelites: del sensor si lo hay; si no, la altitud.
    var sat = null;
    (f.sensors || []).forEach(function (sn) {
      if (sn.type === "satellites" && sat == null) sat = sn.value;
    });

    var sensores = "";
    (f.sensors || []).forEach(function (sn) {
      if (sn.type === "satellites") return;
      var txt = sn.value == null || sn.value === "" ? "—" : String(sn.value);
      var clase = "tp-pop__sv";
      if (SENS_LOGICOS[sn.type]) {
        var on = sn.val === true || sn.val === 1 || sn.val === "1";
        // Sin conexion, encendido/motor/acc se ven apagados (legacy setOffline)
        if (status === "offline" && (sn.type === "acc" || sn.type === "ignition" || sn.type === "engine")) on = false;
        clase += on ? (SENS_ALERTA[sn.type] ? " is-alert" : " is-on") : " is-off";
      }
      sensores += '<div class="tp-pop__sensor"><span class="tp-pop__sn">' + esc(sn.name) + '</span><span class="' + clase + '">' + txt + "</span></div>";
    });
    var servicios = "";
    (f.services || []).forEach(function (sv) {
      servicios += popRow(sv.name, esc(sv.value));
    });

    var ts = parseInt(d.timestamp, 10) || 0;
    var hace = ts ? fmtTimeAgo(ts) : "";
    var street = "https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=" + lat + "," + lng;
    var gmaps = "https://www.google.com/maps/search/?api=1&query=" + lat + "," + lng;

    var html =
      '<div class="tp-pop" data-status="' + status + '">' +
      // Cabecera
      '<div class="tp-pop__head">' +
      '<span class="tp-pop__dot"' + (cEst ? ' style="background:' + cEst + '"' : "") + "></span>" +
      '<div class="tp-pop__title"><strong>' + esc(d.name || "#" + d.id) + "</strong>" +
      '<span style="' + (cEst ? "color:" + statusVar(d.icon_color, true) : "") + '">' + esc(STATUS_LABELS[d.online] || d.online || "—") + "</span></div>" +
      '<div class="tp-pop__tabs">' +
      (canales && canales.length && window.tpDeviceVideo
        ? '<button type="button" data-pop-video title="' + esc(tpT("front.video_live")) + '" aria-label="' + esc(tpT("front.video_live")) + '">' + popIcon("video") + "</button>"
        : "") +
      '<a href="' + street + '" target="_blank" rel="noopener" title="' + esc(tpT("front.street_view")) + '" aria-label="' + esc(tpT("front.street_view")) + '">' + popIcon("street") + "</a>" +
      '<button type="button" data-pop-details title="' + esc(tpT("ui.see_details")) + '" aria-label="' + esc(tpT("ui.see_details")) + '">' + popIcon("details") + "</button>" +
      '<button type="button" data-pop-close title="' + esc(tpT("global.close")) + '" aria-label="' + esc(tpT("global.close")) + '">' + popIcon("close") + "</button>" +
      "</div></div>" +
      // Atajos
      (atajos ? '<div class="tp-pop__acts">' + atajos + "</div>" : "") +
      // Hora
      '<div class="tp-pop__time">' + (hace ? '<b>' + esc(tpT("ui.time_ago").replace(":time", hace)) + "</b> · " : "") + esc(d.time || "—") + "</div>" +
      // Direccion
      '<div class="tp-pop__addr">' + popIcon("pin") +
      '<span data-pop-addr class="' + (CFG.fullAddress ? "tp-addr--full" : "tp-addr--clip") + '">' + tpT("ui.loading_ellipsis") + "</span>" +
      '<button type="button" class="tp-pop__mini" data-tp-copy="' + lat + "," + lng + '" title="' + esc(tpT("ui.copy_coordinates")) + '" aria-label="' + esc(tpT("ui.copy_coordinates")) + '">' + popIcon("copy") + "</button>" +
      '<a class="tp-pop__mini" href="' + gmaps + '" target="_blank" rel="noopener" title="' + esc(tpT("ui.open_in_map")) + '" aria-label="' + esc(tpT("ui.open_in_map")) + '">' + popIcon("ext") + "</a>" +
      "</div>" +
      // Cifras
      '<div class="tp-pop__stats">' +
      '<div>' + popIcon("speed") + "<b>" + esc(speed) + "</b></div>" +
      '<div title="' + esc(tpT("front.stop_duration")) + '">' + popIcon("stop") + "<b>" + esc(stopDurationText(d) || "—") + "</b></div>" +
      (sat != null
        ? '<div title="' + esc(tpT("satellites")) + '">' + popIcon("sat") + "<b>" + esc(sat) + "</b></div>"
        : '<div title="' + esc(tpT("front.altitude")) + '">' + popIcon("alt") + "<b>" + esc(d.altitude != null ? Math.round(d.altitude) + " m" : "—") + "</b></div>") +
      "</div>" +
      // Sensores
      (sensores ? '<div class="tp-pop__sec">' + esc(tpT("front.sensors")) + '</div><div class="tp-pop__sensors">' + sensores + "</div>" : "") +
      (f.expiration_date ? '<div class="tp-pop__exp">' + esc(tpT("front.expiration_date")) + ": <b>" + esc(f.expiration_date) + "</b></div>" : "") +
      // Desplegable (legacy #device-side-params)
      '<details class="tp-dev-popup__more" data-pop-more' + (abierto ? " open" : "") + "><summary>" + esc(tpT("front.show_more")) + "</summary>" +
      '<dl class="tp-dev-popup__defs">' +
      popRow(tpT("front.position"), '<span class="tp-text-mono">' + coords + "</span>") +
      popRow(tpT("front.altitude"), esc(d.altitude != null ? Math.round(d.altitude) + " m" : "—")) +
      popRow(tpT("front.angle"), esc(d.course != null ? Math.round(d.course) + "°" : "—")) +
      popRow(tpT("front.driver"), esc((f.driver && f.driver.name) || "—")) +
      popRow(tpT("front.model"), esc(d.device_model || f.device_model || "—")) +
      popRow(tpT("ui.plate"), esc(d.plate_number || "—")) +
      popRow(tpT("front.protocol"), esc(f.protocol || "—")) +
      servicios +
      "</dl></details>" +
      devShortcutsHtml() +
      "</div>";

    if (!state.popup) {
      state.popup = L.popup({ maxWidth: 340, minWidth: 300, className: "tp-dev-popup-wrap tp-pop-wrap", closeButton: false });
      state.popup.on("remove", function () {
        state.popupId = null;
      });
    }
    state.popupId = id;
    state.popup.setLatLng([lat, lng]).setContent(html);
    if (!state.popup.isOpen()) state.popup.openOn(state.map);
    else state.popup.update();
    var el = state.popup.getElement();
    fetchAddr(d, function (a) {
      if (!state.popup || !state.popup.isOpen() || state.popupId !== id) return;
      var slot = el && el.querySelector("[data-pop-addr]");
      if (slot) slot.textContent = a || "—";
    });
    if (el) {
      var q = function (sel, fn) {
        var n = el.querySelector(sel);
        if (n) n.addEventListener("click", fn);
      };
      q("[data-pop-details]", function () { selectDevice(id); });
      q("[data-pop-close]", function () { state.map.closePopup(state.popup); });
      q("[data-pop-video]", function () { window.tpDeviceVideo.open(id, { name: d.name }); });
      Array.prototype.forEach.call(el.querySelectorAll("[data-pop-act]"), function (b) {
        b.addEventListener("click", function () {
          runDeviceAction(b.getAttribute("data-pop-act"), id, b);
        });
      });
    }
    bindDevShortcuts(el, id);

    // Sensores y demas datos completos: llegan aparte y se repinta.
    if (!refresco || !fullCache[id] || Date.now() - fullCache[id].t >= 15000) {
      loadFull(id, function () {
        if (state.popupId === id && state.popup && state.popup.isOpen()) openDevicePopup(id, true);
      });
    }
  }

  /** Recoloca y repinta el popup abierto (legacy: updatePopup en cada update). */
  function refreshOpenPopup() {
    if (state.popupId == null || !state.popup || !state.popup.isOpen()) return;
    openDevicePopup(state.popupId, true);
  }

  /* ===========================================================
   * ATAJOS DEL VEHICULO — historial (ultima hora / hoy / ayer) y
   * «Reporte de rutas» (tipo 43 en HTML, pestaña nueva, solo este
   * device). Paridad del popup legacy (model/device.js) y del modal
   * Devices/route_report.blade; mismos permisos.
   * =========================================================== */
  var HIST_RANGES = [
    ["last_hour", "front.last_hour"],
    ["today", "front.today"],
    ["yesterday", "front.yesterday"],
  ];

  function goHistory(id, range) {
    location.href = "/ui/v2/history?device=" + id + "&range=" + (range || "today");
  }

  function devShortcutsHtml() {
    var h = "";
    if (CFG.canHistory) {
      h += '<div class="tp-dev-shortcuts" role="group">';
      HIST_RANGES.forEach(function (r) {
        h +=
          '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-hist-range="' + r[0] + '" title="' +
          esc(tpT("front.show_history") + " (" + tpT(r[1]) + ")") + '">' + esc(tpT(r[1])) + "</button>";
      });
      h += "</div>";
    }
    if (CFG.routeReportType)
      h +=
        '<button type="button" class="tp-btn tp-btn--ghost tp-btn--sm tp-full tp-dev-shortcuts__report" data-route-report>' +
        esc(tpT("front.device_routes_report")) + "</button>";
    return h;
  }

  function bindDevShortcuts(el, id) {
    if (!el) return;
    Array.prototype.forEach.call(el.querySelectorAll("[data-hist-range]"), function (b) {
      b.disabled = false;
      b.onclick = function () {
        goHistory(id, b.getAttribute("data-hist-range"));
      };
    });
    var rr = el.querySelector("[data-route-report]");
    if (rr)
      rr.onclick = function () {
        openRouteReport(id);
      };
  }

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function isoMasUnDia(iso) {
    var p = String(iso).split("-");
    var d = new Date(+p[0], +p[1] - 1, +p[2] + 1);
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  // Modal de fechas → GET a reports/update con generate=1&view=1&format=html
  // en una pestaña nueva (target _blank): la ruta pinta el reporte en linea.
  // La generacion web arma la consulta de devices desde selected_devices[]
  // (campo;estado;valor), no desde devices[]: van los dos, como el legacy.
  function openRouteReport(id) {
    if (!CFG.routeReportType || !window.tpModal) return;
    var d = state.index[id] || {};
    var now = new Date();
    var hoy = CFG.today || now.getFullYear() + "-" + pad2(now.getMonth() + 1) + "-" + pad2(now.getDate());
    var manana = isoMasUnDia(hoy);

    function horas() {
      var o = "";
      for (var i = 0; i < 96; i++) {
        var h = pad2(Math.floor(i / 4)) + ":" + pad2((i % 4) * 15);
        o += '<option value="' + h + '">' + h + "</option>";
      }
      return o;
    }
    function fechaHora(label, nDate, nTime, val) {
      return (
        '<label class="tp-form__field"><span class="tp-form__label">' + esc(label) + "</span>" +
        '<div style="display:flex;gap:6px;">' +
        '<input type="date" name="' + nDate + '" value="' + esc(val) + '" style="flex:1;min-width:0;">' +
        '<select class="tp-input" name="' + nTime + '" style="width:96px;flex:0 0 auto;">' + horas() + "</select>" +
        "</div></label>"
      );
    }

    var body =
      '<form class="tp-form" data-route-report-form onsubmit="return false">' +
      '<div class="tp-form__grid tp-form__grid--2">' +
      fechaHora(tpT("date_from"), "date_from", "from_time", hoy) +
      fechaHora(tpT("date_to"), "date_to", "to_time", manana) +
      "</div></form>";

    tpModal.open({
      title: tpT("front.device_routes_report") + (d.name ? " - " + d.name : ""),
      size: "sm",
      body: body,
      onMount: function (dlg) {
        if (window.tpDate && tpDate.init) tpDate.init(dlg);
      },
      buttons: [
        { label: tpT("global.cancel"), variant: "ghost" },
        {
          label: tpT("front.generate"),
          variant: "accent",
          onClick: function (dlg) {
            var src = dlg.querySelector("[data-route-report-form]");
            var fd = new FormData(src);
            var form = document.createElement("form");
            form.method = "GET";
            form.action = CFG.reportsUpdateUrl;
            form.target = "_blank";
            form.style.display = "none";
            var campos = [
              ["id", ""],
              ["type", CFG.routeReportType],
              ["format", "html"],
              ["devices[]", id],
              ["selected_devices[]", "id;true;" + id],
              ["generate", "1"],
              ["view", "1"],
              ["date_from", fd.get("date_from") || hoy],
              ["from_time", fd.get("from_time") || "00:00"],
              ["date_to", fd.get("date_to") || manana],
              ["to_time", fd.get("to_time") || "00:00"],
            ];
            campos.forEach(function (c) {
              var i = document.createElement("input");
              i.type = "hidden";
              i.name = c[0];
              i.value = c[1];
              form.appendChild(i);
            });
            document.body.appendChild(form);
            form.submit();
            setTimeout(function () {
              if (form.parentNode) form.parentNode.removeChild(form);
            }, 0);
          },
        },
      ],
    });
  }
  function refreshMarkerIcon(id) {
    var m = state.markers[id];
    var dd = state.index[id];
    if (m && dd) m.setIcon(deviceIcon(dd));
  }

  /* ===========================================================
   * SEGUIR — ventana flotante con mini-mapa por vehículo
   * (paridad legacy follow: panel arrastrable que trackea al
   * device en su propio mapa; se actualiza con cada refresco).
   * =========================================================== */
  var follows = {}; // id → { el, map, marker }
  var followZ = 900;
  var followOffset = 0;

  function openFollow(id) {
    id = parseInt(id, 10);
    if (follows[id]) {
      follows[id].el.style.zIndex = ++followZ;
      return;
    }
    var d = state.index[id];
    if (!d) return;

    var el = document.createElement("div");
    el.className = "tp-follow";
    var off = (followOffset++ % 5) * 28;
    el.style.right = 24 + off + "px";
    el.style.top = 84 + off + "px";
    el.style.zIndex = ++followZ;
    el.innerHTML =
      '<div class="tp-follow__head">' +
      '<span class="tp-follow__title">Seguir (' + esc(d.name || "#" + id) + ")</span>" +
      '<button type="button" class="tp-follow__close" aria-label="' + tpT('ui.lit_close') + '">×</button></div>' +
      '<div class="tp-follow__map"></div>' +
      '<div class="tp-follow__foot"><span data-f-speed>—</span><span data-f-time></span></div>';
    root.appendChild(el);

    // Dentro va la MISMA pagina de seguimiento del legacy
    // (devices/follow_map/{id}, la que abre devices.follow en un iframe): trae
    // su selector de capa base, la cola, el nombre, el popup del marcador y su
    // propio sondeo. La ventana se puede redimensionar desde la esquina.
    var mapBox = el.querySelector(".tp-follow__map");
    var frame = document.createElement("iframe");
    frame.className = "tp-follow__frame";
    frame.title = tpT("front.follow");
    frame.src = "/devices/follow_map/" + id;
    mapBox.appendChild(frame);

    follows[id] = { el: el, map: null, marker: null, frame: frame };
    updateFollow(id);

    // Arrastre por la cabecera (pointer events)
    var head = el.querySelector(".tp-follow__head");
    var drag = null;
    head.addEventListener("pointerdown", function (e) {
      if (e.target.closest(".tp-follow__close")) return;
      el.style.zIndex = ++followZ;
      el.style.left = el.offsetLeft + "px";
      el.style.right = "auto";
      drag = { x: e.clientX, y: e.clientY, left: el.offsetLeft, top: el.offsetTop };
      el.classList.add("is-dragging");
      head.setPointerCapture(e.pointerId);
      e.preventDefault();
    });
    head.addEventListener("pointermove", function (e) {
      if (!drag) return;
      el.style.left = Math.max(0, drag.left + e.clientX - drag.x) + "px";
      el.style.top = Math.max(0, drag.top + e.clientY - drag.y) + "px";
    });
    head.addEventListener("pointerup", function () {
      drag = null;
      el.classList.remove("is-dragging");
    });
    el.addEventListener("pointerdown", function () {
      el.style.zIndex = ++followZ;
    });

    el.querySelector(".tp-follow__close").addEventListener("click", function () {
      closeFollow(id);
    });
  }

  function closeFollow(id) {
    var f = follows[id];
    if (!f) return;
    if (f.map) {
      try { f.map.remove(); } catch (e) {}
    }
    f.el.remove();
    delete follows[id];
  }

  function updateFollow(id) {
    var f = follows[id];
    var d = state.index[id];
    if (!f || !d || d.lat == null || d.lng == null) return;
    var ll = [parseFloat(d.lat), parseFloat(d.lng)];
    if (!f.map) {
      // La pagina del legacy se actualiza sola; aqui solo el pie.
    } else if (!f.marker) {
      f.marker = L.marker(ll, { icon: deviceIcon(d) }).addTo(f.map);
      f.map.setView(ll, 16);
    } else {
      f.marker.setLatLng(ll);
      f.marker.setIcon(deviceIcon(d));
      f.map.panTo(ll);
    }
    var sp = f.el.querySelector("[data-f-speed]");
    if (sp) sp.textContent = d.speed_text || (d.speed != null ? d.speed + " kph" : "—");
    var tm = f.el.querySelector("[data-f-time]");
    if (tm) tm.textContent = d.time || "";
  }

  function updateFollows() {
    for (var id in follows) updateFollow(parseInt(id, 10));
  }
  /** Deja marcada en la lista solo la fila del vehiculo seleccionado. */
  function markSelectedRow(id) {
    if (!listEl) return;
    Array.prototype.forEach.call(listEl.querySelectorAll(".tp-live__row.is-selected"), function (r) {
      r.classList.remove("is-selected");
    });
    if (id == null) return;
    var row = listEl.querySelector('.tp-live__row[data-id="' + id + '"]');
    if (row) row.classList.add("is-selected");
  }

  // Zoom al seleccionar un vehiculo: el mismo 18 que el legacy, recortado al
  // maximo de la capa base activa (fitBounds ya lo respeta).
  var SELECT_ZOOM = 18;

  /**
   * Centra el mapa en el vehiculo seleccionado dejando fuera la ficha (drawer),
   * que tapa la parte derecha del mapa: con setView a secas el marcador
   * quedaba debajo de la ficha y parecia que no se centraba.
   * `zoom` = nivel a usar; sin el se conserva el actual (seguimiento).
   */
  function centerOnSelected(zoom) {
    var d = state.selectedId != null ? state.index[state.selectedId] : null;
    if (!d || d.lat == null || d.lng == null || !state.map) return;
    // Tamaño real del mapa antes de centrar (la ficha recien abierta lo encoge).
    state.map.invalidateSize({ pan: false });
    var ll = L.latLng(parseFloat(d.lat), parseFloat(d.lng));
    var derecha = 0;
    var dw = document.getElementById("tp-live-drawer");
    if (dw && root.getAttribute("data-drawer-open") === "true") {
      var m = state.map.getContainer().getBoundingClientRect();
      var r = dw.getBoundingClientRect();
      if (r.width && r.left < m.right && r.right > m.left) derecha = Math.max(0, m.right - r.left);
    }
    state.map.fitBounds(L.latLngBounds(ll, ll), {
      paddingTopLeft: [40, 40],
      paddingBottomRight: [derecha + 40, 40],
      maxZoom: zoom || state.map.getZoom(),
      animate: true,
    });
  }

  /** Sigue al vehiculo seleccionado tras cada actualizacion (legacy: refreshCluster → fitBounds(true)). */
  function followSelected() {
    if (state.followSelected && state.selectedId != null) centerOnSelected();
  }

  // Quita la seleccion: cierra la ficha y deja de seguir al vehiculo.
  function deselectDevice() {
    root.removeAttribute("data-drawer-open");
    root.removeAttribute("data-bottombar-open");
    // Sin vehiculo no hay widgets: el boton «Mostrar widgets» (solo visible
    // con la barra abierta y plegada) se recalcula ya, si no se quedaba a la
    // vista tras plegar la barra y cerrar la seleccion.
    root.removeAttribute("data-bottombar-collapsed");
    syncBottombarHandle();
    // Cerrado es invisible (opacity 0) y silenciado (aria-hidden): con
    // `inert` sus botones dejan tambien de recibir el foco con Tab.
    var dw = document.getElementById("tp-live-drawer");
    if (dw) {
      // El foco estaba dentro (el boton Cerrar): se devuelve al mapa
      // (Leaflet le da tabindex=0) antes de volver inerte el panel.
      if (dw.contains(document.activeElement)) {
        var back = document.getElementById("tp-live-map");
        if (back && back.focus) back.focus({ preventScroll: true });
      }
      dw.setAttribute("aria-hidden", "true");
      dw.setAttribute("inert", "");
    }
    var prev = state.selectedId;
    state.selectedId = null;
    state.followSelected = false;
    markSelectedRow(null);
    if (prev != null) {
      refreshMarkerIcon(prev);
      if (state.markers[prev]) state.markers[prev].setZIndexOffset(0);
    }
  }

  function selectDevice(id) {
    var prev = state.selectedId;
    state.selectedId = id;
    markSelectedRow(id);
    // resaltar el marcador seleccionado (glow + traer al frente) y limpiar el anterior
    if (prev != null && prev !== id) {
      refreshMarkerIcon(prev);
      if (state.markers[prev]) state.markers[prev].setZIndexOffset(0);
    }
    refreshMarkerIcon(id);
    if (state.markers[id]) state.markers[id].setZIndexOffset(1000);
    var d = state.index[id];
    root.setAttribute("data-drawer-open", "true");
    // Paridad legacy (devices.select → fitBounds con maxZoom 18): al elegir un
    // vehiculo se acerca a nivel calle y queda SIGUIENDOLO en cada
    // actualizacion hasta que el usuario arrastra el mapa.
    state.followSelected = true;
    if (d && d.lat != null) {
      state.centerZoom = SELECT_ZOOM;
      centerOnSelected(SELECT_ZOOM);
      state.map.once("moveend", function () {
        state.centerZoom = null;
      });
    }
    var drawer = document.getElementById("tp-live-drawer");
    if (drawer) {
      drawer.setAttribute("aria-hidden", "false");
      // Cerrado lleva `inert` (fuera del orden de tabulacion): ver el cierre.
      drawer.removeAttribute("inert");
    }
    if (d) fillDrawer(d);
    loadDrawerWidgets(id);
    loadVehicleState(id);
    var histBox = document.getElementById("tp-drawer-history");
    if (histBox) bindDevShortcuts(histBox, id);
    var rrBtn = document.getElementById("tp-drawer-route-report");
    if (rrBtn) {
      rrBtn.disabled = false;
      rrBtn.onclick = function () {
        openRouteReport(id);
      };
    }
  }
  function setText(elId, val) {
    var el = document.getElementById(elId);
    if (el) el.textContent = val == null || val === "" ? "—" : val;
  }
  // Duración (segundos) → H:MM:SS. Alimenta la KPI "Detenido".
  /**
   * Duracion en el formato que tenga elegido la cuenta.
   *
   * Port de secondsToTime() + time_lrt() del legacy (helpers/helper.js), que
   * miran app.settings.durationFormat. Antes esto escribia H:MM:SS a pelo y
   * el ajuste de Ajustes de cuenta no servia de nada: el popup del sistema
   * clasico decia "12min 50s" y aqui salia "975:11:53" para lo mismo.
   */
  function fmtDuration(sec) {
    if (sec == null || sec === "") return "—";

    sec = Math.max(0, Math.round(Number(sec) || 0));

    var h = Math.floor(sec / 3600),
      m = Math.floor((sec % 3600) / 60),
      s = sec % 60;

    if (CFG.durationFormat === "hh:mm:ss") {
      // El legacy rellena a dos digitos cada tramo; con mas de 99 horas la
      // hora crece y se queda tal cual.
      return (h < 10 ? "0" : "") + h + ":" + ("0" + m).slice(-2) + ":" + ("0" + s).slice(-2);
    }

    // 'standart': se omiten los tramos a cero, los segundos siempre salen.
    return (
      (h ? h + tpT("front.hour_short") + " " : "") +
      (m ? m + tpT("front.minute_short") + " " : "") +
      s + tpT("front.second_short")
    );
  }
  /* ============================================================
     Tablero del vehiculo (drawer)
     ------------------------------------------------------------
     La aguja de velocidad se mueve con el feed (cada 5 s). El resto de
     lecturas llegan del endpoint de widgets al abrir el panel y se guardan
     en dashData, para no volver a pedirlas en cada refresco.

     Un indicador cuyo sensor no existe NO se dibuja: el tablero se arma con
     lo que cada vehiculo reporta de verdad, sin agujas a cero que confundan.
     ============================================================ */

  var dashData = {};   // id -> bloque 'dashboard' del endpoint

  // Geometria de las esferas (media circunferencia).
  var G_GRANDE = { w: 380, h: 196, cx: 190, cy: 158, arco: "M 44 158 A 146 146 0 0 1 336 158", largo: 458.7, aguja: 66 };
  var G_CHICA  = { w: 164, h: 104, cx: 82,  cy: 84,  arco: "M 20 84 A 62 62 0 0 1 144 84",     largo: 194.8, aguja: 34 };

  function pct(v, max) {
    if (!max || isNaN(v)) return 0;
    return Math.max(0, Math.min(1, v / max));
  }

  /** Una esfera con aguja. `nivel` elige el color desde el CSS (ok/warn/bad). */
  function esfera(g, valor, max, texto, sub, nivel, clase) {
    var f = pct(valor, max);

    return (
      '<svg class="tp-dash__gauge ' + (clase || "") + '" data-level="' + (nivel || "ok") + '"' +
      ' viewBox="0 0 ' + g.w + " " + g.h + '" preserveAspectRatio="xMidYMid meet" role="img"' +
      ' aria-label="' + esc(sub) + ": " + esc(texto) + '">' +
      '<path class="tp-dash__track" d="' + g.arco + '"></path>' +
      '<path class="tp-dash__fill" d="' + g.arco + '" stroke-dasharray="' + (f * g.largo).toFixed(1) + " " + g.largo + '"></path>' +
      '<g transform="rotate(' + (f * 180).toFixed(1) + " " + g.cx + " " + g.cy + ')">' +
      '<line class="tp-dash__needle" x1="' + g.cx + '" y1="' + g.cy + '" x2="' + g.aguja + '" y2="' + g.cy + '"></line>' +
      "</g>" +
      '<circle class="tp-dash__hub" cx="' + g.cx + '" cy="' + g.cy + '" r="' + (g === G_GRANDE ? 9 : 6) + '"></circle>' +
      '<text class="tp-dash__read" x="' + g.cx + '" y="' + (g === G_GRANDE ? 128 : 66) + '" text-anchor="middle">' + esc(texto) + "</text>" +
      (g === G_GRANDE
        ? '<text class="tp-dash__unit" x="' + g.cx + '" y="148" text-anchor="middle">' + esc(sub) + "</text>"
        : "") +
      "</svg>"
    );
  }

  /**
   * Esfera pequeña estilo cuadro de instrumentos (opcion A del rediseño):
   * bisel, marcas y numeros, zona de alerta en rojo, aguja con brillo y el
   * valor en una pantallita DEBAJO del eje. El arco es de 240° y termina por
   * encima de la pantallita, asi que la aguja nunca pasa sobre el numero.
   * o = { label, min, max, step, fmt(v) (texto de la escala), red (desde),
   *       low (hasta), color, texto (valor), unidad, alerta (bool) }
   */
  var esferaN = 0;
  function esferaPro(valor, o) {
    var id = "tpg" + ++esferaN;
    var A0 = -210, A1 = 30, cx = 100, cy = 100;
    function rad(d) { return (d * Math.PI) / 180; }
    function pt(r, d) { return [cx + r * Math.cos(rad(d)), cy + r * Math.sin(rad(d))]; }
    function ang(v) {
      var f = (Math.min(Math.max(v, o.min), o.max) - o.min) / (o.max - o.min || 1);
      return A0 + (A1 - A0) * f;
    }
    function arco(r, d0, d1) {
      var a = pt(r, d0), b = pt(r, d1);
      return "M" + a[0].toFixed(1) + " " + a[1].toFixed(1) + " A" + r + " " + r + " 0 " + (d1 - d0 > 180 ? 1 : 0) + " 1 " + b[0].toFixed(1) + " " + b[1].toFixed(1);
    }
    var marcas = "", numeros = "";
    var paso = o.step, menor = paso / 5;
    for (var v = o.min; v <= o.max + 1e-6; v += menor) {
      var mayor = Math.abs((v - o.min) / paso - Math.round((v - o.min) / paso)) < 1e-6;
      var d = ang(v), a = pt(82, d), b = pt(mayor ? 71 : 76, d);
      marcas += '<line x1="' + a[0].toFixed(1) + '" y1="' + a[1].toFixed(1) + '" x2="' + b[0].toFixed(1) + '" y2="' + b[1].toFixed(1) +
        '" class="' + (mayor ? "tp-g__tick" : "tp-g__tick tp-g__tick--minor") + '"/>';
      if (mayor) {
        var n = pt(60, d);
        numeros += '<text x="' + n[0].toFixed(1) + '" y="' + (n[1] + 4).toFixed(1) + '" class="tp-g__num">' + esc(o.fmt(v)) + "</text>";
      }
    }
    var zona = "";
    if (o.red != null) zona = '<path d="' + arco(78, ang(o.red), A1) + '" class="tp-g__zone"/>';
    else if (o.low != null) zona = '<path d="' + arco(78, A0, ang(o.low)) + '" class="tp-g__zone"/>';
    var dv = ang(valor), p = pt(74, dv), b1 = pt(3, dv + 90), b2 = pt(3, dv - 90);
    return (
      '<svg class="tp-g" viewBox="0 0 200 200" role="img" aria-label="' + esc(o.label) + ": " + esc(o.texto) + " " + esc(o.unidad) + '"' +
      ' style="--tp-g-color:' + o.color + '">' +
      "<defs>" +
      '<radialGradient id="' + id + 'f" cx="50%" cy="40%" r="65%"><stop offset="0" stop-color="#1f2c4f"/><stop offset="1" stop-color="#080d1c"/></radialGradient>' +
      '<linearGradient id="' + id + 'b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5c6b8e"/><stop offset=".5" stop-color="#1b2440"/><stop offset="1" stop-color="#3b4766"/></linearGradient>' +
      '<filter id="' + id + 'g" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="2.2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>' +
      "</defs>" +
      '<circle cx="100" cy="100" r="96" fill="url(#' + id + 'b)"/>' +
      '<circle cx="100" cy="100" r="89" fill="url(#' + id + 'f)"/>' +
      zona +
      '<path d="' + arco(78, A0, dv) + '" class="tp-g__fill" filter="url(#' + id + 'g)"/>' +
      marcas + numeros +
      '<polygon points="' + b1[0].toFixed(1) + "," + b1[1].toFixed(1) + " " + p[0].toFixed(1) + "," + p[1].toFixed(1) + " " + b2[0].toFixed(1) + "," + b2[1].toFixed(1) +
      '" class="tp-g__needle" filter="url(#' + id + 'g)"/>' +
      '<circle cx="100" cy="100" r="9" class="tp-g__hub"/><circle cx="100" cy="100" r="3" class="tp-g__hub-dot"/>' +
      '<rect x="58" y="138" width="84" height="34" rx="7" class="tp-g__lcd"/>' +
      '<text x="100" y="163" class="tp-g__read' + (o.alerta ? " tp-g__read--alert" : "") + '" filter="url(#' + id + 'g)">' + esc(o.texto) +
      '<tspan class="tp-g__unit"> ' + esc(o.unidad) + "</tspan></text>" +
      "</svg>"
    );
  }

  /**
   * Velocimetro grande estilo cuadro de instrumentos (variante A2): misma
   * esfera que esferaPro, escala cada 20 con marcas cada 5, la velocidad en
   * una pantalla bajo el eje y, si hay limite de geocerca, el tramo desde el
   * limite en rojo y la señal de transito con el numero en la esquina.
   */
  function velocimetroPro(v, max, unidad, limite, apagado) {
    var id = "tpv" + ++esferaN;
    var A0 = -210, A1 = 30, cx = 150, cy = 150;
    function rad(d) { return (d * Math.PI) / 180; }
    function pt(r, d) { return [cx + r * Math.cos(rad(d)), cy + r * Math.sin(rad(d))]; }
    function ang(x) { return A0 + (A1 - A0) * Math.min(Math.max(x, 0), max) / max; }
    function arco(r, d0, d1) {
      var a = pt(r, d0), b = pt(r, d1);
      return "M" + a[0].toFixed(1) + " " + a[1].toFixed(1) + " A" + r + " " + r + " 0 " + (d1 - d0 > 180 ? 1 : 0) + " 1 " + b[0].toFixed(1) + " " + b[1].toFixed(1);
    }
    var paso = max > 200 ? 40 : 20, menor = paso / 4, marcas = "";
    for (var x = 0; x <= max + 1e-6; x += menor) {
      var mayor = Math.abs(x / paso - Math.round(x / paso)) < 1e-6;
      var d = ang(x), a = pt(124, d), b = pt(mayor ? 108 : 116, d);
      marcas += '<line x1="' + a[0].toFixed(1) + '" y1="' + a[1].toFixed(1) + '" x2="' + b[0].toFixed(1) + '" y2="' + b[1].toFixed(1) +
        '" class="' + (mayor ? "tp-g__tick" : "tp-g__tick tp-g__tick--minor") + '"/>';
      if (mayor) {
        var n = pt(92, d);
        marcas += '<text x="' + n[0].toFixed(1) + '" y="' + (n[1] + 5).toFixed(1) + '" class="tp-g__num tp-g__num--big">' + Math.round(x) + "</text>";
      }
    }
    var dv = ang(v), p = pt(112, dv), b1 = pt(4, dv + 90), b2 = pt(4, dv - 90);
    var sobre = limite && v > limite;
    return (
      '<svg class="tp-g tp-g--speed" viewBox="0 0 300 250" role="img" aria-label="' + esc(Math.round(v) + " " + unidad) + '">' +
      "<defs>" +
      '<radialGradient id="' + id + 'f" cx="50%" cy="40%" r="65%"><stop offset="0" stop-color="#1f2c4f"/><stop offset="1" stop-color="#080d1c"/></radialGradient>' +
      '<linearGradient id="' + id + 'b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5c6b8e"/><stop offset=".5" stop-color="#1b2440"/><stop offset="1" stop-color="#3b4766"/></linearGradient>' +
      '<filter id="' + id + 'g" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>' +
      "</defs>" +
      '<circle cx="150" cy="150" r="146" fill="url(#' + id + 'b)"/><circle cx="150" cy="150" r="137" fill="url(#' + id + 'f)"/>' +
      (limite ? '<path d="' + arco(119, ang(limite), A1) + '" class="tp-g__zone tp-g__zone--speed"/>' : "") +
      '<path d="' + arco(119, A0, dv) + '" class="tp-g__fill tp-g__fill--speed" filter="url(#' + id + 'g)"/>' +
      marcas +
      '<polygon points="' + b1[0].toFixed(1) + "," + b1[1].toFixed(1) + " " + p[0].toFixed(1) + "," + p[1].toFixed(1) + " " + b2[0].toFixed(1) + "," + b2[1].toFixed(1) +
      '" class="tp-g__needle" filter="url(#' + id + 'g)"/>' +
      '<circle cx="150" cy="150" r="13" class="tp-g__hub"/><circle cx="150" cy="150" r="4" class="tp-g__hub-dot"/>' +
      '<rect x="95" y="176" width="110" height="48" rx="9" class="tp-g__lcd"/>' +
      '<text x="150" y="212" class="tp-g__read tp-g__read--speed' + (sobre ? " tp-g__read--alert" : "") + (apagado ? " tp-g__read--off" : "") +
      '" filter="url(#' + id + 'g)">' + Math.round(v) + "</text>" +
      '<text x="150" y="240" class="tp-g__label">' + esc(String(unidad).toUpperCase()) + "</text>" +
      (limite
        ? '<g transform="translate(266 34)"><circle r="24" class="tp-g__sign"/><text y="7" class="tp-g__sign-num">' + esc(limite) + "</text></g>"
        : "") +
      "</svg>"
    );
  }

  function testigo(nombre, etiqueta, encendido, icono) {
    // null = el vehiculo no lleva ese sensor: no se dibuja el testigo
    if (encendido === null || encendido === undefined) return "";

    return (
      '<span class="tp-dash__tell" data-on="' + (encendido ? "1" : "0") + '" title="' + esc(nombre) + '">' +
      icono +
      '<span class="tp-dash__tell-label">' + esc(etiqueta) + "</span>" +
      "</span>"
    );
  }

  var ICONOS = {
    motor: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 10h2l2-3h4l2 3h2a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2l-2 2h-4l-2-2H6a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2z"/></svg>',
    ignicion: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>',
    puerta: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v18H5V6l9-3z"/><path d="M14 3h5v18h-5"/><circle cx="11" cy="12" r="1"/></svg>',
    bloqueo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
    bateria: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="17" height="10" rx="2"/><path d="M22 11v2"/><path d="M6 11v2M10 11v2"/></svg>',
    satelite: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20a8 8 0 0 0-8-8"/><path d="M12 20a12 12 0 0 0-12-12" opacity="0.45"/><circle cx="19" cy="17" r="2"/></svg>',
  };

  // Icono y color de cada dato de la ficha (variante A2 del rediseño).
  var DATO_ICONO = {
    odo: '<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4-5"/>',
    hrs: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>',
    dir: '<circle cx="12" cy="12" r="9"/><path d="m12 7 3 8-3-2-3 2z"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
    dist: '<path d="M6 19a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M8.6 14.5 15.4 9.5"/>',
  };
  function rumboCardinal(g) {
    if (g == null || isNaN(g)) return "";
    var p = ["N", "NE", "E", "SE", "S", "SO", "O", "NO"];
    return p[Math.round(((+g % 360) + 360) % 360 / 45) % 8];
  }

  function celda(etiqueta, valor, unidad, tipo) {
    if (valor === null || valor === undefined || valor === "") return "";

    var texto = String(valor).trim();

    // Algunos valores llegan ya formateados por el sensor, con la unidad
    // pegada ("271161 Km"). Se separa para que numero y unidad compartan
    // linea en vez de partirse por ese espacio.
    // Duracion ("488h 14min 0s"): las dos unidades mayores, la primera
    // grande y la segunda como unidad, para que quepa en la tarjeta.
    if (tipo === "stop" && /\s/.test(texto)) {
      var partes = texto.split(/\s+/);
      texto = partes[0];
      unidad = partes[1] || "";
    }
    if (!unidad) {
      var m = texto.match(/^(.*?)\s+([^\s\d]+)$/);
      if (m) {
        texto = m[1];
        unidad = m[2];
      }
    }

    var icono = tipo && DATO_ICONO[tipo]
      ? '<span class="tp-dash__stat-ic tp-dash__stat-ic--' + tipo + '" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        DATO_ICONO[tipo] + "</svg></span>"
      : "";
    return (
      '<div class="tp-dash__stat' + (icono ? " tp-dash__stat--ic" : "") + '">' + icono +
      '<div class="tp-dash__stat-body">' +
      '<div class="tp-dash__stat-label">' + esc(etiqueta) + "</div>" +
      '<div class="tp-dash__stat-value">' +
      '<span class="tp-dash__stat-num">' + esc(texto) + "</span>" +
      (unidad ? '<span class="tp-dash__stat-unit">' + esc(unidad) + "</span>" : "") +
      "</div></div></div>"
    );
  }

  function renderDashboard(d) {
    var box = document.getElementById("tp-drawer-dash");
    if (!box || !d) return;

    var dash = dashData[d.id] || {};
    var apagado = classify(d) === "offline";

    // d.speed ya viene en la unidad del usuario y con el sensor ECM aplicado
    var v = parseFloat(d.speed) || 0;
    var max = dash.speed_max || 140;
    var nivel = apagado ? "off" : v >= max * 0.85 ? "bad" : v >= max * 0.6 ? "warn" : "ok";

    // Limite de la geocerca, si el vehiculo esta dentro de una
    var gf = !apagado && window.tpLiveZones && tpLiveZones.limitFor
      ? tpLiveZones.limitFor(d.lat, d.lng, d.vehicle_type_id, d.vehicle_state_id)
      : null;

    var html = '<div class="tp-dash__main tp-dash__main--pro">' +
      velocimetroPro(apagado ? 0 : v, max, dash.speed_unit || "km/h", gf && gf.limit ? gf.limit : null, apagado);

    if (gf && gf.limit) {
      var dif = Math.round(v - gf.limit);
      html +=
        '<div class="tp-dash__zone" data-over="' + (dif >= 0 ? "1" : "0") + '">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' +
        "<span>" + esc(gf.zoneName || tpT('ui.zone')) + tpT('ui.dot_limit') + gf.limit + "</span>" +
        '<strong>' + (dif >= 0 ? "+" : "") + dif + "</strong>" +
        "</div>";
    }

    html += "</div>";

    // Esferas pequeñas: solo las que el vehiculo reporta. Estilo cuadro de
    // instrumentos (esferaPro): el valor va en su pantallita, sin aguja encima.
    var chicas = "";
    if (dash.rpm) {
      var rpmMax = dash.rpm.max || 4000;
      chicas += '<div class="tp-dash__small tp-dash__small--pro"><div class="tp-dash__small-label">' + esc(tpT('ui.engine_rpm')) + '</div>' +
        esferaPro(apagado ? 0 : dash.rpm.value, {
          label: tpT('ui.engine_rpm'), min: 0, max: rpmMax, step: 1000,
          fmt: function (v) { return Math.round(v / 1000); },
          red: rpmMax * 0.75, color: "#38bdf8",
          texto: String(apagado ? 0 : dash.rpm.value), unidad: "rpm",
          alerta: dash.rpm.value >= rpmMax * 0.75,
        }) + "</div>";
    }
    if (dash.fuel) {
      chicas += '<div class="tp-dash__small tp-dash__small--pro"><div class="tp-dash__small-label">COMBUSTIBLE</div>' +
        esferaPro(dash.fuel.value, {
          label: "COMBUSTIBLE", min: 0, max: 100, step: 25,
          fmt: function (v) { return v; },
          low: 15, color: "#22c55e",
          texto: String(dash.fuel.value), unidad: dash.fuel.unit === "%" ? "%" : (dash.fuel.unit || ""),
          alerta: dash.fuel.value <= 15,
        }) + "</div>";
    }
    if (dash.temp) {
      chicas += '<div class="tp-dash__small tp-dash__small--pro"><div class="tp-dash__small-label">TEMPERATURA</div>' +
        esferaPro(dash.temp.value, {
          label: "TEMPERATURA", min: 40, max: 120, step: 20,
          fmt: function (v) { return v; },
          red: 95, color: "#f59e0b",
          texto: String(dash.temp.value), unidad: "°C",
          alerta: dash.temp.value >= 95,
        }) + "</div>";
    }
    if (chicas) html += '<div class="tp-dash__row">' + chicas + "</div>";

    // Testigos
    var t = dash.telltales || {};
    // Sin conexion, motor e ignicion se muestran apagados aunque el ultimo
    // dato los dejara encendidos: el legacy hace lo mismo (setOffline pone en
    // falso acc/ignition/engine), tambien al cargar un equipo ya desconectado.
    if (apagado) {
      t = Object.assign({}, t);
      if (t.engine != null) t.engine = false;
      if (t.ignition != null) t.ignition = false;
    }
    var tells =
      testigo(tpT('front.engine'), tpT('engine_upper'), t.engine, ICONOS.motor) +
      testigo(tpT('ui.ignition'), tpT('ui.ignition_upper'), t.ignition, ICONOS.ignicion) +
      testigo(tpT('door'), tpT('door_upper'), t.door, ICONOS.puerta) +
      testigo(tpT('ui.engine_lock'), tpT('lock_upper'), t.blocked, ICONOS.bloqueo) +
      (dash.volt != null
        ? '<span class="tp-dash__tell" data-on="1" title="' + tpT('ui.external_battery') + '">' + ICONOS.bateria +
          '<span class="tp-dash__tell-label">' + esc(dash.volt.text || (dash.volt.value + " " + (dash.volt.unit || "v"))) + "</span></span>"
        : "") +
      (dash.satellites != null
        ? '<span class="tp-dash__tell" data-on="' + (apagado ? "0" : "1") + '" title="' + esc(tpT('satellites')) + '">' + ICONOS.satelite +
          '<span class="tp-dash__tell-label">' + Math.round(dash.satellites) + " SAT</span></span>"
        : "");

    if (tells) html += '<div class="tp-dash__tells">' + tells + "</div>";

    // Cifras de contexto: las cuatro de siempre mas odometro y horometro.
    //
    // La DISTANCIA es la que calcula la plataforma desde las posiciones; el
    // ODOMETRO es el del vehiculo, leido del sensor. Miden lo mismo por dos
    // caminos y casi nunca coinciden -la plataforma empieza a contar cuando
    // se instala el equipo-, asi que con sensor de odometro la calculada no
    // se enseña. Esa decision la toma el servidor (show_total_distance), que
    // mira tambien el interruptor del plugin, como el legacy.
    var stats =
      celda(tpT('ui.odometer'), dash.odometer, null, "odo") +
      celda(tpT('ui.hour_meter'), dash.engine_hours, null, "hrs") +
      celda(tpT('heading'), d.course != null ? Math.round(d.course) + "°" : null, d.course != null ? rumboCardinal(d.course) : null, "dir") +
      celda(tpT('front.stopped'), stopDurationText(d), null, "stop") +
      (dash.show_total_distance ? celda(tpT('front.distance'), d.total_distance, "km", "dist") : "");

    if (stats) html += '<div class="tp-dash__stats">' + stats + "</div>";

    box.innerHTML = html;
  }

  function fillDrawer(d) {
    setText("tp-drawer-name", d.name || "#" + d.id);
    setText("tp-drawer-plate", d.plate_number || d.imei || "—");
    var dot = document.getElementById("tp-drawer-dot");
    if (dot) {
      // Los cuatro cubos tienen su clase en el CSS, no hace falta mapear.
      dot.className = "tp-dot tp-dot--" + classify(d);
      // ...pero si el vehiculo tiene color propio para ese estado, manda el
      // suyo, igual que en la ficha del legacy.
      var cDrawer = statusVar(d.icon_color);
      dot.style.background = cDrawer || "";
      dot.title = STATUS_LABELS[d.online] || d.online || "";
    }
    renderDashboard(d);
    // El resto de la ficha (última señal, coordenadas, altitud, motor, imei,
    // modelo, sim) se muestra unificada en el widget "Objeto" (device-widgets.blade).
    var sr = document.getElementById("tp-drawer-status-row");
    if (sr) {
      var lbl = STATUS_LABELS[d.online] || d.online || "—";
      // Color del estado del propio vehiculo (icon_color = getStatusColor()
      // del backend), como el punto de la ficha y la lista; la clase por
      // categoria queda solo de reserva si no llega color.
      var cSr = statusVar(d.icon_color), cSrOn = statusVar(d.icon_color, "on");
      sr.innerHTML = '<span class="tp-chip tp-chip--status" data-status="' + classify(d) + '"' +
        (cSr ? ' style="background:' + cSr + ";color:" + cSrOn + ";border-color:" + cSr + '"' : "") +
        ">" + esc(lbl) + "</span>";
    }
  }
  // El handle "Widgets" (botón para reabrir la barra inferior) solo debe verse
  // cuando el bottombar está ABIERTO pero COLAPSADO. Deriva su visibilidad del
  // estado del root en cada transición (colapsar / expandir / cambiar device),
  // manejando el atributo `hidden` (el CSS lo oculta con display:none!important).
  function syncBottombarHandle() {
    var h = document.getElementById("tp-bottombar-expand");
    if (!h) return;
    h.hidden = !(root.hasAttribute("data-bottombar-open") && root.hasAttribute("data-bottombar-collapsed"));
  }

  // Alto real de la barra de widgets → --tp-bb-offset en el root. El chip
  // «Actualizando cada 15s» y los controles inferiores de Leaflet (atribución)
  // suben con él en vez de quedar tapados (live-ops.css). Con la barra
  // oculta (display:none) mide 0 y vuelven a su sitio.
  (function () {
    var bb = document.getElementById("tp-live-bottombar");
    if (!bb || !root || typeof ResizeObserver !== "function") return;
    function medir() {
      var h = bb.offsetHeight;
      root.style.setProperty("--tp-bb-offset", h ? h + 12 + "px" : "0px");

      // La atribución (abajo a la derecha) solo sube si el contenido de la
      // barra le pasa por debajo; las tarjetas suelen quedarse a la izquierda.
      var derecha = "0px";
      var attr = root.querySelector(".leaflet-bottom.leaflet-right");
      if (h && attr) {
        var hasta = 0;
        Array.prototype.forEach.call(bb.querySelectorAll(".tp-live__bottombar-cards > *, .tp-live__bottombar-ctl"), function (el) {
          var r = el.getBoundingClientRect();
          if (r.width) hasta = Math.max(hasta, r.right);
        });
        var a = attr.getBoundingClientRect();
        if (hasta + 8 > a.left) derecha = h + 12 + "px";
      }
      root.style.setProperty("--tp-bb-offset-right", derecha);
    }
    new ResizeObserver(medir).observe(bb);
    window.addEventListener("resize", medir);
  })();

  /* ── Estado de vehículo (drawer): badge + cambiador rápido ──────────── */
  var vstateData = { deviceId: null, states: [], current: null };
  function vsBase(id) {
    return (CFG.devicesUrl || "/ui/v2/api/devices").replace(/\/devices(\?.*)?$/, "/devices") + "/" + id;
  }
  function loadVehicleState(id, openAfter) {
    var box = document.getElementById("tp-drawer-vstate");
    if (!box) return;
    box.hidden = true;
    fetch(vsBase(id) + "/vehicle-states", { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (state.selectedId != id || !res || !res.ok || !res.states || !res.states.length) return;
        vstateData = { deviceId: id, states: res.states, current: res.current };
        renderVStateBadge();
        box.hidden = false;
        if (openAfter) openVStateModal();
      })
      .catch(function () {});
  }
  function renderVStateBadge() {
    var el = document.getElementById("tp-drawer-vstate-badge");
    if (!el) return;
    var cur = vstateData.states.filter(function (s) { return s.id === vstateData.current; })[0];
    el.innerHTML = cur
      ? vehicleStateBadge(cur, "font-size:12px;padding:3px 12px;")
      : '<span class="tp-text-xs tp-text-muted">' + tpT('ui.no_state') + '</span>';
  }
  function openVStateModal() {
    if (!window.tpModal || !vstateData.states.length) return;
    var chips = vstateData.states
      .map(function (s) {
        return (
          '<button type="button" class="tp-vs-choice' + (s.id === vstateData.current ? " is-active" : "") + '" data-vs="' + s.id + '">' +
          '<span class="tp-vs-dot" style="background:' + (s.color || "#64748b") + ';"></span>' + esc(s.name) + "</button>"
        );
      })
      .join("");
    var dlg = tpModal.open({
      title: tpT('ui.change_state'),
      size: "sm",
      body:
        '<div class="tp-scope"><div class="tp-vs-choices">' + chips +
        '<button type="button" class="tp-vs-choice' + (!vstateData.current ? " is-active" : "") +
        '" data-vs="0"><span class="tp-vs-dot" style="background:#cbd5e1;"></span>' + tpT('ui.no_state') + '</button></div></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
    });
    dlg.addEventListener("click", function (e) {
      var b = e.target.closest("[data-vs]");
      if (b) changeVState(parseInt(b.getAttribute("data-vs"), 10), dlg);
    });
  }
  function changeVState(stateId, dlg) {
    tpModal.setBusy(dlg, true);
    tpModal
      .fetchJSON(vsBase(vstateData.deviceId) + "/vehicle-state", { method: "POST", json: { vehicle_state_id: stateId } })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (!res.ok || !res.data || !res.data.ok) { tpModal.toast(tpT('ui.state_change_failed'), "error"); return; }
        vstateData.current = stateId > 0 ? stateId : null;
        renderVStateBadge();
        // Re-pinta la fila y el marcador con el nuevo estado.
        var d = state.index[vstateData.deviceId];
        if (d) {
          d.vehicle_state = res.data.vehicle_state;
          var rowEl = listEl && listEl.querySelector('.tp-live__row[data-id="' + d.id + '"]');
          if (rowEl) rowEl.outerHTML = rowTemplate(d);
          refreshMarkerIcon(d.id);
        }
        tpModal.close(dlg);
        tpModal.toast(tpT('ui.state_updated'), "success");
      })
      .catch(function () { tpModal.setBusy(dlg, false); tpModal.toast(tpT('ui.network_error'), "error"); });
  }

  /**
   * Rellena los huecos [data-tp-addr] con la direccion del vehiculo.
   *
   * Va en su propia peticion: el geocodificador es un servicio externo y
   * costaba ~260 ms de los ~263 ms que tardaba el endpoint de widgets,
   * retrasando tambien a los Sensores, que no usan la direccion.
   */
  var addrFicha = {};
  function pintarDireccion(id) {
    var huecos = document.querySelectorAll("[data-tp-addr]");
    if (!huecos.length) return;

    function poner(a) {
      Array.prototype.forEach.call(huecos, function (el) {
        var k = el.getAttribute("data-tp-addr");
        var v = (a && a[k]) || "";
        // En la tarjeta Ubicación la via y las etiquetas se ocultan si no hay
        // dato (sin un «—» suelto); el resto de huecos muestra la raya.
        if (k === "road" || el.closest(".tp-info__crumbs")) {
          el.textContent = v;
          el.hidden = !v;
        } else {
          el.textContent = v || "—";
        }
      });
    }

    // La clave lleva la POSICION, no solo el vehiculo: el legacy hace lo
    // mismo ('_a_' + lat + lng en app.addressStack). Si se guardara por id, un
    // vehiculo que se ha movido seguiria enseñando la direccion de antes.
    var d = state.index[id] || {};
    var clave = id + "@" + d.lat + "," + d.lng;

    if (addrFicha[clave] !== undefined) return poner(addrFicha[clave]);

    var base = CFG.devicesUrl || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/address", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        // Si entretanto se cambio de vehiculo, no se pisa lo que haya.
        if (state.selectedId != id) return;
        addrFicha[clave] = (res && res.address) || null;
        poner(addrFicha[clave]);
      })
      .catch(function () {
        if (state.selectedId == id) poner(null);
      });
  }

  // Reparte los widgets del device: .tp-dw--side → drawer, .tp-dw--bottom → bottombar.
  /**
   * Widgets del equipo (ficha + barra inferior).
   * opts.bottomOnly: solo la barra inferior, para un equipo NO seleccionado
   *   (legacy: clic en el marcador → updateWitgets sin seleccionar).
   * opts.silent: refresco desde el sondeo, sin el «Cargando…».
   */
  var widgetsDe = null;
  function loadDrawerWidgets(id, opts) {
    opts = opts || {};
    var box = opts.bottomOnly ? null : document.getElementById("tp-drawer-widgets");
    var bottom = document.getElementById("tp-live-bottombar-cards");
    widgetsDe = id;
    if (box && !opts.silent) box.innerHTML = '<div class="tp-text-xs tp-text-muted" style="padding:8px;">' + tpT('ui.loading_ellipsis') + '</div>';
    var base = CFG.devicesUrl || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/widgets", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (opts.bottomOnly ? widgetsDe != id || state.selectedId != null : state.selectedId != id) return;

        // Lecturas del tablero: se guardan y se repinta ya, sin esperar al
        // siguiente refresco del feed.
        if (res && res.dashboard) {
          dashData[id] = res.dashboard;
          if (state.index[id] && !opts.bottomOnly) renderDashboard(state.index[id]);
        }

        var sideHtml = "",
          bottomHtml = "";
        if (res && res.ok && res.html) {
          var tmp = document.createElement("div");
          tmp.innerHTML = res.html;
          Array.prototype.forEach.call(tmp.querySelectorAll(".tp-dw--side"), function (n) {
            sideHtml += n.outerHTML;
          });
          // La barra inferior usa cards horizontales: tomamos las .tp-card de
          // dentro de .tp-dw--bottom como hijos directos (no el wrapper).
          Array.prototype.forEach.call(tmp.querySelectorAll(".tp-dw--bottom > .tp-card"), function (n) {
            bottomHtml += n.outerHTML;
          });
          // Si la blade no agrupa, todo va al drawer.
          if (!sideHtml && !bottomHtml) sideHtml = res.html;
        }
        if (box) box.innerHTML = sideHtml || "";
        if (bottom) bottom.innerHTML = bottomHtml || "";
        pintarDireccion(id);
        if (bottomHtml.trim()) {
          root.setAttribute("data-bottombar-open", "true");
          root.removeAttribute("data-bottombar-collapsed");
        } else {
          root.removeAttribute("data-bottombar-open");
        }
        syncBottombarHandle();
        // El HTML inyectado por innerHTML no ejecuta <script>: dibujamos los
        // gráficos de combustible leyendo el JSON embebido en cada card.
        renderFuelCharts(bottom);
      })
      .catch(function () {});
  }

  function loadBottomWidgets(id) {
    loadDrawerWidgets(id, { bottomOnly: true });
  }

  // Refresco de los widgets con el sondeo (legacy refreshWitgets en cada
  // update), con un respiro para no pedirlos cada 5 s si el equipo manda
  // datos seguidos.
  var widgetsRefrescados = {};
  function refreshDrawerWidgets(id) {
    var t = widgetsRefrescados[id] || 0;
    if (Date.now() - t < 20000) {
      pintarDireccion(id);
      return;
    }
    widgetsRefrescados[id] = Date.now();
    loadDrawerWidgets(id, { silent: true });
  }

  // Gráfico de combustible: canvas propio (sin flot), serie {t,v} de 24 h.
  function renderFuelCharts(scope) {
    if (!scope) return;
    Array.prototype.forEach.call(scope.querySelectorAll("[data-fuel-chart]"), function (canvas) {
      var dataEl = canvas.parentNode && canvas.parentNode.querySelector("[data-fuel-data]");
      if (!dataEl) return;
      var payload;
      try {
        payload = JSON.parse(dataEl.textContent || "{}");
      } catch (e) {
        return;
      }
      drawFuelChart(canvas, payload.values || [], payload.unit || "", payload.max);
    });
  }

  function drawFuelChart(canvas, values, unit, max) {
    var pts = (values || [])
      .map(function (p) {
        return { t: parseInt(p.t, 10), v: parseFloat(p.v) };
      })
      .filter(function (p) {
        return isFinite(p.t) && isFinite(p.v);
      });
    var dpr = window.devicePixelRatio || 1;
    var w = canvas.clientWidth || canvas.parentNode.clientWidth || 260;
    var h = canvas.clientHeight || 80;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    var ctx = canvas.getContext("2d");
    if (!ctx) return;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    if (pts.length < 2) return;

    var minX = pts[0].t, maxX = pts[pts.length - 1].t;
    var minY = Infinity, maxY = -Infinity;
    pts.forEach(function (p) {
      if (p.v < minY) minY = p.v;
      if (p.v > maxY) maxY = p.v;
    });

    // Contra el tanque, no contra los datos. Autoescalando, pasar del 62% al
    // 61% en 24 h se dibujaba como una caida de arriba abajo del recuadro:
    // parecia que el vehiculo se habia quedado sin combustible.
    var cap = parseFloat(max);

    if (isFinite(cap) && cap > 0) {
      minY = 0;
      maxY = Math.max(cap, maxY);
    } else if (maxY === minY) {
      maxY = minY + 1;
    }
    var padT = 8, padB = 6, padL = 4, padR = 4;
    function X(t) {
      return padL + (maxX === minX ? 0 : (t - minX) / (maxX - minX)) * (w - padL - padR);
    }
    function Y(v) {
      return padT + (1 - (v - minY) / (maxY - minY)) * (h - padT - padB);
    }

    var accent = "#2e63d9";
    // Relleno bajo la curva
    ctx.beginPath();
    ctx.moveTo(X(pts[0].t), h - padB);
    pts.forEach(function (p) {
      ctx.lineTo(X(p.t), Y(p.v));
    });
    ctx.lineTo(X(pts[pts.length - 1].t), h - padB);
    ctx.closePath();
    ctx.fillStyle = "rgba(46,99,217,0.12)";
    ctx.fill();
    // Línea
    ctx.beginPath();
    pts.forEach(function (p, i) {
      var x = X(p.t), y = Y(p.v);
      if (i === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = accent;
    ctx.lineWidth = 1.5;
    ctx.lineJoin = "round";
    ctx.stroke();
  }

  // -----------------------------------------------------------
  // Lista (búsqueda + estado + grupos, con cabeceras colapsables)
  // -----------------------------------------------------------
  // Filtro avanzado: true si el device pasa el set de IDs (o no hay filtro).
  function advPass(d) {
    return !state.advFilter.ids || state.advFilter.ids.has(Number(d.id));
  }
  function recompute() {
    var q = state.searchQuery.trim().toLowerCase();
    var sf = state.statusFilter;
    var counts = { all: state.devices.length, moving: 0, idle: 0, stopped: 0, offline: 0 };
    // Base: búsqueda + filtro de estado segmentado (siempre aplican a lista y mapa).
    var base = state.devices.filter(function (d) {
      var c = classify(d);
      if (c === "moving") counts.moving++;
      else if (c === "idle") counts.idle++;
      else if (c === "stopped") counts.stopped++;
      else counts.offline++;
      if (sf !== "all" && c !== sf) return false;
      if (q) {
        var hay = [
          d.name, d.imei, d.sim_number, d.vin, d.plate_number, d.registration_number,
          d.object_owner, d.device_model, d.additional_notes,
        ].join(" ");
        if (hay.toLowerCase().indexOf(q) === -1) return false;
      }
      return true;
    });
    // Filtro avanzado (addon.object_filter): set de IDs del backend, aplicado a
    // lista y/o mapa según filter_sidebar / filter_map (paridad legacy).
    var af = state.advFilter;
    state.filtered = af.ids && af.sidebar ? base.filter(advPass) : base.slice();
    state.mapFiltered = af.ids && af.map ? base.filter(advPass) : base.slice();
    var sorter = function (a, b) {
      var r = { moving: 0, idle: 1, stopped: 2, offline: 3 };
      var ra = r[classify(a)],
        rb = r[classify(b)];
      if (ra !== rb) return ra - rb;
      return String(a.name || "").trim().localeCompare(String(b.name || "").trim());
    };
    state.filtered.sort(sorter);
    state.mapFiltered.sort(sorter);
    ["all", "moving", "idle", "stopped", "offline"].forEach(function (k) {
      var n = root.querySelector('[data-count="' + k + '"]');
      if (n) n.textContent = counts[k];
    });
    var sM = document.getElementById("tp-live-stat-moving");
    if (sM) sM.textContent = counts.moving;
    var sI = document.getElementById("tp-live-stat-idle");
    if (sI) sI.textContent = counts.idle;
    var sS = document.getElementById("tp-live-stat-stopped");
    if (sS) sS.textContent = counts.stopped;
    var sO = document.getElementById("tp-live-stat-offline");
    if (sO) sO.textContent = counts.offline;
    if (window.tpUpdateLiveStatus)
      window.tpUpdateLiveStatus({
        moving: counts.moving,
        idle: counts.idle,
        stopped: counts.stopped,
        offline: counts.offline,
      });
  }

  // -----------------------------------------------------------
  // Filtro avanzado del sidebar (addon.object_filter). Paridad con el legacy
  // Objects/filters: estado[] + última conexión (online/offline min) + toggles
  // filter_sidebar / filter_map. El backend resuelve el set de IDs con los MISMOS
  // scopes (->filter()); aquí se aplica a lista y/o mapa.
  // -----------------------------------------------------------
  var FILTER_CFG = (function () {
    var el = document.getElementById("tp-live-filter-config");
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || "null");
    } catch (e) {
      return null;
    }
  })();

  function setFilterBtnActive(on) {
    var btn = document.getElementById("tp-live-filter");
    if (!btn) return;
    btn.classList.toggle("is-active", !!on);
    btn.setAttribute("aria-pressed", on ? "true" : "false");
  }

  function openAdvancedFilter() {
    if (!FILTER_CFG || !window.tpModal) return;
    var L2 = FILTER_CFG.labels || {};
    var af = state.advFilter;
    var statusRows = Object.keys(FILTER_CFG.statuses || {})
      .map(function (val) {
        var on = af.statuses && af.statuses.indexOf(val) >= 0 ? " checked" : "";
        return (
          '<label style="display:flex;align-items:center;gap:8px;margin:5px 0;font-size:13px;cursor:pointer;">' +
          '<input type="checkbox" data-f-status value="' + esc(val) + '"' + on + "> " +
          "<span>" + esc(FILTER_CFG.statuses[val]) + "</span></label>"
        );
      })
      .join("");
    var body =
      '<div class="tp-scope">' +
      '<div style="display:flex;gap:16px;margin-bottom:12px;font-size:13px;">' +
      '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-f-sidebar' +
      (af.sidebar ? " checked" : "") +
      "> " + esc(L2.filter_sidebar || tpT('list')) + "</label>" +
      '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-f-map' +
      (af.map ? " checked" : "") +
      "> " + esc(L2.filter_map || tpT('front.map')) + "</label></div>" +
      '<div style="font-size:12px;font-weight:600;margin-bottom:4px;">' + esc(L2.status || tpT('front.status')) + "</div>" +
      '<div style="max-height:190px;overflow:auto;border:1px solid var(--border-default);border-radius:8px;padding:6px 10px;">' +
      statusRows + "</div>" +
      '<div style="font-size:12px;font-weight:600;margin:12px 0 4px;">' +
      esc(L2.last_connection || tpT('ui.last_connection')) + " (" + esc(L2.minutes || "min") + ")</div>" +
      '<div style="display:flex;gap:8px;">' +
      '<input type="number" min="0" class="tp-input" data-f-online placeholder="' + esc(L2.from || tpT('front.from')) +
      '" value="' + (af.online != null ? af.online : "") + '" style="width:50%;">' +
      '<input type="number" min="0" class="tp-input" data-f-offline placeholder="' + esc(L2.to || tpT('global.to')) +
      '" value="' + (af.offline != null ? af.offline : "") + '" style="width:50%;"></div></div>';
    tpModal.open({
      title: L2.title || tpT('attr.filter'),
      size: "sm",
      body: body,
      buttons: [
        {
          label: L2.clear || tpT('global.clear'),
          variant: "ghost",
          close: false,
          onClick: function (dlg) {
            clearAdvancedFilter();
            tpModal.close(dlg);
            return false;
          },
        },
        { label: L2.cancel || tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: L2.apply || tpT('global.apply'),
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            applyAdvancedFilter(dlg);
            return false;
          },
        },
      ],
    });
  }

  function applyAdvancedFilter(dlg) {
    var statuses = Array.prototype.map.call(
      dlg.querySelectorAll("[data-f-status]:checked"),
      function (c) { return c.value; }
    );
    var online = ((dlg.querySelector("[data-f-online]") || {}).value || "").trim();
    var offline = ((dlg.querySelector("[data-f-offline]") || {}).value || "").trim();
    var sidebar = !!(dlg.querySelector("[data-f-sidebar]") || {}).checked;
    var map = !!(dlg.querySelector("[data-f-map]") || {}).checked;
    // online >= offline (paridad applySidebarFilters legacy)
    if (online !== "" && offline !== "" && parseInt(online, 10) < parseInt(offline, 10)) {
      var t = online;
      online = offline;
      offline = t;
    }
    var af = state.advFilter;
    af.statuses = statuses;
    af.online = online === "" ? null : parseInt(online, 10);
    af.offline = offline === "" ? null : parseInt(offline, 10);
    af.sidebar = sidebar;
    af.map = map;
    var hasFilter = statuses.length || online !== "" || offline !== "";
    if (!hasFilter || (!sidebar && !map)) {
      af.ids = null;
      setFilterBtnActive(false);
      recompute();
      renderList();
      renderMarkers();
      tpModal.close(dlg);
      return;
    }
    var params = [];
    statuses.forEach(function (s) { params.push("status[]=" + encodeURIComponent(s)); });
    if (online !== "") params.push("online=" + encodeURIComponent(online));
    if (offline !== "") params.push("offline=" + encodeURIComponent(offline));
    tpModal.setBusy(dlg, true);
    fetch(FILTER_CFG.url + "?" + params.join("&"), {
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        tpModal.setBusy(dlg, false);
        if (!res || !res.ok) {
          tpModal.toast && tpModal.toast(tpT('ui.filter_failed'), "error");
          return;
        }
        state.advFilter.ids = res.filtered ? new Set((res.ids || []).map(Number)) : null;
        setFilterBtnActive(!!state.advFilter.ids);
        recompute();
        renderList();
        renderMarkers();
        tpModal.close(dlg);
      })
      .catch(function () {
        tpModal.setBusy(dlg, false);
        tpModal.toast && tpModal.toast(tpT('ui.network_error'), "error");
      });
  }

  function clearAdvancedFilter() {
    state.advFilter = { ids: null, sidebar: true, map: true, statuses: [], online: null, offline: null };
    setFilterBtnActive(false);
    recompute();
    renderList();
    renderMarkers();
  }

  // -----------------------------------------------------------
  // Widget de bloqueo de motor (plugin locking_widget). Botones Cortar/
  // Restablecer → modal con el comando preconfigurado (editable) + GPRS/SMS →
  // POST a lock-command. Historial → modal con los periodos (lock-history).
  // -----------------------------------------------------------
  function openLockCommand(btn) {
    var card = btn.closest("[data-tp-locking]");
    if (!card || !window.tpModal) return;
    var isOn = btn.getAttribute("data-lock-action") === "on";
    var canSms = card.getAttribute("data-can-sms") === "1";
    var url = card.getAttribute("data-command-url");
    var tplUrl = card.getAttribute("data-templates-url");
    var body =
      '<p style="margin:0 0 10px;">' +
      (isOn
        ? "" + tpT('ui.command_will_be_sent_for') + " <b>" + tpT('engine_cut_verb') + "</b> " + tpT('ui.the_vehicle_engine') + ""
        : "" + tpT('ui.command_will_be_sent_for') + " <b>" + tpT('engine_restore_verb') + "</b> " + tpT('ui.the_vehicle_engine') + "") +
      "</p>" +
      '<label class="tp-field__label">' + tpT('ui.sending') + '</label>' +
      '<select class="tp-input" data-lock-type style="width:100%;margin:4px 0 10px;"><option value="gprs">GPRS</option>' +
      (canSms ? '<option value="sms">SMS</option>' : "") +
      "</select>" +
      '<label class="tp-field__label">' + tpT('ui.lit_gprs_template') + '</label>' +
      '<select class="tp-input" data-lock-tpl style="width:100%;margin:4px 0 10px;"><option value="">' + tpT('ui.loading_ellipsis') + '</option></select>' +
      '<label class="tp-field__label">' + tpT('ui.chan_command') + '</label>' +
      '<input type="text" class="tp-input" data-lock-cmd value="" placeholder="' +
      (isOn ? "setdigout 1" : "setdigout 0") +
      '" style="width:100%;margin-top:4px;">' +
      '<div data-lock-err role="alert" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.35);"></div>';
    var dlg = tpModal.open({
      title: isOn ? tpT('ui.cut_engine') : tpT('ui.restore_engine'),
      size: "sm",
      body: body,
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: isOn ? tpT('engine_cut') : tpT('engine_restore'),
          variant: isOn ? "danger" : "primary",
          close: false,
          onClick: function (dlg) {
            var cmd = ((dlg.querySelector("[data-lock-cmd]") || {}).value || "").trim();
            var type = (dlg.querySelector("[data-lock-type]") || {}).value || "gprs";
            var err = dlg.querySelector("[data-lock-err]");
            function showErr(m) {
              if (err) {
                err.textContent = m;
                err.style.display = "block";
              }
            }
            if (!cmd) {
              showErr(tpT('ui.pick_template_or_write'));
              return false;
            }
            if (err) err.style.display = "none";
            tpModal.setBusy(dlg, true);
            tpModal
              .fetchJSON(url, { method: "POST", json: { type: type, message: cmd } })
              .then(function (res) {
                tpModal.setBusy(dlg, false);
                if (!res.ok || !res.data || !res.data.ok) {
                  showErr((res.data && res.data.message) || tpT('ui.command_send_failed'));
                  return;
                }
                tpModal.toast((res.data && res.data.message) || "Comando enviado.", "success");
                tpModal.close(dlg);
              })
              .catch(function () {
                tpModal.setBusy(dlg, false);
                showErr(tpT('ui.network_error_retry'));
              });
            return false;
          },
        },
      ],
    });
    // Carga las plantillas GPRS adaptadas al equipo; al elegir una, precarga su
    // comando. Si solo hay una, la selecciona automáticamente.
    var tplSel = dlg.querySelector("[data-lock-tpl]");
    var cmdInput = dlg.querySelector("[data-lock-cmd]");
    function fallbackOption() {
      tplSel.innerHTML = '<option value="">— Escribir comando manualmente —</option>';
    }
    if (tplUrl) {
      fetch(tplUrl, {
        credentials: "same-origin",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var all = (res && res.ok && res.items) || [];
          // Preferir plantillas marcadas para esta acción (block=Cortar / unblock=
          // Restablecer). Si no hay marcadas, ofrecer todas las adaptadas al equipo.
          var want = isOn ? "block" : "unblock";
          var flagged = all.filter(function (t) { return t.lock_action === want; });
          var items = flagged.length ? flagged : all;
          tplSel._items = items;
          var opts = '<option value="">— Escribir comando manualmente —</option>';
          items.forEach(function (t, i) {
            opts +=
              '<option value="' + i + '">' +
              tpModal.escapeHtml(t.title) +
              (t.lock_action === want ? " ✓" : "") +
              "</option>";
          });
          tplSel.innerHTML = opts;
          // Auto-seleccionar cuando hay exactamente una candidata.
          if (items.length === 1) {
            tplSel.value = "0";
            cmdInput.value = items[0].message || "";
          }
        })
        .catch(fallbackOption);
    } else {
      fallbackOption();
    }
    tplSel.addEventListener("change", function () {
      var items = tplSel._items || [];
      var idx = tplSel.value;
      if (idx !== "" && items[idx]) cmdInput.value = items[idx].message || "";
    });
  }

  function openLockHistory(btn) {
    var card = btn.closest("[data-tp-locking]");
    if (!card || !window.tpModal) return;
    var url = card.getAttribute("data-history-url");
    var dlg = tpModal.open({
      title: tpT('ui.lock_history'),
      size: "md",
      body:
        '<div style="margin-bottom:10px;"><select class="tp-input" data-lock-period style="width:auto;">' +
        '<option value="today">' + tpT('front.today') + '</option><option value="yesterday">' + tpT('front.yesterday') + '</option>' +
        '<option value="this_week">' + tpT('ui.this_week') + '</option><option value="last_week">' + tpT('ui.lit_last_week') + '</option></select></div>' +
        '<div data-lock-hist><div class="tp-text-muted" style="padding:12px;">' + tpT('ui.loading_ellipsis') + '</div></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
    });
    // Paginado: el backend manda 50 por pagina; "Cargar mas" anexa filas.
    var pagina = 1, filas = [];
    function load(period, mas) {
      var box = dlg.querySelector("[data-lock-hist]");
      if (!mas) { pagina = 1; filas = []; box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">' + tpT('ui.loading_ellipsis') + '</div>'; }
      fetch(url + "?period=" + encodeURIComponent(period) + "&page=" + pagina, {
        credentials: "same-origin",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (!res || !res.ok) {
            box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">' + tpT('ui.load_failed') + '</div>';
            return;
          }
          filas = filas.concat(res.items || []);
          var items = filas;
          if (!items.length) {
            box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">' + tpT('ui.no_records_in_period') + '</div>';
            return;
          }
          var hayMas = res.pages && pagina < res.pages;
          box.innerHTML =
            '<table class="tp-lock-hist"><thead><tr><th>' + tpT('ui.start') + '</th><th>' + tpT('ui.duration') + '</th><th>' + tpT('front.location') + '</th></tr></thead><tbody>' +
            items
              .map(function (it) {
                var loc =
                  it.lat != null
                    ? '<a href="https://maps.google.com/?q=' +
                      it.lat +
                      "," +
                      it.lng +
                      '" target="_blank" rel="noopener">' +
                      Number(it.lat).toFixed(5) +
                      ", " +
                      Number(it.lng).toFixed(5) +
                      "</a>"
                    : "—";
                return (
                  "<tr><td>" +
                  tpModal.escapeHtml(it.time || "") +
                  "</td><td>" +
                  tpModal.escapeHtml(it.duration || "") +
                  "</td><td>" +
                  loc +
                  "</td></tr>"
                );
              })
              .join("") +
            "</tbody></table>" +
            (hayMas
              ? '<div style="text-align:center;padding:8px;"><button type="button" class="tp-btn tp-btn--ghost tp-btn--sm" data-lock-more>' +
                tpT('ui.lock_history_load_more') + " (" + items.length + "/" + res.total + ")</button></div>"
              : "");
          var more = box.querySelector("[data-lock-more]");
          if (more) more.addEventListener("click", function () { pagina++; load(period, true); });
        })
        .catch(function () {
          box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">' + tpT('ui.network_error') + '</div>';
        });
    }
    var sel = dlg.querySelector("[data-lock-period]");
    if (sel) sel.addEventListener("change", function () { load(sel.value); });
    load("today");
  }

  // -----------------------------------------------------------
  // Herramientas de mapa (menú "Herramientas" del topbar): Regla, Mostrar punto,
  // Buscar dirección — equivalentes a app.ruler/showPoint/showAddress del legacy.
  // -----------------------------------------------------------
  var rulerState = { on: false, pts: [], layer: null };
  var rulerClickBound = false;
  var toolMarker = null;

  function rulerRedraw() {
    if (rulerState.layer) {
      state.map.removeLayer(rulerState.layer);
      rulerState.layer = null;
    }
    if (!rulerState.pts.length) return;
    var g = L.layerGroup();
    if (rulerState.pts.length > 1)
      g.addLayer(L.polyline(rulerState.pts, { color: "#0f172a", weight: 2, dashArray: "5 6", opacity: 0.85 }));
    var acc = 0;
    rulerState.pts.forEach(function (pt, i) {
      if (i > 0) acc += rulerState.pts[i - 1].distanceTo(pt);
      g.addLayer(
        L.marker(pt, {
          icon: L.divIcon({
            className: "tp-ruler-ldiv",
            html: '<span class="tp-ruler-chip">' + (acc / 1000).toFixed(2) + " km</span>",
            iconSize: [0, 0],
            iconAnchor: [-10, 10],
          }),
        }),
      );
    });
    rulerState.layer = g.addTo(state.map);
  }
  function rulerToggle() {
    if (!state.map) return;
    if (!rulerClickBound) {
      state.map.on("click", function (e) {
        if (!rulerState.on) return;
        rulerState.pts.push(e.latlng);
        rulerRedraw();
      });
      rulerClickBound = true;
    }
    rulerState.on = !rulerState.on;
    state.map.getContainer().style.cursor = rulerState.on ? "crosshair" : "";
    rulerState.pts = [];
    rulerRedraw();
    if (rulerState.on && window.tpModal)
      tpModal.toast(tpT('ui.ruler_help'), "info");
  }
  function placeToolMarker(ll) {
    if (!state.map) return;
    if (toolMarker) state.map.removeLayer(toolMarker);
    toolMarker = L.marker(ll).addTo(state.map);
    state.map.setView(ll, 16);
  }
  function toolShowPoint() {
    if (!window.tpModal) return;
    tpModal.open({
      title: tpT('ui.show_point'),
      size: "sm",
      body:
        '<div class="tp-scope"><label class="tp-field__label">' + tpT('front.latitude') + '</label>' +
        '<input type="text" class="tp-input" data-pt-lat placeholder="-8.10610" style="width:100%;margin:4px 0 10px;">' +
        '<label class="tp-field__label">' + tpT('front.longitude') + '</label>' +
        '<input type="text" class="tp-input" data-pt-lng placeholder="-79.02080" style="width:100%;margin-top:4px;">' +
        '<div data-pt-err style="display:none;margin-top:10px;color:#ef4444;font-size:13px;"></div></div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('show'),
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            var lat = parseFloat((dlg.querySelector("[data-pt-lat]") || {}).value);
            var lng = parseFloat((dlg.querySelector("[data-pt-lng]") || {}).value);
            var err = dlg.querySelector("[data-pt-err]");
            if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
              if (err) {
                err.textContent = tpT('ui.invalid_coordinates');
                err.style.display = "block";
              }
              return false;
            }
            placeToolMarker([lat, lng]);
            tpModal.close(dlg);
            return false;
          },
        },
      ],
    });
  }
  function toolSearchAddress(url) {
    if (!window.tpModal || !url) return;
    var dlg = tpModal.open({
      title: tpT('ui.search_address'),
      size: "sm",
      body:
        '<div class="tp-scope"><label class="tp-field__label">' + tpT('front.address') + '</label>' +
        '<input type="text" class="tp-input" data-adr-q placeholder="' + tpT('ui.address_example') + '" style="width:100%;margin-top:4px;">' +
        '<div data-adr-err style="display:none;margin-top:10px;color:#ef4444;font-size:13px;"></div></div>',
      buttons: [
        { label: tpT('global.cancel'), variant: "ghost", close: true },
        {
          label: tpT('ui.search_verb'),
          variant: "primary",
          close: false,
          onClick: function (d) {
            var q = ((d.querySelector("[data-adr-q]") || {}).value || "").trim();
            var err = d.querySelector("[data-adr-err]");
            if (!q) return false;
            if (err) err.style.display = "none";
            tpModal.setBusy(d, true);
            tpModal
              .fetchJSON(url + "?q=" + encodeURIComponent(q), { method: "GET" })
              .then(function (res) {
                tpModal.setBusy(d, false);
                var loc = res.data && res.data.status == 1 ? res.data.location : null;
                if (!loc || loc.lat == null) {
                  if (err) {
                    err.textContent = (res.data && res.data.error) || tpT('ui.address_not_found');
                    err.style.display = "block";
                  }
                  return;
                }
                placeToolMarker([parseFloat(loc.lat), parseFloat(loc.lng)]);
                tpModal.close(d);
              })
              .catch(function () {
                tpModal.setBusy(d, false);
                if (err) {
                  err.textContent = tpT('ui.network_error');
                  err.style.display = "block";
                }
              });
            return false;
          },
        },
      ],
    });
    var input = dlg.querySelector("[data-adr-q]");
    if (input) input.focus();
  }
  window.tpLiveTools = { ruler: rulerToggle, showPoint: toolShowPoint, searchAddress: toolSearchAddress };

  function groupTitle(gid) {
    if (!gid) return tpT('front.ungrouped');
    for (var i = 0; i < state.groups.length; i++)
      if (state.groups[i].id === gid) return state.groups[i].title;
    return tpT('front.group') + gid;
  }
  // Badge del estado de vehículo (label corto + color). Compartido por la fila
  // del rail, la etiqueta del marcador y el drawer. vs = {name,label,color}.
  function vehicleStateBadge(vs, extraStyle) {
    if (!vs || !vs.label) return "";
    return (
      '<span class="tp-vs-badge" style="background:' + (vs.color || "#64748b") + ";" + (extraStyle || "") + '" title="' +
      esc(vs.name || vs.label) + '">' + esc(vs.label) + "</span>"
    );
  }

  function rowTemplate(d) {
    var status = classify(d);
    var speedNum = Math.round(parseFloat(d.speed) || 0);
    var speedTxt = d.speed_text || speedNum + " km/h";
    // Dos cosas distintas, y conviene no mezclarlas:
    //   active  = casilla del sidebar -> se dibuja en MI mapa
    //   enabled = el equipo esta de alta (devices.active)
    var active = d.active !== false;
    var enabled = d.enabled !== false;
    // 2ª línea = fecha de transmisión / estado (paridad legacy Device::time):
    // el backend ya resuelve "Desactivado" / "No conectado" / SIM|plan vencido /
    // fecha formateada. Solo falta pintarlo (antes mostrábamos la placa).
    var ts = Math.max(parseInt(d.timestamp, 10) || 0, parseInt(d.acktimestamp, 10) || 0);
    var metaTxt = d.time || d.plate_number || d.imei || "";
    var metaIsStatus = !enabled || active === false || ts === 0; // estado, no fecha
    // Acento lateral con el color de estado real del device (getStatusColor),
    // salvo que el vehiculo tenga color por velocidad: ese manda, igual que en
    // el marcador, para que la fila y la etiqueta del mapa no digan cosas
    // distintas del mismo vehiculo. markerColor() sale en el primer if cuando
    // el vehiculo no usa la opcion, asi que no cuesta nada en los demas.
    // La fila dice SIEMPRE el estado; el color por velocidad se queda en el
    // marcador del mapa, que es lo que nombra el ajuste. Mezclarlos daba dos
    // verdes distintos en la lista para lo que se lee como el mismo estado:
    // el de la banda 0-40 (#22c55e) y el de "movimiento" (#008000).
    var colEstado = statusVar(d.icon_color);
    var colEstadoInk = statusVar(d.icon_color, true);
    var accent = colEstado || "";
    // Candado (solo si el device tiene sensor blocked): rojo=bloqueado, verde=libre.
    var lockIcon = "";
    if (d.blocked === true || d.blocked === false) {
      lockIcon =
        '<span class="tp-lock-ico ' +
        (d.blocked ? "tp-lock-ico--on" : "tp-lock-ico--off") +
        '" title="' +
        (d.blocked ? tpT('ui.engine_blocked') : tpT('ui.engine_unblocked')) +
        '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>';
    }
    // Motor (paridad legacy detect_engine): solo si hay detección de ignición
    // por SENSOR → engine_status es bool; con GPS es null y no se muestra.
    // A color = encendido, en grises = apagado (acc.svg / acc_off.svg).
    var engineIcon = "";
    if (d.engine_status === true || d.engine_status === false) {
      engineIcon =
        '<span class="tp-eng-ico ' +
        (d.engine_status ? "tp-eng-ico--on" : "tp-eng-ico--off") +
        '" title="' +
        // El dibujo lo pone el CSS con background-image, igual que el legacy
        // de esta marca (.on/.off i.icon.detect_engine -> acc.svg / acc_off.svg).
        (d.engine_status ? tpT('ui.engine_on') : tpT('ui.engine_off')) +
        '"></span>';
    }
    // Badge de estado de vehículo (tipo con estados: VACÍO/CARGADO…) con su color.
    var stateBadge = vehicleStateBadge(d.vehicle_state);
    return (
      // El legacy marca en la lista el vehiculo seleccionado (devices.select
      // -> addClass('active')). La clase se pone aqui tambien, no solo al
      // pulsar, para que sobreviva a un repintado de la lista.
      '<div class="tp-live__row' +
      (colEstado ? " tp-live__row--status" : "") +
      (state.selectedId === d.id ? " is-selected" : "") +
      // Oculto en el mapa (user_device_pivot.active = 0). Se sigue listando,
      // como en el legacy, pero marcado: si no, la unica pista es la casilla
      // y con cientos de filas no se ve.
      (active ? "" : " is-map-hidden") +
      (enabled ? "" : " is-disabled") +
      '" data-id="' +
      d.id +
      '" data-status="' +
      status +
      '"' +
      (accent
        ? ' style="--tp-accent:' + accent +
          ";--tp-status:" + colEstado + ";--tp-status-ink:" + colEstadoInk + '"'
        : "") +
      ' role="option">' +
      '<label class="tp-live__row-active" title="' +
      (active ? tpT('ui.hide_from_map') : tpT('ui.show_on_map')) +
      '"><input type="checkbox" data-id="' +
      d.id +
      '" aria-label="' + esc(tpT('ui.show_on_map') + ': ' + (d.name || "#" + d.id)) + '"' +
      (active ? " checked" : "") +
      "></label>" +
      // El legacy pone el nombre del estado en el title del indicador.
      '<span class="tp-live__row-dot" title="' +
      esc(STATUS_LABELS[d.online] || d.online || "") +
      '"></span>' +
      '<div class="tp-live__row-main"><div class="tp-live__row-name"><span class="tp-live__row-nametext">' +
      esc(d.name || "#" + d.id) +
      "</span>" +
      lockIcon +
      engineIcon +
      stateBadge +
      '</div><div class="tp-live__row-meta' +
      (metaIsStatus ? " tp-live__row-meta--status" : "") +
      '">' +
      esc(metaTxt) +
      "</div></div>" +
      '<div class="tp-live__row-end">' +
      '<span class="tp-live__row-speed' +
      (speedNum === 0 ? " tp-live__row-speed--zero" : "") +
      '">' +
      esc(speedTxt) +
      '</span><span class="tp-live__row-time" title="' + tpT('ui.last_signal') + '">' +
      // De baja: el legacy escribe "Desactivado" en la 2ª linea y NO muestra
      // la ultima transmision. Aqui igual, o se leerian dos cosas que se
      // contradicen (desactivado + "hace 3 dias").
      //
      // ts y no d.acktimestamp || d.timestamp: acktimestamp manda aunque sea
      // mas viejo y suele ir dias por detras, asi que la fila mostraba la
      // fecha de hoy y al lado "2d". El legacy usa max() de los dos
      // (Device::getTime, getLastConnectTimestamp).
      (enabled ? fmtTimeAgo(ts) : "") +
      "</span>" +
      // Vencido y con planes (Objects/item.blade.php:15-25 del legacy).
      (d.expired && d.plans
        ? '<button type="button" class="tp-live__row-upgrade" data-upgrade="' + d.id + '">' + tpT('front.upgrade') + "</button>"
        : "") +
      "</div>" +
      '<button type="button" class="tp-live__row-menu" data-id="' +
      d.id +
      '" title="' + tpT('ui.action') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg></button>' +
      "</div>"
    );
  }
  var listEl = document.getElementById("tp-live-list");
  function renderList() {
    if (!listEl) return;
    var sk = listEl.querySelector(".tp-live__skeleton");
    if (sk) sk.remove();
    var empty = root.querySelector(".tp-live__empty");
    if (!state.filtered.length) {
      listEl.innerHTML = "";
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    var html = "";
    if (state.grouped) {
      var byG = {};
      state.filtered.forEach(function (d) {
        var g = d.group_id || 0;
        (byG[g] = byG[g] || []).push(d);
      });
      // "Sin grupo" SIEMPRE primero (paridad legacy), luego grupos por título
      var order = [];
      if (byG[0]) order.push(0);
      state.groups.forEach(function (g) {
        if (byG[g.id] && order.indexOf(g.id) === -1) order.push(g.id);
      });
      Object.keys(byG).forEach(function (k) {
        var g = parseInt(k, 10);
        if (order.indexOf(g) === -1) order.push(g);
      });
      order.forEach(function (g) {
        var collapsed = !!state.collapsedGroups[g];

        // Kebab de grupo (estilo referencia): "Sin grupo" ofrece crear;
        // los grupos reales además editar/eliminar.
        var tools = "";
        if (CAN_GROUPS) {
          var items =
            '<button type="button" role="menuitem" data-dg-new="1">' +
            '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> ' + tpT('ui.add_new') + '</button>';
          if (g !== 0) {
            items +=
              '<button type="button" role="menuitem" data-dg-edit="' + g + '">' +
              '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> ' + tpT('global.edit') + '</button>' +
              '<button type="button" role="menuitem" class="is-danger" data-dg-del="' + g + '">' +
              '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> ' + tpT('global.delete') + '</button>';
          }
          tools =
            '<div class="tp-svc-kebab" style="margin-left:auto;">' +
            '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="' + tpT('ui.lit_actions') + '">' +
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
            "</button>" +
            '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>";
        }

        // Casilla de visibilidad del grupo entero (legacy groups.blade:
        // multiCheckbox → deviceChangeActive {group_id}). Mira TODOS los
        // equipos del grupo, no solo los que pasan el filtro.
        var delGrupo = state.devices.filter(function (x) {
          return (x.group_id || 0) === g;
        });
        var visibles = delGrupo.filter(function (x) {
          return x.active !== false;
        }).length;
        var casilla =
          '<label class="tp-live__group-active" title="' + esc(tpT("ui.show_on_map") || "") + '">' +
          '<input type="checkbox" data-gid-active="' + g + '"' +
          (visibles === delGrupo.length && delGrupo.length ? " checked" : "") +
          (visibles > 0 && visibles < delGrupo.length ? ' data-indeterminate="1"' : "") +
          ' aria-label="' + esc(groupTitle(g)) + '"></label>';

        html +=
          '<div class="tp-live__group-box"' +
          (collapsed ? ' data-collapsed="1"' : "") +
          '><div class="tp-live__group" data-gid="' +
          g +
          '"' +
          (collapsed ? ' data-collapsed="1"' : "") +
          ">" + casilla + '<svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg><span class="tp-live__group-title">' +
          esc(groupTitle(g)) +
          '</span><span class="tp-live__group-count">' +
          byG[g].length +
          "</span>" +
          tools +
          "</div>";
        if (!collapsed) html += '<div class="tp-live__group-items">' + byG[g].map(rowTemplate).join("") + "</div>";
        html += "</div>";
      });
    } else {
      html = state.filtered.map(rowTemplate).join("");
    }
    listEl.innerHTML = html;
    Array.prototype.forEach.call(listEl.querySelectorAll("[data-indeterminate]"), function (cb) {
      cb.indeterminate = true;
    });
  }

  // -----------------------------------------------------------
  // Kebab de acciones (server-rendered, /actions)
  // -----------------------------------------------------------
  // Menú de acciones por device: SIN AJAX. Los permisos ya vienen resueltos
  // en el <template id="tp-row-menu-tpl"> (render de sesión) y los flags por
  // device (vt/chat/sim/plans/app_uuid/general/video) viajan en liveData.
  // Aquí solo se clona el template, se filtra por data-need y se sustituyen
  // los placeholders __ID__/__IMEI__ → apertura instantánea.
  function buildRowMenu(id) {
    var tpl = document.getElementById("tp-row-menu-tpl");
    if (!tpl || !tpl.content || !tpl.content.firstElementChild) return null;
    var d = null;
    for (var i = 0; i < state.devices.length; i++) {
      if (state.devices[i].id === id) {
        d = state.devices[i];
        break;
      }
    }
    var node = tpl.content.firstElementChild.cloneNode(true);
    node.setAttribute("data-device-id", id);
    node.querySelectorAll("[data-need]").forEach(function (el) {
      var need = el.getAttribute("data-need");
      var ok =
        need === "sim" ? !!(d && d.sim) : !!(d && d[need]);
      if (!ok) el.remove();
    });
    node.querySelectorAll("[data-url]").forEach(function (el) {
      el.setAttribute(
        "data-url",
        el
          .getAttribute("data-url")
          .replace(/__ID__/g, id)
          .replace(/__IMEI__/g, (d && d.imei) || ""),
      );
    });
    return node;
  }

  /**
   * Planes del equipo («Upgrade»): paridad con Objects/item.blade.php y
   * Frontend/DevicePlans. Con custom_device_add el servidor devuelve el paso
   * «plan» del registro y se navega allí, como el enlace del legacy.
   */
  function openDevicePlans(id) {
    if (!window.tpModal) return;
    tpModal.fetchJSON("/ui/v2/api/devices/" + id + "/plans").then(function (res) {
      var d = res && res.data;
      if (!res.ok || !d || !d.ok) {
        tpModal.toast((d && d.message) || tpT('ui.not_available'), "error");
        return;
      }
      if (d.redirect) { location.href = d.redirect; return; }

      var planCard = function (p) {
        if (p.template) return '<div class="tp-plan">' + p.template + "</div>";
        return (
          '<div class="tp-plan">' +
          '<div class="tp-plan__title">' + esc(p.title) + "</div>" +
          '<dl class="tp-plan__dl"><dt>' + tpT('front.duration') + "</dt><dd>" + esc(p.duration) + "</dd>" +
          "<dt>" + tpT('global.price') + "</dt><dd>" + esc(p.price) + "</dd></dl>" +
          '<a class="tp-btn tp-btn--primary tp-btn--block" href="' + esc(p.url) + '" target="_blank" rel="noopener">' +
          tpT('front.upgrade') + "</a></div>"
        );
      };
      var grid = function (g) {
        return '<div class="tp-plans">' + g.plans.map(planCard).join("") + "</div>";
      };
      var groups = d.groups || [];
      var head =
        '<p class="tp-text-sm tp-text-muted" style="margin:0 0 12px;">' + esc(d.device.name) +
        (d.expiration ? " · " + esc(tpT('ui.device_plans_expires', { date: tpFmt.datetime(d.expiration) })) : "") + "</p>";

      var dlg = tpModal.open({
        title: d.title,
        size: "lg",
        body: '<div data-plans>' + head + (groups.length
          ? (groups.length > 1 ? "" : grid(groups[0]))
          : '<div class="tp-empty">' + tpT('front.device_plans_no_data') + "</div>") + "</div>",
        buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      });
      // Varios tipos de duración (main_settings.group_device_plans): una pestaña
      // por tipo, como las nav-tabs del legacy.
      if (dlg && groups.length > 1) {
        var host = document.createElement("div");
        dlg.querySelector("[data-plans]").appendChild(host);
        tpModal.tabs(host, groups.map(function (g) {
          return { key: g.key, label: g.title, html: grid(g) };
        }));
      }
    });
  }

  /** Restablecer UUID de la app (legacy devices.do_reset_app_uuid → PUT devices/reset_app_uuid). */
  function resetAppUuid(id) {
    if (!window.tpModal) return;
    tpModal
      .confirm({ title: tpT('front.reset_app_uuid'), message: tpT('ui.reset_app_uuid_confirm'), confirmLabel: tpT('global.confirm') })
      .then(function (ok) {
        if (!ok) return;
        tpModal.fetchJSON("/devices/reset_app_uuid/" + id, { method: "PUT" }).then(function (res) {
          if (res.ok && res.data && res.data.status) {
            tpModal.toast(tpT('global.success'), "success");
            fetchDevices(true);
          } else tpModal.errorToast ? tpModal.errorToast(res) : tpModal.toast(tpT('ui.update_failed'), "error");
        });
      });
  }

  /**
   * Historial de cambios del vehiculo: quien toco que y cuando.
   *
   * Incluye lo que le pasa a sus partes —sensores, servicios,
   * mantenimientos—, no solo a la ficha, y deja fuera los inicios de sesion,
   * que son el 90% del registro y no son cambios.
   */
  function abrirHistorialCambios(id) {
    if (!window.tpModal) return;

    var dlg = tpModal.open({
      title: tpT('ui.change_history'),
      size: "xl",
      body: '<div data-hist><p class="tp-text-sm tp-text-muted">' + tpT('ui.loading_ellipsis') + '</p></div>',
      buttons: [{ label: tpT('global.close'), variant: "ghost", close: true }],
      onMount: function (d) {
        var host = d.querySelector("[data-hist]");

        tpModal
          .fetchJSON("/ui/v2/api/devices/" + id + "/changes", { method: "GET" })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<p class="tp-text-sm tp-text-error">' + tpT('ui.load_failed') + '</p>';
              return;
            }

            var items = res.data.items || [];

            if (!items.length) {
              host.innerHTML =
                '<p class="tp-text-sm tp-text-muted">' + tpT('ui.no_changes_for_vehicle') + '</p>';
              return;
            }

            var accion = { created: tpT('log_created'), updated: tpT('log_updated'), deleted: tpT('log_deleted') };

            host.innerHTML =
              '<div style="max-height:62vh;overflow:auto;"><table class="tp-table"><thead><tr>' +
              "<th>" + tpT('ui.when') + "</th><th>" + tpT('ui.what') + "</th><th>" + tpT('ui.action') + "</th><th>" + tpT('ui.who') + "</th><th>" + tpT('changes') + "</th>" +
              "</tr></thead><tbody>" +
              items
                .map(function (it) {
                  var cambios = (it.changes || []).length
                    ? it.changes
                        .map(function (c) {
                          return (
                            "<div><b>" + esc(c.field) + "</b>: " +
                            '<span class="tp-text-muted">' + (esc(c.before) || "—") + "</span> → " +
                            esc(c.after || "—") + "</div>"
                          );
                        })
                        .join("")
                    : '<span class="tp-text-muted">—</span>';

                  return (
                    "<tr><td>" + esc(it.at || "—") +
                    "</td><td>" + esc(it.what) +
                    "</td><td>" + esc(accion[it.action] || it.action) +
                    "</td><td>" + esc(it.who) +
                    (it.ip ? '<br><span class="tp-text-xs tp-text-muted">' + esc(it.ip) + "</span>" : "") +
                    '</td><td class="tp-text-xs">' + cambios + "</td></tr>"
                  );
                })
                .join("") +
              "</tbody></table></div>";
          })
          .catch(function () {
            host.innerHTML = '<p class="tp-text-sm tp-text-error">' + tpT('ui.network_error') + '</p>';
          });
      },
    });

    return dlg;
  }

  var rowMenu = null;
  function closeRowMenu() {
    if (rowMenu) {
      rowMenu.remove();
      rowMenu = null;
      document.removeEventListener("click", closeRowMenu);
    }
  }
  /**
   * Coloca el menú junto al kebab. Se mide DESPUÉS de montar las opciones,
   * porque la altura depende de cuántas haya: anclando siempre el borde
   * superior al del botón, los vehículos del final de la lista abrían el
   * menú hacia abajo y quedaba cortado por el borde de la ventana.
   */
  function situarRowMenu(btn) {
    if (!rowMenu) return;

    var MARGEN = 8;
    var r = btn.getBoundingClientRect();
    var m = rowMenu.getBoundingClientRect();

    // Si no cabe hacia abajo, se abre hacia arriba anclado al kebab.
    var top = r.top;
    if (top + m.height > window.innerHeight - MARGEN) {
      top = r.bottom - m.height;
    }

    // Y si tampoco cabe hacia arriba (menú más alto que la ventana), se pega
    // al borde superior y se le deja scroll propio.
    if (top < MARGEN) {
      top = MARGEN;
      rowMenu.style.maxHeight = window.innerHeight - MARGEN * 2 + "px";
      rowMenu.style.overflowY = "auto";
    }

    // A la izquierda del kebab antes que salirse por la derecha.
    var left = r.right + 6;
    if (left + m.width > window.innerWidth - MARGEN) {
      left = Math.max(MARGEN, r.left - m.width - 6);
    }

    rowMenu.style.left = Math.round(left) + "px";
    rowMenu.style.top = Math.round(top) + "px";
    rowMenu.style.visibility = "";
  }

  /**
   * Acciones del vehiculo (menu de la lista y atajos del popup). `it` es el
   * boton pulsado, por si la accion lleva datos (data-range del historial).
   */
  function runDeviceAction(act, id, it) {
      if (act === "history") goHistory(id, (it && it.getAttribute("data-range")) || "today");
      else if (act === "route_report") openRouteReport(id);
      else if (act === "follow") openFollow(id);
      else if (act === "device_video" || act === "device_video_recordings") {
        var dVi = null;
        for (var iVi = 0; iVi < state.devices.length; iVi++) {
          if (state.devices[iVi].id === id) { dVi = state.devices[iVi]; break; }
        }
        var recs = act === "device_video_recordings";
        if (window.tpDeviceVideo)
          tpDeviceVideo[recs ? "recordings" : "open"](id, { name: dVi && dVi.name });
      }
      else if (act === "device_media") {
        var dMe = null;
        for (var iMe = 0; iMe < state.devices.length; iMe++) {
          if (state.devices[iMe].id === id) { dMe = state.devices[iMe]; break; }
        }
        if (window.tpDeviceMedia) tpDeviceMedia.open(id, dMe && dMe.name);
      }
      else if (act === "device_config") {
        if (window.tpDeviceConfig) tpDeviceConfig.open(id);
      }
      else if (act === "device_alerts") {
        var dDa = null;
        for (var iDa = 0; iDa < state.devices.length; iDa++) {
          if (state.devices[iDa].id === id) { dDa = state.devices[iDa]; break; }
        }
        if (window.tpDeviceAlerts) tpDeviceAlerts.open(id, dDa && dDa.name);
      }
      else if (act === "changes") {
        abrirHistorialCambios(id);
      }
      else if (act === "route_types") {
        var dRt = null;
        for (var iRt = 0; iRt < state.devices.length; iRt++) {
          if (state.devices[iRt].id === id) { dRt = state.devices[iRt]; break; }
        }
        if (window.tpRouteTypes) tpRouteTypes.open(id, dRt && dRt.name);
      }
      else if (act === "edit") {
        if (window.tpDeviceModal) window.tpDeviceModal.openEdit(id, function () { fetchDevices(true); });
      } else if (act === "send_command") {
        if (window.tpCommands) tpCommands.open(id);
      } else if (act === "share") {
        if (window.tpSharing) tpSharing.openForm(null, { deviceId: id });
      } else if (act === "sharing_list") {
        location.href = "/ui/v2/sharing?device_id=" + id;
      } else if (act === "call_action") {
        if (window.tpCallActions) tpCallActions.open(id);
      } else if (act === "vehicle_state") {
        // Paridad legacy (Objects/item: devices.vehicle_state_form): mismo modal
        // que el botón del panel del vehículo.
        selectDevice(id);
        loadVehicleState(id, true);
      } else if (act === "chat") {
        location.href = "/ui/v2/chat?device=" + id;
      } else if (act === "checklist_qr") {
        if (window.tpChecklists && tpChecklists.qr) tpChecklists.qr(id);
      } else if (act === "device_plans") {
        openDevicePlans(id);
      } else if (act === "reset_app_uuid") {
        resetAppUuid(id);
      } else if (act === "pending" && window.tpModal && tpModal.toast)
        tpModal.toast(tpT('ui.feature_coming_soon'), "info");
  }

  /** Acciones del menu que la cuenta puede usar con este vehiculo (mismos permisos). */
  function accionesDisponibles(id) {
    var frag = buildRowMenu(id);
    var out = {};
    if (frag)
      Array.prototype.forEach.call(frag.querySelectorAll("[data-act]"), function (b) {
        // El texto del boton (ya traducido) sirve de titulo del atajo.
        out[b.getAttribute("data-act")] = (b.textContent || "").replace(/\s+/g, " ").trim() || true;
      });
    return out;
  }

  function openRowMenu(btn, id) {
    closeRowMenu();
    rowMenu = document.createElement("div");
    rowMenu.className = "tp-row-menu";
    rowMenu.style.position = "fixed";
    rowMenu.style.left = "0px";
    rowMenu.style.top = "0px";
    // Oculto mientras se mide: si se pinta en 0,0 se ve dar el salto.
    rowMenu.style.visibility = "hidden";
    document.body.appendChild(rowMenu);
    rowMenu.addEventListener("click", function (e) {
      var it = e.target.closest(".tp-row-menu__item");
      if (!it) return;
      var act = it.getAttribute("data-act");
      closeRowMenu();
      runDeviceAction(act, id, it);
    });
    // Clonado del template: síncrono e instantáneo, sin petición.
    var built = buildRowMenu(id);
    rowMenu.innerHTML = "";
    if (built) rowMenu.appendChild(built);
    else rowMenu.innerHTML = '<div class="tp-row-menu__loading">' + tpT('ui.no_actions') + '</div>';

    situarRowMenu(btn);

    setTimeout(function () {
      document.addEventListener("click", closeRowMenu);
    }, 0);
  }

  function csrf() {
    return (
      (window.Talentus && window.Talentus.csrf) ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ""
    );
  }
  function persistGroupActive(groupId, active) {
    var fd = new FormData();
    fd.append("group_id", groupId);
    fd.append("active", active ? "1" : "0");
    fetch("/devices/change_active", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-TOKEN": csrf(), "X-Requested-With": "XMLHttpRequest" },
    }).catch(function () {});
  }

  function persistActive(id, active) {
    var fd = new FormData();
    fd.append("id", id);
    fd.append("active", active ? "1" : "0");
    fetch("/devices/change_active", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-TOKEN": csrf(), "X-Requested-With": "XMLHttpRequest" },
    }).catch(function () {});
  }

  // ── Grupos de dispositivos: CRUD (patrón grupos de rutas) ──
  // Gestionar grupos NO es lo mismo que crear vehiculos: antes se miraba si
  // existia el boton de "nuevo vehiculo" -perm('devices','edit')- y dejaba sin
  // kebab a quien si puede tocar grupos. El servidor resuelve el flag con la
  // misma autorizacion que piden los endpoints (ver DevicesController).
  var CAN_GROUPS = root.getAttribute("data-can-groups") === "1";
  var GROUPS_BASE = (CFG.devicesUrl || "/ui/v2/api/devices").replace(/\/devices(\?.*)?$/, "/device-groups");

  // Campo "Reenvíos" del modal de grupos: lista de checkboxes de los Forward
  // accesibles (paridad legacy DevicesGroups select forwards[]). Solo se
  // renderiza si el addon/permiso lo habilitan (FWD_CFG.can).
  function forwardsFieldHtml(info) {
    if (!FWD_CFG.can) return "";
    var sel = {};
    ((info && info.forward_ids) || []).forEach(function (id) {
      sel[id] = true;
    });
    var rows = FWD_CFG.items.length
      ? FWD_CFG.items
          .map(function (f) {
            return (
              '<label style="display:flex;align-items:center;gap:8px;padding:5px 2px;font-size:13px;font-weight:600;color:var(--fg-primary,#0f172a);cursor:pointer;">' +
              '<input type="checkbox" class="tp-choice-input" data-dgm-fwd="' + f.id + '"' + (sel[f.id] ? " checked" : "") + ">" +
              '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(f.title) + "</span></label>"
            );
          })
          .join("")
      : '<span class="tp-text-muted" style="font-size:12.5px;">' + tpT('ui.no_forwards_created') + '</span>';
    return (
      '<div class="tp-form__field" style="margin-bottom:12px;"><span class="tp-form__label">' + tpT('front.forwards') + '</span>' +
      '<div style="border:1px solid var(--border-default,#e2e8f0);border-radius:10px;padding:6px 12px;background:var(--surface-raised,#fff);">' +
      rows +
      "</div></div>"
    );
  }

  function openDeviceGroupModal(groupId) {
    if (!window.tpModal) return;
    var isEdit = !!groupId;

    var pre = isEdit
      ? tpModal.fetchJSON(GROUPS_BASE + "/" + groupId + "/items", { method: "GET" }).then(function (r) {
          return r.ok && r.data && r.data.ok ? r.data : null;
        })
      : Promise.resolve(null);

    pre.then(function (info) {
      var selected = {};
      ((info && info.device_ids) || []).forEach(function (id) {
        selected[id] = true;
      });

      var groupTitles = {};
      state.groups.forEach(function (g) {
        groupTitles[g.id] = g.title;
      });
      var byGroup = {};
      state.devices.forEach(function (d) {
        var gid = d.group_id || 0;
        (byGroup[gid] = byGroup[gid] || []).push(d);
      });
      // Orden de secciones: "Sin grupo" primero, luego el orden de la lista
      var groupOrder = [];
      if (byGroup[0]) groupOrder.push(0);
      state.groups.forEach(function (g) {
        if (byGroup[g.id] && groupOrder.indexOf(g.id) === -1) groupOrder.push(g.id);
      });
      Object.keys(byGroup).forEach(function (k) {
        var g = parseInt(k, 10);
        if (groupOrder.indexOf(g) === -1) groupOrder.push(g);
      });

      var dlg = tpModal.open({
        title: tpT('ui.object_groups'),
        size: "lg",
        static: true,
        body:
          '<div class="tp-form">' +
          '<label class="tp-form__field" style="margin-bottom:12px;">' +
          '<span class="tp-form__label">' + tpT('ui.title') + '</span>' +
          '<input type="text" data-dgm-title maxlength="100" value="' + esc((info && info.title) || "") + '" data-tp-field>' +
          "</label>" +
          forwardsFieldHtml(info) +
          '<div class="tp-form__field"><span class="tp-form__label">' + tpT('ui.vehicles') + '</span>' +
          '<div class="tp-group-box" data-dgm-box>' +
          '<div class="tp-group-box__head" style="margin-bottom:0;">' +
          '<span class="tp-search">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>' +
          '<input type="search" data-dgm-search placeholder="' + tpT('front.search') + '" autocomplete="off">' +
          "</span>" +
          '<button type="button" class="tp-link-action" data-dgm-all>' + tpT('ui.select_all_n') + '</button>' +
          '<span class="tp-group-box__sep">|</span>' +
          '<button type="button" class="tp-link-action" data-dgm-none>' + tpT('ui.lit_deselect_all') + '</button>' +
          "</div>" +
          '<div style="height:1px;background:var(--border-default,#e9eef5);margin:12px -14px;"></div>' +
          '<div data-dgm-list></div>' +
          '<div class="tp-pagination" data-dgm-pager></div>' +
          "</div></div></div>",
        buttons: [
          { label: tpT('global.cancel'), variant: "ghost", close: true },
          {
            label: tpT('global.save'),
            variant: "primary",
            close: false,
            onClick: function (dialog) {
              var title = dialog.querySelector("[data-dgm-title]").value.trim();
              if (!title) {
                tpModal.toast(tpT('ui.title_required'), "warning");
                return false;
              }
              var ids = Object.keys(selected).map(Number);
              var payload = { title: title, devices: ids };
              if (FWD_CFG.can) {
                var fwd = [];
                Array.prototype.forEach.call(dialog.querySelectorAll("[data-dgm-fwd]:checked"), function (c) {
                  fwd.push(parseInt(c.getAttribute("data-dgm-fwd"), 10));
                });
                payload.forwards = fwd;
              }
              var url = isEdit ? GROUPS_BASE + "/" + groupId : GROUPS_BASE;
              tpModal.setBusy(dialog, true);
              tpModal
                .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: payload })
                .then(function (res) {
                  tpModal.setBusy(dialog, false);
                  if (!res.ok || !res.data || !res.data.ok) {
                    var errors = (res.data && res.data.errors) || {};
                    var first = Object.keys(errors)[0];
                    tpModal.toast(tpModal.firstError(errors, tpT('ui.save_failed')), "error");
                    return;
                  }
                  tpModal.toast(isEdit ? tpT('ui.group_updated') : tpT('ui.group_created'), "success");
                  tpModal.close(dialog);
                  fetchDevices(true);
                })
                .catch(function () {
                  tpModal.setBusy(dialog, false);
                  tpModal.toast(tpT('ui.network_error'), "error");
                });
              return false;
            },
          },
        ],
      });

      var box = dlg.querySelector("[data-dgm-box]");
      var list = dlg.querySelector("[data-dgm-list]");
      var pager = dlg.querySelector("[data-dgm-pager]");

      // Estado del listado (estilo referencia): secciones-card colapsables,
      // búsqueda por nombre y paginación de filas al pie de la caja.
      var collapsed = {};
      var PAGE = 30;
      var page = 1;
      var q = "";

      function flatVisible() {
        var flat = [];
        groupOrder.forEach(function (gid) {
          byGroup[gid].forEach(function (d) {
            if (!q || (d.name || "").toLowerCase().indexOf(q) >= 0) flat.push({ gid: gid, d: d });
          });
        });
        return flat;
      }

      function rowHtml(gid, d) {
        return (
          '<label data-dgm-row style="display:flex;align-items:center;gap:10px;min-width:0;padding:9px 4px;cursor:pointer;font-size:13px;font-weight:600;color:var(--fg-primary,#0f172a);">' +
          '<input type="checkbox" class="tp-choice-input" data-dgm-dev="' + d.id + '" data-gid="' + gid + '"' +
          (selected[d.id] ? " checked" : "") + ">" +
          '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(d.name || "#" + d.id) + "</span></label>"
        );
      }

      function sectionHtml(sec) {
        var gid = sec.gid;
        var isOpen = q ? true : !collapsed[gid];
        return (
          '<div data-dgm-sec data-gid="' + gid + '" style="border:1px solid var(--border-default,#e2e8f0);border-radius:10px;background:var(--surface-raised,#fff);margin-bottom:10px;overflow:hidden;">' +
          '<div data-dgm-head style="display:flex;align-items:center;gap:12px;padding:12px 14px;cursor:pointer;user-select:none;">' +
          '<span data-dgm-chev style="display:inline-flex;color:var(--fg-secondary,#475569);transition:transform .12s ease;' +
          (isOpen ? "" : "transform:rotate(-90deg);") + '">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>' +
          '<input type="checkbox" class="tp-choice-input" data-dgm-gchk="' + gid + '">' +
          '<span style="font-size:14px;font-weight:700;color:var(--fg-primary,#0f172a);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
          esc(groupTitles[gid] || tpT('front.ungrouped')) + "</span>" +
          "</div>" +
          '<div data-dgm-body style="display:' + (isOpen ? "grid" : "none") +
          ';grid-template-columns:1fr 1fr;gap:0 24px;border-top:1px solid var(--border-default,#e9eef5);padding:5px 14px 9px;">' +
          sec.rows
            .map(function (d) {
              return rowHtml(gid, d);
            })
            .join("") +
          "</div></div>"
        );
      }

      function syncGroupChecks() {
        Array.prototype.forEach.call(list.querySelectorAll("[data-dgm-gchk]"), function (gc) {
          var gid = parseInt(gc.getAttribute("data-dgm-gchk"), 10);
          var devs = byGroup[gid] || [];
          var on = devs.filter(function (d) {
            return selected[d.id];
          }).length;
          gc.checked = on > 0 && on === devs.length;
          gc.indeterminate = on > 0 && on < devs.length;
        });
      }

      function renderPager(pages) {
        if (pages <= 1) {
          pager.innerHTML = "";
          pager.style.display = "none";
          return;
        }
        pager.style.display = "";
        var from = Math.max(1, Math.min(page - 2, pages - 4));
        var to = Math.min(pages, from + 4);
        var html = '<button type="button" data-dgm-page="' + (page - 1) + '"' + (page <= 1 ? " disabled" : "") + ">&lsaquo;</button>";
        for (var p = from; p <= to; p++) {
          html += '<button type="button" data-dgm-page="' + p + '"' + (p === page ? ' class="is-current"' : "") + ">" + p + "</button>";
        }
        html += '<button type="button" data-dgm-page="' + (page + 1) + '"' + (page >= pages ? " disabled" : "") + ">&rsaquo;</button>";
        pager.innerHTML = html;
      }

      function renderList() {
        var flat = flatVisible();
        var pages = Math.max(1, Math.ceil(flat.length / PAGE));
        if (page > pages) page = pages;
        var slice = flat.slice((page - 1) * PAGE, page * PAGE);
        var secs = [];
        slice.forEach(function (it) {
          var last = secs[secs.length - 1];
          if (!last || last.gid !== it.gid) {
            last = { gid: it.gid, rows: [] };
            secs.push(last);
          }
          last.rows.push(it.d);
        });
        list.innerHTML =
          secs.map(sectionHtml).join("") ||
          '<div style="padding:12px 4px;font-size:13px;color:var(--fg-muted,#94a3b8);">' + tpT('ui.no_results') + '</div>';
        renderPager(pages);
        syncGroupChecks();
      }
      renderList();

      // Colapso por sección: click en la cabecera (excepto el checkbox)
      list.addEventListener("click", function (e) {
        if (e.target.closest("[data-dgm-gchk]")) return;
        var head = e.target.closest("[data-dgm-head]");
        if (!head) return;
        var gid = head.closest("[data-dgm-sec]").getAttribute("data-gid");
        collapsed[gid] = !collapsed[gid];
        renderList();
      });

      list.addEventListener("change", function (e) {
        var dev = e.target.closest("[data-dgm-dev]");
        if (dev) {
          var did = +dev.getAttribute("data-dgm-dev");
          if (dev.checked) selected[did] = true;
          else delete selected[did];
          syncGroupChecks();
          return;
        }
        var gc = e.target.closest("[data-dgm-gchk]");
        if (gc) {
          // Marca/desmarca TODO el grupo (incluidas filas en otras páginas)
          var gid = parseInt(gc.getAttribute("data-dgm-gchk"), 10);
          (byGroup[gid] || []).forEach(function (d) {
            if (gc.checked) selected[d.id] = true;
            else delete selected[d.id];
          });
          renderList();
        }
      });

      pager.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-dgm-page]");
        if (!btn || btn.disabled) return;
        page = parseInt(btn.getAttribute("data-dgm-page"), 10);
        renderList();
      });

      box.querySelector("[data-dgm-all]").addEventListener("click", function () {
        flatVisible().forEach(function (it) {
          selected[it.d.id] = true;
        });
        renderList();
      });
      box.querySelector("[data-dgm-none]").addEventListener("click", function () {
        flatVisible().forEach(function (it) {
          delete selected[it.d.id];
        });
        renderList();
      });

      var searchEl = box.querySelector("[data-dgm-search]");
      searchEl.addEventListener("input", function () {
        q = searchEl.value.trim().toLowerCase();
        page = 1;
        renderList();
      });
    });
  }

  function delDeviceGroup(groupId) {
    tpModal
      .confirm({
        title: tpT('ui.delete_group'),
        message: tpT('ui.confirm_delete_group_vehicles'),
        danger: true,
        confirmLabel: tpT('global.delete'),
      })
      .then(function (okGo) {
        if (!okGo) return;
        tpModal.fetchJSON(GROUPS_BASE + "/" + groupId, { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) {
            tpModal.toast(tpT('ui.group_deleted'), "success");
            fetchDevices(true);
          } else {
            tpModal.toast(tpT('ui.delete_failed'), "error");
          }
        });
      });
  }

  if (listEl) {
    listEl.addEventListener("click", function (e) {
      var up = e.target.closest("[data-upgrade]");
      if (up) {
        e.stopPropagation();
        openDevicePlans(parseInt(up.getAttribute("data-upgrade"), 10));
        return;
      }
      // Acciones del kebab de grupo (antes del toggle de colapso)
      var dgNew = e.target.closest("[data-dg-new]");
      if (dgNew) {
        e.stopPropagation();
        openDeviceGroupModal(null);
        return;
      }
      var dgEdit = e.target.closest("[data-dg-edit]");
      if (dgEdit) {
        e.stopPropagation();
        openDeviceGroupModal(parseInt(dgEdit.getAttribute("data-dg-edit"), 10));
        return;
      }
      var dgDel = e.target.closest("[data-dg-del]");
      if (dgDel) {
        e.stopPropagation();
        delDeviceGroup(parseInt(dgDel.getAttribute("data-dg-del"), 10));
        return;
      }
      // El botón ⋮ lo maneja el delegado global de tp-services (no colapsar)
      if (e.target.closest(".tp-svc-kebab")) return;

      if (e.target.closest(".tp-live__group-active")) return;
      var gh = e.target.closest(".tp-live__group");
      if (gh) {
        var g = parseInt(gh.getAttribute("data-gid"), 10);
        state.collapsedGroups[g] = !state.collapsedGroups[g];
        renderList();
        // Se recuerda en el servidor, como el legacy (devices.toggleGroup →
        // objects/change_group_status): el mismo dato para las dos UIs.
        if (CFG.groupToggleUrl)
          fetch(CFG.groupToggleUrl + (CFG.groupToggleUrl.indexOf("?") === -1 ? "?" : "&") + "id=" + g, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
          }).catch(function () {});
        return;
      }
      if (e.target.closest(".tp-live__row-active")) return;
      var mb = e.target.closest(".tp-live__row-menu");
      if (mb) {
        e.stopPropagation();
        openRowMenu(mb, parseInt(mb.getAttribute("data-id"), 10));
        return;
      }
      var row = e.target.closest(".tp-live__row");
      if (!row) return;
      var rid = parseInt(row.getAttribute("data-id"), 10);
      // Paridad legacy (devices.select): un clic selecciona y otro sobre el
      // mismo vehiculo lo deselecciona y vuelve a encuadrar la flota.
      if (state.selectedId === rid) {
        deselectDevice();
        fitToFleet();
        return;
      }
      selectDevice(rid);
    });
    listEl.addEventListener("change", function (e) {
      var cb = e.target;
      if (cb.matches && cb.matches("[data-gid-active]")) {
        var gid = parseInt(cb.getAttribute("data-gid-active"), 10);
        state.devices.forEach(function (x) {
          if ((x.group_id || 0) === gid) x.active = cb.checked;
        });
        renderList();
        renderMarkers();
        persistGroupActive(gid, cb.checked);
        return;
      }
      if (!cb.matches || !cb.matches('.tp-live__row-active input[type="checkbox"]')) return;
      var id = parseInt(cb.getAttribute("data-id"), 10);
      var d = state.index[id];
      if (!d) return;
      d.active = cb.checked;
      renderMarkers();
      persistActive(id, cb.checked);
    });
  }

  // -----------------------------------------------------------
  // Feed de devices (todas las páginas) + polling de deltas
  // -----------------------------------------------------------
  var statusText = document.getElementById("tp-live-status-text");
  var inFlight = null;
  function commit(rows, total) {
    state.devices = rows;
    state.total = total || rows.length;
    state.index = {};
    var ahora = Date.now();
    rows.forEach(function (d) {
      d._recibido = ahora;
      state.index[d.id] = d;
    });
    recompute();
    renderList();
    renderMarkers();
    updateFollows();
    followSelected();
    if (!state.bootstrapped && state.filtered.length) {
      fitToFleet();
      state.bootstrapped = true;
    }
  }
  // Enfoca un vehículo al abrir /ui/v2/live?device=N (resultado del buscador
  // global). Se ejecuta una sola vez, tras el primer load COMPLETO de la lista
  // (el finally de fetchDevices ya trae todas las páginas en state.index).
  var initialDeviceDone = false;
  function focusInitialDevice() {
    if (initialDeviceDone) return;
    initialDeviceDone = true;
    var id;
    try {
      id = parseInt(new URLSearchParams(location.search).get("device"), 10);
    } catch (e) {
      id = NaN;
    }
    if (id && !isNaN(id) && state.index && state.index[id]) selectDevice(id);
  }

  function fetchDevices(manual) {
    if (inFlight) inFlight.abort();
    var ctrl = new AbortController();
    inFlight = ctrl;
    if (statusText) statusText.textContent = manual ? "Refrescando…" : "Actualizando…";
    var acc = [],
      total = 0;
    function page(p) {
      var sep = CFG.devicesUrl.indexOf("?") === -1 ? "?" : "&";
      return fetch(CFG.devicesUrl + sep + "page=" + p, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        signal: ctrl.signal,
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (j) {
          acc = acc.concat((j && j.data) || []);
          if (j && Array.isArray(j.groups)) {
            state.groups = j.groups;
            if (!state.groupsSeeded) {
              state.groupsSeeded = true;
              j.groups.forEach(function (g) {
                if (g.open === false) state.collapsedGroups[g.id] = true;
              });
              if (j.ungrouped_open === false) state.collapsedGroups[0] = true;
            }
          }
          var pg = (j && j.pagination) || null;
          if (pg && pg.total != null) total = pg.total;
          if (pg && pg.current_page < pg.last_page) {
            // La lista se pinta UNA vez, con todas las páginas (como el
            // legacy). Pintar por página reordenaba la lista en cada tanda
            // (los nombres de la página 2 caen entre los de la 1) y, al
            // refrescar, la recortaba a la primera página y la volvía a
            // llenar. Mientras tanto sigue el esqueleto o la lista anterior.
            if (statusText) statusText.textContent = "Cargando… " + acc.length + "/" + (total || "?");
            var lt = document.getElementById("tp-live-loading-text");
            if (lt) lt.textContent = "Cargando… " + acc.length + "/" + (total || "?");
            return page(pg.current_page + 1);
          }
          commit(acc, total);
          if (statusText)
            statusText.textContent = (total || acc.length) + " " + tpT('ui.vehicles_dot') + " " + new Date().toLocaleTimeString();
        });
    }
    page(1)
      .catch(function (e) {
        if (e.name !== "AbortError" && statusText) statusText.textContent = tpT('ui.load_error');
      })
      .finally(function () {
        inFlight = null;
        // Overlays del mapa: se cargan tras el 1er load completo de devices
        // (mapa ya asentado con su fitBounds) — patrón del legacy.
        seedOverlays();
        // Enfoca el device pedido por ?device=N (buscador global).
        focusInitialDevice();
      });
  }
  // Carga inicial de geocercas/POIs/rutas activas UNA sola vez, cuando el mapa
  // ya está estable (evita el glitch de vectores añadidos durante el fitBounds).
  var overlaysSeeded = false;
  function seedOverlays() {
    if (overlaysSeeded) return;
    overlaysSeeded = true;
    ["geofences", "poi", "routes", "hitos"].forEach(function (key) {
      if (state.toggles[key]) applyToggle(key);
    });
  }
  // Sondeo de cambios. Sigue aunque la pagina no este a la vista, como el
  // legacy (app.check): antes se cortaba con la pestaña oculta y Chrome en
  // Windows da por oculta una ventana tapada por otra, asi que al volver la
  // v2 iba por detras del legacy. El navegador ya espacia los temporizadores
  // de las pestañas ocultas. `polling` evita solapar dos consultas.
  var polling = false;
  function pollUpdates() {
    if (polling) return;
    polling = true;
    fetch(CFG.updatesUrl + "?time=" + encodeURIComponent(state.checkTime || "") + "&_=" + Date.now(), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (res && res.time) state.checkTime = res.time;
        var items = (res && res.items) || [];
        var ahora = Date.now();
        var cambiados = {};
        items.forEach(function (d) {
          d._recibido = ahora;
          cambiados[d.id] = true;
          if (state.index[d.id]) {
            for (var k in d) if (Object.prototype.hasOwnProperty.call(d, k)) state.index[d.id][k] = d[k];
          } else {
            state.index[d.id] = d;
            state.devices.push(d);
          }
        });
        // Equipos que dejaron de transmitir (legacy: checkOfflineTimeout en
        // cada check, con la hora del servidor).
        var vencidos = checkOfflineAll(parseInt(res && res.time, 10) || Math.floor(ahora / 1000));

        if (items.length || vencidos) {
          recompute();
          renderList();
          renderMarkers();
          updateFollows();
          followSelected();
          refreshOpenPopup();
          if (state.autoFit && state.selectedId == null) fitToFleet();
        }

        // La ficha se repinta en cada sondeo: el tiempo detenido corre aunque
        // el equipo no mande nada nuevo (legacy: updateStopDuration).
        if (state.selectedId && state.index[state.selectedId]) {
          fillDrawer(state.index[state.selectedId]);
          // Widgets de abajo (sensores, direccion, eventos, combustible): el
          // legacy los recarga en cada update del equipo (refreshWitgets).
          if (cambiados[state.selectedId]) refreshDrawerWidgets(state.selectedId);
        }
      })
      .catch(function () {})
      .then(function () {
        polling = false;
      });
  }

  // -----------------------------------------------------------
  // Controles + filtros + paneles
  // -----------------------------------------------------------
  function wire() {
    var sf = document.getElementById("tp-live-status-filter");
    if (sf)
      sf.addEventListener("click", function (e) {
        var b = e.target.closest("[data-filter]");
        if (!b) return;
        sf.querySelectorAll("[data-filter]").forEach(function (x) {
          x.setAttribute("aria-selected", "false");
        });
        b.setAttribute("aria-selected", "true");
        state.statusFilter = b.getAttribute("data-filter");
        recompute();
        renderList();
        renderMarkers();
      });
    var search = document.getElementById("tp-live-search");
    var st;
    if (search)
      search.addEventListener("input", function () {
        clearTimeout(st);
        st = setTimeout(function () {
          state.searchQuery = search.value || "";
          recompute();
          renderList();
          renderMarkers();
        }, 120);
      });
    var gt = document.getElementById("tp-live-group-toggle");
    if (gt) {
      var sync = function () {
        gt.setAttribute("aria-pressed", state.grouped ? "true" : "false");
        gt.classList.toggle("is-active", state.grouped);
      };
      sync();
      gt.addEventListener("click", function () {
        state.grouped = !state.grouped;
        try {
          localStorage.setItem("tp_live_grouped", state.grouped ? "1" : "0");
        } catch (e) {}
        sync();
        renderList();
      });
    }
    var refresh = document.getElementById("tp-live-refresh");
    if (refresh) refresh.addEventListener("click", function () { fetchDevices(true); });
    var fit = document.getElementById("tp-live-fit");
    if (fit) fit.addEventListener("click", fitToFleet);
    var zi = document.getElementById("tp-live-zoom-in");
    if (zi) zi.addEventListener("click", function () { state.map.zoomIn(); });
    var zo = document.getElementById("tp-live-zoom-out");
    if (zo) zo.addEventListener("click", function () { state.map.zoomOut(); });
    var fs = document.getElementById("tp-live-fullscreen");
    if (fs)
      fs.addEventListener("click", function () {
        var el = document.getElementById("tp-live-map");
        if (!document.fullscreenElement) (el.requestFullscreen || function () {}).call(el);
        else document.exitFullscreen();
        setTimeout(function () { state.map.invalidateSize(); }, 250);
      });
    var styleBtn = document.getElementById("tp-live-style-toggle");
    var menu = document.getElementById("tp-live-providers-menu");
    if (styleBtn && menu)
      styleBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        if (menu.hidden) {
          // Anclar el menú junto al botón (abre hacia la izquierda, ya que el
          // botón está pegado al borde derecho del mapa).
          menu.style.position = "fixed";
          menu.hidden = false;
          var r = styleBtn.getBoundingClientRect();
          var mw = menu.offsetWidth || 240;
          var mh = menu.offsetHeight || 200;
          menu.style.left = Math.max(8, r.left - mw - 8) + "px";
          menu.style.top = Math.min(Math.max(8, r.top), window.innerHeight - mh - 8) + "px";
        } else {
          menu.hidden = true;
        }
      });
    document.addEventListener("click", function () {
      if (menu) menu.hidden = true;
    });

    // Filtro avanzado (addon.object_filter): abre el modal de filtro.
    var filterBtn = document.getElementById("tp-live-filter");
    if (filterBtn && FILTER_CFG) filterBtn.addEventListener("click", openAdvancedFilter);

    // Menú "Herramientas" del topbar (Regla / Mostrar punto / Buscar dirección).
    var toolsBtn = document.getElementById("tp-tools-btn");
    var toolsMenu = document.getElementById("tp-tools-menu");
    if (toolsBtn && toolsMenu) {
      toolsBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        toolsMenu.hidden = !toolsMenu.hidden;
        toolsBtn.setAttribute("aria-expanded", toolsMenu.hidden ? "false" : "true");
      });
      document.addEventListener("click", function () {
        toolsMenu.hidden = true;
        toolsBtn.setAttribute("aria-expanded", "false");
      });
      toolsMenu.addEventListener("click", function (e) {
        var it = e.target.closest("[data-tp-tool]");
        if (!it) return;
        toolsMenu.hidden = true;
        var tool = it.getAttribute("data-tp-tool");
        if (tool === "ruler") rulerToggle();
        else if (tool === "point") toolShowPoint();
        else if (tool === "address") toolSearchAddress(toolsMenu.getAttribute("data-address-search-url"));
        else if (tool === "silent-devices") {
          if (window.tpSilentDevices) window.tpSilentDevices.open();
        }
        else if (tool === "forwards") {
          if (window.tpForwards) window.tpForwards.open(it.getAttribute("data-forwards-url"));
        }
      });
    }

    // Geolocalización
    var geo = document.getElementById("tp-live-geolocate");
    if (geo)
      geo.addEventListener("click", function () {
        if (!navigator.geolocation) {
          window.tpModal && tpModal.toast && tpModal.toast(tpT('ui.geolocation_unsupported'), "warning");
          return;
        }
        navigator.geolocation.getCurrentPosition(
          function (p) {
            var ll = [p.coords.latitude, p.coords.longitude];
            if (state.geoMarker) state.geoMarker.setLatLng(ll);
            else
              state.geoMarker = L.circleMarker(ll, {
                radius: 7,
                color: "#2e63d9",
                fillColor: "#2e63d9",
                fillOpacity: 0.6,
              }).addTo(state.map);
            state.map.setView(ll, 15);
          },
          function (err) {
            // En orígenes HTTP (no HTTPS) el navegador bloquea la geolocalización.
            var msg =
              err && err.code === 1
                ? location.protocol !== "https:"
                  ? tpT('ui.geolocation_needs_https')
                  : tpT('ui.geolocation_denied')
                : tpT('ui.geolocation_failed');
            window.tpModal && tpModal.toast && tpModal.toast(msg, "warning");
          },
          { enableHighAccuracy: true, timeout: 8000 },
        );
      });
    // Auto-seguir flota
    var af = document.getElementById("tp-live-autofollow");
    if (af)
      af.addEventListener("click", function () {
        setAutoFit(!state.autoFit);
        if (state.autoFit) {
          // Seguir a la flota y a un vehiculo a la vez se pelean: manda la flota.
          state.followSelected = false;
          fitToFleet();
        }
      });

    // Toggles de capa: el estado inicial viene de la DB (MAP_CFG.toggles =
    // user->map_controls) y cada cambio se persiste como en el legacy.
    var cfgToggles = MAP_CFG.toggles || {};
    Array.prototype.forEach.call(root.querySelectorAll(".tp-live__toggle[data-toggle]"), function (btn) {
      var key = btn.getAttribute("data-toggle");
      var initial = Object.prototype.hasOwnProperty.call(cfgToggles, key)
        ? !!cfgToggles[key]
        : btn.getAttribute("aria-pressed") === "true";
      state.toggles[key] = initial;
      btn.setAttribute("aria-pressed", initial ? "true" : "false");
      btn.addEventListener("click", function () {
        state.toggles[key] = !state.toggles[key];
        btn.setAttribute("aria-pressed", state.toggles[key] ? "true" : "false");
        applyToggle(key);
        persistToggle(key, state.toggles[key]);
      });
    });
    // Estado inicial: devices ya; los toggles síncronos (baratos) se difieren
    // un tick para que tpLiveZones/tpLivePois/tpLiveRoutes (scripts cargados
    // después) ya estén definidos. Los OVERLAYS del mapa (geocercas/POIs/rutas)
    // NO se cargan aquí: se difieren a seedOverlays(), que corre tras el primer
    // load COMPLETO de devices — igual que el legacy (app.geofences.load() va
    // dentro del callback de devices.load). Así se añaden a un mapa ya asentado
    // (fitBounds hecho) y no quedan sin dibujar.
    applyToggle("devices");
    setTimeout(function () {
      ["cluster", "names", "tails", "traffic", "aircraft"].forEach(function (key) {
        if (state.toggles[key]) applyToggle(key);
      });
    }, 0);

    // Bottombar (widgets inferiores)
    // Scroll horizontal con la rueda del mouse cuando hay muchos widgets: si el
    // cursor está sobre el cuerpo scrolleable de una card que aún puede scrollear
    // en vertical, se respeta; en caso contrario la rueda desplaza la fila.
    var bbCards = document.getElementById("tp-live-bottombar-cards");
    if (bbCards)
      bbCards.addEventListener(
        "wheel",
        function (e) {
          if (bbCards.scrollWidth <= bbCards.clientWidth) return; // sin overflow
          var body = e.target.closest && e.target.closest(".tp-dw__card-body");
          if (body) {
            var down = e.deltaY > 0 && body.scrollTop + body.clientHeight < body.scrollHeight - 1;
            var up = e.deltaY < 0 && body.scrollTop > 0;
            if (down || up) return; // dejar que la card scrollee en vertical
          }
          var delta = e.deltaY || e.deltaX;
          if (delta) {
            bbCards.scrollLeft += delta;
            e.preventDefault();
          }
        },
        { passive: false },
      );

    // Widget de bloqueo: los botones se inyectan con los widgets del device, así
    // que se delega en el contenedor del bottombar.
    if (bbCards)
      bbCards.addEventListener("click", function (e) {
        var act = e.target.closest("[data-lock-action]");
        if (act) {
          openLockCommand(act);
          return;
        }
        var hist = e.target.closest("[data-lock-history]");
        if (hist) openLockHistory(hist);
      });

    var bbCollapse = document.getElementById("tp-bottombar-collapse");
    if (bbCollapse)
      bbCollapse.addEventListener("click", function () {
        root.toggleAttribute("data-bottombar-collapsed");
        syncBottombarHandle();
      });
    var bbExpand = document.getElementById("tp-bottombar-expand");
    if (bbExpand)
      bbExpand.addEventListener("click", function () {
        root.setAttribute("data-bottombar-open", "true");
        root.removeAttribute("data-bottombar-collapsed");
        syncBottombarHandle();
      });
    var bbConfig = document.getElementById("tp-bottombar-config");
    if (bbConfig)
      bbConfig.addEventListener("click", function () {
        openWidgetsConfig(bbConfig.getAttribute("data-config-url"), bbConfig.getAttribute("data-save-url"));
      });

    // Widget de Servicios: "3 rayitas" → modal LISTA, "+ Añadir servicio" → modal FORM.
    // Modales compartidos en tp-services.js (mismos que usa /ui/v2/maintenance).
    // ── Imagen del vehiculo: subir / borrar ─────────────────────────
    // Paridad con Widgets/image.blade del legacy. Delegado porque el widget
    // llega por AJAX y se repinta al cambiar de vehiculo.
    document.addEventListener("click", function (e) {
      var box = e.target.closest("[data-dw-image]");
      if (!box) return;

      if (e.target.closest("[data-dw-image-upload]")) {
        var file = box.querySelector("[data-dw-image-file]");
        if (file) file.click();
        return;
      }

      if (e.target.closest("[data-dw-image-delete]")) {
        // tpModal.confirm devuelve una Promise; no acepta onConfirm.
        tpModal
          .confirm({
            title: tpT('ui.delete_image'),
            message: tpT('ui.confirm_delete_vehicle_image'),
            confirmLabel: tpT('global.delete'),
            danger: true,
          })
          .then(function (ok) {
            if (!ok) return;
            return tpModal
              .fetchJSON(box.getAttribute("data-destroy"), { method: "DELETE" })
              .then(function (res) {
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast(tpT('ui.image_delete_failed'), "error");
                  return;
                }
                tpModal.toast("Imagen eliminada.", "success");
                if (state.selectedId) loadDrawerWidgets(state.selectedId);
              })
              .catch(function () {
                tpModal.toast(tpT('ui.network_error'), "error");
              });
          });
      }
    });

    document.addEventListener("change", function (e) {
      var file = e.target.closest("[data-dw-image-file]");
      if (!file || !file.files || !file.files[0]) return;

      var box = file.closest("[data-dw-image]");
      var fd = new FormData();
      fd.append("image", file.files[0]);

      tpModal
        .fetchJSON(box.getAttribute("data-store"), { method: "POST", body: fd })
        .then(function (res) {
          file.value = "";
          if (res.status === 422) {
            var errs = (res.data && res.data.errors) || {};
            var first = Object.keys(errs)[0];
            tpModal.toast(tpModal.firstError(errs, tpT('ui.invalid_image')), "error");
            return;
          }
          if (!res.ok || !res.data || !res.data.ok) {
            tpModal.toast(tpT('ui.image_upload_failed'), "error");
            return;
          }
          tpModal.toast("Imagen actualizada.", "success");
          if (state.selectedId) loadDrawerWidgets(state.selectedId);
        })
        .catch(function () {
          file.value = "";
          tpModal.toast(tpT('ui.network_error'), "error");
        });
    });

    // ── Plantillas webhook (addon.widget_template) ──────────────────
    // El boton solo aparece con una plantilla elegida, como el legacy.
    document.addEventListener("change", function (e) {
      var sel = e.target.closest("[data-dw-webhook-select]");
      if (!sel) return;
      var btn = sel.closest("[data-dw-webhook]").querySelector("[data-dw-webhook-send]");
      if (btn) btn.hidden = sel.value === "0" || sel.value === "";
    });

    document.addEventListener("click", function (e) {
      if (!e.target.closest("[data-dw-webhook-send]")) return;
      var box = e.target.closest("[data-dw-webhook]");
      var sel = box.querySelector("[data-dw-webhook-select]");
      if (!sel || sel.value === "0") return;

      tpModal
        .fetchJSON(box.getAttribute("data-url"), {
          method: "POST",
          json: { template_id: sel.value },
        })
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            tpModal.toast(tpT('ui.template_send_failed'), "error");
            return;
          }
          tpModal.toast("Plantilla enviada.", "success");
        })
        .catch(function () {
          tpModal.toast(tpT('ui.network_error'), "error");
        });
    });

    // Delegado en document porque los widgets del drawer se inyectan por AJAX.
    document.addEventListener("click", function (e) {
      var sv = e.target.closest("[data-tp-service]");
      if (!sv) return;
      e.preventDefault();
      if (!window.tpServices) return;
      var deviceId = parseInt(sv.getAttribute("data-device-id"), 10);
      if (!deviceId) return;
      var refresh = function () {
        if (state.selectedId === deviceId) selectDevice(deviceId);
      };
      if (sv.getAttribute("data-tp-service") === "add")
        tpServices.openForm(deviceId, null, refresh);
      else tpServices.openList(deviceId, refresh);
    });

    // "Centrar en mapa" del drawer
    var center = document.getElementById("tp-drawer-center");
    if (center)
      center.addEventListener("click", function () {
        if (state.selectedId) selectDevice(state.selectedId);
      });

    // Cambiador de estado de vehículo (drawer).
    var vsBtn = document.getElementById("tp-drawer-vstate-btn");
    if (vsBtn) vsBtn.addEventListener("click", openVStateModal);

    // Drawer close
    // Colapsar / expandir el panel izquierdo (lista de elementos)
    var fCollapse = document.getElementById("tp-live-filters-collapse");
    var fExpand = document.getElementById("tp-live-filters-expand");
    // Bajo 900px la lista NO se colapsa: sale y entra deslizandose sobre el
    // mapa (data-mobile-filters). Esa regla CSS existia desde siempre pero
    // nada la activaba, asi que en movil no habia forma de ver la lista.
    var movilLista = window.matchMedia("(max-width: 900px)");

    function sincronizaTirador() {
      if (!fExpand) return;

      fExpand.hidden = movilLista.matches
        ? root.getAttribute("data-mobile-filters") === "true"
        : !root.hasAttribute("data-filters-collapsed");
    }

    function cierraLista() {
      if (movilLista.matches) root.removeAttribute("data-mobile-filters");
      else root.setAttribute("data-filters-collapsed", "true");

      sincronizaTirador();
      setTimeout(function () { state.map && state.map.invalidateSize(); }, 250);
    }

    function abreLista() {
      if (movilLista.matches) root.setAttribute("data-mobile-filters", "true");
      else root.removeAttribute("data-filters-collapsed");

      sincronizaTirador();
      setTimeout(function () { state.map && state.map.invalidateSize(); }, 250);
    }

    if (fCollapse) fCollapse.addEventListener("click", cierraLista);
    if (fExpand) fExpand.addEventListener("click", abreLista);

    // Al cruzar el corte, el tirador puede quedarse en el estado que no toca
    if (movilLista.addEventListener) movilLista.addEventListener("change", sincronizaTirador);

    // Con la lista abierta sobre el mapa, Esc la cierra
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && movilLista.matches && root.getAttribute("data-mobile-filters") === "true") {
        cierraLista();
      }
    });

    sincronizaTirador();

    var dc = document.getElementById("tp-drawer-close");
    if (dc) dc.addEventListener("click", deselectDevice);


    // Conmutador de paneles
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__paneltab"), function (t) {
      t.addEventListener("click", function () {
        switchPanel(t.getAttribute("data-panel-tab"));
      });
    });
    // Rail intercept (Live/Geocercas/POIs/Rutas) → cambiar panel sin recargar
    Array.prototype.forEach.call(document.querySelectorAll(".tp-rail a[href]"), function (a) {
      var href = a.getAttribute("href") || "";
      var toPois = href.indexOf("panel=pois") !== -1;
      var toZones = href.indexOf("panel=zones") !== -1;
      var toRoutes = href.indexOf("panel=routes") !== -1;
      var toLive = !toPois && !toZones && !toRoutes && /\/ui\/v2\/live(\?|$)/.test(href);
      if (!toPois && !toZones && !toRoutes && !toLive) return;
      a.addEventListener("click", function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
        e.preventDefault();
        var panel = toPois ? "pois" : toZones ? "zones" : toRoutes ? "routes" : "objects";
        switchPanel(panel);
        try {
          history.replaceState(null, "", panel === "objects" ? "/ui/v2/live" : "/ui/v2/live?panel=" + panel);
        } catch (err) {}
      });
    });
    try {
      var ip = new URLSearchParams(location.search).get("panel");
      if (ip === "pois" || ip === "zones" || ip === "routes") switchPanel(ip);
    } catch (e) {}
  }

  function switchPanel(name) {
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__panel"), function (p) {
      p.hidden = p.getAttribute("data-panel") !== name;
    });
    Array.prototype.forEach.call(document.querySelectorAll(".tp-live__paneltab"), function (t) {
      t.classList.toggle("is-active", t.getAttribute("data-panel-tab") === name);
    });
    // Zonas/POIs/Rutas: render en el mapa Leaflet (módulos propios).
    if (window.tpLiveZones && name === "zones") window.tpLiveZones.activate();
    if (window.tpLivePois && name === "pois") window.tpLivePois.activate();
    if (window.tpLiveRoutes && name === "routes") window.tpLiveRoutes.activate();
    if (name === "objects" && state.map) {
      if (state.selectedId != null) {
        state.followSelected = true;
        centerOnSelected(SELECT_ZOOM);
      } else {
        fitToFleet();
      }
    }
    setRailActive(name);
  }
  // Sincroniza el ítem activo del rail al cambiar de panel SIN recargar
  // (Objetos/Geocercas/POIs/Rutas comparten el mapa de Live).
  function setRailActive(name) {
    var items = document.querySelectorAll(".tp-rail__item");
    Array.prototype.forEach.call(items, function (a) { a.removeAttribute("aria-current"); });
    var sel = name === "zones" ? '.tp-rail__item[href*="panel=zones"]'
      : name === "pois" ? '.tp-rail__item[href*="panel=pois"]'
      : name === "routes" ? '.tp-rail__item[href*="panel=routes"]'
      : '.tp-rail__item[href$="/ui/v2/live"]';
    var el = document.querySelector(sel);
    if (el) el.setAttribute("aria-current", "page");
  }

  // -----------------------------------------------------------
  // API pública para los módulos de paneles (zones/pois en Leaflet)
  // -----------------------------------------------------------
  window.tpLive = {
    map: function () {
      return state.map;
    },
    // El config del mapa, para los modulos que cuelgan de este (geocercas,
    // POIs, rutas): lo leen de aqui en vez de volver a parsear el JSON.
    cfg: function () {
      return MAP_CFG;
    },
    refresh: function () {
      fetchDevices(true);
    },
    selectDevice: selectDevice,
  };

  // Copiar coordenadas de la ficha (boton data-tp-copy).
  document.addEventListener("click", function (e) {
    var b = e.target.closest && e.target.closest("[data-tp-copy]");
    if (!b) return;
    var txt = b.getAttribute("data-tp-copy");
    var ok = function () {
      b.classList.add("is-done");
      setTimeout(function () { b.classList.remove("is-done"); }, 1500);
      if (window.tpModal && tpModal.toast) tpModal.toast(tpT("ui.copied"), "success");
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(ok, function () {});
  });

  // -----------------------------------------------------------
  // Init
  // -----------------------------------------------------------
  initMap();
  wire();
  fetchDevices(false);
  state.pollHandle = setInterval(pollUpdates, CFG.pollInterval);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") pollUpdates();
  });
})();
