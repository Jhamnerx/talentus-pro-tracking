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
    pollInterval: parseInt(root.getAttribute("data-poll-interval"), 10) || 15000,
  };

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
    filtered: [],
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
    var html =
      '<span class="tp-lmarker__name tp-lmarker__name--' + grp + '">' +
      esc(d.name || "") +
      "</span>" +
      '<img src="' +
      src +
      '" width="' +
      w +
      '" height="' +
      h +
      '" style="display:block;' +
      rot +
      '" draggable="false">' +
      '<span class="tp-lmarker__speed">' + esc(spd) + "</span>";
    return L.divIcon({
      html: html,
      className: "tp-lmarker" + (selected ? " tp-lmarker--selected" : ""),
      iconSize: [w, h],
      iconAnchor: anchorBottom ? [w / 2, h] : [w / 2, h / 2],
    });
  }

  function renderMarkers() {
    if (!state.map) return;
    var seen = {};
    state.filtered.forEach(function (d) {
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
    var pts = state.filtered
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
    state.filtered.forEach(function (d) {
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
      '</dd><dt>Ubicación</dt><dd data-pop-addr>Cargando…</dd></dl>' +
      '<div class="tp-hito-ref" data-pop-hito hidden></div>' +
      '<button type="button" class="tp-btn tp-btn--accent tp-btn--sm tp-full" data-pop-details>Ver detalles</button></div>';
    if (!state.popup) state.popup = L.popup({ maxWidth: 280, className: "tp-dev-popup-wrap" });
    state.popup.setLatLng([parseFloat(d.lat), parseFloat(d.lng)]).setContent(html).openOn(state.map);
    var el = state.popup.getElement();
    fetchAddr(d, function (a) {
      if (!state.popup || !state.popup.isOpen()) return;
      var slot = el && el.querySelector("[data-pop-addr]");
      if (slot) slot.textContent = a || "—";
    });
    // Referencia vial "PE-1N · km 557" (solo con el toggle de hitos activo)
    if (state.toggles.hitos && window.tpHitos) {
      tpHitos.nearest(d.lat, d.lng).then(function (h) {
        if (!h || !state.popup || !state.popup.isOpen()) return;
        var slot = el && el.querySelector("[data-pop-hito]");
        if (slot) {
          slot.textContent = tpHitos.refText(h);
          slot.hidden = false;
        }
      });
    }
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
  function selectDevice(id) {
    var prev = state.selectedId;
    state.selectedId = id;
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
  function fillDrawer(d) {
    setText("tp-drawer-name", d.name || "#" + d.id);
    setText("tp-drawer-plate", d.plate_number || d.imei || "—");
    var dot = document.getElementById("tp-drawer-dot");
    if (dot) {
      var st = classify(d);
      dot.className = "tp-dot tp-dot--" + (st === "moving" ? "moving" : st === "idle" ? "idle" : "offline");
    }
    setText("tp-drawer-speed", d.speed_text ? d.speed_text.replace(/[^\d.,]/g, "") : Math.round(parseFloat(d.speed) || 0));
    setText("tp-drawer-course", d.course != null ? Math.round(d.course) : "—");
    setText("tp-drawer-stop", d.stop_duration_text || "—");
    setText("tp-drawer-distance", d.total_distance != null ? d.total_distance : "—");
    setText("tp-drawer-lastseen", d.time || "—");
    setText("tp-drawer-coords", d.lat != null ? parseFloat(d.lat).toFixed(5) + ", " + parseFloat(d.lng).toFixed(5) : "—");
    setText("tp-drawer-altitude", d.altitude != null ? d.altitude + " m" : "—");
    setText(
      "tp-drawer-engine",
      d.engine_status === true || d.engine_status === "on" ? "Encendido" : d.engine_status != null ? "Apagado" : "—",
    );
    setText("tp-drawer-imei", d.imei || "—");
    setText("tp-drawer-model", d.device_model || "—");
    setText("tp-drawer-sim", d.sim_number || "—");
    var sr = document.getElementById("tp-drawer-status-row");
    if (sr) {
      var lbl = STATUS_LABELS[d.online] || d.online || "—";
      sr.innerHTML = '<span class="tp-chip" data-status="' + classify(d) + '">' + esc(lbl) + "</span>";
    }
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
      })
      .catch(function () {});
  }

  // -----------------------------------------------------------
  // Lista (búsqueda + estado + grupos, con cabeceras colapsables)
  // -----------------------------------------------------------
  function recompute() {
    var q = state.searchQuery.trim().toLowerCase();
    var sf = state.statusFilter;
    var counts = { all: state.devices.length, moving: 0, idle: 0, offline: 0 };
    state.filtered = state.devices.filter(function (d) {
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
    state.filtered.sort(function (a, b) {
      var r = { moving: 0, idle: 1, offline: 2 };
      var ra = r[classify(a)],
        rb = r[classify(b)];
      if (ra !== rb) return ra - rb;
      return (a.name || "").localeCompare(b.name || "");
    });
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

  function groupTitle(gid) {
    if (!gid) return "Sin grupo";
    for (var i = 0; i < state.groups.length; i++)
      if (state.groups[i].id === gid) return state.groups[i].title;
    return "Grupo " + gid;
  }
  function rowTemplate(d) {
    var status = classify(d);
    var speedNum = Math.round(parseFloat(d.speed) || 0);
    var speedTxt = d.speed_text || speedNum + " km/h";
    var meta = d.plate_number || d.imei || "";
    var active = d.active !== false;
    return (
      '<div class="tp-live__row" data-id="' +
      d.id +
      '" data-status="' +
      status +
      '" role="option">' +
      '<label class="tp-live__row-active" title="Mostrar en el mapa"><input type="checkbox" data-id="' +
      d.id +
      '"' +
      (active ? " checked" : "") +
      "></label>" +
      '<span class="tp-live__row-dot"></span>' +
      '<div class="tp-live__row-main"><div class="tp-live__row-name">' +
      esc(d.name || "#" + d.id) +
      '</div><div class="tp-live__row-meta">' +
      esc(meta) +
      "</div></div>" +
      '<div class="tp-live__row-end"><span class="tp-live__row-speed' +
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

  var rowMenu = null;
  function closeRowMenu() {
    if (rowMenu) {
      rowMenu.remove();
      rowMenu = null;
      document.removeEventListener("click", closeRowMenu);
    }
  }
  function openRowMenu(btn, id) {
    closeRowMenu();
    rowMenu = document.createElement("div");
    rowMenu.className = "tp-row-menu";
    document.body.appendChild(rowMenu);
    var r = btn.getBoundingClientRect();
    rowMenu.style.position = "fixed";
    rowMenu.style.left = Math.min(r.right + 6, window.innerWidth - 250) + "px";
    rowMenu.style.top = Math.max(8, r.top) + "px";
    rowMenu.addEventListener("click", function (e) {
      var it = e.target.closest(".tp-row-menu__item");
      if (!it) return;
      var act = it.getAttribute("data-act");
      closeRowMenu();
      if (act === "history")
        location.href = "/ui/v2/history?device=" + id + "&range=" + (it.getAttribute("data-range") || "today");
      else if (act === "follow") selectDevice(id);
      else if (act === "edit") {
        if (window.tpDeviceModal) window.tpDeviceModal.openEdit(id, function () { fetchDevices(true); });
      } else if (act === "send_command") {
        if (window.tpCommands) tpCommands.open(id);
      } else if (act === "share") {
        if (window.tpSharing) tpSharing.openForm(null, { deviceId: id });
      } else if (act === "sharing_list") {
        location.href = "/ui/v2/sharing?device_id=" + id;
      } else if (act === "pending" && window.tpModal && tpModal.toast)
        tpModal.toast("Función disponible próximamente.", "info");
    });
    // Clonado del template: síncrono e instantáneo, sin petición.
    var built = buildRowMenu(id);
    rowMenu.innerHTML = "";
    if (built) rowMenu.appendChild(built);
    else rowMenu.innerHTML = '<div class="tp-row-menu__loading">Sin acciones.</div>';
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
              var url = isEdit ? GROUPS_BASE + "/" + groupId : GROUPS_BASE;
              tpModal.setBusy(dialog, true);
              tpModal
                .fetchJSON(url, { method: isEdit ? "PUT" : "POST", json: { title: title, devices: ids } })
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
    if (!state.bootstrapped && state.filtered.length) {
      fitToFleet();
      state.bootstrapped = true;
    }
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
    var bbCollapse = document.getElementById("tp-bottombar-collapse");
    if (bbCollapse)
      bbCollapse.addEventListener("click", function () {
        root.toggleAttribute("data-bottombar-collapsed");
      });
    var bbExpand = document.getElementById("tp-bottombar-expand");
    if (bbExpand)
      bbExpand.addEventListener("click", function () {
        root.setAttribute("data-bottombar-open", "true");
        root.removeAttribute("data-bottombar-collapsed");
      });
    var bbConfig = document.getElementById("tp-bottombar-config");
    if (bbConfig)
      bbConfig.addEventListener("click", function () {
        openWidgetsConfig(bbConfig.getAttribute("data-config-url"), bbConfig.getAttribute("data-save-url"));
      });

    // Widget de Servicios: "3 rayitas" → modal LISTA, "+ Añadir servicio" → modal FORM.
    // Modales compartidos en tp-services.js (mismos que usa /ui/v2/maintenance).
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

    // Drawer close
    // Colapsar / expandir el panel izquierdo (lista de elementos)
    var fCollapse = document.getElementById("tp-live-filters-collapse");
    var fExpand = document.getElementById("tp-live-filters-expand");
    if (fCollapse)
      fCollapse.addEventListener("click", function () {
        root.setAttribute("data-filters-collapsed", "true");
        if (fExpand) fExpand.hidden = false;
        setTimeout(function () { state.map && state.map.invalidateSize(); }, 250);
      });
    if (fExpand)
      fExpand.addEventListener("click", function () {
        root.removeAttribute("data-filters-collapsed");
        fExpand.hidden = true;
        setTimeout(function () { state.map && state.map.invalidateSize(); }, 250);
      });

    var dc = document.getElementById("tp-drawer-close");
    if (dc)
      dc.addEventListener("click", function () {
        root.removeAttribute("data-drawer-open");
        root.removeAttribute("data-bottombar-open");
        var prev = state.selectedId;
        state.selectedId = null;
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
