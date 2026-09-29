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
  function classify(d) {
    switch (d && d.online) {
      case "online":
      case "excessive_speed":
      case "move":
        return "moving";
      case "engine":
      case "ack":
      case "stop":
      case "idle":
      case "park":
      case "blocked":
        return "idle";
      default:
        return "offline";
    }
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
    for (var i = 0; i < rangos.length; i++) {
      var r = rangos[i];
      var desde = r.from == null ? -Infinity : +r.from;
      var hasta = r.to == null ? Infinity : +r.to;
      // Tramo abierto por arriba: [desde, hasta)
      if (v >= desde && v < hasta) return r.color || null;
    }
    // Por encima del ultimo tramo cerrado, el ultimo color definido
    return rangos.length ? rangos[rangos.length - 1].color || null : null;
  }

  function markerColor(d) {
    var cfg = d.marker_colors;
    if (!cfg) return null;

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
    var halo = col
      ? '<span class="tp-lmarker__halo" style="background:' + col + ';"></span>'
      : "";

    var html =
      '<span class="tp-lmarker__name tp-lmarker__name--' + grp + '"' + estiloNombre + ">" +
      esc(d.name || "") +
      vehicleStateBadge(d.vehicle_state, "vertical-align:middle;margin-left:4px;") +
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
        m.setLatLng(ll);
        m.setIcon(deviceIcon(d));
      } else {
        m = L.marker(ll, { icon: deviceIcon(d) });
        m.on("click", function () {
          openDevicePopup(d.id);
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
    if (state.toggles.tails) renderTails();
  }

  // Contenedor activo de marcadores: cluster o layerGroup normal.
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
        spiderfyOnMaxZoom: true,
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
    return state.clusterGroup;
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
        window.tpModal && tpModal.toast && tpModal.toast("Agrupación no disponible.", "warning");
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
    } else if (key === "hitos") {
      // Hitos kilométricos (capa compartida tp-hitos.js)
      if (window.tpHitos) {
        if (!state.hitosCtrl) state.hitosCtrl = tpHitos.create(m);
        state.hitosCtrl.setVisible(on);
      }
    }
  }

  // Cola de recorrido (tail): polilínea de las últimas posiciones de cada device.
  function renderTails() {
    var m = state.map;
    if (!state.tailLayer) state.tailLayer = L.layerGroup();
    if (!state.toggles.tails) {
      if (m.hasLayer(state.tailLayer)) m.removeLayer(state.tailLayer);
      return;
    }
    if (!m.hasLayer(state.tailLayer)) state.tailLayer.addTo(m);
    state.tailLayer.clearLayers();
    state.mapFiltered.forEach(function (d) {
      if (d.active === false) return;
      var tail = d.tail;
      if (typeof tail === "string") {
        try {
          tail = JSON.parse(tail);
        } catch (e) {
          tail = null;
        }
      }
      if (!tail || tail.length < 2) return;
      var pts = tail
        .map(function (p) {
          return [parseFloat(p.lat), parseFloat(p.lng)];
        })
        .filter(function (x) {
          return !isNaN(x[0]) && !isNaN(x[1]);
        });
      if (pts.length < 2) return;
      L.polyline(pts, { color: d.tail_color || "#1e88e5", weight: 2, opacity: 0.8 }).addTo(state.tailLayer);
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
            .bindTooltip(rt.name || "Ruta")
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
        tpModal.toast && tpModal.toast("No se pudo cargar la configuración.", "error");
        return;
      }
      var dlg = tpModal.open({
        title: "Widgets",
        size: "lg",
        body: res.data.html,
        buttons: [
          { label: "Cancelar", variant: "ghost" },
          {
            label: "Guardar",
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
                    tpModal.toast && tpModal.toast("Widgets actualizados.", "success");
                    tpModal.close(dialog);
                    if (state.selectedId) loadDrawerWidgets(state.selectedId);
                  } else tpModal.toast && tpModal.toast("No se pudo guardar.", "error");
                })
                .catch(function () {
                  btn.disabled = false;
                  tpModal.toast && tpModal.toast("Error de red.", "error");
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
  var addrCache = {};
  function fetchAddr(d, cb) {
    if (!CFG.geoUrl || d.lat == null) return cb("");
    if (addrCache[d.id]) return cb(addrCache[d.id]);
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
        addrCache[d.id] = t;
        cb(t);
      })
      .catch(function () {
        cb("");
      });
  }
  function openDevicePopup(id) {
    var d = state.index[id];
    if (!d || d.lat == null) return;
    var status = classify(d);
    var speed = d.speed_text || (Math.round(parseFloat(d.speed) || 0) + " km/h");
    var html =
      '<div class="tp-dev-popup" data-status="' +
      status +
      '"><div class="tp-dev-popup__head"><span class="tp-dev-popup__dot"></span>' +
      '<span class="tp-dev-popup__name">' +
      esc(d.name || "#" + d.id) +
      "</span></div>" +
      '<div class="tp-dev-popup__status">' +
      esc(STATUS_LABELS[d.online] || d.online || "—") +
      "</div><dl class=\"tp-dev-popup__defs\">" +
      (d.plate_number ? "<dt>Placa</dt><dd>" + esc(d.plate_number) + "</dd>" : "") +
      "<dt>Velocidad</dt><dd>" +
      esc(speed) +
      "</dd><dt>Hora</dt><dd>" +
      esc(d.time || "—") +
      '</dd><dt>Ubicación</dt><dd data-pop-addr class="' +
      (CFG.fullAddress ? "tp-addr--full" : "tp-addr--clip") +
      '">Cargando…</dd></dl>' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm tp-full" data-pop-details>Ver detalles</button></div>';
    if (!state.popup) state.popup = L.popup({ maxWidth: 280, className: "tp-dev-popup-wrap" });
    state.popup.setLatLng([parseFloat(d.lat), parseFloat(d.lng)]).setContent(html).openOn(state.map);
    var el = state.popup.getElement();
    fetchAddr(d, function (a) {
      if (!state.popup || !state.popup.isOpen()) return;
      var slot = el && el.querySelector("[data-pop-addr]");
      if (slot) slot.textContent = a || "—";
    });
    var btn = el && el.querySelector("[data-pop-details]");
    if (btn)
      btn.addEventListener("click", function () {
        selectDevice(id);
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
      '<button type="button" class="tp-follow__close" aria-label="Cerrar">×</button></div>' +
      '<div class="tp-follow__map"></div>' +
      '<div class="tp-follow__foot"><span data-f-speed>—</span><span data-f-time></span></div>';
    root.appendChild(el);

    // Mini-mapa con SU PROPIA capa base (una TileLayer solo puede vivir en un
    // mapa; tpBasemaps.build crea instancias frescas).
    var mm = L.map(el.querySelector(".tp-follow__map"), {
      zoomControl: false,
      attributionControl: true,
    });
    var lyr = null;
    try {
      if (window.tpBasemaps && tpBasemaps.build) {
        var bms = tpBasemaps.build(MAP_CFG);
        var bid = tpBasemaps.defaultId ? tpBasemaps.defaultId(bms, MAP_CFG) : null;
        if (bid && bms[bid]) lyr = bms[bid].layer;
      }
    } catch (e) {}
    if (!lyr)
      lyr = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 });
    lyr.addTo(mm);

    follows[id] = { el: el, map: mm, marker: null };
    setTimeout(function () {
      mm.invalidateSize();
      updateFollow(id);
    }, 60);

    // Arrastre por la cabecera (pointer events)
    var head = el.querySelector(".tp-follow__head");
    var drag = null;
    head.addEventListener("pointerdown", function (e) {
      if (e.target.closest(".tp-follow__close")) return;
      el.style.zIndex = ++followZ;
      el.style.left = el.offsetLeft + "px";
      el.style.right = "auto";
      drag = { x: e.clientX, y: e.clientY, left: el.offsetLeft, top: el.offsetTop };
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
    try { f.map.remove(); } catch (e) {}
    f.el.remove();
    delete follows[id];
  }

  function updateFollow(id) {
    var f = follows[id];
    var d = state.index[id];
    if (!f || !d || d.lat == null || d.lng == null) return;
    var ll = [parseFloat(d.lat), parseFloat(d.lng)];
    if (!f.marker) {
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
    if (d && d.lat != null)
      state.map.setView([parseFloat(d.lat), parseFloat(d.lng)], Math.max(state.map.getZoom(), 14));
    root.setAttribute("data-drawer-open", "true");
    var drawer = document.getElementById("tp-live-drawer");
    if (drawer) drawer.setAttribute("aria-hidden", "false");
    if (d) fillDrawer(d);
    loadDrawerWidgets(id);
    loadVehicleState(id);
    var histBtn = document.getElementById("tp-drawer-history");
    if (histBtn) {
      histBtn.disabled = false;
      histBtn.onclick = function () {
        location.href = "/ui/v2/history?device=" + id + "&range=today";
      };
    }
  }
  function setText(elId, val) {
    var el = document.getElementById(elId);
    if (el) el.textContent = val == null || val === "" ? "—" : val;
  }
  // Duración (segundos) → H:MM:SS. Alimenta la KPI "Detenido".
  function fmtDuration(sec) {
    if (sec == null || sec === "") return "—";
    sec = Math.max(0, Math.round(Number(sec) || 0));
    var h = Math.floor(sec / 3600),
      m = Math.floor((sec % 3600) / 60),
      s = sec % 60;
    return h + ":" + ("0" + m).slice(-2) + ":" + ("0" + s).slice(-2);
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

  function celda(etiqueta, valor, unidad) {
    if (valor === null || valor === undefined || valor === "") return "";

    return (
      '<div class="tp-dash__stat">' +
      '<div class="tp-dash__stat-label">' + esc(etiqueta) + "</div>" +
      '<div class="tp-dash__stat-value">' + esc(String(valor).trim()) +
      (unidad ? ' <span class="tp-dash__stat-unit">' + esc(unidad) + "</span>" : "") +
      "</div></div>"
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

    var html = '<div class="tp-dash__main">' +
      esfera(G_GRANDE, apagado ? 0 : v, max, apagado ? "0" : String(Math.round(v)),
             dash.speed_unit || "km/h", nivel, "tp-dash__gauge--big");

    // Limite de la geocerca, si el vehiculo esta dentro de una
    var gf = !apagado && window.tpLiveZones && tpLiveZones.limitFor
      ? tpLiveZones.limitFor(d.lat, d.lng, d.vehicle_type_id, d.vehicle_state_id)
      : null;

    if (gf && gf.limit) {
      var dif = Math.round(v - gf.limit);
      html +=
        '<div class="tp-dash__zone" data-over="' + (dif >= 0 ? "1" : "0") + '">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' +
        "<span>" + esc(gf.zoneName || "Zona") + " · límite " + gf.limit + "</span>" +
        '<strong>' + (dif >= 0 ? "+" : "") + dif + "</strong>" +
        "</div>";
    }

    html += "</div>";

    // Esferas pequeñas: solo las que el vehiculo reporta
    var chicas = "";
    if (dash.rpm) {
      chicas += '<div class="tp-dash__small"><div class="tp-dash__small-label">RPM MOTOR</div>' +
        esfera(G_CHICA, apagado ? 0 : dash.rpm.value, dash.rpm.max, String(dash.rpm.value), "rpm",
               dash.rpm.value >= dash.rpm.max * 0.75 ? "bad" : "info") + "</div>";
    }
    if (dash.fuel) {
      chicas += '<div class="tp-dash__small"><div class="tp-dash__small-label">COMBUSTIBLE</div>' +
        esfera(G_CHICA, dash.fuel.value, 100, dash.fuel.value + (dash.fuel.unit === "%" ? "%" : ""), "combustible",
               dash.fuel.value <= 15 ? "bad" : dash.fuel.value <= 30 ? "warn" : "ok") + "</div>";
    }
    if (dash.temp) {
      chicas += '<div class="tp-dash__small"><div class="tp-dash__small-label">TEMPERATURA</div>' +
        esfera(G_CHICA, dash.temp.value, 120, dash.temp.value + "°", "temperatura",
               dash.temp.value >= 95 ? "bad" : dash.temp.value >= 80 ? "warn" : "ok") + "</div>";
    }
    if (chicas) html += '<div class="tp-dash__row">' + chicas + "</div>";

    // Testigos
    var t = dash.telltales || {};
    var tells =
      testigo("Motor", "MOTOR", t.engine, ICONOS.motor) +
      testigo("Ignición", "IGNICIÓN", t.ignition, ICONOS.ignicion) +
      testigo("Puerta", "PUERTA", t.door, ICONOS.puerta) +
      testigo("Bloqueo de motor", "BLOQUEO", t.blocked, ICONOS.bloqueo) +
      (dash.volt != null
        ? '<span class="tp-dash__tell" data-on="1" title="Batería externa">' + ICONOS.bateria +
          '<span class="tp-dash__tell-label">' + esc(dash.volt.value + " " + (dash.volt.unit || "v")) + "</span></span>"
        : "") +
      (dash.satellites != null
        ? '<span class="tp-dash__tell" data-on="' + (apagado ? "0" : "1") + '" title="Satélites">' + ICONOS.satelite +
          '<span class="tp-dash__tell-label">' + (apagado ? "—" : Math.round(dash.satellites)) + " SAT</span></span>"
        : "");

    if (tells) html += '<div class="tp-dash__tells">' + tells + "</div>";

    // Cifras de contexto: las cuatro de siempre mas odometro y horometro
    var stats =
      celda("Odómetro", dash.odometer, null) +
      celda("Horómetro", dash.engine_hours, null) +
      celda("Rumbo", d.course != null ? Math.round(d.course) + "°" : null, null) +
      celda("Detenido", fmtDuration(d.stop_duration_sec), null) +
      celda("Distancia", d.total_distance, "km");

    if (stats) html += '<div class="tp-dash__stats">' + stats + "</div>";

    box.innerHTML = html;
  }

  function fillDrawer(d) {
    setText("tp-drawer-name", d.name || "#" + d.id);
    setText("tp-drawer-plate", d.plate_number || d.imei || "—");
    var dot = document.getElementById("tp-drawer-dot");
    if (dot) {
      var st = classify(d);
      dot.className = "tp-dot tp-dot--" + (st === "moving" ? "moving" : st === "idle" ? "idle" : "offline");
    }
    renderDashboard(d);
    // El resto de la ficha (última señal, coordenadas, altitud, motor, imei,
    // modelo, sim) se muestra unificada en el widget "Objeto" (device-widgets.blade).
    var sr = document.getElementById("tp-drawer-status-row");
    if (sr) {
      var lbl = STATUS_LABELS[d.online] || d.online || "—";
      sr.innerHTML = '<span class="tp-chip" data-status="' + classify(d) + '">' + esc(lbl) + "</span>";
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

  /* ── Estado de vehículo (drawer): badge + cambiador rápido ──────────── */
  var vstateData = { deviceId: null, states: [], current: null };
  function vsBase(id) {
    return (CFG.devicesUrl || "/ui/v2/api/devices").replace(/\/devices(\?.*)?$/, "/devices") + "/" + id;
  }
  function loadVehicleState(id) {
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
      })
      .catch(function () {});
  }
  function renderVStateBadge() {
    var el = document.getElementById("tp-drawer-vstate-badge");
    if (!el) return;
    var cur = vstateData.states.filter(function (s) { return s.id === vstateData.current; })[0];
    el.innerHTML = cur
      ? vehicleStateBadge(cur, "font-size:12px;padding:3px 12px;")
      : '<span class="tp-text-xs tp-text-muted">Sin estado</span>';
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
      title: "Cambiar estado",
      size: "sm",
      body:
        '<div class="tp-scope"><div class="tp-vs-choices">' + chips +
        '<button type="button" class="tp-vs-choice' + (!vstateData.current ? " is-active" : "") +
        '" data-vs="0"><span class="tp-vs-dot" style="background:#cbd5e1;"></span>Sin estado</button></div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
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
        if (!res.ok || !res.data || !res.data.ok) { tpModal.toast("No se pudo cambiar el estado.", "error"); return; }
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
        tpModal.toast("Estado actualizado.", "success");
      })
      .catch(function () { tpModal.setBusy(dlg, false); tpModal.toast("Error de red.", "error"); });
  }

  // Reparte los widgets del device: .tp-dw--side → drawer, .tp-dw--bottom → bottombar.
  function loadDrawerWidgets(id) {
    var box = document.getElementById("tp-drawer-widgets");
    var bottom = document.getElementById("tp-live-bottombar-cards");
    if (box) box.innerHTML = '<div class="tp-text-xs tp-text-muted" style="padding:8px;">Cargando…</div>';
    var base = CFG.devicesUrl || "/ui/v2/api/devices";
    fetch(base + "/" + id + "/widgets", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (state.selectedId != id) return;

        // Lecturas del tablero: se guardan y se repinta ya, sin esperar al
        // siguiente refresco del feed.
        if (res && res.dashboard) {
          dashData[id] = res.dashboard;
          if (state.index[id]) renderDashboard(state.index[id]);
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
    var counts = { all: state.devices.length, moving: 0, idle: 0, offline: 0 };
    // Base: búsqueda + filtro de estado segmentado (siempre aplican a lista y mapa).
    var base = state.devices.filter(function (d) {
      var c = classify(d);
      if (c === "moving") counts.moving++;
      else if (c === "idle") counts.idle++;
      else counts.offline++;
      if (sf !== "all" && c !== sf) return false;
      if (q) {
        var hay = (d.name || "") + " " + (d.plate_number || "") + " " + (d.imei || "");
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
      var r = { moving: 0, idle: 1, offline: 2 };
      var ra = r[classify(a)],
        rb = r[classify(b)];
      if (ra !== rb) return ra - rb;
      return (a.name || "").localeCompare(b.name || "");
    };
    state.filtered.sort(sorter);
    state.mapFiltered.sort(sorter);
    ["all", "moving", "idle", "offline"].forEach(function (k) {
      var n = root.querySelector('[data-count="' + k + '"]');
      if (n) n.textContent = counts[k];
    });
    var sM = document.getElementById("tp-live-stat-moving");
    if (sM) sM.textContent = counts.moving;
    var sI = document.getElementById("tp-live-stat-idle");
    if (sI) sI.textContent = counts.idle;
    var sO = document.getElementById("tp-live-stat-offline");
    if (sO) sO.textContent = counts.offline;
    if (window.tpUpdateLiveStatus)
      window.tpUpdateLiveStatus({ moving: counts.moving, idle: counts.idle, offline: counts.offline });
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
      "> " + esc(L2.filter_sidebar || "Lista") + "</label>" +
      '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-f-map' +
      (af.map ? " checked" : "") +
      "> " + esc(L2.filter_map || "Mapa") + "</label></div>" +
      '<div style="font-size:12px;font-weight:600;margin-bottom:4px;">' + esc(L2.status || "Estado") + "</div>" +
      '<div style="max-height:190px;overflow:auto;border:1px solid var(--border-default);border-radius:8px;padding:6px 10px;">' +
      statusRows + "</div>" +
      '<div style="font-size:12px;font-weight:600;margin:12px 0 4px;">' +
      esc(L2.last_connection || "Última conexión") + " (" + esc(L2.minutes || "min") + ")</div>" +
      '<div style="display:flex;gap:8px;">' +
      '<input type="number" min="0" class="tp-input" data-f-online placeholder="' + esc(L2.from || "Desde") +
      '" value="' + (af.online != null ? af.online : "") + '" style="width:50%;">' +
      '<input type="number" min="0" class="tp-input" data-f-offline placeholder="' + esc(L2.to || "Hasta") +
      '" value="' + (af.offline != null ? af.offline : "") + '" style="width:50%;"></div></div>';
    tpModal.open({
      title: L2.title || "Filtro",
      size: "sm",
      body: body,
      buttons: [
        {
          label: L2.clear || "Limpiar",
          variant: "ghost",
          close: false,
          onClick: function (dlg) {
            clearAdvancedFilter();
            tpModal.close(dlg);
            return false;
          },
        },
        { label: L2.cancel || "Cancelar", variant: "ghost", close: true },
        {
          label: L2.apply || "Aplicar",
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
          tpModal.toast && tpModal.toast("No se pudo aplicar el filtro.", "error");
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
        tpModal.toast && tpModal.toast("Error de red.", "error");
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
        ? "Se enviará el comando para <b>cortar</b> el motor del vehículo."
        : "Se enviará el comando para <b>restablecer</b> el motor del vehículo.") +
      "</p>" +
      '<label class="tp-field__label">Envío</label>' +
      '<select class="tp-input" data-lock-type style="width:100%;margin:4px 0 10px;"><option value="gprs">GPRS</option>' +
      (canSms ? '<option value="sms">SMS</option>' : "") +
      "</select>" +
      '<label class="tp-field__label">Plantilla GPRS</label>' +
      '<select class="tp-input" data-lock-tpl style="width:100%;margin:4px 0 10px;"><option value="">Cargando…</option></select>' +
      '<label class="tp-field__label">Comando</label>' +
      '<input type="text" class="tp-input" data-lock-cmd value="" placeholder="' +
      (isOn ? "setdigout 1" : "setdigout 0") +
      '" style="width:100%;margin-top:4px;">' +
      '<div data-lock-err role="alert" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.35);"></div>';
    var dlg = tpModal.open({
      title: isOn ? "Cortar motor" : "Restablecer motor",
      size: "sm",
      body: body,
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: isOn ? "Cortar" : "Restablecer",
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
              showErr("Elige una plantilla o escribe el comando a enviar.");
              return false;
            }
            if (err) err.style.display = "none";
            tpModal.setBusy(dlg, true);
            tpModal
              .fetchJSON(url, { method: "POST", json: { type: type, message: cmd } })
              .then(function (res) {
                tpModal.setBusy(dlg, false);
                if (!res.ok || !res.data || !res.data.ok) {
                  showErr((res.data && res.data.message) || "No se pudo enviar el comando.");
                  return;
                }
                tpModal.toast((res.data && res.data.message) || "Comando enviado.", "success");
                tpModal.close(dlg);
              })
              .catch(function () {
                tpModal.setBusy(dlg, false);
                showErr("Error de red. Inténtalo de nuevo.");
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
      title: "Historial de bloqueo",
      size: "md",
      body:
        '<div style="margin-bottom:10px;"><select class="tp-input" data-lock-period style="width:auto;">' +
        '<option value="today">Hoy</option><option value="yesterday">Ayer</option>' +
        '<option value="this_week">Esta semana</option><option value="last_week">Semana pasada</option></select></div>' +
        '<div data-lock-hist><div class="tp-text-muted" style="padding:12px;">Cargando…</div></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
    });
    function load(period) {
      var box = dlg.querySelector("[data-lock-hist]");
      box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">Cargando…</div>';
      fetch(url + "?period=" + encodeURIComponent(period), {
        credentials: "same-origin",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (!res || !res.ok) {
            box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">No se pudo cargar.</div>';
            return;
          }
          var items = res.items || [];
          if (!items.length) {
            box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">Sin registros en el periodo.</div>';
            return;
          }
          box.innerHTML =
            '<table class="tp-lock-hist"><thead><tr><th>Inicio</th><th>Duración</th><th>Ubicación</th></tr></thead><tbody>' +
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
            "</tbody></table>";
        })
        .catch(function () {
          box.innerHTML = '<div class="tp-text-muted" style="padding:12px;">Error de red.</div>';
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
      tpModal.toast("Haz clic en el mapa para medir. Vuelve a Herramientas → Regla para salir.", "info");
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
      title: "Mostrar punto",
      size: "sm",
      body:
        '<div class="tp-scope"><label class="tp-field__label">Latitud</label>' +
        '<input type="text" class="tp-input" data-pt-lat placeholder="-8.10610" style="width:100%;margin:4px 0 10px;">' +
        '<label class="tp-field__label">Longitud</label>' +
        '<input type="text" class="tp-input" data-pt-lng placeholder="-79.02080" style="width:100%;margin-top:4px;">' +
        '<div data-pt-err style="display:none;margin-top:10px;color:#ef4444;font-size:13px;"></div></div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Mostrar",
          variant: "primary",
          close: false,
          onClick: function (dlg) {
            var lat = parseFloat((dlg.querySelector("[data-pt-lat]") || {}).value);
            var lng = parseFloat((dlg.querySelector("[data-pt-lng]") || {}).value);
            var err = dlg.querySelector("[data-pt-err]");
            if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
              if (err) {
                err.textContent = "Coordenadas inválidas.";
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
      title: "Buscar dirección",
      size: "sm",
      body:
        '<div class="tp-scope"><label class="tp-field__label">Dirección</label>' +
        '<input type="text" class="tp-input" data-adr-q placeholder="Av. España 123, Trujillo" style="width:100%;margin-top:4px;">' +
        '<div data-adr-err style="display:none;margin-top:10px;color:#ef4444;font-size:13px;"></div></div>',
      buttons: [
        { label: "Cancelar", variant: "ghost", close: true },
        {
          label: "Buscar",
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
                    err.textContent = (res.data && res.data.error) || "No se encontró la dirección.";
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
                  err.textContent = "Error de red.";
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
    if (!gid) return "Sin grupo";
    for (var i = 0; i < state.groups.length; i++)
      if (state.groups[i].id === gid) return state.groups[i].title;
    return "Grupo " + gid;
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
    var active = d.active !== false;
    // 2ª línea = fecha de transmisión / estado (paridad legacy Device::time):
    // el backend ya resuelve "Desactivado" / "No conectado" / SIM|plan vencido /
    // fecha formateada. Solo falta pintarlo (antes mostrábamos la placa).
    var ts = Math.max(parseInt(d.timestamp, 10) || 0, parseInt(d.acktimestamp, 10) || 0);
    var metaTxt = d.time || d.plate_number || d.imei || "";
    var metaIsStatus = active === false || ts === 0; // muestra estado, no fecha
    // Acento lateral con el color de estado real del device (getStatusColor).
    var accent = d.icon_color || "";
    // Candado (solo si el device tiene sensor blocked): rojo=bloqueado, verde=libre.
    var lockIcon = "";
    if (d.blocked === true || d.blocked === false) {
      lockIcon =
        '<span class="tp-lock-ico ' +
        (d.blocked ? "tp-lock-ico--on" : "tp-lock-ico--off") +
        '" title="' +
        (d.blocked ? "Motor bloqueado" : "Motor desbloqueado") +
        '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>';
    }
    // Motor (paridad legacy detect_engine): solo si hay detección de ignición
    // por SENSOR → engine_status es bool; con GPS es null y no se muestra.
    // Verde = encendido, gris = apagado.
    var engineIcon = "";
    if (d.engine_status === true || d.engine_status === false) {
      engineIcon =
        '<span class="tp-eng-ico ' +
        (d.engine_status ? "tp-eng-ico--on" : "tp-eng-ico--off") +
        '" title="' +
        (d.engine_status ? "Motor encendido" : "Motor apagado") +
        '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg></span>';
    }
    // Badge de estado de vehículo (tipo con estados: VACÍO/CARGADO…) con su color.
    var stateBadge = vehicleStateBadge(d.vehicle_state);
    return (
      // El legacy marca en la lista el vehiculo seleccionado (devices.select
      // -> addClass('active')). La clase se pone aqui tambien, no solo al
      // pulsar, para que sobreviva a un repintado de la lista.
      '<div class="tp-live__row' +
      (state.selectedId === d.id ? " is-selected" : "") +
      '" data-id="' +
      d.id +
      '" data-status="' +
      status +
      '"' +
      (accent ? ' style="--tp-accent:' + accent + '"' : "") +
      ' role="option">' +
      '<label class="tp-live__row-active" title="Mostrar en el mapa"><input type="checkbox" data-id="' +
      d.id +
      '"' +
      (active ? " checked" : "") +
      "></label>" +
      '<span class="tp-live__row-dot"></span>' +
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
      '</span><span class="tp-live__row-time">' +
      fmtTimeAgo(d.acktimestamp || d.timestamp) +
      "</span></div>" +
      '<button type="button" class="tp-live__row-menu" data-id="' +
      d.id +
      '" title="Acciones"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg></button>' +
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
            '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> Agregar nuevo</button>';
          if (g !== 0) {
            items +=
              '<button type="button" role="menuitem" data-dg-edit="' + g + '">' +
              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg> Editar</button>' +
              '<button type="button" role="menuitem" class="is-danger" data-dg-del="' + g + '">' +
              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg> Eliminar</button>';
          }
          tools =
            '<div class="tp-svc-kebab" style="margin-left:auto;">' +
            '<button type="button" class="tp-svc-kebab__btn" data-svc-kebab aria-haspopup="menu" aria-label="Acciones">' +
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>' +
            "</button>" +
            '<div class="tp-svc-kebab__menu" role="menu" hidden>' + items + "</div></div>";
        }

        html +=
          '<div class="tp-live__group-box"' +
          (collapsed ? ' data-collapsed="1"' : "") +
          '><div class="tp-live__group" data-gid="' +
          g +
          '"' +
          (collapsed ? ' data-collapsed="1"' : "") +
          '><svg class="tp-live__group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg><span class="tp-live__group-title">' +
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
   * Historial de cambios del vehiculo: quien toco que y cuando.
   *
   * Incluye lo que le pasa a sus partes —sensores, servicios,
   * mantenimientos—, no solo a la ficha, y deja fuera los inicios de sesion,
   * que son el 90% del registro y no son cambios.
   */
  function abrirHistorialCambios(id) {
    if (!window.tpModal) return;

    var dlg = tpModal.open({
      title: "Historial de cambios",
      size: "xl",
      body: '<div data-hist><p class="tp-text-sm tp-text-muted">Cargando…</p></div>',
      buttons: [{ label: "Cerrar", variant: "ghost", close: true }],
      onMount: function (d) {
        var host = d.querySelector("[data-hist]");

        tpModal
          .fetchJSON("/ui/v2/api/devices/" + id + "/changes", { method: "GET" })
          .then(function (res) {
            if (!res.ok || !res.data || !res.data.ok) {
              host.innerHTML = '<p class="tp-text-sm tp-text-error">No se pudo cargar.</p>';
              return;
            }

            var items = res.data.items || [];

            if (!items.length) {
              host.innerHTML =
                '<p class="tp-text-sm tp-text-muted">Sin cambios registrados para este vehículo.</p>';
              return;
            }

            var accion = { created: "Creado", updated: "Modificado", deleted: "Eliminado" };

            host.innerHTML =
              '<div style="max-height:62vh;overflow:auto;"><table class="tp-table"><thead><tr>' +
              "<th>Cuándo</th><th>Qué</th><th>Acción</th><th>Quién</th><th>Cambios</th>" +
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
            host.innerHTML = '<p class="tp-text-sm tp-text-error">Error de red.</p>';
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
      if (act === "history")
        location.href = "/ui/v2/history?device=" + id + "&range=" + (it.getAttribute("data-range") || "today");
      else if (act === "follow") openFollow(id);
      else if (act === "device_video") {
        // Pendiente: reproducción en vivo y grabaciones (decoder JT808/Howen).
        if (window.tpModal) {
          tpModal.open({
            title: "Vídeo a bordo",
            size: "sm",
            body:
              '<p class="tp-text-sm" style="margin:0 0 8px;">Este equipo reporta con un protocolo de vídeo compatible.</p>' +
              '<p class="tp-text-sm tp-text-muted" style="margin:0;">La reproducción en vivo y las grabaciones se habilitarán con la integración del decoder.</p>',
            buttons: [{ label: "Entendido", variant: "primary", close: true }],
          });
        }
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
      } else if (act === "pending" && window.tpModal && tpModal.toast)
        tpModal.toast("Función disponible próximamente.", "info");
    });
    // Clonado del template: síncrono e instantáneo, sin petición.
    var built = buildRowMenu(id);
    rowMenu.innerHTML = "";
    if (built) rowMenu.appendChild(built);
    else rowMenu.innerHTML = '<div class="tp-row-menu__loading">Sin acciones.</div>';

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
  var CAN_GROUPS = !!document.getElementById("tp-live-create-device"); // perm devices edit
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
      : '<span class="tp-text-muted" style="font-size:12.5px;">No tienes reenvíos creados. Créalos en Herramientas → Reenvíos.</span>';
    return (
      '<div class="tp-form__field" style="margin-bottom:12px;"><span class="tp-form__label">Reenvíos</span>' +
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
        title: "Grupos de objetos",
        size: "lg",
        static: true,
        body:
          '<div class="tp-form">' +
          '<label class="tp-form__field" style="margin-bottom:12px;">' +
          '<span class="tp-form__label">Título</span>' +
          '<input type="text" data-dgm-title maxlength="100" value="' + esc((info && info.title) || "") + '" data-tp-field>' +
          "</label>" +
          forwardsFieldHtml(info) +
          '<div class="tp-form__field"><span class="tp-form__label">Vehículos</span>' +
          '<div class="tp-group-box" data-dgm-box>' +
          '<div class="tp-group-box__head" style="margin-bottom:0;">' +
          '<span class="tp-search">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>' +
          '<input type="search" data-dgm-search placeholder="Búsqueda" autocomplete="off">' +
          "</span>" +
          '<button type="button" class="tp-link-action" data-dgm-all>Seleccionar todo</button>' +
          '<span class="tp-group-box__sep">|</span>' +
          '<button type="button" class="tp-link-action" data-dgm-none>Deseleccionar todo</button>' +
          "</div>" +
          '<div style="height:1px;background:var(--border-default,#e9eef5);margin:12px -14px;"></div>' +
          '<div data-dgm-list></div>' +
          '<div class="tp-pagination" data-dgm-pager></div>' +
          "</div></div></div>",
        buttons: [
          { label: "Cancelar", variant: "ghost", close: true },
          {
            label: "Guardar",
            variant: "primary",
            close: false,
            onClick: function (dialog) {
              var title = dialog.querySelector("[data-dgm-title]").value.trim();
              if (!title) {
                tpModal.toast("El título es obligatorio.", "warning");
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
                    tpModal.toast(first ? (Array.isArray(errors[first]) ? errors[first][0] : errors[first]) : "No se pudo guardar.", "error");
                    return;
                  }
                  tpModal.toast(isEdit ? "Grupo actualizado." : "Grupo creado.", "success");
                  tpModal.close(dialog);
                  fetchDevices(true);
                })
                .catch(function () {
                  tpModal.setBusy(dialog, false);
                  tpModal.toast("Error de red.", "error");
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
          esc(groupTitles[gid] || "Sin grupo") + "</span>" +
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
          '<div style="padding:12px 4px;font-size:13px;color:var(--fg-muted,#94a3b8);">Sin resultados.</div>';
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
        title: "Eliminar grupo",
        message: "¿Eliminar este grupo? Los vehículos quedarán sin grupo.",
        danger: true,
        confirmLabel: "Eliminar",
      })
      .then(function (okGo) {
        if (!okGo) return;
        tpModal.fetchJSON(GROUPS_BASE + "/" + groupId, { method: "DELETE" }).then(function (res) {
          if (res.ok && res.data && res.data.ok) {
            tpModal.toast("Grupo eliminado.", "success");
            fetchDevices(true);
          } else {
            tpModal.toast("No se pudo eliminar.", "error");
          }
        });
      });
  }

  if (listEl) {
    listEl.addEventListener("click", function (e) {
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

      var gh = e.target.closest(".tp-live__group");
      if (gh) {
        var g = parseInt(gh.getAttribute("data-gid"), 10);
        state.collapsedGroups[g] = !state.collapsedGroups[g];
        renderList();
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
      if (row) selectDevice(parseInt(row.getAttribute("data-id"), 10));
    });
    listEl.addEventListener("change", function (e) {
      var cb = e.target;
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
    rows.forEach(function (d) {
      state.index[d.id] = d;
    });
    recompute();
    renderList();
    renderMarkers();
    updateFollows();
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
          if (j && Array.isArray(j.groups)) state.groups = j.groups;
          var pg = (j && j.pagination) || null;
          if (pg && pg.total != null) total = pg.total;
          commit(acc, total);
          if (pg && pg.current_page < pg.last_page) {
            if (statusText) statusText.textContent = "Cargando… " + acc.length + "/" + (total || "?");
            return page(pg.current_page + 1);
          }
          if (statusText)
            statusText.textContent = (total || acc.length) + " vehículos · " + new Date().toLocaleTimeString();
        });
    }
    page(1)
      .catch(function (e) {
        if (e.name !== "AbortError" && statusText) statusText.textContent = "Error al cargar";
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
  function pollUpdates() {
    if (document.visibilityState !== "visible") return;
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
        if (!items.length) return;
        items.forEach(function (d) {
          if (state.index[d.id]) {
            for (var k in d) if (Object.prototype.hasOwnProperty.call(d, k)) state.index[d.id][k] = d[k];
          } else {
            state.index[d.id] = d;
            state.devices.push(d);
          }
        });
        recompute();
        renderList();
        renderMarkers();
        updateFollows();

        // El drawer no se refrescaba con el sondeo: si lo tenias abierto, la
        // velocidad se quedaba congelada en el momento de abrirlo.
        if (state.selectedId && state.index[state.selectedId]) {
          fillDrawer(state.index[state.selectedId]);
        }
      })
      .catch(function () {});
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
          window.tpModal && tpModal.toast && tpModal.toast("Tu navegador no soporta geolocalización.", "warning");
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
                  ? "La ubicación requiere HTTPS (el sitio está en HTTP)."
                  : "Permiso de ubicación denegado."
                : "No se pudo obtener tu ubicación.";
            window.tpModal && tpModal.toast && tpModal.toast(msg, "warning");
          },
          { enableHighAccuracy: true, timeout: 8000 },
        );
      });
    // Auto-seguir flota
    var af = document.getElementById("tp-live-autofollow");
    if (af)
      af.addEventListener("click", function () {
        state.autoFit = !state.autoFit;
        af.setAttribute("aria-pressed", state.autoFit ? "true" : "false");
        if (state.autoFit) fitToFleet();
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
      ["cluster", "names", "tails", "traffic"].forEach(function (key) {
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
            title: "Eliminar imagen",
            message: "¿Eliminar la imagen de este vehículo?",
            confirmLabel: "Eliminar",
            danger: true,
          })
          .then(function (ok) {
            if (!ok) return;
            return tpModal
              .fetchJSON(box.getAttribute("data-destroy"), { method: "DELETE" })
              .then(function (res) {
                if (!res.ok || !res.data || !res.data.ok) {
                  tpModal.toast("No se pudo eliminar la imagen.", "error");
                  return;
                }
                tpModal.toast("Imagen eliminada.", "success");
                if (state.selectedId) loadDrawerWidgets(state.selectedId);
              })
              .catch(function () {
                tpModal.toast("Error de red.", "error");
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
            tpModal.toast(first ? [].concat(errs[first])[0] : "Imagen no válida.", "error");
            return;
          }
          if (!res.ok || !res.data || !res.data.ok) {
            tpModal.toast("No se pudo subir la imagen.", "error");
            return;
          }
          tpModal.toast("Imagen actualizada.", "success");
          if (state.selectedId) loadDrawerWidgets(state.selectedId);
        })
        .catch(function () {
          file.value = "";
          tpModal.toast("Error de red.", "error");
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
            tpModal.toast("No se pudo enviar la plantilla.", "error");
            return;
          }
          tpModal.toast("Plantilla enviada.", "success");
        })
        .catch(function () {
          tpModal.toast("Error de red.", "error");
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
    if (dc)
      dc.addEventListener("click", function () {
        root.removeAttribute("data-drawer-open");
        root.removeAttribute("data-bottombar-open");
        var prev = state.selectedId;
        state.selectedId = null;
        markSelectedRow(null);
        if (prev != null) {
          refreshMarkerIcon(prev);
          if (state.markers[prev]) state.markers[prev].setZIndexOffset(0);
        }
      });

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
    refresh: function () {
      fetchDevices(true);
    },
    selectDevice: selectDevice,
  };

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
